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
use Kirby\Form\Form;
use Kirby\Http\Environment;
use Kirby\Http\Request;
use Kirby\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Kirby\OrderHookDispatcher;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEvent;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
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
        $names = ['session.created', 'payment.pending', 'payment.succeeded', 'payment.failed', 'checkout.expired', 'payment.requiresAction', 'refund.updated', 'dispute.updated'];

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

    public function testCheckoutAndActionReconciliationPreserveOpaquePaymentIdentities(): void
    {
        $sessionId = 'session.reference-' . str_repeat('s', 3000);
        $paymentIntentId = 'payment.reference-' . str_repeat('p', 3000);
        $paymentMethodId = 'method.reference-' . str_repeat('m', 3000);
        $chargeId = 'charge.reference-' . str_repeat('c', 3000);
        $failureCode = 'future_failure_' . str_repeat('f', 3000);
        $invoiceId = 'invoice.reference-' . str_repeat('i', 3000);
        $customerId = 'customer.reference-' . str_repeat('c', 3000);
        $methodType = 'future_method_' . str_repeat('m', 3000);
        $this->provider->sessionLookupId = $sessionId;
        $this->provider->session['id'] = $sessionId;
        $this->provider->session['invoice'] = $invoiceId;
        $this->provider->session['customer'] = $customerId;
        $this->provider->session['payment_status'] = 'unpaid';
        $this->provider->paymentIntent['id'] = $paymentIntentId;
        $this->provider->paymentIntent['status'] = 'processing';
        $this->provider->paymentIntent['amount_received'] = 0;
        $this->provider->paymentIntent['payment_method'] = [
            'id' => $paymentMethodId,
            'object' => 'payment_method',
            'type' => $methodType,
        ];
        $this->provider->paymentIntent['latest_charge'] = [
            'id' => $chargeId,
            'object' => 'charge',
            'created' => $this->createdAt->getTimestamp(),
            'livemode' => false,
            'payment_intent' => $paymentIntentId,
            'currency' => 'eur',
            'amount' => 3200,
            'amount_captured' => 0,
            'status' => 'pending',
            'paid' => false,
            'captured' => false,
            'payment_method' => $paymentMethodId,
            'failure_code' => $failureCode,
        ];
        $event = OrderData::map(json_decode($this->event(), true, flags: JSON_THROW_ON_ERROR));
        $eventData = OrderData::map($event['data']);
        $eventObject = OrderData::map($eventData['object']);
        $eventObject['id'] = $sessionId;
        $eventData['object'] = $eventObject;
        $event['data'] = $eventData;
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        $this->assertResponse($this->send($body), 204);
        $data = $this->data();
        $this->assertSame($sessionId, $data['stripeCheckoutSessionId']);
        $this->assertSame($paymentIntentId, $data['stripePaymentIntentId']);
        $this->assertSame($chargeId, $data['stripeChargeId']);
        $this->assertSame($invoiceId, $data['stripeInvoiceId']);
        $this->assertSame($customerId, $data['stripeCustomerId']);
        $payment = OrderData::map($data['payment']);
        $this->assertSame($paymentMethodId, $payment['stripePaymentMethodId']);
        $this->assertSame($methodType, $payment['methodType']);
        $this->assertSame($failureCode, $payment['failureCode']);
        $this->assertSame($paymentIntentId, $this->delivered[1]->payment()?->stripePaymentIntentId());
        $this->assertResponse($this->send($body), 204);
        $this->assertSame($data, $this->data());

        $actionEvent = OrderData::map(json_decode($this->event('payment_intent.requires_action'), true, flags: JSON_THROW_ON_ERROR));
        $actionEventData = OrderData::map($actionEvent['data']);
        $actionEventObject = OrderData::map($actionEventData['object']);
        $actionEventObject['id'] = $paymentIntentId;
        $actionEventData['object'] = $actionEventObject;
        $actionEvent['data'] = $actionEventData;
        $this->assertResponse($this->send(json_encode($actionEvent, JSON_THROW_ON_ERROR)), 204);
        $this->assertSame($paymentIntentId, $this->delivered[2]->payment()?->stripePaymentIntentId());
        $this->assertSame('payment.requiresAction', $this->delivered[2]->type()->value);
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
        $event = OrderData::map(json_decode($this->event(), true, flags: JSON_THROW_ON_ERROR));
        $event['id'] = 'checkout.event-reference';
        $body = json_encode($event, JSON_THROW_ON_ERROR);
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
        yield 'general Charge remains unsupported' => ['charge.updated', PluginMetadata::NAME];
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
        yield 'empty id' => ['{"id":"","object":"event","type":"future.event"}'];
        yield 'missing id' => ['{"object":"event","type":"future.event"}'];
        yield 'non-string id' => ['{"id":1,"object":"event","type":"future.event"}'];
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

    #[DataProvider('refundOutcomes')]
    public function testRefundEventsRefreshPaymentAndPersistCurrentRefundFacts(string $type, string $status, string $summary): void
    {
        $this->setRefunds([$this->refund(status: $status)]);
        $this->assertResponse($this->send($this->refundEvent($type)), 204);
        $data = $this->data();
        $this->assertSame('paid', $data['paymentStatus']);
        $this->assertSame($summary, $data['refundStatus']);
        $this->assertSame($status === 'succeeded' ? '16.00' : '0', $data['refundedTotal']);
        $this->assertSame('pi_webhook', $this->entries('refunds')[0]['stripePaymentIntentId']);
        $this->assertSame('re_webhook', $this->entries('events')[0]['resourceId']);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertSame(['session.created', 'payment.succeeded', 'refund.updated'], array_map(static fn(LifecycleEvent $event): string => $event->type()->value, $this->delivered));
        $this->assertSame($data['refunds'], $this->delivered[2]->orderSnapshot()['refunds']);
        $this->assertSame($type, $this->delivered[2]->triggerType());
        $this->assertStringNotContainsString('PRIVATE_REFUND', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<array{string, string, string}> */
    public static function refundOutcomes(): iterable
    {
        yield ['refund.created', 'pending', 'pending'];
        yield ['refund.updated', 'succeeded', 'partial'];
        yield ['refund.failed', 'failed', 'failed'];
    }

    public function testNativeRefundStructureDisplaysSafeFactsAndCannotRewriteTheSnapshot(): void
    {
        $refund = $this->refund();
        $refund['reason'] = 'requested_by_customer';
        $this->setRefunds([$refund]);
        $this->assertResponse($this->send($this->refundEvent()), 204);
        $this->kirby->impersonate('kirby');
        $page = $this->orders->requirePage($this->order->pageUuid());
        $before = $this->data();
        $form = Form::for($page);
        /** @var array<string, mixed> $input */
        $input = $form->toFormValues();
        $rows = OrderData::list($input['refunds']);
        $row = OrderData::map($rows[0]);
        $this->assertSame('16.00', $row['amount']);
        $this->assertSame('EUR', $row['currency']);
        $this->assertSame('succeeded', $row['status']);
        $this->assertSame('re_webhook', $row['striperefundid']);
        $this->assertSame('requested_by_customer', $row['reason']);
        $this->assertSame((string) OrderData::integer($refund['created']), $row['createdat']);
        $storedRefund = OrderData::map(OrderData::list($before['refunds'])[0]);
        $this->assertSame((new DateTimeImmutable(OrderData::string($storedRefund['firstObservedAt'])))->format('Y-m-d H:i:s'), $row['firstobservedat']);
        $this->assertStringNotContainsString('PRIVATE_REFUND', json_encode($input['refunds'], JSON_THROW_ON_ERROR));

        $page->update([...$input, 'note' => 'Refund inspected']);
        $this->assertSame($before, $this->data());
        $page = $this->orders->requirePage($this->order->pageUuid());
        $this->assertSame('Refund inspected', $page->version('latest')->read('default')['note'] ?? null);

        $row['amount'] = '1.00';
        $input['refunds'] = [$row];
        $this->expectException(\Kirby\Exception\PermissionException::class);
        $page->update($input);
    }

    public function testRefundReconciliationPreservesOpaqueProviderIdsWithoutLocalTextLengthLimits(): void
    {
        $refundId = 'refund.reference-' . str_repeat('r', 3000);
        $eventId = 'event.reference-' . str_repeat('e', 3000);
        $reason = 'future_reason_' . str_repeat('x', 3000);
        $refund = $this->refund(id: $refundId);
        $refund['reason'] = $reason;
        $this->setRefunds([$refund]);
        $body = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'refund.created',
            'created' => $this->createdAt->getTimestamp() + 3600,
            'livemode' => false,
            'data' => ['object' => $refund],
        ], JSON_THROW_ON_ERROR);

        $this->assertResponse($this->send($body), 204);
        $this->assertSame($refundId, $this->entries('refunds')[0]['stripeRefundId']);
        $this->assertSame($reason, $this->entries('refunds')[0]['reason']);
        $this->assertSame($eventId, $this->entries('events')[0]['id']);
        $this->assertSame($refundId, $this->entries('events')[0]['resourceId']);
        $this->assertSame('partial', $this->data()['refundStatus']);
        $this->assertSame($eventId, $this->delivered[2]->triggerId());

        $before = $this->data();
        $this->assertResponse($this->send($body), 204);
        $this->assertSame($before, $this->data());
        $this->assertCount(3, $this->delivered);

        $expiresAt = OrderData::string($this->entries('lifecycleDeliveries')[0]['expiresAt']);
        $this->kirby->impersonate('kirby');
        $this->orders->pruneLifecycleDeliveryPayloads(
            uuid: $this->order->pageUuid(),
            now: (new DateTimeImmutable($expiresAt))->modify('+1 day'),
        );
        $pruned = OrderData::map($this->entries('lifecycleDeliveries')[2]['event']);
        $this->assertSame($eventId, $pruned['triggerId']);
        $this->assertArrayNotHasKey('orderSnapshot', $pruned);
    }

    public function testUnmodeledRefundFieldsDoNotParticipateInPluginValidation(): void
    {
        $refund = $this->refund();
        $refund['future_provider_extension'] = ['ratio' => 0.5];
        $this->setRefunds([$refund]);
        $this->assertResponse($this->send($this->refundEvent()), 204);
        $this->assertSame('partial', $this->data()['refundStatus']);
        $this->assertArrayNotHasKey('future_provider_extension', $this->entries('refunds')[0]);
    }

    public function testRefundSessionLookupIgnoresUnmodeledProviderFields(): void
    {
        $this->setRefunds([$this->refund()]);
        // A fractional extension is valid provider JSON but would fail canonical snapshot normalization.
        $this->provider->sessionMatches = [[
            ...$this->provider->session,
            'payment_intent' => 'pi_webhook',
            'future_provider_extension' => ['ratio' => 0.5],
        ]];
        $this->assertArrayNotHasKey('stripeCheckoutSessionId', $this->data());

        $this->assertResponse($this->send($this->refundEvent()), 204);
        $data = $this->data();
        $this->assertSame('cs_webhook', $data['stripeCheckoutSessionId']);
        $this->assertSame('paid', $data['paymentStatus']);
        $this->assertSame('partial', $data['refundStatus']);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertStringNotContainsString('future_provider_extension', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testRefundDuplicateAndUnchangedDifferentEventDoNotRepeatTheHook(): void
    {
        $this->setRefunds([$this->refund()]);
        $body = $this->refundEvent();
        $this->assertResponse($this->send($body), 204);
        $before = $this->data();
        $reads = count($this->provider->requests);
        $this->assertResponse($this->send($body), 204);
        $this->assertSame($before, $this->data());
        // Parent ownership reads locate the order; processed duplication skips full Session and refund collections.
        $this->assertSame(['https://api.stripe.com/v1/refunds/re_webhook', 'https://api.stripe.com/v1/payment_intents/pi_webhook'], array_slice($this->provider->requests, $reads));
        $this->assertResponse($this->send($this->refundEvent(id: 'evt_refund_again')), 204);
        $this->assertSame($before['refunds'], $this->data()['refunds']);
        $this->assertSame($before['refundUpdatedAt'], $this->data()['refundUpdatedAt']);
        $this->assertCount(3, $this->delivered);
        $this->assertCount(2, $this->entries('events'));
    }

    public function testRefundStatusComesFromTheCurrentCollectionNotTheHistoricalEvent(): void
    {
        $this->setRefunds([$this->refund(status: 'pending')]);
        $body = $this->refundEvent();
        $this->setRefunds([$this->refund(status: 'succeeded', amount: 3200)]);
        // Keep the immutable amount in the historical envelope consistent; its pending status is stale.
        /** @var array{data: array{object: array<string, mixed>}} $event */
        $event = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $event['data']['object']['amount'] = 3200;
        $this->assertResponse($this->send(json_encode($event, JSON_THROW_ON_ERROR)), 204);
        $this->assertSame('full', $this->data()['refundStatus']);
        $this->assertSame('32.00', $this->data()['refundedTotal']);
    }

    public function testForeignParentIsIgnoredEvenWhenRefundMetadataClaimsOwnership(): void
    {
        $this->setRefunds([$this->refund()]);
        /** @var array{created: int, data: array{object: array<string, mixed>}} $body */
        $body = json_decode($this->refundEvent(), true, flags: JSON_THROW_ON_ERROR);
        $body['data']['object']['metadata'] = [
            PluginMetadata::OWNER_KEY => PluginMetadata::NAME,
            PluginMetadata::ORDER_KEY => $this->order->pageUuid(),
        ];
        $this->provider->paymentIntent['metadata'] = [];
        $before = $this->data();
        $this->assertResponse($this->send(json_encode($body, JSON_THROW_ON_ERROR)), 204);
        $this->assertSame($before, $this->data());
        $this->assertCount(0, $this->delivered);
    }

    public function testChargeFallbackAndOlderSamePaymentChargeAreCorrelated(): void
    {
        $refund = $this->refund();
        $refund['payment_intent'] = null;
        $refund['charge'] = 'charge.older-reference';
        $this->provider->charges['charge.older-reference'] = [
            'id' => 'charge.older-reference',
            'object' => 'charge',
            'payment_intent' => 'pi_webhook',
            'currency' => 'eur',
            'livemode' => false,
        ];
        $this->setRefunds([$refund]);
        $this->assertResponse($this->send($this->refundEvent()), 204);
        $this->assertSame('charge.older-reference', $this->entries('refunds')[0]['stripeChargeId']);
        $this->assertSame('charge.older-reference', $this->entries('events')[0]['stripeChargeId']);
        $this->assertSame('pi_webhook', $this->entries('refunds')[0]['stripePaymentIntentId']);
        $this->assertArrayNotHasKey('stripeChargeId', $this->data());
    }

    #[DataProvider('invalidRefundParents')]
    public function testRefundParentContradictionsCannotMutateAnOrder(string $field, mixed $value): void
    {
        $this->setRefunds([$this->refund()]);
        $this->provider->paymentIntent[$field] = $value;
        $before = $this->data();
        $this->assertResponse($this->send($this->refundEvent()), 500);
        $this->assertSame($before, $this->data());
    }

    /** @return iterable<array{string, mixed}> */
    public static function invalidRefundParents(): iterable
    {
        yield ['id', 'pi_other'];
        yield ['livemode', true];
        yield ['currency', 'usd'];
    }

    /** @param list<array<string, mixed>> $matches */
    #[DataProvider('missingRefundSessions')]
    public function testMissingRefundSessionLookupFailsSafely(array $matches, bool $hasMore, int $status): void
    {
        $this->setRefunds([$this->refund()]);
        $this->provider->sessionMatches = $matches;
        $this->provider->sessionLookupHasMore = $hasMore;
        $before = $this->data();
        $this->assertResponse($this->send($this->refundEvent()), $status);
        $after = $this->data();
        unset($after['events']);
        $this->assertSame($before, $after);
        $this->assertSame('failed', $this->entries('events')[0]['status']);
    }

    /** @return iterable<array{list<array<string, mixed>>, bool, int}> */
    public static function missingRefundSessions(): iterable
    {
        yield [[], false, 503];
        yield [[], true, 500];
        yield [[['id' => 'cs_one'], ['id' => 'cs_two']], false, 500];
        yield [[[
            'id' => 'cs_other',
            'object' => 'checkout.session',
            'payment_intent' => 'pi_other',
        ]], false, 500];
    }

    public function testCompleteRefundPaginationIsSortedAndMultipleRefundsReachFull(): void
    {
        $first = $this->refund(id: 're_webhook');
        $second = $this->refund(id: 're_second');
        $this->setRefunds([$first, $second]);
        $this->provider->refundPages = [
            '' => [
                'object' => 'list',
                'has_more' => true,
                'data' => [$first],
            ],
            're_webhook' => [
                'object' => 'list',
                'has_more' => false,
                'data' => [$second],
            ],
        ];
        $this->assertResponse($this->send($this->refundEvent()), 204);
        $this->assertSame('full', $this->data()['refundStatus']);
        $this->assertSame(['re_second', 're_webhook'], array_column($this->entries('refunds'), 'stripeRefundId'));
    }

    public function testRepeatedRefundCursorCannotCommitAPartialCollection(): void
    {
        $refund = $this->refund();
        $this->setRefunds([$refund]);
        $page = [
            'object' => 'list',
            'has_more' => true,
            'data' => [$refund],
        ];
        $this->provider->refundPages = [
            '' => $page,
            're_webhook' => $page,
        ];
        $this->assertResponse($this->send($this->refundEvent()), 500);
        $this->assertArrayNotHasKey('refunds', $this->data());
        $this->assertArrayNotHasKey('payment', $this->data());
    }

    public function testEmptyFinalRefundContinuationCannotBeTreatedAsComplete(): void
    {
        $refund = $this->refund();
        $this->setRefunds([$refund]);
        $this->provider->refundPages = [
            '' => [
                'object' => 'list',
                'has_more' => true,
                'data' => [$refund],
            ],
            're_webhook' => [
                'object' => 'list',
                'has_more' => false,
                'data' => [],
            ],
        ];
        $this->assertResponse($this->send($this->refundEvent()), 500);
        $this->assertArrayNotHasKey('refunds', $this->data());
        $this->assertArrayNotHasKey('payment', $this->data());
    }

    public function testLaterRefundPageFailureReturns503AndRedeliveryCommitsTheCompleteObservation(): void
    {
        $refund = $this->refund();
        $this->setRefunds([$refund]);
        $this->provider->refundPages[''] = [
            'object' => 'list',
            'has_more' => true,
            'data' => [$refund],
        ];
        $reads = 0;
        $this->provider->beforeRefundListRead = function () use (&$reads): void {
            if (++$reads > 1) {
                $this->provider->httpStatus = 500;
            }
        };
        $body = $this->refundEvent();
        $this->assertResponse($this->send($body), 503);
        $this->assertArrayNotHasKey('refunds', $this->data());
        $this->assertArrayNotHasKey('payment', $this->data());
        $this->assertSame('failed', $this->entries('events')[0]['status']);
        $this->provider->beforeRefundListRead = null;
        $this->provider->httpStatus = 200;
        $this->provider->refundPages = [];
        $this->assertResponse($this->send($body), 204);
        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertSame('partial', $this->data()['refundStatus']);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
    }

    public function testFailedCombinedWritePreservesPaymentAndRefundFactsUntilRedelivery(): void
    {
        $this->setRefunds([$this->refund()]);
        /** @var Closure(App, ModelWithContent): Storage $nativeStorage */
        $nativeStorage = $this->kirby->component('storage');
        $this->provider->beforeRefundListRead = function () use ($nativeStorage): void {
            $this->kirby->extend(['components' => ['storage' => function (App $kirby, ModelWithContent $model) use ($nativeStorage): Storage {
                if ($model instanceof OrderPage === false) {
                    return $nativeStorage($kirby, $model);
                }

                return new class ($model) extends PlainTextStorage {
                    protected function write(VersionId $versionId, Language $language, array $fields): void
                    {
                        throw new RuntimeException('PRIVATE_REFUND_WRITE');
                    }
                };
            }]]);
        };

        try {
            $this->assertResponse($this->send($this->refundEvent()), 503);
        } finally {
            $this->kirby->extend(['components' => ['storage' => $nativeStorage]]);
            $this->provider->beforeRefundListRead = null;
        }

        $this->assertArrayNotHasKey('refunds', $this->data());
        $this->assertArrayNotHasKey('payment', $this->data());
        $this->assertCount(0, $this->delivered);
        $this->assertResponse($this->send($this->refundEvent()), 204);
        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertSame('partial', $this->data()['refundStatus']);
    }

    public function testStaleCheckoutReadCannotErasePaymentSuccessButStillCommitsRefunds(): void
    {
        $this->assertResponse($this->send($this->event()), 204);
        $before = $this->data();
        $this->provider->session['payment_status'] = 'unpaid';
        $this->provider->paymentIntent['status'] = 'processing';
        $this->setRefunds([$this->refund()]);
        $this->assertResponse($this->send($this->refundEvent()), 204);
        $this->assertSame($before['paidAt'], $this->data()['paidAt']);
        $this->assertSame($before['payment'], $this->data()['payment']);
        $this->assertSame('partial', $this->data()['refundStatus']);
        $this->assertSame('refund.updated', $this->delivered[2]->type()->value);
    }

    public function testCheckoutEventPreservesRefundHistoryAndReorderingDoesNotNotify(): void
    {
        $this->setRefunds([$this->refund(status: 'pending'), $this->refund(id: 're_other', status: 'failed')]);
        $this->assertResponse($this->send($this->refundEvent()), 204);
        $before = $this->data();
        $this->provider->refunds = array_reverse($this->provider->refunds);
        $this->assertResponse($this->send($this->refundEvent(id: 'evt_reordered')), 204);
        $this->assertSame($before['refunds'], $this->data()['refunds']);
        $this->assertSame($before['refundUpdatedAt'], $this->data()['refundUpdatedAt']);
        $this->assertResponse($this->send($this->event()), 204);
        $this->assertSame($before['refunds'], $this->data()['refunds']);
        $this->assertCount(3, $this->delivered);
        $this->setRefunds([$this->refund(), $this->refund(id: 're_other', status: 'failed')]);
        $this->assertResponse($this->send($this->refundEvent(type: 'refund.updated', id: 'evt_changed')), 204);
        $this->assertSame('partial', $this->data()['refundStatus']);
        $this->assertTrue($this->data()['refundHasFailed']);
        $this->assertCount(4, $this->delivered);
        // Frozen pending facts remain restorable even after the live refund succeeds.
        $this->assertSame('pending', $this->delivered[2]->orderSnapshot()['refundStatus']);
    }

    /** @param array<string, mixed> $page */
    #[DataProvider('brokenRefundPages')]
    public function testIncompleteRefundCollectionsCannotPartiallyRefreshPayment(array $page): void
    {
        $this->setRefunds([$this->refund()]);
        $this->provider->refundPages[''] = $page;
        $this->assertResponse($this->send($this->refundEvent()), 500);
        $this->assertSame('creating', $this->data()['checkoutStatus']);
        $this->assertArrayNotHasKey('payment', $this->data());
        $this->assertArrayNotHasKey('refunds', $this->data());
        $this->assertSame('failed', $this->entries('events')[0]['status']);
    }

    /** @return iterable<array{array<string, mixed>}> */
    public static function brokenRefundPages(): iterable
    {
        yield 'empty continuation' => [[
            'object' => 'list',
            'has_more' => true,
            'data' => [],
        ]];
        yield 'missing trigger' => [[
            'object' => 'list',
            'has_more' => false,
            'data' => [],
        ]];
        yield 'wrong shape' => [[
            'object' => 'list',
            'has_more' => 'false',
            'data' => [],
        ]];
    }

    public function testRefundReadConflictRefetchesBothFamiliesBeforeCommitting(): void
    {
        $this->setRefunds([$this->refund(status: 'pending')]);
        $reads = 0;
        $this->provider->beforeRefundListRead = function () use (&$reads): void {
            $reads++;

            if ($reads === 1) {
                $this->provider->beforeRefundListRead = null;
                $this->setRefunds([$this->refund(status: 'succeeded')]);
                $this->assertResponse($this->send($this->refundEvent(id: 'evt_concurrent_refund')), 204);
                $this->provider->beforeRefundListRead = function () use (&$reads): void {
                    $reads++;
                };
            }
        };
        $this->assertResponse($this->send($this->refundEvent()), 204);
        $this->assertSame(2, $reads);
        $this->assertSame('partial', $this->data()['refundStatus']);
        $this->assertCount(3, $this->delivered);
    }

    public function testConcurrentCheckoutCommitRequiresAFreshCombinedRefundRead(): void
    {
        $this->setRefunds([$this->refund()]);
        $reads = 0;
        $this->provider->beforeRefundListRead = function () use (&$reads): void {
            if (++$reads === 1) {
                $this->provider->beforeRefundListRead = null;
                $this->provider->session['customer_details'] = ['email' => 'current@example.test'];
                $this->assertResponse($this->send($this->event()), 204);
                $this->provider->beforeRefundListRead = function () use (&$reads): void {
                    $reads++;
                };
            }
        };
        $this->assertResponse($this->send($this->refundEvent()), 204);
        $this->assertSame(2, $reads);
        $this->assertSame('current@example.test', OrderData::map($this->data()['customer'])['email']);
        $this->assertSame('partial', $this->data()['refundStatus']);
        $this->assertCount(3, $this->delivered);
    }

    public function testRepeatedCheckoutConflictsReturn503WithoutCommittingRefunds(): void
    {
        $this->setRefunds([$this->refund()]);
        $reads = 0;
        $this->provider->beforeRefundListRead = function () use (&$reads): void {
            $this->provider->session['customer_details'] = ['email' => 'version' . ++$reads . '@example.test'];
            /** @var array<string, mixed> $event */
            $event = json_decode($this->event(), true, flags: JSON_THROW_ON_ERROR);
            $event['id'] = 'evt_checkout_conflict_' . $reads;
            $this->assertResponse($this->send(json_encode($event, JSON_THROW_ON_ERROR)), 204);
        };
        $this->assertResponse($this->send($this->refundEvent()), 503);
        $this->assertArrayNotHasKey('refunds', $this->data());
        $this->assertSame('failed', $this->entries('events')[0]['status']);
        $this->assertSame('checkout.reconciliation_conflict', $this->entries('events')[0]['errorCode']);
        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertCount(2, $this->delivered);
    }

    public function testRefundReplayRestoresFrozenFactsAndKeepsTheOriginalDeadline(): void
    {
        $fail = true;
        /** @var list<LifecycleEvent> $replayed */
        $replayed = [];
        $this->kirby->extend(['hooks' => ['programmatordev.stripe-checkout.refund.updated' => function (OrderPage $order, LifecycleEvent $lifecycleEvent) use (&$fail, &$replayed): void {
            /** @var bool $fail Changed between delivery attempts. */
            if ($fail) {
                throw new RuntimeException('PRIVATE_REFUND_LISTENER');
            }

            $replayed[] = $lifecycleEvent;
        }]]);
        $this->setRefunds([$this->refund(status: 'pending')]);
        $this->assertResponse($this->send($this->refundEvent()), 204);
        $delivery = $this->entries('lifecycleDeliveries')[3];
        $this->assertSame('failed', $delivery['status']);
        $fail = false;
        $this->setRefunds([$this->refund()]);
        $this->assertResponse($this->send($this->refundEvent(type: 'refund.updated', id: 'evt_succeeded')), 204);
        $deliveryId = OrderData::string(OrderData::map($delivery['event'])['deliveryId']);
        $dispatcher = new OrderHookDispatcher($this->kirby);
        $dispatcher->dispatch($this->order->pageUuid(), $deliveryId);
        $this->assertCount(2, $replayed);
        $this->assertSame('partial', $replayed[0]->orderSnapshot()['refundStatus']);
        $this->assertSame('pending', $replayed[1]->orderSnapshot()['refundStatus']);
        $this->assertSame($deliveryId, $replayed[1]->deliveryId());
        $after = $this->entries('lifecycleDeliveries')[3];
        $this->assertSame($delivery['expiresAt'], $after['expiresAt']);
        $this->assertSame('delivered', $after['status']);
        $this->assertSame('partial', $this->data()['refundStatus']);
    }

    public function testLateRefundReadFailureAcknowledgesAConcurrentProcessedEvent(): void
    {
        $this->setRefunds([$this->refund()]);
        $body = $this->refundEvent();
        $this->provider->beforeRefundListRead = function () use ($body): void {
            $this->provider->beforeRefundListRead = null;
            $this->assertResponse($this->send($body), 204);
            $this->provider->httpStatus = 500;
        };
        $this->assertResponse($this->send($body), 204);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertSame('partial', $this->data()['refundStatus']);
        $this->assertCount(3, $this->delivered);
    }

    public function testRefundObserverFailureDoesNotRetryOnStripeRedelivery(): void
    {
        $this->kirby->extend(['hooks' => ['programmatordev.stripe-checkout.refund.updated' => function (): void {
            throw new RuntimeException('PRIVATE_REFUND_LISTENER');
        }]]);
        $this->setRefunds([$this->refund()]);
        $body = $this->refundEvent();
        $this->assertResponse($this->send($body), 204);
        $before = $this->data();
        $this->assertResponse($this->send($body), 204);
        $this->assertSame($before, $this->data());
        $this->assertSame('failed', $this->entries('lifecycleDeliveries')[3]['status']);
        $this->assertStringNotContainsString('PRIVATE_REFUND_LISTENER', json_encode($this->data(), JSON_THROW_ON_ERROR));
    }

    public function testReusedRefundEventWithAContradictoryParentOrTimestampIsRejected(): void
    {
        $this->setRefunds([$this->refund()]);
        $this->assertResponse($this->send($this->refundEvent()), 204);
        $before = $this->data();
        /** @var array{created: int, data: array{object: array<string, mixed>}} $body */
        $body = json_decode($this->refundEvent(), true, flags: JSON_THROW_ON_ERROR);
        $body['created']++;
        $this->assertResponse($this->send(json_encode($body, JSON_THROW_ON_ERROR)), 500);
        /** @var array{created: int, data: array{object: array<string, mixed>}} $body */
        $body = json_decode($this->refundEvent(), true, flags: JSON_THROW_ON_ERROR);
        $body['data']['object']['payment_intent'] = 'pi_other';
        $this->assertResponse($this->send(json_encode($body, JSON_THROW_ON_ERROR)), 500);
        $this->assertSame($before, $this->data());
    }

    public function testNativeDisputeStructureDisplaysSafeFactsAndCannotRewriteTheSnapshot(): void
    {
        $dispute = $this->dispute();
        $dispute['reason'] = 'fraudulent';
        $this->setDisputes([$dispute]);
        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $this->kirby->impersonate('kirby');
        $page = $this->orders->requirePage($this->order->pageUuid());
        $before = $this->data();
        $form = Form::for($page);
        /** @var array<string, mixed> $input */
        $input = $form->toFormValues();
        $rows = OrderData::list($input['disputes']);
        $row = OrderData::map($rows[0]);
        $this->assertSame('16.00', $row['amount']);
        $this->assertSame('EUR', $row['currency']);
        $this->assertSame('needs_response', $row['status']);
        $this->assertSame('du_webhook', $row['stripedisputeid']);
        $this->assertSame('fraudulent', $row['reason']);
        $evidenceDetails = OrderData::map($dispute['evidence_details']);
        $this->assertSame((string) OrderData::integer($evidenceDetails['due_by']), $row['evidencedueby']);
        $this->assertSame((string) OrderData::integer($dispute['created']), $row['createdat']);
        $storedDispute = OrderData::map(OrderData::list($before['disputes'])[0]);
        $this->assertSame((new DateTimeImmutable(OrderData::string($storedDispute['firstObservedAt'])))->format('Y-m-d H:i:s'), $row['firstobservedat']);
        $this->assertStringNotContainsString('PRIVATE_DISPUTE', json_encode($input['disputes'], JSON_THROW_ON_ERROR));

        $page->update([...$input, 'note' => 'Dispute inspected']);
        $this->assertSame($before, $this->data());
        $page = $this->orders->requirePage($this->order->pageUuid());
        $this->assertSame('Dispute inspected', $page->version('latest')->read('default')['note'] ?? null);

        $row['amount'] = '1.00';
        $input['disputes'] = [$row];
        $this->expectException(\Kirby\Exception\PermissionException::class);
        $page->update($input);
    }

    public function testUnmodeledDisputeFieldsDoNotParticipateInPluginValidation(): void
    {
        $dispute = $this->dispute();
        $dispute['future_provider_extension'] = ['ratio' => 0.5];
        $this->setDisputes([$dispute]);
        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $this->assertSame('needs_response', $this->data()['disputeStatus']);
        $this->assertArrayNotHasKey('future_provider_extension', $this->entries('disputes')[0]);
    }

    public function testDisputeSessionLookupIgnoresUnmodeledProviderFields(): void
    {
        $this->setDisputes([$this->dispute()]);
        // A fractional extension is valid provider JSON but would fail canonical snapshot normalization.
        $this->provider->sessionMatches = [[
            ...$this->provider->session,
            'payment_intent' => 'pi_webhook',
            'future_provider_extension' => ['ratio' => 0.5],
        ]];
        $this->assertArrayNotHasKey('stripeCheckoutSessionId', $this->data());

        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $data = $this->data();
        $this->assertSame('cs_webhook', $data['stripeCheckoutSessionId']);
        $this->assertSame('paid', $data['paymentStatus']);
        $this->assertSame('needs_response', $data['disputeStatus']);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertStringNotContainsString('future_provider_extension', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testForeignParentIsIgnoredEvenWhenDisputeMetadataClaimsOwnership(): void
    {
        $this->setDisputes([$this->dispute()]);
        /** @var array{created: int, data: array{object: array<string, mixed>}} $body */
        $body = json_decode($this->disputeEvent(), true, flags: JSON_THROW_ON_ERROR);
        $body['data']['object']['metadata'] = [
            PluginMetadata::OWNER_KEY => PluginMetadata::NAME,
            PluginMetadata::ORDER_KEY => $this->order->pageUuid(),
        ];
        $this->provider->paymentIntent['metadata'] = [];
        $before = $this->data();
        $this->assertResponse($this->send(json_encode($body, JSON_THROW_ON_ERROR)), 204);
        $this->assertSame($before, $this->data());
        $this->assertCount(0, $this->delivered);
    }

    /** @param list<array<string, mixed>> $matches */
    #[DataProvider('missingDisputeSessions')]
    public function testMissingDisputeSessionLookupFailsSafely(array $matches, bool $hasMore, int $status): void
    {
        $this->setDisputes([$this->dispute()]);
        $this->provider->sessionMatches = $matches;
        $this->provider->sessionLookupHasMore = $hasMore;
        $before = $this->data();
        $this->assertResponse($this->send($this->disputeEvent()), $status);
        $after = $this->data();
        unset($after['events']);
        $this->assertSame($before, $after);
        $this->assertSame('failed', $this->entries('events')[0]['status']);
    }

    /** @return iterable<array{list<array<string, mixed>>, bool, int}> */
    public static function missingDisputeSessions(): iterable
    {
        yield [[], false, 503];
        yield [[], true, 500];
        yield [[
            ['id' => 'cs_one'],
            ['id' => 'cs_two'],
        ], false, 500];
        yield [[[
            'id' => 'cs_other',
            'object' => 'checkout.session',
            'payment_intent' => 'pi_other',
        ]], false, 500];
    }

    public function testRepeatedDisputeCursorCannotCommitAPartialCollection(): void
    {
        $dispute = $this->dispute();
        $this->setDisputes([$dispute]);
        $page = [
            'object' => 'list',
            'has_more' => true,
            'data' => [$dispute],
        ];
        $this->provider->disputePages = [
            '' => $page,
            'du_webhook' => $page,
        ];
        $this->assertResponse($this->send($this->disputeEvent()), 500);
        $this->assertArrayNotHasKey('disputes', $this->data());
        $this->assertArrayNotHasKey('payment', $this->data());
    }

    public function testEmptyFinalDisputeContinuationCannotBeTreatedAsComplete(): void
    {
        $dispute = $this->dispute();
        $this->setDisputes([$dispute]);
        $this->provider->disputePages = [
            '' => [
                'object' => 'list',
                'has_more' => true,
                'data' => [$dispute],
            ],
            'du_webhook' => [
                'object' => 'list',
                'has_more' => false,
                'data' => [],
            ],
        ];
        $this->assertResponse($this->send($this->disputeEvent()), 500);
        $this->assertArrayNotHasKey('disputes', $this->data());
        $this->assertArrayNotHasKey('payment', $this->data());
    }

    public function testLaterDisputePageFailureReturns503AndRedeliveryCommitsTheCompleteObservation(): void
    {
        $dispute = $this->dispute();
        $this->setDisputes([$dispute]);
        $this->provider->disputePages[''] = [
            'object' => 'list',
            'has_more' => true,
            'data' => [$dispute],
        ];
        $reads = 0;
        $this->provider->beforeDisputeListRead = function () use (&$reads): void {
            if (++$reads > 1) {
                $this->provider->httpStatus = 500;
            }
        };
        $body = $this->disputeEvent();
        $this->assertResponse($this->send($body), 503);
        $this->assertArrayNotHasKey('disputes', $this->data());
        $this->assertArrayNotHasKey('payment', $this->data());
        $this->assertSame('failed', $this->entries('events')[0]['status']);
        $this->provider->beforeDisputeListRead = null;
        $this->provider->httpStatus = 200;
        $this->provider->disputePages = [];
        $this->assertResponse($this->send($body), 204);
        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertSame('needs_response', $this->data()['disputeStatus']);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
    }

    public function testFailedCombinedWritePreservesPaymentAndDisputeFactsUntilRedelivery(): void
    {
        $this->setDisputes([$this->dispute()]);
        /** @var Closure(App, ModelWithContent): Storage $nativeStorage */
        $nativeStorage = $this->kirby->component('storage');
        $this->provider->beforeDisputeListRead = function () use ($nativeStorage): void {
            $this->kirby->extend(['components' => ['storage' => function (App $kirby, ModelWithContent $model) use ($nativeStorage): Storage {
                if ($model instanceof OrderPage === false) {
                    return $nativeStorage($kirby, $model);
                }

                return new class ($model) extends PlainTextStorage {
                    protected function write(VersionId $versionId, Language $language, array $fields): void
                    {
                        throw new RuntimeException('PRIVATE_DISPUTE_WRITE');
                    }
                };
            }]]);
        };

        try {
            $this->assertResponse($this->send($this->disputeEvent()), 503);
        } finally {
            $this->kirby->extend(['components' => ['storage' => $nativeStorage]]);
            $this->provider->beforeDisputeListRead = null;
        }

        $this->assertArrayNotHasKey('disputes', $this->data());
        $this->assertArrayNotHasKey('payment', $this->data());
        $this->assertCount(0, $this->delivered);
        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertSame('needs_response', $this->data()['disputeStatus']);
    }

    public function testStaleCheckoutReadCannotErasePaymentSuccessButStillCommitsDisputes(): void
    {
        $this->assertResponse($this->send($this->event()), 204);
        $before = $this->data();
        $this->provider->session['payment_status'] = 'unpaid';
        $this->provider->paymentIntent['status'] = 'processing';
        $this->setDisputes([$this->dispute()]);
        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $this->assertSame($before['paidAt'], $this->data()['paidAt']);
        $this->assertSame($before['payment'], $this->data()['payment']);
        $this->assertSame('needs_response', $this->data()['disputeStatus']);
        $this->assertSame('dispute.updated', $this->delivered[2]->type()->value);
    }

    public function testConcurrentCheckoutCommitRequiresAFreshCombinedDisputeRead(): void
    {
        $this->setDisputes([$this->dispute()]);
        $reads = 0;
        $this->provider->beforeDisputeListRead = function () use (&$reads): void {
            if (++$reads === 1) {
                $this->provider->beforeDisputeListRead = null;
                $this->provider->session['customer_details'] = ['email' => 'current@example.test'];
                $this->assertResponse($this->send($this->event()), 204);
                $this->provider->beforeDisputeListRead = function () use (&$reads): void {
                    $reads++;
                };
            }
        };
        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $this->assertSame(2, $reads);
        $this->assertSame('current@example.test', OrderData::map($this->data()['customer'])['email']);
        $this->assertSame('needs_response', $this->data()['disputeStatus']);
        $this->assertCount(3, $this->delivered);
    }

    public function testRepeatedCheckoutConflictsReturn503WithoutCommittingDisputes(): void
    {
        $this->setDisputes([$this->dispute()]);
        $reads = 0;
        $this->provider->beforeDisputeListRead = function () use (&$reads): void {
            $this->provider->session['customer_details'] = ['email' => 'version' . ++$reads . '@example.test'];
            /** @var array<string, mixed> $event */
            $event = json_decode($this->event(), true, flags: JSON_THROW_ON_ERROR);
            $event['id'] = 'evt_checkout_conflict_' . $reads;
            $this->assertResponse($this->send(json_encode($event, JSON_THROW_ON_ERROR)), 204);
        };
        $this->assertResponse($this->send($this->disputeEvent()), 503);
        $this->assertArrayNotHasKey('disputes', $this->data());
        $this->assertSame('failed', $this->entries('events')[0]['status']);
        $this->assertSame('checkout.reconciliation_conflict', $this->entries('events')[0]['errorCode']);
        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertCount(2, $this->delivered);
    }

    public function testDisputeReplayRestoresFrozenFactsAndKeepsTheOriginalDeadline(): void
    {
        $fail = true;
        /** @var list<LifecycleEvent> $replayed */
        $replayed = [];
        $this->kirby->extend(['hooks' => ['programmatordev.stripe-checkout.dispute.updated' => function (OrderPage $order, LifecycleEvent $lifecycleEvent) use (&$fail, &$replayed): void {
            /** @var bool $fail Changed between delivery attempts. */
            if ($fail) {
                throw new RuntimeException('PRIVATE_DISPUTE_LISTENER');
            }

            $replayed[] = $lifecycleEvent;
        }]]);
        $this->setDisputes([$this->dispute(status: 'under_review')]);
        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $delivery = $this->entries('lifecycleDeliveries')[3];
        $this->assertSame('failed', $delivery['status']);
        $fail = false;
        $this->setDisputes([$this->dispute()]);
        $this->assertResponse($this->send($this->disputeEvent(type: 'charge.dispute.updated', id: 'evt_needs_response')), 204);
        $deliveryId = OrderData::string(OrderData::map($delivery['event'])['deliveryId']);
        $dispatcher = new OrderHookDispatcher($this->kirby);
        $dispatcher->dispatch($this->order->pageUuid(), $deliveryId);
        $this->assertCount(2, $replayed);
        $this->assertSame('needs_response', $replayed[0]->orderSnapshot()['disputeStatus']);
        $this->assertSame('under_review', $replayed[1]->orderSnapshot()['disputeStatus']);
        $this->assertSame($deliveryId, $replayed[1]->deliveryId());
        $after = $this->entries('lifecycleDeliveries')[3];
        $this->assertSame($delivery['expiresAt'], $after['expiresAt']);
        $this->assertSame('delivered', $after['status']);
        $this->assertSame('needs_response', $this->data()['disputeStatus']);
    }

    public function testLateDisputeReadFailureAcknowledgesAConcurrentProcessedEvent(): void
    {
        $this->setDisputes([$this->dispute()]);
        $body = $this->disputeEvent();
        $this->provider->beforeDisputeListRead = function () use ($body): void {
            $this->provider->beforeDisputeListRead = null;
            $this->assertResponse($this->send($body), 204);
            $this->provider->httpStatus = 500;
        };
        $this->assertResponse($this->send($body), 204);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertSame('needs_response', $this->data()['disputeStatus']);
        $this->assertCount(3, $this->delivered);
    }

    public function testDisputeObserverFailureDoesNotRetryOnStripeRedelivery(): void
    {
        $this->kirby->extend(['hooks' => ['programmatordev.stripe-checkout.dispute.updated' => function (): void {
            throw new RuntimeException('PRIVATE_DISPUTE_LISTENER');
        }]]);
        $this->setDisputes([$this->dispute()]);
        $body = $this->disputeEvent();
        $this->assertResponse($this->send($body), 204);
        $before = $this->data();
        $this->assertResponse($this->send($body), 204);
        $this->assertSame($before, $this->data());
        $this->assertSame('failed', $this->entries('lifecycleDeliveries')[3]['status']);
        $this->assertStringNotContainsString('PRIVATE_DISPUTE_LISTENER', json_encode($this->data(), JSON_THROW_ON_ERROR));
    }

    public function testReusedDisputeEventWithAContradictoryParentOrTimestampIsRejected(): void
    {
        $this->setDisputes([$this->dispute()]);
        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $before = $this->data();
        /** @var array{created: int, data: array{object: array<string, mixed>}} $body */
        $body = json_decode($this->disputeEvent(), true, flags: JSON_THROW_ON_ERROR);
        $body['created']++;
        $this->assertResponse($this->send(json_encode($body, JSON_THROW_ON_ERROR)), 500);
        /** @var array{created: int, data: array{object: array<string, mixed>}} $body */
        $body = json_decode($this->disputeEvent(), true, flags: JSON_THROW_ON_ERROR);
        $body['data']['object']['payment_intent'] = 'pi_other';
        $this->assertResponse($this->send(json_encode($body, JSON_THROW_ON_ERROR)), 500);
        $this->assertSame($before, $this->data());
    }

    #[DataProvider('disputeEvents')]
    public function testEveryDisputeEventRetrievesCurrentStateRatherThanTrustingTheEvent(string $type): void
    {
        $this->setDisputes([$this->dispute()]);
        $body = $this->disputeEvent($type);
        $this->setDisputes([$this->dispute(status: 'prevented', amount: 5000)]);
        $this->assertResponse($this->send($body), 204);
        $data = $this->data();
        $this->assertSame('paid', $data['paymentStatus']);
        $this->assertSame('resolved_favorable', $data['disputeStatus']);
        $this->assertSame('50.00', $this->entries('disputes')[0]['amount']);
        $this->assertSame('ch_disputed', $this->entries('events')[0]['stripeChargeId']);
        $this->assertSame('dispute.updated', $this->delivered[2]->type()->value);
        $this->assertSame($data['disputes'], $this->delivered[2]->orderSnapshot()['disputes']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<array{string}> */
    public static function disputeEvents(): iterable
    {
        yield ['charge.dispute.created'];
        yield ['charge.dispute.updated'];
        yield ['charge.dispute.closed'];
        yield ['charge.dispute.funds_withdrawn'];
        yield ['charge.dispute.funds_reinstated'];
    }

    public function testDisputeDuplicateAndUnchangedObservationSkipNotificationsAndKeepTimes(): void
    {
        $this->setDisputes([$this->dispute()]);
        $body = $this->disputeEvent();
        $this->assertResponse($this->send($body), 204);
        $before = $this->data();
        $reads = count($this->provider->requests);
        $this->assertResponse($this->send($body), 204);
        $this->assertSame($before, $this->data());
        $this->assertSame([
            'https://api.stripe.com/v1/disputes/du_webhook',
            'https://api.stripe.com/v1/charges/ch_disputed',
            'https://api.stripe.com/v1/payment_intents/pi_webhook',
        ], array_slice($this->provider->requests, $reads));
        $this->assertResponse($this->send($this->disputeEvent(id: 'evt_unchanged')), 204);
        $this->assertSame($before['disputes'], $this->data()['disputes']);
        $this->assertSame($before['disputeUpdatedAt'], $this->data()['disputeUpdatedAt']);
        $this->assertCount(3, $this->delivered);
    }

    public function testBalanceAndEvidenceChangesNotifyWithoutChangingTheDisputeSummary(): void
    {
        $dispute = $this->dispute();
        $this->setDisputes([$dispute]);
        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $dispute['balance_transactions'] = [[
            'id' => 'txn_withdrawn',
            'object' => 'balance_transaction',
            'currency' => 'usd',
            'amount' => -1800,
            'fee' => 200,
            'net' => -2000,
            'created' => 100,
            'exchange_rate' => 1.125,
            'description' => 'PRIVATE_BALANCE',
        ]];
        $evidenceDetails = OrderData::map($dispute['evidence_details']);
        $dispute['evidence_details'] = [
            ...$evidenceDetails,
            'has_evidence' => true,
            'submission_count' => 1,
            'future_extension' => 1.5,
        ];
        $this->setDisputes([$dispute]);
        $this->assertResponse($this->send($this->disputeEvent('charge.dispute.funds_withdrawn', 'evt_balance')), 204);
        $facts = $this->entries('disputes')[0];
        $this->assertSame('needs_response', $this->data()['disputeStatus']);
        $this->assertTrue($facts['evidenceHasEvidence']);
        $this->assertSame([[
            'amount' => -1800,
            'createdAt' => 100,
            'currency' => 'USD',
            'fee' => 200,
            'net' => -2000,
            'stripeBalanceTransactionId' => 'txn_withdrawn',
        ]], $facts['balanceTransactions']);
        $this->assertCount(4, $this->delivered);
        $this->assertStringNotContainsString('PRIVATE', json_encode($this->data(), JSON_THROW_ON_ERROR));

        $this->kirby->impersonate('kirby');
        $input = Form::for($this->orders->requirePage($this->order->pageUuid()))->toFormValues();
        $rows = OrderData::list($input['disputes']);
        $row = OrderData::map($rows[0]);
        $movements = OrderData::list($row['balancetransactions']);
        $movement = OrderData::map($movements[0]);
        $this->assertSame('-1800', $movement['amount']);
        $this->assertSame('USD', $movement['currency']);
    }

    public function testRefundCheckoutAndDisputeEventsPreserveTheOtherFinancialCollection(): void
    {
        $this->setRefunds([$this->refund()]);
        $this->assertResponse($this->send($this->refundEvent()), 204);
        $refunded = $this->data();
        $this->setDisputes([$this->dispute(amount: 5000)]);
        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $disputed = $this->data();
        $this->assertSame($refunded['refunds'], $disputed['refunds']);
        $this->assertSame('partial', $disputed['refundStatus']);
        $this->assertSame($refunded['paidAt'], $disputed['paidAt']);
        $this->provider->beforeDisputeListRead = static function (): never {
            throw new RuntimeException('The other financial family must not be read.');
        };
        $this->setRefunds([$this->refund(amount: 3200)]);
        $this->assertResponse($this->send($this->refundEvent(id: 'evt_full')), 204);
        $this->assertResponse($this->send($this->event()), 204);
        $this->assertSame('full', $this->data()['refundStatus']);
        $this->assertSame($disputed['disputes'], $this->data()['disputes']);
        $this->assertSame($disputed['disputeUpdatedAt'], $this->data()['disputeUpdatedAt']);
    }

    public function testAConcurrentRefundCommitForcesAFreshDisputeReadAndPreservesBothFamilies(): void
    {
        $this->setDisputes([$this->dispute()]);
        $this->setRefunds([$this->refund()]);
        $reads = 0;
        $this->provider->beforeDisputeListRead = function () use (&$reads): void {
            if (++$reads === 1) {
                $this->provider->beforeDisputeListRead = null;
                $this->assertResponse($this->send($this->refundEvent()), 204);
                $this->provider->beforeDisputeListRead = static function () use (&$reads): void {
                    $reads++;
                };
            }
        };
        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $this->assertSame(2, $reads);
        $this->assertSame('partial', $this->data()['refundStatus']);
        $this->assertSame('needs_response', $this->data()['disputeStatus']);
        $this->assertSame(['session.created', 'payment.succeeded', 'refund.updated', 'dispute.updated'], array_map(static fn(LifecycleEvent $event): string => $event->type()->value, $this->delivered));
    }

    public function testDisputeChargeBacklinkResolvesANullPaymentIntentAndAcceptsAnOlderCharge(): void
    {
        $dispute = $this->dispute();
        $dispute['payment_intent'] = null;
        $this->setDisputes([$dispute]);
        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $this->assertSame('pi_webhook', $this->entries('disputes')[0]['stripePaymentIntentId']);
        $this->assertSame('ch_disputed', $this->entries('disputes')[0]['stripeChargeId']);
        $this->assertNull($this->provider->paymentIntent['latest_charge']);
    }

    #[DataProvider('disputeParentContradictions')]
    public function testDisputeParentContradictionsCannotAttachAnEventOrChangeCommerce(string $field, mixed $value): void
    {
        $this->setDisputes([$this->dispute()]);
        $this->provider->charges['ch_disputed'][$field] = $value;
        $before = $this->data();
        $this->assertResponse($this->send($this->disputeEvent()), 500);
        $this->assertSame($before, $this->data());
    }

    /** @return iterable<array{string, mixed}> */
    public static function disputeParentContradictions(): iterable
    {
        yield ['id', 'ch_other'];
        yield ['payment_intent', 'pi_other'];
        yield ['currency', 'usd'];
        yield ['livemode', true];
    }

    public function testCompleteDisputePagesProduceAMixedSummaryWithoutRepeatedChargeReads(): void
    {
        $first = $this->dispute(status: 'lost');
        $second = $this->dispute(id: 'du_second', status: 'won');
        $this->setDisputes([$first, $second]);
        $this->provider->disputePages = [
            '' => [
                'object' => 'list',
                'has_more' => true,
                'data' => [$first],
            ],
            'du_webhook' => [
                'object' => 'list',
                'has_more' => false,
                'data' => [$second],
            ],
        ];
        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $this->assertSame('mixed', $this->data()['disputeStatus']);
        $this->assertTrue($this->data()['disputeHasLost']);
        $this->assertSame(['du_second', 'du_webhook'], array_column($this->entries('disputes'), 'stripeDisputeId'));
        $this->assertCount(1, array_filter($this->provider->requests, static fn(string $url): bool => str_contains($url, '/charges/')));
        $before = $this->data();
        $this->provider->disputePages = [];
        $this->provider->disputes = array_reverse($this->provider->disputes);
        $this->assertResponse($this->send($this->disputeEvent(id: 'evt_reordered_disputes')), 204);
        $this->assertSame($before['disputes'], $this->data()['disputes']);
        $this->assertSame($before['disputeUpdatedAt'], $this->data()['disputeUpdatedAt']);
        $this->assertCount(3, $this->delivered);
    }

    public function testDistinctSiblingDisputeChargeIsCorrelatedOnceAndReused(): void
    {
        $first = $this->dispute();
        $second = $this->dispute(id: 'du_second', status: 'won');
        $second['charge'] = 'ch_sibling';
        $second['payment_intent'] = null;
        $third = $this->dispute(id: 'du_third', status: 'lost');
        $third['charge'] = 'ch_sibling';
        $this->setDisputes([$first, $second, $third]);
        $this->provider->charges['ch_sibling'] = [
            'id' => 'ch_sibling',
            'object' => 'charge',
            'payment_intent' => 'pi_webhook',
            'currency' => 'eur',
            'livemode' => false,
        ];

        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $disputes = $this->entries('disputes');
        $this->assertSame(['du_second', 'du_third', 'du_webhook'], array_column($disputes, 'stripeDisputeId'));
        $this->assertSame(['ch_sibling', 'ch_sibling', 'ch_disputed'], array_column($disputes, 'stripeChargeId'));
        $this->assertSame(['pi_webhook', 'pi_webhook', 'pi_webhook'], array_column($disputes, 'stripePaymentIntentId'));
        $this->assertSame('needs_response', $this->data()['disputeStatus']);
        $this->assertTrue($this->data()['disputeHasLost']);
        $this->assertCount(1, array_filter($this->provider->requests, static fn(string $url): bool => $url === 'https://api.stripe.com/v1/charges/ch_sibling'));
        $this->assertCount(3, $this->delivered);
    }

    public function testSiblingDisputeChargeForAnotherPaymentCannotChangeSavedCommerce(): void
    {
        $first = $this->dispute();
        $this->setDisputes([$first]);
        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $before = $this->data();
        $second = $this->dispute(id: 'du_other');
        $second['charge'] = 'ch_other_payment';
        $second['payment_intent'] = null;
        $this->setDisputes([$first, $second]);
        $this->provider->charges['ch_other_payment'] = [
            'id' => 'ch_other_payment',
            'object' => 'charge',
            'payment_intent' => 'pi_other',
            'currency' => 'eur',
            'livemode' => false,
        ];

        $this->assertResponse($this->send($this->disputeEvent(id: 'evt_invalid_sibling')), 500);
        $after = $this->data();
        unset($before['events'], $after['events']);
        $this->assertSame($before, $after);
        $this->assertSame('failed', $this->entries('events')[1]['status']);
        $this->assertSame('checkout.session_incompatible', $this->entries('events')[1]['errorCode']);
        $this->assertCount(3, $this->delivered);
    }

    public function testMissingTriggeringDisputeCannotCommitAnUnrelatedCompleteCollection(): void
    {
        $this->setDisputes([$this->dispute()]);
        $this->provider->disputes = [$this->dispute(id: 'du_other')];
        $this->assertResponse($this->send($this->disputeEvent()), 500);
        $this->assertSame('creating', $this->data()['checkoutStatus']);
        $this->assertArrayNotHasKey('disputes', $this->data());
        $this->assertSame('failed', $this->entries('events')[0]['status']);
    }

    #[DataProvider('contradictoryDisputeSiblings')]
    public function testContradictorySiblingFactsCannotPartiallyCommitPayment(string $field, mixed $value): void
    {
        $first = $this->dispute();
        $second = $this->dispute(id: 'du_other');
        $second[$field] = $value;
        $this->setDisputes([$first, $second]);
        $this->assertResponse($this->send($this->disputeEvent()), 500);
        $this->assertSame('creating', $this->data()['checkoutStatus']);
        $this->assertArrayNotHasKey('disputes', $this->data());
        $this->assertSame('failed', $this->entries('events')[0]['status']);
    }

    /** @return iterable<array{string, mixed}> */
    public static function contradictoryDisputeSiblings(): iterable
    {
        yield ['payment_intent', 'pi_other'];
        yield ['currency', 'usd'];
        yield ['livemode', true];
        yield ['object', 'refund'];
    }

    public function testOpaqueDisputeFactsSurviveProcessingAndExpiredPayloadPruning(): void
    {
        $dispute = $this->dispute(id: 'dispute.reference-01');
        $dispute['reason'] = str_repeat('provider_reason_', 200);
        $this->setDisputes([$dispute]);
        $body = json_encode([
            'id' => 'event.reference-dispute',
            'object' => 'event',
            'type' => 'charge.dispute.created',
            'created' => $this->createdAt->getTimestamp() + 3600,
            'livemode' => false,
            'data' => ['object' => $dispute],
        ], JSON_THROW_ON_ERROR);
        $this->assertResponse($this->send($body), 204);
        $this->assertSame('dispute.reference-01', $this->entries('disputes')[0]['stripeDisputeId']);
        $this->assertSame($dispute['reason'], $this->entries('disputes')[0]['reason']);
        $this->assertResponse($this->send($body), 204);
        $deliveries = $this->entries('lifecycleDeliveries');
        $delivery = $deliveries[3];
        $expiresAt = OrderData::string($delivery['expiresAt']);
        $this->kirby->impersonate('kirby');
        $this->orders->pruneLifecycleDeliveryPayloads(
            uuid: $this->order->pageUuid(),
            now: (new DateTimeImmutable($expiresAt))->modify('+1 day'),
        );
        $pruned = $this->entries('lifecycleDeliveries')[3];
        $this->assertSame($expiresAt, $pruned['expiresAt']);
        $event = OrderData::map($pruned['event']);
        $this->assertSame('event.reference-dispute', $event['triggerId']);
        $this->assertArrayNotHasKey('orderSnapshot', $event);
        $this->assertSame('dispute.reference-01', $this->entries('disputes')[0]['stripeDisputeId']);
    }

    public function testDisputeEventRestorationRequiresItsChargeCorrelation(): void
    {
        $this->setDisputes([$this->dispute()]);
        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $entries = $this->entries('events');
        $entries[0]['stripeChargeId'] = null;
        $this->expectException(\ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException::class);
        \ProgrammatorDev\StripeCheckout\Order\Internal\StripeEventLedger::normalize($entries);
    }

    public function testDisputeRestorationRejectsContradictoryDerivedSummary(): void
    {
        $this->setDisputes([$this->dispute()]);
        $this->assertResponse($this->send($this->disputeEvent()), 204);
        $data = $this->data();
        $data['disputeHasLost'] = true;
        $this->expectException(\ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException::class);
        \ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer::normalize($data);
    }

    /** @param list<array<string, mixed>> $refunds */
    private function setRefunds(array $refunds): void
    {
        $this->provider->refunds = $refunds;

        foreach ($refunds as $refund) {
            $this->provider->refundRecords[OrderData::string($refund['id'])] = $refund;
        }
    }

    /** @return array<string, mixed> */
    private function refund(string $id = 're_webhook', string $status = 'succeeded', int $amount = 1600): array
    {
        return [
            'id' => $id,
            'object' => 'refund',
            'amount' => $amount,
            'currency' => 'eur',
            'payment_intent' => 'pi_webhook',
            'charge' => null,
            'created' => $this->createdAt->getTimestamp() + 3600,
            'status' => $status,
            'metadata' => [],
            'instructions_email' => 'PRIVATE_REFUND_EMAIL',
            'next_action' => ['secret' => 'PRIVATE_REFUND_ACTION'],
        ];
    }

    private function refundEvent(string $type = 'refund.created', string $id = 'evt_refund'): string
    {
        return json_encode([
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'created' => $this->createdAt->getTimestamp() + 3600,
            'livemode' => false,
            'data' => ['object' => $this->provider->refundRecords['re_webhook']],
        ], JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string, mixed>> $disputes */
    private function setDisputes(array $disputes): void
    {
        $this->provider->disputes = $disputes;
        $this->provider->charges['ch_disputed'] = [
            'id' => 'ch_disputed',
            'object' => 'charge',
            'payment_intent' => 'pi_webhook',
            'currency' => 'eur',
            'livemode' => false,
        ];

        foreach ($disputes as $dispute) {
            $this->provider->disputeRecords[OrderData::string($dispute['id'])] = $dispute;
        }
    }

    /** @return array<string, mixed> */
    private function dispute(string $id = 'du_webhook', string $status = 'needs_response', int $amount = 1600): array
    {
        return [
            'id' => $id,
            'object' => 'dispute',
            'amount' => $amount,
            'currency' => 'eur',
            'payment_intent' => 'pi_webhook',
            'charge' => 'ch_disputed',
            'livemode' => false,
            'created' => $this->createdAt->getTimestamp() + 3600,
            'status' => $status,
            'reason' => 'fraudulent',
            'evidence_details' => [
                'due_by' => $this->createdAt->getTimestamp() + 86400,
                'has_evidence' => false,
                'past_due' => false,
                'submission_count' => 0,
            ],
            'balance_transactions' => [],
            'metadata' => [],
            'evidence' => ['customer_email_address' => 'PRIVATE_DISPUTE_EMAIL'],
        ];
    }

    private function disputeEvent(string $type = 'charge.dispute.created', string $id = 'evt_dispute'): string
    {
        return json_encode([
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'created' => $this->createdAt->getTimestamp() + 3600,
            'livemode' => false,
            'data' => ['object' => $this->provider->disputeRecords['du_webhook']],
        ], JSON_THROW_ON_ERROR);
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
