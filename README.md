# Kirby Stripe Checkout

[![Latest Version](https://img.shields.io/github/release/programmatordev/kirby-stripe-checkout.svg?style=flat-square)](https://github.com/programmatordev/kirby-stripe-checkout/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Tests](https://github.com/programmatordev/kirby-stripe-checkout/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/programmatordev/kirby-stripe-checkout/actions/workflows/ci.yml?query=branch%3Amain)

Stripe Checkout integration for [Kirby CMS](https://getkirby.com).

> [!CAUTION]
> The plugin is under active development and is not ready for production use. The current package registers the plugin foundation only; Checkout, configuration, products, carts, orders, webhooks, and Panel tools are not implemented yet.

## Requirements

- PHP 8.2 or later
- Kirby 5.5.3 or a later compatible Kirby 5 release
- Composer

## Installation

Composer is the supported installation method:

```bash
composer require programmatordev/kirby-stripe-checkout
```

The package currently verifies that Kirby discovers the plugin automatically. Configuration and commerce usage will be documented as those features become available.

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
