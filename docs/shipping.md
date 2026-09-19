# Shipping quotes

Shipping is Kirby-owned. The built-in resolver uses the ordered zones and fixed whole-order options configured in the Stripe Checkout Settings Page. A project can replace that calculation with one PHP resolver for product-specific rules, free-shipping thresholds, pickup labels, carrier APIs, or other store policy.

The quote engine and resolver contract power the [PHP Cart shipping preview](cart.md#preview-shipping), JSON Cart responses, and project-owned HTML Cart renderers. A destination can be retained through the [PHP Cart API](cart.md#set-the-shipping-destination) or the revision-safe [Cart HTTP route](cart-http.md#preview-shipping-for-a-destination). Checkout Session shipping mapping is not implemented yet.

## Built-in resolution

Every shipping destination resolves to one zone:

- an explicit zone containing the destination country wins;
- otherwise the optional rest-of-world fallback applies;
- without either match, shipping is unavailable.

Options from different zones are never combined. Before a destination is known, the resolver can return options only when a fallback is the sole configured zone and therefore applies everywhere. Otherwise it reports that a destination is required. An empty shipping configuration is unavailable rather than implicitly free.

Digital-only orders do not produce a shipping quote and never call a custom resolver. Mixed orders provide every line to the resolver and identify the subset that requires shipping.

For a shippable Cart, `destinationCountries()` exposes localized country choices for the storefront. Explicit built-in zones narrow the choices to their configured countries. A rest-of-world fallback, or a custom resolver whose eligibility cannot be inferred from settings, exposes every Stripe-supported destination and leaves the final availability decision to quote resolution.

## Replacing the resolver

Configure either a `ShippingResolverInterface` implementation or a typed Closure in `site/config/config.php`:

```php
<?php

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;

return [
    'programmatordev.stripe-checkout' => [
        'shipping' => [
            'resolver' => static function (
                CheckoutContext $checkout,
                ShippingContext $shipping,
            ): ShippingQuote {
                if ($shipping->destinationCountry() === null) {
                    return ShippingQuote::destinationRequired();
                }

                if ($checkout->subtotal()->isGreaterThanOrEqualTo(Money::of('75.00', $checkout->currency()))) {
                    return ShippingQuote::available([
                        new ShippingOption(
                            key: 'free',
                            label: 'Free delivery',
                            amount: Money::zero($checkout->currency()),
                            taxBehavior: $shipping->taxBehavior(),
                            taxCode: $shipping->taxCode(),
                        ),
                    ]);
                }

                return ShippingQuote::available([
                    new ShippingOption(
                        key: 'standard',
                        label: 'Standard delivery',
                        amount: Money::of('4.90', $checkout->currency()),
                        taxBehavior: $shipping->taxBehavior(),
                        taxCode: $shipping->taxCode(),
                    ),
                ]);
            },
        ],
    ],
];
```

The custom resolver replaces the zone resolver; its result is not merged with configured zones. The Settings Page retains the zones for a later return to built-in resolution, shows them as inactive, and keeps the shipping tax defaults editable. Diagnostics reports which calculation source is active without calling the resolver.

The resolver receives two immutable contexts with separate responsibilities.

`CheckoutContext` describes the normalized purchase:

- `items()` and `shippableItems()` return `CheckoutLineItem` values;
- `currency()` and `subtotal()` describe the complete merchandise selection;
- `languageCode()` and `locale()` preserve customer presentation context;
- `userUuid()` is the authenticated Kirby user UUID when present;
- `checkoutSource()` and `uiMode()` identify the direct/Cart and hosted/embedded flow.

`ShippingContext` contains the shipping policy inputs:

- `destinationCountry()` returns the validated quote destination, when known;
- `taxBehavior()` and `taxCode()` provide the effective shipping tax defaults for new options.

Each line item exposes `productReference()`, nullable `variantId()` and `sku()`, `quantity()`, effective `price()`, calculated `subtotal()`, `requiresShipping()`, selected `options()`, and safe project `metadata()`.

## Adjusting a resolved quote

Use the `programmatordev.stripe-checkout.shipping.quote` Kirby hook when configured zones or a replacement resolver provide the correct starting point but one Checkout needs an adjustment. The hook receives the resolved quote and both contexts and must return a `ShippingQuote`:

```php
<?php

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;

return [
    'hooks' => [
        'programmatordev.stripe-checkout.shipping.quote' => function (
            ShippingQuote $quote,
            CheckoutContext $checkout,
            ShippingContext $shipping,
        ): ShippingQuote {
            if ($checkout->subtotal()->isLessThan(Money::of('75.00', $checkout->currency()))) {
                return $quote;
            }

            return ShippingQuote::available([
                new ShippingOption(
                    key: 'free',
                    label: 'Free delivery',
                    amount: Money::zero($checkout->currency()),
                    taxBehavior: $shipping->taxBehavior(),
                    taxCode: $shipping->taxCode(),
                ),
            ]);
        },
    ],
];
```

The hook runs after built-in or custom resolution. Its result passes through the same currency and quote validation before it can reach Cart or Checkout Session code. Digital-only checkouts return no quote and do not invoke the resolver or hook.

## Quote outcomes

A resolver returns exactly one outcome:

```php
ShippingQuote::available([$standard, $express]);
ShippingQuote::destinationRequired();
ShippingQuote::unavailable();
ShippingQuote::unavailable('shipping.carrier_unavailable');
```

Available quotes require one through five ordered options. Keys and labels must be unique inside the quote, amounts must be exact and non-negative, and every option must use the Checkout currency. The first option remains first for Stripe's later preselection.

Reason codes are safe, language-neutral strings in the `shipping.*` namespace. Return a specific code when the storefront needs to distinguish a known unavailable condition. Do not put carrier messages, addresses, credentials, or other private data in a reason code.

`$cart->shippingQuote()` returns these same immutable outcomes after rebuilding trusted Checkout and shipping contexts from the current Cart. Empty and digital-only carts return `null`. A destination-required outcome is informational; an unavailable outcome blocks Checkout. Shipping-resolution failures add one generic translated Cart error, while an existing product or configuration error is not duplicated. Resolver-specific reason codes remain on the quote for project logic without exposing exception messages.

The available options are preview information rather than Cart selection state. Stripe owns the final selection in Checkout and preselects the first ordered option. Checkout creation resolves the quote again from fresh product and configuration data.

Resolver exceptions are normalized to `shipping.resolver_failed`. Hook failures use `shipping.filter_failed`, while an invalid hook return uses `shipping.filter_invalid`. Original exception details are retained only as the previous cause for developer debugging; they are not copied into the safe public error code or message. Neither extension point receives a Stripe client, mutable Cart, order storage, or raw browser request. They must not trust browser-supplied amounts or create Stripe resources.

See [Configuration](configuration.md#shipping-configuration) for fixed zones, delivery estimates, translations, and shipping tax defaults.
