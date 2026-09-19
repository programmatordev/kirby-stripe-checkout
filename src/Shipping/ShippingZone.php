<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;

/** Groups fixed shipping options for one explicit-country or fallback zone. */
final readonly class ShippingZone
{
    /** @var list<string> */
    private array $countries;

    /** @var list<ShippingOption> */
    private array $options;

    /**
     * @param array<mixed> $countries
     * @param array<mixed> $options
     */
    public function __construct(
        private string $name,
        private ShippingZoneScope $scope,
        array $countries,
        array $options,
    ) {
        if (
            $name === ''
            || trim($name) !== $name
            || TextValidator::isSingleLine($name) === false
            || mb_strlen($name) > 100
        ) {
            throw new InvalidArgumentException('A shipping zone requires a valid name.');
        }

        if (array_is_list($countries) === false) {
            throw new InvalidArgumentException('Shipping zone countries must be a list.');
        }

        $normalizedCountries = [];
        $countryRegistry = new StripeShippingCountryRegistry();

        foreach ($countries as $country) {
            if (
                is_string($country) === false
                || preg_match('/\A[A-Z]{2}\z/D', $country) !== 1
                || $countryRegistry->supports($country) === false
                || isset($normalizedCountries[$country])
            ) {
                throw new InvalidArgumentException('Shipping zone countries must be unique Stripe-supported two-letter codes.');
            }

            $normalizedCountries[$country] = true;
        }

        if (
            ($scope === ShippingZoneScope::Fallback && $normalizedCountries !== [])
            || ($scope === ShippingZoneScope::SelectedCountries && $normalizedCountries === [])
        ) {
            throw new InvalidArgumentException('Shipping zone countries do not match their scope.');
        }

        // Checkout accepts at most five shipping options for one Session.
        // https://docs.stripe.com/api/checkout/sessions/create#checkout_session_create-shipping_options
        if (array_is_list($options) === false || $options === [] || count($options) > 5) {
            throw new InvalidArgumentException('A shipping zone requires between one and five options.');
        }

        $optionKeys = [];
        $optionLabels = [];

        foreach ($options as $option) {
            if (
                $option instanceof ShippingOption === false
                || isset($optionKeys[$option->key()])
                || isset($optionLabels[$option->label()])
            ) {
                throw new InvalidArgumentException('Shipping zone options must have unique keys and labels.');
            }

            $optionKeys[$option->key()] = true;
            $optionLabels[$option->label()] = true;
        }

        $this->countries = array_keys($normalizedCountries);
        /** @var list<ShippingOption> $options */
        $this->options = $options;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function scope(): ShippingZoneScope
    {
        return $this->scope;
    }

    /** @return list<string> */
    public function countries(): array
    {
        return $this->countries;
    }

    /** @return list<ShippingOption> */
    public function options(): array
    {
        return $this->options;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'scope' => $this->scope->value,
            'countries' => $this->countries,
            'options' => array_map(
                static fn(ShippingOption $option): array => $option->toArray(),
                $this->options,
            ),
        ];
    }
}
