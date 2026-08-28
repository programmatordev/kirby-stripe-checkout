<?php

declare(strict_types=1);

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsBlueprint;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsPage;
use ProgrammatorDev\StripeCheckout\Panel\StripeCheckoutArea;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;
use ProgrammatorDev\StripeCheckout\Translation\Registration;

App::plugin(
    name: 'programmatordev/stripe-checkout',
    extends: [
        // Business defaults stay in the resolver so explicit project values
        // remain distinguishable from plugin defaults.
        'options' => [],
        'blueprints' => [
            'pages/stripe-checkout-settings' => [SettingsBlueprint::class, 'load'],
        ],
        'pageModels' => [
            'stripe-checkout-settings' => SettingsPage::class,
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
            },
        ],
        'siteMethods' => require __DIR__ . '/config/site-methods.php',
    ],
    version: '0.7.0',
);
