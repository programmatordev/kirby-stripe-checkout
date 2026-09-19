<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Cart\CartErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Exception\MoneyException;
use ProgrammatorDev\StripeCheckout\Money\MoneyErrorCode;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuoteStatus;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

final class DirectShippingContextTest extends KirbyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->restart();
    }

    public function testDirectInputBuildsTheSharedCheckoutAndShippingContexts(): void
    {
        $runtime = new RuntimeFactory($this->kirby);
        $checkout = $runtime->directCheckoutContext([
            ['reference' => 'digital', 'quantity' => 2],
            ['reference' => 'physical', 'quantity' => 2],
            ['reference' => 'physical'],
        ]);
        $shipping = $runtime->directShippingContext('PT');
        $quote = $runtime->resolveShippingQuote($checkout, $shipping);

        $this->assertSame(CheckoutSource::Direct, $checkout->checkoutSource());
        $this->assertCount(2, $checkout->items());
        $this->assertCount(1, $checkout->shippableItems());
        $this->assertSame('physical', $checkout->shippableItems()[0]->productReference());
        $this->assertSame(3, $checkout->shippableItems()[0]->quantity());
        $this->assertSame('44.00', (string) $checkout->subtotal()->getAmount());
        $this->assertSame('PT', $shipping->shippingCountry());
        $this->assertSame(TaxBehavior::StripeDefault, $shipping->taxBehavior());
        $this->assertNull($shipping->taxCode());
        $this->assertNotNull($quote);
        $this->assertSame(ShippingQuoteStatus::Available, $quote->status());
        $this->assertSame('standard', $quote->options()[0]->key());
    }

    public function testMissingCountryUsesTheNormalQuoteOutcome(): void
    {
        $runtime = new RuntimeFactory($this->kirby);
        $checkout = $runtime->directCheckoutContext([
            ['reference' => 'physical'],
        ]);
        $shipping = $runtime->directShippingContext();
        $quote = $runtime->resolveShippingQuote($checkout, $shipping);

        $this->assertNull($shipping->shippingCountry());
        $this->assertNotNull($quote);
        $this->assertSame(ShippingQuoteStatus::CountryRequired, $quote->status());
        $this->assertSame(ShippingErrorCode::COUNTRY_REQUIRED, $quote->reasonCode());
    }

    #[DataProvider('invalidShippingCountries')]
    public function testDirectInputRejectsInvalidShippingCountries(mixed $shippingCountry): void
    {
        try {
            (new RuntimeFactory($this->kirby))->directShippingContext($shippingCountry);
            self::fail('The direct shipping country should be rejected.');
        } catch (CheckoutInputException $error) {
            $this->assertSame(ShippingErrorCode::COUNTRY_INVALID, $error->errorCode());
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidShippingCountries(): iterable
    {
        yield 'empty string' => [''];
        yield 'lowercase code' => ['pt'];
        yield 'unsupported code' => ['CU'];
        yield 'integer' => [1];
        yield 'array' => [[]];
    }

    public function testDigitalOnlyDirectInputSkipsShippingResolution(): void
    {
        $runtime = new RuntimeFactory($this->kirby);
        $checkout = $runtime->directCheckoutContext([
            ['reference' => 'digital'],
        ]);

        $this->assertNull($runtime->resolveShippingQuote(
            $checkout,
            $runtime->directShippingContext('PT'),
        ));
    }

    public function testCartAndDirectInputResolveTheSameShippingQuote(): void
    {
        $runtime = new RuntimeFactory($this->kirby);
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $cart->add('physical', 2)->updateShippingCountry('PT');
        $directCheckout = $runtime->directCheckoutContext([
            ['reference' => 'physical', 'quantity' => 2],
        ]);
        $directQuote = $runtime->resolveShippingQuote(
            $directCheckout,
            $runtime->directShippingContext('PT'),
        );

        $this->assertEquals($cart->shippingQuote(), $directQuote);
        $this->assertSame('PT', $cart->shippingCountry());

        // Direct input is request-scoped and never mutates the browser Cart.
        $runtime->directShippingContext('US');
        $this->assertSame('PT', (new StripeCheckout($this->kirby))->cart()?->shippingCountry());
    }

    public function testDirectContextUsesRuntimeIdentityAndActiveShippingTaxDefaults(): void
    {
        $this->restart(
            options: ['programmatordev.stripe-checkout' => ['settings' => [
                'automaticTax' => true,
                'shippingTaxBehavior' => 'inclusive',
                'shippingTaxCode' => 'shipping',
                'uiMode' => 'embedded',
            ]]],
            languages: [
                ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English'],
                ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português'],
            ],
        );
        $this->kirby->setCurrentLanguage('pt');
        $user = $this->kirby->users()->create([
            'email' => 'direct@example.test',
            'role' => 'admin',
            'password' => 'test-password-123',
        ]);
        $this->kirby->impersonate($user->id());
        $runtime = new RuntimeFactory($this->kirby);
        $checkout = $runtime->directCheckoutContext([['reference' => 'physical']]);
        $shipping = $runtime->directShippingContext('PT');

        $this->assertSame('pt', $checkout->languageCode());
        $this->assertSame('pt_PT', $checkout->locale());
        $this->assertSame($user->uuid()->toString(), $checkout->userUuid());
        $this->assertSame(UiMode::Embedded, $checkout->uiMode());
        $this->assertSame(TaxBehavior::Inclusive, $shipping->taxBehavior());
        $this->assertSame('txcd_92010001', $shipping->taxCode());
    }

    public function testDirectContextResolvesStripePrices(): void
    {
        $this->restart(['programmatordev.stripe-checkout' => [
            'settings' => ['priceSource' => 'stripe'],
            'stripe' => ['secretKey' => 'sk_test_direct_fixture'],
            'products' => ['resolver' => static fn(ProductRequest $request): Product => new Product(
                request: $request,
                name: 'Stripe product',
                requiresShipping: true,
                price: new StripePriceReference('price_direct'),
            )],
        ]]);
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturn([
            json_encode([
                'id' => 'price_direct',
                'object' => 'price',
                'active' => true,
                'billing_scheme' => 'per_unit',
                'currency' => 'eur',
                'type' => 'one_time',
                'unit_amount' => 2500,
                'unit_amount_decimal' => '2500',
                'custom_unit_amount' => null,
                'nickname' => null,
                'recurring' => null,
                'tax_behavior' => 'unspecified',
                'tiers_mode' => null,
                'transform_quantity' => null,
                'product' => [
                    'id' => 'prod_direct',
                    'object' => 'product',
                    'active' => true,
                    'name' => 'Stripe product',
                    'description' => null,
                    'images' => [],
                    'tax_code' => null,
                ],
            ], JSON_THROW_ON_ERROR),
            200,
            [],
        ]);
        ApiRequestor::setHttpClient($client);

        $checkout = (new RuntimeFactory($this->kirby))->directCheckoutContext([
            ['reference' => 'stripe-product', 'quantity' => 2],
        ]);

        $this->assertSame('25.00', (string) $checkout->items()[0]->price()->getAmount());
        $this->assertSame('50.00', (string) $checkout->subtotal()->getAmount());
    }

    public function testCartAndDirectContextsRejectAnAggregateAmountOutsideProviderUnits(): void
    {
        $this->restart(['programmatordev.stripe-checkout' => [
            'products' => ['resolver' => static fn(ProductRequest $request): Product => new Product(
                request: $request,
                name: 'Large product',
                requiresShipping: false,
                price: new Price(Money::of('50000000000000000.00', 'EUR')),
            )],
        ]]);

        try {
            (new RuntimeFactory($this->kirby))->directCheckoutContext([
                ['reference' => 'first'],
                ['reference' => 'second'],
            ]);
            self::fail('The aggregate amount should exceed supported provider units.');
        } catch (MoneyException $error) {
            $this->assertSame(MoneyErrorCode::AMOUNT_OVERFLOW, $error->errorCode());
        }

        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $cart->add('first')->add('second');
        $this->assertNull($cart->subtotal());
        $this->assertSame(CartErrorCode::AMOUNT_INVALID, $cart->errors()[0]->code());
    }

    /**
     * @param array<string, mixed> $options
     * @param list<array<string, mixed>>|null $languages
     */
    private function restart(array $options = [], ?array $languages = null): void
    {
        $this->environment->close();
        /** @var array<string, mixed> $mergedOptions */
        $mergedOptions = array_replace_recursive([
            'programmatordev.stripe-checkout' => [
                'settings' => [
                    'currency' => 'EUR',
                    'shippingZones' => [[
                        'name' => 'Portugal',
                        'scope' => 'selected_countries',
                        'countries' => ['PT'],
                        'options' => [[
                            'key' => 'standard',
                            'label' => 'Standard delivery',
                            'amount' => '4.90',
                        ]],
                    ], [
                        'name' => 'Rest of the world',
                        'scope' => 'fallback',
                        'countries' => [],
                        'options' => [[
                            'key' => 'worldwide',
                            'label' => 'Worldwide delivery',
                            'amount' => '12.00',
                        ]],
                    ]],
                ],
                'products' => [
                    'resolver' => static fn(ProductRequest $request): Product => new Product(
                        request: $request,
                        name: ucfirst($request->reference()),
                        requiresShipping: $request->reference() === 'physical',
                        price: new Price(Money::of(
                            $request->reference() === 'physical' ? '10.00' : '7.00',
                            'EUR',
                        )),
                    ),
                ],
            ],
        ], $options);
        $this->environment = KirbyTestEnvironment::start(
            options: $mergedOptions,
            languages: $languages,
        );
        $this->kirby = $this->environment->app();
    }
}
