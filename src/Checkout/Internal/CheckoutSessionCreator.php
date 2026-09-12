<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use Closure;
use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSessionPresentation;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\Configuration;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailure;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailureType;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionGatewayInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Exception\CheckoutSessionGatewayException;
use Throwable;

/** Creates, resumes, and repairs one persisted idempotent Checkout Session attempt. */
final class CheckoutSessionCreator
{
    /** @var Closure(SessionRequestContext): SessionRequest */
    private readonly Closure $prepareSessionRequest;

    /** @param Closure(SessionRequestContext): SessionRequest $prepareSessionRequest */
    public function __construct(
        private readonly Configuration $configuration,
        private readonly SessionRequestContextFactory $requestContextFactory,
        private readonly OrderPageStore $orderPageStore,
        private readonly CheckoutSessionGatewayInterface $sessionGateway,
        Closure $prepareSessionRequest,
        private readonly string $stripeApiVersion,
        private readonly CheckoutSessionRecordValidator $sessionRecordValidator = new CheckoutSessionRecordValidator(),
    ) {
        $this->prepareSessionRequest = $prepareSessionRequest;
    }

    public function create(
        OrderCreationContext $order,
        AttemptBinding $binding,
        AttemptToken $token,
        ?string $guestReference,
        DateTimeImmutable $now,
        ?string $initiatingUrl = null,
    ): CheckoutSessionPresentation {
        // The structured token reserves the only Order identity this request
        // may create or reuse. Reject mismatches before configuration or writes.
        if ($token->orderUuid() !== $order->uuid()) {
            throw new CheckoutInputException('checkout.attempt_conflict');
        }

        // The preparation callback runs only when this token has no persisted
        // order. Null values therefore distinguish reuse without rebuilding the
        // request from configuration that may have changed since the first POST.
        $requestContext = null;
        $sessionRequest = null;
        $checkoutAttempt = null;
        $page = $this->orderPageStore->createAttemptOnce(
            orderUuid: $token->orderUuid(),
            prepare: function () use ($order, $binding, $token, $guestReference, $now, $initiatingUrl, &$requestContext, &$sessionRequest, &$checkoutAttempt): array {
                $requestContext = $this->requestContextFactory->create(
                    order: $order,
                    configuration: $this->configuration,
                    createdAt: $now,
                    initiatingUrl: $initiatingUrl,
                );
                $sessionRequest = ($this->prepareSessionRequest)($requestContext);
                $checkoutAttempt = new CheckoutAttempt(
                    order: $order,
                    context: $requestContext,
                    request: $sessionRequest,
                    binding: $binding,
                    token: $token,
                    guestReference: $guestReference,
                    stripeApiVersion: $this->stripeApiVersion,
                    credentialMode: $this->configuration->stripe()->secretKeyMode(),
                    credentialFingerprint: $this->credentialFingerprint($order),
                    createdAt: $now,
                );

                return [$order, $checkoutAttempt, $now];
            },
        );

        if ($requestContext === null || $sessionRequest === null || $checkoutAttempt === null) {
            return $this->reuse(
                page: $page,
                incomingOrder: $order,
                binding: $binding,
                token: $token,
                guestReference: $guestReference,
                now: $now,
            );
        }

        return $this->createSession(
            page: $page,
            requestContext: $requestContext,
            sessionRequest: $sessionRequest,
            idempotencyKey: $checkoutAttempt->idempotencyKey(),
            reused: false,
            now: $now,
        );
    }

    private function reuse(
        OrderPage $page,
        OrderCreationContext $incomingOrder,
        AttemptBinding $binding,
        AttemptToken $token,
        ?string $guestReference,
        DateTimeImmutable $now,
    ): CheckoutSessionPresentation {
        $data = $this->orderPageStore->data($page);
        $checkoutAttempt = OrderData::map($data['checkoutAttempt']);
        $persistedOrder = OrderSerializer::context($data);
        $sessionRequest = new SessionRequest(OrderData::map($checkoutAttempt['sessionRequest']));
        $requestContext = $this->contextFromAttempt(
            order: $persistedOrder,
            checkoutAttempt: $checkoutAttempt,
            sessionRequest: $sessionRequest,
            data: $data,
        );

        $this->assertSameAttempt(
            persistedOrder: $persistedOrder,
            incomingOrder: $incomingOrder,
            checkoutAttempt: $checkoutAttempt,
            binding: $binding,
            token: $token,
            guestReference: $guestReference,
        );

        $status = CheckoutStatus::from(OrderData::text($data['checkoutStatus']));

        if ($status === CheckoutStatus::Open) {
            // Presentation credentials are intentionally not order content.
            // Retrieve the Session again to return a current URL/client secret.
            return $this->retrievePresentation(
                page: $page,
                requestContext: $requestContext,
                sessionRequest: $sessionRequest,
            );
        }

        if (in_array($status, [CheckoutStatus::Creating, CheckoutStatus::CreationUncertain], true) === false) {
            throw new CheckoutInputException('checkout.attempt_closed');
        }

        $providerFailure = $checkoutAttempt['providerFailure'];

        if ($providerFailure !== null) {
            $providerFailure = OrderData::map($providerFailure);

            if (OrderData::boolean($providerFailure['retryable'] ?? null) === false) {
                throw $this->persistedFailureException($providerFailure);
            }
        }

        if ($now >= OrderData::date($checkoutAttempt['retryUntil'])) {
            $page = $this->markRetryExpired($page, $now);
            $presentation = $this->presentationFromConcurrentAssociation(
                page: $page,
                requestContext: $requestContext,
                sessionRequest: $sessionRequest,
            );

            if ($presentation !== null) {
                return $presentation;
            }

            throw new CheckoutSessionException('checkout.attempt_retry_expired');
        }

        // This is recovery in a later PHP request after stripe-php has already
        // exhausted its own retries. Reusing the saved request and key is what
        // prevents the recovery call from creating a second Session.
        return $this->createSession(
            page: $page,
            requestContext: $requestContext,
            sessionRequest: $sessionRequest,
            idempotencyKey: OrderData::text($checkoutAttempt['idempotencyKey'], 255),
            reused: true,
            now: $now,
        );
    }

    /** @param array<string, mixed> $checkoutAttempt */
    private function assertSameAttempt(
        OrderCreationContext $persistedOrder,
        OrderCreationContext $incomingOrder,
        array $checkoutAttempt,
        AttemptBinding $binding,
        AttemptToken $token,
        ?string $guestReference,
    ): void {
        $binding->assertCompatible($incomingOrder, $guestReference);
        $binding->assertMatchesFingerprint(OrderData::text($checkoutAttempt['bindingFingerprint']));

        // The embedded UUID locates a candidate Page; the nonce-bearing full
        // token hash proves that candidate belongs to this exact attempt.
        if (
            hash_equals(OrderData::text($checkoutAttempt['tokenHash']), $token->hash()) === false
            || $persistedOrder->uuid() !== $token->orderUuid()
            || $persistedOrder->currency() !== $incomingOrder->currency()
            || $persistedOrder->uiMode() !== $incomingOrder->uiMode()
            || $checkoutAttempt['stripeApiVersion'] !== $this->stripeApiVersion
            || $checkoutAttempt['credentialMode'] !== $this->configuration->stripe()->secretKeyMode()->value
            || hash_equals(
                OrderData::text($checkoutAttempt['credentialFingerprint']),
                $this->credentialFingerprint($persistedOrder),
            ) === false
            || $checkoutAttempt['operation'] !== CheckoutAttempt::OPERATION
        ) {
            throw new CheckoutInputException('checkout.attempt_conflict');
        }
    }

    /**
     * Rebuilds the context from saved evidence so retries never re-run mutable
     * configuration or request customization with an existing idempotency key.
     *
     * @param array<string, mixed> $checkoutAttempt
     * @param array<string, mixed> $data
     */
    private function contextFromAttempt(
        OrderCreationContext $order,
        array $checkoutAttempt,
        SessionRequest $sessionRequest,
        array $data,
    ): SessionRequestContext {
        $parameters = $sessionRequest->parameters();

        return new SessionRequestContext(
            order: $order,
            locale: OrderData::text($parameters['locale'] ?? null, 80),
            expiresAt: OrderData::date($data['checkoutExpiresAt']),
            initiatingUrl: OrderData::text($checkoutAttempt['initiatingUrl'], 2048),
            successDestination: OrderData::text($checkoutAttempt['successUrl'], 2048),
            cancelDestination: OrderData::text($checkoutAttempt['cancelUrl'], 2048),
            returnDestination: OrderData::text($checkoutAttempt['returnUrl'], 2048),
        );
    }

    private function createSession(
        OrderPage $page,
        SessionRequestContext $requestContext,
        SessionRequest $sessionRequest,
        string $idempotencyKey,
        bool $reused,
        DateTimeImmutable $now,
    ): CheckoutSessionPresentation {
        try {
            $sessionRecord = $this->sessionGateway->create(
                request: $sessionRequest,
                idempotencyKey: $idempotencyKey,
            );
        } catch (CheckoutSessionGatewayException $error) {
            $page = $this->recordFailure(
                page: $page,
                failure: $error->failure(),
                now: $now,
            );
            $presentation = $this->presentationFromConcurrentAssociation(
                page: $page,
                requestContext: $requestContext,
                sessionRequest: $sessionRequest,
            );

            if ($presentation !== null) {
                return $presentation;
            }

            throw $this->sessionException($error);
        }

        try {
            // Treat every SDK response as untrusted until its correlation and
            // presentation facts agree with the exact persisted request.
            $this->sessionRecordValidator->validate(
                sessionRecord: $sessionRecord,
                context: $requestContext,
                request: $sessionRequest,
                liveMode: $this->liveMode(),
            );
        } catch (CheckoutSessionException $error) {
            $page = $this->recordFailure(
                page: $page,
                failure: CheckoutSessionFailure::fromProvider(
                    type: CheckoutSessionFailureType::Incompatible,
                    retryable: false,
                    requestId: $sessionRecord->requestId,
                ),
                now: $now,
            );
            $presentation = $this->presentationFromConcurrentAssociation(
                page: $page,
                requestContext: $requestContext,
                sessionRequest: $sessionRequest,
            );

            if ($presentation !== null) {
                return $presentation;
            }

            throw $error;
        }

        try {
            $page = $this->associate(
                page: $page,
                sessionRecord: $sessionRecord,
                now: $now,
            );
        } catch (CheckoutSessionException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new CheckoutSessionException(
                errorCode: 'checkout.session_attachment_failed',
                retryable: true,
                previous: $error,
            );
        }

        $data = $this->orderPageStore->data($page);

        if (($data['stripeCheckoutSessionId'] ?? null) !== $sessionRecord->id) {
            throw new CheckoutSessionException(
                errorCode: 'checkout.session_attachment_failed',
                retryable: true,
            );
        }

        $status = CheckoutStatus::from(OrderData::text($data['checkoutStatus']));

        if (in_array($status, [CheckoutStatus::Complete, CheckoutStatus::Expired], true)) {
            throw new CheckoutInputException('checkout.attempt_closed');
        }

        if ($status !== CheckoutStatus::Open) {
            throw new CheckoutSessionException(
                errorCode: 'checkout.session_attachment_failed',
                retryable: true,
            );
        }

        return $this->presentation(
            uiMode: $requestContext->uiMode(),
            page: $page,
            sessionRecord: $sessionRecord,
            reused: $reused,
        );
    }

    private function retrievePresentation(
        OrderPage $page,
        SessionRequestContext $requestContext,
        SessionRequest $sessionRequest,
    ): CheckoutSessionPresentation {
        $data = $this->orderPageStore->data($page);
        $sessionId = OrderData::text($data['stripeCheckoutSessionId'] ?? null, 255);

        try {
            $sessionRecord = $this->sessionGateway->retrieve(sessionId: $sessionId);
            $this->sessionRecordValidator->validate(
                sessionRecord: $sessionRecord,
                context: $requestContext,
                request: $sessionRequest,
                liveMode: $this->liveMode(),
            );
        } catch (CheckoutSessionGatewayException $error) {
            throw $this->sessionException($error);
        }

        return $this->presentation(
            uiMode: $requestContext->uiMode(),
            page: $page,
            sessionRecord: $sessionRecord,
            reused: true,
        );
    }

    private function associate(
        OrderPage $page,
        CheckoutSessionRecord $sessionRecord,
        DateTimeImmutable $now,
    ): OrderPage {
        $sessionId = $sessionRecord->id;

        if ($sessionId === null) {
            throw new CheckoutSessionException('checkout.session_incompatible');
        }

        return $this->orderPageStore->update(
            uuid: $page->uuid()->toString(),
            reduce: static function (array $data) use ($sessionId, $now): array {
                // A future webhook reconciler may associate the same Session
                // before this POST response acquires the order write lock.
                if (isset($data['stripeCheckoutSessionId'])) {
                    if ($data['stripeCheckoutSessionId'] !== $sessionId) {
                        throw new CheckoutSessionException('checkout.session_incompatible');
                    }

                    return $data;
                }

                $data['stripeCheckoutSessionId'] = $sessionId;
                $data['checkoutStatus'] = CheckoutStatus::Open->value;
                $data['checkoutOpenedAt'] = OrderData::timestamp($now);
                $data['updatedAt'] = max($data['updatedAt'], OrderData::timestamp($now));

                return $data;
            },
            events: [LifecycleEventType::SessionCreated],
            triggerType: 'checkout.session',
            triggerId: $sessionId,
        );
    }

    private function recordFailure(
        OrderPage $page,
        CheckoutSessionFailure $failure,
        DateTimeImmutable $now,
    ): OrderPage {
        return $this->orderPageStore->update(
            uuid: $page->uuid()->toString(),
            reduce: static function (array $data) use ($failure, $now): array {
                $status = CheckoutStatus::from(OrderData::text($data['checkoutStatus']));

                // A concurrent request or webhook owns the stronger observation.
                // Late local failures must not age or modify that record.
                if (in_array($status, [CheckoutStatus::Creating, CheckoutStatus::CreationUncertain], true) === false) {
                    return $data;
                }

                $checkoutAttempt = OrderData::map($data['checkoutAttempt']);
                $checkoutAttempt['providerFailure'] = [
                    ...$failure->toArray(),
                    'occurredAt' => OrderData::timestamp($now),
                ];
                $data['checkoutAttempt'] = $checkoutAttempt;
                $data['updatedAt'] = max($data['updatedAt'], OrderData::timestamp($now));

                // Outcome certainty controls state; retry permission remains a
                // separate persisted decision consulted by attempt reuse.
                if ($failure->type() === CheckoutSessionFailureType::Uncertain || $failure->type() === CheckoutSessionFailureType::Incompatible) {
                    $data['checkoutStatus'] = CheckoutStatus::CreationUncertain->value;
                    $data['creationUncertainAt'] ??= OrderData::timestamp($now);
                } elseif ($failure->isRetryable() === false) {
                    $data['checkoutStatus'] = CheckoutStatus::CreationFailed->value;
                    $data['creationFailedAt'] ??= OrderData::timestamp($now);
                }

                return $data;
            },
        );
    }

    private function markRetryExpired(OrderPage $page, DateTimeImmutable $now): OrderPage
    {
        return $this->orderPageStore->update(
            uuid: $page->uuid()->toString(),
            reduce: static function (array $data) use ($now): array {
                $status = CheckoutStatus::from(OrderData::text($data['checkoutStatus']));

                if (in_array($status, [CheckoutStatus::Creating, CheckoutStatus::CreationUncertain], true) === false) {
                    return $data;
                }

                $data['checkoutStatus'] = CheckoutStatus::CreationFailed->value;
                $data['creationFailedAt'] ??= OrderData::timestamp($now);
                $data['updatedAt'] = max($data['updatedAt'], OrderData::timestamp($now));

                return $data;
            },
        );
    }

    /** Returns the presentation established by a concurrent successful observer. */
    private function presentationFromConcurrentAssociation(
        OrderPage $page,
        SessionRequestContext $requestContext,
        SessionRequest $sessionRequest,
    ): ?CheckoutSessionPresentation {
        $status = CheckoutStatus::from(OrderData::text($this->orderPageStore->data($page)['checkoutStatus']));

        if ($status === CheckoutStatus::Open) {
            return $this->retrievePresentation(
                page: $page,
                requestContext: $requestContext,
                sessionRequest: $sessionRequest,
            );
        }

        if (in_array($status, [CheckoutStatus::Complete, CheckoutStatus::Expired], true)) {
            throw new CheckoutInputException('checkout.attempt_closed');
        }

        return null;
    }

    private function sessionException(CheckoutSessionGatewayException $error): CheckoutSessionException
    {
        return new CheckoutSessionException(
            errorCode: 'checkout.session_' . str_replace('provider_', '', $error->failure()->type()->value),
            retryable: $error->failure()->isRetryable(),
            previous: $error,
        );
    }

    /** @param array<string, mixed> $providerFailure */
    private function persistedFailureException(array $providerFailure): CheckoutSessionException
    {
        $type = CheckoutSessionFailureType::from(OrderData::text($providerFailure['type'] ?? null));

        return new CheckoutSessionException(
            errorCode: 'checkout.session_' . str_replace('provider_', '', $type->value),
            retryable: false,
        );
    }

    private function presentation(
        UiMode $uiMode,
        OrderPage $page,
        CheckoutSessionRecord $sessionRecord,
        bool $reused,
    ): CheckoutSessionPresentation {
        return new CheckoutSessionPresentation(
            uiMode: $uiMode,
            orderPageUuid: $page->uuid()->toString(),
            reused: $reused,
            redirectUrl: $uiMode === UiMode::Hosted ? $sessionRecord->url : null,
            clientSecret: $uiMode === UiMode::Embedded ? $sessionRecord->clientSecret : null,
        );
    }

    private function liveMode(): ?bool
    {
        return match ($this->configuration->stripe()->secretKeyMode()) {
            CredentialMode::Live => true,
            CredentialMode::Test => false,
            CredentialMode::Unknown => null,
        };
    }

    private function credentialFingerprint(OrderCreationContext $order): string
    {
        return $this->configuration->stripe()->secretKeyFingerprint($order->pageUuid());
    }
}
