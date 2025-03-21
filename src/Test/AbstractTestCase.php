<?php

namespace ProgrammatorDev\StripeCheckout\Test;

use PHPUnit\Framework\TestCase;

class AbstractTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        kirby()->impersonate('kirby');
    }
}
