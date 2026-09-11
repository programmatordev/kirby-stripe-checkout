<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestContextFactory;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\Configuration;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

final class SessionRequestContextTest extends KirbyTestCase
{
    private const PREFIX = 'programmatordev.stripe-checkout';

    public function testNormalizesLocaleExpiryAndLocalizedDestinations(): void
    {
        $this->restart(languages: [
            [
                'code' => 'en',
                'default' => true,
                'locale' => 'en_GB.UTF-8',
                'name' => 'English',
            ],
            [
                'code' => 'pt',
                'locale' => 'pt_PT',
                'name' => 'Português',
            ],
        ]);
        $destination = $this->kirby->site()->createChild([
            'slug' => 'confirmation',
            'template' => 'default',
            'content' => ['title' => 'Confirmation'],
        ])->changeStatus('listed');
        $store = new StripeCheckoutPageStore($this->kirby);
        $this->kirby->setCurrentLanguage('pt');
        $store->initialize()->update([
            'currency' => 'EUR',
            'checkoutExpirationMinutes' => 30,
            'successDestination' => $destination->uuid()->toString(),
            'cancelDestination' => 'https://merchant.example/cancel?keep=1#section',
            'returnDestination' => '/account',
        ]);
        $configuration = (new ConfigurationResolver())
            ->resolve([], $store->settings())
            ->configurationOrFail();
        $order = $this->order(languageCode: 'pt');
        $context = (new SessionRequestContextFactory($this->kirby))->create(
            $order,
            $configuration,
            new DateTimeImmutable('2026-09-11T09:00:00+01:00'),
            'https://kirby-stripe-checkout.test/pt/product?keep=1&_stripe_checkout_result=old#details',
        );

        $this->assertSame(UiMode::Hosted, $context->uiMode());
        $this->assertSame('pt', $context->languageCode());
        $this->assertSame('pt', $context->locale());
        $this->assertSame('2026-09-11T08:30:00+00:00', $context->expiresAt()->format('c'));
        $this->assertSame('https://kirby-stripe-checkout.test/pt/product?keep=1', $context->originUrl());
        $this->assertSame($destination->url('pt'), $context->successDestination());
        $this->assertSame('https://merchant.example/cancel?keep=1', $context->cancelDestination());
        $this->assertSame('https://kirby-stripe-checkout.test/pt/account', $context->returnDestination());
        $this->assertSame($order, $context->order());
    }

    public function testUsesExactStripeLocaleAndFallsBackFromRemovedLanguage(): void
    {
        $this->restart(languages: [
            [
                'code' => 'en',
                'default' => true,
                'locale' => 'en_GB',
                'name' => 'English',
            ],
        ]);
        $configuration = $this->configuration(['settings' => ['currency' => 'EUR']]);
        $context = (new SessionRequestContextFactory($this->kirby))->create(
            $this->order(languageCode: 'removed'),
            $configuration,
            new DateTimeImmutable('2026-09-11T08:00:00Z'),
        );

        $this->assertSame('removed', $context->languageCode());
        $this->assertSame('en-GB', $context->locale());
        $this->assertSame('https://kirby-stripe-checkout.test/en', $context->originUrl());
        $this->assertSame($context->originUrl(), $context->successDestination());
        $this->assertSame($context->originUrl(), $context->cancelDestination());
        $this->assertSame($context->originUrl(), $context->returnDestination());
    }

    public function testUnsupportedLocaleUsesStripeAutomaticDetection(): void
    {
        $this->restart(languages: [[
            'code' => 'ga',
            'default' => true,
            'locale' => 'ga_IE',
            'name' => 'Gaeilge',
        ]]);
        $context = (new SessionRequestContextFactory($this->kirby))->create(
            $this->order(languageCode: 'ga'),
            $this->configuration(['settings' => ['currency' => 'EUR']]),
            new DateTimeImmutable('2026-09-11T08:00:00Z'),
        );

        $this->assertSame('auto', $context->locale());
    }

    #[DataProvider('unsafeDestinations')]
    public function testRejectsUnsafeConfiguredDestinations(string $destination): void
    {
        $configuration = $this->configuration(['settings' => [
            'currency' => 'EUR',
            'successDestination' => $destination,
        ]]);

        try {
            (new SessionRequestContextFactory($this->kirby))->create(
                $this->order(),
                $configuration,
                new DateTimeImmutable('2026-09-11T08:00:00Z'),
            );
            $this->fail('Expected an unsafe Checkout destination to be rejected.');
        } catch (ConfigurationException $error) {
            $this->assertSame('configuration.value_invalid', $error->errorCode());
            $this->assertSame('settings.successDestination', $error->path());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeDestinations(): iterable
    {
        yield 'protocol relative' => ['//example.com/return'];
        yield 'credentials' => ['https://user:password@example.com/return'];
        yield 'unsupported scheme' => ['mailto:buyer@example.com'];
        yield 'unknown Page' => ['missing-page'];
        yield 'reserved result query' => ['https://example.com/return?_stripe_checkout_result=chosen'];
    }

    public function testLiveModeRequiresHttpsExceptForLocalDevelopment(): void
    {
        $external = $this->configuration([
            'settings' => [
                'currency' => 'EUR',
                'successDestination' => 'http://merchant.example/complete',
            ],
            'stripe' => ['secretKey' => 'sk_live_example'],
        ]);

        $this->expectException(ConfigurationException::class);
        (new SessionRequestContextFactory($this->kirby))->create(
            $this->order(),
            $external,
            new DateTimeImmutable('2026-09-11T08:00:00Z'),
        );
    }

    public function testExternalOrMalformedInitiatingUrlFallsBackToTheSite(): void
    {
        $factory = new SessionRequestContextFactory($this->kirby);
        $configuration = $this->configuration(['settings' => ['currency' => 'EUR']]);

        foreach (['https://external.example/product', 'not a URL'] as $origin) {
            $context = $factory->create(
                $this->order(),
                $configuration,
                new DateTimeImmutable('2026-09-11T08:00:00Z'),
                $origin,
            );
            $this->assertSame('https://kirby-stripe-checkout.test', $context->originUrl());
        }
    }

    #[DataProvider('staleSettings')]
    public function testRejectsSettingsThatNoLongerMatchTheOrder(
        string $uiMode,
        string $currency,
    ): void {
        $factory = new SessionRequestContextFactory($this->kirby);
        $configuration = $this->configuration(['settings' => [
            'currency' => $currency,
            'uiMode' => $uiMode,
        ]]);

        $this->expectException(CheckoutInputException::class);
        $factory->create(
            $this->order(),
            $configuration,
            new DateTimeImmutable('2026-09-11T08:00:00Z'),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function staleSettings(): iterable
    {
        yield 'UI mode changed' => ['embedded', 'EUR'];
        yield 'currency changed' => ['hosted', 'USD'];
    }

    public function testRequiresAConfiguredCurrency(): void
    {
        $factory = new SessionRequestContextFactory($this->kirby);
        $configuration = $this->configuration([]);

        try {
            $factory->create(
                $this->order(),
                $configuration,
                new DateTimeImmutable('2026-09-11T08:00:00Z'),
            );
            $this->fail('Expected Checkout context creation to require a store currency.');
        } catch (ConfigurationException $error) {
            $this->assertSame('configuration.required_missing', $error->errorCode());
            $this->assertSame('settings.currency', $error->path());
        }
    }

    /** @param array<string, mixed> $options */
    private function configuration(array $options): Configuration
    {
        return (new ConfigurationResolver())->resolve([
            self::PREFIX => $options,
        ])->configurationOrFail();
    }

    private function order(
        ?string $languageCode = null,
        UiMode $uiMode = UiMode::Hosted,
    ): OrderCreationContext {
        $price = Money::of('16', 'EUR');
        $product = new Product(
            new ProductRequest('product'),
            'Product',
            false,
            new Price($price),
        );

        return new OrderCreationContext(
            'Abc123def456GHI7',
            'ORD-ABC123DEF456GHI7',
            CheckoutSource::Direct,
            null,
            null,
            $languageCode,
            $uiMode->value,
            'EUR',
            [OrderLineItemSnapshot::fromProduct($product, $price)],
        );
    }

    /** @param list<array<string, mixed>>|null $languages */
    private function restart(?array $languages = null): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(languages: $languages);
        $this->kirby = $this->environment->app();
    }
}
