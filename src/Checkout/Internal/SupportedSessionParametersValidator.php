<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\Exception\InvalidSessionRequestException;
use ProgrammatorDev\StripeCheckout\Collection\CustomField;
use ProgrammatorDev\StripeCheckout\Collection\CustomFieldOption;
use ProgrammatorDev\StripeCheckout\Collection\CustomFieldType;
use ProgrammatorDev\StripeCheckout\Collection\Exception\InvalidCustomFieldException;

/** Validates the Stripe parameter shapes the plugin officially supports. */
final class SupportedSessionParametersValidator
{
    /** @param array<string, mixed> $parameters */
    public function validate(array $parameters): void
    {
        $this->validateBillingAddressCollection($parameters);
        $this->validateNameCollection($parameters);
        $this->validatePhoneNumberCollection($parameters);
        $this->validateTaxIdCollection($parameters);
        $this->validateConsentCollection($parameters);
        $this->validateCustomFields($parameters);
        $this->validateAllowPromotionCodes($parameters);
    }

    /** @param array<string, mixed> $parameters */
    private function validateBillingAddressCollection(array $parameters): void
    {
        if (array_key_exists('billing_address_collection', $parameters) === false) {
            return;
        }

        if (
            is_string($parameters['billing_address_collection']) === false
            || in_array($parameters['billing_address_collection'], ['auto', 'required'], true) === false
        ) {
            $this->invalid('billing_address_collection');
        }
    }

    /** @param array<string, mixed> $parameters */
    private function validateNameCollection(array $parameters): void
    {
        if (array_key_exists('name_collection', $parameters) === false) {
            return;
        }

        $collection = $this->map($parameters['name_collection'], 'name_collection');
        $nameTypes = ['individual', 'business'];

        foreach ($nameTypes as $nameType) {
            if (array_key_exists($nameType, $collection) === false) {
                continue;
            }

            $path = 'name_collection.' . $nameType;
            $definition = $this->map($collection[$nameType], $path);
            $this->requiredBoolean($definition, 'enabled', $path . '.enabled');

            if (array_key_exists('optional', $definition)) {
                $this->boolean($definition['optional'], $path . '.optional');
            }
        }
    }

    /** @param array<string, mixed> $parameters */
    private function validatePhoneNumberCollection(array $parameters): void
    {
        if (array_key_exists('phone_number_collection', $parameters) === false) {
            return;
        }

        $collection = $this->map(
            $parameters['phone_number_collection'],
            'phone_number_collection',
        );
        $this->requiredBoolean(
            $collection,
            'enabled',
            'phone_number_collection.enabled',
        );
    }

    /** @param array<string, mixed> $parameters */
    private function validateTaxIdCollection(array $parameters): void
    {
        if (array_key_exists('tax_id_collection', $parameters) === false) {
            return;
        }

        $collection = $this->map($parameters['tax_id_collection'], 'tax_id_collection');
        $this->requiredBoolean($collection, 'enabled', 'tax_id_collection.enabled');

        if (
            array_key_exists('required', $collection)
            && (
                is_string($collection['required']) === false
                || in_array($collection['required'], ['never', 'if_supported'], true) === false
            )
        ) {
            $this->invalid('tax_id_collection.required');
        }
    }

    /** @param array<string, mixed> $parameters */
    private function validateConsentCollection(array $parameters): void
    {
        if (array_key_exists('consent_collection', $parameters) === false) {
            return;
        }

        $collection = $this->map($parameters['consent_collection'], 'consent_collection');
        $allowed = [
            'promotions' => ['auto', 'none'],
            'terms_of_service' => ['none', 'required'],
        ];

        foreach ($allowed as $field => $values) {
            if (array_key_exists($field, $collection) === false) {
                continue;
            }

            if (
                is_string($collection[$field]) === false
                || in_array($collection[$field], $values, true) === false
            ) {
                $this->invalid('consent_collection.' . $field);
            }
        }
    }

    /** @param array<string, mixed> $parameters */
    private function validateCustomFields(array $parameters): void
    {
        if (array_key_exists('custom_fields', $parameters) === false) {
            return;
        }

        $customFields = $parameters['custom_fields'];

        // Checkout accepts no more than three custom fields per Session.
        // https://docs.stripe.com/api/checkout/sessions/create#create_checkout_session-custom_fields
        if (is_array($customFields) === false || array_is_list($customFields) === false || count($customFields) > 3) {
            $this->invalid('custom_fields');
        }

        $keys = [];

        foreach ($customFields as $index => $value) {
            $path = 'custom_fields.' . $index;
            $field = $this->map($value, $path);
            $key = $this->requiredString($field, 'key', $path . '.key');

            if (isset($keys[$key])) {
                $this->invalid($path . '.key');
            }

            $keys[$key] = true;
            $typeName = $this->requiredString($field, 'type', $path . '.type');
            $type = CustomFieldType::tryFrom($typeName);

            if ($type === null) {
                $this->invalid($path . '.type');
            }

            $label = $this->map($field['label'] ?? null, $path . '.label');

            if (($label['type'] ?? null) !== 'custom') {
                $this->invalid($path . '.label.type');
            }

            $customLabel = $this->requiredString($label, 'custom', $path . '.label.custom');

            if (array_key_exists('optional', $field) === false) {
                $this->invalid($path . '.optional');
            }

            $optional = $this->boolean($field['optional'], $path . '.optional');
            $typeParameters = array_key_exists($type->value, $field)
                ? $this->map($field[$type->value], $path . '.' . $type->value)
                : [];
            $minimumLength = $this->optionalInteger(
                $typeParameters,
                'minimum_length',
                $path . '.' . $type->value . '.minimum_length',
            );
            $maximumLength = $this->optionalInteger(
                $typeParameters,
                'maximum_length',
                $path . '.' . $type->value . '.maximum_length',
            );
            $defaultValue = $this->optionalString(
                $typeParameters,
                'default_value',
                $path . '.' . $type->value . '.default_value',
            );
            $options = $type === CustomFieldType::Dropdown
                ? $this->customFieldOptions($typeParameters, $path . '.dropdown')
                : [];

            foreach (CustomFieldType::cases() as $otherType) {
                if ($otherType !== $type && array_key_exists($otherType->value, $field)) {
                    $this->invalid($path . '.' . $otherType->value);
                }
            }

            try {
                new CustomField(
                    key: $key,
                    label: $customLabel,
                    type: $type,
                    required: $optional === false,
                    minimumLength: $minimumLength,
                    maximumLength: $maximumLength,
                    defaultValue: $defaultValue,
                    options: $options,
                );
            } catch (InvalidCustomFieldException $error) {
                $attribute = match ($error->attribute()) {
                    'minimumLength' => $type->value . '.minimum_length',
                    'maximumLength' => $type->value . '.maximum_length',
                    'defaultValue' => $type->value . '.default_value',
                    'options' => $type->value . '.options',
                    default => $error->attribute(),
                };

                $this->invalid($path . '.' . $attribute, $error);
            }
        }
    }

    /** @param array<string, mixed> $parameters */
    private function validateAllowPromotionCodes(array $parameters): void
    {
        if (
            array_key_exists('allow_promotion_codes', $parameters)
            && is_bool($parameters['allow_promotion_codes']) === false
        ) {
            $this->invalid('allow_promotion_codes');
        }
    }

    /**
     * @param array<string, mixed> $parameters
     * @return list<CustomFieldOption>
     */
    private function customFieldOptions(array $parameters, string $path): array
    {
        $values = $parameters['options'] ?? null;

        if (is_array($values) === false || array_is_list($values) === false) {
            $this->invalid($path . '.options');
        }

        $options = [];

        foreach ($values as $index => $value) {
            $optionPath = $path . '.options.' . $index;
            $option = $this->map($value, $optionPath);

            try {
                $options[] = new CustomFieldOption(
                    value: $this->requiredString($option, 'value', $optionPath . '.value'),
                    label: $this->requiredString($option, 'label', $optionPath . '.label'),
                );
            } catch (InvalidCustomFieldException $error) {
                $this->invalid($optionPath . '.' . $error->attribute(), $error);
            }
        }

        return $options;
    }

    /** @return array<string, mixed> */
    private function map(mixed $value, string $path): array
    {
        if (is_array($value) === false || $value === [] || array_is_list($value)) {
            $this->invalid($path);
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param array<string, mixed> $values */
    private function requiredBoolean(array $values, string $field, string $path): bool
    {
        if (array_key_exists($field, $values) === false) {
            $this->invalid($path);
        }

        return $this->boolean($values[$field], $path);
    }

    private function boolean(mixed $value, string $path): bool
    {
        if (is_bool($value) === false) {
            $this->invalid($path);
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $field, string $path): string
    {
        if (array_key_exists($field, $values) === false || is_string($values[$field]) === false) {
            $this->invalid($path);
        }

        return $values[$field];
    }

    /** @param array<string, mixed> $values */
    private function optionalString(array $values, string $field, string $path): ?string
    {
        if (array_key_exists($field, $values) === false) {
            return null;
        }

        if (is_string($values[$field]) === false) {
            $this->invalid($path);
        }

        return $values[$field];
    }

    /** @param array<string, mixed> $values */
    private function optionalInteger(array $values, string $field, string $path): ?int
    {
        if (array_key_exists($field, $values) === false) {
            return null;
        }

        if (is_int($values[$field]) === false) {
            $this->invalid($path);
        }

        return $values[$field];
    }

    private function invalid(string $path, ?InvalidCustomFieldException $previous = null): never
    {
        throw new InvalidSessionRequestException(
            'session_request.parameter_invalid',
            $path,
            $previous,
        );
    }
}
