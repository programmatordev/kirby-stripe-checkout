<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use Stripe\PaymentIntent;
use Stripe\StripeObject;

/**
 * @internal Transient provider action, not a durable customer-instruction snapshot.
 * Readable actions can contain authentication data; never copy the raw value
 * into persisted orders or replayable hook snapshots.
 */
final readonly class PaymentAction
{
    /** @param array<array-key, mixed> $details */
    private function __construct(
        private string $type,
        private array $details,
    ) {}

    /** @param array<array-key, mixed>|null $action Selected from an SDK response, not stored order content. */
    public static function fromArray(?array $action): ?self
    {
        if ($action === null) {
            return null;
        }

        $type = OrderData::text($action['type'] ?? null, 255);
        $details = $action[$type] ?? null;

        // The action type selects its provider branch, not a payment-method schema.
        // SDK toArray() represents empty objects as []; an empty branch is
        // therefore different from a missing branch.
        // https://docs.stripe.com/api/payment_intents/object#payment_intent_object-next_action
        if (is_array($details) === false || ($details !== [] && array_is_list($details))) {
            throw new OrderDataException();
        }

        // Keep the active branch only. Do not interpret provider details or apply
        // order-persistence scalar rules to transient SDK/authentication payloads.
        return new self($type, $details);
    }

    public function type(): string
    {
        return $this->type;
    }

    /**
     * SDK objects are mutable: each access creates a fresh object so callers
     * cannot edit the captured action through its nested details.
     */
    public function details(): StripeObject
    {
        return StripeObject::constructFrom($this->details);
    }

    /** @return array<string, mixed> Provider data for inspection, not a persistence-safe order snapshot. */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            $this->type => $this->details,
        ];
    }

    /**
     * A fresh partial SDK read projection for contextual next_action PHPDoc.
     * It is not a complete/current PaymentIntent and must not be used for provider writes.
     */
    public function toPaymentIntent(): PaymentIntent
    {
        return PaymentIntent::constructFrom(['next_action' => $this->toArray()]);
    }
}
