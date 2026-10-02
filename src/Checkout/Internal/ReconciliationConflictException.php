<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use RuntimeException;

/** @internal Another writer changed commerce facts during the provider read; fetch current facts again. */
final class ReconciliationConflictException extends RuntimeException {}
