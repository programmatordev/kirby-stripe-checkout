<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Collator;
use InvalidArgumentException;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Shipping\StripeShippingCountryRegistry;
use Symfony\Component\Intl\Countries;
use Throwable;

/** @internal Localizes and sorts Stripe-compatible shipping countries. */
final readonly class ShippingCountryOptions
{
    /** @return array<string, string> */
    public function all(): array
    {
        return $this->forCodes((new StripeShippingCountryRegistry())->codes());
    }

    /**
     * @param array<mixed> $countryCodes
     * @return array<string, string>
     */
    public function forCodes(array $countryCodes): array
    {
        if (array_is_list($countryCodes) === false) {
            throw new InvalidArgumentException('Shipping countries must be a list.');
        }

        $registry = new StripeShippingCountryRegistry();
        $options = [];

        foreach ($countryCodes as $countryCode) {
            if (
                is_string($countryCode) === false
                || $registry->supports($countryCode) === false
                || isset($options[$countryCode])
            ) {
                throw new InvalidArgumentException('Shipping countries must be unique supported country codes.');
            }

            try {
                $name = Countries::getName($countryCode, I18n::locale());
            } catch (Throwable) {
                $name = $countryCode;
            }

            if ($name === $countryCode) {
                // Symfony Intl does not label every special country code that
                // Stripe accepts, so bundled translations cover those gaps.
                $translatedName = I18n::translate(
                    'programmatordev.stripe-checkout.settings.shippingZones.countries.' . strtolower($countryCode),
                    $countryCode,
                );
                $name = is_string($translatedName) ? $translatedName : $countryCode;
            }

            $options[$countryCode] = $name;
        }

        (new Collator(I18n::locale()))->asort($options, Collator::SORT_STRING);

        return $options;
    }
}
