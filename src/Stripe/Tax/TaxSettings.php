<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Tax;

use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;
use ProgrammatorDev\StripeCheckout\Tax\TaxCodeReference;
use Stripe\Tax\Settings;

/** @internal Validated account facts; active settings are not a tax obligation. */
final readonly class TaxSettings
{
    /** @var list<string> */
    private array $missingFields;

    /** @param array<mixed> $missingFields */
    public function __construct(
        private string $status,
        private bool $liveMode,
        private ?string $defaultTaxCode,
        private ?string $defaultTaxBehavior,
        array $missingFields,
        private int $readAt,
    ) {
        // Account defaults expose inferred_by_currency, not the plugin's
        // stripe_default sentinel. Leave that currency policy to Stripe.
        // https://docs.stripe.com/api/tax/settings/object?query=defaults
        if (
            in_array($this->status, [Settings::STATUS_ACTIVE, Settings::STATUS_PENDING], true) === false
            || in_array($this->defaultTaxBehavior, [null, 'inclusive', 'exclusive', 'inferred_by_currency'], true) === false
            || array_is_list($missingFields) === false
            || $this->readAt < 1
        ) {
            throw new ConfigurationException('tax.settings_invalid', 'stripe.taxSettings');
        }

        if ($this->defaultTaxCode !== null) {
            try {
                new TaxCodeReference($this->defaultTaxCode);
            } catch (ConfigurationException $error) {
                throw new ConfigurationException('tax.settings_invalid', 'stripe.taxSettings', $error);
            }
        }

        foreach ($missingFields as $field) {
            if (is_string($field) === false || $field === '' || TextValidator::isSingleLine($field) === false) {
                throw new ConfigurationException('tax.settings_invalid', 'stripe.taxSettings');
            }
        }

        $this->missingFields = $missingFields;
    }

    public function isActive(): bool
    {
        return $this->status === Settings::STATUS_ACTIVE;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function liveMode(): bool
    {
        return $this->liveMode;
    }

    public function defaultTaxCode(): ?string
    {
        return $this->defaultTaxCode;
    }

    public function defaultTaxBehavior(): ?string
    {
        return $this->defaultTaxBehavior;
    }

    /** @return list<string> */
    public function missingFields(): array
    {
        return $this->missingFields;
    }

    public function readAt(): int
    {
        return $this->readAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'liveMode' => $this->liveMode,
            'defaultTaxCode' => $this->defaultTaxCode,
            'defaultTaxBehavior' => $this->defaultTaxBehavior,
            'missingFields' => $this->missingFields,
            'readAt' => $this->readAt,
        ];
    }
}
