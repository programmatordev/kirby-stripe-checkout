<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use Closure;
use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Http\Environment;
use Kirby\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartEntry;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Cart\Internal\KirbySessionCartStore;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\BrowserAttemptStore;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSubmissionParser;
use ProgrammatorDev\StripeCheckout\Checkout\RequestErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\SelectionErrorCode;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ReflectionProperty;

final class CheckoutSubmissionTest extends KirbyTestCase
{
    /** @var array<array-key, mixed> */
    private array $server;
    /** @var list<ProductRequest> */
    private array $resolved = [];
    private string $price = '10.00';
    private ?Closure $onResolve = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        $this->restart();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $_SERVER = $this->server;
    }

    public function testDirectSubmissionBindsCanonicalFactsWithoutCreatingAnOrderOrQuotingShipping(): void
    {
        $body = [...$this->bootstrapData(), 'items' => [['reference' => 'alias', 'quantity' => 2, 'options' => (object) ['size' => 'large']]], 'shippingCountry' => 'PT'];
        $this->request($body);
        $acceptedAt = new DateTimeImmutable();
        $submission = (new RuntimeFactory($this->kirby))->checkoutSubmissionFactory()->create($acceptedAt);
        $lineItem = $submission->checkout()->items()[0];
        $this->assertSame('shirt', $lineItem->productReference());
        $this->assertSame(2, $lineItem->quantity());
        $this->assertSame(['size' => 'large'], $lineItem->productRequest()->selectedOptions());
        $this->assertSame('20.00', (string) $submission->checkout()->subtotal()->getAmount());
        $this->assertSame('PT', $submission->shipping()->shippingCountry());
        $this->assertSame($acceptedAt->getTimestamp(), $submission->attempt()->boundAt());
        $this->assertCount(1, $this->resolved);
        $this->assertCount(0, (new StripeCheckout($this->kirby))->orders());
        $state = json_encode($this->kirby->session()->data()->get(BrowserAttemptStore::KEY), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('shirt', $state);
        $this->assertStringNotContainsString('sk_test_server', $state);

        $body['items'] = [['reference' => 'shirt', 'quantity' => 2, 'options' => (object) ['size' => 'large']]];
        $this->request($body);
        $duplicate = (new RuntimeFactory($this->kirby))->checkoutSubmissionFactory()->create($acceptedAt->modify('+1 minute'));
        $this->assertSame($submission->binding()->fingerprint(), $duplicate->binding()->fingerprint());
        $this->assertSame($acceptedAt->getTimestamp(), $duplicate->attempt()->boundAt());
    }

    public function testCartSubmissionUsesOnlySavedSelectionsAndShippingCountry(): void
    {
        $cart = new CartSnapshot('cart', 'revision', [new CartEntry('line', new ProductRequest('shirt', 3))], 1, 1, 'PT');
        $store = new KirbySessionCartStore($this->kirby->session(), static fn(): string => 'cart');
        $store->mutate(static fn(CartSnapshot $current): CartSnapshot => $cart);
        $this->request($this->bootstrapData(CheckoutSource::Cart));
        $submission = (new RuntimeFactory($this->kirby))->checkoutSubmissionFactory()->create(new DateTimeImmutable());
        $this->assertSame(CheckoutSource::Cart, $submission->checkout()->checkoutSource());
        $this->assertSame('revision', $submission->binding()->cartRevision());
        $this->assertSame('PT', $submission->shipping()->shippingCountry());
        $this->assertSame(3, $submission->checkout()->items()[0]->quantity());
        $this->assertCount(1, $this->resolved);
        $this->assertSame('revision', $store->read()->revision());
    }

    public function testDirectAliasesReuseTheFactsAcceptedForTheirMergedQuantity(): void
    {
        $this->onResolve = function (): void {
            $this->price = count($this->resolved) === 3 ? '8.00' : '10.00';
        };
        $body = [...$this->bootstrapData(), 'items' => [
            ['reference' => 'alias'],
            ['reference' => 'shirt', 'quantity' => 2],
        ]];
        $this->request($body);
        $submission = (new RuntimeFactory($this->kirby))->checkoutSubmissionFactory()->create(new DateTimeImmutable());
        $this->assertCount(1, $submission->checkout()->items());
        $lineItem = $submission->checkout()->items()[0];
        $this->assertSame('shirt', $lineItem->productRequest()->reference());
        $this->assertSame(3, $lineItem->productRequest()->quantity());
        $this->assertSame('24.00', (string) $submission->checkout()->subtotal()->getAmount());
        $this->assertCount(3, $this->resolved);
    }

    #[DataProvider('cartOverrides')]
    public function testCartSubmissionCannotOverrideSavedPurchaseInput(string $field): void
    {
        $body = [...$this->bootstrapData(CheckoutSource::Cart), $field => $field === 'items' ? [['reference' => 'other']] : 'PT'];
        $this->request($body);
        $this->expectException(CheckoutInputException::class);
        $this->expectExceptionMessage(RequestErrorCode::INVALID_BODY);
        CheckoutSubmissionParser::parse($this->kirby);
    }

    /** @return iterable<string, array{string}> */
    public static function cartOverrides(): iterable
    {
        yield 'products' => ['items'];
        yield 'country' => ['shippingCountry'];
    }

    public function testAnUnavailableProductLeavesTheIssuedActionUnbound(): void
    {
        $this->onResolve = static function (): never {
            throw new \ProgrammatorDev\StripeCheckout\Product\Exception\ProductUnavailableException();
        };
        $submissionData = $this->bootstrapData();
        $this->request([...$submissionData, 'items' => [['reference' => 'shirt']]]);

        try {
            (new RuntimeFactory($this->kirby))->checkoutSubmissionFactory()->create(new DateTimeImmutable());
            $this->fail('An unavailable product cannot accept the action.');
        } catch (\ProgrammatorDev\StripeCheckout\Product\Exception\ProductUnavailableException) {
            /** @var array{attempts: array<string, array{boundAt: int|null}>} $state */
            $state = $this->kirby->session()->data()->get(BrowserAttemptStore::KEY);
            $this->assertNull($state['attempts'][hash('sha256', $submissionData['attemptToken'])]['boundAt']);
            $this->assertCount(0, (new StripeCheckout($this->kirby))->orders());
        }
    }

    public function testProjectResolutionRunsWithoutHoldingTheNativeSessionLock(): void
    {
        $body = [...$this->bootstrapData(), 'items' => [['reference' => 'shirt']]];
        $this->onResolve = function (): void {
            $paths = glob($this->environment->workspace()->roots()['sessions'] . '/*.sess');
            $this->assertIsArray($paths);
            $this->assertCount(1, $paths);
            $handle = fopen($paths[0], 'r+');
            $this->assertIsResource($handle);

            try {
                // Probe the actual native file lock without blocking the suite if the workflow regresses.
                $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        };
        $this->request($body);
        (new RuntimeFactory($this->kirby))->checkoutSubmissionFactory()->create(new DateTimeImmutable());
        $this->assertCount(1, $this->resolved);
    }

    #[DataProvider('changedPurchases')]
    public function testAnAcceptedActionCannotBeRetargeted(string $change): void
    {
        $body = [...$this->bootstrapData(), 'items' => [['reference' => 'shirt']], 'shippingCountry' => 'PT'];
        $this->request($body);
        $acceptedAt = new DateTimeImmutable();
        $submission = (new RuntimeFactory($this->kirby))->checkoutSubmissionFactory()->create($acceptedAt);

        if ($change === 'country') {
            $body['shippingCountry'] = 'ES';
        } elseif ($change === 'price') {
            $this->price = '12.00';
        } else {
            $body['items'] = [['reference' => 'other']];
        }

        $this->request($body);

        try {
            (new RuntimeFactory($this->kirby))->checkoutSubmissionFactory()->create($acceptedAt->modify('+1 second'));
            $this->fail('An accepted purchase must remain bound.');
        } catch (CheckoutInputException $error) {
            $this->assertSame(CheckoutErrorCode::ATTEMPT_CONFLICT, $error->errorCode());
        }

        /** @var array{attempts: array<string, array{bindingFingerprint: string}>} $state */
        $state = $this->kirby->session()->data()->get(BrowserAttemptStore::KEY);
        $this->assertSame($submission->binding()->fingerprint(), $state['attempts'][$submission->attempt()->token()->hash()]['bindingFingerprint']);
        $this->assertCount(0, (new StripeCheckout($this->kirby))->orders());
    }

    /** @return iterable<string, array{string}> */
    public static function changedPurchases(): iterable
    {
        yield 'country' => ['country'];
        yield 'price' => ['price'];
        yield 'selection' => ['selection'];
    }

    public function testCartChangesRejectBeforeProductResolution(): void
    {
        $store = new KirbySessionCartStore($this->kirby->session(), static fn(): string => 'cart');
        $cart = new CartSnapshot('cart', 'revision', [new CartEntry('line', new ProductRequest('shirt'))], 1, 1);
        $store->mutate(static fn(CartSnapshot $current): CartSnapshot => $cart);
        $body = $this->bootstrapData(CheckoutSource::Cart);
        $store->mutate(static fn(CartSnapshot $current): CartSnapshot => new CartSnapshot('cart', 'changed', $current->entries(), 1, 2));
        $this->request($body);

        try {
            (new RuntimeFactory($this->kirby))->checkoutSubmissionFactory()->create(new DateTimeImmutable());
            $this->fail('The issued revision must match.');
        } catch (CheckoutInputException $error) {
            $this->assertSame(CheckoutErrorCode::ATTEMPT_CONFLICT, $error->errorCode());
            $this->assertSame([], $this->resolved);
        }
    }

    public function testAnotherActorCannotResolveOrBindTheIssuedAction(): void
    {
        $body = [...$this->bootstrapData(), 'items' => [['reference' => 'shirt']]];
        $this->kirby->impersonate('kirby');
        $this->request($body);

        try {
            (new RuntimeFactory($this->kirby))->checkoutSubmissionFactory()->create(new DateTimeImmutable());
            $this->fail('Changing actor cannot reuse guest authority.');
        } catch (CheckoutInputException $error) {
            $this->assertSame(CheckoutErrorCode::ATTEMPT_CONFLICT, $error->errorCode());
            $this->assertSame([], $this->resolved);
        }
    }

    public function testAChangedLanguageCannotReuseTheIssuedAction(): void
    {
        $body = [...$this->bootstrapData(), 'items' => [['reference' => 'shirt']]];
        $this->kirby->setCurrentLanguage('pt');
        $this->request($body);
        $this->expectException(CheckoutInputException::class);
        $this->expectExceptionMessage(CheckoutErrorCode::ATTEMPT_CONFLICT);
        (new RuntimeFactory($this->kirby))->checkoutSubmissionFactory()->create(new DateTimeImmutable());
    }

    public function testAnUnissuedTokenCannotResolveProducts(): void
    {
        $body = [...$this->bootstrapData(), 'items' => [['reference' => 'shirt']]];
        $this->kirby->session()->data()->remove(BrowserAttemptStore::KEY);
        $this->request($body);

        try {
            (new RuntimeFactory($this->kirby))->checkoutSubmissionFactory()->create(new DateTimeImmutable());
            $this->fail('A valid token must also be issued in this browser.');
        } catch (CheckoutInputException $error) {
            $this->assertSame(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID, $error->errorCode());
            $this->assertSame([], $this->resolved);
        }
    }

    public function testEmbeddedFormIsRejectedBeforePurchaseBinding(): void
    {
        $this->restart(['settings' => ['uiMode' => 'embedded'], 'stripe' => ['publishableKey' => 'pk_test_browser']]);
        $submissionData = $this->bootstrapData(json: false);
        $body = [...$submissionData, 'items' => [['reference' => 'shirt']]];
        $this->request(http_build_query($body), headers: ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF' => null]);

        try {
            (new RuntimeFactory($this->kirby))->checkoutSubmissionFactory()->create(new DateTimeImmutable());
            $this->fail('Embedded Checkout is JSON-only.');
        } catch (CheckoutInputException $error) {
            $this->assertSame(RequestErrorCode::UNSUPPORTED_REPRESENTATION, $error->errorCode());
            $this->assertSame([], $this->resolved);
            /** @var array{attempts: array<string, array{boundAt: int|null}>} $state */
            $state = $this->kirby->session()->data()->get(BrowserAttemptStore::KEY);
            $this->assertNull($state['attempts'][hash('sha256', $submissionData['attemptToken'])]['boundAt']);
        }
    }

    public function testFormNormalizationPreservesOnlyTheDocumentedTransportValues(): void
    {
        $body = [...$this->bootstrapData(json: false), 'items' => [['reference' => 'shirt', 'quantity' => '2', 'options' => ['size' => 'large']]], 'shippingCountry' => ''];
        $this->request(http_build_query($body), headers: ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF' => null]);
        $input = CheckoutSubmissionParser::parse($this->kirby);
        $this->assertFalse($input->isJson());
        $this->assertSame(2, $input->items()[0]->quantity());
        $this->assertSame(['size' => 'large'], $input->items()[0]->selectedOptions());
        $this->assertNull($input->shippingCountry());
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidInput')]
    public function testInvalidTransportDoesNotResolveOrBind(array $changes, string $errorCode): void
    {
        $submissionData = $this->bootstrapData();
        $body = [...$submissionData, 'items' => [['reference' => 'shirt']], ...$changes];
        $this->request($body);

        try {
            (new RuntimeFactory($this->kirby))->checkoutSubmissionFactory()->create(new DateTimeImmutable());
            $this->fail('Expected invalid transport.');
        } catch (CheckoutInputException $error) {
            $this->assertSame($errorCode, $error->errorCode());
            $this->assertSame([], $this->resolved);
            /** @var array{attempts: array<string, array{boundAt: int|null}>} $state */
            $state = $this->kirby->session()->data()->get(BrowserAttemptStore::KEY);
            $this->assertNull($state['attempts'][hash('sha256', $submissionData['attemptToken'])]['boundAt']);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidInput(): iterable
    {
        yield 'protected currency' => [['currency' => 'USD'], RequestErrorCode::INVALID_BODY];
        yield 'protected actor' => [['userUuid' => 'user://other'], RequestErrorCode::INVALID_BODY];
        yield 'JSON CSRF belongs in header' => [['csrf' => 'body-token'], RequestErrorCode::INVALID_BODY];
        yield 'direct revision' => [['revision' => 'revision'], RequestErrorCode::INVALID_BODY];
        yield 'unknown source' => [['source' => 'unknown'], RequestErrorCode::INVALID_BODY];
        yield 'empty items' => [['items' => []], SelectionErrorCode::INVALID];
        yield 'object instead of items list' => [['items' => (object) ['first' => (object) ['reference' => 'shirt']]], SelectionErrorCode::INVALID];
        yield 'too many inputs' => [['items' => array_fill(0, 101, ['reference' => 'shirt'])], SelectionErrorCode::LINE_LIMIT_EXCEEDED];
        yield 'JSON string quantity' => [['items' => [['reference' => 'shirt', 'quantity' => '2']]], SelectionErrorCode::QUANTITY_INVALID];
        yield 'JSON options list' => [['items' => [['reference' => 'shirt', 'options' => []]]], SelectionErrorCode::INVALID];
        yield 'protected line price' => [['items' => [['reference' => 'shirt', 'price' => '0.01']]], SelectionErrorCode::INVALID];
        yield 'internal options key' => [['items' => [['reference' => 'shirt', 'selectedOptions' => []]]], SelectionErrorCode::INVALID];
        yield 'country type' => [['shippingCountry' => []], ShippingErrorCode::COUNTRY_INVALID];
        yield 'country value' => [['shippingCountry' => 'unsupported'], ShippingErrorCode::COUNTRY_INVALID];
    }

    #[DataProvider('invalidBodies')]
    public function testMalformedBodiesAreRejectedWithoutKirbyFallback(string $body, string $contentType): void
    {
        $this->bootstrapData();
        $this->request($body, headers: ['Content-Type' => $contentType]);
        $this->expectException(CheckoutInputException::class);
        $this->expectExceptionMessage(RequestErrorCode::INVALID_BODY);
        CheckoutSubmissionParser::parse($this->kirby);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidBodies(): iterable
    {
        yield 'JSON array' => ['[]', 'application/json'];
        yield 'form body labelled JSON' => ['source=direct&items[0][reference]=shirt', 'application/json'];
        yield 'JSON body labelled form' => ['{"source":"direct"}', 'application/x-www-form-urlencoded'];
        yield 'invalid JSON' => ['{', 'application/json'];
    }

    /** @param array<string, string|null> $headers */
    #[DataProvider('requestSecurity')]
    public function testRequestSecurityRejectsBeforeResolution(array $headers, string $errorCode): void
    {
        $body = [...$this->bootstrapData(), 'items' => [['reference' => 'shirt']]];
        $this->request($body, $headers);

        try {
            (new RuntimeFactory($this->kirby))->checkoutSubmissionFactory()->create(new DateTimeImmutable());
            $this->fail('Request security must reject this submission.');
        } catch (CheckoutInputException $error) {
            $this->assertSame($errorCode, $error->errorCode());
            $this->assertSame([], $this->resolved);
        }
    }

    /** @return iterable<string, array{array<string, string|null>, string}> */
    public static function requestSecurity(): iterable
    {
        yield 'missing CSRF' => [['X-CSRF' => null], RequestErrorCode::CSRF_INVALID];
        yield 'wrong CSRF' => [['X-CSRF' => 'wrong'], RequestErrorCode::CSRF_INVALID];
        yield 'foreign origin' => [['Origin' => 'https://other.test'], RequestErrorCode::ORIGIN_INVALID];
        yield 'foreign origin with good referer' => [['Origin' => 'https://other.test', 'Referer' => 'https://kirby-stripe-checkout.test/shop'], RequestErrorCode::ORIGIN_INVALID];
        yield 'opaque origin' => [['Origin' => 'null'], RequestErrorCode::ORIGIN_INVALID];
        yield 'different scheme' => [['Origin' => 'http://kirby-stripe-checkout.test'], RequestErrorCode::ORIGIN_INVALID];
        yield 'different port' => [['Origin' => 'https://kirby-stripe-checkout.test:444'], RequestErrorCode::ORIGIN_INVALID];
        yield 'foreign referer fallback' => [['Origin' => null, 'Referer' => 'https://other.test/shop'], RequestErrorCode::ORIGIN_INVALID];
        yield 'unsupported media' => [['Content-Type' => 'text/plain'], RequestErrorCode::UNSUPPORTED_MEDIA_TYPE];
    }

    #[DataProvider('sameOriginHeaders')]
    public function testValidCsrfWorksWithTheSupportedOriginHeaderContexts(?string $origin, ?string $referer): void
    {
        $body = [...$this->bootstrapData(), 'items' => [['reference' => 'shirt']]];
        $this->request($body, ['Origin' => $origin, 'Referer' => $referer]);
        $input = CheckoutSubmissionParser::parse($this->kirby);
        $this->assertSame(CheckoutSource::Direct, $input->checkoutSource());
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function sameOriginHeaders(): iterable
    {
        yield 'explicit default port' => ['https://KIRBY-STRIPE-CHECKOUT.test:443', null];
        yield 'same-origin referer' => [null, 'https://kirby-stripe-checkout.test/shop'];
        yield 'browser omits both' => [null, null];
    }

    /** @return array<string, string> */
    private function bootstrapData(CheckoutSource $checkoutSource = CheckoutSource::Direct, bool $json = true): array
    {
        $data = (new StripeCheckout($this->kirby))->checkout($checkoutSource)->submissionData();

        if ($json) {
            unset($data['csrf']);
        }

        return $data;
    }

    /**
     * @param array<string, mixed>|string $body
     * @param array<string, string|null> $headers
     */
    private function request(array|string $body, array $headers = []): void
    {
        $_SERVER = $this->server;
        $keys = ['HTTP_ACCEPT', 'HTTP_CONTENT_TYPE', 'HTTP_X_CSRF', 'HTTP_ORIGIN', 'HTTP_REFERER', 'CONTENT_TYPE'];

        foreach ($keys as $key) {
            unset($_SERVER[$key]);
        }

        $headers = array_replace([
            'Content-Type' => 'application/json',
            'X-CSRF' => (string) $this->kirby->csrf(),
            'Origin' => 'https://kirby-stripe-checkout.test',
        ], $headers);

        foreach ($headers as $key => $value) {
            if ($value !== null) {
                $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
            }
        }

        (new ReflectionProperty(Environment::class, 'info'))->setValue($this->kirby->environment(), $_SERVER);
        (new ReflectionProperty(App::class, 'request'))->setValue($this->kirby, new Request([
            'method' => 'POST',
            'url' => 'https://kirby-stripe-checkout.test/en/stripe-checkout/checkout',
            'body' => is_array($body) ? json_encode((object) $body, JSON_THROW_ON_ERROR) : $body,
        ]));
    }

    /** @param array<string, mixed> $options */
    private function restart(array $options = []): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            options: ['programmatordev.stripe-checkout' => array_replace_recursive([
                'settings' => ['currency' => 'EUR', 'defaultRequiresShipping' => false],
                'stripe' => ['secretKey' => 'sk_test_server', 'webhookSecret' => 'whsec_test_hook'],
                'shipping' => ['resolver' => static function (): never {
                    throw new \RuntimeException('Submission must leave quoting to creator preparation.');
                }],
                'products' => ['resolver' => function (ProductRequest $request): Product {
                    $this->resolved[] = $request;
                    $this->onResolve?->__invoke($request);
                    $selectedOptions = [];

                    foreach ($request->selectedOptions() as $optionId => $valueId) {
                        $selectedOptions[] = new SelectedOption($optionId, $optionId, $valueId, $valueId);
                    }

                    return new Product(
                        request: new ProductRequest($request->reference() === 'alias' ? 'shirt' : $request->reference(), $request->quantity(), $request->selectedOptions()),
                        name: 'Product',
                        requiresShipping: $request->reference() === 'physical',
                        price: new Price(Money::of($this->price, 'EUR')),
                        selectedOptions: $selectedOptions,
                        variantId: $selectedOptions === [] ? null : 'selected-variant',
                    );
                }],
            ], $options)],
            languages: [
                ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English'],
                ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português'],
            ],
            impersonate: null,
        );
        $this->kirby = $this->environment->app();
        $this->kirby->setCurrentLanguage('en');
    }
}
