<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Closure;
use Kirby\Cms\ModelCommit;
use Kirby\Content\Version;
use Kirby\Content\VersionId;
use Kirby\Data\Yaml;
use Kirby\Exception\PermissionException;
use Kirby\Form\Form;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderSchema;
use Throwable;

/** @internal Ordinary Kirby fields with guarded custom-field edits and canonical storage. */
final class OrderPage extends ProtectedOrderPage
{
    private bool $deletingStoredOrder = false;

    /** @internal Used only by the store after reloading and checking eligibility. */
    public function deleteStoredOrder(): bool
    {
        $this->deletingStoredOrder = true;

        try {
            return parent::delete();
        } finally {
            $this->deletingStoredOrder = false;
        }
    }

    /** @internal Opens only the native deletion permission for this operation. */
    public function isDeletingStoredOrder(): bool
    {
        return $this->deletingStoredOrder;
    }

    public function version(VersionId|string|null $versionId = null): Version
    {
        $version = parent::version($versionId);

        return $version->id()->is('changes')
            ? new OrderChangesVersion($this, $version->id())
            : $version;
    }

    /**
     * @param array<string, mixed>|null $input
     * @throws OrderStorageException
     */
    public function update(?array $input = null, ?string $languageCode = null, bool $validate = false): static
    {
        PluginPermissions::require($this->kirby(), 'orders.read');
        PluginPermissions::require($this->kirby(), 'orders.update');
        $store = new OrderPageStore($this->kirby());
        $page = $store->requirePage($this->id());

        return $page->updateCustomFields($input ?? [], $languageCode, $validate);
    }

    /** @param array<string, mixed> $input */
    private function updateCustomFields(array $input, ?string $languageCode, bool $validate): static
    {
        return parent::update($this->extractCustomFields($input), $languageCode, $validate);
    }

    /** @param array<string, mixed> $arguments */
    protected function commit(string $action, array $arguments, Closure $callback): mixed
    {
        if ($action !== 'update') {
            return parent::commit($action, $arguments, $callback);
        }

        // Keep native before/after hooks and Form conversion, but never write a
        // stale full form over canonical data or another custom-field edit.
        $languageCode = OrderData::string($arguments['languageCode'] ?? $this->kirby()->languageCode() ?? 'default');
        $baseline = $this->version('latest')->read($languageCode) ?? [];

        return (new ModelCommit($this, 'update'))->call($arguments, function (OrderPage $page, array $values, array $strings, ?string $languageCode) use ($baseline): OrderPage {
            // Read-only Object/Structure fields may have a display-only subset
            // of the snapshot schema. Never persist their Form re-encoding.
            $customFields = array_filter(
                OrderData::map($strings),
                static fn(string $field): bool => $field !== 'lock' && OrderSchema::isReserved($field) === false,
                ARRAY_FILTER_USE_KEY,
            );
            $changes = [];

            foreach ($customFields as $field => $value) {
                if (array_key_exists($field, $baseline) === false || $baseline[$field] !== $value) {
                    $changes[$field] = $value;
                }
            }

            return (new OrderPageStore($this->kirby()))->updateCustomFields($page->id(), $changes, $languageCode);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function extractCustomFields(array $input): array
    {
        $stored = $this->version('latest')->read('default') ?? [];
        $form = null;
        $formValues = null;
        $storedValues = null;
        $snapshotFields = array_map(strtolower(...), OrderSchema::SNAPSHOTS);
        $customFields = [];

        foreach ($input as $field => $value) {
            $field = strtolower($field);

            // Kirby's changes-version lock is transport metadata, not content.
            if ($field === 'lock') {
                continue;
            }

            if (OrderSchema::isReserved($field) === false) {
                $customFields[$field] = $value;
                continue;
            }

            $current = $stored[$field] ?? '';

            if ($value === $current || $value === null && $current === '') {
                continue;
            }

            // The Panel submits native form values, not raw text content.
            // Accept unchanged display projections, then discard them: only
            // the store may write the complete canonical snapshots.
            $form ??= Form::for($this, language: 'default');
            $formValues ??= $form->toFormValues();

            if (array_key_exists($field, $formValues) && $value === $formValues[$field]) {
                continue;
            }

            if (in_array($field, $snapshotFields, true)) {
                try {
                    $submitted = is_string($value) ? Yaml::decode($value) : $value;
                    $storedValues ??= $form->toStoredValues();

                    if (
                        OrderData::normalize($submitted) === OrderData::normalize(Yaml::decode($current))
                        || OrderData::normalize($submitted) === OrderData::normalize($storedValues[$field] ?? null)
                    ) {
                        continue;
                    }
                } catch (Throwable) {
                }
            }

            throw new PermissionException(message: I18n::template('programmatordev.stripe-checkout.orders.errors.protectedFields'));
        }

        return $customFields;
    }
}
