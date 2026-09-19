<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart;

enum CartOperation: string
{
    case Read = 'read';
    case AddItem = 'add_item';
    case UpdateItem = 'update_item';
    case UpdateShippingCountry = 'update_shipping_country';
    case RemoveItem = 'remove_item';
    case Clear = 'clear';
}
