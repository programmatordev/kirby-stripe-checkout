<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use Brick\Money\Money;
use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimate;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimateUnit;
use ProgrammatorDev\StripeCheckout\Shipping\Exception\InvalidShippingOptionException;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingZone;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingZoneScope;
use ProgrammatorDev\StripeCheckout\Shipping\StripeShippingCountryRegistry;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;
use Throwable;

/** Normalizes configured zones and creates their localized domain values. */
final class ShippingZoneFactory
{
    private const ZONE_KEYS = ['name', 'scope', 'countries', 'options'];

    private const OPTION_KEYS = [
        'key',
        'label',
        'labels',
        'amount',
        'deliveryEstimate',
        'taxBehavior',
        'taxCode',
    ];

    private const ESTIMATE_KEYS = ['minimum', 'maximum', 'unit'];

    public function __construct(
        private readonly ?string $currency,
        private readonly TaxBehavior $defaultTaxBehavior,
        private readonly ?string $defaultTaxCode,
        private readonly ?string $languageCode = null,
    ) {}

    /**
     * @param array<mixed, mixed> $zones
     * @return list<array<string, mixed>>
     */
    public function normalize(array $zones): array
    {
        if (array_is_list($zones) === false) {
            throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, 'settings.shippingZones');
        }

        $normalized = [];
        $countryOwners = [];
        $optionKeys = [];
        $hasFallback = false;

        foreach ($zones as $zoneIndex => $zone) {
            $path = 'settings.shippingZones.' . $zoneIndex;

            if (is_array($zone) === false) {
                throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $path);
            }

            $this->assertKnownKeys($zone, self::ZONE_KEYS, $path);

            foreach (['name', 'scope', 'countries', 'options'] as $requiredName) {
                if (array_key_exists($requiredName, $zone) === false) {
                    throw new ConfigurationException(
                        ConfigurationErrorCode::REQUIRED_MISSING,
                        $path . '.' . $requiredName,
                    );
                }
            }

            $name = $this->label($zone['name'], 100, $path . '.name');
            $scope = $zone['scope'];

            if (is_string($scope) === false) {
                throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $path . '.scope');
            }

            $zoneScope = ShippingZoneScope::tryFrom($scope);

            if ($zoneScope === null) {
                throw new ConfigurationException(ConfigurationErrorCode::VALUE_INVALID, $path . '.scope');
            }

            $countries = $this->countries($zone['countries'], $path . '.countries');

            if (
                ($zoneScope === ShippingZoneScope::Fallback && $countries !== [])
                || ($zoneScope === ShippingZoneScope::SelectedCountries && $countries === [])
            ) {
                throw new ConfigurationException(ConfigurationErrorCode::VALUE_INVALID, $path . '.countries');
            }

            if ($zoneScope === ShippingZoneScope::Fallback) {
                if ($hasFallback) {
                    throw new ConfigurationException(ConfigurationErrorCode::VALUE_INVALID, $path . '.scope');
                }

                $hasFallback = true;
            }

            foreach ($countries as $countryIndex => $country) {
                if (isset($countryOwners[$country])) {
                    throw new ConfigurationException(
                        ConfigurationErrorCode::VALUE_INVALID,
                        $path . '.countries.' . $countryIndex,
                    );
                }

                $countryOwners[$country] = true;
            }

            $rawOptions = $zone['options'];

            if (is_array($rawOptions) === false) {
                throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $path . '.options');
            }

            $options = $this->options($rawOptions, $path . '.options', $optionKeys);
            $normalizedZone = [
                'name' => $name,
                'scope' => $scope,
                'countries' => $countries,
                'options' => $options,
            ];

            $this->create($normalizedZone, $path);
            $normalized[] = $normalizedZone;
        }

        return $normalized;
    }

    /**
     * @param array<mixed, mixed> $zones
     * @return list<ShippingZone>
     */
    public function createAll(array $zones): array
    {
        $shippingZones = [];

        foreach ($this->normalize($zones) as $index => $zone) {
            $shippingZones[] = $this->create($zone, 'settings.shippingZones.' . $index);
        }

        return $shippingZones;
    }

    /**
     * @param array<string, mixed> $zone
     */
    private function create(array $zone, string $path): ShippingZone
    {
        $name = $zone['name'] ?? null;
        $scope = $zone['scope'] ?? null;
        $countries = $zone['countries'] ?? null;
        $options = $zone['options'] ?? null;

        if (
            is_string($name) === false
            || is_string($scope) === false
            || is_array($countries) === false
            || is_array($options) === false
        ) {
            throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $path);
        }

        $zoneOptions = [];

        foreach ($options as $index => $option) {
            if (is_array($option) === false) {
                throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $path . '.options.' . $index);
            }

            /** @var array<string, mixed> $option */
            $zoneOptions[] = $this->createOption($option, $path . '.options.' . $index);
        }

        try {
            return new ShippingZone(
                name: $name,
                scope: ShippingZoneScope::from($scope),
                countries: $countries,
                options: $zoneOptions,
            );
        } catch (InvalidArgumentException $error) {
            throw new ConfigurationException(ConfigurationErrorCode::VALUE_INVALID, $path, previous: $error);
        }
    }

    /**
     * @param array<mixed, mixed> $options
     * @param array<string, true> $globalKeys
     * @return list<array<string, mixed>>
     */
    private function options(array $options, string $path, array &$globalKeys): array
    {
        if (array_is_list($options) === false) {
            throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $path);
        }

        if ($options === [] || count($options) > 5) {
            // Stripe Checkout accepts at most five shipping options per Session.
            // https://docs.stripe.com/api/checkout/sessions/create#checkout_session_create-shipping_options
            throw new ConfigurationException(ConfigurationErrorCode::VALUE_INVALID, $path);
        }

        $normalized = [];
        $labels = [];

        foreach ($options as $index => $option) {
            $optionPath = $path . '.' . $index;

            if (is_array($option) === false) {
                throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $optionPath);
            }

            $this->assertKnownKeys($option, self::OPTION_KEYS, $optionPath);

            foreach (['key', 'label', 'amount'] as $requiredName) {
                if (array_key_exists($requiredName, $option) === false) {
                    throw new ConfigurationException(
                        ConfigurationErrorCode::REQUIRED_MISSING,
                        $optionPath . '.' . $requiredName,
                    );
                }
            }

            $key = $option['key'];

            if (is_string($key) === false) {
                throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $optionPath . '.key');
            }

            if (preg_match('/\A[a-z0-9_-]{1,64}\z/D', $key) !== 1 || isset($globalKeys[$key])) {
                throw new ConfigurationException(ConfigurationErrorCode::VALUE_INVALID, $optionPath . '.key');
            }

            $globalKeys[$key] = true;
            $label = $this->label($option['label'], 100, $optionPath . '.label');
            $localizedLabels = $this->labels($option['labels'] ?? [], $optionPath . '.labels');
            $effectiveLabel = $this->localizedLabel($label, $localizedLabels);

            if (isset($labels[$effectiveLabel])) {
                throw new ConfigurationException(ConfigurationErrorCode::VALUE_INVALID, $optionPath . '.label');
            }

            $labels[$effectiveLabel] = true;
            $amount = $option['amount'];

            if (is_string($amount) === false) {
                throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $optionPath . '.amount');
            }

            if ($this->currency === null) {
                throw new ConfigurationException(ConfigurationErrorCode::REQUIRED_MISSING, 'settings.currency');
            }

            $taxBehavior = $option['taxBehavior'] ?? $this->defaultTaxBehavior->value;

            if (is_string($taxBehavior) === false) {
                throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $optionPath . '.taxBehavior');
            }

            if (TaxBehavior::tryFrom($taxBehavior) === null) {
                throw new ConfigurationException(ConfigurationErrorCode::VALUE_INVALID, $optionPath . '.taxBehavior');
            }

            $taxCode = $option['taxCode'] ?? $this->defaultTaxCode;

            if ($taxCode !== null && is_string($taxCode) === false) {
                throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $optionPath . '.taxCode');
            }

            $normalizedOption = [
                'key' => $key,
                'label' => $label,
                'labels' => $localizedLabels,
                'amount' => $amount,
                'deliveryEstimate' => $this->deliveryEstimate(
                    $option['deliveryEstimate'] ?? null,
                    $optionPath . '.deliveryEstimate',
                ),
                'taxBehavior' => $taxBehavior,
                'taxCode' => $taxCode,
            ];

            $this->createOption($normalizedOption, $optionPath);
            $normalized[] = $normalizedOption;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $option */
    private function createOption(array $option, string $path): ShippingOption
    {
        $key = $option['key'] ?? null;
        $label = $option['label'] ?? null;
        $labels = $option['labels'] ?? null;
        $amount = $option['amount'] ?? null;
        $estimate = $option['deliveryEstimate'] ?? null;
        $taxBehavior = $option['taxBehavior'] ?? null;
        $taxCode = $option['taxCode'] ?? null;

        if (
            is_string($key) === false
            || is_string($label) === false
            || is_array($labels) === false
            || is_string($amount) === false
            || ($estimate !== null && is_array($estimate) === false)
            || is_string($taxBehavior) === false
            || ($taxCode !== null && is_string($taxCode) === false)
            || $this->currency === null
        ) {
            throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $path);
        }

        try {
            /** @var array<string, string> $labels */
            /** @var array{minimum: int|null, maximum: int|null, unit: string}|null $estimate */
            return new ShippingOption(
                key: $key,
                label: $this->localizedLabel($label, $labels),
                amount: Money::of($amount, $this->currency),
                deliveryEstimate: $estimate === null ? null : new DeliveryEstimate(
                    minimum: $estimate['minimum'],
                    maximum: $estimate['maximum'],
                    unit: DeliveryEstimateUnit::from($estimate['unit']),
                ),
                taxBehavior: TaxBehavior::from($taxBehavior),
                taxCode: $taxCode,
            );
        } catch (InvalidShippingOptionException $error) {
            throw new ConfigurationException(ConfigurationErrorCode::VALUE_INVALID, $path . '.' . $error->attribute());
        } catch (Throwable $error) {
            throw new ConfigurationException(ConfigurationErrorCode::VALUE_INVALID, $path . '.amount', previous: $error);
        }
    }

    /** @return list<string> */
    private function countries(mixed $countries, string $path): array
    {
        if (is_array($countries) === false || array_is_list($countries) === false) {
            throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $path);
        }

        $normalized = [];
        $countryRegistry = new StripeShippingCountryRegistry();

        foreach ($countries as $index => $country) {
            if (is_string($country) === false) {
                throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $path . '.' . $index);
            }

            if (
                preg_match('/\A[A-Z]{2}\z/D', $country) !== 1
                || $countryRegistry->supports($country) === false
                || isset($normalized[$country])
            ) {
                throw new ConfigurationException(ConfigurationErrorCode::VALUE_INVALID, $path . '.' . $index);
            }

            $normalized[$country] = true;
        }

        return array_keys($normalized);
    }

    /** @return array{minimum: ?int, maximum: ?int, unit: string}|null */
    private function deliveryEstimate(mixed $estimate, string $path): ?array
    {
        if ($estimate === null || $estimate === '') {
            return null;
        }

        if (is_array($estimate) === false) {
            throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $path);
        }

        $this->assertKnownKeys($estimate, self::ESTIMATE_KEYS, $path);
        $minimum = $estimate['minimum'] ?? null;
        $maximum = $estimate['maximum'] ?? null;
        $unit = $estimate['unit'] ?? null;

        foreach (['minimum' => $minimum, 'maximum' => $maximum] as $name => $value) {
            if ($value !== null && is_int($value) === false) {
                throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $path . '.' . $name);
            }
        }

        if (is_string($unit) === false) {
            throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $path . '.unit');
        }

        if (DeliveryEstimateUnit::tryFrom($unit) === null) {
            throw new ConfigurationException(ConfigurationErrorCode::VALUE_INVALID, $path . '.unit');
        }

        /** @var int|null $minimum */
        /** @var int|null $maximum */

        return [
            'minimum' => $minimum,
            'maximum' => $maximum,
            'unit' => $unit,
        ];
    }

    private function label(mixed $label, int $maximumLength, string $path): string
    {
        if (
            is_string($label) === false
            || $label === ''
            || trim($label) !== $label
            || TextValidator::isSingleLine($label) === false
            || mb_strlen($label) > $maximumLength
        ) {
            throw new ConfigurationException(ConfigurationErrorCode::VALUE_INVALID, $path);
        }

        return $label;
    }

    /** @return array<string, string> */
    private function labels(mixed $labels, string $path): array
    {
        if (is_array($labels) === false) {
            throw new ConfigurationException(ConfigurationErrorCode::TYPE_INVALID, $path);
        }

        $normalized = [];

        foreach ($labels as $languageCode => $label) {
            if (
                is_string($languageCode) === false
                || preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/D', $languageCode) !== 1
            ) {
                throw new ConfigurationException(ConfigurationErrorCode::VALUE_INVALID, $path);
            }

            $normalized[$languageCode] = $this->label($label, 100, $path . '.' . $languageCode);
        }

        ksort($normalized);

        return $normalized;
    }

    /** @param array<string, string> $labels */
    private function localizedLabel(string $fallback, array $labels): string
    {
        if ($this->languageCode === null) {
            return $fallback;
        }

        return $labels[$this->languageCode] ?? $fallback;
    }

    /**
     * @param array<mixed, mixed> $values
     * @param list<string> $knownKeys
     */
    private function assertKnownKeys(array $values, array $knownKeys, string $parent): void
    {
        foreach (array_keys($values) as $key) {
            if (is_string($key) && in_array($key, $knownKeys, true)) {
                continue;
            }

            throw new ConfigurationException(ConfigurationErrorCode::OPTION_UNKNOWN, $parent . '.' . (string) $key);
        }
    }
}
