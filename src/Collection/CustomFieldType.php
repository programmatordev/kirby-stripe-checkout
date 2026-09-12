<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Collection;

/** Supported Stripe Checkout custom-field presentations. */
enum CustomFieldType: string
{
    case Text = 'text';
    case Numeric = 'numeric';
    case Dropdown = 'dropdown';
}
