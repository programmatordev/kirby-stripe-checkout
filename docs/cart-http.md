# Cart HTTP routes

The plugin registers cart routes automatically. Guests and signed-in visitors use the same browser-session cart. No custom route or frontend library is required.

These routes use the same operations as the [PHP cart API](cart.md). The browser sends references, quantities and chosen option IDs. It cannot choose prices, names, Stripe IDs, tax amounts or other product facts.

Writes resolve the products needed for validation and the resulting cart, without first resolving the old cart for display. Clearing therefore does not contact Stripe; removing a line only resolves the remaining lines for the response.

## Routes

Paths are relative to the current site's language URL. For example, a Portuguese site may use `/pt/stripe-checkout/cart`.

| Method | Path | Body |
| --- | --- | --- |
| GET | `/stripe-checkout/cart` | None |
| POST | `/stripe-checkout/cart/items` | `{"reference":"products/shirt","quantity":1}` |
| PATCH | `/stripe-checkout/cart/items/{itemId}` | `{"quantity":2,"revision":"…"}` |
| DELETE | `/stripe-checkout/cart/items/{itemId}` | `{"revision":"…"}` |
| DELETE | `/stripe-checkout/cart` | `{"revision":"…"}` |

`reference` supports the same product references as PHP `add()`. Quantity defaults to 1 only when omitted from an add. Updates require a positive whole number; use DELETE to remove an item. `{itemId}` is the returned cart-item ID, not the product's Page ID.

Products with options add `options` alongside `reference` and `quantity`, as an object mapping stable option IDs to value IDs:

```json
{"reference":"products/shirt","quantity":2,"options":{"size-id":"large-id"}}
```

The HTTP key and the PHP `add()` argument are both named `options`.

Send the revision last displayed to the visitor for updates, removals and clearing. If it is stale, the route returns `409` with the current cart and makes no change. Refresh the controls and let the visitor decide; do not automatically retry the write.

All responses are private and use `Cache-Control: no-store, private` and `Vary: Accept`. There is no CORS configuration or cross-session cart lookup. With PHP `cart.enabled` set to `false`, the routes are absent.

## Add an item with JavaScript

Render a form in your product template:

```php
<?php
/** @var Kirby\Cms\Site $site */
/** @var Kirby\Cms\Page $page */
?>
<form id="add-to-cart" method="post" action="<?= esc($site->url() . '/stripe-checkout/cart/items', 'attr') ?>">
    <input type="hidden" name="csrf" value="<?= esc(csrf(), 'attr') ?>">
    <input type="hidden" name="reference" value="<?= esc($page->id(), 'attr') ?>">
    <label>Quantity <input type="number" name="quantity" min="1" step="1" value="1" required></label>
    <button>Add to cart</button>
</form>
<p id="cart-message" role="status"></p>
```

```js
const form = document.querySelector('#add-to-cart');
const message = document.querySelector('#cart-message');

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const fields = new FormData(form);
    const button = form.querySelector('button');
    button.disabled = true;

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF': fields.get('csrf'),
            },
            body: JSON.stringify({
                reference: fields.get('reference'),
                quantity: Number(fields.get('quantity')),
            }),
        });
        const result = await response.json();
        message.textContent = response.ok
            ? `${result.data.cart.totalQuantity} item(s) in your cart.`
            : result.error.message;
    } catch {
        // An uncertain response does not prove the add failed. Do not retry it.
        message.textContent = 'Refresh the cart to check whether the item was added.';
    } finally {
        button.disabled = false;
    }
});
```

Every mutation requires Kirby's CSRF token. JSON sends it in `X-CSRF`. Form-encoded requests may send a `csrf` body field instead. If both are supplied, they must match. Query-string tokens are never accepted. GET does not need a token.

`application/x-www-form-urlencoded` bodies use `reference`, `quantity`, and `options[option-id]`. The plugin converts form quantity strings to integers; JSON quantities must already be integers. A JSON `csrf` body field is not supported. Unknown fields, including a `request` wrapper, are rejected.

## Update an item and keep its revision

A revision identifies the saved cart state the visitor has seen. For example, if two tabs display quantity 1 and one changes it to 3, the other must not silently overwrite that newer choice. Update, remove and clear requests therefore include the displayed cart's revision. Adding items works against the latest cart and needs no revision.

Every successful JSON read or write returns `data.cart.revision`. Keep it together with the cart you display; do not generate it yourself or fetch a new revision just before submitting an older view. `fetch()` does not add it automatically.

In this example, `cart` is the `data.cart` object already displayed, `cartUrl` is the language-aware cart URL, and `csrfToken` is Kirby's token rendered into the page:

```js
async function updateQuantity(itemId, quantity) {
    const response = await fetch(`${cartUrl}/items/${encodeURIComponent(itemId)}`, {
        method: 'PATCH',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF': csrfToken,
        },
        body: JSON.stringify({ quantity, revision: cart.revision }),
    });
    const result = await response.json();

    if (result.data?.cart) {
        cart = result.data.cart;
        renderCart(cart); // Your own rendering function updates values and controls.
    }

    if (!response.ok) {
        // A 409 includes the newer cart. Display it; don't repeat the write.
        throw new Error(result.error.message);
    }
}
```

Disable cart mutation controls while a write is pending and handle network failures without automatically retrying. Remove and clear follow the same pattern, with only `revision` in the body. For HTML forms, render `<input type="hidden" name="revision" value="<?= esc($cart->revision(), 'attr') ?>">` and replace the controls with the returned fragment so they carry the next revision.

This is optimistic concurrency control. The `revision` body field is this plugin's contract, not a standard HTTP field. HTTP also defines conditional writes through [ETag and If-Match](https://www.rfc-editor.org/rfc/rfc9110.html#name-if-match); the explicit body value keeps JSON and ordinary form submissions consistent here.

## JSON responses

Successful reads and writes return `200` with `data.cart`. It contains:

- `revision`, `items`, `count`, `totalQuantity`, `empty`, `hasErrors` and `errors`;
- `currency`, `subtotal` and `destinationCountry` (currently `null`);
- each item's `id`, canonical `request`, resolved `product`, `price`, `subtotal`, `hasErrors` and `errors`.

Product details include `name`, `description`, `images` (URLs), `sku`, `requiresShipping` and the chosen `options`, with option/value IDs and names. PHP's native Kirby File is not serialized. Internal cart IDs, provider IDs, metadata and session data are not included.

Amounts are decimal strings, for example `{"amount":"16.00","currency":"EUR"}`, never JSON numbers. An unresolved item has `null` product and amounts. Any unresolved line makes the cart subtotal `null`; valid lines remain readable. A successful GET can therefore have `hasErrors: true`.

Failures contain `error.code` and a translated `error.message`, plus `field`, `itemId` or safe `details` when applicable. Rejected mutations retain the available cart in `data.cart`; a revision conflict supplies the newer cart. Errors before the cart can be read, such as invalid CSRF or malformed input, may omit it.

| Status | Meaning / code |
| --- | --- |
| 400 | Invalid JSON, empty or non-object body: `request.invalid_body` |
| 403 | Missing, invalid or conflicting CSRF token: `request.csrf_invalid` |
| 404 | Cart item not found in this session: `cart.item_not_found` |
| 405 | Method not supported; the `Allow` header lists supported methods |
| 406 | Requested representation unavailable, including HTML without a renderer |
| 409 | Stale revision: `cart.revision_conflict` |
| 415 | Unsupported body content type: `request.unsupported_media_type` |
| 422 | Invalid selection/quantity, line limit, unavailable product or incomplete configuration |
| 503 | Stripe Price retrieval temporarily unavailable: `product.resolution_unavailable` |
| 500 | Unexpected failure: `internal.error` |

Validation codes include `selection.invalid`, `selection.quantity_invalid`, `selection.line_limit_exceeded`, `product.unavailable`, `product.invalid` and `configuration.not_ready`. Unknown, draft and otherwise unavailable products deliberately share a non-disclosing error. Cart/line read errors retain the PHP API's `cart.*` codes. Method/representation rejections have an empty body.

## Return HTML instead

Configure one PHP renderer to return a project-owned fragment:

```php
use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Cart\CartRenderContext;

return [
    'programmatordev.stripe-checkout' => [
        'cart' => [
            'renderer' => static fn(?Cart $cart, CartRenderContext $context): string =>
                snippet('cart', ['cart' => $cart, 'context' => $context, 'site' => site()], true),
        ],
    ],
];
```

Request `Accept: text/html` on the same routes. The response body is the fragment itself, not HTML nested inside JSON. HTMX, Alpine AJAX or your own script can insert it into the page. No framework-specific headers are inferred. With no Accept header, or `*/*`, JSON is the default; explicit JSON never calls the renderer.

The renderer receives the Cart when available, including after a rejected mutation, or `null` for errors before one can be read. Rejection does not erase the cart's controls; render them alongside the error. `CartRenderContext` exposes only:

- `operation()`: `CartOperation::Read`, `Add`, `Update`, `Remove` or `Clear`;
- `status()`: the HTTP status;
- `error()`: the safe CartError, or `null` on success.

Render the current revision into every update/remove/clear control. Handle a nullable cart and escape displayed values. Route rendering has no Page template scope: pass any additional variables your snippet uses explicitly, as with `site` above. See the repository's `site/snippets/cart.php` and `cart-script.php` for a working development example. The snippet name and markup are entirely yours.

HTML errors retain their meaningful HTTP status. Configure your client to display non-2xx fragments where appropriate. Without a renderer, HTML is rejected with `406` **before any mutation**.

If rendering fails after a successful write, the response is empty `204`: the change succeeded, so never repeat it. Fetch the cart again if needed. Renderer failure on a GET returns empty `500`; on a rejected write it keeps the original error status. A safe `cart.renderer_failed` diagnostic goes to the PHP error log. Rendering must only present the result, never perform cart mutations or other business actions.
