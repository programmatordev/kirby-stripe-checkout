<?php

declare(strict_types=1);

return [
    'debug' => true,
    'cache' => false,
    // Development storefront fragment; the package ships no cart markup or JS.
    'programmatordev.stripe-checkout.cart.renderer' => static fn(
        ?ProgrammatorDev\StripeCheckout\Cart\Cart $cart,
        ProgrammatorDev\StripeCheckout\Cart\CartRenderContext $context,
    ): string => snippet('cart', ['cart' => $cart, 'context' => $context, 'site' => site()], true),
    'programmatordev.stripe-checkout.stripe.secretKey' => getenv('KIRBY_STRIPE_CHECKOUT_SECRET_KEY') ?: null,
    'programmatordev.stripe-checkout.stripe.publishableKey' => getenv('KIRBY_STRIPE_CHECKOUT_PUBLISHABLE_KEY') ?: null,
    'programmatordev.stripe-checkout.stripe.webhookSecret' => getenv('KIRBY_STRIPE_CHECKOUT_WEBHOOK_SECRET') ?: null,
];
