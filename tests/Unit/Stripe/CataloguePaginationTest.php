<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Stripe;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Stripe\CataloguePagination;
use ProgrammatorDev\StripeCheckout\Stripe\CatalogueState;

final class CataloguePaginationTest extends TestCase
{
    #[DataProvider('pages')]
    public function testPreservesEmptyResultsClampingAndItemOrder(
        int $total,
        int $requestedPage,
        int $expectedPage,
        int $expectedPages,
        int $expectedFirst,
        int $expectedCount,
    ): void {
        $items = $total === 0 ? [] : range(1, $total);
        $state = new CatalogueState(
            items: $items,
            refreshedAt: 1,
            failedAt: null,
            error: null,
        );
        $result = CataloguePagination::paginate($items, $requestedPage, $state);

        $this->assertSame($expectedPage, $result->page());
        $this->assertSame($expectedPages, $result->pages());
        $this->assertSame($total, $result->total());
        $this->assertSame($state, $result->state());
        $this->assertSame(
            $expectedCount === 0 ? [] : range($expectedFirst, $expectedFirst + $expectedCount - 1),
            $result->items(),
        );
    }

    /** @return iterable<string, array{int, int, int, int, int, int}> */
    public static function pages(): iterable
    {
        yield 'empty first page' => [0, 1, 1, 1, 0, 0];
        yield 'empty high page' => [0, 99, 1, 1, 0, 0];
        yield 'negative page' => [25, -1, 1, 2, 1, 20];
        yield 'complete page' => [20, 1, 1, 1, 1, 20];
        yield 'partial last page' => [25, 2, 2, 2, 21, 5];
        yield 'high page' => [25, 99, 2, 2, 21, 5];
    }
}
