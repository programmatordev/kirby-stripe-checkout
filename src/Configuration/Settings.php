<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use LogicException;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Collection\BillingAddressCollection;
use ProgrammatorDev\StripeCheckout\Collection\CustomField;
use ProgrammatorDev\StripeCheckout\Collection\NameCollectionMode;
use ProgrammatorDev\StripeCheckout\Collection\TaxIdCollection;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;

/**
 * Provides the immutable, sanitized public view of effective store settings.
 */
final class Settings
{
    /** @var array<string, Setting> */
    private readonly array $settings;

    /** @var list<CustomField> */
    private readonly array $customFields;

    /**
     * @internal Constructed from the public-setting whitelist.
     *
     * @param array<string, Setting> $settings
     * @param array<mixed> $customFields
     */
    public function __construct(array $settings, array $customFields)
    {
        if (array_keys($settings) !== array_keys(Defaults::SETTINGS)) {
            throw new LogicException('The public Settings view contains an unexpected schema.');
        }

        if (array_is_list($customFields) === false) {
            throw new LogicException('The public Settings view requires a list of custom fields.');
        }

        foreach ($customFields as $customField) {
            if ($customField instanceof CustomField === false) {
                throw new LogicException('The public Settings view contains an invalid custom field.');
            }
        }

        $this->settings = $settings;
        /** @var list<CustomField> $customFields */
        $this->customFields = $customFields;
    }

    public function priceSource(): PriceSource
    {
        $value = $this->settings['priceSource']->value();

        if (is_string($value) === false) {
            throw new LogicException('The resolved priceSource must be a string.');
        }

        return PriceSource::from($value);
    }

    public function currency(): ?string
    {
        $value = $this->settings['currency']->value();

        if ($value !== null && is_string($value) === false) {
            throw new LogicException('The resolved currency must be a string or null.');
        }

        return $value;
    }

    public function defaultRequiresShipping(): ?bool
    {
        $value = $this->settings['defaultRequiresShipping']->value();

        if ($value !== null && is_bool($value) === false) {
            throw new LogicException('The resolved shipping default must be a boolean or null.');
        }

        return $value;
    }

    public function uiMode(): UiMode
    {
        $value = $this->settings['uiMode']->value();

        if (is_string($value) === false) {
            throw new LogicException('The resolved Checkout UI mode must be a string.');
        }

        return UiMode::from($value);
    }

    public function successDestination(): ?string
    {
        return $this->destination('successDestination');
    }

    public function cancelDestination(): ?string
    {
        return $this->destination('cancelDestination');
    }

    public function returnDestination(): ?string
    {
        return $this->destination('returnDestination');
    }

    public function billingAddressCollection(): BillingAddressCollection
    {
        return BillingAddressCollection::from($this->string('billingAddressCollection'));
    }

    public function individualNameCollection(): NameCollectionMode
    {
        return NameCollectionMode::from($this->string('individualNameCollection'));
    }

    public function businessNameCollection(): NameCollectionMode
    {
        return NameCollectionMode::from($this->string('businessNameCollection'));
    }

    public function phoneNumberCollection(): bool
    {
        return $this->boolean('phoneNumberCollection');
    }

    public function taxIdCollection(): TaxIdCollection
    {
        return TaxIdCollection::from($this->string('taxIdCollection'));
    }

    public function termsOfServiceConsent(): bool
    {
        return $this->boolean('termsOfServiceConsent');
    }

    public function promotionsConsent(): bool
    {
        return $this->boolean('promotionsConsent');
    }

    /** @return list<CustomField> */
    public function customFields(): array
    {
        return $this->customFields;
    }

    public function allowPromotionCodes(): bool
    {
        return $this->boolean('allowPromotionCodes');
    }

    public function automaticTax(): bool
    {
        return $this->boolean('automaticTax');
    }

    /** Retained inline-price policy, even when tax is off or Stripe Prices are used. */
    public function taxBehavior(): TaxBehavior
    {
        return TaxBehavior::from($this->string('taxBehavior'));
    }

    public function setting(string $path): ?Setting
    {
        return $this->settings[$path] ?? null;
    }

    public function cleanupCreationFailures(): bool
    {
        return $this->settings['cleanupCreationFailures']->value() === true;
    }

    public function creationFailureRetentionDays(): int
    {
        return $this->retentionDays('creationFailureRetentionDays');
    }

    public function cleanupUnpaidOrders(): bool
    {
        return $this->settings['cleanupUnpaidOrders']->value() === true;
    }

    public function unpaidOrderRetentionDays(): int
    {
        return $this->retentionDays('unpaidOrderRetentionDays');
    }

    private function retentionDays(string $name): int
    {
        $value = $this->settings[$name]->value();

        if (is_int($value) === false || $value < 1) {
            throw new LogicException('Resolved retention days must be a positive integer.');
        }

        return $value;
    }

    private function string(string $name): string
    {
        $value = $this->settings[$name]->value();

        if (is_string($value) === false) {
            throw new LogicException('The resolved setting must be a string.');
        }

        return $value;
    }

    private function boolean(string $name): bool
    {
        $value = $this->settings[$name]->value();

        if (is_bool($value) === false) {
            throw new LogicException('The resolved setting must be a boolean.');
        }

        return $value;
    }

    private function destination(string $name): ?string
    {
        $value = $this->settings[$name]->value();

        if ($value !== null && is_string($value) === false) {
            throw new LogicException('A resolved Checkout destination must be a string or null.');
        }

        return $value;
    }

    /** @return array<string, Setting> */
    public function all(): array
    {
        return $this->settings;
    }
}
