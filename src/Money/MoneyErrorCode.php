<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Money;

/** Stable money error codes for exceptions and boundary mappings. */
final class MoneyErrorCode
{
    public const AMOUNT_INEXACT = 'money.amount_inexact';

    public const AMOUNT_INVALID = 'money.amount_invalid';

    public const AMOUNT_NEGATIVE = 'money.amount_negative';

    public const AMOUNT_OVERFLOW = 'money.amount_overflow';

    public const CURRENCY_INVALID = 'money.currency_invalid';

    public const CURRENCY_REDUNDANT = 'money.currency_redundant';

    public const CURRENCY_REQUIRED = 'money.currency_required';

    public const CURRENCY_UNSUPPORTED = 'money.currency_unsupported';

    public const FORMAT_FAILED = 'money.format_failed';

    public const LOCALE_INVALID = 'money.locale_invalid';

    public const PROVIDER_AMOUNT_INVALID = 'money.provider_amount_invalid';
}
