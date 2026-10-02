<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Lifecycle\Internal;

use DateInterval;
use DateTimeImmutable;
use Kirby\Uuid\Uri;
use Kirby\Uuid\Uuid;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleErrorCode;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEvent;
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEventType;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\DisputeStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSerializer;
use ProgrammatorDev\StripeCheckout\Order\PaymentAction;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;

/** @internal Persisted event-time facts and hook outcomes, separate from Stripe's event ledger. */
final class HookDeliveryLedger
{
    private const EVENT_METADATA_KEYS = ['deliveryId', 'type', 'pageUuid', 'occurredAt', 'revision', 'triggerType', 'triggerId'];

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $customFields
     */
    public static function event(array $data, array $customFields, LifecycleEventType $type, int $revision, ?string $triggerType = null, ?string $triggerId = null, ?PaymentAction $nextAction = null): LifecycleEvent
    {
        // Never nest older delivery snapshots inside a new snapshot.
        // Event-time custom content is retained alongside normalized canonical facts.
        unset($data['lifecycleDeliveries'], $data['events']);

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
            nextAction: $nextAction,
        );
    }

    /** @return array<string, mixed> */
    public static function pending(LifecycleEvent $event, int $lifecycleDeliveryPayloadRetentionDays, ?DateTimeImmutable $now = null): array
    {
        // Calculate in UTC so daylight-saving changes cannot lengthen or shorten the retained replay window.
        $createdAt = OrderData::date(OrderData::timestamp($now ?? new DateTimeImmutable()));

        // The payload's replay window starts when delivery intent is saved, not at a potentially older provider/business timestamp.
        // Retries and subsequent configuration changes never extend this original deadline.
        // Expiry ends replay eligibility; it does not expire the delivery identity or its sanitized outcome.
        return [
            'event' => $event->toArray(),
            'actionFingerprint' => self::actionFingerprint($event->payment()?->nextAction()),
            'createdAt' => OrderData::timestamp($createdAt),
            'expiresAt' => OrderData::timestamp($createdAt->add(new DateInterval('P' . $lifecycleDeliveryPayloadRetentionDays . 'D'))),
            'payloadPrunedAt' => null,
            'status' => 'pending',
            'attempts' => 0,
            'lastAttemptAt' => null,
            'errorCode' => null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries Validated persistence entries, selected under the order lock.
     * @return list<array<string, mixed>>
     */
    public static function prunePayloads(array $entries, DateTimeImmutable $now): array
    {
        foreach ($entries as &$entry) {
            // Successful delivery ends retry eligibility, not the shared payload retention period.
            if ($entry['payloadPrunedAt'] !== null || self::isExpired($entry, $now) === false) {
                continue;
            }

            // Keep identity, ordering and trigger evidence; remove customer snapshots and private action data together.
            $entry['event'] = self::eventMetadata(OrderData::map($entry['event']));
            $entry['payloadPrunedAt'] = OrderData::timestamp($now);
        }

        return $entries;
    }

    /** @param array<string, mixed> $entry Persisted delivery metadata. */
    public static function isExpired(array $entry, DateTimeImmutable $now): bool
    {
        return OrderData::date($entry['expiresAt']) <= $now;
    }

    /** @param array<string, mixed> $data */
    public static function restoreEvent(array $data): LifecycleEvent
    {
        OrderData::validateAllowedKeys($data, ['deliveryId', 'type', 'pageUuid', 'occurredAt', 'revision', 'languageCode', 'checkoutStatus', 'paymentStatus', 'refundStatus', 'disputeStatus', 'triggerType', 'triggerId', 'orderSnapshot', 'nextAction']);
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
            nextAction: ($data['nextAction'] ?? null) === null ? null : PaymentAction::fromJson(OrderData::string($data['nextAction'])),
        );
    }

    /** @return list<array<string, mixed>> */
    public static function normalize(mixed $value, string $uuid): array
    {
        // Expired entries remain valid stored evidence until cleanup; expiry controls dispatch, not order readability.
        $entries = [];
        $ids = [];
        $revision = 0;

        foreach (OrderData::list($value) as $entry) {
            $entry = OrderData::map($entry);
            $keys = ['event', 'actionFingerprint', 'createdAt', 'expiresAt', 'status', 'attempts', 'lastAttemptAt', 'errorCode'];
            OrderData::validateAllowedKeys($entry, [...$keys, 'payloadPrunedAt']);
            OrderData::validateRequiredKeys($entry, $keys);
            // Entries written before pruning support still carry their complete payload.
            $entry['payloadPrunedAt'] ??= null;
            $eventData = OrderData::map($entry['event']);

            if ($entry['payloadPrunedAt'] === null) {
                $event = self::restoreEvent($eventData);
                $eventData = $event->toArray();

                if ($entry['actionFingerprint'] !== self::actionFingerprint($event->payment()?->nextAction())) {
                    throw new OrderDataException();
                }
            } else {
                self::validatePrunedEvent($eventData);
                $prunedAt = OrderData::date($entry['payloadPrunedAt']);

                if (
                    $prunedAt < OrderData::date($entry['createdAt'])
                    || $prunedAt < OrderData::date($entry['expiresAt'])
                ) {
                    throw new OrderDataException();
                }

                // The removed action cannot be hashed again; validate the retained fingerprint's shape here.
                // Transition validation preserves its original value across cleanup.
                if ($eventData['type'] === LifecycleEventType::PaymentRequiresAction->value) {
                    if (is_string($entry['actionFingerprint']) === false || preg_match('/\A[a-f0-9]{64}\z/', $entry['actionFingerprint']) !== 1) {
                        throw new OrderDataException();
                    }
                } elseif ($entry['actionFingerprint'] !== null) {
                    throw new OrderDataException();
                }
            }

            $deliveryId = OrderData::string($eventData['deliveryId']);
            $eventRevision = OrderData::integer($eventData['revision']);

            if ($eventData['pageUuid'] !== 'page://' . $uuid || isset($ids[$deliveryId])) {
                throw new OrderDataException();
            }

            if ($eventRevision < $revision) {
                throw new OrderDataException();
            }

            // Deletion is a one-shot notification; this ledger must not retain a deleted order's snapshot.
            if ($eventData['type'] === LifecycleEventType::OrderDeleted->value) {
                throw new OrderDataException();
            }

            if (OrderData::date($entry['expiresAt']) <= OrderData::date($entry['createdAt'])) {
                throw new OrderDataException();
            }

            self::validateDeliveryOutcome($entry, OrderData::string($eventData['occurredAt']));
            $ids[$deliveryId] = true;
            $revision = $eventRevision;
            $entry['event'] = $eventData;
            $entries[] = $entry;
        }

        return $entries;
    }

    /** @param array<string, mixed> $entry */
    private static function validateDeliveryOutcome(array $entry, string $occurredAt): void
    {
        if (in_array($entry['status'], ['pending', 'delivered', 'failed'], true) === false) {
            throw new OrderDataException();
        }

        $attempts = OrderData::integer($entry['attempts']);

        // Attempts are saved before listeners run; an interrupted process can leave pending status with a nonzero count.
        if (
            $attempts < 0
            || ($attempts === 0) !== ($entry['lastAttemptAt'] === null)
            || $entry['status'] !== 'pending' && $attempts === 0
        ) {
            throw new OrderDataException();
        }

        if ($entry['errorCode'] !== ($entry['status'] === 'failed' ? LifecycleErrorCode::LISTENER_FAILED : null)) {
            throw new OrderDataException();
        }

        if ($entry['lastAttemptAt'] !== null && OrderData::date($entry['lastAttemptAt']) < OrderData::date($occurredAt)) {
            throw new OrderDataException();
        }
    }

    /** @param list<array<string, mixed>> $entries */
    public static function nextRevision(array $entries): int
    {
        // Only event-bearing commits advance this sequence.
        // Events appended by one commit share a revision; retry outcomes do not consume one.
        // Read retained metadata so pruning the last payload cannot reset the sequence.
        $last = $entries === [] ? null : $entries[array_key_last($entries)];

        return $last === null ? 1 : OrderData::integer(OrderData::map($last['event'])['revision']) + 1;
    }

    /** @param array<string, mixed> $event */
    private static function validatePrunedEvent(array $event): void
    {
        OrderData::validateAllowedKeys($event, self::EVENT_METADATA_KEYS);
        OrderData::validateRequiredKeys($event, self::EVENT_METADATA_KEYS);
        OrderData::text($event['deliveryId'], 255);
        LifecycleEventType::from(OrderData::text($event['type']));
        OrderData::uuid(OrderData::text($event['pageUuid']));
        OrderData::date($event['occurredAt']);

        if (OrderData::integer($event['revision']) < 1 || ($event['triggerType'] === null) !== ($event['triggerId'] === null)) {
            throw new OrderDataException();
        }

        $triggerKeys = ['triggerType', 'triggerId'];

        foreach ($triggerKeys as $key) {
            if ($event[$key] !== null) {
                OrderData::text($event[$key], 255);
            }
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed> Sanitized persistence metadata, not a replayable lifecycle envelope.
     */
    private static function eventMetadata(array $event): array
    {
        return array_intersect_key($event, array_flip(self::EVENT_METADATA_KEYS));
    }

    private static function actionFingerprint(?PaymentAction $nextAction): ?string
    {
        return $nextAction === null ? null : hash('sha256', $nextAction->toJson());
    }

    /** @param list<array<string, mixed>> $entries */
    public static function hasAction(array $entries, PaymentAction $nextAction): bool
    {
        $fingerprint = self::actionFingerprint($nextAction);

        // Retain the fingerprint outside the expiring payload, so cleanup cannot re-enable duplicate notifications.
        // A later, different action must not make an older identical Event's evidence appear new again.
        foreach ($entries as $entry) {
            if ($entry['actionFingerprint'] === $fingerprint) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $before
     * @param list<array<string, mixed>> $after
     */
    public static function validateTransition(array $before, array $after): void
    {
        // Event identity is append-only. A payload may be removed once, but never replaced or restored.
        // Keep the original replay window immutable as well; editing a deadline must not reactivate an expired delivery.
        foreach ($before as $index => $entry) {
            $updated = $after[$index] ?? null;

            if ($updated === null) {
                throw new OrderDataException();
            }

            $payloadRemoved = $entry['payloadPrunedAt'] === null && $updated['payloadPrunedAt'] !== null;
            $expectedEvent = $payloadRemoved ? self::eventMetadata(OrderData::map($entry['event'])) : $entry['event'];

            if (OrderData::normalize($updated['event']) !== OrderData::normalize($expectedEvent)) {
                throw new OrderDataException();
            }

            if ($entry['payloadPrunedAt'] !== null) {
                if ($updated['payloadPrunedAt'] !== $entry['payloadPrunedAt']) {
                    throw new OrderDataException();
                }

                // An attempt admitted before cleanup may still finish afterward, so its outcome can advance.
                // Once pruned, the attempt count and time stay fixed to prevent another dispatch.
                if ($updated['attempts'] !== $entry['attempts'] || $updated['lastAttemptAt'] !== $entry['lastAttemptAt']) {
                    throw new OrderDataException();
                }
            }

            $immutableFields = ['createdAt', 'expiresAt', 'actionFingerprint'];

            foreach ($immutableFields as $field) {
                if ($updated[$field] !== $entry[$field]) {
                    throw new OrderDataException();
                }
            }

            if ($updated['attempts'] < $entry['attempts']) {
                throw new OrderDataException();
            }

            // A successful concurrent listener wins over a later failure from another attempt.
            if ($entry['status'] === 'delivered' && $updated['status'] !== 'delivered') {
                throw new OrderDataException();
            }

            if ($entry['lastAttemptAt'] !== null && $updated['lastAttemptAt'] < $entry['lastAttemptAt']) {
                throw new OrderDataException();
            }
        }
    }
}
