<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Collection;

use InvalidArgumentException;

/** Exposes one stable value and localized label in a dropdown custom field. */
final readonly class CustomFieldOption
{
    public function __construct(
        private string $value,
        private string $label,
    ) {
        if (preg_match('/\A[a-z0-9]{1,64}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('A custom-field option requires a lowercase alphanumeric value.');
        }

        if ($label === '' || trim($label) !== $label || mb_strlen($label) > 100) {
            throw new InvalidArgumentException('A custom-field option requires a valid label.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return array{value: string, label: string} */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
        ];
    }
}
