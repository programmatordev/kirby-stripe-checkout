<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use Kirby\Cms\Url;
use Kirby\Uuid\Uuids;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use Throwable;

/**
 * Validates the UUID ID used as both native Order identity and Page slug.
 *
 * Kirby normalizes every Page slug during creation. Checkout retries derive the
 * Order path directly from its UUID, so the generated ID must survive that
 * normalization unchanged. This check belongs at identity issuance; stored
 * tokens remain parseable if a project changes its slug settings afterwards.
 *
 * @internal
 */
final class OrderUuidValidator
{
    public static function validate(string $uuid): void
    {
        if (Uuids::enabled() === false) {
            throw new OrderDataException('order.uuid_unavailable');
        }

        OrderData::uuid('page://' . $uuid);

        try {
            $slug = Url::slug($uuid);
        } catch (Throwable) {
            throw new OrderDataException('order.uuid_slug_incompatible');
        }

        if ($slug !== $uuid) {
            throw new OrderDataException('order.uuid_slug_incompatible');
        }
    }
}
