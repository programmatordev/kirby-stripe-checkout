# Kirby Stripe Checkout

[![Latest Version](https://img.shields.io/github/release/programmatordev/kirby-stripe-checkout.svg?style=flat-square)](https://github.com/programmatordev/kirby-stripe-checkout/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Tests](https://github.com/programmatordev/kirby-stripe-checkout/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/programmatordev/kirby-stripe-checkout/actions/workflows/ci.yml?query=branch%3Amain)

Stripe Checkout integration for [Kirby CMS](https://getkirby.com).

> [!CAUTION]
> The plugin is under active development and is not ready for production use. The current package provides configuration, the native Settings Page, and local Panel diagnostics only; Checkout, products, carts, orders, and webhooks are not implemented yet.

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

For the future hosted Checkout flow, the minimum credential configuration is the server key and webhook secret. Add them to the project's `site/config/config.php` using the nested form:

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

Stripe credentials are optional until a later operation needs them. Embedded Checkout will additionally require the publishable key:

```php
'publishableKey' => getenv('KIRBY_STRIPE_CHECKOUT_PUBLISHABLE_KEY') ?: null,
```

Keep secret and webhook keys in environment or deployment configuration and never commit them. Kirby 5 automatically loads `site/config/env.php` after the regular and host-specific config files, so an uncommitted deployment file can override only the credentials:

```php
<?php

return [
    'programmatordev.stripe-checkout.stripe.secretKey' => getenv('KIRBY_STRIPE_CHECKOUT_SECRET_KEY') ?: null,
    'programmatordev.stripe-checkout.stripe.webhookSecret' => getenv('KIRBY_STRIPE_CHECKOUT_WEBHOOK_SECRET') ?: null,
];
```

The general Settings API and diagnostics never return credential values or fragments.

### Panel settings

Composer installation automatically adds a **Stripe Checkout** area to Kirby's default Panel menu. On the first Kirby boot after installation, the plugin creates its protected `stripe-checkout-settings` draft Page. Open **Settings** to edit it without leaving the area's Overview, Settings, and Diagnostics tabs; no separate setup action is required. The view extends Kirby's native Page editor behavior and uses its fields, validation, versions, permissions, and save flow. Composer itself cannot create the Page because it runs without an initialized Kirby site.

The Settings schema and layout are owned by the plugin so new capabilities can be added with predictable validation, locks, translations, and documentation. Projects cannot replace the `stripe-checkout-settings` blueprint. Keep unrelated project settings in the Site blueprint or another project-owned Page.

If the fixed identifier already belongs to unrelated content or Kirby cannot create the Page, the plugin leaves existing content unchanged. The Settings and Diagnostics views report the problem without preventing unrelated site requests.

Admins receive all plugin permissions. Custom roles opt in explicitly:

```yaml
permissions:
  access:
    stripe-checkout: true
  programmatordev.stripe-checkout:
    settings.read: true
    settings.update: true
    diagnostics.read: true
```

The area stays registered even when it is omitted from a custom `panel.menu`. Use Kirby's normal menu option to reorder, rename, hide, or link directly to one of its stable views:

```php
'panel.menu' => [
    'site',
    'stripe-checkout' => [
        'label' => 'Store',
        'link' => 'stripe-checkout/settings',
    ],
    'users',
],
```

The available paths are `stripe-checkout`, `stripe-checkout/settings`, and `stripe-checkout/diagnostics`. Menu visibility does not replace the area and plugin permission checks.

### Store settings

`settings.priceSource` accepts `kirby` (the default) or `stripe`. An explicit PHP value is treated as locked deployment configuration. Fully dotted Kirby option keys are also accepted, but defining the same logical option in nested and dotted forms is an error.

```php
'programmatordev.stripe-checkout.settings.priceSource' => 'stripe',
```

When PHP locks a setting, the Panel keeps the field visible, shows the effective value, and explains its configuration path. Any previously stored Page value is preserved and becomes active again if the PHP value is removed. The same lock is enforced on the server.

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

Only safe, store-facing settings are available through this API. Credentials and structural configuration are absent rather than redacted. The fixed `stripe-checkout-settings` record is a native draft Kirby Page with a plugin-owned schema: the custom area edits it through Kirby's Page behavior, while its protected model prevents frontend rendering and structural changes. The plugin initializes it automatically on the first Kirby boot. Page values override internal defaults; explicit PHP values remain authoritative and lock only their corresponding Page fields.

## Diagnostics

The Panel diagnostics view checks the PHP, Kirby, and Stripe SDK versions; configuration validity; credential presence and detectable test/live mode; and Settings Page ownership. These checks are local and never make a Stripe API request.

Configuration errors expose a stable code and safe option path. The initial codes are:

- `configuration.root_invalid`, `configuration.option_duplicate`, `configuration.option_unknown`, `configuration.type_invalid`, `configuration.value_invalid`, and `configuration.combination_invalid` for malformed options;
- `configuration.required_missing`, `configuration.setting_locked`, `configuration.credential_missing`, `configuration.credential_mode_mismatch`, and `configuration.translation_invalid` for operation or setup failures;
- `persistence.model_mismatch`, `persistence.owner_mismatch`, `persistence.schema_unsupported`, `persistence.content_invalid`, `persistence.write_failed`, and `persistence.verify_failed` for the protected Settings Page.

Fix the reported path in PHP configuration or, for Page ownership errors, move the unrelated Page away from the fixed `stripe-checkout-settings` ID before running setup again. Diagnostics never include the rejected value for a sensitive path.

## Translations

The plugin ships complete English and Portuguese Panel catalogues. English is the deterministic fallback. Override a known key or add a partial locale with suffix-only keys:

```php
'programmatordev.stripe-checkout' => [
    'translations' => [
        'pt_PT' => [
            'settings.locked' => 'Configurado em PHP.',
        ],
    ],
],
```

Unknown suffixes and blank values are rejected so translation typos appear in diagnostics. These translations cover plugin-owned Kirby UI; Stripe Checkout localizes its own hosted or embedded payment interface separately.

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
