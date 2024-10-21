<?php

use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Cart;
use ProgrammatorDev\StripeCheckout\StripeCheckout;

@include_once __DIR__ . '/vendor/autoload.php';

/**
 * @throws UnknownCurrencyException
 * @throws NumberFormatException
 * @throws RoundingNecessaryException
 */
function cart(array $options = []): Cart
{
    $options = array_merge(
        ['currency' => kirby()->option('programmatordev.stripe-checkout.currency')],
        $options
    );

    return new Cart($options);
}

function stripeCheckout(array $options = []): StripeCheckout
{
    $options = array_merge(
        kirby()->option('programmatordev.stripe-checkout'),
        $options
    );

    return new StripeCheckout($options);
}

App::plugin('programmatordev/stripe-checkout', [
    'options' => [
        'stripePublicKey' => null,
        'stripeSecretKey' => null,
        'stripeWebhookSecret' => null,
        'currency' => 'EUR',
        'uiMode' => 'hosted',
        'returnPage' => null,
        'successPage' => null,
        'cancelPage' => null,
        'ordersPage' => 'orders',
        'settingsPage' => 'checkout-settings'
    ],
    'blueprints' => [
        // fields
        'stripe.checkout.fields/price' => __DIR__ . '/blueprints/fields/price.yml',
        'stripe.checkout.fields/cover' => __DIR__ . '/blueprints/fields/cover.yml',
        'stripe.checkout.fields/options' => __DIR__ . '/blueprints/fields/options.yml',
        // sections
        'stripe.checkout.sections/orders' => __DIR__ . '/blueprints/sections/orders.yml',
        // pages
        'stripe.checkout.pages/product' => __DIR__ . '/blueprints/pages/product.yml',
        'stripe.checkout.pages/product-options' => __DIR__ . '/blueprints/pages/product-options.yml',
        'stripe.checkout.pages/orders' => __DIR__ . '/blueprints/pages/orders.yml',
        'stripe.checkout.pages/order' => __DIR__ . '/blueprints/pages/order.yml',
        'stripe.checkout.pages/checkout-settings' => __DIR__ . '/blueprints/pages/checkout-settings.yml',
    ],
    'siteMethods' => require __DIR__ . '/config/siteMethods.php',
    'routes' => require __DIR__ . '/config/routes.php',
    'api' => require __DIR__ . '/config/api.php',
]);
