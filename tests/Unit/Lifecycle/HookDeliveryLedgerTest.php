<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Lifecycle;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Lifecycle\Internal\HookDeliveryLedger;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleErrorCode;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer;
use ProgrammatorDev\StripeCheckout\Test\Support\CheckoutAttemptFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\OrderFixture;

final class HookDeliveryLedgerTest extends TestCase
{
    public function testRetainedEntriesWrittenBeforePruningSupportRemainReadable(): void
    {
        $entry = $this->entry('failed');
        unset($entry['payloadPrunedAt']);
        $normalized = HookDeliveryLedger::normalize([$entry], 'Abc123def456GHI7');
        $this->assertNull($normalized[0]['payloadPrunedAt']);
        $event = HookDeliveryLedger::restoreEvent(OrderData::map($normalized[0]['event']));
        $this->assertSame('PRIVATE_CANARY', $event->orderSnapshot()['note']);
    }

    #[DataProvider('deliveryStatuses')]
    public function testPruningKeepsOutcomesAndOnlyRemovesEligiblePayloads(string $status): void
    {
        $entry = $this->entry($status);
        $deadline = new DateTimeImmutable('2026-10-01T00:00:00Z');
        $beforeDeadline = HookDeliveryLedger::prunePayloads([$entry], $deadline->modify('-1 second'));

        $this->assertSame([$entry], $beforeDeadline);

        $pruned = HookDeliveryLedger::prunePayloads([$entry], $deadline);
        $expected = $entry;
        $event = OrderData::map($entry['event']);
        $expected['event'] = [
            'deliveryId' => $event['deliveryId'],
            'type' => 'order.created',
            'pageUuid' => 'page://Abc123def456GHI7',
            'occurredAt' => '2026-09-01T00:00:00Z',
            'revision' => 1,
            'triggerType' => null,
            'triggerId' => null,
        ];
        $expected['payloadPrunedAt'] = '2026-10-01T00:00:00Z';
        $this->assertEquals([$expected], $pruned);
        $this->assertEquals($pruned, HookDeliveryLedger::normalize($pruned, 'Abc123def456GHI7'));
        $this->assertSame($pruned, HookDeliveryLedger::prunePayloads($pruned, $deadline->modify('+1 day')));
        $this->assertSame(2, HookDeliveryLedger::nextRevision($pruned));
        HookDeliveryLedger::validateTransition([$entry], $pruned);
    }

    /** @return iterable<string, array{string}> */
    public static function deliveryStatuses(): iterable
    {
        yield 'pending' => ['pending'];
        yield 'failed' => ['failed'];
        yield 'delivered' => ['delivered'];
    }

    #[DataProvider('invalidPrunedEntries')]
    public function testPrunedStorageStillRejectsInvalidMetadata(string $mutation): void
    {
        $entry = $this->entry('failed');
        $pruned = HookDeliveryLedger::prunePayloads([$entry], new DateTimeImmutable('2026-10-01T00:00:00Z'));
        $event = OrderData::map($pruned[0]['event']);

        switch ($mutation) {
            case 'too early after delivery':
                $pruned[0]['status'] = 'delivered';
                $pruned[0]['errorCode'] = null;
                $pruned[0]['payloadPrunedAt'] = '2026-09-30T23:59:59Z';
                break;
            case 'too early':
                $pruned[0]['payloadPrunedAt'] = '2026-09-30T23:59:59Z';
                break;
            case 'missing marker':
                unset($pruned[0]['payloadPrunedAt']);
                break;
            case 'foreign order':
                $event['pageUuid'] = 'page://other';
                break;
            case 'private data':
                $event['nextAction'] = 'PRIVATE_CANARY';
                break;
            case 'unrelated fingerprint':
                $pruned[0]['actionFingerprint'] = str_repeat('a', 64);
                break;
            case 'invalid trigger':
                $event['triggerType'] = 'checkout.session.completed';
                break;
        }

        $pruned[0]['event'] = $event;
        $this->expectException(OrderDataException::class);
        HookDeliveryLedger::normalize($pruned, 'Abc123def456GHI7');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPrunedEntries(): iterable
    {
        $mutations = ['too early', 'too early after delivery', 'missing marker', 'foreign order', 'private data', 'unrelated fingerprint', 'invalid trigger'];

        foreach ($mutations as $mutation) {
            yield $mutation => [$mutation];
        }
    }

    #[DataProvider('invalidTransitions')]
    public function testPruningCannotRestorePayloadsOrRewriteDeliveryHistory(string $mutation): void
    {
        $entry = $this->entry('failed');
        $before = HookDeliveryLedger::prunePayloads([$entry], new DateTimeImmutable('2026-10-01T00:00:00Z'));
        $after = $before;

        switch ($mutation) {
            case 'restore payload':
                $after = [$entry];
                break;
            case 'change identity':
                $after[0]['event'] = [...OrderData::map($after[0]['event']), 'deliveryId' => 'replacement'];
                break;
            case 'change fingerprint':
                $after[0]['actionFingerprint'] = str_repeat('a', 64);
                break;
            case 'renew deadline':
                $after[0]['expiresAt'] = '2026-11-01T00:00:00Z';
                break;
            case 'retry after pruning':
                $after[0]['attempts'] = OrderData::integer($after[0]['attempts']) + 1;
                break;
            case 'change pruning time':
                $after[0]['payloadPrunedAt'] = '2026-10-02T00:00:00Z';
                break;
        }

        $this->expectException(OrderDataException::class);
        HookDeliveryLedger::validateTransition($before, $after);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTransitions(): iterable
    {
        $mutations = ['restore payload', 'change identity', 'change fingerprint', 'renew deadline', 'retry after pruning', 'change pruning time'];

        foreach ($mutations as $mutation) {
            yield $mutation => [$mutation];
        }
    }

    /** @return array<string, mixed> */
    private function entry(string $status): array
    {
        $createdAt = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $context = OrderFixture::context();
        $data = OrderSerializer::creation($context, CheckoutAttemptFactory::create($context, $createdAt), $createdAt);
        $event = HookDeliveryLedger::event($data, ['note' => 'PRIVATE_CANARY'], LifecycleEventType::OrderCreated, 1);
        $entry = HookDeliveryLedger::pending($event, 30, $createdAt);
        $entry['status'] = $status;

        if ($status !== 'pending') {
            $entry['attempts'] = 1;
            $entry['lastAttemptAt'] = '2026-09-01T00:00:00Z';
            $entry['errorCode'] = $status === 'failed' ? LifecycleErrorCode::LISTENER_FAILED : null;
        }

        return $entry;
    }
}
