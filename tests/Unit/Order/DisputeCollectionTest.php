<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Order;

use Brick\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\DisputeCollection;
use ProgrammatorDev\StripeCheckout\Order\Internal\DisputeSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;

final class DisputeCollectionTest extends TestCase
{
    /** @param list<string> $statuses */
    #[DataProvider('summaries')]
    public function testDerivesSummaryAndIndependentFlags(array $statuses, string $summary, bool $requiresResponse, bool $hasLost): void
    {
        $disputes = [];

        foreach ($statuses as $index => $status) {
            $disputes[] = DisputeSnapshot::fromStripe($this->facts('du_' . $index, $status), 'pi_test');
        }

        $collection = DisputeCollection::fromSnapshots($disputes, 'pi_test', Money::of('32', 'EUR'));
        $this->assertSame($summary, $collection->disputeStatus()->value);
        $this->assertSame($requiresResponse, $collection->disputeRequiresResponse());
        $this->assertSame($hasLost, $collection->disputeHasLost());
    }

    /** @return iterable<array{list<string>, string, bool, bool}> */
    public static function summaries(): iterable
    {
        yield [[], 'none', false, false];
        yield [['needs_response'], 'needs_response', true, false];
        yield [['warning_needs_response', 'lost'], 'needs_response', true, true];
        yield [['under_review', 'needs_response'], 'needs_response', true, false];
        yield [['under_review', 'lost'], 'under_review', false, true];
        yield [['warning_under_review', 'won'], 'under_review', false, false];
        yield [['won'], 'resolved_favorable', false, false];
        yield [['prevented'], 'resolved_favorable', false, false];
        yield [['warning_closed'], 'resolved_favorable', false, false];
        yield [['lost', 'lost'], 'resolved_lost', false, true];
        yield [['lost', 'won', 'prevented', 'warning_closed'], 'mixed', false, true];
    }

    #[DataProvider('amounts')]
    public function testPreservesDisputedAmountsWithoutAChargeTotalCap(string $currency, int $providerAmount, string $amount): void
    {
        $facts = $this->facts();
        $facts['currency'] = strtolower($currency);
        $facts['amount'] = $providerAmount;
        $dispute = DisputeSnapshot::fromStripe($facts, 'pi_test');
        $collection = DisputeCollection::fromSnapshots([$dispute], 'pi_test', Money::of('1', $currency));
        $this->assertSame($amount, (string) $dispute->amount()->getAmount());
        $this->assertSame('needs_response', $collection->disputeStatus()->value);
    }

    /** @return iterable<array{string, int, string}> */
    public static function amounts(): iterable
    {
        yield ['EUR', 5000, '50.00'];
        yield ['JPY', 500, '500'];
        yield ['BHD', 123, '1.230'];
        yield ['ISK', 500, '5'];
        yield ['UGX', 500, '5'];
    }

    public function testMovementReorderingPreservesObservationHistoryButEvidenceChangesUpdateIt(): void
    {
        $facts = $this->facts();
        $facts['balance_transactions'] = [$this->movement('txn_b', 1600), $this->movement('txn_a', -1600)];
        $now = new DateTimeImmutable('2026-10-04T10:00:00Z');
        $before = DisputeCollection::fromSnapshots([DisputeSnapshot::fromStripe($facts, 'pi_test')], 'pi_test', Money::of('32', 'EUR'))->observed(null, $now);
        $facts['balance_transactions'] = array_reverse($facts['balance_transactions']);
        $unchanged = DisputeCollection::fromSnapshots([DisputeSnapshot::fromStripe($facts, 'pi_test')], 'pi_test', Money::of('32', 'EUR'))->observed($before, $now->modify('+1 hour'));
        $this->assertSame($before->toArray(), $unchanged->toArray());
        $evidenceDetails = OrderData::map($facts['evidence_details']);
        $facts['evidence_details'] = [
            ...$evidenceDetails,
            'has_evidence' => true,
            'submission_count' => 1,
        ];
        $after = DisputeCollection::fromSnapshots([DisputeSnapshot::fromStripe($facts, 'pi_test')], 'pi_test', Money::of('32', 'EUR'))->observed($before, $now->modify('-1 hour'));
        $this->assertSame('2026-10-04T10:00:00Z', $after->toArray()[0]['firstObservedAt']);
        $this->assertSame('2026-10-04T10:00:00Z', $after->toArray()[0]['updatedAt']);
        $this->assertTrue($after->toArray()[0]['evidenceHasEvidence']);
        $this->assertSame($after->toArray(), DisputeCollection::fromArray($after->toArray(), 'pi_test', Money::of('32', 'EUR'))->toArray());
        $balanceTransactions = OrderData::list($after->toArray()[0]['balanceTransactions']);
        $balanceTransaction = OrderData::map($balanceTransactions[0]);
        $this->assertSame('USD', $balanceTransaction['currency']);
        $this->assertSame(-1600, $balanceTransaction['amount']);
    }

    #[DataProvider('invalidFacts')]
    public function testRejectsInvalidModeledFacts(string $field, mixed $value): void
    {
        $facts = $this->facts();
        $facts[$field] = $value;
        $this->expectException(OrderDataException::class);
        DisputeSnapshot::fromStripe($facts, 'pi_test');
    }

    /** @return iterable<array{string, mixed}> */
    public static function invalidFacts(): iterable
    {
        yield ['id', ''];
        yield ['charge', null];
        yield ['amount', '1600'];
        yield ['amount', -1];
        yield ['currency', 'unknown'];
        yield ['status', 'unknown'];
        yield ['reason', []];
        yield ['created', -1];
        yield ['balance_transactions', null];
        yield ['balance_transactions', [[
            'id' => 'txn_bad',
            'object' => 'balance_transaction',
            'currency' => 'usd',
            'amount' => 1.5,
            'fee' => 0,
            'net' => 1,
            'created' => 1,
        ]]];
        yield ['evidence_details', [
            'due_by' => null,
            'has_evidence' => false,
            'past_due' => false,
            'submission_count' => -1,
        ]];
    }

    public function testRejectsDuplicateMovementIdentities(): void
    {
        $facts = $this->facts();
        $facts['balance_transactions'] = [$this->movement('txn_same', -1600), $this->movement('txn_same', 1600)];
        $this->expectException(OrderDataException::class);
        DisputeSnapshot::fromStripe($facts, 'pi_test');
    }

    public function testRestorationRevalidatesObservationHistory(): void
    {
        $snapshot = DisputeSnapshot::fromStripe($this->facts(), 'pi_test')->observed(null, new DateTimeImmutable('2026-10-04T10:00:00Z'))->toArray();
        $snapshot['updatedAt'] = '2026-10-03T10:00:00Z';
        $this->expectException(OrderDataException::class);
        DisputeSnapshot::fromArray($snapshot);
    }

    /** @return array<string, mixed> */
    private function facts(string $id = 'du_test', string $status = 'needs_response'): array
    {
        return [
            'id' => $id,
            'object' => 'dispute',
            'charge' => 'ch_test',
            'amount' => 1600,
            'currency' => 'eur',
            'status' => $status,
            'reason' => 'fraudulent',
            'created' => 100,
            'evidence_details' => [
                'due_by' => null,
                'has_evidence' => false,
                'past_due' => false,
                'submission_count' => 0,
            ],
            'balance_transactions' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function movement(string $id, int $amount): array
    {
        return [
            'id' => $id,
            'object' => 'balance_transaction',
            'currency' => 'usd',
            'amount' => $amount,
            'fee' => 0,
            'net' => $amount,
            'created' => 100,
        ];
    }
}
