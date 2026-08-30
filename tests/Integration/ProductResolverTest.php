<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use Kirby\Filesystem\F;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductNotFoundException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductUnavailableException;
use ProgrammatorDev\StripeCheckout\Product\InlinePrice;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
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
            'stripeCheckoutPrice' => '16',
            'stripeCheckoutRequiresShipping' => 'yes',
            'stripeCheckoutSku' => 'SIMPLE-1',
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
                'stripeCheckoutPrice' => '8',
                'stripeCheckoutRequiresShipping' => 'no',
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
            'stripeCheckoutPrice' => '20',
            'stripeCheckoutRequiresShipping' => 'no',
            'stripeCheckoutOptions' => self::variantFixture(),
        ]);
        $page = $page->update([
            'title' => 'Camisola',
            'stripeCheckoutOptions' => [
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
        $this->assertSame('Tamanho', $product->selectedOptions()[0]->optionLabel());
        $this->assertSame('Grande', $product->selectedOptions()[0]->valueLabel());

        $view = $this->stripeCheckout()->productOptions($page);

        $this->assertSame('Tamanho', $view->options()[0]->label());
        $this->assertSame(
            'largeVariant001',
            $view->matchVariant(['sizeOption000001' => 'largeValue00001'])?->id(),
        );
    }

    public function testResolverRejectsDisabledStaleAndDraftProducts(): void
    {
        $page = $this->publishedProduct('shirt', [
            'title' => 'Shirt',
            'stripeCheckoutPrice' => '20',
            'stripeCheckoutRequiresShipping' => 'no',
            'stripeCheckoutOptions' => self::variantFixture(),
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
                'stripeCheckoutPrice' => '10',
                'stripeCheckoutRequiresShipping' => 'no',
            ],
        ]);

        $this->expectException(ProductNotFoundException::class);
        $this->stripeCheckout()->resolveProduct(new ProductRequest($draft->id()));
    }

    public function testStripeSourceReturnsAnUnhydratedPriceReferenceForTheNextBatch(): void
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
            'stripeCheckoutPriceId' => 'price_fixture',
            'stripeCheckoutRequiresShipping' => 'no',
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
        $this->assertTrue($product->requiresShipping());
    }

    public function testImageProjectionPreservesOrderAndReportsStripeOverflow(): void
    {
        $page = $this->publishedProduct('image-product', [
            'title' => 'Image product',
            'stripeCheckoutPrice' => '16',
            'stripeCheckoutRequiresShipping' => 'no',
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

        $page = $page->update(['stripeCheckoutImages' => Yaml::encode($references)]);
        $product = $this->stripeCheckout()->resolveProduct(new ProductRequest($page->id()));

        $this->assertCount(8, $product->imageUrls());
        $this->assertStringEndsWith('/image-1.jpg', $product->imageUrls()[0]);
        $this->assertStringEndsWith('/image-8.jpg', $product->imageUrls()[7]);
        $this->assertTrue($product->metadata()['imagesTruncated'] ?? false);
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

    public function testCustomResolverCannotChangeTheSelection(): void
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
                        price: new InlinePrice(\Brick\Money\Money::of('9.50', 'USD')),
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
