<?php

use Kirby\Cms\App;

@include_once __DIR__ . '/vendor/autoload.php';

App::plugin('programmatordev/kirby-stripe-checkout', [
    'blueprints' => [
        // fields
        'stripe.checkout.fields/price' => __DIR__ . '/blueprints/fields/price.yml',
        'stripe.checkout.fields/tax' => __DIR__ . '/blueprints/fields/tax.yml',
        'stripe.checkout.fields/cover' => __DIR__ . '/blueprints/fields/cover.yml',
        'stripe.checkout.fields/options' => __DIR__ . '/blueprints/fields/options.yml',
        // pages
        'stripe.checkout.pages/product' => __DIR__ . '/blueprints/pages/product.yml',
        'stripe.checkout.pages/product-options' => __DIR__ . '/blueprints/pages/product-options.yml',
    ]
]);
