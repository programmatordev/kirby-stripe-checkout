<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuoteStatus;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

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
            ['reference' => 'physical', 'quantity' => 3],
        ]);
        $shipping = $runtime->directShippingContext('PT');
        $quote = $runtime->resolveShippingQuote($checkout, $shipping);

        $this->assertSame(CheckoutSource::Direct, $checkout->checkoutSource());
        $this->assertCount(2, $checkout->items());
        $this->assertCount(1, $checkout->shippableItems());
        $this->assertSame('physical', $checkout->shippableItems()[0]->productReference());
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

    private function restart(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
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
        ]);
        $this->kirby = $this->environment->app();
    }
}
