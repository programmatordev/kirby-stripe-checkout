<?php

declare(strict_types=1);

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Content\Field;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Kirby\OptionsField;
use ProgrammatorDev\StripeCheckout\Kirby\ProductBlueprint;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsBlueprint;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPage;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Panel\StripeCheckoutArea;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\ProductOptions;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;
use ProgrammatorDev\StripeCheckout\Translation\Registration;

App::plugin(
    name: 'programmatordev/stripe-checkout',
    extends: [
        // Business defaults stay in the resolver so explicit project values
        // remain distinguishable from plugin defaults.
        'options' => [],
        'blueprints' => [
            'pages/stripe-checkout' => [SettingsBlueprint::class, 'load'],
            'fields/stripe-checkout/name' => [ProductBlueprint::class, 'name'],
            'fields/stripe-checkout/price' => [ProductBlueprint::class, 'price'],
            'fields/stripe-checkout/stripe-price' => [ProductBlueprint::class, 'stripePrice'],
            'fields/stripe-checkout/description' => [ProductBlueprint::class, 'description'],
            'fields/stripe-checkout/images' => [ProductBlueprint::class, 'images'],
            'fields/stripe-checkout/sku' => [ProductBlueprint::class, 'sku'],
            'fields/stripe-checkout/requires-shipping' => [ProductBlueprint::class, 'requiresShipping'],
            'fields/stripe-checkout/options' => [ProductBlueprint::class, 'options'],
        ],
        'pageModels' => [
            'stripe-checkout' => StripeCheckoutPage::class,
        ],
        'fields' => [
            'stripe-checkout-options' => OptionsField::class,
        ],
        'fieldMethods' => [
            'toProductOptions' => static function (Field $field): ProductOptions {
                $page = $field->parent();

                if ($page instanceof Page === false) {
                    throw new InvalidProductException('product.field_invalid');
                }

                return (new RuntimeFactory($page->kirby()))->productOptionsFromField($field);
            },
        ],
        'translations' => Catalogue::bundled(),
        'permissions' => [
            'settings.read' => false,
            'settings.update' => false,
            'diagnostics.read' => false,
        ],
        'areas' => [
            'stripe-checkout' => [StripeCheckoutArea::class, 'definition'],
        ],
        'hooks' => [
            'system.loadPlugins:after' => function (): void {
                // Kirby binds plugin hooks to the active App instance.
                // @phpstan-ignore variable.undefined, argument.type
                Registration::applyProjectOverrides($this);

                try {
                    // Composer cannot create site content while installing the
                    // package, so initialize it on the first Kirby boot instead.
                    // @phpstan-ignore variable.undefined, argument.type
                    (new StripeCheckoutPageStore($this))->initialize();
                } catch (ConfigurationException) {
                    // Storage problems must remain recoverable through the
                    // Panel Settings error view and local diagnostics.
                }
            },
        ],
        'siteMethods' => require __DIR__ . '/config/site-methods.php',
    ],
    version: '0.7.0',
);
