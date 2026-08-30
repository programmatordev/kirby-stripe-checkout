<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Product;

use Brick\Money\Money;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\InlinePrice;
use ProgrammatorDev\StripeCheckout\Product\ProductSelection;
use ProgrammatorDev\StripeCheckout\Product\ProductSelectionGroup;
use ProgrammatorDev\StripeCheckout\Product\ProductSelectionValue;
use ProgrammatorDev\StripeCheckout\Product\ProductSelectionVariant;
use ProgrammatorDev\StripeCheckout\Product\ProductSelectionView;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use ProgrammatorDev\StripeCheckout\Product\SelectedChoice;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;

final class ProductValuesTest extends TestCase
{
    public function testSelectionNormalizesChoiceOrderWithoutChangingTheInput(): void
    {
        $selection = new ProductSelection(
            'products/shirt',
            2,
            ['sizeGroup' => 'largeValue', 'colourGroup' => 'blueValue'],
        );

        $this->assertSame('products/shirt', $selection->reference());
        $this->assertSame(2, $selection->quantity());
        $this->assertSame([
            'colourGroup' => 'blueValue',
            'sizeGroup' => 'largeValue',
        ], $selection->choices());
    }

    public function testSelectionRejectsInvalidQuantity(): void
    {
        try {
            new ProductSelection('products/shirt', 0);
            $this->fail('Expected an invalid quantity to be rejected.');
        } catch (InvalidProductException $error) {
            $this->assertSame('product.selection_invalid', $error->errorCode());
        }
    }

    public function testResolvedProductCarriesAnExactTrustedSnapshot(): void
    {
        $selection = new ProductSelection(
            'page://shirt000000001',
            2,
            ['sizeGroup' => 'largeValue'],
        );
        $product = new ResolvedProduct(
            selection: $selection,
            name: 'T-shirt',
            requiresShipping: true,
            price: new InlinePrice(Money::of('16.00', 'EUR')),
            choices: [new SelectedChoice('sizeGroup', 'Size', 'largeValue', 'Large')],
            description: 'Heavy cotton.',
            imageUrls: ['https://example.test/shirt.jpg'],
            sku: 'SHIRT-L',
            metadata: ['imagesTruncated' => false],
            variantId: 'largeVariant0001',
        );

        $this->assertSame(PriceSource::Kirby, $product->priceSource());
        $price = $product->price();
        $this->assertInstanceOf(InlinePrice::class, $price);
        $this->assertSame('16.00', $price->unitPrice()->getAmount()->toString());
        $this->assertSame('largeVariant0001', $product->variantId());
        $this->assertSame('SHIRT-L', $product->sku());
        $this->assertSame(['https://example.test/shirt.jpg'], $product->imageUrls());
    }

    public function testResolvedProductRejectsChoicesThatDoNotMatchTheSelection(): void
    {
        $this->expectException(InvalidProductException::class);
        $this->expectExceptionMessage('product.choices_invalid');

        new ResolvedProduct(
            selection: new ProductSelection('page://shirt000000001', choices: [
                'sizeGroup' => 'largeValue',
            ]),
            name: 'T-shirt',
            requiresShipping: true,
            price: new StripePriceReference('price_fixture'),
            choices: [new SelectedChoice('sizeGroup', 'Size', 'smallValue', 'Small')],
            variantId: 'largeVariant0001',
        );
    }

    public function testSelectionViewExposesOnlySafeLocalizedSelectionFacts(): void
    {
        $view = new ProductSelectionView(
            [new ProductSelectionGroup('sizeGroup', 'Tamanho', [
                new ProductSelectionValue('smallValue', 'Pequeno'),
                new ProductSelectionValue('largeValue', 'Grande'),
            ])],
            [
                new ProductSelectionVariant(
                    'smallVariant001',
                    ['sizeGroup' => 'smallValue'],
                    true,
                ),
                new ProductSelectionVariant(
                    'largeVariant001',
                    ['sizeGroup' => 'largeValue'],
                    false,
                ),
            ],
        );

        $this->assertSame(
            'smallVariant001',
            $view->match(['sizeGroup' => 'smallValue'])?->id(),
        );
        $this->assertNull($view->match(['sizeGroup' => 'largeValue']));
        $this->assertSame('Tamanho', $view->toArray()['groups'][0]['label']);
        $this->assertArrayNotHasKey('price', $view->toArray()['variants'][0]);
    }

    public function testSelectionViewRejectsVariantsOutsideItsGroups(): void
    {
        $this->expectException(InvalidProductException::class);
        $this->expectExceptionMessage('product.selection_view_invalid');

        new ProductSelectionView(
            [new ProductSelectionGroup('sizeGroup', 'Size', [
                new ProductSelectionValue('smallValue', 'Small'),
            ])],
            [new ProductSelectionVariant(
                'colourVariant01',
                ['colourGroup' => 'redValue'],
                true,
            )],
        );
    }
}
