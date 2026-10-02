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
            'status' => 'pending',
            'attempts' => 0,
            'lastAttemptAt' => null,
            'errorCode' => null,
        ];
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
            OrderData::validateAllowedKeys($entry, $keys);
            OrderData::validateRequiredKeys($entry, $keys);
            $event = self::restoreEvent(OrderData::map($entry['event']));
            $attempts = OrderData::integer($entry['attempts']);

            if (
                $event->orderSnapshot()['uuid'] !== $uuid
                || isset($ids[$event->deliveryId()])
                || $event->revision() < $revision
                || $event->type() === LifecycleEventType::OrderDeleted
                || in_array($entry['status'], ['pending', 'delivered', 'failed'], true) === false
                || $attempts < 0
                || OrderData::date($entry['expiresAt']) <= OrderData::date($entry['createdAt'])
                || ($attempts === 0) !== ($entry['lastAttemptAt'] === null)
                || $entry['status'] !== 'pending' && $attempts === 0
                || $entry['errorCode'] !== ($entry['status'] === 'failed' ? LifecycleErrorCode::LISTENER_FAILED : null)
                || $entry['actionFingerprint'] !== self::actionFingerprint($event->payment()?->nextAction())
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
        // Only event-bearing commits advance this sequence.
        // Events appended by one commit share a revision; retry outcomes do not consume one.
        $last = $entries === [] ? null : $entries[array_key_last($entries)];

        return $last === null ? 1 : self::restoreEvent(OrderData::map($last['event']))->revision() + 1;
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
        // Existing event facts are append-only.
        // Only their delivery bookkeeping may advance, so retries retain the original identity and snapshot.
        // Keep the original replay window immutable as well; editing a deadline must not reactivate an expired delivery.
        foreach ($before as $index => $entry) {
            $updated = $after[$index] ?? null;

            if (
                $updated === null
                || OrderData::normalize($updated['event']) !== OrderData::normalize($entry['event'])
                || $updated['createdAt'] !== $entry['createdAt']
                || $updated['expiresAt'] !== $entry['expiresAt']
                || $updated['actionFingerprint'] !== $entry['actionFingerprint']
                || $updated['attempts'] < $entry['attempts']
                || $entry['status'] === 'delivered' && $updated['status'] !== 'delivered'
                || $entry['lastAttemptAt'] !== null && $updated['lastAttemptAt'] < $entry['lastAttemptAt']
            ) {
                throw new OrderDataException();
            }
        }
    }
}
