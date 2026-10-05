<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\DisputeCollection;
use ProgrammatorDev\StripeCheckout\Order\Internal\DisputeSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Plugin\PluginMetadata;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionObservation;
use ProgrammatorDev\StripeCheckout\Stripe\Dispute\DisputeGatewayInterface;
use Stripe\Charge;
use Stripe\Dispute;
use Stripe\Event;
use Stripe\PaymentIntent;

/** @internal Establishes parent ownership and normalizes complete current dispute facts; never writes orders. */
final class DisputeRetriever
{
    public function __construct(private readonly DisputeGatewayInterface $gateway) {}

    public function correlate(Event $event, CredentialMode $mode): ?DisputeCorrelation
    {
        $trigger = ReconciliationEvent::disputeEnvelope($event, $mode);
        $data = $this->gateway->retrieve($trigger->resourceId);

        if (($data['object'] ?? null) !== Dispute::OBJECT_NAME || ($data['id'] ?? null) !== $trigger->resourceId) {
            throw new OrderDataException();
        }

        $chargeId = OrderData::nonEmptyString($data['charge'] ?? null);
        $paymentIntentId = $data['payment_intent'] ?? null;
        // Stripe resolves lookup IDs. Validate the returned identities and parent backlinks before trusting their facts.
        $charge = $this->gateway->retrieveCharge($chargeId);

        if ($paymentIntentId === null) {
            $paymentIntentId = $charge['payment_intent'] ?? null;
        }

        $paymentIntentId = OrderData::string($paymentIntentId);
        $paymentIntent = $this->gateway->retrievePaymentIntent($paymentIntentId);

        if (($paymentIntent['object'] ?? null) !== PaymentIntent::OBJECT_NAME || ($paymentIntent['id'] ?? null) !== $paymentIntentId) {
            throw new OrderDataException();
        }

        $metadata = OrderData::map($paymentIntent['metadata'] ?? []);

        // Dispute metadata cannot establish order ownership; use the current PaymentIntent rather than copied Charge metadata.
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

        $this->validateCharge(
            charge: $charge,
            chargeId: $chargeId,
            paymentIntentId: $paymentIntentId,
            currency: $currency,
            liveMode: $liveMode,
        );

        if (OrderData::boolean($data['livemode'] ?? null) !== $liveMode) {
            throw new OrderDataException();
        }

        $dispute = DisputeSnapshot::fromStripe($data, $paymentIntentId);

        if ($dispute->amount()->getCurrency()->getCurrencyCode() !== $currency) {
            throw new OrderDataException();
        }

        return new DisputeCorrelation(
            pageUuid: $pageUuid,
            dispute: $dispute,
            trigger: ReconciliationEvent::fromDispute($event, $dispute, $mode),
        );
    }

    public function retrieve(DisputeCorrelation $correlation, CheckoutSessionObservation $observation): DisputeCollection
    {
        $paymentIntentId = $correlation->dispute->stripePaymentIntentId();

        if ($observation->payment()->stripePaymentIntentId() !== $paymentIntentId) {
            throw new OrderDataException();
        }

        $paymentAmount = $observation->payment()->amount() ?? throw new OrderDataException();
        $currency = $paymentAmount->getCurrency()->getCurrencyCode();
        $validatedChargeIds = [];
        $disputes = [];
        $found = false;

        // Reuse the triggering Charge's proven parent within this operation.
        $validatedChargeIds[$correlation->dispute->stripeChargeId()] = true;

        foreach ($this->gateway->allForPaymentIntent($paymentIntentId) as $data) {
            $chargeId = OrderData::nonEmptyString($data['charge'] ?? null);
            $disputePaymentIntentId = $data['payment_intent'] ?? null;

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

            $disputePaymentIntentId ??= $paymentIntentId;

            if (OrderData::boolean($data['livemode'] ?? null) !== $observation->liveMode()) {
                throw new OrderDataException();
            }

            if ($disputePaymentIntentId !== $paymentIntentId) {
                throw new OrderDataException();
            }

            $dispute = DisputeSnapshot::fromStripe($data, $paymentIntentId);

            if ($dispute->stripeDisputeId() === $correlation->dispute->stripeDisputeId()) {
                // Current status, amount and evidence can change between reads; stable parent identity cannot.
                if ($dispute->stripeChargeId() !== $correlation->dispute->stripeChargeId()) {
                    throw new OrderDataException();
                }

                $found = true;
            }

            $disputes[] = $dispute;
        }

        if ($found === false) {
            throw new OrderDataException();
        }

        return DisputeCollection::fromSnapshots(
            disputes: $disputes,
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

        // Verify the disputed Charge's parent rather than requiring the latest Charge.
        // https://docs.stripe.com/api/disputes/object#dispute_object-charge
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
