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
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSessionRetriever;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\DisputeRetriever;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ReconciliationOutcome;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\RefundRetriever;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Kirby\OrderHookDispatcher;
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
use ProgrammatorDev\StripeCheckout\Stripe\Dispute\DisputeGatewayInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Refund\RefundGatewayInterface;
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
        $this->assertNull(Payment::fromArray(OrderData::map($this->data()['payment']))->nextAction());
        $this->assertArrayNotHasKey('nextAction', OrderData::map($actionEvent->orderSnapshot()['payment']));

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
        $this->assertNull($payment->nextAction());
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

    public function testActionOnlyReadsCreateDeliveriesWithoutChangingCanonicalPaymentFacts(): void
    {
        $action = PaymentAction::fromArray([
            'type' => 'future_action',
            'future_action' => ['reference' => 'example'],
        ]);
        $this->reconciler($this->gateway($this->record()))->reconcile($this->order->pageUuid(), 'cs_current');
        $before = $this->data();
        unset($before['lifecycleDeliveries']);
        $reconciler = $this->reconciler($this->gateway($this->record(action: $action)));
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current');
        $captured = $this->data();
        $after = $captured;
        unset($after['lifecycleDeliveries']);
        $this->assertSame($before, $after);
        $this->assertCount(3, $this->deliveries());
        $this->assertSame('example', $this->deliveries()[2]->payment()?->nextAction()?->details()['reference']);

        $reconciler->reconcile($this->order->pageUuid(), 'cs_current');
        $this->assertSame($captured, $this->data());
        $this->assertCount(3, $this->deliveries());
    }

    public function testActionsBeforeCompletionNotifyImmediatelyAndPendingDoesNotRepeatThem(): void
    {
        $gateway = $this->gateway($this->record('open'));
        $reconciler = $this->reconciler($gateway);
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $this->actionEvent());
        $this->assertCount(1, $gateway->reconciliationRetrievals);
        $this->assertSame('unpaid', $this->data()['paymentStatus']);
        $this->assertSame(['session.created', 'payment.requiresAction'], array_map(static fn(LifecycleEvent $event): string => $event->type()->value, $this->deliveries()));
        $this->assertSame('unpaid', $this->deliveries()[1]->paymentStatus()->value);
        $this->assertSame('open', $this->deliveries()[1]->checkoutStatus()->value);
        $this->assertSame('123456789', $this->deliveries()[1]->payment()?->nextAction()?->details()['reference']);
        $this->reconciler($this->gateway($this->record()))->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
        $this->assertSame(['session.created', 'payment.requiresAction', 'payment.pending'], array_map(static fn(LifecycleEvent $event): string => $event->type()->value, $this->deliveries()));
        $this->assertNull($this->deliveries()[2]->payment()?->nextAction());
    }

    public function testActionDeduplicationUsesEventIdentityAndFingerprintNotTimestampOrdering(): void
    {
        $reconciler = $this->reconciler($this->gateway($this->record()));
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current');
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $this->actionEvent());
        $raw = $this->actionEvent()->toArray();
        $raw['id'] = 'evt_identical';
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', Event::constructFrom($raw));
        $this->assertCount(3, $this->deliveries());
        $raw['id'] = 'evt_changed';
        $object = OrderData::map(OrderData::map($raw['data'])['object']);
        $object['next_action'] = [
            'type' => 'multibanco_display_details',
            'multibanco_display_details' => ['reference' => 'CHANGED'],
        ];
        $raw['data'] = ['object' => $object];
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', Event::constructFrom($raw));
        $payment = Payment::fromArray(OrderData::map($this->data()['payment']));
        $this->assertNull($payment->nextAction());
        $this->assertSame('123456789', $this->deliveries()[2]->payment()?->nextAction()?->details()['reference']);
        $this->assertSame('CHANGED', $this->deliveries()[3]->payment()?->nextAction()?->details()['reference']);
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', Event::constructFrom($raw));
        $this->assertCount(4, $this->deliveries());
        $this->assertSame('payment.requiresAction', $this->deliveries()[3]->type()->value);

        $raw = $this->actionEvent()->toArray();
        $raw['id'] = 'evt_same_evidence_late';
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', Event::constructFrom($raw));
        $this->assertCount(4, $this->deliveries());
    }

    public function testPrunedActionEvidenceStillSuppressesDuplicatesAndAllowsADifferentAction(): void
    {
        $gateway = $this->gateway($this->record());
        $reconciler = $this->reconciler($gateway);
        $event = $this->actionEvent();
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $event);
        $before = $this->data();
        $actionDelivery = OrderData::map($this->entries('lifecycleDeliveries')[3]);
        $this->store->pruneLifecycleDeliveryPayloads($this->order->pageUuid(), OrderData::date($actionDelivery['expiresAt']));
        $after = $this->data();
        unset($before['lifecycleDeliveries'], $after['lifecycleDeliveries']);
        $this->assertSame($before, $after);
        $this->assertStringNotContainsString('123456789', OrderData::json($this->data()));
        $this->assertCount(3, $this->deliveries());

        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $event);
        $this->assertCount(1, $gateway->reconciliationRetrievals);
        $raw = $event->toArray();
        $raw['id'] = 'evt_identical_after_pruning';
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', Event::constructFrom($raw));
        $this->assertCount(3, $this->deliveries());
        $raw['id'] = 'evt_changed_after_pruning';
        $object = OrderData::map(OrderData::map($raw['data'])['object']);
        $object['next_action'] = [
            'type' => 'multibanco_display_details',
            'multibanco_display_details' => ['reference' => 'CHANGED'],
        ];
        $raw['data'] = ['object' => $object];
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', Event::constructFrom($raw));
        $this->assertCount(4, $this->delivered);
        $this->assertSame(3, $this->deliveries()[3]->revision());
        $this->assertSame('CHANGED', $this->deliveries()[3]->payment()?->nextAction()?->details()['reference']);
        $changedDelivery = OrderData::map($this->entries('lifecycleDeliveries')[4]);
        $this->store->pruneLifecycleDeliveryPayloads($this->order->pageUuid(), OrderData::date($changedDelivery['expiresAt']));
        $raw = $event->toArray();
        $raw['id'] = 'evt_old_evidence_after_second_pruning';
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', Event::constructFrom($raw));
        $this->assertCount(4, $this->deliveries());
    }

    public function testFailedActionDeliveryReplaysItsOwnEvidenceAfterTheOrderIsPaid(): void
    {
        $observed = [];
        $orderStatuses = [];
        $this->kirby->extend(['hooks' => [
            'programmatordev.stripe-checkout.payment.requiresAction' => function (OrderPage $order, LifecycleEvent $lifecycleEvent) use (&$observed, &$orderStatuses): void {
                $observed[] = $lifecycleEvent;
                $orderStatuses[] = $order->content()->toArray()['paymentstatus'];

                if (count($observed) === 1) {
                    throw new RuntimeException('SECRET_CANARY');
                }

            },
        ]]);
        $this->reconciler($this->gateway($this->record()))->reconcile($this->order->pageUuid(), 'cs_current', $this->actionEvent());
        $entry = $this->entries('lifecycleDeliveries')[3];
        $this->assertSame('failed', $entry['status']);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertNull(Payment::fromArray(OrderData::map($this->data()['payment']))->nextAction());

        $this->reconciler($this->gateway($this->record('complete', 'paid', 'succeeded')))->reconcile($this->order->pageUuid(), 'cs_current');
        $before = $this->data();
        $result = (new OrderHookDispatcher($this->kirby))->retryFailed($this->order->pageUuid(), $observed[0]->deliveryId());
        $this->assertTrue($result->isDelivered());
        $after = $this->data();
        unset($before['lifecycleDeliveries'], $after['lifecycleDeliveries']);
        $this->assertSame($before, $after);
        $this->assertCount(2, $observed);
        $this->assertSame(['pending', 'paid'], $orderStatuses);
        $this->assertSame($observed[0]->toArray(), $observed[1]->toArray());
        $this->assertSame('pending', $observed[1]->paymentStatus()->value);
        $this->assertSame('123456789', $observed[1]->payment()?->nextAction()?->details()['reference']);
        $retried = $this->entries('lifecycleDeliveries')[3];
        $this->assertSame('delivered', $retried['status']);
        $this->assertSame($entry['expiresAt'], $retried['expiresAt']);
        $this->assertStringNotContainsString('SECRET_CANARY', json_encode($this->data(), JSON_THROW_ON_ERROR));
    }

    #[DataProvider('deliveryOnlyPaymentFields')]
    public function testCanonicalOrderPaymentRejectsDeliveryOnlyFields(string $field): void
    {
        $this->reconciler($this->gateway($this->record()))->reconcile($this->order->pageUuid(), 'cs_current');
        $data = $this->data();
        $payment = OrderData::map($data['payment']);
        $payment[$field] = null;
        $data['payment'] = $payment;
        $this->expectException(OrderDataException::class);
        OrderSerializer::normalize($data);
    }

    /** @return iterable<string, array{string}> */
    public static function deliveryOnlyPaymentFields(): iterable
    {
        yield 'action' => ['nextAction'];
        yield 'observation watermark' => ['nextActionObservedAt'];
        yield 'action expiry' => ['nextActionExpiresAt'];
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

    public function testConcurrentProcessorCompletingTheSameEventSuppressesLateNotifications(): void
    {
        $event = $this->event();
        $action = PaymentAction::fromArray([
            'type' => 'future_action',
            'future_action' => ['reference' => 'example'],
        ]);
        $record = $this->record(action: $action);
        $gateway = $this->createMock(CheckoutSessionGatewayInterface::class);
        $gateway->expects($this->once())->method('retrieveForReconciliation')->willReturnCallback(function () use ($event, $record): CheckoutSessionReconciliationRecord {
            // Another request processes this same Event while the first request is still retrieving provider data.
            $this->reconciler($this->gateway($this->record()))->reconcile($this->order->pageUuid(), 'cs_current', $event);

            return $record;
        });
        $reconciler = new CheckoutSessionReconciler($this->store, new CheckoutSessionRetriever($gateway), CredentialMode::Test);
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $event);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertSame(['session.created', 'payment.pending'], array_map(static fn(LifecycleEvent $event): string => $event->type()->value, $this->deliveries()));
    }

    public function testEarlyActionCorrelationReadIsRefetchedAfterAConcurrentCommerceChange(): void
    {
        $paid = $this->record(status: 'complete', sessionPayment: 'paid', intentStatus: 'succeeded');
        $open = $this->record(status: 'open');
        $reads = 0;
        $gateway = $this->createMock(CheckoutSessionGatewayInterface::class);
        $gateway->expects($this->exactly(2))->method('retrieveForReconciliation')->willReturnCallback(function () use ($paid, $open, &$reads): CheckoutSessionReconciliationRecord {
            $reads++;

            if ($reads === 1) {
                $this->reconciler($this->gateway($paid))->reconcile($this->order->pageUuid(), 'cs_current');

                return $open;
            }

            return $paid;
        });
        $reconciler = new CheckoutSessionReconciler($this->store, new CheckoutSessionRetriever($gateway), CredentialMode::Test);
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $this->actionEvent());

        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertSame(['session.created', 'payment.succeeded'], array_map(static fn(LifecycleEvent $event): string => $event->type()->value, $this->deliveries()));
    }

    public function testThreeConflictingReadsPreserveCompetingWritesAndLeaveTheEventRetryable(): void
    {
        $this->reconciler($this->gateway($this->record()))->reconcile($this->order->pageUuid(), 'cs_current');
        $paid = $this->record(status: 'complete', sessionPayment: 'paid', intentStatus: 'succeeded');
        $gateway = $this->createMock(CheckoutSessionGatewayInterface::class);
        $reads = 0;
        $gateway->expects($this->exactly(3))->method('retrieveForReconciliation')->willReturnCallback(function () use ($paid, &$reads): CheckoutSessionReconciliationRecord {
            $reads++;
            // Each competing commerce update invalidates the provider read's baseline, independently of Event bookkeeping.
            $this->store->update($this->order->pageUuid(), static fn(array $data): array => [
                ...$data,
                'stripeInvoiceId' => 'in_concurrent_' . $reads,
            ]);

            return $paid;
        });
        $reconciler = new CheckoutSessionReconciler($this->store, new CheckoutSessionRetriever($gateway), CredentialMode::Test);

        try {
            $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
            $this->fail('Unstable commerce facts must stop after three reads.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame(CheckoutErrorCode::RECONCILIATION_CONFLICT, $error->errorCode());
            $this->assertTrue($error->isRetryable());
        }

        $this->assertSame('pending', $this->data()['paymentStatus']);
        $this->assertSame('in_concurrent_3', $this->data()['stripeInvoiceId']);
        $this->assertSame('failed', $this->entries('events')[0]['status']);
        $this->assertSame(CheckoutErrorCode::RECONCILIATION_CONFLICT, $this->entries('events')[0]['errorCode']);
        $this->assertCount(2, $this->deliveries());

        $this->reconciler($this->gateway($paid))->reconcile($this->order->pageUuid(), 'cs_current', $this->event());
        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertSame(2, $this->entries('events')[0]['attempts']);
    }

    public function testLateRetrievalFailureCannotReplaceAConcurrentProcessedEvent(): void
    {
        $event = $this->event();
        $gateway = $this->createMock(CheckoutSessionGatewayInterface::class);
        $gateway->expects($this->once())->method('retrieveForReconciliation')->willReturnCallback(function () use ($event): never {
            $this->reconciler($this->gateway($this->record(status: 'complete', sessionPayment: 'paid', intentStatus: 'succeeded')))
                ->reconcile($this->order->pageUuid(), 'cs_current', $event);

            throw new CheckoutSessionGatewayException(
                new CheckoutSessionFailure(CheckoutSessionFailureType::Unavailable, true),
                new RuntimeException('SECRET_CANARY'),
            );
        });
        $reconciler = new CheckoutSessionReconciler($this->store, new CheckoutSessionRetriever($gateway), CredentialMode::Test);

        try {
            $reconciler->reconcile($this->order->pageUuid(), 'cs_current', $event);
            $this->fail('The failed caller still receives its retrieval error.');
        } catch (CheckoutSessionException $error) {
            $this->assertSame(CheckoutErrorCode::SESSION_UNAVAILABLE, $error->errorCode());
        }

        $this->assertSame('paid', $this->data()['paymentStatus']);
        $this->assertSame('processed', $this->entries('events')[0]['status']);
        $this->assertNull($this->entries('events')[0]['errorCode']);
        $this->assertSame(2, $this->entries('events')[0]['attempts']);
        $this->assertSame(['session.created', 'payment.succeeded'], array_map(static fn(LifecycleEvent $event): string => $event->type()->value, $this->deliveries()));
        $this->assertStringNotContainsString('SECRET_CANARY', OrderData::json($this->data()));
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

    public function testManualOpenCheckoutWithoutPaymentIntentMakesNoFinancialReads(): void
    {
        $record = $this->record('open', 'unpaid');
        $gateway = $this->gateway(new CheckoutSessionReconciliationRecord($record->session, $record->lineItems, null, null));
        $reconciler = $this->reconciler($gateway);
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current');
        $before = $this->data();
        // No financial retriever is supplied: absence of a PaymentIntent requires no list operation.
        $result = $reconciler->reconcileCurrent($this->order->pageUuid());
        $this->assertSame(ReconciliationOutcome::NoChange, $result->outcome());
        $this->assertSame($before, $this->data());
        $this->assertArrayNotHasKey('stripePaymentIntentId', $before);
        $this->assertArrayNotHasKey('events', $before);
    }

    public function testManualActionOnlyDeliveryCountsAsAnUpdateAndIsDeduplicated(): void
    {
        $reconciler = $this->reconciler($this->gateway($this->record('open', 'unpaid', 'requires_action')));
        $reconciler->reconcile($this->order->pageUuid(), 'cs_current');
        $before = $this->data();
        $action = PaymentAction::fromArray([
            'type' => 'future_action',
            'future_action' => ['instruction' => 'Follow provider instructions'],
        ]);
        $reconciler = new CheckoutSessionReconciler(
            orders: $this->store,
            retriever: new CheckoutSessionRetriever($this->gateway($this->record('open', 'unpaid', 'requires_action', $action))),
            credentialMode: CredentialMode::Test,
            refundRetriever: new RefundRetriever($this->createMock(RefundGatewayInterface::class)),
            disputeRetriever: new DisputeRetriever($this->createMock(DisputeGatewayInterface::class)),
        );
        $this->assertSame(ReconciliationOutcome::Updated, $reconciler->reconcileCurrent($this->order->pageUuid())->outcome());
        $after = $this->data();
        $this->assertSame($before['updatedAt'], $after['updatedAt']);
        $this->assertSame($before['payment'], $after['payment']);
        $this->assertArrayNotHasKey('events', $after);
        $this->assertCount(2, $this->deliveries());
        $this->assertSame(ReconciliationOutcome::NoChange, $reconciler->reconcileCurrent($this->order->pageUuid())->outcome());
        $this->assertSame($after, $this->data());
    }

    public function testManualStaleReadWithoutEstablishedPaymentCannotEraseFinancialFacts(): void
    {
        $this->reconciler($this->gateway($this->record('complete', 'paid', 'succeeded')))->reconcile($this->order->pageUuid(), 'cs_current');
        $before = $this->data();
        $record = $this->record('open', 'unpaid');
        $reconciler = $this->reconciler($this->gateway(new CheckoutSessionReconciliationRecord($record->session, $record->lineItems, null, null)));
        $result = $reconciler->reconcileCurrent($this->order->pageUuid());
        $this->assertSame(CheckoutErrorCode::SESSION_INCOMPATIBLE, $result->errorCode());
        $this->assertSame($before, $this->data());
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
        $reconciler = $this->reconciler($this->gateway(new CheckoutSessionReconciliationRecord($freeSession, $lines, null, null)));
        $page = $reconciler->reconcile($freeOrder->pageUuid(), 'cs_current');
        $data = $this->data($page);
        $this->assertSame('no_payment_required', $data['paymentStatus']);
        $this->assertSame('0', $data['total']);
        $this->assertNull(Payment::fromArray(OrderData::map($data['payment']))->stripePaymentIntentId());
        $this->assertNull(Payment::fromArray(OrderData::map($data['payment']))->amount());
        $this->assertSame(ReconciliationOutcome::NoChange, $reconciler->reconcileCurrent($freeOrder->pageUuid())->outcome());
        $this->assertSame($data, $this->data($this->store->order($freeOrder->pageUuid())));
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
