<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Housekeeping;

use Kirby\CLI\CLI;
use Kirby\CLI\Command;
use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Housekeeping\Exception\HousekeepingException;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use Throwable;

/** Runs one bounded housekeeping invocation from Kirby CLI. */
final class HousekeepingCommand extends Command
{
    private const EXIT_CONFIGURATION = 4;
    private const EXIT_FAILED = 1;
    private const EXIT_LOCKED = 3;
    private const EXIT_PARTIAL_FAILURE = 2;
    private const EXIT_SUCCESS = 0;

    public static function command(CLI $cli): void
    {
        $exitCode = self::execute($cli);

        if ($exitCode !== self::EXIT_SUCCESS) {
            exit($exitCode);
        }
    }

    public static function description(): string
    {
        return 'Run one bounded Stripe Checkout housekeeping pass';
    }

    /** @internal Separated from process exit so output and status remain directly testable. */
    public static function execute(CLI $cli): int
    {
        try {
            /** @var App $kirby Kirby CLI throws before returning without an application. */
            $kirby = $cli->kirby();
            $outcome = (new RuntimeFactory($kirby))->housekeepingRunner()->run();
        } catch (ConfigurationException $error) {
            self::writeFailure($cli, $error->errorCode());

            return self::EXIT_CONFIGURATION;
        } catch (HousekeepingException $error) {
            self::writeFailure($cli, $error->errorCode());

            return $error->errorCode() === HousekeepingErrorCode::LOCKED
                ? self::EXIT_LOCKED
                : self::EXIT_FAILED;
        } catch (Throwable $error) {
            // Normal output exposes only stable operator-facing codes; debug mode preserves the underlying diagnostic.
            if ($cli->isDefined('debug')) {
                throw $error;
            }

            self::writeFailure($cli, HousekeepingErrorCode::RUN_FAILED);

            return self::EXIT_FAILED;
        }

        $cli->climate()->out($cli->json($outcome->toArray()));

        return match ($outcome->status()) {
            HousekeepingRunStatus::Success => self::EXIT_SUCCESS,
            HousekeepingRunStatus::PartialFailure => self::EXIT_PARTIAL_FAILURE,
            HousekeepingRunStatus::Failed => self::EXIT_FAILED,
        };
    }

    private static function writeFailure(CLI $cli, string $errorCode): void
    {
        $cli->climate()->out($cli->json([
            'status' => HousekeepingRunStatus::Failed->value,
            'errorCode' => $errorCode,
        ]));
    }
}
