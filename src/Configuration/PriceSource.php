<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

/**
 * Selects the store-wide authority for product prices.
 */
enum PriceSource: string
{
    case Kirby = 'kirby';
    case Stripe = 'stripe';
}
