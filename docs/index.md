# Documentation

Kirby Stripe Checkout is under active development and is not ready for production use. The current package provides its configuration foundation, exact money formatting, Kirby and Stripe Price product resolution, variants, a session-backed PHP cart API and HTTP routes, native Panel area, and local diagnostics. Checkout, orders, and webhooks are not implemented yet.

## Current guides

- [Configuration](configuration.md) — credentials, store settings, PHP locks, and the Settings API.
- [Money and currency](money.md) — exact decimal values, store currency, and formatting helpers.
- [Products and variants](products.md) — reusable fields, existing-schema mapping, options, variants, and product resolution.
- [Cart](cart.md) — adding and changing items in PHP, exact totals, errors, and session behavior.
- [Cart HTTP routes](cart-http.md) — browser requests, CSRF, revisions, JSON and HTML fragments.
- [Panel and diagnostics](panel.md) — automatic setup, permissions, menu composition, and local checks.
- [Translations](translations.md) — bundled languages and project overrides.

The root [README](../README.md) contains the supported requirements, Composer installation, and shortest setup example. Contributor setup and testing are documented separately in the repository's [contribution guide](https://github.com/programmatordev/kirby-stripe-checkout/blob/main/CONTRIBUTING.md).

Additional task-oriented guides will be added only when their corresponding behavior is implemented.
