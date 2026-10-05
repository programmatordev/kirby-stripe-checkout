<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use Closure;
use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Cms\Language;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Content\PlainTextStorage;
use Kirby\Content\Storage;
use Kirby\Content\VersionId;
use Kirby\Data\Data;
use Kirby\Data\Yaml;
use Kirby\Exception\PermissionException;
use Kirby\Uuid\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Diagnostics\LocalDiagnostics;
use ProgrammatorDev\StripeCheckout\Kirby\OrderHookDispatcher;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\PersistenceErrorCode;
use ProgrammatorDev\StripeCheckout\Lifecycle\Internal\LifecycleNotification;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleErrorCode;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEvent;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderNumberFormatter;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer;
use ProgrammatorDev\StripeCheckout\Order\Internal\RetentionPolicy;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Test\Support\CheckoutAttemptFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\TestWorkspace;
use RuntimeException;

final class OrderHookDispatcherTest extends KirbyTestCase
{
    public function testRetentionConfigurationChangesOnlyNewDeliveriesAndCleanupUsesEachSavedDeadline(): void
    {
        $calls = 0;
        $this->restart([
            'programmatordev.stripe-checkout.order.created' => function () use (&$calls): void {
                $calls++;
                throw new RuntimeException('Intentional listener failure');
            },
        ]);
        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $originalDelivery = $this->entries($page)[0];
        $originalDeadline = OrderData::date($originalDelivery['expiresAt']);
        $this->assertEquals(OrderData::date($originalDelivery['createdAt'])->modify('+30 days'), $originalDeadline);

        $this->kirby->extend(['options' => [
            'programmatordev.stripe-checkout.housekeeping.lifecycleDeliveryPayloadRetentionDays' => 90,
        ]]);
        $page = $store->update(
            uuid: $page->uuid()->toString(),
            reduce: static fn(array $data): array => [
                ...$data,
                'checkoutStatus' => 'open',
                'stripeCheckoutSessionId' => 'cs_test',
                'stripeShippingRateIds' => [],
                'checkoutOpenedAt' => $data['createdAt'],
            ],
            notifications: [new LifecycleNotification(LifecycleEventType::SessionCreated)],
        );
        $deliveries = [];

        foreach ($this->entries($page) as $entry) {
            $deliveries[OrderData::string(OrderData::map($entry['event'])['type'])] = $entry;
        }

        $this->assertSame($originalDelivery, $deliveries['order.created']);
        $newDelivery = $deliveries['session.created'];
        $this->assertEquals(OrderData::date($newDelivery['createdAt'])->modify('+90 days'), OrderData::date($newDelivery['expiresAt']));
        $this->assertSame('delivered', $newDelivery['status']);

        $deliveryId = OrderData::string(OrderData::map($originalDelivery['event'])['deliveryId']);
        $dispatcher = new OrderHookDispatcher($this->kirby);
        $dispatcher->dispatch(uuid: $page->uuid()->toString(), deliveryId: $deliveryId, attemptedAt: $originalDeadline->modify('-1 second'));
        $dispatcher->dispatch(uuid: $page->uuid()->toString(), deliveryId: $deliveryId, attemptedAt: $originalDeadline);
        $page = $store->pruneLifecycleDeliveryPayloads($page->uuid()->toString(), $originalDeadline);
        $afterCleanup = [];

        foreach ($this->entries($page) as $entry) {
            $afterCleanup[OrderData::string(OrderData::map($entry['event'])['type'])] = $entry;
        }

        $expiredDelivery = $afterCleanup['order.created'];
        $this->assertSame($originalDelivery['expiresAt'], $expiredDelivery['expiresAt']);
        $this->assertSame('failed', $expiredDelivery['status']);
        $this->assertSame(2, $expiredDelivery['attempts']);
        $this->assertSame(OrderData::timestamp($originalDeadline), $expiredDelivery['payloadPrunedAt']);
        $this->assertArrayNotHasKey('orderSnapshot', OrderData::map($expiredDelivery['event']));
        $this->assertSame($newDelivery, $afterCleanup['session.created']);
        $this->assertSame(2, $calls);
    }

    public function testPayloadCleanupPreservesOrderTimeCacheAndSanitizedDeliveryHistory(): void
    {
        $calls = 0;
        $this->restart([
            'programmatordev.stripe-checkout.order.fields' => fn(): array => ['note' => 'PRIVATE_SNAPSHOT_CANARY'],
            'programmatordev.stripe-checkout.order.created' => function () use (&$calls): void {
                $calls++;
            },
        ], options: ['cache' => ['pages' => ['active' => true]]]);
        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $before = $store->data($page);
        $entry = $this->entries($page)[0];
        $cache = $this->kirby->cache('pages');
        $cache->set('order-summary', 'unchanged');
        $now = OrderData::date($entry['expiresAt']);
        $page = $store->pruneLifecycleDeliveryPayloads($page->uuid()->toString(), $now->modify('-1 second'));
        $this->assertSame($before, $store->data($page));
        $this->assertSame('unchanged', $cache->get('order-summary'));
        $deliveryId = OrderData::string(OrderData::map($entry['event'])['deliveryId']);
        (new OrderHookDispatcher($this->kirby))->dispatch($page->uuid()->toString(), $deliveryId);
        $page = $store->pruneLifecycleDeliveryPayloads($page->uuid()->toString(), $now);
        $after = $store->data($page);
        $pruned = $this->entries($page)[0];
        unset($before['lifecycleDeliveries'], $after['lifecycleDeliveries']);
        $this->assertSame($before, $after);
        $this->assertSame('unchanged', $cache->get('order-summary'));
        $this->assertSame(OrderData::timestamp($now), $pruned['payloadPrunedAt']);
        $this->assertSame('delivered', $pruned['status']);
        $this->assertSame($entry['attempts'], $pruned['attempts']);
        $this->assertSame($entry['expiresAt'], $pruned['expiresAt']);
        $this->assertStringNotContainsString('PRIVATE_SNAPSHOT_CANARY', OrderData::json($pruned));
        $deliveryId = OrderData::string(OrderData::map($pruned['event'])['deliveryId']);
        (new OrderHookDispatcher($this->kirby))->dispatch($page->uuid()->toString(), $deliveryId);
        $page = $store->pruneLifecycleDeliveryPayloads($page->uuid()->toString(), $now->modify('+1 day'));
        $this->assertSame($pruned, $this->entries($page)[0]);
        $this->assertSame(1, $calls);
    }

    public function testExpiredFailedPayloadCanBePrunedWithoutReenablingReplay(): void
    {
        $calls = 0;
        $this->restart([
            'programmatordev.stripe-checkout.order.created' => function () use (&$calls): void {
                $calls++;
                throw new RuntimeException('Intentional listener failure');
            },
        ]);
        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $before = $this->entries($page)[0];
        $deadline = OrderData::date($before['expiresAt']);
        $page = $store->pruneLifecycleDeliveryPayloads($page->uuid()->toString(), $deadline->modify('-1 second'));
        $this->assertSame($before, $this->entries($page)[0]);
        $page = $store->pruneLifecycleDeliveryPayloads($page->uuid()->toString(), $deadline);
        $pruned = $this->entries($page)[0];
        $this->assertSame('failed', $pruned['status']);
        $this->assertSame($before['errorCode'], $pruned['errorCode']);
        $this->assertArrayNotHasKey('orderSnapshot', OrderData::map($pruned['event']));
        $deliveryId = OrderData::string(OrderData::map($pruned['event'])['deliveryId']);
        // Even an earlier injected retry clock cannot reconstruct a removed payload.
        (new OrderHookDispatcher($this->kirby))->dispatch($page->uuid()->toString(), $deliveryId, $deadline->modify('-1 second'));
        $this->assertSame($pruned, $this->entries($store->requirePage($page->id()))[0]);
        $this->assertSame(1, $calls);
    }

    public function testFailedPayloadCleanupPreservesTheOriginalRecordForRetry(): void
    {
        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $before = $store->data($page);
        $deadline = OrderData::date($this->entries($page)[0]['expiresAt']);
        /** @var Closure(App, ModelWithContent): Storage $nativeStorage */
        $nativeStorage = $this->kirby->component('storage');
        $this->kirby->extend(['components' => ['storage' => static function (App $kirby, ModelWithContent $model) use ($nativeStorage): Storage {
            if ($model instanceof OrderPage === false) {
                return $nativeStorage($kirby, $model);
            }

            return new class ($model) extends PlainTextStorage {
                protected function write(VersionId $versionId, Language $language, array $fields): void
                {
                    throw new RuntimeException('PRIVATE_FAILURE_CANARY');
                }
            };
        }]]);

        try {
            $store->pruneLifecycleDeliveryPayloads($page->uuid()->toString(), $deadline);
            $this->fail('A failed cleanup write must remain retryable.');
        } catch (OrderStorageException $error) {
            $this->assertSame(PersistenceErrorCode::WRITE_FAILED, $error->errorCode());
        } finally {
            $this->kirby->extend(['components' => ['storage' => $nativeStorage]]);
        }

        $this->assertSame($before, $store->data($store->requirePage($page->id())));
        $page = $store->pruneLifecycleDeliveryPayloads($page->uuid()->toString(), $deadline);
        $this->assertNotNull($this->entries($page)[0]['payloadPrunedAt']);
    }

    public function testInFlightDeliveryCanRecordSuccessAfterItsExpiredPayloadWasPruned(): void
    {
        $calls = 0;
        $this->restart([
            'programmatordev.stripe-checkout.order.created' => function (OrderPage $order) use (&$calls): void {
                $calls++;

                if ($calls === 1) {
                    throw new RuntimeException('Intentional listener failure');
                }

                $store = new OrderPageStore($order->kirby());
                $entry = OrderData::map(OrderData::list($store->data($order)['lifecycleDeliveries'])[0]);
                // Cleanup can commit while an already admitted listener runs outside the lock.
                $store->pruneLifecycleDeliveryPayloads($order->uuid()->toString(), OrderData::date($entry['expiresAt']));
            },
        ]);
        $page = $this->createOrder();
        $entry = $this->entries($page)[0];
        $deliveryId = OrderData::string(OrderData::map($entry['event'])['deliveryId']);
        $dispatcher = new OrderHookDispatcher($this->kirby);
        $dispatcher->retryFailed($page->uuid()->toString(), $deliveryId);
        $page = (new OrderPageStore($this->kirby))->requirePage($page->id());
        $pruned = $this->entries($page)[0];
        $this->assertSame('delivered', $pruned['status']);
        $this->assertSame(2, $pruned['attempts']);
        $this->assertNull($pruned['errorCode']);
        $this->assertArrayNotHasKey('orderSnapshot', OrderData::map($pruned['event']));
        $this->assertNotNull($pruned['payloadPrunedAt']);
        $dispatcher->retryFailed($page->uuid()->toString(), $deliveryId);
        $this->assertSame(2, $calls);
    }

    public function testExpiredDeliveryCannotInvokeHooksOrExtendItsRetryWindow(): void
    {
        $attempts = 0;
        $this->restart([
            'programmatordev.stripe-checkout.order.created' => function () use (&$attempts): void {
                $attempts++;
                throw new RuntimeException('Intentional listener failure');
            },
        ]);
        $page = $this->createOrder();
        $entry = $this->entries($page)[0];
        $createdAt = OrderData::date($entry['createdAt']);
        $expiresAt = OrderData::date($entry['expiresAt']);
        $this->assertEquals($createdAt->modify('+30 days'), $expiresAt);
        $dispatcher = new OrderHookDispatcher($this->kirby);
        $store = new OrderPageStore($this->kirby);
        $deliveryId = OrderData::string(OrderData::map($entry['event'])['deliveryId']);

        $result = $dispatcher->retryFailed(uuid: $page->uuid()->toString(), deliveryId: $deliveryId, attemptedAt: $expiresAt->modify('-1 second'));
        $this->assertFalse($result->isDelivered());
        $this->assertSame(LifecycleErrorCode::LISTENER_FAILED, $result->errorCode());
        $beforeExpiry = $this->entries($store->requirePage($page->id()))[0];
        $this->assertSame(2, $beforeExpiry['attempts']);
        $this->assertSame($entry['expiresAt'], $beforeExpiry['expiresAt']);

        $this->assertSame(LifecycleErrorCode::DELIVERY_NOT_RETRYABLE, $dispatcher->retryFailed(uuid: $page->uuid()->toString(), deliveryId: $deliveryId, attemptedAt: $expiresAt)->errorCode());
        $dispatcher->retryFailed(uuid: $page->uuid()->toString(), deliveryId: $deliveryId, attemptedAt: $expiresAt->modify('+1 day'));
        $this->assertSame(2, $attempts);
        $this->assertSame($beforeExpiry, $this->entries($store->requirePage($page->id()))[0]);
    }

    public function testBackwardClockMovementDoesNotPreventAnEligibleRetry(): void
    {
        $calls = 0;
        $this->restart([
            'programmatordev.stripe-checkout.order.created' => function () use (&$calls): void {
                $calls++;
                throw new RuntimeException('Intentional listener failure');
            },
        ]);
        $page = $this->createOrder();
        $entry = $this->entries($page)[0];
        $deliveryId = OrderData::string(OrderData::map($entry['event'])['deliveryId']);
        $attemptedAt = OrderData::date($entry['lastAttemptAt'])->modify('+10 seconds');
        $dispatcher = new OrderHookDispatcher($this->kirby);
        $store = new OrderPageStore($this->kirby);
        $dispatcher->retryFailed(uuid: $page->uuid()->toString(), deliveryId: $deliveryId, attemptedAt: $attemptedAt);
        $before = $this->entries($store->requirePage($page->id()))[0];

        $dispatcher->retryFailed(uuid: $page->uuid()->toString(), deliveryId: $deliveryId, attemptedAt: $attemptedAt->modify('-5 seconds'));
        $after = $this->entries($store->requirePage($page->id()))[0];

        $this->assertSame(3, $calls);
        $this->assertSame(3, $after['attempts']);
        $this->assertSame($before['lastAttemptAt'], $after['lastAttemptAt']);
        $this->assertSame($entry['createdAt'], $after['createdAt']);
        $this->assertSame($entry['expiresAt'], $after['expiresAt']);
        $this->assertSame($entry['event'], $after['event']);
        $this->assertSame('failed', $after['status']);
    }

    public function testDeadlineCrossedDuringReloadCannotInvokeTheHook(): void
    {
        $attempts = 0;
        $this->restart([
            'programmatordev.stripe-checkout.order.created' => function () use (&$attempts): void {
                $attempts++;
                throw new RuntimeException('Intentional listener failure');
            },
        ]);
        $page = $this->createOrder();
        $entries = $this->entries($page);
        $expiresAt = OrderData::date(OrderData::timestamp(new DateTimeImmutable('+2 seconds')));
        // A near deadline in disposable storage avoids waiting for the real retention period; normal updates cannot edit it.
        $entries[0]['createdAt'] = OrderData::timestamp($expiresAt->modify('-30 days'));
        $entries[0]['expiresAt'] = OrderData::timestamp($expiresAt);
        $page->version('latest')->update(['lifecycleDeliveries' => Yaml::encode($entries)], 'default');
        /** @var Closure(App, ModelWithContent): Storage $nativeStorage */
        $nativeStorage = $this->kirby->component('storage');
        $delayed = false;
        $this->kirby->extend(['components' => ['storage' => static function (App $kirby, ModelWithContent $model) use ($nativeStorage, $expiresAt, &$delayed): Storage {
            if ($model instanceof OrderPage && $delayed === false) {
                $delayed = true;

                while (new DateTimeImmutable() < $expiresAt) {
                    usleep(10000);
                }
            }

            return $nativeStorage($kirby, $model);
        }]]);
        $this->assertLessThan($expiresAt, new DateTimeImmutable());
        $deliveryId = OrderData::string(OrderData::map($entries[0]['event'])['deliveryId']);
        $result = (new OrderHookDispatcher($this->kirby))->retryFailed($page->uuid()->toString(), $deliveryId);
        $this->assertSame(LifecycleErrorCode::DELIVERY_NOT_RETRYABLE, $result->errorCode());

        $this->assertTrue($delayed);
        $this->assertSame(1, $attempts);
        $this->assertSame($entries, $this->entries((new OrderPageStore($this->kirby))->requirePage($page->id())));
    }

    public function testCreationSnapshotIncludesNativeDefaultsAndBeforeHookEdits(): void
    {
        $observed = null;
        $atWrite = null;
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            hooks: [
                'programmatordev.stripe-checkout.order.fields' => function (): array {
                    return ['note' => 'Initial note'];
                },
                'page.create:before' => function (Page $page): Page {
                    return $page instanceof OrderPage
                        ? $page->clone(['content' => [...$page->content('default')->toArray(), 'note' => 'Native hook note']])
                        : $page;
                },
                'page.create:after' => function (Page $page) use (&$atWrite): void {
                    if ($page instanceof OrderPage) {
                        // Intent must already be persisted before after hooks or lifecycle dispatch, not repaired by a subsequent write.
                        $atWrite = (new OrderPageStore($page->kirby()))->data($page);
                        $page->update(['note' => 'Later edit']);
                    }
                },
                'programmatordev.stripe-checkout.order.created' => function (LifecycleEvent $lifecycleEvent) use (&$observed): void {
                    $observed = $lifecycleEvent;
                },
            ],
            beforeApp: static function (TestWorkspace $workspace): void {
                $workspace->writePageBlueprint('stripe-checkout-order', [
                    'extends' => 'programmatordev/stripe-checkout/pages/order',
                    'tabs' => ['custom' => ['fields' => [
                        'note' => ['type' => 'text'],
                        'source' => [
                            'type' => 'text',
                            'default' => 'Storefront',
                        ],
                    ]]],
                ]);
            },
        );
        $this->kirby = $this->environment->app();
        $page = $this->createOrder();
        $this->assertInstanceOf(LifecycleEvent::class, $observed);
        $this->assertIsArray($atWrite);
        $entry = OrderData::map(OrderData::list($atWrite['lifecycleDeliveries'])[0]);
        $this->assertSame('pending', $entry['status']);
        $this->assertSame(0, $entry['attempts']);
        $this->assertEquals($observed->toArray(), $entry['event']);
        $this->assertSame('Native hook note', $observed->orderSnapshot()['note']);
        $this->assertSame('Storefront', $observed->orderSnapshot()['source']);
        $this->assertSame('Later edit', $page->content('default')->data()['note']);
    }

    public function testBeforeHookCannotChangeCanonicalCreationFacts(): void
    {
        $this->restart([
            'page.create:before' => function (Page $page): Page {
                return $page instanceof OrderPage
                    ? $page->clone(['content' => [...$page->content('default')->toArray(), 'languagecode' => 'pt']])
                    : $page;
            },
        ]);

        try {
            $this->createOrder();
            $this->fail('Expected canonical before-hook changes to be rejected.');
        } catch (OrderStorageException $error) {
            $this->assertSame('persistence.verify_failed', $error->errorCode());
            $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
        }
    }

    public function testNativeAfterCreateFailureDoesNotHideTheCommittedOrder(): void
    {
        $observed = [];
        $this->restart([
            'page.create:after' => function (Page $page): void {
                if ($page instanceof OrderPage) {
                    throw new RuntimeException('Private native callback error');
                }
            },
            'programmatordev.stripe-checkout.order.created' => function (LifecycleEvent $lifecycleEvent) use (&$observed): void {
                $observed[] = $lifecycleEvent;
            },
        ]);
        $page = $this->createOrder();
        $this->assertCount(1, (new OrderPageStore($this->kirby))->orders());
        $this->assertCount(1, $observed);
        $this->assertSame($page->uuid()->toString(), $observed[0]->pageUuid());
        $this->assertSame('delivered', $this->entries($page)[0]['status']);
    }

    /** @param array<string, string> $fields */
    #[DataProvider('postCreationMutations')]
    public function testPostCommitRecoveryStillRejectsChangedCanonicalContent(array $fields, string $errorCode): void
    {
        $observed = false;
        $this->restart([
            'page.create:after' => function (Page $page) use ($fields): void {
                if ($page instanceof OrderPage) {
                    $page->version('latest')->update($fields, 'default');
                    throw new RuntimeException('Private native callback error');
                }
            },
            'programmatordev.stripe-checkout.order.created' => function () use (&$observed): void {
                $observed = true;
            },
        ]);

        try {
            $this->createOrder();
            $this->fail('Expected invalid stored content to be rejected.');
        } catch (OrderStorageException $error) {
            $this->assertSame($errorCode, $error->errorCode());
            $this->assertFalse($observed);
        }
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function postCreationMutations(): iterable
    {
        yield 'invalid content' => [['subtotal' => 'broken'], 'persistence.content_invalid'];
        yield 'valid but changed facts' => [['languagecode' => 'pt'], 'persistence.verify_failed'];
    }

    public function testCreationRecoveryNeverAdoptsACollidingOrder(): void
    {
        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $before = $store->data($page);
        $generator = Uuid::$generator;
        Uuid::$generator = static fn(): string => $page->uuid()->id();

        try {
            $this->createOrder();
            $this->fail('Expected the collision to be rejected.');
        } catch (OrderStorageException $error) {
            $this->assertSame('persistence.write_failed', $error->errorCode());
            $this->assertSame($before, $store->data($store->requirePage($page->id())));
            $this->assertCount(1, $store->orders());
        } finally {
            Uuid::$generator = $generator;
        }
    }

    public function testHookBookkeepingPreservesOrderTimeAndStorefrontCache(): void
    {
        $fail = true;
        $this->restart([
            'programmatordev.stripe-checkout.order.created' => function () use (&$fail): void {
                /** @var bool $fail Changed between delivery attempts. */
                if ($fail) {
                    throw new RuntimeException('Retry later');
                }
            },
        ], options: ['cache' => ['pages' => ['active' => true]]]);
        $store = new OrderPageStore($this->kirby);
        $page = $this->createOrder();
        $before = $store->data($page);
        $entry = $this->entries($page)[0];
        $cache = $this->kirby->cache('pages');
        $cache->set('order-summary', 'unchanged');
        $fail = false;
        $event = OrderData::map($entry['event']);
        (new OrderHookDispatcher($this->kirby))->retryFailed($page->uuid()->toString(), OrderData::text($event['deliveryId']));
        $page = $store->requirePage($page->id());
        $after = $store->data($page);
        $retried = $this->entries($page)[0];
        $this->assertSame('unchanged', $cache->get('order-summary'));
        $this->assertSame($before['updatedAt'], $after['updatedAt']);
        $this->assertSame('delivered', $retried['status']);
        $this->assertSame(2, $retried['attempts']);
        $this->assertSame($entry['event'], $retried['event']);
    }

    public function testCreationHookRunsAfterPersistenceWithoutImpersonationOrWriteLock(): void
    {
        $observed = [];
        $this->restart([
            'programmatordev.stripe-checkout.order.fields' => fn(): array => ['note' => 'Initial'],
            'programmatordev.stripe-checkout.order.created' => function (Page $order, LifecycleEvent $lifecycleEvent) use (&$observed): void {
                $store = new OrderPageStore($order->kirby());
                $observed = [
                    'exists' => $store->order($lifecycleEvent->pageUuid()) !== null,
                    'user' => $order->kirby()->user(),
                    'event' => $lifecycleEvent,
                ];
                $store->updateCustomFields($order->id(), ['note' => 'From hook'], null);
            },
        ]);
        $page = $this->createOrder();
        $entry = $this->entries($page)[0];
        $this->assertTrue($observed['exists']);
        $this->assertNull($observed['user']);
        $this->assertInstanceOf(LifecycleEvent::class, $observed['event']);
        $this->assertSame('Initial', $observed['event']->orderSnapshot()['note']);
        $this->assertSame('From hook', $page->content('default')->data()['note']);
        $this->assertSame('delivered', $entry['status']);
        $this->assertSame(1, $entry['attempts']);
        $this->assertNull($entry['errorCode']);
        $this->assertArrayNotHasKey('lifecycleDeliveries', $observed['event']->orderSnapshot());
    }

    public function testFailedHooksDoNotSuppressOtherOrdersAndRetryKeepsOriginalFacts(): void
    {
        $fail = true;
        $observed = [];
        $this->restart([
            'programmatordev.stripe-checkout.order.created' => function (Page $order, LifecycleEvent $lifecycleEvent) use (&$fail, &$observed): void {
                $observed[] = [$lifecycleEvent->toArray(), $order->content('default')->data()['checkoutstatus']];

                /** @var bool $fail Changed between delivery attempts. */
                if ($fail) {
                    throw new RuntimeException('secret-and-customer-data-must-not-be-saved');
                }
            },
        ]);
        $first = $this->createOrder();
        $second = $this->createOrder();
        $initial = $observed;
        $this->assertCount(2, $initial);
        $entry = $this->entries($first)[0];
        $this->assertSame('failed', $entry['status']);
        $this->assertSame('failed', $this->entries($second)[0]['status']);
        $this->assertStringNotContainsString('secret-and-customer', OrderData::json($entry));
        $checks = array_column((new LocalDiagnostics($this->kirby))->report()['checks'], null, 'id');
        $this->assertSame('2', $checks['lifecycle']['values']['failed']);
        $store = new OrderPageStore($this->kirby);
        $store->update($first->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'open',
            'stripeCheckoutSessionId' => 'cs_test',
            'stripeShippingRateIds' => [],
            'checkoutOpenedAt' => $data['createdAt'],
        ]);
        $fail = false;
        $dispatcher = new OrderHookDispatcher($this->kirby);
        $event = OrderData::map($entry['event']);
        $deliveryId = OrderData::text($event['deliveryId']);
        $this->assertTrue($dispatcher->retryFailed($first->uuid()->toString(), $deliveryId)->isDelivered());
        $this->assertSame(LifecycleErrorCode::DELIVERY_NOT_RETRYABLE, $dispatcher->retryFailed($first->uuid()->toString(), $deliveryId)->errorCode());
        $this->assertCount(3, $observed);
        $this->assertSame($observed[0][0], $observed[2][0]);
        $this->assertSame('open', $observed[2][1]);
        $retried = $this->entries($store->requirePage($first->id()))[0];
        $this->assertSame(2, $retried['attempts']);
        $this->assertSame('delivered', $retried['status']);
        $this->assertSame($entry['event'], $retried['event']);
        $this->assertSame($entry['expiresAt'], $retried['expiresAt']);
    }

    public function testTransitionDeliveryIsAtomicDeduplicatedAndKeepsTriggerContext(): void
    {
        $observed = [];
        $this->restart([
            'programmatordev.stripe-checkout.session.created' => function (LifecycleEvent $lifecycleEvent) use (&$observed): void {
                $observed[] = $lifecycleEvent;
            },
        ]);
        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $reduce = static fn(array $data): array => [
            ...OrderData::map($data),
            'checkoutStatus' => 'open',
            'stripeCheckoutSessionId' => 'cs_test',
            'stripeShippingRateIds' => [],
            'checkoutOpenedAt' => $data['createdAt'],
        ];
        $events = static fn(array $before, array $after): array => isset($before['stripeCheckoutSessionId']) === false && isset($after['stripeCheckoutSessionId'])
            ? [new LifecycleNotification(LifecycleEventType::SessionCreated)] : [];
        $store->update($page->uuid()->toString(), $reduce, $events, 'checkout.session.completed', 'evt_test');
        $page = $store->update($page->uuid()->toString(), $reduce, $events, 'checkout.session.completed', 'evt_test');
        $this->assertCount(1, $observed);
        $this->assertCount(2, $this->entries($page));
        $this->assertSame(2, $observed[0]->revision());
        $this->assertSame('evt_test', $observed[0]->triggerId());
        $this->assertSame('checkout.session.completed', $observed[0]->triggerType());
        $this->expectException(OrderDataException::class);
        $store->update($page->uuid()->toString(), static function (array $data): array {
            unset($data['lifecycleDeliveries']);

            return $data;
        });
    }

    public function testDeliveryActivatesInitiatingLanguageAndRestoresCallerAfterFailure(): void
    {
        $languages = [
            [
                'code' => 'en',
                'name' => 'English',
                'default' => true,
                'locale' => 'en_US',
            ],
            [
                'code' => 'pt',
                'name' => 'Português',
                'locale' => 'pt_PT',
            ],
        ];
        $observed = [];
        $fail = true;
        $this->restart([
            'programmatordev.stripe-checkout.order.created' => function (Page $order) use (&$observed, &$fail): void {
                $observed[] = $order->kirby()->languageCode();

                /** @var bool $fail Changed between delivery attempts. */
                if ($fail) {
                    throw new RuntimeException('Failed');
                }
            },
        ], $languages);
        $this->kirby->setCurrentLanguage('en');
        $page = $this->createOrder('pt');
        $this->assertSame('en', $this->kirby->languageCode());
        $entry = $this->entries($page)[0];
        $event = OrderData::map($entry['event']);
        (new OrderHookDispatcher($this->kirby))->retryFailed($page->uuid()->toString(), OrderData::text($event['deliveryId']));
        $initialLanguages = $observed;
        $this->assertSame(['pt', 'pt'], $initialLanguages);
        $this->assertSame('en', $this->kirby->languageCode());
        $this->assertNull($page->version('latest')->read('pt'));
        $this->kirby->languages(false)->remove('pt');
        $fail = false;
        $result = (new OrderHookDispatcher($this->kirby))->retryFailed($page->uuid()->toString(), OrderData::text($event['deliveryId']));
        $this->assertTrue($result->isDelivered());
        $this->assertSame(['pt', 'pt', 'en'], $observed);
        $this->assertSame('en', $this->kirby->languageCode());
    }

    public function testManualRetryRejectsPendingDeliveredMissingAndPrunedDeliveriesWithoutChangingHistory(): void
    {
        $pending = [];
        $this->restart([
            'page.create:after' => function (Page $page) use (&$pending): void {
                if ($page instanceof OrderPage) {
                    $pending = (new OrderPageStore($page->kirby()))->data($page)['lifecycleDeliveries'];
                }
            },
        ]);
        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $dispatcher = new OrderHookDispatcher($this->kirby);
        $entry = $this->entries($page)[0];
        $deliveryId = OrderData::string(OrderData::map($entry['event'])['deliveryId']);
        $this->assertSame(LifecycleErrorCode::DELIVERY_NOT_RETRYABLE, $dispatcher->retryFailed($page->uuid()->toString(), $deliveryId)->errorCode());
        $this->assertSame(LifecycleErrorCode::DELIVERY_NOT_FOUND, $dispatcher->retryFailed($page->uuid()->toString(), 'missing-delivery')->errorCode());
        $this->assertSame([$entry], $this->entries($store->requirePage($page->id())));

        // Restore the actual initial intent in disposable storage to represent a process exit before automatic admission.
        $page->version('latest')->update(['lifecycleDeliveries' => Yaml::encode($pending)], 'default');
        $before = $store->data($store->requirePage($page->id()));
        $this->assertSame(LifecycleErrorCode::DELIVERY_NOT_RETRYABLE, $dispatcher->retryFailed($page->uuid()->toString(), $deliveryId)->errorCode());
        $this->assertSame($before, $store->data($store->requirePage($page->id())));
        $dispatcher->dispatch($page->uuid()->toString(), $deliveryId);
        $this->assertSame('delivered', $this->entries($store->requirePage($page->id()))[0]['status']);

        $page = $store->pruneLifecycleDeliveryPayloads($page->uuid()->toString(), OrderData::date($entry['expiresAt']));
        $before = $store->data($page);
        $this->assertSame(LifecycleErrorCode::DELIVERY_NOT_RETRYABLE, $dispatcher->retryFailed($page->uuid()->toString(), $deliveryId)->errorCode());
        $this->assertSame($before, $store->data($store->requirePage($page->id())));
    }

    public function testManualRetryRunsOutsideTheLockAndRejectsRecursiveInvocation(): void
    {
        $calls = 0;
        $recursiveErrorCode = null;
        $user = null;
        $this->restart([
            'programmatordev.stripe-checkout.order.created' => function (OrderPage $order, LifecycleEvent $lifecycleEvent) use (&$calls, &$recursiveErrorCode, &$user): void {
                $calls++;

                if ($calls === 1) {
                    throw new RuntimeException('Initial failure');
                }

                $user = $order->kirby()->user();
                $recursiveErrorCode = (new OrderHookDispatcher($order->kirby()))->retryFailed($order->uuid()->toString(), $lifecycleEvent->deliveryId())->errorCode();
                (new OrderPageStore($order->kirby()))->updateCustomFields($order->id(), ['note' => 'Manual retry'], null);
            },
        ]);
        $page = $this->createOrder();
        $entry = $this->entries($page)[0];
        $deliveryId = OrderData::string(OrderData::map($entry['event'])['deliveryId']);
        $result = (new OrderHookDispatcher($this->kirby))->retryFailed($page->uuid()->toString(), $deliveryId);
        $page = (new OrderPageStore($this->kirby))->requirePage($page->id());
        $this->assertTrue($result->isDelivered());
        $this->assertNull($result->errorCode());
        $this->assertSame(LifecycleErrorCode::DELIVERY_NOT_RETRYABLE, $recursiveErrorCode);
        $this->assertNull($user);
        $this->assertSame(2, $calls);
        $this->assertSame(2, $this->entries($page)[0]['attempts']);
        $this->assertSame('Manual retry', $page->content('default')->data()['note']);
    }

    public function testConcurrentRecordedSuccessWinsOverTheManualListenerFailure(): void
    {
        $calls = 0;
        $this->restart([
            'programmatordev.stripe-checkout.order.created' => function (OrderPage $order, LifecycleEvent $lifecycleEvent) use (&$calls): void {
                $calls++;

                if ($calls === 2) {
                    // Another admitted process can commit its successful outcome while this listener is still running.
                    (new OrderPageStore($order->kirby()))->update($order->uuid()->toString(), static function (array $data) use ($lifecycleEvent): array {
                        /** @var list<array<string, mixed>> $entries */
                        $entries = $data['lifecycleDeliveries'];

                        foreach ($entries as &$entry) {
                            if (OrderData::map($entry['event'])['deliveryId'] === $lifecycleEvent->deliveryId()) {
                                $entry['status'] = 'delivered';
                                $entry['errorCode'] = null;
                            }
                        }

                        $data['lifecycleDeliveries'] = $entries;

                        return $data;
                    });
                }

                throw new RuntimeException('Listener failed');
            },
        ]);
        $page = $this->createOrder();
        $entry = $this->entries($page)[0];
        $deliveryId = OrderData::string(OrderData::map($entry['event'])['deliveryId']);
        $result = (new OrderHookDispatcher($this->kirby))->retryFailed($page->uuid()->toString(), $deliveryId);
        $this->assertTrue($result->isDelivered());
        $this->assertNull($result->errorCode());
        $page = (new OrderPageStore($this->kirby))->requirePage($page->id());
        $this->assertSame('delivered', $this->entries($page)[0]['status']);
        $this->assertNull($this->entries($page)[0]['errorCode']);
    }

    #[DataProvider('retryWriteFailures')]
    public function testManualRetryReportsStorageFailureWithoutClaimingRecordedSuccess(bool $failAfterInvocation, int $expectedCalls, int $expectedAttempts): void
    {
        $calls = 0;
        $this->restart([
            'programmatordev.stripe-checkout.order.created' => function () use (&$calls): void {
                $calls++;

                if ($calls === 1) {
                    throw new RuntimeException('Initial failure');
                }
            },
        ]);
        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $entry = $this->entries($page)[0];
        $deliveryId = OrderData::string(OrderData::map($entry['event'])['deliveryId']);
        /** @var Closure(App, ModelWithContent): Storage $nativeStorage */
        $nativeStorage = $this->kirby->component('storage');
        $beforeWrite = static function () use (&$calls, $failAfterInvocation): void {
            if ($failAfterInvocation === false || $calls === 2) {
                throw new RuntimeException('PRIVATE_FAILURE_CANARY');
            }
        };
        $this->kirby->extend(['components' => ['storage' => static function (App $kirby, ModelWithContent $model) use ($nativeStorage, $beforeWrite): Storage {
            if ($model instanceof OrderPage === false) {
                return $nativeStorage($kirby, $model);
            }

            // Kirby reconstructs storage by class when cloning a model; retain the failure callback across those instances.
            $storage = new class ($model) extends PlainTextStorage {
                public static Closure $beforeWrite;

                protected function write(VersionId $versionId, Language $language, array $fields): void
                {
                    (self::$beforeWrite)();
                    parent::write($versionId, $language, $fields);
                }
            };
            $storage::$beforeWrite = $beforeWrite;

            return $storage;
        }]]);

        try {
            $result = (new OrderHookDispatcher($this->kirby))->retryFailed($page->uuid()->toString(), $deliveryId);
        } finally {
            $this->kirby->extend(['components' => ['storage' => $nativeStorage]]);
        }

        $this->assertFalse($result->isDelivered());
        $this->assertSame(PersistenceErrorCode::WRITE_FAILED, $result->errorCode());
        $this->assertSame($expectedCalls, $calls);
        $after = $this->entries($store->requirePage($page->id()))[0];
        $this->assertSame($expectedAttempts, $after['attempts']);
        $this->assertSame('failed', $after['status']);
        $this->assertSame($entry['event'], $after['event']);
        $this->assertSame($entry['expiresAt'], $after['expiresAt']);
    }

    /** @return iterable<string, array{bool, int, int}> */
    public static function retryWriteFailures(): iterable
    {
        yield 'admission' => [false, 1, 1];
        yield 'outcome' => [true, 2, 2];
    }

    #[DataProvider('manualDeletionStates')]
    public function testManualDeletionBypassesCleanupSettingsAndAgeForTerminalUnpaidOrders(string $checkoutStatus, string $paymentStatus): void
    {
        $this->restart([], options: ['programmatordev.stripe-checkout' => ['settings' => [
            'cleanupCreationFailures' => false,
            'cleanupUnpaidOrders' => false,
        ]]]);
        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $page = $store->update($page->uuid()->toString(), static function (array $data) use ($checkoutStatus, $paymentStatus): array {
            $data['checkoutStatus'] = $checkoutStatus;
            $data['paymentStatus'] = $paymentStatus;
            $timestamp = match ($checkoutStatus) {
                'creation_failed' => 'creationFailedAt',
                'expired' => 'checkoutExpiredAt',
                'complete' => 'checkoutCompletedAt',
                default => throw new \LogicException('Unknown test status.'),
            };
            $data[$timestamp] = $data['createdAt'];

            if ($checkoutStatus !== 'creation_failed') {
                $data['stripeCheckoutSessionId'] = 'cs_test';
                $data['stripeShippingRateIds'] = [];
            }

            if ($checkoutStatus === 'complete') {
                $data = [
                    ...$data,
                    'paymentFailedAt' => $data['createdAt'],
                    'discountTotal' => '0',
                    'customFields' => [],
                    'discounts' => [],
                    'shippingTotal' => '0',
                    'taxTotal' => '0',
                    'total' => '16.00',
                ];
            }

            return $data;
        });
        /** @var array<string, mixed> $options */
        $options = $this->kirby->options();
        $policy = new RetentionPolicy((new ConfigurationResolver())->resolve($options)->configurationOrFail()->settings());
        $this->assertFalse($store->deleteEligible($page->uuid()->toString(), $policy, new DateTimeImmutable()));
        $this->assertTrue($store->deleteManually($page->uuid()->toString()));
        $this->assertNull($store->order($page->uuid()->toString()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function manualDeletionStates(): iterable
    {
        yield 'creation failed' => ['creation_failed', 'unpaid'];
        yield 'expired' => ['expired', 'unpaid'];
        yield 'complete failed' => ['complete', 'failed'];
    }

    #[DataProvider('deletionModes')]
    public function testEligibleDeletionUsesNativePageAndKeepsOnlySanitizedOutcome(bool $manual): void
    {
        $observed = [];
        $this->restart([
            'programmatordev.stripe-checkout.order.fields' => fn(): array => ['note' => 'Private customer note'],
            'programmatordev.stripe-checkout.order.deleted' => function (Page $order, LifecycleEvent $lifecycleEvent) use (&$observed): void {
                $observed = [
                    'found' => (new OrderPageStore($order->kirby()))->order($lifecycleEvent->pageUuid()),
                    'note' => $order->content('default')->data()['note'],
                    'user' => $order->kirby()->user(),
                    'event' => $lifecycleEvent,
                ];
                throw new RuntimeException('Private error');
            },
        ]);
        $store = new OrderPageStore($this->kirby);
        $page = $this->createOrder();
        $policy = new RetentionPolicy((new ConfigurationResolver())->resolve([])->configurationOrFail()->settings());
        $future = new DateTimeImmutable('+8 days');
        $this->assertFalse($store->deleteEligible($page->uuid()->toString(), $policy, $future));
        $page = $store->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'creation_failed',
            'creationFailedAt' => $data['createdAt'],
        ]);
        $this->assertFalse($store->deleteEligible($page->uuid()->toString(), $policy, new DateTimeImmutable()));
        $deleted = $manual
            ? $store->deleteManually($page->uuid()->toString())
            : $store->deleteEligible($page->uuid()->toString(), $policy, $future);
        $this->assertTrue($deleted);
        $this->assertNull($observed['found']);
        $this->assertNull($observed['user']);
        $this->assertSame('Private customer note', $observed['note']);
        $this->assertInstanceOf(LifecycleEvent::class, $observed['event']);
        $this->assertSame(LifecycleEventType::OrderDeleted, $observed['event']->type());
        $outcome = Data::read($this->kirby->root('site') . '/storage/stripe-checkout/lifecycle-last-deletion.json');
        $this->assertSame('failed', $outcome['status']);
        $this->assertSame(['deliveryId', 'occurredAt', 'status', 'errorCode'], array_keys($outcome));
        $this->assertStringNotContainsString('Private', json_encode($outcome, JSON_THROW_ON_ERROR));
        $this->assertTrue((new OrderHookDispatcher($this->kirby))->hasFailedDeletionDelivery());
        $this->assertCount(0, $store->orders());
    }

    /** @return iterable<string, array{bool}> */
    public static function deletionModes(): iterable
    {
        yield 'manual' => [true];
        yield 'automatic policy' => [false];
    }

    #[DataProvider('nativeDeletionFailureStages')]
    public function testManualDeletionDistinguishesNativeFailureBeforeAndAfterCommit(string $stage, bool $committed): void
    {
        $deletedNotifications = 0;
        $this->restart([
            'programmatordev.stripe-checkout.order.deleted' => function () use (&$deletedNotifications): void {
                $deletedNotifications++;
            },
        ]);
        $store = new OrderPageStore($this->kirby);
        $page = $this->createOrder();
        $store->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'creation_failed',
            'creationFailedAt' => $data['createdAt'],
        ]);
        $this->kirby->extend(['hooks' => [
            'page.delete:' . $stage => function (): void {
                throw new RuntimeException('PRIVATE_FAILURE_CANARY');
            },
        ]]);

        try {
            $this->assertTrue($store->deleteManually($page->uuid()->toString()));
            $this->assertTrue($committed);
        } catch (OrderStorageException $error) {
            $this->assertFalse($committed);
            $this->assertSame(PersistenceErrorCode::WRITE_FAILED, $error->errorCode());
        }

        $this->assertSame($committed ? 1 : 0, $deletedNotifications);
        $this->assertSame($committed, $store->order($page->uuid()->toString()) === null);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function nativeDeletionFailureStages(): iterable
    {
        yield 'before commit' => ['before', false];
        yield 'after commit' => ['after', true];
    }

    public function testOrdinaryDeletionStillCannotUseTheInternalOperation(): void
    {
        $this->restart([]);
        $page = $this->createOrder();
        $this->expectException(PermissionException::class);
        $page->deleteStoredOrder();
    }

    public function testSuccessfulPaymentDefeatsAnEarlierUnpaidCleanupCandidate(): void
    {
        $store = new OrderPageStore($this->kirby);
        $page = $this->createOrder();
        $failed = $store->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'complete',
            'paymentStatus' => 'failed',
            'stripeCheckoutSessionId' => 'cs_test',
            'stripeShippingRateIds' => [],
            'checkoutCompletedAt' => $data['createdAt'],
            'paymentFailedAt' => $data['createdAt'],
            'discountTotal' => '0',
            'customFields' => [],
            'discounts' => [],
            'shippingTotal' => '0',
            'taxTotal' => '0',
            'total' => '16.00',
        ]);
        $policy = new RetentionPolicy((new ConfigurationResolver())->resolve([])->configurationOrFail()->settings());
        $future = new DateTimeImmutable('+31 days');
        $this->assertTrue($policy->isEligible($store->data($failed), $future));
        $store->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'paymentStatus' => 'paid',
            'paidAt' => $data['createdAt'],
        ]);
        $this->assertFalse($store->deleteEligible($page->uuid()->toString(), $policy, $future));
        $this->assertFalse($store->deleteManually($page->uuid()->toString()));
        $this->assertNotNull($store->order($page->uuid()->toString()));
    }

    #[DataProvider('invalidDeliveries')]
    public function testRejectsCorruptPersistedDeliveryFacts(string $mutation): void
    {
        $page = $this->createOrder();
        $data = (new OrderPageStore($this->kirby))->data($page);
        $entries = $this->entries($page);
        $event = OrderData::map($entries[0]['event']);

        switch ($mutation) {
            case 'foreign order':
                $event['pageUuid'] = 'page://other';
                break;
            case 'event time':
                $event['occurredAt'] = '2020-01-01T00:00:00Z';
                break;
            case 'nested ledger':
                $snapshot = OrderData::map($event['orderSnapshot']);
                $snapshot['lifecycleDeliveries'] = [];
                $event['orderSnapshot'] = $snapshot;
                break;
            case 'raw provider data':
                $event['rawEvent'] = ['secret' => 'not allowed'];
                break;
            case 'invalid attempts':
                $entries[0]['attempts'] = -1;
                break;
            case 'exception text':
                $entries[0]['errorCode'] = 'Sensitive error message';
                break;
            case 'duplicate id':
                $entries[] = $entries[0];
                break;
        }

        $entries[0]['event'] = $event;
        $data['lifecycleDeliveries'] = $entries;
        $this->expectException(OrderDataException::class);
        OrderSerializer::normalize($data);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDeliveries(): iterable
    {
        $mutations = ['foreign order', 'event time', 'nested ledger', 'raw provider data', 'invalid attempts', 'exception text', 'duplicate id'];

        foreach ($mutations as $mutation) {
            yield $mutation => [$mutation];
        }
    }

    /** @return list<array<string, mixed>> */
    private function entries(OrderPage $page): array
    {
        return array_map(OrderData::map(...), OrderData::list((new OrderPageStore($this->kirby))->data($page)['lifecycleDeliveries']));
    }

    /**
     * @param array<string, callable> $hooks
     * @param list<array<string, mixed>>|null $languages
     * @param array<string, mixed> $options
     */
    private function restart(array $hooks, ?array $languages = null, array $options = []): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: $options, hooks: $hooks, languages: $languages, impersonate: null);
        $this->kirby = $this->environment->app();
    }

    private function createOrder(?string $languageCode = null): OrderPage
    {
        $price = Money::of('16', 'EUR');
        $product = new Product(new ProductRequest('product', 1), 'Product', false, new Price($price));
        $uuid = Uuid::generate();
        $context = new OrderCreationContext(
            uuid: $uuid,
            orderNumber: (new OrderNumberFormatter())->format($uuid),
            lineItems: [OrderLineItemSnapshot::fromCheckoutLineItem(new CheckoutLineItem($product))],
            currency: 'EUR',
            checkoutSource: CheckoutSource::Direct,
            cartRevision: null,
            userUuid: null,
            languageCode: $languageCode,
            uiMode: UiMode::Hosted,
        );
        $createdAt = new DateTimeImmutable();

        return (new OrderPageStore($this->kirby))->create(
            context: $context,
            checkoutAttempt: CheckoutAttemptFactory::create(
                order: $context,
                createdAt: $createdAt,
                guestReference: 'guest',
            ),
            createdAt: $createdAt,
        );
    }
}
