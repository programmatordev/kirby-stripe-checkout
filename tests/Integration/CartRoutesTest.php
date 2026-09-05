<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Http\Environment;
use Kirby\Http\Request;
use Kirby\Http\Response;
use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Cart\CartOperation;
use ProgrammatorDev\StripeCheckout\Cart\CartRenderContext;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartResponseMapper;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\InlinePrice;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ReflectionProperty;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

final class CartRoutesTest extends KirbyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->restart();
    }

    public function testGuestRoutesUseThePhpCartAndExactPrivateProjection(): void
    {
        $product = $this->product();
        $this->kirby->impersonate(null);
        $read = $this->send('GET');
        $this->assertSame(200, $read->code());
        $this->assertSame('no-store, private', $read->headers()['Cache-Control']);
        $this->assertSame('Accept', $read->headers()['Vary']);
        $this->assertSame('0.00', $this->data($read, 'data.cart.subtotal.amount'));
        $added = $this->send('POST', '/items', ['reference' => $product->id()]);
        $this->assertSame(200, $added->code());
        $itemId = $this->data($added, 'data.cart.items.0.id');
        $this->assertIsString($itemId);
        $this->assertSame(['amount' => '16.00', 'currency' => 'EUR'], $this->data($added, 'data.cart.items.0.price'));
        $this->assertSame([], $this->data($added, 'data.cart.items.0.product.options'));
        $this->assertStringContainsString('"options":{}', $added->body());
        $this->assertNull($this->data($added, 'data.cart.items.0.product.metadata'));
        $this->assertNull($this->data($added, 'data.cart.id'));

        $cart = $this->cart()->add($product->uuid()->toString(), 2);
        $read = $this->send('GET');
        $this->assertSame(json_decode(json_encode(CartResponseMapper::cart($cart), JSON_THROW_ON_ERROR), true), $this->data($read, 'data.cart'));
        $updated = $this->send('PATCH', '/items/' . $itemId, ['quantity' => 4, 'revision' => $cart->revision()]);
        $this->assertSame(200, $updated->code());
        $this->assertSame(4, $this->cart()->totalQuantity());
        $this->assertSame('64.00', $this->data($updated, 'data.cart.subtotal.amount'));
        $removed = $this->send('DELETE', '/items/' . $itemId, ['revision' => $this->cart()->revision()]);
        $this->assertTrue($this->data($removed, 'data.cart.empty'));
        $this->cart()->add($product->id());
        $cleared = $this->send('DELETE', '', ['revision' => $this->cart()->revision()]);
        $this->assertSame(200, $cleared->code());
        $this->assertTrue($this->cart()->isEmpty());
    }

    public function testStaleWritesReturnCurrentCartAndDoNotMutate(): void
    {
        $cart = $this->cart()->add($this->product()->id());
        $itemId = $cart->items()[0]->id();
        $stale = $cart->revision();
        $cart->update($itemId, 3);

        foreach ([['PATCH', '/items/' . $itemId, ['quantity' => 5]], ['DELETE', '/items/' . $itemId, []], ['DELETE', '', []]] as [$method, $path, $body]) {
            $response = $this->send($method, $path, [...$body, 'revision' => $stale]);
            $this->assertSame(409, $response->code());
            $this->assertSame('cart.revision_conflict', $this->data($response, 'error.code'));
            $this->assertSame('revision', $this->data($response, 'error.field'));
            $this->assertSame($cart->revision(), $this->data($response, 'data.cart.revision'));
        }

        $this->assertSame(3, $this->cart()->totalQuantity());
        $response = $this->send('DELETE', '/items/foreign-id', ['revision' => $cart->revision()]);
        $this->assertSame(404, $response->code());
    }

    public function testCsrfCannotComeFromQueryOrAnAmbiguousTransport(): void
    {
        $reference = $this->product()->id();
        $body = ['reference' => $reference];
        $this->assertSame(403, $this->send('POST', '/items', $body, csrf: false)->code());
        $this->assertSame(403, $this->send('POST', '/items', $body, headers: ['X-CSRF' => 'wrong'])->code());
        $token = $this->kirby->csrf();
        $this->assertIsString($token);
        $form = http_build_query([...$body, 'csrf' => $token]);
        $this->assertSame(403, $this->send('POST', '/items', $form, headers: ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF' => 'wrong'])->code());
        $this->assertSame(403, $this->send('POST', '/items', $body, csrf: false, query: ['csrf' => $token])->code());
        $this->assertTrue($this->cart()->isEmpty());
        $response = $this->send('POST', '/items', $form, headers: ['Content-Type' => 'application/x-www-form-urlencoded'], csrf: false);
        $this->assertSame(200, $response->code());
        $response = $this->send('POST', '/items', $form, headers: ['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->assertSame(200, $response->code());
        $this->assertSame(2, $this->cart()->totalQuantity());
    }

    public function testStrictBodiesAndProtectedFactsAreRejected(): void
    {
        $product = $this->product();

        foreach (['', '{', '[]', 'null', '"test"', 'true', 'reference=shirt'] as $body) {
            $this->assertSame(400, $this->send('POST', '/items', $body)->code(), $body);
        }

        $this->assertSame(415, $this->send('POST', '/items', '{}', ['Content-Type' => 'text/plain'])->code());
        $this->assertSame(422, $this->send('POST', '/items', '{"reference":"shirt"}', ['Content-Type' => 'application/x-www-form-urlencoded'])->code());

        foreach ([
            ['request' => ['reference' => $product->id()]],
            ['reference' => $product->id(), 'options' => null],
            ['reference' => $product->id(), 'options' => []],
            ['reference' => $product->id(), 'price' => '0.01'],
            ['reference' => $product->id(), 'userUuid' => 'foreign'],
            ['reference' => $product->id(), 'options' => ['bad']],
            ['reference' => $product->id(), 'quantity' => '2'],
            ['reference' => $product->id(), 'quantity' => null],
            ['reference' => $product->id(), 'quantity' => 0],
            ['reference' => $product->id(), 'quantity' => 1.5],
        ] as $body) {
            $this->assertSame(422, $this->send('POST', '/items', $body)->code());
        }

        $this->assertSame(422, $this->send('DELETE', '', [])->code());
        $this->assertSame(422, $this->send('POST', '/items', http_build_query(['request' => ['reference' => $product->id()]]), ['Content-Type' => 'application/x-www-form-urlencoded'])->code());
        $this->assertTrue($this->cart()->isEmpty());
        $draft = $this->kirby->site()->createChild(['slug' => 'draft', 'template' => 'default', 'content' => ['title' => 'Hidden', 'price' => '10']]);

        foreach (['unknown', $draft->id()] as $reference) {
            $response = $this->send('POST', '/items', ['reference' => $reference]);
            $this->assertSame(422, $response->code());
            $this->assertSame('product.unavailable', $this->data($response, 'error.code'));
        }
    }

    public function testFormQuantityNormalizationAndRequiredRevision(): void
    {
        $reference = $this->product()->id();
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'];
        $response = $this->send('POST', '/items', http_build_query(['reference' => $reference, 'quantity' => '3']), $headers);
        $this->assertSame(200, $response->code());
        $itemId = $this->cart()->items()[0]->id();
        $this->assertSame(422, $this->send('PATCH', '/items/' . $itemId, ['quantity' => 4])->code());

        foreach (['0', '-1', '1.2', '2e1', str_repeat('9', 30)] as $quantity) {
            $body = http_build_query(['revision' => $this->cart()->revision(), 'quantity' => $quantity]);
            $this->assertSame(422, $this->send('PATCH', '/items/' . $itemId, $body, $headers)->code());
        }

        $this->assertSame(3, $this->cart()->totalQuantity());
    }

    public function testOptionsUseTheCartVocabularyInJsonAndForms(): void
    {
        $this->restart(['products' => ['resolver' => static fn(ProductRequest $request): ResolvedProduct => new ResolvedProduct(
            $request,
            'Shirt',
            false,
            new InlinePrice(Money::of('16', 'EUR')),
            selectedOptions: [new SelectedOption('size', 'Size', 'large', 'Large')],
            variantId: 'large-variant',
        )]]);
        $selection = ['reference' => 'external', 'options' => ['size' => 'large']];
        $response = $this->send('POST', '/items', $selection);
        $this->assertSame(200, $response->code());
        $this->assertSame(['size' => 'large'], $this->data($response, 'data.cart.items.0.request.options'));
        $this->assertSame('Large', $this->data($response, 'data.cart.items.0.product.options.0.valueName'));
        $this->assertStringNotContainsString('selectedOptions', $response->body());
        $response = $this->send('POST', '/items', http_build_query($selection), ['Content-Type' => 'application/x-www-form-urlencoded']);
        $this->assertSame(200, $response->code());
        $this->assertSame(2, $this->data($response, 'data.cart.totalQuantity'));
        $response = $this->send('POST', '/items', ['reference' => 'external', 'selectedOptions' => ['size' => 'large']]);
        $this->assertSame(422, $response->code());
        $this->assertSame(2, $this->cart()->totalQuantity());
    }

    public function testHtmlNegotiationAndContextUseTheSameResult(): void
    {
        $calls = [];
        $this->restart(['cart' => ['renderer' => static function (?Cart $cart, CartRenderContext $context) use (&$calls): string {
            $calls[] = $context;
            return '<div data-revision="' . $cart?->revision() . '">' . ($cart?->totalQuantity() ?? 'unavailable') . '</div>';
        }]]);
        $product = $this->product();

        foreach ([null, '*/*', 'application/json', 'application/json;q=1,text/html;q=0.5'] as $accept) {
            $this->assertSame('application/json', $this->send('GET', headers: $accept === null ? [] : ['Accept' => $accept])->type());
        }

        $this->assertCount(0, $calls);
        $response = $this->send('POST', '/items', ['reference' => $product->id()], ['Accept' => 'text/html']);
        $this->assertSame(200, $response->code());
        $this->assertStringContainsString('>1</div>', $response->body());
        /** @var list<CartRenderContext> $calls */
        $this->assertSame(CartOperation::Add, $calls[0]->operation());
        $this->assertSame(200, $calls[0]->status());
        $this->assertNull($calls[0]->error());
        $response = $this->send('DELETE', '', ['revision' => 'stale'], ['Accept' => 'text/html']);
        $this->assertSame(409, $response->code());
        $this->assertSame('cart.revision_conflict', $calls[1]->error()?->code());
        $this->assertStringContainsString('>1</div>', $response->body());
        $this->assertSame('text/html', $this->send('GET', headers: ['Accept' => 'application/json;q=0,*/*;q=1'])->type());
        $this->assertSame(406, $this->send('GET', headers: ['Accept' => 'application/json;q=0,text/html;q=0'])->code());
    }

    public function testMissingRendererRejectsBeforeMutationAndRendererFailureDoesNotInviteRetry(): void
    {
        $product = $this->product();
        $this->assertSame(406, $this->send('POST', '/items', ['reference' => $product->id()], ['Accept' => 'text/html'])->code());
        $this->assertSame(406, $this->send('GET', headers: ['Accept' => 'image/png'])->code());
        $this->assertTrue($this->cart()->isEmpty());
        $this->restart(['cart' => ['renderer' => static function (): never {
            throw new RuntimeException('SECRET');
        }]]);
        $product = $this->product();
        $response = $this->send('POST', '/items', ['reference' => $product->id()], ['Accept' => 'text/html']);
        $this->assertSame(204, $response->code());
        $this->assertSame('', $response->body());
        $this->assertSame(1, $this->cart()->totalQuantity());
        $this->assertSame(500, $this->send('GET', headers: ['Accept' => 'text/html'])->code());
        $this->assertSame(409, $this->send('DELETE', '', ['revision' => 'stale'], ['Accept' => 'text/html'])->code());
        $this->assertSame(200, $this->send('GET')->code());
    }

    public function testUnsupportedMethodsAndDisabledRoutes(): void
    {
        foreach ([['POST', ''], ['PATCH', ''], ['GET', '/items'], ['POST', '/items/id'], ['OPTIONS', '']] as [$method, $path]) {
            $response = $this->send($method, $path);
            $this->assertSame(405, $response->code());
            $this->assertArrayHasKey('Allow', $response->headers());
        }

        $this->restart(['cart' => ['enabled' => false]]);
        $this->assertSame([], array_values(array_filter($this->kirby->extensions('routes'), static fn(mixed $route): bool => is_array($route) && is_string($route['pattern'] ?? null) && str_starts_with($route['pattern'], 'stripe-checkout/cart'))));
        $this->assertNull($this->kirby->session()->token());
    }

    public function testRendererConfigurationIsPhpOnlyAndStrict(): void
    {
        $resolver = new ConfigurationResolver();
        $renderer = static fn(): string => '';
        $this->assertSame($renderer, $resolver->cartRenderer(['programmatordev.stripe-checkout.cart.renderer' => $renderer]));
        $this->assertFalse($resolver->resolve(['programmatordev.stripe-checkout' => ['cart' => ['renderer' => 'snippet']]])->isValid());
        $this->restart(['cart' => ['renderer' => 'not-callable']]);
        $this->assertSame(422, $this->send('GET')->code());
    }

    public function testMultilingualRoutesResolveCurrentLanguageWithoutChangingRevision(): void
    {
        $this->restart(['products' => ['resolver' => static fn(ProductRequest $request, ProductResolutionContext $context): ResolvedProduct => new ResolvedProduct(
            $request,
            $context->languageCode() === 'pt' ? 'Camisola' : 'Shirt',
            false,
            new InlinePrice(Money::of('16', 'EUR')),
        )]], languages: [
            ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English', 'url' => '/'],
            ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português', 'url' => '/pt'],
        ]);
        $added = $this->send('POST', '/items', ['reference' => 'external']);
        $this->assertSame(200, $added->code());
        $this->assertSame('Shirt', $this->data($added, 'data.cart.items.0.product.name'));
        $read = $this->send('GET', languagePrefix: 'pt/');
        $this->assertSame('Camisola', $this->data($read, 'data.cart.items.0.product.name'));
        $this->assertSame($this->data($added, 'data.cart.revision'), $this->data($read, 'data.cart.revision'));
        $error = $this->send('DELETE', body: ['revision' => 'stale'], languagePrefix: 'pt/');
        $this->assertSame(409, $error->code());
        $this->assertSame('O carrinho foi alterado. Reveja-o antes de tentar novamente.', $this->data($error, 'error.message'));
    }

    public function testHttpCartSurvivesLoginLogoutButIsNotSharedWithAnotherSession(): void
    {
        $product = $this->product();
        $user = $this->kirby->users()->create(['email' => 'buyer@example.test', 'role' => 'admin', 'password' => 'test-password-123']);
        $this->kirby->impersonate(null);
        $added = $this->send('POST', '/items', ['reference' => $product->id()]);
        $revision = $this->data($added, 'data.cart.revision');
        $user->loginPasswordless();
        $this->assertSame($revision, $this->data($this->send('GET'), 'data.cart.revision'));
        $user->logout();
        $this->assertSame($revision, $this->data($this->send('GET'), 'data.cart.revision'));
        $originalApp = $this->kirby;
        $other = KirbyTestEnvironment::start();

        try {
            $this->kirby = $other->app();
            $this->assertTrue($this->data($this->send('GET'), 'data.cart.empty'));
        } finally {
            $other->close();
            $this->kirby = $originalApp;
            App::instance($originalApp);
        }
    }

    public function testLineLimitAndUnavailableProductsStillAllowRemoval(): void
    {
        $state = new class {
            public bool $available = true;
        };
        $this->restart(['products' => ['resolver' => static function (ProductRequest $request) use ($state): ResolvedProduct {
            if ($state->available === false) {
                throw new InvalidProductException('PRIVATE CUSTOMER DATA');
            }

            return new ResolvedProduct($request, $request->reference(), false, new InlinePrice(Money::of('1', 'EUR')));
        }]]);
        $cart = $this->cart();

        for ($i = 0; $i < 100; $i++) {
            $cart->add('product-' . $i);
        }

        $response = $this->send('POST', '/items', ['reference' => 'product-101']);
        $this->assertSame(422, $response->code());
        $this->assertSame('selection.line_limit_exceeded', $this->data($response, 'error.code'));
        $state->available = false;
        $response = $this->send('GET');
        $this->assertSame(200, $response->code());
        $this->assertTrue($this->data($response, 'data.cart.hasErrors'));
        $this->assertNull($this->data($response, 'data.cart.subtotal'));
        $this->assertNull($this->data($response, 'data.cart.items.0.product'));
        $this->assertStringNotContainsString('PRIVATE', $response->body());
        $this->assertSame(200, $this->send('DELETE', body: ['revision' => $cart->revision()])->code());
    }

    public function testStripeSourceProjectionAndProviderFailureAreSanitized(): void
    {
        $this->restart([
            'settings' => ['priceSource' => 'stripe'],
            'stripe' => ['secretKey' => 'sk_test_fixture'],
            'products' => ['resolver' => static fn(ProductRequest $request): ResolvedProduct => new ResolvedProduct(
                $request,
                'Shirt',
                false,
                new StripePriceReference('price_fixture'),
            )],
        ]);
        $state = new class {
            public bool $fail = false;
        };
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturnCallback(static function () use ($state): array {
            if ($state->fail) {
                throw new RuntimeException('PRIVATE STRIPE KEY');
            }

            return [json_encode([
                'id' => 'price_fixture', 'object' => 'price', 'active' => true, 'type' => 'one_time',
                'billing_scheme' => 'per_unit', 'currency' => 'eur', 'unit_amount' => 1600,
                'unit_amount_decimal' => '1600', 'custom_unit_amount' => null, 'recurring' => null,
                'nickname' => null, 'tax_behavior' => 'unspecified', 'tiers_mode' => null,
                'transform_quantity' => null, 'product' => [
                    'id' => 'prod_fixture', 'object' => 'product', 'active' => true, 'name' => 'Stripe Shirt',
                    'description' => null, 'images' => [], 'tax_code' => null,
                ],
            ], JSON_THROW_ON_ERROR), 200, []];
        });
        ApiRequestor::setHttpClient($client);
        $response = $this->send('POST', '/items', ['reference' => 'external', 'quantity' => 2]);
        $this->assertSame(200, $response->code());
        $this->assertSame('32.00', $this->data($response, 'data.cart.subtotal.amount'));
        $this->assertStringNotContainsString('price_fixture', $response->body());
        $state->fail = true;
        $response = $this->send('POST', '/items', ['reference' => 'external']);
        $this->assertSame(503, $response->code());
        $this->assertSame('product.resolution_unavailable', $this->data($response, 'error.code'));
        $this->assertStringNotContainsString('PRIVATE', $response->body());
        $this->assertSame(200, $this->send('DELETE', body: ['revision' => $this->cart()->revision()])->code());
    }

    public function testDevelopmentSnippetCanRenderTheTypedContext(): void
    {
        $snippet = dirname(__DIR__, 2) . '/site/snippets/cart.php';
        $this->restart(['cart' => ['renderer' => function (?Cart $cart, CartRenderContext $context): string {
            $html = $this->kirby->snippet('cart', ['cart' => $cart, 'context' => $context, 'site' => $this->kirby->site()], true);
            $this->assertIsString($html);
            return $html;
        }]]);
        $root = $this->kirby->root('snippets');
        $this->assertIsString($root);
        Dir::make($root);
        F::copy($snippet, $root . '/cart.php');
        $response = $this->send('GET', headers: ['Accept' => 'text/html']);
        $this->assertSame(200, $response->code());
        $this->assertStringContainsString('Your cart is empty.', $response->body());
    }

    private function product(): Page
    {
        return $this->kirby->site()->createChild(['slug' => 'shirt', 'template' => 'default', 'content' => ['title' => 'Shirt', 'price' => '16.00']])->changeStatus('unlisted');
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
        $this->environment = KirbyTestEnvironment::start(options: ['programmatordev.stripe-checkout' => array_replace_recursive([
            'settings' => ['currency' => 'EUR', 'defaultRequiresShipping' => false],
        ], $options)], languages: $languages);
        $this->kirby = $this->environment->app();
    }

    /**
     * @param array<string, mixed>|string $body
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    private function send(string $method, string $path = '', array|string $body = [], array $headers = [], bool $csrf = true, array $query = [], string $languagePrefix = ''): Response
    {
        $server = $_SERVER;
        $environmentInfo = new ReflectionProperty(Environment::class, 'info');
        $originalInfo = $environmentInfo->getValue($this->kirby->environment());

        try {
            foreach (['HTTP_ACCEPT', 'HTTP_CONTENT_TYPE', 'HTTP_X_CSRF', 'CONTENT_TYPE'] as $key) {
                unset($_SERVER[$key]);
            }

            if ($csrf) {
                $token = $this->kirby->csrf();
                $this->assertIsString($token);
                $_SERVER['HTTP_X_CSRF'] = $token;
            }

            $_SERVER['CONTENT_TYPE'] = 'application/json';

            foreach ($headers as $key => $value) {
                $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
            }

            // Kirby caches environment headers; refresh that snapshot as well as
            // the Request when simulating several HTTP requests in one PHP process.
            $environmentInfo->setValue($this->kirby->environment(), $_SERVER);
            // Dispatch through Kirby's real router, retaining one browser session.
            (new ReflectionProperty(App::class, 'request'))->setValue($this->kirby, new Request([
                'method' => $method, 'body' => is_array($body) ? json_encode((object) $body, JSON_THROW_ON_ERROR) : $body, 'query' => $query,
            ]));
            $response = $this->kirby->call($languagePrefix . 'stripe-checkout/cart' . $path, $method);
            $this->assertInstanceOf(Response::class, $response);
            return $response;
        } finally {
            $_SERVER = $server;
            $environmentInfo->setValue($this->kirby->environment(), $originalInfo);
        }
    }

    private function data(Response $response, string $path): mixed
    {
        $value = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        foreach (explode('.', $path) as $key) {
            $this->assertIsArray($value);
            $value = $value[$key] ?? null;
        }

        return $value;
    }
}
