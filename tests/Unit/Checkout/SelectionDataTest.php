<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Checkout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SelectionCanonicalizer;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SelectionData;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;

final class SelectionDataTest extends TestCase
{
    public function testDefaultsAndOptionOrderingReuseProductRequestRules(): void
    {
        $request = SelectionData::parse(['reference' => 'products/shirt']);
        $this->assertSame(1, $request->quantity());
        $this->assertSame([], $request->selectedOptions());
        $this->assertSame([
            'reference' => 'products/shirt',
            'quantity' => 1,
            'selectedOptions' => ['colour' => 'blue', 'size' => 'large'],
        ], SelectionData::toArray(SelectionData::parse([
            'reference' => 'products/shirt',
            'selectedOptions' => ['size' => 'large', 'colour' => 'blue'],
        ])));
    }

    #[DataProvider('invalidSelections')]
    public function testRejectsMalformedAndProtectedSelectionInput(mixed $input, string $code): void
    {
        try {
            SelectionData::parse($input);
            $this->fail('Expected invalid selection.');
        } catch (CheckoutInputException $error) {
            $this->assertSame($code, $error->errorCode());
        }
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidSelections(): iterable
    {
        foreach ([null, false, 'shirt', [], ['shirt'], new ProductRequest('shirt')] as $index => $input) {
            yield 'shape-' . $index => [$input, 'selection.invalid'];
        }
        foreach ([null, '', 123, ' shirt', "shirt\n", str_repeat('a', 2049)] as $index => $reference) {
            yield 'reference-' . $index => [['reference' => $reference], 'selection.invalid'];
        }
        foreach ([null, 0, -1, '1', '01', 1.0, true, [], (float) PHP_INT_MAX] as $index => $quantity) {
            yield 'quantity-' . $index => [['reference' => 'shirt', 'quantity' => $quantity], 'selection.quantity_invalid'];
        }
        foreach ([null, false, 'size', ['large'], ['size' => 1], ['size' => ''], ['' => 'large'], [str_repeat('a', 129) => 'large'], ['size' => str_repeat('a', 129)]] as $index => $options) {
            yield 'options-' . $index => [['reference' => 'shirt', 'selectedOptions' => $options], 'selection.invalid'];
        }
        foreach (['id', 'variantId', 'price', 'unitPrice', 'currency', 'stripePriceId', 'stripeProductId', 'sku', 'images', 'description', 'name', 'requiresShipping', 'shipping', 'taxBehavior', 'discounts', 'metadata', 'product'] as $field) {
            yield 'protected-' . $field => [['reference' => 'shirt', $field => 'forged'], 'selection.invalid'];
        }
    }

    public function testDirectInputRejectsTooManyRawItemsEvenWhenTheyWouldMerge(): void
    {
        $calls = 0;
        $canonicalizer = $this->canonicalizer($calls);
        try {
            $canonicalizer->direct(array_fill(0, 101, ['reference' => 'shirt']));
            $this->fail('Expected line limit.');
        } catch (CheckoutInputException $error) {
            $this->assertSame('selection.line_limit_exceeded', $error->errorCode());
        }
        $this->assertSame(0, $calls);
        $merged = $canonicalizer->direct(array_fill(0, 100, ['reference' => 'shirt']));
        $this->assertCount(1, $merged);
        $this->assertSame(100, $merged[0]->quantity());

        $distinct = array_map(static fn(int $i): array => ['reference' => 'product-' . $i], range(1, 100));
        $this->assertCount(100, $canonicalizer->direct($distinct));
    }

    #[DataProvider('invalidDirectInputs')]
    public function testDirectInputValidatesTheWholeBodyBeforeResolution(mixed $input): void
    {
        $calls = 0;
        try {
            $this->canonicalizer($calls)->direct($input);
            $this->fail('Expected invalid direct input.');
        } catch (CheckoutInputException $error) {
            $this->assertSame('selection.invalid', $error->errorCode());
        }
        $this->assertSame(0, $calls);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDirectInputs(): iterable
    {
        yield 'empty' => [[]];
        yield 'null' => [null];
        yield 'object' => [['reference' => 'shirt']];
        yield 'sparse list' => [[1 => ['reference' => 'shirt']]];
        yield 'later forged price' => [[['reference' => 'shirt'], ['reference' => 'other', 'price' => 1]]];
    }

    public function testDirectInputCannotOverflowAcrossDifferentProducts(): void
    {
        $calls = 0;
        $this->expectException(CheckoutInputException::class);
        $this->expectExceptionMessage('selection.quantity_invalid');
        $this->canonicalizer($calls)->direct([
            ['reference' => 'shirt', 'quantity' => PHP_INT_MAX],
            ['reference' => 'other'],
        ]);
    }

    public function testExistingCanonicalReferencesCannotChangeDuringQuantityUpdates(): void
    {
        $canonicalizer = new SelectionCanonicalizer(static fn(ProductRequest $request): ResolvedProduct => new ResolvedProduct(
            new ProductRequest('different-product', $request->quantity()),
            'Product',
            false,
            new StripePriceReference('price_fixture'),
        ));

        $this->expectException(CheckoutInputException::class);
        $canonicalizer->withQuantity(new ProductRequest('canonical-product'), 2);
    }

    public function testDirectMergedQuantitiesAreCheckedForOverflow(): void
    {
        $calls = 0;
        $this->expectException(CheckoutInputException::class);
        $this->canonicalizer($calls)->direct([
            ['reference' => 'shirt', 'quantity' => PHP_INT_MAX],
            ['reference' => 'shirt'],
        ]);
    }

    private function canonicalizer(int &$calls): SelectionCanonicalizer
    {
        return new SelectionCanonicalizer(static function (ProductRequest $request) use (&$calls): ResolvedProduct {
            $calls++;
            return new ResolvedProduct($request, 'Product', false, new StripePriceReference('price_fixture'));
        });
    }
}
