<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Panel\Panel;
use ProgrammatorDev\StripeCheckout\Kirby\PersistenceErrorCode;
use ProgrammatorDev\StripeCheckout\Kirby\PluginPermissions;
use ProgrammatorDev\StripeCheckout\Order\OrderErrorCode;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

final class OperationalRequestsTest extends KirbyTestCase
{
    private const ORDER_ID = 'missingorder';
    private const PATTERN = 'stripe-checkout/orders/(:any)/sync';
    private const PATH = 'stripe-checkout/orders/' . self::ORDER_ID . '/sync';

    public function testOrderSyncRequestIsRegisteredAsAPanelPostRequest(): void
    {
        /** @var array{requests: list<array<string, mixed>>} $area */
        $area = $this->kirby->load()->areas()['stripe-checkout'];
        $requests = $area['requests'];

        $this->assertCount(1, $requests);
        $this->assertSame(self::PATTERN, $requests[0]['pattern']);
        $this->assertSame('POST', $requests[0]['method']);
    }

    public function testOrderSyncRequiresReadAndReconcilePermissions(): void
    {
        $this->restartRequest([
            'orders.read' => true,
            'orders.reconcile' => false,
        ]);

        $response = Panel::router(self::PATH);

        $this->assertNotNull($response);
        $this->assertSame(403, $response->code(), $response->body());
    }

    public function testOrderSyncPermissionDoesNotRevealAnUnreadableOrder(): void
    {
        $this->restartRequest([
            'orders.read' => false,
            'orders.reconcile' => true,
        ]);

        $response = Panel::router(self::PATH);

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

        $response = Panel::router(self::PATH);

        $this->assertNotNull($response);
        $this->assertSame(409, $response->code(), $response->body());
        /** @var array{outcome: string, errorCode: string, retryable: bool} $body */
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('failed', $body['outcome']);
        $this->assertSame(PersistenceErrorCode::ORDER_UNAVAILABLE, $body['errorCode']);
        $this->assertFalse($body['retryable']);
    }

    /** @param array<string, bool> $permissions */
    private function restartRequest(array $permissions): void
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
                'method' => 'POST',
                'url' => 'https://kirby-stripe-checkout.test/panel/' . self::PATH,
            ],
        );
        $this->kirby = $this->environment->app();
    }
}
