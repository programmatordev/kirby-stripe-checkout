<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support;

use Kirby\Cms\App;
use PHPUnit\Framework\TestCase;

abstract class KirbyTestCase extends TestCase
{
    protected App $kirby;

    protected KirbyTestEnvironment $environment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->environment = KirbyTestEnvironment::start();
        $this->kirby = $this->environment->app();
    }

    protected function tearDown(): void
    {
        if (isset($this->environment) === true) {
            $this->environment->close();
        }

        parent::tearDown();
    }
}
