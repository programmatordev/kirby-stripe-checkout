# Order values

The package includes the value types and serialization foundation for orders. It does not yet create or query order Pages, register order hooks, or create Checkout Sessions. There is nothing to configure for these types yet.

## Separate states

An order has several independent states:

| Type | What it describes |
| --- | --- |
| `Order\CheckoutStatus` | Whether Checkout is being created, open, complete, expired, or its creation failed/is uncertain. |
| `Order\PaymentStatus` | Whether payment is unpaid, pending, paid, failed, or no payment is required. |
| `Order\RefundStatus` | The refund summary, independently of the original payment. |
| `Order\DisputeStatus` | The dispute summary, independently of the original payment. |

These string-backed enums live under `ProgrammatorDev\StripeCheckout`. For example, `PaymentStatus::Paid->value` is `paid`; `PaymentStatus::tryFrom('paid')` returns that enum case.

Completing Checkout does not mean a delayed payment has succeeded. A refund also does not erase the fact that the original payment was paid. Provider statuses and error codes remain strings rather than a closed set of enum cases.

## Initiating facts

`Order\OrderCreationContext` is a read-only description of the validated purchase. It exposes:

- `uuid()` — the native identifier stored in Kirby's `uuid` field, without a scheme.
- `pageUuid()` — its full Kirby reference, such as `page://abc123`.
- `orderNumber()` — the display label, not a separate identity.
- `sourceType()` and `cartRevision()` — cart or direct purchase and the initiating cart revision, if applicable.
- `userUuid()` and `languageCode()` — the initiating user and language, when present.
- `uiMode()` and `currency()` — the Checkout mode and store currency.
- `subtotal()` — an exact Brick Money value.
- `lineItems()` — the frozen initiating product, option, quantity and price facts.

Each line retains its name, selected `options`, SKU, images and shipping requirement. `price` and `subtotal` are exact decimal strings; `currency` is uppercase. Stripe-backed lines also retain their Price reference and any supplied Product reference. The protected `providerAmounts` map retains Stripe's exact integer units. These are not always the same as a currency's ISO minor units.

The initiating snapshot contains no live product Page, File, cart, credentials or raw attempt token. Reading it does not re-fetch product information. Customer-facing text keeps the language used when the purchase began. A single-language site uses `null` for `languageCode`; a locale is not duplicated alongside it.

## Lifecycle values

`Lifecycle\LifecycleEvent` describes a committed change. Its enum type, delivery ID, time, revision, order identity and states are separate from its immutable `orderSnapshot()`.

`toArray()` produces plain JSON-safe data: enum cases become their string values, timestamps use UTC, and snapshots contain no PHP objects. A trigger type and ID can identify provider evidence; both are `null` when there is no provider event. The value does not itself dispatch hooks or perform retries.

Snapshots can contain customer information and project fields. Treat them as private order data, not general-purpose log payloads.

## Internal content format

The serializer uses Kirby's text/YAML handlers. It keeps canonical fields separate from project fields, rejects reserved-name collisions and unsupported nested facts, and hashes normalized values without adding hash/revision fields to order metadata.

Known zero totals are explicit; final totals are absent before completion rather than invented as zero. Initial product amounts are checked against quantities and currency. Final provider totals are not forced through a local tax equation.

Recorded timestamps must agree with the order state and fall between creation and the last update. The Checkout expiration deadline may be in the future. Earlier observations, such as uncertainty before a Session opens, are retained; contradictory completion and expiration facts are rejected.

The default display number is `ORD-` followed by the uppercase native Kirby UUID ID. The internal formatter takes that native ID directly; a custom formatter callback receives the full Page reference. Formatting never changes the UUID or adds a sequential counter. Number-format validation and project-field validation are implemented internally; their integration with order creation is not available yet.
