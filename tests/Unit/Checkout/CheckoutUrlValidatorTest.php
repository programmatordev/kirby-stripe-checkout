<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Checkout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutUrlValidator;

final class CheckoutUrlValidatorTest extends TestCase
{
    #[DataProvider('destinations')]
    public function testDestinationPolicy(string $url, bool $liveMode, bool $valid): void
    {
        $this->assertSame($valid, CheckoutUrlValidator::isDestination($url, $liveMode));
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function destinations(): iterable
    {
        yield 'test HTTP' => ['http://store.example/checkout', false, true];
        yield 'live HTTPS' => ['https://store.example/checkout', true, true];
        yield 'live local HTTP' => ['http://store.ddev.site/checkout', true, true];
        yield 'live public HTTP' => ['http://store.example/checkout', true, false];
        yield 'credentials' => ['https://user:password@store.example/checkout', false, false];
        yield 'unsupported scheme' => ['javascript:alert(1)', false, false];
    }

    public function testPersistedAndPresentationPoliciesAreNarrower(): void
    {
        $this->assertTrue(CheckoutUrlValidator::isPersistedDestination('http://store.example/checkout', liveMode: false));
        $this->assertFalse(CheckoutUrlValidator::isPersistedDestination('http://store.example/checkout', liveMode: true));
        $this->assertFalse(CheckoutUrlValidator::isPersistedDestination('https://store.example/checkout#fragment', liveMode: false));
        $this->assertFalse(CheckoutUrlValidator::isPersistedDestination('https://store.example/checkout?_stripe_checkout_result=token', liveMode: false));
        $this->assertTrue(CheckoutUrlValidator::isHostedPresentation('https://checkout.stripe.com/c/pay/session'));
        $this->assertFalse(CheckoutUrlValidator::isHostedPresentation('http://checkout.stripe.com/c/pay/session'));
    }
}
