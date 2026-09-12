<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Collection\BillingAddressCollection;
use ProgrammatorDev\StripeCheckout\Collection\CollectionMode;
use ProgrammatorDev\StripeCheckout\Collection\TaxIdCollection;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;

/**
 * Carries validated non-secret values read from the protected hub Page.
 *
 * @internal
 */
final class PageSettings
{
    /** @var array<string, bool|int|null> */
    private readonly array $retention;

    public function __construct(
        mixed $priceSource = null,
        mixed $currency = null,
        mixed $defaultRequiresShipping = null,
        mixed $uiMode = null,
        mixed $successDestination = null,
        mixed $cancelDestination = null,
        mixed $returnDestination = null,
        mixed $billingAddressCollection = null,
        mixed $individualNameCollection = null,
        mixed $businessNameCollection = null,
        mixed $phoneNumberCollection = null,
        mixed $taxIdCollection = null,
        mixed $termsOfServiceConsent = null,
        mixed $promotionsConsent = null,
        mixed $customFields = null,
        mixed $allowPromotionCodes = null,
        mixed $cleanupCreationFailures = null,
        mixed $creationFailureRetentionDays = null,
        mixed $cleanupUnpaidOrders = null,
        mixed $unpaidOrderRetentionDays = null,
    ) {
        $priceSource = $priceSource === '' ? null : $priceSource;

        if (
            $priceSource !== null
            && (
                is_string($priceSource) === false
                || PriceSource::tryFrom($priceSource) === null
            )
        ) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                'settings.priceSource',
            );
        }

        $currency = $currency === '' ? null : $currency;

        if (
            $currency !== null
            && (
                is_string($currency) === false
                || (new StripeCurrencyRegistry())->supports($currency) === false
            )
        ) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                'settings.currency',
            );
        }

        // Kirby's select field stores stable strings; PHP configuration reaches
        // the resolver separately as a native boolean.
        $defaultRequiresShipping = match ($defaultRequiresShipping) {
            null, '' => null,
            true, 'yes' => true,
            false, 'no' => false,
            default => throw new ConfigurationException(
                'persistence.content_invalid',
                'settings.defaultRequiresShipping',
            ),
        };

        $this->priceSource = $priceSource;
        $this->currency = $currency;
        $this->defaultRequiresShipping = $defaultRequiresShipping;
        $this->uiMode = $this->normalizeUiMode($uiMode);
        $this->successDestination = $this->normalizeDestination($successDestination, 'successDestination');
        $this->cancelDestination = $this->normalizeDestination($cancelDestination, 'cancelDestination');
        $this->returnDestination = $this->normalizeDestination($returnDestination, 'returnDestination');
        $this->billingAddressCollection = $this->normalizeChoice(
            $billingAddressCollection,
            array_column(BillingAddressCollection::cases(), 'value'),
            'billingAddressCollection',
        );
        $this->individualNameCollection = $this->normalizeChoice(
            $individualNameCollection,
            array_column(CollectionMode::cases(), 'value'),
            'individualNameCollection',
        );
        $this->businessNameCollection = $this->normalizeChoice(
            $businessNameCollection,
            array_column(CollectionMode::cases(), 'value'),
            'businessNameCollection',
        );
        $this->phoneNumberCollection = $this->normalizeToggle($phoneNumberCollection, 'phoneNumberCollection');
        $this->taxIdCollection = $this->normalizeChoice(
            $taxIdCollection,
            array_column(TaxIdCollection::cases(), 'value'),
            'taxIdCollection',
        );
        $this->termsOfServiceConsent = $this->normalizeToggle($termsOfServiceConsent, 'termsOfServiceConsent');
        $this->promotionsConsent = $this->normalizeToggle($promotionsConsent, 'promotionsConsent');
        $this->customFields = $this->normalizeCustomFields($customFields);
        $this->allowPromotionCodes = $this->normalizeToggle($allowPromotionCodes, 'allowPromotionCodes');
        $retention = compact('cleanupCreationFailures', 'creationFailureRetentionDays', 'cleanupUnpaidOrders', 'unpaidOrderRetentionDays');

        foreach (Defaults::RETENTION as $name => $default) {
            $value = $retention[$name];

            if ($value === '' || $value === null) {
                $retention[$name] = null;

                continue;
            }

            // Kirby persists toggle/number fields as text. Normalize only that
            // transport here; PHP options are checked without string coercion.
            $value = is_bool($default)
                ? match ($value) {
                    true, 'true' => true,
                    false, 'false' => false,
                    default => null,
                }
            : filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($value === null || (is_int($default) && (is_int($value) === false || is_float($retention[$name]) || is_bool($retention[$name])))) {
                throw new ConfigurationException('persistence.content_invalid', 'settings.' . $name);
            }

            $retention[$name] = $value;
        }

        $this->retention = $retention;
    }

    private readonly ?string $priceSource;
    private readonly ?string $currency;
    private readonly ?bool $defaultRequiresShipping;
    private readonly ?string $uiMode;
    private readonly ?string $successDestination;
    private readonly ?string $cancelDestination;
    private readonly ?string $returnDestination;
    private readonly ?string $billingAddressCollection;
    private readonly ?string $individualNameCollection;
    private readonly ?string $businessNameCollection;
    private readonly ?bool $phoneNumberCollection;
    private readonly ?string $taxIdCollection;
    private readonly ?bool $termsOfServiceConsent;
    private readonly ?bool $promotionsConsent;
    /** @var list<array<string, mixed>>|null */
    private readonly ?array $customFields;
    private readonly ?bool $allowPromotionCodes;

    public function priceSource(): ?string
    {
        return $this->priceSource;
    }

    public function currency(): ?string
    {
        return $this->currency;
    }

    public function defaultRequiresShipping(): ?bool
    {
        return $this->defaultRequiresShipping;
    }

    public function uiMode(): ?string
    {
        return $this->uiMode;
    }

    public function successDestination(): ?string
    {
        return $this->successDestination;
    }

    public function cancelDestination(): ?string
    {
        return $this->cancelDestination;
    }

    public function returnDestination(): ?string
    {
        return $this->returnDestination;
    }

    public function billingAddressCollection(): ?string
    {
        return $this->billingAddressCollection;
    }

    public function individualNameCollection(): ?string
    {
        return $this->individualNameCollection;
    }

    public function businessNameCollection(): ?string
    {
        return $this->businessNameCollection;
    }

    public function phoneNumberCollection(): ?bool
    {
        return $this->phoneNumberCollection;
    }

    public function taxIdCollection(): ?string
    {
        return $this->taxIdCollection;
    }

    public function termsOfServiceConsent(): ?bool
    {
        return $this->termsOfServiceConsent;
    }

    public function promotionsConsent(): ?bool
    {
        return $this->promotionsConsent;
    }

    /** @return list<array<string, mixed>>|null */
    public function customFields(): ?array
    {
        return $this->customFields;
    }

    public function allowPromotionCodes(): ?bool
    {
        return $this->allowPromotionCodes;
    }

    /** @return string|bool|int|list<array<string, mixed>>|null */
    public function value(string $name): string|bool|int|array|null
    {
        return match ($name) {
            'priceSource' => $this->priceSource(),
            'currency' => $this->currency(),
            'defaultRequiresShipping' => $this->defaultRequiresShipping(),
            'uiMode' => $this->uiMode(),
            'successDestination' => $this->successDestination(),
            'cancelDestination' => $this->cancelDestination(),
            'returnDestination' => $this->returnDestination(),
            'billingAddressCollection' => $this->billingAddressCollection(),
            'individualNameCollection' => $this->individualNameCollection(),
            'businessNameCollection' => $this->businessNameCollection(),
            'phoneNumberCollection' => $this->phoneNumberCollection(),
            'taxIdCollection' => $this->taxIdCollection(),
            'termsOfServiceConsent' => $this->termsOfServiceConsent(),
            'promotionsConsent' => $this->promotionsConsent(),
            'customFields' => $this->customFields(),
            'allowPromotionCodes' => $this->allowPromotionCodes(),
            default => $this->retention[$name] ?? null,
        };
    }

    private function normalizeUiMode(mixed $value): ?string
    {
        $value = $value === '' ? null : $value;

        if ($value !== null && (is_string($value) === false || UiMode::tryFrom($value) === null)) {
            throw new ConfigurationException('persistence.content_invalid', 'settings.uiMode');
        }

        return $value;
    }

    private function normalizeDestination(mixed $value, string $name): ?string
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (is_string($value) === false || trim($value) !== $value) {
            throw new ConfigurationException('persistence.content_invalid', 'settings.' . $name);
        }

        return $value;
    }

    /** @param list<string> $allowed */
    private function normalizeChoice(mixed $value, array $allowed, string $name): ?string
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (is_string($value) === false || in_array($value, $allowed, true) === false) {
            throw new ConfigurationException('persistence.content_invalid', 'settings.' . $name);
        }

        return $value;
    }

    private function normalizeToggle(mixed $value, string $name): ?bool
    {
        return match ($value) {
            null, '' => null,
            true, 'true' => true,
            false, 'false' => false,
            default => throw new ConfigurationException(
                'persistence.content_invalid',
                'settings.' . $name,
            ),
        };
    }

    /** @return list<array<string, mixed>>|null */
    private function normalizeCustomFields(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value) === false) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                'settings.customFields',
            );
        }

        try {
            return (new CustomFieldFactory())->normalize($value);
        } catch (ConfigurationException $error) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                $error->path(),
                previous: $error,
            );
        }
    }
}
