<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order;

use JsonException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use Stripe\PaymentIntent;
use Stripe\StripeObject;

/**
 * The active Stripe next_action branch, without a payment-method schema.
 * Details may contain private authentication or handoff data; retained copies are bounded replay evidence, not permanent order facts.
 */
final readonly class PaymentAction
{
    private const MAX_JSON_DEPTH = 32;

    /** @param array<array-key, mixed> $details */
    private function __construct(
        private string $type,
        private array $details,
    ) {}

    /** @param array<array-key, mixed>|null $action Selected provider data, not a complete PaymentIntent. */
    public static function fromArray(?array $action): ?self
    {
        if ($action === null) {
            return null;
        }

        $type = OrderData::text($action['type'] ?? null, 255);
        $details = $action[$type] ?? null;

        // The action type selects its provider branch, not a payment-method schema.
        // SDK toArray() represents empty objects as []; an empty branch is therefore different from a missing branch.
        // https://docs.stripe.com/api/payment_intents/object#payment_intent_object-next_action
        if (is_array($details) === false || ($details !== [] && array_is_list($details))) {
            throw new OrderDataException();
        }

        // Keep only the active branch and detach references without interpreting provider field names or rejecting JSON floats.
        return new self($type, self::normalizeDetails($details));
    }

    /** Restores opaque provider JSON without applying the order schema's restrictions on monetary values. */
    public static function fromJson(string $json): self
    {
        try {
            $action = json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);

            if (is_array($action) === false) {
                throw new OrderDataException();
            }

            return self::fromArray($action) ?? throw new OrderDataException();
        } catch (JsonException) {
            throw new OrderDataException();
        }
    }

    public function type(): string
    {
        return $this->type;
    }

    /**
     * SDK objects are mutable: each access creates a fresh object so callers cannot edit the captured action through its nested details.
     */
    public function details(): StripeObject
    {
        return StripeObject::constructFrom($this->details);
    }

    /** @return array<string, mixed> Private provider data with original names and nesting. */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            $this->type => $this->details,
        ];
    }

    /** Opaque storage at the provider boundary; never relax the order schema to admit arbitrary provider values. */
    public function toJson(): string
    {
        try {
            return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION, self::MAX_JSON_DEPTH);
        } catch (JsonException) {
            throw new OrderDataException();
        }
    }

    /**
     * A fresh partial SDK read projection for contextual next_action PHPDoc.
     * It is not a complete/current PaymentIntent and must not be used for provider writes.
     */
    public function toPaymentIntent(): PaymentIntent
    {
        return PaymentIntent::constructFrom(['next_action' => $this->toArray()]);
    }

    /** @param array<array-key, mixed> $details
     * @return array<array-key, mixed>
     */
    private static function normalizeDetails(array $details, int $depth = 2): array
    {
        // Count the envelope and active branch; decoding requires a limit greater than the deepest container.
        if ($depth >= self::MAX_JSON_DEPTH) {
            throw new OrderDataException();
        }

        $result = [];

        foreach ($details as $key => $value) {
            if (is_array($value)) {
                $value = self::normalizeDetails($value, $depth + 1);
            } elseif ($value !== null && is_scalar($value) === false) {
                // Reject PHP objects before serialization can invoke user code; Stripe SDK toArray() already produces scalar JSON data.
                throw new OrderDataException();
            }

            $result[$key] = $value;
        }

        if (array_is_list($details) === false) {
            // JSON object key order must not turn identical provider evidence into a different notification.
            // Lists preserve their provider order.
            ksort($result, SORT_STRING);

            if (array_is_list($result)) {
                // Sorting numeric map keys into list order would change their JSON kind and collide with list evidence.
                // Reverse order keeps the map distinguishable and deterministic without introducing PHP objects.
                krsort($result, SORT_STRING);
            }
        }

        return $result;
    }
}
