<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\PersistenceErrorCode;
use ProgrammatorDev\StripeCheckout\Lifecycle\Internal\LifecycleNotification;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderQueryException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutSessionAssociation;
use ProgrammatorDev\StripeCheckout\Order\Internal\DisputeCollection;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer;
use ProgrammatorDev\StripeCheckout\Order\Internal\RefundCollection;
use ProgrammatorDev\StripeCheckout\Order\Internal\StripeEventLedger;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionObservation;
use Stripe\Event;

/** @internal Owns complete provider reads, fresh-state commits and Event outcomes; never creates a Session. */
final class CheckoutSessionReconciler
{
    public function __construct(
        private readonly OrderPageStore $orders,
        private readonly CheckoutSessionRetriever $retriever,
        private readonly CredentialMode $credentialMode,
        private readonly CheckoutSessionReducer $reducer = new CheckoutSessionReducer(),
        private readonly ?RefundRetriever $refundRetriever = null,
        private readonly ?DisputeRetriever $disputeRetriever = null,
    ) {}

    /**
     * Refreshes the saved Session and both financial collections as one order-wide operation.
     * Financial webhook subscriptions are not required, and no provider Event is invented.
     */
    public function syncOrder(string $pageUuid): ReconciliationResult
    {
        try {
            $page = $this->orders->order($pageUuid);

            if ($page === null) {
                return ReconciliationResult::failed(PersistenceErrorCode::ORDER_UNAVAILABLE);
            }

            $data = $this->orders->data($page);
            $sessionId = $data['stripeCheckoutSessionId'] ?? null;

            if ($sessionId === null) {
                return ReconciliationResult::failed(CheckoutErrorCode::SESSION_MISSING);
            }

            $checkoutAttempt = OrderData::map($data['checkoutAttempt']);

            if ($this->credentialMode === CredentialMode::Unknown || $checkoutAttempt['credentialMode'] !== $this->credentialMode->value) {
                return ReconciliationResult::failed(CheckoutErrorCode::SESSION_INCOMPATIBLE);
            }

            return $this->reconcileOrder(
                pageUuid: $pageUuid,
                sessionId: OrderData::string($sessionId),
                trigger: null,
                baseline: $data,
                refreshFinancials: true,
            );
        } catch (CheckoutSessionException $error) {
            return ReconciliationResult::failed($error->errorCode(), $error->isRetryable());
        } catch (OrderDataException $error) {
            return ReconciliationResult::failed($error->errorCode());
        } catch (OrderStorageException $error) {
            // Match the webhook's recoverable storage categories; invalid content needs repair before another sync.
            $retryable = in_array($error->errorCode(), [PersistenceErrorCode::BUSY, PersistenceErrorCode::WRITE_FAILED, PersistenceErrorCode::VERIFY_FAILED], true);

            return ReconciliationResult::failed(errorCode: $error->errorCode(), retryable: $retryable);
        } catch (OrderQueryException $error) {
            return ReconciliationResult::failed(errorCode: $error->errorCode(), retryable: true);
        }
    }

    /**
     * $event must come from a verified HTTP edge or an explicit trusted provider read.
     * Null is a current-state reconciliation, not an invented Stripe Event.
     * This read establishes Checkout/payment facts for incomplete-order recovery;
     * unlike syncOrder(), it preserves financial collections that were not read.
     */
    public function reconcile(string $pageUuid, string $sessionId, ?Event $event = null): OrderPage
    {
        $page = $this->orders->order($pageUuid) ?? throw new OrderDataException();
        $data = $this->orders->data($page);
        $order = OrderSerializer::context($data);
        $checkoutAttempt = OrderData::map($data['checkoutAttempt']);
        $baseline = $data;
        $observation = null;

        if (
            $this->credentialMode === CredentialMode::Unknown
            || ($checkoutAttempt['credentialMode'] ?? null) !== $this->credentialMode->value
            || isset($data['stripeCheckoutSessionId']) && $data['stripeCheckoutSessionId'] !== $sessionId
        ) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
        }

        try {
            $trigger = $event === null ? null : ReconciliationEvent::fromStripe($event, $order, $sessionId, $this->credentialMode);

            if ($trigger?->type === Event::PAYMENT_INTENT_REQUIRES_ACTION) {
                // Early action Events can precede the local Session association.
                // Establish their PaymentIntent backlink through a complete read before attaching an Event ledger entry to this order.
                $paymentIntentId = $data['stripePaymentIntentId'] ?? null;

                if ($paymentIntentId === null) {
                    $observation = $this->retrieve($baseline, $sessionId);
                    $paymentIntentId = $observation->payment()->stripePaymentIntentId();
                }

                if ($trigger->resourceId !== $paymentIntentId) {
                    throw new OrderDataException();
                }
            }
        } catch (OrderDataException $error) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE, previous: $error);
        }

        return $this->reconcileOrder(
            pageUuid: $pageUuid,
            sessionId: $sessionId,
            trigger: $trigger,
            baseline: $baseline,
            observation: $observation,
        )->orderPageOrFail();
    }

    public function refundCorrelation(Event $event): ?RefundCorrelation
    {
        try {
            return ($this->refundRetriever ?? throw new OrderDataException())->correlate($event, $this->credentialMode);
        } catch (OrderDataException $error) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE, previous: $error);
        }
    }

    public function reconcileRefund(RefundCorrelation $correlation): OrderPage
    {
        try {
            $page = $this->orders->order($correlation->pageUuid) ?? throw new OrderDataException();
            $data = $this->orders->data($page);
            $checkoutAttempt = OrderData::map($data['checkoutAttempt']);

            if (($checkoutAttempt['credentialMode'] ?? null) !== $this->credentialMode->value) {
                throw new OrderDataException();
            }

            if (isset($data['stripePaymentIntentId']) && $data['stripePaymentIntentId'] !== $correlation->refund->stripePaymentIntentId()) {
                throw new OrderDataException();
            }

            if ($data['currency'] !== $correlation->refund->amount()->getCurrency()->getCurrencyCode()) {
                throw new OrderDataException();
            }

            $sessionId = isset($data['stripeCheckoutSessionId']) ? OrderData::string($data['stripeCheckoutSessionId']) : null;

            return $this->reconcileOrder(
                pageUuid: $correlation->pageUuid,
                sessionId: $sessionId,
                trigger: $correlation->trigger,
                baseline: $data,
                refundCorrelation: $correlation,
            )->orderPageOrFail();
        } catch (OrderDataException $error) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE, previous: $error);
        }
    }

    public function disputeCorrelation(Event $event): ?DisputeCorrelation
    {
        try {
            return ($this->disputeRetriever ?? throw new OrderDataException())->correlate($event, $this->credentialMode);
        } catch (OrderDataException $error) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE, previous: $error);
        }
    }

    public function reconcileDispute(DisputeCorrelation $correlation): OrderPage
    {
        try {
            $page = $this->orders->order($correlation->pageUuid) ?? throw new OrderDataException();
            $data = $this->orders->data($page);
            $checkoutAttempt = OrderData::map($data['checkoutAttempt']);

            if (($checkoutAttempt['credentialMode'] ?? null) !== $this->credentialMode->value) {
                throw new OrderDataException();
            }

            if (isset($data['stripePaymentIntentId']) && $data['stripePaymentIntentId'] !== $correlation->dispute->stripePaymentIntentId()) {
                throw new OrderDataException();
            }

            if ($data['currency'] !== $correlation->dispute->amount()->getCurrency()->getCurrencyCode()) {
                throw new OrderDataException();
            }

            $sessionId = isset($data['stripeCheckoutSessionId']) ? OrderData::string($data['stripeCheckoutSessionId']) : null;

            return $this->reconcileOrder(
                pageUuid: $correlation->pageUuid,
                sessionId: $sessionId,
                trigger: $correlation->trigger,
                baseline: $data,
                disputeCorrelation: $correlation,
            )->orderPageOrFail();
        } catch (OrderDataException $error) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE, previous: $error);
        }
    }

    /** @param array<string, mixed> $baseline */
    private function reconcileOrder(
        string $pageUuid,
        ?string $sessionId,
        ?ReconciliationEvent $trigger,
        array $baseline,
        ?CheckoutSessionObservation $observation = null,
        ?RefundCorrelation $refundCorrelation = null,
        ?DisputeCorrelation $disputeCorrelation = null,
        bool $refreshFinancials = false,
    ): ReconciliationResult {
        // Attempts belong to a specific provider Event, not manual refreshes or conflict re-reads.
        if ($trigger !== null) {
            $recordAttempt = static function (array $data) use ($trigger): array {
                /** @var array<string, mixed> $data */
                /** @var list<array<string, mixed>> $entries */
                $entries = $data['events'] ?? [];
                $data['events'] = StripeEventLedger::attempted($entries, $trigger, new DateTimeImmutable());

                return $data;
            };

            $page = $this->orders->update($pageUuid, $recordAttempt);
            $data = $this->orders->data($page);

            /** @var list<array<string, mixed>> $entries */
            $entries = $data['events'];

            if (StripeEventLedger::isComplete($entries, $trigger)) {
                return ReconciliationResult::committed(orderPage: $page, updated: false);
            }
        }

        try {
            if ($sessionId === null) {
                // Parent ownership already identifies this order; record lookup failures in its Event ledger too.
                $paymentIntentId = $refundCorrelation?->refund->stripePaymentIntentId() ?? $disputeCorrelation?->dispute->stripePaymentIntentId() ?? throw new OrderDataException();
                $sessionId = $this->retriever->sessionForPaymentIntent($paymentIntentId)
                    ?? throw new CheckoutSessionException(CheckoutErrorCode::SESSION_UNAVAILABLE, retryable: true);
            }

            // Do not hold a filesystem write lock across provider requests.
            // If commerce facts changed during the read, re-fetch rather than commit an older graph over the newer order.
            for ($read = 0; $read < 3; $read++) {
                // The early correlation read is already complete; the same locked conflict check protects its original baseline.
                if ($observation === null) {
                    $page = $this->orders->order($pageUuid) ?? throw new OrderDataException();
                    $baseline = $this->orders->data($page);
                    $observation = $this->retrieve($baseline, $sessionId);
                }

                if ($trigger?->type === Event::PAYMENT_INTENT_REQUIRES_ACTION && $trigger->resourceId !== $observation->payment()->stripePaymentIntentId()) {
                    throw new OrderDataException();
                }

                // Null means a family was not read; preserve its saved collection independently of the other family.
                $refunds = $refundCorrelation === null ? null
                    : ($this->refundRetriever ?? throw new OrderDataException())->retrieve($refundCorrelation, $observation);
                $disputes = $disputeCorrelation === null ? null
                    : ($this->disputeRetriever ?? throw new OrderDataException())->retrieve($disputeCorrelation, $observation);

                if ($refreshFinancials) {
                    $paymentIntentId = $observation->payment()->stripePaymentIntentId();

                    // A stale read without the established PaymentIntent cannot prove financial absence.
                    if (isset($baseline['stripePaymentIntentId']) && $baseline['stripePaymentIntentId'] !== $paymentIntentId) {
                        throw new OrderDataException();
                    }

                    // No-cost and pre-payment Sessions can have no PaymentIntent to query financial collections for.
                    if ($paymentIntentId !== null) {
                        // Both lists must complete before the protected write; a read failure leaves commerce facts unchanged.
                        $refunds = ($this->refundRetriever ?? throw new OrderDataException())->retrieve(correlation: null, observation: $observation);
                        $disputes = ($this->disputeRetriever ?? throw new OrderDataException())->retrieve(correlation: null, observation: $observation);
                    }
                }

                try {
                    // These callbacks run inside the store's lock, using its freshly loaded order rather than the read baseline.
                    $reduceOrder = function (array $data) use ($baseline, $observation, $trigger, $refunds, $disputes): array {
                        /** @var array<string, mixed> $data */
                        return $this->reduceObservation(
                            data: $data,
                            baseline: $baseline,
                            observation: $observation,
                            trigger: $trigger,
                            refunds: $refunds,
                            disputes: $disputes,
                        );
                    };
                    $updated = false;
                    $selectNotifications = function (array $before, array $after) use ($observation, $trigger, &$updated): array {
                        /** @var array<string, mixed> $before */
                        /** @var array<string, mixed> $after */
                        $notifications = $this->notificationsForObservation(
                            before: $before,
                            after: $after,
                            observation: $observation,
                            trigger: $trigger,
                        );

                        // Attribute the result to this locked commit, including action-only deliveries, not another writer's later changes.
                        $updated = $this->commerceHash($before) !== $this->commerceHash($after) || $notifications !== [];

                        return $notifications;
                    };

                    $page = $this->orders->update(
                        uuid: $pageUuid,
                        reduce: $reduceOrder,
                        notifications: $selectNotifications,
                        triggerType: $trigger?->type,
                        triggerId: $trigger?->id,
                    );

                    return ReconciliationResult::committed(orderPage: $page, updated: $updated);
                } catch (ReconciliationConflictException) {
                    // A new complete provider read follows the fresh local state; no mutation is replayed.
                    $observation = null;
                }
            }

            throw new CheckoutSessionException(CheckoutErrorCode::RECONCILIATION_CONFLICT, retryable: true);
        } catch (OrderDataException $error) {
            $failure = new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE, previous: $error);
            $this->recordFailure($pageUuid, $trigger, $failure);

            throw $failure;
        } catch (CheckoutSessionException $error) {
            $this->recordFailure($pageUuid, $trigger, $error);

            throw $error;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $baseline
     * @return array<string, mixed>
     */
    private function reduceObservation(
        array $data,
        array $baseline,
        CheckoutSessionObservation $observation,
        ?ReconciliationEvent $trigger,
        ?RefundCollection $refunds = null,
        ?DisputeCollection $disputes = null,
    ): array {
        /** @var list<array<string, mixed>> $entries */
        $entries = $data['events'] ?? [];

        // Another processor may have completed this Event while the provider read was in flight.
        if ($trigger !== null && StripeEventLedger::isComplete($entries, $trigger)) {
            return $data;
        }

        if ($this->commerceHash($baseline) !== $this->commerceHash($data)) {
            throw new ReconciliationConflictException();
        }

        $now = new DateTimeImmutable();
        $after = $this->reducer->reduce($data, $observation, $now);

        // Financial collections remain current even if the payment guards refused a stale Checkout observation.
        if ($refunds !== null) {
            $after = $this->reducer->reduceRefunds($after, $refunds, $now);
        }

        if ($disputes !== null) {
            $after = $this->reducer->reduceDisputes($after, $disputes, $now);
        }

        if ($trigger !== null) {
            $after['events'] = StripeEventLedger::outcome($entries, $trigger);
        }

        return $after;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<LifecycleNotification>
     */
    private function notificationsForObservation(
        array $before,
        array $after,
        CheckoutSessionObservation $observation,
        ?ReconciliationEvent $trigger,
    ): array {
        /** @var list<array<string, mixed>> $entries */
        $entries = $before['events'] ?? [];

        // Check the pre-commit ledger: this operation also marks the Event processed in $after during the same write.
        if ($trigger !== null && StripeEventLedger::isComplete($entries, $trigger)) {
            return [];
        }

        // Historical Events may supply action evidence, but never overwrite the current read's payment state.
        $nextAction = $trigger?->type === Event::PAYMENT_INTENT_REQUIRES_ACTION
            ? $trigger->nextAction : $observation->payment()->nextAction();

        $notifications = $this->reducer->notifications($before, $after, $nextAction);

        if (($before['refunds'] ?? []) !== ($after['refunds'] ?? [])) {
            $notifications[] = new LifecycleNotification(LifecycleEventType::RefundUpdated);
        }

        // Evidence or balance movements can change while the dispute summary stays the same.
        if (($before['disputes'] ?? []) !== ($after['disputes'] ?? [])) {
            $notifications[] = new LifecycleNotification(LifecycleEventType::DisputeUpdated);
        }

        return $notifications;
    }

    /** @param array<string, mixed> $data */
    private function retrieve(array $data, string $sessionId): CheckoutSessionObservation
    {
        $checkoutAttempt = OrderData::map($data['checkoutAttempt']);
        $request = new SessionRequest(OrderData::map($checkoutAttempt['sessionRequest']));

        return $this->retriever->retrieve(
            sessionId: $sessionId,
            order: OrderSerializer::context($data),
            request: $request,
            credentialMode: $this->credentialMode,
            association: isset($data['stripeCheckoutSessionId']) ? CheckoutSessionAssociation::fromOrderData($data, $request) : null,
        );
    }

    /** @param array<string, mixed> $data */
    private function commerceHash(array $data): string
    {
        // Concurrent ledger attempts do not invalidate provider facts; only a change to the order's commerce state requires re-fetching.
        unset($data['events'], $data['lifecycleDeliveries']);

        return OrderSerializer::hash($data);
    }

    private function recordFailure(string $pageUuid, ?ReconciliationEvent $trigger, CheckoutSessionException $error): void
    {
        if ($trigger === null) {
            return;
        }

        $recordFailure = static function (array $data) use ($trigger, $error): array {
            /** @var array<string, mixed> $data */
            /** @var list<array<string, mixed>> $entries */
            $entries = $data['events'] ?? [];
            $data['events'] = StripeEventLedger::outcome($entries, $trigger, $error->errorCode());

            return $data;
        };

        $this->orders->update($pageUuid, $recordFailure);
    }
}
