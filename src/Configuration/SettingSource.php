<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

/**
 * Identifies the winning source of an effective public setting.
 */
enum SettingSource: string
{
    case InternalDefault = 'default';
    case Page = 'page';
    case Php = 'php';
}
