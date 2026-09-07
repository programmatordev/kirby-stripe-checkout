# Orders

Orders are stored as native Kirby draft Pages under the protected `stripe-checkout-orders` container, which is initialized automatically. The package includes guarded internal creation and updates, developer queries, and an extendable order blueprint. Checkout does not yet create orders automatically; Checkout Sessions, payment synchronization, lifecycle dispatch and cleanup are not implemented yet.

## Query orders

```php
/** @var Kirby\Cms\Site $site */
$checkout = $site->stripeCheckout();
$orders = $checkout->orders(); // Kirby Pages collection
$paid = $orders->filterBy('paymentStatus', 'paid');
$order = $checkout->order('page://abc123'); // Kirby Page or null

if ($user = kirby()->user()) {
    $myOrders = $checkout->ordersFor($user);
    $myOrder = $checkout->orderFor($user, 'page://abc123');
}
```

Singular lookups accept the full Page UUID, not a slug, page path, order number or email. A missing, malformed, invalid or differently owned order returns `null`. User queries compare the stored native `user://` reference; they never match guest orders by email. Kirby UUIDs must be enabled.

These are trusted server-side queries, not authorization for a public endpoint. On a customer account page, authenticate the visitor and use `ordersFor()`/`orderFor()` with that user. Do not expose site-wide results or every order field as public JSON. The order Pages themselves cannot render as frontend pages.

Reads do not contact Stripe, open a cart session, create missing content, or run cleanup. Invalid children are excluded; broken container storage raises `Order\Exception\OrderQueryException` instead of silently returning an empty store. Diagnostics reports storage problems without customer data.

## Extend the order editor

Add `site/blueprints/pages/stripe-checkout-order.yml`:

```yaml
extends: programmatordev/stripe-checkout/pages/order

tabs:
  project:
    label: Internal notes
    fields:
      internalNote:
        label: Note
        type: textarea
```

You can also replace the blueprint. This changes presentation, not protection: UUID, order number, states, totals, provider references, timestamps and snapshots remain plugin-owned. Changing the slug, template, status, title or parent, duplicating an order, and ordinary deletion are blocked, including for admins. Keep the plugin's Page models registered.

Custom fields use normal Kirby updates, validation and `page.update:before`/`page.update:after` hooks:

Here, custom fields means developer-added order fields such as internal notes—not Stripe Checkout's collected custom fields, which remain in the protected `customFields` snapshot.

```php
if ($order !== null) {
    $order->update(['internalNote' => 'Contact the customer before dispatch.']);
}
```

Panel roles require `orders.read` to view orders, and both `orders.read` and `orders.update` to edit custom fields. Both permissions are under `programmatordev.stripe-checkout` and disabled by default for non-admin roles. Canonical facts are stored only in the default language; custom fields can use Kirby translations. Payment changes are not ordinary custom-field edits and do not trigger generic Page update hooks.

## Order number and initial custom fields

The default visible number is `ORD-` plus the uppercase native Kirby UUID ID. The full ID is retained. An optional PHP formatter receives the full Page reference:

```php
'programmatordev.stripe-checkout' => [
    'orders' => [
        'numberFormatter' => fn (string $uuid): string =>
            'WEB-' . strtoupper((new Kirby\Uuid\Uri($uuid))->host()),
    ],
],
```

The returned label must be non-empty and at most 80 characters; it is never silently truncated. Formatting does not change the UUID or provide sequential numbering.

The initial custom-field filter runs before an order is created, outside internal impersonation:

```php
'hooks' => [
    'programmatordev.stripe-checkout.order.fields' => function (
        array $fields,
        ProgrammatorDev\StripeCheckout\Order\OrderCreationContext $context,
    ): array {
        $fields['salesChannel'] = 'website';
        return $fields;
    },
],
```

Return the complete custom field map. Reserved names or invalid values stop creation; this filter cannot override canonical order facts. Hook argument names matter because Kirby supplies them by name. These extension points are wired into internal order creation, but there is no public manual-order creation API yet.

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

Snapshots can contain customer information and custom fields. Treat them as private order data, not general-purpose log payloads.

## Internal content format

The serializer uses Kirby's text/YAML handlers. It keeps canonical fields separate from custom fields, rejects reserved-name collisions and unsupported nested facts, and hashes normalized values without adding hash/revision fields to order metadata.

Known zero totals are explicit; final totals are absent before completion rather than invented as zero. Initial product amounts are checked against quantities and currency. Final provider totals are not forced through a local tax equation.

Recorded timestamps must agree with the order state and fall between creation and the last update. The Checkout expiration deadline may be in the future. Earlier observations, such as uncertainty before a Session opens, are retained; contradictory completion and expiration facts are rejected.

Writes reload and validate the current record before persisting through Kirby's Page/content APIs. Failed or corrupt records are never replaced with empty orders. Custom fields survive canonical updates.

The site needs writable content and `site/storage/stripe-checkout/order-locks`. Empty per-order lock files coordinate concurrent read/update/write operations, with a bounded two-second wait (`persistence.busy` on contention). These are not cache files: do not clear them while writers are running. They contain no order data. Multi-server installations must share this directory and content storage on a filesystem that supports reliable file locking.
