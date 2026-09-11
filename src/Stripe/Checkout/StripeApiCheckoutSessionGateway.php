<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Exception\CheckoutSessionGatewayException;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Throwable;

/** Adapts Checkout Session creation through the pinned Stripe API client. */
final class StripeApiCheckoutSessionGateway implements CheckoutSessionGatewayInterface
{
    public function __construct(private readonly StripeClient $client) {}

    public function create(
        SessionRequest $request,
        string $idempotencyKey,
    ): CheckoutSessionRecord {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('A Checkout Session idempotency key is required.');
        }

        try {
            $session = $this->client->checkout->sessions->create(
                // SessionRequest deliberately supports the SDK's complete scalar/map
                // vocabulary, which is wider than its generated array shape.
                // @phpstan-ignore-next-line argument.type
                $request->parameters(),
                ['idempotency_key' => $idempotencyKey],
            );
        } catch (Throwable $error) {
            throw new CheckoutSessionGatewayException($error);
        }

        $metadata = $session->metadata;
        $lastResponse = $session->getLastResponse();
        $metadata = $metadata instanceof StripeObject ? $metadata->toArray() : [];

        /** @var array<string, mixed> $metadata */

        return new CheckoutSessionRecord(
            id: $this->nullableString($session->id),
            createdAt: $this->nullableInteger($session->created),
            expiresAt: $this->nullableInteger($session->expires_at),
            status: $this->nullableString($session->status),
            paymentStatus: $this->nullableString($session->payment_status),
            liveMode: $this->nullableBoolean($session->livemode),
            mode: $this->nullableString($session->mode),
            uiMode: $this->nullableString($session->ui_mode),
            currency: $this->nullableString($session->currency),
            clientReferenceId: $this->nullableString($session->client_reference_id),
            integrationIdentifier: $this->nullableString($session->integration_identifier),
            metadata: $metadata,
            requestId: $this->nullableString($lastResponse?->headers['request-id'] ?? null),
            url: $this->nullableString($session->url),
            clientSecret: $this->nullableString($session->client_secret),
        );
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
