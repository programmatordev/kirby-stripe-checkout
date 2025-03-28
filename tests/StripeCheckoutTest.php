<?php

namespace ProgrammatorDev\StripeCheckout\Test;

use Kirby\Cms\Page;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Exception\EmptyCartException;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use Stripe\ApiRequestor;
use Stripe\Checkout\Session;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;

class StripeCheckoutTest extends AbstractTestCase
{
    private array $options;

    private Cart $cart;

    private Page $testPage;

    private Page $productPage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [
            'hosted' => [
                'stripePublicKey' => 'pk_test_abc123',
                'stripeSecretKey' => 'sk_test_abc123',
                'stripeWebhookSecret' => 'whsec_abc123',
                'currency' => 'EUR',
                'uiMode' => 'hosted',
                'successPage' => 'test-page',
                'cancelPage' => 'test-page',
                'returnPage' => null,
                'ordersPage' => 'orders',
                'settingsPage' => 'checkout-settings'
            ],
            'embedded' => [
                'stripePublicKey' => 'pk_test_abc123',
                'stripeSecretKey' => 'sk_test_abc123',
                'stripeWebhookSecret' => 'whsec_abc123',
                'currency' => 'EUR',
                'uiMode' => 'embedded',
                'successPage' => null,
                'cancelPage' => null,
                'returnPage' => 'test-page',
                'ordersPage' => 'orders',
                'settingsPage' => 'checkout-settings'
            ]
        ];

        $this->cart = new Cart();

        // for success, return and cancel option pages
        $this->testPage = site()->createChild([
            'slug' => 'test-page',
            'template' => 'default',
            'content' => [
                'title' => 'Test'
            ]
        ])->changeStatus('listed');

        // to test a product
        $this->productPage = site()->createChild([
            'slug' => 'test-product',
            'template' => 'product',
            'content' => [
                'title' => 'Product',
                'price' => 10
            ]
        ])->changeStatus('listed');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // destroy data after each test
        $this->cart->destroy();
        $this->testPage->delete(true);
        $this->productPage->delete(true);
    }

    #[DataProvider('provideInvalidOptionsData')]
    public function testInvalidOptions(string $uiMode, string $optionName, mixed $invalidValue): void
    {
        $options = $this->options[$uiMode];
        $options[$optionName] = $invalidValue;

        $this->expectException(InvalidOptionsException::class);

        new StripeCheckout($options);
    }

    public static function provideInvalidOptionsData(): \Generator
    {
        // hosted
        yield 'hosted invalid stripePublicKey' => ['hosted', 'stripePublicKey', null];
        yield 'hosted invalid stripeSecretKey' => ['hosted', 'stripeSecretKey', null];
        yield 'hosted invalid stripeWebhookSecret' => ['hosted', 'stripeWebhookSecret', null];
        yield 'hosted invalid currency' => ['hosted', 'currency', 'INV'];
        yield 'hosted invalid uiMode' => ['hosted', 'uiMode', 'invalid'];
        yield 'hosted invalid ordersPage' => ['hosted', 'ordersPage', null];
        yield 'hosted invalid successPage' => ['hosted', 'successPage', null];
        yield 'hosted invalid cancelPage' => ['hosted', 'cancelPage', null];

        // embedded
        yield 'embedded invalid stripePublicKey' => ['embedded', 'stripePublicKey', null];
        yield 'embedded invalid stripeSecretKey' => ['embedded', 'stripeSecretKey', null];
        yield 'embedded invalid stripeWebhookSecret' => ['embedded', 'stripeWebhookSecret', null];
        yield 'embedded invalid currency' => ['embedded', 'currency', 'INV'];
        yield 'embedded invalid uiMode' => ['embedded', 'uiMode', 'invalid'];
        yield 'embedded invalid ordersPage' => ['embedded', 'ordersPage', null];
        yield 'embedded invalid returnPage' => ['embedded', 'returnPage', null];
    }

    #[DataProvider('provideUiModeData')]
    public function testCreateSession(string $uiMode): void
    {
        // set Stripe mock HTTP client
        ApiRequestor::setHttpClient(
            new MockStripeClient('{"object": "checkout.session"}')
        );

        // arrange
        $this->cart->addItem('test-product', 1);

        $stripeCheckout = new StripeCheckout($this->options[$uiMode]);
        $this->assertInstanceOf(Session::class, $stripeCheckout->createSession($this->cart));
    }

    #[DataProvider('provideUiModeData')]
    public function testCreateSessionWithEmptyCart(string $uiMode): void
    {
        $this->expectException(EmptyCartException::class);

        $stripeCheckout = new StripeCheckout($this->options[$uiMode]);
        $stripeCheckout->createSession($this->cart);
    }

    #[DataProvider('provideUiModeData')]
    public function testRetrieveSession(string $uiMode): void
    {
        // set Stripe mock HTTP client
        ApiRequestor::setHttpClient(
            new MockStripeClient('{"object": "checkout.session"}')
        );

        $stripeCheckout = new StripeCheckout($this->options[$uiMode]);
        $this->assertInstanceOf(Session::class, $stripeCheckout->retrieveSession('session-id'));
    }

    #[DataProvider('provideUiModeData')]
    public function testGetters(string $uiMode): void
    {
        $options = $this->options[$uiMode];
        $stripeCheckout = new StripeCheckout($options);

        $this->assertSame($stripeCheckout->stripePublicKey(), $options['stripePublicKey']);
        $this->assertSame($stripeCheckout->stripeSecretKey(), $options['stripeSecretKey']);
        $this->assertSame($stripeCheckout->stripeWebhookSecret(), $options['stripeWebhookSecret']);
        $this->assertSame($stripeCheckout->currency(), $options['currency']);
        $this->assertSame($stripeCheckout->currencySymbol(), '€');
        $this->assertSame($stripeCheckout->uiMode(), $options['uiMode']);
        $this->assertSame($stripeCheckout->ordersPage(), $options['ordersPage']);
        $this->assertSame($stripeCheckout->settingsPage(), $options['settingsPage']);
        $this->assertStringEndsWith('/stripe/checkout', $stripeCheckout->checkoutUrl());
        $this->assertStringEndsWith('/stripe/checkout/embedded', $stripeCheckout->checkoutEmbeddedUrl());

        switch ($uiMode) {
            case 'hosted':
                $this->assertSame($stripeCheckout->successPage(), $options['successPage']);
                $this->assertSame($stripeCheckout->cancelPage(), $options['cancelPage']);
                $this->assertSame($stripeCheckout->returnPage(), null);
                break;
            case 'embedded':
                $this->assertSame($stripeCheckout->successPage(), null);
                $this->assertSame($stripeCheckout->cancelPage(), null);
                $this->assertSame($stripeCheckout->returnPage(), $options['returnPage']);
                break;
        }
    }

    public static function provideUiModeData(): \Generator
    {
        yield 'hosted' => ['hosted'];
        yield 'embedded' => ['embedded'];
    }
}
