<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Checkout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\InvalidSessionRequestException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestValidator;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;

final class SessionRequestValidatorTest extends TestCase
{
    public function testAllowsSupportedOverridesAndStripeOwnedParameters(): void
    {
        $parameters = $this->standardRequest()->parameters();
        $parameters['billing_address_collection'] = 'required';
        $parameters['name_collection'] = [
            'individual' => [
                'enabled' => true,
                'optional' => false,
            ],
        ];
        $parameters['custom_fields'] = [[
            'key' => 'vatnumber',
            'label' => [
                'custom' => 'VAT number',
                'type' => 'custom',
            ],
            'optional' => true,
            'text' => [
                'maximum_length' => 20,
                'minimum_length' => 3,
            ],
            'type' => 'text',
        ]];
        $parameters['allow_promotion_codes'] = false;
        $parameters['customer'] = 'cus_test';
        $parameters['discounts'] = [['promotion_code' => 'promo_test']];
        $parameters['invoice_creation'] = ['enabled' => true];
        $parameters['origin_context'] = 'mobile_app';
        $parameters['payment_method_types'] = ['card'];
        $parameters['payment_method_options'] = [
            'card' => ['request_three_d_secure' => 'automatic'],
        ];
        $metadata = $this->map($parameters['metadata']);
        $metadata['customer_reference'] = 42;
        $parameters['metadata'] = $metadata;
        $paymentIntentData = $this->map($parameters['payment_intent_data']);
        $paymentIntentData['description'] = 'Order ORD-TEST';
        $parameters['payment_intent_data'] = $paymentIntentData;
        $lineItems = $this->valueList($parameters['line_items']);
        $firstLine = $this->map($lineItems[0]);
        $firstLine['tax_rates'] = ['txr_custom'];
        $lineItems[0] = $firstLine;
        $parameters['line_items'] = $lineItems;
        $customizedRequest = new SessionRequest($parameters);

        $this->assertSame(
            $customizedRequest,
            (new SessionRequestValidator())->validate($this->standardRequest(), $customizedRequest),
        );
    }

    public function testAllowsOptionalStandardParametersToBeRemoved(): void
    {
        $parameters = $this->standardRequest()->parameters();
        unset($parameters['billing_address_collection']);
        $customizedRequest = new SessionRequest($parameters);

        $this->assertSame(
            $customizedRequest,
            (new SessionRequestValidator())->validate($this->standardRequest(), $customizedRequest),
        );
    }

    #[DataProvider('invalidSupportedParameters')]
    public function testRejectsInvalidSupportedParameters(callable $change, string $path): void
    {
        $parameters = $this->standardRequest()->parameters();
        $change($parameters);

        $this->assertRejected(
            parameters: $parameters,
            errorCode: 'session_request.parameter_invalid',
            path: $path,
        );
    }

    /** @return iterable<string, array{callable(array<string, mixed>&): void, string}> */
    public static function invalidSupportedParameters(): iterable
    {
        yield 'billing address collection' => [
            static function (array &$parameters): void {
                $parameters['billing_address_collection'] = 'sometimes';
            },
            'billing_address_collection',
        ];
        yield 'name collection enabled flag' => [
            static function (array &$parameters): void {
                $parameters['name_collection'] = [
                    'individual' => ['enabled' => 'true'],
                ];
            },
            'name_collection.individual.enabled',
        ];
        yield 'phone collection enabled flag' => [
            static function (array &$parameters): void {
                $parameters['phone_number_collection'] = ['enabled' => 1];
            },
            'phone_number_collection.enabled',
        ];
        yield 'tax ID requirement' => [
            static function (array &$parameters): void {
                $parameters['tax_id_collection'] = [
                    'enabled' => true,
                    'required' => 'always',
                ];
            },
            'tax_id_collection.required',
        ];
        yield 'consent value' => [
            static function (array &$parameters): void {
                $parameters['consent_collection'] = ['terms_of_service' => 'optional'];
            },
            'consent_collection.terms_of_service',
        ];
        yield 'promotion-code flag' => [
            static function (array &$parameters): void {
                $parameters['allow_promotion_codes'] = 1;
            },
            'allow_promotion_codes',
        ];
        yield 'custom-field key' => [
            static function (array &$parameters): void {
                $parameters['custom_fields'] = [[
                    'key' => 'VAT-number',
                    'label' => [
                        'custom' => 'VAT number',
                        'type' => 'custom',
                    ],
                    'optional' => true,
                    'type' => 'text',
                ]];
            },
            'custom_fields.0.key',
        ];
        yield 'too many custom fields' => [
            static function (array &$parameters): void {
                $parameters['custom_fields'] = [[], [], [], []];
            },
            'custom_fields',
        ];
    }

    #[DataProvider('protectedParameters')]
    public function testRejectsChangesOutsideTheLifecycleSafetyFloor(
        callable $change,
        string $errorCode,
        string $path,
    ): void {
        $parameters = $this->standardRequest()->parameters();
        $change($parameters);

        $this->assertRejected(
            parameters: $parameters,
            errorCode: $errorCode,
            path: $path,
        );
    }

    /** @return iterable<string, array{callable(array<string, mixed>&): void, string, string}> */
    public static function protectedParameters(): iterable
    {
        yield 'subscription mode' => [
            static function (array &$parameters): void {
                $parameters['mode'] = 'subscription';
            },
            'session_request.invariant_violation',
            'mode',
        ];
        yield 'other currency' => [
            static function (array &$parameters): void {
                $parameters['currency'] = 'usd';
            },
            'session_request.invariant_violation',
            'currency',
        ];
        yield 'other success URL' => [
            static function (array &$parameters): void {
                $parameters['success_url'] = 'https://example.com/success';
            },
            'session_request.invariant_violation',
            'success_url',
        ];
        yield 'missing Session correlation' => [
            static function (array &$parameters): void {
                $metadata = self::map($parameters['metadata']);
                unset($metadata['kirby_stripe_checkout_order']);
                $parameters['metadata'] = $metadata;
            },
            'session_request.invariant_violation',
            'metadata.kirby_stripe_checkout_order',
        ];
        yield 'forged private metadata' => [
            static function (array &$parameters): void {
                $metadata = self::map($parameters['metadata']);
                $metadata['kirby_stripe_checkout_extra'] = 'forged';
                $parameters['metadata'] = $metadata;
            },
            'session_request.parameter_protected',
            'metadata.kirby_stripe_checkout_extra',
        ];
        yield 'different line quantity' => [
            static function (array &$parameters): void {
                $lineItems = self::valueList($parameters['line_items']);
                $firstLine = self::map($lineItems[0]);
                $firstLine['quantity'] = 2;
                $lineItems[0] = $firstLine;
                $parameters['line_items'] = $lineItems;
            },
            'session_request.invariant_violation',
            'line_items.0.quantity',
        ];
        yield 'second line' => [
            static function (array &$parameters): void {
                $lineItems = self::valueList($parameters['line_items']);
                $lineItems[] = $lineItems[0];
                $parameters['line_items'] = $lineItems;
            },
            'session_request.invariant_violation',
            'line_items',
        ];
        yield 'inline amount' => [
            static function (array &$parameters): void {
                $lineItems = self::valueList($parameters['line_items']);
                $firstLine = self::map($lineItems[0]);
                $priceData = self::map($firstLine['price_data']);
                $priceData['unit_amount'] = 2_000;
                $firstLine['price_data'] = $priceData;
                $lineItems[0] = $firstLine;
                $parameters['line_items'] = $lineItems;
            },
            'session_request.invariant_violation',
            'line_items.0.price_data.unit_amount',
        ];
        yield 'adjustable quantity' => [
            static function (array &$parameters): void {
                $lineItems = self::valueList($parameters['line_items']);
                $firstLine = self::map($lineItems[0]);
                $firstLine['adjustable_quantity'] = ['enabled' => true];
                $lineItems[0] = $firstLine;
                $parameters['line_items'] = $lineItems;
            },
            'session_request.parameter_protected',
            'line_items.0.adjustable_quantity',
        ];
        yield 'recurring inline price' => [
            static function (array &$parameters): void {
                $lineItems = self::valueList($parameters['line_items']);
                $firstLine = self::map($lineItems[0]);
                $priceData = self::map($firstLine['price_data']);
                $priceData['recurring'] = ['interval' => 'month'];
                $firstLine['price_data'] = $priceData;
                $lineItems[0] = $firstLine;
                $parameters['line_items'] = $lineItems;
            },
            'session_request.parameter_protected',
            'line_items.0.price_data.recurring',
        ];
        yield 'manual capture' => [
            static function (array &$parameters): void {
                $paymentIntentData = self::map($parameters['payment_intent_data']);
                $paymentIntentData['capture_method'] = 'manual';
                $parameters['payment_intent_data'] = $paymentIntentData;
            },
            'session_request.parameter_protected',
            'payment_intent_data.capture_method',
        ];
        yield 'future payment method' => [
            static function (array &$parameters): void {
                $parameters['payment_method_options'] = [
                    'card' => ['setup_future_usage' => 'off_session'],
                ];
            },
            'session_request.parameter_protected',
            'payment_method_options.card.setup_future_usage',
        ];
        yield 'payment-method-specific manual capture' => [
            static function (array &$parameters): void {
                $parameters['payment_method_options'] = [
                    'card' => ['capture_method' => 'manual'],
                ];
            },
            'session_request.parameter_protected',
            'payment_method_options.card.capture_method',
        ];
        yield 'Connect transfer' => [
            static function (array &$parameters): void {
                $paymentIntentData = self::map($parameters['payment_intent_data']);
                $paymentIntentData['transfer_data'] = [
                    'destination' => 'acct_test',
                ];
                $parameters['payment_intent_data'] = $paymentIntentData;
            },
            'session_request.parameter_protected',
            'payment_intent_data.transfer_data',
        ];
        yield 'automatic-tax liability' => [
            static function (array &$parameters): void {
                $parameters['automatic_tax'] = [
                    'enabled' => true,
                    'liability' => ['type' => 'account'],
                ];
            },
            'session_request.parameter_protected',
            'automatic_tax.liability',
        ];
        yield 'private metadata outside correlation maps' => [
            static function (array &$parameters): void {
                $parameters['invoice_creation'] = [
                    'enabled' => true,
                    'invoice_data' => [
                        'metadata' => ['kirby_stripe_checkout_order' => 'forged'],
                    ],
                ];
            },
            'session_request.parameter_protected',
            'invoice_creation.invoice_data.metadata.kirby_stripe_checkout_order',
        ];
        yield 'optional items' => [
            static function (array &$parameters): void {
                $parameters['optional_items'] = [];
            },
            'session_request.parameter_protected',
            'optional_items',
        ];
    }

    public function testProtectionMatchesExactPathsRatherThanNestedNames(): void
    {
        $parameters = $this->standardRequest()->parameters();
        $parameters['invoice_creation'] = [
            'enabled' => true,
            'invoice_data' => [
                'metadata' => ['recurring' => 'allowed'],
            ],
        ];
        $parameters['payment_method_options'] = [
            'card' => [
                'network' => [
                    'liability' => 'allowed',
                    'setup_future_usage_hint' => 'allowed',
                ],
            ],
        ];
        $customizedRequest = new SessionRequest($parameters);

        $this->assertSame(
            $customizedRequest,
            (new SessionRequestValidator())->validate($this->standardRequest(), $customizedRequest),
        );
    }

    /** @param array<string, mixed> $parameters */
    private function assertRejected(array $parameters, string $errorCode, string $path): void
    {
        try {
            (new SessionRequestValidator())->validate(
                $this->standardRequest(),
                new SessionRequest($parameters),
            );
            $this->fail('Expected the customized request to be rejected.');
        } catch (InvalidSessionRequestException $error) {
            $this->assertSame($errorCode, $error->errorCode());
            $this->assertSame($path, $error->path());
        }
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertFalse(array_is_list($value));

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return list<mixed> */
    private static function valueList(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertTrue(array_is_list($value));

        /** @var list<mixed> $value */
        return $value;
    }

    private function standardRequest(): SessionRequest
    {
        return new SessionRequest([
            'billing_address_collection' => 'auto',
            'cancel_url' => 'https://example.com/stripe-checkout/cancel',
            'client_reference_id' => 'page://Order123',
            'currency' => 'eur',
            'expires_at' => 1_789_200_000,
            'integration_identifier' => 'kirby_stripe_checkout_abcdefgh',
            'line_items' => [[
                'metadata' => [
                    'kirby_stripe_checkout_line' => 'line-hash',
                    'kirby_stripe_checkout_order' => 'page://Order123',
                    'kirby_stripe_checkout_owner' => 'programmatordev/stripe-checkout',
                ],
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => ['name' => 'Product'],
                    'unit_amount' => 1_000,
                ],
                'quantity' => 1,
            ]],
            'locale' => 'en',
            'metadata' => [
                'kirby_stripe_checkout_order' => 'page://Order123',
                'kirby_stripe_checkout_owner' => 'programmatordev/stripe-checkout',
            ],
            'mode' => 'payment',
            'payment_intent_data' => [
                'metadata' => [
                    'kirby_stripe_checkout_order' => 'page://Order123',
                    'kirby_stripe_checkout_owner' => 'programmatordev/stripe-checkout',
                ],
            ],
            'success_url' => 'https://example.com/stripe-checkout/success?session_id={CHECKOUT_SESSION_ID}',
            'ui_mode' => 'hosted_page',
        ]);
    }
}
