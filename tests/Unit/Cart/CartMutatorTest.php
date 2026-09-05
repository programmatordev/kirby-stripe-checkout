<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Cart;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartEntry;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartMutationException;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartMutator;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SelectionCanonicalizer;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SelectionData;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductUnavailableException;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Test\Support\Cart\InMemoryCartStore;

final class CartMutatorTest extends TestCase
{
    private InMemoryCartStore $store;
    private CartMutator $cart;
    private SelectionCanonicalizer $selections;
    private bool $available = true;
    private ?int $maximum = null;
    private int $resolutions = 0;
    private int $nextId = 0;
    private int $nextRevision = 0;

    protected function setUp(): void
    {
        $this->store = new InMemoryCartStore(new CartSnapshot('cart-id', 'revision-0', [], 100, 100));
        $this->selections = new SelectionCanonicalizer(function (ProductRequest $request): ResolvedProduct {
            $this->resolutions++;

            if ($this->available === false || ($this->maximum !== null && $request->quantity() > $this->maximum)) {
                throw new ProductUnavailableException();
            }

            $request = new ProductRequest(
                $request->reference() === 'shirt' ? 'page://shirt' : $request->reference(),
                $request->quantity(),
                $request->selectedOptions(),
            );
            $options = [];

            foreach ($request->selectedOptions() as $option => $value) {
                $options[] = new SelectedOption($option, $option, $value, $value);
            }

            return new ResolvedProduct(
                $request,
                'Product',
                false,
                new StripePriceReference('price_fixture'),
                selectedOptions: $options,
                variantId: $options === [] ? null : 'variant-fixture',
            );
        });
        $this->cart = $this->mutator();
    }

    private function mutator(): CartMutator
    {
        return new CartMutator(
            $this->store,
            $this->selections,
            fn(): string => 'item-' . ++$this->nextId,
            fn(): string => 'revision-' . ++$this->nextRevision,
            static fn(): int => 200,
        );
    }

    public function testEquivalentLocatorsAndOptionOrderMergeWithoutChangingIdentityOrPosition(): void
    {
        $empty = $this->store->read();
        $first = $this->cart->add(new ProductRequest('shirt', 2, ['size' => 'large', 'colour' => 'blue']));
        $this->cart->add(new ProductRequest('other'));
        $merged = $this->cart->add(new ProductRequest('page://shirt', 3, ['colour' => 'blue', 'size' => 'large']));

        $this->assertSame([], $empty->entries());
        $this->assertSame(2, $first->entries()[0]->request()->quantity());
        $this->assertCount(2, $merged->entries());
        $this->assertSame($first->entries()[0]->id(), $merged->entries()[0]->id());
        $this->assertSame('page://shirt', $merged->entries()[0]->request()->reference());
        $this->assertSame(5, $merged->entries()[0]->request()->quantity());
        $this->assertSame('other', $merged->entries()[1]->request()->reference());
        $this->assertSame($empty->id(), $merged->id());
        $this->assertSame(100, $merged->createdAt());
        $this->assertSame(200, $merged->updatedAt());
        $this->assertNotSame($first->revision(), $merged->revision());
    }

    public function testDifferentOptionsRemainSeparateAndReaddingCreatesANewLastItem(): void
    {
        $first = $this->cart->add(new ProductRequest('shirt', selectedOptions: ['size' => 'small']));
        $second = $this->cart->add(new ProductRequest('shirt', selectedOptions: ['size' => 'large']));
        $removed = $this->cart->remove($first->entries()[0]->id(), $second->revision());
        $readded = $this->cart->add(new ProductRequest('shirt', selectedOptions: ['size' => 'small']));

        $this->assertCount(1, $removed->entries());
        $this->assertSame(['size' => 'large'], $readded->entries()[0]->request()->selectedOptions());
        $this->assertNotSame($first->entries()[0]->id(), $readded->entries()[1]->id());
    }

    public function testSemanticNoOpsPreserveTheEntireSnapshot(): void
    {
        $empty = $this->store->read();
        $this->assertSame($empty, $this->cart->clear($empty->revision()));
        $added = $this->cart->add(new ProductRequest('shirt', 2));
        $this->assertSame($added, $this->cart->update($added->entries()[0]->id(), 2, $added->revision()));
        $updated = $this->cart->update($added->entries()[0]->id(), 4, $added->revision());
        $this->assertSame(4, $updated->entries()[0]->request()->quantity());
        $this->assertNotSame($added->revision(), $updated->revision());
    }

    #[DataProvider('revisionOperations')]
    public function testStaleAndMissingRevisionsCannotMutateOrResolve(string $operation): void
    {
        $added = $this->cart->add(new ProductRequest('shirt'));
        $id = $added->entries()[0]->id();
        $calls = $this->resolutions;

        foreach (['revision-0', ''] as $revision) {
            try {
                match ($operation) {
                    'update' => $this->cart->update($id, 2, $revision),
                    'remove' => $this->cart->remove($id, $revision),
                    default => $this->cart->clear($revision),
                };
                $this->fail('Expected a revision rejection.');
            } catch (CartMutationException $error) {
                $this->assertSame('cart.revision_conflict', $error->errorCode());
                $this->assertSame($added, $error->current());
            } catch (CheckoutInputException $error) {
                $this->assertSame('selection.invalid', $error->errorCode());
            }

            $this->assertSame($added, $this->store->read());
            $this->assertSame($calls, $this->resolutions);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function revisionOperations(): iterable
    {
        foreach (['update', 'remove', 'clear'] as $operation) {
            yield $operation => [$operation];
        }
    }

    public function testAnOlderMutatorAddsAgainstTheLatestStoreState(): void
    {
        $other = $this->mutator();
        $first = $this->cart->add(new ProductRequest('shirt', 2));
        $added = $other->add(new ProductRequest('shirt', 3));

        $this->assertCount(1, $added->entries());
        $this->assertSame(5, $added->entries()[0]->request()->quantity());
        $this->assertNotSame($first->revision(), $added->revision());
    }

    public function testMergedQuantityIsValidatedByTheResolverBeforeCommit(): void
    {
        $this->maximum = 5;
        $added = $this->cart->add(new ProductRequest('shirt', 3));
        $this->assertRejectedWithoutWrite(fn() => $this->cart->add(new ProductRequest('shirt', 3)), ProductUnavailableException::class);
        $this->assertSame($added, $this->store->read());
        $this->assertRejectedWithoutWrite(fn() => $this->cart->update($added->entries()[0]->id(), 6, $added->revision()), ProductUnavailableException::class);
    }

    public function testRemovalAndClearRecoverDuringAResolverOutage(): void
    {
        $this->cart->add(new ProductRequest('shirt'));
        $added = $this->cart->add(new ProductRequest('other'));
        $calls = $this->resolutions;
        $this->available = false;
        $removed = $this->cart->remove($added->entries()[0]->id(), $added->revision());
        $cleared = $this->cart->clear($removed->revision());

        $this->assertSame([], $cleared->entries());
        $this->assertSame($added->id(), $cleared->id());
        $this->assertSame($calls, $this->resolutions);
    }

    public function testFailedAddsUpdatesAndUnknownItemsLeaveStateUnchanged(): void
    {
        $added = $this->cart->add(new ProductRequest('shirt'));
        $this->assertRejectedWithoutWrite(fn() => $this->cart->update($added->entries()[0]->id(), 0, $added->revision()), CheckoutInputException::class);

        foreach (['remove', 'update'] as $operation) {
            try {
                $operation === 'remove'
                    ? $this->cart->remove('unknown', $added->revision())
                    : $this->cart->update('unknown', 1, $added->revision());
                $this->fail('Expected a missing item.');
            } catch (CartMutationException $error) {
                $this->assertSame('cart.item_not_found', $error->errorCode());
                $this->assertNull($error->current());
            }
        }

        $this->available = false;
        $this->assertRejectedWithoutWrite(fn() => $this->cart->add(new ProductRequest('other')), ProductUnavailableException::class);
        $this->assertRejectedWithoutWrite(fn() => $this->cart->update($added->entries()[0]->id(), 2, $added->revision()), ProductUnavailableException::class);
    }

    public function testHundredEntryLimitStillAllowsMergingAnExistingEntry(): void
    {
        for ($index = 0; $index < 100; $index++) {
            $this->cart->add(new ProductRequest('product-' . $index));
        }

        $merged = $this->cart->add(new ProductRequest('product-0'));
        $this->assertCount(100, $merged->entries());
        $this->assertSame(2, $merged->entries()[0]->request()->quantity());
        $this->assertRejectedWithoutWrite(fn() => $this->cart->add(new ProductRequest('product-101')), CheckoutInputException::class);
    }

    public function testQuantitiesHaveNoBusinessCapButMergesAndTotalsCannotOverflow(): void
    {
        $added = $this->cart->add(new ProductRequest('shirt', PHP_INT_MAX));
        $this->assertSame(PHP_INT_MAX, $added->entries()[0]->request()->quantity());

        foreach (['shirt', 'other'] as $reference) {
            $this->assertRejectedWithoutWrite(fn() => $this->cart->add(new ProductRequest($reference)), CheckoutInputException::class);
        }
    }

    public function testCartAndDirectSelectionsProduceTheSameCanonicalCollection(): void
    {
        $inputs = [
            ['reference' => 'shirt', 'quantity' => 2, 'selectedOptions' => ['size' => 'large', 'colour' => 'blue']],
            ['reference' => 'other'],
            ['reference' => 'page://shirt', 'quantity' => 3, 'selectedOptions' => ['colour' => 'blue', 'size' => 'large']],
        ];

        foreach ($inputs as $input) {
            $this->cart->add(SelectionData::parse($input));
        }

        $before = $this->store->read();
        $direct = $this->selections->direct($inputs);

        $this->assertEquals($direct, array_map(static fn(CartEntry $entry): ProductRequest => $entry->request(), $before->entries()));
        $this->assertSame($before, $this->store->read());
    }

    /**
     * @param Closure(): mixed $operation
     * @param class-string<\Throwable> $exception
     */
    private function assertRejectedWithoutWrite(Closure $operation, string $exception): void
    {
        $before = $this->store->read();

        try {
            $operation();
            $this->fail('Expected mutation rejection.');
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);
        }

        $this->assertSame($before, $this->store->read());
    }
}
