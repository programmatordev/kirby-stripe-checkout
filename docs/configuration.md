# Configuration

This guide covers the configuration behavior available in the current package. Checkout creation is not implemented yet.

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

Keep secret and webhook keys in environment or deployment configuration and never commit them. Kirby 5 automatically loads `site/config/env.php` after regular and host-specific configuration, so an uncommitted deployment file can override only the credentials:

```php
<?php

return [
    'programmatordev.stripe-checkout.stripe.secretKey' => getenv('KIRBY_STRIPE_CHECKOUT_SECRET_KEY') ?: null,
    'programmatordev.stripe-checkout.stripe.webhookSecret' => getenv('KIRBY_STRIPE_CHECKOUT_WEBHOOK_SECRET') ?: null,
];
```

## Store settings

`settings.priceSource` accepts `kirby` (the default) or `stripe`. An explicit PHP value is treated as locked deployment configuration:

```php
'programmatordev.stripe-checkout.settings.priceSource' => 'stripe',
```

Fully dotted Kirby option keys are accepted, but defining the same logical option in nested and dotted forms is an error.

When PHP locks a setting, the Panel keeps the field visible, shows the effective value, and explains its configuration path. A previously stored Page value is preserved and becomes active again if the PHP value is removed. The same lock is enforced on the server.

Unknown options, wrong types, unsupported values, duplicate definitions, blank credentials, and recognizable test/live key mismatches are rejected when plugin configuration is used. Invalid plugin configuration does not prevent unrelated Kirby pages from booting.

## Reading effective settings

The Site entry point returns sanitized effective settings:

```php
<?php

/** @var Kirby\Cms\Site $site */
$settings = $site->stripeCheckout()->settings();

$settings->priceSource(); // PriceSource::Kirby or PriceSource::Stripe

$priceSource = $settings->setting('priceSource');
$priceSource?->value();
$priceSource?->source();
$priceSource?->isLocked();
```

Only safe, store-facing settings are available through this API. Credentials and structural configuration are absent rather than redacted.

Page values override internal defaults. Explicit PHP values remain authoritative and lock only their corresponding Page fields.

See [Panel and diagnostics](panel.md) for the protected Page, permissions, and configuration troubleshooting.
