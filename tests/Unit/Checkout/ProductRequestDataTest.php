<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Checkout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ProductRequestData;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ProductRequestNormalizer;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;

final class ProductRequestDataTest extends TestCase
{
    public function testDefaultsAndOptionOrderingReuseProductRequestRules(): void
    {
        $request = ProductRequestData::parse(['reference' => 'products/shirt']);
        $this->assertSame(1, $request->quantity());
        $this->assertSame([], $request->selectedOptions());
        $this->assertSame([
            'reference' => 'products/shirt',
            'quantity' => 1,
            'selectedOptions' => ['colour' => 'blue', 'size' => 'large'],
        ], ProductRequestData::toArray(ProductRequestData::parse([
            'reference' => 'products/shirt',
            'selectedOptions' => ['size' => 'large', 'colour' => 'blue'],
        ])));
    }

    #[DataProvider('invalidSelections')]
    public function testRejectsMalformedAndProtectedSelectionInput(mixed $input, string $code): void
    {
        try {
            ProductRequestData::parse($input);
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
        $requestNormalizer = $this->requestNormalizer($calls);

        try {
            $requestNormalizer->direct(array_fill(0, 101, ['reference' => 'shirt']));
            $this->fail('Expected line limit.');
        } catch (CheckoutInputException $error) {
            $this->assertSame('selection.line_limit_exceeded', $error->errorCode());
        }

        $this->assertSame(0, $calls);
        $merged = $requestNormalizer->direct(array_fill(0, 100, ['reference' => 'shirt']));
        $this->assertCount(1, $merged);
        $this->assertSame(100, $merged[0]->quantity());

        $distinct = array_map(static fn(int $i): array => ['reference' => 'product-' . $i], range(1, 100));
        $this->assertCount(100, $requestNormalizer->direct($distinct));
    }

    #[DataProvider('invalidDirectInputs')]
    public function testDirectInputValidatesTheWholeBodyBeforeResolution(mixed $input): void
    {
        $calls = 0;

        try {
            $this->requestNormalizer($calls)->direct($input);
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
        $this->requestNormalizer($calls)->direct([
            ['reference' => 'shirt', 'quantity' => PHP_INT_MAX],
            ['reference' => 'other'],
        ]);
    }

    public function testExistingCanonicalReferencesCannotChangeDuringQuantityUpdates(): void
    {
        $requestNormalizer = new ProductRequestNormalizer(static fn(ProductRequest $request): Product => new Product(
            new ProductRequest('different-product', $request->quantity()),
            'Product',
            false,
            new StripePriceReference('price_fixture'),
        ));

        $this->expectException(CheckoutInputException::class);
        $requestNormalizer->withQuantity(new ProductRequest('canonical-product'), 2);
    }

    public function testDirectMergedQuantitiesAreCheckedForOverflow(): void
    {
        $calls = 0;
        $this->expectException(CheckoutInputException::class);
        $this->requestNormalizer($calls)->direct([
            ['reference' => 'shirt', 'quantity' => PHP_INT_MAX],
            ['reference' => 'shirt'],
        ]);
    }

    private function requestNormalizer(int &$calls): ProductRequestNormalizer
    {
        return new ProductRequestNormalizer(static function (ProductRequest $request) use (&$calls): Product {
            $calls++;
            return new Product($request, 'Product', false, new StripePriceReference('price_fixture'));
        });
    }
}
