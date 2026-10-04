<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\RefundCollection;
use ProgrammatorDev\StripeCheckout\Order\Internal\RefundSnapshot;
use ProgrammatorDev\StripeCheckout\Plugin\PluginMetadata;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionObservation;
use ProgrammatorDev\StripeCheckout\Stripe\Refund\RefundGatewayInterface;
use Stripe\Charge;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\Refund;

/** @internal Establishes parent ownership and normalizes complete current refund facts; never writes orders. */
final class RefundRetriever
{
    public function __construct(private readonly RefundGatewayInterface $gateway) {}

    public function correlate(Event $event, CredentialMode $mode): ?RefundCorrelation
    {
        $trigger = ReconciliationEvent::refundEnvelope($event, $mode);
        $data = $this->gateway->retrieve($trigger->resourceId);

        if (($data['object'] ?? null) !== Refund::OBJECT_NAME || ($data['id'] ?? null) !== $trigger->resourceId) {
            throw new OrderDataException();
        }

        $chargeId = $data['charge'] ?? null;
        $paymentIntentId = $data['payment_intent'] ?? null;
        // Stripe resolves lookup IDs. Validate the returned identities and parent backlinks before trusting their facts.
        $charge = $chargeId === null ? null : $this->gateway->retrieveCharge(OrderData::string($chargeId));

        if ($paymentIntentId === null) {
            $paymentIntentId = $charge['payment_intent'] ?? null;
        }

        $paymentIntentId = OrderData::string($paymentIntentId);
        $paymentIntent = $this->gateway->retrievePaymentIntent($paymentIntentId);

        if (($paymentIntent['object'] ?? null) !== PaymentIntent::OBJECT_NAME || ($paymentIntent['id'] ?? null) !== $paymentIntentId) {
            throw new OrderDataException();
        }

        $metadata = OrderData::map($paymentIntent['metadata'] ?? []);

        // Refund metadata is independent. Dashboard refunds are owned through the current PaymentIntent, not copied Charge metadata.
        // https://docs.stripe.com/metadata#copy-metadata-to-another-object
        if (($metadata[PluginMetadata::OWNER_KEY] ?? null) !== PluginMetadata::NAME) {
            return null;
        }

        $pageUuid = OrderData::uuid(OrderData::text($metadata[PluginMetadata::ORDER_KEY] ?? null));
        $currency = strtoupper(OrderData::string($paymentIntent['currency'] ?? null));
        $liveMode = OrderData::boolean($paymentIntent['livemode'] ?? null);

        if ($liveMode !== ($mode === CredentialMode::Live)) {
            throw new OrderDataException();
        }

        if ($charge !== null) {
            $this->validateCharge(
                charge: $charge,
                chargeId: OrderData::string($chargeId),
                paymentIntentId: $paymentIntentId,
                currency: $currency,
                liveMode: $liveMode,
            );
        }

        $refund = RefundSnapshot::fromStripe($data, $paymentIntentId);

        if ($refund->amount()->getCurrency()->getCurrencyCode() !== $currency) {
            throw new OrderDataException();
        }

        return new RefundCorrelation(
            pageUuid: $pageUuid,
            refund: $refund,
            trigger: ReconciliationEvent::fromRefund($event, $refund, $mode),
        );
    }

    public function retrieve(RefundCorrelation $correlation, CheckoutSessionObservation $observation): RefundCollection
    {
        $paymentIntentId = $correlation->refund->stripePaymentIntentId();

        if ($observation->payment()->stripePaymentIntentId() !== $paymentIntentId) {
            throw new OrderDataException();
        }

        $paymentAmount = $observation->payment()->amount() ?? throw new OrderDataException();
        $currency = $paymentAmount->getCurrency()->getCurrencyCode();
        $validatedChargeIds = [];
        $refunds = [];
        $found = false;

        if ($correlation->refund->stripeChargeId() !== null) {
            // Initial correlation already proved this Charge's parent; sibling refunds can reuse that proof within this operation.
            $validatedChargeIds[$correlation->refund->stripeChargeId()] = true;
        }

        foreach ($this->gateway->allForPaymentIntent($paymentIntentId) as $data) {
            $chargeId = $data['charge'] ?? null;
            $refundPaymentIntentId = $data['payment_intent'] ?? null;

            if ($chargeId !== null) {
                $chargeId = OrderData::string($chargeId);

                if (isset($validatedChargeIds[$chargeId]) === false) {
                    $this->validateCharge(
                        charge: $this->gateway->retrieveCharge($chargeId),
                        chargeId: $chargeId,
                        paymentIntentId: $paymentIntentId,
                        currency: $currency,
                        liveMode: $observation->liveMode(),
                    );
                    $validatedChargeIds[$chargeId] = true;
                }

                $refundPaymentIntentId ??= $paymentIntentId;
            }

            if ($refundPaymentIntentId !== $paymentIntentId) {
                throw new OrderDataException();
            }

            $refund = RefundSnapshot::fromStripe($data, $paymentIntentId);

            if ($refund->stripeRefundId() === $correlation->refund->stripeRefundId()) {
                // Status/reason can change between reads; immutable purchase identities cannot.
                if ($refund->stripeChargeId() !== $correlation->refund->stripeChargeId() || $refund->amount()->isEqualTo($correlation->refund->amount()) === false) {
                    throw new OrderDataException();
                }

                $found = true;
            }

            $refunds[] = $refund;
        }

        if ($found === false) {
            throw new OrderDataException();
        }

        return RefundCollection::fromSnapshots(
            refunds: $refunds,
            paymentIntentId: $paymentIntentId,
            paymentAmount: $paymentAmount,
        );
    }

    /** @param array<string, mixed> $charge */
    private function validateCharge(array $charge, string $chargeId, string $paymentIntentId, string $currency, bool $liveMode): void
    {
        if (($charge['object'] ?? null) !== Charge::OBJECT_NAME || ($charge['id'] ?? null) !== $chargeId) {
            throw new OrderDataException();
        }

        // A refund can refer to an earlier Charge, so verify its parent rather than requiring the latest Charge.
        // https://docs.stripe.com/api/refunds/object#refund_object-charge
        if (($charge['payment_intent'] ?? null) !== $paymentIntentId) {
            throw new OrderDataException();
        }

        if (strtoupper(OrderData::string($charge['currency'] ?? null)) !== $currency) {
            throw new OrderDataException();
        }

        if (($charge['livemode'] ?? null) !== $liveMode) {
            throw new OrderDataException();
        }
    }
}
