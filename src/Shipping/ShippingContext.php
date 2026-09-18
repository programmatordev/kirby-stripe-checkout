<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;

/**
 * Immutable destination and tax policy inputs for shipping resolution.
 * Purchase facts remain in CheckoutContext so this value stays shipping-specific.
 */
final readonly class ShippingContext
{
    /** @var list<string> */
    private array $allowedCountries;

    /** @param array<mixed> $allowedCountries */
    public function __construct(
        array $allowedCountries,
        private ?string $destinationCountry,
        private TaxBehavior $taxBehavior = TaxBehavior::StripeDefault,
        private ?string $taxCode = null,
    ) {
        $this->allowedCountries = self::validateCountries($allowedCountries);
        self::validateDestination(
            $this->destinationCountry,
            $this->allowedCountries,
        );

        if ($this->taxCode !== null && preg_match('/\Atxcd_[A-Za-z0-9]{1,249}\z/D', $this->taxCode) !== 1) {
            throw new InvalidArgumentException('A shipping context contains an invalid tax code.');
        }
    }

    /** @return list<string> */
    public function allowedCountries(): array
    {
        return $this->allowedCountries;
    }

    public function destinationCountry(): ?string
    {
        return $this->destinationCountry;
    }

    public function taxBehavior(): TaxBehavior
    {
        return $this->taxBehavior;
    }

    public function taxCode(): ?string
    {
        return $this->taxCode;
    }

    /**
     * @param array<mixed> $countries
     * @return list<string>
     */
    private static function validateCountries(array $countries): array
    {
        if (array_is_list($countries) === false || $countries === []) {
            throw new InvalidArgumentException('A shipping context requires allowed countries.');
        }

        $registry = new StripeShippingCountryRegistry();
        $normalized = [];

        foreach ($countries as $country) {
            if (
                is_string($country) === false
                || $registry->supports($country) === false
                || isset($normalized[$country])
            ) {
                throw new InvalidArgumentException('A shipping context contains invalid allowed countries.');
            }

            $normalized[$country] = true;
        }

        return array_keys($normalized);
    }

    /** @param list<string> $allowedCountries */
    private static function validateDestination(?string $country, array $allowedCountries): void
    {
        if ($country === null) {
            return;
        }

        if (in_array($country, $allowedCountries, true) === false) {
            throw new InvalidArgumentException('The destination is not an allowed shipping country.');
        }
    }
}
