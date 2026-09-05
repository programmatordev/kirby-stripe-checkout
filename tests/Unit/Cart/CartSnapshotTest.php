<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Cart;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartEntry;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartMutator;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SelectionCanonicalizer;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Test\Support\Cart\InMemoryCartStore;

final class CartSnapshotTest extends TestCase
{
    /** @param array<array-key, CartEntry> $entries */
    #[DataProvider('invalidEntries')]
    public function testRejectsDuplicateAndUnorderedState(array $entries): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CartSnapshot('cart', 'revision', $entries, 100, 100);
    }

    /** @return iterable<string, array{array<array-key, CartEntry>}> */
    public static function invalidEntries(): iterable
    {
        yield 'duplicate identity' => [[
            new CartEntry('item', new ProductRequest('shirt')),
            new CartEntry('item', new ProductRequest('bag')),
        ]];
        yield 'equivalent selections' => [[
            new CartEntry('item-a', new ProductRequest('shirt', 2)),
            new CartEntry('item-b', new ProductRequest('shirt', 3)),
        ]];
        yield 'sparse entries' => [[1 => new CartEntry('item', new ProductRequest('shirt'))]];
    }

    public function testUpdatingBeforeCreationIsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CartSnapshot('cart', 'revision', [], 100, 99);
    }

    public function testClearResetsReservedDestinationWithoutResolvingAndPreservesClockOrder(): void
    {
        $store = new InMemoryCartStore(new CartSnapshot('cart', 'revision', [], 100, 200, 'PT'));
        $mutator = new CartMutator(
            $store,
            new SelectionCanonicalizer(static fn() => throw new \LogicException('Clear must not resolve products.')),
            static fn(): string => 'unused-id',
            static fn(): string => 'new-revision',
            static fn(): int => 150,
        );
        $cleared = $mutator->clear('revision');

        $this->assertNull($cleared->destinationCountry());
        $this->assertSame('cart', $cleared->id());
        $this->assertSame('new-revision', $cleared->revision());
        $this->assertSame(200, $cleared->updatedAt());
        $this->assertSame($cleared, $mutator->clear($cleared->revision()));
    }
}
