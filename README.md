# Kirby Stripe Checkout

[![Latest Version](https://img.shields.io/github/release/programmatordev/kirby-stripe-checkout.svg?style=flat-square)](https://github.com/programmatordev/kirby-stripe-checkout/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Tests](https://github.com/programmatordev/kirby-stripe-checkout/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/programmatordev/kirby-stripe-checkout/actions/workflows/ci.yml?query=branch%3Amain)

Stripe Checkout integration for [Kirby CMS](https://getkirby.com).

> [!CAUTION]
> The plugin is under active development and is not ready for production use. The current package provides the strict configuration and native Settings Page foundations only; Checkout, products, carts, orders, webhooks, and the custom Panel area are not implemented yet.

## Requirements

- PHP 8.2 or later
- Kirby 5.5.3 or a later compatible Kirby 5 release
- Composer

## Installation

Composer is the supported installation method:

```bash
composer require programmatordev/kirby-stripe-checkout
```

Kirby discovers the Composer-installed plugin automatically. No manual plugin registration is needed.

## Configuration

Add plugin options to the project's `site/config/config.php`. The nested form is the recommended format:

```php
<?php

return [
    'programmatordev.stripe-checkout' => [
        'stripe' => [
            'secretKey' => getenv('KIRBY_STRIPE_CHECKOUT_SECRET_KEY') ?: null,
            'publishableKey' => getenv('KIRBY_STRIPE_CHECKOUT_PUBLISHABLE_KEY') ?: null,
            'webhookSecret' => getenv('KIRBY_STRIPE_CHECKOUT_WEBHOOK_SECRET') ?: null,
        ],
        'settings' => [
            'priceSource' => 'kirby',
        ],
    ],
];
```

Stripe credentials are optional until a later operation needs them. Keep secret and webhook keys in environment or deployment configuration and never commit them. `publishableKey` is intended for the later embedded Checkout integration; the general Settings API does not expose any credential.

`settings.priceSource` accepts `kirby` (the default) or `stripe`. An explicit PHP value is treated as locked deployment configuration. Fully dotted Kirby option keys are also accepted, but defining the same logical option in nested and dotted forms is an error.

Unknown options, wrong types, unsupported values, duplicate definitions, blank credentials, and recognizable test/live key mismatches are rejected when plugin configuration is used. Invalid plugin configuration does not prevent unrelated Kirby pages from booting.

## Reading settings

The Site entry point provides the sanitized effective settings:

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

Only safe, store-facing settings are available through this API. Credentials and structural configuration are absent rather than redacted. The fixed `stripe-checkout-settings` record is a native draft Kirby Page: Kirby's Panel can edit it, while its protected model prevents frontend rendering and structural changes. Reading settings or booting Kirby never creates the Page. When it exists, Page values override internal defaults; explicit PHP values remain authoritative and lock only their corresponding Page fields.

The setup action and custom Panel area that initialize and open this Page are not implemented yet. Projects should not create or manipulate the internal record directly during this intermediate development stage.

## Development

The repository contains both the Composer plugin package and a small Kirby development site. Development uses DDEV:

```bash
ddev start
ddev composer install
ddev composer check
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the complete development and testing workflow.

## License

[MIT](LICENSE)
