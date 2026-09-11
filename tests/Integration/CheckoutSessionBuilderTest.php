<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestBuilder;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;

final class CheckoutSessionBuilderTest extends KirbyTestCase
{
    public function testBuildsTheProtectedHostedInlineRequest(): void
    {
        $context = $this->context(UiMode::Hosted, $this->inlineOrder());
        $request = (new SessionRequestBuilder($this->kirby))->build($context);
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
        $parameters = (new SessionRequestBuilder($this->kirby))
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
    }

    public function testKeepsTheInstallationIdentifierStable(): void
    {
        $builder = new SessionRequestBuilder($this->kirby);
        $context = $this->context(UiMode::Hosted, $this->inlineOrder());
        $first = $builder->build($context)->parameters()['integration_identifier'];
        $second = (new SessionRequestBuilder($this->kirby))
            ->build($context)
            ->parameters()['integration_identifier'];

        $this->assertSame($first, $second);
    }

    public function testAllowsAZeroAmountInlineOrderWithoutChangingThePaymentMode(): void
    {
        $parameters = (new SessionRequestBuilder($this->kirby))
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
        $parameters = (new SessionRequestBuilder($this->kirby))
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
    ): OrderCreationContext {
        $price = Money::of($amount, 'EUR');
        $product = new Product(
            new ProductRequest('canvas-bag', 2),
            'Canvas bag',
            true,
            new Price($price),
            description: 'Heavy canvas.',
            imageUrls: ['https://example.com/bag.jpg'],
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
            'Abc123def456GHI7',
            'ORD-ABC123DEF456GHI7',
            CheckoutSource::Direct,
            null,
            null,
            $languageCode,
            $uiMode,
            'EUR',
            [$lineItem],
        );
    }

    /** @param list<array<string, mixed>>|null $languages */
    private function restart(?array $languages = null): void
    {
        $this->environment->close();
        $this->environment = \ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment::start(languages: $languages);
        $this->kirby = $this->environment->app();
    }
}
