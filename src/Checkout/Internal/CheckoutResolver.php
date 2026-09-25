<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use Closure;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationErrorCode;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Configuration\Settings;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Internal\GuardedProductResolver;
use ProgrammatorDev\StripeCheckout\Product\Internal\TaxCodeValidator;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductErrorCode;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Shipping\Internal\ShippingQuotePipeline;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingZoneScope;
use ProgrammatorDev\StripeCheckout\Shipping\StripeShippingCountryRegistry;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceResolver;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;

/** @internal Resolves one operation's product, price and shipping facts without persistence. */
final class CheckoutResolver
{
    private ?ProductResolutionContext $context = null;

    /**
     * These closures construct dependencies on demand: shipping-only work needs
     * no product context, and Kirby-priced lines need no Stripe Price resolver.
     * The product context is captured once per resolver operation, not per line.
     *
     * @param Closure(): ProductResolutionContext $productContext
     * @param Closure(): PriceResolver $stripePriceResolver
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly Closure $productContext,
        private readonly GuardedProductResolver $productResolver,
        private readonly Closure $stripePriceResolver,
        private readonly TaxCodeValidator $taxCodeValidator,
        private readonly ShippingQuotePipeline $shippingQuotes,
        private readonly bool $customShippingResolver,
    ) {}

    public function currency(): string
    {
        return $this->settings->currency()
            ?? throw new ConfigurationException(ConfigurationErrorCode::REQUIRED_MISSING, 'settings.currency');
    }

    public function resolveProduct(ProductRequest $request): Product
    {
        $context = $this->productContext();
        $product = $this->productResolver->resolve(
            $request,
            $context,
        );

        $this->taxCodeValidator->validate($product->taxCode(), $context);

        return $product;
    }

    /** Resolves untrusted cartless product input into the shared trusted context. */
    public function directCheckoutContext(mixed $items): CheckoutContext
    {
        $requests = (new ProductRequestNormalizer($this->resolveProduct(...)))
            ->normalizeDirectInput($items);
        $lineItems = [];

        foreach ($requests as $request) {
            // Normalization can merge duplicate lines. Resolve the resulting request
            // so quantity limits and product facts reflect the final quantity.
            $product = $this->resolveProduct($request);

            // Canonicalization may change a locator, never the selected product.
            if (ProductRequestData::sameItem($request, $product->request()) === false) {
                throw new InvalidProductException(ProductErrorCode::RESOLVER_CHANGED_REQUEST);
            }

            $lineItems[] = $this->checkoutLineItem($product);
        }

        return $this->checkoutContext($lineItems, CheckoutSource::Direct);
    }

    /** Resolves the price once and retains its provider references with the line. */
    public function checkoutLineItem(Product $product): CheckoutLineItem
    {
        $productPrice = $product->price();
        $stripePrice = $productPrice instanceof StripePriceReference
            ? ($this->stripePriceResolver)()->resolve($productPrice, $this->currency())
            : null;

        return new CheckoutLineItem($product, $stripePrice);
    }

    /** Mutation eligibility uses the same fresh pricing boundary as presentation. */
    public function cartProduct(ProductRequest $request): Product
    {
        $product = $this->resolveProduct($request);

        if ($product->price() instanceof StripePriceReference) {
            // Selection writes check eligibility, not presentation totals. A cart
            // must remain editable even when its resolved subtotal is unavailable.
            ($this->stripePriceResolver)()->resolve($product->price(), $this->currency());
        }

        return $product;
    }

    /**
     * Builds current-request facts and validates invariants shared by Cart and direct Checkout.
     *
     * @param list<CheckoutLineItem> $lineItems
     */
    public function checkoutContext(array $lineItems, CheckoutSource $checkoutSource): CheckoutContext
    {
        $context = new CheckoutContext(
            items: $lineItems,
            languageCode: $this->productContext()->languageCode(),
            locale: $this->productContext()->locale(),
            userUuid: $this->productContext()->user()?->uuid()->toString(),
            checkoutSource: $checkoutSource,
            uiMode: $this->settings->uiMode(),
        );

        // Individually valid lines can still exceed provider-unit integer bounds
        // when their subtotals are combined.
        (new StripeCurrencyRegistry())->fromMoney($context->subtotal());

        return $context;
    }

    /** Maps the optional direct-input country to the same trusted policy used by Cart Checkout. */
    public function directShippingContext(mixed $shippingCountry = null): ShippingContext
    {
        if (
            $shippingCountry !== null
            && (
                is_string($shippingCountry) === false
                || (new StripeShippingCountryRegistry())->supports($shippingCountry) === false
            )
        ) {
            throw new CheckoutInputException(ShippingErrorCode::COUNTRY_INVALID);
        }

        return $this->shippingContext($shippingCountry);
    }

    /** Applies the settings-owned tax policy to a trusted shipping country. */
    public function shippingContext(?string $shippingCountry): ShippingContext
    {
        $settings = $this->settings;
        $automaticTax = $settings->automaticTax();

        return new ShippingContext(
            shippingCountry: $shippingCountry,
            taxBehavior: $automaticTax
                ? $settings->shippingTaxBehavior()
                : TaxBehavior::StripeDefault,
            taxCode: $automaticTax
                ? $settings->shippingTaxCode()->taxCode()
                : null,
        );
    }

    public function resolveShippingQuote(
        CheckoutContext $checkout,
        ShippingContext $shipping,
    ): ?ShippingQuote {
        return $this->shippingQuotes->resolve(
            $checkout,
            $shipping,
        );
    }

    /** @return list<string> */
    public function shippingCountryCodes(): array
    {
        if ($this->customShippingResolver) {
            // A replacement resolver owns shipping-country eligibility, so the
            // built-in zones cannot narrow its possible input countries.
            return (new StripeShippingCountryRegistry())->codes();
        }

        $countries = [];

        foreach ($this->settings->shippingZones() as $zone) {
            if ($zone->scope() === ShippingZoneScope::Fallback) {
                return (new StripeShippingCountryRegistry())->codes();
            }

            foreach ($zone->countries() as $country) {
                $countries[$country] = true;
            }
        }

        return array_keys($countries);
    }

    public function validateTaxCode(TaxCode $taxCode): void
    {
        // Inactive local classification must not require a current product
        // context just to map an already frozen order into a Session request.
        if ($this->settings->automaticTax() === false || $this->settings->priceSource() !== PriceSource::Kirby) {
            return;
        }

        $this->taxCodeValidator->validate($taxCode, $this->productContext());
    }

    private function productContext(): ProductResolutionContext
    {
        return $this->context ??= ($this->productContext)();
    }
}
