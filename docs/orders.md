# Orders

Orders are stored as native Kirby draft Pages under the protected `stripe-checkout-orders` container, which is initialized automatically. The package includes guarded internal creation and updates, developer queries, lifecycle hooks, and an extendable order blueprint. The internal Checkout pipeline creates an order before its Stripe Session and can reconcile current payment results. The public browser Checkout flow, signed webhook endpoint, and automatic cleanup are not implemented yet.

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

The generated UUID ID is also the Order Page slug. Kirby's default short format and UUID-v4 format work directly. A custom UUID generator must return an ID that Kirby's Page-slug normalization leaves unchanged. Checkout validates every generated ID before exposing its attempt token. Diagnostics checks the bundled formats without creating content; when a custom generator is active, it reports that the concrete output will be validated when Checkout starts instead of invoking project code merely by opening Diagnostics.

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

Unsaved Panel edits keep the latest protected order values. If the order state changes while someone is editing a note, saving the note preserves the newer state. Read-only values in Kirby's editing version are not treated as requested changes; explicit attempts to change protected fields through `$order->update()` are rejected. Kirby's normal editor locks still apply.

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
- `checkoutSource()` and `cartRevision()` — cart or direct purchase and the initiating cart revision, if applicable.
- `userUuid()` and `languageCode()` — the initiating user and language, when present.
- `uiMode()` — the `UiMode` used for the attempt.
- `currency()` — the store currency.
- `subtotal()` — an exact Brick Money value.
- `requiresShipping()` — whether at least one initiating line requires shipping.
- `lineItems()` — the frozen initiating product, option, quantity and price facts.

Each line retains its name, selected `options`, SKU, images and shipping requirement. `price` and `subtotal` are exact decimal strings; `currency` is uppercase. Stripe-backed lines also retain their Price reference and any supplied Product reference. The protected `providerAmounts` map retains Stripe's exact integer units. These are not always the same as a currency's ISO minor units.

Kirby-priced lines also freeze their effective nullable `taxCode` ID. This initiating classification describes what was submitted, not the tax later calculated by Stripe.

The initiating snapshot contains no live product Page, File, cart, credentials or raw attempt token. Reading it does not re-fetch product information. Customer-facing text keeps the language used when the purchase began. A single-language site uses `null` for `languageCode`; a locale is not duplicated alongside it.

## Authoritative Checkout details

The protected order schema accepts normalized customer, address, Checkout custom-field, consent and discount results. These values come from the current Stripe Checkout Session—not from current Settings or from the request that originally asked Stripe to collect them. This matters when a Session filter changes collection for one order, Settings change later, or Stripe returns a value through another Checkout feature.

The stored shapes are:

- `customer`: nullable `email`, `individualName`, `businessName` and `phone`, plus ordered `taxIds` containing `type` and nullable `value`.
- `billingAddress` and `shippingAddress`: separate nullable values with `name`, `line1`, `line2`, `postalCode`, `city`, `state` and uppercase two-letter `country`.
- `customFields`: ordered fields with stable `key`, `type`, presented `label`, `required`, `configured`, `answered` and nullable string `value`. An unanswered optional field remains in the list with `answered: false`; a returned empty string is an answer.
- `consent`: nullable `termsOfService` and `promotions` provider outcomes. Missing results stay `null` and are not interpreted as refusal.
- `discounts`: ordered applied discounts with known Discount, Coupon and Promotion Code references; customer-facing names/codes; exact amount and currency; and safe product/minimum/first-transaction restrictions when Stripe returns them.
- `tax`: nullable returned calculation with `automaticTaxEnabled`, nullable `calculationStatus` and `provider`, currency, exact `amount` and integer `providerAmount`. Its nullable `breakdown` preserves ordered allocations with an `order`, `line_item` or `shipping` target and optional target ID, taxable amount, tax amount, inclusive flag, rate percentages, jurisdiction, tax type and taxability reason when returned.
- `shipping`: nullable selected Shipping Rate details with the plugin option key and quote fingerprint, customer-facing label, currency, exact subtotal/tax/total pairs, nullable delivery estimate, tax behavior and Tax Code. `stripeShippingRateId` keeps the selected `shr_...` reference separately, while `shippingTotal` keeps the authoritative order-level shipping total.

Tax facts do not determine payment state. A completed zero-tax calculation is different from disabled Automatic Tax: reasons such as `not_collecting`, exemption or reverse charge are retained. Manual tax can also have a positive amount while `automaticTaxEnabled` is false. Missing totals stay `null`; an unexpanded breakdown stays `null`, not an invented empty list. Order-level and line/shipping allocations overlap, so do not add all targets together. Internal reconciliation retrieves every line-item page; an incomplete collection is rejected rather than stored as complete.

When both are present, the tax snapshot amount must agree with `taxTotal` in the order currency. The plugin checks returned amounts and allocations, but never derives tax from a percentage or forces inclusive/exclusive totals through its own tax equation.

A selected shipping snapshot must come from an expanded Stripe Shipping Rate. A scalar Rate ID identifies the object but cannot freeze its label, amount, estimate or tax classification. Reconciliation therefore needs to retrieve or expand that object before normalization. The selected Rate ID and immutable snapshot are stored together. Digital Checkout results retain an explicit zero `shippingTotal` without inventing either one. The selected Rate's fixed amount must agree with Stripe's returned shipping subtotal, and the snapshot total must agree with `shippingTotal`; when Stripe includes shipping-tax allocations, their sum must also agree with its shipping-tax total. The plugin does not derive the final total locally because Stripe may apply tax and discounts.

`stripeCustomerId` remains a separate protected provider reference. Each discount stores both its decimal `amount` and exact Stripe `providerAmount`; the sum must equal `discountTotal` in the order currency. A completed order has explicit `customFields` and `discounts` lists, including empty lists when nothing was collected or applied.

Internal reconciliation retrieves the current Session, complete line items, and required payment and shipping expansions. It commits the resulting payment and capability facts together. It uses the saved purchase and Session request, not today's products, cart, or storefront Settings. Failed or contradictory reads do not partially update the order. There is no public reconciliation endpoint or signed webhook route yet.

Only selected facts cross the stripe-php boundary. The order never stores a complete Session or PaymentIntent, Stripe SDK object, payment credentials, root PaymentIntent client secret, Checkout redirect URL or raw webhook response. Customer, address, tax-ID and custom-field values are private order data. The active payment-action branch can also be retained temporarily as described below; its URLs, codes and authentication directives are private.

## Payment facts and actions

The protected `payment` snapshot retains common payment facts: status, exact amounts, method type, PaymentIntent/Charge/PaymentMethod identifiers, provider statuses, safe failure code, and provider timestamps. A free Checkout can have no PaymentIntent; missing amounts remain unknown rather than being invented as zero. A successful payment is not assumed to have been captured immediately.

Hooks read these frozen facts through `$lifecycleEvent->payment()`. This does not contact Stripe or read the current Order Page:

```php
use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEvent;

'hooks' => [
    'programmatordev.stripe-checkout.payment.requiresAction' => function (
        Kirby\Cms\Page $order,
        LifecycleEvent $lifecycleEvent,
    ): void {
        $payment = $lifecycleEvent->payment();
        $nextAction = $payment?->nextAction();

        if ($nextAction === null) {
            return;
        }

        $type = $nextAction->type();
        $details = $nextAction->details(); // Fresh StripeObject; original provider field names.
        $paymentIntent = $nextAction->toPaymentIntent(); // Optional partial SDK projection for IDE types.
        // Choose how your store presents these private facts; deduplicate effects by deliveryId().
    },
],
```

`Payment` exposes `status()`, `amount()`, `amountReceived()`, `amountCaptured()`, provider identifiers/statuses, `methodType()`, `failureCode()`, timestamps, and `nextAction()`. Amount methods return Brick Money or `null`. `PaymentAction` preserves Stripe's action `type` and the complete corresponding branch, without a payment-method allowlist or renamed fields. `details()` and `toPaymentIntent()` return independent mutable SDK objects, so changing them cannot alter the saved snapshot. The partial PaymentIntent contains only the retained `next_action`, not a complete or current PaymentIntent; never use it for Stripe writes.

An action is not necessarily a set of customer payment instructions. It may describe a voucher, a QR code, an authentication step, an SDK directive or a future Stripe capability. Your store decides what to do with its type and provider details; the plugin does not classify payment methods or promise universal instruction fields. Do not expose raw action data in public order summaries, logs or analytics.

A pending payment can be recorded before its action arrives, and an action can arrive before Checkout completes. Use `payment.requiresAction` to handle action details, not `payment.pending`. The action hook runs after its delivery snapshot is saved while payment is non-terminal; its frozen payment status may still be `unpaid`. A later pending transition has no action attached and does not repeat the action notification. Identical action evidence does not notify again, and late actions after terminal payment are ignored.

Action data exists only in the `requiresAction` lifecycle payload, outside its canonical `orderSnapshot`. `$lifecycleEvent->payment()?->nextAction()` attaches that historical evidence to the frozen common payment facts. The order's `payment` field never contains an action, observation watermark or action-specific expiry. `Payment::toArray()` serializes common payment facts only; use `LifecycleEvent::toArray()` when queuing the complete hook payload.

The delivery's fixed replay deadline uses the PHP-only `housekeeping.lifecycleDeliveryPayloadRetentionDays`, defaulting to 30 days. Retries never renew it. It is not Stripe's own action expiry: historical codes or URLs may already be unusable. Every saved delivery payload, including successful deliveries, stays until its fixed retention deadline, defaulting to 30 days from local delivery creation. The internal single-order cleanup operation can remove expired payloads regardless of delivery status. Successful deliveries remain non-retryable during retention. It removes the frozen order snapshot and private action together; automatic cleanup is not running yet. Sanitized delivery metadata, including an action fingerprint for duplicate suppression, is separate from the private payload.

Current Stripe reads determine payment state. A correlated `payment_intent.requires_action` Event may additionally supply a historical action that a later read can no longer recover. This capture path is implemented at the trusted service boundary; signed HTTP delivery is not wired yet.

## Lifecycle hooks

Register normal Kirby hooks in `site/config/config.php`. The internal order creator emits `programmatordev.stripe-checkout.order.created` after verifying the saved order:

```php
'hooks' => [
    'programmatordev.stripe-checkout.order.created' => function (
        Kirby\Cms\Page $order,
        ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEvent $lifecycleEvent,
    ): void {
        $number = $order->orderNumber()->value();
        $deliveryId = $lifecycleEvent->deliveryId();
        // Use these in your own integration; deduplicate effects by delivery ID.
    },
],
```

Keep the argument names `order` and `lifecycleEvent`: Kirby supplies them by name. Use a normal closure, not a `static` closure, because Kirby binds its application to hooks.

`order` is freshly read before delivery; `lifecycleEvent` keeps the original event-time facts. Hooks run after the write, outside the order lock and internal impersonation. The initiating content language is active during the hook, then the caller's language is restored—even if a listener throws. If that language was removed from Kirby, its normal default-language fallback applies.

Failed listeners do not undo the order or its payment state. A protected `lifecycleDeliveries` field records pending, delivered or failed status, attempt count, safe error code, delivery identity, the retained event payload and a fixed local expiry deadline. After cleanup, sanitized identity/outcome metadata and action fingerprints remain, with an immutable pruning timestamp; the payload cannot be restored or retried. Diagnostics show pending/failed counts. A retry keeps the same delivery ID and snapshot, but receives the current Page; it is refused at or after the deadline, even before physical cleanup runs. The internal retry primitive exists; there is no Panel retry action or automatic retry runner yet.

Recording a hook attempt or result does not advance the order's `updatedAt` or invalidate the storefront page cache. Business-state changes still invalidate that cache.

A failing native `page.create:after` hook does not make a verified, saved order appear uncreated. Failures before or during the native write still fail creation. Native hooks are not tracked for retries; use the plugin lifecycle hooks for effects that need that record.

The creation event includes native blueprint defaults and custom-field changes from `page.create:before`. Later edits, including those from `page.create:after`, appear on the live Page but do not rewrite the creation snapshot.

Kirby stops calling listeners when one throws. Retrying the whole hook can therefore call listeners that already succeeded. Make external effects idempotent using `deliveryId`, or enqueue `toArray()` into your own durable queue. A process can stop between an external effect and saving its outcome; exactly-once delivery is not promised.

The controlled, single-order deletion primitive emits `programmatordev.stripe-checkout.order.deleted` with Kirby's final in-memory Page after deletion. A failed deletion hook **cannot be retried**: only the last sanitized outcome is retained, not the deleted customer's snapshot. Durable deletion integrations must enqueue successfully during the first invocation. No public deletion route or automatic cleanup runner is available yet.

The Session-creation pipeline emits `programmatordev.stripe-checkout.session.created` after the Session ID is committed. Internal reconciliation can also repair an association missed locally without creating another Session. It emits new `payment.pending`, `payment.succeeded`, `payment.failed`, `payment.requiresAction`, and `checkout.expired` transitions after persistence. Duplicate or unchanged observations do not dispatch them again. Refund and dispute provider flows are not implemented yet.

The protected `events` ledger records correlated Stripe Event identity, type, resource, provider creation time, attempts and sanitized processing outcome. It is separate from `lifecycleDeliveries`: an Event can be successfully processed while an optional hook delivery fails. Successfully processed duplicates need no new Stripe read. A failed provider read remains retryable according to its error classification; a storage failure cannot mark the canonical processing successful. Current-state reconciliation without an Event creates no invented Event entry or trigger identity.

### Event values

`Lifecycle\LifecycleEvent` describes a committed change. Its enum type, delivery ID, time, revision, order identity and states are separate from its immutable `orderSnapshot()`.

`toArray()` produces plain JSON-safe data: enum cases become their string values, timestamps use UTC, and snapshots contain no PHP objects. A trigger type and ID can identify provider evidence; both are `null` when there is no provider event. The value does not itself dispatch hooks or perform retries.

`revision()` identifies the event-bearing commit within the order's delivery ledger. Multiple events from the same commit share it; retries and custom-field edits do not increment it. The snapshot excludes both bookkeeping ledgers, avoiding nested copies of earlier snapshots and unrelated provider attempts.

Snapshots can contain customer information and custom fields. Treat them as private order data, not general-purpose log payloads.

## Retention policy

The Settings tab contains cleanup preferences with defaults of **7 days for definitely failed creation attempts** and **30 days for terminal unpaid orders**. Both categories are enabled by default. These preferences prepare the policy; automatic cleanup is not running yet. See [retention configuration](configuration.md#order-retention).

Only definitely failed creation without a Session, expired Checkout, or completed Checkout with a failed payment can become eligible. Completed failures are aged from the later of completion and payment failure. Still-creating, uncertain, open, pending, paid and no-payment-required orders are never eligible merely because they are old. Shortening a retention period can make existing records eligible.

The internal deletion operation reloads and rechecks eligibility under the existing per-order write lock. Ordinary Page deletion remains forbidden, including for administrators. This is not a new public manual-order API.

## Internal content format

The serializer uses Kirby's text/YAML handlers. It keeps canonical fields separate from custom fields, rejects reserved-name collisions and unsupported nested facts, and hashes normalized values without adding hash/revision fields to order metadata.

Known zero totals are explicit; final totals are absent before completion rather than invented as zero. Initial product amounts are checked against quantities and currency. Final provider totals are not forced through a local tax equation.

Recorded timestamps must agree with the order state and fall between creation and the last update. The Checkout expiration deadline may be in the future. Earlier observations, such as uncertainty before a Session opens, are retained; contradictory completion and expiration facts are rejected.

Before the Stripe request, the order stores the exact normalized Session request and its fingerprint, a separate actor/source/context binding fingerprint, the UUID-derived idempotency key, an opaque credential fingerprint, Stripe test/live mode, pinned Stripe API version, destinations, and a 23-hour retry deadline. Only the attempt-token hash is stored. The token itself contains the future Kirby order UUID plus an independent random nonce: its UUID locates the Order Page directly, while its nonce keeps the public UUID from being the complete retry token. The credential itself cannot be recovered from the fingerprint; the value prevents an uncertain request from being repeated with another Stripe credential, even when both credentials use the same mode. Rotating the credential during an unresolved attempt therefore requires reconciliation instead of automatic replay.

Once an attempt is stored, reuse checks its token and actor/source/context binding before contacting Stripe. It uses the saved request and destinations rather than rerunning shipping resolution, order-number formatting or Session-parameter hooks. A concurrent submission is checked again under the creation lock so only one order and customized request are persisted.

Stripe's redirect URL and embedded client secret are returned for the current request and are never written to order content. Failure category and retry permission are stored separately: an outcome can remain uncertain while automatic replay is forbidden. Stripe's `Stripe-Should-Retry` response header decides retry permission when present. Without that header, connection failures and retryable HTTP statuses use the plugin's conservative exact-request fallback. A definite rejection marks creation as failed; an indeterminate result is repeated only when its saved retry decision permits it and the deadline has not passed.

Writes reload and validate the current record before persisting through Kirby's Page/content APIs. Failed or corrupt records are never replaced with empty orders. Custom fields survive canonical updates. Successful canonical changes also clear Kirby's page cache so cached output does not retain the old state; no-op updates leave the cache intact.

The site needs writable content and `site/storage/stripe-checkout`. Empty files in its `order-locks` subdirectory coordinate concurrent order writes and the short attempt-token lookup/create boundary, with a bounded two-second wait (`persistence.busy` on contention). No lock is held while contacting Stripe. These are not cache files: do not clear them while writers are running. They contain no order data.

The structured token reserves the future native Page UUID before submission, and the Order slug uses that UUID ID. A retry locates the Page directly and must still match the complete persisted token hash and actor/source/context binding. Attempt identity is therefore stored with the retained Order Page.

The separate `lifecycle-last-deletion.json` keeps only a delivery ID, timestamp, status and safe error code for the last deleted-order notification. Multi-server installations must share this directory and content storage on a filesystem that supports reliable file locking.
