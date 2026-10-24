<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Diagnostics;

enum DiagnosticStatus: string
{
    case Fail = 'fail';
    case NotVerifiable = 'not_verifiable';
    case Pass = 'pass';
    case Warning = 'warning';
}
