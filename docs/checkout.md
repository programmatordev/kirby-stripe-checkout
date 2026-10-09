# Checkout bootstrap

`checkout()` prepares private form or JSON data for one browser checkout action. It creates no Order or Stripe resource and does not resolve products, prices or shipping quotes.

The bootstrap is available now. The submission endpoint, pending/error pages and browser return flow are still being implemented; this guide does not describe a complete production checkout integration.

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

The action URL is reserved for the upcoming submission implementation; generating a bootstrap does not yet register that endpoint.

## Browser ownership and lifetime

Bootstrap uses ordinary Kirby sessions and Kirby's CSRF token. It does not replace or extend the configured native session lifetime or timeout. Preserve the browser's session cookie when submitting.

Each call issues a new action. Keep the same issued action for duplicate submissions or retries. A deliberate new checkout uses a new bootstrap. The attempt token is not authorization by itself: its native browser state must match the actor, source, UI mode, language and, for Cart, saved identity/revision. A login, logout or changed context makes the old action unusable.

Switching language allows a new bootstrap in that language. The language check applies to reusing the same action: its retries retain the original language and navigation context.

The initiating URL comes from the current same-site GET request, with the internal result parameter and fragment removed. Other methods or an unsuitable URL use the localized site URL. Request-body and Referer destinations are not used.

Native state retains at most 100 actions per browser. Issuing another action retires the oldest unused form when needed; accepted purchases are preserved. If all retained actions have been accepted, issuance throws `CheckoutInputException` with `CheckoutErrorCode::ATTEMPT_LIMIT_REACHED`.

Unused actions expire after 24 hours. First purchase binding retains its browser state for 24 hours from that acceptance; duplicate binding never renews it. The native session may expire sooner. This browser retention does not change the existing creator's Session expiration or uncertain-retry deadline, and pruning browser state does not delete an Order.

The native state contains browser context and the first canonical purchase fingerprint, with token hashes for lookup. It stores no purchase snapshot, raw request, provider credentials, hosted checkout URL or client secret. Direct purchase binding occurs when its accepted selection has been resolved, rather than while rendering a bootstrap.

**Bootstrap data is private request data.** Do not put it into shared/static HTML, cached fragments, analytics, logs or long-lived JavaScript bundles. Configure pages that render a bootstrap to bypass shared caching; the PHP API cannot add response headers on behalf of a project template.
