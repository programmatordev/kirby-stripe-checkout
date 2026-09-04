<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Price;

use Kirby\Cache\Cache;
use ProgrammatorDev\StripeCheckout\Money\MoneySnapshot;
use Throwable;

/**
 * Maintains the last-good searchable Price index in Kirby's native cache.
 *
 * @internal
 */
final class PriceCatalogue
{
    private const PAGE_LIMIT = 20;

    public function __construct(
        private readonly Cache $cache,
        private readonly ?PriceProviderInterface $provider,
        private readonly ?PriceResolver $resolver,
    ) {}

    /**
     * @return array{items: list<ResolvedPrice>, refreshedAt: ?int, failedAt: ?int, error: ?string}
     */
    public function current(string $currency): array
    {
        return $this->decode($this->cache->get($this->key($currency)));
    }

    /**
     * @return array{items: list<ResolvedPrice>, refreshedAt: ?int, failedAt: ?int, error: ?string}
     */
    public function load(string $currency): array
    {
        $state = $this->current($currency);

        return $state['refreshedAt'] === null
            ? $this->refresh($currency)
            : $state;
    }

    /**
     * @return array{items: list<ResolvedPrice>, refreshedAt: ?int, failedAt: ?int, error: ?string}
     */
    public function refresh(string $currency): array
    {
        $previous = $this->current($currency);

        try {
            if ($this->provider === null || $this->resolver === null) {
                throw new \RuntimeException('Stripe Price reads are not configured.');
            }

            $items = [];
            $cursor = null;
            $seenCursors = [];

            do {
                $page = $this->provider->list($currency, $cursor);
                $records = $page->prices();

                foreach ($records as $record) {
                    try {
                        $item = $this->resolver->resolveRecord($record, $currency);
                        $items[$item->priceId()] = $item;
                    } catch (Throwable) {
                        // Ineligible Stripe resources are omitted from the selector.
                    }
                }

                $last = $records[array_key_last($records)] ?? null;

                if ($page->hasMore() && $last === null) {
                    throw new \RuntimeException('Stripe returned an empty page with more data.');
                }

                $cursor = $last?->priceId;

                if ($cursor !== null && isset($seenCursors[$cursor])) {
                    throw new \RuntimeException('Stripe repeated a Price page cursor.');
                }

                if ($cursor !== null) {
                    $seenCursors[$cursor] = true;
                }
            } while ($page->hasMore());

            uasort($items, static function (ResolvedPrice $left, ResolvedPrice $right): int {
                return [$left->name(), $left->nickname() ?? '', $left->priceId()]
                    <=> [$right->name(), $right->nickname() ?? '', $right->priceId()];
            });

            $state = [
                'items' => array_values($items),
                'refreshedAt' => time(),
                'failedAt' => null,
                'error' => null,
            ];
            $this->cache->set($this->key($currency), $this->encode($state));

            return $state;
        } catch (Throwable) {
            $state = [
                ...$previous,
                'failedAt' => time(),
                'error' => 'prices.refresh_failed',
            ];
            $this->cache->set($this->key($currency), $this->encode($state));

            return $state;
        }
    }

    /**
     * @return array{items: list<ResolvedPrice>, page: int, pages: int, total: int, refreshedAt: ?int, failedAt: ?int, error: ?string}
     */
    public function search(
        string $currency,
        ?string $query = null,
        int $page = 1,
        bool $refresh = false,
    ): array {
        $state = $refresh ? $this->refresh($currency) : $this->load($currency);
        $query = mb_strtolower(trim($query ?? ''));
        $items = $query === '' ? $state['items'] : array_values(array_filter(
            $state['items'],
            static function (ResolvedPrice $price) use ($query): bool {
                $haystack = mb_strtolower(implode(' ', [
                    $price->priceId(),
                    $price->productId(),
                    $price->name(),
                    $price->nickname() ?? '',
                ]));

                return str_contains($haystack, $query);
            },
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

    private function key(string $currency): string
    {
        return 'catalogue-' . strtolower($currency);
    }

    /**
     * @param array{items: list<ResolvedPrice>, refreshedAt: ?int, failedAt: ?int, error: ?string} $state
     * @return array<string, mixed>
     */
    private function encode(array $state): array
    {
        return [
            ...$state,
            'items' => array_map(
                static fn(ResolvedPrice $price): array => $price->toArray(),
                $state['items'],
            ),
        ];
    }

    /**
     * @return array{items: list<ResolvedPrice>, refreshedAt: ?int, failedAt: ?int, error: ?string}
     */
    private function decode(mixed $cached): array
    {
        $empty = [
            'items' => [],
            'refreshedAt' => null,
            'failedAt' => null,
            'error' => null,
        ];

        if (is_array($cached) === false || is_array($cached['items'] ?? null) === false) {
            return $empty;
        }

        try {
            $items = [];

            foreach ($cached['items'] as $item) {
                if (is_array($item) === false) {
                    return $empty;
                }

                $price = $this->fromArray($item);
                $items[$price->priceId()] = $price;
            }

            return [
                'items' => array_values($items),
                'refreshedAt' => is_int($cached['refreshedAt'] ?? null) ? $cached['refreshedAt'] : null,
                'failedAt' => is_int($cached['failedAt'] ?? null) ? $cached['failedAt'] : null,
                'error' => is_string($cached['error'] ?? null) ? $cached['error'] : null,
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /** @param array<mixed, mixed> $item */
    private function fromArray(array $item): ResolvedPrice
    {
        foreach (['priceId', 'productId', 'name', 'currency', 'minorAmount', 'taxBehavior'] as $key) {
            if (array_key_exists($key, $item) === false) {
                throw new \UnexpectedValueException('Incomplete cached Price.');
            }
        }

        $images = $item['images'] ?? [];

        if (is_array($images) === false || array_is_list($images) === false) {
            throw new \UnexpectedValueException('Invalid cached Price images.');
        }

        return new ResolvedPrice(
            priceId: $this->string($item['priceId']),
            productId: $this->string($item['productId']),
            name: $this->string($item['name']),
            unitPrice: new MoneySnapshot(
                $this->string($item['currency']),
                $this->integer($item['minorAmount']),
            ),
            taxBehavior: $this->string($item['taxBehavior']),
            description: $this->nullableString($item['description'] ?? null),
            images: array_map(fn(mixed $image): string => $this->string($image), $images),
            nickname: $this->nullableString($item['nickname'] ?? null),
            taxCode: $this->nullableString($item['taxCode'] ?? null),
        );
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new \UnexpectedValueException('Invalid cached string.');
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : $this->string($value);
    }

    private function integer(mixed $value): int
    {
        return is_int($value) ? $value : throw new \UnexpectedValueException('Invalid cached integer.');
    }
}
