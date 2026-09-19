<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\Exception\InvalidSessionRequestException;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestErrorCode;
use ProgrammatorDev\StripeCheckout\Collection\CustomField;
use ProgrammatorDev\StripeCheckout\Collection\CustomFieldOption;
use ProgrammatorDev\StripeCheckout\Collection\CustomFieldType;
use ProgrammatorDev\StripeCheckout\Collection\Exception\InvalidCustomFieldException;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Shipping\StripeShippingCountryRegistry;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;
use Throwable;

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
        $this->validateAutomaticTax($parameters);
        $this->validateTaxBehavior($parameters);
        $this->validateShipping($parameters);
    }

    /** @param array<string, mixed> $parameters */
    private function validateShipping(array $parameters): void
    {
        if (
            array_key_exists('shipping_address_collection', $parameters) === false
            && array_key_exists('shipping_options', $parameters) === false
        ) {
            return;
        }

        $collection = $this->map(
            $parameters['shipping_address_collection'] ?? null,
            'shipping_address_collection',
        );
        $countries = $collection['allowed_countries'] ?? null;

        if (is_array($countries) === false || array_is_list($countries) === false || $countries === []) {
            $this->invalid('shipping_address_collection.allowed_countries');
        }

        $countryRegistry = new StripeShippingCountryRegistry();

        foreach ($countries as $index => $country) {
            if (
                is_string($country) === false
                || $countryRegistry->supports($country) === false
            ) {
                $this->invalid('shipping_address_collection.allowed_countries.' . $index);
            }
        }

        $options = $parameters['shipping_options'] ?? null;

        if (
            is_array($options) === false
            || array_is_list($options) === false
            || $options === []
            || count($options) > 5
        ) {
            $this->invalid('shipping_options');
        }

        foreach ($options as $index => $value) {
            $path = 'shipping_options.' . $index . '.shipping_rate_data';
            $option = $this->map($value, 'shipping_options.' . $index);
            $data = $this->map($option['shipping_rate_data'] ?? null, $path);
            $label = $this->requiredString($data, 'display_name', $path . '.display_name');

            if (
                $label === ''
                || trim($label) !== $label
                || mb_strlen($label) > 100
                || TextValidator::isSingleLine($label) === false
            ) {
                $this->invalid($path . '.display_name');
            }

            if (($data['type'] ?? null) !== 'fixed_amount') {
                $this->invalid($path . '.type');
            }

            $this->validateShippingAmount(
                data: $data,
                expectedCurrency: $parameters['currency'] ?? null,
                path: $path,
            );
            $this->validateDeliveryEstimate($data, $path);

            if (
                array_key_exists('tax_behavior', $data)
                && in_array($data['tax_behavior'], ['inclusive', 'exclusive', 'unspecified'], true) === false
            ) {
                $this->invalid($path . '.tax_behavior');
            }

            if (
                array_key_exists('tax_code', $data)
                && (
                    is_string($data['tax_code']) === false
                    || preg_match('/\Atxcd_[A-Za-z0-9]{1,249}\z/D', $data['tax_code']) !== 1
                )
            ) {
                $this->invalid($path . '.tax_code');
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function validateShippingAmount(
        array $data,
        mixed $expectedCurrency,
        string $path,
    ): void {
        $amount = $this->map($data['fixed_amount'] ?? null, $path . '.fixed_amount');
        $providerAmount = $amount['amount'] ?? null;
        $currency = $amount['currency'] ?? null;

        if (
            is_int($providerAmount) === false
            || $providerAmount < 0
            || is_string($currency) === false
            || strtolower($currency) !== $currency
            || $currency !== $expectedCurrency
            || array_key_exists('currency_options', $amount)
        ) {
            $this->invalid($path . '.fixed_amount');
        }

        try {
            (new StripeCurrencyRegistry())->fromProviderAmount(
                $providerAmount,
                strtoupper($currency),
            );
        } catch (Throwable $error) {
            $this->invalid($path . '.fixed_amount', $error);
        }
    }

    /** @param array<string, mixed> $data */
    private function validateDeliveryEstimate(array $data, string $path): void
    {
        if (array_key_exists('delivery_estimate', $data) === false) {
            return;
        }

        $estimate = $this->map(
            $data['delivery_estimate'],
            $path . '.delivery_estimate',
        );
        $bounds = ['minimum', 'maximum'];
        $found = false;

        foreach ($bounds as $bound) {
            if (array_key_exists($bound, $estimate) === false) {
                continue;
            }

            $found = true;
            $boundPath = $path . '.delivery_estimate.' . $bound;
            $value = $this->map($estimate[$bound], $boundPath);

            if (
                is_int($value['value'] ?? null) === false
                || $value['value'] < 1
                || in_array($value['unit'] ?? null, ['hour', 'day', 'business_day', 'week', 'month'], true) === false
            ) {
                $this->invalid($boundPath);
            }
        }

        if ($found === false) {
            $this->invalid($path . '.delivery_estimate');
        }

        $minimum = $estimate['minimum'] ?? null;
        $maximum = $estimate['maximum'] ?? null;

        // A filter receives provider-shaped arrays, so repeat the ordered,
        // single-unit range invariant normally guaranteed by DeliveryEstimate.
        if (
            is_array($minimum)
            && is_array($maximum)
            && (
                $minimum['unit'] !== $maximum['unit']
                || $minimum['value'] > $maximum['value']
            )
        ) {
            $this->invalid($path . '.delivery_estimate.maximum');
        }
    }

    /** @param array<string, mixed> $parameters */
    private function validateTaxBehavior(array $parameters): void
    {
        // Line structure and authoritative amounts are already checked by the
        // request validator. Validate only the inclusion policy modeled here.
        $lineItems = $parameters['line_items'] ?? [];

        if (is_array($lineItems) === false) {
            return;
        }

        foreach ($lineItems as $index => $lineItem) {
            if (is_array($lineItem) === false || is_array($lineItem['price_data'] ?? null) === false) {
                continue;
            }

            $priceData = $lineItem['price_data'];

            if (array_key_exists('tax_behavior', $priceData) && in_array($priceData['tax_behavior'], ['inclusive', 'exclusive', 'unspecified'], true) === false) {
                $this->invalid('line_items.' . $index . '.price_data.tax_behavior');
            }
        }
    }

    /** @param array<string, mixed> $parameters */
    private function validateAutomaticTax(array $parameters): void
    {
        if (array_key_exists('automatic_tax', $parameters) === false) {
            return;
        }

        $automaticTax = $this->map($parameters['automatic_tax'], 'automatic_tax');
        $this->requiredBoolean($automaticTax, 'enabled', 'automatic_tax.enabled');
        // Other tax parameters, including manual rates and performance locations,
        // belong to Stripe's validation. Settings are defaults, not filter locks.
        // https://docs.stripe.com/api/checkout/sessions/create#create_checkout_session-automatic_tax
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

    private function invalid(string $path, ?Throwable $previous = null): never
    {
        throw new InvalidSessionRequestException(
            SessionRequestErrorCode::PARAMETER_INVALID,
            $path,
            $previous,
        );
    }
}
