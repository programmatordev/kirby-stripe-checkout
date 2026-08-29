<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;

/**
 * Carries validated non-secret values read from the protected hub Page.
 *
 * @internal
 */
final class PageSettings
{
    public function __construct(
        mixed $priceSource = null,
        mixed $currency = null,
        mixed $defaultRequiresShipping = null,
    ) {
        $priceSource = $priceSource === '' ? null : $priceSource;

        if (
            $priceSource !== null
            && (
                is_string($priceSource) === false
                || PriceSource::tryFrom($priceSource) === null
            )
        ) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                'settings.priceSource',
            );
        }

        $currency = $currency === '' ? null : $currency;

        if (
            $currency !== null
            && (
                is_string($currency) === false
                || (new StripeCurrencyRegistry())->supports($currency) === false
            )
        ) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                'settings.currency',
            );
        }

        // Kirby's select field stores stable strings; PHP configuration reaches
        // the resolver separately as a native boolean.
        $defaultRequiresShipping = match ($defaultRequiresShipping) {
            null, '' => null,
            true, 'yes' => true,
            false, 'no' => false,
            default => throw new ConfigurationException(
                'persistence.content_invalid',
                'settings.defaultRequiresShipping',
            ),
        };

        $this->priceSource = $priceSource;
        $this->currency = $currency;
        $this->defaultRequiresShipping = $defaultRequiresShipping;
    }

    private readonly ?string $priceSource;
    private readonly ?string $currency;
    private readonly ?bool $defaultRequiresShipping;

    public function priceSource(): ?string
    {
        return $this->priceSource;
    }

    public function currency(): ?string
    {
        return $this->currency;
    }

    public function defaultRequiresShipping(): ?bool
    {
        return $this->defaultRequiresShipping;
    }

    public function value(string $name): string|bool|null
    {
        return match ($name) {
            'priceSource' => $this->priceSource(),
            'currency' => $this->currency(),
            'defaultRequiresShipping' => $this->defaultRequiresShipping(),
            default => null,
        };
    }
}
