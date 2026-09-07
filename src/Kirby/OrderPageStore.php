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
        $context = new OrderCreationContext($uuid, (new OrderNumberFormatter($formatter))->format($uuid), $sourceType, $cartRevision, $userUuid, $languageCode, $uiMode, $currency, $lineItems);
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

        return $created;
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
            $id = OrderData::text((new Uri($uuid))->host());
        } catch (Throwable) {
            return null;
        }

        try {
            $this->requireUuids();
            $page = $this->container()?->drafts()->find($id);

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

    public function requirePage(string $id): OrderPage
    {
        $this->requireUuids();
        $container = $this->container();
        $page = $container?->drafts()->find($id);

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

    /** @param Closure(array<string, mixed>): array<string, mixed> $reduce */
    public function update(string $uuid, Closure $reduce): OrderPage
    {
        OrderData::uuid($uuid);
        $id = OrderSchema::CONTAINER . '/' . (new Uri($uuid))->host();

        return OrderWriteLock::run($this->kirby, $id, function () use ($id, $reduce): OrderPage {
            $page = $this->requirePage($id);
            $before = $this->data($page);
            $candidate = $reduce($before);

            if (OrderData::normalize($before) === OrderData::normalize($candidate)) {
                return $page;
            }

            $updatedAt = OrderData::string($candidate['updatedAt'] ?? null);

            if ($updatedAt < $before['updatedAt']) {
                throw new OrderDataException();
            }

            // Stamp the commit before validating newly observed timestamps.
            // Reducers need not advance updatedAt just to record an observation.
            $candidate['updatedAt'] = max(OrderData::timestamp(new DateTimeImmutable()), $updatedAt);
            $after = OrderSerializer::normalize($candidate);
            $this->validateTransition($before, $after);
            $content = $page->version('latest')->read('default') ?? [];

            foreach (array_keys($content) as $field) {
                if (OrderSchema::isReserved($field)) {
                    unset($content[$field]);
                }
            }

            $this->persist($page, [...$content, ...OrderSerializer::encode($after)], 'default');
            $updated = $this->requirePage($page->id());

            if (OrderSerializer::hash($this->data($updated)) !== OrderSerializer::hash($after)) {
                throw new OrderStorageException('persistence.verify_failed');
            }

            return $updated;
        });
    }

    /** @param array<string, mixed> $fields */
    public function updateCustomFields(string $id, array $fields, ?string $languageCode): OrderPage
    {
        $fields = OrderCustomFieldsValidator::validate($fields);

        return OrderWriteLock::run($this->kirby, $id, function () use ($id, $fields, $languageCode): OrderPage {
            $page = $this->requirePage($id);
            $languageCode ??= $this->kirby->languageCode() ?? 'default';
            $content = $page->version('latest')->read($languageCode) ?? [];
            $this->persist($page, [...$content, ...$fields], $languageCode);

            return $this->requirePage($id);
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
        $immutable = ['uuid', 'title', 'orderNumber', 'stripeCheckout', 'checkoutAttempt', 'userUuid', 'languageCode', 'currency', 'createdAt', 'initiatingLineItems'];

        foreach ($immutable as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                throw new OrderDataException();
            }
        }

        $observations = ['checkoutOpenedAt', 'creationUncertainAt', 'creationFailedAt', 'checkoutCompletedAt', 'checkoutExpiredAt', 'paidAt', 'paymentFailedAt'];

        foreach ($observations as $field) {
            if (isset($before[$field]) && ($after[$field] ?? null) !== $before[$field]) {
                throw new OrderDataException();
            }
        }

        $allowed = match (CheckoutStatus::from(OrderData::string($before['checkoutStatus']))) {
            CheckoutStatus::Creating => ['creating', 'creation_uncertain', 'creation_failed', 'open', 'complete', 'expired'],
            CheckoutStatus::CreationUncertain => ['creation_uncertain', 'creation_failed', 'open', 'complete', 'expired'],
            CheckoutStatus::Open => ['open', 'complete', 'expired'],
            default => [$before['checkoutStatus']],
        };

        if (
            in_array($after['checkoutStatus'], $allowed, true) === false
            || $after['updatedAt'] < $before['updatedAt']
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
