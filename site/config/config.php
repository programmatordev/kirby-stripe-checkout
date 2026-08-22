<?php

$environment = static function(string $name, string $default): string {
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
};

return [
    'debug' => true,
    'cache' => false,
    'panel' => [
        'install' => true
    ],
    'programmatordev.stripe-checkout' => [
        'stripePublicKey' => $environment('KIRBY_STRIPE_CHECKOUT_PUBLIC_KEY', 'pk_test_replace_me'),
        'stripeSecretKey' => $environment('KIRBY_STRIPE_CHECKOUT_SECRET_KEY', 'sk_test_replace_me'),
        'stripeWebhookSecret' => $environment('KIRBY_STRIPE_CHECKOUT_WEBHOOK_SECRET', 'whsec_replace_me'),
        'uiMode' => 'hosted',
        'currency' => 'EUR',
        'successPage' => 'checkout-success',
        'cancelPage' => 'checkout-cancel',
        'returnPage' => 'checkout-return',
        'ordersPage' => 'stripe-orders',
        'settingsPage' => 'stripe-checkout-settings',
        'cartSnippet' => null
    ]
];
