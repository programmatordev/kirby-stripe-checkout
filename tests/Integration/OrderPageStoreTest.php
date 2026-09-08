<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use DateTimeImmutable;
use Kirby\Api\Controller\Changes;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use Kirby\Content\LockedContentException;
use Kirby\Exception\LogicException;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Form\Form;
use Kirby\Toolkit\I18n;
use Kirby\Uuid\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Diagnostics\LocalDiagnostics;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\OrdersPage;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderQueryException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\TestWorkspace;
use RuntimeException;

final class OrderPageStoreTest extends KirbyTestCase
{
    public function testBootCreatesOnlyTheOwnedContainerAndSetupIsIdempotent(): void
    {
        $store = new OrderPageStore($this->kirby);
        $container = $store->container();
        $this->assertInstanceOf(OrdersPage::class, $container);
        $this->assertTrue($container->isDraft());
        $this->assertSame($container->id(), $store->initialize()->id());
        $this->assertCount(0, $store->orders());
    }

    public function testCreatesAndQueriesNativeDraftPages(): void
    {
        $page = $this->createOrder();
        $api = new StripeCheckout($this->kirby);
        $this->assertTrue($page->isDraft());
        $this->assertSame($page->uuid()->id(), $page->slug());
        $this->assertSame('ORD-' . strtoupper($page->slug()), $this->value($page, 'orderNumber'));
        $this->assertSame('creating', $this->value($page, 'checkoutStatus'));
        $this->assertSame('32.00', $this->value($page, 'subtotal'));
        $this->assertCount(1, $api->orders()->filterBy('paymentStatus', 'unpaid'));
        $this->assertSame($page->id(), $api->order($page->uuid()->toString())?->id());
        $this->assertNull($api->order($page->id()));
        $this->assertNull($api->order($page->slug()));
        $this->assertNull($api->order('page://missing'));
    }

    public function testUserQueriesDoNotDiscloseOtherUsersOrGuestOrders(): void
    {
        $first = new User([
            'id' => 'buyer-one',
            'email' => 'one@example.test',
        ]);
        $second = new User([
            'id' => 'buyer-two',
            'email' => 'two@example.test',
        ]);
        $owned = $this->createOrder($first->uuid()->toString());
        $this->createOrder($second->uuid()->toString());
        $this->createOrder();
        $api = new StripeCheckout($this->kirby);
        $this->assertCount(1, $api->ordersFor($first));
        $this->assertNull($api->orderFor($second, $owned->uuid()->toString()));
        $this->assertSame($owned->id(), $api->orderFor($first, $owned->uuid()->toString())?->id());
    }

    public function testCanonicalWritesPreserveCustomFieldsAndRejectRegressions(): void
    {
        $page = $this->createOrder()->update(['internalNote' => 'Keep me']);
        $store = new OrderPageStore($this->kirby);
        $opened = $store->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'open',
            'stripeCheckoutSessionId' => 'cs_test',
            'checkoutOpenedAt' => $data['createdAt'],
        ]);
        $this->assertSame('Keep me', $this->value($opened, 'internalNote'));
        $this->assertSame('open', $this->value($opened, 'checkoutStatus'));
        $this->expectException(OrderDataException::class);
        $store->update($opened->uuid()->toString(), static function (array $data): array {
            $data['checkoutStatus'] = 'creating';
            unset($data['stripeCheckoutSessionId'], $data['checkoutOpenedAt']);

            return $data;
        });
    }

    public function testStaleOrderPageDoesNotOverwriteCanonicalUpdates(): void
    {
        $old = $this->createOrder();
        (new OrderPageStore($this->kirby))->update($old->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'open',
            'stripeCheckoutSessionId' => 'cs_test',
            'checkoutOpenedAt' => $data['createdAt'],
        ]);
        $updated = $old->update(['note' => 'A later project edit']);
        $this->assertSame('open', $this->value($updated, 'checkoutStatus'));
        $this->assertSame('A later project edit', $this->value($updated, 'note'));
    }

    #[DataProvider('protectedActions')]
    public function testAdminCannotChangeStructureOrCanonicalFields(string $action, bool $container): void
    {
        $page = $container ? (new OrderPageStore($this->kirby))->initialize() : $this->createOrder();
        $this->expectException($action === 'sort' ? LogicException::class : PermissionException::class);

        match ($action) {
            'title' => $page->changeTitle('Other'),
            'slug' => $page->changeSlug('other'),
            'status' => $page->changeStatus('unlisted'),
            'delete' => $page->delete(),
            'copy' => $page->copy(),
            'duplicate' => $page->duplicate('duplicate'),
            'template' => $page->changeTemplate('default'),
            'move' => $page->move(new Page(['slug' => 'destination'])),
            'sort' => $page->changeNum(1),
            'child' => $page->createChild(['slug' => 'child']),
            'canonical' => $page->update(['paymentStatus' => 'paid']),
            'save' => $page->save(['paymentStatus' => 'paid']),
            default => $this->fail('Unknown action'),
        };
    }

    /** @return iterable<string, array{string, bool}> */
    public static function protectedActions(): iterable
    {
        $actions = ['title', 'slug', 'status', 'delete', 'copy', 'duplicate', 'template', 'move', 'sort', 'child', 'canonical', 'save'];

        foreach ($actions as $action) {
            yield 'order ' . $action => [$action, false];
            yield 'container ' . $action => [$action, true];
        }
    }

    public function testOrderCannotRender(): void
    {
        $this->expectException(NotFoundException::class);
        $this->createOrder()->render();
    }

    public function testCustomBlueprintAndTranslationsDoNotDuplicateCanonicalFields(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            languages: [
                [
                    'code' => 'en',
                    'default' => true,
                    'locale' => 'en_US',
                    'name' => 'English',
                ],
                [
                    'code' => 'pt',
                    'locale' => 'pt_PT',
                    'name' => 'Português',
                ],
            ],
            beforeApp: static function (TestWorkspace $workspace): void {
                $workspace->writePageBlueprint('stripe-checkout-order', [
                    'extends' => 'programmatordev/stripe-checkout/pages/order',
                    'tabs' => ['project' => [
                        'label' => 'Project',
                        'fields' => ['note' => [
                            'type' => 'textarea',
                            'default' => 'New order',
                        ]],
                    ]],
                ]);
            },
        );
        $this->kirby = $this->environment->app();
        $this->kirby->setCurrentLanguage('pt');
        $page = $this->createOrder();
        $this->assertSame('pt', $this->value($page, 'languageCode'));
        $this->assertSame('New order', $this->value($page, 'note'));
        $page = $page->update(['note' => 'Português'], 'pt');
        $page = $page->update(['note' => 'English'], 'en');
        $this->assertSame('Português', $page->version('latest')->read('pt')['note'] ?? null);
        $this->assertSame('English', $page->version('latest')->read('en')['note'] ?? null);
        $this->assertArrayNotHasKey('uuid', $page->version('latest')->read('pt'));
        $this->assertArrayNotHasKey('paymentstatus', $page->version('latest')->read('pt'));
        $this->assertArrayHasKey('note', $page->blueprint()->fields());

        $page = (new OrderPageStore($this->kirby))->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'creation_uncertain',
            'creationUncertainAt' => $data['createdAt'],
        ]);
        $this->assertSame('Português', $page->version('latest')->read('pt')['note'] ?? null);
        $this->assertSame('English', $this->value($page, 'note'));
        $this->assertSame('creation_uncertain', $this->value($page, 'checkoutStatus'));
        // Native writes already strip untranslatable fields. Simulate corrupt
        // imported content to verify queries cannot expose a translated state.
        F::write($page->root() . '/stripe-checkout-order.pt.txt', \Kirby\Data\Txt::encode([
            'note' => 'Português',
            'paymentstatus' => 'paid',
        ]));
        $this->assertNull((new StripeCheckout($this->kirby))->order($page->uuid()->toString()));
    }

    public function testFormatterAndCustomFieldsFilterRunBeforeCreationWithoutImpersonation(): void
    {
        $seen = [];
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            options: ['programmatordev.stripe-checkout.orders.numberFormatter' => static fn(string $uuid): string => 'WEB-' . (new Uri($uuid))->host()],
            hooks: ['programmatordev.stripe-checkout.order.fields' => function (array $fields, OrderCreationContext $context) use (&$seen): array {
                $seen[] = [
                    'user' => App::instance()->user()?->id(),
                    'orders' => (new OrderPageStore(App::instance()))->orders()->count(),
                ];

                return [...$fields, 'salesChannel' => 'website'];
            }],
            impersonate: null,
        );
        $this->kirby = $this->environment->app();
        $page = $this->createOrder();
        $this->assertSame([[
            'user' => null,
            'orders' => 0,
        ]], $seen);
        $this->assertNull($this->kirby->user());
        $this->assertSame('WEB-' . $page->slug(), $this->value($page, 'orderNumber'));
        $this->assertSame('website', $this->value($page, 'salesChannel'));
    }

    public function testFilterCannotOverrideCanonicalFieldsOrLeaveAnOrderBehind(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(hooks: [
            'programmatordev.stripe-checkout.order.fields' => fn(): array => ['total' => '1'],
        ]);
        $this->kirby = $this->environment->app();

        try {
            $this->createOrder();
            $this->fail('Expected invalid custom fields.');
        } catch (OrderDataException $error) {
            $this->assertSame('order.custom_fields_invalid', $error->errorCode());
            $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
        }
    }

    public function testUnownedContainerIsNotAdoptedOrOverwritten(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(beforeApp: static function (TestWorkspace $workspace): void {
            $workspace->writeDraftPage('stripe-checkout-orders', 'default', ['title' => 'Mine']);
        });
        $this->kirby = $this->environment->app();
        $file = $this->environment->workspace()->roots()['content'] . '/_drafts/stripe-checkout-orders/default.txt';
        $before = F::read($file);

        try {
            (new OrderPageStore($this->kirby))->initialize();
            $this->fail('Expected a collision.');
        } catch (OrderStorageException) {
            $this->assertSame($before, F::read($file));
        }

        $this->expectException(OrderQueryException::class);
        (new StripeCheckout($this->kirby))->orders();
    }

    public function testReadsDoNotRecreateMissingStorage(): void
    {
        $root = $this->environment->workspace()->roots()['content'] . '/_drafts/stripe-checkout-orders';
        Dir::remove($root);
        $api = new StripeCheckout($this->kirby);
        $this->assertCount(0, $api->orders());
        $this->assertNull($api->order('page://missing'));
        $this->assertDirectoryDoesNotExist($root);
        $this->createOrder();
        $this->assertCount(1, $api->orders());
    }

    public function testCorruptOrderIsExcludedWithoutLosingValidOrders(): void
    {
        $broken = $this->createOrder();
        $valid = $this->createOrder();
        $uuid = $broken->uuid()->toString();
        $broken->version('latest')->update(['subtotal' => 'incorrect'], 'default');
        $api = new StripeCheckout($this->kirby);
        $this->assertCount(1, $api->orders());
        $this->assertNull($api->order($uuid));
        $this->assertSame($valid->id(), $api->orders()->first()?->id());
    }

    public function testFailedNativeCreationIsNotReturnedAsAnOrder(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(hooks: [
            'page.create:before' => function (Page $page): void {
                if ($page instanceof OrderPage) {
                    throw new RuntimeException('Injected write failure');
                }
            },
        ]);
        $this->kirby = $this->environment->app();

        try {
            $this->createOrder();
            $this->fail('Expected failed creation.');
        } catch (OrderStorageException $error) {
            $this->assertSame('persistence.write_failed', $error->errorCode());
            $this->assertCount(0, (new OrderPageStore($this->kirby))->orders());
        }
    }

    public function testPermissionsDoNotRestrictTrustedQueriesButDoRestrictEdits(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(
            roles: [[
                'name' => 'reader',
                'permissions' => ['programmatordev.stripe-checkout' => [
                    'orders.read' => true,
                    'orders.update' => false,
                ]],
            ]],
            users: [[
                'id' => 'reader',
                'email' => 'reader@example.test',
                'role' => 'reader',
            ]],
            impersonate: 'reader',
        );
        $this->kirby = $this->environment->app();
        $page = $this->createOrder();
        $this->assertTrue($page->permissions()->can('read'));
        $this->assertFalse($page->permissions()->can('update'));
        $this->assertSame('reader', $this->kirby->user()?->id());
        $this->kirby->impersonate(null);
        $this->assertFalse($page->permissions()->can('read'));
        $this->assertCount(1, (new StripeCheckout($this->kirby))->orders());
        $this->expectException(PermissionException::class);
        $page->update(['note' => 'Denied']);
    }

    public function testConcurrentCanonicalWritesReloadAfterTheFirstCommit(): void
    {
        $page = $this->createOrder();
        $uuid = $page->uuid()->toString();
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/Order/concurrent-update.php'], [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process);

        try {
            fwrite($pipes[0], json_encode([
                'roots' => $this->environment->workspace()->roots(),
                'uuid' => $uuid,
            ], JSON_THROW_ON_ERROR) . "\n");
            stream_set_timeout($pipes[1], 5);
            $this->assertSame("ready\n", fgets($pipes[1]));
            (new OrderPageStore($this->kirby))->update($uuid, function (array $data) use ($pipes): array {
                fwrite($pipes[0], "go\n");
                $read = [$pipes[1]];
                $write = $except = [];
                $ready = stream_select($read, $write, $except, 0, 200000);
                $earlyResult = $ready === 1 ? fgets($pipes[1]) : '';
                $this->assertSame(0, $ready, 'The second writer must wait; early result: ' . $earlyResult);

                return [
                    ...$data,
                    'checkoutStatus' => 'open',
                    'stripeCheckoutSessionId' => 'cs_test',
                    'checkoutOpenedAt' => $data['createdAt'],
                ];
            });
            $this->assertSame("rejected\n", fgets($pipes[1]));
            $this->assertSame('open', $this->value((new OrderPageStore($this->kirby))->order($uuid), 'checkoutStatus'));
        } finally {
            proc_terminate($process);

            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            proc_close($process);
        }
    }

    public function testNativePanelChangesPreserveCompleteSnapshots(): void
    {
        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $before = $store->data($page);
        $form = Form::for($page);
        /** @var array<string, mixed> $input */
        $input = [...$form->toFormValues(), 'note' => 'Panel note'];
        $page->update($input);
        $updated = $store->requirePage($page->id());
        $this->assertSame($before, $store->data($updated));
        $this->assertSame('Panel note', $this->value($updated, 'note'));
        $changes = $updated->version('changes');
        $changes->save([...($updated->version('latest')->read('default') ?? []), 'note' => 'Published note'], 'default');
        $changes->publish('default');
        $published = $store->requirePage($page->id());
        $this->assertSame($before, $store->data($published));
        $this->assertSame('Published note', $this->value($published, 'note'));
    }

    #[DataProvider('editingContexts')]
    public function testPanelCustomEditsSurviveCanonicalUpdates(?string $languageCode, bool $reload): void
    {
        if ($languageCode !== null) {
            $this->environment->close();
            $this->environment = KirbyTestEnvironment::start(languages: [
                [
                    'code' => 'en',
                    'default' => true,
                    'locale' => 'en_US',
                    'name' => 'English',
                ],
                [
                    'code' => 'pt',
                    'locale' => 'pt_PT',
                    'name' => 'Português',
                ],
            ]);
            $this->kirby = $this->environment->app();
            $this->kirby->setCurrentLanguage($languageCode);
        }

        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $input = [...Form::for($page)->toFormValues(), 'note' => 'Pending note'];
        Changes::save($page, $input);
        $updated = $store->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'open',
            'stripeCheckoutSessionId' => 'cs_test',
            'checkoutOpenedAt' => $data['createdAt'],
        ]);
        $expected = $store->data($updated);

        // Cover both a later Panel request and native publication through a
        // retained Page whose latest-version cache predates the canonical write.
        $editingPage = $reload ? $store->requirePage($page->id()) : $page;
        Changes::publish($editingPage, $input);
        $saved = $store->requirePage($page->id());
        $this->assertSame($expected, $store->data($saved));
        $this->assertSame('Pending note', $saved->content($languageCode ?? 'default')->data()['note'] ?? null);
        $this->assertFalse($saved->version('changes')->exists($languageCode ?? 'default'));

        if ($languageCode === 'pt') {
            $translation = $saved->version('latest')->read('pt');
            $this->assertIsArray($translation);
            $this->assertArrayNotHasKey('checkoutstatus', $translation);
            $this->assertArrayNotHasKey('uuid', $translation);
        }
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function editingContexts(): iterable
    {
        yield 'fresh, single language' => [null, true];
        yield 'fresh, default language' => ['en', true];
        yield 'fresh, translated custom fields' => ['pt', true];
        yield 'retained, single language' => [null, false];
        yield 'retained, default language' => ['en', false];
        yield 'retained, translated custom fields' => ['pt', false];
    }

    public function testNativeVersionPublishIgnoresProtectedChangesButDirectUpdatesRejectThem(): void
    {
        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $expected = $store->data($page);
        $page->version('changes')->save([
            ...($page->version('latest')->read('default') ?? []),
            'paymentStatus' => 'paid',
            'note' => 'Custom note',
        ]);
        $page->version('changes')->publish();
        $saved = $store->requirePage($page->id());
        $this->assertSame($expected, $store->data($saved));
        $this->assertSame('Custom note', $this->value($saved, 'note'));

        $this->expectException(PermissionException::class);
        $saved->update(['paymentStatus' => 'paid']);
    }

    public function testCanonicalUpdatesInvalidatePageCacheOnlyWhenCommitted(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'cache' => ['pages' => ['active' => true]],
        ]);
        $this->kirby = $this->environment->app();
        $page = $this->createOrder();
        $uuid = $page->uuid()->toString();
        $store = new OrderPageStore($this->kirby);
        $cache = $this->kirby->cache('pages');
        $cache->set('order-summary', 'creating');
        $this->assertSame('creating', $cache->get('order-summary'));
        $store->update($uuid, static fn(array $data): array => $data);
        $this->assertSame('creating', $cache->get('order-summary'));

        try {
            $store->update($uuid, static fn(array $data): array => [...$data, 'uuid' => 'changed']);
            $this->fail('Expected immutable identity rejection.');
        } catch (OrderDataException) {
            $this->assertSame('creating', $cache->get('order-summary'));
        }

        $store->update($uuid, static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'creation_uncertain',
            'creationUncertainAt' => $data['createdAt'],
        ]);
        $this->assertNull($cache->get('order-summary'));
    }

    public function testOrderChangesRetainNativeEditorLocks(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(users: [
            [
                'id' => 'first-editor',
                'email' => 'first@example.test',
                'role' => 'admin',
            ],
            [
                'id' => 'second-editor',
                'email' => 'second@example.test',
                'role' => 'admin',
            ],
        ], impersonate: 'first-editor');
        $this->kirby = $this->environment->app();
        $page = $this->createOrder();
        Changes::save($page, ['note' => 'First editor']);
        $this->assertSame('first-editor', $page->version('changes')->read()['lock'] ?? null);
        $this->kirby->impersonate('second-editor');
        $this->assertTrue($page->version('changes')->isLocked());
        $this->expectException(LockedContentException::class);
        Changes::publish($page, ['note' => 'Second editor']);
    }

    #[DataProvider('translatedPermissionActions')]
    public function testOrderPermissionErrorsUseThePanelTranslation(string $action, string $suffix): void
    {
        $this->kirby->setCurrentTranslation('pt_PT');
        $page = $this->createOrder();
        $this->expectException(PermissionException::class);
        $this->expectExceptionMessage(I18n::template('programmatordev.stripe-checkout.orders.errors.' . $suffix));

        match ($action) {
            'update' => $page->update(['paymentStatus' => 'paid']),
            'createChild' => $page->createChild(['slug' => 'child']),
            'copy' => $page->copy(),
            'save' => $page->save(['note' => 'Bypass']),
            default => $this->fail('Unknown action'),
        };
    }

    /** @return iterable<string, array{string, string}> */
    public static function translatedPermissionActions(): iterable
    {
        yield 'protected facts' => ['update', 'protectedFields'];
        yield 'manual creation' => ['createChild', 'manualCreation'];
        yield 'structure' => ['copy', 'protectedStructure'];
        yield 'low-level save' => ['save', 'directSave'];
    }

    public function testNativeUuidConfigurationIsUsedForStoredOrders(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: ['content.uuid' => 'uuid-v4']);
        $this->kirby = $this->environment->app();
        $page = $this->createOrder();
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $page->uuid()->id());
        $this->assertSame($page->uuid()->id(), $page->slug());
    }

    public function testDisabledUuidsReportUnavailableUserQueries(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: ['content.uuid' => false]);
        $this->kirby = $this->environment->app();
        $this->expectException(OrderQueryException::class);
        $this->expectExceptionMessage('persistence.user_uuid_unavailable');
        (new StripeCheckout($this->kirby))->ordersFor(new User(['id' => 'buyer']));
    }

    public function testFailedReducerReleasesItsLockAndReentrantWritesFail(): void
    {
        $page = $this->createOrder();
        $store = new OrderPageStore($this->kirby);
        $uuid = $page->uuid()->toString();

        try {
            $store->update($uuid, static fn(array $data): array => $store->data($store->update($uuid, static fn(array $data): array => $data)));
            $this->fail('Expected reentrant write rejection.');
        } catch (OrderStorageException $error) {
            $this->assertSame('persistence.reentrant_write', $error->errorCode());
        }

        $updated = $page->update(['note' => 'Recovered']);
        $this->assertSame('Recovered', $this->value($updated, 'note'));
    }

    public function testCanonicalUpdateAdvancesTimeBeforeValidatingObservations(): void
    {
        $page = $this->createOrder();
        $earlier = '2025-01-01T00:00:00Z';
        $page->version('latest')->update([
            'createdAt' => $earlier,
            'updatedAt' => $earlier,
        ], 'default');
        $now = OrderData::timestamp(new DateTimeImmutable());
        $updated = (new OrderPageStore($this->kirby))->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'open',
            'stripeCheckoutSessionId' => 'cs_test',
            'checkoutOpenedAt' => $now,
        ]);
        $this->assertSame($now, $this->value($updated, 'checkoutOpenedAt'));
        $this->assertGreaterThanOrEqual($now, $this->value($updated, 'updatedAt'));
    }

    public function testDiagnosticsExposeInvalidRecordsWithoutCustomerData(): void
    {
        $page = $this->createOrder();
        $page->version('latest')->update(['subtotal' => 'private-invalid-value'], 'default');
        $report = (new LocalDiagnostics($this->kirby))->report();
        $checks = array_column($report['checks'], null, 'id');
        $this->assertSame('fail', $checks['orders']['status']);
        $this->assertSame(['count' => '1'], $checks['orders']['values']);
        $this->assertStringNotContainsString('private-invalid-value', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testCustomFieldHooksSeeTheirNativeBeforeAndAfterValuesOnly(): void
    {
        $seen = [];
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(hooks: [
            'page.update:before' => function (Page $page) use (&$seen): void {
                $seen[] = ['before', $page->content()->data()['note'] ?? null];
            },
            'page.update:after' => function (Page $newPage, Page $oldPage) use (&$seen): void {
                $seen[] = ['after', $oldPage->content()->data()['note'] ?? null, $newPage->content()->data()['note'] ?? null];
            },
        ]);
        $this->kirby = $this->environment->app();
        $page = $this->createOrder();
        $page->update(['note' => 'Updated']);
        (new OrderPageStore($this->kirby))->update($page->uuid()->toString(), static fn(array $data): array => [
            ...$data,
            'checkoutStatus' => 'creation_uncertain',
            'creationUncertainAt' => $data['createdAt'],
        ]);
        $this->assertSame([['before', null], ['after', null, 'Updated']], $seen);
    }

    public function testMissingCanonicalContentCannotBeRecreatedByAnUpdate(): void
    {
        $page = $this->createOrder();
        $file = $page->root() . '/stripe-checkout-order.txt';
        F::remove($file);

        try {
            $page->update(['note' => 'Must not recreate']);
            $this->fail('Expected unavailable canonical content.');
        } catch (OrderStorageException) {
            $this->assertFileDoesNotExist($file);
            $this->assertNull((new StripeCheckout($this->kirby))->order($page->uuid()->toString()));
        }
    }

    private function value(?Page $page, string $field): mixed
    {
        return $page?->content('default')->data()[strtolower($field)] ?? null;
    }

    private function createOrder(?string $userUuid = null): OrderPage
    {
        $price = Money::of('16', 'EUR');
        $product = new Product(new ProductRequest('product', 2), 'Product', false, new Price($price));

        return (new OrderPageStore($this->kirby))->create(
            [OrderLineSnapshot::fromProduct($product, $price)],
            'EUR',
            CheckoutSource::Direct,
            null,
            $userUuid,
            $this->kirby->languageCode(),
            'hosted',
            hash('sha256', 'token'),
            hash('sha256', 'request'),
            $userUuid === null ? 'guest' : null,
        );
    }
}
