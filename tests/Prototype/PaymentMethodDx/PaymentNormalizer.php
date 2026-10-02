<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Prototype\PaymentMethodDx;

use Brick\Money\Money;
use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use Stripe\PaymentIntent;

/** Offline projection experiment only: retrieval, correlation and state reduction are later work. */
final class PaymentNormalizer
{
    // Canonical state is supplied by the caller.
    // A provider action alone does not distinguish unfinished Checkout authentication from a completed pending payment.
    public function normalize(PaymentStatus $status, Money $amount, ?PaymentIntent $paymentIntent): Payment
    {
        if ($paymentIntent === null) {
            return new Payment(status: $status, amount: $amount);
        }

        $data = $paymentIntent->toArray();
        $charge = $data['latest_charge'] ?? null;
        $chargeData = is_array($charge) ? $this->objectData($charge) : [];
        $details = $this->objectData($chargeData['payment_method_details'] ?? []);
        $method = $data['payment_method'] ?? null;
        $methodData = is_array($method) ? $this->objectData($method) : [];
        $nextAction = $data['next_action'] ?? null;
        $nextAction = $nextAction === null ? [] : $this->objectData($nextAction);
        $failure = $data['last_payment_error'] ?? null;
        $failure = $failure === null ? [] : $this->objectData($failure);
        $registry = new StripeCurrencyRegistry();

        // The configured method list is not evidence of the method actually chosen.
        // A Charge or an expanded PaymentMethod can provide that fact; otherwise it stays unknown.
        // https://docs.stripe.com/api/payment_intents/object#payment_intent_object-payment_method
        return new Payment(
            status: $status,
            amount: $amount,
            amountReceived: $registry->toMoney($registry->fromProviderAmount(
                OrderData::integer($data['amount_received'] ?? null),
                strtoupper(OrderData::text($data['currency'] ?? null)),
            )),
            methodType: OrderData::nullableString($details['type'] ?? $methodData['type'] ?? null),
            stripePaymentIntentId: OrderData::text($data['id'] ?? null),
            stripeChargeId: OrderData::nullableString(is_string($charge) ? $charge : ($chargeData['id'] ?? null)),
            paymentIntentStatus: OrderData::text($data['status'] ?? null),
            nextActionType: OrderData::nullableString($nextAction['type'] ?? null),
            failureCode: OrderData::nullableString($failure['code'] ?? null),
            instructions: $this->instructions($nextAction),
        );
    }

    /** @param array<string, mixed> $nextAction */
    private function instructions(array $nextAction): ?PaymentInstructions
    {
        $type = $nextAction['type'] ?? null;

        // next_action is keyed by its action type, not by the payment-method type.
        // This experiment maps only three instruction shapes;
        // it does not establish a production contract for other methods or for preserving Stripe-shaped data.
        // Authentication/redirect actions are not durable voucher instructions.
        // https://docs.stripe.com/api/payment_intents/object?query=next_action
        if (in_array($type, ['multibanco_display_details', 'oxxo_display_details', 'paynow_display_qr_code'], true) === false) {
            return null;
        }

        $details = $this->objectData($nextAction[$type] ?? []);
        $expiresAt = $details['expires_at'] ?? $details['expires_after'] ?? null;

        $instructions = new PaymentInstructions(
            hostedUrl: OrderData::nullableSingleLine($details['hosted_voucher_url'] ?? $details['hosted_instructions_url'] ?? null),
            reference: OrderData::nullableSingleLine($details['reference'] ?? $details['number'] ?? null),
            entity: OrderData::nullableSingleLine($details['entity'] ?? null),
            qrCodeImageUrl: OrderData::nullableSingleLine($details['image_url_png'] ?? null),
            // OXXO's expires_after is an absolute timestamp despite its name.
            // PayNow has no expiry field here; do not substitute Session expiry.
            expiresAt: $expiresAt === null ? null : new DateTimeImmutable('@' . OrderData::integer($expiresAt)),
        );

        return array_filter($instructions->toArray(), static fn(?string $value): bool => $value !== null) === []
            ? null
            : $instructions;
    }

    /** @return array<string, mixed> */
    private function objectData(mixed $value): array
    {
        if (is_array($value) === false) {
            throw new OrderDataException();
        }

        foreach (array_keys($value) as $key) {
            if (is_string($key) === false) {
                throw new OrderDataException();
            }
        }

        // Do not recursively validate fields we neither model nor persist.
        // Unknown provider subtrees may use values outside the Order content schema.
        /** @var array<string, mixed> $value */
        return $value;
    }
}
