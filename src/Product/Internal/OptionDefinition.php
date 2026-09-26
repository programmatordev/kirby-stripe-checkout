<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

/**
 * One stored product option and its stable value definitions.
 *
 * @internal
 */
final readonly class OptionDefinition
{
    /** @param list<OptionValueDefinition> $values */
    public function __construct(
        private string $id,
        private string $label,
        private array $values,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return list<OptionValueDefinition> */
    public function values(): array
    {
        return $this->values;
    }

    /** @param list<OptionValueDefinition> $values */
    public function localized(string $label, array $values): self
    {
        return new self($this->id, $label, $values);
    }

    public function value(string $id): ?OptionValueDefinition
    {
        foreach ($this->values as $value) {
            if ($value->id() === $id) {
                return $value;
            }
        }

        return null;
    }

    /** @return array{id: string, label: string, values: list<array{id: string, label: string}>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'values' => array_map(
                static fn(OptionValueDefinition $value): array => $value->toArray(),
                $this->values,
            ),
        ];
    }
}
