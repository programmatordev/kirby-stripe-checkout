<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Exception\PermissionException;
use Kirby\Form\Form;
use ProgrammatorDev\StripeCheckout\Kirby\StripePriceField;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\TestWorkspace;

final class StripePriceFieldTest extends KirbyTestCase
{
    public function testFieldStoresOneScalarPriceIdAndPreservesCachedSelectionDetails(): void
    {
        $page = $this->restartWithStripePriceField();
        $this->seedCatalogue();
        $field = Form::for($page)->fields()->field('price');
        /** @var array{value: string, selected: array{info: string, text: string}, catalogue: array{status: string}} $props */
        $props = $field->toArray();

        $this->assertInstanceOf(StripePriceField::class, $field);
        $this->assertSame('price_canvas', $field->toStoredValue());
        $this->assertSame('price_canvas', $props['value']);
        $this->assertSame('Canvas bag · Standard', $props['selected']['text']);
        $this->assertSame('16.00 EUR', $props['selected']['info']);
        $this->assertSame('ready', $props['catalogue']['status']);
    }

    public function testStoredPriceSurvivesWhenItIsMissingFromTheCatalogue(): void
    {
        $page = $this->restartWithStripePriceField();
        $field = Form::for($page)->fields()->field('price');
        /** @var array{selected: array{id: string, unavailable: bool}} $props */
        $props = $field->toArray();

        $this->assertSame('price_canvas', $props['selected']['id']);
        $this->assertTrue($props['selected']['unavailable']);
    }

    public function testInactiveSourcePreservesTheValueWithoutLoadingTheCatalogue(): void
    {
        $page = $this->restartWithStripePriceField(sourceInactive: true);
        $field = Form::for($page)->fields()->field('price');
        /** @var array{disabled: bool, sourceInactive: bool, selected: array{id: string, unavailable: bool}, catalogue: array{status: string}} $props */
        $props = $field->toArray();

        $this->assertTrue($props['disabled']);
        $this->assertTrue($props['sourceInactive']);
        $this->assertSame('price_canvas', $props['selected']['id']);
        $this->assertFalse($props['selected']['unavailable']);
        $this->assertSame('empty', $props['catalogue']['status']);
    }

    public function testFieldApiSearchesCachedPricesWithoutARequestToStripe(): void
    {
        $this->restartWithStripePriceField();
        $this->seedCatalogue();
        /** @var array{data: list<array{id: string}>, pagination: array{total: int}, catalogue: array{status: string}} $response */
        $response = $this->kirby->api()->call(
            'pages/product/fields/price',
            'GET',
            ['query' => ['search' => 'canvas']],
        );

        $this->assertSame('price_canvas', $response['data'][0]['id'] ?? null);
        $this->assertSame(1, $response['pagination']['total']);
        $this->assertSame('ready', $response['catalogue']['status']);
    }

    public function testFieldApiListsProductsBeforeTheirPrices(): void
    {
        $this->restartWithStripePriceField();
        $this->seedCatalogue([
            $this->priceItem(),
            $this->priceItem(
                priceId: 'price_canvas_large',
                nickname: 'Large',
                minorAmount: 2400,
            ),
            $this->priceItem(
                priceId: 'price_poster',
                productId: 'prod_poster',
                name: 'Poster',
                nickname: null,
                minorAmount: 2500,
                images: [],
            ),
        ]);
        /** @var array{data: list<array{id: string, info: string, image: array<string, string>}>, pagination: array{total: int}} $products */
        $products = $this->kirby->api()->call(
            'pages/product/fields/price',
            'GET',
            ['query' => ['view' => 'products']],
        );
        /** @var array{data: list<array{id: string, info: string, text: string, image: array<string, string>, selected: array{text: string}}>, pagination: array{total: int}} $prices */
        $prices = $this->kirby->api()->call(
            'pages/product/fields/price',
            'GET',
            ['query' => ['view' => 'prices', 'product' => 'prod_canvas']],
        );

        $this->assertSame(2, $products['pagination']['total']);
        $this->assertSame('prod_canvas', $products['data'][0]['id']);
        $this->assertSame('2 eligible Prices', $products['data'][0]['info']);
        $this->assertSame('https://example.test/canvas.jpg', $products['data'][0]['image']['src']);
        $this->assertSame('pattern', $products['data'][1]['image']['back']);
        $this->assertSame(2, $prices['pagination']['total']);
        $this->assertSame('Standard', $prices['data'][0]['text']);
        $this->assertSame('16.00 EUR', $prices['data'][0]['info']);
        $this->assertSame('https://example.test/canvas.jpg', $prices['data'][0]['image']['src']);
        $this->assertSame(
            'Canvas bag · Standard',
            $prices['data'][0]['selected']['text'],
        );
    }

    public function testFieldApiHydratesOneSavedSelectionFromCache(): void
    {
        $this->restartWithStripePriceField();
        $this->seedCatalogue();
        /** @var array{data: list<array{id: string, info: string, text: string}>, pagination: array{total: int}} $response */
        $response = $this->kirby->api()->call(
            'pages/product/fields/price',
            'GET',
            ['query' => ['view' => 'selected', 'price' => 'price_canvas']],
        );

        $this->assertSame(1, $response['pagination']['total']);
        $this->assertSame('price_canvas', $response['data'][0]['id']);
        $this->assertSame('Canvas bag · Standard', $response['data'][0]['text']);
        $this->assertSame('16.00 EUR', $response['data'][0]['info']);
    }

    public function testOptionsFieldReusesTheSameCatalogueEndpointForVariantPrices(): void
    {
        $page = $this->restartWithStripePriceField();
        $this->seedCatalogue();
        $options = Form::for($page)->fields()->field('variants')->toArray();
        /** @var array{data: list<array{id: string}>} $response */
        $response = $this->kirby->api()->call(
            'pages/product/fields/variants/prices',
            'GET',
            ['query' => [
                'view' => 'selected',
                'prices' => 'price_canvas,price_missing',
            ]],
        );

        $this->assertTrue($options['pricesReadable'] ?? false);
        $this->assertSame('price_canvas', $response['data'][0]['id'] ?? null);
    }

    public function testPermissionDenialDisablesTheFieldAndBlocksItsApi(): void
    {
        $page = $this->restartWithStripePriceField(false);
        $field = Form::for($page)->fields()->field('price');

        $this->assertTrue($field->isDisabled());

        $this->expectException(PermissionException::class);
        $this->kirby->api()->call('pages/product/fields/price', 'GET');
    }

    public function testFailedExplicitRefreshReturnsARecoverableErrorState(): void
    {
        $this->restartWithStripePriceField();
        /** @var array{data: list<mixed>, catalogue: array{status: string, error: string}} $response */
        $response = $this->kirby->api()->call(
            'pages/product/fields/price',
            'POST',
        );

        $this->assertSame('error', $response['catalogue']['status']);
        $this->assertSame('prices.refresh_failed', $response['catalogue']['error']);
        $this->assertSame([], $response['data']);
    }

    public function testInvalidPriceIdCannotBeStored(): void
    {
        $page = $this->restartWithStripePriceField();
        $field = Form::for($page)->fields()->field('price');

        $this->expectException(\Kirby\Exception\InvalidArgumentException::class);
        $field->fill('not-a-price')->toStoredValue();
    }

    private function restartWithStripePriceField(
        ?bool $pricesRead = null,
        bool $sourceInactive = false,
    ): \Kirby\Cms\Page {
        $template = $sourceInactive ? 'inactive-price-product' : 'price-product';
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            options: [
                'api.allowImpersonation' => true,
                'programmatordev.stripe-checkout' => [
                    'settings' => [
                        'currency' => 'EUR',
                        'defaultRequiresShipping' => false,
                        'priceSource' => 'stripe',
                    ],
                    'stripe' => [
                        'secretKey' => 'sk_test_example',
                    ],
                ],
            ],
            beforeApp: static function (TestWorkspace $workspace) use ($sourceInactive, $template): void {
                $workspace->writePageBlueprint($template, [
                    'title' => 'Price product',
                    'fields' => [
                        'price' => [
                            'label' => 'Price',
                            'disabled' => $sourceInactive,
                            'sourceInactive' => $sourceInactive,
                            'type' => 'stripe-checkout-price',
                        ],
                        'variants' => [
                            'currency' => 'EUR',
                            'priceSource' => 'stripe',
                            'type' => 'stripe-checkout-options',
                        ],
                    ],
                ]);
            },
            roles: $pricesRead === null ? null : [[
                'name' => 'price-editor',
                'permissions' => [
                    'access' => ['panel' => true],
                    'programmatordev.stripe-checkout' => [
                        'prices.read' => $pricesRead,
                    ],
                ],
            ]],
            users: $pricesRead === null ? null : [[
                'id' => 'price-editor',
                'email' => 'price-editor@example.com',
                'role' => 'price-editor',
            ]],
            impersonate: $pricesRead === null ? 'kirby' : 'price-editor',
        );
        $this->kirby = $this->environment->app();

        return $this->kirby->site()->createChild([
            'slug' => 'product',
            'template' => $template,
            'content' => [
                'title' => 'Product',
                'price' => 'price_canvas',
                'variants' => [
                    'options' => [],
                    'variants' => [],
                ],
            ],
        ]);
    }

    /** @param list<array<string, mixed>>|null $items */
    private function seedCatalogue(?array $items = null): void
    {
        $this->kirby->cache('programmatordev.stripe-checkout.prices')->set('catalogue-eur', [
            'error' => null,
            'failedAt' => null,
            'refreshedAt' => time(),
            'items' => $items ?? [$this->priceItem()],
        ]);
    }

    /**
     * @param list<string> $images
     * @return array<string, mixed>
     */
    private function priceItem(
        string $priceId = 'price_canvas',
        string $productId = 'prod_canvas',
        string $name = 'Canvas bag',
        ?string $nickname = 'Standard',
        int $minorAmount = 1600,
        array $images = ['https://example.test/canvas.jpg'],
    ): array {
        return [
            'priceId' => $priceId,
            'productId' => $productId,
            'name' => $name,
            'currency' => 'EUR',
            'minorAmount' => $minorAmount,
            'taxBehavior' => 'exclusive',
            'description' => null,
            'images' => $images,
            'nickname' => $nickname,
            'taxCode' => null,
        ];
    }
}
