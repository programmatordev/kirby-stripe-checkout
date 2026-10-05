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

        $entry = [
            'id' => $event->id,
            'type' => $event->type,
            'createdAt' => $event->createdAt,
            'resourceId' => $event->resourceId,
            'status' => 'pending',
            'attempts' => 1,
            'lastAttemptAt' => OrderData::timestamp($now),
            'errorCode' => null,
        ];

        if ($event->stripePaymentIntentId !== null) {
            $entry['stripePaymentIntentId'] = $event->stripePaymentIntentId;
            $entry['stripeChargeId'] = $event->stripeChargeId;
        }

        $entries[] = $entry;

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
            $isFinancial = in_array($entry['type'] ?? null, ReconciliationEvent::FINANCIAL_TYPES, true);
            $entryKeys = $isFinancial ? [...$keys, 'stripePaymentIntentId', 'stripeChargeId'] : $keys;
            OrderData::validateAllowedKeys($entry, $entryKeys);
            OrderData::validateRequiredKeys($entry, $entryKeys);
            $id = OrderData::string($entry['id']);
            $type = OrderData::string($entry['type']);
            $resourceId = OrderData::string($entry['resourceId']);

            if ($id === '' || $resourceId === '') {
                throw new OrderDataException();
            }

            if ($isFinancial) {
                if (OrderData::string($entry['stripePaymentIntentId']) === '') {
                    throw new OrderDataException();
                }

                if ($entry['stripeChargeId'] !== null && OrderData::string($entry['stripeChargeId']) === '') {
                    throw new OrderDataException();
                }
            }

            if (in_array($type, ReconciliationEvent::DISPUTE_TYPES, true) && $entry['stripeChargeId'] === null) {
                throw new OrderDataException();
            }

            if (isset($ids[$id])) {
                throw new OrderDataException();
            }

            if (in_array($type, ReconciliationEvent::TYPES, true) === false) {
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
        $identityKeys = array_flip(['id', 'type', 'createdAt', 'resourceId', 'stripePaymentIntentId', 'stripeChargeId']);

        foreach ($before as $index => $entry) {
            $updated = $after[$index] ?? throw new OrderDataException();
            $identity = array_intersect_key($entry, $identityKeys);
            $updatedIdentity = array_intersect_key($updated, $identityKeys);

            if ($identity !== $updatedIdentity) {
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
        // A reused Event ID must not hide a different resource or financial parent behind duplicate suppression.
        if (($entry['stripePaymentIntentId'] ?? null) !== $event->stripePaymentIntentId || ($entry['stripeChargeId'] ?? null) !== $event->stripeChargeId) {
            throw new OrderDataException();
        }

        if ($entry['type'] !== $event->type || $entry['createdAt'] !== $event->createdAt || $entry['resourceId'] !== $event->resourceId) {
            throw new OrderDataException();
        }
    }
}
