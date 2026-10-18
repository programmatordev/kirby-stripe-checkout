<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use DateTimeImmutable;
use Kirby\Data\Yaml;
use PHPUnit\Framework\MockObject\MockObject;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSessionReconciler;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutSessionRetriever;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\IncompleteOrderRecovery;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingErrorCode;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingRunStatus;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\HousekeepingRunner;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\HousekeepingStateStore;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\OrderReferenceSelector;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\RetentionPolicy;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionDiscoveryPage;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailure;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailureType;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionGatewayInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Checkout\Exception\CheckoutSessionGatewayException;
use ProgrammatorDev\StripeCheckout\Test\Support\CheckoutAttemptFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\OrderFixture;
use RuntimeException;

final class HousekeepingRunnerTest extends KirbyTestCase
{
    private const CREDENTIAL_FINGERPRINT = 'current-credential';

    public function testProcessesOneSelectedBatchAndContinuesWithTheNextOrder(): void
    {
        $store = new OrderPageStore($this->kirby);
        $createdAt = new DateTimeImmutable('2026-09-01T00:00:00Z');
        $first = $this->createOrder($store, 'order-alpha', $createdAt);
        $second = $this->createOrder($store, 'order-bravo', $createdAt);
        $attemptedAt = new DateTimeImmutable();
        $this->expireDeliveryPayload($store, $first, $attemptedAt);
        $this->expireDeliveryPayload($store, $second, $attemptedAt);
        $gateway = $this->createMock(CheckoutSessionGatewayInterface::class);
        $gateway->expects($this->never())->method('discoverForOrder');
        $runner = $this->runner(
            store: $store,
            gateway: $gateway,
            batchSize: 1,
            settings: ['cleanupIncompleteOrders' => false],
        );

        $firstOutcome = $runner->run()->toArray();
        $this->assertSame(1, $firstOutcome['selectedOrders']);
        $this->assertSame(1, $firstOutcome['prunedDeliveries']);
        $this->assertSame($first->uuid()->toString(), (new HousekeepingStateStore($this->kirby))->read()->continueAfterPageUuid());
        $this->assertNotNull($this->delivery($store, $store->requirePage($first->id()))['payloadPrunedAt']);
        $this->assertNull($this->delivery($store, $store->requirePage($second->id()))['payloadPrunedAt']);

        $secondOutcome = $runner->run()->toArray();
        $this->assertSame(1, $secondOutcome['selectedOrders']);
        $this->assertSame(1, $secondOutcome['prunedDeliveries']);
        $this->assertNull((new HousekeepingStateStore($this->kirby))->read()->continueAfterPageUuid());
        $this->assertNotNull($this->delivery($store, $store->requirePage($second->id()))['payloadPrunedAt']);
    }

    public function testProviderFailureDoesNotBlockIndependentConfirmedDeletion(): void
    {
        $store = new OrderPageStore($this->kirby);
        $createdAt = (new DateTimeImmutable())->modify('-8 days');
        $unresolved = $this->createOrder($store, 'order-alpha', $createdAt);
        $expired = $this->createOrder($store, 'order-bravo', $createdAt);
        $store->update($expired->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'expired',
            'checkoutExpiredAt' => $data['createdAt'],
            'stripeCheckoutSessionId' => 'opaque-session',
            'stripeShippingRateIds' => [],
        ]);
        $gateway = $this->createMock(CheckoutSessionGatewayInterface::class);
        $gateway->expects($this->once())->method('discoverForOrder')->willThrowException(
            new CheckoutSessionGatewayException(
                new CheckoutSessionFailure(CheckoutSessionFailureType::Unavailable, true),
                new RuntimeException('PRIVATE_PROVIDER_FAILURE'),
            ),
        );
        $outcome = $this->runner(store: $store, gateway: $gateway)->run();

        $this->assertSame(HousekeepingRunStatus::PartialFailure, $outcome->status());
        $outcomeData = $outcome->toArray();
        $this->assertSame('partial_failure', $outcomeData['status']);
        $this->assertSame(2, $outcomeData['selectedOrders']);
        $this->assertSame(2, $outcomeData['processedOrders']);
        $this->assertSame(0, $outcomeData['prunedDeliveries']);
        $this->assertSame(1, $outcomeData['deletedOrders']);
        $this->assertSame(0, $outcomeData['recoveredOrders']);
        $this->assertSame(1, $outcomeData['failedOrders']);
        $this->assertSame(HousekeepingErrorCode::PARTIAL_FAILURE, $outcomeData['errorCode']);
        $this->assertNotNull($store->order($unresolved->uuid()->toString()));
        $this->assertNull($store->order($expired->uuid()->toString()));
        $this->assertStringNotContainsString('PRIVATE_PROVIDER_FAILURE', OrderData::json((new HousekeepingStateStore($this->kirby))->read()->toArray()));
    }

    public function testResumesOneDiscoveryPagePerInvocation(): void
    {
        $store = new OrderPageStore($this->kirby);
        $createdAt = (new DateTimeImmutable())->modify('-8 days');
        $order = $this->createOrder($store, 'order-alpha', $createdAt);
        $gateway = $this->createMock(CheckoutSessionGatewayInterface::class);
        $gateway->expects($this->exactly(2))->method('discoverForOrder')->willReturnOnConsecutiveCalls(
            new CheckoutSessionDiscoveryPage([], 'next-session'),
            new CheckoutSessionDiscoveryPage([], null),
        );
        $runner = $this->runner(store: $store, gateway: $gateway);
        $runner->run();
        $progress = (new HousekeepingStateStore($this->kirby))->read()
            ->progress($order->uuid()->toString(), self::CREDENTIAL_FINGERPRINT);
        $this->assertNotNull($progress);
        $this->assertSame('next-session', $progress->startingAfter);
        $this->assertFalse($progress->complete);

        $runner->run();
        $progress = (new HousekeepingStateStore($this->kirby))->read()
            ->progress($order->uuid()->toString(), self::CREDENTIAL_FINGERPRINT);
        $this->assertNotNull($progress);
        $this->assertNull($progress->startingAfter);
        $this->assertTrue($progress->complete);
        $this->assertNull($progress->candidateSessionId);
        $this->assertSame('creating', $store->data($store->requirePage($order->id()))['checkoutStatus']);
    }

    /** @param array<string, mixed> $settings */
    private function runner(
        OrderPageStore $store,
        CheckoutSessionGatewayInterface&MockObject $gateway,
        int $batchSize = 25,
        array $settings = [],
    ): HousekeepingRunner {
        $resolvedSettings = (new ConfigurationResolver())->resolve([
            'programmatordev.stripe-checkout' => ['settings' => $settings],
        ])->configurationOrFail()->settings();
        $retentionPolicy = new RetentionPolicy($resolvedSettings);
        $reconciler = new CheckoutSessionReconciler(
            orders: $store,
            retriever: new CheckoutSessionRetriever($gateway),
            credentialMode: CredentialMode::Test,
        );

        return new HousekeepingRunner(
            kirby: $this->kirby,
            orders: $store,
            orderReferenceSelector: new OrderReferenceSelector($store),
            stateStore: new HousekeepingStateStore($this->kirby),
            retentionPolicy: $retentionPolicy,
            incompleteOrderRecovery: new IncompleteOrderRecovery(
                orders: $store,
                gateway: $gateway,
                reconciler: $reconciler,
                retentionPolicy: $retentionPolicy,
                credentialMode: CredentialMode::Test,
                credentialFingerprint: self::CREDENTIAL_FINGERPRINT,
            ),
            checkoutSessionReconciler: $reconciler,
            batchSize: $batchSize,
            credentialFingerprint: self::CREDENTIAL_FINGERPRINT,
        );
    }

    private function createOrder(OrderPageStore $store, string $uuid, DateTimeImmutable $createdAt): OrderPage
    {
        $context = new OrderCreationContext(
            uuid: $uuid,
            orderNumber: strtoupper($uuid),
            checkoutSource: CheckoutSource::Direct,
            cartRevision: null,
            userUuid: null,
            languageCode: 'en',
            uiMode: UiMode::Hosted,
            currency: 'EUR',
            lineItems: [OrderFixture::lineItem()],
        );

        return $store->create(
            context: $context,
            checkoutAttempt: CheckoutAttemptFactory::create(
                order: $context,
                createdAt: $createdAt,
                guestReference: 'guest-' . $uuid,
            ),
            createdAt: $createdAt,
        );
    }

    /** @return array<string, mixed> */
    private function delivery(OrderPageStore $store, OrderPage $orderPage): array
    {
        return OrderData::map(OrderData::list($store->data($orderPage)['lifecycleDeliveries'])[0] ?? null);
    }

    private function expireDeliveryPayload(OrderPageStore $store, OrderPage $orderPage, DateTimeImmutable $now): void
    {
        $data = $store->data($orderPage);
        $deliveries = OrderData::list($data['lifecycleDeliveries']);
        $delivery = OrderData::map($deliveries[0] ?? null);
        // Seed an already-expired persisted delivery without making the test wait for its retention period.
        $delivery['createdAt'] = OrderData::timestamp($now->modify('-2 days'));
        $delivery['expiresAt'] = OrderData::timestamp($now->modify('-1 day'));
        $delivery['lastAttemptAt'] = OrderData::timestamp($now->modify('-36 hours'));
        $deliveries[0] = $delivery;
        $orderPage->version('latest')->update([
            'lifecycleDeliveries' => Yaml::encode($deliveries),
        ], 'default');
        $store->data($store->requirePage($orderPage->id()));
    }
}
