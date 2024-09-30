<?php

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Exception\StripeCheckoutUiModeIsInvalidException;
use ProgrammatorDev\StripeCheckout\StripeCheckout;

return function(App $kirby) {
    return [
        // handle checkout request
        [
            'pattern' => 'stripe/checkout',
            'method' => 'GET',
            'action' => function() use ($kirby) {
                $stripeCheckout = stripeCheckout();

                if ($stripeCheckout->getUiMode() !== StripeCheckout::UI_MODE_HOSTED) {
                    throw new StripeCheckoutUiModeIsInvalidException(
                        'This endpoint is reserved for Stripe Checkout in "hosted" mode.'
                    );
                }

                $cart = cart();
                $checkoutSession = $stripeCheckout->createSession($cart);

                // redirect to hosted payment form
                // https://docs.stripe.com/checkout/quickstart#redirect
                go($checkoutSession->url);
            }
        ],
        // get checkout client secret when in "embedded" mode
        [
            'pattern' => 'stripe/checkout/embedded',
            'method' => 'POST',
            'action' => function() use ($kirby) {
                $stripeCheckout = stripeCheckout();

                if ($stripeCheckout->getUiMode() !== StripeCheckout::UI_MODE_EMBEDDED) {
                    throw new StripeCheckoutUiModeIsInvalidException(
                        'This endpoint is reserved for Stripe Checkout in "embedded" mode.'
                    );
                }

                $cart = cart();
                $checkoutSession = $stripeCheckout->createSession($cart);

                // return JSON with required data for embedded checkout
                // https://docs.stripe.com/checkout/embedded/quickstart#fetch-checkout-session
                return [
                    'clientSecret' => $checkoutSession->client_secret
                ];
            }
        ]
    ];
};
