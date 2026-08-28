# Kirby Stripe Checkout

[![Latest Version](https://img.shields.io/github/release/programmatordev/kirby-stripe-checkout.svg?style=flat-square)](https://github.com/programmatordev/kirby-stripe-checkout/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Tests](https://github.com/programmatordev/kirby-stripe-checkout/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/programmatordev/kirby-stripe-checkout/actions/workflows/ci.yml?query=branch%3Amain)

Stripe Checkout integration for [Kirby CMS](https://getkirby.com).

> [!CAUTION]
> The plugin is under active development and is not ready for production use. The current package provides configuration, the native Stripe Checkout Page, and local Panel diagnostics only; Checkout, products, carts, orders, and webhooks are not implemented yet.

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

## Quick start

Add the future hosted Checkout credentials to `site/config/config.php`:

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

Credentials are optional until an implemented operation requires them. Keep secret values in environment or deployment configuration and never commit them. Composer installation also adds the **Stripe Checkout** Panel area automatically.

See [Configuration](docs/configuration.md) for environment-specific credentials, store settings, PHP locks, and `$site->stripeCheckout()->settings()`.

## Documentation

- [Documentation overview](docs/index.md)
- [Configuration](docs/configuration.md)
- [Panel and diagnostics](docs/panel.md)
- [Translations](docs/translations.md)

Guides for products, carts, Checkout, orders, webhooks, and extension points will be added alongside those implementations. They are not available in the current package.

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
