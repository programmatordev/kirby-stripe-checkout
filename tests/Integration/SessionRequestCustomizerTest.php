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
use RuntimeException;

final class SessionRequestCustomizerTest extends KirbyTestCase
{
    public function testKirbyAppliesCompleteParameterFiltersSequentially(): void
    {
        $expectedContext = $this->context();
        $seenContext = null;
        $secondHandlerValue = null;
        $this->restart(hooks: [
            SessionRequestCustomizer::FILTER => [
                function (array $parameters, SessionRequestContext $context) use (&$seenContext): array {
                    $seenContext = $context;
                    $metadata = $parameters['metadata'] ?? null;

                    if (is_array($metadata) === false) {
                        throw new RuntimeException('Expected Session metadata.');
                    }

                    $metadata['sales_channel'] = 'website';
                    $parameters['metadata'] = $metadata;

                    return $parameters;
                },
                function (array $parameters) use (&$secondHandlerValue): array {
                    $metadata = $parameters['metadata'] ?? null;
                    $paymentIntentData = $parameters['payment_intent_data'] ?? null;

                    if (is_array($metadata) === false || is_array($paymentIntentData) === false) {
                        throw new RuntimeException('Expected Session request maps.');
                    }

                    $secondHandlerValue = $metadata['sales_channel'] ?? null;
                    $paymentIntentData['description'] = 'Project order';
                    $parameters['payment_intent_data'] = $paymentIntentData;

                    return $parameters;
                },
            ],
        ]);

        $parameters = (new SessionRequestCustomizer($this->kirby))
            ->customize($expectedContext, $this->standardRequest())
            ->parameters();

        $this->assertSame($expectedContext, $seenContext);
        $this->assertSame('website', $secondHandlerValue);
        $metadata = $parameters['metadata'] ?? null;
        $paymentIntentData = $parameters['payment_intent_data'] ?? null;
        $this->assertIsArray($metadata);
        $this->assertIsArray($paymentIntentData);
        $this->assertSame('website', $metadata['sales_channel'] ?? null);
        $this->assertSame('Project order', $paymentIntentData['description'] ?? null);
    }

    public function testFilterCanChangeSettingsValuesAndStripeOwnedParameters(): void
    {
        $this->restart(hooks: [
            SessionRequestCustomizer::FILTER => function (array $parameters): array {
                $parameters['billing_address_collection'] = 'required';
                $parameters['discounts'] = [['promotion_code' => 'promo_test']];
                unset($parameters['allow_promotion_codes']);

                return $parameters;
            },
        ]);
        $request = $this->standardRequest([
            'allow_promotion_codes' => true,
            'billing_address_collection' => 'auto',
        ]);

        $parameters = (new SessionRequestCustomizer($this->kirby))
            ->customize($this->context(), $request)
            ->parameters();

        $this->assertSame('required', $parameters['billing_address_collection'] ?? null);
        $this->assertSame([['promotion_code' => 'promo_test']], $parameters['discounts'] ?? null);
        $this->assertArrayNotHasKey('allow_promotion_codes', $parameters);
    }

    public function testRuntimeUsesTheRegisteredSessionParametersFilter(): void
    {
        $this->restart(hooks: [
            SessionRequestCustomizer::FILTER => function (array $parameters): array {
                $parameters['branding_settings'] = ['display_name' => 'Example Store'];

                return $parameters;
            },
        ]);

        $parameters = (new RuntimeFactory($this->kirby))
            ->checkoutSessionRequest($this->context())
            ->parameters();

        $this->assertSame(
            ['display_name' => 'Example Store'],
            $parameters['branding_settings'] ?? null,
        );
    }

    public function testInvalidFilterResultIsWrappedSafely(): void
    {
        $this->restart(hooks: [
            SessionRequestCustomizer::FILTER => fn(): string => 'private request body',
        ]);

        try {
            (new SessionRequestCustomizer($this->kirby))->customize(
                $this->context(),
                $this->standardRequest(),
            );
            $this->fail('Expected the filter result to be rejected.');
        } catch (InvalidSessionRequestException $error) {
            $this->assertSame('session_request.filter_invalid', $error->errorCode());
            $this->assertNull($error->path());
            $this->assertStringNotContainsString('private request body', $error->getMessage());
        }
    }

    public function testFilterExceptionIsWrappedSafely(): void
    {
        $this->restart(hooks: [
            SessionRequestCustomizer::FILTER => fn(array $parameters): never => throw new RuntimeException('private value'),
        ]);

        try {
            (new SessionRequestCustomizer($this->kirby))->customize(
                $this->context(),
                $this->standardRequest(),
            );
            $this->fail('Expected the filter exception to be wrapped.');
        } catch (InvalidSessionRequestException $error) {
            $this->assertSame('session_request.filter_failed', $error->errorCode());
            $this->assertNull($error->path());
            $this->assertStringNotContainsString('private value', $error->getMessage());
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
            uuid: 'Order123',
            orderNumber: 'ORD-ORDER123',
            checkoutSource: CheckoutSource::Direct,
            cartRevision: null,
            userUuid: null,
            languageCode: null,
            uiMode: UiMode::Hosted,
            currency: 'EUR',
            lineItems: [OrderLineItemSnapshot::fromProduct($product, $price)],
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

    /** @param array<string, mixed> $overrides */
    private function standardRequest(array $overrides = []): SessionRequest
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
            ...$overrides,
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
