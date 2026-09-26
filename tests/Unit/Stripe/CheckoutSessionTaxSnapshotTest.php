<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Stripe;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\TaxSnapshot;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Internal\CheckoutSessionSnapshotNormalizer;
use Stripe\ShippingRate;

final class CheckoutSessionTaxSnapshotTest extends TestCase
{
    #[DataProvider('calculationOutcomes')]
    public function testPreservesCalculationOutcomesIndependentlyOfPayment(bool $enabled, ?string $status, int $amount): void
    {
        $source = $this->source($enabled, $status, $amount);
        $snapshots = $this->normalize($source);
        $tax = $snapshots['tax'];
        $this->assertIsArray($tax);
        $this->assertSame($enabled, $tax['automaticTaxEnabled']);
        $this->assertSame($status, $tax['calculationStatus']);
        $this->assertSame($amount, $tax['providerAmount']);
        $this->assertSame('EUR', $tax['currency']);
        $this->assertSame('stripe', $tax['provider']);
        $this->assertSame($tax['amount'], $snapshots['taxTotal']);
        $this->assertNull($tax['breakdown']);
        $this->assertSame($tax, TaxSnapshot::fromArray(OrderData::map($tax))->toArray());
    }

    /** @return iterable<string, array{bool, ?string, int}> */
    public static function calculationOutcomes(): iterable
    {
        yield 'disabled' => [false, null, 0];
        yield 'manual tax' => [false, null, 230];
        yield 'automatic positive' => [true, 'complete', 230];
        yield 'automatic zero' => [true, 'complete', 0];
        yield 'awaiting location' => [true, 'requires_location_inputs', 0];
        yield 'failed calculation' => [true, 'failed', 0];
        yield 'not calculated yet' => [true, null, 0];
    }

    public function testDoesNotInventAnAmountBeforeStripeReturnsOne(): void
    {
        $snapshots = $this->normalize(['automatic_tax' => ['enabled' => true, 'status' => null]]);
        $this->assertIsArray($snapshots['tax']);
        $this->assertNull($snapshots['tax']['amount']);
        $this->assertNull($snapshots['tax']['providerAmount']);
        $this->assertNull($snapshots['taxTotal']);
    }

    public function testKeepsAggregateLineAndShippingAllocationsSeparateWithoutDoubleCounting(): void
    {
        $source = $this->source(true, 'complete', 460);
        $taxEntry = $this->taxEntry(230, 'standard_rated');
        $source['total_details']['breakdown'] = ['taxes' => [$this->taxEntry(460, 'standard_rated')]];
        $source['line_items'] = [
            'data' => [['id' => 'li_one', 'taxes' => [$taxEntry]]],
            'has_more' => false,
        ];
        $source['total_details']['amount_shipping'] = 1230;
        $source['shipping_cost'] = [
            'amount_subtotal' => 1000,
            'amount_tax' => 230,
            'amount_total' => 1230,
            'shipping_rate' => [
                'id' => 'shr_one',
                'display_name' => 'Standard delivery',
                'fixed_amount' => [
                    'amount' => 1000,
                    'currency' => 'eur',
                ],
                'metadata' => [
                    'kirby_stripe_checkout_order' => 'page://order',
                    'kirby_stripe_checkout_owner' => 'programmatordev/stripe-checkout',
                    'kirby_stripe_checkout_shipping_option' => 'standard',
                    'kirby_stripe_checkout_shipping_quote' => str_repeat('a', 64),
                ],
                'object' => ShippingRate::OBJECT_NAME,
                'type' => ShippingRate::TYPE_FIXED_AMOUNT,
            ],
            'taxes' => [$taxEntry],
        ];
        $snapshots = $this->normalize($source);
        $this->assertSame('4.60', $snapshots['taxTotal']);
        $this->assertIsArray($snapshots['tax']);
        $breakdown = $snapshots['tax']['breakdown'];
        $this->assertIsArray($breakdown);
        $this->assertCount(3, $breakdown);
        $this->assertSame(['order', 'line_item', 'shipping'], array_column($breakdown, 'target'));
        $this->assertSame([null, 'li_one', 'shr_one'], array_column($breakdown, 'targetId'));
        $entry = OrderData::map($breakdown[1]);
        $this->assertSame('10.00', $entry['taxableAmount']);
        $this->assertSame('23', $entry['percentage']);
        $this->assertTrue($entry['inclusive']);
        $this->assertSame('Portugal', $entry['jurisdiction']);
        $this->assertSame('standard_rated', $entry['taxabilityReason']);
        $this->assertArrayNotHasKey('metadata', $entry);
    }

    #[DataProvider('zeroReasons')]
    public function testPreservesZeroTaxReasonsWithoutInferringDisabledTax(string $reason): void
    {
        $source = $this->source(true, 'complete', 0);
        $source['total_details']['breakdown'] = ['taxes' => [$this->taxEntry(0, $reason)]];
        $snapshots = $this->normalize($source);
        $this->assertIsArray($snapshots['tax']);
        $this->assertTrue($snapshots['tax']['automaticTaxEnabled']);
        $this->assertSame('0.00', $snapshots['taxTotal']);
        $this->assertIsArray($snapshots['tax']['breakdown']);
        $entry = OrderData::map($snapshots['tax']['breakdown'][0]);
        $this->assertSame($reason, $entry['taxabilityReason']);
    }

    /** @return iterable<string, array{string}> */
    public static function zeroReasons(): iterable
    {
        $reasons = ['not_collecting', 'customer_exempt', 'product_exempt', 'reverse_charge', 'zero_rated', 'future_stripe_reason'];

        foreach ($reasons as $reason) {
            yield $reason => [$reason];
        }
    }

    public function testUsesStripeProviderUnitsForNonIsoExponents(): void
    {
        $snapshots = $this->normalize($this->source(true, 'complete', 500), 'isk');
        $this->assertSame('5', $snapshots['taxTotal']);
    }

    public function testPersistedAggregateAllocationsCannotDisagreeWithTheTotal(): void
    {
        $source = $this->source(true, 'complete', 230);
        $source['total_details']['breakdown'] = ['taxes' => [$this->taxEntry(230, 'standard_rated')]];
        $snapshots = $this->normalize($source);
        $tax = OrderData::map($snapshots['tax']);
        $breakdown = OrderData::list($tax['breakdown']);
        $entry = OrderData::map($breakdown[0]);
        $entry['amount'] = '1.00';
        $entry['providerAmount'] = 100;
        $tax['breakdown'] = [$entry];
        $this->expectException(OrderDataException::class);
        TaxSnapshot::fromArray($tax);
    }

    /** @param array<string, mixed> $source */
    #[DataProvider('invalidSources')]
    public function testRejectsMalformedProviderFacts(array $source): void
    {
        $this->expectException(OrderDataException::class);
        $this->normalize($source);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidSources(): iterable
    {
        yield 'enabled string' => [['automatic_tax' => ['enabled' => 'true']]];
        yield 'tax float' => [[
            'automatic_tax' => ['enabled' => true],
            'total_details' => ['amount_discount' => 0, 'amount_tax' => 2.3],
        ]];
        yield 'negative tax' => [[
            'automatic_tax' => ['enabled' => true],
            'total_details' => ['amount_discount' => 0, 'amount_tax' => -1],
        ]];
        yield 'truncated lines' => [[
            'automatic_tax' => ['enabled' => true],
            'line_items' => ['data' => [], 'has_more' => true],
        ]];
        yield 'wrong breakdown shape' => [[
            'automatic_tax' => ['enabled' => true],
            'total_details' => ['amount_discount' => 0, 'breakdown' => ['taxes' => ['rate' => []]]],
        ]];
        yield 'aggregate total mismatch' => [[
            'automatic_tax' => ['enabled' => true],
            'total_details' => [
                'amount_discount' => 0,
                'amount_tax' => 1,
                'breakdown' => ['taxes' => []],
            ],
        ]];
    }

    /** @return array<string, mixed> */
    private function taxEntry(int $amount, string $reason): array
    {
        return [
            'amount' => $amount,
            'taxable_amount' => 1000,
            'taxability_reason' => $reason,
            'rate' => [
                'id' => 'txr_one',
                'inclusive' => true,
                'percentage' => 23.0,
                'effective_percentage' => 23.0,
                'jurisdiction' => 'Portugal',
                'country' => 'PT',
                'tax_type' => 'vat',
                'metadata' => ['private' => 'not-persisted'],
            ],
        ];
    }

    /** @return array{automatic_tax: array<string, mixed>, total_details: array<string, mixed>} */
    private function source(bool $enabled, ?string $status, int $amount): array
    {
        return [
            'automatic_tax' => ['enabled' => $enabled, 'status' => $status, 'provider' => 'stripe'],
            'total_details' => ['amount_discount' => 0, 'amount_tax' => $amount],
        ];
    }

    /** @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function normalize(array $source, string $currency = 'eur'): array
    {
        return (new CheckoutSessionSnapshotNormalizer())->normalize(new CheckoutSessionRecord(
            id: 'cs_test_one',
            createdAt: 1,
            expiresAt: 2,
            status: 'open',
            paymentStatus: 'unpaid',
            liveMode: false,
            mode: 'payment',
            uiMode: 'hosted_page',
            currency: $currency,
            clientReferenceId: 'page://order',
            integrationIdentifier: null,
            metadata: [],
            requestId: null,
            url: null,
            clientSecret: null,
            orderSnapshotSource: $source,
        ));
    }
}
