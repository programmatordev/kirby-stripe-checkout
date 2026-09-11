<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSessionPresentation;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptBinding;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptToken;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSessionCreator;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestBuilder;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestContextFactory;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\Configuration;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Kirby\OrderCreationContextFactory;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailure;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailureType;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Exception\CheckoutSessionGatewayException;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\Stripe\FakeCheckoutSessionGateway;
use RuntimeException;
use Stripe\Util\ApiVersion;

final class CheckoutSessionCreatorTest extends KirbyTestCase
{
    #[DataProvider('sessionModesAndPriceSources')]
    public function testCreatesAValidatedSessionForBothModesAndPriceSources(UiMode $uiMode, bool $stripePrice): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configuration($uiMode);
        $order = $this->order($uiMode, $stripePrice);
        $request = $this->request(order: $order, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: $uiMode);
        $gateway = new FakeCheckoutSessionGateway([$sessionRecord]);
        $presentation = $this->creator($configuration, $gateway)->create(
            order: $order,
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
            initiatingUrl: 'https://kirby-stripe-checkout.test/product',
        );

        $this->assertPresentation($presentation, $uiMode, reused: false);
        $this->assertCount(1, $gateway->requests);
        $this->assertSame($request->parameters(), $gateway->requests[0]->parameters());
        $this->assertSame(['stripe-checkout/session/' . $order->uuid()], $gateway->idempotencyKeys);

        $data = (new OrderPageStore($this->kirby))->data(
            (new OrderPageStore($this->kirby))->order($order->pageUuid()) ?? $this->fail('Order was not persisted.'),
        );
        $checkoutAttempt = OrderData::map($data['checkoutAttempt']);
        $this->assertSame(CheckoutStatus::Open->value, $data['checkoutStatus']);
        $this->assertSame($sessionRecord->id, $data['stripeCheckoutSessionId']);
        $this->assertSame($request->parameters(), $checkoutAttempt['sessionRequest']);
        $this->assertSame($this->binding()->fingerprint(), $checkoutAttempt['bindingFingerprint']);
        $this->assertSame($request->fingerprint(), $checkoutAttempt['requestFingerprint']);
        $this->assertSame(ApiVersion::CURRENT, $checkoutAttempt['stripeApiVersion']);
        $this->assertSame('test', $checkoutAttempt['credentialMode']);
        $this->assertSame('stripe-checkout/session/' . $order->uuid(), $checkoutAttempt['idempotencyKey']);
        $persisted = OrderData::json($data);

        if ($sessionRecord->url !== null) {
            $this->assertStringNotContainsString($sessionRecord->url, $persisted);
        }

        if ($sessionRecord->clientSecret !== null) {
            $this->assertStringNotContainsString($sessionRecord->clientSecret, $persisted);
        }

        $deliveries = OrderData::list($data['lifecycleDeliveries']);
        $types = array_map(
            static fn(mixed $entry): mixed => OrderData::map(OrderData::map($entry)['event'])['type'] ?? null,
            $deliveries,
        );
        $this->assertSame(['order.created', 'session.created'], $types);
    }

    public function testDuplicateTokenReusesTheOrderAndRetrievesTheExistingSession(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configuration(UiMode::Hosted);
        $order = $this->order(UiMode::Hosted);
        $request = $this->request(order: $order, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $gateway = new FakeCheckoutSessionGateway(
            results: [$sessionRecord],
            retrievalResults: [$sessionRecord->id => $sessionRecord],
        );
        $requestCalls = 0;
        $kirby = $this->kirby;
        $creator = $this->creator(
            configuration: $configuration,
            gateway: $gateway,
            sessionRequestFactory: static function (SessionRequestContext $context) use (&$requestCalls, $kirby): SessionRequest {
                $requestCalls++;

                return (new SessionRequestBuilder($kirby))->build($context);
            },
        );
        $first = $creator->create(
            order: $order,
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );
        $second = $creator->create(
            order: $this->order(UiMode::Hosted),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now->add(new DateInterval('PT1M')),
            initiatingUrl: 'https://kirby-stripe-checkout.test/changed',
        );

        $this->assertFalse($first->isReused());
        $this->assertTrue($second->isReused());
        $this->assertSame($first->orderPageUuid(), $second->orderPageUuid());
        $this->assertSame(1, $requestCalls);
        $this->assertCount(1, $gateway->requests);
        $this->assertSame([$sessionRecord->id], $gateway->retrievals);
        $this->assertCount(1, (new OrderPageStore($this->kirby))->orders());
    }

    public function testAnUncertainFailurePersistsTheExactAttemptBeforeItCanBeRetried(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configuration(UiMode::Hosted);
        $order = $this->order(UiMode::Hosted);
        $failure = new CheckoutSessionFailure(
            type: CheckoutSessionFailureType::Uncertain,
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
                order: $order,
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
        $retryRequestCalls = 0;
        $presentation = $this->creator(
            configuration: $configuration,
            gateway: $retryGateway,
            sessionRequestFactory: static function () use (&$retryRequestCalls): SessionRequest {
                $retryRequestCalls++;

                throw new RuntimeException('An existing request must not be rebuilt.');
            },
        )->create(
            order: $this->order(UiMode::Hosted),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now->add(new DateInterval('PT1M')),
        );

        $this->assertTrue($presentation->isReused());
        $this->assertSame(0, $retryRequestCalls);
        $this->assertSame($checkoutAttempt['sessionRequest'], $retryGateway->requests[0]->parameters());
        $this->assertSame([$checkoutAttempt['idempotencyKey']], $retryGateway->idempotencyKeys);
    }

    public function testRejectedAndIncompatibleResultsBecomeDurableCreationStates(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configuration(UiMode::Hosted);
        $rejectedOrder = $this->order(UiMode::Hosted);
        $rejectedGateway = new FakeCheckoutSessionGateway();
        $rejectedGateway->creationFailure = new CheckoutSessionGatewayException(
            failure: new CheckoutSessionFailure(
                type: CheckoutSessionFailureType::Rejected,
                requestId: 'req_rejected',
                providerCode: 'parameter_invalid_integer',
                providerType: 'invalid_request_error',
            ),
            error: new RuntimeException('private'),
        );

        try {
            $this->creator($configuration, $rejectedGateway)->create(
                order: $rejectedOrder,
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

        $incompatibleOrder = $this->order(UiMode::Hosted, selectedOption: 'other');
        $request = $this->request(order: $incompatibleOrder, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(
            order: $incompatibleOrder,
            request: $request,
            now: $now,
            uiMode: UiMode::Hosted,
            overrides: ['clientReferenceId' => 'page://wrong'],
        );

        try {
            $this->creator($configuration, new FakeCheckoutSessionGateway([$sessionRecord]))->create(
                order: $incompatibleOrder,
                binding: $this->binding(selectedOption: 'other'),
                token: new AttemptToken(str_repeat('b', 32)),
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
        $configuration = $this->configuration(UiMode::Hosted);
        $order = $this->order(UiMode::Hosted);
        $gateway = new FakeCheckoutSessionGateway();
        $gateway->creationFailure = new CheckoutSessionGatewayException(
            failure: new CheckoutSessionFailure(type: CheckoutSessionFailureType::Uncertain),
            error: new RuntimeException('private'),
        );

        try {
            $this->creator($configuration, $gateway)->create(
                order: $order,
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
                order: $this->order(UiMode::Hosted),
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
        $configuration = $this->configuration(UiMode::Hosted);
        $order = $this->order(UiMode::Hosted);
        $request = $this->request(order: $order, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $gateway = new FakeCheckoutSessionGateway(
            results: [$sessionRecord],
            retrievalResults: [$sessionRecord->id => $sessionRecord],
        );
        $creator = $this->creator($configuration, $gateway);
        $creator->create(
            order: $order,
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );

        $this->expectException(CheckoutInputException::class);
        $creator->create(
            order: $this->order(UiMode::Hosted, selectedOption: 'changed'),
            binding: $this->binding(selectedOption: 'changed'),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );
    }

    public function testChangedStripeCredentialModeCannotReuseAnExistingAttempt(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configuration(UiMode::Hosted);
        $order = $this->order(UiMode::Hosted);
        $request = $this->request(order: $order, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $creator = $this->creator($configuration, new FakeCheckoutSessionGateway([$sessionRecord]));
        $creator->create(
            order: $order,
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );

        $this->expectException(CheckoutInputException::class);
        $this->creator(
            configuration: $this->configuration(UiMode::Hosted, secretKey: 'sk_live_checkout'),
            gateway: new FakeCheckoutSessionGateway(),
        )->create(
            order: $this->order(UiMode::Hosted),
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );
    }

    public function testAWebhookObservationCanWinBeforeTheCreateResponseIsAttached(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configuration(UiMode::Hosted);
        $order = $this->order(UiMode::Hosted);
        $request = $this->request(order: $order, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $gateway = new FakeCheckoutSessionGateway([$sessionRecord]);
        $store = new OrderPageStore($this->kirby);
        $gateway->beforeCreate = static function () use ($store, $order, $sessionRecord, $now): void {
            $page = $store->order($order->pageUuid());

            if ($page === null) {
                throw new RuntimeException('The order must exist before the provider mutation.');
            }

            $store->update($order->pageUuid(), static function (array $data) use ($sessionRecord, $now): array {
                $data['stripeCheckoutSessionId'] = $sessionRecord->id;
                $data['checkoutStatus'] = CheckoutStatus::Open->value;
                $data['checkoutOpenedAt'] = OrderData::timestamp($now);
                $data['updatedAt'] = max($data['updatedAt'], OrderData::timestamp($now));

                return $data;
            });
        };

        $presentation = $this->creator($configuration, $gateway)->create(
            order: $order,
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
        $configuration = $this->configuration(UiMode::Hosted);
        $order = $this->order(UiMode::Hosted);
        $request = $this->request(order: $order, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $store = new OrderPageStore($this->kirby);
        $gateway = new FakeCheckoutSessionGateway(retrievalResults: [$sessionRecord->id => $sessionRecord]);
        $gateway->creationFailure = new CheckoutSessionGatewayException(
            failure: new CheckoutSessionFailure(type: CheckoutSessionFailureType::Retryable),
            error: new RuntimeException('private'),
        );
        $gateway->beforeCreate = static function () use ($store, $order, $sessionRecord, $now): void {
            $store->update($order->pageUuid(), static function (array $data) use ($sessionRecord, $now): array {
                $data['stripeCheckoutSessionId'] = $sessionRecord->id;
                $data['checkoutStatus'] = CheckoutStatus::Open->value;
                $data['checkoutOpenedAt'] = OrderData::timestamp($now);
                $data['updatedAt'] = max($data['updatedAt'], OrderData::timestamp($now));

                return $data;
            });
        };

        $presentation = $this->creator($configuration, $gateway)->create(
            order: $order,
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );

        $this->assertTrue($presentation->isReused());
        $this->assertSame([$sessionRecord->id], $gateway->retrievals);
        $this->assertSame(CheckoutStatus::Open->value, $store->data($store->order($order->pageUuid()) ?? $this->fail('Order missing.'))['checkoutStatus']);
    }

    public function testProviderMetadataOrderAndAdditionalCustomKeysDoNotAffectCorrelation(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configuration(UiMode::Hosted);
        $order = $this->order(UiMode::Hosted);
        $request = $this->request(order: $order, configuration: $configuration, now: $now);
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
            order: $order,
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
        $configuration = $this->configuration(UiMode::Hosted);
        $order = $this->order(UiMode::Hosted);
        $request = $this->request(order: $order, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $gateway = new FakeCheckoutSessionGateway([$sessionRecord]);
        $creator = $this->creator($configuration, $gateway);
        $creator->create(
            order: $order,
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );
        $gateway->retrievalFailure = new CheckoutSessionGatewayException(
            failure: new CheckoutSessionFailure(type: CheckoutSessionFailureType::Rejected),
            error: new RuntimeException('private'),
        );

        try {
            $creator->create(
                order: $this->order(UiMode::Hosted),
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
        $configuration = $this->configuration(UiMode::Hosted);
        $order = $this->order(UiMode::Hosted);
        $request = $this->request(order: $order, configuration: $configuration, now: $now);
        $sessionRecord = $this->sessionRecord(order: $order, request: $request, now: $now, uiMode: UiMode::Hosted);
        $gateway = new FakeCheckoutSessionGateway([$sessionRecord]);
        $store = new OrderPageStore($this->kirby);
        $gateway->beforeCreate = static function () use ($store, $order, $now): void {
            $store->update($order->pageUuid(), static function (array $data) use ($now): array {
                $data['stripeCheckoutSessionId'] = 'cs_test_conflict';
                $data['checkoutStatus'] = CheckoutStatus::Open->value;
                $data['checkoutOpenedAt'] = OrderData::timestamp($now);
                $data['updatedAt'] = max($data['updatedAt'], OrderData::timestamp($now));

                return $data;
            });
        };

        try {
            $this->creator($configuration, $gateway)->create(
                order: $order,
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
        $configuration = $this->configuration(UiMode::Hosted);
        $order = $this->order(UiMode::Hosted);
        $request = $this->request(order: $order, configuration: $configuration, now: $now);
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
                order: $order,
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
        $configuration = $this->configuration(UiMode::Hosted);
        $gateway = new FakeCheckoutSessionGateway();
        $creator = $this->creator(
            configuration: $configuration,
            gateway: $gateway,
            sessionRequestFactory: static fn(): never => throw new CheckoutInputException('checkout.request_invalid'),
        );

        try {
            $creator->create(
                order: $this->order(UiMode::Hosted),
                binding: $this->binding(),
                token: $this->token(),
                guestReference: 'guest-browser',
                now: new DateTimeImmutable('2026-09-11T12:00:00Z'),
            );
            $this->fail('Expected request validation to fail.');
        } catch (CheckoutInputException $error) {
            $this->assertSame('checkout.request_invalid', $error->errorCode());
        }

        $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
        $this->assertSame([], $gateway->requests);
    }

    public function testANewCustomerAttemptCreatesANewOrderAndSession(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00Z');
        $configuration = $this->configuration(UiMode::Hosted);
        $firstOrder = $this->order(UiMode::Hosted);
        $secondOrder = $this->order(UiMode::Hosted);
        $firstRequest = $this->request(order: $firstOrder, configuration: $configuration, now: $now);
        $secondRequest = $this->request(order: $secondOrder, configuration: $configuration, now: $now);
        $gateway = new FakeCheckoutSessionGateway(results: [
            $this->sessionRecord(order: $firstOrder, request: $firstRequest, now: $now, uiMode: UiMode::Hosted),
            $this->sessionRecord(order: $secondOrder, request: $secondRequest, now: $now, uiMode: UiMode::Hosted),
        ]);
        $creator = $this->creator($configuration, $gateway);
        $first = $creator->create(
            order: $firstOrder,
            binding: $this->binding(),
            token: $this->token(),
            guestReference: 'guest-browser',
            now: $now,
        );
        $second = $creator->create(
            order: $secondOrder,
            binding: $this->binding(),
            token: new AttemptToken(str_repeat('b', 32)),
            guestReference: 'guest-browser',
            now: $now,
        );

        $this->assertNotSame($first->orderPageUuid(), $second->orderPageUuid());
        $this->assertCount(2, $gateway->requests);
        $this->assertCount(2, (new OrderPageStore($this->kirby))->orders());
    }

    /** @return iterable<string, array{UiMode, bool}> */
    public static function sessionModesAndPriceSources(): iterable
    {
        yield 'hosted Kirby price' => [UiMode::Hosted, false];
        yield 'embedded Kirby price' => [UiMode::Embedded, false];
        yield 'hosted Stripe Price' => [UiMode::Hosted, true];
        yield 'embedded Stripe Price' => [UiMode::Embedded, true];
    }

    private function configuration(UiMode $uiMode, string $secretKey = 'sk_test_checkout'): Configuration
    {
        return (new ConfigurationResolver())->resolve([
            'programmatordev.stripe-checkout' => [
                'settings' => [
                    'currency' => 'EUR',
                    'uiMode' => $uiMode->value,
                ],
                'stripe' => [
                    'secretKey' => $secretKey,
                ],
            ],
        ])->configurationOrFail();
    }

    private function order(UiMode $uiMode, bool $stripePrice = false, string $selectedOption = 'large'): OrderCreationContext
    {
        $price = Money::of('16', 'EUR');
        $request = new ProductRequest(
            reference: 'page://product',
            quantity: 2,
            selectedOptions: ['size' => $selectedOption],
        );
        $product = new Product(
            request: $request,
            name: 'T-shirt',
            requiresShipping: true,
            price: $stripePrice ? new StripePriceReference('price_checkouttest') : new Price($price),
            selectedOptions: [new SelectedOption(
                optionId: 'size',
                optionName: 'Size',
                valueId: $selectedOption,
                valueName: ucfirst($selectedOption),
            )],
            variantId: 'variant-' . $selectedOption,
        );
        $lineItem = OrderLineItemSnapshot::fromProduct(
            product: $product,
            price: $price,
            stripeProductId: $stripePrice ? 'prod_checkouttest' : null,
        );

        return (new OrderCreationContextFactory($this->kirby))->create(
            lineItems: [$lineItem],
            currency: 'EUR',
            source: CheckoutSource::Direct,
            cartRevision: null,
            userUuid: null,
            languageCode: null,
            uiMode: $uiMode,
        );
    }

    private function request(
        OrderCreationContext $order,
        Configuration $configuration,
        DateTimeImmutable $now,
    ): SessionRequest {
        $context = (new SessionRequestContextFactory($this->kirby))->create(
            order: $order,
            configuration: $configuration,
            createdAt: $now,
            initiatingUrl: 'https://kirby-stripe-checkout.test/product',
        );

        return (new SessionRequestBuilder($this->kirby))->build($context);
    }

    /** @param array{clientReferenceId?: string, metadata?: array<string, string>} $overrides */
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
        return new CheckoutSessionRecord(
            id: 'cs_test_' . $order->uuid(),
            createdAt: $now->getTimestamp(),
            expiresAt: is_int($parameters['expires_at']) ? $parameters['expires_at'] : null,
            status: 'open',
            paymentStatus: 'unpaid',
            liveMode: false,
            mode: 'payment',
            uiMode: is_string($parameters['ui_mode']) ? $parameters['ui_mode'] : null,
            currency: 'eur',
            clientReferenceId: $overrides['clientReferenceId'] ?? $order->pageUuid(),
            integrationIdentifier: is_string($parameters['integration_identifier']) ? $parameters['integration_identifier'] : null,
            metadata: $overrides['metadata'] ?? $metadata,
            requestId: 'req_' . $order->uuid(),
            url: $uiMode === UiMode::Hosted ? 'https://checkout.stripe.com/c/pay/' . $order->uuid() : null,
            clientSecret: $uiMode === UiMode::Embedded ? 'cs_test_secret_' . $order->uuid() : null,
        );
    }

    /** @param (callable(SessionRequestContext): SessionRequest)|null $sessionRequestFactory */
    private function creator(
        Configuration $configuration,
        FakeCheckoutSessionGateway $gateway,
        ?callable $sessionRequestFactory = null,
    ): CheckoutSessionCreator {
        $sessionRequestFactory ??= fn(SessionRequestContext $context): SessionRequest => (new SessionRequestBuilder($this->kirby))->build($context);

        return new CheckoutSessionCreator(
            configuration: $configuration,
            requestContextFactory: new SessionRequestContextFactory($this->kirby),
            orderPageStore: new OrderPageStore($this->kirby),
            sessionGateway: $gateway,
            sessionRequestFactory: $sessionRequestFactory(...),
            stripeApiVersion: ApiVersion::CURRENT,
        );
    }

    private function token(): AttemptToken
    {
        return new AttemptToken(str_repeat('a', 32));
    }

    private function binding(string $selectedOption = 'large'): AttemptBinding
    {
        return AttemptBinding::direct(
            items: [new ProductRequest(
                reference: 'page://product',
                quantity: 2,
                selectedOptions: ['size' => $selectedOption],
            )],
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
