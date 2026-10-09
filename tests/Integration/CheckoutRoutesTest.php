<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Http\Environment;
use Kirby\Http\Request;
use Kirby\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptToken;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\BrowserAttemptContext;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\BrowserAttemptStore;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSubmissionInput;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestCustomizer;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductUnavailableException;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ReflectionProperty;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

final class CheckoutRoutesTest extends KirbyTestCase
{
    /** @var list<array{method: string, parameters: array<array-key, mixed>, idempotencyKey: ?string}> */
    private array $requests = [];

    /** @var array<string, mixed>|null */
    private ?array $session = null;

    private int $resolutions = 0;
    private int $customizations = 0;
    private bool $uncertain = false;
    private bool $readUnavailable = false;
    private ?int $providerStatus = null;
    private string $sessionStatus = 'open';
    private string $price = '16.00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->restart();
    }

    #[DataProvider('checkoutModesAndSources')]
    public function testCreatesAndReusesOnlyAuthorizedModeSpecificPresentation(UiMode $uiMode, CheckoutSource $checkoutSource): void
    {
        $this->restart($uiMode);
        $body = $this->submission($checkoutSource);
        $created = $this->send($body);

        $expected = [
            'ok' => true,
            'data' => $uiMode === UiMode::Hosted
                ? ['uiMode' => 'hosted', 'redirectUrl' => 'https://checkout.stripe.com/c/pay/cs_test_browser']
                : ['uiMode' => 'embedded', 'clientSecret' => 'cs_test_browser_secret_private'],
        ];
        $this->assertSame(201, $created->code());
        $this->assertSame($expected, $this->body($created));
        $this->assertPrivate($created);
        $this->assertSame(1, $this->resolutions);
        $this->assertSame(1, $this->customizations);
        $duplicate = $this->send($body);
        $this->assertSame(200, $duplicate->code());
        $this->assertSame($expected, $this->body($duplicate));
        $this->assertCount(2, $this->requests);
        $this->assertSame('post', $this->requests[0]['method']);
        $this->assertSame('get', $this->requests[1]['method']);
        $this->assertSame(1, $this->customizations);

        $data = $this->orderData($body);
        $this->assertSame(CheckoutStatus::Open->value, $data['checkoutStatus']);
        $this->assertSame($checkoutSource->value, OrderData::map($data['checkoutAttempt'])['source']);
        $stored = json_encode([$data, $this->kirby->session()->data()->get(BrowserAttemptStore::KEY)], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('https://checkout.stripe.com', $stored);
        $this->assertStringNotContainsString('cs_test_browser_secret_private', $stored);
        $this->assertCount(1, (new OrderPageStore($this->kirby))->orders());
    }

    /** @return iterable<string, array{UiMode, CheckoutSource}> */
    public static function checkoutModesAndSources(): iterable
    {
        yield 'hosted cart' => [UiMode::Hosted, CheckoutSource::Cart];
        yield 'hosted direct' => [UiMode::Hosted, CheckoutSource::Direct];
        yield 'embedded cart' => [UiMode::Embedded, CheckoutSource::Cart];
        yield 'embedded direct' => [UiMode::Embedded, CheckoutSource::Direct];
    }

    public function testUncertainMutationRetriesTheExactSavedRequestAndKey(): void
    {
        $body = $this->submission();
        $this->uncertain = true;
        $pending = $this->send($body);
        $this->assertError($pending, 202, 'checkout.session_uncertain', true);
        $before = $this->orderData($body);
        $this->assertSame(CheckoutStatus::CreationUncertain->value, $before['checkoutStatus']);
        $retried = $this->send($body);
        $this->assertSame(200, $retried->code());
        $this->assertSame($this->requests[0]['parameters'], $this->requests[1]['parameters']);
        $this->assertSame($this->requests[0]['idempotencyKey'], $this->requests[1]['idempotencyKey']);
        $this->assertNotNull($this->requests[0]['idempotencyKey']);
        $after = $this->orderData($body);
        $this->assertSame(OrderData::map($before['checkoutAttempt'])['retryUntil'], OrderData::map($after['checkoutAttempt'])['retryUntil']);
        $this->assertSame(1, $this->customizations);
        $this->assertCount(1, (new OrderPageStore($this->kirby))->orders());
    }

    public function testUnavailableDuplicateReadIsNotAnUncertainMutation(): void
    {
        $body = $this->submission();
        $this->assertSame(201, $this->send($body)->code());
        $this->readUnavailable = true;
        $this->assertError($this->send($body), 503, 'checkout.session_unavailable', true);
        $this->assertSame(CheckoutStatus::Open->value, $this->orderData($body)['checkoutStatus']);
        $this->assertSame(['post', 'get'], array_column($this->requests, 'method'));
    }

    public function testExpiredRetryPreservesUnresolvedCreation(): void
    {
        // Create the original attempt beyond its 23-hour retry window but within browser retention.
        // Today's HTTP request then exercises expiry without editing immutable persisted timestamps.
        $acceptedAt = new DateTimeImmutable('-23 hours -1 minute');
        $context = BrowserAttemptContext::capture(
            checkoutSource: CheckoutSource::Direct,
            uiMode: UiMode::Hosted,
            languageCode: 'en',
            userUuid: null,
            csrf: (string) $this->kirby->csrf(),
            cart: null,
        );
        $attempt = (new BrowserAttemptStore($this->kirby->session()))->issue($context, 'https://kirby-stripe-checkout.test/en', $acceptedAt);
        $body = [
            'source' => 'direct',
            'attemptToken' => $attempt->token()->value(),
            'items' => [['reference' => 'product', 'quantity' => 2]],
        ];
        $this->uncertain = true;
        $runtime = new RuntimeFactory($this->kirby);
        $input = new CheckoutSubmissionInput(
            checkoutSource: CheckoutSource::Direct,
            attemptToken: $attempt->token(),
            cartRevision: null,
            items: [new ProductRequest('product', 2)],
            shippingCountry: null,
            json: true,
        );
        $submission = $runtime->checkoutSubmissionFactory()->create($input, $acceptedAt);

        try {
            $runtime->checkoutSessionCreator()->create(
                checkout: $submission->checkout(),
                shipping: $submission->shipping(),
                binding: $submission->binding(),
                token: $attempt->token(),
                guestReference: $context->guestReference(),
                now: $acceptedAt,
                initiatingUrl: $attempt->initiatingUrl(),
            );
            $this->fail('Expected uncertain creation.');
        } catch (CheckoutSessionException $failure) {
            $this->assertSame('checkout.session_uncertain', $failure->errorCode());
        }

        $before = $this->orderData($body);
        $this->assertError($this->send($body), 409, 'checkout.attempt_retry_expired', false);
        $this->assertSame($before, $this->orderData($body));
        $this->assertCount(1, $this->requests);
    }

    #[DataProvider('closedSessionStatuses')]
    public function testClosedDuplicateCannotCreateAnotherSession(string $sessionStatus): void
    {
        $body = $this->submission();
        $this->assertSame(201, $this->send($body)->code());
        $this->sessionStatus = $sessionStatus;
        $this->assertError($this->send($body), 409, 'checkout.attempt_closed', false);
        $this->assertSame(['post', 'get'], array_column($this->requests, 'method'));
    }

    /** @return iterable<string, array{string}> */
    public static function closedSessionStatuses(): iterable
    {
        yield 'completed before webhook' => ['complete'];
        yield 'expired before webhook' => ['expired'];
    }

    public function testClosedProviderResultStillRequiresTheSavedSessionIdentity(): void
    {
        $body = $this->submission();
        $this->assertSame(201, $this->send($body)->code());
        $this->assertIsArray($this->session);
        $this->session['id'] = 'other-session';
        $this->sessionStatus = 'expired';
        $this->assertError($this->send($body), 502, 'checkout.session_incompatible', false);
        $this->assertSame(CheckoutStatus::Open->value, $this->orderData($body)['checkoutStatus']);
    }

    #[DataProvider('providerFailures')]
    public function testProviderFailuresAreSanitizedAndKeepIndependentRetryPolicy(int $providerStatus, int $httpStatus, string $code, bool $retryable): void
    {
        $body = $this->submission();
        $this->providerStatus = $providerStatus;
        $this->assertError($this->send($body), $httpStatus, $code, $retryable);
    }

    /** @return iterable<string, array{int, int, string, bool}> */
    public static function providerFailures(): iterable
    {
        yield 'definitive rejection' => [400, 502, 'checkout.session_rejected', false];
        yield 'rate limit with explicit no retry' => [429, 503, 'checkout.session_unavailable', false];
        yield 'uncertain mutation with explicit no retry' => [500, 202, 'checkout.session_uncertain', false];
    }

    #[DataProvider('negotiationFailures')]
    public function testUnsupportedRepresentationIsRejectedBeforeParsingOrResolving(string $accept): void
    {
        $response = $this->send('broken JSON', headers: ['Accept' => $accept, 'X-CSRF' => null]);
        $this->assertSame(406, $response->code());
        $this->assertSame('', $response->body());
        $this->assertPrivate($response);
        $this->assertSame(0, $this->resolutions);
        $this->assertCount(0, $this->requests);
        $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
    }

    /** @return iterable<string, array{string}> */
    public static function negotiationFailures(): iterable
    {
        yield 'html' => ['text/html'];
        yield 'other representation' => ['image/png'];
        yield 'zero quality' => ['application/json;q=0'];
        yield 'specific exclusion beats wildcard' => ['*/*;q=1, application/json;q=0'];
        yield 'type exclusion beats wildcard' => ['application/*;q=0, */*;q=1'];
    }

    #[DataProvider('acceptedRepresentations')]
    public function testSupportedAcceptRangesReachTheJsonBoundary(string $accept): void
    {
        $this->assertError($this->send('{}', headers: ['Accept' => $accept]), 400, 'request.invalid_body', false);
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedRepresentations(): iterable
    {
        yield 'json' => ['application/json'];
        yield 'wildcard' => ['*/*'];
        yield 'application wildcard' => ['application/*'];
        yield 'blank' => [''];
    }

    #[DataProvider('unsupportedMethods')]
    public function testMethodsHaveExplicitPrivateRejections(string $method): void
    {
        $response = $this->send('{}', method: $method);
        $this->assertSame(405, $response->code());
        $this->assertSame('POST', $response->headers()['Allow']);
        $this->assertPrivate($response);
        $this->assertSame(0, $this->resolutions);
        $this->assertCount(0, $this->requests);
    }

    /** @return iterable<int, array{string}> */
    public static function unsupportedMethods(): iterable
    {
        yield ['GET'];
        yield ['PUT'];
        yield ['PATCH'];
        yield ['DELETE'];
        yield ['OPTIONS'];
    }

    public function testFormSubmissionWaitsForTheHostedPresentationStep(): void
    {
        $body = $this->submission();
        $body['csrf'] = (string) $this->kirby->csrf();
        $response = $this->send(http_build_query($body), headers: ['Content-Type' => 'application/x-www-form-urlencoded', 'X-CSRF' => null]);
        $this->assertSame(406, $response->code());
        $this->assertCount(0, $this->requests);
        $this->assertSame(0, $this->resolutions);
        $entries = $this->kirby->session()->data()->get(BrowserAttemptStore::KEY);
        $this->assertIsArray($entries);
        $this->assertIsArray($entries['attempts']);
        $this->assertIsString($body['attemptToken']);
        $entry = $entries['attempts'][(new AttemptToken($body['attemptToken']))->hash()];
        $this->assertIsArray($entry);
        $this->assertNull($entry['boundAt']);
    }

    public function testSecurityFailuresDoNotCreateOrdersOrCallResolvers(): void
    {
        $body = $this->submission();
        $this->assertError($this->send($body, headers: ['X-CSRF' => 'wrong']), 403, 'request.csrf_invalid', false);
        $this->assertError($this->send($body, headers: ['Origin' => 'https://foreign.test']), 403, 'request.origin_invalid', false);
        $this->assertSame(0, $this->resolutions);
        $this->assertCount(0, $this->requests);
        $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
    }

    public function testInvalidBodyAndProtectedCommerceFieldsDoNotResolveProducts(): void
    {
        $body = $this->submission();
        $this->assertError($this->send('broken JSON'), 400, 'request.invalid_body', false);
        $this->assertError($this->send([...$body, 'amount' => 1]), 400, 'request.invalid_body', false);
        $this->assertError($this->send($body, headers: ['Content-Type' => 'text/plain']), 406, 'request.unsupported_media_type', false);
        $this->assertSame(0, $this->resolutions);
        $this->assertCount(0, $this->requests);
    }

    public function testUnavailableProductReturnsAReviewConflictWithoutAcceptingThePurchase(): void
    {
        $this->restart(options: ['products' => ['resolver' => static function (): never {
            throw new ProductUnavailableException();
        }]]);
        $this->assertError($this->send($this->submission()), 409, 'product.unavailable', false);
        $this->assertCount(0, $this->requests);
        $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
    }

    public function testPhysicalCheckoutUsesTheAcceptedShippingCountry(): void
    {
        $this->restart(options: ['settings' => ['shippingZones' => [[
            'name' => 'Portugal',
            'scope' => 'selected_countries',
            'countries' => ['PT'],
            'options' => [[
                'key' => 'standard',
                'label' => 'Standard delivery',
                'amount' => '4.90',
            ]],
        ]]]]);
        $body = $this->submission();
        $body['items'] = [['reference' => 'physical']];
        $this->assertError($this->send($body), 409, 'shipping.country_required', false);
        $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());

        // Reviewing an incomplete purchase issues a new action; an accepted country's binding is immutable.
        $body = $this->submission();
        $body['items'] = [['reference' => 'physical']];
        $body['shippingCountry'] = 'PT';
        $this->assertSame(201, $this->send($body)->code());
        $this->assertCount(1, $this->requests);
        $parameters = $this->requests[0]['parameters'];
        $this->assertSame(['allowed_countries' => ['PT']], $parameters['shipping_address_collection']);
        $this->assertSame(['shr_browser_standard'], $this->orderData($body)['stripeShippingRateIds']);
    }

    public function testCustomUnavailableShippingReasonRemainsASafeReviewConflict(): void
    {
        $this->restart(options: ['shipping' => ['resolver' => static fn(): ShippingQuote => ShippingQuote::unavailable('shipping.private_custom_reason')]]);
        $body = $this->submission();
        $body['items'] = [['reference' => 'physical']];
        $body['shippingCountry'] = 'PT';
        $this->assertError($this->send($body), 409, 'shipping.unavailable', false);
        $this->assertCount(0, $this->requests);
        $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
    }

    public function testForeignBrowserCannotUseTheIssuedTokenWithItsOwnValidCsrf(): void
    {
        $body = $this->submission();
        $this->restart();
        $this->assertError($this->send($body), 409, 'checkout.attempt_token_invalid', false);
        $this->assertSame(0, $this->resolutions);
        $this->assertCount(0, $this->requests);
    }

    public function testChangedCartAndPurchaseCannotRetargetAnAcceptedAction(): void
    {
        $body = $this->submission(CheckoutSource::Cart);
        $this->price = '17.00';
        $this->assertSame(201, $this->send($body)->code());
        $this->price = '18.00';
        $this->assertError($this->send($body), 409, 'checkout.attempt_conflict', false);
        (new StripeCheckout($this->kirby))->cart()?->add('product');
        $this->assertError($this->send($body), 409, 'checkout.attempt_conflict', false);
        $this->assertCount(1, $this->requests);
    }

    public function testLocalizedRouteAndTranslatedErrorsRetainTheIssuedLanguage(): void
    {
        $this->kirby->setCurrentLanguage('pt');
        $body = $this->submission();
        $failure = $this->send($body, headers: ['X-CSRF' => 'wrong'], languageCode: 'pt');
        $this->assertError($failure, 403, 'request.csrf_invalid', false);
        $error = $this->body($failure)['error'];
        $this->assertIsArray($error);
        $this->assertSame('Não foi possível verificar a sessão. Atualize a página e tente novamente.', $error['message']);
        $response = $this->send($body, languageCode: 'pt');
        $this->assertSame(201, $response->code());
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['success_url']);
        $this->assertStringContainsString('/pt/stripe-checkout/success?', $parameters['success_url']);
        $this->assertStringContainsString('{CHECKOUT_SESSION_ID}', $parameters['success_url']);
        $this->assertSame('pt', $this->orderData($body)['languageCode']);
    }

    public function testInvalidSettingsAndCallbackFailuresAreSafe(): void
    {
        $body = $this->submission();
        $this->kirby->extend(['hooks' => [SessionRequestCustomizer::FILTER => function (): never {
            throw new RuntimeException('private provider details');
        }]]);
        $this->assertError($this->send($body), 422, 'session_request.filter_failed', false);
        $this->assertCount(0, $this->requests);
        $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());

        $this->restart(options: ['settings' => ['uiMode' => 'invalid']]);
        $this->assertError($this->send($body), 503, 'configuration.not_ready', false);
    }

    public function testCustomProductExceptionDoesNotExposeItsArbitraryCode(): void
    {
        $this->restart(options: ['products' => ['resolver' => static function (): never {
            throw new InvalidProductException('private callback code');
        }]]);
        $this->assertError($this->send($this->submission()), 422, 'product.invalid', false);
    }

    /** @param array<string, mixed> $options */
    private function restart(UiMode $uiMode = UiMode::Hosted, array $options = []): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            options: ['programmatordev.stripe-checkout' => array_replace_recursive([
                'settings' => ['currency' => 'EUR', 'defaultRequiresShipping' => false, 'uiMode' => $uiMode->value],
                'stripe' => ['secretKey' => 'sk_test_server', 'webhookSecret' => 'whsec_test_hook', 'publishableKey' => 'pk_test_browser'],
                'products' => ['resolver' => function (ProductRequest $request): Product {
                    $this->resolutions++;
                    return new Product(request: $request, name: 'Product', requiresShipping: $request->reference() === 'physical', price: new Price(Money::of($this->price, 'EUR')));
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
        $customizations = &$this->customizations;
        $this->kirby->extend(['hooks' => [SessionRequestCustomizer::FILTER => function (array $parameters) use (&$customizations): array {
            $customizations++;
            return $parameters;
        }]]);

        // Exercise the actual RuntimeFactory and Stripe adapter with an explicit offline HTTP client.
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturnCallback(function (string $method, string $url, array $headers, array $parameters): array {
            $idempotencyKey = null;

            foreach ($headers as $header) {
                $this->assertIsString($header);

                if (str_starts_with($header, 'Idempotency-Key: ')) {
                    $idempotencyKey = substr($header, strlen('Idempotency-Key: '));
                }
            }

            $this->requests[] = [
                'method' => $method,
                'parameters' => $parameters,
                'idempotencyKey' => $idempotencyKey,
            ];

            if ($method === 'post' && $this->uncertain) {
                $this->uncertain = false;
                throw new ApiConnectionException('private provider details');
            }

            if ($method === 'get' && $this->readUnavailable) {
                throw new ApiConnectionException('private provider details');
            }

            if ($this->providerStatus !== null) {
                return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'private provider details']], JSON_THROW_ON_ERROR), $this->providerStatus, ['stripe-should-retry' => 'false']];
            }

            if ($method === 'post') {
                $this->assertIsInt($parameters['expires_at']);
                $this->session = [
                    'id' => 'cs_test_browser',
                    'object' => 'checkout.session',
                    'created' => $parameters['expires_at'] - 86400,
                    'expires_at' => $parameters['expires_at'],
                    'status' => 'open',
                    'payment_status' => 'unpaid',
                    'livemode' => false,
                    'mode' => 'payment',
                    'ui_mode' => $parameters['ui_mode'],
                    'currency' => 'eur',
                    'client_reference_id' => $parameters['client_reference_id'],
                    'integration_identifier' => $parameters['integration_identifier'],
                    'metadata' => $parameters['metadata'],
                    'url' => $parameters['ui_mode'] === 'hosted_page' ? 'https://checkout.stripe.com/c/pay/cs_test_browser' : null,
                    'client_secret' => $parameters['ui_mode'] === 'embedded_page' ? 'cs_test_browser_secret_private' : null,
                    'shipping_options' => isset($parameters['shipping_options']) ? [['shipping_rate' => 'shr_browser_standard']] : [],
                ];
            }

            $session = $this->session ?? $this->fail('Retrieve requires an existing provider Session.');
            $session['status'] = $this->sessionStatus;

            return [json_encode($session, JSON_THROW_ON_ERROR), 200, []];
        });
        ApiRequestor::setHttpClient($client);
    }

    /** @return array<string, string|list<array<string, mixed>>> */
    private function submission(CheckoutSource $checkoutSource = CheckoutSource::Direct): array
    {
        $stripeCheckout = new StripeCheckout($this->kirby);

        if ($checkoutSource === CheckoutSource::Cart) {
            $stripeCheckout->cart()?->add('product', 2);
        }

        $data = $stripeCheckout->checkout($checkoutSource)->submissionData();
        unset($data['csrf']);

        if ($checkoutSource === CheckoutSource::Direct) {
            $data['items'] = [['reference' => 'product', 'quantity' => 2]];
        }

        $this->resolutions = 0;
        return $data;
    }

    /**
     * @param array<string, mixed>|string $body
     * @param array<string, string|null> $headers
     */
    private function send(array|string $body, array $headers = [], string $method = 'POST', string $languageCode = 'en'): Response
    {
        $server = $_SERVER;
        $info = new ReflectionProperty(Environment::class, 'info');
        $previous = $info->getValue($this->kirby->environment());

        try {
            $keys = ['HTTP_ACCEPT', 'HTTP_CONTENT_TYPE', 'HTTP_X_CSRF', 'HTTP_ORIGIN', 'HTTP_REFERER', 'CONTENT_TYPE'];

            foreach ($keys as $key) {
                unset($_SERVER[$key]);
            }

            $headers = array_replace([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-CSRF' => (string) $this->kirby->csrf(),
                'Origin' => 'https://kirby-stripe-checkout.test',
            ], $headers);

            foreach ($headers as $key => $value) {
                if ($value !== null) {
                    $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
                }
            }

            $info->setValue($this->kirby->environment(), $_SERVER);
            (new ReflectionProperty(App::class, 'request'))->setValue($this->kirby, new Request([
                'method' => $method,
                'body' => is_array($body) ? json_encode((object) $body, JSON_THROW_ON_ERROR) : $body,
                'url' => 'https://kirby-stripe-checkout.test/' . $languageCode . '/stripe-checkout/checkout',
            ]));
            $response = $this->kirby->call($languageCode . '/stripe-checkout/checkout', $method);
            $this->assertInstanceOf(Response::class, $response);
            return $response;
        } finally {
            $_SERVER = $server;
            $info->setValue($this->kirby->environment(), $previous);
        }
    }

    /** @return array<string, mixed> */
    private function body(Response $response): array
    {
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        /** @var array<string, mixed> $body */
        return $body;
    }

    private function assertPrivate(Response $response): void
    {
        $this->assertSame('no-store, private', $response->headers()['Cache-Control']);
        $this->assertSame('Accept', $response->headers()['Vary']);
        $this->assertSame('no-referrer', $response->headers()['Referrer-Policy']);
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers());
    }

    private function assertError(Response $response, int $httpStatus, string $code, bool $retryable): void
    {
        $this->assertSame($httpStatus, $response->code(), $response->body());
        $body = $this->body($response);
        $this->assertSame(['ok', 'error'], array_keys($body));
        $this->assertFalse($body['ok']);
        $this->assertIsArray($body['error']);
        $this->assertSame($code, $body['error']['code']);
        $this->assertSame($retryable, $body['error']['retryable']);
        $this->assertIsString($body['error']['message']);
        $this->assertNotSame('', $body['error']['message']);
        $this->assertStringNotContainsString('private', $response->body());
        $this->assertPrivate($response);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function orderData(array $body): array
    {
        $store = new OrderPageStore($this->kirby);
        $this->assertIsString($body['attemptToken']);
        $page = $store->order('page://' . (new AttemptToken($body['attemptToken']))->orderUuid()) ?? $this->fail('Missing order.');
        return $store->data($page);
    }
}
