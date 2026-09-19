<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

use Brick\Money\Currency;
use Brick\Money\Money;
use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Cart\CartError;
use ProgrammatorDev\StripeCheckout\Cart\CartErrorCode;
use ProgrammatorDev\StripeCheckout\Cart\CartItem;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ProductRequestData;
use ProgrammatorDev\StripeCheckout\Checkout\SelectionErrorCode;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationErrorCode;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Exception\MoneyException;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductException;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\ProductErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\Tax\TaxErrorCode;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;
use ProgrammatorDev\StripeCheckout\Translation\LocaleResolver;
use Throwable;

/** @internal Resolves presentation afresh without making it stored cart authority. */
final class CartViewFactory
{
    public function __construct(private readonly App $kirby) {}

    public function create(CartSnapshot $snapshot, CartMutator $mutator, bool $resolve = true): Cart
    {
        if ($resolve === false) {
            return new Cart($snapshot, [], null, null, [], $mutator, $this, presentationResolved: false);
        }

        $runtime = new RuntimeFactory($this->kirby);
        $currency = null;
        $subtotal = null;
        $errors = [];
        $items = [];

        try {
            $code = $runtime->settings()->currency();

            if ($code === null) {
                throw new ConfigurationException(ConfigurationErrorCode::REQUIRED_MISSING, 'settings.currency');
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
                if (ProductRequestData::sameItem($entry->request(), $product->request()) === false) {
                    throw new InvalidProductException(ProductErrorCode::RESOLVER_CHANGED_REQUEST);
                }

                $price = $product->price();
                $itemPrice = $price instanceof Price
                    ? $price->price()
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
            $error instanceof ConfigurationException => CartErrorCode::CONFIGURATION_INVALID,
            $error instanceof MoneyException => CartErrorCode::AMOUNT_INVALID,
            $error instanceof ProductException && in_array($error->errorCode(), [ProductErrorCode::STRIPE_PRICE_UNAVAILABLE, TaxErrorCode::CATALOGUE_UNAVAILABLE], true) => CartErrorCode::PROVIDER_UNAVAILABLE,
            $error instanceof ProductException && $error->errorCode() === ProductErrorCode::REQUEST_INVALID => CartErrorCode::SELECTION_INVALID,
            $error instanceof ProductException => CartErrorCode::PRODUCT_UNAVAILABLE,
            $error instanceof CheckoutInputException => match ($error->errorCode()) {
                SelectionErrorCode::QUANTITY_INVALID => CartErrorCode::QUANTITY_INVALID,
                SelectionErrorCode::LINE_LIMIT_EXCEEDED => CartErrorCode::LINE_LIMIT_EXCEEDED,
                ShippingErrorCode::DESTINATION_INVALID => ShippingErrorCode::DESTINATION_INVALID,
                default => CartErrorCode::SELECTION_INVALID,
            },
            $error instanceof CartMutationException && $error->errorCode() === CartErrorCode::REVISION_CONFLICT => CartErrorCode::REVISION_CONFLICT,
            $error instanceof CartMutationException && $error->errorCode() === CartErrorCode::ITEM_NOT_FOUND => CartErrorCode::ITEM_NOT_FOUND,
            default => CartErrorCode::UNAVAILABLE,
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
