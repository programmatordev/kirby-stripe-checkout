<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
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

    /** @param list<array<string, mixed>> $selection */
    private function __construct(
        private CheckoutSource $checkoutSource,
        private ?string $userUuid,
        private ?string $guestReference,
        string $contextFingerprint,
        ?string $cartId,
        ?string $cartRevision,
        array $selection,
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

        if (preg_match('/\A[a-f0-9]{64}\z/', $contextFingerprint) !== 1) {
            throw new InvalidArgumentException('The context fingerprint must be a SHA-256 digest.');
        }

        // Ordered tuples avoid delimiter ambiguity and preserve direct-item order.
        // The caller supplies the fingerprint of fully resolved Checkout context;
        // these selection-only values cannot represent prices or navigation yet.
        $this->fingerprint = hash('sha256', json_encode([
            $checkoutSource->value,
            $userUuid,
            $guestReference,
            $contextFingerprint,
            $cartId,
            $cartRevision,
            $selection,
        ], JSON_THROW_ON_ERROR));
    }

    public static function cart(
        CartSnapshot $cart,
        string $contextFingerprint,
        ?string $userUuid = null,
        ?string $guestReference = null,
    ): self {
        if ($cart->entries() === []) {
            throw new CheckoutInputException('selection.invalid');
        }

        // Cart identity/revision identify selection state. The separate request
        // context fingerprint must still cover current commerce facts, which can change
        // without a cart mutation (for example, a merchant changing a price).
        return new self(
            checkoutSource: CheckoutSource::Cart,
            userUuid: $userUuid,
            guestReference: $guestReference,
            contextFingerprint: $contextFingerprint,
            cartId: $cart->id(),
            cartRevision: $cart->revision(),
            selection: [],
        );
    }

    /** @param array<array-key, ProductRequest> $items Canonical output from ProductRequestNormalizer. */
    public static function direct(
        array $items,
        string $contextFingerprint,
        ?string $userUuid = null,
        ?string $guestReference = null,
    ): self {
        if (array_is_list($items) === false || $items === [] || count($items) > ProductRequestNormalizer::MAX_ENTRIES) {
            throw new CheckoutInputException('selection.invalid');
        }

        return new self(
            checkoutSource: CheckoutSource::Direct,
            userUuid: $userUuid,
            guestReference: $guestReference,
            contextFingerprint: $contextFingerprint,
            cartId: null,
            cartRevision: null,
            selection: array_map(ProductRequestData::toArray(...), $items),
        );
    }

    public function checkoutSource(): CheckoutSource
    {
        return $this->checkoutSource;
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

    public function assertMatchesFingerprint(string $fingerprint): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1 || hash_equals($fingerprint, $this->fingerprint) === false) {
            throw new CheckoutInputException('checkout.attempt_conflict');
        }
    }

    public function assertCompatible(OrderCreationContext $order, ?string $guestReference): void
    {
        if (
            $this->checkoutSource !== $order->checkoutSource()
            || $this->userUuid !== $order->userUuid()
            || $this->guestReference !== $guestReference
        ) {
            throw new CheckoutInputException('checkout.attempt_conflict');
        }
    }
}
