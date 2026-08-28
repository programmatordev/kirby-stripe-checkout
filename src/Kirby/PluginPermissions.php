<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\App;
use Kirby\Exception\PermissionException;

/**
 * Keeps plugin-specific Panel permission checks at the HTTP/UI boundary.
 *
 * @internal
 */
final class PluginPermissions
{
    public const CATEGORY = 'programmatordev.stripe-checkout';

    public static function allows(App $kirby, string $action): bool
    {
        return $kirby->user()?->role()->permissions()->for(self::CATEGORY, $action) === true;
    }

    public static function require(App $kirby, string $action): void
    {
        if (self::allows($kirby, $action) === false) {
            throw new PermissionException('No access');
        }
    }
}
