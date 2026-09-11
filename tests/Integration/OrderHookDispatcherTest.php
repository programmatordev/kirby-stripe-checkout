<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use DateTimeImmutable;
use Kirby\Cms\Page;
use Kirby\Data\Data;
use Kirby\Exception\PermissionException;
use Kirby\Uuid\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Diagnostics\LocalDiagnostics;
use ProgrammatorDev\StripeCheckout\Kirby\OrderCreationContextFactory;
use ProgrammatorDev\StripeCheckout\Kirby\OrderHookDispatcher;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEvent;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer;
use ProgrammatorDev\StripeCheckout\Order\Internal\RetentionPolicy;
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
                        // Intent must already be persisted before after hooks or
                        // lifecycle dispatch, not repaired by a subsequent write.
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
        (new OrderHookDispatcher($this->kirby))->dispatch($page->uuid()->toString(), OrderData::text($event['deliveryId']));
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
            'checkoutOpenedAt' => $data['createdAt'],
        ]);
        $fail = false;
        $dispatcher = new OrderHookDispatcher($this->kirby);
        $event = OrderData::map($entry['event']);
        $deliveryId = OrderData::text($event['deliveryId']);
        $dispatcher->dispatch($first->uuid()->toString(), $deliveryId);
        $dispatcher->dispatch($first->uuid()->toString(), $deliveryId);
        $this->assertCount(3, $observed);
        $this->assertSame($observed[0][0], $observed[2][0]);
        $this->assertSame('open', $observed[2][1]);
        $retried = $this->entries($store->requirePage($first->id()))[0];
        $this->assertSame(2, $retried['attempts']);
        $this->assertSame('delivered', $retried['status']);
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
            'checkoutOpenedAt' => $data['createdAt'],
        ];
        $store->update($page->uuid()->toString(), $reduce, [LifecycleEventType::SessionCreated], 'checkout.session.completed', 'evt_test');
        $page = $store->update($page->uuid()->toString(), $reduce, [LifecycleEventType::SessionCreated], 'checkout.session.completed', 'evt_test');
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
        $this->restart([
            'programmatordev.stripe-checkout.order.created' => function (Page $order) use (&$observed): void {
                $observed[] = $order->kirby()->languageCode();
                throw new RuntimeException('Failed');
            },
        ], $languages);
        $this->kirby->setCurrentLanguage('en');
        $page = $this->createOrder('pt');
        $this->assertSame('en', $this->kirby->languageCode());
        $entry = $this->entries($page)[0];
        $event = OrderData::map($entry['event']);
        (new OrderHookDispatcher($this->kirby))->dispatch($page->uuid()->toString(), OrderData::text($event['deliveryId']));
        $this->assertSame(['pt', 'pt'], $observed);
        $this->assertSame('en', $this->kirby->languageCode());
        $this->assertNull($page->version('latest')->read('pt'));
    }

    public function testEligibleDeletionUsesNativePageAndKeepsOnlySanitizedOutcome(): void
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
        $this->assertTrue($store->deleteEligible($page->uuid()->toString(), $policy, $future));
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
            'checkoutCompletedAt' => $data['createdAt'],
            'paymentFailedAt' => $data['createdAt'],
            'discountTotal' => '0',
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
        $context = (new OrderCreationContextFactory($this->kirby))->create(
            lineItems: [OrderLineItemSnapshot::fromProduct($product, $price)],
            currency: 'EUR',
            source: CheckoutSource::Direct,
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
