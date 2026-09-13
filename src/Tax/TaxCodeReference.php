<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Tax;

use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;

/** Identifies a product classification, with optional confirmed Stripe facts. */
final readonly class TaxCodeReference
{
    public function __construct(
        private string $id,
        private string $label = '',
        private string $providerName = '',
        private string $providerDescription = '',
        private bool $confirmed = false,
    ) {
        // Syntax is not confirmation: the catalogue must supply the exact ID.
        // https://docs.stripe.com/api/tax_codes
        if (
            preg_match('/^txcd_[A-Za-z0-9]{1,249}$/D', $this->id) !== 1
            || TextValidator::isSingleLine($this->label) === false
            || TextValidator::isSingleLine($this->providerName) === false
            || TextValidator::isUtf8($this->providerDescription) === false
            || ($this->confirmed && trim($this->providerName) === '')
        ) {
            throw new ConfigurationException('tax.code_invalid', 'settings.taxCodes');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label !== '' ? $this->label : ($this->providerName !== '' ? $this->providerName : $this->id);
    }

    public function providerName(): string
    {
        return $this->providerName;
    }

    public function providerDescription(): string
    {
        return $this->providerDescription;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }
}
