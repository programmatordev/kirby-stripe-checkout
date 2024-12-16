<?php

use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use Kirby\Cms\App;
use Kirby\Data\Yaml;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Toolkit\A;
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
        'uiMode' => 'hosted',
        'currency' => 'EUR',
        'returnPage' => null,
        'successPage' => null,
        'cancelPage' => null,
        'ordersPage' => 'orders',
        'settingsPage' => 'checkout-settings'
    ],
    'blueprints' => [
        // fields
        'stripe-checkout.fields/price' => __DIR__ . '/blueprints/fields/price.yml',
        'stripe-checkout.fields/cover' => __DIR__ . '/blueprints/fields/cover.yml',
        // sections
        'stripe-checkout.sections/orders' => __DIR__ . '/blueprints/sections/orders.yml',
        // pages
        'stripe-checkout.pages/orders' => __DIR__ . '/blueprints/pages/orders.yml',
        'stripe-checkout.pages/order' => __DIR__ . '/blueprints/pages/order.yml',
        'stripe-checkout.pages/checkout-settings' => __DIR__ . '/blueprints/pages/checkout-settings.yml',
    ],
    // get all files from /translations and register them as language files
    // shameless copy from https://github.com/tobimori/kirby-dreamform
    'translations' => A::keyBy(
        A::map(
            Dir::read(__DIR__ . '/translations'),
            function ($file): array
            {
                $translations = [];

                foreach (Yaml::read(__DIR__ . '/translations/' . $file) as $key => $value) {
                    $translations['stripe-checkout.' . $key] = $value;
                }

                return A::merge(
                    ['lang' => F::name($file)],
                    $translations
                );
            }
        ),
        'lang'
    ),
    'siteMethods' => require __DIR__ . '/config/siteMethods.php',
    'routes' => require __DIR__ . '/config/routes.php',
    'api' => require __DIR__ . '/config/api.php',
]);
