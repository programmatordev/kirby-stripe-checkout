<?php

use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\StripeCheckout;

function cart(array $options = []): Cart
{
    return Cart::instance($options);
}

function stripeCheckout(array $options = []): StripeCheckout
{
    return StripeCheckout::instance($options);
}
