<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use Kirby\Cms\Site;
use Kirby\Cms\User;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Configuration\Settings;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductPriceSourceMismatchException;

/**
 * Gives resolvers the safe, immutable context for one resolution operation.
 */
final readonly class ProductResolutionContext
{
    public function __construct(
        private Site $site,
        private ?User $user,
        private ?string $languageCode,
        private string $locale,
        private PriceSource $priceSource,
        private Settings $settings,
    ) {
        if ($this->priceSource !== $this->settings->priceSource()) {
            throw new ProductPriceSourceMismatchException();
        }

        if ($this->settings->currency() === null) {
            throw new InvalidProductException('product.currency_missing');
        }

        if (
            ($this->languageCode !== null && ($this->languageCode === '' || trim($this->languageCode) !== $this->languageCode))
            || $this->locale === ''
            || trim($this->locale) !== $this->locale
        ) {
            throw new InvalidProductException('product.context_invalid');
        }
    }

    public function site(): Site
    {
        return $this->site;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function languageCode(): ?string
    {
        return $this->languageCode;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function priceSource(): PriceSource
    {
        return $this->priceSource;
    }

    public function settings(): Settings
    {
        return $this->settings;
    }
}
