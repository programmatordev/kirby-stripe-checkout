<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe;

/** @internal Paginates local catalogue results without changing picker semantics. */
final class CataloguePagination
{
    public const LIMIT = 20;

    /**
     * @template T
     * @param list<T> $items
     * @return array{items: list<T>, page: int, pages: int, total: int}
     */
    public static function paginate(array $items, int $page): array
    {
        $total = count($items);
        // Unlike Kirby's native Pagination, empty results retain page 1 and
        // out-of-range requests clamp instead of throwing a route error.
        $pages = max(1, (int) ceil($total / self::LIMIT));
        $page = min(max(1, $page), $pages);

        return [
            'items' => array_slice($items, ($page - 1) * self::LIMIT, self::LIMIT),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ];
    }
}
