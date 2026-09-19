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
    public function testAllowsPerOrderTaxOverridesAndStripeOwnedEventLocationValidation(): void
    {
        $parameters = $this->standardRequest()->parameters();
        $parameters['automatic_tax'] = ['enabled' => true];
        $lineItems = $this->valueList($parameters['line_items']);
        $lineItem = $this->map($lineItems[0]);
        $priceData = $this->map($lineItem['price_data']);
        $priceData['tax_behavior'] = 'inclusive';
        $productData = $this->map($priceData['product_data']);
        $productData['tax_details'] = [
            'performance_location' => 'taxloc_event',
            'tax_code' => 'txcd_20060058',
        ];
        $priceData['product_data'] = $productData;
        $lineItem['price_data'] = $priceData;
        // Even incompatible provider-owned combinations reach Stripe rather
        // than becoming a second local manual-rate/tax-location validator.
        $lineItem['tax_rates'] = ['txr_manual'];
        $lineItems[0] = $lineItem;
        $parameters['line_items'] = $lineItems;
        $customizedRequest = new SessionRequest($parameters);
        $validator = new SessionRequestValidator();
        $this->assertSame($customizedRequest, $validator->validate($this->standardRequest(), $customizedRequest));
        unset($parameters['automatic_tax']);
        $customizedRequest = new SessionRequest($parameters);
        $this->assertSame($customizedRequest, $validator->validate($this->standardRequest(), $customizedRequest));
    }

    public function testRejectsAnInvalidInlineTaxBehavior(): void
    {
        $parameters = $this->standardRequest()->parameters();
        $lineItems = $this->valueList($parameters['line_items']);
        $lineItem = $this->map($lineItems[0]);
        $priceData = $this->map($lineItem['price_data']);
        $priceData['tax_behavior'] = 'sometimes';
        $lineItem['price_data'] = $priceData;
        $lineItems[0] = $lineItem;
        $parameters['line_items'] = $lineItems;
        $this->assertRejected($parameters, 'session_request.parameter_invalid', 'line_items.0.price_data.tax_behavior');
    }

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
        $parameters['payment_method_configuration'] = 'pmc_test';
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

    public function testAllowsShippingBusinessOverridesWhilePreservingCorrelation(): void
    {
        $request = $this->standardShippingRequest();
        $parameters = $request->parameters();
        $options = $this->valueList($parameters['shipping_options']);
        $option = $this->map($options[0]);
        $data = $this->map($option['shipping_rate_data']);
        $data['display_name'] = 'Next-day delivery';
        $data['fixed_amount'] = [
            'amount' => 1_250,
            'currency' => 'eur',
        ];
        $data['tax_behavior'] = 'exclusive';
        $data['tax_code'] = 'txcd_92010001';
        $option['shipping_rate_data'] = $data;
        $options[0] = $option;
        $parameters['shipping_options'] = $options;
        $customizedRequest = new SessionRequest($parameters);

        $this->assertSame(
            $customizedRequest,
            (new SessionRequestValidator())->validate($request, $customizedRequest),
        );
    }

    #[DataProvider('invalidShippingChanges')]
    public function testRejectsShippingChangesThatBreakInitiatingEvidence(
        callable $change,
        string $errorCode,
        string $path,
    ): void {
        $request = $this->standardShippingRequest();
        $parameters = $request->parameters();
        $change($parameters);

        try {
            (new SessionRequestValidator())->validate(
                $request,
                new SessionRequest($parameters),
            );
            $this->fail('Expected the customized shipping request to be rejected.');
        } catch (InvalidSessionRequestException $error) {
            $this->assertSame($errorCode, $error->errorCode());
            $this->assertSame($path, $error->path());
        }
    }

    /** @return iterable<string, array{callable(array<string, mixed>&): void, string, string}> */
    public static function invalidShippingChanges(): iterable
    {
        yield 'destination scope' => [
            static function (array &$parameters): void {
                $parameters['shipping_address_collection'] = [
                    'allowed_countries' => ['ES'],
                ];
            },
            'session_request.invariant_violation',
            'shipping_address_collection',
        ];
        yield 'missing option' => [
            static function (array &$parameters): void {
                $parameters['shipping_options'] = [];
            },
            'session_request.invariant_violation',
            'shipping_options',
        ];
        yield 'reusable Shipping Rate' => [
            static function (array &$parameters): void {
                $parameters['shipping_options'] = [[
                    'shipping_rate' => 'shr_existing',
                ]];
            },
            'session_request.parameter_protected',
            'shipping_options.0.shipping_rate',
        ];
        yield 'missing option correlation' => [
            static function (array &$parameters): void {
                $options = self::valueList($parameters['shipping_options']);
                $option = self::map($options[0]);
                $data = self::map($option['shipping_rate_data']);
                $metadata = self::map($data['metadata']);
                unset($metadata['kirby_stripe_checkout_shipping_option']);
                $data['metadata'] = $metadata;
                $option['shipping_rate_data'] = $data;
                $options[0] = $option;
                $parameters['shipping_options'] = $options;
            },
            'session_request.invariant_violation',
            'shipping_options.0.shipping_rate_data.metadata.kirby_stripe_checkout_shipping_option',
        ];
    }

    #[DataProvider('invalidSupportedShippingParameters')]
    public function testRejectsInvalidSupportedShippingParameters(
        callable $change,
        string $path,
    ): void {
        $request = $this->standardShippingRequest();
        $parameters = $request->parameters();
        $change($parameters);

        try {
            (new SessionRequestValidator())->validate(
                $request,
                new SessionRequest($parameters),
            );
            $this->fail('Expected the customized shipping parameter to be rejected.');
        } catch (InvalidSessionRequestException $error) {
            $this->assertSame('session_request.parameter_invalid', $error->errorCode());
            $this->assertSame($path, $error->path());
        }
    }

    /** @return iterable<string, array{callable(array<string, mixed>&): void, string}> */
    public static function invalidSupportedShippingParameters(): iterable
    {
        yield 'display name' => [
            static function (array &$parameters): void {
                self::changeShippingRateData(
                    $parameters,
                    static function (array &$data): void {
                        $data['display_name'] = str_repeat('x', 101);
                    },
                );
            },
            'shipping_options.0.shipping_rate_data.display_name',
        ];
        yield 'provider amount' => [
            static function (array &$parameters): void {
                self::changeShippingRateData(
                    $parameters,
                    static function (array &$data): void {
                        $data['fixed_amount'] = [
                            'amount' => -1,
                            'currency' => 'eur',
                        ];
                    },
                );
            },
            'shipping_options.0.shipping_rate_data.fixed_amount',
        ];
        yield 'delivery estimate' => [
            static function (array &$parameters): void {
                self::changeShippingRateData(
                    $parameters,
                    static function (array &$data): void {
                        $data['delivery_estimate'] = [
                            'minimum' => [
                                'unit' => 'business_day',
                                'value' => 0,
                            ],
                        ];
                    },
                );
            },
            'shipping_options.0.shipping_rate_data.delivery_estimate.minimum',
        ];
        yield 'reversed delivery estimate' => [
            static function (array &$parameters): void {
                self::changeShippingRateData(
                    $parameters,
                    static function (array &$data): void {
                        $data['delivery_estimate'] = [
                            'minimum' => [
                                'unit' => 'business_day',
                                'value' => 5,
                            ],
                            'maximum' => [
                                'unit' => 'business_day',
                                'value' => 3,
                            ],
                        ];
                    },
                );
            },
            'shipping_options.0.shipping_rate_data.delivery_estimate.maximum',
        ];
        yield 'tax behavior' => [
            static function (array &$parameters): void {
                self::changeShippingRateData(
                    $parameters,
                    static function (array &$data): void {
                        $data['tax_behavior'] = 'sometimes';
                    },
                );
            },
            'shipping_options.0.shipping_rate_data.tax_behavior',
        ];
    }

    public function testRejectsShippingParametersForADigitalOrder(): void
    {
        $parameters = $this->standardShippingRequest()->parameters();

        $this->assertRejected(
            parameters: $parameters,
            errorCode: 'session_request.parameter_protected',
            path: 'shipping_address_collection',
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
        yield 'automatic tax enabled flag' => [
            static function (array &$parameters): void {
                $parameters['automatic_tax'] = ['enabled' => 'true'];
            },
            'automatic_tax.enabled',
        ];
        yield 'automatic tax required flag' => [
            static function (array &$parameters): void {
                $parameters['automatic_tax'] = ['unknown' => true];
            },
            'automatic_tax.enabled',
        ];
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
        yield 'static payment method types' => [
            static function (array &$parameters): void {
                $parameters['payment_method_types'] = ['card'];
            },
            'session_request.parameter_protected',
            'payment_method_types',
        ];
        yield 'connected-account invoice issuer' => [
            static function (array &$parameters): void {
                $parameters['invoice_creation'] = [
                    'enabled' => true,
                    'invoice_data' => [
                        'issuer' => [
                            'account' => 'acct_test',
                            'type' => 'account',
                        ],
                    ],
                ];
            },
            'session_request.parameter_protected',
            'invoice_creation.invoice_data.issuer',
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

    /**
     * @param array<mixed, mixed> $parameters
     * @param callable(array<mixed, mixed>&): void $change
     */
    private static function changeShippingRateData(
        array &$parameters,
        callable $change,
    ): void {
        $options = self::valueList($parameters['shipping_options']);
        $option = self::map($options[0]);
        $data = self::map($option['shipping_rate_data']);
        $change($data);
        $option['shipping_rate_data'] = $data;
        $options[0] = $option;
        $parameters['shipping_options'] = $options;
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

    private function standardShippingRequest(): SessionRequest
    {
        $parameters = $this->standardRequest()->parameters();
        $parameters['shipping_address_collection'] = [
            'allowed_countries' => ['PT'],
        ];
        $parameters['shipping_options'] = [[
            'shipping_rate_data' => [
                'delivery_estimate' => [
                    'minimum' => [
                        'unit' => 'business_day',
                        'value' => 2,
                    ],
                ],
                'display_name' => 'Standard delivery',
                'fixed_amount' => [
                    'amount' => 500,
                    'currency' => 'eur',
                ],
                'metadata' => [
                    'kirby_stripe_checkout_order' => 'page://Order123',
                    'kirby_stripe_checkout_owner' => 'programmatordev/stripe-checkout',
                    'kirby_stripe_checkout_shipping_option' => 'standard',
                    'kirby_stripe_checkout_shipping_quote' => str_repeat('a', 64),
                ],
                'type' => 'fixed_amount',
            ],
        ]];

        return new SessionRequest($parameters);
    }
}
