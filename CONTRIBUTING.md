# Contributing

This repository is both the Composer plugin package and a small Kirby 5 development site. The root `index.php` is the canonical plugin bootstrap. The file under `site/plugins/kirby-stripe-checkout/` loads that same bootstrap; it is not a second copy of the plugin.

The current development pages are deterministic fixtures. They demonstrate the repository setup but do not yet represent the final 0.7.0 product, cart, Checkout, or order APIs.

## Requirements

- Git
- DDEV and its Docker provider
- Stripe CLI on the operating system only when running explicit Stripe-assisted checks

Stripe CLI is not a project dependency and is not needed for the default test suite.

## DDEV setup

From a fresh clone:

```bash
ddev start
ddev composer install
```

Run `ddev describe` to see the local URL. Open that URL for the fixture storefront and append `/panel` for the Kirby Panel. A clean clone has no Panel account; Kirby will guide you through creating the first local account. Account files are ignored.

The site boots with non-working placeholder Stripe credentials. Real Checkout requests are intentionally unavailable until you configure Stripe test-mode values.

To add local test credentials:

```bash
cp .env.example .ddev/.env
```

Edit `.ddev/.env`, use test-mode values only, and apply them with:

```bash
ddev restart
```

The `.ddev/.env` file is ignored. Never commit Stripe credentials, customer information, local accounts, sessions, caches, or generated orders.

## Checks

Run the standard checks through DDEV:

```bash
ddev composer validate --strict
ddev composer audit --locked
ddev composer analyse
ddev composer format:check
ddev php vendor/bin/phpunit
```

Run every code-quality check with `ddev composer check`. Use `ddev composer format` to apply the configured PHP style before rerunning the checks.

Generate a local coverage report only when reviewing test completeness:

```bash
ddev xdebug on
ddev composer coverage
ddev xdebug off
```

The command prints a summary and writes the ignored `coverage.xml` Clover report. Coverage is intentionally separate from `composer check` so normal development remains fast. Turn Xdebug off afterwards even if the coverage command fails.

The default PHPUnit command runs both test suites. Run one layer while developing with:

```bash
ddev php vendor/bin/phpunit --testsuite "Plugin Unit"
ddev php vendor/bin/phpunit --testsuite "Plugin Integration"
```

Tests cover plugin behavior and public contracts. `tests/Support` contains only the minimum infrastructure needed to exercise those contracts; support utilities are not treated as product behavior and are not tested independently. Unit tests do not boot Kirby. Plugin integration tests construct a fresh application with unique disposable content, site, account, cache, media, and session roots for every test. Test support must not be added to runtime `src/` or the Composer package artifact.

The default suite is deterministic and offline. Its Stripe HTTP client rejects unexpected requests instead of contacting Stripe. Use explicit fakes and sanitized fixtures for Stripe behavior; Stripe CLI checks remain separate and opt-in.

PHPStan analyzes at its maximum rule level. `phpstan-baseline.neon` contains only findings from the pre-rework production implementation and rejects stale entries. Do not add new or test-code findings to it. Remove the matching baseline entries whenever legacy code is replaced or deliberately retained.

PHP-CS-Fixer checks tests and new files under `src/` and `config/`. Its configuration temporarily excludes the specific pre-rework source paths listed in `.php-cs-fixer.dist.php`; remove each exclusion when that code is replaced or deliberately retained. Add the root bootstrap or helper file to the finder when either is reworked.

Coverage measures production PHP under `src/`, `config/`, `index.php`, and `helpers.php`; test support and the development site are excluded. The project does not target 100% coverage. New Phase 6 components will introduce justified thresholds that can only stay level or increase, while correctness-critical payment scenarios remain mandatory regardless of the percentage.

To expose order dependencies while changing shared test infrastructure, run:

```bash
ddev php vendor/bin/phpunit --order-by=random
```

## Development data

Commit only deterministic fixture content. Local runtime data belongs in the ignored paths defined by `.gitignore`.

The shared product catalog contains four fixtures:

- physical product;
- digital product;
- free product;
- option-bearing product.

Templates and snippets must add PHPDoc types for Kirby-provided or passed variables they actually use. Do not declare unused scope variables.

## Composer package artifact

Composer is the only supported installation method for plugin consumers. The archive below is an internal QA representation of the Composer package artifact; it is not a supported manual ZIP installation method.

Development-only files are removed from the package artifact through `.gitattributes`. To inspect it from a committed revision:

```bash
git archive --worktree-attributes --format=tar.gz --output=/tmp/kirby-stripe-checkout.tar.gz HEAD
tar -tzf /tmp/kirby-stripe-checkout.tar.gz
```

The package artifact must contain the root plugin bootstrap, runtime source, Composer manifest, blueprints, translations, license, and consumer documentation. It must not contain DDEV configuration, development content/site files, tests, plans, local environment files, or the lockfile.
