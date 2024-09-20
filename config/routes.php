<?php

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Exception\PageDoesNotExistException;
use ProgrammatorDev\StripeCheckout\StripeCheckout;

return function(App $kirby) {
    return [
        // handle checkout request
        [
            'pattern' => 'checkout',
            'method' => 'GET',
            'action' => function() use ($kirby) {
                $options = $kirby->option('programmatordev.stripe-checkout');

                $stripeCheckout = new StripeCheckout($options);

                // if "embedded", show checkout page (no need to proceed more)
                if ($stripeCheckout->getUiMode() === StripeCheckout::UI_MODE_EMBEDDED) {
                    if (($page = page($stripeCheckout->getCheckoutPage())) === null) {
                        throw new PageDoesNotExistException('checkoutPage does not exist.');
                    }

                    return $page->render([
                        'stripePublicKey' => $stripeCheckout->getStripePublicKey(),
                    ]);
                }

                // if we reached here, we are in "hosted" mode
                $checkoutSession = $stripeCheckout->createSession();

                // redirect to hosted payment form
                // https://docs.stripe.com/checkout/quickstart#redirect
                go($checkoutSession->url);
            }
        ],
        // get checkout client secret when in "embedded" mode
        [
            'pattern' => 'checkout/embedded',
            'method' => 'POST',
            'action' => function() use ($kirby) {
                $options = $kirby->option('programmatordev.stripe-checkout');

                $stripeCheckout = new StripeCheckout($options);

                $checkoutSession = $stripeCheckout->createSession();

                // return JSON with required data for embedded checkout
                // https://docs.stripe.com/checkout/embedded/quickstart#fetch-checkout-session
                return [
                    'clientSecret' => $checkoutSession->client_secret
                ];
            }
        ]
    ];
};
