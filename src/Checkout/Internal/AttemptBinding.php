<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/**
 * Compares the actor and canonical source/context bound to an already matched token.
 * Actor identity belongs to the attempt, not the cart: changing users preserves
 * selections but must not allow reuse of another actor's attempt binding.
 *
 * @internal No attempt lookup, persistence, or Session reuse happens here.
 */
final readonly class AttemptBinding
{
    private string $fingerprint;

    /** @param list<ProductRequest> $items */
    private function __construct(
        private CheckoutSource $source,
        ?string $userUuid,
        ?string $guestReference,
        string $requestFingerprint,
        ?string $cartId,
        ?string $cartRevision,
        array $items,
    ) {
        if (($userUuid === null) === ($guestReference === null)) {
            throw new InvalidArgumentException('An attempt requires exactly one actor.');
        }

        if ($userUuid !== null) {
            ProductData::reference($userUuid);

            if (str_starts_with($userUuid, 'user://') === false || strlen($userUuid) <= 7) {
                throw new InvalidArgumentException('An authenticated actor requires a Kirby User UUID.');
            }
        }

        if ($guestReference !== null) {
            ProductData::identifier($guestReference);
        }

        if (preg_match('/\A[a-f0-9]{64}\z/', $requestFingerprint) !== 1) {
            throw new InvalidArgumentException('The request fingerprint must be a SHA-256 digest.');
        }

        // Ordered tuples avoid delimiter ambiguity and preserve direct-item order.
        // The caller supplies the fingerprint of fully resolved Checkout context;
        // these selection-only values cannot represent prices or navigation yet.
        $this->fingerprint = hash('sha256', json_encode([
            $source->value,
            $userUuid,
            $guestReference,
            $requestFingerprint,
            $cartId,
            $cartRevision,
            array_map(SelectionData::toArray(...), $items),
        ], JSON_THROW_ON_ERROR));
    }

    public static function cart(
        CartSnapshot $cart,
        string $requestFingerprint,
        ?string $userUuid = null,
        ?string $guestReference = null,
    ): self {
        if ($cart->entries() === []) {
            throw new CheckoutInputException('selection.invalid');
        }

        // Cart identity/revision identify selection state. The separate request
        // fingerprint must still cover current commerce facts, which can change
        // without a cart mutation (for example, a merchant changing a price).
        return new self(CheckoutSource::Cart, $userUuid, $guestReference, $requestFingerprint, $cart->id(), $cart->revision(), []);
    }

    /** @param array<array-key, ProductRequest> $items Canonical output from SelectionCanonicalizer. */
    public static function direct(
        array $items,
        string $requestFingerprint,
        ?string $userUuid = null,
        ?string $guestReference = null,
    ): self {
        if (array_is_list($items) === false || $items === [] || count($items) > SelectionCanonicalizer::MAX_ENTRIES) {
            throw new CheckoutInputException('selection.invalid');
        }

        return new self(CheckoutSource::Direct, $userUuid, $guestReference, $requestFingerprint, null, null, $items);
    }

    public function source(): CheckoutSource
    {
        return $this->source;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function assertMatches(self $binding): void
    {
        if (hash_equals($this->fingerprint, $binding->fingerprint) === false) {
            throw new CheckoutInputException('checkout.attempt_conflict');
        }
    }
}
