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
        /** @var array{value: string, selected: array{text: string}, catalogue: array{status: string}} $props */
        $props = $field->toArray();

        $this->assertInstanceOf(StripePriceField::class, $field);
        $this->assertSame('price_canvas', $field->toStoredValue());
        $this->assertSame('price_canvas', $props['value']);
        $this->assertSame('Canvas bag', $props['selected']['text']);
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

    public function testOptionsFieldReusesTheSameCatalogueEndpointForVariantPrices(): void
    {
        $page = $this->restartWithStripePriceField();
        $this->seedCatalogue();
        $options = Form::for($page)->fields()->field('variants')->toArray();
        /** @var array{data: list<array{id: string}>} $response */
        $response = $this->kirby->api()->call(
            'pages/product/fields/variants/prices',
            'GET',
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

    private function seedCatalogue(): void
    {
        $this->kirby->cache('programmatordev.stripe-checkout.prices')->set('catalogue-eur', [
            'error' => null,
            'failedAt' => null,
            'refreshedAt' => 1_700_000_000,
            'items' => [[
                'priceId' => 'price_canvas',
                'productId' => 'prod_canvas',
                'name' => 'Canvas bag',
                'currency' => 'EUR',
                'minorAmount' => 1600,
                'taxBehavior' => 'exclusive',
                'description' => null,
                'images' => [],
                'nickname' => 'Standard',
                'taxCode' => null,
            ]],
        ]);
    }
}
