<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

/**
 * Represents only the non-sensitive mode detectable from a Stripe key.
 *
 * @internal
 */
enum CredentialMode: string
{
    case Test = 'test';
    case Live = 'live';
    case Unknown = 'unknown';
}
