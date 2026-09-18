<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Option\Options;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;

/** Adapts the synchronized shipping-zone editor to Kirby's field API. */
final class ShippingZonesField extends SynchronizedStructureField
{
    /** @var list<array{text: string, value: string}> */
    private readonly array $countryOptions;

    private readonly ?string $currency;

    /** @var list<array<string, mixed>>|null */
    private readonly ?array $lockedValue;

    private readonly ShippingZoneStructureAdapter $structureAdapter;

    /** @param array<string, mixed> $params */
    public function __construct(array $params = [])
    {
        parent::__construct($params);

        $this->structureAdapter = new ShippingZoneStructureAdapter();
        $this->currency = $this->resolveCurrency($params['currency'] ?? null);
        $countryOptions = $params['countryOptions'] ?? [];
        $lockedValue = $params['lockedValue'] ?? null;
        $renderedCountryOptions = is_array($countryOptions)
            ? Options::factory($countryOptions)->render($this->model())
            : [];
        $normalizedCountryOptions = [];

        foreach ($renderedCountryOptions as $option) {
            if (is_array($option) === false) {
                continue;
            }

            $text = $option['text'] ?? null;
            $value = $option['value'] ?? null;

            if (is_string($text) && is_string($value)) {
                $normalizedCountryOptions[] = [
                    'text' => $text,
                    'value' => $value,
                ];
            }
        }

        $this->countryOptions = $normalizedCountryOptions;
        $this->lockedValue = is_array($lockedValue)
            ? $this->structureAdapter->canonical($lockedValue)
            : null;
    }

    public function type(): string
    {
        return 'stripe-checkout-shipping-zones';
    }

    /** @return array<string, mixed> */
    public function props(): array
    {
        return [
            ...parent::props(),
            'countryOptions' => $this->countryOptions,
            'currency' => $this->currency,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function toFormValue(): array
    {
        return $this->lockedValue ?? parent::toFormValue();
    }

    protected function adapter(): SynchronizedStructureAdapterInterface
    {
        return $this->structureAdapter;
    }

    private function resolveCurrency(mixed $currency): ?string
    {
        if (is_string($currency) && $currency !== '') {
            return strtoupper($currency);
        }

        try {
            return (new RuntimeFactory($this->kirby()))->settings()->currency();
        } catch (ConfigurationException) {
            return null;
        }
    }
}
