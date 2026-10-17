<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use Closure;
use DateTimeImmutable;
use Kirby\Session\Session;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use Throwable;

/**
 * Native browser authorization with atomic first binding, independent of Order persistence.
 *
 * @internal
 */
final class BrowserAttemptStore
{
    public const KEY = 'programmatordev.stripe-checkout.attempts';

    private const SCHEMA_VERSION = 1;

    private const MAX_ATTEMPTS = 100;

    private const RETENTION_SECONDS = 86400;

    public function __construct(private readonly Session $session) {}

    public function issue(
        BrowserAttemptContext $context,
        string $initiatingUrl,
        DateTimeImmutable $issuedAt,
    ): BrowserAttempt {
        return $this->locked(function () use ($context, $initiatingUrl, $issuedAt): BrowserAttempt {
            $attempts = $this->current($issuedAt->getTimestamp());

            // Retire the oldest unused form when rendering many actions.
            // Never evict an accepted purchase merely to issue another action.
            if (count($attempts) >= self::MAX_ATTEMPTS) {
                foreach ($attempts as $hash => $data) {
                    if (($data['boundAt'] ?? null) === null) {
                        unset($attempts[$hash]);
                        break;
                    }
                }
            }

            if (count($attempts) >= self::MAX_ATTEMPTS) {
                throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_LIMIT_REACHED);
            }

            $attempt = new BrowserAttempt(
                token: AttemptToken::generate(),
                context: $context,
                initiatingUrl: $initiatingUrl,
                issuedAt: $issuedAt->getTimestamp(),
            );
            $attempts[$attempt->token()->hash()] = $attempt->toArray();
            $this->write($attempts);

            return $attempt;
        });
    }

    public function load(
        AttemptToken $token,
        BrowserAttemptContext $context,
        DateTimeImmutable $checkedAt,
    ): BrowserAttempt {
        return $this->access($token, $context, $checkedAt);
    }

    public function bind(
        AttemptToken $token,
        BrowserAttemptContext $context,
        AttemptBinding $binding,
        CheckoutSubmissionInput $acceptedInput,
        DateTimeImmutable $boundAt,
    ): BrowserAttempt {
        return $this->access($token, $context, $boundAt, $binding, $acceptedInput);
    }

    public static function retainedUntil(BrowserAttempt $attempt): int
    {
        return ($attempt->boundAt() ?? $attempt->issuedAt()) + self::RETENTION_SECONDS;
    }

    public function findBoundContextForOrder(
        string $pageUuid,
        string $tokenHash,
        string $bindingFingerprint,
        ?string $userUuid,
        ?string $guestReference,
        DateTimeImmutable $checkedAt,
    ): ?BrowserAttemptContext {
        try {
            OrderData::uuid($pageUuid);
        } catch (Throwable) {
            return null;
        }

        return $this->locked(function () use ($pageUuid, $tokenHash, $bindingFingerprint, $userUuid, $guestReference, $checkedAt): ?BrowserAttemptContext {
            foreach ($this->current($checkedAt->getTimestamp()) as $hash => $data) {
                if (($data['pageUuid'] ?? null) !== $pageUuid) {
                    continue;
                }

                $storedBindingFingerprint = $data['bindingFingerprint'] ?? null;

                if (
                    hash_equals($tokenHash, $hash) === false
                    || is_string($storedBindingFingerprint) === false
                    || hash_equals($bindingFingerprint, $storedBindingFingerprint) === false
                ) {
                    return null;
                }

                try {
                    $context = BrowserAttempt::boundContextFromArray($pageUuid, $data);
                } catch (Throwable) {
                    return null;
                }

                return $context->matchesActor($userUuid, $guestReference) ? $context : null;
            }

            return null;
        });
    }

    public function findAcceptedAttemptForOrder(
        string $pageUuid,
        BrowserAttemptContext $context,
        DateTimeImmutable $checkedAt,
    ): ?BrowserAttempt {
        try {
            OrderData::uuid($pageUuid);
        } catch (Throwable) {
            return null;
        }

        return $this->locked(function () use ($pageUuid, $context, $checkedAt): ?BrowserAttempt {
            foreach ($this->current($checkedAt->getTimestamp()) as $hash => $data) {
                if (($data['pageUuid'] ?? null) !== $pageUuid) {
                    continue;
                }

                try {
                    $acceptedInput = CheckoutSubmissionInput::fromSessionData($data['acceptedInput'] ?? null);
                    $attempt = BrowserAttempt::fromArray($acceptedInput->attemptToken(), $data);
                    $attempt->context()->assertMatches($context);
                } catch (Throwable) {
                    return null;
                }

                if (hash_equals($hash, $attempt->token()->hash()) === false) {
                    return null;
                }

                return $attempt;
            }

            return null;
        });
    }

    private function access(
        AttemptToken $token,
        BrowserAttemptContext $context,
        DateTimeImmutable $checkedAt,
        ?AttemptBinding $binding = null,
        ?CheckoutSubmissionInput $acceptedInput = null,
    ): BrowserAttempt {
        return $this->locked(function () use ($token, $context, $checkedAt, $binding, $acceptedInput): BrowserAttempt {
            $attempts = $this->current($checkedAt->getTimestamp());
            $attempt = BrowserAttempt::fromArray($token, $attempts[$token->hash()] ?? null);
            $attempt->context()->assertMatches($context);

            if ($binding !== null) {
                if ($acceptedInput === null) {
                    throw new \LogicException('Binding a browser attempt requires accepted input.');
                }

                $attempt = $attempt->bind($binding, $acceptedInput, $checkedAt->getTimestamp());
                $attempts[$token->hash()] = $attempt->toArray();
                $this->write($attempts);
            }

            return $attempt;
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
        // As with the cart, Session::set() alone locks after the comparison.
        // Reload under Kirby's native lock; commit before any commerce work.
        $this->session->prepareForWriting();

        try {
            return $operation();
        } finally {
            $this->session->commit();
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function current(int $checkedAt): array
    {
        $payload = $this->session->data()->get(self::KEY);
        $attempts = [];

        if (is_array($payload) && ($payload['schema'] ?? null) === self::SCHEMA_VERSION && is_array($payload['attempts'] ?? null)) {
            foreach ($payload['attempts'] as $hash => $data) {
                if (is_string($hash) === false || is_array($data) === false) {
                    continue;
                }

                $issuedAt = $data['issuedAt'] ?? null;
                $boundAt = $data['boundAt'] ?? null;

                if (is_int($issuedAt) === false || $issuedAt < 0) {
                    continue;
                }

                if ($boundAt !== null && (is_int($boundAt) === false || $boundAt < $issuedAt)) {
                    continue;
                }

                // Retain accepted action state from first binding rather than form rendering.
                // This browser retention is independent of the creator's retry deadline and native session expiry;
                // duplicates cannot renew it because the first binding time is immutable.
                // Another request may commit after this request captured checkedAt but before it acquired the lock.
                // A newer stored timestamp is not corruption and must not cause pruning.
                if ($checkedAt - ($boundAt ?? $issuedAt) >= self::RETENTION_SECONDS) {
                    continue;
                }

                /** @var array<string, mixed> $data */
                $attempts[$hash] = $data;
            }
        }

        // Pruning affects only this plugin key, including when lookup is rejected.
        $this->write($attempts);

        return $attempts;
    }

    /** @param array<string, array<string, mixed>> $attempts */
    private function write(array $attempts): void
    {
        $this->session->data()->set(self::KEY, [
            'schema' => self::SCHEMA_VERSION,
            'attempts' => $attempts,
        ]);
    }
}
