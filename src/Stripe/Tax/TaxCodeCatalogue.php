<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Tax;

use Kirby\Cache\Cache;
use ProgrammatorDev\StripeCheckout\Stripe\CataloguePage;
use ProgrammatorDev\StripeCheckout\Stripe\CataloguePagination;
use ProgrammatorDev\StripeCheckout\Stripe\CatalogueRefreshPolicy;
use ProgrammatorDev\StripeCheckout\Stripe\CatalogueState;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;
use RuntimeException;
use Throwable;

/**
 * Keeps the last-good classification catalogue without expiring its contents.
 * Automatic loads belong to authorized Panel access, never storefront traffic.
 *
 * @internal
 */
final class TaxCodeCatalogue
{
    private const REFRESH_AFTER_SECONDS = 30 * 24 * 60 * 60;
    private const FAILED_REFRESH_COOLDOWN_SECONDS = 24 * 60 * 60;

    public function __construct(
        private readonly Cache $cache,
        private readonly ?TaxProviderInterface $provider,
        private readonly string $cacheKey,
    ) {}

    /** @return CatalogueState<TaxCode> */
    public function cached(): CatalogueState
    {
        $empty = $this->emptyState();
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
                // Cached classifications must retain requirement metadata so an authorized Panel load cannot silently omit the location warning.
                if (
                    is_array($item) === false
                    || is_string($item['id'] ?? null) === false
                    || is_string($item['name'] ?? null) === false
                    || is_string($item['description'] ?? null) === false
                    || is_bool($item['requiresPerformanceLocation'] ?? null) === false
                    || isset($items[$item['id']])
                ) {
                    return $empty;
                }

                $items[$item['id']] = new TaxCode(
                    id: $item['id'],
                    providerName: $item['name'],
                    providerDescription: $item['description'],
                    confirmed: true,
                    requiresPerformanceLocation: $item['requiresPerformanceLocation'],
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

            return new CatalogueState(
                items: array_values($items),
                refreshedAt: $refreshedAt,
                failedAt: $failedAt,
                error: $failedAt === null ? null : TaxCodeCatalogueErrorCode::REFRESH_FAILED,
            );
        } catch (Throwable) {
            return $empty;
        }
    }

    /** @return CatalogueState<TaxCode> */
    public function load(): CatalogueState
    {
        $state = $this->cached();
        $shouldRefresh = CatalogueRefreshPolicy::shouldRefresh(
            refreshedAt: $state->refreshedAt(),
            failedAt: $state->failedAt(),
            refreshAfterSeconds: self::REFRESH_AFTER_SECONDS,
            failureCooldownSeconds: self::FAILED_REFRESH_COOLDOWN_SECONDS,
        );

        return $shouldRefresh ? $this->refresh() : $state;
    }

    /** Cached lookup deliberately does not trigger monthly provider refresh. */
    public function find(string $id): ?TaxCode
    {
        foreach ($this->cached()->items() as $taxCode) {
            if ($taxCode->id() === $id) {
                return $taxCode;
            }
        }

        return null;
    }

    /** @return CatalogueState<TaxCode> */
    public function refresh(): CatalogueState
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
                    $items[$record->id] = new TaxCode(
                        id: $record->id,
                        providerName: $record->name,
                        providerDescription: $record->description,
                        confirmed: true,
                        requiresPerformanceLocation: $record->requiresPerformanceLocation,
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

            uasort($items, static fn(TaxCode $left, TaxCode $right): int =>
                [$left->providerName(), $left->id()] <=> [$right->providerName(), $right->id()]);
            $state = new CatalogueState(
                items: array_values($items),
                refreshedAt: time(),
                failedAt: null,
                error: null,
            );
            $this->store($state);

            return $state;
        } catch (Throwable) {
            // An incomplete refresh must not replace a known-good catalogue.
            // Keep failures value-safe; Stripe exception messages stay private.
            $state = $previous->withFailure(time(), TaxCodeCatalogueErrorCode::REFRESH_FAILED);
            $this->store($state);

            return $state;
        }
    }

    /** @return CataloguePage<TaxCode, TaxCode> */
    public function search(?string $query = null, int $page = 1, bool $refresh = false): CataloguePage
    {
        $state = $refresh ? $this->refresh() : $this->load();
        $query = mb_strtolower(trim($query ?? ''));
        $items = array_values(array_filter(
            $state->items(),
            static fn(TaxCode $taxCode): bool => $query === '' || str_contains(
                mb_strtolower(implode(' ', [$taxCode->id(), $taxCode->providerName(), $taxCode->providerDescription()])),
                $query,
            ),
        ));

        return CataloguePagination::paginate($items, $page, $state);
    }

    /** @param CatalogueState<TaxCode> $state */
    private function store(CatalogueState $state): void
    {
        // No hard expiry: only provider facts are cached, never local labels.
        $this->cache->set($this->cacheKey, [
            'refreshedAt' => $state->refreshedAt(),
            'failedAt' => $state->failedAt(),
            'items' => array_map(static fn(TaxCode $taxCode): array => [
                'id' => $taxCode->id(),
                'name' => $taxCode->providerName(),
                'description' => $taxCode->providerDescription(),
                'requiresPerformanceLocation' => $taxCode->requiresPerformanceLocation(),
            ], $state->items()),
        ]);
    }

    /** @return CatalogueState<TaxCode> */
    private function emptyState(): CatalogueState
    {
        return new CatalogueState(
            items: [],
            refreshedAt: null,
            failedAt: null,
            error: null,
        );
    }
}
