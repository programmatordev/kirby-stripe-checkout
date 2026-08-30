<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout;

use Brick\Money\Currency;
use Brick\Money\Money;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use ProgrammatorDev\StripeCheckout\Configuration\Settings;
use ProgrammatorDev\StripeCheckout\Money\MoneyFormatter;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\ProductSelection;
use ProgrammatorDev\StripeCheckout\Product\ProductSelectionView;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;

/**
 * Provides the immutable, Site-scoped entry point for plugin developers.
 */
final class StripeCheckout
{
    /**
     * Keeps every operation tied to the Site's active App instead of relying
     * on ambient global state.
     *
     * @internal Constructed by the registered Kirby Site method.
     */
    public function __construct(
        private readonly App $kirby,
    ) {}

    public function settings(): Settings
    {
        return (new RuntimeFactory($this->kirby))->settings();
    }

    public function formatMoney(
        Money|string|int $amount,
        Currency|string|null $currency = null,
        ?string $locale = null,
    ): string {
        return (new MoneyFormatter($this->kirby))->format($amount, $currency, $locale);
    }

    public function currencySymbol(
        Currency|string $currency,
        ?string $locale = null,
    ): string {
        return (new MoneyFormatter($this->kirby))->symbol($currency, $locale);
    }

    public function resolveProduct(ProductSelection $selection): ResolvedProduct
    {
        return (new RuntimeFactory($this->kirby))->resolveProduct($selection);
    }

    public function productSelection(Page|string $reference): ProductSelectionView
    {
        return (new RuntimeFactory($this->kirby))->productSelection($reference);
    }
}
