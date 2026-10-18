<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Housekeeping\Internal;

use Kirby\Cms\App;
use Kirby\Data\Json;
use Kirby\Filesystem\F;
use ProgrammatorDev\StripeCheckout\Housekeeping\Exception\HousekeepingException;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingErrorCode;
use Throwable;

/** @internal Persists bounded continuation and sanitized outcomes outside Order content. */
final readonly class HousekeepingStateStore
{
    private string $path;

    public function __construct(App $kirby)
    {
        $this->path = $kirby->root('site') . '/storage/stripe-checkout/housekeeping.json';
    }

    public function read(): HousekeepingState
    {
        if (is_file($this->path) === false) {
            return new HousekeepingState();
        }

        try {
            $handle = @fopen($this->path, 'r');

            if ($handle === false) {
                throw new HousekeepingException(HousekeepingErrorCode::STATE_INVALID);
            }

            try {
                // F::update() holds an exclusive lock while rewriting; this prevents observing a partial document.
                if (flock($handle, LOCK_SH) === false) {
                    throw new HousekeepingException(HousekeepingErrorCode::STATE_INVALID);
                }

                $contents = stream_get_contents($handle);

                if ($contents === false) {
                    throw new HousekeepingException(HousekeepingErrorCode::STATE_INVALID);
                }
            } finally {
                fclose($handle);
            }

            return HousekeepingState::fromArray(Json::decode($contents));
        } catch (HousekeepingException $error) {
            throw $error;
        } catch (Throwable) {
            throw new HousekeepingException(HousekeepingErrorCode::STATE_INVALID);
        }
    }

    public function write(HousekeepingState $state): void
    {
        try {
            $contents = Json::encode($state->toArray(), true);

            if (F::update($this->path, static fn(string $_current): string => $contents) === false) {
                throw new HousekeepingException(HousekeepingErrorCode::WRITE_FAILED);
            }
        } catch (HousekeepingException $error) {
            throw $error;
        } catch (Throwable) {
            throw new HousekeepingException(HousekeepingErrorCode::WRITE_FAILED);
        }
    }
}
