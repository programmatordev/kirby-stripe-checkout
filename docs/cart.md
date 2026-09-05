# Cart

The built-in cart works with Kirby's normal browser session. It needs no separate storage setup. Configure the store currency and product fields first; see [Configuration](configuration.md) and [Products](products.md).

The PHP API is available now. Built-in cart HTTP routes and Checkout submission are not available yet.

## Add and change items

```php
<?php

/** @var Kirby\Cms\Site $site */
/** @var Kirby\Cms\Page $page */
$cart = $site->stripeCheckout()->cart();

if ($cart !== null) {
    $cart->add($page->id()); // Quantity defaults to 1.
    $cart->add($page->uuid()->toString(), quantity: 2);

    $item = $cart->items()[0];
    $cart->update($item->id(), quantity: 4); // Set an absolute quantity.
    $cart->remove($item->id());
    $cart->clear();
}
```

These are server-side examples, not code to run unconditionally when displaying a page. In a custom controller or route, validate the request and check Kirby's CSRF token before a visitor-triggered mutation. The PHP methods validate commerce input but do not perform HTTP authorization or CSRF checks for you.

`add()` takes a product reference: any string locator supported by Kirby's native Page lookup, or a reference understood by your [custom product resolver](products.md#custom-product-resolver). Published Page references are stored as stable Page UUIDs.

For a product with options, pass stable option/value IDs, not labels or a variant ID:

```php
$cart->add($page->id(), quantity: 1, options: [
    'size-option-id' => 'large-value-id',
    'colour-option-id' => 'blue-value-id',
]);
```

Adding the same product and options again increases the existing quantity. Different options create separate lines. `update()` and `remove()` therefore take a **cart item ID**, not a product reference. Quantities must be positive integers; use `remove()` instead of setting zero. A cart supports up to 100 different lines.

Each successful mutation refreshes the same Cart object and returns it. Existing CartItem objects remain immutable. Call `$site->stripeCheckout()->cart()` again to read changes made elsewhere or to re-resolve product data without changing selections.

## Read the cart

```php
<?php

/** @var Kirby\Cms\Site $site */
$checkout = $site->stripeCheckout();
$cart = $checkout->cart();

if ($cart !== null) {
    foreach ($cart->items() as $item) {
        echo esc($item->product()?->name() ?? 'Unavailable product');
        echo ' × ' . $item->quantity();

        if ($item->subtotal() !== null) {
            echo esc($checkout->formatMoney($item->subtotal()));
        }
    }

    if ($cart->subtotal() !== null) {
        echo esc($checkout->formatMoney($cart->subtotal()));
    }
}
```

- `items()` returns an array of CartItem objects; `item($id)` returns one or `null`.
- `count()` counts lines; `totalQuantity()` adds their quantities.
- `isEmpty()` tells you whether any selections remain.
- `currency()` returns a Brick Money Currency, or `null` when setup is incomplete.
- `$item->price()` returns the price of one unit, resolved from either Kirby or Stripe.
- `$item->image()` returns the first mapped Kirby File, so you can use crops, thumbs, and file metadata. It returns `null` when there is no native file, including external URL-only images and unavailable products. `$item->product()?->imageUrls()` retains the resolved URLs.
- `$item->subtotal()` returns that price multiplied by the quantity. Both amounts are exact Brick Money values, or `null` when the item cannot resolve.
- `$cart->subtotal()` adds the item subtotals. It covers merchandise only, before shipping, discounts, or tax adjustments. It is never a partial total: any unresolved line makes it `null`. An empty, configured cart has a zero subtotal.

For example, three shirts priced at `16.00 EUR` each have `price()` of `16.00 EUR` and an item `subtotal()` of `48.00 EUR`.

```php
<?php

/** @var ProgrammatorDev\StripeCheckout\Cart\CartItem $item */
$thumbnailUrl = $item->image()?->crop(400, 400)->url();
```

The File follows the configured image-field priority and ordering. External images are not downloaded or converted into Kirby Files. In Stripe price mode, local Kirby images remain available for your storefront; this does not replace Stripe's own Checkout image data.

### List an item's chosen options

`$item->options()` returns typed SelectedOption objects with stable IDs and names in the current site language:

```php
<?php

/** @var ProgrammatorDev\StripeCheckout\Cart\CartItem $item */
foreach ($item->options() as $option) {
    echo esc($option->optionName()) . ': ' . esc($option->valueName()); // Size: Large

    $option->optionId(); // Stable option ID.
    $option->valueId();  // Stable chosen value ID.
}
```

These are the item's chosen values, not every option available on the product. A simple product returns an empty array. An unavailable product also returns an empty array because its labels cannot be resolved; check `hasErrors()` to distinguish that case. The stored ID map remains available through `$item->request()->selectedOptions()`.

### Resolution and errors

The cart stores only product references, quantities, and option IDs—not prices or product details. Each fresh read resolves current products and prices. Stripe-source products use fresh, validated Stripe Prices rather than treating the Panel catalogue cache as authority.

An item that becomes unavailable stays visible with `hasErrors()` and `errors()`, and can still be removed. Each CartError has a stable `code()`, translated `message()`, and optional `itemId()`. Cart-level `errors()` includes line errors too. Messages can be customized using [translation overrides](translations.md). `hasErrors()` does not guarantee whether a later Checkout attempt will succeed.

## Revisions and rejected changes

Updates, removals, and clearing use the Cart object's current `revision()` by default. Adding is relative and always applies to the latest stored cart.

For forms or other requests, include the revision displayed with the cart and pass it explicitly. Reading a new Cart on submission and using its default revision would lose protection against changes made in another browser tab.

```php
<?php

use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Cart\Exception\CartException;

/** @var Cart $cart */
/** @var string $itemId Validated submitted cart-item ID. */
/** @var int $quantity Validated submitted quantity. */
/** @var string $submittedRevision Validated revision from the displayed form. */
try {
    $cart->update($itemId, $quantity, revision: $submittedRevision);
} catch (CartException $error) {
    echo esc($error->error()->message());

    if ($error->errorCode() === 'cart.revision_conflict') {
        $cart = $error->cart(); // Fresh cart for redisplay; do not blindly retry.
    }
}
```

Rejected input does not change stored selections. A conflict leaves the original Cart object unchanged and exposes the newer Cart through the exception. Error objects never include raw Stripe or custom-resolver exception messages. Misconfigured structural options may throw the existing ConfigurationException before a Cart can be created; use the Panel diagnostics to correct them.

## Session behavior and disabling

Login, logout, and signing in as another user preserve the same browser's cart. A fresh read uses the current user and site language. Carts are not saved to user accounts or shared between browsers/devices.

Kirby's normal session lifetime controls expiry. The plugin does not request a long session, add its own cookie, or need cron. Unrelated plugin operations do not create a cart session. If stored cart data is malformed, only the cart is reset and a safe `cart.session_reset` diagnostic is written to PHP's error log.

To disable the built-in cart, configure this PHP-only option:

```php
'programmatordev.stripe-checkout' => [
    'cart' => ['enabled' => false],
],
```

Then `cart()` returns `null` without opening a session. This switch is not an editable Panel setting.
