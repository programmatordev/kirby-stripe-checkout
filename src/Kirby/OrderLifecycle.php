<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Cms\Events;
use Kirby\Cms\Page;
use Kirby\Data\Data;
use ProgrammatorDev\StripeCheckout\Lifecycle\Internal\DeliveryLedger;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEvent;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use Throwable;

/** @internal Invokes native hooks after commits; failures never undo order state. */
final class OrderLifecycle
{
    /** @var array<string, true> Request-local recursion guard, not a cross-process delivery lock. */
    private static array $active = [];

    public function __construct(private readonly App $kirby) {}

    /** A primitive for pending/failed deliveries; not a public retry route. */
    public function deliver(string $uuid, string $deliveryId): void
    {
        $key = $uuid . ':' . $deliveryId;

        if (isset(self::$active[$key])) {
            return;
        }

        self::$active[$key] = true;

        try {
            $store = new OrderPageStore($this->kirby);
            $event = null;
            $page = $store->update($uuid, static function (array $data) use ($deliveryId, &$event): array {
                /** @var list<array<string, mixed>> $entries */
                $entries = $data['lifecycleDeliveries'] ?? [];

                foreach ($entries as &$entry) {
                    $candidate = DeliveryLedger::restoreEvent(OrderData::map($entry['event']));

                    if ($candidate->deliveryId() === $deliveryId && $entry['status'] !== 'delivered') {
                        // Record the attempt before invoking listeners. A process
                        // exit leaves the original event available for another try.
                        $event = $candidate;
                        $entry['attempts'] = OrderData::integer($entry['attempts']) + 1;
                        $entry['lastAttemptAt'] = max(OrderData::timestamp(new DateTimeImmutable()), OrderData::timestamp($event->occurredAt()));

                        break;
                    }
                }

                if ($event !== null) {
                    $data['lifecycleDeliveries'] = $entries;
                }

                return $data;
            });

            if ($event === null) {
                return;
            }

            // The write lock and virtual Kirby identity have both ended.
            $delivered = $this->invoke($page, $event);
            $store->update($uuid, static function (array $data) use ($deliveryId, $delivered): array {
                /** @var list<array<string, mixed>> $entries */
                $entries = $data['lifecycleDeliveries'] ?? [];

                foreach ($entries as &$entry) {
                    $eventData = OrderData::map($entry['event']);

                    if ($eventData['deliveryId'] === $deliveryId) {
                        // A successful concurrent attempt wins over a later
                        // failure. Native hook consumers still deduplicate effects.
                        if ($entry['status'] !== 'delivered') {
                            $entry['status'] = $delivered ? 'delivered' : 'failed';
                            $entry['errorCode'] = $delivered ? null : 'lifecycle.listener_failed';
                        }

                        break;
                    }
                }

                $data['lifecycleDeliveries'] = $entries;

                return $data;
            });
        } catch (Throwable) {
            // Even a failure to record the outcome must not turn a committed
            // payment into an apparent failure. Pending intent remains replayable.
            error_log('Stripe Checkout: lifecycle.delivery_record_failed');
        } finally {
            unset(self::$active[$key]);
        }
    }

    public function deleted(Page $order, LifecycleEvent $event): void
    {
        $delivered = $this->invoke($order, $event);

        try {
            // Keep only the last sanitized outcome, not a deleted customer's
            // snapshot or a second durable order archive merely for hook retries.
            $written = Data::write($this->deletionOutcomePath(), [
                'deliveryId' => $event->deliveryId(),
                'occurredAt' => OrderData::timestamp($event->occurredAt()),
                'status' => $delivered ? 'delivered' : 'failed',
                'errorCode' => $delivered ? null : 'lifecycle.listener_failed',
            ], 'json');

            if ($written === false) {
                error_log('Stripe Checkout: lifecycle.delivery_record_failed');
            }
        } catch (Throwable) {
            error_log('Stripe Checkout: lifecycle.delivery_record_failed');
        }
    }

    public function hasFailedDeletionDelivery(): bool
    {
        $path = $this->deletionOutcomePath();

        if (is_file($path) === false) {
            return false;
        }

        try {
            $outcome = Data::read($path, 'json');

            return ($outcome['status'] ?? null) !== 'delivered';
        } catch (Throwable) {
            return true;
        }
    }

    private function invoke(Page $order, LifecycleEvent $event): bool
    {
        $languageCode = $this->kirby->languageCode();

        try {
            // The initiating content language, not the Panel/webhook request's,
            // determines localized project work. A removed language falls back natively.
            $this->kirby->setCurrentLanguage($event->languageCode());
            // Kirby's shared Events instance retains processed listeners when
            // one throws. An independent native dispatcher keeps later deliveries
            // callable without replacing registration, wildcards or named arguments.
            (new Events($this->kirby))->trigger('programmatordev.stripe-checkout.' . $event->type()->value, [
                'order' => $order,
                'lifecycleEvent' => $event,
            ]);

            // Success means the hook returned without throwing, not that an
            // email arrived or another external effect completed exactly once.
            return true;
        } catch (Throwable) {
            // Never persist the exception message: listeners may include PII or credentials.
            return false;
        } finally {
            $this->kirby->setCurrentLanguage($languageCode);
        }
    }

    private function deletionOutcomePath(): string
    {
        return $this->kirby->root('site') . '/storage/stripe-checkout/lifecycle-last-deletion.json';
    }
}
