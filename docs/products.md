# Products and variants

The plugin can resolve published Kirby pages into trusted product snapshots. It does not require a particular product template or parent page.

Kirby is the default source for names, prices, images, SKUs, shipping behavior, and variants. A store can instead use read-only Stripe Prices while keeping product Pages and project-owned SKUs in Kirby.

## Minimum product fields

Configure the store currency and default shipping behavior first. A simple Kirby-priced product then needs only:

- its normal Kirby page title;
- an exact decimal `stripeCheckoutPrice` value.

Add only the reusable fields that fit the product blueprint:

```yaml
title: Product

fields:
  stripeCheckoutPrice:
    extends: fields/stripe-checkout/price
  stripeCheckoutPriceId:
    extends: fields/stripe-checkout/stripe-price
  stripeCheckoutDescription:
    extends: fields/stripe-checkout/description
  stripeCheckoutImages:
    extends: fields/stripe-checkout/images
  stripeCheckoutSku:
    extends: fields/stripe-checkout/sku
  stripeCheckoutRequiresShipping:
    extends: fields/stripe-checkout/requires-shipping
  stripeCheckoutOptions:
    extends: fields/stripe-checkout/options
```

Each blueprint defines one field and can be placed in any tab, section, or column. The field handle is chosen by the developer; the namespaced handles above match the plugin's default mapping. Omit fields the store does not use. The plugin does not add a product currency because every product uses the configured store currency.

The complete reusable set is `name`, `price`, `stripe-price`, `description`, `images`, `sku`, `requires-shipping`, and `options`, all below `fields/stripe-checkout/`. The Page title is the default product name. If a separate name field is needed, extend `fields/stripe-checkout/name` under the chosen handle and map `products.fields.name` to it.

Including both price fields lets the store switch its configured price source without changing the blueprint. Both fields remain visible in the Panel, but only the active source is editable. The inactive value is preserved and ignored. Stores that will never switch can include only the relevant field.

Prices remain exact decimal strings in content. A stored value such as `16` is rendered as `€16.00` for an English EUR context, while a zero-decimal currency uses no decimal places. See [Money and currency](money.md).

## Existing product blueprints

You do not need to use the reusable fields. Map the semantic product roles to fields you already have:

```php
<?php

return [
    'programmatordev.stripe-checkout' => [
        'products' => [
            'fields' => [
                'name' => 'productName',
                'description' => 'summary',
                'images' => ['thumbnail', 'gallery'],
                'price' => 'unitPrice',
                'requiresShipping' => 'shippingOverride',
            ],
        ],
    ],
];
```

A mapping tells the resolver where to read content. It does not create aliases or new Page methods. Unknown mapping keys, invalid field handles, and duplicate image fields are rejected.

The available mappings and defaults are:

| Role | Default field | Notes |
| --- | --- | --- |
| `name` | `title` | Required resolved product name. |
| `description` | `stripeCheckoutDescription` | Optional; map it to `null` to disable it. |
| `images` | `stripeCheckoutImages` | One field, an ordered list, or `null`. |
| `sku` | `stripeCheckoutSku` | Optional simple-product SKU. |
| `price` | `stripeCheckoutPrice` | Exact default price in Kirby price mode. |
| `stripePriceId` | `stripeCheckoutPriceId` | Selected Stripe Price in Stripe price mode. |
| `requiresShipping` | `stripeCheckoutRequiresShipping` | `inherit`, `yes`, or `no`. |
| `options` | `stripeCheckoutOptions` | Product options and generated variants. |

The image fields are read in order and duplicate URLs are removed. The first eight usable HTTP(S) images are exposed in the resolved snapshot because that is Stripe Checkout's limit. Extra selected images remain in Kirby; `metadata()['imagesTruncated']` reports that the projection was shortened.

## Variants

Use one model for every customer choice. A T-shirt can have a Colour option and a Size option; the Panel generates the complete set of colour-and-size combinations. You do not need to decide whether a choice is a “variant” or an “option.”

Each generated variant has a stable internal ID and can define:

- whether the combination is active;
- its own SKU;
- a price override;
- a shipping override.

In Stripe price mode, the variant price control uses the same searchable Stripe Price selector as the product default. It never asks an editor to paste a raw Price ID.

An empty variant price inherits the product price. `inherit` shipping first uses the product override and then the store default. A variant SKU does not inherit the simple-product SKU.

Options and values can be renamed without changing their stable IDs. On multi-language sites, the default language owns IDs, combinations, availability, SKUs, prices, and shipping facts. Other languages translate only product and option names. The resolved snapshot keeps the language-specific names used for the customer flow.

### Variant presets

The Settings tab can store reusable presets such as Colour and Size. Importing a preset creates an independent copy with fresh IDs in the current product. Later edits to a preset do not rewrite products that already imported it.

Presets are technical editor templates stored in the default language. Translate the copied option and value names on each product. This avoids a live cross-language relationship between every preset and product.

## Rendering options

Convert the configured options field with Kirby's normal field API. The result contains the localized names and effective variant data needed to render and match controls:

```php
<?php

/** @var Kirby\Cms\Page $page */

$view = $page->stripeCheckoutOptions()->toProductOptions();

foreach ($view->options() as $option) {
    echo esc($option->name());
}

$variant = $view->matchVariant([
    'colourOption0001' => 'blueValue000001',
    'sizeOption0000001' => 'largeValue00001',
]);

if ($variant !== null) {
    $variant->sku();
    $variant->price();
    $variant->requiresShipping();
}
```

`price()` and `requiresShipping()` contain the effective values after applying the product and store fallbacks. Kirby prices are returned as `InlinePrice`; Stripe-owned prices are returned as `StripePriceReference`. The variant SKU remains `null` when it is not configured because it does not inherit the simple-product SKU.

Use the mapped field handle when it differs from the default:

```php
$view = $page->variants()->toProductOptions();
```

For programmatic code that starts with a Page reference instead of a field, use `$site->stripeCheckout()->productOptions($reference)`.

`toArray()` provides a JSON-safe projection with option and value names, variant IDs, complete selected-option maps, active state, SKU, effective price, and effective shipping behavior. Browser values are presentation feedback only; submit the option/value IDs and let the server resolve them again.

For example, expose the projection to JavaScript through an escaped data attribute and match the currently selected IDs:

```php
<?php

use Kirby\Data\Json;

/** @var ProgrammatorDev\StripeCheckout\Product\ProductOptions $view */
?>

<form data-product-options="<?= esc(Json::encode($view->toArray()), 'attr') ?>">
    <?php foreach ($view->options() as $option): ?>
        <label>
            <?= esc($option->name()) ?>
            <select name="stripeCheckoutSelectedOptions[<?= esc($option->id(), 'attr') ?>]">
                <?php foreach ($option->values() as $value): ?>
                    <option value="<?= esc($value->id(), 'attr') ?>">
                        <?= esc($value->name()) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </label>
    <?php endforeach ?>
</form>

<script>
const form = document.querySelector('[data-product-options]');
const { variants } = JSON.parse(form.dataset.productOptions);
const selectedOptions = Object.fromEntries(
    [...form.querySelectorAll('select')].map(select => [
        select.name.match(/\[([^\]]+)\]/)[1],
        select.value,
    ]),
);
const variant = variants.find(candidate =>
    candidate.enabled
    && Object.entries(selectedOptions).every(([optionId, valueId]) =>
        candidate.selectedOptions[optionId] === valueId
    )
);

if (variant) {
    console.log(variant.price, variant.sku, variant.requiresShipping);
}
</script>
```

## Resolving a product

Pass an untrusted page reference, quantity, and optional selected options through `ProductRequest`:

```php
<?php

use ProgrammatorDev\StripeCheckout\Product\ProductRequest;

/** @var Kirby\Cms\Site $site */

$request = new ProductRequest(
    reference: 'products/t-shirt',
    quantity: 2,
    selectedOptions: [
        'colourOption0001' => 'blueValue000001',
        'sizeOption0000001' => 'largeValue00001',
    ],
);

$product = $site->stripeCheckout()->resolveProduct($request);
```

The default resolver accepts the same useful Page locator forms as Kirby, rejects drafts and missing pages, validates the complete request, and normalizes the result to the Page's canonical `page://...` UUID. The returned `ResolvedProduct` is an immutable snapshot containing the exact price, effective shipping boolean, localized option names, optional SKU and images, and matched variant ID.

Resolution is read-only. It does not change stock, create an order, create a Checkout Session, or contact Stripe. In Stripe price mode it returns a validated `StripePriceReference`; the internal charging path retrieves that Price and its Product freshly before the reference can become an effective line.

## Stripe Price mode

Set the store price source to Stripe, choose the store currency, and configure a Stripe server key that can read Prices and Products. The `stripe-checkout-price` field then opens a searchable, single-value picker containing eligible active one-time Prices in that currency.

The picker lists Stripe Products first, with their first image and number of eligible Prices. Choose a Product to see its Prices, including the optional nickname, localized amount, currency, tax behavior, and full Price ID. The saved field keeps showing the Product and Price summary, including the Price ID, after the Page reloads, but stores only the scalar `price_...` ID.

The catalogue is a Kirby-native, read-only cache for Panel convenience:

- the first authorized lookup fills an empty catalogue;
- opening or searching the picker refreshes a catalogue whose last successful refresh is at least 24 hours old;
- after a failed automatic refresh, the last successful catalogue remains available and another automatic attempt waits 15 minutes;
- the picker dialog's refresh button retrieves every Stripe result page;
- searching and pagination then use the local catalogue;
- a failed refresh keeps the last successful catalogue and marks it as stale;
- a refresh immediately rehydrates the saved selection; if it is no longer eligible, its ID is preserved with a warning;
- a saved selection is hydrated from the cache without contacting Stripe on every Page load;
- a saved ID is shown and preserved even if the catalogue is unavailable or that Price is no longer eligible.

The cache is never charging authority. Before later order creation, the selected Price and associated Product are retrieved freshly and revalidated. Supported Prices must be active, fixed, per-unit, one-time Prices whose default currency exactly matches the store currency and whose Product is active and named. Recurring, tiered, customer-chosen, fractional-provider-unit, quantity-transformed, or otherwise ambiguous Prices are rejected. Extra `currency_options` are ignored.

Stripe supplies the authoritative Product name, description, images, amount, currency, and tax facts in this mode. Kirby's local SKU and selected option labels remain project-owned snapshots; local Product presentation fields are not merged into Stripe Product data.

## Custom product resolver

Most Kirby stores do not need a custom resolver. If products are normal Kirby pages with different field names, keep the built-in resolver and configure the `products.fields` mapping instead.

Replace the resolver when finding or assembling a product requires a genuinely different data source or business rule, for example:

- products stored in an ERP, external API, or database;
- lookup by SKU or another project-specific identifier instead of a Kirby Page reference;
- authoritative prices or availability supplied by an external catalogue;
- configurable products that cannot be represented by the standard variant model;
- store-specific product rules that require a complete custom resolution step.

Configure either a `ProductResolverInterface` object or a typed PHP closure:

```php
<?php

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Product\InlinePrice;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;

return [
    'programmatordev.stripe-checkout.products.resolver' => static function (
        ProductRequest $request,
        ProductResolutionContext $context,
    ): ResolvedProduct {
        $currency = $context->settings()->currency()
            ?? throw new LogicException('The product context requires a store currency.');

        return new ResolvedProduct(
            request: $request,
            name: 'External product',
            requiresShipping: false,
            price: new InlinePrice(Money::of('9.50', $currency)),
        );
    },
];
```

A custom resolver replaces the Kirby Page resolver. The plugin still checks that it did not change the requested quantity or selected options and that its price source and currency match the store configuration. Expected lookup and validation failures use the public `Product\Exception` family with stable `errorCode()` values.

## Current boundary

Kirby and Stripe Price product resolution are available, but the cart, Checkout Session, order, and webhook layers are not implemented yet.
