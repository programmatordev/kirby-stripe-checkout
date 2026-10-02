<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateInterval;
use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Configuration\Defaults;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Payment;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionObservation;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\PaymentIntent;

/** @internal Monotonic checkout/payment reduction of one complete observation, never a provider Event's state. */
final class CheckoutSessionReducer
{
    public function __construct(private readonly int $lifecycleDeliveryRetentionDays = Defaults::LIFECYCLE_DELIVERY_RETENTION_DAYS) {}

    /**
     * @param array<string, mixed> $data Fresh locked order content.
     * @return array<string, mixed> Canonical persistence projection, committed by the existing store.
     */
    public function reduce(array $data, CheckoutSessionObservation $observation, ?ReconciliationEvent $event, DateTimeImmutable $now): array
    {
        $checkoutStatus = match ($observation->status()) {
            Session::STATUS_OPEN => CheckoutStatus::Open,
            Session::STATUS_COMPLETE => CheckoutStatus::Complete,
            Session::STATUS_EXPIRED => CheckoutStatus::Expired,
            default => throw new OrderDataException(),
        };
        $paymentStatus = $this->paymentStatus($observation, $checkoutStatus);
        $previousStatus = PaymentStatus::from(OrderData::string($data['paymentStatus']));
        $previousCheckout = CheckoutStatus::from(OrderData::string($data['checkoutStatus']));

        if (
            $previousCheckout === CheckoutStatus::Complete && $checkoutStatus !== CheckoutStatus::Complete
            || in_array($previousStatus, [PaymentStatus::Paid, PaymentStatus::NoPaymentRequired], true) && $paymentStatus !== $previousStatus
            || $previousStatus === PaymentStatus::Failed && $paymentStatus === PaymentStatus::Pending
        ) {
            // A stale/out-of-order trigger cannot erase a committed terminal observation or replace its capability snapshots.
            return $data;
        }

        if ($previousCheckout === CheckoutStatus::Expired && $checkoutStatus !== CheckoutStatus::Expired) {
            throw new OrderDataException();
        }

        $previousPayment = isset($data['payment']) ? Payment::fromArray(OrderData::map($data['payment'])) : null;
        // Current next_action can disappear before the associated hook is delivered.
        // Retain captured evidence until a replacement, terminal payment or local expiry removes it.
        $nextAction = $previousPayment?->nextAction();
        $nextActionObservedAt = $previousPayment?->nextActionObservedAt();
        $nextActionExpiresAt = $previousPayment?->nextActionExpiresAt();
        $capturedAction = $observation->payment()->nextAction();
        $capturedActionObservedAt = $now->getTimestamp();

        if ($event?->type === Event::PAYMENT_INTENT_REQUIRES_ACTION) {
            // Only the action uses historical Event facts. Payment status still comes from the current, correlated Session read above.
            $capturedAction = $event->nextAction;
            $capturedActionObservedAt = $event->createdAt;
        }

        if (
            $capturedAction !== null && ($nextActionObservedAt === null || $capturedActionObservedAt >= $nextActionObservedAt)
            && ($capturedAction->toJson() !== $nextAction?->toJson() || $event?->type === Event::PAYMENT_INTENT_REQUIRES_ACTION)
        ) {
            if ($capturedAction->toJson() !== $nextAction?->toJson()) {
                // Capture private replay evidence in the existing payment slot, including before Checkout completes.
                // Only a different action starts a new window; observing the same action never renews its deadline.
                $nextActionExpiresAt = OrderData::date(OrderData::timestamp($now))->add(new DateInterval('P' . $this->lifecycleDeliveryRetentionDays . 'D'));
            }

            $nextAction = $capturedAction;
            // An identical newer Event advances the historical watermark without another notification or a renewed deadline.
            $nextActionObservedAt = $capturedActionObservedAt;
        }

        if (
            $checkoutStatus === CheckoutStatus::Expired
            || in_array($paymentStatus, [PaymentStatus::Unpaid, PaymentStatus::Pending], true) === false
            || $nextActionExpiresAt !== null && $nextActionExpiresAt <= $now
        ) {
            // Terminal state no longer needs a live action. Earlier delivery snapshots retain their own frozen replay evidence.
            // Logical expiry does not depend on a housekeeping pass having removed the stored bytes.
            $nextAction = null;
            $nextActionObservedAt = null;
            $nextActionExpiresAt = null;
        }

        $payment = Payment::fromSnapshot(
            snapshot: $observation->payment(),
            status: $paymentStatus,
            currency: OrderData::string($data['currency']),
            nextAction: $nextAction,
            nextActionObservedAt: $nextActionObservedAt,
            nextActionExpiresAt: $nextActionExpiresAt,
        );
        $after = [
            ...$data,
            ...$observation->association()->toOrderData(),
            'checkoutStatus' => $checkoutStatus->value,
            'paymentStatus' => $paymentStatus->value,
            'stripePaymentIntentId' => $payment->stripePaymentIntentId(),
            'stripeChargeId' => $payment->stripeChargeId(),
            'stripeInvoiceId' => $observation->stripeInvoiceId(),
            'payment' => $payment->toArray(),
            'lineItems' => array_map(static fn(CheckoutLineItemSnapshot $line): array => $line->toArray(), $observation->lineItems()),
        ];
        $timestamp = OrderData::timestamp($now);
        $stateTimestamp = match ($checkoutStatus) {
            CheckoutStatus::Open => 'checkoutOpenedAt',
            CheckoutStatus::Complete => 'checkoutCompletedAt',
            CheckoutStatus::Expired => 'checkoutExpiredAt',
        };
        $after[$stateTimestamp] ??= $timestamp;

        if ($checkoutStatus === CheckoutStatus::Complete) {
            // Replace all capability fields in one projection, including authoritative absences.
            $after = [
                ...$after,
                ...$observation->snapshot()->toArray(),
                'subtotal' => (string) $observation->subtotal()->getAmount(),
                'total' => (string) $observation->total()->getAmount(),
            ];

            if (in_array($paymentStatus, [PaymentStatus::Paid, PaymentStatus::NoPaymentRequired], true)) {
                $after['paidAt'] ??= $timestamp;
            } elseif ($paymentStatus === PaymentStatus::Failed) {
                $after['paymentFailedAt'] ??= $timestamp;
            }
        }

        // Bookkeeping alone does not create a fresh commerce timestamp or transition.
        if (OrderData::normalize($after) !== OrderData::normalize($data)) {
            $after['updatedAt'] = max(OrderData::string($data['updatedAt']), $timestamp);
        }

        return $after;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<LifecycleEventType>
     */
    public function events(array $before, array $after): array
    {
        $events = [];

        if (isset($before['stripeCheckoutSessionId']) === false && isset($after['stripeCheckoutSessionId'])) {
            $events[] = LifecycleEventType::SessionCreated;
        }

        if ($before['paymentStatus'] !== $after['paymentStatus']) {
            $type = match (PaymentStatus::from(OrderData::string($after['paymentStatus']))) {
                PaymentStatus::Pending => LifecycleEventType::PaymentPending,
                PaymentStatus::Paid, PaymentStatus::NoPaymentRequired => LifecycleEventType::PaymentSucceeded,
                PaymentStatus::Failed => LifecycleEventType::PaymentFailed,
                PaymentStatus::Unpaid => null,
            };

            if ($type !== null) {
                $events[] = $type;
            }
        }

        $beforeAction = isset($before['payment']) ? Payment::fromArray(OrderData::map($before['payment']))->nextAction()?->toJson() : null;
        $afterAction = isset($after['payment']) ? Payment::fromArray(OrderData::map($after['payment']))->nextAction()?->toJson() : null;

        // An early action is saved without notifying yet; entering pending payment makes that same evidence eligible for its first hook.
        if (
            $after['paymentStatus'] === PaymentStatus::Pending->value && $afterAction !== null
            && ($before['paymentStatus'] !== PaymentStatus::Pending->value || $beforeAction !== $afterAction)
        ) {
            $events[] = LifecycleEventType::PaymentRequiresAction;
        }

        if ($before['checkoutStatus'] !== $after['checkoutStatus'] && $after['checkoutStatus'] === CheckoutStatus::Expired->value) {
            $events[] = LifecycleEventType::CheckoutExpired;
        }

        return $events;
    }

    private function paymentStatus(CheckoutSessionObservation $observation, CheckoutStatus $checkoutStatus): PaymentStatus
    {
        if ($checkoutStatus !== CheckoutStatus::Complete) {
            return PaymentStatus::Unpaid;
        }

        // The Session is authoritative for paid/no-cost outcomes; a Charge may belong to an earlier failed attempt.
        // https://docs.stripe.com/payments/checkout/fulfill-orders
        return match ($observation->paymentStatus()) {
            Session::PAYMENT_STATUS_PAID => PaymentStatus::Paid,
            Session::PAYMENT_STATUS_NO_PAYMENT_REQUIRED => PaymentStatus::NoPaymentRequired,
            Session::PAYMENT_STATUS_UNPAID => in_array($observation->payment()->paymentIntentStatus(), [PaymentIntent::STATUS_CANCELED, PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD], true)
                ? PaymentStatus::Failed : PaymentStatus::Pending,
            default => throw new OrderDataException(),
        };
    }
}
