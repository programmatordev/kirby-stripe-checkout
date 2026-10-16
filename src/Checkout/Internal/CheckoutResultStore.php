<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Kirby\Session\Session;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutResultReason;
use Throwable;

/** @internal Bounded native-session storage for one-navigation Checkout results. */
final class CheckoutResultStore
{
    public const KEY = 'programmatordev.stripe-checkout.results';

    private const SCHEMA_VERSION = 1;

    private const MAX_RESULTS = 100;

    // This bounds one-navigation presentation state independently of Order and lifecycle retention.
    private const RETENTION = 'PT15M';

    public function __construct(private readonly Session $session) {}

    public function save(
        CheckoutResultReason $reason,
        BrowserAttemptContext $context,
        ?string $pageUuid,
        string $destinationUrl,
        DateTimeImmutable $savedAt,
        bool $canResumeCheckout = false,
    ): string {
        return $this->locked(function () use ($reason, $context, $pageUuid, $destinationUrl, $savedAt, $canResumeCheckout): string {
            $results = $this->current($savedAt->getTimestamp());

            if (count($results) >= self::MAX_RESULTS) {
                unset($results[array_key_first($results)]);
            }

            do {
                $reference = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $hash = hash('sha256', $reference);
            } while (array_key_exists($hash, $results));

            $results[$hash] = new CheckoutResultState(
                reason: $reason,
                pageUuid: $pageUuid,
                userUuid: $context->userUuid(),
                guestReference: $context->guestReference(),
                destinationUrl: $destinationUrl,
                canResumeCheckout: $canResumeCheckout,
                expiresAt: $savedAt->add(new DateInterval(self::RETENTION))->getTimestamp(),
            );
            $this->write($results);

            return $reference;
        });
    }

    public function consume(
        string $reference,
        ?string $userUuid,
        ?string $guestReference,
        string $destinationUrl,
        DateTimeImmutable $checkedAt,
    ): ?CheckoutResultState {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $reference) !== 1) {
            return null;
        }

        $hash = hash('sha256', $reference);

        return $this->locked(function () use ($hash, $userUuid, $guestReference, $destinationUrl, $checkedAt): ?CheckoutResultState {
            $results = $this->current($checkedAt->getTimestamp());
            $result = $results[$hash] ?? null;

            if ($result === null) {
                return null;
            }

            if ($result->matches($userUuid, $guestReference, $destinationUrl) === false) {
                // A mismatched reader must not consume the result intended for its original browser and destination.
                return null;
            }

            unset($results[$hash]);
            $this->write($results);

            return $result;
        });
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    private function locked(Closure $operation): mixed
    {
        $this->session->ensureToken();
        $this->session->prepareForWriting();

        try {
            return $operation();
        } finally {
            $this->session->commit();
        }
    }

    /** @return array<string, CheckoutResultState> */
    private function current(int $checkedAt): array
    {
        $payload = $this->session->data()->get(self::KEY);
        $results = [];

        if (is_array($payload) && ($payload['schema'] ?? null) === self::SCHEMA_VERSION && is_array($payload['results'] ?? null)) {
            foreach ($payload['results'] as $hash => $resultData) {
                if (
                    is_string($hash) === false
                    || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1
                    || is_array($resultData) === false
                ) {
                    continue;
                }

                try {
                    $result = CheckoutResultState::fromArray($resultData);
                } catch (Throwable) {
                    continue;
                }

                if ($checkedAt >= $result->expiresAt()) {
                    continue;
                }

                $results[$hash] = $result;
            }
        }

        $this->write($results);

        return $results;
    }

    /** @param array<string, CheckoutResultState> $results */
    private function write(array $results): void
    {
        $this->session->data()->set(self::KEY, [
            'schema' => self::SCHEMA_VERSION,
            'results' => array_map(static fn(CheckoutResultState $result): array => $result->toArray(), $results),
        ]);
    }
}
