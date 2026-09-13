# Configuration

This guide covers the configuration behavior available in the current package. Protected Checkout Session creation is implemented internally, but the public browser Checkout route is not available yet.

## Stripe credentials

The future hosted Checkout flow requires a server key and webhook signing secret. Configure them with the nested plugin option in `site/config/config.php`:

```php
<?php

return [
    'programmatordev.stripe-checkout' => [
        'stripe' => [
            'secretKey' => getenv('KIRBY_STRIPE_CHECKOUT_SECRET_KEY') ?: null,
            'webhookSecret' => getenv('KIRBY_STRIPE_CHECKOUT_WEBHOOK_SECRET') ?: null,
        ],
    ],
];
```

Embedded Checkout will additionally require the publishable key:

```php
'publishableKey' => getenv('KIRBY_STRIPE_CHECKOUT_PUBLISHABLE_KEY') ?: null,
```

Credentials are optional until an implemented operation requires them. The general Settings API and diagnostics never return credential values or fragments.

## Environment-specific credentials

Keep secret and webhook keys in environment or deployment configuration and never commit them. Kirby 5 automatically loads `site/config/env.php` after regular and host-specific configuration, so it can map deployment variables without placing credentials in PHP files:

```php
<?php

return [
    'programmatordev.stripe-checkout.stripe.secretKey' => getenv('KIRBY_STRIPE_CHECKOUT_SECRET_KEY') ?: null,
    'programmatordev.stripe-checkout.stripe.publishableKey' => getenv('KIRBY_STRIPE_CHECKOUT_PUBLISHABLE_KEY') ?: null,
    'programmatordev.stripe-checkout.stripe.webhookSecret' => getenv('KIRBY_STRIPE_CHECKOUT_WEBHOOK_SECRET') ?: null,
];
```

## Store settings

The Settings tab currently contains:

- `priceSource`: `kirby` (the default) or `stripe`;
- `currency`: one uppercase Stripe presentment currency, required before commerce features can run;
- `defaultRequiresShipping`: the fallback used when a product does not declare whether it needs shipping;
- `uiMode`: `hosted` (the default) or `embedded`;
- translated success, cancellation, and embedded-return destinations;
- billing-address, individual-name, business-name, phone, tax-ID and consent collection;
- customer-entered promotion codes;
- Automatic Tax and the tax inclusion policy for Kirby prices;
- [order retention preferences](#order-retention), with separate controls for failed attempts and unpaid orders.

The protected Page is created with `kirby` as its saved price source, so a fresh installation does not require an initial save for that deterministic default. The plugin does not guess a currency or whether products are physical. It can boot with those two fields empty so the Panel and diagnostics remain available, but the Settings tab asks the operator to select both values.

An explicit PHP value is treated as locked deployment configuration:

```php
<?php

return [
    'programmatordev.stripe-checkout' => [
        'settings' => [
            'priceSource' => 'kirby',
            'currency' => 'EUR',
            'defaultRequiresShipping' => false,
            'uiMode' => 'hosted',
            'successDestination' => 'page://thanks-page-uuid',
            'cancelDestination' => '/cart',
            'returnDestination' => '/checkout',
            'billingAddressCollection' => 'auto',
            'individualNameCollection' => 'optional',
            'businessNameCollection' => 'off',
            'phoneNumberCollection' => false,
            'taxIdCollection' => 'off',
            'termsOfServiceConsent' => false,
            'promotionsConsent' => false,
            'allowPromotionCodes' => false,
            'automaticTax' => false,
            'taxBehavior' => 'stripe_default',
        ],
    ],
];
```

The three destination fields accept a published Kirby Page reference or an HTTP(S) URL. Choosing a Page in the Panel is recommended because its URL is resolved for the language in which Checkout started. Root-relative and external HTTP(S) URLs are also supported. Unsafe or unresolved values are rejected when a Checkout request is prepared. Leaving a destination empty uses the initiating same-site URL, then the language-specific site URL as a fallback.

The Panel shows success and cancellation destinations for hosted Checkout, and the return destination for embedded Checkout. Checkout Sessions use a fixed 24-hour lifetime. Retention-day fields are shown only when their corresponding cleanup option is enabled.

Live Stripe credentials require HTTPS destinations, except for local hosts such as DDEV. Query strings are retained and fragments are removed. Destination values come only from trusted Settings or PHP configuration; they are never accepted from the Checkout form body.

These destination values are available through Settings now. The current package does not yet expose a public Checkout endpoint that consumes them.

The collection controls are independent. Tax-ID collection does not enable Automatic Tax, and billing-address collection set to `auto` does not promise a complete address. The defaults match Stripe's disabled or automatic behavior except for the individual name, which this plugin asks for optionally by default. Enabling phone collection makes the field required in Stripe Checkout. Terms acceptance requires the store's terms URL to be configured in Stripe. Stripe currently restricts promotional-email consent to US merchants and US customers.

Stripe Checkout supports at most three custom fields. Configure them in the Settings tab or lock the complete list through PHP. Their stable keys and dropdown values use lowercase letters and numbers; keys accept up to 200 characters and dropdown values up to 100. PHP labels can provide language-specific overrides keyed by Kirby language code:

```php
'settings' => [
    'customFields' => [
        [
            'key' => 'nif',
            'label' => 'Tax number',
            'labels' => ['pt' => 'NIF'],
            'type' => 'text',
            'required' => false,
            'minimumLength' => 9,
            'maximumLength' => 9,
        ],
    ],
],
```

Supported types are `text`, `numeric`, and `dropdown`. Dropdowns require between one and 200 options, each with a stable `value`, fallback `label`, and optional `labels`. Custom fields must not request card or bank details, passwords, health information, or other sensitive data prohibited by Stripe or applicable law.

On multi-language sites, create, remove, and order fields and dropdown options in the default language. Other languages can translate only customer-facing labels. Stable internal row IDs connect those translations without appearing in the public Settings API. Missing translations use the default-language label, and removing a default-language row also removes it from the effective translated list. A secondary-language Page/API update cannot add fields, reorder them, or change their keys, types, bounds, defaults, requirements, or dropdown values.

The effective collection and promotion settings are added to every Checkout Session request. Disabled values are omitted, while billing-address collection is always explicit. Optional and required names map to Stripe's corresponding name controls; phone collection is enabled only when requested; tax-ID collection maps to Stripe's optional or location-aware required mode; and consent settings map to Stripe's terms and promotional-email controls. Custom fields use their active-language labels and customer-entered promotion codes are enabled only when configured. Payment-method types remain managed by Stripe rather than being fixed by the plugin.

Fully dotted Kirby option keys are accepted, but defining the same logical option in nested and dotted forms is an error.

When PHP locks a setting, the Panel keeps the field visible, shows the effective value, and explains its configuration path. A previously stored Page value is preserved and becomes active again if the PHP value is removed. The same lock is enforced on the server.

Normal Panel saves, including partial saves and pending edits, never copy PHP overrides into Page content. Direct Page/API attempts to change a locked setting are rejected.

Unknown options, wrong types, unsupported values, duplicate definitions, blank credentials, and recognizable test/live key mismatches are rejected when plugin configuration is used. Invalid plugin configuration does not prevent unrelated Kirby pages from booting.

## Automatic Tax configuration

`automaticTax` defaults to `false`. The separate `taxBehavior` setting controls how Kirby product prices represent tax:

- `stripe_default` uses the account's Stripe Tax policy;
- `inclusive` means the listed product price already includes tax;
- `exclusive` means Stripe adds any applicable tax to that price.

The Panel shows this policy only when Automatic Tax is enabled and the price source is Kirby. Its saved value is retained when hidden. Stripe Prices use the tax behavior and product classification configured in Stripe, not this local policy. Tax-ID collection is independent of Automatic Tax.

These settings can currently be saved and read, but **they are not yet connected to Checkout Session creation**. Enabling the toggle does not yet enable tax in Checkout Sessions. The plugin never calculates VAT percentages or changes tax registrations. Stripe calculates tax according to customer location, product classification, and your registrations; enabling Automatic Tax alone does not mean tax will be collected everywhere. See [Stripe's tax inclusion guide](https://docs.stripe.com/tax/products-prices-tax-codes-tax-behavior) and [Stripe Tax setup](https://docs.stripe.com/tax/set-up).

## Reading effective settings

The Site entry point returns sanitized effective settings:

```php
<?php

/** @var Kirby\Cms\Site $site */
$settings = $site->stripeCheckout()->settings();

$settings->priceSource(); // PriceSource::Kirby or PriceSource::Stripe
$settings->currency(); // "EUR" or null
$settings->defaultRequiresShipping(); // true, false, or null
$settings->uiMode(); // UiMode::Hosted or UiMode::Embedded
$settings->successDestination(); // Page reference, URL, or null
$settings->cancelDestination(); // Page reference, URL, or null
$settings->returnDestination(); // Page reference, URL, or null
$settings->billingAddressCollection(); // BillingAddressCollection enum
$settings->individualNameCollection(); // NameCollectionMode enum
$settings->businessNameCollection(); // NameCollectionMode enum
$settings->phoneNumberCollection(); // boolean
$settings->taxIdCollection(); // TaxIdCollection enum
$settings->termsOfServiceConsent(); // boolean
$settings->promotionsConsent(); // boolean
$settings->customFields(); // list of CustomField values
$settings->allowPromotionCodes(); // boolean
$settings->automaticTax(); // boolean
$settings->taxBehavior(); // TaxBehavior enum

$priceSource = $settings->setting('priceSource');
$priceSource?->value();
$priceSource?->source();
$priceSource?->isLocked();
```

Only safe, store-facing settings are available through this API. Credentials and structural configuration are absent rather than redacted.

Page values override internal defaults. Explicit PHP values remain authoritative and lock only their corresponding Page fields.

When an update adds a setting with a default, existing Settings pages display that default automatically—no reinstall is needed. Opening the page does not write content or replace saved values or pending edits. Defaults are stored on a normal save; on multi-language sites, edit these store-wide settings in the default language. Required settings without a default still need your input.

See [Panel and diagnostics](panel.md) for the protected Page, permissions, and configuration troubleshooting.

See [Money and currency](money.md) for exact amount syntax and localized formatting.

## Order retention

The Settings tab provides four policy values. All follow the same Page/PHP precedence and individual lock behavior:

| Setting / typed accessor | Default | Meaning |
| --- | --- | --- |
| `cleanupCreationFailures()` | `true` | Allow cleanup of definite creation failures without a Session. |
| `creationFailureRetentionDays()` | `7` | Whole days to keep those attempts after failure. |
| `cleanupUnpaidOrders()` | `true` | Allow cleanup of expired or completed-failed unpaid orders. |
| `unpaidOrderRetentionDays()` | `30` | Whole days to keep those terminal unpaid orders. |

For example, `$site->stripeCheckout()->settings()->unpaidOrderRetentionDays()` returns the effective number. The same names without parentheses are available through `setting()` and `all()`, and under `settings` in PHP configuration. PHP booleans must be actual booleans; day counts must be positive integers. `null` leaves the Page/default value in control. Use the category toggle to disable cleanup, not `0` days.

Shortening a period can make existing records eligible for cleanup. Pending and paid orders are never automatically eligible. **Automatic cleanup is not running yet**; these settings currently define the tested eligibility policy.

Request-load controls are separate, PHP-only values:

```php
'programmatordev.stripe-checkout' => [
    'housekeeping' => [
        'intervalHours' => 24,
        'batchSize' => 25,
    ],
],
```

`intervalHours` is a positive integer; `batchSize` is an integer from 1 to 100. Both are shown read-only in Diagnostics, not exposed as merchant Settings or editable Page fields. They do not start a scheduler or cleanup process.

## Built-in cart

The session cart is enabled by default. Set the PHP-only `cart.enabled` option to `false` to disable it; `$site->stripeCheckout()->cart()` then returns `null` without opening a session and its routes are not registered. The optional PHP-only `cart.renderer` closure enables HTML fragments on those same routes. See [Cart](cart.md) for PHP usage and [Cart HTTP routes](cart-http.md) for requests and rendering.

## Order numbers

The optional PHP-only `orders.numberFormatter` closure changes the visible order number without changing the native UUID. See [Orders](orders.md) for its input and a configuration example. It is not a Panel setting.

## Session request customization

Checkout Session values that vary per order belong in the `programmatordev.stripe-checkout.session.parameters` Kirby filter rather than global configuration. The filter receives the complete standard request and immutable checkout context. See [Checkout Session requests](session-requests.md) for its contract, protected values, and retry behavior.
