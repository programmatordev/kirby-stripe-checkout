<?php

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Exception\CartIsEmptyException;
use ProgrammatorDev\StripeCheckout\Exception\StripeCheckoutUiModeIsInvalidException;
use ProgrammatorDev\StripeCheckout\StripeCheckout;

/**
 * @throws CartIsEmptyException
 */
function createStripeCheckout(array $options): StripeCheckout
{
    $cart = cart();

    // stop if cart is empty
    if (empty($cart->getItems())) {
        throw new CartIsEmptyException('Cart is empty.');
    }

    return new StripeCheckout($options, $cart);
}

return function(App $kirby) {
    return [
        // handle checkout request
        [
            'pattern' => 'stripe/checkout',
            'method' => 'GET',
            'action' => function() use ($kirby) {
                $options = $kirby->option('programmatordev.stripe-checkout');
                $stripeCheckout = createStripeCheckout($options);

                if ($stripeCheckout->getUiMode() !== StripeCheckout::UI_MODE_HOSTED) {
                    throw new StripeCheckoutUiModeIsInvalidException(
                        'This endpoint is reserved for Stripe Checkout in "hosted" mode.'
                    );
                }

                $checkoutSession = $stripeCheckout->createSession();

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
                $options = $kirby->option('programmatordev.stripe-checkout');
                $stripeCheckout = createStripeCheckout($options);

                if ($stripeCheckout->getUiMode() !== StripeCheckout::UI_MODE_EMBEDDED) {
                    throw new StripeCheckoutUiModeIsInvalidException(
                        'This endpoint is reserved for Stripe Checkout in "embedded" mode.'
                    );
                }

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
