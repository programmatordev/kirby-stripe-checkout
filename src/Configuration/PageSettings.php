<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;

/**
 * Carries validated non-secret values read from the protected hub Page.
 *
 * @internal
 */
final class PageSettings
{
    public function __construct(
        private readonly ?string $priceSource = null,
    ) {
        if (
            $this->priceSource !== null
            && in_array(
                $this->priceSource,
                [PriceSource::Kirby->value, PriceSource::Stripe->value],
                true,
            ) === false
        ) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                'settings.priceSource',
            );
        }
    }

    public function priceSource(): ?string
    {
        return $this->priceSource;
    }
}
