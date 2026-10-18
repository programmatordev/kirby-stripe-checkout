<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Housekeeping\Internal;

use Closure;
use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use ProgrammatorDev\StripeCheckout\Housekeeping\Exception\HousekeepingException;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingErrorCode;

/** @internal Excludes overlapping CLI and Panel housekeeping invocations without waiting. */
final class HousekeepingRunLock
{
    private static bool $held = false;

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public static function run(App $kirby, Closure $operation): mixed
    {
        if (self::$held) {
            throw new HousekeepingException(HousekeepingErrorCode::LOCKED);
        }

        $directory = $kirby->root('site') . '/storage/stripe-checkout';

        if (is_dir($directory) === false && Dir::make($directory) === false) {
            throw new HousekeepingException(HousekeepingErrorCode::WRITE_FAILED);
        }

        // File existence is not run state. Keep one durable path so every process locks the same file identity;
        // only the operating-system flock is held for the invocation.
        $handle = @fopen($directory . '/housekeeping.lock', 'c');

        if ($handle === false) {
            throw new HousekeepingException(HousekeepingErrorCode::WRITE_FAILED);
        }

        try {
            if (flock($handle, LOCK_EX | LOCK_NB) === false) {
                throw new HousekeepingException(HousekeepingErrorCode::LOCKED);
            }

            self::$held = true;

            return $operation();
        } finally {
            self::$held = false;
            fclose($handle);
        }
    }
}
