<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

enum CheckoutSource: string
{
    case Cart = 'cart';
    case Direct = 'direct';
}
