<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Closure;
use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\User;
use Kirby\Data\Yaml;
use Kirby\Uuid\Uri;
use Kirby\Uuid\Uuid;
use Kirby\Uuid\Uuids;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Lifecycle\Internal\DeliveryLedger;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderQueryException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderCustomFieldsValidator;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderNumberFormatter;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer;
use ProgrammatorDev\StripeCheckout\Order\Internal\RetentionPolicy;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use Throwable;

/** @internal Native Page persistence; no provider requests, sessions or public mutation API. */
final class OrderPageStore
{
    public function __construct(private readonly App $kirby) {}

    public function initialize(): OrdersPage
    {
        $this->requireUuids();

        if (($container = $this->container()) !== null) {
            return $container;
        }

        try {
            $this->kirby->impersonate('kirby', fn(): Page => Page::create([
                'slug' => OrderSchema::CONTAINER,
                'template' => OrderSchema::CONTAINER,
                'isDraft' => true,
                'site' => $this->kirby->site()->clone(),
                'content' => [
                    'title' => 'Stripe Checkout Orders',
                    'stripeCheckout' => Yaml::encode([
                        'owner' => OrderSchema::OWNER,
                        'schemaVersion' => OrderSchema::VERSION,
                    ]),
                ],
            ]));
        } catch (Throwable) {
            // Accept only a fully persisted, owned winner of concurrent setup.
            if (($container = $this->container()) !== null) {
                return $container;
            }

            throw new OrderStorageException('persistence.write_failed');
        }

        return $this->container() ?? throw new OrderStorageException('persistence.verify_failed');
    }

    /** @phpstan-impure Reads the current filesystem inventory. */
    public function container(): ?OrdersPage
    {
        // A fresh native inventory must not reuse an earlier request-scoped
        // collection or the temporary model left by a failed Page creation.
        $page = $this->kirby->site()->clone()->findPageOrDraft(OrderSchema::CONTAINER);

        if ($page === null) {
            return null;
        }

        if ($page instanceof OrdersPage === false || $page->isDraft() === false || $page->intendedTemplate()->name() !== OrderSchema::CONTAINER) {
            throw new OrderStorageException('persistence.model_mismatch');
        }

        try {
            $content = $page->version('latest')->read('default');
            $metadata = OrderData::map(Yaml::decode($content['stripecheckout'] ?? ''));

            $expected = [
                'owner' => OrderSchema::OWNER,
                'schemaVersion' => OrderSchema::VERSION,
            ];

            if ($metadata !== $expected) {
                throw new OrderDataException();
            }
        } catch (Throwable) {
            throw new OrderStorageException('persistence.content_invalid');
        }

        return $page;
    }

    /** @param list<OrderLineSnapshot> $lineItems */
    public function create(
        array $lineItems,
        string $currency,
        CheckoutSource $sourceType,
        ?string $cartRevision,
        ?string $userUuid,
        ?string $languageCode,
        string $uiMode,
        string $tokenHash,
        string $requestFingerprint,
        ?string $guestReference,
    ): OrderPage {
        $this->requireUuids();
        /** @var array<string, mixed> $options */
        $options = $this->kirby->options();
        $formatter = (new ConfigurationResolver())->orderNumberFormatter($options);
        $uuid = Uuid::generate();
        $context = new OrderCreationContext(
            uuid: $uuid,
            orderNumber: (new OrderNumberFormatter($formatter))->format($uuid),
            sourceType: $sourceType,
            cartRevision: $cartRevision,
            userUuid: $userUuid,
            languageCode: $languageCode,
            uiMode: $uiMode,
            currency: $currency,
            lineItems: $lineItems,
        );
        $data = OrderSerializer::creation($context, $tokenHash, $requestFingerprint, $guestReference, new DateTimeImmutable());

        try {
            // Custom-field filters run before content creation and outside impersonation.
            $fields = OrderCustomFieldsValidator::validate($this->kirby->apply('programmatordev.stripe-checkout.order.fields', [
                'fields' => [],
                'context' => $context,
            ], 'fields'));
        } catch (Throwable) {
            throw new OrderDataException('order.custom_fields_invalid');
        }

        $container = $this->initialize();
        // Persist the pending creation delivery in the same content write, so
        // a process exit after creation does not lose the notification intent.
        // Resolve custom blueprint defaults first so the snapshot includes the
        // values that native Page creation will store, not just caller input.
        $defaults = Page::factory([
            'parent' => $container,
            'slug' => $uuid,
            'template' => OrderSchema::TEMPLATE,
        ])->createDefaultContent();
        $fields = OrderCustomFieldsValidator::validate([...$defaults, ...$fields]);
        $event = DeliveryLedger::event($data, $fields, LifecycleEventType::OrderCreated, 1);
        $data['lifecycleDeliveries'] = [DeliveryLedger::pending($event)];

        try {
            $this->kirby->impersonate('kirby', fn(): Page => Page::create([
                'parent' => $container,
                'site' => $this->kirby->site(),
                'slug' => $uuid,
                'template' => OrderSchema::TEMPLATE,
                'isDraft' => true,
                'content' => [...$fields, ...OrderSerializer::encode($data)],
            ]));
        } catch (Throwable) {
            // Never adopt a colliding order, including a valid-looking record.
            throw new OrderStorageException('persistence.write_failed');
        }

        $created = $this->requirePage(OrderSchema::CONTAINER . '/' . $uuid);

        if (OrderSerializer::hash($this->data($created)) !== OrderSerializer::hash($data)) {
            throw new OrderStorageException('persistence.verify_failed');
        }

        (new OrderLifecycle($this->kirby))->deliver($created->uuid()->toString(), $event->deliveryId());

        // Listeners may update custom fields; return the post-hook Page rather
        // than the model read before dispatch and outcome persistence.
        return $this->requirePage($created->id());
    }

    /** @return Pages<Page> */
    public function orders(): Pages
    {
        try {
            $this->requireUuids();
            $pages = [];

            foreach ($this->container()?->childrenAndDrafts() ?? [] as $page) {
                try {
                    $this->data($page);
                    $pages[] = $page;
                } catch (OrderStorageException) {
                    // Do not turn malformed children into trusted order Pages.
                    error_log('Stripe Checkout: persistence.order_invalid');
                }
            }

            return new Pages($pages);
        } catch (OrderStorageException) {
            throw new OrderQueryException();
        }
    }

    public function order(string $uuid): ?OrderPage
    {
        try {
            OrderData::uuid($uuid);
            $slug = OrderData::text((new Uri($uuid))->host());
        } catch (Throwable) {
            return null;
        }

        try {
            $this->requireUuids();
            // Order slugs are their native UUID IDs. Keep lookups inside the
            // owned container instead of resolving arbitrary site-wide Pages.
            $page = $this->container()?->drafts()->find($slug);

            if ($page instanceof Page === false) {
                return null;
            }

            try {
                $this->data($page);
            } catch (OrderStorageException) {
                return null;
            }

            return $page instanceof OrderPage ? $page : null;
        } catch (OrderStorageException) {
            throw new OrderQueryException();
        }
    }

    /** @return Pages<Page> */
    public function ordersFor(User $user): Pages
    {
        $userUuid = $this->userUuid($user);

        return $this->orders()->filterBy('userUuid', $userUuid);
    }

    public function orderFor(User $user, string $uuid): ?OrderPage
    {
        $userUuid = $this->userUuid($user);
        $page = $this->order($uuid);

        return ($page?->content('default')->data()['useruuid'] ?? null) === $userUuid ? $page : null;
    }

    private function userUuid(User $user): string
    {
        if (Uuids::enabled() === false) {
            throw new OrderQueryException('persistence.user_uuid_unavailable');
        }

        return OrderData::uuid($user->uuid()->toString(), 'user');
    }

    public function requirePage(string $pageId): OrderPage
    {
        $this->requireUuids();
        $container = $this->container();
        $page = $container?->drafts()->find($pageId);

        if ($page instanceof OrderPage === false) {
            throw new OrderStorageException('persistence.order_unavailable');
        }

        $this->data($page);

        return $page;
    }

    /** @return array<string, mixed> */
    public function data(Page $page): array
    {
        if ($page instanceof OrderPage === false || $page->isDraft() === false || $page->parent()?->id() !== OrderSchema::CONTAINER) {
            throw new OrderStorageException('persistence.model_mismatch');
        }

        try {
            $fields = OrderData::map($page->version('latest')->read('default'));

            // Ordinary Page reads merge translated content. Reject translated
            // canonical overrides so those reads cannot shadow validated facts.
            foreach ($this->kirby->languages() as $language) {
                if ($language->isDefault()) {
                    continue;
                }

                foreach (array_keys($page->version('latest')->read($language) ?? []) as $field) {
                    if (OrderSchema::isReserved($field)) {
                        throw new OrderDataException();
                    }
                }
            }

            return OrderSerializer::decode($fields, $page->intendedTemplate()->name(), $page->slug());
        } catch (Throwable) {
            throw new OrderStorageException('persistence.content_invalid');
        }
    }

    /**
     * @param Closure(array<string, mixed>): array<string, mixed> $reduce
     * @param list<LifecycleEventType> $events Events owned by the calling transition, not inferred from arbitrary field edits.
     */
    public function update(string $uuid, Closure $reduce, array $events = [], ?string $triggerType = null, ?string $triggerId = null): OrderPage
    {
        OrderData::uuid($uuid);
        $pageId = OrderSchema::CONTAINER . '/' . (new Uri($uuid))->host();

        $deliveryIds = [];
        $updated = OrderWriteLock::run($this->kirby, $pageId, function () use ($pageId, $reduce, $events, $triggerType, $triggerId, &$deliveryIds): OrderPage {
            // Reload after acquiring the lock: a writer may have committed
            // while this request waited, changing which transitions are valid.
            $page = $this->requirePage($pageId);
            $before = $this->data($page);
            $candidate = $reduce($before);

            if (OrderData::normalize($before) === OrderData::normalize($candidate)) {
                return $page;
            }

            $updatedAt = OrderData::string($candidate['updatedAt'] ?? null);

            if ($updatedAt < $before['updatedAt']) {
                throw new OrderDataException();
            }

            $bookkeeping = ['lifecycleDeliveries' => true];
            $orderChanged = OrderData::normalize(array_diff_key($before, $bookkeeping))
                !== OrderData::normalize(array_diff_key($candidate, $bookkeeping));

            // Hook attempts/results are not new order observations. Keep their
            // timestamps in the ledger without aging the order or its page cache.
            if ($orderChanged) {
                $candidate['updatedAt'] = max(OrderData::timestamp(new DateTimeImmutable()), $updatedAt);
            }

            $after = OrderSerializer::normalize($candidate);
            $this->validateTransition($before, $after);
            $content = $page->version('latest')->read('default') ?? [];

            // Replace the whole canonical projection, including removed fields,
            // while preserving unrelated custom content from the latest record.
            foreach (array_keys($content) as $field) {
                if (OrderSchema::isReserved($field)) {
                    unset($content[$field]);
                }
            }

            if ($events !== []) {
                /** @var list<array<string, mixed>> $entries */
                $entries = $after['lifecycleDeliveries'] ?? [];
                $revision = DeliveryLedger::nextRevision($entries);
                $types = [];

                foreach ($events as $type) {
                    if (in_array($type, [LifecycleEventType::OrderCreated, LifecycleEventType::OrderDeleted], true) || isset($types[$type->value])) {
                        throw new OrderDataException();
                    }

                    $types[$type->value] = true;
                    $event = DeliveryLedger::event($after, $content, $type, $revision, $triggerType, $triggerId);
                    $entries[] = DeliveryLedger::pending($event);
                    $deliveryIds[] = $event->deliveryId();
                }

                $after['lifecycleDeliveries'] = $entries;
                $after = OrderSerializer::normalize($after);
            }

            $this->persist($page, [...$content, ...OrderSerializer::encode($after)], 'default');
            $updated = $this->requirePage($page->id());

            if (OrderSerializer::hash($this->data($updated)) !== OrderSerializer::hash($after)) {
                throw new OrderStorageException('persistence.verify_failed');
            }

            // Canonical writes bypass ModelCommit and its cache invalidation.
            if ($orderChanged) {
                $this->kirby->cache('pages')->flush();
            }

            return $updated;
        });

        foreach ($deliveryIds as $deliveryId) {
            (new OrderLifecycle($this->kirby))->deliver($uuid, $deliveryId);
        }

        return $deliveryIds === [] ? $updated : $this->requirePage($pageId);
    }

    /** Internal single-order primitive; no scan, route or automatic execution. */
    public function deleteEligible(string $uuid, RetentionPolicy $policy, DateTimeImmutable $now): bool
    {
        OrderData::uuid($uuid);
        $pageId = OrderSchema::CONTAINER . '/' . (new Uri($uuid))->host();
        $deletion = OrderWriteLock::run($this->kirby, $pageId, function () use ($pageId, $policy, $now): ?array {
            // An earlier cleanup candidate may since have been paid. Eligibility
            // must be checked again against the record protected by this lock.
            $page = $this->requirePage($pageId);
            $data = $this->data($page);

            if ($policy->isEligible($data, $now) === false) {
                return null;
            }

            $customFields = array_filter($page->version('latest')->read('default') ?? [], static fn(string $field): bool => OrderSchema::isReserved($field) === false, ARRAY_FILTER_USE_KEY);
            /** @var list<array<string, mixed>> $entries */
            $entries = $data['lifecycleDeliveries'] ?? [];
            $data['updatedAt'] = OrderData::timestamp($now);
            $event = DeliveryLedger::event($data, $customFields, LifecycleEventType::OrderDeleted, DeliveryLedger::nextRevision($entries));

            try {
                $this->kirby->impersonate('kirby', fn(): bool => $page->deleteStoredOrder());
            } catch (Throwable) {
                // A native after-hook can throw after deletion. Verify storage
                // before deciding whether the committed deletion failed.
                if ($this->container()?->drafts()->find($pageId) !== null) {
                    throw new OrderStorageException('persistence.write_failed');
                }
            }

            if ($this->container()?->drafts()->find($pageId) !== null) {
                throw new OrderStorageException('persistence.verify_failed');
            }

            $this->kirby->cache('pages')->flush();

            return [$page, $event];
        });

        if ($deletion === null) {
            return false;
        }

        (new OrderLifecycle($this->kirby))->deleted($deletion[0], $deletion[1]);

        return true;
    }

    /** @param array<string, mixed> $fields */
    public function updateCustomFields(string $pageId, array $fields, ?string $languageCode): OrderPage
    {
        $fields = OrderCustomFieldsValidator::validate($fields);

        return OrderWriteLock::run($this->kirby, $pageId, function () use ($pageId, $fields, $languageCode): OrderPage {
            $page = $this->requirePage($pageId);
            $languageCode ??= $this->kirby->languageCode() ?? 'default';
            $content = $page->version('latest')->read($languageCode) ?? [];
            $this->persist($page, [...$content, ...$fields], $languageCode);

            return $this->requirePage($pageId);
        });
    }

    /** @param array<string, mixed> $content */
    private function persist(OrderPage $page, array $content, string $languageCode): void
    {
        try {
            $this->kirby->impersonate('kirby', fn(): OrderPage => $page->persistOrderContent($content, $languageCode));
        } catch (Throwable) {
            throw new OrderStorageException('persistence.write_failed');
        }
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function validateTransition(array $before, array $after): void
    {
        /** @var list<array<string, mixed>> $previousDeliveries */
        $previousDeliveries = $before['lifecycleDeliveries'] ?? [];
        /** @var list<array<string, mixed>> $deliveries */
        $deliveries = $after['lifecycleDeliveries'] ?? [];
        DeliveryLedger::validateTransition($previousDeliveries, $deliveries);

        $immutableFields = ['uuid', 'title', 'orderNumber', 'stripeCheckout', 'checkoutAttempt', 'userUuid', 'languageCode', 'currency', 'createdAt', 'initiatingLineItems'];

        foreach ($immutableFields as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                throw new OrderDataException();
            }
        }

        // These record the first observation; repeated evidence must not move
        // their timestamps or erase the history of an earlier state.
        $observationFields = ['checkoutOpenedAt', 'creationUncertainAt', 'creationFailedAt', 'checkoutCompletedAt', 'checkoutExpiredAt', 'paidAt', 'paymentFailedAt'];

        foreach ($observationFields as $field) {
            if (isset($before[$field]) && ($after[$field] ?? null) !== $before[$field]) {
                throw new OrderDataException();
            }
        }

        $allowedCheckoutStatuses = match (CheckoutStatus::from(OrderData::string($before['checkoutStatus']))) {
            CheckoutStatus::Creating => ['creating', 'creation_uncertain', 'creation_failed', 'open', 'complete', 'expired'],
            CheckoutStatus::CreationUncertain => ['creation_uncertain', 'creation_failed', 'open', 'complete', 'expired'],
            CheckoutStatus::Open => ['open', 'complete', 'expired'],
            default => [$before['checkoutStatus']],
        };

        if (
            in_array($after['checkoutStatus'], $allowedCheckoutStatuses, true) === false
            || isset($before['stripeCheckoutSessionId']) && ($after['stripeCheckoutSessionId'] ?? null) !== $before['stripeCheckoutSessionId']
            || in_array($before['paymentStatus'], ['paid', 'no_payment_required'], true) && $after['paymentStatus'] !== $before['paymentStatus']
            || $before['paymentStatus'] === 'failed' && in_array($after['paymentStatus'], ['failed', 'paid'], true) === false
        ) {
            throw new OrderDataException();
        }
    }

    private function requireUuids(): void
    {
        if (Uuids::enabled() === false) {
            throw new OrderStorageException('persistence.uuid_unavailable');
        }
    }
}
