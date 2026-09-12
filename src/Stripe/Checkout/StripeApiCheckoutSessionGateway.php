<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Exception\CheckoutSessionGatewayException;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Internal\CheckoutSessionFailureClassifier;
use Stripe\Checkout\Session;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Throwable;

/** Adapts Checkout Session creation and retrieval through the pinned Stripe API client. */
final class StripeApiCheckoutSessionGateway implements CheckoutSessionGatewayInterface
{
    public function __construct(
        private readonly StripeClient $client,
        private readonly CheckoutSessionFailureClassifier $failures = new CheckoutSessionFailureClassifier(),
    ) {}

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
                // Supplying our persisted key lets both SDK retries and a later
                // PHP request address the same Stripe mutation.
                ['idempotency_key' => $idempotencyKey],
            );
        } catch (Throwable $error) {
            throw new CheckoutSessionGatewayException(
                failure: $this->failures->classify($error, mutation: true),
                error: $error,
            );
        }

        return $this->sessionRecord($session);
    }

    public function retrieve(string $sessionId): CheckoutSessionRecord
    {
        if (preg_match('/\Acs_[A-Za-z0-9_]+\z/', $sessionId) !== 1) {
            throw new InvalidArgumentException('A valid Checkout Session ID is required.');
        }

        try {
            $session = $this->client->checkout->sessions->retrieve($sessionId, []);
        } catch (Throwable $error) {
            throw new CheckoutSessionGatewayException(
                failure: $this->failures->classify($error, mutation: false),
                error: $error,
            );
        }

        return $this->sessionRecord($session);
    }

    private function sessionRecord(Session $session): CheckoutSessionRecord
    {
        $metadata = $session->metadata;
        $lastResponse = $session->getLastResponse();
        $metadata = $metadata instanceof StripeObject ? $metadata->toArray() : [];
        $sessionData = $session->toArray();
        $orderSnapshotSource = [];
        $snapshotFields = [
            'customer',
            'customer_details',
            'collected_information',
            'custom_fields',
            'consent',
            'total_details',
        ];

        // Keep only the provider fields owned by the accepted order snapshots.
        // The strict normalizer deliberately sees malformed nested values instead
        // of silently treating them as absent, while SDK objects stop at this edge.
        foreach ($snapshotFields as $field) {
            if (array_key_exists($field, $sessionData)) {
                $value = $sessionData[$field];

                // An expanded Customer can contain far more than the one stable
                // reference owned by the order. Keep only that ID at this edge.
                if ($field === 'customer' && is_array($value)) {
                    $value = ['id' => $value['id'] ?? null];
                }

                $orderSnapshotSource[$field] = $value;
            }
        }

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
            orderSnapshotSource: $orderSnapshotSource,
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
