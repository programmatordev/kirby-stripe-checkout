<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use DateTimeImmutable;
use Kirby\Panel\Panel;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationErrorCode;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingErrorCode;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\HousekeepingRunLock;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\PersistenceErrorCode;
use ProgrammatorDev\StripeCheckout\Kirby\PluginPermissions;
use ProgrammatorDev\StripeCheckout\Lifecycle\Internal\LifecycleNotification;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleErrorCode;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderNumberFormatter;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Order\OrderErrorCode;
use ProgrammatorDev\StripeCheckout\Test\Support\CheckoutAttemptFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\OrderFixture;
use RuntimeException;

final class OperationalRequestsTest extends KirbyTestCase
{
    private const DELETION_PATTERN = 'stripe-checkout/orders/(:any)';
    private const DELETION_PATH = 'stripe-checkout/orders/' . self::ORDER_ID;
    private const HOUSEKEEPING_PATH = 'stripe-checkout/housekeeping/run';
    private const ORDER_ID = 'abc123def456ghi7';
    private const RETRY_PATTERN = 'stripe-checkout/orders/(:any)/deliveries/(:any)/retry';
    private const SYNC_PATTERN = 'stripe-checkout/orders/(:any)/sync';
    private const SYNC_PATH = 'stripe-checkout/orders/' . self::ORDER_ID . '/sync';

    public function testOperationalRequestsAreRegisteredWithResourceOrientedPaths(): void
    {
        /** @var array{requests: list<array<string, mixed>>} $area */
        $area = $this->kirby->load()->areas()['stripe-checkout'];
        $requests = $area['requests'];

        $this->assertSame([
            ['pattern' => self::SYNC_PATTERN, 'method' => 'POST'],
            ['pattern' => self::RETRY_PATTERN, 'method' => 'POST'],
            ['pattern' => self::DELETION_PATTERN, 'method' => 'DELETE'],
            ['pattern' => self::HOUSEKEEPING_PATH, 'method' => 'POST'],
        ], array_map(static fn(array $request): array => [
            'pattern' => $request['pattern'],
            'method' => $request['method'],
        ], $requests));
    }

    public function testOrderSyncRequiresReadAndReconcilePermissions(): void
    {
        $this->restartRequest([
            'orders.read' => true,
            'orders.reconcile' => false,
        ]);

        $response = Panel::router(self::SYNC_PATH);

        $this->assertNotNull($response);
        $this->assertSame(403, $response->code(), $response->body());
    }

    public function testOrderSyncPermissionDoesNotRevealAnUnreadableOrder(): void
    {
        $this->restartRequest([
            'orders.read' => false,
            'orders.reconcile' => true,
        ]);

        $response = Panel::router(self::SYNC_PATH);

        $this->assertNotNull($response);
        $this->assertSame(403, $response->code(), $response->body());
    }

    public function testOrderSyncRejectsAnInvalidOrderIdAtTheTransportBoundary(): void
    {
        $this->restartRequest([
            'orders.read' => true,
            'orders.reconcile' => true,
        ]);

        $response = Panel::router('stripe-checkout/orders/@/sync');

        $this->assertNotNull($response);
        $this->assertSame(400, $response->code(), $response->body());
        $this->assertSame([
            'code' => 400,
            'status' => 'failed',
            'result' => null,
            'error' => [
                'code' => OrderErrorCode::DATA_INVALID,
                'retryable' => false,
            ],
        ], json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testOrderSyncReturnsTheSafeOperationFailure(): void
    {
        $this->restartRequest([
            'orders.read' => true,
            'orders.reconcile' => true,
        ]);

        $response = Panel::router(self::SYNC_PATH);

        $this->assertNotNull($response);
        $this->assertSame(409, $response->code(), $response->body());
        $this->assertSame([
            'code' => 409,
            'status' => 'failed',
            'result' => null,
            'error' => [
                'code' => PersistenceErrorCode::ORDER_UNAVAILABLE,
                'retryable' => false,
            ],
        ], json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testOrderSyncReturnsASafeConfigurationFailure(): void
    {
        $this->restartRequest(
            permissions: [
                'orders.read' => true,
                'orders.reconcile' => true,
            ],
            pluginOptions: ['stripe' => []],
        );

        $response = Panel::router(self::SYNC_PATH);

        $this->assertNotNull($response);
        $this->assertSame(409, $response->code(), $response->body());
        $this->assertSame([
            'code' => 409,
            'status' => 'failed',
            'result' => null,
            'error' => [
                'code' => ConfigurationErrorCode::CREDENTIAL_MISSING,
                'retryable' => false,
            ],
        ], json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @param array<string, bool> $permissions */
    #[DataProvider('operationalPermissionDenials')]
    public function testOrderOperationsRequireReadAndSpecificActionPermissions(array $permissions, string $path, string $method): void
    {
        $this->restartRequest($permissions, $method);

        $response = Panel::router($path);

        $this->assertNotNull($response);
        $this->assertSame(403, $response->code(), $response->body());
    }

    /** @return iterable<string, array{array<string, bool>, string, string}> */
    public static function operationalPermissionDenials(): iterable
    {
        $retryPath = 'stripe-checkout/orders/' . self::ORDER_ID . '/deliveries/missing-delivery/retry';

        yield 'retry requires order read' => [[
            'orders.read' => false,
            'lifecycle.retry' => true,
        ], $retryPath, 'POST'];
        yield 'retry requires lifecycle permission' => [[
            'orders.read' => true,
            'lifecycle.retry' => false,
        ], $retryPath, 'POST'];
        yield 'deletion requires order read' => [[
            'orders.read' => false,
            'orders.delete' => true,
        ], self::DELETION_PATH, 'DELETE'];
        yield 'deletion requires delete permission' => [[
            'orders.read' => true,
            'orders.delete' => false,
        ], self::DELETION_PATH, 'DELETE'];
    }

    public function testFailedDeliveryCanBeRetriedThroughThePanelRequest(): void
    {
        $calls = 0;
        $this->restartRequest(
            permissions: [
                'orders.read' => true,
                'lifecycle.retry' => true,
            ],
        );
        $order = $this->createOrder(new DateTimeImmutable());
        $store = new OrderPageStore($this->kirby);
        $this->kirby->extend(['hooks' => [
            'programmatordev.stripe-checkout.session.created' => function () use (&$calls): void {
                $calls++;

                if ($calls === 1) {
                    throw new RuntimeException('Intentional listener failure');
                }
            },
        ]]);
        $order = $store->update(
            uuid: $order->uuid()->toString(),
            reduce: static fn(array $data): array => [
                ...$data,
                'checkoutStatus' => 'open',
                'stripeCheckoutSessionId' => 'cs_test_operational_request',
                'stripeShippingRateIds' => [],
                'checkoutOpenedAt' => $data['createdAt'],
            ],
            notifications: [new LifecycleNotification(LifecycleEventType::SessionCreated)],
        );
        $deliveries = OrderData::list($store->data($order)['lifecycleDeliveries']);
        $delivery = null;

        foreach ($deliveries as $candidate) {
            $candidate = OrderData::map($candidate);

            if ($candidate['status'] === 'failed') {
                $delivery = $candidate;
                break;
            }
        }

        $this->assertNotNull($delivery);
        $event = OrderData::map($delivery['event']);
        $deliveryId = OrderData::text($event['deliveryId']);
        $path = 'stripe-checkout/orders/' . self::ORDER_ID . '/deliveries/' . $deliveryId . '/retry';

        $response = Panel::router($path);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->code(), $response->body());
        $this->assertSame([
            'code' => 200,
            'status' => 'success',
            'result' => ['delivered' => true],
            'error' => null,
        ], json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(2, $calls);
        $order = $store->requirePage($order->id());
        $deliveries = OrderData::list($store->data($order)['lifecycleDeliveries']);
        $retriedDelivery = null;

        foreach ($deliveries as $candidate) {
            $candidate = OrderData::map($candidate);
            $candidateEvent = OrderData::map($candidate['event']);

            if ($candidateEvent['deliveryId'] === $deliveryId) {
                $retriedDelivery = $candidate;
                break;
            }
        }

        $this->assertNotNull($retriedDelivery);
        $this->assertSame('delivered', $retriedDelivery['status']);
        $this->assertSame(2, $retriedDelivery['attempts']);
    }

    public function testMissingDeliveryReturnsItsSafeLifecycleFailure(): void
    {
        $this->restartRequest([
            'orders.read' => true,
            'lifecycle.retry' => true,
        ]);
        $order = $this->createOrder(new DateTimeImmutable());
        $path = 'stripe-checkout/orders/' . $order->slug() . '/deliveries/missing-delivery/retry';

        $response = Panel::router($path);

        $this->assertNotNull($response);
        $this->assertSame(409, $response->code(), $response->body());
        $this->assertSame([
            'code' => 409,
            'status' => 'failed',
            'result' => null,
            'error' => [
                'code' => LifecycleErrorCode::DELIVERY_NOT_FOUND,
                'retryable' => false,
            ],
        ], json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testEligibleOrderCanBeDeletedThroughThePanelRequest(): void
    {
        $this->restartRequest([
            'orders.read' => true,
            'orders.delete' => true,
        ], 'DELETE');
        $createdAt = new DateTimeImmutable('-2 days');
        $store = new OrderPageStore($this->kirby);
        $order = $this->createOrder($createdAt);
        $order = $store->update($order->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'creation_failed',
            'creationFailedAt' => $data['createdAt'],
        ]);

        $response = Panel::router(self::DELETION_PATH);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->code(), $response->body());
        $this->assertSame([
            'code' => 200,
            'status' => 'success',
            'result' => ['deleted' => true],
            'error' => null,
        ], json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertNull($store->order($order->uuid()->toString()));
    }

    public function testIneligibleOrderDeletionReturnsAConflictWithoutDeleting(): void
    {
        $this->restartRequest([
            'orders.read' => true,
            'orders.delete' => true,
        ], 'DELETE');
        $store = new OrderPageStore($this->kirby);
        $order = $this->createOrder(new DateTimeImmutable('-2 days'));

        $response = Panel::router(self::DELETION_PATH);

        $this->assertNotNull($response);
        $this->assertSame(409, $response->code(), $response->body());
        $this->assertSame([
            'code' => 409,
            'status' => 'failed',
            'result' => null,
            'error' => [
                'code' => OrderErrorCode::DELETION_NOT_ALLOWED,
                'retryable' => false,
            ],
        ], json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertNotNull($store->order($order->uuid()->toString()));
    }

    public function testHousekeepingRequiresItsSpecificPermission(): void
    {
        $this->restartRequest(['housekeeping.run' => false]);

        $response = Panel::router(self::HOUSEKEEPING_PATH);

        $this->assertNotNull($response);
        $this->assertSame(403, $response->code(), $response->body());
    }

    public function testHousekeepingReturnsTheSanitizedBatchOutcome(): void
    {
        $this->restartRequest(['housekeeping.run' => true]);

        $response = Panel::router(self::HOUSEKEEPING_PATH);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->code(), $response->body());
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([
            'code',
            'status',
            'result',
            'error',
        ], array_keys($body));
        $this->assertIsArray($body['result']);
        /** @var array<string, int|string> $result */
        $result = $body['result'];
        $this->assertSame([
            'attemptedAt',
            'completedAt',
            'selectedOrders',
            'processedOrders',
            'prunedDeliveries',
            'deletedOrders',
            'recoveredOrders',
            'failedOrders',
        ], array_keys($result));
        $this->assertSame('success', $body['status']);
        $this->assertSame(0, $result['selectedOrders']);
        $this->assertSame(0, $result['processedOrders']);
        $this->assertNull($body['error']);
    }

    public function testHousekeepingUsesTheConfiguredBatchSize(): void
    {
        $this->restartRequest(
            permissions: ['housekeeping.run' => true],
            pluginOptions: ['housekeeping' => ['batchSize' => 1]],
        );
        $this->createOrder(new DateTimeImmutable(), self::ORDER_ID);
        $this->createOrder(new DateTimeImmutable(), 'order-second');

        $response = Panel::router(self::HOUSEKEEPING_PATH);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->code(), $response->body());
        /** @var array{result: array{selectedOrders: int, processedOrders: int}} $body */
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $body['result']['selectedOrders']);
        $this->assertSame(1, $body['result']['processedOrders']);
    }

    public function testHousekeepingSharesTheCliRunLock(): void
    {
        $this->restartRequest(['housekeeping.run' => true]);

        $response = HousekeepingRunLock::run(
            $this->kirby,
            static fn() => Panel::router(self::HOUSEKEEPING_PATH),
        );

        $this->assertNotNull($response);
        $this->assertSame(409, $response->code(), $response->body());
        $this->assertSame([
            'code' => 409,
            'status' => 'failed',
            'result' => null,
            'error' => [
                'code' => HousekeepingErrorCode::LOCKED,
                'retryable' => true,
            ],
        ], json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testHousekeepingReturnsASafeConfigurationFailure(): void
    {
        $this->restartRequest(
            permissions: ['housekeeping.run' => true],
            pluginOptions: ['stripe' => []],
        );

        $response = Panel::router(self::HOUSEKEEPING_PATH);

        $this->assertNotNull($response);
        $this->assertSame(409, $response->code(), $response->body());
        $this->assertSame([
            'code' => 409,
            'status' => 'failed',
            'result' => null,
            'error' => [
                'code' => ConfigurationErrorCode::CREDENTIAL_MISSING,
                'retryable' => false,
            ],
        ], json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testHousekeepingReturnsPartialFailuresAsCompletedBatchOutcomes(): void
    {
        $this->restartRequest(['housekeeping.run' => true]);
        $order = $this->createOrder(new DateTimeImmutable('2026-09-01T00:00:00Z'), 'order-corrupt');
        $order->version('latest')->update(['schemaVersion' => '2'], 'default');

        $response = Panel::router(self::HOUSEKEEPING_PATH);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->code(), $response->body());
        /** @var array{status: string, result: array{failedOrders: int}, error: array{code: string, retryable: bool}} $body */
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('partial_failure', $body['status']);
        $this->assertSame(1, $body['result']['failedOrders']);
        $this->assertSame([
            'code' => HousekeepingErrorCode::PARTIAL_FAILURE,
            'retryable' => false,
        ], $body['error']);
    }

    /**
     * @param array<string, bool>  $permissions
     * @param array<string, mixed> $pluginOptions
     */
    private function restartRequest(
        array $permissions,
        string $method = 'POST',
        array $pluginOptions = [],
    ): void {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            options: [
                'programmatordev.stripe-checkout' => [
                    'stripe' => ['secretKey' => 'sk_test_operational_requests'],
                    ...$pluginOptions,
                ],
            ],
            roles: [[
                'name' => 'store-operator',
                'permissions' => [
                    'access' => [
                        'panel' => true,
                        'stripe-checkout' => true,
                    ],
                    PluginPermissions::CATEGORY => $permissions,
                ],
            ]],
            users: [[
                'id' => 'store-operator',
                'email' => 'operator@example.com',
                'role' => 'store-operator',
            ]],
            impersonate: 'store-operator',
            request: [
                'method' => $method,
                'url' => 'https://kirby-stripe-checkout.test/panel/' . self::SYNC_PATH,
            ],
        );
        $this->kirby = $this->environment->app();
    }

    private function createOrder(DateTimeImmutable $createdAt, string $orderId = self::ORDER_ID): OrderPage
    {
        $context = new OrderCreationContext(
            uuid: $orderId,
            orderNumber: (new OrderNumberFormatter())->format($orderId),
            lineItems: [OrderFixture::lineItem()],
            currency: 'EUR',
            checkoutSource: CheckoutSource::Direct,
            cartRevision: null,
            userUuid: null,
            languageCode: null,
            uiMode: UiMode::Hosted,
        );

        return (new OrderPageStore($this->kirby))->create(
            context: $context,
            checkoutAttempt: CheckoutAttemptFactory::create(
                order: $context,
                createdAt: $createdAt,
            ),
            createdAt: $createdAt,
        );
    }
}
