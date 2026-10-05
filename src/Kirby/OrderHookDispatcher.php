<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Cms\Events;
use Kirby\Cms\Page;
use Kirby\Data\Data;
use ProgrammatorDev\StripeCheckout\Lifecycle\Internal\DeliveryResult;
use ProgrammatorDev\StripeCheckout\Lifecycle\Internal\HookDeliveryLedger;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleErrorCode;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEvent;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use Throwable;

/** @internal Invokes native hooks after commits; failures never undo order state. */
final class OrderHookDispatcher
{
    /** @var array<string, true> Request-local recursion guard, not a cross-process delivery lock. */
    private static array $active = [];

    public function __construct(private readonly App $kirby) {}

    /**
     * Attempts pending/failed deliveries after canonical commits; observer failures remain isolated.
     * An explicit attempt time supports deterministic checks; otherwise it is resolved after the locked reload.
     */
    public function dispatch(string $uuid, string $deliveryId, ?DateTimeImmutable $attemptedAt = null): void
    {
        $this->attempt(uuid: $uuid, deliveryId: $deliveryId, attemptedAt: $attemptedAt, failedOnly: false);
    }

    /**
     * Explicit failed-delivery retry; preserves the original event and deadline. No retry scheduling.
     * An omitted attempt time is resolved after the locked reload.
     * Later order transitions do not invalidate a retained historical event.
     * Retrying can invoke listeners that succeeded before another listener failed.
     */
    public function retryFailed(string $uuid, string $deliveryId, ?DateTimeImmutable $attemptedAt = null): DeliveryResult
    {
        return $this->attempt(uuid: $uuid, deliveryId: $deliveryId, attemptedAt: $attemptedAt, failedOnly: true);
    }

    private function attempt(string $uuid, string $deliveryId, ?DateTimeImmutable $attemptedAt, bool $failedOnly): DeliveryResult
    {
        $key = $uuid . ':' . $deliveryId;

        if (isset(self::$active[$key])) {
            return DeliveryResult::failed(LifecycleErrorCode::DELIVERY_NOT_RETRYABLE);
        }

        self::$active[$key] = true;

        try {
            $store = new OrderPageStore($this->kirby);
            $event = null;
            $errorCode = LifecycleErrorCode::DELIVERY_NOT_FOUND;
            $page = $store->update($uuid, static function (array $data) use ($deliveryId, $attemptedAt, $failedOnly, &$event, &$errorCode): array {
                /** @var list<array<string, mixed>> $entries */
                $entries = $data['lifecycleDeliveries'] ?? [];

                foreach ($entries as &$entry) {
                    $eventData = OrderData::map($entry['event']);

                    if ($eventData['deliveryId'] !== $deliveryId) {
                        continue;
                    }

                    $errorCode = LifecycleErrorCode::DELIVERY_NOT_RETRYABLE;

                    if ($entry['status'] === 'delivered') {
                        break;
                    }

                    if ($failedOnly && $entry['status'] !== 'failed') {
                        break;
                    }

                    // Pruned entries retain identity metadata but cannot restore a replayable event.
                    if ($entry['payloadPrunedAt'] !== null) {
                        break;
                    }

                    // Resolve the clock after lock acquisition and reload; waiting must not admit an expired retry.
                    $attemptedAt ??= new DateTimeImmutable();

                    if (HookDeliveryLedger::isExpired($entry, $attemptedAt)) {
                        break;
                    }

                    $event = HookDeliveryLedger::restoreEvent($eventData);
                    // Persist admission before invoking listeners. Retries never renew the payload deadline.
                    $entry['attempts'] = OrderData::integer($entry['attempts']) + 1;
                    // Keep history monotonic when the clock moves backward; eligibility uses the actual attempt time.
                    $entry['lastAttemptAt'] = max(
                        $entry['lastAttemptAt'],
                        OrderData::timestamp($attemptedAt),
                        OrderData::timestamp($event->occurredAt()),
                    );

                    break;
                }

                if ($event !== null) {
                    $data['lifecycleDeliveries'] = $entries;
                }

                return $data;
            });

            if ($event === null) {
                return DeliveryResult::failed($errorCode);
            }

            // The write lock and virtual Kirby identity have both ended.
            $delivered = $this->invoke($page, $event);
            $store->update($uuid, static function (array $data) use ($deliveryId, &$delivered): array {
                /** @var list<array<string, mixed>> $entries */
                $entries = $data['lifecycleDeliveries'] ?? [];

                foreach ($entries as &$entry) {
                    $eventData = OrderData::map($entry['event']);

                    if ($eventData['deliveryId'] === $deliveryId) {
                        // A successful concurrent attempt wins over a later failure, including in the returned outcome.
                        // Native hook consumers still deduplicate effects.
                        if ($entry['status'] === 'delivered') {
                            $delivered = true;
                        } else {
                            $entry['status'] = $delivered ? 'delivered' : 'failed';
                            $entry['errorCode'] = $delivered ? null : LifecycleErrorCode::LISTENER_FAILED;
                        }

                        break;
                    }
                }

                $data['lifecycleDeliveries'] = $entries;

                return $data;
            });

            return $delivered ? DeliveryResult::delivered() : DeliveryResult::failed(LifecycleErrorCode::LISTENER_FAILED);
        } catch (OrderStorageException $error) {
            error_log('Stripe Checkout: ' . LifecycleErrorCode::DELIVERY_RECORD_FAILED);

            return DeliveryResult::failed($error->errorCode());
        } catch (Throwable) {
            // Bookkeeping failure cannot undo canonical success or expose private listener/storage exceptions.
            error_log('Stripe Checkout: ' . LifecycleErrorCode::DELIVERY_RECORD_FAILED);

            return DeliveryResult::failed(LifecycleErrorCode::DELIVERY_RECORD_FAILED);
        } finally {
            unset(self::$active[$key]);
        }
    }

    public function dispatchDeletion(OrderDeletion $deletion): void
    {
        $event = $deletion->event();
        $delivered = $this->invoke($deletion->order(), $event);

        try {
            // Keep only the last sanitized outcome, not a deleted customer's snapshot or a second durable order archive merely for hook retries.
            $written = Data::write($this->deletionOutcomePath(), [
                'deliveryId' => $event->deliveryId(),
                'occurredAt' => OrderData::timestamp($event->occurredAt()),
                'status' => $delivered ? 'delivered' : 'failed',
                'errorCode' => $delivered ? null : LifecycleErrorCode::LISTENER_FAILED,
            ], 'json');

            if ($written === false) {
                error_log('Stripe Checkout: ' . LifecycleErrorCode::DELIVERY_RECORD_FAILED);
            }
        } catch (Throwable) {
            error_log('Stripe Checkout: ' . LifecycleErrorCode::DELIVERY_RECORD_FAILED);
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
            // The initiating content language, not the Panel/webhook request's, determines localized project work.
            // A removed language falls back natively.
            $this->kirby->setCurrentLanguage($event->languageCode());
            // Kirby's shared Events instance retains processed listeners when one throws.
            // An independent native dispatcher keeps later deliveries callable without replacing registration, wildcards or named arguments.
            (new Events($this->kirby))->trigger('programmatordev.stripe-checkout.' . $event->type()->value, [
                'order' => $order,
                'lifecycleEvent' => $event,
            ]);

            // Success means the hook returned without throwing, not that an email arrived or another external effect completed exactly once.
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
