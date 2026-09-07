<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Lifecycle\Internal;

use Kirby\Uuid\Uri;
use Kirby\Uuid\Uuid;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEvent;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\DisputeStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;

/** @internal Persisted event-time facts and hook outcomes, separate from Stripe's event ledger. */
final class DeliveryLedger
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $customFields
     */
    public static function event(array $data, array $customFields, LifecycleEventType $type, int $revision, ?string $triggerType = null, ?string $triggerId = null): LifecycleEvent
    {
        // Never nest older delivery snapshots inside a new snapshot. Event-time
        // custom content is retained alongside normalized canonical facts.
        unset($data['lifecycleDeliveries']);

        return new LifecycleEvent(
            deliveryId: Uuid::generate(),
            type: $type,
            pageUuid: (new Uri([
                'scheme' => 'page',
                'host' => OrderData::text($data['uuid']),
            ]))->toString(),
            occurredAt: OrderData::date($data['updatedAt']),
            revision: $revision,
            languageCode: OrderData::nullableString($data['languageCode'] ?? null),
            checkoutStatus: CheckoutStatus::from(OrderData::text($data['checkoutStatus'])),
            paymentStatus: PaymentStatus::from(OrderData::text($data['paymentStatus'])),
            refundStatus: RefundStatus::from(OrderData::text($data['refundStatus'])),
            disputeStatus: DisputeStatus::from(OrderData::text($data['disputeStatus'])),
            triggerType: $triggerType,
            triggerId: $triggerId,
            orderSnapshot: [...$customFields, ...$data],
        );
    }

    /** @return array<string, mixed> */
    public static function pending(LifecycleEvent $event): array
    {
        return [
            'event' => $event->toArray(),
            'status' => 'pending',
            'attempts' => 0,
            'lastAttemptAt' => null,
            'errorCode' => null,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function restoreEvent(array $data): LifecycleEvent
    {
        OrderData::validateAllowedKeys($data, ['deliveryId', 'type', 'pageUuid', 'occurredAt', 'revision', 'languageCode', 'checkoutStatus', 'paymentStatus', 'refundStatus', 'disputeStatus', 'triggerType', 'triggerId', 'orderSnapshot']);
        $snapshot = OrderData::map($data['orderSnapshot'] ?? null);

        if (array_key_exists('lifecycleDeliveries', $snapshot) || ($data['occurredAt'] ?? null) !== ($snapshot['updatedAt'] ?? null)) {
            throw new OrderDataException();
        }

        // Decode only the known canonical projection, never a raw provider map.
        OrderSerializer::normalize(array_intersect_key($snapshot, array_flip(OrderSchema::fields())));

        return new LifecycleEvent(
            deliveryId: OrderData::text($data['deliveryId'] ?? null),
            type: LifecycleEventType::from(OrderData::text($data['type'] ?? null)),
            pageUuid: OrderData::text($data['pageUuid'] ?? null),
            occurredAt: OrderData::date($data['occurredAt'] ?? null),
            revision: OrderData::integer($data['revision'] ?? null),
            languageCode: OrderData::nullableString($data['languageCode'] ?? null),
            checkoutStatus: CheckoutStatus::from(OrderData::text($data['checkoutStatus'] ?? null)),
            paymentStatus: PaymentStatus::from(OrderData::text($data['paymentStatus'] ?? null)),
            refundStatus: RefundStatus::from(OrderData::text($data['refundStatus'] ?? null)),
            disputeStatus: DisputeStatus::from(OrderData::text($data['disputeStatus'] ?? null)),
            triggerType: OrderData::nullableString($data['triggerType'] ?? null),
            triggerId: OrderData::nullableString($data['triggerId'] ?? null),
            orderSnapshot: $snapshot,
        );
    }

    /** @return list<array<string, mixed>> */
    public static function normalize(mixed $value, string $uuid): array
    {
        $entries = [];
        $ids = [];
        $revision = 0;

        foreach (OrderData::list($value) as $entry) {
            $entry = OrderData::map($entry);
            OrderData::validateAllowedKeys($entry, ['event', 'status', 'attempts', 'lastAttemptAt', 'errorCode']);
            OrderData::validateRequiredKeys($entry, ['event', 'status', 'attempts', 'lastAttemptAt', 'errorCode']);
            $event = self::restoreEvent(OrderData::map($entry['event']));
            $attempts = OrderData::integer($entry['attempts']);

            if (
                $event->orderSnapshot()['uuid'] !== $uuid
                || isset($ids[$event->deliveryId()])
                || $event->revision() < $revision
                || $event->type() === LifecycleEventType::OrderDeleted
                || in_array($entry['status'], ['pending', 'delivered', 'failed'], true) === false
                || $attempts < 0
                || ($attempts === 0) !== ($entry['lastAttemptAt'] === null)
                || $entry['status'] !== 'pending' && $attempts === 0
                || $entry['errorCode'] !== ($entry['status'] === 'failed' ? 'lifecycle.listener_failed' : null)
            ) {
                throw new OrderDataException();
            }

            if ($entry['lastAttemptAt'] !== null && OrderData::date($entry['lastAttemptAt']) < $event->occurredAt()) {
                throw new OrderDataException();
            }

            $ids[$event->deliveryId()] = true;
            $revision = $event->revision();
            $entry['event'] = $event->toArray();
            $entries[] = $entry;
        }

        return $entries;
    }

    /** @param list<array<string, mixed>> $entries */
    public static function nextRevision(array $entries): int
    {
        // Only event-bearing commits advance this sequence. Events appended
        // by one commit share a revision; retry outcomes do not consume one.
        $last = $entries === [] ? null : $entries[array_key_last($entries)];

        return $last === null ? 1 : self::restoreEvent(OrderData::map($last['event']))->revision() + 1;
    }

    /**
     * @param list<array<string, mixed>> $before
     * @param list<array<string, mixed>> $after
     */
    public static function validateTransition(array $before, array $after): void
    {
        // Existing event facts are append-only. Only their delivery bookkeeping
        // may advance, so retries retain the original identity and snapshot.
        foreach ($before as $index => $entry) {
            $updated = $after[$index] ?? null;

            if (
                $updated === null
                || OrderData::normalize($updated['event']) !== OrderData::normalize($entry['event'])
                || $updated['attempts'] < $entry['attempts']
                || $entry['status'] === 'delivered' && $updated['status'] !== 'delivered'
                || $entry['lastAttemptAt'] !== null && $updated['lastAttemptAt'] < $entry['lastAttemptAt']
            ) {
                throw new OrderDataException();
            }
        }
    }
}
