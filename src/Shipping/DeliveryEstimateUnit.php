<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping;

/** Units accepted by Stripe for a Shipping Rate delivery estimate. */
enum DeliveryEstimateUnit: string
{
    case BusinessDay = 'business_day';
    case Day = 'day';
    case Hour = 'hour';
    case Week = 'week';
    case Month = 'month';
}
