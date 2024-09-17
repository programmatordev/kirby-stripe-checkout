<?php

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Exception\InvalidOptionException;
use ProgrammatorDev\StripeCheckout\StripeCheckout;

return function(App $kirby) {
    return [
        [
            'pattern' => 'checkout',
            'action' => function () use ($kirby) {
                $options = $kirby->option('programmatordev.kirby-stripe-checkout');

                StripeCheckout::setOptions($options);

                // if "embedded" show checkout page
                // no need to proceed more
                if (StripeCheckout::getUiMode() === StripeCheckout::UI_MODE_EMBEDDED) {
                    $checkoutPage = $options['checkoutPage'];

                    if (($page = page($checkoutPage)) === null) {
                        throw new InvalidOptionException('checkoutPage is invalid or does not exist.');
                    }

                    return $page->render([
                        'stripePublicKey' => StripeCheckout::getPublicKey(),
                    ]);
                }

                $checkoutSession = StripeCheckout::createSession();

                // redirect to hosted payment form
                // https://docs.stripe.com/checkout/quickstart#redirect
                go($checkoutSession->url);
            }
        ],
        [
            'pattern' => 'checkout/embedded',
            'action' => function () use ($kirby) {
                $options = $kirby->option('programmatordev.kirby-stripe-checkout');

                StripeCheckout::setOptions($options);

                $checkoutSession = StripeCheckout::createSession();

                // return JSON with required data for embedded checkout
                // https://docs.stripe.com/checkout/embedded/quickstart#fetch-checkout-session
                return [
                    'clientSecret' => $checkoutSession->client_secret
                ];
            },
            'method' => 'POST'
        ]
    ];
};
