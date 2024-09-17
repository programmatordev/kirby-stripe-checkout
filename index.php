<?php

use Kirby\Cms\App;
use Kirby\Cms\Site;

@include_once __DIR__ . '/vendor/autoload.php';

App::plugin('programmatordev/kirby-stripe-checkout', [
    'options' => [
        'stripePublicKey' => null,
        'stripeSecretKey' => null,
        // available modes: "hosted" or "embedded"
        'uiMode' => 'hosted',
        // required if uiMode is "embedded"
        // page with Stripe Checkout embedded form
        'checkoutPage' => 'checkout',
        // required if uiMode is "embedded"
        // redirects to this page after payment is completed
        'returnUrl' => null,
        // required if uiMode is "hosted"
        // redirects to this page after payment is completed
        'successUrl' => null,
        // required if uiMode is "hosted"
        // redirects to this page if payment was cancelled
        'cancelUrl' => null,
    ],
    'blueprints' => [
        // fields
        'stripe.checkout.fields/price' => __DIR__ . '/blueprints/fields/price.yml',
        'stripe.checkout.fields/tax' => __DIR__ . '/blueprints/fields/tax.yml',
        'stripe.checkout.fields/cover' => __DIR__ . '/blueprints/fields/cover.yml',
        'stripe.checkout.fields/options' => __DIR__ . '/blueprints/fields/options.yml',
        // pages
        'stripe.checkout.pages/product' => __DIR__ . '/blueprints/pages/product.yml',
        'stripe.checkout.pages/product-options' => __DIR__ . '/blueprints/pages/product-options.yml',
        'stripe.checkout.pages/orders' => __DIR__ . '/blueprints/pages/orders.yml',
        'stripe.checkout.pages/order' => __DIR__ . '/blueprints/pages/order.yml',
    ],
    'snippets' => [],
    'siteMethods' => [
        'checkoutUrl' => function () {
            /** @var Site $this */
            return sprintf('%s/%s', $this->url(), 'checkout');
        },
        'checkoutClientSecretUrl' => function () {
            /** @var Site $this */
            return sprintf('%s/%s', $this->url(), 'checkout/embedded');
        }
    ],
    'routes' => require __DIR__ . '/config/routes.php',
]);
