<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use Closure;
use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Cms\User;
use Kirby\Http\Environment;
use Kirby\Http\Request;
use Kirby\Http\Response;
use Kirby\Session\AutoSession;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutResultReason;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\AttemptToken;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\BrowserAttemptContext;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\BrowserAttemptStore;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutFormResultStore;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutResultStore;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSubmissionInput;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionRequestCustomizer;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
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
use ProgrammatorDev\StripeCheckout\Test\Support\TestWorkspace;
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
    private string $sessionPaymentStatus = 'unpaid';
    private string $paymentIntentStatus = 'requires_payment_method';
    private string $createdSessionId = 'cs_test_browser';
    /** @var array<string, mixed>|null */
    private ?array $paymentNextAction = null;
    private string $price = '16.00';

    /** @var array<string, mixed>|null */
    private ?array $sessionRequest = null;

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

    #[DataProvider('cartCheckoutModes')]
    public function testLaterCartActionReusesTheFirstAcceptedOrderAndSession(UiMode $uiMode): void
    {
        $this->restart($uiMode);
        $stripeCheckout = new StripeCheckout($this->kirby);
        $stripeCheckout->cart()?->add('product', 2);
        $firstSubmissionData = $stripeCheckout->checkout(CheckoutSource::Cart)->submissionData();
        $laterSubmissionData = (new StripeCheckout($this->kirby))->checkout(CheckoutSource::Cart)->submissionData();
        unset($firstSubmissionData['csrf'], $laterSubmissionData['csrf']);
        $this->assertNotSame($firstSubmissionData['attemptToken'], $laterSubmissionData['attemptToken']);
        $firstRevision = $firstSubmissionData['revision'] ?? null;
        $laterRevision = $laterSubmissionData['revision'] ?? null;
        $this->assertIsString($firstRevision);
        $this->assertSame($firstRevision, $laterRevision);

        $createdResponse = $this->send($firstSubmissionData);
        $reusedResponse = $this->send($laterSubmissionData);

        $this->assertSame(201, $createdResponse->code());
        $this->assertSame(200, $reusedResponse->code());
        $this->assertSame($this->body($createdResponse), $this->body($reusedResponse));
        $this->assertSame(['post', 'get'], array_column($this->requests, 'method'));
        $this->assertCount(1, (new OrderPageStore($this->kirby))->orders());
        $laterPageUuid = OrderData::pageUuid((new AttemptToken($laterSubmissionData['attemptToken']))->orderUuid());
        $this->assertNull((new OrderPageStore($this->kirby))->order($laterPageUuid));
    }

    /** @return iterable<string, array{UiMode}> */
    public static function cartCheckoutModes(): iterable
    {
        yield 'hosted' => [UiMode::Hosted];
        yield 'embedded' => [UiMode::Embedded];
    }

    public function testChangedCartRevisionCreatesAnIndependentOrderAndSession(): void
    {
        $stripeCheckout = new StripeCheckout($this->kirby);
        $cart = $stripeCheckout->cart();
        $this->assertNotNull($cart);
        $cart->add('product', 2);
        $firstBody = $stripeCheckout->checkout(CheckoutSource::Cart)->submissionData();
        unset($firstBody['csrf']);
        $this->assertSame(201, $this->send($firstBody)->code());

        $item = $cart->items()[0];
        $cart->update($item->id(), 3);
        $secondBody = (new StripeCheckout($this->kirby))->checkout(CheckoutSource::Cart)->submissionData();
        unset($secondBody['csrf']);
        $firstRevision = $firstBody['revision'] ?? null;
        $secondRevision = $secondBody['revision'] ?? null;
        $this->assertIsString($firstRevision);
        $this->assertIsString($secondRevision);
        $this->assertNotSame($firstRevision, $secondRevision);
        $this->createdSessionId = 'cs_test_changed_cart';

        $this->assertSame(201, $this->send($secondBody)->code());

        $firstOrder = $this->orderData($firstBody);
        $secondOrder = $this->orderData($secondBody);
        $this->assertSame(CheckoutStatus::Open->value, $firstOrder['checkoutStatus']);
        $this->assertSame('cs_test_browser', $firstOrder['stripeCheckoutSessionId']);
        $this->assertSame(CheckoutStatus::Open->value, $secondOrder['checkoutStatus']);
        $this->assertSame('cs_test_changed_cart', $secondOrder['stripeCheckoutSessionId']);
        $this->assertSame(['post', 'post'], array_column($this->requests, 'method'));
        $this->assertCount(2, (new OrderPageStore($this->kirby))->orders());
    }

    public function testChangedLanguageCreatesAnIndependentCheckoutForTheSameCartRevision(): void
    {
        $stripeCheckout = new StripeCheckout($this->kirby);
        $stripeCheckout->cart()?->add('product', 2);
        $englishBody = $stripeCheckout->checkout(CheckoutSource::Cart)->submissionData();
        unset($englishBody['csrf']);
        $this->assertSame(201, $this->send($englishBody)->code());

        $this->kirby->setCurrentLanguage('pt');
        $portugueseBody = (new StripeCheckout($this->kirby))->checkout(CheckoutSource::Cart)->submissionData();
        unset($portugueseBody['csrf']);
        $englishRevision = $englishBody['revision'] ?? null;
        $portugueseRevision = $portugueseBody['revision'] ?? null;
        $this->assertIsString($englishRevision);
        $this->assertSame($englishRevision, $portugueseRevision);
        $this->assertNotSame($englishBody['attemptToken'], $portugueseBody['attemptToken']);
        $this->createdSessionId = 'cs_test_portuguese';

        $this->assertSame(201, $this->send($portugueseBody, languageCode: 'pt')->code());

        $englishOrder = $this->orderData($englishBody);
        $portugueseOrder = $this->orderData($portugueseBody);
        $this->assertSame('en', $englishOrder['languageCode']);
        $this->assertSame('pt', $portugueseOrder['languageCode']);
        $this->assertSame('cs_test_browser', $englishOrder['stripeCheckoutSessionId']);
        $this->assertSame('cs_test_portuguese', $portugueseOrder['stripeCheckoutSessionId']);
        $this->assertSame(['post', 'post'], array_column($this->requests, 'method'));
        $this->assertCount(2, (new OrderPageStore($this->kirby))->orders());
    }

    public function testLoginPreservesTheCartButCreatesCheckoutForTheNewActor(): void
    {
        /** @var User $user */
        $user = $this->kirby->impersonate('kirby', fn() => $this->kirby->users()->create([
            'email' => 'checkout@example.test',
            'role' => 'admin',
            'password' => 'test-password-123',
        ]));
        $stripeCheckout = new StripeCheckout($this->kirby);
        $cart = $stripeCheckout->cart();
        $this->assertNotNull($cart);
        $cart->add('product', 2);
        $guestBody = $stripeCheckout->checkout(CheckoutSource::Cart)->submissionData();
        unset($guestBody['csrf']);
        $this->assertSame(201, $this->send($guestBody)->code());

        $guestRevision = $guestBody['revision'] ?? null;
        $this->assertIsString($guestRevision);
        $user->loginPasswordless();
        $userCheckout = new StripeCheckout($this->kirby);
        $userCart = $userCheckout->cart();
        $this->assertNotNull($userCart);
        $this->assertSame($guestRevision, $userCart->revision());
        $userBody = $userCheckout->checkout(CheckoutSource::Cart)->submissionData();
        unset($userBody['csrf']);
        $userRevision = $userBody['revision'] ?? null;
        $this->assertIsString($userRevision);
        $this->assertSame($guestRevision, $userRevision);
        $this->assertNotSame($guestBody['attemptToken'], $userBody['attemptToken']);
        $this->createdSessionId = 'cs_test_signed_in';

        $this->assertSame(201, $this->send($userBody)->code());

        $guestOrder = $this->orderData($guestBody);
        $userOrder = $this->orderData($userBody);
        $this->assertArrayNotHasKey('userUuid', $guestOrder);
        $this->assertSame($user->uuid()->toString(), $userOrder['userUuid']);
        $this->assertSame('cs_test_browser', $guestOrder['stripeCheckoutSessionId']);
        $this->assertSame('cs_test_signed_in', $userOrder['stripeCheckoutSessionId']);
        $this->assertSame(['post', 'post'], array_column($this->requests, 'method'));
        $this->assertCount(2, (new OrderPageStore($this->kirby))->orders());
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

    public function testCompletedCartOrderClosesOtherActionsForTheSameCart(): void
    {
        $stripeCheckout = new StripeCheckout($this->kirby);
        $stripeCheckout->cart()?->add('product', 2);
        $firstBody = $stripeCheckout->checkout(CheckoutSource::Cart)->submissionData();
        $secondBody = (new StripeCheckout($this->kirby))->checkout(CheckoutSource::Cart)->submissionData();
        unset($firstBody['csrf'], $secondBody['csrf']);
        $this->assertSame(201, $this->send($firstBody)->code());

        $this->sessionStatus = 'complete';
        $this->sessionPaymentStatus = 'paid';
        $this->paymentIntentStatus = 'succeeded';
        $pageUuid = OrderData::pageUuid((new AttemptToken($firstBody['attemptToken']))->orderUuid());
        (new RuntimeFactory($this->kirby))->checkoutSessionReconciler()->reconcile($pageUuid, 'cs_test_browser');

        $this->assertError($this->send($secondBody), 409, 'checkout.attempt_closed', false);
        $this->assertCount(1, array_filter($this->requests, static fn(array $request): bool => $request['method'] === 'post'));
        $this->assertCount(1, (new OrderPageStore($this->kirby))->orders());
    }

    public function testCreationFailureAllowsANewOrderForTheUnchangedCart(): void
    {
        $body = $this->submission(CheckoutSource::Cart);
        $this->providerStatus = 400;
        $this->assertError($this->send($body), 502, 'checkout.session_rejected', false);
        $orderData = $this->orderData($body);
        $this->assertSame(CheckoutStatus::CreationFailed->value, $orderData['checkoutStatus']);
        $this->assertIsString($body['attemptToken']);
        $pageUuid = OrderData::pageUuid((new AttemptToken($body['attemptToken']))->orderUuid());
        $store = new OrderPageStore($this->kirby);
        $this->assertFalse($store->deleteManually(
            uuid: $pageUuid,
            checkedAt: OrderData::date($orderData['createdAt'])->modify('+23 hours 59 minutes 59 seconds'),
        ));
        $this->assertNotNull($store->order($pageUuid));

        $this->providerStatus = null;
        $replacement = (new StripeCheckout($this->kirby))->checkout(CheckoutSource::Cart)->submissionData();
        unset($replacement['csrf']);
        $this->assertNotSame($body['attemptToken'], $replacement['attemptToken']);
        $bodyRevision = $body['revision'] ?? null;
        $replacementRevision = $replacement['revision'] ?? null;
        $this->assertIsString($bodyRevision);
        $this->assertSame($bodyRevision, $replacementRevision);
        $this->assertSame(201, $this->send($replacement)->code());
        $this->assertCount(2, $store->orders());
        $this->assertCount(2, array_filter($this->requests, static fn(array $request): bool => $request['method'] === 'post'));
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

    public function testHostedFormRedirectsDirectlyToStripe(): void
    {
        $body = $this->submission();
        $response = $this->sendForm($body);
        $this->assertSame(303, $response->code());
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_browser', $response->headers()['Location']);
        $this->assertPrivate($response);
        $this->assertCount(1, $this->requests);
        $this->assertSame(1, $this->resolutions);
        $this->assertNull($this->kirby->session()->data()->get(CheckoutFormResultStore::KEY));
    }

    public function testHostedFormReturnsSafePreAttemptFailureWithAFreshAction(): void
    {
        $this->restart(options: ['products' => ['resolver' => static function (): never {
            throw new ProductUnavailableException();
        }]]);
        $body = $this->submission();
        $response = $this->sendForm($body);
        $this->assertSame(303, $response->code());
        $location = $response->headers()['Location'];
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://kirby-stripe-checkout.test', $location);
        $this->assertStringContainsString('_stripe_checkout_form_result=', $location);
        $bootstrap = $this->checkoutAt($location);
        $error = $bootstrap->error();
        $this->assertNotNull($error);
        $this->assertSame('product.unavailable', $error->code());
        $this->assertFalse($error->retryable());
        $this->assertSame($error->toArray(), $bootstrap->toArray()['error']);
        $this->assertNotSame($body['attemptToken'], $bootstrap->submissionData()['attemptToken']);
        $this->assertArrayNotHasKey('items', $bootstrap->submissionData());
        $this->assertCount(0, $this->requests);
        $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
    }

    public function testHostedFormUncertaintyReturnsTheExactAcceptedAction(): void
    {
        $body = $this->submission();
        $this->uncertain = true;
        $response = $this->sendForm($body);
        $this->assertSame(303, $response->code());
        $location = $response->headers()['Location'];
        $this->assertIsString($location);
        $bootstrap = $this->checkoutAt($location);
        $error = $bootstrap->error();
        $this->assertNotNull($error);
        $this->assertSame('checkout.session_uncertain', $error->code());
        $this->assertTrue($error->retryable());
        $retryData = $bootstrap->submissionData();
        $this->assertSame($body['attemptToken'], $retryData['attemptToken']);
        $this->assertSame([[
            'reference' => 'product',
            'quantity' => 2,
        ]], $retryData['items'] ?? null);
        $storedResult = json_encode($this->kirby->session()->data()->get(CheckoutFormResultStore::KEY), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('https://checkout.stripe.com', $storedResult);
        $this->assertStringNotContainsString('cs_test_browser_secret_private', $storedResult);
        $this->assertStringNotContainsString('private provider details', $storedResult);
        $retried = $this->sendForm($retryData);
        $this->assertSame(303, $retried->code());
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_browser', $retried->headers()['Location']);
        $this->assertSame($this->requests[0]['parameters'], $this->requests[1]['parameters']);
        $this->assertSame($this->requests[0]['idempotencyKey'], $this->requests[1]['idempotencyKey']);
        $this->assertCount(1, (new OrderPageStore($this->kirby))->orders());
    }

    public function testHostedCartFormUncertaintyRetainsTheSavedCartAction(): void
    {
        $body = $this->submission(CheckoutSource::Cart);
        $this->uncertain = true;
        $response = $this->sendForm($body);
        $location = $response->headers()['Location'];
        $this->assertIsString($location);
        $bootstrap = $this->checkoutAt($location, CheckoutSource::Cart);
        $retryData = $bootstrap->submissionData();
        $this->assertSame('checkout.session_uncertain', $bootstrap->error()?->code());
        $this->assertSame($body['attemptToken'], $retryData['attemptToken']);
        $this->assertSame($body['revision'], $retryData['revision'] ?? null);
        $this->assertArrayNotHasKey('items', $retryData);
        $this->assertArrayNotHasKey('shippingCountry', $retryData);
        $retried = $this->sendForm($retryData);
        $this->assertSame(303, $retried->code());
        $this->assertSame($this->requests[0]['parameters'], $this->requests[1]['parameters']);
        $this->assertSame($this->requests[0]['idempotencyKey'], $this->requests[1]['idempotencyKey']);
    }

    public function testHostedFormUnavailablePresentationKeepsTheExistingAction(): void
    {
        $body = $this->submission();
        $this->assertSame(303, $this->sendForm($body)->code());
        $this->readUnavailable = true;
        $response = $this->sendForm($body);
        $this->assertSame(303, $response->code());
        $location = $response->headers()['Location'];
        $this->assertIsString($location);
        $bootstrap = $this->checkoutAt($location);
        $this->assertSame('checkout.session_unavailable', $bootstrap->error()?->code());
        $this->assertSame($body['attemptToken'], $bootstrap->submissionData()['attemptToken']);
        $this->assertSame(['post', 'get'], array_column($this->requests, 'method'));
    }

    public function testHostedFormResultsRemainIndependentAcrossTabs(): void
    {
        $this->restart(options: [
            'settings' => ['shippingZones' => [[
                'name' => 'Portugal',
                'scope' => 'selected_countries',
                'countries' => ['PT'],
                'options' => [[
                    'key' => 'standard',
                    'label' => 'Standard delivery',
                    'amount' => '4.90',
                ]],
            ]]],
            'products' => ['resolver' => function (ProductRequest $request): Product {
                if ($request->reference() === 'unavailable') {
                    throw new ProductUnavailableException();
                }

                return new Product(
                    request: $request,
                    name: 'Product',
                    requiresShipping: true,
                    price: new Price(Money::of('16.00', 'EUR')),
                );
            }],
        ]);
        $shippingBody = $this->submission();
        $shippingBody['items'] = [['reference' => 'physical']];
        $productBody = $this->submission();
        $productBody['items'] = [['reference' => 'unavailable']];
        $shippingResponse = $this->sendForm($shippingBody);
        $productResponse = $this->sendForm($productBody);
        $shippingLocation = $shippingResponse->headers()['Location'];
        $productLocation = $productResponse->headers()['Location'];
        $this->assertIsString($shippingLocation);
        $this->assertIsString($productLocation);
        $this->assertNotSame($shippingLocation, $productLocation);
        $this->assertSame('shipping.country_required', $this->checkoutAt($shippingLocation)->error()?->code());
        $this->assertSame('product.unavailable', $this->checkoutAt($productLocation)->error()?->code());
    }

    public function testFormResultReferenceIsBoundToItsInitiatingPage(): void
    {
        $this->restart(options: ['products' => ['resolver' => static function (): never {
            throw new ProductUnavailableException();
        }]]);
        $response = $this->sendForm($this->submission());
        $location = $response->headers()['Location'];
        $this->assertIsString($location);
        $query = parse_url($location, PHP_URL_QUERY);
        $this->assertIsString($query);
        $otherPage = 'https://kirby-stripe-checkout.test/another-page?' . $query;
        $this->assertNull($this->checkoutAt($otherPage)->error());
        $this->assertSame('product.unavailable', $this->checkoutAt($location)->error()?->code());
    }

    public function testFormFailuresWithoutAnAuthorizedActionRemainDirect(): void
    {
        $body = $this->submission();
        $body['csrf'] = 'wrong';
        $response = $this->sendForm($body);
        $this->assertSame(403, $response->code());
        $this->assertArrayNotHasKey('Location', $response->headers());
        $this->assertNull($this->kirby->session()->data()->get(CheckoutFormResultStore::KEY));
        $this->assertCount(0, $this->requests);
    }

    public function testUnsupportedFormMediaUsesTheNegotiatedHtmlResponse(): void
    {
        $response = $this->send('unsupported', headers: [
            'Accept' => 'text/html',
            'Content-Type' => 'text/plain',
            'X-CSRF' => null,
        ]);
        $this->assertSame(415, $response->code());
        $this->assertSame('', $response->body());
        $this->assertPrivate($response);
        $this->assertCount(0, $this->requests);
        $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
    }

    public function testEmbeddedFormRemainsUnsupported(): void
    {
        $this->restart(UiMode::Embedded);
        $response = $this->sendForm($this->submission());
        $this->assertSame(406, $response->code());
        $this->assertArrayNotHasKey('Location', $response->headers());
        $this->assertCount(0, $this->requests);
        $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
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
        $this->assertError($this->send($body, headers: ['Content-Type' => 'text/plain']), 415, 'request.unsupported_media_type', false);
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

    public function testHostedCancellationReturnsOneAvailableResultWithoutChangingCommerceState(): void
    {
        $this->restart(options: ['settings' => ['cancelDestination' => '/cancelled?from=checkout']]);
        $body = $this->submission(CheckoutSource::Cart);
        $this->assertSame(201, $this->send($body)->code());
        $before = $this->orderData($body);
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $cartRevision = $cart->revision();
        $this->assertSame(1, $cart->count());
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['cancel_url']);

        $cancelled = $this->sendNavigation($parameters['cancel_url']);
        $this->assertSame(303, $cancelled->code());
        $this->assertNavigationPrivate($cancelled);
        $location = $cancelled->headers()['Location'] ?? null;
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://kirby-stripe-checkout.test/en/cancelled?from=checkout&', $location);
        $this->assertStringContainsString('_stripe_checkout_result=', $location);
        $this->assertCount(1, $this->requests);
        $this->assertSame($before, $this->orderData($body));
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $this->assertSame($cartRevision, $cart->revision());
        $this->assertSame(1, $cart->count());

        $this->setRequest($location);
        $stripeCheckout = new StripeCheckout($this->kirby);
        $result = $stripeCheckout->checkoutResult();
        $this->assertNotNull($result);
        $this->assertSame($result, $stripeCheckout->checkoutResult());
        $this->assertSame(CheckoutResultReason::HostedCancel, $result->reason());
        $this->assertTrue($result->isAvailable());
        $this->assertSame(CheckoutStatus::Open, $result->checkoutStatus());
        $this->assertSame(PaymentStatus::Unpaid, $result->paymentStatus());
        $this->assertFalse($result->canResumeCheckout());
        $this->assertNotNull($result->order());
        $this->assertNull((new StripeCheckout($this->kirby))->checkoutResult());
        $this->assertNull($this->withUnavailableSession(
            fn() => (new StripeCheckout($this->kirby))->checkoutResult(),
        ));
    }

    #[DataProvider('providerReturnModes')]
    public function testAuthoritativeCompletedReturnRedirectsToSuccessAndClearsTheOriginatingCart(
        UiMode $uiMode,
        string $requestUrlKey,
        CheckoutResultReason $reason,
    ): void {
        $this->restart($uiMode, ['settings' => ['successDestination' => '/completed?from=checkout']]);
        $body = $this->submission(CheckoutSource::Cart);
        $this->assertSame(201, $this->send($body)->code());
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $originalRevision = $cart->revision();
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters[$requestUrlKey]);
        $this->sessionStatus = 'complete';
        $this->sessionPaymentStatus = 'paid';
        $this->paymentIntentStatus = 'succeeded';

        $returned = $this->sendNavigation(str_replace('{CHECKOUT_SESSION_ID}', 'cs_test_browser', $parameters[$requestUrlKey]));
        $this->assertSame(303, $returned->code());
        $this->assertNavigationPrivate($returned);
        $location = $returned->headers()['Location'] ?? null;
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://kirby-stripe-checkout.test/en/completed?from=checkout&', $location);
        $this->assertStringContainsString('_stripe_checkout_result=', $location);
        $this->assertSame(['post', 'get', 'get'], array_column($this->requests, 'method'));
        $data = $this->orderData($body);
        $this->assertSame(CheckoutStatus::Complete->value, $data['checkoutStatus']);
        $this->assertSame(PaymentStatus::Paid->value, $data['paymentStatus']);
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $this->assertNotSame($originalRevision, $cart->revision());
        $this->assertSame(0, $cart->count());

        $this->setRequest($location);
        $result = (new StripeCheckout($this->kirby))->checkoutResult();
        $this->assertNotNull($result);
        $this->assertSame($reason, $result->reason());
        $this->assertTrue($result->isAvailable());
        $this->assertSame(CheckoutStatus::Complete, $result->checkoutStatus());
        $this->assertSame(PaymentStatus::Paid, $result->paymentStatus());
        $this->assertFalse($result->canResumeCheckout());
    }

    /** @return iterable<string, array{UiMode, string, CheckoutResultReason}> */
    public static function providerReturnModes(): iterable
    {
        yield 'hosted success' => [UiMode::Hosted, 'success_url', CheckoutResultReason::HostedSuccess];
        yield 'embedded completion' => [UiMode::Embedded, 'return_url', CheckoutResultReason::EmbeddedReturn];
    }

    public function testWebhookOnlyCompletionClearsTheExactCartOnItsNextReadWithoutAProviderRequest(): void
    {
        $body = $this->submission(CheckoutSource::Cart);
        $this->assertSame(201, $this->send($body)->code());
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $originalRevision = $cart->revision();
        $this->sessionStatus = 'complete';
        $this->sessionPaymentStatus = 'paid';
        $this->paymentIntentStatus = 'succeeded';
        $attemptToken = $body['attemptToken'];
        $this->assertIsString($attemptToken);
        $pageUuid = OrderData::pageUuid((new AttemptToken($attemptToken))->orderUuid());
        (new RuntimeFactory($this->kirby))->checkoutSessionReconciler()->reconcile($pageUuid, 'cs_test_browser');
        $providerRequestCount = count($this->requests);

        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $this->assertNotSame($originalRevision, $cart->revision());
        $this->assertSame(0, $cart->count());
        $this->assertCount($providerRequestCount, $this->requests);
    }

    public function testWebhookOnlyCompletionPreservesACartChangedAfterCheckoutStarted(): void
    {
        $body = $this->submission(CheckoutSource::Cart);
        $this->assertSame(201, $this->send($body)->code());
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $cart->add('product');
        $changedRevision = $cart->revision();
        $this->sessionStatus = 'complete';
        $this->sessionPaymentStatus = 'paid';
        $this->paymentIntentStatus = 'succeeded';
        $attemptToken = $body['attemptToken'];
        $this->assertIsString($attemptToken);
        $pageUuid = OrderData::pageUuid((new AttemptToken($attemptToken))->orderUuid());
        (new RuntimeFactory($this->kirby))->checkoutSessionReconciler()->reconcile($pageUuid, 'cs_test_browser');
        $providerRequestCount = count($this->requests);

        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $this->assertSame($changedRevision, $cart->revision());
        $this->assertSame(3, $cart->totalQuantity());
        $this->assertCount($providerRequestCount, $this->requests);
    }

    public function testCartCheckoutBootstrapAppliesDeferredWebhookCompletionBeforeIssuingANewAction(): void
    {
        $body = $this->submission(CheckoutSource::Cart);
        $this->assertSame(201, $this->send($body)->code());
        $this->sessionStatus = 'complete';
        $this->sessionPaymentStatus = 'paid';
        $this->paymentIntentStatus = 'succeeded';
        $attemptToken = $body['attemptToken'];
        $this->assertIsString($attemptToken);
        $pageUuid = OrderData::pageUuid((new AttemptToken($attemptToken))->orderUuid());
        (new RuntimeFactory($this->kirby))->checkoutSessionReconciler()->reconcile($pageUuid, 'cs_test_browser');
        $providerRequestCount = count($this->requests);

        $submissionData = (new StripeCheckout($this->kirby))->checkout()->submissionData();
        $this->assertArrayHasKey('revision', $body);
        $this->assertArrayHasKey('revision', $submissionData);
        $this->assertNotSame($body['revision'], $submissionData['revision']);
        $this->assertCount($providerRequestCount, $this->requests);
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $this->assertSame(0, $cart->count());
    }

    public function testCartMutationAfterWebhookOnlyCompletionStartsFromTheClearedCart(): void
    {
        $body = $this->submission(CheckoutSource::Cart);
        $this->assertSame(201, $this->send($body)->code());
        $this->sessionStatus = 'complete';
        $this->sessionPaymentStatus = 'paid';
        $this->paymentIntentStatus = 'succeeded';
        $attemptToken = $body['attemptToken'];
        $this->assertIsString($attemptToken);
        $pageUuid = OrderData::pageUuid((new AttemptToken($attemptToken))->orderUuid());
        (new RuntimeFactory($this->kirby))->checkoutSessionReconciler()->reconcile($pageUuid, 'cs_test_browser');
        $providerRequestCount = count($this->requests);

        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $cart->add('product');
        $this->assertSame(1, $cart->totalQuantity());
        $this->assertCount($providerRequestCount, $this->requests);
    }

    public function testWebhookOnlyDirectCompletionDoesNotClearTheCurrentCart(): void
    {
        $stripeCheckout = new StripeCheckout($this->kirby);
        $cart = $stripeCheckout->cart();
        $this->assertNotNull($cart);
        $cart->add('product');
        $cartRevision = $cart->revision();
        $body = $this->submission();
        $this->assertSame(201, $this->send($body)->code());
        $this->sessionStatus = 'complete';
        $this->sessionPaymentStatus = 'paid';
        $this->paymentIntentStatus = 'succeeded';
        $attemptToken = $body['attemptToken'];
        $this->assertIsString($attemptToken);
        $pageUuid = OrderData::pageUuid((new AttemptToken($attemptToken))->orderUuid());
        (new RuntimeFactory($this->kirby))->checkoutSessionReconciler()->reconcile($pageUuid, 'cs_test_browser');
        $providerRequestCount = count($this->requests);

        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $this->assertSame($cartRevision, $cart->revision());
        $this->assertSame(1, $cart->totalQuantity());
        $this->assertCount($providerRequestCount, $this->requests);
    }

    public function testEmbeddedOpenReturnUsesItsReturnDestinationWithoutClearingTheCart(): void
    {
        $this->restart(UiMode::Embedded, ['settings' => ['returnDestination' => '/continue?from=checkout']]);
        $this->kirby->setCurrentLanguage('pt');
        $body = $this->submission(CheckoutSource::Cart);
        $this->assertSame(201, $this->send($body, languageCode: 'pt')->code());
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $originalRevision = $cart->revision();
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['return_url']);

        $returned = $this->sendNavigation(str_replace('{CHECKOUT_SESSION_ID}', 'cs_test_browser', $parameters['return_url']));
        $location = $returned->headers()['Location'] ?? null;
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://kirby-stripe-checkout.test/pt/continue?from=checkout&', $location);
        $this->assertSame(CheckoutStatus::Open->value, $this->orderData($body)['checkoutStatus']);
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $this->assertSame($originalRevision, $cart->revision());
        $this->assertSame(1, $cart->count());

        $this->setRequest($location);
        $stripeCheckout = new StripeCheckout($this->kirby);
        $result = $stripeCheckout->checkoutResult();
        $this->assertNotNull($result);
        $this->assertTrue($result->isAvailable());
        $this->assertSame(CheckoutStatus::Open, $result->checkoutStatus());
        $this->assertTrue($result->canResumeCheckout());
        $resumeData = $stripeCheckout->checkout(CheckoutSource::Cart)->submissionData();
        $this->assertArrayHasKey('revision', $body);
        $this->assertArrayHasKey('revision', $resumeData);
        $this->assertSame($body['attemptToken'], $resumeData['attemptToken']);
        $this->assertSame($body['revision'], $resumeData['revision']);
        unset($resumeData['csrf']);
        $resumed = $this->send($resumeData, languageCode: 'pt');
        $this->assertSame(200, $resumed->code());
        $this->assertSame(['post', 'get', 'get', 'get'], array_column($this->requests, 'method'));
        $this->assertCount(1, array_filter($this->requests, static fn(array $request): bool => $request['method'] === 'post'));
    }

    public function testEmbeddedOpenReturnReissuesTheAcceptedDirectInput(): void
    {
        $this->restart(UiMode::Embedded, ['settings' => ['returnDestination' => '/continue']]);
        $body = $this->submission();
        $this->assertSame(201, $this->send($body)->code());
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['return_url']);
        $returned = $this->sendNavigation(str_replace('{CHECKOUT_SESSION_ID}', 'cs_test_browser', $parameters['return_url']));
        $location = $returned->headers()['Location'] ?? null;
        $this->assertIsString($location);

        $this->setRequest($location);
        $stripeCheckout = new StripeCheckout($this->kirby);
        $resumeData = $stripeCheckout->checkout(CheckoutSource::Direct)->submissionData();
        $this->assertTrue($stripeCheckout->checkoutResult()?->canResumeCheckout());
        $this->assertSame($body['attemptToken'], $resumeData['attemptToken']);
        $this->assertArrayHasKey('items', $resumeData);
        $this->assertSame([[
            'reference' => 'product',
            'quantity' => 2,
        ]], $resumeData['items']);
        unset($resumeData['csrf']);
        $this->assertSame(200, $this->send($resumeData)->code());
        $this->assertCount(1, array_filter($this->requests, static fn(array $request): bool => $request['method'] === 'post'));
    }

    public function testCompletedHostedReturnKeepsPendingPaymentDistinctFromCheckoutCompletion(): void
    {
        $this->restart(options: ['settings' => ['successDestination' => '/completed']]);
        $body = $this->submission();
        $this->assertSame(201, $this->send($body)->code());
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['success_url']);
        $this->sessionStatus = 'complete';
        $this->paymentIntentStatus = 'processing';
        $this->paymentNextAction = [
            'type' => 'multibanco_display_details',
            'multibanco_display_details' => [
                'reference' => '123456789',
            ],
        ];

        $returned = $this->sendNavigation(str_replace('{CHECKOUT_SESSION_ID}', 'cs_test_browser', $parameters['success_url']));
        $location = $returned->headers()['Location'] ?? null;
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://kirby-stripe-checkout.test/en/completed?', $location);
        $data = $this->orderData($body);
        $this->assertSame(CheckoutStatus::Complete->value, $data['checkoutStatus']);
        $this->assertSame(PaymentStatus::Pending->value, $data['paymentStatus']);

        $this->setRequest($location);
        $result = (new StripeCheckout($this->kirby))->checkoutResult();
        $this->assertNotNull($result);
        $this->assertSame(CheckoutStatus::Complete, $result->checkoutStatus());
        $this->assertSame(PaymentStatus::Pending, $result->paymentStatus());
        $payment = $result->payment();
        $this->assertNotNull($payment);
        $this->assertSame('pi_browser', $payment->stripePaymentIntentId());
        $nextAction = $payment->nextAction();
        $this->assertNotNull($nextAction);
        $this->assertSame('multibanco_display_details', $nextAction->type());
        $this->assertSame('123456789', $nextAction->details()['reference']);
    }

    public function testReturnResultDropsItsObservedActionAfterThePaymentBecomesTerminal(): void
    {
        $this->restart(options: ['settings' => ['successDestination' => '/completed']]);
        $body = $this->submission();
        $this->assertSame(201, $this->send($body)->code());
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['success_url']);
        $this->sessionStatus = 'complete';
        $this->paymentIntentStatus = 'processing';
        $this->paymentNextAction = [
            'type' => 'multibanco_display_details',
            'multibanco_display_details' => [
                'reference' => '123456789',
            ],
        ];

        $returned = $this->sendNavigation(str_replace('{CHECKOUT_SESSION_ID}', 'cs_test_browser', $parameters['success_url']));
        $location = $returned->headers()['Location'] ?? null;
        $this->assertIsString($location);
        $this->sessionPaymentStatus = 'paid';
        $this->paymentIntentStatus = 'succeeded';
        $this->paymentNextAction = null;
        $attemptToken = $body['attemptToken'];
        $this->assertIsString($attemptToken);
        $pageUuid = OrderData::pageUuid((new AttemptToken($attemptToken))->orderUuid());
        (new RuntimeFactory($this->kirby))->checkoutSessionReconciler()->reconcile($pageUuid, 'cs_test_browser');

        $this->setRequest($location);
        $result = (new StripeCheckout($this->kirby))->checkoutResult();
        $this->assertNotNull($result);
        $this->assertSame(PaymentStatus::Paid, $result->paymentStatus());
        $this->assertNull($result->payment()?->nextAction());
    }

    public function testExpiredEmbeddedReturnUsesItsReturnDestinationAndPreservesTheCart(): void
    {
        $this->restart(UiMode::Embedded, ['settings' => ['returnDestination' => '/continue']]);
        $body = $this->submission(CheckoutSource::Cart);
        $this->assertSame(201, $this->send($body)->code());
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $originalRevision = $cart->revision();
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['return_url']);
        $this->sessionStatus = 'expired';

        $returned = $this->sendNavigation(str_replace('{CHECKOUT_SESSION_ID}', 'cs_test_browser', $parameters['return_url']));
        $location = $returned->headers()['Location'] ?? null;
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://kirby-stripe-checkout.test/en/continue?', $location);
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $this->assertSame($originalRevision, $cart->revision());
        $this->assertSame(1, $cart->count());

        $this->setRequest($location);
        $stripeCheckout = new StripeCheckout($this->kirby);
        $result = $stripeCheckout->checkoutResult();
        $this->assertNotNull($result);
        $this->assertSame(CheckoutStatus::Expired, $result->checkoutStatus());
        $this->assertSame(PaymentStatus::Unpaid, $result->paymentStatus());
        $this->assertFalse($result->canResumeCheckout());
        $this->assertNotSame($body['attemptToken'], $stripeCheckout->checkout()->submissionData()['attemptToken']);
    }

    public function testExpiredEmbeddedReturnDoesNotExposeAnObservedPaymentAction(): void
    {
        $this->restart(UiMode::Embedded, ['settings' => ['returnDestination' => '/continue']]);
        $body = $this->submission();
        $this->assertSame(201, $this->send($body)->code());
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['return_url']);
        $this->sessionStatus = 'expired';
        $this->paymentIntentStatus = 'requires_action';
        $this->paymentNextAction = [
            'type' => 'multibanco_display_details',
            'multibanco_display_details' => [
                'reference' => '123456789',
            ],
        ];

        $returned = $this->sendNavigation(str_replace('{CHECKOUT_SESSION_ID}', 'cs_test_browser', $parameters['return_url']));
        $location = $returned->headers()['Location'] ?? null;
        $this->assertIsString($location);
        $this->setRequest($location);
        $result = (new StripeCheckout($this->kirby))->checkoutResult();
        $this->assertNotNull($result);
        $this->assertSame(CheckoutStatus::Expired, $result->checkoutStatus());
        $this->assertSame(PaymentStatus::Unpaid, $result->paymentStatus());
        $payment = $result->payment();
        $this->assertNotNull($payment);
        $this->assertSame('pi_browser', $payment->stripePaymentIntentId());
        $this->assertNull($payment->nextAction());
    }

    public function testCompletedReturnPreservesACartChangedAfterCheckoutStarted(): void
    {
        $this->restart(options: ['settings' => ['successDestination' => '/completed']]);
        $body = $this->submission(CheckoutSource::Cart);
        $this->assertSame(201, $this->send($body)->code());
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $cart->add('product');
        $changedRevision = $cart->revision();
        $this->assertSame(3, $cart->totalQuantity());
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['success_url']);
        $this->sessionStatus = 'complete';
        $this->sessionPaymentStatus = 'paid';
        $this->paymentIntentStatus = 'succeeded';

        $this->sendNavigation(str_replace('{CHECKOUT_SESSION_ID}', 'cs_test_browser', $parameters['success_url']));
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $this->assertSame($changedRevision, $cart->revision());
        $this->assertSame(3, $cart->totalQuantity());
    }

    public function testExternalSuccessDestinationReceivesNoBrowserResultButStillClearsTheMatchingCart(): void
    {
        $this->restart(options: ['settings' => ['successDestination' => 'https://storefront.example/completed']]);
        $body = $this->submission(CheckoutSource::Cart);
        $this->assertSame(201, $this->send($body)->code());
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['success_url']);
        $this->sessionStatus = 'complete';
        $this->sessionPaymentStatus = 'paid';
        $this->paymentIntentStatus = 'succeeded';

        $returned = $this->sendNavigation(str_replace('{CHECKOUT_SESSION_ID}', 'cs_test_browser', $parameters['success_url']));
        $this->assertSame('https://storefront.example/completed', $returned->headers()['Location'] ?? null);
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $this->assertSame(0, $cart->count());
        $this->assertNull($this->kirby->session()->data()->get(CheckoutResultStore::KEY));
    }

    public function testUnavailableEmbeddedReturnExposesNoOrderFactsAndPreservesTheCart(): void
    {
        $this->restart(UiMode::Embedded, ['settings' => ['returnDestination' => '/continue']]);
        $body = $this->submission(CheckoutSource::Cart);
        $this->assertSame(201, $this->send($body)->code());
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $originalRevision = $cart->revision();
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['return_url']);
        $this->readUnavailable = true;

        $returned = $this->sendNavigation(str_replace('{CHECKOUT_SESSION_ID}', 'cs_test_browser', $parameters['return_url']));
        $location = $returned->headers()['Location'] ?? null;
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://kirby-stripe-checkout.test/en/continue?', $location);
        $this->assertSame(['post', 'get'], array_column($this->requests, 'method'));
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $this->assertSame($originalRevision, $cart->revision());
        $this->assertSame(1, $cart->count());

        $this->setRequest($location);
        $result = (new StripeCheckout($this->kirby))->checkoutResult();
        $this->assertNotNull($result);
        $this->assertSame(CheckoutResultReason::EmbeddedReturn, $result->reason());
        $this->assertFalse($result->isAvailable());
        $this->assertNull($result->order());
        $this->assertNull($result->checkoutStatus());
        $this->assertNull($result->paymentStatus());
    }

    public function testMismatchedReturnSessionPerformsNoProviderReadAndExposesNoOrderFacts(): void
    {
        $this->restart(options: ['settings' => ['successDestination' => '/completed']]);
        $body = $this->submission();
        $this->assertSame(201, $this->send($body)->code());
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['success_url']);

        $returned = $this->sendNavigation(str_replace('{CHECKOUT_SESSION_ID}', 'cs_other', $parameters['success_url']));
        $location = $returned->headers()['Location'] ?? null;
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://kirby-stripe-checkout.test/en/completed?', $location);
        $this->assertCount(1, $this->requests);

        $this->setRequest($location);
        $result = (new StripeCheckout($this->kirby))->checkoutResult();
        $this->assertNotNull($result);
        $this->assertSame(CheckoutResultReason::HostedSuccess, $result->reason());
        $this->assertFalse($result->isAvailable());
        $this->assertNull($result->order());
    }

    public function testCompletedReturnWithoutBrowserBindingReconcilesWithoutClearingOrExposingAResult(): void
    {
        $this->restart(options: ['settings' => ['successDestination' => '/completed']]);
        $body = $this->submission(CheckoutSource::Cart);
        $this->assertSame(201, $this->send($body)->code());
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $originalRevision = $cart->revision();
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['success_url']);
        $this->kirby->session()->data()->remove(BrowserAttemptStore::KEY);
        $this->sessionStatus = 'complete';
        $this->sessionPaymentStatus = 'paid';
        $this->paymentIntentStatus = 'succeeded';

        $returned = $this->sendNavigation(str_replace('{CHECKOUT_SESSION_ID}', 'cs_test_browser', $parameters['success_url']));
        $this->assertSame('https://kirby-stripe-checkout.test/en/completed', $returned->headers()['Location'] ?? null);
        $this->assertSame(CheckoutStatus::Complete->value, $this->orderData($body)['checkoutStatus']);
        $cart = (new StripeCheckout($this->kirby))->cart();
        $this->assertNotNull($cart);
        $this->assertSame($originalRevision, $cart->revision());
        $this->assertSame(1, $cart->count());
        $this->assertNull($this->kirby->session()->data()->get(CheckoutResultStore::KEY));
    }

    public function testProviderReturnRejectsTheWrongModeAndNonGetRequests(): void
    {
        $body = $this->submission();
        $this->assertSame(201, $this->send($body)->code());
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['success_url']);
        $returnUrl = str_replace('/success?', '/return?', $parameters['success_url']);
        $returnUrl = str_replace('{CHECKOUT_SESSION_ID}', 'cs_test_browser', $returnUrl);

        $wrongMode = $this->sendNavigation($returnUrl);
        $this->assertSame(303, $wrongMode->code());
        $this->assertSame('https://kirby-stripe-checkout.test/en', $wrongMode->headers()['Location'] ?? null);
        $this->assertCount(1, $this->requests);

        $method = $this->sendNavigation($returnUrl, 'POST');
        $this->assertSame(405, $method->code());
        $this->assertSame('GET', $method->headers()['Allow'] ?? null);
    }

    public function testHostedCancellationWithoutBrowserBindingNavigatesWithoutAnOrderResult(): void
    {
        $this->restart(options: ['settings' => ['cancelDestination' => '/cancelled']]);
        $body = $this->submission();
        $this->assertSame(201, $this->send($body)->code());
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['cancel_url']);
        $this->kirby->session()->data()->remove(BrowserAttemptStore::KEY);

        $cancelled = $this->sendNavigation($parameters['cancel_url']);
        $this->assertSame(303, $cancelled->code());
        $this->assertSame('https://kirby-stripe-checkout.test/en/cancelled', $cancelled->headers()['Location'] ?? null);
        $this->assertCount(1, $this->requests);
        $this->setRequest('https://kirby-stripe-checkout.test/en/cancelled');
        $this->assertNull((new StripeCheckout($this->kirby))->checkoutResult());
    }

    public function testHostedCancellationNavigatesWhenOptionalSessionAccessFails(): void
    {
        $this->restart(options: ['settings' => ['cancelDestination' => '/cancelled']]);
        $body = $this->submission();
        $this->assertSame(201, $this->send($body)->code());
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['cancel_url']);

        $cancelled = $this->withUnavailableSession(
            fn() => $this->sendNavigation($parameters['cancel_url']),
        );
        $this->assertSame(303, $cancelled->code());
        $this->assertSame('https://kirby-stripe-checkout.test/en/cancelled', $cancelled->headers()['Location'] ?? null);
    }

    public function testExternalCancellationDestinationReceivesNoSessionResultReference(): void
    {
        $this->restart(options: ['settings' => ['cancelDestination' => 'https://storefront.example/cancelled?from=checkout']]);
        $body = $this->submission();
        $this->assertSame(201, $this->send($body)->code());
        $parameters = $this->requests[0]['parameters'];
        $this->assertIsString($parameters['cancel_url']);

        $cancelled = $this->withUnavailableSession(
            fn() => $this->sendNavigation($parameters['cancel_url']),
        );
        $this->assertSame(303, $cancelled->code());
        $this->assertSame('https://storefront.example/cancelled?from=checkout', $cancelled->headers()['Location'] ?? null);
        $this->assertNull($this->kirby->session()->data()->get(CheckoutResultStore::KEY));
        $this->assertCount(1, $this->requests);
    }

    public function testCancellationRejectsOtherMethodsAndEmbeddedOrders(): void
    {
        $this->restart(UiMode::Embedded);
        $body = $this->submission();
        $this->assertSame(201, $this->send($body)->code());
        $this->assertIsString($body['attemptToken']);
        $pageUuid = OrderData::pageUuid((new AttemptToken($body['attemptToken']))->orderUuid());
        $url = 'https://kirby-stripe-checkout.test/en/stripe-checkout/cancel?_stripe_checkout_order=' . rawurlencode($pageUuid);

        $embedded = $this->sendNavigation($url);
        $this->assertSame(303, $embedded->code());
        $this->assertSame('https://kirby-stripe-checkout.test/en', $embedded->headers()['Location'] ?? null);
        $this->assertCount(1, $this->requests);

        $method = $this->sendNavigation($url, 'POST');
        $this->assertSame(405, $method->code());
        $this->assertSame('GET', $method->headers()['Allow'] ?? null);
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

    public function testUnavailableOrderStoreReturnsARetryableDependencyFailure(): void
    {
        $this->restart(beforeApp: static function (TestWorkspace $workspace): void {
            $workspace->writeDraftPage('stripe-checkout-orders', 'default', ['title' => 'Foreign container']);
        });
        $body = $this->submission();

        $this->assertError($this->send($body), 503, 'persistence.order_unavailable', true);
        $this->assertCount(0, $this->requests);
    }

    public function testCustomProductExceptionDoesNotExposeItsArbitraryCode(): void
    {
        $this->restart(options: ['products' => ['resolver' => static function (): never {
            throw new InvalidProductException('private callback code');
        }]]);
        $this->assertError($this->send($this->submission()), 422, 'product.invalid', false);
    }

    #[DataProvider('orderNumberFormatterFailures')]
    public function testOrderNumberFormatterFailuresAreSafeConfigurationErrors(bool $throws): void
    {
        $this->restart(options: ['orders' => ['numberFormatter' => static function (string $pageUuid) use ($throws): string {
            if ($throws) {
                throw new RuntimeException('private formatter details');
            }

            return '';
        }]]);
        $response = $this->send($this->submission());
        $this->assertError($response, 422, 'order.number_invalid', false);
        $error = $this->body($response)['error'];
        $this->assertIsArray($error);
        $this->assertSame('The order number configuration needs attention. Contact the store.', $error['message']);
        $this->assertCount(0, $this->requests);
        $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
    }

    /** @return iterable<string, array{bool}> */
    public static function orderNumberFormatterFailures(): iterable
    {
        yield 'empty label' => [false];
        yield 'callback throws' => [true];
    }

    /** @param array<string, mixed> $options */
    private function restart(UiMode $uiMode = UiMode::Hosted, array $options = [], ?Closure $beforeApp = null): void
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
            beforeApp: $beforeApp,
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
                $expiresAt = $parameters['expires_at'] ?? null;
                $this->assertIsInt($expiresAt);
                /** @var array<string, mixed> $parameters */
                $this->sessionRequest = $parameters;
                $this->session = [
                    'id' => $this->createdSessionId,
                    'object' => 'checkout.session',
                    'created' => $expiresAt - 86400,
                    'expires_at' => $expiresAt,
                    'status' => 'open',
                    'payment_status' => 'unpaid',
                    'livemode' => false,
                    'mode' => 'payment',
                    'ui_mode' => $parameters['ui_mode'],
                    'currency' => 'eur',
                    'client_reference_id' => $parameters['client_reference_id'],
                    'integration_identifier' => $parameters['integration_identifier'],
                    'metadata' => $parameters['metadata'],
                    'url' => $parameters['ui_mode'] === 'hosted_page' ? 'https://checkout.stripe.com/c/pay/' . $this->createdSessionId : null,
                    'client_secret' => $parameters['ui_mode'] === 'embedded_page' ? $this->createdSessionId . '_secret_private' : null,
                    'shipping_options' => isset($parameters['shipping_options']) ? [['shipping_rate' => 'shr_browser_standard']] : [],
                ];
            }

            if (str_ends_with($url, '/line_items')) {
                return [json_encode($this->reconciliationLineItems(), JSON_THROW_ON_ERROR), 200, []];
            }

            $session = $this->session ?? $this->fail('Retrieve requires an existing provider Session.');
            $session['status'] = $this->sessionStatus;
            $session['payment_status'] = $this->sessionPaymentStatus;

            return [json_encode($this->reconciliationSession($session), JSON_THROW_ON_ERROR), 200, []];
        });
        ApiRequestor::setHttpClient($client);
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function reconciliationSession(array $session): array
    {
        $parameters = $this->sessionRequest ?? $this->fail('Missing saved Session request.');
        $lineItems = $parameters['line_items'] ?? null;
        $this->assertIsArray($lineItems);
        $lineItem = $lineItems[0] ?? null;
        $this->assertIsArray($lineItem);
        $priceData = $lineItem['price_data'] ?? null;
        $this->assertIsArray($priceData);
        $unitAmount = $priceData['unit_amount'] ?? null;
        $quantity = $lineItem['quantity'] ?? null;
        $this->assertIsInt($unitAmount);
        $this->assertIsInt($quantity);
        $total = $unitAmount * $quantity;
        $paymentIntentData = $parameters['payment_intent_data'] ?? null;
        $this->assertIsArray($paymentIntentData);
        $paymentMetadata = $paymentIntentData['metadata'] ?? null;
        $this->assertIsArray($paymentMetadata);
        $session['amount_subtotal'] = $total;
        $session['amount_total'] = $total;
        $session['automatic_tax'] = ['enabled' => false];
        $session['custom_fields'] = [];
        $session['total_details'] = [
            'amount_discount' => 0,
            'amount_shipping' => 0,
            'amount_tax' => 0,
            'breakdown' => ['discounts' => []],
        ];
        $session['payment_intent'] = $this->sessionStatus === 'complete' || $this->paymentNextAction !== null ? [
            'id' => 'pi_browser',
            'object' => 'payment_intent',
            'created' => $session['created'],
            'livemode' => false,
            'metadata' => $paymentMetadata,
            'currency' => 'eur',
            'amount' => $total,
            'amount_received' => $this->sessionPaymentStatus === 'paid' ? $total : 0,
            'capture_method' => 'automatic',
            'status' => $this->paymentIntentStatus,
            'payment_method' => [
                'id' => 'pm_browser',
                'object' => 'payment_method',
                'type' => 'card',
            ],
            'latest_charge' => null,
            'next_action' => $this->paymentNextAction,
        ] : null;

        return $session;
    }

    /** @return array<string, mixed> */
    private function reconciliationLineItems(): array
    {
        $parameters = $this->sessionRequest ?? $this->fail('Missing saved Session request.');
        $lineItems = $parameters['line_items'] ?? null;
        $this->assertIsArray($lineItems);
        $lineItem = $lineItems[0] ?? null;
        $this->assertIsArray($lineItem);
        $metadata = $lineItem['metadata'] ?? null;
        $priceData = $lineItem['price_data'] ?? null;
        $quantity = $lineItem['quantity'] ?? null;
        $this->assertIsArray($metadata);
        $this->assertIsArray($priceData);
        $this->assertIsInt($quantity);
        $unitAmount = $priceData['unit_amount'] ?? null;
        $this->assertIsInt($unitAmount);
        $total = $unitAmount * $quantity;

        return [
            'object' => 'list',
            'data' => [[
                'id' => 'li_browser',
                'object' => 'item',
                'metadata' => $metadata,
                'quantity' => $quantity,
                'currency' => 'eur',
                'description' => 'Product',
                'amount_subtotal' => $total,
                'amount_discount' => 0,
                'amount_tax' => 0,
                'amount_total' => $total,
                'price' => [
                    'id' => 'price_browser',
                    'object' => 'price',
                    'currency' => 'eur',
                    'unit_amount' => $unitAmount,
                    'product' => 'prod_browser',
                    'billing_scheme' => 'per_unit',
                    'type' => 'one_time',
                ],
                'discounts' => [],
                'taxes' => [],
            ]],
            'has_more' => false,
            'url' => '/v1/checkout/sessions/cs_test_browser/line_items',
        ];
    }

    /** @return array<string, mixed> */
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

    /** @param array<string, mixed> $body */
    private function sendForm(array $body): Response
    {
        $body['csrf'] ??= (string) $this->kirby->csrf();

        return $this->send(http_build_query($body), headers: [
            'Accept' => 'text/html',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'X-CSRF' => null,
        ]);
    }

    private function checkoutAt(
        string $url,
        CheckoutSource $checkoutSource = CheckoutSource::Direct,
    ): \ProgrammatorDev\StripeCheckout\Checkout\CheckoutBootstrap {
        (new ReflectionProperty(App::class, 'request'))->setValue($this->kirby, new Request([
            'method' => 'GET',
            'url' => $url,
        ]));

        return (new StripeCheckout($this->kirby))->checkout($checkoutSource);
    }

    private function sendNavigation(string $url, string $method = 'GET'): Response
    {
        $this->setRequest($url, $method);
        $path = parse_url($url, PHP_URL_PATH);
        $this->assertIsString($path);
        $response = $this->kirby->call(ltrim($path, '/'), $method);
        $this->assertInstanceOf(Response::class, $response);

        return $response;
    }

    private function setRequest(string $url, string $method = 'GET'): void
    {
        (new ReflectionProperty(App::class, 'request'))->setValue($this->kirby, new Request([
            'method' => $method,
            'url' => $url,
        ]));
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    private function withUnavailableSession(Closure $operation): mixed
    {
        $sessionHandlerProperty = new ReflectionProperty(App::class, 'sessionHandler');
        $sessionHandler = $this->kirby->sessionHandler();
        $unavailableSessionHandler = $this->createMock(AutoSession::class);
        $unavailableSessionHandler->method('get')->willThrowException(new RuntimeException('Session unavailable.'));
        $sessionHandlerProperty->setValue($this->kirby, $unavailableSessionHandler);

        try {
            return $operation();
        } finally {
            $sessionHandlerProperty->setValue($this->kirby, $sessionHandler);
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

    private function assertNavigationPrivate(Response $response): void
    {
        $this->assertSame('no-store, private', $response->headers()['Cache-Control']);
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
        $page = $store->order(OrderData::pageUuid((new AttemptToken($body['attemptToken']))->orderUuid())) ?? $this->fail('Missing order.');
        return $store->data($page);
    }
}
