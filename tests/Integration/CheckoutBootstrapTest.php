<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use DateTimeImmutable;
use Kirby\Cms\User;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartEntry;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Cart\Internal\KirbySessionCartStore;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptToken;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\BrowserAttemptContext;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\BrowserAttemptStore;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationErrorCode;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use RuntimeException;

final class CheckoutBootstrapTest extends KirbyTestCase
{
    public function testHostedCartBootstrapCapturesNativeInputWithoutResolvingCommerce(): void
    {
        $this->restart([
            'products' => ['resolver' => static function (): never {
                throw new RuntimeException('Bootstrap must not resolve products.');
            }],
            'shipping' => ['resolver' => static function (): never {
                throw new RuntimeException('Bootstrap must not request shipping.');
            }],
        ]);
        $session = $this->kirby->session();
        $cart = new CartSnapshot('cart', 'revision', [new CartEntry('item', new ProductRequest('shirt', 2))], 1, 1);
        $store = new KirbySessionCartStore($session, static fn(): string => 'cart');
        $store->mutate(static fn(CartSnapshot $current): CartSnapshot => $cart);
        $bootstrap = (new StripeCheckout($this->kirby))->checkout();
        $submissionData = $bootstrap->submissionData();
        $this->assertSame(UiMode::Hosted, $bootstrap->uiMode());
        $this->assertSame(CheckoutSource::Cart, $bootstrap->checkoutSource());
        $this->assertSame('https://kirby-stripe-checkout.test/stripe-checkout/checkout', $bootstrap->actionUrl());
        $this->assertSame(['csrf', 'source', 'attemptToken', 'revision'], array_keys($submissionData));
        $this->assertSame('cart', $submissionData['source']);
        $this->assertSame('revision', $submissionData['revision']);
        $this->assertTrue($this->kirby->csrf($submissionData['csrf']));
        $this->assertNull($bootstrap->publishableKey());
        $this->assertSame([
            'uiMode' => 'hosted',
            'source' => 'cart',
            'actionUrl' => 'https://kirby-stripe-checkout.test/stripe-checkout/checkout',
            'submissionData' => $submissionData,
        ], $bootstrap->toArray());
        $this->assertSame($bootstrap->toArray(), json_decode(json_encode($bootstrap, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $context = BrowserAttemptContext::capture(CheckoutSource::Cart, UiMode::Hosted, null, null, $submissionData['csrf'], $cart);
        $attempt = (new BrowserAttemptStore($session))->load(new AttemptToken($submissionData['attemptToken']), $context, new DateTimeImmutable());
        $this->assertNotNull($attempt->context()->guestReference());
        $this->assertSame('https://kirby-stripe-checkout.test/shop?category=shirts', $attempt->initiatingUrl());
        $this->assertSame(900, $session->duration());
        $this->assertSame(300, $session->timeout());
        $this->assertCount(0, (new StripeCheckout($this->kirby))->orders());
        $this->assertStringNotContainsString($submissionData['attemptToken'], json_encode($session->data()->get(BrowserAttemptStore::KEY), JSON_THROW_ON_ERROR));
        $second = (new StripeCheckout($this->kirby))->checkout();
        $this->assertNotSame($submissionData['attemptToken'], $second->submissionData()['attemptToken']);
        $this->assertSame('revision', $second->submissionData()['revision']);
    }

    public function testEmbeddedDirectBootstrapIsLocalizedAndExposesOnlyThePublishableKey(): void
    {
        $this->restart([
            'cart' => ['enabled' => false],
            'settings' => ['uiMode' => 'embedded'],
            'stripe' => ['publishableKey' => 'pk_test_browser'],
        ], languages: [
            ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English'],
            ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português'],
        ]);
        $this->kirby->setCurrentLanguage('pt');
        $bootstrap = (new StripeCheckout($this->kirby))->checkout(CheckoutSource::Direct);
        $submissionData = $bootstrap->submissionData();
        $this->assertSame(UiMode::Embedded, $bootstrap->uiMode());
        $this->assertSame(CheckoutSource::Direct, $bootstrap->checkoutSource());
        $this->assertSame('https://kirby-stripe-checkout.test/pt/stripe-checkout/checkout', $bootstrap->actionUrl());
        $this->assertSame(['csrf', 'source', 'attemptToken'], array_keys($submissionData));
        $this->assertSame('pk_test_browser', $bootstrap->publishableKey());
        $this->assertSame('pk_test_browser', $bootstrap->toArray()['publishableKey']);
        $this->assertNull($this->kirby->session()->data()->get(KirbySessionCartStore::KEY));
        $serialized = json_encode($bootstrap, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('sk_test_server', $serialized);
        $this->assertStringNotContainsString('whsec_test_hook', $serialized);

        // Changing language may issue a new action; it must not retarget the earlier action.
        $this->kirby->setCurrentLanguage('en');
        $englishBootstrap = (new StripeCheckout($this->kirby))->checkout(CheckoutSource::Direct);
        $englishSubmissionData = $englishBootstrap->submissionData();
        $this->assertNotSame($submissionData['attemptToken'], $englishSubmissionData['attemptToken']);
        $context = BrowserAttemptContext::capture(CheckoutSource::Direct, UiMode::Embedded, 'en', null, $englishSubmissionData['csrf'], null);
        $attempt = (new BrowserAttemptStore($this->kirby->session()))->load(new AttemptToken($englishSubmissionData['attemptToken']), $context, new DateTimeImmutable());
        $this->assertSame('en', $attempt->context()->languageCode());
    }

    public function testCartDisabledRejectsBeforeIssuingBrowserState(): void
    {
        $this->restart(['cart' => ['enabled' => false]]);

        try {
            (new StripeCheckout($this->kirby))->checkout();
            $this->fail('Expected disabled cart.');
        } catch (CheckoutInputException $error) {
            $this->assertSame(CheckoutErrorCode::CART_DISABLED, $error->errorCode());
            $this->assertNull($this->kirby->session()->token());
        }

        $this->assertSame(CheckoutSource::Direct, (new StripeCheckout($this->kirby))->checkout(CheckoutSource::Direct)->checkoutSource());
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('missingConfiguration')]
    public function testMissingConfigurationDoesNotIssueBrowserState(array $options, string $errorCode): void
    {
        $this->restart($options);

        try {
            (new StripeCheckout($this->kirby))->checkout(CheckoutSource::Direct);
            $this->fail('Expected configuration failure.');
        } catch (ConfigurationException $error) {
            $this->assertSame($errorCode, $error->errorCode());
            $this->assertNull($this->kirby->session()->token());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function missingConfiguration(): iterable
    {
        yield 'currency' => [['settings' => ['currency' => null]], ConfigurationErrorCode::REQUIRED_MISSING];
        yield 'secret key' => [['stripe' => ['secretKey' => null]], ConfigurationErrorCode::CREDENTIAL_MISSING];
        yield 'webhook secret' => [['stripe' => ['webhookSecret' => null]], ConfigurationErrorCode::CREDENTIAL_MISSING];
        yield 'embedded publishable key' => [['settings' => ['uiMode' => 'embedded']], ConfigurationErrorCode::CREDENTIAL_MISSING];
    }

    #[DataProvider('requestUrls')]
    public function testInitiatingUrlUsesOnlySafeNativeGetContext(string $method, string $url, string $expected): void
    {
        $this->restart(request: ['method' => $method, 'url' => $url]);
        $submissionData = (new StripeCheckout($this->kirby))->checkout(CheckoutSource::Direct)->submissionData();
        $context = BrowserAttemptContext::capture(CheckoutSource::Direct, UiMode::Hosted, null, null, $submissionData['csrf'], null);
        $attempt = (new BrowserAttemptStore($this->kirby->session()))->load(new AttemptToken($submissionData['attemptToken']), $context, new DateTimeImmutable());
        $this->assertSame($expected, $attempt->initiatingUrl());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function requestUrls(): iterable
    {
        yield 'result and fragment stripped' => ['GET', 'https://kirby-stripe-checkout.test/shop?keep=yes&_stripe_checkout_result=private#section', 'https://kirby-stripe-checkout.test/shop?keep=yes'];
        yield 'foreign origin' => ['GET', 'https://other.example/shop', 'https://kirby-stripe-checkout.test'];
        yield 'post path is not a back link' => ['POST', 'https://kirby-stripe-checkout.test/stripe-checkout/checkout', 'https://kirby-stripe-checkout.test'];
    }

    public function testLogoutInvalidatesEarlierGuestActionsEvenAfterReturningToGuest(): void
    {
        $this->restart();
        $submissionData = (new StripeCheckout($this->kirby))->checkout(CheckoutSource::Direct)->submissionData();
        $this->kirby->auth()->logout();
        $context = BrowserAttemptContext::capture(CheckoutSource::Direct, UiMode::Hosted, null, null, (string) $this->kirby->csrf(), null);
        $this->expectException(CheckoutInputException::class);
        $this->expectExceptionMessage(CheckoutErrorCode::ATTEMPT_CONFLICT);
        (new BrowserAttemptStore($this->kirby->session()))->load(new AttemptToken($submissionData['attemptToken']), $context, new DateTimeImmutable());
    }

    public function testNativeLoginBindsNewActionsToTheUserAndRejectsThePreviousGuestAction(): void
    {
        $this->restart();
        /** @var User $user */
        $user = $this->kirby->impersonate('kirby', fn() => $this->kirby->users()->create([
            'email' => 'checkout@example.test',
            'role' => 'admin',
            'password' => 'test-password-123',
        ]));
        $guestSubmission = (new StripeCheckout($this->kirby))->checkout(CheckoutSource::Direct)->submissionData();
        $user->login('test-password-123');
        $submissionData = (new StripeCheckout($this->kirby))->checkout(CheckoutSource::Direct)->submissionData();
        $context = BrowserAttemptContext::capture(CheckoutSource::Direct, UiMode::Hosted, null, $user->uuid()->toString(), $submissionData['csrf'], null);
        $store = new BrowserAttemptStore($this->kirby->session());
        $attempt = $store->load(new AttemptToken($submissionData['attemptToken']), $context, new DateTimeImmutable());
        $this->assertSame($user->uuid()->toString(), $attempt->context()->userUuid());
        $this->assertNull($attempt->context()->guestReference());
        $this->expectException(CheckoutInputException::class);
        $this->expectExceptionMessage(CheckoutErrorCode::ATTEMPT_CONFLICT);
        $store->load(new AttemptToken($guestSubmission['attemptToken']), $context, new DateTimeImmutable());
    }

    /**
     * @param array<string, mixed> $options
     * @param list<array<string, mixed>>|null $languages
     * @param array<string, mixed>|null $request
     */
    private function restart(array $options = [], ?array $languages = null, ?array $request = null): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            options: [
                'session' => ['durationNormal' => 900, 'timeout' => 300],
                'programmatordev.stripe-checkout' => array_replace_recursive([
                    'settings' => ['currency' => 'EUR', 'defaultRequiresShipping' => false],
                    'stripe' => ['secretKey' => 'sk_test_server', 'webhookSecret' => 'whsec_test_hook'],
                ], $options),
            ],
            languages: $languages,
            impersonate: null,
            request: $request ?? ['method' => 'GET', 'url' => 'https://kirby-stripe-checkout.test/shop?category=shirts'],
        );
        $this->kirby = $this->environment->app();
    }
}
