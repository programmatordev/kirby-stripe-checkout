<?php

use Kirby\Cms\App;

@include_once __DIR__ . '/vendor/autoload.php';
@include_once __DIR__ . '/helpers.php';

App::plugin('programmatordev/stripe-checkout', [
    'options' => [
        'stripePublicKey' => null,
        'stripeSecretKey' => null,
        'stripeWebhookSecret' => null,
        'uiMode' => 'hosted',
        'currency' => 'EUR',
        'returnPage' => null,
        'successPage' => null,
        'cancelPage' => null,
        'ordersPage' => 'orders',
        'settingsPage' => 'checkout-settings',
        'cartSnippet' => null
    ],
    'blueprints' => require __DIR__ . '/config/blueprints.php',
    'translations' => require __DIR__ . '/config/translations.php',
    'siteMethods' => require __DIR__ . '/config/siteMethods.php',
    'routes' => require __DIR__ . '/config/routes.php',
    'api' => require __DIR__ . '/config/api.php',
]);
