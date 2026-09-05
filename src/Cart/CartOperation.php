<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart;

enum CartOperation: string
{
    case Read = 'read';
    case Add = 'add';
    case Update = 'update';
    case Remove = 'remove';
    case Clear = 'clear';
}
