<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use DateTimeImmutable;
use Kirby\Panel\Panel;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
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
        /** @var array{outcome: string, errorCode: string, retryable: bool} $body */
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('failed', $body['outcome']);
        $this->assertSame(OrderErrorCode::DATA_INVALID, $body['errorCode']);
        $this->assertFalse($body['retryable']);
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
        /** @var array{outcome: string, errorCode: string, retryable: bool} $body */
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('failed', $body['outcome']);
        $this->assertSame(PersistenceErrorCode::ORDER_UNAVAILABLE, $body['errorCode']);
        $this->assertFalse($body['retryable']);
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
        /** @var array{delivered: bool, errorCode: string|null} $body */
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($body['delivered']);
        $this->assertNull($body['errorCode']);
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
        /** @var array{delivered: bool, errorCode: string} $body */
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($body['delivered']);
        $this->assertSame(LifecycleErrorCode::DELIVERY_NOT_FOUND, $body['errorCode']);
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
        /** @var array{deleted: bool, errorCode: string|null} $body */
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($body['deleted']);
        $this->assertNull($body['errorCode']);
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
        /** @var array{deleted: bool, errorCode: string} $body */
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($body['deleted']);
        $this->assertSame(OrderErrorCode::DELETION_NOT_ALLOWED, $body['errorCode']);
        $this->assertNotNull($store->order($order->uuid()->toString()));
    }

    /** @param array<string, bool> $permissions */
    private function restartRequest(array $permissions, string $method = 'POST'): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            options: [
                'programmatordev.stripe-checkout' => [
                    'stripe' => ['secretKey' => 'sk_test_operational_requests'],
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

    private function createOrder(DateTimeImmutable $createdAt): OrderPage
    {
        $context = new OrderCreationContext(
            uuid: self::ORDER_ID,
            orderNumber: (new OrderNumberFormatter())->format(self::ORDER_ID),
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
