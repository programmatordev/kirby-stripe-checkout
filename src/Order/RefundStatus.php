<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order;

enum RefundStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Partial = 'partial';
    case Full = 'full';
    case Failed = 'failed';
}
