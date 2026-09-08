<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout;

use Brick\Money\Currency;
use Brick\Money\Money;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\User;
use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Configuration\Settings;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Money\MoneyFormatter;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductOptions;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;

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

    public function cart(): ?Cart
    {
        return (new RuntimeFactory($this->kirby))->cart();
    }

    /** @return Pages<Page> */
    public function orders(): Pages
    {
        return (new OrderPageStore($this->kirby))->orders();
    }

    public function order(string $uuid): ?Page
    {
        return (new OrderPageStore($this->kirby))->order($uuid);
    }

    /** @return Pages<Page> */
    public function ordersFor(User $user): Pages
    {
        return (new OrderPageStore($this->kirby))->ordersFor($user);
    }

    public function orderFor(User $user, string $uuid): ?Page
    {
        return (new OrderPageStore($this->kirby))->orderFor($user, $uuid);
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

    public function resolveProduct(ProductRequest $request): Product
    {
        return (new RuntimeFactory($this->kirby))->resolveProduct($request);
    }

    public function productOptions(Page|string $reference): ProductOptions
    {
        return (new RuntimeFactory($this->kirby))->productOptions($reference);
    }
}
