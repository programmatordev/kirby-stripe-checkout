<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

use Brick\Money\Currency;
use Brick\Money\Money;
use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Cart\CartError;
use ProgrammatorDev\StripeCheckout\Cart\CartItem;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SelectionData;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Exception\MoneyException;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductException;
use ProgrammatorDev\StripeCheckout\Product\InlinePrice;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;
use ProgrammatorDev\StripeCheckout\Translation\LocaleResolver;
use Throwable;

/** @internal Resolves presentation afresh without making it stored cart authority. */
final class CartViewFactory
{
    public function __construct(private readonly App $kirby) {}

    public function create(CartSnapshot $snapshot, CartMutator $mutator): Cart
    {
        $runtime = new RuntimeFactory($this->kirby);
        $currency = null;
        $subtotal = null;
        $errors = [];
        $items = [];

        try {
            $code = $runtime->settings()->currency();

            if ($code === null) {
                throw new ConfigurationException('configuration.required_missing', 'settings.currency');
            }

            $currency = Currency::of($code);
            $subtotal = Money::zero($currency);
        } catch (Throwable $error) {
            $errors[] = $this->error($error);
        }

        foreach ($snapshot->entries() as $entry) {
            $product = null;
            $itemPrice = null;
            $itemSubtotal = null;
            $itemErrors = [];

            try {
                $product = $runtime->resolveProduct($entry->request());

                // A saved selection may become unavailable, never a different product.
                if (SelectionData::equivalent($entry->request(), $product->request()) === false) {
                    throw new InvalidProductException('product.resolver_changed_request');
                }

                $price = $product->price();
                $itemPrice = $price instanceof InlinePrice
                    ? $price->unitPrice()
                    : $runtime->stripePriceResolver()->resolve($price, $currency?->getCurrencyCode() ?? '')->price();
                $itemSubtotal = $itemPrice->multipliedBy($entry->request()->quantity());
                (new StripeCurrencyRegistry())->fromMoney($itemSubtotal);
                $subtotal = $subtotal?->plus($itemSubtotal);
            } catch (Throwable $error) {
                $product = null;
                $itemPrice = null;
                $itemSubtotal = null;
                $itemErrors[] = $this->error($error, $entry->id());
            }

            $items[] = new CartItem($entry->id(), $entry->request(), $product, $itemPrice, $itemSubtotal, $itemErrors);
            array_push($errors, ...$itemErrors);
        }

        // Individually valid lines can still add up to an unsupported amount;
        // validate the aggregate before exposing it as the cart subtotal.
        if ($subtotal !== null && $errors === []) {
            try {
                (new StripeCurrencyRegistry())->fromMoney($subtotal);
            } catch (Throwable $error) {
                $errors[] = $this->error($error);
            }
        }

        // Keep readable lines, but never present their partial sum as the whole cart.
        return new Cart($snapshot, $items, $currency, $errors === [] ? $subtotal : null, $errors, $mutator, $this);
    }

    public function error(Throwable $error, ?string $itemId = null): CartError
    {
        $code = match (true) {
            $error instanceof ConfigurationException => 'cart.configuration_invalid',
            $error instanceof MoneyException => 'cart.amount_invalid',
            $error instanceof ProductException && $error->errorCode() === 'product.stripe_price_unavailable' => 'cart.provider_unavailable',
            $error instanceof ProductException && $error->errorCode() === 'product.request_invalid' => 'cart.selection_invalid',
            $error instanceof ProductException => 'cart.product_unavailable',
            $error instanceof CheckoutInputException => match ($error->errorCode()) {
                'selection.quantity_invalid' => 'cart.quantity_invalid',
                'selection.line_limit_exceeded' => 'cart.line_limit_exceeded',
                default => 'cart.selection_invalid',
            },
            $error instanceof CartMutationException && $error->errorCode() === 'cart.revision_conflict' => 'cart.revision_conflict',
            $error instanceof CartMutationException && $error->errorCode() === 'cart.item_not_found' => 'cart.item_not_found',
            default => 'cart.unavailable',
        };

        return $this->translatedError($code, itemId: $itemId);
    }

    /** Only call with plugin-owned codes, never raw exception/provider messages. */
    public function translatedError(string $code, ?string $field = null, ?string $itemId = null): CartError
    {

        // Only plugin-owned codes cross this edge. Even a custom resolver's
        // exception code/message can contain arbitrary, sensitive provider data.
        $key = Catalogue::PREFIX . $code;
        $locale = 'en';

        try {
            $locale = (new LocaleResolver($this->kirby))->resolve();
        } catch (Throwable) {
        }

        $language = $this->kirby->language()?->code();
        $message = null;

        foreach (array_unique(array_filter([$language, $locale, explode('_', $locale)[0], 'en'])) as $candidate) {
            $message = $this->kirby->translation($candidate)->get($key);

            if ($message !== null) {
                break;
            }
        }

        return new CartError($code, $message ?? Catalogue::bundled()['en'][$key], $itemId, $field);
    }
}
