<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Closure;
use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;

/**
 * @internal Coordinates the reproduced read/reduce/write race, not content storage.
 * Native file writes lock only the write itself; F::update() cannot bound its wait
 * or wrap Page persistence without writing the canonical file a second time.
 */
final class OrderWriteLock
{
    /** @var array<string, true> */
    private static array $held = [];

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public static function run(App $kirby, string $lockKey, Closure $operation): mixed
    {
        // Not a cache: deleting/recreating a locked file would give concurrent
        // writers different lock identities. Empty lock files remain in place.
        $directory = $kirby->root('site') . '/storage/stripe-checkout/order-locks';
        $path = $directory . '/' . hash('sha256', $lockKey) . '.lock';

        if (isset(self::$held[$path])) {
            throw new OrderStorageException('persistence.reentrant_write');
        }

        if (is_dir($directory) === false && Dir::make($directory) === false) {
            throw new OrderStorageException('persistence.write_failed');
        }

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            throw new OrderStorageException('persistence.write_failed');
        }

        try {
            $deadline = microtime(true) + 2;

            while (flock($handle, LOCK_EX | LOCK_NB) === false) {
                if (microtime(true) >= $deadline) {
                    throw new OrderStorageException('persistence.busy');
                }

                usleep(10000);
            }

            self::$held[$path] = true;

            return $operation();
        } finally {
            unset(self::$held[$path]);
            fclose($handle);
        }
    }
}
