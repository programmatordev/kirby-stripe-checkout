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
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutSessionException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSessionReconciler;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSessionReducer;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSessionRetriever;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\PersistenceErrorCode;
use ProgrammatorDev\StripeCheckout\Lifecycle\Internal\HookDeliveryLedger;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEvent;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Order\Payment;
use ProgrammatorDev\StripeCheckout\Order\PaymentAction;
use ProgrammatorDev\StripeCheckout\Plugin\PluginMetadata;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailure;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailureType;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionGatewayInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionReconciliationRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Exception\CheckoutSessionGatewayException;
use ProgrammatorDev\StripeCheckout\Test\Support\CheckoutAttemptFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\OrderFixture;
use ProgrammatorDev\StripeCheckout\Test\Support\Stripe\FakeCheckoutSessionGateway;
use RuntimeException;
use Stripe\Event;

final class CheckoutSessionReconcilerTest extends KirbyTestCase
{
    private OrderPageStore $store;
    private OrderCreationContext $order;
    private SessionRequest $request;
    private DateTimeImmutable $createdAt;
    /** @var list<LifecycleEvent> */
    private array $delivered = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new OrderPageStore($this->kirby);
        $this->createdAt = new DateTimeImmutable('2026-10-01T12:00:00Z');
        $line = OrderFixture::lineItemData();
        $line['requiresShipping'] = false;
        $this->order = new OrderCreationContext(
            uuid: 'reconciliation001',
            orderNumber: 'ORD-RECONCILIATION001',
            checkoutSource: \ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource::Cart,
            cartRevision: 'revision',
            userUuid: null,
            languageCode: null,
            uiMode: \ProgrammatorDev\StripeCheckout\Checkout\UiMode::Hosted,
            currency: 'EUR',
            lineItems: [OrderLineItemSnapshot::fromArray($line)],
        );
        $this->request = new SessionRequest([
            'mode' => 'payment',
            'currency' => 'eur',
            'client_reference_id' => $this->order->pageUuid(),
            'expires_at' => $this->createdAt->getTimestamp() + 86400,
            'integration_identifier' => 'kirby_stripe_checkout_abcdefgh',
            'metadata' => $this->metadata(),
            'payment_intent_data' => ['metadata' => $this->metadata()],
            'line_items' => [[
                'quantity' => 2,
                'metadata' => [
                    ...$this->metadata(),
                    PluginMetadata::LINE_KEY => 'line_0',
                ],
            ]],
        ]);
        $attempt = CheckoutAttemptFactory::create($this->order, $this->createdAt, request: $this->request);
        $this->store->create($this->order, $attempt, $this->createdAt);
        $delivered = &$this->delivered;
        $hooks = [];
        $names = ['session.created', 'payment.pending', 'payment.requiresAction', 'payment.succeeded', 'payment.failed', 'checkout.expired'];

        foreach ($names as $name) {
            $hooks['programmatordev.stripe-checkout.' . $name] = function (OrderPage $order, LifecycleEvent $lifecycleEvent) use (&$delivered): void {
                // Read the committed Page inside the observer; delivery must follow persistence and lock release.
                $order->version('latest')->read('default');
                $delivered[] = $lifecycleEvent;
            };
        }

        $this->kirby->extend(['hooks' => $hooks]);
    }

    #[DataProvider('outcomes')]
    public function testCommitsAuthoritativeOutcomesRegardlessOfTriggerAge(string $eventType, string $status, string $sessionPayment, string $intentStatus, string $expected, string $hook): void
    {
        $record = $this->record($status, $sessionPayment, $intentStatus);
        $gateway = $this->gateway($record);
        $page = $this->reconciler($gateway)->reconcile($this->order->pageUuid(), 'cs_current', $this->event($eventType));
        $data = $this->data($page);

        $this->assertSame($expected, $data['paymentStatus']);
        $this->assertSame($status, $data['checkoutStatus']);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertSame(1, $this->entries('events')[0]['attempts']);
        $this->assertSame('in_current', $data['stripeInvoiceId']);
        $this->assertSame(['session.created', $hook], array_map(static fn(LifecycleEvent $event): string => $event->type()->value, $this->deliveries()));
        $this->assertSame($eventType, $this->deliveries()[1]->triggerType());
        $this->assertSame('evt_first', $this->deliveries()[1]->triggerId());
        $this->assertSame($expected, $this->deliveries()[1]->payment()?->status()->value);
        $this->assertCount(1, $gateway->reconciliationRetrievals);
        $this->assertSame([], $gateway->requests);

        if ($status === 'complete') {
            $this->assertSame('32.00', $data['total']);
            $this->assertSame([], $data['customFields']);
            $this->assertSame([], $data['discounts']);
            $this->assertSame('li_first', $this->entries('lineItems')[0]['stripeLineItemId']);
        }
    }

    /** @return iterable<string, array{string, string, string, string, string, string}> */
    public static function outcomes(): iterable
    {
        yield 'immediate payment' => ['checkout.session.completed', 'complete', 'paid', 'succeeded', 'paid', 'payment.succeeded'];
        yield 'delayed payment' => ['checkout.session.completed', 'complete', 'unpaid', 'processing', 'pending', 'payment.pending'];
        yield 'delayed success' => ['checkout.session.async_payment_succeeded', 'complete', 'paid', 'succeeded', 'paid', 'payment.succeeded'];
        yield 'delayed failure' => ['checkout.session.async_payment_failed', 'complete', 'unpaid', 'requires_payment_method', 'failed', 'payment.failed'];
        yield 'expired checkout' => ['checkout.session.expired', 'expired', 'unpaid', 'canceled', 'unpaid', 'checkout.expired'];
    }

    public function testCapturesActionsAfterPendingAndReplaysTheFrozenFactsAfterPayment(): void
    {
        $this->reconciler($this->gateway($this->record()))->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
        $pending = $this->deliveries()[1];
        $this->assertNull($pending->payment()?->nextAction());

        $event = $this->actionEvent();
        $page = $this->reconciler($this->gateway($this->record()))->reconcile($this->order->pageUuid(), 'cs_current', $event);
        $this->assertSame('pending', $this->store->data($page)['paymentStatus']);
        $actionEvent = $this->deliveries()[2];
        $payment = $actionEvent->payment() ?? $this->fail('Captured actions require payment facts.');
        $nextAction = $payment->nextAction() ?? $this->fail('Provider action was not captured.');
        $this->assertSame('payment.requiresAction', $actionEvent->type()->value);
        $this->assertSame('payment_intent.requires_action', $actionEvent->triggerType());
        $this->assertSame('123456789', $nextAction->details()['reference']);
        $this->assertNull($nextAction->toPaymentIntent()->client_secret ?? null);

        $gateway = $this->gateway($this->record('complete', 'paid', 'succeeded'));
        $this->reconciler($gateway)->reconcile($this->order->pageUuid(), 'cs_current', $this->event('checkout.session.async_payment_succeeded', 'evt_paid'));
        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertSame('payment.succeeded', $this->deliveries()[3]->type()->value);
        $this->assertSame('pending', $payment->status()->value);
        $this->assertSame('123456789', $nextAction->details()['reference']);
        $this->assertNull($this->deliveries()[3]->payment()?->nextAction());
        $this->assertNull(Payment::fromArray(OrderData::map($this->data()['payment']))->nextAction());

        $details = $nextAction->details();
        $details['reference'] = 'MUTATED';
        $this->assertSame('123456789', $nextAction->details()['reference']);
        $entries = $this->entries('lifecycleDeliveries');
        $restored = HookDeliveryLedger::restoreEvent(OrderData::map(OrderData::map($entries[3])['event']));
        $this->assertSame('123456789', $restored->payment()?->nextAction()?->details()['reference']);

        $serialized = json_encode($this->data(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SECRET_CANARY', $serialized);
        $this->assertStringNotContainsString('client_secret', $serialized);
    }

    public function testDuplicatesDoNotReadStripeOrDispatchAgainAndOtherEventsDoNotRepeatTransitions(): void
    {
        $gateway = $this->gateway($this->record('complete', 'paid', 'succeeded'));
        $reconciler = $this->reconciler($gateway);
        $event = $this->event();
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $event);
        $before = $this->data();
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $event);
        $this->assertSame($before, $this->data());
        $this->assertCount(1, $gateway->reconciliationRetrievals);
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $this->event('checkout.session.async_payment_succeeded', 'evt_other'));
        $this->assertCount(2, $this->deliveries());
        $this->assertCount(2, $this->entries('events'));
    }

    public function testOutOfOrderPendingReadDoesNotOverwritePaidStateOrCapabilities(): void
    {
        $this->reconciler($this->gateway($this->record('complete', 'paid', 'succeeded')))->reconcile($this->order->pageUuid(), 'cs_current');
        $before = $this->data();
        $this->reconciler($this->gateway($this->record()))->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
        $after = $this->data();
        unset($after['events']);
        $this->assertSame($before, $after);
        $this->assertCount(2, $this->deliveries());
    }

    public function testLateActionsAreNotRetainedOrNotifiedAfterPayment(): void
    {
        $gateway = $this->gateway($this->record('complete', 'paid', 'succeeded'));
        $reconciler = $this->reconciler($gateway);
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current');
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $this->actionEvent());
        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertNull(Payment::fromArray(OrderData::map($this->data()['payment']))->nextAction());
        $this->assertCount(2, $this->deliveries());
    }

    /** @param array<string, mixed> $action */
    #[DataProvider('genericActions')]
    public function testRetainsTheActiveProviderBranchWithoutAnActionAllowlist(array $action): void
    {
        $raw = $this->actionEvent()->toArray();
        $object = OrderData::map(OrderData::map($raw['data'])['object']);
        $object['next_action'] = $action;
        $raw['data'] = ['object' => $object];
        $page = $this->reconciler($this->gateway($this->record()))->reconcile($this->order->pageUuid(), 'cs_current', Event::constructFrom($raw));
        $payment = Payment::fromArray(OrderData::map($this->data($page)['payment']));
        $this->assertEquals($action, $payment->nextAction()?->toArray());
        $this->assertEquals($action, $this->deliveries()[2]->payment()?->nextAction()?->toArray());
        $this->assertStringNotContainsString('SECRET_CANARY', json_encode($this->data($page), JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function genericActions(): iterable
    {
        yield 'unfamiliar provider data' => [[
            'type' => 'future_action',
            'future_action' => [
                'nested' => ['values' => [1.25, null, false]],
                'unmapped_key' => 'original provider value',
            ],
        ]];
        yield 'SDK directive' => [[
            'type' => 'use_stripe_sdk',
            'use_stripe_sdk' => [],
        ]];
    }

    public function testActionExpiryUsesConfiguredDaysAndUnchangedReadsDoNotRenewIt(): void
    {
        $action = PaymentAction::fromArray([
            'type' => 'future_action',
            'future_action' => ['reference' => 'example'],
        ]);
        $observation = (new CheckoutSessionRetriever($this->gateway($this->record(action: $action))))->retrieve(
            sessionId: 'cs_current',
            order: $this->order,
            request: $this->request,
            credentialMode: CredentialMode::Test,
        );
        $reducer = new CheckoutSessionReducer(lifecycleDeliveryRetentionDays: 7);
        $now = new DateTimeImmutable('2026-10-02T12:00:00Z');
        $captured = $reducer->reduce($this->data(), $observation, null, $now);
        $expiresAt = Payment::fromArray(OrderData::map($captured['payment']))->nextActionExpiresAt();
        $this->assertEquals($now->modify('+7 days'), $expiresAt);
        $unchanged = $reducer->reduce($captured, $observation, null, $now->modify('+1 day'));
        $this->assertSame($captured, $unchanged);

        $expired = $reducer->reduce($unchanged, $observation, null, $now->modify('+7 days'));
        $payment = Payment::fromArray(OrderData::map($expired['payment']));
        $this->assertNull($payment->nextAction());
        $this->assertNull($payment->nextActionExpiresAt());
        $this->assertSame('pending', $expired['paymentStatus']);
        $this->assertSame([], $reducer->events($unchanged, $expired));
        OrderSerializer::normalize($expired);
    }

    public function testActionsBeforeCompletionNotifyOnlyWhenPaymentBecomesPending(): void
    {
        $reconciler = $this->reconciler($this->gateway($this->record('open')));
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $this->actionEvent());
        $this->assertSame('unpaid', $this->data()['paymentStatus']);
        $this->assertSame(['session.created'], array_map(static fn(LifecycleEvent $event): string => $event->type()->value, $this->deliveries()));
        $this->reconciler($this->gateway($this->record()))->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
        $this->assertSame(['session.created', 'payment.pending', 'payment.requiresAction'], array_map(static fn(LifecycleEvent $event): string => $event->type()->value, $this->deliveries()));
    }

    public function testNewerIdenticalActionsPreventOlderDifferentActionsFromReplacingThem(): void
    {
        $reconciler = $this->reconciler($this->gateway($this->record()));
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current');
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $this->actionEvent());
        $expiresAt = Payment::fromArray(OrderData::map($this->data()['payment']))->nextActionExpiresAt();
        $raw = $this->actionEvent()->toArray();
        $raw['id'] = 'evt_newer';
        $raw['created'] = $this->createdAt->getTimestamp() + 120;
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', Event::constructFrom($raw));
        $raw['id'] = 'evt_older';
        $raw['created'] = $this->createdAt->getTimestamp() + 90;
        $object = OrderData::map(OrderData::map($raw['data'])['object']);
        $object['next_action'] = [
            'type' => 'multibanco_display_details',
            'multibanco_display_details' => ['reference' => 'OLDER'],
        ];
        $raw['data'] = ['object' => $object];
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', Event::constructFrom($raw));
        $payment = Payment::fromArray(OrderData::map($this->data()['payment']));
        $this->assertSame('123456789', $payment->nextAction()?->details()['reference']);
        $this->assertSame($this->createdAt->getTimestamp() + 120, $payment->nextActionObservedAt());
        $this->assertEquals($expiresAt, $payment->nextActionExpiresAt());
        $this->assertSame(['session.created', 'payment.pending', 'payment.requiresAction'], array_map(static fn(LifecycleEvent $event): string => $event->type()->value, $this->deliveries()));

        $raw['id'] = 'evt_changed';
        $raw['created'] = $this->createdAt->getTimestamp() + 180;
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', Event::constructFrom($raw));
        $this->assertSame('OLDER', Payment::fromArray(OrderData::map($this->data()['payment']))->nextAction()?->details()['reference']);
        $this->assertCount(4, $this->deliveries());
        $this->assertSame('payment.requiresAction', $this->deliveries()[3]->type()->value);
    }

    public function testCommitsReturnedCustomerCapabilitiesTogetherWithPaymentAndFrozenHookFacts(): void
    {
        $source = [
            'customer' => 'cus_buyer',
            'customer_details' => [
                'email' => 'buyer@example.com',
                'individual_name' => 'Buyer',
                'tax_ids' => [['type' => 'eu_vat', 'value' => 'PT123456789']],
                'address' => ['country' => 'PT', 'city' => 'Porto', 'line1' => 'Rua Um'],
            ],
            'consent' => ['terms_of_service' => 'accepted'],
            'custom_fields' => [[
                'key' => 'reference',
                'type' => 'text',
                'label' => ['type' => 'custom', 'custom' => 'Reference'],
                'optional' => false,
                'text' => ['value' => 'Buyer reference'],
            ]],
        ];
        $page = $this->reconciler($this->gateway($this->record('complete', 'paid', 'succeeded', source: $source)))->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
        $data = $this->data($page);
        $snapshot = $this->deliveries()[1]->orderSnapshot();
        $this->assertSame('paid', $data['paymentStatus']);
        $this->assertSame('cus_buyer', $data['stripeCustomerId']);
        $this->assertSame('buyer@example.com', OrderData::map($data['customer'])['email']);
        $this->assertSame('PT', OrderData::map($data['billingAddress'])['country']);
        $this->assertSame('accepted', OrderData::map($data['consent'])['termsOfService']);
        $this->assertSame('Buyer reference', OrderData::map(OrderData::list($data['customFields'])[0])['value']);
        $this->assertEquals($data['customer'], $snapshot['customer']);
        $this->assertEquals($data['customFields'], $snapshot['customFields']);
        $this->assertEquals($data['lineItems'], $snapshot['lineItems']);
        $this->assertArrayNotHasKey('events', $snapshot);

        // Explicit provider absence replaces the previous capability facts in the same canonical commit.
        $this->reconciler($this->gateway($this->record('complete', 'paid', 'succeeded')))->reconcile($this->order->pageUuid(), 'cs_current');
        $this->assertArrayNotHasKey('customer', $this->data());
        $this->assertSame([], $this->data()['customFields']);
        $this->assertSame('buyer@example.com', OrderData::map($this->deliveries()[1]->orderSnapshot()['customer'])['email']);
    }

    public function testInvalidCompleteObservationLeavesAllCanonicalFactsUnchanged(): void
    {
        $this->reconciler($this->gateway($this->record()))->reconcile($this->order->pageUuid(), 'cs_current');
        $before = $this->data();
        $record = $this->record('complete', 'paid', 'succeeded', source: ['customer_details' => ['email' => 'changed@example.com']]);
        $broken = new CheckoutSessionReconciliationRecord($record->session, [], $record->paymentSource, null);

        try {
            $this->reconciler($this->gateway($broken))->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
            $this->fail('Missing line items must prevent the entire canonical commit.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame(CheckoutErrorCode::SESSION_INCOMPATIBLE, $error->errorCode());
        }

        $after = $this->data();
        unset($after['events']);
        $this->assertSame($before, $after);
        $this->assertSame('failed', $this->entries('events')[0]['status']);
        $this->assertCount(2, $this->deliveries());
    }

    public function testAuthoritativePaymentCanRepairFailureWithoutErasingItsHistory(): void
    {
        $this->reconciler($this->gateway($this->record('complete', 'unpaid', 'requires_payment_method')))->reconcile($this->order->pageUuid(), 'cs_current');
        $failedAt = $this->data()['paymentFailedAt'];
        $this->reconciler($this->gateway($this->record('complete', 'paid', 'succeeded')))->reconcile($this->order->pageUuid(), 'cs_current');
        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertSame($failedAt, $this->data()['paymentFailedAt']);
        $this->assertSame(['session.created', 'payment.failed', 'payment.succeeded'], array_map(static fn(LifecycleEvent $event): string => $event->type()->value, $this->deliveries()));
    }

    public function testCorrelatedSessionRepairsClosedCreationWithoutAnotherPost(): void
    {
        $failedAt = OrderData::timestamp(new DateTimeImmutable());
        $this->store->update($this->order->pageUuid(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'creation_failed',
            'creationFailedAt' => $failedAt,
        ]);
        $gateway = $this->gateway($this->record('complete', 'paid', 'succeeded'));
        $this->reconciler($gateway)->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
        $this->assertSame('complete', $this->data()['checkoutStatus']);
        $this->assertSame($failedAt, $this->data()['creationFailedAt']);
        $this->assertSame([], $gateway->requests);
    }

    public function testFailedNativeCommitLeavesTheEventRetryableAndDispatchesNoTransition(): void
    {
        $record = $this->record('complete', 'paid', 'succeeded');
        /** @var Closure(App, ModelWithContent): Storage $nativeStorage */
        $nativeStorage = $this->kirby->component('storage');
        $gateway = $this->createMock(CheckoutSessionGatewayInterface::class);
        $gateway->method('retrieveForReconciliation')->willReturnCallback(function () use ($record, $nativeStorage): CheckoutSessionReconciliationRecord {
            // Fail only the canonical write, after the Event attempt was persisted and the complete provider read succeeded.
            $this->kirby->extend(['components' => ['storage' => function (App $kirby, ModelWithContent $model) use ($nativeStorage): Storage {
                if ($model instanceof OrderPage === false) {
                    return $nativeStorage($kirby, $model);
                }

                return new class ($model) extends PlainTextStorage {
                    protected function write(VersionId $versionId, Language $language, array $fields): void
                    {
                        throw new RuntimeException('SECRET_CANARY');
                    }
                };
            }]]);

            return $record;
        });
        $reconciler = new CheckoutSessionReconciler($this->store, new CheckoutSessionRetriever($gateway), CredentialMode::Test);

        try {
            $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
            $this->fail('A failed native write cannot acknowledge canonical processing.');
        } catch (OrderStorageException $error) {
            $this->assertSame(PersistenceErrorCode::WRITE_FAILED, $error->errorCode());
        } finally {
            $this->kirby->extend(['components' => ['storage' => $nativeStorage]]);
        }

        $this->assertSame('creating', $this->data()['checkoutStatus']);
        $this->assertSame('pending', $this->entries('events')[0]['status']);
        $this->assertSame([], $this->deliveries());
        $this->reconciler($this->gateway($record))->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertSame(2, $this->entries('events')[0]['attempts']);
        $this->assertCount(2, $this->deliveries());
    }

    public function testFailureRecordsSanitizedEvidenceAndRetryUsesTheSameEventIdentity(): void
    {
        $gateway = $this->gateway($this->record());
        $gateway->retrievalFailure = new CheckoutSessionGatewayException(
            new CheckoutSessionFailure(CheckoutSessionFailureType::Unavailable, true),
            new RuntimeException('SECRET_CANARY'),
        );
        $reconciler = $this->reconciler($gateway);

        try {
            $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
            $this->fail('Provider failure must not commit payment facts.');
        } catch (CheckoutSessionException $error) {
            $this->assertTrue($error->isRetryable());
        }

        $data = $this->data();
        $this->assertSame('creating', $data['checkoutStatus']);
        $this->assertArrayNotHasKey('payment', $data);
        $this->assertSame('failed', $this->entries('events')[0]['status']);
        $this->assertSame(CheckoutErrorCode::SESSION_UNAVAILABLE, $this->entries('events')[0]['errorCode']);
        $this->assertStringNotContainsString('SECRET_CANARY', json_encode($data, JSON_THROW_ON_ERROR));
        $gateway->retrievalFailure = null;
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertSame(2, $this->entries('events')[0]['attempts']);
        $this->assertSame('pending', $this->data()['paymentStatus']);
    }

    public function testForeignEventsCannotWriteOrderEvidence(): void
    {
        $before = $this->data();
        $raw = $this->event()->toArray();
        $object = OrderData::map(OrderData::map($raw['data'])['object']);
        $object['metadata'] = [
            ...$this->metadata(),
            PluginMetadata::ORDER_KEY => 'page://other',
        ];
        $raw['data'] = ['object' => $object];
        $event = Event::constructFrom($raw);

        try {
            $this->reconciler($this->gateway($this->record()))->reconcile($this->order->pageUuid(), 'cs_current', $event);
            $this->fail('Foreign Event must be rejected.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame(CheckoutErrorCode::SESSION_INCOMPATIBLE, $error->errorCode());
        }

        $this->assertSame($before, $this->data());
    }

    public function testObserverFailureCannotRollbackPaymentOrTurnTheStripeEventIntoAFailure(): void
    {
        $this->kirby->extend(['hooks' => [
            'programmatordev.stripe-checkout.payment.succeeded' => function (): void {
                throw new RuntimeException('SECRET_CANARY');
            },
        ]]);
        $this->reconciler($this->gateway($this->record('complete', 'paid', 'succeeded')))->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
        $data = $this->data();
        $this->assertSame('paid', $data['paymentStatus']);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertSame('failed', $this->entries('lifecycleDeliveries')[2]['status']);
        $this->assertStringNotContainsString('SECRET_CANARY', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testRuntimeAssemblyDoesNotRequireCurrentStorefrontSettings(): void
    {
        $this->kirby->extend(['options' => [
            'programmatordev.stripe-checkout' => [
                'stripe' => ['secretKey' => 'sk_test_reconciliation'],
                'settings' => ['currency' => 'INVALID'],
                'products' => ['resolver' => 'INVALID'],
            ],
        ]]);
        $before = $this->data();
        (new RuntimeFactory($this->kirby))->checkoutSessionReconciler();
        $this->assertSame($before, $this->data());
    }

    public function testRepeatsAReadWhenAnotherWriterCommittedDuringRetrieval(): void
    {
        $paid = $this->record('complete', 'paid', 'succeeded');
        $stale = $this->record();
        $reads = 0;
        $gateway = $this->createMock(CheckoutSessionGatewayInterface::class);
        $gateway->method('retrieveForReconciliation')->willReturnCallback(function () use (&$reads, $paid, $stale): CheckoutSessionReconciliationRecord {
            $reads++;

            if ($reads === 1) {
                $this->reconciler($this->gateway($paid))->reconcile($this->order->pageUuid(), 'cs_current');

                return $stale;
            }

            return $paid;
        });
        $reconciler = new CheckoutSessionReconciler($this->store, new CheckoutSessionRetriever($gateway), CredentialMode::Test);
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
        $this->assertSame(2, $reads);
        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertCount(2, $this->deliveries());
        $this->assertSame('processed', $this->entries('events')[0]['status']);
    }

    public function testCurrentReadThenWebhookUsesOneTransitionAndNoInventedTrigger(): void
    {
        $reconciler = $this->reconciler($this->gateway($this->record('complete', 'paid', 'succeeded')));
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current');
        $this->assertNull($this->deliveries()[1]->triggerType());
        $this->assertArrayNotHasKey('events', $this->data());
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current');
        $this->assertCount(2, $this->deliveries());
        $this->assertCount(1, $this->entries('events'));
    }

    public function testCompletedFreeCheckoutHasNoInventedPaymentIntent(): void
    {
        // The saved purchase itself must be free, not just a contradictory zero returned by Stripe.
        $line = OrderFixture::lineItemData();
        $line['requiresShipping'] = false;
        $line['price'] = '0';
        $line['subtotal'] = '0';
        $line['providerAmounts'] = [
            'price' => 0,
            'subtotal' => 0,
        ];
        $freeOrder = new OrderCreationContext(
            uuid: 'reconciliationfree',
            orderNumber: 'ORD-FREE',
            checkoutSource: $this->order->checkoutSource(),
            cartRevision: 'revision',
            userUuid: null,
            languageCode: null,
            uiMode: $this->order->uiMode(),
            currency: 'EUR',
            lineItems: [OrderLineItemSnapshot::fromArray($line)],
        );
        $parameters = $this->request->parameters();
        $metadata = [
            ...$this->metadata(),
            PluginMetadata::ORDER_KEY => $freeOrder->pageUuid(),
        ];
        $parameters = [
            ...$parameters,
            'client_reference_id' => $freeOrder->pageUuid(),
            'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata],
            'line_items' => [[
                'quantity' => 2,
                'metadata' => [
                    ...$metadata,
                    PluginMetadata::LINE_KEY => 'line_0',
                ],
            ]],
        ];
        $request = new SessionRequest($parameters);
        $this->store->create($freeOrder, CheckoutAttemptFactory::create($freeOrder, $this->createdAt, request: $request), $this->createdAt);
        $record = $this->record('complete', 'no_payment_required');
        $session = $record->session;
        $freeSession = new CheckoutSessionRecord(
            id: 'cs_current',
            createdAt: $session->createdAt,
            expiresAt: $session->expiresAt,
            status: 'complete',
            paymentStatus: 'no_payment_required',
            liveMode: false,
            mode: 'payment',
            uiMode: $session->uiMode,
            currency: 'eur',
            clientReferenceId: $freeOrder->pageUuid(),
            integrationIdentifier: $session->integrationIdentifier,
            metadata: $metadata,
            requestId: null,
            url: null,
            clientSecret: null,
            orderSnapshotSource: $session->orderSnapshotSource,
            shippingOptions: [],
            amountSubtotal: 0,
            amountTotal: 0,
        );
        $lines = $record->lineItems;
        $lines[0]['metadata'] = [
            ...$metadata,
            PluginMetadata::LINE_KEY => 'line_0',
        ];
        $lines[0]['price'] = array_replace(OrderData::map($lines[0]['price']), ['unit_amount' => 0]);
        $lines[0]['amount_subtotal'] = 0;
        $lines[0]['amount_total'] = 0;
        $page = $this->reconciler($this->gateway(new CheckoutSessionReconciliationRecord($freeSession, $lines, null, null)))->reconcile($freeOrder->pageUuid(), 'cs_current');
        $data = $this->data($page);
        $this->assertSame('no_payment_required', $data['paymentStatus']);
        $this->assertSame('0', $data['total']);
        $this->assertNull(Payment::fromArray(OrderData::map($data['payment']))->stripePaymentIntentId());
        $this->assertNull(Payment::fromArray(OrderData::map($data['payment']))->amount());
    }

    /** @return array<string, mixed> */
    private function data(?OrderPage $page = null): array
    {
        return $this->store->data($page ?? $this->store->order($this->order->pageUuid()) ?? $this->fail('Order is missing.'));
    }

    /** @return list<array<string, mixed>> */
    private function entries(string $field): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = $this->data()[$field] ?? [];

        return $entries;
    }

    /** @return list<LifecycleEvent> */
    private function deliveries(): array
    {
        return $this->delivered;
    }

    private function reconciler(FakeCheckoutSessionGateway $gateway): CheckoutSessionReconciler
    {
        return new CheckoutSessionReconciler($this->store, new CheckoutSessionRetriever($gateway), CredentialMode::Test);
    }

    private function gateway(CheckoutSessionReconciliationRecord $record): FakeCheckoutSessionGateway
    {
        return new FakeCheckoutSessionGateway(reconciliationResults: ['cs_current' => $record]);
    }

    private function event(string $type = 'checkout.session.completed', string $id = 'evt_first'): Event
    {
        return Event::constructFrom([
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'livemode' => false,
            'created' => $this->createdAt->getTimestamp() + 60,
            'data' => ['object' => [
                'id' => 'cs_current',
                'object' => 'checkout.session',
                'livemode' => false,
                'metadata' => $this->metadata(),
                'client_reference_id' => $this->order->pageUuid(),
            ]],
        ]);
    }

    private function actionEvent(): Event
    {
        $event = $this->event('payment_intent.requires_action', 'evt_action')->toArray();
        $object = [
            'id' => 'pi_current',
            'object' => 'payment_intent',
            'status' => 'requires_action',
            'livemode' => false,
            'currency' => 'eur',
            'metadata' => $this->metadata(),
            'client_secret' => 'SECRET_CANARY',
            'next_action' => [
                'type' => 'multibanco_display_details',
                'multibanco_display_details' => [
                    'entity' => '12345',
                    'reference' => '123456789',
                    'expires_at' => $this->createdAt->getTimestamp() + 86400,
                    'hosted_voucher_url' => 'https://payments.stripe.com/test/voucher',
                ],
            ],
        ];

        $event['data'] = ['object' => $object];

        return Event::constructFrom($event);
    }

    /** @return array<string, string> */
    private function metadata(): array
    {
        return [
            PluginMetadata::OWNER_KEY => PluginMetadata::NAME,
            PluginMetadata::ORDER_KEY => $this->order->pageUuid(),
        ];
    }

    /** @param array<string, mixed> $source */
    private function record(string $status = 'complete', string $sessionPayment = 'unpaid', string $intentStatus = 'processing', ?PaymentAction $action = null, array $source = []): CheckoutSessionReconciliationRecord
    {
        $payment = [
            'id' => 'pi_current',
            'object' => 'payment_intent',
            'created' => $this->createdAt->getTimestamp(),
            'livemode' => false,
            'metadata' => $this->metadata(),
            'currency' => 'eur',
            'amount' => 3200,
            'amount_received' => $sessionPayment === 'paid' ? 3200 : 0,
            'capture_method' => 'automatic',
            'status' => $intentStatus,
            'payment_method' => [
                'id' => 'pm_current',
                'object' => 'payment_method',
                'type' => 'future_method',
            ],
            'latest_charge' => null,
        ];
        $source = [
            'automatic_tax' => ['enabled' => false],
            'total_details' => [
                'amount_discount' => 0,
                'amount_shipping' => 0,
                'amount_tax' => 0,
                'breakdown' => ['discounts' => []],
            ],
            'custom_fields' => [],
            ...$source,
        ];
        $session = new CheckoutSessionRecord(
            id: 'cs_current',
            createdAt: $this->createdAt->getTimestamp(),
            expiresAt: $this->createdAt->getTimestamp() + 86400,
            status: $status,
            paymentStatus: $sessionPayment,
            liveMode: false,
            mode: 'payment',
            uiMode: 'hosted_page',
            currency: 'eur',
            clientReferenceId: $this->order->pageUuid(),
            integrationIdentifier: 'kirby_stripe_checkout_abcdefgh',
            metadata: $this->metadata(),
            requestId: 'req_read',
            url: null,
            clientSecret: null,
            orderSnapshotSource: $source,
            shippingOptions: [],
            amountSubtotal: 3200,
            amountTotal: 3200,
            invoiceId: 'in_current',
        );
        $line = [
            'id' => 'li_first',
            'object' => 'item',
            'metadata' => [
                ...$this->metadata(),
                PluginMetadata::LINE_KEY => 'line_0',
            ],
            'quantity' => 2,
            'currency' => 'eur',
            'description' => 'T-shirt',
            'amount_subtotal' => 3200,
            'amount_discount' => 0,
            'amount_tax' => 0,
            'amount_total' => 3200,
            'price' => [
                'id' => 'price_current',
                'object' => 'price',
                'currency' => 'eur',
                'unit_amount' => 1600,
                'product' => 'prod_current',
                'billing_scheme' => 'per_unit',
                'type' => 'one_time',
            ],
            'discounts' => [],
            'taxes' => [],
        ];

        return new CheckoutSessionReconciliationRecord($session, [$line], $payment, $action);
    }
}
