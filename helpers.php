<?php

use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use ProgrammatorDev\StripeCheckout\Cart;
use ProgrammatorDev\StripeCheckout\StripeCheckout;

/**
 * @throws UnknownCurrencyException
 * @throws NumberFormatException
 * @throws RoundingNecessaryException
 */
function cart(array $options = []): Cart
{
    return Cart::instance($options);
}

function stripeCheckout(array $options = []): StripeCheckout
{
    return StripeCheckout::instance($options);
}
