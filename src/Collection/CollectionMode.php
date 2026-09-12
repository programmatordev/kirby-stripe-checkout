<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Collection;

/** Controls whether a supported customer detail is omitted, optional or required. */
enum CollectionMode: string
{
    case Off = 'off';
    case Optional = 'optional';
    case Required = 'required';
}
