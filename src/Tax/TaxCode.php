<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Tax;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;

/**
 * Represents a product tax classification and optional Stripe catalogue facts.
 * A code carries no fixed rate: Stripe applies jurisdiction-specific rules using
 * the customer location and the merchant's tax setup and active registrations.
 * Catalogue membership alone does not guarantee applicability or tax collection.
 * https://docs.stripe.com/tax/checkout/page
 */
final readonly class TaxCode
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
            throw new InvalidProductException(TaxErrorCode::CODE_INVALID);
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

    /**
     * Reports attached catalogue facts, not current membership or tax readiness.
     * Product resolution checks membership independently, even when this is true.
     */
    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }
}
