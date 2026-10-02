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
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutSessionAssociation;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer;
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
    ) {}

    /**
     * $event must come from a verified HTTP edge or an explicit trusted provider read.
     * Null is a current-state reconciliation, not an invented Stripe Event.
     */
    public function reconcile(string $pageUuid, string $sessionId, ?Event $event = null): OrderPage
    {
        $page = $this->orders->order($pageUuid) ?? throw new OrderDataException();
        $data = $this->orders->data($page);
        $order = OrderSerializer::context($data);
        $checkoutAttempt = OrderData::map($data['checkoutAttempt']);

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
                $paymentIntentId = $data['stripePaymentIntentId'] ?? $this->retrieve($data, $sessionId)->payment()->stripePaymentIntentId();

                if ($trigger->resourceId !== $paymentIntentId) {
                    throw new OrderDataException();
                }
            }
        } catch (OrderDataException $error) {
            throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE, previous: $error);
        }

        if ($trigger !== null) {
            $page = $this->orders->update($pageUuid, static function (array $data) use ($trigger): array {
                /** @var list<array<string, mixed>> $entries */
                $entries = $data['events'] ?? [];
                $data['events'] = StripeEventLedger::attempted($entries, $trigger, new DateTimeImmutable());

                return $data;
            });
            $data = $this->orders->data($page);

            /** @var list<array<string, mixed>> $entries */
            $entries = $data['events'];

            if (StripeEventLedger::isComplete($entries, $trigger)) {
                return $page;
            }
        }

        try {
            // Do not hold a filesystem write lock across provider requests.
            // If commerce facts changed during the read, re-fetch rather than commit an older graph over the newer order.
            for ($read = 0; $read < 3; $read++) {
                $page = $this->orders->order($pageUuid) ?? throw new OrderDataException();
                $baseline = $this->orders->data($page);
                $observation = $this->retrieve($baseline, $sessionId);

                if ($trigger?->type === Event::PAYMENT_INTENT_REQUIRES_ACTION && $trigger->resourceId !== $observation->payment()->stripePaymentIntentId()) {
                    throw new OrderDataException();
                }

                try {
                    return $this->orders->update(
                        uuid: $pageUuid,
                        reduce: function (array $data) use ($baseline, $observation, $trigger): array {
                            /** @var list<array<string, mixed>> $entries */
                            $entries = $data['events'] ?? [];

                            if ($trigger !== null && StripeEventLedger::isComplete($entries, $trigger)) {
                                return $data;
                            }

                            if ($this->commerceHash($baseline) !== $this->commerceHash($data)) {
                                throw new ReconciliationConflictException();
                            }

                            $after = $this->reducer->reduce($data, $observation, $trigger, new DateTimeImmutable());

                            if ($trigger !== null) {
                                $after['events'] = StripeEventLedger::outcome($entries, $trigger);
                            }

                            return $after;
                        },
                        events: $this->reducer->events(...),
                        triggerType: $trigger?->type,
                        triggerId: $trigger?->id,
                    );
                } catch (ReconciliationConflictException) {
                    // A new complete provider read follows the fresh local state; no mutation is replayed.
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

        $this->orders->update($pageUuid, static function (array $data) use ($trigger, $error): array {
            /** @var list<array<string, mixed>> $entries */
            $entries = $data['events'] ?? [];
            $data['events'] = StripeEventLedger::outcome($entries, $trigger, $error->errorCode());

            return $data;
        });
    }
}
