<?php

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Cart;

@include_once __DIR__ . '/vendor/autoload.php';

function cart(): Cart
{
    return new Cart();
}

App::plugin('programmatordev/stripe-checkout', [
    'options' => [
        'stripePublicKey' => null,
        'stripeSecretKey' => null,
        'stripeWebhookSecret' => null,
        'currency' => 'eur',
        'uiMode' => 'hosted',
        'returnUrl' => null,
        'successUrl' => null,
        'cancelUrl' => null
    ],
    'blueprints' => [
        // fields
        'stripe.checkout.fields/price' => __DIR__ . '/blueprints/fields/price.yml',
        'stripe.checkout.fields/cover' => __DIR__ . '/blueprints/fields/cover.yml',
        'stripe.checkout.fields/options' => __DIR__ . '/blueprints/fields/options.yml',
        // pages
        'stripe.checkout.pages/product' => __DIR__ . '/blueprints/pages/product.yml',
        'stripe.checkout.pages/product-options' => __DIR__ . '/blueprints/pages/product-options.yml',
        'stripe.checkout.pages/orders' => __DIR__ . '/blueprints/pages/orders.yml',
        'stripe.checkout.pages/order' => __DIR__ . '/blueprints/pages/order.yml',
    ],
    'siteMethods' => require __DIR__ . '/config/siteMethods.php',
    'routes' => require __DIR__ . '/config/routes.php',
    'api' => require __DIR__ . '/config/api.php',
]);
