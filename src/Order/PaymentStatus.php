<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case Paid = 'paid';
    case NoPaymentRequired = 'no_payment_required';
    case Failed = 'failed';
}
