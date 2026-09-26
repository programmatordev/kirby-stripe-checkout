<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

/**
 * One stable value in a stored product-option definition.
 *
 * @internal
 */
final readonly class OptionValueDefinition
{
    public function __construct(
        private string $id,
        private string $label,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function withLabel(string $label): self
    {
        return new self($this->id, $label);
    }

    /** @return array{id: string, label: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
        ];
    }
}
