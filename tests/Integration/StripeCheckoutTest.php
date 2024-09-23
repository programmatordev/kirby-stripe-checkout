<?php

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Test\BaseTestCase;
use ProgrammatorDev\StripeCheckout\Test\MockStripeClient;
use Stripe\ApiRequestor;
use Stripe\Checkout\Session;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;

class StripeCheckoutTest extends BaseTestCase
{
    private array $options;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [
            'hosted' => [
                'stripePublicKey' => 'pk_test_abc123',
                'stripeSecretKey' => 'sk_test_abc123',
                'uiMode' => 'hosted',
                'successUrl' => 'https://example.com/success',
                'cancelUrl' => 'https://example.com/cancel',
            ],
            'embedded' => [
                'stripePublicKey' => 'pk_test_abc123',
                'stripeSecretKey' => 'sk_test_abc123',
                'uiMode' => 'embedded',
                'checkoutPage' => 'checkout',
                'returnUrl' => 'https://example.com/return',
            ]
        ];
    }

    #[DataProvider('provideInitializeWithMissingOptionsData')]
    public function testInitializeWithMissingOptions(string $uiMode, string $missingOption): void
    {
        $options = $this->options[$uiMode];

        unset($options[$missingOption]);

        $this->expectException(MissingOptionsException::class);

        new StripeCheckout($options);
    }

    public static function provideInitializeWithMissingOptionsData(): \Generator
    {
        // hosted
        yield 'hosted missing stripePublicKey' => ['hosted', 'stripePublicKey'];
        yield 'hosted missing stripeSecretKey' => ['hosted', 'stripeSecretKey'];
        yield 'hosted missing uiMode' => ['hosted', 'uiMode'];
        yield 'hosted missing successUrl' => ['hosted', 'successUrl'];
        yield 'hosted missing cancelUrl' => ['hosted', 'cancelUrl'];
        // embedded
        yield 'embedded missing stripePublicKey' => ['embedded', 'stripePublicKey'];
        yield 'embedded missing stripeSecretKey' => ['embedded', 'stripeSecretKey'];
        yield 'embedded missing uiMode' => ['embedded', 'uiMode'];
        yield 'embedded missing checkoutPage' => ['embedded', 'checkoutPage'];
        yield 'embedded missing returnUrl' => ['embedded', 'returnUrl'];
    }

    #[DataProvider('provideInitializeWithInvalidOptionsData')]
    public function testInitializeWithInvalidOptions(string $uiMode, string $optionName, mixed $invalidValue): void
    {
        $options = $this->options[$uiMode];

        $options[$optionName] = $invalidValue;

        $this->expectException(InvalidOptionsException::class);

        new StripeCheckout($options);
    }

    public static function provideInitializeWithInvalidOptionsData(): \Generator
    {
        // hosted
        yield 'hosted invalid stripePublicKey' => ['hosted', 'stripePublicKey', 1];
        yield 'hosted empty stripePublicKey' => ['hosted', 'stripePublicKey', ''];
        yield 'hosted invalid stripeSecretKey' => ['hosted', 'stripeSecretKey', 1];
        yield 'hosted empty stripeSecretKey' => ['hosted', 'stripeSecretKey', ''];
        yield 'hosted invalid uiMode' => ['hosted', 'uiMode', 'invalid'];
        yield 'hosted invalid type successUrl' => ['hosted', 'successUrl', 1];
        yield 'hosted invalid url successUrl' => ['hosted', 'successUrl', 'invalid'];
        yield 'hosted empty successUrl' => ['hosted', 'successUrl', ''];
        yield 'hosted invalid type cancelUrl' => ['hosted', 'cancelUrl', 1];
        yield 'hosted invalid url cancelUrl' => ['hosted', 'cancelUrl', 'invalid'];
        yield 'hosted empty cancelUrl' => ['hosted', 'cancelUrl', ''];
        // embedded
        yield 'embedded invalid stripePublicKey' => ['embedded', 'stripePublicKey', 1];
        yield 'embedded empty stripePublicKey' => ['embedded', 'stripePublicKey', ''];
        yield 'embedded invalid stripeSecretKey' => ['embedded', 'stripeSecretKey', 1];
        yield 'embedded empty stripeSecretKey' => ['embedded', 'stripeSecretKey', ''];
        yield 'embedded invalid uiMode' => ['embedded', 'uiMode', 'invalid'];
        yield 'embedded invalid checkoutPage' => ['embedded', 'checkoutPage', 1];
        yield 'embedded empty checkoutPage' => ['embedded', 'checkoutPage', ''];
        yield 'embedded invalid type returnUrl' => ['embedded', 'returnUrl', 1];
        yield 'embedded invalid url returnUrl' => ['embedded', 'returnUrl', 'invalid'];
        yield 'embedded empty returnUrl' => ['embedded', 'returnUrl', ''];
    }

    #[DataProvider('provideCreateSessionData')]
    public function testCreateSession(string $uiMode): void
    {
        // set Stripe mock HTTP client
        ApiRequestor::setHttpClient(
            new MockStripeClient('{"object": "checkout.session"}')
        );

        $stripeCheckout = new StripeCheckout($this->options[$uiMode]);
        $this->assertInstanceOf(Session::class, $stripeCheckout->createSession());
    }

    public static function provideCreateSessionData(): \Generator
    {
        yield 'hosted' => ['hosted'];
        yield 'embedded' => ['embedded'];
    }

    #[DataProvider('provideGettersData')]
    public function testGetters(string $uiMode): void
    {
        $options = $this->options[$uiMode];
        $stripeCheckout = new StripeCheckout($options);

        $this->assertEquals($stripeCheckout->getOptions(), $options);

        $this->assertSame($stripeCheckout->getStripePublicKey(), $options['stripePublicKey']);
        $this->assertSame($stripeCheckout->getStripeSecretKey(), $options['stripeSecretKey']);
        $this->assertSame($stripeCheckout->getUiMode(), $options['uiMode']);

        switch ($uiMode) {
            case 'hosted':
                $this->assertSame($stripeCheckout->getCheckoutPage(), null);
                $this->assertSame($stripeCheckout->getReturnUrl(), null);
                $this->assertSame($stripeCheckout->getSuccessUrl(), $options['successUrl']);
                $this->assertSame($stripeCheckout->getCancelUrl(), $options['cancelUrl']);

                break;
            case 'embedded':
                $this->assertSame($stripeCheckout->getCheckoutPage(), $options['checkoutPage']);
                $this->assertSame($stripeCheckout->getReturnUrl(), $options['returnUrl']);
                $this->assertSame($stripeCheckout->getSuccessUrl(), null);
                $this->assertSame($stripeCheckout->getCancelUrl(), null);

                breaK;
        }
    }

    public static function provideGettersData(): \Generator
    {
        yield 'hosted' => ['hosted'];
        yield 'embedded' => ['embedded'];
    }
}
