<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

use Closure;
use InvalidArgumentException;
use Kirby\Session\Session;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SelectionData;
use Throwable;

/**
 * Stores only the plugin's selection payload in an ordinary Kirby session.
 *
 * @internal
 */
final class KirbySessionCartStore implements CartStoreInterface
{
    public const KEY = 'programmatordev.stripe-checkout.cart';

    /** @var Closure(string): void */
    private readonly Closure $diagnostic;

    /**
     * @param Closure(): string $newId The configured Kirby UUID generator.
     * @param (Closure(string): void)|null $diagnostic Receives safe codes, never payloads.
     */
    public function __construct(
        private readonly Session $session,
        private readonly Closure $newId,
        ?Closure $diagnostic = null,
    ) {
        $this->diagnostic = $diagnostic ?? static function (string $code): void {
            error_log('[Stripe Checkout] ' . $code);
        };
    }

    public function read(): CartSnapshot
    {
        // A read can initialize or repair the payload, so it needs the same
        // locked reload as a mutation rather than Kirby's cached session data.
        return $this->mutate(static fn(CartSnapshot $current): CartSnapshot => $current);
    }

    public function mutate(Closure $mutation): CartSnapshot
    {
        $this->session->ensureToken();

        // Session::set() locks too late for a read/compare/write operation.
        // Kirby's @unstable method acquires its native lock and reloads the data;
        // keep that dependency here and exercise it against the installed core.
        $this->session->prepareForWriting();

        try {
            $current = $this->current();
            $next = $mutation($current);

            if ($next !== $current) {
                $this->session->data()->set(self::KEY, $this->encode($next));
            }

            return $next;
        } finally {
            // Release the lock even on rejection. Selection changes require a
            // successful callback; empty-cart initialization/repair and unrelated
            // Kirby writes can still be committed.
            $this->session->commit();
        }
    }

    private function current(): CartSnapshot
    {
        $payload = $this->session->data()->get(self::KEY);

        if ($payload !== null) {
            try {
                return $this->decode($payload);
            } catch (Throwable) {
                // A diagnostic must neither reveal the damaged data nor prevent recovery.
                try {
                    ($this->diagnostic)('cart.session_reset');
                } catch (Throwable) {
                }
            }
        }

        $now = time();
        $empty = new CartSnapshot(($this->newId)(), bin2hex(random_bytes(16)), [], $now, $now);
        $this->session->data()->set(self::KEY, $this->encode($empty));

        return $empty;
    }

    private function decode(mixed $payload): CartSnapshot
    {
        if (
            is_array($payload) === false
            || count($payload) !== 7
            || ($payload['schema'] ?? null) !== 1
            || is_string($payload['id'] ?? null) === false
            || is_string($payload['revision'] ?? null) === false
            || is_int($payload['createdAt'] ?? null) === false
            || is_int($payload['updatedAt'] ?? null) === false
            || array_key_exists('destinationCountry', $payload) === false
            || ($payload['destinationCountry'] !== null && is_string($payload['destinationCountry']) === false)
            || is_array($payload['entries'] ?? null) === false
            || array_is_list($payload['entries']) === false
            || count($payload['entries']) > 100
        ) {
            throw new InvalidArgumentException('Invalid cart payload.');
        }

        $entries = [];
        foreach ($payload['entries'] as $entry) {
            if (is_array($entry) === false || count($entry) !== 2 || is_string($entry['id'] ?? null) === false) {
                throw new InvalidArgumentException('Invalid cart entry.');
            }

            $entries[] = new CartEntry($entry['id'], SelectionData::parse($entry['request'] ?? null));
        }

        return new CartSnapshot(
            $payload['id'],
            $payload['revision'],
            $entries,
            $payload['createdAt'],
            $payload['updatedAt'],
            $payload['destinationCountry'],
        );
    }

    /** @return array<string, mixed> */
    private function encode(CartSnapshot $snapshot): array
    {
        return [
            'schema' => 1,
            'id' => $snapshot->id(),
            'revision' => $snapshot->revision(),
            'createdAt' => $snapshot->createdAt(),
            'updatedAt' => $snapshot->updatedAt(),
            'destinationCountry' => $snapshot->destinationCountry(),
            'entries' => array_map(static fn(CartEntry $entry): array => [
                'id' => $entry->id(),
                'request' => SelectionData::toArray($entry->request()),
            ], $snapshot->entries()),
        ];
    }
}
