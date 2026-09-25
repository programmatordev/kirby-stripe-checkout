<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use Closure;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartEntry;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSessionPresentation;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\InvalidSessionRequestException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptBinding;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptToken;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutPreparationFactory;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSessionCreator;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\InitiatingShippingSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestBuilder;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestContextFactory;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestCustomizer;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\Configuration;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\OrderWriteLock;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderNumberFormatter;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Shipping\Internal\ShippingQuotePipeline;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailure;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailureType;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Exception\CheckoutSessionGatewayException;
use ProgrammatorDev\StripeCheckout\Stripe\Price\StripePrice;
use ProgrammatorDev\StripeCheckout\Test\Support\InitiatingShippingSnapshotFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\Stripe\FakeCheckoutSessionGateway;
use RuntimeException;
use Stripe\Util\ApiVersion;

final class CheckoutSessionCreatorTest extends KirbyTestCase
{
    private const ORDER_UUID = 'checkoutorder001';

    private const SECOND_ORDER_UUID = 'checkoutorder002';

    /** @var list<ShippingOption>|null */
    private ?array $shippingOptions = null;

    /** @var Closure(CheckoutContext, ShippingContext): ShippingQuote|null */
    private ?Closure $quoteResolver = null;

    /** @var Closure(array<mixed>, SessionRequestContext): array<mixed>|null */
    private ?Closure $requestFilter = null;

    private int $quoteCalls = 0;

    private int $numberCalls = 0;

    private int $requestCalls = 0;

    private function registerTestHooks(): void
    {
        // Kirby binds hooks to App; capture test state without relying on $this.
        $quoteCalls = &$this->quoteCalls;
        $quoteResolver = &$this->quoteResolver;
        $shippingOptions = &$this->shippingOptions;
        $requestCalls = &$this->requestCalls;
        $requestFilter = &$this->requestFilter;
        $this->kirby->extend(['hooks' => [
            ShippingQuotePipeline::FILTER => function (ShippingQuote $quote, CheckoutContext $checkout, ShippingContext $shipping) use (&$quoteCalls, &$quoteResolver, &$shippingOptions): ShippingQuote {
                $quoteCalls++;

                return $quoteResolver !== null
                    ? $quoteResolver($checkout, $shipping)
                    : ShippingQuote::available($shippingOptions ?? InitiatingShippingSnapshotFactory::fromCheckout($checkout)->options());
            },
            SessionRequestCustomizer::FILTER => function (array $parameters, SessionRequestContext $context) use (&$requestCalls, &$requestFilter): array {
                $requestCalls++;

                return $requestFilter !== null
                    ? $requestFilter($parameters, $context)
                    : $parameters;
            },
        ]]);
    }

    #[DataProvider('sessionModesAndPriceSources')]
    public function testCreatesASessionAcrossSupportedCheckoutCombinations(
        UiMode $uiMode,
        bool $stripePrice,
        CheckoutSource $checkoutSource,
        bool $requiresShipping,
        bool $mixed,
    ): void {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure($uiMode, priceSource: $stripePrice ? PriceSource::Stripe : PriceSource::Kirby);
        $checkout = $this->checkout(
            uiMode: $uiMode,
            stripePrice: $stripePrice,
            requiresShipping: $requiresShipping,
            checkoutSource: $checkoutSource,
            mixed: $mixed,
        );
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: $uiMode);
        $gateway = new FakeCheckoutSessionGateway([$sessionRecord]);
        $presentation = $this->creator($configuration, $gateway)->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(checkoutSource: $checkoutSource),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
            initiatingUrl: 'https://kirby-stripe-checkout.test/product',
        );

        $this->assertPresentation($presentation, $uiMode, reused: false);
        $this->assertCount(1, $gateway->requests);
        $this->assertSame($request->parameters(), $gateway->requests[0]->parameters());

        $store = new OrderPageStore($this->kirby);
        $data = $store->data($store->order($order->pageUuid()) ?? $this->fail('Order was not persisted.'));
        $checkoutAttempt = OrderData::map($data['checkoutAttempt']);
        $this->assertSame(CheckoutStatus::Open->value, $data['checkoutStatus']);
        $this->assertSame($sessionRecord->id, $data['stripeCheckoutSessionId']);
        $this->assertSame($checkoutSource->value, $checkoutAttempt['source']);
        $this->assertSame($checkoutSource === CheckoutSource::Cart ? 'revision' : null, $checkoutAttempt['cartRevision']);
        $this->assertCount($mixed ? 2 : 1, OrderData::list($data['initiatingLineItems']));
        $this->assertSame($requiresShipping ? ['shr_test_option_0'] : [], $data['stripeShippingRateIds']);
        $this->assertSame($requiresShipping ? 1 : 0, $this->quoteCalls);
    }

    #[DataProvider('uiModes')]
    public function testPersistsRetryEvidenceAndLifecycleEventsWithoutPresentationSecrets(UiMode $uiMode): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure($uiMode);
        $checkout = $this->checkout(uiMode: $uiMode);
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: $uiMode);
        $gateway = new FakeCheckoutSessionGateway([$sessionRecord]);

        $this->creator($configuration, $gateway)->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );

        $store = new OrderPageStore($this->kirby);
        $data = $store->data($store->order($order->pageUuid()) ?? $this->fail('Order was not persisted.'));
        $checkoutAttempt = OrderData::map($data['checkoutAttempt']);
        $this->assertSame(['stripe-checkout/session/' . self::ORDER_UUID], $gateway->idempotencyKeys);
        $this->assertSame($gateway->requests[0]->parameters(), $checkoutAttempt['sessionRequest']);
        $this->assertSame($request->fingerprint(), $checkoutAttempt['requestFingerprint']);
        $this->assertSame($this->binding()->fingerprint(), $checkoutAttempt['bindingFingerprint']);
        $this->assertSame(ApiVersion::CURRENT, $checkoutAttempt['stripeApiVersion']);
        $this->assertSame('test', $checkoutAttempt['credentialMode']);
        $this->assertSame(
            hash_hmac('sha256', $order->pageUuid(), 'sk_test_checkout'),
            $checkoutAttempt['credentialFingerprint'],
        );
        $this->assertSame($gateway->idempotencyKeys[0], $checkoutAttempt['idempotencyKey']);
        $this->assertArrayNotHasKey('stripeShippingRateIds', $checkoutAttempt);
        $persisted = OrderData::json($data);
        $presentationSecret = $uiMode === UiMode::Hosted ? $sessionRecord->url : $sessionRecord->clientSecret;
        $this->assertNotNull($presentationSecret);
        $this->assertStringNotContainsString($presentationSecret, $persisted);
        $this->assertStringNotContainsString('sk_test_checkout', $persisted);

        $deliveries = OrderData::list($data['lifecycleDeliveries']);
        $types = array_map(
            static fn(mixed $entry): mixed => OrderData::map(OrderData::map($entry)['event'])['type'] ?? null,
            $deliveries,
        );
        $this->assertSame(['order.created', 'session.created'], $types);
    }

    #[DataProvider('invalidSessionShippingOptions')]
    public function testRejectsInvalidSessionShippingOptions(mixed $shippingOptions): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(
            order: $order,
            request: $request,
            now: $now,
            uiMode: UiMode::Hosted,
            overrides: ['shippingOptions' => $shippingOptions],
        );

        try {
            $this->creator($configuration, new FakeCheckoutSessionGateway([$sessionRecord]))->create(
                checkout: $checkout,
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: $now,
            );
            $this->fail('Expected invalid Session shipping options to be rejected.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame('checkout.session_incompatible', $error->errorCode());
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidSessionShippingOptions(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [[]];
        yield 'non-list' => [['option' => ['shipping_rate' => 'shr_test_option_0']]];
        yield 'invalid reference' => [[['shipping_rate' => 'rate_invalid']]];
        yield 'expanded reference' => [[['shipping_rate' => ['id' => 'shr_test_option_0']]]];
        yield 'unexpected extra option' => [[
            ['shipping_rate' => 'shr_test_option_0'],
            ['shipping_rate' => 'shr_test_option_1'],
        ]];
    }

    public function testRejectsDuplicateAndReorderedSessionShippingRates(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $snapshot = InitiatingShippingSnapshotFactory::fromCheckout(
            checkout: $checkout,
            options: [
                new ShippingOption('standard', 'Standard delivery', Money::of('5', 'EUR')),
                new ShippingOption('express', 'Express delivery', Money::of('10', 'EUR')),
            ],
        );
        $this->shippingOptions = $snapshot->options();
        $request = $this->request(
            checkout: $checkout,
            configuration: $configuration,
            now: $now,
            initiatingShipping: $snapshot,
        );
        $duplicateRecord = $this->sessionRecord(
            order: $order,
            request: $request,
            now: $now,
            uiMode: UiMode::Hosted,
            overrides: ['shippingOptions' => [
                ['shipping_rate' => 'shr_test_duplicate'],
                ['shipping_rate' => 'shr_test_duplicate'],
            ]],
        );

        try {
            $this->creator($configuration, new FakeCheckoutSessionGateway([$duplicateRecord]))->create(
                checkout: $checkout,
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: $now,
            );
            $this->fail('Expected duplicate Session Shipping Rates to be rejected.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame('checkout.session_incompatible', $error->errorCode());
        }

        $otherOrder = $this->order($checkout, uuid: self::SECOND_ORDER_UUID);
        $otherSnapshot = InitiatingShippingSnapshotFactory::fromCheckout(
            checkout: $checkout,
            options: [
                new ShippingOption('standard', 'Standard delivery', Money::of('5', 'EUR')),
                new ShippingOption('express', 'Express delivery', Money::of('10', 'EUR')),
            ],
        );
        $otherRequest = $this->request(
            checkout: $checkout,
            uuid: self::SECOND_ORDER_UUID,
            configuration: $configuration,
            now: $now,
            initiatingShipping: $otherSnapshot,
        );
        $createdRecord = $this->sessionRecord(
            order: $otherOrder,
            request: $otherRequest,
            now: $now,
            uiMode: UiMode::Hosted,
        );
        $shippingOptions = $createdRecord->shippingOptions;

        if (is_array($shippingOptions) === false) {
            $this->fail('Expected provider shipping options.');
        }

        $retrievedRecord = $this->sessionRecord(
            order: $otherOrder,
            request: $otherRequest,
            now: $now,
            uiMode: UiMode::Hosted,
            overrides: ['shippingOptions' => array_reverse($shippingOptions)],
        );
        $gateway = new FakeCheckoutSessionGateway(
            results: [$createdRecord],
            retrievalResults: [$createdRecord->id => $retrievedRecord],
        );
        $creator = $this->creator($configuration, $gateway);
        $token = $this->token(orderUuid: self::SECOND_ORDER_UUID, nonceByte: 'b');
        $binding = $this->binding();
        $creator->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $binding,
            token: $token,
            guestReference: 'guest-browser',
            now: $now,
        );

        try {
            $creator->create(
                checkout: $checkout,
                shipping: new ShippingContext('PT'),
                binding: $binding,
                token: $token,
                guestReference: 'guest-browser',
                now: $now->add(new DateInterval('PT1M')),
            );
            $this->fail('Expected reordered Session Shipping Rates to be rejected.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame('checkout.session_incompatible', $error->errorCode());
        }
    }

    public function testPersistedSessionShippingRateIdsCannotBeReplaced(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $store = new OrderPageStore($this->kirby);
        $this->creator($configuration, new FakeCheckoutSessionGateway([$sessionRecord]))->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );

        $this->expectException(OrderDataException::class);
        $store->update($order->pageUuid(), static function (array $data): array {
            $data['stripeShippingRateIds'] = ['shr_replaced'];

            return $data;
        });
    }

    public function testPreparesMatchingShippingEvidenceForTheRequest(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $gateway = new FakeCheckoutSessionGateway([
            $this->sessionRecord(
                order: $order,
                request: $request,
                now: $now,
                uiMode: UiMode::Hosted,
            ),
        ]);

        $this->creator($configuration, $gateway)->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );

        $this->assertSame($request->parameters()['shipping_options'], $gateway->requests[0]->parameters()['shipping_options']);
        $this->assertSame(['allowed_countries' => ['PT']], $gateway->requests[0]->parameters()['shipping_address_collection']);
        $this->assertSame(1, $this->quoteCalls);
    }

    #[DataProvider('uiModesAndCheckoutSources')]
    public function testDuplicateTokenRetrievesTheSessionWithoutRepeatingPreparation(
        UiMode $uiMode,
        CheckoutSource $checkoutSource,
    ): void {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure($uiMode);
        $checkout = $this->checkout(uiMode: $uiMode, checkoutSource: $checkoutSource);
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: $uiMode);
        $gateway = new FakeCheckoutSessionGateway(
            results: [$sessionRecord],
            retrievalResults: [$sessionRecord->id => $sessionRecord],
        );
        $token = $this->token();
        $creator = $this->creator($configuration, $gateway);
        $first = $creator->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(checkoutSource: $checkoutSource),
            token: $token,
            guestReference: 'guest-browser',
            now: $now,
        );
        $this->quoteResolver = static fn(): never => throw new RuntimeException('Reuse must not resolve shipping.');
        $this->requestFilter = static fn(): never => throw new RuntimeException('Reuse must not customize the request.');
        $second = $this->creator(
            configuration: $configuration,
            gateway: $gateway,
            numberFormatter: static fn(): never => throw new RuntimeException('Reuse must not format an order number.'),
        )->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(checkoutSource: $checkoutSource),
            token: $token,
            guestReference: 'guest-browser',
            now: $now->add(new DateInterval('PT1M')),
            initiatingUrl: 'https://kirby-stripe-checkout.test/changed',
        );

        $this->assertPresentation($first, $uiMode, reused: false);
        $this->assertPresentation($second, $uiMode, reused: true);
        $this->assertSame($first->orderPageUuid(), $second->orderPageUuid());
        $this->assertSame(1, $this->requestCalls);
        $this->assertSame(1, $this->quoteCalls);
        $this->assertSame(1, $this->numberCalls);
        $this->assertCount(1, $gateway->requests);
        $this->assertSame([$sessionRecord->id], $gateway->retrievals);
        $this->assertCount(1, (new OrderPageStore($this->kirby))->orders());
        $this->assertSame('page://' . $token->orderUuid(), $first->orderPageUuid());
    }

    public function testAChangedNonceCannotReuseTheOrderUuid(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $gateway = new FakeCheckoutSessionGateway(
            results: [$sessionRecord],
            retrievalResults: [$sessionRecord->id => $sessionRecord],
        );
        $creator = $this->creator($configuration, $gateway);
        $token = $this->token();
        $creator->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $token,
            guestReference: 'guest-browser',
            now: $now,
        );
        $changedToken = $this->token(nonceByte: 'b');

        try {
            $creator->create(
                checkout: $this->checkout(),
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $changedToken,
                guestReference: 'guest-browser',
                now: $now->add(new DateInterval('PT1M')),
            );
            $this->fail('Expected another nonce for the same Order UUID to conflict.');
        } catch (CheckoutInputException $error) {
            $this->assertSame('checkout.attempt_conflict', $error->errorCode());
        }

        $this->assertCount(1, $gateway->requests);
        $this->assertSame([], $gateway->retrievals);
        $this->assertCount(1, (new OrderPageStore($this->kirby))->orders());
    }

    public function testAnIncompatibleActorStopsBeforePreparation(): void
    {
        $configuration = $this->configure(UiMode::Hosted);
        $gateway = new FakeCheckoutSessionGateway();

        try {
            $this->creator($configuration, $gateway)->create(
                checkout: $this->checkout(),
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'another-browser',
                now: new DateTimeImmutable('2026-09-11T12:00:00Z'),
            );
            $this->fail('Expected an incompatible actor to conflict.');
        } catch (CheckoutInputException $error) {
            $this->assertSame('checkout.attempt_conflict', $error->errorCode());
        }

        $this->assertSame(0, $this->quoteCalls);
        $this->assertSame(0, $this->numberCalls);
        $this->assertCount(0, $gateway->requests);
        $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
    }

    public function testAnUncertainFailurePersistsTheExactAttemptBeforeItCanBeRetried(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $failure = new CheckoutSessionFailure(
            type: CheckoutSessionFailureType::Uncertain,
            retryable: true,
            requestId: 'req_uncertain',
            providerCode: 'api_connection_error',
            providerType: 'api_connection_error',
        );
        $firstGateway = new FakeCheckoutSessionGateway();
        $firstGateway->creationFailure = new CheckoutSessionGatewayException(
            failure: $failure,
            error: new RuntimeException('private'),
        );

        try {
            $this->creator($configuration, $firstGateway)->create(
                checkout: $checkout,
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: $now,
            );
            $this->fail('Expected an uncertain provider result.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame('checkout.session_uncertain', $error->errorCode());
            $this->assertTrue($error->isRetryable());
        }

        $store = new OrderPageStore($this->kirby);
        $page = $store->order($order->pageUuid()) ?? $this->fail('Attempt was not persisted.');
        $data = $store->data($page);
        $checkoutAttempt = OrderData::map($data['checkoutAttempt']);
        $this->assertSame(CheckoutStatus::CreationUncertain->value, $data['checkoutStatus']);
        $this->assertSame('provider_uncertain', OrderData::map($checkoutAttempt['providerFailure'])['type']);
        $this->assertSame($firstGateway->requests[0]->parameters(), $checkoutAttempt['sessionRequest']);
        $this->assertSame($firstGateway->idempotencyKeys[0], $checkoutAttempt['idempotencyKey']);

        $request = new SessionRequest(OrderData::map($checkoutAttempt['sessionRequest']));
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $retryGateway = new FakeCheckoutSessionGateway([$sessionRecord]);
        $this->requestFilter = static fn(): never => throw new RuntimeException('An existing request must not be rebuilt.');
        $this->quoteResolver = static fn(): never => throw new RuntimeException('An existing quote must not be resolved.');
        $presentation = $this->creator($configuration, $retryGateway)->create(
            checkout: $this->checkout(),
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now->add(new DateInterval('PT1M')),
        );

        $this->assertTrue($presentation->isReused());
        $this->assertSame(1, $this->requestCalls);
        $this->assertSame(1, $this->quoteCalls);
        $this->assertSame(1, $this->numberCalls);
        $this->assertSame($checkoutAttempt['sessionRequest'], $retryGateway->requests[0]->parameters());
        $this->assertSame([$checkoutAttempt['idempotencyKey']], $retryGateway->idempotencyKeys);
    }

    public function testRejectedAndIncompatibleResultsBecomeDurableCreationStates(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $rejectedOrder = $this->order($checkout);
        $rejectedGateway = new FakeCheckoutSessionGateway();
        $rejectedGateway->creationFailure = new CheckoutSessionGatewayException(
            failure: new CheckoutSessionFailure(
                type: CheckoutSessionFailureType::Rejected,
                retryable: false,
                requestId: 'req_rejected',
                providerCode: 'parameter_invalid_integer',
                providerType: 'invalid_request_error',
            ),
            error: new RuntimeException('private'),
        );

        try {
            $this->creator($configuration, $rejectedGateway)->create(
                checkout: $checkout,
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: $now,
            );
            $this->fail('Expected a rejected provider result.');
        } catch (CheckoutSessionException $error) {
            $this->assertFalse($error->isRetryable());
        }

        $store = new OrderPageStore($this->kirby);
        $rejectedData = $store->data($store->order($rejectedOrder->pageUuid()) ?? $this->fail('Rejected order was not persisted.'));
        $this->assertSame(CheckoutStatus::CreationFailed->value, $rejectedData['checkoutStatus']);

        $incompatibleCheckout = $this->checkout(selectedOption: 'other');
        $incompatibleOrder = $this->order(
            $incompatibleCheckout,
            uuid: self::SECOND_ORDER_UUID,
        );
        $request = $this->request(checkout: $incompatibleCheckout, uuid: self::SECOND_ORDER_UUID, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(
            order: $incompatibleOrder,
            request: $request,
            now: $now,
            uiMode: UiMode::Hosted,
            overrides: ['clientReferenceId' => 'page://wrong'],
        );

        try {
            $this->creator($configuration, new FakeCheckoutSessionGateway([$sessionRecord]))->create(
                checkout: $incompatibleCheckout,
                shipping: new ShippingContext('PT'),
                binding: $this->binding(selectedOption: 'other'),
                token: $this->token(orderUuid: self::SECOND_ORDER_UUID, nonceByte: 'b'),
                guestReference: 'guest-browser',
                now: $now,
            );
            $this->fail('Expected an incompatible provider result.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame('checkout.session_incompatible', $error->errorCode());
        }

        $incompatibleData = $store->data($store->order($incompatibleOrder->pageUuid()) ?? $this->fail('Incompatible order was not persisted.'));
        $this->assertSame(CheckoutStatus::CreationUncertain->value, $incompatibleData['checkoutStatus']);
    }

    public function testRetryDeadlineClosesAnUncertainAttemptWithoutAnotherProviderCall(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $gateway = new FakeCheckoutSessionGateway();
        $gateway->creationFailure = new CheckoutSessionGatewayException(
            failure: new CheckoutSessionFailure(
                type: CheckoutSessionFailureType::Uncertain,
                retryable: true,
            ),
            error: new RuntimeException('private'),
        );

        try {
            $this->creator($configuration, $gateway)->create(
                checkout: $checkout,
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: $now,
            );
        } catch (CheckoutSessionException) {
        }

        $retryGateway = new FakeCheckoutSessionGateway();

        try {
            $this->creator($configuration, $retryGateway)->create(
                checkout: $this->checkout(),
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: $now->add(new DateInterval('PT23H')),
            );
            $this->fail('Expected the retry deadline to close the attempt.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame('checkout.attempt_retry_expired', $error->errorCode());
        }

        $data = (new OrderPageStore($this->kirby))->data(
            (new OrderPageStore($this->kirby))->order($order->pageUuid()) ?? $this->fail('Order was not persisted.'),
        );
        $this->assertSame(CheckoutStatus::CreationFailed->value, $data['checkoutStatus']);
        $this->assertSame([], $retryGateway->requests);
    }

    public function testChangedSelectionCannotReuseAnExistingAttempt(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $gateway = new FakeCheckoutSessionGateway(
            results: [$sessionRecord],
            retrievalResults: [$sessionRecord->id => $sessionRecord],
        );
        $creator = $this->creator($configuration, $gateway);
        $creator->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );

        $this->expectException(CheckoutInputException::class);
        $creator->create(
            checkout: $this->checkout(selectedOption: 'changed'),
            shipping: new ShippingContext('PT'),
            binding: $this->binding(selectedOption: 'changed'),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );
    }

    public function testChangedStripeCredentialModeCannotReuseAnExistingAttempt(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $creator = $this->creator($configuration, new FakeCheckoutSessionGateway([$sessionRecord]));
        $creator->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );

        $this->expectException(CheckoutInputException::class);
        $this->creator(
            configuration: $this->configure(UiMode::Hosted, secretKey: 'sk_live_checkout'),
            gateway: new FakeCheckoutSessionGateway(),
        )->create(
            checkout: $this->checkout(),
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );
    }

    public function testChangedStripeCredentialCannotReuseAnExistingAttemptInTheSameMode(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted, secretKey: 'sk_test_first');
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $firstGateway = new FakeCheckoutSessionGateway();
        $firstGateway->creationFailure = new CheckoutSessionGatewayException(
            failure: new CheckoutSessionFailure(
                type: CheckoutSessionFailureType::Uncertain,
                retryable: true,
            ),
            error: new RuntimeException('private'),
        );

        try {
            $this->creator($configuration, $firstGateway)->create(
                checkout: $checkout,
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: $now,
            );
        } catch (CheckoutSessionException) {
        }

        $retryGateway = new FakeCheckoutSessionGateway();

        try {
            $this->creator(
                configuration: $this->configure(UiMode::Hosted, secretKey: 'sk_test_second'),
                gateway: $retryGateway,
            )->create(
                checkout: $this->checkout(),
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: $now,
            );
            $this->fail('Expected another Stripe credential to conflict with the attempt.');
        } catch (CheckoutInputException $error) {
            $this->assertSame('checkout.attempt_conflict', $error->errorCode());
        }

        $this->assertSame([], $retryGateway->requests);
        $this->assertSame([], $retryGateway->retrievals);
    }

    public function testUnknownCredentialModeUsesStripeResponseAsTheAuthority(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted, secretKey: 'opaque_future_key');
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(
            order: $order,
            request: $request,
            now: $now,
            uiMode: UiMode::Hosted,
            overrides: ['liveMode' => true],
        );

        $presentation = $this->creator($configuration, new FakeCheckoutSessionGateway([$sessionRecord]))->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );

        $this->assertFalse($presentation->isReused());
    }

    public function testAWebhookObservationCanWinBeforeTheCreateResponseIsAttached(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $gateway = new FakeCheckoutSessionGateway([$sessionRecord]);
        $store = new OrderPageStore($this->kirby);
        $gateway->beforeCreate = static function () use ($store, $order, $sessionRecord, $now): void {
            $page = $store->order($order->pageUuid());

            if ($page === null) {
                throw new RuntimeException('The order must exist before the provider mutation.');
            }

            $store->update($order->pageUuid(), static function (array $data) use ($sessionRecord, $now): array {
                $data['stripeShippingRateIds'] = ['shr_test_option_0'];
                $data['stripeCheckoutSessionId'] = $sessionRecord->id;
                $data['checkoutStatus'] = CheckoutStatus::Open->value;
                $data['checkoutOpenedAt'] = OrderData::timestamp($now);
                $data['updatedAt'] = max($data['updatedAt'], OrderData::timestamp($now));

                return $data;
            });
        };

        $presentation = $this->creator($configuration, $gateway)->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );

        $this->assertSame($order->pageUuid(), $presentation->orderPageUuid());
        $this->assertSame(CheckoutStatus::Open->value, $store->data($store->order($order->pageUuid()) ?? $this->fail('Order missing.'))['checkoutStatus']);
    }

    public function testAConcurrentSessionAssociationWinsOverALateCreationFailure(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $store = new OrderPageStore($this->kirby);
        $gateway = new FakeCheckoutSessionGateway(retrievalResults: [$sessionRecord->id => $sessionRecord]);
        $gateway->creationFailure = new CheckoutSessionGatewayException(
            failure: new CheckoutSessionFailure(
                type: CheckoutSessionFailureType::Unavailable,
                retryable: true,
            ),
            error: new RuntimeException('private'),
        );
        $gateway->beforeCreate = static function () use ($store, $order, $sessionRecord, $now): void {
            $store->update($order->pageUuid(), static function (array $data) use ($sessionRecord, $now): array {
                $data['stripeShippingRateIds'] = ['shr_test_option_0'];
                $data['stripeCheckoutSessionId'] = $sessionRecord->id;
                $data['checkoutStatus'] = CheckoutStatus::Open->value;
                $data['checkoutOpenedAt'] = OrderData::timestamp($now);
                $data['updatedAt'] = max($data['updatedAt'], OrderData::timestamp($now));

                return $data;
            });
        };

        $presentation = $this->creator($configuration, $gateway)->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );

        $this->assertTrue($presentation->isReused());
        $this->assertSame([$sessionRecord->id], $gateway->retrievals);
        $data = $store->data($store->order($order->pageUuid()) ?? $this->fail('Order missing.'));
        $this->assertSame(CheckoutStatus::Open->value, $data['checkoutStatus']);
        $this->assertNull(OrderData::map($data['checkoutAttempt'])['providerFailure']);
    }

    public function testNonRetryableUncertainAttemptWaitsForReconciliation(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $firstGateway = new FakeCheckoutSessionGateway();
        $firstGateway->creationFailure = new CheckoutSessionGatewayException(
            failure: new CheckoutSessionFailure(
                type: CheckoutSessionFailureType::Uncertain,
                retryable: false,
            ),
            error: new RuntimeException('private'),
        );

        try {
            $this->creator($configuration, $firstGateway)->create(
                checkout: $checkout,
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: $now,
            );
        } catch (CheckoutSessionException) {
        }

        $retryGateway = new FakeCheckoutSessionGateway();

        try {
            $this->creator($configuration, $retryGateway)->create(
                checkout: $this->checkout(),
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: $now->add(new DateInterval('PT1M')),
            );
            $this->fail('Expected the uncertain attempt to wait for reconciliation.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame('checkout.session_uncertain', $error->errorCode());
            $this->assertFalse($error->isRetryable());
        }

        $this->assertSame([], $retryGateway->requests);
    }

    public function testConcurrentTerminalObservationSuppressesAStalePresentation(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $gateway = new FakeCheckoutSessionGateway([$sessionRecord]);
        $store = new OrderPageStore($this->kirby);
        $gateway->beforeCreate = static function () use ($store, $order, $sessionRecord, $now): void {
            $store->update($order->pageUuid(), static function (array $data) use ($sessionRecord, $now): array {
                $data['stripeShippingRateIds'] = ['shr_test_option_0'];
                $data['stripeCheckoutSessionId'] = $sessionRecord->id;
                $data['checkoutStatus'] = CheckoutStatus::Expired->value;
                $data['checkoutExpiredAt'] = OrderData::timestamp($now);
                $data['updatedAt'] = max($data['updatedAt'], OrderData::timestamp($now));

                return $data;
            });
        };

        try {
            $this->creator($configuration, $gateway)->create(
                checkout: $checkout,
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: $now,
            );
            $this->fail('Expected the terminal observation to close the attempt.');
        } catch (CheckoutInputException $error) {
            $this->assertSame('checkout.attempt_closed', $error->errorCode());
        }
    }

    public function testProviderMetadataOrderAndAdditionalCustomKeysDoNotAffectCorrelation(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $metadata = $request->parameters()['metadata'] ?? [];

        if (is_array($metadata) === false) {
            $this->fail('Expected request metadata.');
        }

        $reorderedMetadata = ['custom_key' => 'value'];

        foreach (array_reverse($metadata, true) as $key => $value) {
            if (is_string($key) === false || is_string($value) === false) {
                $this->fail('Expected string request metadata.');
            }

            $reorderedMetadata[$key] = $value;
        }

        $sessionRecord = $this->sessionRecord(
            order: $order,
            request: $request,
            now: $now,
            uiMode: UiMode::Hosted,
            overrides: ['metadata' => $reorderedMetadata],
        );

        $presentation = $this->creator($configuration, new FakeCheckoutSessionGateway([$sessionRecord]))->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );

        $this->assertFalse($presentation->isReused());
    }

    public function testRetrievalPreservesTheProviderFailureRetryPolicy(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $gateway = new FakeCheckoutSessionGateway([$sessionRecord]);
        $creator = $this->creator($configuration, $gateway);
        $creator->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );
        $gateway->retrievalFailure = new CheckoutSessionGatewayException(
            failure: new CheckoutSessionFailure(
                type: CheckoutSessionFailureType::Rejected,
                retryable: false,
            ),
            error: new RuntimeException('private'),
        );

        try {
            $creator->create(
                checkout: $this->checkout(),
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: $now,
            );
            $this->fail('Expected retrieval to fail.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame('checkout.session_rejected', $error->errorCode());
            $this->assertFalse($error->isRetryable());
        }
    }

    public function testAConflictingSessionObservedBeforeTheResponseIsRejected(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $gateway = new FakeCheckoutSessionGateway([$sessionRecord]);
        $store = new OrderPageStore($this->kirby);
        $gateway->beforeCreate = static function () use ($store, $order, $now): void {
            $store->update($order->pageUuid(), static function (array $data) use ($now): array {
                $data['stripeShippingRateIds'] = ['shr_test_conflict'];
                $data['stripeCheckoutSessionId'] = 'cs_test_conflict';
                $data['checkoutStatus'] = CheckoutStatus::Open->value;
                $data['checkoutOpenedAt'] = OrderData::timestamp($now);
                $data['updatedAt'] = max($data['updatedAt'], OrderData::timestamp($now));

                return $data;
            });
        };

        try {
            $this->creator($configuration, $gateway)->create(
                checkout: $checkout,
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: $now,
            );
            $this->fail('Expected the conflicting Session identity to be rejected.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame('checkout.session_incompatible', $error->errorCode());
        }

        $data = $store->data($store->order($order->pageUuid()) ?? $this->fail('Order missing.'));
        $this->assertSame('cs_test_conflict', $data['stripeCheckoutSessionId']);
    }

    public function testAValidatedProviderResponseStillReportsALocalAttachmentFailure(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $store = new OrderPageStore($this->kirby);
        $gateway = new FakeCheckoutSessionGateway([$sessionRecord]);
        $gateway->beforeCreate = static function () use ($store, $order): void {
            $page = $store->order($order->pageUuid());

            if ($page === null) {
                throw new RuntimeException('The order must exist before the provider mutation.');
            }

            unlink($page->root() . '/stripe-checkout-order.txt');
        };

        try {
            $this->creator($configuration, $gateway)->create(
                checkout: $checkout,
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: $now,
            );
            $this->fail('Expected local Session attachment to fail.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame('checkout.session_attachment_failed', $error->errorCode());
            $this->assertTrue($error->isRetryable());
        }

        $this->assertCount(1, $gateway->requests);
    }

    public function testAValidationFailureCreatesNeitherAnOrderNorAProviderRequest(): void
    {
        $configuration = $this->configure(UiMode::Hosted);
        $gateway = new FakeCheckoutSessionGateway();
        $this->requestFilter = static function (array $parameters): array {
            $parameters['mode'] = 'subscription';

            return $parameters;
        };
        $creator = $this->creator($configuration, $gateway);

        try {
            $creator->create(
                checkout: $this->checkout(),
                shipping: new ShippingContext('PT'),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: new DateTimeImmutable('2026-09-11T12:00:00Z'),
            );
            $this->fail('Expected request validation to fail.');
        } catch (InvalidSessionRequestException $error) {
            $this->assertSame('session_request.invariant_violation', $error->errorCode());
        }

        $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
        $this->assertSame([], $gateway->requests);
    }

    public function testANewCustomerAttemptCreatesANewOrderAndSession(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $firstOrder = $this->order($checkout);
        $secondOrder = $this->order($checkout, uuid: self::SECOND_ORDER_UUID);
        $firstRequest = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $secondRequest = $this->request(checkout: $checkout, uuid: self::SECOND_ORDER_UUID, configuration: $configuration, now: $now);
        $gateway = new FakeCheckoutSessionGateway(results: [
            $this->sessionRecord(order: $firstOrder, request: $firstRequest, now: $now, uiMode: UiMode::Hosted),
            $this->sessionRecord(order: $secondOrder, request: $secondRequest, now: $now, uiMode: UiMode::Hosted),
        ]);
        $creator = $this->creator($configuration, $gateway);
        $first = $creator->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );
        $second = $creator->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(orderUuid: self::SECOND_ORDER_UUID, nonceByte: 'b'),
            guestReference: 'guest-browser',
            now: $now,
        );

        $this->assertNotSame($first->orderPageUuid(), $second->orderPageUuid());
        $this->assertCount(2, $gateway->requests);
        $this->assertCount(2, (new OrderPageStore($this->kirby))->orders());
    }

    #[DataProvider('changedSubmissionContexts')]
    public function testChangedSubmissionContextCannotReuseAnAttempt(string $change): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $gateway = new FakeCheckoutSessionGateway([
            $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted),
        ]);
        $this->creator($configuration, $gateway)->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );
        $changedCheckout = new CheckoutContext(
            items: $change === 'currency' ? [new CheckoutLineItem(new Product(
                request: new ProductRequest('page://product'),
                name: 'Product',
                requiresShipping: true,
                price: new Price(Money::of('16', 'USD')),
            ))] : $checkout->items(),
            languageCode: $checkout->languageCode(),
            locale: $checkout->locale(),
            userUuid: $change === 'actor' ? 'user://other' : null,
            checkoutSource: $change === 'source' ? CheckoutSource::Cart : CheckoutSource::Direct,
            uiMode: $change === 'uiMode' ? UiMode::Embedded : UiMode::Hosted,
        );
        $binding = $change === 'contextFingerprint'
            ? AttemptBinding::direct(
                items: [new ProductRequest('page://product', 2, ['size' => 'large'])],
                contextFingerprint: hash('sha256', 'changed commerce facts'),
                guestReference: 'guest-browser',
            )
            : $this->binding();

        try {
            $this->creator(
                configuration: $configuration,
                gateway: $gateway,
                stripeApiVersion: $change === 'apiVersion' ? 'changed-api-version' : ApiVersion::CURRENT,
            )->create(
                checkout: $changedCheckout,
                shipping: new ShippingContext('PT'),
                binding: $binding,
                token: $this->token(),
                guestReference: 'guest-browser',
                now: $now,
            );
            $this->fail('Changed context must not reuse the attempt.');
        } catch (CheckoutInputException $error) {
            $this->assertSame('checkout.attempt_conflict', $error->errorCode());
        }

        $this->assertSame([], $gateway->retrievals);
        $this->assertCount(1, $gateway->requests);
        $this->assertSame(1, $this->quoteCalls);
        $this->assertSame(1, $this->numberCalls);
        $this->assertSame(1, $this->requestCalls);
    }

    /** @return iterable<string, array{string}> */
    public static function changedSubmissionContexts(): iterable
    {
        $changes = ['currency', 'uiMode', 'actor', 'source', 'contextFingerprint', 'apiVersion'];

        foreach ($changes as $change) {
            yield $change => [$change];
        }
    }

    #[DataProvider('raceTokens')]
    public function testLockedRecheckAdoptsOnlyTheMatchingConcurrentAttempt(bool $sameToken): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $shipping = new ShippingContext('PT');
        $binding = $this->binding();
        $token = $this->token();
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $winnerGateway = new FakeCheckoutSessionGateway([$sessionRecord]);
        $winner = $this->creator($configuration, $winnerGateway);
        $loserGateway = new FakeCheckoutSessionGateway(retrievalResults: [$sessionRecord->id => $sessionRecord]);
        $winnerToken = $sameToken ? $token : $this->token(nonceByte: 'b');
        $loser = $this->creator(
            configuration: $configuration,
            gateway: $loserGateway,
            numberFormatter: static function () use ($winner, $checkout, $shipping, $binding, $winnerToken, $now): string {
                // Both callers have observed no order. Commit the winner before
                // the outer caller reaches its authoritative locked recheck.
                $winner->create(
                    checkout: $checkout,
                    shipping: $shipping,
                    binding: $binding,
                    token: $winnerToken,
                    guestReference: 'guest-browser',
                    now: $now,
                );

                return 'UNUSED-LOSER-NUMBER';
            },
        );

        try {
            $presentation = $loser->create(
                checkout: $checkout,
                shipping: $shipping,
                binding: $binding,
                token: $token,
                guestReference: 'guest-browser',
                now: $now,
            );
            $this->assertTrue($sameToken, 'A different nonce must not adopt the concurrent order.');
            $this->assertTrue($presentation->isReused());
        } catch (CheckoutInputException $error) {
            $this->assertFalse($sameToken);
            $this->assertSame('checkout.attempt_conflict', $error->errorCode());
        }

        $store = new OrderPageStore($this->kirby);
        $page = $store->order($order->pageUuid()) ?? $this->fail('The winner must be persisted.');
        $this->assertCount(1, $store->orders());
        $this->assertSame($order->orderNumber(), $store->data($page)['orderNumber']);
        $this->assertCount(1, $winnerGateway->requests);
        $this->assertSame([], $loserGateway->requests);
        $this->assertCount($sameToken ? 1 : 0, $loserGateway->retrievals);
        $this->assertSame(1, $this->requestCalls);
        $this->assertSame(2, $this->quoteCalls);
        $this->assertSame(2, $this->numberCalls);
    }

    /** @return iterable<string, array{bool}> */
    public static function raceTokens(): iterable
    {
        yield 'same attempt' => [true];
        yield 'same UUID but different nonce' => [false];
    }

    public function testShippingProviderAndLifecycleWorkRunOutsideWriteLocks(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configure(UiMode::Hosted);
        $checkout = $this->checkout();
        $order = $this->order($checkout);
        $request = $this->request(checkout: $checkout, configuration: $configuration, now: $now);
        $gateway = new FakeCheckoutSessionGateway([
            $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted),
        ]);
        $kirby = $this->kirby;
        // These locks reject reentrant acquisition, so a callback running inside
        // either write boundary fails here instead of silently passing the check.
        $checkLocks = static fn(): bool => OrderWriteLock::run(
            $kirby,
            'checkout-attempt:' . self::ORDER_UUID,
            static fn(): bool => OrderWriteLock::run(
                $kirby,
                OrderSchema::ORDERS_PAGE_ID . '/' . self::ORDER_UUID,
                static fn(): bool => true,
            ),
        );
        $observations = [];
        $this->quoteResolver = static function (CheckoutContext $checkout) use ($checkLocks, &$observations): ShippingQuote {
            $observations['shipping'] = $checkLocks();

            return ShippingQuote::available(InitiatingShippingSnapshotFactory::fromCheckout($checkout)->options());
        };
        $gateway->beforeCreate = static function () use ($checkLocks, &$observations): void {
            $observations['provider'] = $checkLocks();
        };
        $this->kirby->extend(['hooks' => [
            'programmatordev.stripe-checkout.order.created' => function () use ($checkLocks, &$observations): void {
                $observations['order.created'] = $checkLocks();
            },
            'programmatordev.stripe-checkout.session.created' => function () use ($checkLocks, &$observations): void {
                $observations['session.created'] = $checkLocks();
            },
        ]]);

        $this->creator($configuration, $gateway)->create(
            checkout: $checkout,
            shipping: new ShippingContext('PT'),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );

        $this->assertSame([
            'shipping' => true,
            'order.created' => true,
            'provider' => true,
            'session.created' => true,
        ], $observations);
    }

    /** @return iterable<string, array{UiMode}> */
    public static function uiModes(): iterable
    {
        foreach (UiMode::cases() as $uiMode) {
            yield $uiMode->value => [$uiMode];
        }
    }

    /** @return iterable<string, array{UiMode, CheckoutSource}> */
    public static function uiModesAndCheckoutSources(): iterable
    {
        foreach (UiMode::cases() as $uiMode) {
            foreach (CheckoutSource::cases() as $checkoutSource) {
                yield "$uiMode->value $checkoutSource->value" => [$uiMode, $checkoutSource];
            }
        }
    }

    /** @return iterable<string, array{UiMode, bool, CheckoutSource, bool, bool}> */
    public static function sessionModesAndPriceSources(): iterable
    {
        $priceSources = ['Kirby' => false, 'Stripe' => true];
        $shippingCases = [
            'digital' => [false, false],
            'physical' => [true, false],
            'mixed' => [true, true],
        ];

        foreach (UiMode::cases() as $uiMode) {
            foreach ($priceSources as $priceSource => $stripePrice) {
                foreach (CheckoutSource::cases() as $checkoutSource) {
                    foreach ($shippingCases as $shipping => [$requiresShipping, $mixed]) {
                        yield "$uiMode->value $priceSource $checkoutSource->value $shipping" => [
                            $uiMode, $stripePrice, $checkoutSource, $requiresShipping, $mixed,
                        ];
                    }
                }
            }
        }
    }

    private function configure(
        UiMode $uiMode,
        string $secretKey = 'sk_test_checkout',
        PriceSource $priceSource = PriceSource::Kirby,
    ): Configuration {
        // All collaborators read the same configuration. Cloning retains the
        // disposable site's roots, including orders used by credential-rotation tests.
        $this->kirby = $this->kirby->clone([
            'options' => [
                'programmatordev.stripe-checkout' => [
                    'settings' => [
                        'currency' => 'EUR',
                        'uiMode' => $uiMode->value,
                        'priceSource' => $priceSource->value,
                    ],
                    'stripe' => [
                        'secretKey' => $secretKey,
                    ],
                ],
            ],
        ]);
        $this->registerTestHooks();

        return (new RuntimeFactory($this->kirby))->configurationReport()->configurationOrFail();
    }

    private function checkout(
        UiMode $uiMode = UiMode::Hosted,
        bool $stripePrice = false,
        string $selectedOption = 'large',
        bool $requiresShipping = true,
        CheckoutSource $checkoutSource = CheckoutSource::Direct,
        bool $mixed = false,
    ): CheckoutContext {
        $price = Money::of('16', 'EUR');
        $request = new ProductRequest(
            reference: 'page://product',
            quantity: 2,
            selectedOptions: ['size' => $selectedOption],
        );
        $product = new Product(
            request: $request,
            name: 'T-shirt',
            requiresShipping: $requiresShipping,
            price: $stripePrice ? new StripePriceReference('price_checkouttest') : new Price($price),
            selectedOptions: [new SelectedOption(
                optionId: 'size',
                optionName: 'Size',
                valueId: $selectedOption,
                valueName: ucfirst($selectedOption),
            )],
            variantId: 'variant-' . $selectedOption,
        );
        $resolvedStripePrice = $stripePrice ? new StripePrice(
            priceId: 'price_checkouttest',
            productId: 'prod_checkouttest',
            name: 'Provider product',
            unitPrice: (new StripeCurrencyRegistry())->fromMoney($price),
            taxBehavior: \Stripe\Price::TAX_BEHAVIOR_UNSPECIFIED,
        ) : null;
        $lineItems = [new CheckoutLineItem($product, $resolvedStripePrice)];

        if ($mixed) {
            $lineItems[] = new CheckoutLineItem(new Product(
                request: new ProductRequest('page://digital'),
                name: 'Digital product',
                requiresShipping: false,
                price: $product->price(),
            ), $resolvedStripePrice);
        }

        return new CheckoutContext(
            items: $lineItems,
            languageCode: null,
            locale: 'en_US',
            userUuid: null,
            checkoutSource: $checkoutSource,
            uiMode: $uiMode,
        );
    }

    private function order(CheckoutContext $checkout, string $uuid = self::ORDER_UUID): OrderCreationContext
    {
        return new OrderCreationContext(
            uuid: $uuid,
            orderNumber: (new OrderNumberFormatter())->format($uuid),
            lineItems: array_map(OrderLineItemSnapshot::fromCheckoutLineItem(...), $checkout->items()),
            currency: 'EUR',
            checkoutSource: $checkout->checkoutSource(),
            cartRevision: $checkout->checkoutSource() === CheckoutSource::Cart ? 'revision' : null,
            userUuid: $checkout->userUuid(),
            languageCode: $checkout->languageCode(),
            uiMode: $checkout->uiMode(),
        );
    }

    private function request(
        CheckoutContext $checkout,
        Configuration $configuration,
        DateTimeImmutable $now,
        ?InitiatingShippingSnapshot $initiatingShipping = null,
        string $uuid = self::ORDER_UUID,
    ): SessionRequest {
        // Build a compatible request for the offline provider fixture. Exact
        // payload mappings are tested separately in SessionRequestBuilderTest.
        $context = (new SessionRequestContextFactory($this->kirby))->create(
            order: $this->order($checkout, $uuid),
            configuration: $configuration,
            createdAt: $now,
            initiatingUrl: 'https://kirby-stripe-checkout.test/product',
        );

        return (new SessionRequestBuilder(
            kirby: $this->kirby,
            settings: $configuration->settings(),
        ))->build(
            $context,
            $initiatingShipping ?? ($checkout->shippableItems() !== []
                ? InitiatingShippingSnapshotFactory::fromCheckout($checkout)
                : null),
        );
    }

    /** @param array{clientReferenceId?: string, metadata?: array<string, string>, liveMode?: bool, shippingOptions?: mixed} $overrides */
    private function sessionRecord(
        OrderCreationContext $order,
        SessionRequest $request,
        DateTimeImmutable $now,
        UiMode $uiMode,
        array $overrides = [],
    ): CheckoutSessionRecord {
        $parameters = $request->parameters();
        $metadata = $parameters['metadata'] ?? null;

        if (is_array($metadata) === false || array_is_list($metadata)) {
            throw new RuntimeException('The test Session request requires metadata.');
        }

        /** @var array<string, mixed> $metadata */
        $shippingOptions = array_key_exists('shippingOptions', $overrides)
            ? $overrides['shippingOptions']
            : $this->providerShippingOptions($parameters);

        return new CheckoutSessionRecord(
            id: 'cs_test_' . $order->uuid(),
            createdAt: $now->getTimestamp(),
            expiresAt: is_int($parameters['expires_at']) ? $parameters['expires_at'] : null,
            status: 'open',
            paymentStatus: 'unpaid',
            liveMode: $overrides['liveMode'] ?? false,
            mode: 'payment',
            uiMode: is_string($parameters['ui_mode']) ? $parameters['ui_mode'] : null,
            currency: 'eur',
            clientReferenceId: $overrides['clientReferenceId'] ?? $order->pageUuid(),
            integrationIdentifier: is_string($parameters['integration_identifier']) ? $parameters['integration_identifier'] : null,
            metadata: $overrides['metadata'] ?? $metadata,
            requestId: 'req_' . $order->uuid(),
            url: $uiMode === UiMode::Hosted ? 'https://checkout.stripe.com/c/pay/' . $order->uuid() : null,
            clientSecret: $uiMode === UiMode::Embedded ? 'cs_test_secret_' . $order->uuid() : null,
            shippingOptions: $shippingOptions,
        );
    }

    /**
     * @param array<string, mixed> $parameters
     * @return list<array{shipping_amount: int, shipping_rate: string}>
     */
    private function providerShippingOptions(array $parameters): array
    {
        $requestOptions = $parameters['shipping_options'] ?? [];

        if (is_array($requestOptions) === false || array_is_list($requestOptions) === false) {
            throw new RuntimeException('The test Session request contains invalid shipping options.');
        }

        return array_map(
            static fn(int $index): array => [
                'shipping_amount' => 500 + $index,
                'shipping_rate' => 'shr_test_option_' . $index,
            ],
            array_keys($requestOptions),
        );
    }

    /** @param Closure(string): string|null $numberFormatter */
    private function creator(
        Configuration $configuration,
        FakeCheckoutSessionGateway $gateway,
        string $stripeApiVersion = ApiVersion::CURRENT,
        ?Closure $numberFormatter = null,
    ): CheckoutSessionCreator {
        $preparationFactory = new CheckoutPreparationFactory(
            resolver: (new RuntimeFactory($this->kirby))->checkoutResolver(),
            orderNumbers: new OrderNumberFormatter(function (string $uuid) use ($numberFormatter): string {
                $this->numberCalls++;

                return $numberFormatter !== null
                    ? $numberFormatter($uuid)
                    : (new OrderNumberFormatter())->format(OrderData::text((new \Kirby\Uuid\Uri($uuid))->host()));
            }),
            requestBuilder: new SessionRequestBuilder($this->kirby, $configuration->settings()),
            requestCustomizer: new SessionRequestCustomizer($this->kirby),
        );

        return new CheckoutSessionCreator(
            configuration: $configuration,
            requestContextFactory: new SessionRequestContextFactory($this->kirby),
            orderPageStore: new OrderPageStore($this->kirby),
            sessionGateway: $gateway,
            preparationFactory: $preparationFactory,
            stripeApiVersion: $stripeApiVersion,
        );
    }

    private function token(
        string $orderUuid = self::ORDER_UUID,
        string $nonceByte = 'a',
    ): AttemptToken {
        return AttemptToken::forOrder(
            $orderUuid,
            static fn(int $length): string => str_repeat($nonceByte, $length),
        );
    }

    private function binding(string $selectedOption = 'large', CheckoutSource $checkoutSource = CheckoutSource::Direct): AttemptBinding
    {
        $request = new ProductRequest(
            reference: 'page://product',
            quantity: 2,
            selectedOptions: ['size' => $selectedOption],
        );

        if ($checkoutSource === CheckoutSource::Cart) {
            return AttemptBinding::cart(
                cart: new CartSnapshot('cart', 'revision', [new CartEntry('item', $request)], 1, 1),
                contextFingerprint: hash('sha256', 'checkout-context'),
                guestReference: 'guest-browser',
            );
        }

        return AttemptBinding::direct(
            items: [$request],
            contextFingerprint: hash('sha256', 'checkout-context'),
            guestReference: 'guest-browser',
        );
    }

    private function assertPresentation(
        CheckoutSessionPresentation $presentation,
        UiMode $uiMode,
        bool $reused,
    ): void {
        $this->assertSame($uiMode, $presentation->uiMode());
        $this->assertSame($reused, $presentation->isReused());

        if ($uiMode === UiMode::Hosted) {
            $this->assertNotNull($presentation->redirectUrl());
            $this->assertNull($presentation->clientSecret());
        } else {
            $this->assertNull($presentation->redirectUrl());
            $this->assertNotNull($presentation->clientSecret());
        }
    }
}
