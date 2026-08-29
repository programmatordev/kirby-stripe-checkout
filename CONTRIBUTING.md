# Contributing

This repository is both the Composer plugin package and a small Kirby 5 development site. The root `index.php` is the canonical plugin bootstrap. The file under `site/plugins/kirby-stripe-checkout/` loads that bootstrap; it is not a second copy of the plugin.

The committed site content is deterministic development data. It provides neutral product and navigation fixtures for features as they are implemented; it does not imply that the corresponding plugin behavior already exists.

## Requirements

- Git
- DDEV and its Docker provider
- Stripe CLI on the operating system only for explicit Stripe-assisted checks

Stripe CLI is not a project dependency and is not needed for the default test suite.

## DDEV setup

From a fresh clone:

```bash
ddev start
ddev composer install
ddev npm ci
```

Run `ddev describe` to see the local URL. Open that URL for the fixture storefront and append `/panel` for the Kirby Panel. A clean clone has no Panel account; Kirby will guide you through creating the first local account. Account files are ignored.

Never commit Stripe credentials, customer information, local accounts, sessions, caches, or generated store data.

## Checks

Run the standard checks through DDEV:

```bash
ddev composer validate --strict
ddev composer audit --locked
ddev composer analyse
ddev composer format:check
ddev php vendor/bin/phpunit
ddev npm test
ddev npm run build
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

Tests cover plugin behavior and public contracts. `tests/Support` contains only the minimum infrastructure needed to exercise those contracts; support utilities are not treated as product behavior and are not tested independently. Unit tests do not boot Kirby. Plugin integration tests construct a fresh application with unique disposable content, site, account, cache, media, and session roots for every test. Test support must not be added to runtime code or the Composer package artifact.

The default suite is deterministic and offline. Its Stripe HTTP client rejects unexpected requests instead of contacting Stripe. Use explicit fakes and sanitized fixtures for Stripe behavior; Stripe CLI checks remain separate and opt-in.

CI keeps the committed lockfile matrix for PHP 8.2 through PHP 8.5 and adds two uncached dependency-range checks: Composer's lowest currently allowed resolution on PHP 8.2 and the latest allowed resolution on PHP 8.5. Composer's security-advisory blocking remains enabled. The package job also installs the built artifact as a mirrored Composer package in a disposable Kirby project and verifies Kirby discovers it from `site/plugins`. The files under `tests/Package` exist only for that package-install check and are not shipped with the plugin. Kirby's explicit plugin version is asserted once by the registration integration test; release validation compares it with the release tag.

PHPStan analyzes at its maximum rule level. Do not add baselines for new production or test code. PHP-CS-Fixer checks the canonical bootstrap, runtime configuration and source, and tests.

Panel source lives under `panel-src/` and is built with the locked local kirbyup dependency. Commit `index.js` and `index.css` whenever Panel source changes. CI reruns the JavaScript tests and build, then fails if those compiled assets are stale; source, Node dependencies, and package-manager metadata are excluded from the Composer artifact.

Coverage measures the production PHP that currently exists. The project does not target 100% coverage; correctness-critical payment scenarios remain mandatory regardless of the percentage.

To expose order dependencies while changing shared test infrastructure, run:

```bash
ddev php vendor/bin/phpunit --order-by=random
```

## Development data

Commit only deterministic fixture content. The development site is multilingual, so default-language fixtures use Kirby's `.en.txt` naming and translated overlays use their language code. Local runtime data belongs in the ignored paths defined by `.gitignore`.

Templates and snippets must add PHPDoc types for Kirby-provided or passed variables they actually use. Do not declare unused scope variables.

## Composer package artifact

Composer is the only supported installation method for plugin consumers. The archive below is an internal QA representation of the Composer package artifact; it is not a supported manual ZIP installation method.

To inspect it from a committed revision:

```bash
git archive --worktree-attributes --format=tar.gz --output=/tmp/kirby-stripe-checkout.tar.gz HEAD
tar -tzf /tmp/kirby-stripe-checkout.tar.gz
```

The package artifact must contain the canonical plugin bootstrap, runtime configuration and source, implemented blueprints and translations, Composer manifest, root README, task-oriented consumer guides under `docs/`, and license. The compiled root `index.js` and `index.css` belong in the artifact because the variant matrix needs behavior that Kirby's native fields do not provide. Its Panel source and Node build dependencies remain development-only. The artifact must not contain DDEV configuration, development guidance, fixture content/site files, tests, plans, local environment files, or either dependency lockfile.
