<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout;

/**
 * Carries an untrusted Stripe Checkout Session response across the SDK edge.
 *
 * @internal
 */
final readonly class CheckoutSessionRecord
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public ?string $id,
        public ?int $createdAt,
        public ?int $expiresAt,
        public ?string $status,
        public ?string $paymentStatus,
        public ?bool $liveMode,
        public ?string $mode,
        public ?string $uiMode,
        public ?string $currency,
        public ?string $clientReferenceId,
        public ?string $integrationIdentifier,
        public array $metadata,
        public ?string $requestId,
        public ?string $url,
        public ?string $clientSecret,
    ) {}
}
