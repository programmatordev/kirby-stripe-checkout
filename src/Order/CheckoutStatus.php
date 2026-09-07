<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order;

enum CheckoutStatus: string
{
    case Creating = 'creating';
    case CreationUncertain = 'creation_uncertain';
    case CreationFailed = 'creation_failed';
    case Open = 'open';
    case Complete = 'complete';
    case Expired = 'expired';
}
