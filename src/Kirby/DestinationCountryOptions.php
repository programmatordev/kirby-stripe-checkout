<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Collator;
use InvalidArgumentException;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Shipping\StripeDestinationCountryRegistry;
use Symfony\Component\Intl\Countries;
use Throwable;

/** @internal Localizes and sorts Stripe-compatible destination countries. */
final readonly class DestinationCountryOptions
{
    /** @return array<string, string> */
    public function all(): array
    {
        return $this->forCodes((new StripeDestinationCountryRegistry())->codes());
    }

    /**
     * @param array<mixed> $countries
     * @return array<string, string>
     */
    public function forCodes(array $countries): array
    {
        if (array_is_list($countries) === false) {
            throw new InvalidArgumentException('Destination countries must be a list.');
        }

        $registry = new StripeDestinationCountryRegistry();
        $options = [];

        foreach ($countries as $country) {
            if (
                is_string($country) === false
                || $registry->supports($country) === false
                || isset($options[$country])
            ) {
                throw new InvalidArgumentException('Destination countries must be unique supported country codes.');
            }

            try {
                $name = Countries::getName($country, I18n::locale());
            } catch (Throwable) {
                $name = $country;
            }

            if ($name === $country) {
                // Symfony Intl does not label every special country code that
                // Stripe accepts, so bundled translations cover those gaps.
                $translatedName = I18n::translate(
                    'programmatordev.stripe-checkout.settings.shippingZones.countries.' . strtolower($country),
                    $country,
                );
                $name = is_string($translatedName) ? $translatedName : $country;
            }

            $options[$country] = $name;
        }

        (new Collator(I18n::locale()))->asort($options, Collator::SORT_STRING);

        return $options;
    }
}
