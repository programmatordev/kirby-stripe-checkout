<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Product;

use Brick\Money\Money;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\InlinePrice;
use ProgrammatorDev\StripeCheckout\Product\ProductOption;
use ProgrammatorDev\StripeCheckout\Product\ProductOptions;
use ProgrammatorDev\StripeCheckout\Product\ProductOptionValue;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductVariant;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;

final class ProductValuesTest extends TestCase
{
    public function testRequestNormalizesSelectedOptionOrderWithoutChangingTheInput(): void
    {
        $request = new ProductRequest(
            'products/shirt',
            2,
            ['sizeOption' => 'largeValue', 'colourOption' => 'blueValue'],
        );

        $this->assertSame('products/shirt', $request->reference());
        $this->assertSame(2, $request->quantity());
        $this->assertSame([
            'colourOption' => 'blueValue',
            'sizeOption' => 'largeValue',
        ], $request->selectedOptions());
    }

    public function testRequestRejectsInvalidQuantity(): void
    {
        try {
            new ProductRequest('products/shirt', 0);
            $this->fail('Expected an invalid quantity to be rejected.');
        } catch (InvalidProductException $error) {
            $this->assertSame('product.request_invalid', $error->errorCode());
        }
    }

    public function testResolvedProductCarriesAnExactTrustedSnapshot(): void
    {
        $request = new ProductRequest(
            'page://shirt000000001',
            2,
            ['sizeOption' => 'largeValue'],
        );
        $product = new ResolvedProduct(
            request: $request,
            name: 'T-shirt',
            requiresShipping: true,
            price: new InlinePrice(Money::of('16.00', 'EUR')),
            selectedOptions: [new SelectedOption('sizeOption', 'Size', 'largeValue', 'Large')],
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

    public function testResolvedProductRejectsSelectedOptionsThatDoNotMatchTheRequest(): void
    {
        $this->expectException(InvalidProductException::class);
        $this->expectExceptionMessage('product.selected_options_invalid');

        new ResolvedProduct(
            request: new ProductRequest('page://shirt000000001', selectedOptions: [
                'sizeOption' => 'largeValue',
            ]),
            name: 'T-shirt',
            requiresShipping: true,
            price: new StripePriceReference('price_fixture'),
            selectedOptions: [new SelectedOption('sizeOption', 'Size', 'smallValue', 'Small')],
            variantId: 'largeVariant0001',
        );
    }

    public function testProductOptionsExposeLocalizedNamesAndEffectiveVariantFacts(): void
    {
        $view = new ProductOptions(
            [new ProductOption('sizeOption', 'Tamanho', [
                new ProductOptionValue('smallValue', 'Pequeno'),
                new ProductOptionValue('largeValue', 'Grande'),
            ])],
            [
                new ProductVariant(
                    'smallVariant001',
                    ['sizeOption' => 'smallValue'],
                    true,
                    new InlinePrice(Money::of('19.95', 'EUR')),
                    true,
                    sku: 'SHIRT-S',
                ),
                new ProductVariant(
                    'largeVariant001',
                    ['sizeOption' => 'largeValue'],
                    false,
                    new InlinePrice(Money::of('20.00', 'EUR')),
                    false,
                ),
            ],
        );

        $this->assertSame(
            'smallVariant001',
            $view->matchVariant(['sizeOption' => 'smallValue'])?->id(),
        );
        $this->assertNull($view->matchVariant(['sizeOption' => 'largeValue']));
        $this->assertSame('Tamanho', $view->toArray()['options'][0]['name']);
        $variant = $view->variants()[0];
        $variantPrice = $variant->price();

        $this->assertSame('SHIRT-S', $variant->sku());
        $this->assertInstanceOf(InlinePrice::class, $variantPrice);
        $this->assertSame('19.95', $variantPrice->unitPrice()->getAmount()->toString());
        $this->assertTrue($variant->requiresShipping());
        $this->assertSame([
            'source' => 'kirby',
            'amount' => '19.95',
            'currency' => 'EUR',
        ], $view->toArray()['variants'][0]['price']);
    }

    public function testProductVariantExposesAnEffectiveStripePrice(): void
    {
        $variant = new ProductVariant(
            'smallVariant001',
            ['sizeOption' => 'smallValue'],
            true,
            new StripePriceReference('price_fixture'),
            false,
        );
        $price = $variant->price();

        $this->assertInstanceOf(StripePriceReference::class, $price);
        $this->assertSame('price_fixture', $price->priceId());
        $this->assertSame([
            'source' => 'stripe',
            'priceId' => 'price_fixture',
        ], $variant->toArray()['price']);
    }

    public function testProductOptionsRejectVariantsOutsideTheirOptions(): void
    {
        $this->expectException(InvalidProductException::class);
        $this->expectExceptionMessage('product.options_invalid');

        new ProductOptions(
            [new ProductOption('sizeOption', 'Size', [
                new ProductOptionValue('smallValue', 'Small'),
            ])],
            [new ProductVariant(
                'colourVariant01',
                ['colourOption' => 'redValue'],
                true,
                new InlinePrice(Money::of('10.00', 'EUR')),
                false,
            )],
        );
    }
}
