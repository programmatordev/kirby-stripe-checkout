<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Stripe;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\AddressSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\ConsentSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\CustomerSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\CustomFieldSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\DiscountSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Internal\CheckoutSessionSnapshotNormalizer;

final class CheckoutSessionSnapshotNormalizerTest extends TestCase
{
    public function testPreservesOpaqueDiscountAndCustomerReferencesThroughRestoration(): void
    {
        $customerId = 'customer.reference-' . str_repeat('c', 3000);
        $discountId = 'discount.reference-' . str_repeat('d', 3000);
        $couponId = 'coupon.reference-' . str_repeat('c', 3000);
        $promotionCodeId = 'promotion.reference-' . str_repeat('p', 3000);
        $productId = 'product.reference-' . str_repeat('r', 3000);
        $promotionCode = str_repeat('PROMOTION', 400);
        $snapshot = (new CheckoutSessionSnapshotNormalizer())->normalize($this->sessionRecord([
            'customer' => ['id' => $customerId],
            'total_details' => [
                'amount_discount' => 500,
                'breakdown' => ['discounts' => [[
                    'amount' => 500,
                    'discount' => [
                        'id' => $discountId,
                        'coupon' => ['id' => $couponId, 'applies_to' => ['products' => [$productId]]],
                        'promotion_code' => ['id' => $promotionCodeId, 'code' => $promotionCode],
                    ],
                ]]],
            ],
        ]));
        $discount = $snapshot->discounts()[0]->toArray();

        $this->assertSame($customerId, $snapshot->toArray()['stripeCustomerId']);
        $this->assertSame($discountId, $discount['discountId']);
        $this->assertSame($couponId, $discount['couponId']);
        $this->assertSame($promotionCodeId, $discount['promotionCodeId']);
        $this->assertSame($promotionCode, $discount['promotionCode']);
        $this->assertSame([$productId], $discount['appliesToProducts']);
        $this->assertSame($discount, DiscountSnapshot::fromArray($discount)->toArray());
    }

    public function testNormalizesCurrentProviderFactsWithoutConfigurationInput(): void
    {
        $snapshot = (new CheckoutSessionSnapshotNormalizer())->normalize(
            $this->sessionRecord($this->completeSource()),
        );

        $this->assertSame([
            'stripeCustomerId' => 'cus_customer',
            'customer' => [
                'email' => 'buyer@example.test',
                'individualName' => 'Ana Silva',
                'businessName' => 'Example Studio',
                'phone' => '+351910000000',
                'taxIds' => [[
                    'type' => 'eu_vat',
                    'value' => 'PT123456789',
                ]],
            ],
            'billingAddress' => [
                'name' => 'Ana Silva',
                'line1' => 'Rua Um, 10',
                'line2' => null,
                'postalCode' => '1000-001',
                'city' => 'Lisboa',
                'state' => null,
                'country' => 'PT',
            ],
            'shippingAddress' => [
                'name' => 'Ana Silva',
                'line1' => 'Rua Dois, 20',
                'line2' => '2.º esquerdo',
                'postalCode' => '4000-001',
                'city' => 'Porto',
                'state' => 'Porto',
                'country' => 'PT',
            ],
            'stripeShippingRateId' => null,
            'shipping' => null,
            'shippingTotal' => null,
            'customFields' => [
                [
                    'key' => 'reference',
                    'type' => 'text',
                    'label' => 'Order reference',
                    'required' => false,
                    'configured' => true,
                    'answered' => false,
                    'value' => null,
                ],
                [
                    'key' => 'fiscalnumber',
                    'type' => 'numeric',
                    'label' => 'Fiscal number',
                    'required' => true,
                    'configured' => true,
                    'answered' => true,
                    'value' => '123456789',
                ],
                [
                    'key' => 'packaging',
                    'type' => 'dropdown',
                    'label' => 'Packaging',
                    'required' => false,
                    'configured' => true,
                    'answered' => true,
                    'value' => 'gift',
                ],
            ],
            'consent' => [
                'termsOfService' => 'accepted',
                'promotions' => 'opt_in',
            ],
            'discounts' => [[
                'discountId' => 'di_discount',
                'couponId' => 'summer-sale',
                'promotionCodeId' => 'promo_save',
                'couponName' => 'Summer sale',
                'promotionCode' => 'SAVE125',
                'amount' => '5.00',
                'currency' => 'EUR',
                'providerAmount' => 500,
                'percentOff' => '12.5',
                'appliesToProducts' => ['prod_shirt'],
                'firstTimeTransaction' => true,
                'minimumAmount' => '20.00',
                'minimumAmountCurrency' => 'EUR',
                'providerMinimumAmount' => 2000,
            ]],
            'discountTotal' => '5.00',
            'tax' => null,
            'taxTotal' => null,
        ], $snapshot->toArray());
    }

    public function testPreservesAbsentAndOptionalUnansweredFacts(): void
    {
        $snapshot = (new CheckoutSessionSnapshotNormalizer())->normalize($this->sessionRecord([]));
        $this->assertSame([
            'stripeCustomerId' => null,
            'customer' => null,
            'billingAddress' => null,
            'shippingAddress' => null,
            'stripeShippingRateId' => null,
            'shipping' => null,
            'shippingTotal' => null,
            'customFields' => [],
            'consent' => null,
            'discounts' => [],
            'discountTotal' => null,
            'tax' => null,
            'taxTotal' => null,
        ], $snapshot->toArray());

        $source = [
            'custom_fields' => [[
                'key' => 'note',
                'label' => [
                    'custom' => 'Note',
                    'type' => 'custom',
                ],
                'optional' => true,
                'text' => ['value' => ''],
                'type' => 'text',
            ]],
            'consent' => [
                'promotions' => null,
                'terms_of_service' => null,
            ],
            'total_details' => [
                'amount_discount' => 0,
                'breakdown' => ['discounts' => []],
            ],
        ];
        $snapshot = (new CheckoutSessionSnapshotNormalizer())->normalize($this->sessionRecord($source));
        $customField = $snapshot->customFields()[0]->toArray();
        $this->assertTrue($customField['answered']);
        $this->assertSame('', $customField['value']);
        $this->assertSame([
            'termsOfService' => null,
            'promotions' => null,
        ], $snapshot->consent()?->toArray());
        $this->assertSame('0.00', $snapshot->discountTotal());
    }

    public function testCanonicalSnapshotValuesRoundTrip(): void
    {
        $snapshot = (new CheckoutSessionSnapshotNormalizer())->normalize(
            $this->sessionRecord($this->completeSource()),
        );

        $customer = $snapshot->customer()?->toArray();
        $billingAddress = $snapshot->billingAddress()?->toArray();
        $customField = $snapshot->customFields()[0]->toArray();
        $consent = $snapshot->consent()?->toArray();
        $discount = $snapshot->discounts()[0]->toArray();

        $this->assertSame($customer, CustomerSnapshot::fromArray(OrderData::map($customer))->toArray());
        $this->assertSame($billingAddress, AddressSnapshot::fromArray(OrderData::map($billingAddress))->toArray());
        $this->assertSame($customField, CustomFieldSnapshot::fromArray($customField)->toArray());
        $this->assertSame($consent, ConsentSnapshot::fromArray(OrderData::map($consent))->toArray());
        $this->assertSame($discount, DiscountSnapshot::fromArray($discount)->toArray());
    }

    /** @param array<string, mixed> $source */
    #[DataProvider('invalidProviderSources')]
    public function testRejectsMalformedOrIncompleteProviderFacts(array $source): void
    {
        $this->expectException(OrderDataException::class);
        (new CheckoutSessionSnapshotNormalizer())->normalize($this->sessionRecord($source));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidProviderSources(): iterable
    {
        yield 'customer object' => [['customer_details' => new \stdClass()]];
        yield 'custom fields shape' => [['custom_fields' => false]];
        yield 'custom field optional flag' => [['custom_fields' => [[
            'key' => 'note',
            'label' => [
                'custom' => 'Note',
                'type' => 'custom',
            ],
            'text' => ['value' => null],
            'type' => 'text',
        ]]]];
        yield 'duplicate custom key' => [['custom_fields' => [
            [
                'key' => 'note',
                'label' => [
                    'custom' => 'Note',
                    'type' => 'custom',
                ],
                'optional' => true,
                'text' => ['value' => null],
                'type' => 'text',
            ],
            [
                'key' => 'note',
                'label' => [
                    'custom' => 'Other',
                    'type' => 'custom',
                ],
                'optional' => true,
                'text' => ['value' => null],
                'type' => 'text',
            ],
        ]]];
        yield 'invalid consent' => [['consent' => ['terms_of_service' => false]]];
        yield 'missing discount breakdown' => [['total_details' => ['amount_discount' => 100]]];
        yield 'discount total mismatch' => [[
            'total_details' => [
                'amount_discount' => 200,
                'breakdown' => ['discounts' => [[
                    'amount' => 100,
                    'discount' => ['id' => 'di_test'],
                ]]],
            ],
        ]];
    }

    /** @return array<string, mixed> */
    private function completeSource(): array
    {
        return [
            'customer' => ['id' => 'cus_customer'],
            'customer_details' => [
                'address' => [
                    'city' => 'Lisboa',
                    'country' => 'pt',
                    'line1' => 'Rua Um, 10',
                    'line2' => null,
                    'postal_code' => '1000-001',
                    'state' => null,
                ],
                'business_name' => 'Example Studio',
                'email' => 'buyer@example.test',
                'individual_name' => 'Ana Silva',
                'name' => 'Ana Silva',
                'phone' => '+351910000000',
                'tax_ids' => [[
                    'type' => 'eu_vat',
                    'value' => 'PT123456789',
                ]],
            ],
            'collected_information' => ['shipping_details' => [
                'address' => [
                    'city' => 'Porto',
                    'country' => 'PT',
                    'line1' => 'Rua Dois, 20',
                    'line2' => '2.º esquerdo',
                    'postal_code' => '4000-001',
                    'state' => 'Porto',
                ],
                'name' => 'Ana Silva',
            ]],
            'custom_fields' => [
                [
                    'key' => 'reference',
                    'label' => [
                        'custom' => 'Order reference',
                        'type' => 'custom',
                    ],
                    'optional' => true,
                    'text' => ['value' => null],
                    'type' => 'text',
                ],
                [
                    'key' => 'fiscalnumber',
                    'label' => [
                        'custom' => 'Fiscal number',
                        'type' => 'custom',
                    ],
                    'numeric' => ['value' => '123456789'],
                    'optional' => false,
                    'type' => 'numeric',
                ],
                [
                    'dropdown' => ['value' => 'gift'],
                    'key' => 'packaging',
                    'label' => [
                        'custom' => 'Packaging',
                        'type' => 'custom',
                    ],
                    'optional' => true,
                    'type' => 'dropdown',
                ],
            ],
            'consent' => [
                'promotions' => 'opt_in',
                'terms_of_service' => 'accepted',
            ],
            'total_details' => [
                'amount_discount' => 500,
                'breakdown' => ['discounts' => [[
                    'amount' => 500,
                    'discount' => [
                        'id' => 'di_discount',
                        'promotion_code' => [
                            'code' => 'SAVE125',
                            'id' => 'promo_save',
                            'restrictions' => [
                                'currency_options' => [
                                    'eur' => ['minimum_amount' => 2000],
                                ],
                                'first_time_transaction' => true,
                                'minimum_amount' => null,
                                'minimum_amount_currency' => null,
                            ],
                        ],
                        'source' => ['coupon' => [
                            'applies_to' => ['products' => ['prod_shirt']],
                            'id' => 'summer-sale',
                            'name' => 'Summer sale',
                            'percent_off' => 12.5,
                        ]],
                    ],
                ]]],
            ],
        ];
    }

    /** @param array<string, mixed> $orderSnapshotSource */
    private function sessionRecord(array $orderSnapshotSource): CheckoutSessionRecord
    {
        return new CheckoutSessionRecord(
            id: 'cs_test',
            createdAt: 1,
            expiresAt: 2,
            status: 'complete',
            paymentStatus: 'paid',
            liveMode: false,
            mode: 'payment',
            uiMode: 'hosted_page',
            currency: 'eur',
            clientReferenceId: 'page://order',
            integrationIdentifier: 'kirby_stripe_checkout_test',
            metadata: [],
            requestId: 'req_test',
            url: null,
            clientSecret: null,
            orderSnapshotSource: $orderSnapshotSource,
        );
    }
}
