<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Housekeeping\Internal;

use Kirby\Cms\App;
use Kirby\Data\Json;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use ProgrammatorDev\StripeCheckout\Housekeeping\Exception\HousekeepingException;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingErrorCode;
use Throwable;

/** @internal Persists batch continuation and sanitized outcomes outside Order content. */
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
            $contents = F::read($this->path);

            if ($contents === false) {
                throw new HousekeepingException(HousekeepingErrorCode::STATE_INVALID);
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
            $this->replace($contents);
        } catch (HousekeepingException $error) {
            throw $error;
        } catch (Throwable) {
            throw new HousekeepingException(HousekeepingErrorCode::WRITE_FAILED);
        }
    }

    private function replace(string $contents): void
    {
        $directory = dirname($this->path);

        if (is_dir($directory) === false && Dir::make($directory) === false) {
            throw new HousekeepingException(HousekeepingErrorCode::WRITE_FAILED);
        }

        $temporaryPath = $this->path . '.tmp-' . bin2hex(random_bytes(8));
        $handle = @fopen($temporaryPath, 'xb');

        if ($handle === false) {
            throw new HousekeepingException(HousekeepingErrorCode::WRITE_FAILED);
        }

        try {
            $length = strlen($contents);
            $written = 0;

            while ($written < $length) {
                $bytes = fwrite($handle, substr($contents, $written));

                if ($bytes === false || $bytes === 0) {
                    throw new HousekeepingException(HousekeepingErrorCode::WRITE_FAILED);
                }

                $written += $bytes;
            }

            if (fflush($handle) === false || fsync($handle) === false) {
                throw new HousekeepingException(HousekeepingErrorCode::WRITE_FAILED);
            }

            if (fclose($handle) === false) {
                throw new HousekeepingException(HousekeepingErrorCode::WRITE_FAILED);
            }

            $handle = null;

            // The completed file is on the same filesystem, so readers see either the previous state or this replacement.
            if (@rename($temporaryPath, $this->path) === false) {
                throw new HousekeepingException(HousekeepingErrorCode::WRITE_FAILED);
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }
}
