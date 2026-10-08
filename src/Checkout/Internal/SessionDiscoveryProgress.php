<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionDiscoveryPage;

/**
 * Immutable continuation for one order's search under one credential and creation window.
 * The candidate remains provisional until complete enumeration and purchase reconciliation.
 * Completion describes the listing only; an empty search does not prove creation failed.
 *
 * @internal
 */
final readonly class SessionDiscoveryProgress
{
    public function __construct(
        public string $pageUuid,
        public string $credentialFingerprint,
        public int $createdFrom,
        public int $createdBefore,
        public ?string $startingAfter = null,
        public ?string $candidateSessionId = null,
        public bool $complete = false,
    ) {}

    public function advance(CheckoutSessionDiscoveryPage $page): self
    {
        $candidateSessionId = $this->candidateSessionId;

        foreach ($page->sessionIds as $sessionId) {
            if ($candidateSessionId !== null && $candidateSessionId !== $sessionId) {
                // Ownership metadata alone cannot choose between two Sessions for the same attempt.
                throw new CheckoutSessionException(CheckoutErrorCode::SESSION_INCOMPATIBLE);
            }

            $candidateSessionId = $sessionId;
        }

        return new self(
            pageUuid: $this->pageUuid,
            credentialFingerprint: $this->credentialFingerprint,
            createdFrom: $this->createdFrom,
            createdBefore: $this->createdBefore,
            startingAfter: $page->nextCursor,
            candidateSessionId: $candidateSessionId,
            complete: $page->nextCursor === null,
        );
    }
}
