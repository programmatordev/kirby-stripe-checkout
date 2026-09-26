<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Stripe;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\ShippingSnapshot;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Internal\CheckoutSessionSnapshotNormalizer;
use Stripe\ShippingRate;

final class CheckoutSessionShippingSnapshotTest extends TestCase
{
    public function testNormalizesSelectedShippingAddressRateAmountsAndTax(): void
    {
        $snapshots = $this->normalize($this->shippingSource());

        $this->assertSame('shr_standard', $snapshots['stripeShippingRateId']);
        $this->assertSame([
            'name' => 'Ana Silva',
            'line1' => 'Rua Dois, 20',
            'line2' => null,
            'postalCode' => '4000-001',
            'city' => 'Porto',
            'state' => null,
            'country' => 'PT',
        ], $snapshots['shippingAddress']);
        $this->assertSame([
            'optionKey' => 'standard',
            'quoteFingerprint' => str_repeat('a', 64),
            'label' => 'Standard delivery',
            'currency' => 'EUR',
            'subtotal' => '5.00',
            'providerSubtotal' => 500,
            'tax' => '1.15',
            'providerTax' => 115,
            'total' => '6.15',
            'providerTotal' => 615,
            'deliveryEstimate' => [
                'minimum' => 2,
                'maximum' => 4,
                'unit' => 'business_day',
            ],
            'taxBehavior' => 'exclusive',
            'taxCode' => 'txcd_92010001',
        ], $snapshots['shipping']);
        $this->assertSame('6.15', $snapshots['shippingTotal']);
        $this->assertSame(
            $snapshots['shipping'],
            ShippingSnapshot::fromArray(OrderData::map($snapshots['shipping']))->toArray(),
        );

        $tax = OrderData::map($snapshots['tax']);
        $breakdown = OrderData::list($tax['breakdown']);
        $shippingTax = OrderData::map($breakdown[1]);
        $this->assertSame('shipping', $shippingTax['target']);
        $this->assertSame('shr_standard', $shippingTax['targetId']);
        $this->assertSame('1.15', $shippingTax['amount']);
    }

    public function testNormalizesAuthoritativeAbsenceAsZeroShipping(): void
    {
        $snapshots = $this->normalize([
            'total_details' => [
                'amount_discount' => 0,
                'amount_shipping' => 0,
            ],
        ]);

        $this->assertNull($snapshots['stripeShippingRateId']);
        $this->assertNull($snapshots['shipping']);
        $this->assertSame('0.00', $snapshots['shippingTotal']);
    }

    public function testKeepsASelectedFreeShippingRateDistinctFromNoShipping(): void
    {
        $source = $this->shippingSource();
        $shippingCost = self::map($source['shipping_cost']);
        $shippingCost['amount_subtotal'] = 0;
        $shippingCost['amount_tax'] = 0;
        $shippingCost['amount_total'] = 0;
        $shippingCost['taxes'] = [];
        $shippingRate = self::map($shippingCost['shipping_rate']);
        $fixedAmount = self::map($shippingRate['fixed_amount']);
        $fixedAmount['amount'] = 0;
        $shippingRate['fixed_amount'] = $fixedAmount;
        $shippingCost['shipping_rate'] = $shippingRate;
        $source['shipping_cost'] = $shippingCost;
        $totalDetails = self::map($source['total_details']);
        $totalDetails['amount_shipping'] = 0;
        $totalDetails['amount_tax'] = 0;
        $totalBreakdown = self::map($totalDetails['breakdown']);
        $totalBreakdown['taxes'] = [];
        $totalDetails['breakdown'] = $totalBreakdown;
        $source['total_details'] = $totalDetails;

        $snapshots = $this->normalize($source);

        $this->assertSame('shr_standard', $snapshots['stripeShippingRateId']);
        $this->assertIsArray($snapshots['shipping']);
        $this->assertSame('0.00', $snapshots['shippingTotal']);
    }

    public function testAcceptsTheProviderUnspecifiedTaxBehavior(): void
    {
        $source = $this->shippingSource();
        $shippingCost = self::map($source['shipping_cost']);
        $shippingRate = self::map($shippingCost['shipping_rate']);
        $shippingRate['tax_behavior'] = ShippingRate::TAX_BEHAVIOR_UNSPECIFIED;
        $shippingCost['shipping_rate'] = $shippingRate;
        $source['shipping_cost'] = $shippingCost;

        $shipping = OrderData::map($this->normalize($source)['shipping']);

        $this->assertSame(ShippingRate::TAX_BEHAVIOR_UNSPECIFIED, $shipping['taxBehavior']);
    }

    /** @param callable(array<string, mixed>&): void $change */
    #[DataProvider('invalidShippingSources')]
    public function testRejectsIncompleteOrInconsistentShippingFacts(callable $change): void
    {
        $source = $this->shippingSource();
        $change($source);
        $source = OrderData::map($source);

        $this->expectException(OrderDataException::class);
        $this->normalize($source);
    }

    /** @return iterable<string, array{callable(array<string, mixed>&): void}> */
    public static function invalidShippingSources(): iterable
    {
        yield 'unexpanded rate' => [static function (array &$source): void {
            $shippingCost = self::map($source['shipping_cost']);
            $shippingCost['shipping_rate'] = 'shr_standard';
            $source['shipping_cost'] = $shippingCost;
        }];
        yield 'selected rate reference' => [static function (array &$source): void {
            $shippingCost = self::map($source['shipping_cost']);
            $shippingRate = self::map($shippingCost['shipping_rate']);
            $shippingRate['id'] = 'rate_standard';
            $shippingCost['shipping_rate'] = $shippingRate;
            $source['shipping_cost'] = $shippingCost;
        }];
        yield 'rate object type' => [static function (array &$source): void {
            $shippingCost = self::map($source['shipping_cost']);
            $shippingRate = self::map($shippingCost['shipping_rate']);
            $shippingRate['object'] = 'price';
            $shippingCost['shipping_rate'] = $shippingRate;
            $source['shipping_cost'] = $shippingCost;
        }];
        yield 'rate tax behavior' => [static function (array &$source): void {
            $shippingCost = self::map($source['shipping_cost']);
            $shippingRate = self::map($shippingCost['shipping_rate']);
            $shippingRate['tax_behavior'] = 'automatic';
            $shippingCost['shipping_rate'] = $shippingRate;
            $source['shipping_cost'] = $shippingCost;
        }];
        yield 'Session shipping total' => [static function (array &$source): void {
            $totalDetails = self::map($source['total_details']);
            $totalDetails['amount_shipping'] = 614;
            $source['total_details'] = $totalDetails;
        }];
        yield 'rate fixed amount' => [static function (array &$source): void {
            $shippingCost = self::map($source['shipping_cost']);
            $shippingRate = self::map($shippingCost['shipping_rate']);
            $fixedAmount = self::map($shippingRate['fixed_amount']);
            $fixedAmount['amount'] = 499;
            $shippingRate['fixed_amount'] = $fixedAmount;
            $shippingCost['shipping_rate'] = $shippingRate;
            $source['shipping_cost'] = $shippingCost;
        }];
        yield 'shipping tax allocation' => [static function (array &$source): void {
            $shippingCost = self::map($source['shipping_cost']);
            $taxes = OrderData::list($shippingCost['taxes']);
            $tax = self::map($taxes[0]);
            $tax['amount'] = 114;
            $shippingCost['taxes'] = [$tax];
            $source['shipping_cost'] = $shippingCost;
        }];
        yield 'private metadata' => [static function (array &$source): void {
            $shippingCost = self::map($source['shipping_cost']);
            $shippingRate = self::map($shippingCost['shipping_rate']);
            $metadata = self::map($shippingRate['metadata']);
            unset($metadata['kirby_stripe_checkout_shipping_quote']);
            $shippingRate['metadata'] = $metadata;
            $shippingCost['shipping_rate'] = $shippingRate;
            $source['shipping_cost'] = $shippingCost;
        }];
        yield 'delivery estimate range' => [static function (array &$source): void {
            $shippingCost = self::map($source['shipping_cost']);
            $shippingRate = self::map($shippingCost['shipping_rate']);
            $estimate = self::map($shippingRate['delivery_estimate']);
            $maximum = self::map($estimate['maximum']);
            $maximum['unit'] = 'day';
            $estimate['maximum'] = $maximum;
            $shippingRate['delivery_estimate'] = $estimate;
            $shippingCost['shipping_rate'] = $shippingRate;
            $source['shipping_cost'] = $shippingCost;
        }];
        yield 'unexpected positive shipping without a cost' => [static function (array &$source): void {
            unset($source['shipping_cost']);
        }];
    }

    /** @return array<string, mixed> */
    private function shippingSource(): array
    {
        $tax = [
            'amount' => 115,
            'rate' => [
                'id' => 'txr_shipping',
                'inclusive' => false,
                'percentage' => 23,
            ],
            'taxability_reason' => 'standard_rated',
            'taxable_amount' => 500,
        ];

        return [
            'automatic_tax' => [
                'enabled' => true,
                'provider' => 'stripe',
                'status' => 'complete',
            ],
            'collected_information' => ['shipping_details' => [
                'address' => [
                    'city' => 'Porto',
                    'country' => 'pt',
                    'line1' => 'Rua Dois, 20',
                    'line2' => null,
                    'postal_code' => '4000-001',
                    'state' => null,
                ],
                'name' => 'Ana Silva',
            ]],
            'shipping_cost' => [
                'amount_subtotal' => 500,
                'amount_tax' => 115,
                'amount_total' => 615,
                'shipping_rate' => [
                    'id' => 'shr_standard',
                    'active' => false,
                    'delivery_estimate' => [
                        'minimum' => [
                            'unit' => 'business_day',
                            'value' => 2,
                        ],
                        'maximum' => [
                            'unit' => 'business_day',
                            'value' => 4,
                        ],
                    ],
                    'display_name' => 'Standard delivery',
                    'fixed_amount' => [
                        'amount' => 500,
                        'currency' => 'eur',
                    ],
                    'livemode' => false,
                    'metadata' => [
                        'kirby_stripe_checkout_order' => 'page://order',
                        'kirby_stripe_checkout_owner' => 'programmatordev/stripe-checkout',
                        'kirby_stripe_checkout_shipping_option' => 'standard',
                        'kirby_stripe_checkout_shipping_quote' => str_repeat('a', 64),
                    ],
                    'object' => ShippingRate::OBJECT_NAME,
                    'tax_behavior' => ShippingRate::TAX_BEHAVIOR_EXCLUSIVE,
                    'tax_code' => ['id' => 'txcd_92010001'],
                    'type' => ShippingRate::TYPE_FIXED_AMOUNT,
                ],
                'taxes' => [$tax],
            ],
            'total_details' => [
                'amount_discount' => 0,
                'amount_shipping' => 615,
                'amount_tax' => 115,
                'breakdown' => [
                    'discounts' => [],
                    'taxes' => [$tax],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function normalize(array $source): array
    {
        return (new CheckoutSessionSnapshotNormalizer())->normalize(new CheckoutSessionRecord(
            id: 'cs_test_shipping',
            createdAt: 1,
            expiresAt: 2,
            status: 'complete',
            paymentStatus: 'paid',
            liveMode: false,
            mode: 'payment',
            uiMode: 'hosted_page',
            currency: 'eur',
            clientReferenceId: 'page://order',
            integrationIdentifier: null,
            metadata: [],
            requestId: null,
            url: null,
            clientSecret: null,
            orderSnapshotSource: $source,
        ));
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        return OrderData::map($value);
    }
}
