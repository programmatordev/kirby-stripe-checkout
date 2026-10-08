<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout;

/**
 * Matching Session references from one provider page, with its continuation cursor.
 * An empty matching list can still have a next cursor; unrelated Sessions also advance the search.
 *
 * @internal
 */
final readonly class CheckoutSessionDiscoveryPage
{
    /** @param list<string> $sessionIds */
    public function __construct(
        public array $sessionIds,
        public ?string $nextCursor,
    ) {}
}
