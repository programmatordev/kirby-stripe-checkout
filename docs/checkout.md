# Checkout bootstrap and submission

`checkout()` prepares private submission data for one browser checkout action. It creates no Order or Stripe resource and does not resolve products, prices or shipping quotes.

Bootstrap, hosted form submission, JSON submission, hosted success/cancellation, embedded return, embedded Session resume and deferred Cart clearing after webhook-only completion are available now.

```php
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;

$checkoutBootstrap = $site->stripeCheckout()->checkout();

// For a purchase supplied directly rather than through the cart:
$directCheckoutBootstrap = $site->stripeCheckout()->checkout(CheckoutSource::Direct);
```

The default source is `CheckoutSource::Cart`. Cart bootstrap requires an enabled cart; Direct works with the cart disabled. Bootstrap reads the current saved cart identity and revision without resolving its selections, including when the cart is empty. Purchase validation belongs to submission.

Configure the store currency, Stripe secret key and webhook signing secret before issuing a bootstrap. Embedded mode additionally requires a publishable key. Missing configuration throws `ConfigurationException` before opening a browser session. Disabled Cart bootstrap throws `CheckoutInputException` with `CheckoutErrorCode::CART_DISABLED`.

## Returned data

`CheckoutBootstrap` is immutable and exposes:

| Method | Value |
| --- | --- |
| `uiMode()` | Configured `UiMode` enum |
| `checkoutSource()` | Requested `CheckoutSource` enum |
| `actionUrl()` | Absolute, language-aware `/stripe-checkout/checkout` POST URL |
| `submissionData()` | Form/JSON fields to retain together for this action |
| `publishableKey()` | Embedded publishable key; `null` for hosted mode |
| `error()` | Safe `CheckoutError` after a hosted form failure; otherwise `null` |
| `toArray()` | `uiMode`, `source`, `actionUrl`, `submissionData`, embedded-only `publishableKey`, and an error only when present |

JSON serialization returns the same data as `toArray()`.

Submission fields are `csrf`, `source`, `attemptToken`, and, for Cart, `revision`. Direct does not include a revision. Retain the issued fields together; do not fetch a fresh revision to submit an older form. For JSON submission, the CSRF value belongs in `X-CSRF`, with the remaining submission fields in the body. Escape values when rendering them into HTML.

Calling `checkout()` in multiple tabs can issue different action tokens for the same unchanged Cart. The first accepted submission claims that Cart UUID and revision for the current browser actor, language and Checkout UI mode. Later submissions for that exact Checkout context reuse the same Order and Stripe Checkout Session instead of creating competing purchases. A Cart change or language change starts an independent Checkout context and may create another Order and Session; the plugin does not expire an earlier open Session because the customer may still be using it. An expired Session or a definitive Session-creation failure allows a replacement Order for the same context.

After a hosted form failure that must reuse an existing attempt, Direct submission data additionally contains the exact normalized `items` and optional `shippingCountry` to render unchanged. Ordinary validation and definitive pre-attempt failures receive a fresh action instead.

The action URL targets the plugin's fixed, language-aware POST endpoint. Route registration itself opens no session and performs no Stripe operation.

## Hosted form submission

A normal form posts to `actionUrl()`. Successful creation responds with `303` directly to the validated Stripe Checkout URL. A safely attributable failure responds with `303` back to the initiating page; call `checkout()` there and display its safe error.

For a Cart checkout, render the issued submission data directly. The optional `stripe-checkout/submission-fields` snippet renders only the hidden fields; the project retains the form, button, error presentation and JavaScript:

```php
<?php
$checkout = $site->stripeCheckout()->checkout();
?>

<?php if ($error = $checkout->error()): ?>
    <p><?= esc($error->message()) ?></p>
<?php endif ?>

<form action="<?= esc($checkout->actionUrl(), 'attr') ?>" method="post">
    <?php snippet('stripe-checkout/submission-fields', [
        'checkoutBootstrap' => $checkout,
    ]) ?>

    <button type="submit">Checkout</button>
</form>
```

The snippet is a convenience, not a checkout dependency. A project can render the Cart fields itself:

```php
<form action="<?= esc($checkout->actionUrl(), 'attr') ?>" method="post">
    <?php foreach ($checkout->submissionData() as $name => $value): ?>
        <input
            type="hidden"
            name="<?= esc($name, 'attr') ?>"
            value="<?= esc($value, 'attr') ?>"
        >
    <?php endforeach ?>

    <button type="submit">Checkout</button>
</form>
```

That direct loop is sufficient for Cart submission data, whose values are scalar. Direct forms also submit nested `items[index][reference]`, optional `items[index][quantity]`, optional `items[index][options][optionId]`, and optional `shippingCountry`. The optional snippet renders those nested names correctly. A custom Direct renderer must recursively preserve the same bracketed names and values. When returned `submissionData()` contains `items`, render those values unchanged: the plugin retained them because starting a fresh action could duplicate or abandon an existing Session attempt.

`CheckoutError` exposes `code()`, translated `message()` and `retryable()`. The initiating template owns presentation; the plugin registers no error/pending page, template, snippet or configurable error destination. Failures that cannot be safely tied to an issued browser action, including invalid CSRF or malformed action identity, remain direct HTTP errors and do not trust a request or Referer destination.

## Browser returns

Hosted success and embedded return navigation pass through fixed, language-aware plugin routes. Those routes retrieve the current Checkout Session, run the same guarded reconciliation used by webhooks, commit the authoritative Order state and then redirect to the destination saved when the Session was created. Browser navigation itself is never payment proof.

A complete Session redirects to the saved `successDestination`, including when an asynchronous payment remains pending. An embedded open or expired Session redirects to `returnDestination`. When the same browser still owns the attempt and the destination is on the Kirby site, its template can consume the result:

```php
<?php
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutResultReason;

$stripeCheckout = $site->stripeCheckout();
$checkoutResult = $stripeCheckout->checkoutResult();
?>

<?php if ($checkoutResult?->reason() === CheckoutResultReason::HostedSuccess): ?>
    <!-- Inspect checkoutStatus() and paymentStatus() before choosing the message. -->
<?php elseif ($checkoutResult?->reason() === CheckoutResultReason::EmbeddedReturn): ?>
    <!-- Present the current embedded Checkout result. -->
<?php endif ?>

<?php if ($nextAction = $checkoutResult?->payment()?->nextAction()): ?>
    <!-- Present the provider action that fits your store and payment methods. -->
<?php endif ?>
```

`reason()` describes the route used, while `checkoutStatus()` and `paymentStatus()` expose the reconciled facts. `payment()` returns the current typed payment facts when the Order has them. A complete Checkout may still have `PaymentStatus::Pending`; fulfillment must follow authoritative payment state and lifecycle hooks. A temporary provider read or correlation failure can produce a result whose `isAvailable()` is false and whose Order, payment and status methods return `null`.

The meaningful status combinations are:

| Checkout status | Payment status | Meaning |
| --- | --- | --- |
| `creating`, `creation_uncertain` or `creation_failed` | `unpaid` | The plugin has not confirmed an open Stripe Checkout Session. |
| `open` | `unpaid` | The Checkout Session can potentially still be completed. |
| `expired` | `unpaid` | The Checkout Session expired before completion. |
| `complete` | `pending` | Checkout completed, but an asynchronous payment is still processing. |
| `complete` | `paid` | Stripe confirmed payment. |
| `complete` | `no_payment_required` | Checkout completed with no payment to collect. |
| `complete` | `failed` | Checkout completed, but the associated payment definitively failed. |

`CheckoutStatus::Complete` therefore describes completion of the Checkout flow, not proof that funds have settled. Use `paymentStatus()` when deciding what to present or fulfill, and keep webhooks authoritative for later payment changes.

When the return-time provider read includes `next_action`, the one-time result attaches it to `payment()` for the destination template. The action keeps Stripe's original type and active detail branch; inspect `type()` before using `details()`. A pending payment does not necessarily require customer action, so `nextAction()` can be `null`. The plugin discards the result action if Checkout expires, or if a later webhook or reconciliation makes the payment terminal or replaces its PaymentIntent before the template consumes it.

This action copy follows the result's same-browser, same-destination, one-use and 15-minute limits. It is private provider data and can expire sooner at Stripe. Do not expose its complete details in public logs or analytics, and do not treat it as payment proof. The permanent Order keeps only canonical payment facts; longer-lived server-side handling uses the `payment.requiresAction` lifecycle event described in [Orders](orders.md#payment-facts-and-actions).

Every browser result reference is bound to its native session and exact destination, expires after 15 minutes and is consumed once. Repeated `$site->stripeCheckout()` calls share one facade during the active Kirby App lifecycle, so templates and snippets can read the same memoized result after its session reference is consumed.

An authoritative complete return clears only the unchanged Cart from the initiating browser. A changed Cart, Direct checkout, missing browser binding, open/expired Session or unavailable read is preserved. Reconciliation still runs without browser authority, but that request cannot expose an Order result or clear browser state. External destinations receive no result reference; a matching local Cart may still be cleared after authoritative completion.

If a webhook completes Checkout and the browser never follows the return URL, the next Cart read, Cart mutation or Cart Checkout bootstrap for the same browser and actor checks the retained accepted attempt against the local Order. It clears only the exact initiating Cart while its UUID and revision remain unchanged, without calling Stripe. A changed Cart, Direct checkout, incomplete Order, missing accepted attempt, changed actor or another browser is preserved.

Embedded resume is intended for a customer who leaves Embedded Checkout for an external payment step and returns without completing Checkout. For example, a bank authorization may be cancelled or fail while the Stripe Checkout Session remains open. The return destination can then mount that same Session again so the customer can retry or choose another payment method without creating another Order or Session.

After a correlated same-browser return, `canResumeCheckout()` is `true` only while that original Session is open and unexpired. Use it to decide whether the return page should mount Embedded Checkout again:

```php
<?php
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;

$stripeCheckout = $site->stripeCheckout();
$checkoutResult = $stripeCheckout->checkoutResult();

if ($checkoutResult?->canResumeCheckout()) {
    $checkoutBootstrap = $stripeCheckout->checkout(CheckoutSource::Cart);

    // Use the normal embedded JSON integration with this bootstrap.
}
```

Submit the returned bootstrap through the ordinary embedded JSON flow. The endpoint recognizes the accepted action, retrieves the existing Session and returns its current `data.clientSecret`; the frontend uses that secret to mount Embedded Checkout as usual. There is no separate resume endpoint or frontend submission format.

Direct checkout reuses the accepted canonical items and shipping country; it never reconstructs them from current storefront content. Cart checkout requires the same Cart identity and revision. A completed return should present completion, and an expired return should present expiration or an explicit new-checkout action. Do not automatically submit a fresh bootstrap for either result. An ordinary Checkout page without a return result calls `checkout()` normally to issue a new action.

If the Cart changed before a resumable return is rendered, `checkout()` throws `CheckoutInputException` with `CheckoutErrorCode::ATTEMPT_CONFLICT`. Present a separate action that starts again from the current Cart; the plugin does not silently replace the resume with a fresh Order or Session.

## Hosted cancellation

Stripe's hosted back action returns through the fixed plugin cancel route and redirects to the saved `cancelDestination`. When that destination is on the Kirby site and the same browser still owns the attempt, its template can consume the result:

```php
<?php
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutResultReason;

$stripeCheckout = $site->stripeCheckout();
$checkoutResult = $stripeCheckout->checkoutResult();
?>

<?php if ($checkoutResult?->reason() === CheckoutResultReason::HostedCancel): ?>
    <!-- Render the project's cancellation message or action here. -->
<?php endif ?>
```

An available cancellation result exposes the protected Order Page through `order()` and its current local `checkoutStatus()` and `paymentStatus()`. It can expose existing canonical facts through `payment()`, but never a return-time payment action. The navigation reason does not claim Checkout completion or payment success. `canResumeCheckout()` is always false for hosted cancellation.

Cancellation performs no Stripe read or mutation, does not change the Order, and does not clear the Cart. The result reference is native-session-bound, expires after 15 minutes and is consumed once. Keep the same `$stripeCheckout` object when reading it more than once in one template; that object memoizes the consumed result. External cancellation destinations receive no result reference because they cannot read the Kirby session API.

## JavaScript submission

Request encoding and response negotiation are independent. JavaScript can submit the native form fields as `application/x-www-form-urlencoded` while requesting an `application/json` response. This lets the browser and PHP preserve nested Direct field names without reconstructing them in JavaScript:

```js
const response = await fetch(form.action, {
  method: "POST",
  headers: { Accept: "application/json" },
  body: new URLSearchParams(new FormData(form))
});
```

The form's `csrf` field remains in that encoded body. A client may instead submit JSON to the issued `actionUrl`; retain the native browser session cookie, put `csrf` in the `X-CSRF` header and send the remaining issued fields in the body. Cart selections and shipping country come from the saved cart; do not add replacement `items` or `shippingCountry` fields to Cart submissions.

Direct submissions additionally require an `items` list. Each item accepts `reference`, optional integer `quantity` (default `1`) and optional `options` object using the same option/value identifiers as Cart. Physical Direct purchases need a supported `shippingCountry`. Review and select that country before submitting. Unknown fields, prices, destination URLs and other protected commerce values are rejected.

The repository development store demonstrates Direct Checkout from product pages in both UI modes. Hosted mode keeps JavaScript and native-form controls side by side; embedded mode submits the selected product before mounting Stripe. These are test fixtures rather than a required storefront or theme integration.

For Kirby-priced products with selected options, Checkout identifies the chosen variant in the customer-visible Product name, for example `Poster (Printed / Portuguese)`. The values use the frozen localized Order snapshot and follow the configured option order. Products backed by an existing Stripe Price keep their Stripe Product presentation; give those Products distinct customer-facing names in Stripe when their variants need to be distinguishable in Checkout.

For example, serialize `$checkoutBootstrap` into private, escaped page data and use that value as `checkoutBootstrap`:

```js
const { csrf, ...submissionData } = checkoutBootstrap.submissionData;
const body = checkoutBootstrap.source === "direct"
  ? {
      ...submissionData,
      items: [{ reference: "page://your-product-uuid", quantity: 2 }],
      shippingCountry: "PT"
    }
  : submissionData;

const response = await fetch(checkoutBootstrap.actionUrl, {
  method: "POST",
  credentials: "same-origin",
  headers: {
    "Accept": "application/json",
    "Content-Type": "application/json",
    "X-CSRF": csrf
  },
  body: JSON.stringify(body)
});
const result = await response.json();

if (result.ok && result.data.uiMode === "hosted") {
  window.location.assign(result.data.redirectUrl);
}
```

The example shows submission, not a complete storefront or error interface. When `ok` is false, display the safe `error.message` and handle `error.code` as described below. Embedded success supplies `data.clientSecret` for Stripe.js instead of a redirect URL. The bootstrap's publishable key is the browser key; never send the server key to the browser. An eligible embedded return uses this same submission contract to retrieve the existing Session's current client secret.

Successful JSON responses contain only:

```json
{"ok":true,"data":{"uiMode":"hosted","redirectUrl":"https://checkout.stripe.com/..."}}
```

or:

```json
{"ok":true,"data":{"uiMode":"embedded","clientSecret":"..."}}
```

A newly created Session returns `201`. A duplicate or successful recovery of the same saved attempt returns `200`; it creates no additional Order and does not run request customization again. Presentation is available only to the initiating browser with matching actor, language, cart and purchase context. Responses exclude Order identity and saved provider request data.

Failures contain `ok: false` and `error.code`, translated `error.message` and independent `error.retryable`. Never treat an error response as a payment result.

| HTTP status | Meaning |
| --- | --- |
| `400` | Malformed body, unknown field or invalid selection. Correct the input. |
| `403` | Invalid CSRF or Origin/Referer context. Refresh the store page. |
| `405` / `406` | Unsupported method or requested response representation. |
| `415` | Unsupported request media type. Send JSON with `application/json`. |
| `409` | Stale or closed action, changed purchase/configuration, unavailable product, shipping input that needs review, or expired uncertain retry. |
| `422` | Product, shipping, order number or customized Session request configuration needs attention. |
| `503` | Invalid Settings, unavailable storage or a temporary product/Session dependency. |
| `502` | Definitive Stripe rejection or unverifiable Session facts. |
| `202` | Session creation is uncertain. Preserve this same action and original submission. |
| `500` | Unexpected local failure. Contact the store before trying again. |

An uncertain JSON mutation receives `checkout.session_uncertain`. Offer an explicit retry of the **same** original submission only when `error.retryable` is true. Hosted forms receive the same error through `CheckoutBootstrap::error()` and retain the exact action/input when creator state requires it. Do not issue another action yourself, change the purchase or infer failure/payment from the error. The existing saved request, idempotency key and retry deadline govern retries; repeating a request never extends that deadline. `checkout.attempt_retry_expired` retains the unresolved Order and requires store guidance.

A temporarily unavailable duplicate presentation read receives `503` with `checkout.session_unavailable`, rather than `202`; it does not create another Session. A correlated Session that Stripe has already completed or expired receives `409` even if its webhook has not updated the local Order yet. This read does not change payment state.

Known errors expose no raw callback/provider details. All endpoint responses are private/no-store and use `Referrer-Policy: no-referrer`, with no permissive CORS. Treat authorized URLs and client secrets as ephemeral private values; do not persist them in content, session state, analytics or logs.

Hosted forms are supported only in hosted Checkout mode. Embedded Checkout remains JSON-only and rejects form/HTML submission with `406`. Both transports use the same safe error mapping; there are no separate plugin pending/error pages.

## Browser ownership and lifetime

Bootstrap uses ordinary Kirby sessions and Kirby's CSRF token. It does not replace or extend the configured native session lifetime or timeout. Preserve the browser's session cookie when submitting.

Each call issues a new action. Keep the same issued action for duplicate submissions or retries. A deliberate new checkout uses a new bootstrap. The attempt token is not authorization by itself: its native browser state must match the actor, source, UI mode, language and, for Cart, saved identity/revision. A login, logout or changed context makes the old action unusable.

Switching language allows a new bootstrap and independent Checkout in that language while preserving the Cart and its revision. Reusing an existing action retains its original language and navigation context.

The initiating URL comes from the current same-site GET request, with internal result parameters and the fragment removed. Other methods or an unsuitable URL use the localized site URL. Request-body and Referer destinations are not used.

Hosted form failures use a random opaque reference in the redirect URL to retrieve bounded native-session state. Results from separate tabs cannot replace one another. The reference has no authority outside its browser session and exposes no Order identity, provider response, credentials, Checkout URL or client secret. Refreshing the initiating page performs no Stripe call.

Native state retains at most 100 actions per browser. Issuing another action retires the oldest unused form when needed; accepted purchases are preserved. If all retained actions have been accepted, issuance throws `CheckoutInputException` with `CheckoutErrorCode::ATTEMPT_LIMIT_REACHED`.

Unused actions expire after 24 hours. First purchase binding retains its browser state for 24 hours from that acceptance; duplicate binding never renews it. The native session may expire sooner. This browser retention does not change the existing creator's Session expiration or uncertain-retry deadline, and pruning browser state does not delete an Order.

The native attempt state contains browser context and the first canonical purchase fingerprint, with token hashes for lookup. After an embedded or Cart action is accepted, it also retains the exact normalized submission, including the opaque action token, for Session resume or Cart winner recovery. Form-result state contains the safe error and its browser/page binding, and retains normalized product/shipping input only when an existing action must be submitted unchanged. Neither store contains a raw request, provider response, credentials, hosted checkout URL or client secret. Direct purchase binding occurs when its accepted selection has been resolved, rather than while rendering a bootstrap.

**Bootstrap data is private request data.** Do not put it into shared/static HTML, cached fragments, analytics, logs or long-lived JavaScript bundles. Configure pages that render a bootstrap to bypass shared caching; the PHP API cannot add response headers on behalf of a project template.
