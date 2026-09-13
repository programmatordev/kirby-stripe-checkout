<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Tax;

use Kirby\Cache\Cache;
use ProgrammatorDev\StripeCheckout\Tax\TaxCodeReference;
use RuntimeException;
use Throwable;

/**
 * Keeps the last-good classification catalogue without expiring its contents.
 * Automatic loads belong to authorized Panel access, never storefront traffic.
 *
 * @internal
 * @phpstan-type State array{items: list<TaxCodeReference>, refreshedAt: ?int, failedAt: ?int, error: ?string}
 */
final class TaxCodeCatalogue
{
    private const REFRESH_AFTER_SECONDS = 30 * 24 * 60 * 60;
    private const FAILED_REFRESH_COOLDOWN_SECONDS = 24 * 60 * 60;
    private const PAGE_LIMIT = 20;

    public function __construct(
        private readonly Cache $cache,
        private readonly ?TaxProviderInterface $provider,
        private readonly string $cacheKey,
    ) {}

    /** @return State */
    public function cached(): array
    {
        $empty = [
            'items' => [],
            'refreshedAt' => null,
            'failedAt' => null,
            'error' => null,
        ];
        $cached = $this->cache->get($this->cacheKey);

        if (
            is_array($cached) === false
            || is_array($cached['items'] ?? null) === false
            || array_is_list($cached['items']) === false
        ) {
            return $empty;
        }

        try {
            $items = [];

            foreach ($cached['items'] as $item) {
                if (
                    is_array($item) === false
                    || is_string($item['id'] ?? null) === false
                    || is_string($item['name'] ?? null) === false
                    || is_string($item['description'] ?? null) === false
                    || isset($items[$item['id']])
                ) {
                    return $empty;
                }

                $items[$item['id']] = new TaxCodeReference(
                    id: $item['id'],
                    providerName: $item['name'],
                    providerDescription: $item['description'],
                    confirmed: true,
                );
            }

            $refreshedAt = $cached['refreshedAt'] ?? null;
            $failedAt = $cached['failedAt'] ?? null;

            if (
                ($refreshedAt !== null && (is_int($refreshedAt) === false || $refreshedAt < 1))
                || ($failedAt !== null && (is_int($failedAt) === false || $failedAt < 1))
                || ($items !== [] && $refreshedAt === null)
            ) {
                return $empty;
            }

            return [
                'items' => array_values($items),
                'refreshedAt' => $refreshedAt,
                'failedAt' => $failedAt,
                'error' => $failedAt === null ? null : 'tax_codes.refresh_failed',
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /** @return State */
    public function load(): array
    {
        $state = $this->cached();
        $now = time();
        $refreshDue = $state['refreshedAt'] === null
            || $state['refreshedAt'] <= $now - self::REFRESH_AFTER_SECONDS;
        $retryAllowed = $state['failedAt'] === null
            || $state['failedAt'] <= $now - self::FAILED_REFRESH_COOLDOWN_SECONDS;

        return $refreshDue && $retryAllowed ? $this->refresh() : $state;
    }

    /** Cached lookup deliberately does not trigger monthly provider refresh. */
    public function find(string $id): ?TaxCodeReference
    {
        foreach ($this->cached()['items'] as $taxCode) {
            if ($taxCode->id() === $id) {
                return $taxCode;
            }
        }

        return null;
    }

    /** @return State */
    public function refresh(): array
    {
        $previous = $this->cached();

        try {
            if ($this->provider === null) {
                throw new RuntimeException('Stripe Tax reads are not configured.');
            }

            $items = [];
            $cursor = null;
            $seenCursors = [];

            do {
                $page = $this->provider->listTaxCodes($cursor);
                $records = $page->taxCodes();

                foreach ($records as $record) {
                    $items[$record->id] = new TaxCodeReference(
                        id: $record->id,
                        providerName: $record->name,
                        providerDescription: $record->description,
                        confirmed: true,
                    );
                }

                $last = $records[array_key_last($records)] ?? null;

                if ($page->hasMore() && $last === null) {
                    throw new RuntimeException('Stripe returned an empty Tax Code page with more data.');
                }

                $cursor = $last?->id;

                if ($cursor !== null && isset($seenCursors[$cursor])) {
                    throw new RuntimeException('Stripe repeated a Tax Code page cursor.');
                }

                if ($cursor !== null) {
                    $seenCursors[$cursor] = true;
                }
            } while ($page->hasMore());

            uasort($items, static fn(TaxCodeReference $left, TaxCodeReference $right): int =>
                [$left->providerName(), $left->id()] <=> [$right->providerName(), $right->id()]);
            $state = [
                'items' => array_values($items),
                'refreshedAt' => time(),
                'failedAt' => null,
                'error' => null,
            ];
            $this->store($state);

            return $state;
        } catch (Throwable) {
            // An incomplete refresh must not replace a known-good catalogue.
            // Keep failures value-safe; Stripe exception messages stay private.
            $state = [
                ...$previous,
                'failedAt' => time(),
                'error' => 'tax_codes.refresh_failed',
            ];
            $this->store($state);

            return $state;
        }
    }

    /** @return array{items: list<TaxCodeReference>, refreshedAt: ?int, failedAt: ?int, error: ?string, page: int, pages: int, total: int} */
    public function search(?string $query = null, int $page = 1, bool $refresh = false): array
    {
        $state = $refresh ? $this->refresh() : $this->load();
        $query = mb_strtolower(trim($query ?? ''));
        $items = array_values(array_filter(
            $state['items'],
            static fn(TaxCodeReference $taxCode): bool => $query === '' || str_contains(
                mb_strtolower(implode(' ', [$taxCode->id(), $taxCode->providerName(), $taxCode->providerDescription()])),
                $query,
            ),
        ));
        $total = count($items);
        $pages = max(1, (int) ceil($total / self::PAGE_LIMIT));
        $page = min(max(1, $page), $pages);

        return [
            ...$state,
            'items' => array_slice($items, ($page - 1) * self::PAGE_LIMIT, self::PAGE_LIMIT),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    /** @param State $state */
    private function store(array $state): void
    {
        // No hard expiry: only provider facts are cached, never local labels.
        $this->cache->set($this->cacheKey, [
            ...$state,
            'items' => array_map(static fn(TaxCodeReference $taxCode): array => [
                'id' => $taxCode->id(),
                'name' => $taxCode->providerName(),
                'description' => $taxCode->providerDescription(),
            ], $state['items']),
        ]);
    }
}
