<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Closure;
use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Cms\Language;
use Kirby\Cms\ModelWithContent;
use Kirby\Content\PlainTextStorage;
use Kirby\Content\Storage;
use Kirby\Content\VersionId;
use Kirby\Filesystem\F;
use Kirby\Http\Environment;
use Kirby\Http\Request;
use Kirby\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEvent;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Plugin\PluginMetadata;
use ProgrammatorDev\StripeCheckout\Test\Support\CheckoutAttemptFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\OrderFixture;
use ProgrammatorDev\StripeCheckout\Test\Support\Stripe\WebhookCheckoutClient;
use ReflectionProperty;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\WebhookSignature;

final class WebhookRoutesTest extends KirbyTestCase
{
    private const SECRET = 'whsec_webhook_fixture';

    private OrderPageStore $orders;
    private OrderCreationContext $order;
    private DateTimeImmutable $createdAt;
    private WebhookCheckoutClient $provider;

    /** @var list<LifecycleEvent> */
    private array $delivered = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'stripe' => [
                    'secretKey' => 'sk_test_webhook_fixture',
                    'webhookSecret' => self::SECRET,
                ],
                'cart' => ['enabled' => false],
            ],
        ]);
        $this->kirby = $this->environment->app();
        $this->createdAt = new DateTimeImmutable('2026-10-01T12:00:00Z');
        $line = OrderFixture::lineItemData();
        $line['requiresShipping'] = false;
        $this->order = new OrderCreationContext(
            uuid: 'webhookorder0001',
            orderNumber: 'ORD-WEBHOOK001',
            checkoutSource: CheckoutSource::Cart,
            cartRevision: 'revision',
            userUuid: null,
            languageCode: null,
            uiMode: UiMode::Hosted,
            currency: 'EUR',
            lineItems: [OrderLineItemSnapshot::fromArray($line)],
        );
        $request = new SessionRequest([
            'mode' => 'payment',
            'currency' => 'eur',
            'client_reference_id' => $this->order->pageUuid(),
            'expires_at' => $this->createdAt->getTimestamp() + 86400,
            'integration_identifier' => 'kirby_stripe_checkout_abcdefgh',
            'metadata' => [
                PluginMetadata::OWNER_KEY => PluginMetadata::NAME,
                PluginMetadata::ORDER_KEY => $this->order->pageUuid(),
            ],
            'payment_intent_data' => ['metadata' => [
                PluginMetadata::OWNER_KEY => PluginMetadata::NAME,
                PluginMetadata::ORDER_KEY => $this->order->pageUuid(),
            ]],
            'line_items' => [[
                'quantity' => 2,
                'metadata' => [
                    PluginMetadata::OWNER_KEY => PluginMetadata::NAME,
                    PluginMetadata::ORDER_KEY => $this->order->pageUuid(),
                    PluginMetadata::LINE_KEY => 'line_0',
                ],
            ]],
        ]);
        $this->orders = new OrderPageStore($this->kirby);
        $this->orders->create($this->order, CheckoutAttemptFactory::create($this->order, $this->createdAt, request: $request), $this->createdAt);
        // Current storefront settings can become invalid after the initiating order was saved.
        $this->kirby->extend(['options' => ['programmatordev.stripe-checkout' => ['settings' => ['currency' => 'INVALID']]]]);
        $this->provider = new WebhookCheckoutClient($this->order->pageUuid(), $this->createdAt->getTimestamp());
        ApiRequestor::setHttpClient($this->provider);
        $hooks = [];
        $names = ['session.created', 'payment.pending', 'payment.succeeded', 'payment.failed', 'checkout.expired', 'payment.requiresAction'];

        $test = $this;
        $orders = $this->orders;
        $delivered = &$this->delivered;

        foreach ($names as $name) {
            $hooks['programmatordev.stripe-checkout.' . $name] = function (OrderPage $order, LifecycleEvent $lifecycleEvent) use ($test, $orders, &$delivered): void {
                $test->assertSame($lifecycleEvent->orderSnapshot()['paymentStatus'], $orders->data($order)['paymentStatus']);
                $delivered[] = $lifecycleEvent;
            };
        }

        $this->kirby->extend(['hooks' => $hooks]);
        $this->kirby->impersonate(null);
    }

    #[DataProvider('checkoutOutcomes')]
    public function testDispatchesTheFourCheckoutEventsThroughTheRealRuntime(string $type, string $checkoutStatus, string $paymentStatus, string $intentStatus, string $expected): void
    {
        $this->provider->session['status'] = $checkoutStatus;
        $this->provider->session['payment_status'] = $paymentStatus;
        $this->provider->paymentIntent['status'] = $intentStatus;
        $this->provider->paymentIntent['amount_received'] = $paymentStatus === 'paid' ? 3200 : 0;
        $this->assertResponse($this->send($this->event($type)), 204);
        $this->assertSame($expected, $this->data()['paymentStatus']);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertCount(2, $this->provider->requests);
        $this->assertNull($this->kirby->session()->token());
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function checkoutOutcomes(): iterable
    {
        yield 'completed' => ['checkout.session.completed', 'complete', 'paid', 'succeeded', 'paid'];
        yield 'async success' => ['checkout.session.async_payment_succeeded', 'complete', 'paid', 'succeeded', 'paid'];
        yield 'async failure' => ['checkout.session.async_payment_failed', 'complete', 'unpaid', 'requires_payment_method', 'failed'];
        yield 'expired' => ['checkout.session.expired', 'expired', 'unpaid', 'canceled', 'unpaid'];
    }

    public function testRedeliveryAfterALostSuccessfulResponseDoesNotRepeatEffects(): void
    {
        $body = $this->event();
        // Discard the acknowledgement as if the connection failed after the canonical commit.
        $this->send($body);
        $committed = $this->data();
        $delivered = $this->delivered;
        $this->assertSame('paid', $committed['paymentStatus']);
        $this->assertResponse($this->send($body, signature: WebhookSignature::generateSignatureHeader($body, self::SECRET, time() + 1)), 204);
        $this->assertSame($committed, $this->data());
        $this->assertSame($delivered, $this->delivered);
        $this->assertCount(2, $this->provider->requests);
        $this->assertCount(1, $this->orders->orders());
    }

    public function testObserverFailureRemainsAcknowledgedOnRedelivery(): void
    {
        $calls = 0;
        $this->kirby->extend(['hooks' => ['programmatordev.stripe-checkout.payment.succeeded' => function () use (&$calls): void {
            $calls++;
            throw new RuntimeException('PRIVATE_OBSERVER_CANARY');
        }]]);
        $body = $this->event();
        $this->assertResponse($this->send($body), 204);
        $this->assertResponse($this->send($body), 204);
        $this->assertSame(1, $calls);
        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertSame('failed', $this->entries('lifecycleDeliveries')[2]['status']);
    }

    public function testCapturesAnActionAfterItsAssociationWithoutWaitingForCompletion(): void
    {
        $this->provider->session['status'] = 'open';
        $this->provider->session['payment_status'] = 'unpaid';
        $this->provider->paymentIntent['status'] = 'requires_action';
        $this->provider->paymentIntent['amount_received'] = 0;
        $this->assertResponse($this->send($this->event()), 204);
        $actionBody = $this->event('payment_intent.requires_action');
        $this->assertResponse($this->send($actionBody), 204);
        $this->assertSame('unpaid', $this->data()['paymentStatus']);
        $this->assertSame('open', $this->data()['checkoutStatus']);
        $actions = array_values(array_filter($this->delivered, static fn(LifecycleEvent $event): bool => $event->type()->value === 'payment.requiresAction'));
        $this->assertCount(1, $actions);
        $this->assertSame('future_action', $actions[0]->payment()?->nextAction()?->type());
        $this->assertResponse($this->send($actionBody), 204);
        $this->assertCount(4, $this->provider->requests);
    }

    public function testEarlyActionRequestsRetryAndLaterCapturesAgainstTheSavedAssociation(): void
    {
        $body = $this->event('payment_intent.requires_action');
        $this->assertResponse($this->send($body), 503);
        $this->assertSame([], $this->entries('events'));
        $this->assertSame([], $this->provider->requests);
        $this->provider->session['status'] = 'open';
        $this->provider->session['payment_status'] = 'unpaid';
        $this->provider->paymentIntent['status'] = 'requires_action';
        $this->provider->paymentIntent['amount_received'] = 0;
        $this->assertResponse($this->send($this->event()), 204);
        $this->assertResponse($this->send($body), 204);
        $this->assertCount(2, $this->entries('events'));
    }

    public function testContradictoryEarlyActionIsNotClassifiedAsMissingAssociation(): void
    {
        /** @var array{data: array{object: array<string, mixed>}} $data */
        $data = json_decode($this->event('payment_intent.requires_action'), true, flags: JSON_THROW_ON_ERROR);
        $data['data']['object']['currency'] = 'usd';
        $this->assertResponse($this->send(json_encode($data, JSON_THROW_ON_ERROR)), 500);
        $this->assertSame([], $this->entries('events'));
        $this->assertSame([], $this->provider->requests);
    }

    #[DataProvider('invalidDeliveries')]
    public function testRejectsInvalidDeliveriesWithoutProcessingOrRetainingThem(string $body, string $header): void
    {
        $body = $body === 'valid' ? $this->event() : $body;
        $signature = match ($header) {
            'correct' => WebhookSignature::generateSignatureHeader($body, self::SECRET),
            'stale' => WebhookSignature::generateSignatureHeader($body, self::SECRET, time() - 900),
            'future' => WebhookSignature::generateSignatureHeader($body, self::SECRET, time() + 900),
            default => $header,
        };
        $this->assertResponse($this->send($body, signature: $signature), 400);
        $this->assertSame([], $this->provider->requests);
        $this->assertSame([], $this->entries('events'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidDeliveries(): iterable
    {
        yield 'missing signature' => ['valid', ''];
        yield 'malformed timestamp fragment' => ['valid', 't,v1=bad'];
        yield 'malformed signature fragment' => ['valid', 't=1,v1'];
        yield 'wrong signature' => ['valid', 't=1,v1=bad'];
        yield 'stale timestamp' => ['valid', 'stale'];
        yield 'future timestamp' => ['valid', 'future'];
        yield 'invalid JSON' => ['{"PRIVATE_BODY_CANARY"', 'correct'];
        yield 'signed scalar JSON' => ['null', 'correct'];
        yield 'signed list JSON' => ['[]', 'correct'];
        yield 'missing Event fields' => ['{}', 'correct'];
    }

    public function testUsesOriginalBytesAndAcceptsRotationOverlap(): void
    {
        $body = json_encode(json_decode($this->event(), true, flags: JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $valid = WebhookSignature::generateSignatureHeader($body, self::SECRET);
        $old = WebhookSignature::generateSignatureHeader($body, 'whsec_old');
        $this->assertResponse($this->send($body, signature: $old . ',' . explode(',', $valid)[1]), 204);
        $this->assertResponse($this->send(str_replace('    ', '  ', $body), signature: $valid), 400);
    }

    #[DataProvider('ignoredEvents')]
    public function testAcknowledgesIgnoredEventsWithoutProviderReadsOrOrderWrites(string $type, string $owner): void
    {
        $body = $this->event($type, owner: $owner);
        $this->assertResponse($this->send($body), 204);
        $this->assertSame([], $this->provider->requests);
        $this->assertSame([], $this->entries('events'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function ignoredEvents(): iterable
    {
        yield 'future unsupported event' => ['future.event', PluginMetadata::NAME];
        yield 'refund remains deferred' => ['refund.created', PluginMetadata::NAME];
        yield 'foreign Checkout' => ['checkout.session.completed', 'other/plugin'];
        yield 'unowned Checkout' => ['checkout.session.completed', ''];
    }

    public function testOwnedUncorrelatableEventFailsWithoutProviderReadsOrOrderChanges(): void
    {
        $before = $this->data();
        $body = $this->event(pageUuid: 'page://missing');
        $this->assertResponse($this->send($body), 500);
        $this->assertResponse($this->send($body), 500);
        $this->assertSame($before, $this->data());
        $this->assertSame([], $this->provider->requests);
        $this->assertSame([], $this->entries('events'));
    }

    public function testProviderFailureIsRetryableAndKeepsEvidenceOnTheOrder(): void
    {
        $this->provider->httpStatus = 503;
        $this->assertResponse($this->send($this->event()), 503);
        $this->assertSame('failed', $this->entries('events')[0]['status']);
        $this->assertSame('creating', $this->data()['checkoutStatus']);
        $this->provider->httpStatus = 200;
        $this->assertResponse($this->send($this->event()), 204);
        $this->assertSame('paid', $this->data()['paymentStatus']);
    }

    public function testIncompatibleProviderGraphReturns500WithoutPartialCanonicalChanges(): void
    {
        $this->provider->session['amount_total'] = 3199;
        $before = $this->data();
        $this->assertResponse($this->send($this->event()), 500);
        $after = $this->data();
        $this->assertSame($before['paymentStatus'], $after['paymentStatus']);
        $this->assertSame($before['initiatingLineItems'], $after['initiatingLineItems']);
        $this->assertSame('failed', $this->entries('events')[0]['status']);
    }

    public function testNativeWriteFailureReturns503AndCanBeRedelivered(): void
    {
        /** @var Closure(App, ModelWithContent): Storage $nativeStorage */
        $nativeStorage = $this->kirby->component('storage');
        $this->kirby->extend(['components' => ['storage' => function (App $kirby, ModelWithContent $model) use ($nativeStorage): Storage {
            if ($model instanceof OrderPage === false) {
                return $nativeStorage($kirby, $model);
            }

            return new class ($model) extends PlainTextStorage {
                protected function write(VersionId $versionId, Language $language, array $fields): void
                {
                    throw new RuntimeException('PRIVATE_WRITE_CANARY');
                }
            };
        }]]);

        try {
            $this->assertResponse($this->send($this->event()), 503);
        } finally {
            $this->kirby->extend(['components' => ['storage' => $nativeStorage]]);
        }

        $this->assertResponse($this->send($this->event()), 204);
        $this->assertSame('paid', $this->data()['paymentStatus']);
    }

    public function testRouteIsCanonicalOnMultilingualSitesAndOtherMethodsDoNotProcess(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout.stripe.webhookSecret' => self::SECRET,
            'programmatordev.stripe-checkout.cart.enabled' => false,
        ], languages: [
            [
                'code' => 'en',
                'default' => true,
                'locale' => 'en_US',
                'name' => 'English',
                'url' => '/',
            ],
            [
                'code' => 'pt',
                'locale' => 'pt_PT',
                'name' => 'Português',
                'url' => '/pt',
            ],
        ]);
        $this->kirby = $this->environment->app();
        $this->assertResponse($this->send($this->event('future.event')), 204);
        $this->assertNotInstanceOf(Response::class, $this->kirby->call('pt/stripe-checkout/webhook', 'POST'));
        $methods = ['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

        foreach ($methods as $method) {
            $this->assertNotInstanceOf(Response::class, $this->kirby->call('stripe-checkout/webhook', $method));
        }

        $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
        $this->assertNull($this->kirby->session()->token());
    }

    public function testMissingSigningSecretReturns503BeforeAnyProcessing(): void
    {
        $this->kirby = $this->kirby->clone(['options' => ['programmatordev.stripe-checkout' => ['stripe' => ['webhookSecret' => null]]]]);
        $this->assertResponse($this->send($this->event()), 503);
        $this->assertSame([], $this->provider->requests);
        $this->assertSame([], $this->entries('events'));
    }

    public function testMissingApiCredentialsOnlyFailAnOwnedSupportedEvent(): void
    {
        $this->kirby = $this->kirby->clone(['options' => ['programmatordev.stripe-checkout' => ['stripe' => ['secretKey' => null]]]]);
        $this->assertResponse($this->send($this->event('future.event')), 204);
        $this->assertResponse($this->send($this->event(owner: 'other/plugin')), 204);
        $this->assertResponse($this->send($this->event()), 503);
        $this->assertSame([], $this->provider->requests);
        $this->assertSame([], $this->entries('events'));
    }

    public function testInvalidLocalSigningConfigurationIsAServerFailure(): void
    {
        $this->kirby = $this->kirby->clone(['options' => ['programmatordev.stripe-checkout' => ['stripe' => ['webhookSecret' => []]]]]);
        $this->assertResponse($this->send($this->event()), 500);
        $this->assertSame([], $this->provider->requests);
    }

    #[DataProvider('malformedEnvelopes')]
    public function testRejectsSignedMalformedSelectedEnvelopes(string $body): void
    {
        $this->assertResponse($this->send($body), 400);
        $this->assertSame([], $this->provider->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedEnvelopes(): iterable
    {
        yield 'not an Event' => ['{"id":"evt_bad","object":"payment_intent","type":"future.event"}'];
        yield 'unsafe id' => ['{"id":"evt_bad\\nprivate","object":"event","type":"future.event"}'];
        yield 'missing type' => ['{"id":"evt_bad","object":"event"}'];
        yield 'missing object' => ['{"id":"evt_bad","object":"event","type":"checkout.session.completed","data":{}}'];
        yield 'invalid metadata' => ['{"id":"evt_bad","object":"event","type":"checkout.session.completed","data":{"object":{"metadata":"PRIVATE"}}}'];
    }

    #[DataProvider('contradictoryEvents')]
    public function testOwnedContradictionsAreNotAcknowledgedOrCommitted(string $field): void
    {
        /** @var array{livemode: bool, data: array{object: array<string, mixed>}} $data */
        $data = json_decode($this->event(), true, flags: JSON_THROW_ON_ERROR);

        if ($field === 'livemode') {
            $data['livemode'] = true;
            $data['data']['object']['livemode'] = true;
        } else {
            $data['data']['object'][$field] = 'PRIVATE_CONTRADICTION';
        }

        $before = $this->data();
        $this->assertResponse($this->send(json_encode($data, JSON_THROW_ON_ERROR)), 500);
        $this->assertSame($before, $this->data());
        $this->assertSame([], $this->provider->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function contradictoryEvents(): iterable
    {
        yield 'purchase reference' => ['client_reference_id'];
        yield 'credential mode' => ['livemode'];
        yield 'resource type' => ['object'];
    }

    public function testReusedProcessedEventIdDoesNotHideContradictoryEventIdentity(): void
    {
        $this->assertResponse($this->send($this->event()), 204);
        $before = $this->data();
        /** @var array{created: int} $data */
        $data = json_decode($this->event(), true, flags: JSON_THROW_ON_ERROR);
        $data['created']++;
        $this->assertResponse($this->send(json_encode($data, JSON_THROW_ON_ERROR)), 500);
        $this->assertSame($before, $this->data());
        $this->assertCount(2, $this->provider->requests);
    }

    public function testOutOfOrderEventsUseCurrentProviderFactsWithoutRepeatingTheTransition(): void
    {
        $this->assertResponse($this->send($this->event('checkout.session.async_payment_succeeded')), 204);
        /** @var array{id: string, created: int} $body */
        $body = json_decode($this->event(), true, flags: JSON_THROW_ON_ERROR);
        $body['id'] = 'evt_older';
        $body['created']--;
        $delivered = $this->delivered;
        $this->assertResponse($this->send(json_encode($body, JSON_THROW_ON_ERROR)), 204);
        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertSame($delivered, $this->delivered);
        $this->assertCount(2, $this->entries('events'));
    }

    public function testContradictoryPaymentIntentBacklinkFailsBeforeAttachingTheEvent(): void
    {
        $this->assertResponse($this->send($this->event()), 204);
        $before = $this->data();
        /** @var array{data: array{object: array<string, mixed>}} $data */
        $data = json_decode($this->event('payment_intent.requires_action'), true, flags: JSON_THROW_ON_ERROR);
        $data['data']['object']['id'] = 'pi_other';
        $this->assertResponse($this->send(json_encode($data, JSON_THROW_ON_ERROR)), 500);
        $this->assertSame($before, $this->data());
        $this->assertCount(2, $this->provider->requests);
    }

    public function testConflictExhaustionReturns503AndPreservesTheCompetingWrites(): void
    {
        $this->provider->beforeSessionRead = function (): void {
            $number = count($this->provider->requests);
            $this->orders->update($this->order->pageUuid(), static fn(array $data): array => [
                ...$data,
                'stripeInvoiceId' => 'in_competing_' . $number,
            ]);
        };
        $this->assertResponse($this->send($this->event()), 503);
        $this->assertSame('creating', $this->data()['checkoutStatus']);
        $this->assertSame('in_competing_5', $this->data()['stripeInvoiceId']);
        $this->assertSame('checkout.reconciliation_conflict', $this->entries('events')[0]['errorCode']);
        $this->provider->beforeSessionRead = null;
        $this->assertResponse($this->send($this->event()), 204);
    }

    public function testLateProviderFailureAcknowledgesTheSameEventCommittedByAnotherProcessor(): void
    {
        $body = $this->event();
        // Complete a competing delivery while the original request is waiting for provider data, then fail that original read.
        $this->provider->beforeSessionRead = function () use ($body): void {
            $this->provider->beforeSessionRead = null;
            $this->assertResponse($this->send($body), 204);
            $this->provider->httpStatus = 400;
        };
        $this->assertResponse($this->send($body), 204);
        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertSame(['session.created', 'payment.succeeded'], array_map(static fn(LifecycleEvent $event): string => $event->type()->value, $this->delivered));
    }

    public function testRejectedAndFailedDeliveriesDoNotExposePrivateEvidence(): void
    {
        $errorLog = ini_get('error_log');
        $path = $this->environment->workspace()->root() . '/webhook-errors.log';
        ini_set('error_log', $path);

        try {
            $this->assertResponse($this->send($this->event(), 't=1,v1=PRIVATE_SIGNATURE_CANARY'), 400);
            $this->assertResponse($this->send($this->event(pageUuid: 'page://missing')), 500);
            $this->provider->httpStatus = 400;
            $this->assertResponse($this->send($this->event()), 500);
        } finally {
            ini_set('error_log', $errorLog === false ? '' : $errorLog);
        }

        $logs = F::read($path);
        $this->assertIsString($logs);
        $this->assertStringContainsString('webhook.signature_invalid', $logs);
        $this->assertStringContainsString('webhook.correlation_invalid', $logs);
        $this->assertStringNotContainsString('PRIVATE', $logs);
        $this->assertStringNotContainsString(self::SECRET, $logs);
        $this->assertStringNotContainsString('sk_test_webhook_fixture', $logs);
        $this->assertStringNotContainsString('PRIVATE', json_encode($this->data(), JSON_THROW_ON_ERROR));
    }

    private function assertResponse(Response $response, int $code): void
    {
        $this->assertSame($code, $response->code());
        $this->assertSame('', $response->body());
        $this->assertSame('no-store', $response->headers()['Cache-Control']);
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return $this->orders->data($this->orders->order($this->order->pageUuid()) ?? $this->fail('Missing order.'));
    }

    /** @return list<array<string, mixed>> */
    private function entries(string $field): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = $this->data()[$field] ?? [];

        return $entries;
    }

    private function event(string $type = 'checkout.session.completed', string $owner = PluginMetadata::NAME, ?string $pageUuid = null): string
    {
        $metadata = [
            PluginMetadata::OWNER_KEY => $owner,
            PluginMetadata::ORDER_KEY => $pageUuid ?? $this->order->pageUuid(),
        ];
        $object = $type === 'payment_intent.requires_action' ? [
            'id' => 'pi_webhook',
            'object' => 'payment_intent',
            'currency' => 'eur',
            'status' => 'requires_action',
            'next_action' => [
                'type' => 'future_action',
                'future_action' => ['instruction' => 'PRIVATE_ACTION_CANARY'],
            ],
        ] : [
            'id' => 'cs_webhook',
            'object' => 'checkout.session',
            'client_reference_id' => $pageUuid ?? $this->order->pageUuid(),
        ];

        return json_encode([
            'id' => $type === 'payment_intent.requires_action' ? 'evt_action' : 'evt_checkout',
            'object' => 'event',
            'type' => $type,
            'created' => $this->createdAt->getTimestamp() + 60,
            'livemode' => false,
            'data' => ['object' => [
                ...$object,
                'livemode' => false,
                'metadata' => $metadata,
                'private_unused' => 'PRIVATE_BODY_CANARY',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function send(string $body, ?string $signature = null): Response
    {
        $server = $_SERVER;
        // Kirby reads headers from the global Environment snapshot, independently of the Request's body options.
        // Replace both request sources so repeated deliveries in one application receive their own signed bytes and headers.
        $environmentInfo = new ReflectionProperty(Environment::class, 'info');
        $originalInfo = $environmentInfo->getValue($this->kirby->environment());

        try {
            $_SERVER['CONTENT_TYPE'] = 'application/json';
            $_SERVER['HTTP_STRIPE_SIGNATURE'] = $signature ?? WebhookSignature::generateSignatureHeader($body, self::SECRET);
            $environmentInfo->setValue($this->kirby->environment(), $_SERVER);
            (new ReflectionProperty(App::class, 'request'))->setValue($this->kirby, new Request([
                'method' => 'POST',
                'body' => $body,
            ]));
            $response = $this->kirby->call('stripe-checkout/webhook', 'POST');
            $this->assertInstanceOf(Response::class, $response);

            return $response;
        } finally {
            $_SERVER = $server;
            $environmentInfo->setValue($this->kirby->environment(), $originalInfo);
        }
    }
}
