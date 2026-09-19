<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\InitiatingShippingSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestBuilder;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimate;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimateUnit;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Shipping\StripeShippingCountryRegistry;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;
use ProgrammatorDev\StripeCheckout\Test\Support\InitiatingShippingSnapshotFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;

final class SessionRequestBuilderTest extends KirbyTestCase
{
    #[DataProvider('taxPolicies')]
    public function testMapsTaxDefaultsForBothCheckoutModes(bool $enabled, string $behavior, UiMode $uiMode): void
    {
        $this->restart(options: [
            'programmatordev.stripe-checkout' => [
                'settings' => [
                    'automaticTax' => $enabled,
                    'taxBehavior' => $behavior,
                    'uiMode' => $uiMode->value,
                ],
            ],
        ]);
        $order = $this->inlineOrder(uiMode: $uiMode, taxCode: new TaxCode('txcd_33020002'));
        $snapshot = $order->lineItems()[0];
        $this->assertSame('txcd_33020002', $snapshot['taxCode']);
        $this->assertSame($snapshot, OrderLineItemSnapshot::fromArray($snapshot)->toArray());
        $parameters = $this->build($this->context($uiMode, $order))->parameters();
        $this->assertIsArray($parameters['line_items']);
        $this->assertIsArray($parameters['line_items'][0]);
        $priceData = $parameters['line_items'][0]['price_data'];
        $this->assertIsArray($priceData);
        $this->assertIsArray($priceData['product_data']);

        if ($enabled) {
            $this->assertSame(['enabled' => true], $parameters['automatic_tax']);
            $this->assertSame('txcd_33020002', $priceData['product_data']['tax_code']);
        } else {
            $this->assertArrayNotHasKey('automatic_tax', $parameters);
            $this->assertArrayNotHasKey('tax_code', $priceData['product_data']);
        }

        if ($enabled && $behavior !== 'stripe_default') {
            $this->assertSame($behavior, $priceData['tax_behavior']);
        } else {
            $this->assertArrayNotHasKey('tax_behavior', $priceData);
        }
    }

    /** @return iterable<string, array{bool, string, UiMode}> */
    public static function taxPolicies(): iterable
    {
        $behaviors = ['stripe_default', 'inclusive', 'exclusive'];

        foreach (UiMode::cases() as $uiMode) {
            foreach ($behaviors as $behavior) {
                yield $uiMode->value . ' ' . $behavior => [true, $behavior, $uiMode];
                yield $uiMode->value . ' disabled ' . $behavior => [false, $behavior, $uiMode];
            }
        }
    }

    public function testStripePricesRemainAuthoritativeWithAutomaticTax(): void
    {
        $this->restart(options: [
            'programmatordev.stripe-checkout' => [
                'settings' => [
                    'automaticTax' => true,
                    'taxBehavior' => 'inclusive',
                ],
            ],
        ]);
        $parameters = $this->build($this->context(UiMode::Embedded, $this->stripePriceOrder()))->parameters();
        $this->assertSame(['enabled' => true], $parameters['automatic_tax']);
        $this->assertIsArray($parameters['line_items']);
        $this->assertIsArray($parameters['line_items'][0]);
        $this->assertSame('price_standard', $parameters['line_items'][0]['price']);
        $this->assertArrayNotHasKey('price_data', $parameters['line_items'][0]);
    }

    public function testOmittedClassificationDelegatesToStripePreset(): void
    {
        $this->restart(options: [
            'programmatordev.stripe-checkout' => [
                'settings' => ['automaticTax' => true],
            ],
        ]);
        $parameters = $this->build($this->context(UiMode::Hosted, $this->inlineOrder()))->parameters();
        $this->assertIsArray($parameters['line_items']);
        $this->assertIsArray($parameters['line_items'][0]);
        $this->assertIsArray($parameters['line_items'][0]['price_data']);
        $this->assertIsArray($parameters['line_items'][0]['price_data']['product_data']);
        $this->assertArrayNotHasKey('tax_code', $parameters['line_items'][0]['price_data']['product_data']);
    }

    #[DataProvider('checkoutModes')]
    public function testMapsTheAcceptedShippingQuoteForBothCheckoutModes(UiMode $uiMode): void
    {
        $this->restart(options: [
            'programmatordev.stripe-checkout' => [
                'settings' => [
                    'automaticTax' => true,
                    'uiMode' => $uiMode->value,
                ],
            ],
        ]);
        $order = $this->inlineOrder(uiMode: $uiMode);
        $checkout = InitiatingShippingSnapshotFactory::checkoutFromOrder($order);
        $shipping = InitiatingShippingSnapshot::fromQuote(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            quote: ShippingQuote::available([
                new ShippingOption(
                    key: 'express',
                    label: 'Express delivery',
                    amount: Money::of('10.25', 'EUR'),
                    deliveryEstimate: new DeliveryEstimate(
                        minimum: 1,
                        maximum: 2,
                        unit: DeliveryEstimateUnit::BusinessDay,
                    ),
                    taxBehavior: TaxBehavior::Inclusive,
                    taxCode: 'txcd_92010001',
                ),
                new ShippingOption(
                    key: 'free',
                    label: 'Free delivery',
                    amount: Money::of('0', 'EUR'),
                ),
            ]),
        );
        $parameters = $this->builder()
            ->build($this->context($uiMode, $order), $shipping)
            ->parameters();

        $this->assertSame([
            'allowed_countries' => ['PT'],
        ], $parameters['shipping_address_collection']);
        $this->assertSame([
            [
                'shipping_rate_data' => [
                    'delivery_estimate' => [
                        'maximum' => [
                            'unit' => 'business_day',
                            'value' => 2,
                        ],
                        'minimum' => [
                            'unit' => 'business_day',
                            'value' => 1,
                        ],
                    ],
                    'display_name' => 'Express delivery',
                    'fixed_amount' => [
                        'amount' => 1_025,
                        'currency' => 'eur',
                    ],
                    'metadata' => [
                        'kirby_stripe_checkout_order' => $order->pageUuid(),
                        'kirby_stripe_checkout_owner' => 'programmatordev/stripe-checkout',
                        'kirby_stripe_checkout_shipping_option' => 'express',
                        'kirby_stripe_checkout_shipping_quote' => $shipping->quoteFingerprint(),
                    ],
                    'tax_behavior' => 'inclusive',
                    'tax_code' => 'txcd_92010001',
                    'type' => 'fixed_amount',
                ],
            ],
            [
                'shipping_rate_data' => [
                    'display_name' => 'Free delivery',
                    'fixed_amount' => [
                        'amount' => 0,
                        'currency' => 'eur',
                    ],
                    'metadata' => [
                        'kirby_stripe_checkout_order' => $order->pageUuid(),
                        'kirby_stripe_checkout_owner' => 'programmatordev/stripe-checkout',
                        'kirby_stripe_checkout_shipping_option' => 'free',
                        'kirby_stripe_checkout_shipping_quote' => $shipping->quoteFingerprint(),
                    ],
                    'type' => 'fixed_amount',
                ],
            ],
        ], $parameters['shipping_options']);
    }

    public function testMapsCountrylessQuotesToTheCompleteCheckoutCountryPolicy(): void
    {
        $order = $this->inlineOrder();
        $shipping = InitiatingShippingSnapshotFactory::fromOrder(
            order: $order,
            shippingCountry: null,
        );
        $parameters = $this->builder()
            ->build($this->context(UiMode::Hosted, $order), $shipping)
            ->parameters();
        $collection = $parameters['shipping_address_collection'] ?? null;
        $this->assertIsArray($collection);

        $this->assertSame(
            (new StripeShippingCountryRegistry())->codes(),
            $collection['allowed_countries'] ?? null,
        );
    }

    public function testOmitsShippingTaxPolicyWhenAutomaticTaxIsDisabled(): void
    {
        $order = $this->inlineOrder();
        $shipping = InitiatingShippingSnapshot::fromQuote(
            checkout: InitiatingShippingSnapshotFactory::checkoutFromOrder($order),
            shipping: new ShippingContext('PT'),
            quote: ShippingQuote::available([new ShippingOption(
                key: 'standard',
                label: 'Standard delivery',
                amount: Money::of('5', 'EUR'),
                taxBehavior: TaxBehavior::Exclusive,
                taxCode: 'txcd_92010001',
            )]),
        );
        $parameters = $this->builder()
            ->build($this->context(UiMode::Hosted, $order), $shipping)
            ->parameters();
        $options = $parameters['shipping_options'] ?? null;
        $this->assertIsArray($options);
        $option = $options[0] ?? null;
        $this->assertIsArray($option);
        $data = $option['shipping_rate_data'] ?? null;
        $this->assertIsArray($data);

        $this->assertArrayNotHasKey('tax_behavior', $data);
        $this->assertArrayNotHasKey('tax_code', $data);
    }

    public function testRejectsMissingInitiatingShippingForAShippableOrder(): void
    {
        $order = $this->inlineOrder();

        $this->expectException(LogicException::class);
        $this->builder()->build($this->context(UiMode::Hosted, $order), null);
    }

    /** @return iterable<string, array{UiMode}> */
    public static function checkoutModes(): iterable
    {
        foreach (UiMode::cases() as $uiMode) {
            yield $uiMode->value => [$uiMode];
        }
    }

    public function testBuildsTheProtectedHostedInlineRequest(): void
    {
        $context = $this->context(UiMode::Hosted, $this->inlineOrder());
        $request = $this->build($context);
        $parameters = $request->parameters();

        $this->assertSame('payment', $parameters['mode']);
        $this->assertSame('hosted_page', $parameters['ui_mode']);
        $this->assertSame('eur', $parameters['currency']);
        $this->assertSame('pt', $parameters['locale']);
        $this->assertSame(1_789_200_000, $parameters['expires_at']);
        $this->assertSame('page://Abc123def456GHI7', $parameters['client_reference_id']);
        $this->assertIsString($parameters['integration_identifier']);
        $this->assertMatchesRegularExpression(
            '/\Akirby_stripe_checkout_[a-z]{8}\z/',
            $parameters['integration_identifier'],
        );
        $this->assertSame([
            'kirby_stripe_checkout_order' => 'page://Abc123def456GHI7',
            'kirby_stripe_checkout_owner' => 'programmatordev/stripe-checkout',
        ], $parameters['metadata']);
        $this->assertIsArray($parameters['payment_intent_data']);
        $this->assertSame($parameters['metadata'], $parameters['payment_intent_data']['metadata'] ?? null);
        $this->assertSame(
            'https://kirby-stripe-checkout.test/stripe-checkout/success?_stripe_checkout_order=page%3A%2F%2FAbc123def456GHI7&session_id={CHECKOUT_SESSION_ID}',
            $parameters['success_url'],
        );
        $this->assertSame(
            'https://kirby-stripe-checkout.test/stripe-checkout/cancel?_stripe_checkout_order=page%3A%2F%2FAbc123def456GHI7',
            $parameters['cancel_url'],
        );
        $this->assertArrayNotHasKey('return_url', $parameters);
        $this->assertArrayNotHasKey('redirect_on_completion', $parameters);
        $this->assertArrayNotHasKey('payment_method_types', $parameters);
        $this->assertSame('auto', $parameters['billing_address_collection']);
        $this->assertSame([
            'individual' => [
                'enabled' => true,
                'optional' => true,
            ],
        ], $parameters['name_collection']);
        $this->assertArrayNotHasKey('phone_number_collection', $parameters);
        $this->assertArrayNotHasKey('tax_id_collection', $parameters);
        $this->assertArrayNotHasKey('consent_collection', $parameters);
        $this->assertArrayNotHasKey('custom_fields', $parameters);
        $this->assertArrayNotHasKey('allow_promotion_codes', $parameters);

        $this->assertIsArray($parameters['line_items']);
        $lineItem = $parameters['line_items'][0] ?? null;
        $this->assertIsArray($lineItem);
        $this->assertSame(2, $lineItem['quantity']);
        $this->assertSame([
            'currency' => 'eur',
            'product_data' => [
                'description' => 'Heavy canvas.',
                'images' => ['https://example.com/bag.jpg'],
                'name' => 'Canvas bag',
            ],
            'unit_amount' => 1_600,
        ], $lineItem['price_data']);
        $this->assertIsArray($lineItem['metadata']);
        $this->assertSame('programmatordev/stripe-checkout', $lineItem['metadata']['kirby_stripe_checkout_owner'] ?? null);
        $this->assertSame('page://Abc123def456GHI7', $lineItem['metadata']['kirby_stripe_checkout_order'] ?? null);
        $this->assertIsString($lineItem['metadata']['kirby_stripe_checkout_line'] ?? null);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $lineItem['metadata']['kirby_stripe_checkout_line']);
    }

    public function testBuildsTheProtectedEmbeddedStripePriceRequest(): void
    {
        $context = $this->context(UiMode::Embedded, $this->stripePriceOrder());
        $parameters = $this
            ->build($context)
            ->parameters();

        $this->assertSame('embedded_page', $parameters['ui_mode']);
        $this->assertSame('always', $parameters['redirect_on_completion']);
        $this->assertSame(
            'https://kirby-stripe-checkout.test/stripe-checkout/return?_stripe_checkout_order=page%3A%2F%2FAbc123def456GHI7&session_id={CHECKOUT_SESSION_ID}',
            $parameters['return_url'],
        );
        $this->assertArrayNotHasKey('success_url', $parameters);
        $this->assertArrayNotHasKey('cancel_url', $parameters);
        $this->assertIsArray($parameters['line_items']);
        $lineItem = $parameters['line_items'][0] ?? null;
        $this->assertIsArray($lineItem);
        $this->assertSame('price_standard', $lineItem['price']);
        $this->assertArrayNotHasKey('price_data', $lineItem);
        $this->assertArrayNotHasKey('shipping_address_collection', $parameters);
        $this->assertArrayNotHasKey('shipping_options', $parameters);
    }

    public function testKeepsTheInstallationIdentifierStable(): void
    {
        $builder = $this->builder();
        $context = $this->context(UiMode::Hosted, $this->inlineOrder());
        $shipping = InitiatingShippingSnapshotFactory::fromOrder($context->order());
        $first = $builder->build($context, $shipping)->parameters()['integration_identifier'];
        $second = $this->builder()
            ->build($context, $shipping)
            ->parameters()['integration_identifier'];

        $this->assertSame($first, $second);
    }

    public function testAllowsAZeroAmountInlineOrderWithoutChangingThePaymentMode(): void
    {
        $parameters = $this
            ->build($this->context(UiMode::Hosted, $this->inlineOrder(amount: '0')))
            ->parameters();
        $this->assertIsArray($parameters['line_items']);
        $lineItem = $parameters['line_items'][0] ?? null;
        $this->assertIsArray($lineItem);
        $this->assertIsArray($lineItem['price_data']);

        $this->assertSame('payment', $parameters['mode']);
        $this->assertSame(0, $lineItem['price_data']['unit_amount']);
        $this->assertArrayNotHasKey('payment_method_types', $parameters);
    }

    #[DataProvider('modes')]
    public function testUsesLanguageAwareInternalRoutes(UiMode $mode, string $routeKey): void
    {
        $this->restart(languages: [
            [
                'code' => 'en',
                'default' => true,
                'locale' => 'en_GB',
                'name' => 'English',
            ],
            [
                'code' => 'pt',
                'locale' => 'pt_PT',
                'name' => 'Português',
            ],
        ]);
        $order = $this->inlineOrder(languageCode: 'pt', uiMode: $mode);
        $parameters = $this
            ->build($this->context($mode, $order))
            ->parameters();
        $this->assertIsString($parameters[$routeKey]);

        $this->assertStringStartsWith(
            'https://kirby-stripe-checkout.test/pt/stripe-checkout/',
            $parameters[$routeKey],
        );
    }

    /** @return iterable<string, array{UiMode, string}> */
    public static function modes(): iterable
    {
        yield 'hosted' => [UiMode::Hosted, 'success_url'];
        yield 'embedded' => [UiMode::Embedded, 'return_url'];
    }

    public function testMapsEnabledCollectionAndPromotionSettings(): void
    {
        $this->restart(options: [
            'programmatordev.stripe-checkout' => [
                'settings' => [
                    'allowPromotionCodes' => true,
                    'billingAddressCollection' => 'required',
                    'businessNameCollection' => 'optional',
                    'customFields' => [
                        [
                            'key' => 'reference',
                            'label' => 'Order reference',
                            'required' => true,
                            'type' => 'text',
                        ],
                        [
                            'defaultValue' => '25',
                            'key' => 'age',
                            'label' => 'Age',
                            'maximumLength' => 3,
                            'minimumLength' => 1,
                            'type' => 'numeric',
                        ],
                        [
                            'defaultValue' => 'gift',
                            'key' => 'purpose',
                            'label' => 'Purpose',
                            'options' => [
                                [
                                    'label' => 'Gift',
                                    'value' => 'gift',
                                ],
                                [
                                    'label' => 'Personal',
                                    'value' => 'personal',
                                ],
                            ],
                            'required' => true,
                            'type' => 'dropdown',
                        ],
                    ],
                    'individualNameCollection' => 'required',
                    'phoneNumberCollection' => true,
                    'promotionsConsent' => true,
                    'taxIdCollection' => 'required_if_supported',
                    'termsOfServiceConsent' => true,
                ],
            ],
        ]);

        $parameters = $this
            ->build($this->context(UiMode::Hosted, $this->inlineOrder()))
            ->parameters();

        $this->assertSame('required', $parameters['billing_address_collection']);
        $this->assertSame([
            'business' => [
                'enabled' => true,
                'optional' => true,
            ],
            'individual' => [
                'enabled' => true,
                'optional' => false,
            ],
        ], $parameters['name_collection']);
        $this->assertSame(['enabled' => true], $parameters['phone_number_collection']);
        $this->assertSame([
            'enabled' => true,
            'required' => 'if_supported',
        ], $parameters['tax_id_collection']);
        $this->assertSame([
            'promotions' => 'auto',
            'terms_of_service' => 'required',
        ], $parameters['consent_collection']);
        $this->assertSame([
            [
                'key' => 'reference',
                'label' => [
                    'custom' => 'Order reference',
                    'type' => 'custom',
                ],
                'optional' => false,
                'type' => 'text',
            ],
            [
                'key' => 'age',
                'label' => [
                    'custom' => 'Age',
                    'type' => 'custom',
                ],
                'numeric' => [
                    'default_value' => '25',
                    'maximum_length' => 3,
                    'minimum_length' => 1,
                ],
                'optional' => true,
                'type' => 'numeric',
            ],
            [
                'dropdown' => [
                    'default_value' => 'gift',
                    'options' => [
                        [
                            'label' => 'Gift',
                            'value' => 'gift',
                        ],
                        [
                            'label' => 'Personal',
                            'value' => 'personal',
                        ],
                    ],
                ],
                'key' => 'purpose',
                'label' => [
                    'custom' => 'Purpose',
                    'type' => 'custom',
                ],
                'optional' => false,
                'type' => 'dropdown',
            ],
        ], $parameters['custom_fields']);
        $this->assertTrue($parameters['allow_promotion_codes']);
        $this->assertArrayNotHasKey('payment_method_types', $parameters);
    }

    public function testOmitsDisabledNamesAndMapsOptionalTaxIds(): void
    {
        $this->restart(options: [
            'programmatordev.stripe-checkout' => [
                'settings' => [
                    'individualNameCollection' => 'off',
                    'businessNameCollection' => 'off',
                    'taxIdCollection' => 'optional',
                ],
            ],
        ]);

        $parameters = $this
            ->build($this->context(UiMode::Embedded, $this->stripePriceOrder()))
            ->parameters();

        $this->assertArrayNotHasKey('name_collection', $parameters);
        $this->assertSame([
            'enabled' => true,
            'required' => 'never',
        ], $parameters['tax_id_collection']);
    }

    public function testMapsCustomFieldsWithTheActiveLanguageLabels(): void
    {
        $this->restart(
            options: [
                'programmatordev.stripe-checkout' => [
                    'settings' => [
                        'customFields' => [[
                            'key' => 'purpose',
                            'label' => 'Purpose',
                            'labels' => ['pt' => 'Finalidade'],
                            'options' => [[
                                'label' => 'Gift',
                                'labels' => ['pt' => 'Presente'],
                                'value' => 'gift',
                            ]],
                            'type' => 'dropdown',
                        ]],
                    ],
                ],
            ],
            languages: [
                [
                    'code' => 'en',
                    'default' => true,
                    'locale' => 'en_GB',
                    'name' => 'English',
                ],
                [
                    'code' => 'pt',
                    'locale' => 'pt_PT',
                    'name' => 'Português',
                ],
            ],
        );
        $this->kirby->setCurrentLanguage('pt');

        $parameters = $this
            ->build($this->context(UiMode::Hosted, $this->inlineOrder(languageCode: 'pt')))
            ->parameters();
        $customFields = $parameters['custom_fields'] ?? null;

        $this->assertIsArray($customFields);
        $customField = $customFields[0] ?? null;
        $this->assertIsArray($customField);
        $label = $customField['label'] ?? null;
        $dropdown = $customField['dropdown'] ?? null;
        $this->assertIsArray($label);
        $this->assertIsArray($dropdown);
        $options = $dropdown['options'] ?? null;
        $this->assertIsArray($options);
        $option = $options[0] ?? null;
        $this->assertIsArray($option);

        $this->assertSame('Finalidade', $label['custom'] ?? null);
        $this->assertSame('Presente', $option['label'] ?? null);
    }

    private function builder(): SessionRequestBuilder
    {
        return new SessionRequestBuilder(
            kirby: $this->kirby,
            settings: (new RuntimeFactory($this->kirby))->settings(),
        );
    }

    private function build(SessionRequestContext $context): SessionRequest
    {
        $order = $context->order();

        return $this->builder()->build(
            $context,
            $order->requiresShipping()
                ? InitiatingShippingSnapshotFactory::fromOrder($order)
                : null,
        );
    }

    private function context(
        UiMode $uiMode,
        OrderCreationContext $order,
    ): SessionRequestContext {
        return new SessionRequestContext(
            order: $order,
            locale: 'pt',
            expiresAt: new DateTimeImmutable('2026-09-12T08:00:00Z'),
            initiatingUrl: 'https://kirby-stripe-checkout.test/product',
            successDestination: 'https://kirby-stripe-checkout.test/complete',
            cancelDestination: 'https://kirby-stripe-checkout.test/product',
            returnDestination: 'https://kirby-stripe-checkout.test/product',
        );
    }

    private function inlineOrder(
        ?string $languageCode = null,
        UiMode $uiMode = UiMode::Hosted,
        string $amount = '16.00',
        ?TaxCode $taxCode = null,
    ): OrderCreationContext {
        $price = Money::of($amount, 'EUR');
        $product = new Product(
            new ProductRequest('canvas-bag', 2),
            'Canvas bag',
            true,
            new Price($price),
            description: 'Heavy canvas.',
            imageUrls: ['https://example.com/bag.jpg'],
            taxCode: $taxCode,
        );

        return $this->order(
            OrderLineItemSnapshot::fromProduct($product, $price),
            $languageCode,
            $uiMode,
        );
    }

    private function stripePriceOrder(): OrderCreationContext
    {
        $price = Money::of('25.00', 'EUR');
        $product = new Product(
            new ProductRequest('stripe-shirt'),
            'Stripe shirt',
            false,
            new StripePriceReference('price_standard'),
        );

        return $this->order(
            OrderLineItemSnapshot::fromProduct($product, $price, 'prod_standard'),
            uiMode: UiMode::Embedded,
        );
    }

    private function order(
        OrderLineItemSnapshot $lineItem,
        ?string $languageCode = null,
        UiMode $uiMode = UiMode::Hosted,
    ): OrderCreationContext {
        return new OrderCreationContext(
            uuid: 'Abc123def456GHI7',
            orderNumber: 'ORD-ABC123DEF456GHI7',
            checkoutSource: CheckoutSource::Direct,
            cartRevision: null,
            userUuid: null,
            languageCode: $languageCode,
            uiMode: $uiMode,
            currency: 'EUR',
            lineItems: [$lineItem],
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param list<array<string, mixed>>|null $languages
     */
    private function restart(array $options = [], ?array $languages = null): void
    {
        $this->environment->close();
        $this->environment = \ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment::start(
            options: $options,
            languages: $languages,
        );
        $this->kirby = $this->environment->app();
    }
}
