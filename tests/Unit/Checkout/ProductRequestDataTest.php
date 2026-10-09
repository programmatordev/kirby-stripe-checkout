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
    public function testHttpSelectionsShareOptionMappingWithDistinctFormAndJsonQuantityTypes(): void
    {
        $form = ProductRequestData::parseHttp([
            'reference' => 'shirt',
            'quantity' => '2',
            'options' => ['size' => 'large'],
        ], json: false);
        $json = ProductRequestData::parseHttp([
            'reference' => 'shirt',
            'quantity' => 2,
            'options' => (object) ['size' => 'large'],
        ], json: true);
        $expected = [
            'reference' => 'shirt',
            'quantity' => 2,
            'selectedOptions' => ['size' => 'large'],
        ];
        $this->assertSame($expected, ProductRequestData::toArray($form));
        $this->assertSame($expected, ProductRequestData::toArray($json));
        $this->assertSame(PHP_INT_MAX, ProductRequestData::parseHttp([
            'reference' => 'shirt',
            'quantity' => (string) PHP_INT_MAX,
        ], json: false)->quantity());
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidHttpSelections')]
    public function testHttpSelectionParsingPreservesInputAndQuantityErrors(array $input, bool $json, string $code): void
    {
        try {
            ProductRequestData::parseHttp($input, json: $json);
            $this->fail('Expected invalid HTTP selection.');
        } catch (CheckoutInputException $error) {
            $this->assertSame($code, $error->errorCode());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, bool, string}> */
    public static function invalidHttpSelections(): iterable
    {
        $quantities = [
            'zero' => '0',
            'negative' => '-1',
            'zero padding' => '01',
            'positive sign' => '+1',
            'whitespace' => ' 1 ',
            'fraction' => '1.2',
            'exponent' => '1e2',
            'integer overflow' => (string) PHP_INT_MAX . '0',
            'array' => ['2'],
            'null' => null,
        ];

        foreach ($quantities as $case => $quantity) {
            yield 'form ' . $case => [['reference' => 'shirt', 'quantity' => $quantity], false, 'selection.quantity_invalid'];
        }

        yield 'JSON string quantity' => [['reference' => 'shirt', 'quantity' => '2'], true, 'selection.quantity_invalid'];
        yield 'JSON options list' => [['reference' => 'shirt', 'options' => []], true, 'selection.invalid'];
        yield 'form internal options key' => [['reference' => 'shirt', 'selectedOptions' => []], false, 'selection.invalid'];
        yield 'JSON internal options key' => [['reference' => 'shirt', 'selectedOptions' => (object) []], true, 'selection.invalid'];
    }

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
        $shapes = [
            'null body' => null,
            'boolean body' => false,
            'scalar reference instead of object' => 'shirt',
            'empty body' => [],
            'list instead of object' => ['shirt'],
            'domain object instead of input data' => new ProductRequest('shirt'),
        ];

        foreach ($shapes as $case => $input) {
            yield $case => [$input, 'selection.invalid'];
        }

        $references = [
            'null reference' => null,
            'empty reference' => '',
            'numeric reference' => 123,
            'leading whitespace in reference' => ' shirt',
            'newline in reference' => "shirt\n",
            'reference exceeds length limit' => str_repeat('a', 2049),
        ];

        foreach ($references as $case => $reference) {
            yield $case => [['reference' => $reference], 'selection.invalid'];
        }

        $quantities = [
            'null quantity' => null,
            'zero quantity' => 0,
            'negative quantity' => -1,
            'numeric string quantity' => '1',
            'zero-padded string quantity' => '01',
            'whole float quantity' => 1.0,
            'boolean quantity' => true,
            'array quantity' => [],
            'float at integer limit' => (float) PHP_INT_MAX,
        ];

        foreach ($quantities as $case => $quantity) {
            yield $case => [[
                'reference' => 'shirt',
                'quantity' => $quantity,
            ], 'selection.quantity_invalid'];
        }

        $options = [
            'null options' => null,
            'boolean options' => false,
            'scalar options' => 'size',
            'list instead of option map' => ['large'],
            'numeric option value' => ['size' => 1],
            'empty option value' => ['size' => ''],
            'empty option identifier' => ['' => 'large'],
            'option identifier exceeds length limit' => [str_repeat('a', 129) => 'large'],
            'option value exceeds length limit' => ['size' => str_repeat('a', 129)],
        ];

        foreach ($options as $case => $selection) {
            yield $case => [[
                'reference' => 'shirt',
                'selectedOptions' => $selection,
            ], 'selection.invalid'];
        }

        $protectedFields = ['id', 'variantId', 'price', 'unitPrice', 'currency', 'stripePriceId', 'stripeProductId', 'sku', 'images', 'description', 'name', 'requiresShipping', 'shipping', 'taxBehavior', 'discounts', 'metadata', 'product'];

        foreach ($protectedFields as $field) {
            yield 'forged ' . $field => [[
                'reference' => 'shirt',
                $field => 'forged',
            ], 'selection.invalid'];
        }
    }

    public function testDirectInputRejectsTooManyRawItemsEvenWhenTheyWouldMerge(): void
    {
        $calls = 0;
        $requestNormalizer = $this->requestNormalizer($calls);

        try {
            $requestNormalizer->normalizeDirectInput(array_fill(0, 101, ['reference' => 'shirt']));
            $this->fail('Expected line limit.');
        } catch (CheckoutInputException $error) {
            $this->assertSame('selection.line_limit_exceeded', $error->errorCode());
        }

        $this->assertSame(0, $calls);
        $merged = $requestNormalizer->normalizeDirectInput(array_fill(0, 100, ['reference' => 'shirt']));
        $this->assertCount(1, $merged);
        $this->assertSame(100, $merged[0]->request()->quantity());

        $distinct = array_map(static fn(int $i): array => ['reference' => 'product-' . $i], range(1, 100));
        $this->assertCount(100, $requestNormalizer->normalizeDirectInput($distinct));
    }

    #[DataProvider('invalidDirectInputs')]
    public function testDirectInputValidatesTheWholeBodyBeforeResolution(mixed $input): void
    {
        $calls = 0;

        try {
            $this->requestNormalizer($calls)->normalizeDirectInput($input);
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
        $this->requestNormalizer($calls)->normalizeDirectInput([
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
        $this->requestNormalizer($calls)->normalizeDirectInput([
            ['reference' => 'shirt', 'quantity' => PHP_INT_MAX],
            ['reference' => 'shirt'],
        ]);
    }

    public function testDirectMergingCannotChangeTheCanonicalProduct(): void
    {
        $requestNormalizer = new ProductRequestNormalizer(static fn(ProductRequest $request): Product => new Product(
            request: new ProductRequest($request->quantity() > 1 ? 'different-product' : 'canonical-product', $request->quantity()),
            name: 'Product',
            requiresShipping: false,
            price: new StripePriceReference('price_fixture'),
        ));

        $this->expectException(CheckoutInputException::class);
        $this->expectExceptionMessage('selection.invalid');
        $requestNormalizer->normalizeDirectInput([
            ['reference' => 'product-alias'],
            ['reference' => 'canonical-product'],
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
