<?php

declare(strict_types=1);

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsPage;

App::plugin(
    name: 'programmatordev/stripe-checkout',
    extends: [
        // Business defaults stay in the resolver so explicit project values
        // remain distinguishable from plugin defaults.
        'options' => [],
        'blueprints' => [
            'pages/stripe-checkout-settings' => __DIR__ . '/blueprints/pages/stripe-checkout-settings.yml',
        ],
        'pageModels' => [
            'stripe-checkout-settings' => SettingsPage::class,
        ],
        'siteMethods' => require __DIR__ . '/config/site-methods.php',
    ],
    version: '0.7.0',
);
