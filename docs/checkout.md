# Checkout bootstrap and JSON submission

`checkout()` prepares private form or JSON data for one browser checkout action. It creates no Order or Stripe resource and does not resolve products, prices or shipping quotes.

Bootstrap and JSON submission are available now. Hosted form redirects, pending/error pages and browser return routes are still being implemented; this guide does not describe a complete production checkout integration.

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
| `submissionData()` | String fields to retain together for this action |
| `publishableKey()` | Embedded publishable key; `null` for hosted mode |
| `toArray()` | `uiMode`, `source`, `actionUrl`, `submissionData`, plus embedded-only `publishableKey` |

JSON serialization returns the same data as `toArray()`.

Submission fields are `csrf`, `source`, `attemptToken`, and, for Cart, `revision`. Direct does not include a revision. Retain the issued fields together; do not fetch a fresh revision to submit an older form. For JSON submission, the CSRF value belongs in `X-CSRF`, with the remaining submission fields in the body. Escape values when rendering them into HTML.

The action URL targets the plugin's fixed, language-aware POST endpoint. Route registration itself opens no session and performs no Stripe operation.

## JSON submission

Submit JSON to the issued `actionUrl`, request an `application/json` response, and retain the native browser session cookie. Put `csrf` in the `X-CSRF` header and the remaining issued fields in the body. Cart selections and shipping country come from the saved cart; do not add replacement `items` or `shippingCountry` fields to Cart submissions.

Direct submissions additionally require an `items` list. Each item accepts `reference`, optional integer `quantity` (default `1`) and optional `options` object using the same option/value identifiers as Cart. Physical Direct purchases need a supported `shippingCountry`. Review and select that country before submitting. Unknown fields, prices, destination URLs and other protected commerce values are rejected.

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

The example shows submission, not a complete storefront or error interface. When `ok` is false, display the safe `error.message` and handle `error.code` as described below. Embedded success supplies `data.clientSecret` for Stripe.js instead of a redirect URL. The bootstrap's publishable key is the browser key; never send the server key to the browser. A complete embedded/return example will accompany the remaining browser-flow implementation.

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
| `405` / `406` | Unsupported method, request media type or representation. |
| `409` | Stale or closed action, changed purchase/configuration, unavailable product, shipping input that needs review, or expired uncertain retry. |
| `422` | Product, shipping or customized Session request configuration needs attention. |
| `503` | Invalid Settings, unavailable storage or a temporary product/Session dependency. |
| `502` | Definitive Stripe rejection or unverifiable Session facts. |
| `202` | Session creation is uncertain. Preserve this same action and original submission. |
| `500` | Unexpected local failure. Contact the store before trying again. |

An uncertain mutation receives `checkout.session_uncertain`. Offer an explicit retry of the **same** original submission only when `error.retryable` is true. Do not issue a new bootstrap, change the purchase or infer failure/payment from `202`. The existing saved request, idempotency key and retry deadline govern retries; repeating a request never extends that deadline. `checkout.attempt_retry_expired` retains the unresolved Order and requires store guidance.

A temporarily unavailable duplicate presentation read receives `503` with `checkout.session_unavailable`, rather than `202`; it does not create another Session. A correlated Session that Stripe has already completed or expired receives `409` even if its webhook has not updated the local Order yet. This read does not change payment state.

Known errors expose no raw callback/provider details. All endpoint responses are private/no-store and use `Referrer-Policy: no-referrer`, with no permissive CORS. Treat authorized URLs and client secrets as ephemeral private values; do not persist them in content, session state, analytics or logs.

This implementation step supports JSON requests and responses. HTML responses and hosted form submissions remain unavailable (`406`) until the pending/error presentation step is implemented. The accepted hosted form design will provide `303` redirects and explicit same-action retry controls.

## Browser ownership and lifetime

Bootstrap uses ordinary Kirby sessions and Kirby's CSRF token. It does not replace or extend the configured native session lifetime or timeout. Preserve the browser's session cookie when submitting.

Each call issues a new action. Keep the same issued action for duplicate submissions or retries. A deliberate new checkout uses a new bootstrap. The attempt token is not authorization by itself: its native browser state must match the actor, source, UI mode, language and, for Cart, saved identity/revision. A login, logout or changed context makes the old action unusable.

Switching language allows a new bootstrap in that language. The language check applies to reusing the same action: its retries retain the original language and navigation context.

The initiating URL comes from the current same-site GET request, with the internal result parameter and fragment removed. Other methods or an unsuitable URL use the localized site URL. Request-body and Referer destinations are not used.

Native state retains at most 100 actions per browser. Issuing another action retires the oldest unused form when needed; accepted purchases are preserved. If all retained actions have been accepted, issuance throws `CheckoutInputException` with `CheckoutErrorCode::ATTEMPT_LIMIT_REACHED`.

Unused actions expire after 24 hours. First purchase binding retains its browser state for 24 hours from that acceptance; duplicate binding never renews it. The native session may expire sooner. This browser retention does not change the existing creator's Session expiration or uncertain-retry deadline, and pruning browser state does not delete an Order.

The native state contains browser context and the first canonical purchase fingerprint, with token hashes for lookup. It stores no purchase snapshot, raw request, provider credentials, hosted checkout URL or client secret. Direct purchase binding occurs when its accepted selection has been resolved, rather than while rendering a bootstrap.

**Bootstrap data is private request data.** Do not put it into shared/static HTML, cached fragments, analytics, logs or long-lived JavaScript bundles. Configure pages that render a bootstrap to bypass shared caching; the PHP API cannot add response headers on behalf of a project template.
