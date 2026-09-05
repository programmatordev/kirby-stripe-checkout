# Project Guidance

## Product principles

- Design the plugin from the Kirby developer's point of view. Prefer an intuitive, discoverable API and the fewest reasonable setup steps.
- Automate deterministic setup and integration work when it remains transparent and debuggable. Do not hide important behavior behind surprising conventions.
- Be opinionated about the common path while providing focused extension points for legitimate store-specific requirements.
- Keep the core suitable for different product and order models. Do not encode assumptions that only fit one storefront.
- Prefer simple, current solutions over abstractions created only for hypothetical future needs.

## Kirby conventions

- Use Kirby-native features, storage, models, permissions, extension registries, and content APIs before introducing another dependency or persistence system.
- Before adding infrastructure around a Kirby concern, inspect the supported Kirby version's public APIs and implementation. Add plugin-owned code only for a concrete, reproducible gap, document that gap, and keep the solution narrower than the Kirby behavior it complements. Prefer removing plugin code when Kirby already provides the required guarantee.
- Composer is the supported installation path. The root plugin bootstrap is canonical; the development-site loader must load it rather than duplicate it.
- Follow Kirby's documented Panel design system. Compose custom interfaces from Kirby components, patterns, states, tokens, and accessibility behavior whenever possible.
- Make Panel configuration understandable to non-developers. When PHP configuration overrides a Panel value, show that the value is locked and explain its source.
- Support multi-language Kirby sites deliberately. Persist stable identifiers independently from translated labels, and retain the language needed for customer-facing flows.
- Load bundled Panel translations from translation files and allow documented configuration overrides.

## Stripe and commerce boundaries

- Let Stripe own payment data and payment-method behavior. Never collect or persist card or equivalent sensitive payment credentials.
- Delegate capabilities such as payment-method configuration and tax calculation to Stripe when Stripe is authoritative for them; keep Kirby as the default source for store content and business configuration.
- Treat synchronous and asynchronous payments as normal flows. Webhooks are authoritative for payment state; browser returns are a user-experience signal, not proof of payment.
- Preserve useful Stripe identifiers and event context for reconciliation and debugging without coupling the public API to one payment method.
- Use money value objects and integer minor units at system boundaries. Do not use binary floating-point values for persisted or calculated amounts.
- Validate sensitive or mutually exclusive Stripe parameters clearly. Allow advanced customization through documented escape hatches without weakening safe defaults.

## Architecture and code

- Read the surrounding implementation and public contracts before changing them. Keep each change small, cohesive, and independently testable.
- Prefer explicit domain names and typed objects over ambiguous arrays. Public APIs should be predictable from their names and return types.
- Keep runtime code independent from development-site fixtures and test support.
- Do not add a new abstraction, adapter, dependency, or configuration option without a concrete use case.
- Add concise class documentation when a class's responsibility is not immediately clear. Add inline comments only for non-obvious constraints, decisions, or edge cases.
- In templates and snippets, add PHPDoc types for implicit Kirby or passed variables only when those variables are used.
- Use the project's PHP-CS-Fixer and PHPStan configuration as the source of truth for formatting and static analysis.
- Add translatable labels and messages instead of hard-coded user-facing Panel text.

## Naming conventions

- Let namespaces provide domain context instead of repeating every namespace segment in class names. Use a more explicit name when a class is imported into a broader context and would otherwise be ambiguous.
- Name classes by their responsibility rather than their current implementation technology. Name external implementations after the stable integration boundary, such as an API, rather than the client library used internally.
- Name plugin-owned interfaces with the `Interface` suffix, including their filenames.
- Use precise role names such as `Resolver`, `Factory`, `Catalogue`, `Record`, `Reference`, and `Formatter` consistently.
- Use `Reference` for an unresolved identifier, `Record` for untrusted provider data, and `Resolved...` for a validated domain value.
- Keep mapped Page values as ordinary Kirby fields. Name plugin-owned Field converters into typed product-domain values with the singular `toProduct*()` prefix, such as `toProductOptions()` and `toProductStripePrice()`; do not apply this prefix to unrelated conversions.
- Avoid vague names such as `Manager`, `Service`, `Helper`, and `Handler` when a more specific responsibility can be named.
- Keep related PHP classes, Panel components, tests, factory methods, and documentation terminology aligned when a concept is renamed.

## Testing

- Run project tooling through DDEV by default. Stripe CLI is an operating-system development tool, not a project dependency.
- Test plugin behavior rather than test infrastructure or implementation details.
- Use unit tests for isolated domain behavior and integration tests in a real, disposable Kirby application for Kirby contracts.
- Keep the default suite deterministic and offline. Stripe interactions must use explicit fakes or sanitized fixtures unless an explicit Stripe-assisted check is being run.
- Cover correctness-critical payment, order, webhook, and idempotency behavior even when a coverage percentage would not require it. Coverage is a diagnostic metric, not a goal of 100 percent.
- Keep package-install tests representative of the Composer consumer experience.

## Documentation

- Write user documentation alongside the behavior it describes. Use simple terms, practical examples, and clearly distinguish required setup from optional customization.
- Keep repository documentation accurate for the current code; do not describe planned behavior as already available.
