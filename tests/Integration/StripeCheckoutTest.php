<?php

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Exception\InvalidOptionException;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Test\BaseTestCase;
use ProgrammatorDev\StripeCheckout\Test\MockStripeClient;
use Stripe\ApiRequestor;
use Stripe\Checkout\Session;

class StripeCheckoutTest extends BaseTestCase
{
    private array $options;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [
            'stripePublicKey' => 'pk_test_abc123',
            'stripeSecretKey' => 'sk_test_abc123',
            'uiMode' => 'hosted',
            'returnUrl' => 'https://example.com/return',
            'successUrl' => 'https://example.com/success',
            'cancelUrl' => 'https://example.com/cancel',
        ];
    }

    public function testInvalidOptionsOnCreateSession(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('No options provided. Set your options using StripeCheckout::setOptions($options).');

        StripeCheckout::createSession();
    }

    public function testCreateSession(): void
    {
        // set Stripe mock HTTP client
        ApiRequestor::setHttpClient(
            new MockStripeClient('{"object": "checkout.session"}')
        );

        StripeCheckout::setOptions($this->options);

        $this->assertInstanceOf(Session::class, StripeCheckout::createSession());
    }

    #[DataProvider('provideInvalidSetOptionsData')]
    public function testInvalidSetOptions(array $invalidOptions, string $exceptionMessage): void
    {
        // replace with invalid options
        $options = array_merge($this->options, $invalidOptions);

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage($exceptionMessage);

        StripeCheckout::setOptions($options);
    }

    public static function provideInvalidSetOptionsData(): \Generator
    {
        yield 'stripe public key' => [
            ['stripePublicKey' => null],
            'stripePublicKey and stripeSecretKey are required.'
        ];
        yield 'stripe secret key' => [
            ['stripeSecretKey' => null],
            'stripePublicKey and stripeSecretKey are required.'
        ];
        yield 'ui mode' => [
            ['uiMode' => 'invalid'],
            'uiMode is invalid. Accepted values are: "hosted" or "embedded".'
        ];
        yield 'return url' => [
            ['uiMode' => 'embedded', 'returnUrl' => null],
            'returnUrl is required in "embedded" mode.'
        ];
        yield 'success url' => [
            ['uiMode' => 'hosted', 'successUrl' => null],
            'successUrl and cancelUrl are required in "hosted" mode.'
        ];
        yield 'cancel url' => [
            ['uiMode' => 'hosted', 'cancelUrl' => null],
            'successUrl and cancelUrl are required in "hosted" mode.'
        ];
    }

    public function testSetOptions(): void
    {
        StripeCheckout::setOptions($this->options);

        $this->assertSame(StripeCheckout::getPublicKey(), $this->options['stripePublicKey']);
        $this->assertSame(StripeCheckout::getSecretKey(), $this->options['stripeSecretKey']);
        $this->assertSame(StripeCheckout::getUiMode(), $this->options['uiMode']);
        $this->assertSame(StripeCheckout::getReturnUrl(), $this->options['returnUrl']);
        $this->assertSame(StripeCheckout::getSuccessUrl(), $this->options['successUrl']);
        $this->assertSame(StripeCheckout::getCancelUrl(), $this->options['cancelUrl']);
    }
}
