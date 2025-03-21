<?php

namespace ProgrammatorDev\StripeCheckout\Test;

use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\ErrorHandler;

class AbstractTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        kirby()->impersonate('kirby');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->flushHandlerState();
    }

    /**
     * Flush the bootstrapper global handlers state.
     * https://github.com/symfony/symfony/issues/53812#issuecomment-1958859357
     */
    private function flushHandlerState(): void
    {
        while (true) {
            $previousHandler = set_exception_handler(static fn() => null);

            restore_exception_handler();

            if ($previousHandler === null) {
                break;
            }

            restore_exception_handler();
        }

        while (true) {
            $previousHandler = set_error_handler(static fn() => null);

            restore_error_handler();

            if ($previousHandler === null) {
                break;
            }

            restore_error_handler();
        }

        if (class_exists(ErrorHandler::class)) {
            $instance = ErrorHandler::instance();

            if ((fn() => $this->enabled ?? false)->call($instance)) {
                $instance->disable();
                $instance->enable();
            }
        }
    }
}
