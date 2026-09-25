<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use Kirby\Uuid\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

final class CheckoutPreparationFactoryTest extends KirbyTestCase
{
    public function testInactiveTaxValidationNeedsNoProductContextOrProvider(): void
    {
        $runtime = new RuntimeFactory($this->kirby);
        $this->assertNull($runtime->settings()->currency());

        $runtime->checkoutResolver()->validateTaxCode(new TaxCode('txcd_99999999'));
    }

    public function testMixedPurchaseUsesOneResolutionForShippingAndOrderFacts(): void
    {
        $productCalls = [];
        $quotedCheckout = null;
        $quoteCalls = 0;
        $this->restart([
            'products' => ['resolver' => static function (ProductRequest $request, ProductResolutionContext $context) use (&$productCalls): Product {
                $productCalls[] = $request->quantity();

                return new Product(
                    request: $request,
                    name: $context->languageCode() === 'pt' ? 'Camisola' : 'Shirt',
                    requiresShipping: $request->reference() === 'shirt',
                    price: new Price(Money::of('16', 'EUR')),
                    selectedOptions: $request->selectedOptions() === [] ? [] : [new SelectedOption('size', 'Tamanho', 'large', 'Grande')],
                    description: 'Local description',
                    imageUrls: ['https://example.test/local.jpg'],
                    sku: 'SHIRT-L',
                    metadata: ['shipping_class' => 'parcel'],
                    variantId: $request->selectedOptions() === [] ? null : 'large-variant',
                    taxCode: new TaxCode('txcd_99999999'),
                );
            }],
            'shipping' => ['resolver' => static function (CheckoutContext $checkout, ShippingContext $shipping) use (&$quotedCheckout, &$quoteCalls): ShippingQuote {
                $quotedCheckout = $checkout;
                $quoteCalls++;

                return ShippingQuote::available([new ShippingOption('standard', 'Standard', Money::of('5', 'EUR'))]);
            }],
            'orders' => ['numberFormatter' => static fn(string $uuid): string => 'WEB-' . (new \Kirby\Uuid\Uri($uuid))->host()],
        ]);
        $user = $this->kirby->users()->create(['email' => 'buyer@example.test', 'role' => 'admin', 'password' => 'test-password-123']);
        $this->kirby->impersonate($user->id());
        $this->kirby->setCurrentLanguage('pt');
        $runtime = new RuntimeFactory($this->kirby);
        $resolver = $runtime->checkoutResolver();
        $checkout = $resolver->directCheckoutContext([
            ['reference' => 'shirt', 'quantity' => 1, 'selectedOptions' => ['size' => 'large']],
            ['reference' => 'shirt', 'quantity' => 2, 'selectedOptions' => ['size' => 'large']],
            ['reference' => 'digital'],
        ]);
        $resolvedCalls = $productCalls;
        $uuid = Uuid::generate();
        $preparation = $runtime->checkoutPreparationFactory()->create(
            uuid: $uuid,
            checkout: $checkout,
            shipping: $resolver->shippingContext('PT'),
        );
        $order = $preparation->order();

        $this->assertSame($resolvedCalls, $productCalls);
        $this->assertContains(3, $productCalls);
        $this->assertSame(1, $quoteCalls);
        $this->assertSame($checkout, $quotedCheckout);
        $this->assertCount(1, $checkout->shippableItems());
        $this->assertSame($uuid, $order->uuid());
        $this->assertSame('WEB-' . $uuid, $order->orderNumber());
        $this->assertSame('pt', $order->languageCode());
        $this->assertSame($user->uuid()->toString(), $order->userUuid());
        $this->assertSame('64.00', (string) $order->subtotal()->getAmount());
        $this->assertTrue($order->requiresShipping());
        $this->assertTrue($preparation->shipping()?->matches($order));
        $this->assertSame(['PT'], $preparation->shipping()->allowedCountries());
        $line = $order->lineItems()[0];
        $this->assertSame('Camisola', $line['name']);
        $this->assertSame('Local description', $line['description']);
        $this->assertSame(['https://example.test/local.jpg'], $line['images']);
        $this->assertSame('SHIRT-L', $line['sku']);
        $this->assertSame('large-variant', $line['variantId']);
        $this->assertSame([[
            'optionId' => 'size',
            'optionName' => 'Tamanho',
            'valueId' => 'large',
            'valueName' => 'Grande',
        ]], $line['options']);
        $this->assertSame('txcd_99999999', $line['taxCode']);
        $this->assertSame(['shipping_class' => 'parcel'], $line['metadata']);
        $this->assertSame('48.00', $line['subtotal']);
    }

    public function testStripeLineIsRetrievedOnceThenReusedWithoutReplacingLocalDescriptions(): void
    {
        $this->restart([
            'settings' => ['priceSource' => 'stripe'],
            'stripe' => ['secretKey' => 'sk_test_preparation'],
            'products' => ['resolver' => static fn(ProductRequest $request): Product => new Product(
                request: $request,
                name: 'Local name',
                requiresShipping: true,
                price: new StripePriceReference('price_preparation'),
                description: 'Local description',
                imageUrls: ['https://example.test/local.jpg'],
            )],
        ]);
        $client = $this->createMock(ClientInterface::class);
        // One call per resolution operation, not another for quote/snapshot creation.
        $client->expects($this->exactly(2))->method('request')->willReturn([
            json_encode([
                'id' => 'price_preparation',
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
                'tax_behavior' => 'exclusive',
                'tiers_mode' => null,
                'transform_quantity' => null,
                'product' => [
                    'id' => 'prod_preparation',
                    'object' => 'product',
                    'active' => true,
                    'name' => 'Provider name',
                    'description' => 'Provider description',
                    'images' => ['https://example.test/provider.jpg'],
                    'tax_code' => 'txcd_99999999',
                ],
            ], JSON_THROW_ON_ERROR),
            200,
            [],
        ]);
        ApiRequestor::setHttpClient($client);
        $operations = [1, 2];

        foreach ($operations as $operation) {
            $runtime = new RuntimeFactory($this->kirby);
            $resolver = $runtime->checkoutResolver();
            $checkout = $resolver->directCheckoutContext([['reference' => 'shirt', 'quantity' => 2]]);
            $preparation = $runtime->checkoutPreparationFactory()->create(
                uuid: Uuid::generate(),
                checkout: $checkout,
                shipping: $resolver->shippingContext('PT'),
            );
            $line = $preparation->order()->lineItems()[0];
            $this->assertSame('price_preparation', $line['stripePriceId']);
            $this->assertSame('prod_preparation', $line['stripeProductId']);
            $this->assertSame('stripe', $line['priceSource']);
            $this->assertSame('25.00', $line['price']);
            $this->assertSame('50.00', $line['subtotal']);
            $this->assertSame('Local name', $line['name']);
            $this->assertSame('Local description', $line['description']);
            $this->assertSame(['https://example.test/local.jpg'], $line['images']);
            $this->assertNull($line['taxCode']);
            $this->assertTrue($preparation->shipping()?->matches($preparation->order()));
        }
    }

    public function testDigitalCartPreparationRetainsRevisionAndSkipsShipping(): void
    {
        $this->restart([
            'settings' => ['uiMode' => 'embedded'],
            'shipping' => ['resolver' => static fn(): never => throw new \LogicException('Digital checkout must not resolve shipping.')],
        ]);
        $runtime = new RuntimeFactory($this->kirby);
        $resolver = $runtime->checkoutResolver();
        $product = $resolver->resolveProduct(new ProductRequest('digital'));
        $checkout = $resolver->checkoutContext([$resolver->checkoutLineItem($product)], CheckoutSource::Cart);
        $preparation = $runtime->checkoutPreparationFactory()->create(
            uuid: Uuid::generate(),
            checkout: $checkout,
            shipping: $resolver->shippingContext(null),
            cartRevision: 'revision',
        );

        $this->assertFalse($preparation->order()->requiresShipping());
        $this->assertSame(CheckoutSource::Cart, $preparation->order()->checkoutSource());
        $this->assertSame('revision', $preparation->order()->cartRevision());
        $this->assertSame(UiMode::Embedded, $preparation->order()->uiMode());
        $this->assertNull($preparation->shipping());
    }

    #[DataProvider('unavailableQuotes')]
    public function testUnavailableShippingCannotBecomeInitiatingEvidence(ShippingQuote $quote, string $code): void
    {
        $this->restart(['shipping' => ['resolver' => static fn(): ShippingQuote => $quote]]);
        $runtime = new RuntimeFactory($this->kirby);
        $resolver = $runtime->checkoutResolver();
        $checkout = $resolver->directCheckoutContext([['reference' => 'shirt']]);

        try {
            $runtime->checkoutPreparationFactory()->create(
                uuid: Uuid::generate(),
                checkout: $checkout,
                shipping: $resolver->shippingContext(null),
            );
            $this->fail('Unavailable shipping cannot prepare an order.');
        } catch (CheckoutInputException $error) {
            $this->assertSame($code, $error->errorCode());
        }
    }

    /** @return iterable<string, array{ShippingQuote, string}> */
    public static function unavailableQuotes(): iterable
    {
        yield 'country missing' => [ShippingQuote::countryRequired(), ShippingErrorCode::COUNTRY_REQUIRED];
        yield 'unavailable' => [ShippingQuote::unavailable(), ShippingErrorCode::UNAVAILABLE];
    }

    /** @param array<string, mixed> $options */
    private function restart(array $options = []): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            options: ['programmatordev.stripe-checkout' => array_replace_recursive([
                'settings' => ['currency' => 'EUR'],
                'products' => ['resolver' => static fn(ProductRequest $request): Product => new Product(
                    request: $request,
                    name: 'Product',
                    requiresShipping: $request->reference() !== 'digital',
                    price: new Price(Money::of('16', 'EUR')),
                )],
                'shipping' => ['resolver' => static fn(): ShippingQuote => ShippingQuote::available([
                    new ShippingOption('standard', 'Standard', Money::of('5', 'EUR')),
                ])],
            ], $options)],
            languages: [
                ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English'],
                ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português'],
            ],
        );
        $this->kirby = $this->environment->app();
    }
}
