<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

/** Adapts the synchronized custom-field editor to Kirby's field API. */
final class CustomFieldsField extends SynchronizedStructureField
{
    /** @var list<array<string, mixed>>|null */
    private readonly ?array $lockedValue;

    private readonly CustomFieldStructureAdapter $structureAdapter;

    /** @param array<string, mixed> $params */
    public function __construct(array $params = [])
    {
        $this->structureAdapter = new CustomFieldStructureAdapter();
        $lockedValue = $params['lockedValue'] ?? null;

        // PHP definitions have no storage-only synchronization IDs. Canonical
        // conversion supplies transient IDs so the locked Panel field can use
        // the same table and drawer components as Page-owned definitions.
        $this->lockedValue = is_array($lockedValue)
            ? $this->structureAdapter->canonical($lockedValue)
            : null;

        parent::__construct($params);
    }

    public function type(): string
    {
        return 'stripe-checkout-custom-fields';
    }

    /** @return list<array<string, mixed>> */
    public function toFormValue(): array
    {
        return $this->lockedValue ?? parent::toFormValue();
    }

    protected function adapter(): SynchronizedStructureAdapterInterface
    {
        return $this->structureAdapter;
    }
}
