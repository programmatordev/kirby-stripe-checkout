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
- `Tax\TaxErrorCode` for product tax classification.

These namespaces are relative to `ProgrammatorDev\StripeCheckout`. Other domain classes follow the same `*ErrorCode` naming pattern.

An error can be mapped to a safer code at a public boundary. For example, unavailable Tax Code catalogue data is `tax.catalogue_unavailable` during product resolution, but Cart presents it as `CartErrorCode::PROVIDER_UNAVAILABLE`. Compare the code returned by the API you are using.

Translation keys remain literal strings; use the existing keys when [overriding messages](translations.md). Stripe-owned codes and provider statuses also remain strings. Constants list the plugin's own codes, not every error Stripe or custom integration code can return.
