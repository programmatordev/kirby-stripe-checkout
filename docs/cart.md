# Cart

The built-in cart works with Kirby's normal browser session. It needs no separate storage setup. Configure the store currency and product fields first; see [Configuration](configuration.md) and [Products](products.md).

Use the PHP API below or the built-in [HTTP routes](cart-http.md) for browser interactions. Checkout submission is not available yet.

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

## Set the shipping destination

The cart can retain a destination country before Checkout so the shipping flow can resolve the applicable options. Pass an uppercase country code supported by Stripe Checkout, or `null` to clear it:

```php
<?php

/** @var ProgrammatorDev\StripeCheckout\Cart\Cart $cart */
$countries = $cart->destinationCountries(); // ['PT' => 'Portugal', ...]
$cart->updateDestinationCountry('PT');
$cart->destinationCountry(); // 'PT'

$cart->updateDestinationCountry(null);
```

This country is bounded quote input, not a saved address. Stripe Checkout still collects the authoritative shipping address. Updating the destination uses the same revision protection as item updates; setting the current value again is a no-op and keeps the revision unchanged.

`destinationCountries()` returns localized `code => name` choices for the current Cart. It is empty for an empty or digital-only Cart. With built-in shipping, explicit zones limit the list to their configured countries, while a rest-of-world fallback exposes every Stripe-supported destination. With a custom resolver, the list also contains every Stripe-supported destination because only the resolver can decide whether the customer's selected country is eligible.

```php
<select name="destinationCountry">
    <?php foreach ($cart->destinationCountries() as $code => $name): ?>
        <option value="<?= esc($code, 'attr') ?>"<?= $cart->destinationCountry() === $code ? ' selected' : '' ?>>
            <?= esc($name) ?>
        </option>
    <?php endforeach ?>
</select>
```

## Preview shipping

`shippingQuote()` resolves the current products, destination, language, user, and shipping configuration together:

```php
<?php

use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuoteStatus;

/** @var ProgrammatorDev\StripeCheckout\Cart\Cart $cart */
$quote = $cart->shippingQuote();

if ($quote?->status() === ShippingQuoteStatus::Available) {
    foreach ($quote->options() as $option) {
        echo esc($option->label());
        echo esc($site->stripeCheckout()->formatMoney($option->amount()));
    }
}
```

The result is:

- `null` for an empty or entirely digital cart;
- `available` with one to five ordered options;
- `destination_required` when the customer needs to choose a country first; or
- `unavailable` with a safe `shipping.*` reason code.

Quote options are an optional preview of what Checkout will offer. They are not selectable Cart state: Stripe preselects the first supplied option and owns the customer's final choice. Checkout creation will resolve products and shipping again rather than trusting a previously displayed quote.

Each available `ShippingOption` exposes `key()`, `label()`, exact `amount()`, optional `deliveryEstimate()`, `taxBehavior()` and `taxCode()`. A destination-required quote is not a Cart error. An unavailable quote is blocking. Shipping-resolution failures add the translated `shipping.unavailable` Cart error, while leaving a valid merchandise `subtotal()` readable; an existing product or configuration error is not duplicated as a shipping error.

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

Item updates, removals, destination changes, and clearing use the Cart object's current `revision()` by default. Adding is relative and always applies to the latest stored cart.

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

Then `cart()` returns `null` without opening a session, and the built-in cart routes are not registered. This switch is not an editable Panel setting.
