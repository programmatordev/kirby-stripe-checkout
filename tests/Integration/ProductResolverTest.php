<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Cms\Page;
use Kirby\Content\Field;
use Kirby\Data\Yaml;
use Kirby\Filesystem\F;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductNotFoundException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductPriceSourceMismatchException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductUnavailableException;
use ProgrammatorDev\StripeCheckout\Product\InlinePrice;
use ProgrammatorDev\StripeCheckout\Product\ProductOptions;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

final class ProductResolverTest extends KirbyTestCase
{
    private const PREFIX = 'programmatordev.stripe-checkout';

    protected function setUp(): void
    {
        parent::setUp();

        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => false,
                ],
            ],
        ]);
    }

    public function testDefaultResolverUsesKirbyLocatorsAndCanonicalizesThePageReference(): void
    {
        $page = $this->publishedProduct('simple-product', [
            'title' => 'Simple product',
            'price' => '16',
            'requiresShipping' => 'yes',
            'sku' => 'SIMPLE-1',
        ]);
        $plugin = $this->stripeCheckout();

        foreach ([$page, $page->id(), $page->uuid()->toString()] as $reference) {
            $product = $plugin->resolveProduct(new ProductRequest(
                $reference instanceof Page ? $reference->id() : $reference,
                2,
            ));

            $this->assertSame($page->uuid()->toString(), $product->request()->reference());
            $this->assertSame(2, $product->request()->quantity());
            $this->assertSame('Simple product', $product->name());
            $this->assertTrue($product->requiresShipping());
            $this->assertSame('SIMPLE-1', $product->sku());
            $price = $product->price();
            $this->assertInstanceOf(InlinePrice::class, $price);
            $this->assertSame('16.00', $price->unitPrice()->getAmount()->toString());
        }

        $unlisted = $this->kirby->site()->createChild([
            'slug' => 'unlisted-product',
            'template' => 'default',
            'content' => [
                'title' => 'Unlisted product',
                'price' => '8',
                'requiresShipping' => 'no',
            ],
        ])->changeStatus('unlisted');

        $this->assertSame(
            $unlisted->uuid()->toString(),
            $plugin->resolveProduct(new ProductRequest($unlisted->id()))
                ->request()
                ->reference(),
        );
    }

    public function testVariantResolutionUsesTechnicalFactsAndLocalizedLabels(): void
    {
        $this->restart(
            [
                self::PREFIX => [
                    'settings' => [
                        'currency' => 'EUR',
                        'defaultRequiresShipping' => false,
                    ],
                ],
            ],
            [
                ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English'],
                ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português'],
            ],
        );
        $page = $this->publishedProduct('shirt', [
            'title' => 'Shirt',
            'price' => '20',
            'requiresShipping' => 'no',
            'options' => self::variantFixture(),
        ]);
        $page = $page->update([
            'title' => 'Camisola',
            'options' => [
                'options' => [[
                    'id' => 'sizeOption000001',
                    'label' => 'Tamanho',
                    'values' => [
                        ['id' => 'smallValue00001', 'label' => 'Pequeno'],
                        ['id' => 'largeValue00001', 'label' => 'Grande'],
                    ],
                ]],
            ],
        ], 'pt');
        $this->kirby->setCurrentLanguage('pt');
        $request = new ProductRequest(
            $page->id(),
            selectedOptions: ['sizeOption000001' => 'largeValue00001'],
        );
        $product = $this->stripeCheckout()->resolveProduct($request);

        $this->assertSame('Camisola', $product->name());
        $this->assertSame('largeVariant001', $product->variantId());
        $this->assertSame('SHIRT-L', $product->sku());
        $this->assertTrue($product->requiresShipping());
        $price = $product->price();
        $this->assertInstanceOf(InlinePrice::class, $price);
        $this->assertSame('24.00', $price->unitPrice()->getAmount()->toString());
        $this->assertSame('Tamanho', $product->selectedOptions()[0]->optionName());
        $this->assertSame('Grande', $product->selectedOptions()[0]->valueName());

        $view = $this->stripeCheckout()->productOptions($page);
        /** @phpstan-ignore-next-line method.notFound */
        $fieldView = $page->options()->toProductOptions();

        $this->assertInstanceOf(ProductOptions::class, $fieldView);
        $this->assertSame('Tamanho', $view->options()[0]->name());
        $this->assertSame($view->toArray(), $fieldView->toArray());
        $smallVariant = $view->variants()[0];
        $largeVariant = $view->variants()[1];

        $smallPrice = $smallVariant->price();
        $this->assertInstanceOf(\Brick\Money\Money::class, $smallPrice);
        $this->assertSame('20.00', $smallPrice->getAmount()->toString());
        $this->assertNull($smallVariant->stripePrice());
        $this->assertFalse($smallVariant->requiresShipping());

        $this->assertSame('SHIRT-L', $largeVariant->sku());
        $variantPrice = $largeVariant->price();
        $this->assertInstanceOf(\Brick\Money\Money::class, $variantPrice);
        $this->assertSame('24.00', $variantPrice->getAmount()->toString());
        $this->assertNull($largeVariant->stripePrice());
        $this->assertTrue($largeVariant->requiresShipping());
        $this->assertSame(
            'largeVariant001',
            $view->matchVariant(['sizeOption000001' => 'largeValue00001'])?->id(),
        );
    }

    public function testProductOptionsFieldConverterUsesTheCallingField(): void
    {
        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => false,
                ],
                'products' => [
                    'fields' => ['options' => 'variants'],
                ],
            ],
        ]);
        $page = $this->publishedProduct('custom-options-field', [
            'title' => 'Custom options field',
            'price' => '20',
            'variants' => self::variantFixture(),
        ]);

        /** @phpstan-ignore-next-line method.notFound */
        $fieldView = $page->variants()->toProductOptions();
        $configuredView = $this->stripeCheckout()->productOptions($page);

        $this->assertInstanceOf(ProductOptions::class, $fieldView);
        $this->assertSame($configuredView->toArray(), $fieldView->toArray());
        $this->assertSame('Size', $fieldView->options()[0]->name());
    }

    public function testProductOptionsFieldConverterRequiresAPageField(): void
    {
        $field = $this->kirby->site()->content()->get('options');

        $this->assertInstanceOf(Field::class, $field);
        $this->expectException(InvalidProductException::class);
        $this->expectExceptionMessage('product.field_invalid');

        /** @phpstan-ignore-next-line method.notFound */
        $field->toProductOptions();
    }

    public function testResolverRejectsDisabledStaleAndDraftProducts(): void
    {
        $page = $this->publishedProduct('shirt', [
            'title' => 'Shirt',
            'price' => '20',
            'requiresShipping' => 'no',
            'options' => self::variantFixture(),
        ]);

        try {
            $this->stripeCheckout()->resolveProduct(new ProductRequest(
                $page->id(),
                selectedOptions: ['sizeOption000001' => 'smallValue00001'],
            ));
            $this->fail('Expected the disabled variant to be rejected.');
        } catch (ProductUnavailableException $error) {
            $this->assertSame('product.variant_unavailable', $error->errorCode());
        }

        try {
            $this->stripeCheckout()->resolveProduct(new ProductRequest(
                $page->id(),
                selectedOptions: ['sizeOption000001' => 'unknownValue0001'],
            ));
            $this->fail('Expected a stale option selection to be rejected.');
        } catch (InvalidProductException $error) {
            $this->assertSame('product.selected_options_invalid', $error->errorCode());
        }

        $draft = $this->kirby->site()->createChild([
            'slug' => 'draft-product',
            'template' => 'default',
            'content' => [
                'title' => 'Draft product',
                'price' => '10',
                'requiresShipping' => 'no',
            ],
        ]);

        $this->expectException(ProductNotFoundException::class);
        $this->stripeCheckout()->resolveProduct(new ProductRequest($draft->id()));
    }

    public function testStripeSourceReturnsAReferenceForFreshAuthoritativeResolution(): void
    {
        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => false,
                    'priceSource' => 'stripe',
                ],
            ],
        ]);
        $page = $this->publishedProduct('stripe-product', [
            'title' => 'Stripe product',
            'stripePrice' => 'price_fixture',
            'requiresShipping' => 'no',
        ]);
        $product = $this->stripeCheckout()->resolveProduct(new ProductRequest($page->id()));

        $price = $product->price();
        $this->assertInstanceOf(StripePriceReference::class, $price);
        $this->assertSame('price_fixture', $price->priceId());
    }

    public function testExistingProductSchemasCanUseStrictFieldMappings(): void
    {
        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => true,
                ],
                'products' => [
                    'fields' => [
                        'name' => 'productName',
                        'description' => null,
                        'images' => null,
                        'price' => 'unitPrice',
                        'requiresShipping' => 'shippingOverride',
                    ],
                ],
            ],
        ]);
        $page = $this->publishedProduct('mapped-product', [
            'title' => 'Ignored title',
            'productName' => 'Mapped product',
            'unitPrice' => '7.50',
            'shippingOverride' => 'inherit',
        ]);
        $product = $this->stripeCheckout()->resolveProduct(new ProductRequest($page->id()));

        $this->assertSame('Mapped product', $product->name());
        $this->assertNull($product->description());
        $this->assertSame([], $product->imageUrls());
        $this->assertNull($product->image());
        $this->assertTrue($product->requiresShipping());
    }

    public function testImageProjectionPreservesOrderAndReportsStripeOverflow(): void
    {
        $page = $this->publishedProduct('image-product', [
            'title' => 'Image product',
            'price' => '16',
            'requiresShipping' => 'no',
        ]);
        $references = [];

        for ($index = 1; $index <= 9; $index++) {
            $source = $this->environment->workspace()->root() . '/source-' . $index . '.jpg';
            F::write($source, 'image-' . $index);
            $file = $page->createFile([
                'filename' => 'image-' . $index . '.jpg',
                'source' => $source,
            ]);
            $references[] = $file->uuid()->toString();
        }

        $page = $page->update(['productImages' => Yaml::encode($references)]);
        $product = $this->stripeCheckout()->resolveProduct(new ProductRequest($page->id()));

        $this->assertCount(8, $product->imageUrls());
        $this->assertStringEndsWith('/image-1.jpg', $product->imageUrls()[0]);
        $this->assertStringEndsWith('/image-8.jpg', $product->imageUrls()[7]);
        $this->assertTrue($product->metadata()['imagesTruncated'] ?? false);
        $this->assertNotNull($product->image());
        $this->assertSame($references[0], $product->image()->uuid()->toString());
        $this->assertSame($product->imageUrls()[0], $product->image()->url());
    }

    public function testCustomClosureResolverReplacesTheBuiltInPageResolver(): void
    {
        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => false,
                ],
                'products' => [
                    'resolver' => static function (
                        ProductRequest $request,
                        ProductResolutionContext $context,
                    ): ResolvedProduct {
                        return new ResolvedProduct(
                            request: $request,
                            name: 'External catalogue product',
                            requiresShipping: $context->settings()->defaultRequiresShipping() ?? false,
                            price: new InlinePrice(\Brick\Money\Money::of('9.50', 'EUR')),
                        );
                    },
                ],
            ],
        ]);
        $product = $this->stripeCheckout()->resolveProduct(new ProductRequest('catalogue:42'));

        $this->assertSame('External catalogue product', $product->name());
        $this->assertSame('catalogue:42', $product->request()->reference());
    }

    public function testCustomResolverCannotChangeTheRequestedQuantity(): void
    {
        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => false,
                ],
                'products' => [
                    'resolver' => static fn(ProductRequest $request): ResolvedProduct => new ResolvedProduct(
                        request: new ProductRequest($request->reference(), 2),
                        name: 'Invalid product',
                        requiresShipping: false,
                        price: new InlinePrice(\Brick\Money\Money::of('9.50', 'EUR')),
                    ),
                ],
            ],
        ]);

        try {
            $this->stripeCheckout()->resolveProduct(new ProductRequest('catalogue:42'));
            $this->fail('Expected a resolver-mutated request to be rejected.');
        } catch (InvalidProductException $error) {
            $this->assertSame('product.resolver_changed_request', $error->errorCode());
        }
    }

    public function testCustomResolverCannotChangeTheSelectedOptions(): void
    {
        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => false,
                ],
                'products' => [
                    'resolver' => static fn(ProductRequest $request): ResolvedProduct => new ResolvedProduct(
                        request: new ProductRequest(
                            $request->reference(),
                            selectedOptions: ['sizeOption' => 'largeValue'],
                        ),
                        name: 'Invalid product',
                        requiresShipping: false,
                        price: new InlinePrice(\Brick\Money\Money::of('9.50', 'EUR')),
                        selectedOptions: [new SelectedOption(
                            'sizeOption',
                            'Size',
                            'largeValue',
                            'Large',
                        )],
                        variantId: 'largeVariant',
                    ),
                ],
            ],
        ]);

        try {
            $this->stripeCheckout()->resolveProduct(new ProductRequest(
                'catalogue:42',
                selectedOptions: ['sizeOption' => 'smallValue'],
            ));
            $this->fail('Expected resolver-mutated options to be rejected.');
        } catch (InvalidProductException $error) {
            $this->assertSame('product.resolver_changed_request', $error->errorCode());
        }
    }

    public function testCustomResolverMustMatchTheConfiguredPriceSource(): void
    {
        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => false,
                ],
                'products' => [
                    'resolver' => static fn(ProductRequest $request): ResolvedProduct => new ResolvedProduct(
                        request: $request,
                        name: 'Invalid product',
                        requiresShipping: false,
                        price: new StripePriceReference('price_fixture'),
                    ),
                ],
            ],
        ]);

        $this->expectException(ProductPriceSourceMismatchException::class);
        $this->stripeCheckout()->resolveProduct(new ProductRequest('catalogue:42'));
    }

    public function testCustomResolverMustUseTheConfiguredCurrency(): void
    {
        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => false,
                ],
                'products' => [
                    'resolver' => static fn(ProductRequest $request): ResolvedProduct => new ResolvedProduct(
                        request: $request,
                        name: 'Invalid product',
                        requiresShipping: false,
                        price: new InlinePrice(\Brick\Money\Money::of('9.50', 'USD')),
                    ),
                ],
            ],
        ]);

        try {
            $this->stripeCheckout()->resolveProduct(new ProductRequest('catalogue:42'));
            $this->fail('Expected a mismatched resolver currency to be rejected.');
        } catch (InvalidProductException $error) {
            $this->assertSame('product.currency_mismatch', $error->errorCode());
        }
    }

    public function testCustomResolverFailuresUseTheStableProductErrorContract(): void
    {
        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => false,
                ],
                'products' => [
                    'resolver' => static function (): ResolvedProduct {
                        throw new \RuntimeException('External catalogue failed.');
                    },
                ],
            ],
        ]);

        try {
            $this->stripeCheckout()->resolveProduct(new ProductRequest('catalogue:42'));
            $this->fail('Expected the resolver failure to be normalized.');
        } catch (InvalidProductException $error) {
            $this->assertSame('product.resolver_failed', $error->errorCode());
            $this->assertInstanceOf(\RuntimeException::class, $error->getPrevious());
        }
    }

    /** @param array<string, mixed> $content */
    private function publishedProduct(string $slug, array $content): Page
    {
        return $this->kirby->site()->createChild([
            'slug' => $slug,
            'template' => 'default',
            'content' => $content,
        ])->changeStatus('listed');
    }

    /**
     * @param array<string, mixed> $options
     * @param list<array<string, mixed>>|null $languages
     */
    private function restart(array $options, ?array $languages = null): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start($options, $languages);
        $this->kirby = $this->environment->app();
    }

    private function stripeCheckout(): \ProgrammatorDev\StripeCheckout\StripeCheckout
    {
        /** @phpstan-ignore-next-line method.notFound */
        return $this->kirby->site()->stripeCheckout();
    }

    /** @return array<string, mixed> */
    private static function variantFixture(): array
    {
        return [
            'options' => [[
                'id' => 'sizeOption000001',
                'label' => 'Size',
                'values' => [
                    ['id' => 'smallValue00001', 'label' => 'Small'],
                    ['id' => 'largeValue00001', 'label' => 'Large'],
                ],
            ]],
            'variants' => [
                [
                    'id' => 'smallVariant001',
                    'selectedOptions' => ['sizeOption000001' => 'smallValue00001'],
                    'enabled' => false,
                    'sku' => 'SHIRT-S',
                    'price' => null,
                    'stripePriceId' => null,
                    'requiresShipping' => 'inherit',
                ],
                [
                    'id' => 'largeVariant001',
                    'selectedOptions' => ['sizeOption000001' => 'largeValue00001'],
                    'enabled' => true,
                    'sku' => 'SHIRT-L',
                    'price' => '24',
                    'stripePriceId' => null,
                    'requiresShipping' => 'yes',
                ],
            ],
        ];
    }
}
