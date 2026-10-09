# Error codes

Plugin errors have stable string codes. Use a code to decide what to do, rather than matching the translated message.

PHP constants let you compare these codes without copying their strings:

```php
use ProgrammatorDev\StripeCheckout\Cart\CartErrorCode;
use ProgrammatorDev\StripeCheckout\Cart\Exception\CartException;

try {
    $site->stripeCheckout()->cart()?->add($page->id());
} catch (CartException $error) {
    if ($error->errorCode() === CartErrorCode::PROVIDER_UNAVAILABLE) {
        // Product information is temporarily unavailable; offer another try.
    }
}
```

`CartErrorCode::PROVIDER_UNAVAILABLE` has the value `cart.provider_unavailable`. Constants do not change the returned data: exception `errorCode()` methods, CartError `code()`, JSON responses, and stored error codes still use strings.

## Where to find constants

Each domain owns its codes. Common examples are:

- `Cart\CartErrorCode` for Cart errors.
- `Product\ProductErrorCode` for product resolution.
- `Configuration\ConfigurationErrorCode` for configuration failures.
- `Money\MoneyErrorCode` for amounts, currencies, and formatting.
- `Checkout\CheckoutErrorCode` and `Checkout\SessionRequestErrorCode` for Checkout attempts and request customization.
- `Order\OrderErrorCode` and `Kirby\PersistenceErrorCode` for orders and native content storage.
- `Webhook\WebhookErrorCode` for safe verification and processing log codes; webhook HTTP bodies remain empty.
- `Tax\TaxErrorCode` for product tax classification.
- `Shipping\ShippingErrorCode` for quote resolution and safe unavailable reasons.

These namespaces are relative to `ProgrammatorDev\StripeCheckout`. Other domain classes follow the same `*ErrorCode` naming pattern.

An error can be mapped to a safer code at a public boundary. For example, unavailable Tax Code catalogue data is `tax.catalogue_unavailable` during product resolution, but Cart presents it as `CartErrorCode::PROVIDER_UNAVAILABLE`. Compare the code returned by the API you are using.

Translation keys remain literal strings; use the existing keys when [overriding messages](translations.md). Stripe-owned codes and provider statuses also remain strings. Constants list the plugin's own codes, not every error Stripe or custom integration code can return.

Internal reconciliation uses `CheckoutErrorCode::RECONCILIATION_CONFLICT` when repeated concurrent changes prevent a stable commit. It is retryable: retry the read/commit operation, not Session creation. `SESSION_INCOMPATIBLE` means required provider facts are missing or contradict the saved purchase evidence; the order is not partially updated.

Checkout bootstrap uses `CheckoutErrorCode::CART_DISABLED` when Cart is selected while disabled, and `ATTEMPT_LIMIT_REACHED` when browser capacity is occupied entirely by accepted actions. Missing credentials or currency use the existing configuration codes. These bootstrap failures create no Order or Stripe resource.

Checkout submission uses `RequestErrorCode::ORIGIN_INVALID` for a mismatched Origin or fallback Referer. The registered JSON endpoint maps errors to safe translated messages and HTTP statuses; see [Checkout submission](checkout.md#json-submission). `SESSION_UNCERTAIN` describes an uncertain mutation (`202`); `SESSION_UNAVAILABLE` describes an unavailable provider operation (`503`). Retryability is separate from that classification. Hosted form/HTML presentation remains unfinished.
