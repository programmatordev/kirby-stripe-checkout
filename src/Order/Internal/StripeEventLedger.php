<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ReconciliationEvent;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;

/** @internal Provider Event attempts/outcomes only; hook delivery identities and snapshots live elsewhere. */
final class StripeEventLedger
{
    /** @param list<array<string, mixed>> $entries */
    public static function isComplete(array $entries, ReconciliationEvent $event): bool
    {
        foreach ($entries as $entry) {
            if ($entry['id'] === $event->id) {
                self::assertSameEvent($entry, $event);

                return $entry['status'] === 'processed';
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public static function attempted(array $entries, ReconciliationEvent $event, DateTimeImmutable $now): array
    {
        foreach ($entries as $index => $entry) {
            if ($entry['id'] === $event->id) {
                self::assertSameEvent($entry, $event);

                if ($entry['status'] !== 'processed') {
                    $entries[$index]['attempts'] = OrderData::integer($entry['attempts']) + 1;
                    // Keep attempt time monotonic even if the local clock moves backward.
                    $entries[$index]['lastAttemptAt'] = max($entry['lastAttemptAt'], OrderData::timestamp($now));
                }

                return $entries;
            }
        }

        $entries[] = [
            'id' => $event->id,
            'type' => $event->type,
            'createdAt' => $event->createdAt,
            'resourceId' => $event->resourceId,
            'status' => 'pending',
            'attempts' => 1,
            'lastAttemptAt' => OrderData::timestamp($now),
            'errorCode' => null,
        ];

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public static function outcome(array $entries, ReconciliationEvent $event, ?string $errorCode = null): array
    {
        foreach ($entries as $index => $entry) {
            if ($entry['id'] === $event->id) {
                self::assertSameEvent($entry, $event);

                // A concurrent successful processor wins over a late failure from another attempt.
                if ($entry['status'] !== 'processed') {
                    $entries[$index]['status'] = $errorCode === null ? 'processed' : 'failed';
                    $entries[$index]['errorCode'] = $errorCode;
                }

                return $entries;
            }
        }

        throw new OrderDataException();
    }

    /** @return list<array<string, mixed>> */
    public static function normalize(mixed $value): array
    {
        $entries = [];
        $ids = [];
        $keys = ['id', 'type', 'createdAt', 'resourceId', 'status', 'attempts', 'lastAttemptAt', 'errorCode'];

        foreach (OrderData::list($value) as $entry) {
            $entry = OrderData::map($entry);
            OrderData::validateAllowedKeys($entry, $keys);
            OrderData::validateRequiredKeys($entry, $keys);
            $id = OrderData::text($entry['id'], 255);
            $type = OrderData::text($entry['type']);
            $resourcePrefix = $type === \Stripe\Event::PAYMENT_INTENT_REQUIRES_ACTION ? 'pi_' : 'cs_';

            if (isset($ids[$id]) || preg_match('/\Aevt_[A-Za-z0-9_]+\z/', $id) !== 1) {
                throw new OrderDataException();
            }

            if (
                in_array($type, ReconciliationEvent::TYPES, true) === false
                || preg_match('/\A' . $resourcePrefix . '[A-Za-z0-9_]+\z/', OrderData::text($entry['resourceId'], 255)) !== 1
            ) {
                throw new OrderDataException();
            }

            if (OrderData::integer($entry['createdAt']) < 0) {
                throw new OrderDataException();
            }

            self::validateProcessingOutcome($entry);
            $ids[$id] = true;
            $entries[] = $entry;
        }

        return $entries;
    }

    /** @param array<string, mixed> $entry */
    private static function validateProcessingOutcome(array $entry): void
    {
        // An Event enters this ledger only when an attempt is recorded, so pending entries also require attempt history.
        if (OrderData::integer($entry['attempts']) < 1) {
            throw new OrderDataException();
        }

        if (in_array($entry['status'], ['pending', 'processed', 'failed'], true) === false) {
            throw new OrderDataException();
        }

        if (($entry['status'] === 'failed') !== ($entry['errorCode'] !== null)) {
            throw new OrderDataException();
        }

        $errors = [CheckoutErrorCode::SESSION_INCOMPATIBLE, CheckoutErrorCode::SESSION_REJECTED, CheckoutErrorCode::SESSION_UNAVAILABLE, CheckoutErrorCode::SESSION_UNCERTAIN, CheckoutErrorCode::RECONCILIATION_CONFLICT];

        if ($entry['errorCode'] !== null && in_array($entry['errorCode'], $errors, true) === false) {
            throw new OrderDataException();
        }

        OrderData::date($entry['lastAttemptAt']);
    }

    /**
     * @param list<array<string, mixed>> $before
     * @param list<array<string, mixed>> $after
     */
    public static function validateTransition(array $before, array $after): void
    {
        foreach ($before as $index => $entry) {
            $updated = $after[$index] ?? throw new OrderDataException();

            if (
                array_intersect_key($entry, array_flip(['id', 'type', 'createdAt', 'resourceId'])) !== array_intersect_key($updated, array_flip(['id', 'type', 'createdAt', 'resourceId']))
            ) {
                throw new OrderDataException();
            }

            if ($updated['attempts'] < $entry['attempts']) {
                throw new OrderDataException();
            }

            if ($updated['lastAttemptAt'] < $entry['lastAttemptAt']) {
                throw new OrderDataException();
            }

            // Once processed, the entire entry is final, including its attempt history.
            if ($entry['status'] === 'processed' && $updated !== $entry) {
                throw new OrderDataException();
            }
        }
    }

    /** @param array<string, mixed> $entry */
    private static function assertSameEvent(array $entry, ReconciliationEvent $event): void
    {
        if ($entry['type'] !== $event->type || $entry['createdAt'] !== $event->createdAt || $entry['resourceId'] !== $event->resourceId) {
            throw new OrderDataException();
        }
    }
}
