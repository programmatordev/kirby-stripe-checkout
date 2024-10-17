<?php

namespace ProgrammatorDev\StripeCheckout\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Cart;
use ProgrammatorDev\StripeCheckout\Exception\CartIsEmptyException;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use Stripe\ApiRequestor;
use Stripe\Checkout\Session;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;

class StripeCheckoutTest extends BaseTestCase
{
    private array $options;

    private Cart $cart;

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
                'successUrl' => 'https://example.com/success',
                'cancelUrl' => 'https://example.com/cancel',
                'ordersPage' => 'orders',
                'settingsPage' => 'checkout-settings'
            ],
            'embedded' => [
                'stripePublicKey' => 'pk_test_abc123',
                'stripeSecretKey' => 'sk_test_abc123',
                'stripeWebhookSecret' => 'whsec_abc123',
                'currency' => 'EUR',
                'uiMode' => 'embedded',
                'returnUrl' => 'https://example.com/return',
                'ordersPage' => 'orders',
                'settingsPage' => 'checkout-settings',
            ]
        ];

        $this->cart = new Cart([
            'currency' => 'EUR'
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->cart->destroy();
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
        yield 'hosted missing stripeWebhookSecret' => ['hosted', 'stripeWebhookSecret'];
        yield 'hosted missing currency' => ['hosted', 'currency'];
        yield 'hosted missing uiMode' => ['hosted', 'uiMode'];
        yield 'hosted missing successUrl' => ['hosted', 'successUrl'];
        yield 'hosted missing cancelUrl' => ['hosted', 'cancelUrl'];
        yield 'hosted missing ordersPage' => ['hosted', 'ordersPage'];
        yield 'hosted missing settingsPage' => ['hosted', 'settingsPage'];
        // embedded
        yield 'embedded missing stripePublicKey' => ['embedded', 'stripePublicKey'];
        yield 'embedded missing stripeSecretKey' => ['embedded', 'stripeSecretKey'];
        yield 'embedded missing stripeWebhookSecret' => ['embedded', 'stripeWebhookSecret'];
        yield 'embedded missing currency' => ['embedded', 'currency'];
        yield 'embedded missing uiMode' => ['embedded', 'uiMode'];
        yield 'embedded missing returnUrl' => ['embedded', 'returnUrl'];
        yield 'embedded missing ordersPage' => ['embedded', 'ordersPage'];
        yield 'embedded missing settingsPage' => ['embedded', 'settingsPage'];
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
        yield 'hosted invalid stripeWebhookSecret' => ['hosted', 'stripeWebhookSecret', 1];
        yield 'hosted empty stripeWebhookSecret' => ['hosted', 'stripeWebhookSecret', ''];
        yield 'hosted invalid currency' => ['hosted', 'currency', 1];
        yield 'hosted empty currency' => ['hosted', 'currency', ''];
        yield 'hosted invalid uiMode' => ['hosted', 'uiMode', 'invalid'];
        yield 'hosted invalid type successUrl' => ['hosted', 'successUrl', 1];
        yield 'hosted invalid url successUrl' => ['hosted', 'successUrl', 'invalid'];
        yield 'hosted empty successUrl' => ['hosted', 'successUrl', ''];
        yield 'hosted invalid type cancelUrl' => ['hosted', 'cancelUrl', 1];
        yield 'hosted invalid url cancelUrl' => ['hosted', 'cancelUrl', 'invalid'];
        yield 'hosted empty cancelUrl' => ['hosted', 'cancelUrl', ''];
        yield 'hosted empty ordersPage' => ['hosted', 'ordersPage', ''];
        yield 'hosted empty settingsPage' => ['hosted', 'settingsPage', ''];
        // embedded
        yield 'embedded invalid stripePublicKey' => ['embedded', 'stripePublicKey', 1];
        yield 'embedded empty stripePublicKey' => ['embedded', 'stripePublicKey', ''];
        yield 'embedded invalid stripeSecretKey' => ['embedded', 'stripeSecretKey', 1];
        yield 'embedded empty stripeSecretKey' => ['embedded', 'stripeSecretKey', ''];
        yield 'embedded invalid stripeWebhookSecret' => ['embedded', 'stripeWebhookSecret', 1];
        yield 'embedded empty stripeWebhookSecret' => ['embedded', 'stripeWebhookSecret', ''];
        yield 'embedded invalid currency' => ['embedded', 'currency', 1];
        yield 'embedded empty currency' => ['embedded', 'currency', ''];
        yield 'embedded invalid uiMode' => ['embedded', 'uiMode', 'invalid'];
        yield 'embedded invalid type returnUrl' => ['embedded', 'returnUrl', 1];
        yield 'embedded invalid url returnUrl' => ['embedded', 'returnUrl', 'invalid'];
        yield 'embedded empty returnUrl' => ['embedded', 'returnUrl', ''];
        yield 'embedded empty ordersPage' => ['embedded', 'ordersPage', ''];
        yield 'embedded empty settingsPage' => ['embedded', 'settingsPage', ''];
    }

    #[DataProvider('provideUiModeData')]
    public function testCreateSession(string $uiMode): void
    {
        // set Stripe mock HTTP client
        ApiRequestor::setHttpClient(
            new MockStripeClient('{"object": "checkout.session"}')
        );

        // arrange
        $this->cart->addItem([
            'id' => 'item-id-1',
            'name' => 'Item 1',
            'image' => 'image.jpg',
            'price' => 10,
            'quantity' => 1
        ]);

        $stripeCheckout = new StripeCheckout($this->options[$uiMode]);
        $this->assertInstanceOf(Session::class, $stripeCheckout->createSession($this->cart));
    }

    #[DataProvider('provideUiModeData')]
    public function testCreateSessionWithEmptyCart(string $uiMode): void
    {
        $this->expectException(CartIsEmptyException::class);

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

        $this->assertSame($stripeCheckout->getStripePublicKey(), $options['stripePublicKey']);
        $this->assertSame($stripeCheckout->getStripeSecretKey(), $options['stripeSecretKey']);
        $this->assertSame($stripeCheckout->getStripeWebhookSecret(), $options['stripeWebhookSecret']);
        $this->assertSame($stripeCheckout->getCurrency(), $options['currency']);
        $this->assertSame($stripeCheckout->getUiMode(), $options['uiMode']);
        $this->assertSame($stripeCheckout->getOrdersPage(), $options['ordersPage']);
        $this->assertSame($stripeCheckout->getSettingsPage(), $options['settingsPage']);

        if ($uiMode === 'hosted') {
            // success url is always appended with a session_id parameter
            $options['successUrl'] .= '?session_id={CHECKOUT_SESSION_ID}';

            $this->assertSame($stripeCheckout->getOptions(), $options);
            $this->assertSame($stripeCheckout->getReturnUrl(), null);
            $this->assertSame($stripeCheckout->getSuccessUrl(), $options['successUrl']);
            $this->assertSame($stripeCheckout->getCancelUrl(), $options['cancelUrl']);
        }
        else if ($uiMode === 'embedded') {
            // return url is always appended with a session_id parameter
            $options['returnUrl'] .= '?session_id={CHECKOUT_SESSION_ID}';

            $this->assertSame($stripeCheckout->getOptions(), $options);
            $this->assertSame($stripeCheckout->getReturnUrl(), $options['returnUrl']);
            $this->assertSame($stripeCheckout->getSuccessUrl(), null);
            $this->assertSame($stripeCheckout->getCancelUrl(), null);
        }
    }

    public function testConvertCartToLineItems(): void
    {
        // arrange
        $this->cart->addItem([
            'id' => 'item-id-1',
            'name' => 'Item 1',
            'image' => 'image.jpg',
            'price' => 10,
            'quantity' => 1
        ]);
        $this->cart->addItem([
            'id' => 'item-id-2',
            'name' => 'Item 2',
            'image' => 'image.jpg',
            'price' => 20,
            'quantity' => 2,
            'options' => [
                'Name' => 'Value'
            ]
        ]);

        $stripeCheckout = new class($this->options['hosted']) extends StripeCheckout {
            public function getLineItems(Cart $cart): array
            {
                return parent::getLineItems($cart);
            }
        };

        // act
        $lineItems = $stripeCheckout->getLineItems($this->cart);

        // assert
        $this->assertEquals([
            [
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => 1000,
                    'product_data' => [
                        'name' => 'Item 1',
                        'images' => ['image.jpg']
                    ]
                ],
                'quantity' => 1,
            ],
            [
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => 2000,
                    'product_data' => [
                        'name' => 'Item 2',
                        'images' => ['image.jpg'],
                        'description' => 'Name: Value'
                    ]
                ],
                'quantity' => 2,
            ]
        ], $lineItems);
    }

    public function testAddSessionIdParamToUrl(): void
    {
        // arrange
        $stripeCheckout = new class($this->options['hosted']) extends StripeCheckout {
            public function addSessionIdToUrlQuery(string $url): string
            {
                return parent::addSessionIdToUrlQuery($url);
            }
        };

        // assert
        $this->assertSame(
            'https://example.com/success?session_id={CHECKOUT_SESSION_ID}',
            $stripeCheckout->addSessionIdToUrlQuery('https://example.com/success')
        );
        $this->assertSame(
            'https://example.com/success?action=purchase&session_id={CHECKOUT_SESSION_ID}',
            $stripeCheckout->addSessionIdToUrlQuery('https://example.com/success?action=purchase')
        );
    }

    public static function provideUiModeData(): \Generator
    {
        yield 'hosted' => ['hosted'];
        yield 'embedded' => ['embedded'];
    }
}
