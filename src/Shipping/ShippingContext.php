<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;

/**
 * Immutable country and tax policy inputs for shipping resolution.
 * Purchase facts remain in CheckoutContext so this value stays shipping-specific.
 */
final readonly class ShippingContext
{
    public function __construct(
        private ?string $shippingCountry,
        private TaxBehavior $taxBehavior = TaxBehavior::StripeDefault,
        private ?string $taxCode = null,
    ) {
        self::validateShippingCountry($this->shippingCountry);

        if ($this->taxCode !== null && preg_match('/\Atxcd_[A-Za-z0-9]{1,249}\z/D', $this->taxCode) !== 1) {
            throw new InvalidArgumentException('A shipping context contains an invalid tax code.');
        }
    }

    public function shippingCountry(): ?string
    {
        return $this->shippingCountry;
    }

    public function taxBehavior(): TaxBehavior
    {
        return $this->taxBehavior;
    }

    public function taxCode(): ?string
    {
        return $this->taxCode;
    }

    private static function validateShippingCountry(?string $country): void
    {
        if ($country === null) {
            return;
        }

        // Provider support is a structural constraint. Store-specific zone or
        // resolver eligibility is evaluated later when the quote is resolved.
        if ((new StripeShippingCountryRegistry())->supports($country) === false) {
            throw new InvalidArgumentException('The shipping country is not supported by Stripe Checkout.');
        }
    }
}
