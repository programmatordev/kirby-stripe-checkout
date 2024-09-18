<?php

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Exception\InvalidConfigException;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Test\BaseTestCase;
use ProgrammatorDev\StripeCheckout\Test\MockStripeClient;
use Stripe\ApiRequestor;
use Stripe\Checkout\Session;

class StripeCheckoutTest extends BaseTestCase
{
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = [
            'stripePublicKey' => 'pk_test_abc123',
            'stripeSecretKey' => 'sk_test_abc123',
            'uiMode' => 'hosted',
            'returnUrl' => 'https://example.com/return',
            'successUrl' => 'https://example.com/success',
            'cancelUrl' => 'https://example.com/cancel',
        ];
    }

    public function testInvalidConfigOnCreateSession(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('No config provided. Set your config using StripeCheckout::setConfig($options).');

        StripeCheckout::createSession();
    }

    public function testCreateSession(): void
    {
        // set Stripe mock HTTP client
        ApiRequestor::setHttpClient(
            new MockStripeClient('{"object": "checkout.session"}')
        );

        StripeCheckout::setConfig($this->config);

        $this->assertInstanceOf(Session::class, StripeCheckout::createSession());
    }

    #[DataProvider('provideInvalidSetConfigData')]
    public function testInvalidSetConfig(array $invalidOptions, string $exceptionMessage): void
    {
        // replace with invalid options
        $options = array_merge($this->config, $invalidOptions);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($exceptionMessage);

        StripeCheckout::setConfig($options);
    }

    public static function provideInvalidSetConfigData(): \Generator
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
        yield 'success url' => [
            ['uiMode' => 'hosted', 'successUrl' => null],
            'successUrl and cancelUrl are required in "hosted" mode.'
        ];
        yield 'cancel url' => [
            ['uiMode' => 'hosted', 'cancelUrl' => null],
            'successUrl and cancelUrl are required in "hosted" mode.'
        ];
        yield 'return url' => [
            ['uiMode' => 'embedded', 'returnUrl' => null],
            'returnUrl is required in "embedded" mode.'
        ];
    }

    public function testSetConfig(): void
    {
        StripeCheckout::setConfig($this->config);

        $this->assertSame(StripeCheckout::getPublicKey(), $this->config['stripePublicKey']);
        $this->assertSame(StripeCheckout::getSecretKey(), $this->config['stripeSecretKey']);
        $this->assertSame(StripeCheckout::getUiMode(), $this->config['uiMode']);
        $this->assertSame(StripeCheckout::getReturnUrl(), $this->config['returnUrl']);
        $this->assertSame(StripeCheckout::getSuccessUrl(), $this->config['successUrl']);
        $this->assertSame(StripeCheckout::getCancelUrl(), $this->config['cancelUrl']);
    }
}
