<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Collection;

use ProgrammatorDev\StripeCheckout\Collection\Exception\InvalidCustomFieldException;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;

/**
 * Exposes one validated, localized Stripe Checkout custom field.
 *
 * Provider-facing labels, input bounds and dropdown sizes follow Stripe's
 * Checkout Session schema. Stable identifiers use the plugin's narrower limit.
 *
 * @see https://docs.stripe.com/api/checkout/sessions/create?query=custom_fields
 */
final readonly class CustomField
{
    /** @var list<CustomFieldOption> */
    private array $options;

    /** @param array<mixed> $options */
    public function __construct(
        private string $key,
        private string $label,
        private CustomFieldType $type,
        private bool $required = false,
        private ?int $minimumLength = null,
        private ?int $maximumLength = null,
        private ?string $defaultValue = null,
        array $options = [],
    ) {
        if (preg_match('/\A[a-z0-9]{1,64}\z/D', $key) !== 1) {
            throw new InvalidCustomFieldException('key', 'A custom field requires a lowercase alphanumeric key.');
        }

        if (
            $label === ''
            || trim($label) !== $label
            || TextValidator::isSingleLine($label) === false
            || mb_strlen($label) > 50
        ) {
            throw new InvalidCustomFieldException('label', 'A custom field requires a valid label.');
        }

        if ($minimumLength !== null && ($minimumLength < 1 || $minimumLength > 255)) {
            throw new InvalidCustomFieldException('minimumLength', 'Custom-field length bounds must be between 1 and 255.');
        }

        if ($maximumLength !== null && ($maximumLength < 1 || $maximumLength > 255)) {
            throw new InvalidCustomFieldException('maximumLength', 'Custom-field length bounds must be between 1 and 255.');
        }

        if ($minimumLength !== null && $maximumLength !== null && $minimumLength > $maximumLength) {
            throw new InvalidCustomFieldException('maximumLength', 'The custom-field minimum length cannot exceed its maximum length.');
        }

        if (array_is_list($options) === false) {
            throw new InvalidCustomFieldException('options', 'A custom field requires a list of options.');
        }

        $optionValues = [];

        foreach ($options as $option) {
            if ($option instanceof CustomFieldOption === false || isset($optionValues[$option->value()])) {
                throw new InvalidCustomFieldException('options', 'A custom field requires unique valid options.');
            }

            $optionValues[$option->value()] = true;
        }

        if ($type === CustomFieldType::Dropdown) {
            if ($minimumLength !== null) {
                throw new InvalidCustomFieldException('minimumLength', 'Dropdown custom fields do not accept length bounds.');
            }

            if ($maximumLength !== null) {
                throw new InvalidCustomFieldException('maximumLength', 'Dropdown custom fields do not accept length bounds.');
            }

            if ($options === [] || count($options) > 200) {
                throw new InvalidCustomFieldException('options', 'A dropdown custom field requires between 1 and 200 options and no length bounds.');
            }

            if ($defaultValue !== null && isset($optionValues[$defaultValue]) === false) {
                throw new InvalidCustomFieldException('defaultValue', 'A dropdown default must reference one of its options.');
            }
        } elseif ($options !== []) {
            throw new InvalidCustomFieldException('options', 'Only dropdown custom fields accept options.');
        }

        if ($defaultValue !== null) {
            if (TextValidator::isSingleLine($defaultValue) === false) {
                throw new InvalidCustomFieldException('defaultValue', 'The custom-field default does not satisfy its definition.');
            }

            $length = mb_strlen($defaultValue);

            if (
                $defaultValue === ''
                || $length > 255
                || ($minimumLength !== null && $length < $minimumLength)
                || ($maximumLength !== null && $length > $maximumLength)
                || ($type === CustomFieldType::Numeric && preg_match('/\A[0-9]+\z/D', $defaultValue) !== 1)
            ) {
                throw new InvalidCustomFieldException('defaultValue', 'The custom-field default does not satisfy its definition.');
            }
        }

        $this->options = $options;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function type(): CustomFieldType
    {
        return $this->type;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function minimumLength(): ?int
    {
        return $this->minimumLength;
    }

    public function maximumLength(): ?int
    {
        return $this->maximumLength;
    }

    public function defaultValue(): ?string
    {
        return $this->defaultValue;
    }

    /** @return list<CustomFieldOption> */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * @return array{
     *   key: string,
     *   label: string,
     *   type: string,
     *   required: bool,
     *   minimumLength: ?int,
     *   maximumLength: ?int,
     *   defaultValue: ?string,
     *   options: list<array{value: string, label: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'required' => $this->required,
            'minimumLength' => $this->minimumLength,
            'maximumLength' => $this->maximumLength,
            'defaultValue' => $this->defaultValue,
            'options' => array_map(
                static fn(CustomFieldOption $option): array => $option->toArray(),
                $this->options,
            ),
        ];
    }
}
