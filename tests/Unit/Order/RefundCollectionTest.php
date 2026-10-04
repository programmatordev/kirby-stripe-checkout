<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Order;

use Brick\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\RefundCollection;
use ProgrammatorDev\StripeCheckout\Order\Internal\RefundSnapshot;

final class RefundCollectionTest extends TestCase
{
    /** @param list<array{string, int}> $attempts */
    #[DataProvider('summaries')]
    public function testDerivesIndependentSummaryFromAllAttempts(array $attempts, string $status, string $total, bool $active, bool $action, bool $failed): void
    {
        $refunds = [];

        foreach ($attempts as $index => [$providerStatus, $amount]) {
            $refunds[] = RefundSnapshot::fromStripe($this->providerData('re_' . $index, $providerStatus, $amount), 'pi_test');
        }

        $collection = RefundCollection::fromSnapshots($refunds, 'pi_test', Money::of('32', 'EUR'));
        $this->assertSame($status, $collection->refundStatus()->value);
        $this->assertSame($total, (string) $collection->refundedTotal()->getAmount());
        $this->assertSame($active, $collection->refundHasActive());
        $this->assertSame($action, $collection->refundRequiresAction());
        $this->assertSame($failed, $collection->refundHasFailed());
    }

    /** @return iterable<array{list<array{string, int}>, string, string, bool, bool, bool}> */
    public static function summaries(): iterable
    {
        yield 'none' => [[], 'none', '0.00', false, false, false];
        yield 'pending' => [[['pending', 1600]], 'pending', '0.00', true, false, false];
        yield 'action' => [[['requires_action', 1600]], 'pending', '0.00', true, true, false];
        yield 'failed' => [[['failed', 1600]], 'failed', '0.00', false, false, true];
        yield 'canceled' => [[['canceled', 1600]], 'failed', '0.00', false, false, false];
        yield 'partial' => [[['succeeded', 1600]], 'partial', '16.00', false, false, false];
        yield 'full' => [[['succeeded', 3200]], 'full', '32.00', false, false, false];
        yield 'multiple' => [[['succeeded', 1600], ['succeeded', 1600]], 'full', '32.00', false, false, false];
        yield 'partial plus failed' => [[['succeeded', 1600], ['failed', 1600]], 'partial', '16.00', false, false, true];
        yield 'partial plus active' => [[['succeeded', 1600], ['pending', 1600]], 'pending', '16.00', true, false, false];
        yield 'full with failed attempt' => [[['succeeded', 3200], ['failed', 1600]], 'full', '32.00', false, false, true];
    }

    public function testObservationHistorySurvivesReorderingAndBackwardClockMovement(): void
    {
        $first = RefundSnapshot::fromStripe($this->providerData('re_b', 'pending', 1600), 'pi_test');
        $other = RefundSnapshot::fromStripe($this->providerData('re_a', 'failed', 1600), 'pi_test');
        $now = new DateTimeImmutable('2026-10-04T10:00:00Z');
        $before = RefundCollection::fromSnapshots([$first, $other], 'pi_test', Money::of('32', 'EUR'))->observed(null, $now);
        $unchanged = RefundCollection::fromSnapshots([$other, $first], 'pi_test', Money::of('32', 'EUR'))->observed($before, $now->modify('+1 hour'));
        $this->assertSame($before->toArray(), $unchanged->toArray());
        $changed = RefundSnapshot::fromStripe($this->providerData('re_b', 'succeeded', 1600), 'pi_test');
        $after = RefundCollection::fromSnapshots([$changed, $other], 'pi_test', Money::of('32', 'EUR'))->observed($before, $now->modify('-1 hour'));
        $this->assertSame('2026-10-04T10:00:00Z', $after->toArray()[1]['firstObservedAt']);
        $this->assertSame('2026-10-04T10:00:00Z', $after->toArray()[1]['updatedAt']);
        $this->assertSame('succeeded', $after->toArray()[1]['status']);
        $this->assertSame($after->toArray(), RefundCollection::fromArray($after->toArray(), 'pi_test', Money::of('32', 'EUR'))->toArray());
    }

    #[DataProvider('providerAmounts')]
    public function testConvertsStripeAmountUnitsExactly(string $currency, int $providerAmount, string $amount): void
    {
        $data = $this->providerData('re_test', 'succeeded', $providerAmount);
        $data['currency'] = strtolower($currency);
        $refund = RefundSnapshot::fromStripe($data, 'pi_test');
        $this->assertSame($amount, (string) $refund->amount()->getAmount());
        $this->assertSame($currency, $refund->amount()->getCurrency()->getCurrencyCode());
    }

    /** @return iterable<array{string, int, string}> */
    public static function providerAmounts(): iterable
    {
        yield ['EUR', 123, '1.23'];
        yield ['JPY', 500, '500'];
        yield ['BHD', 123, '1.230'];
        yield ['ISK', 500, '5'];
        yield ['UGX', 500, '5'];
    }

    public function testPreservesOpaqueReferencesAcrossSnapshotRestoration(): void
    {
        $data = $this->providerData('refund.reference-01', 'succeeded', 1600);
        $data['charge'] = 'charge.reference-01';
        $refund = RefundSnapshot::fromStripe($data, 'payment.reference-01');
        $collection = RefundCollection::fromSnapshots([$refund], 'payment.reference-01', Money::of('32', 'EUR'))
            ->observed(null, new DateTimeImmutable('2026-10-04T10:00:00Z'));
        $restored = RefundCollection::fromArray($collection->toArray(), 'payment.reference-01', Money::of('32', 'EUR'));

        $this->assertSame('refund.reference-01', $refund->stripeRefundId());
        $this->assertSame('payment.reference-01', $refund->stripePaymentIntentId());
        $this->assertSame('charge.reference-01', $refund->stripeChargeId());
        $this->assertSame($collection->toArray(), $restored->toArray());
    }

    public function testRejectsAnEmptyPaymentIdentity(): void
    {
        $this->expectException(OrderDataException::class);
        RefundSnapshot::fromStripe($this->providerData('re_test', 'pending', 1600), '');
    }

    #[DataProvider('invalidFacts')]
    public function testRejectsInvalidModeledProviderFacts(string $field, mixed $value): void
    {
        $data = $this->providerData('re_test', 'pending', 1600);
        $data[$field] = $value;
        $this->expectException(OrderDataException::class);
        RefundSnapshot::fromStripe($data, 'pi_test');
    }

    /** @return iterable<array{string, mixed}> */
    public static function invalidFacts(): iterable
    {
        yield ['amount', 0];
        yield ['amount', -1];
        yield ['amount', '1600'];
        yield ['id', ''];
        yield ['id', null];
        yield ['id', 1];
        yield ['charge', ''];
        yield ['charge', 1];
        yield ['status', 'unknown'];
        yield ['created', -1];
        yield ['currency', 'unknown'];
        yield ['reason', []];
        yield ['failure_reason', 1];
        yield ['pending_reason', false];
    }

    #[DataProvider('invalidCollections')]
    public function testRejectsContradictoryCompleteCollection(string $scenario): void
    {
        $refund = RefundSnapshot::fromStripe($this->providerData('re_test', 'succeeded', 1600), 'pi_test');
        $this->expectException(OrderDataException::class);
        RefundCollection::fromSnapshots(
            $scenario === 'duplicate' ? [$refund, $refund] : [$refund],
            $scenario === 'parent' ? 'pi_other' : 'pi_test',
            Money::of($scenario === 'excess' ? '1' : '32', $scenario === 'currency' ? 'USD' : 'EUR'),
        );
    }

    /** @return iterable<array{string}> */
    public static function invalidCollections(): iterable
    {
        yield ['duplicate'];
        yield ['parent'];
        yield ['currency'];
        yield ['excess'];
    }

    /** @return array<string, mixed> */
    private function providerData(string $id, string $status, int $amount): array
    {
        return [
            'id' => $id,
            'object' => 'refund',
            'currency' => 'eur',
            'amount' => $amount,
            'status' => $status,
            'created' => 1700000000,
            'charge' => null,
        ];
    }
}
