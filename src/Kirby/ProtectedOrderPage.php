<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\Page;
use Kirby\Cms\PagePermissions;
use Kirby\Content\VersionId;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;

/**
 * @internal Shared storage behavior and guards outside Kirby's normal action checks.
 * Standard Page actions use Kirby's rules with OrderPagePermissions.
 */
abstract class ProtectedOrderPage extends Page
{
    /** @return array<string, mixed> */
    public function createDefaultContent(): array
    {
        // Keep native defaults for custom fields, but do not manufacture empty
        // final payment facts from the canonical display fields.
        /** @var array<string, mixed> $defaults */
        $defaults = parent::createDefaultContent();

        return array_filter(
            $defaults,
            static fn(string $field): bool => OrderSchema::isReserved($field) === false,
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function permissions(): PagePermissions
    {
        return new OrderPagePermissions($this);
    }

    /** @param array<string, mixed> $props */
    public function createChild(array $props): Page
    {
        // Kirby checks the new child's permissions, not this parent's. A child
        // with a different template must not bypass the controlled order creator.
        throw new PermissionException(message: I18n::template('programmatordev.stripe-checkout.orders.errors.manualCreation'));
    }

    /** @param array<string, mixed> $options */
    public function copy(array $options = []): static
    {
        // Low-level copy() does not run duplicate()'s permission checks.
        throw new PermissionException(message: I18n::template('programmatordev.stripe-checkout.orders.errors.protectedStructure'));
    }

    /** @param array<string, mixed>|null $data */
    public function save(?array $data = null, ?string $languageCode = null, bool $overwrite = false): static
    {
        // Low-level save() bypasses update() and its protected-field checks.
        throw new PermissionException(message: I18n::template('programmatordev.stripe-checkout.orders.errors.directSave'));
    }

    /** @param array<string, mixed> $data */
    public function render(array $data = [], $contentType = 'html', VersionId|string|null $versionId = null): string
    {
        throw new NotFoundException();
    }

    /**
     * @internal Called only after storage validation, never through ordinary save().
     * @param array<string, mixed> $data
     */
    final public function persistOrderContent(array $data, string $languageCode = 'default'): static
    {
        return parent::save($data, $languageCode, true);
    }
}
