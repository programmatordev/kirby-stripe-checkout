<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Lifecycle\Internal\HookDeliveryLedger;
use ProgrammatorDev\StripeCheckout\Lifecycle\Internal\LifecycleNotification;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Payment;
use ProgrammatorDev\StripeCheckout\Order\PaymentAction;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionObservation;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;

/** @internal Monotonic checkout/payment reduction of one complete observation, never a provider Event's state. */
final class CheckoutSessionReducer
{
    /**
     * The store owns updatedAt after canonical normalization; $now timestamps newly observed lifecycle facts only.
     *
     * @param array<string, mixed> $data Fresh locked order content.
     * @return array<string, mixed> Canonical persistence projection, committed by the existing store.
     */
    public function reduce(array $data, CheckoutSessionObservation $observation, DateTimeImmutable $now): array
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

        $payment = Payment::fromSnapshot(
            snapshot: $observation->payment(),
            status: $paymentStatus,
            currency: OrderData::string($data['currency']),
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

        return $after;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<LifecycleNotification>
     */
    public function notifications(array $before, array $after, ?PaymentAction $nextAction = null): array
    {
        $notifications = [];

        if (isset($before['stripeCheckoutSessionId']) === false && isset($after['stripeCheckoutSessionId'])) {
            $notifications[] = new LifecycleNotification(LifecycleEventType::SessionCreated);
        }

        if ($before['paymentStatus'] !== $after['paymentStatus']) {
            $type = match (PaymentStatus::from(OrderData::string($after['paymentStatus']))) {
                PaymentStatus::Pending => LifecycleEventType::PaymentPending,
                PaymentStatus::Paid, PaymentStatus::NoPaymentRequired => LifecycleEventType::PaymentSucceeded,
                PaymentStatus::Failed => LifecycleEventType::PaymentFailed,
                PaymentStatus::Unpaid => null,
            };

            if ($type !== null) {
                $notifications[] = new LifecycleNotification($type);
            }
        }

        /** @var list<array<string, mixed>> $deliveries */
        $deliveries = $before['lifecycleDeliveries'] ?? [];

        // Requires-action can precede Checkout completion; it is not a pending-payment transition.
        // Event IDs identify duplicates, not creation timestamps (which can tie or arrive out of order).
        // https://docs.stripe.com/webhooks#event-ordering
        if (
            $nextAction !== null
            && in_array($after['paymentStatus'], [PaymentStatus::Unpaid->value, PaymentStatus::Pending->value], true)
            && $after['checkoutStatus'] !== CheckoutStatus::Expired->value
            && HookDeliveryLedger::hasAction($deliveries, $nextAction) === false
        ) {
            $notifications[] = new LifecycleNotification(LifecycleEventType::PaymentRequiresAction, $nextAction);
        }

        if ($before['checkoutStatus'] !== $after['checkoutStatus'] && $after['checkoutStatus'] === CheckoutStatus::Expired->value) {
            $notifications[] = new LifecycleNotification(LifecycleEventType::CheckoutExpired);
        }

        return $notifications;
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
