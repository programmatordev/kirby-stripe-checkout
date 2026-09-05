<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Data\Yaml;
use Kirby\Filesystem\F;
use Kirby\Uuid\Uuid;
use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Cart\Exception\CartException;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartEntry;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Cart\Internal\KirbySessionCartStore;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Product\InlinePrice;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

final class CartApiTest extends KirbyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->restart();
    }

    public function testReadsAreLazyAndRespectTheNormalSessionLifetime(): void
    {
        $session = $this->kirby->session();
        $this->assertNull($session->token());
        $plugin = new StripeCheckout($this->kirby);
        $plugin->settings();
        $this->assertNull($session->token());
        $cart = $plugin->cart();
        $this->assertNotNull($cart);
        $this->assertNotNull($session->token());
        $this->assertSame(900, $session->duration());
        $this->assertSame(300, $session->timeout());
        $this->assertTrue($cart->isEmpty());
        $this->assertFalse($cart->hasErrors());
        $this->assertSame('0.00', (string) $cart->subtotal()?->getAmount());
        $this->assertSame('EUR', $cart->currency()?->getCurrencyCode());
        $this->assertNull($cart->destinationCountry());
    }

    public function testDisabledCartCreatesNoSessionAndSupportsDottedConfiguration(): void
    {
        $this->restart(['programmatordev.stripe-checkout.cart.enabled' => false]);
        $this->assertNull((new StripeCheckout($this->kirby))->cart());
        $this->assertNull($this->kirby->session()->token());
        $resolver = new ConfigurationResolver();
        $this->assertFalse($resolver->cartEnabled(['programmatordev.stripe-checkout' => ['cart.enabled' => false]]));
        $report = $resolver->resolve(['programmatordev.stripe-checkout' => [
            'cart' => ['enabled' => true], 'cart.enabled' => false,
        ]]);
        $this->assertFalse($report->isValid());
        $this->assertFalse($resolver->resolve(['programmatordev.stripe-checkout' => ['cart' => ['enabled' => 'false']]])->isValid());
    }

    public function testPhpMutationsMergeReferencesAndRefreshTheSameObject(): void
    {
        $page = $this->product();
        $cart = $this->cart();
        $this->assertSame($cart, $cart->add($page->id(), 2));
        $first = $cart->items()[0];
        $cart->add($page->uuid()->toString());
        $this->assertSame(1, $cart->count());
        $this->assertSame(3, $cart->totalQuantity());
        $this->assertSame($first->id(), $cart->items()[0]->id());
        $this->assertSame('48.00', (string) $cart->subtotal()?->getAmount());
        $this->assertSame('16.00', (string) $cart->items()[0]->price()?->getAmount());
        $this->assertSame('48.00', (string) $cart->items()[0]->subtotal()?->getAmount());
        $this->assertSame([], $cart->items()[0]->options());
        $this->assertSame(2, $first->quantity());
        $this->assertSame($page->uuid()->toString(), $first->request()->reference());
        $cart->update($first->id(), 4);
        $revision = $cart->revision();
        $cart->update($first->id(), 4);
        $this->assertSame($revision, $cart->revision());
        $this->assertSame(4, $this->cart()->totalQuantity());
        $cart->remove($first->id());
        $this->assertNull($cart->item($first->id()));
        $this->assertTrue($cart->isEmpty());
        $revision = $cart->revision();
        $cart->clear();
        $this->assertSame($revision, $cart->revision());
    }

    public function testItemImageReturnsTheOriginalKirbyFileForTransforms(): void
    {
        $this->restart(['programmatordev.stripe-checkout' => [
            'products' => ['fields' => ['images' => ['hero', 'gallery']]],
        ]]);
        $page = $this->product();
        $source = $this->environment->workspace()->root() . '/image.png';
        // A real image exercises Kirby's dimensions and thumbnail API, not
        // merely a filename that happens to have an image extension.
        F::write($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1sAAAAASUVORK5CYII=', true));
        $hero = $page->createFile(['filename' => 'hero.png', 'source' => $source, 'content' => ['alt' => 'Shirt image']]);
        $gallery = $page->createFile(['filename' => 'gallery.png', 'source' => $hero->root()]);
        $page = $page->update([
            'hero' => Yaml::encode([$hero->uuid()->toString()]),
            'gallery' => Yaml::encode([$gallery->uuid()->toString(), $hero->uuid()->toString()]),
        ]);
        $cart = $this->cart()->add($page->id());
        $item = $cart->items()[0];
        $image = $item->image();
        $this->assertInstanceOf(File::class, $image);
        $this->assertSame($hero->uuid()->toString(), $image->uuid()->toString());
        $this->assertSame($hero->root(), $image->root());
        $this->assertSame('Shirt image', $image->content()->toArray()['alt']);
        // Kirby forwards image methods through File::__call().
        /** @phpstan-ignore-next-line method.notFound */
        $this->assertSame(1, $image->width());
        $cropUrl = $image->crop(100, 100)->url();
        $this->assertNotNull($cropUrl);
        $this->assertStringContainsString('hero', $cropUrl);
        $this->assertSame([$hero->url(), $gallery->url()], $item->product()?->imageUrls());

        $page->update(['hero' => '', 'gallery' => '']);
        $this->assertNull($this->cart()->items()[0]->image());
    }

    public function testExternalImageUrlsDoNotProduceKirbyFiles(): void
    {
        $this->restart(['programmatordev.stripe-checkout' => [
            'products' => ['resolver' => static fn(ProductRequest $request): ResolvedProduct => new ResolvedProduct(
                $request,
                'Shirt',
                false,
                new InlinePrice(Money::of('16.00', 'EUR')),
                imageUrls: match ($request->reference()) {
                    'gallery' => ['https://example.test/hero.jpg', 'https://example.test/detail.jpg'],
                    'single' => ['https://example.test/single.jpg'],
                    default => [],
                },
            )],
        ]]);
        $cart = $this->cart()->add('gallery')->add('single')->add('no-image');

        $this->assertNull($cart->items()[0]->image());
        $this->assertSame(['https://example.test/hero.jpg', 'https://example.test/detail.jpg'], $cart->items()[0]->product()?->imageUrls());
        $this->assertNull($cart->items()[1]->image());
        $this->assertSame(['https://example.test/single.jpg'], $cart->items()[1]->product()?->imageUrls());
        $this->assertNull($cart->items()[2]->image());
    }

    public function testChosenOptionsExposeStableIdsAndCurrentLanguageLabels(): void
    {
        $state = new class {
            public bool $available = true;
        };
        $this->restart(['programmatordev.stripe-checkout' => [
            'products' => ['resolver' => static function (ProductRequest $request, ProductResolutionContext $context) use ($state): ResolvedProduct {
                if ($state->available === false) {
                    throw new RuntimeException('Product no longer available');
                }

                $portuguese = $context->languageCode() === 'pt';

                return new ResolvedProduct(
                    $request,
                    'Shirt',
                    false,
                    new InlinePrice(Money::of('16.00', 'EUR')),
                    selectedOptions: [new SelectedOption('size', $portuguese ? 'Tamanho' : 'Size', 'large', $portuguese ? 'Grande' : 'Large')],
                    variantId: 'large-shirt',
                );
            }],
        ]], languages: [
            ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English'],
            ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português'],
        ]);
        $this->kirby->setCurrentLanguage('en');
        $cart = $this->cart()->add('shirt', quantity: 2, options: ['size' => 'large']);
        $item = $cart->items()[0];
        $this->assertCount(1, $item->options());
        $option = $item->options()[0];
        $this->assertSame('size', $option->optionId());
        $this->assertSame('large', $option->valueId());
        $this->assertSame('Size', $option->optionName());
        $this->assertSame('Large', $option->valueName());
        $this->assertSame('16.00', (string) $item->price()?->getAmount());
        $this->assertSame('32.00', (string) $item->subtotal()?->getAmount());

        $this->kirby->setCurrentLanguage('pt');
        $translated = $this->cart();
        $this->assertSame($cart->revision(), $translated->revision());
        $translatedOption = $translated->items()[0]->options()[0];
        $this->assertSame('size', $translatedOption->optionId());
        $this->assertSame('large', $translatedOption->valueId());
        $this->assertSame('Tamanho', $translatedOption->optionName());
        $this->assertSame('Grande', $translatedOption->valueName());
        $this->assertSame('Size', $option->optionName());

        $state->available = false;
        $unavailable = $this->cart()->items()[0];
        $this->assertTrue($unavailable->hasErrors());
        $this->assertNull($unavailable->image());
        $this->assertSame([], $unavailable->options());
        $this->assertSame(['size' => 'large'], $unavailable->request()->selectedOptions());
    }

    public function testStaleObjectsAndSubmittedRevisionsCannotOverwriteNewerSelections(): void
    {
        $page = $this->product();
        $cart = $this->cart()->add($page->id());
        $otherTab = $this->cart();
        $submittedRevision = $cart->revision();
        $cart->add($page->id());

        try {
            $otherTab->clear();
            $this->fail('Expected a revision conflict.');
        } catch (CartException $error) {
            $this->assertSame('cart.revision_conflict', $error->errorCode());
            $this->assertSame(2, $error->cart()?->totalQuantity());
            $this->assertNull($error->getPrevious());
        }

        try {
            $cart->remove($cart->items()[0]->id(), $submittedRevision);
            $this->fail('Expected a submitted revision conflict.');
        } catch (CartException $error) {
            $this->assertSame('cart.revision_conflict', $error->errorCode());
        }

        $this->assertSame(2, $this->cart()->totalQuantity());
    }

    public function testInvalidMutationsLeaveSelectionsUnchanged(): void
    {
        $cart = $this->cart()->add($this->product()->id());
        $revision = $cart->revision();

        foreach ([
            fn() => $cart->add('missing'),
            fn() => $cart->add('shirt', 0),
            fn() => $cart->update($cart->items()[0]->id(), 0),
            fn() => $cart->remove('missing'),
            fn() => $cart->clear(''),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected a rejected operation.');
            } catch (CartException $error) {
                $this->assertStringStartsWith('cart.', $error->errorCode());
            }
            $this->assertSame($revision, $this->cart()->revision());
        }
    }

    public function testChangedPricesAndDeletedProductsAreResolvedWithoutChangingRevision(): void
    {
        $page = $this->product();
        $cart = $this->cart()->add($page->id(), 2);
        $revision = $cart->revision();
        $page = $page->update(['price' => '20.50']);
        $this->assertSame('41.00', (string) $this->cart()->subtotal()?->getAmount());
        $page->delete();
        $invalid = $this->cart();
        $this->assertSame($revision, $invalid->revision());
        $this->assertSame(2, $invalid->totalQuantity());
        $this->assertTrue($invalid->hasErrors());
        $this->assertNull($invalid->subtotal());
        $this->assertNull($invalid->items()[0]->product());
        $this->assertNull($invalid->items()[0]->price());
        $this->assertNull($invalid->items()[0]->subtotal());
        $this->assertSame($invalid->items()[0]->id(), $invalid->errors()[0]->itemId());
        $invalid->remove($invalid->items()[0]->id());
        $this->assertFalse($invalid->hasErrors());
    }

    public function testMissingCurrencyProducesAnErrorInsteadOfAFalseZeroSubtotal(): void
    {
        $this->restart(['programmatordev.stripe-checkout' => ['settings' => ['currency' => null]]]);
        $cart = $this->cart();
        $this->assertTrue($cart->hasErrors());
        $this->assertNull($cart->currency());
        $this->assertNull($cart->subtotal());
        $this->assertSame('cart.configuration_invalid', $cart->errors()[0]->code());
        $cart->clear();
        $this->assertTrue($cart->isEmpty());
    }

    public function testBrokenStoreConfigurationDoesNotPreventRemovingStoredSelections(): void
    {
        $this->restart(['programmatordev.stripe-checkout' => ['settings' => ['currency' => 'invalid']]]);
        $store = new KirbySessionCartStore($this->kirby->session(), Uuid::generate(...));
        $store->mutate(static fn(CartSnapshot $current): CartSnapshot => new CartSnapshot(
            $current->id(),
            'saved-revision',
            [new CartEntry('saved-item', new ProductRequest('old-product'))],
            $current->createdAt(),
            $current->updatedAt(),
        ));
        $cart = $this->cart();
        $this->assertTrue($cart->hasErrors());
        $this->assertSame(1, $cart->totalQuantity());
        $cart->remove('saved-item');
        $this->assertTrue($cart->isEmpty());
        $this->assertNull($cart->subtotal());
    }

    public function testSiteScopedApiDoesNotReadAnotherActiveAppsCart(): void
    {
        $plugin = new StripeCheckout($this->kirby);
        $first = $this->cart()->add($this->product()->id());
        $other = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => ['settings' => ['currency' => 'JPY']],
        ]);

        try {
            $second = (new StripeCheckout($other->app()))->cart();
            $this->assertNotNull($second);
            $this->assertTrue($second->isEmpty());
            $this->assertSame('JPY', $second->currency()?->getCurrencyCode());
            $reread = $plugin->cart();
            $this->assertNotNull($reread);
            $this->assertSame($first->revision(), $reread->revision());
            $this->assertSame('EUR', $reread->currency()?->getCurrencyCode());
            $this->assertSame('16.00', (string) $reread->subtotal()?->getAmount());
        } finally {
            $other->close();
            \Kirby\Cms\App::instance($this->kirby);
        }
    }

    public function testPartialFailuresNeverReturnAPartialSubtotal(): void
    {
        $this->restart(['programmatordev.stripe-checkout' => ['products' => ['resolver' => static fn(ProductRequest $request): ResolvedProduct => new ResolvedProduct(
            $request,
            'Product',
            false,
            new InlinePrice(Money::of('16.00', 'EUR')),
        )]]]);
        $cart = $this->cart()->add('first')->add('second', PHP_INT_MAX - 1);
        $this->assertSame(PHP_INT_MAX, $cart->totalQuantity());
        $this->assertFalse($cart->items()[0]->hasErrors());
        $this->assertTrue($cart->items()[1]->hasErrors());
        $this->assertNull($cart->subtotal());
        $remaining = $cart->remove($cart->items()[1]->id());
        $this->assertSame('16.00', (string) $remaining->subtotal()?->getAmount());
    }

    public function testResolutionUsesCurrentLanguageAndUserAndSanitizesFailures(): void
    {
        $state = new class {
            public bool $fail = false;
        };
        $this->restart(['programmatordev.stripe-checkout' => [
            'products' => ['resolver' => static function (ProductRequest $request, ProductResolutionContext $context) use ($state): ResolvedProduct {
                if ($state->fail) {
                    throw new RuntimeException('secret_token private/customer/data');
                }

                return new ResolvedProduct($request, $context->languageCode() . ':' . ($context->user()?->id() ?? 'guest'), false, new InlinePrice(Money::of('2.00', 'EUR')));
            }],
        ]], languages: [
            ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English'],
            ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português'],
        ]);
        $this->kirby->setCurrentLanguage('en');
        $cart = $this->cart()->add('external-product');
        $this->assertSame('en:kirby', $cart->items()[0]->product()?->name());
        $revision = $cart->revision();
        $this->kirby->setCurrentLanguage('pt');
        $this->kirby->impersonate(null);
        $this->assertSame('pt:guest', $this->cart()->items()[0]->product()?->name());
        $this->assertSame($revision, $this->cart()->revision());
        $state->fail = true;
        $invalid = $this->cart();
        $this->assertSame('Este produto ou as opções escolhidas já não estão disponíveis.', $invalid->errors()[0]->message());
        $this->assertStringNotContainsString('secret_token', serialize($invalid->errors()));
        $invalid->clear();
        $this->assertTrue($invalid->isEmpty());
    }

    public function testStoredPayloadContainsSelectionsOnlyAndPreservesUnrelatedSessionData(): void
    {
        $this->kirby->session()->data()->set('unrelated', ['hello' => 'world']);
        $this->cart()->add($this->product()->id());
        $payload = $this->kirby->session()->data()->get(KirbySessionCartStore::KEY);
        $this->assertIsArray($payload);
        $this->assertSame(['schema', 'id', 'revision', 'createdAt', 'updatedAt', 'destinationCountry', 'entries'], array_keys($payload));
        $this->assertStringNotContainsString('16.00', serialize($payload));
        $this->assertSame(['hello' => 'world'], $this->kirby->session()->data()->get('unrelated'));
    }

    public function testNativeLoginLogoutAndAnotherLoginPreserveTheGuestCart(): void
    {
        $cart = $this->cart()->add($this->product()->id(), 2);
        $firstUser = $this->kirby->users()->create(['email' => 'first@example.test', 'role' => 'admin', 'password' => 'test-password-123']);
        $secondUser = $this->kirby->users()->create(['email' => 'second@example.test', 'role' => 'admin', 'password' => 'test-password-456']);
        $this->kirby->impersonate(null);
        $session = $this->kirby->session();
        $token = $session->token();
        $firstUser->loginPasswordless();
        $this->assertNotSame($token, $session->token());
        $this->assertSame($cart->revision(), $this->cart()->revision());
        $this->assertSame(2, $this->cart()->totalQuantity());
        $token = $session->token();
        $firstUser->logout();
        $this->assertNotSame($token, $session->token());
        $this->assertSame($cart->revision(), $this->cart()->revision());
        $secondUser->loginPasswordless();
        $this->assertSame($cart->revision(), $this->cart()->revision());
        $this->assertSame($cart->items()[0]->id(), $this->cart()->items()[0]->id());
        $this->assertSame(300, $session->timeout());
    }

    public function testStripePricesAreRetrievedFreshAndUnavailablePricesCannotBeAdded(): void
    {
        $this->restart(['programmatordev.stripe-checkout' => [
            'settings' => ['priceSource' => 'stripe'],
            'stripe' => ['secretKey' => 'sk_test_cart_fixture'],
        ]]);
        $page = $this->product()->update(['stripePrice' => 'price_fixture']);
        $amount = 2000;
        $active = true;
        $requests = 0;
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturnCallback(static function () use (&$amount, &$active, &$requests): array {
            $requests++;

            return [json_encode([
                'id' => 'price_fixture', 'object' => 'price', 'active' => $active,
                'billing_scheme' => 'per_unit', 'currency' => 'eur', 'type' => 'one_time',
                'unit_amount' => $amount, 'unit_amount_decimal' => (string) $amount,
                'custom_unit_amount' => null, 'nickname' => null, 'recurring' => null,
                'tax_behavior' => 'unspecified', 'tiers_mode' => null, 'transform_quantity' => null,
                'product' => [
                    'id' => 'prod_fixture', 'object' => 'product', 'active' => true, 'name' => 'Stripe shirt',
                    'description' => null, 'images' => [], 'tax_code' => null,
                ],
            ], JSON_THROW_ON_ERROR), 200, []];
        });
        ApiRequestor::setHttpClient($client);
        $cart = $this->cart()->add($page->id(), 2);
        $this->assertSame('40.00', (string) $cart->subtotal()?->getAmount());
        $amount = 2500;
        $this->assertSame('50.00', (string) $this->cart()->subtotal()?->getAmount());
        $this->assertSame($cart->revision(), $this->cart()->revision());
        $active = false;
        $this->assertNull($this->cart()->subtotal());
        $this->assertTrue($this->cart()->hasErrors());

        try {
            $cart->add($page->id());
            $this->fail('An archived price cannot be added.');
        } catch (CartException $error) {
            $this->assertSame('cart.product_unavailable', $error->errorCode());
        }

        $beforeClear = $requests;
        $cart->clear();
        $this->assertSame($beforeClear, $requests);
        $this->assertTrue($cart->isEmpty());
    }

    public function testCartErrorsRespectConfiguredTranslationOverrides(): void
    {
        $this->restart(['locale' => 'pt_PT', 'programmatordev.stripe-checkout' => [
            'settings' => ['currency' => null],
            'translations' => ['pt_PT' => ['cart.configuration_invalid' => 'A loja não está pronta.']],
        ]]);
        $this->assertSame('A loja não está pronta.', $this->cart()->errors()[0]->message());
    }

    private function product(): Page
    {
        return $this->kirby->site()->createChild([
            'slug' => 'shirt', 'template' => 'default',
            'content' => ['title' => 'Shirt', 'price' => '16.00'],
        ])->changeStatus('unlisted');
    }

    private function cart(): Cart
    {
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);

        return $cart;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<array<string, mixed>>|null $languages
     */
    private function restart(array $options = [], ?array $languages = null): void
    {
        $this->environment->close();
        /** @var array<string, mixed> $mergedOptions */
        $mergedOptions = array_replace_recursive([
            'session' => ['durationNormal' => 900, 'timeout' => 300],
            'programmatordev.stripe-checkout' => ['settings' => ['currency' => 'EUR', 'defaultRequiresShipping' => false]],
        ], $options);
        $this->environment = KirbyTestEnvironment::start(options: $mergedOptions, languages: $languages);
        $this->kirby = $this->environment->app();
    }
}
