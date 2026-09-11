<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\InvalidSessionRequestException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestCustomizer;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

final class SessionRequestCustomizerTest extends KirbyTestCase
{
    public function testKirbyAppliesMultipleParameterFiltersSequentially(): void
    {
        $expectedContext = $this->context();
        $seenContext = null;
        $this->restart(hooks: [
            SessionRequestCustomizer::FILTER => [
                function (array $parameters, SessionRequestContext $context) use (&$seenContext): array {
                    $seenContext = $context;

                    return [
                        ...$parameters,
                        'metadata' => ['warehouse' => 'west'],
                    ];
                },
                function (array $parameters): array {
                    $parameters['payment_intent_data'] = [
                        'description' => 'Project order',
                    ];

                    return $parameters;
                },
            ],
        ]);

        $parameters = (new SessionRequestCustomizer($this->kirby))
            ->customize($expectedContext, $this->standardRequest())
            ->parameters();

        $this->assertIsArray($parameters['metadata']);
        $this->assertIsArray($parameters['payment_intent_data']);
        $this->assertSame($expectedContext, $seenContext);
        $this->assertSame('west', $parameters['metadata']['warehouse'] ?? null);
        $this->assertSame('Project order', $parameters['payment_intent_data']['description'] ?? null);
    }

    public function testAdvancedFactoryCanCustomizeNonProtectedConstruction(): void
    {
        $context = $this->context();
        $request = $this->standardRequest();
        $factory = static function (
            SessionRequestContext $receivedContext,
            SessionRequest $receivedRequest,
        ) use ($context, $request): SessionRequest {
            self::assertSame($context, $receivedContext);
            self::assertSame($request, $receivedRequest);
            $parameters = $receivedRequest->parameters();
            $parameters['automatic_tax'] = ['enabled' => true];

            return new SessionRequest($parameters);
        };

        $parameters = (new SessionRequestCustomizer($this->kirby))
            ->customize($context, $request, $factory)
            ->parameters();

        $this->assertSame(['enabled' => true], $parameters['automatic_tax'] ?? null);
    }

    public function testRuntimeUsesTheConfiguredSessionRequestFactory(): void
    {
        $factory = static function (
            SessionRequestContext $context,
            SessionRequest $request,
        ): SessionRequest {
            $parameters = $request->parameters();
            $parameters['branding_settings'] = [
                'display_name' => 'Example Store',
            ];

            return new SessionRequest($parameters);
        };
        $this->restart(options: [
            'programmatordev.stripe-checkout' => [
                'checkout' => ['sessionRequestFactory' => $factory],
            ],
        ]);

        $parameters = (new RuntimeFactory($this->kirby))
            ->checkoutSessionRequest($this->context())
            ->parameters();

        $this->assertSame(
            ['display_name' => 'Example Store'],
            $parameters['branding_settings'] ?? null,
        );
    }

    public function testFactoryReceivesParametersFromAdditiveFilters(): void
    {
        $this->restart(hooks: [
            SessionRequestCustomizer::FILTER => fn(array $parameters): array => [
                ...$parameters,
                'metadata' => ['sales_channel' => 'website'],
            ],
        ]);
        $factory = static function (
            SessionRequestContext $context,
            SessionRequest $request,
        ): SessionRequest {
            $parameters = $request->parameters();
            $metadata = $parameters['metadata'] ?? null;
            self::assertIsArray($metadata);
            self::assertSame('website', $metadata['sales_channel'] ?? null);
            $metadata['sales_channel'] = 'factory';
            $parameters['metadata'] = $metadata;
            $parameters['branding_settings'] = ['display_name' => 'Example Store'];

            return new SessionRequest($parameters);
        };

        $parameters = (new SessionRequestCustomizer($this->kirby))
            ->customize($this->context(), $this->standardRequest(), $factory)
            ->parameters();

        $metadata = $parameters['metadata'] ?? null;
        $this->assertIsArray($metadata);
        $this->assertSame('factory', $metadata['sales_channel'] ?? null);
        $this->assertSame(
            ['display_name' => 'Example Store'],
            $parameters['branding_settings'] ?? null,
        );
    }

    public function testInvalidFilterResultIsWrappedSafely(): void
    {
        $this->restart(hooks: [
            SessionRequestCustomizer::FILTER => fn(): string => 'invalid',
        ]);

        try {
            (new SessionRequestCustomizer($this->kirby))->customize(
                $this->context(),
                $this->standardRequest(),
            );
            $this->fail('Expected the filter result to be rejected.');
        } catch (InvalidSessionRequestException $error) {
            $this->assertSame('session_request.additions_invalid', $error->errorCode());
            $this->assertNull($error->path());
        }
    }

    public function testInvalidFactoryResultIsRejectedSafely(): void
    {
        try {
            (new SessionRequestCustomizer($this->kirby))->customize(
                $this->context(),
                $this->standardRequest(),
                fn(): string => 'private request body',
            );
            $this->fail('Expected the factory result to be rejected.');
        } catch (InvalidSessionRequestException $error) {
            $this->assertSame('session_request.factory_invalid', $error->errorCode());
            $this->assertStringNotContainsString('private request body', $error->getMessage());
        }
    }

    private function context(): SessionRequestContext
    {
        $price = Money::of('10.00', 'EUR');
        $product = new Product(
            new ProductRequest('product'),
            'Product',
            false,
            new Price($price),
        );
        $order = new OrderCreationContext(
            'Order123',
            'ORD-ORDER123',
            CheckoutSource::Direct,
            null,
            null,
            null,
            UiMode::Hosted,
            'EUR',
            [OrderLineItemSnapshot::fromProduct($product, $price)],
        );

        return new SessionRequestContext(
            order: $order,
            locale: 'en',
            expiresAt: new DateTimeImmutable('2026-09-12T08:00:00Z'),
            initiatingUrl: 'https://kirby-stripe-checkout.test/product',
            successDestination: 'https://kirby-stripe-checkout.test/success',
            cancelDestination: 'https://kirby-stripe-checkout.test/product',
            returnDestination: 'https://kirby-stripe-checkout.test/product',
        );
    }

    private function standardRequest(): SessionRequest
    {
        return new SessionRequest([
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

    /**
     * @param array<string, callable|list<callable>> $hooks
     * @param array<string, mixed> $options
     */
    private function restart(array $hooks = [], array $options = []): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(hooks: $hooks, options: $options);
        $this->kirby = $this->environment->app();
    }
}
