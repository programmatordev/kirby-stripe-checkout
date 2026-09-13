<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use Kirby\Cms\Page;
use Kirby\Content\Field;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Cart\Exception\CartException;
use ProgrammatorDev\StripeCheckout\Configuration\StripeConfiguration;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeCatalogue;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeListResult;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeRecord;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\Stripe\FakeTaxProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

final class ProductTaxCodeTest extends KirbyTestCase
{
    private const PREFIX = 'programmatordev.stripe-checkout';

    protected function setUp(): void
    {
        parent::setUp();

        $this->restart();
    }

    #[DataProvider('omittedCodes')]
    public function testOmittedCodeNeedsNeitherCatalogueNorCredentials(?string $code): void
    {
        $page = $this->product(['taxCode' => $code]);
        $runtime = new RuntimeFactory($this->kirby);

        $this->assertNull($runtime->resolveProduct(new ProductRequest($page->id()))->taxCode());
        $this->assertSame([], $runtime->taxCodeCatalogue()->cached()['items']);
    }

    /** @return iterable<string, array{?string}> */
    public static function omittedCodes(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ['  '];
    }

    public function testMappedCodeUsesDefaultLanguageAndLastGoodMembership(): void
    {
        $this->restart(
            ['fields' => ['taxCode' => 'classification']],
            languages: [
                ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English'],
                ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português'],
            ],
        );
        $page = $this->product(['classification' => ' txcd_test ', 'taxCode' => 'txcd_unused']);
        $page = $page->update(['classification' => 'txcd_wrong_language'], 'pt');
        $this->kirby->setCurrentLanguage('pt');
        $provider = $this->seedCatalogue();
        $provider->failLists = true;
        $catalogue = $this->catalogue($provider);
        $catalogue->refresh();

        $product = (new RuntimeFactory($this->kirby))->resolveProduct(new ProductRequest($page->id()));

        $this->assertSame('txcd_test', $product->taxCode()?->id());
        $this->assertSame([null, null], $provider->listCursors);
        $this->assertSame('tax_codes.refresh_failed', $catalogue->cached()['error']);
    }

    public function testVariantsInheritTheProductClassification(): void
    {
        $page = $this->product([
            'taxCode' => 'txcd_test',
            'options' => [
                'options' => [[
                    'id' => 'sizeOption000001',
                    'label' => 'Size',
                    'values' => [['id' => 'largeValue00001', 'label' => 'Large']],
                ]],
                'variants' => [[
                    'id' => 'largeVariant001',
                    'selectedOptions' => ['sizeOption000001' => 'largeValue00001'],
                    'enabled' => true,
                    'price' => '24',
                    'stripePriceId' => null,
                    'sku' => 'SHIRT-L',
                    'requiresShipping' => 'inherit',
                ]],
            ],
        ]);
        $provider = $this->seedCatalogue();
        $product = (new RuntimeFactory($this->kirby))->resolveProduct(new ProductRequest(
            reference: $page->id(),
            selectedOptions: ['sizeOption000001' => 'largeValue00001'],
        ));

        $this->assertSame('largeVariant001', $product->variantId());
        $this->assertSame('txcd_test', $product->taxCode()?->id());
        $this->assertSame([null], $provider->listCursors);
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('inactiveSettings')]
    public function testInactiveClassificationDoesNotValidateRetainedContent(array $settings): void
    {
        $this->restart(settings: $settings);
        $page = $this->product(['taxCode' => ['invalid'], 'stripePrice' => 'price_test']);

        $this->assertNull((new RuntimeFactory($this->kirby))->resolveProduct(new ProductRequest($page->id()))->taxCode());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function inactiveSettings(): iterable
    {
        yield 'Automatic Tax off' => [['automaticTax' => false]];
        yield 'Stripe Price authority' => [['priceSource' => 'stripe']];
    }

    #[DataProvider('invalidCodes')]
    public function testInvalidOrUnconfirmedCodesFailWithSafeProductErrors(mixed $code, bool $seed, string $errorCode): void
    {
        $page = $this->product(['taxCode' => $code]);
        $provider = $seed ? $this->seedCatalogue() : null;

        try {
            (new RuntimeFactory($this->kirby))->resolveProduct(new ProductRequest($page->id()));
            $this->fail('Expected an invalid classification to fail.');
        } catch (InvalidProductException $error) {
            $this->assertSame($errorCode, $error->errorCode());
        }

        if ($provider !== null) {
            $this->assertSame([null], $provider->listCursors);
        }
    }

    /** @return iterable<string, array{mixed, bool, string}> */
    public static function invalidCodes(): iterable
    {
        yield 'malformed ID' => ['wrong', false, 'tax.code_invalid'];
        yield 'non-scalar content' => [['txcd_test'], false, 'tax.code_invalid'];
        yield 'missing catalogue' => ['txcd_test', false, 'tax.catalogue_unavailable'];
        yield 'unknown exact ID' => ['txcd_unknown', true, 'tax.code_invalid'];
    }

    public function testRefreshedRemovalInvalidatesButDoesNotEditTheStoredCode(): void
    {
        $page = $this->product(['taxCode' => 'txcd_test']);
        $provider = $this->seedCatalogue();
        $runtime = new RuntimeFactory($this->kirby);
        $this->assertSame('txcd_test', $runtime->resolveProduct(new ProductRequest($page->id()))->taxCode()?->id());
        $provider->pages = ['first' => new TaxCodeListResult([], false)];
        $this->catalogue($provider)->refresh();

        try {
            $runtime->resolveProduct(new ProductRequest($page->id()));
            $this->fail('Expected a removed code to fail.');
        } catch (InvalidProductException $error) {
            $this->assertSame('tax.code_invalid', $error->errorCode());
        }

        $field = $page->content()->get('taxCode');
        $this->assertInstanceOf(Field::class, $field);
        $this->assertSame('txcd_test', $field->value());
    }

    public function testProductClassificationUsesOnlyTheConfiguredCredentialCatalogue(): void
    {
        $secretKey = 'sk_test_product_tax';
        $this->restart(secretKey: $secretKey);
        $page = $this->product(['taxCode' => 'txcd_test']);
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->never())->method('request');
        ApiRequestor::setHttpClient($client);
        $this->seedCatalogue($secretKey);
        $foreignProvider = new FakeTaxProvider(pages: [
            'first' => new TaxCodeListResult([new TaxCodeRecord('txcd_foreign', 'Foreign category', '')], false),
        ]);
        $this->catalogue($foreignProvider, 'sk_test_foreign')->refresh();
        $runtime = new RuntimeFactory($this->kirby);

        $this->assertSame('txcd_test', $runtime->resolveProduct(new ProductRequest($page->id()))->taxCode()?->id());
        $page = $page->update(['taxCode' => 'txcd_foreign']);

        $this->expectException(InvalidProductException::class);
        $this->expectExceptionMessage('tax.code_invalid');
        $runtime->resolveProduct(new ProductRequest($page->id()));
    }

    public function testCredentialRotationNeedsItsOwnCatalogueWithoutFetchingDuringResolution(): void
    {
        $secretKey = 'sk_test_product_tax';
        $this->restart(secretKey: $secretKey);
        $page = $this->product(['taxCode' => 'txcd_test']);
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->never())->method('request');
        ApiRequestor::setHttpClient($client);
        $this->seedCatalogue($secretKey);
        $this->assertSame('txcd_test', (new RuntimeFactory($this->kirby))->resolveProduct(new ProductRequest($page->id()))->taxCode()?->id());

        // Keep the same content and cache root while changing only the credentials.
        $rotatedSecretKey = 'sk_test_rotated_product_tax';
        $this->kirby = $this->kirby->clone([
            'options' => [self::PREFIX => ['stripe' => ['secretKey' => $rotatedSecretKey]]],
        ]);
        $runtime = new RuntimeFactory($this->kirby);

        try {
            $runtime->resolveProduct(new ProductRequest($page->id()));
            $this->fail('A previous credential catalogue cannot confirm the rotated credentials.');
        } catch (InvalidProductException $error) {
            $this->assertSame('tax.catalogue_unavailable', $error->errorCode());
        }

        $this->seedCatalogue($rotatedSecretKey);
        $this->assertSame('txcd_test', $runtime->resolveProduct(new ProductRequest($page->id()))->taxCode()?->id());
    }

    public function testMissingCatalogueRemainsATemporaryCartErrorOnReadsAndMutations(): void
    {
        $page = $this->product(['taxCode' => 'txcd_test']);
        $this->seedCatalogue();
        $cart = (new RuntimeFactory($this->kirby))->cart();
        $this->assertNotNull($cart);
        $cart->add($page->id());
        $this->kirby->cache(self::PREFIX . '.taxCodes')->remove('unconfigured');
        $cart = (new RuntimeFactory($this->kirby))->cart();
        $this->assertNotNull($cart);

        $this->assertSame('cart.provider_unavailable', $cart->errors()[0]->code());
        $this->assertSame('Product information is temporarily unavailable. Please try again.', $cart->errors()[0]->message());
        $this->assertNull($cart->subtotal());

        try {
            $cart->add($page->id());
            $this->fail('A classification without a catalogue cannot be added.');
        } catch (CartException $error) {
            $this->assertSame('cart.provider_unavailable', $error->errorCode());
        }
    }

    public function testCustomResolversCannotBypassMembershipWithAConfirmationFlag(): void
    {
        $this->restart(['resolver' => static fn(ProductRequest $request): Product => new Product(
            request: $request,
            name: 'Custom product',
            requiresShipping: false,
            price: new Price(Money::of('16', 'EUR')),
            taxCode: new TaxCode('txcd_unknown', providerName: 'Unknown', confirmed: true),
        )]);
        $this->seedCatalogue();
        $this->expectException(InvalidProductException::class);
        $this->expectExceptionMessage('tax.code_invalid');
        (new RuntimeFactory($this->kirby))->resolveProduct(new ProductRequest('external:42'));
    }

    public function testCustomResolversCanClassifyAnEffectiveVariant(): void
    {
        $this->restart(['resolver' => static fn(ProductRequest $request, ProductResolutionContext $context): Product => new Product(
            request: $request,
            name: 'Custom variant',
            requiresShipping: false,
            price: new Price(Money::of('16', $context->settings()->currency() ?? 'EUR')),
            taxCode: new TaxCode('txcd_test'),
        )]);
        $provider = $this->seedCatalogue();
        $product = (new RuntimeFactory($this->kirby))->resolveProduct(new ProductRequest('external:42'));

        $this->assertSame('txcd_test', $product->taxCode()?->id());
        $this->assertSame([null], $provider->listCursors);
    }

    /** @param array<string, mixed> $content */
    private function product(array $content = []): Page
    {
        return $this->kirby->site()->createChild([
            'slug' => 'tax-product',
            'template' => 'default',
            'content' => [
                'title' => 'Tax product',
                'price' => '16',
                ...$content,
            ],
        ])->changeStatus('listed');
    }

    private function seedCatalogue(?string $secretKey = null): FakeTaxProvider
    {
        $provider = new FakeTaxProvider(pages: [
            'first' => new TaxCodeListResult([new TaxCodeRecord('txcd_test', 'Test category', 'Test description')], false),
        ]);
        $this->catalogue($provider, $secretKey)->refresh();

        return $provider;
    }

    private function catalogue(FakeTaxProvider $provider, ?string $secretKey = null): TaxCodeCatalogue
    {
        // Seed the runtime's credential namespace with an explicit offline fake.
        $stripe = new StripeConfiguration(secretKey: $secretKey, publishableKey: null, webhookSecret: null);

        return new TaxCodeCatalogue(
            cache: $this->kirby->cache(self::PREFIX . '.taxCodes'),
            provider: $provider,
            cacheKey: $secretKey === null ? 'unconfigured' : $stripe->secretKeyFingerprint('tax-codes'),
        );
    }

    /**
     * @param array<string, mixed> $products
     * @param array<string, mixed> $settings
     * @param list<array<string, mixed>>|null $languages
     */
    private function restart(array $products = [], array $settings = [], ?array $languages = null, ?string $secretKey = null): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => false,
                    'automaticTax' => true,
                    ...$settings,
                ],
                'products' => $products,
                'stripe' => ['secretKey' => $secretKey],
            ],
        ], languages: $languages);
        $this->kirby = $this->environment->app();
    }
}
