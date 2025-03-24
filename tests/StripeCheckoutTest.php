<?php

namespace ProgrammatorDev\StripeCheckout\Test;

use Kirby\Cms\Page;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use Stripe\ApiRequestor;
use Stripe\Checkout\Session;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;

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
                'successPage' => 'test',
                'cancelPage' => 'test',
                'ordersPage' => 'orders',
                'settingsPage' => 'checkout-settings'
            ],
            'embedded' => [
                'stripePublicKey' => 'pk_test_abc123',
                'stripeSecretKey' => 'sk_test_abc123',
                'stripeWebhookSecret' => 'whsec_abc123',
                'currency' => 'EUR',
                'uiMode' => 'embedded',
                'returnPage' => 'test',
                'ordersPage' => 'orders',
                'settingsPage' => 'checkout-settings',
            ]
        ];

        $this->cart = new Cart([
            'currency' => 'EUR'
        ]);

        $this->testPage = site()
            ->createChild([
                'slug' => 'test',
                'template' => 'default',
                'isDraft' => false
            ]);

        $this->productPage = site()
            ->createChild([
                'slug' => 'product',
                'template' => 'product',
                'content' => [
                    'title' => 'Product',
                    'price' => 10
                ]
            ])
            ->changeStatus('listed');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // destroy data after each test
        $this->cart->destroy();
        $this->testPage->delete(true);
        $this->productPage->delete(true);
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
        yield 'hosted missing successPage' => ['hosted', 'successPage'];
        yield 'hosted missing cancelPage' => ['hosted', 'cancelPage'];
        yield 'hosted missing ordersPage' => ['hosted', 'ordersPage'];
        yield 'hosted missing settingsPage' => ['hosted', 'settingsPage'];
        // embedded
        yield 'embedded missing stripePublicKey' => ['embedded', 'stripePublicKey'];
        yield 'embedded missing stripeSecretKey' => ['embedded', 'stripeSecretKey'];
        yield 'embedded missing stripeWebhookSecret' => ['embedded', 'stripeWebhookSecret'];
        yield 'embedded missing currency' => ['embedded', 'currency'];
        yield 'embedded missing uiMode' => ['embedded', 'uiMode'];
        yield 'embedded missing returnPage' => ['embedded', 'returnPage'];
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
        yield 'hosted empty successPage' => ['hosted', 'successPage', ''];
        yield 'hosted empty cancelPage' => ['hosted', 'cancelPage', ''];
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
        yield 'embedded empty returnPage' => ['embedded', 'returnPage', ''];
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
            'id' => 'product',
            'quantity' => 1
        ]);

        $stripeCheckout = new StripeCheckout($this->options[$uiMode]);
        $this->assertInstanceOf(Session::class, $stripeCheckout->createSession($this->cart));
    }

    #[DataProvider('provideUiModeData')]
    public function testCreateSessionWithEmptyCart(string $uiMode): void
    {
        $this->expectException(CheckoutSessionException::class);

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
            $this->assertSame($stripeCheckout->getOptions(), $options);
            $this->assertSame($stripeCheckout->getReturnPage(), null);
            $this->assertSame($stripeCheckout->getSuccessPage(), $options['successPage']);
            $this->assertSame($stripeCheckout->getCancelPage(), $options['cancelPage']);
        }
        else if ($uiMode === 'embedded') {
            $this->assertSame($stripeCheckout->getOptions(), $options);
            $this->assertSame($stripeCheckout->getReturnPage(), $options['returnPage']);
            $this->assertSame($stripeCheckout->getSuccessPage(), null);
            $this->assertSame($stripeCheckout->getCancelPage(), null);
        }
    }

    public function testGetLineItems(): void
    {
        // arrange
        $this->cart->addItem([
            'id' => 'product',
            'quantity' => 1
        ]);
        $this->cart->addItem([
            'id' => 'product',
            'quantity' => 2,
            'options' => [
                'Name' => 'Value'
            ]
        ]);

        $stripeCheckout = new class($this->options['hosted']) extends StripeCheckout {
            public function addLineItemsParams(Cart $cart): array
            {
                return parent::addLineItemsParams($cart);
            }
        };

        // act
        $lineItems = $stripeCheckout->addLineItemsParams($this->cart);

        // assert
        $this->assertEquals([
            [
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => 1000,
                    'product_data' => [
                        'name' => 'Product'
                    ]
                ],
                'quantity' => 1,
            ],
            [
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => 1000,
                    'product_data' => [
                        'name' => 'Product',
                        'description' => 'Name: Value'
                    ]
                ],
                'quantity' => 2,
            ]
        ], $lineItems);
    }

    public function testGetPageUrlWithInvalidId(): void
    {
        // arrange
        $stripeCheckout = new class($this->options['hosted']) extends StripeCheckout {
            public function buildPageUrl(string $pageId, ?string $languageCode = null, bool $withCheckoutSessionParam = false): string
            {
                return parent::buildPageUrl($pageId, $languageCode, $withCheckoutSessionParam);
            }
        };

        $this->expectException(CheckoutSessionException::class);
        $stripeCheckout->buildPageUrl('invalid-page');
    }

    public function testAddSessionIdToUrlQuery(): void
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
