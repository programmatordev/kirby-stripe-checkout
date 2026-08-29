# Money and currency

The plugin uses [Brick Money](https://github.com/brick/money) for exact amounts. It never uses PHP floating-point values for prices, calculations, or Stripe amounts.

Products and Checkout are not implemented yet. The current package provides the store currency setting and formatting helpers that those features will use.

## Store currency

Choose one currency in the Stripe Checkout Settings tab. The selected currency applies to every future product, shipping option, Checkout Session, and order managed by the plugin.

You can lock the same setting in PHP:

```php
<?php

return [
    'programmatordev.stripe-checkout.settings.currency' => 'EUR',
];
```

Currency codes are uppercase three-letter Stripe presentment currencies. The plugin does not convert currencies, choose a currency from the visitor's locale, or allow individual products to override the store currency.

## Exact amounts

Write merchant amounts as locale-independent decimal strings:

```text
19
19.95
1.234
```

Use `.` as the decimal separator. Do not include spaces, grouping separators, currency symbols, commas, signs, or exponent notation. Floats such as `19.95` are not accepted because their binary representation can be imprecise.

The number of accepted decimal places depends on the configured currency. Extra trailing zeroes are harmless, but the plugin never rounds a value that cannot be represented by Stripe exactly.

The plugin converts amounts to the exact integer expected by Stripe without rounding. This matters because [Stripe's request units](https://docs.stripe.com/currencies?locale=en-GB#zero-decimal) do not always match ISO currency metadata. For example, MGA is zero-decimal, ISK and UGX use two request digits ending in `00`, and Stripe treats ISO three-decimal currencies such as BHD and KWD as two-decimal for payment requests. An amount that cannot be represented exactly is rejected before a Stripe request.

## Formatting amounts

Use `formatMoney()` for customer-facing output. In a Kirby template:

```php
<?php

/** @var Kirby\Cms\Site $site */

echo $site->stripeCheckout()->formatMoney('19.95', 'EUR');
```

The first argument can be a Brick `Money`, an exact major-unit string, or a major-unit integer:

```php
<?php

use Brick\Money\Money;

/** @var Kirby\Cms\Site $site */

echo $site->stripeCheckout()->formatMoney(Money::of('19.95', 'EUR'));
echo $site->stripeCheckout()->formatMoney(20, 'EUR');
echo $site->stripeCheckout()->formatMoney('-5.00', 'EUR', 'pt_PT');
```

Typical output with the corresponding locale is:

```php
$site->stripeCheckout()->formatMoney('19.95', 'EUR', 'en_US'); // €19.95
$site->stripeCheckout()->formatMoney('19.95', 'EUR', 'pt_PT'); // 19,95 €
$site->stripeCheckout()->formatMoney('-5', 'USD', 'en_US');    // -$5.00
$site->stripeCheckout()->formatMoney('500', 'JPY', 'ja_JP');  // ￥500
```

Exact spacing and symbol variants come from the Intl/ICU data installed with PHP.

A Brick `Money` already contains its currency, so passing another currency with it is an error. Strings and integers require an explicit currency. Integers mean whole major units—`20` means EUR 20, not 20 cents.

An explicit locale such as `pt_PT` takes priority. Otherwise the plugin uses the current Kirby language's monetary locale, Kirby's configured locale, and finally `en_US`.

## Currency symbols

For custom layouts, `currencySymbol()` returns the localized symbol:

```php
<?php

/** @var Kirby\Cms\Site $site */

echo $site->stripeCheckout()->currencySymbol('EUR');
```

Prefer `formatMoney()` when possible. Symbols such as `$` are ambiguous without an amount, locale, or currency code.

Invalid amounts, currencies, locales, or argument combinations throw `ProgrammatorDev\StripeCheckout\Exception\MoneyException`. Its `errorCode()` identifies the failure without including the rejected value.
