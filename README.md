# Kirby Stripe Checkout

[![Latest Version](https://img.shields.io/github/release/programmatordev/kirby-stripe-checkout.svg?style=flat-square)](https://github.com/programmatordev/kirby-stripe-checkout/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Tests](https://github.com/programmatordev/kirby-stripe-checkout/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/programmatordev/kirby-stripe-checkout/actions/workflows/ci.yml?query=branch%3Amain)

[Stripe Checkout](https://stripe.com/en-pt/payments/checkout) for [Kirby CMS](https://getkirby.com).

> [!CAUTION]
> This plugin is still in its early stages.
> This means that it should not be considered stable, so use it at your own risk.
> Expect a lot of breaking changes until version `1.0`.

## Features

- 🔥 Stripe Checkout for both `hosted` and `embedded` modes;
- 💸 Handles sync and async payments (credit card, bank transfer, etc.);
- 📦 Orders panel page;
- ⚙️ Checkout Settings panel page;
- 🪝 Hooks for all payment status (completed, pending and failed), orders and checkout sessions;
- 🛒 Cart management;
- ...and more.

## Documentation

- [Requirements](#requirements)
- [Installation](#installation)
- [Options](#options)
- [Hooks](#hooks)
- [Cart](#cart)
- [Translations](#translations-1)
- [Setup](#setup)
- [Development](#development)
- [Production](#production)

## Requirements

- PHP `8.2` or higher;
- Kirby CMS `4.0` or higher;
- [Stripe account](https://dashboard.stripe.com/register).

## Installation

Install the plugin via [Composer](https://getcomposer.org/):

```bash
composer require programmatordev/kirby-stripe-checkout
```

## Options

Default options:

```php
// config.php

return [
    'programmatordev.stripe-checkout' => [
        'stripePublicKey' => null,
        'stripeSecretKey' => null,
        'stripeWebhookSecret' => null,
        'currency' => 'EUR',
        'uiMode' => 'hosted',
        'successPage' => null,
        'cancelPage' => null,
        'returnPage' => null,
        'ordersPage' => 'orders',
        'settingsPage' => 'checkout-settings',
        'cartSnippet' => null,
        'translations' => []
    ]
];
```

> [!TIP]
> It is recommended that you use a library that enables environment variables
> to store your project credentials in a separate place from your code
> and to have separate development and production access keys.

List of all available options:

- [stripePublicKey](#stripepublickey)
- [stripeSecretKey](#stripesecretkey)
- [stripeWebhookSecret](#stripewebhooksecret)
- [uiMode](#uimode)
- [currency](#currency)
- [successPage](#successpage)
- [cancelPage](#cancelpage)
- [returnPage](#returnpage)
- [ordersPage](#orderspage)
- [settingsPage](#settingspage)
- [cartSnippet](#cartsnippet)
- [translations](#translations)

### `stripePublicKey`

type: `string` `required`

Stripe public key found on the Stripe Dashboard.

### `stripeSecretKey`

type: `string` `required`

Stripe secret key found on the Stripe Dashboard.

### `stripeWebhookSecret`

type: `string` `required`

Webhook secret found when a Webhook is created in the Stripe Dashboard.

Check the [Setup](#setup) section for more information.

### `currency`

type: `string` default: `EUR` `required`

Three-letter [ISO currency code](https://www.iso.org/iso-4217-currency-codes.html).
Must be a [supported currency](https://stripe.com/docs/currencies).

### `uiMode`

type: `string` default: `hosted` `required`

The UI mode of the Checkout Session.

Available options:
- `hosted` the Checkout Session will be displayed on a Stripe-hosted page (where the user will be redirected);
- `embedded` the Checkout Session will be displayed as an embedded form on the website page.

### `successPage`

type: `string`

This option is `required` if `uiMode` is set to `hosted`.

Page to where a user will be redirected when a Checkout Session is completed (form successfully submitted).

Must be a valid Kirby page `id`.
The `id` is used, instead of a URL, to make sure that the user is redirected correctly in case of multi-language setups.

### `cancelPage`

type: `string`

This option is `required` if `uiMode` is set to `hosted`.

Page to where a user will be redirected if decides to cancel the payment and return to the website.

Must be a valid Kirby page `id`.
The `id` is used, instead of a URL, to make sure that the user is redirected correctly in case of multi-language setups.

### `returnPage`

type: `string`

This option is `required` if `uiMode` is set to `embedded`.

Page to where a user will be redirected when a Checkout Session is completed (form successfully submitted).

Must be a valid Kirby page `id`.
The `id` is used, instead of a URL, to make sure that the user is redirected correctly in case of multi-language setups.

### `ordersPage`

type: `string` default: `orders` `required`

Kirby Panel page with the overview of all orders.

Must be a valid Kirby page `id`.

Check the [Setup](#setup) section for more information.

### `settingsPage`

type: `string` default: `checkout-settings` `required`

Kirby Panel page with Checkout settings.

Must be a valid Kirby page `id`.

Check the [Setup](#setup) section for more information.

### `cartSnippet`

type: `?string` default: `null`

When set, it will look for the snippet with the same name and return the HTML content on every cart API response.
Useful when adding, updating or removing cart contents, and you want to update the HTML on every request.

If snippet does not exist or is empty, it will return `null`.

### `translations`

type: `array` default: `[]`

Use this option to overwrite existing translations or to add a new one that is not bundled with the plugin.
Check the [`translations`](translations) folder for all available translations.

An example when overwriting an existing translation:

```php
// site/config/config.php

return [
    'programmatordev.stripe-checkout' => [
        'translations' => [
            'en' => [
                // overwrites "Orders" to "Tickets"
                'stripe-checkout.fields.orders.ordersHeadline.label' => 'Tickets'
            ]
        ]
    ]
];
```

If a translation does not exist, you can provide yours:

```php
// site/config/config.php

return [
    'programmatordev.stripe-checkout.translations' => [
        'translations' => [
            // The German translation is not currently bundled with the plugin, so you can provide your own
            'de' => [
                'stripe-checkout.fields.product.price.label' => 'Preis',
                'stripe-checkout.fields.product.thumbnail.label' => 'Vorschaubild',
                'stripe-checkout.fields.settings.shippingHeadline.label' => 'Versand',
                'stripe-checkout.fields.settings.shippingEnabled.label' => 'Versandeinstellungen',
                'stripe-checkout.fields.settings.shippingAllowedCountries.label' => 'Erlaubte Länder',
                // ...
            ]
        ]
    ]
];
```

## Hooks

- [stripe-checkout.session.create:before](#stripe-checkoutsessioncreatebefore)
- [stripe-checkout.order.create:before](#stripe-checkoutordercreatebefore)
- [stripe-checkout.payment:succeeded](#stripe-checkoutpaymentsucceeded)
- [stripe-checkout.payment:pending](#stripe-checkoutpaymentpending)
- [stripe-checkout.payment:failed](#stripe-checkoutpaymentfailed)

### `stripe-checkout.session.create:before`

Triggered before creating a Checkout Session.
Useful to set additional Checkout Session parameters.

You can check all the available parameters in the Stripe API [documentation page](https://docs.stripe.com/api/checkout/sessions/create?lang=php).

```php
// config.php

return [
    'hooks' => [
        'stripe-checkout.session.create:before' => function (array $sessionParams): array
        {
            // for example, if you want to enable promotion codes
            // https://docs.stripe.com/api/checkout/sessions/create?lang=php#create_checkout_session-allow_promotion_codes
            $sessionParams['allow_promotion_codes'] = true;

            return $sessionParams;
        }
    ]
];
```

> [!WARNING]
> Take into account that the `sessionParams` variable contains data required to initialize a Checkout Session.
> You may change these but at your own risk.

### `stripe-checkout.order.create:before`

Triggered before creating an Order page in the Panel.
Useful to set additional Order data in case you add additional fields in the blueprint or want to change existing ones.

```php
// config.php

use Stripe\Checkout\Session;
use Stripe\Event;

return [
    'hooks' => [
        'stripe-checkout.order.create:before' => function (array $orderContent, Session $checkoutSession, Event $stripeEvent): array
        {
            // change order content
            // ...

            return $orderContent;
        }
    ]
];
```

> [!WARNING]
> Take into account that the `orderContent` variable contains all data required to create an Order page.
> You may change these but at your own risk.

### `stripe-checkout.payment:succeeded`

Triggered when a payment is completed successfully.

> [!IMPORTANT]
> This hook is triggered for both sync and async payments.
> An example of a sync payment is when a customer pays using a credit card as the payment method.
> An example of an async payment is when a customer wants to pay through a bank transfer.
> In this case, the hook will only be triggered when the actual bank transfer is successfully performed.
> Check the [stripe-checkout.payment:pending](#stripe-checkoutpaymentpending) hook to handle pending payments.

```php
// config.php

use Kirby\Cms\Page;
use Stripe\Checkout\Session;
use Stripe\Event;

return [
    'hooks' => [
        'stripe-checkout.payment:succeeded' => function (Page $orderPage, Session $checkoutSession, Event $stripeEvent): void
        {
            // email the customer when the payment succeeds
            kirby()->email([
                'from' => 'orders@myshop.com',
                'to' => $orderPage->customer()->toObject()->email()->value(),
                'subject' => 'Thank you for your order!',
                'body' => 'Your order will be processed soon.',
            ]);
        }
    ]
];
```

### `stripe-checkout.payment:pending`

Triggered when an order is pending payment.

This happens when a customer uses an async payment method, like a bank transfer,
where the Checkout form is submitted successfully, but the payment is yet to be made.

```php
// config.php

use Kirby\Cms\Page;
use Stripe\Checkout\Session;
use Stripe\Event;

return [
    'hooks' => [
        'stripe-checkout.payment:pending' => function (Page $orderPage, Session $checkoutSession, Event $stripeEvent): void
        {
            // email the customer when the payment is pending
            kirby()->email([
                'from' => 'orders@myshop.com',
                'to' => $orderPage->customer()->toObject()->email()->value(),
                'subject' => 'Thank you for your order!',
                'body' => 'Your order is pending payment.',
            ]);
        }
    ]
];
```

### `stripe-checkout.payment:failed`

Triggered when a payment has failed.

This happens when a customer uses an async payment method, like a bank transfer,
where the Checkout form is submitted successfully, but the payment has failed
(for example, the deadline for the payment has expired).

```php
// config.php

use Kirby\Cms\Page;
use Stripe\Checkout\Session;
use Stripe\Event;

return [
    'hooks' => [
        'stripe-checkout.payment:failed' => function (Page $orderPage, Session $checkoutSession, Event $stripeEvent): void
        {
            // email the customer when the payment has failed
            kirby()->email([
                'from' => 'orders@myshop.com',
                'to' => $orderPage->customer()->toObject()->email()->value(),
                'subject' => 'Bad news!',
                'body' => 'Your order has been canceled because the payment has failed.',
            ]);
        }
    ]
];
```

## Cart

A cart management system already exists and is required to be able to create a Checkout Session.
The reason for this is that the checkout line items are generated based on the current cart contents.
This means that the cart must have at least one added item; otherwise it will throw an error.

### PHP

A `cart()` function is available to manage the cart contents.

```php
use ProgrammatorDev\StripeCheckout\Cart\Cart;

cart(array $options = []): Cart
```

Default options:

```php
cart([
    // the same as configured in the plugin options
    'currency' => option('programmatordev.stripe-checkout.currency'),
    'cartSnippet' => option('programmatordev.stripe-checkout.cartSnippet')
]);
```

Available methods:

- [addItem](#additem)
- [updateItem](#updateitem)
- [removeItem](#removeitem)
- [items](#items)
- [totalQuantity](#totalquantity)
- [totalAmount](#totalamount)
- [currency](#currency-1)
- [currencySymbol](#currencysymbol)
- [cartSnippet](#getcartsnippet)
- [destroy](#destroy)
- [toArray](#toarray)

#### `addItem`

```php
addItem(string $id, int $quantity, ?array $options = null): string
```

Adds an item to the cart.

The `id` and `quantity` values are required.
An additional `options` value is available to set the options of a product (color, size, etc.).

Important to note that the `id` must be a valid Kirby page id and the page must include a valid `price` field.
Otherwise, an exception will be thrown.
Check the [Setup](#setup) section for more information.

Information related to the `price`, `name` and `thumbnail` are added to the item based on the given `id` (and related Kirby page).

If the item that is being added already exists in the cart, the sum of its quantities will be made into a single item.

If the same item is added but with different options, it will be considered different items in the cart.
For example, a t-shirt with the color blue and the same t-shirt with the color red will be different items.

A `key` is returned that uniquely identifies the item in the cart.

```php
$cart = cart();

// a key is returned and uniquely identifies that item in the cart
$key = $cart->addItem(id: 'products/cd', quantity: 1);

// you can add options per item
$key = $cart->addItem(
    id: 'products/t-shirt',
    quantity: 1,
    options: ['Color' => 'Green', 'Size' => 'Medium']
);
```

#### `updateItem`

```php
updateItem(string $key, int $quantity): void
```

Updates the `quantity` of an item in the cart.

```php
$cart = cart();

$key = $cart->addItem(id: 'products/cd', quantity: 1);

$cart->updateItem(key: $key, quantity: 3);
```

#### `removeItem`

```php
removeItem(string $key): void
```

Removes an item from the cart.

```php
$cart = cart();

$key = $cart->addItem(id: 'products/cd', quantity: 1);

$cart->removeItem($key);
```

#### `items`

```php
use Kirby\Toolkit\Collection;
use ProgrammatorDev\StripeCheckout\Cart\Item

/** @return Collection<string, Item> */
items(): Collection
```

Collection with all items in the cart.

```php
use ProgrammatorDev\StripeCheckout\Cart\Item;

$cart = cart();
$items = $cart->items();

/** @var Item $item */
foreach ($items as $key => $item) {
    $item->id();
    $item->quantity()
    $item->options();
    $item->productPage();
    $item->name();
    $item->price();
    $item->totalAmount();
    $item->thumbnail();
}
```

#### `totalQuantity`

```php
totalQuantity(): int
```

Get the total quantity of items in the cart.

```php
$cart = cart();
echo $cart->totalQuantity(); // 3
```

#### `totalAmount`

```php
totalAmount(): int|float
```

Get the total amount in the cart.

```php
$cart = cart();
echo $cart->totalAmount(); // 100
```

#### `currency`

```php
currency(): string
```

Get currency.

```php
$cart = cart();
echo $cart->currency(); // EUR
```

#### `currencySymbol`

```php
currencySymbol(): string
```

Get currency symbol.

```php
$cart = cart();
echo $cart->currencySymbol(); // €
```

#### `cartSnippet`

```php
cartSnippet(bool $render = false): ?string
```

Get cart snippet if set in the [`cartSnippet`](#cartsnippet) option.

if `render` is set to `true` it will return the rendered HTML snippet

```php
$cart = cart();
echo $cart->cartSnippet(render: false); // snippet name or null
echo $cart->cartSnippet(render: true); // rendered snippet HTML
```

#### `destroy`

```php
destroy(): void
```

Destroy all contents and reset the cart to the initial state.

```php
$cart = cart();
$cart->destroy();
```

#### `toArray`

```php
toArray(bool $includeCurrency = true): array
```

Converts all cart contents into an array.


```php
$cart = cart();
$cart->toArray();
```

### JavaScript

A JavaScript library is currently being developed.
Meanwhile, check the [API endpoints](#api-endpoints) section below for examples on how to use with JavaScript.

### API endpoints

Endpoints are available to help manage the cart system in the frontend.
You can make requests to these to add, update and remove items, get the cart contents or its snippet.

All successful responses will have the following structure:

```json
{
  "status": "ok",
  "data": {
    "items": [
      {
        "key": "key1",
        "id": "products/item-1",
        "name": "Item 1",
        "price": 10,
        "quantity": 2,
        "totalAmount": 20,
        "options": null,
        "thumbnail": null
      },
      {
        "key": "key2",
        "id": "products/item-2",
        "name": "Item 2",
        "price": 10,
        "quantity": 1,
        "subtotal": 10,
        "options": {
          "name": "value"
        },
        "thumbnail": "https://path.com/to/image.jpg"
      }
    ],
    "totalAmount": 30,
    "totalQuantity": 3,
    "currency": "EUR",
    "currencySymbol": "€"
  },
  "snippet": null
}
```

In case of error:

```json
{
  "status": "error",
  "message": "Product does not exist."
}
```

### `GET /api/cart`

Get cart contents.

```js
const response = await fetch('api/cart', {
  method: "GET"
});
```

### `POST /api/cart/items`

Adds item to the cart.

```js
const response = await fetch('/api/cart/items', {
  method: 'POST',
  body: JSON.stringify({
    id: 'products/item',
    quantity: 1,
    // optional
    options: {
      'Size': 'Medium'
    }
  })
});
```

### `PATCH /api/cart/items/:key`

Updates item in the cart.

```js
const key = 'key-hash';
const response = await fetch(`/api/cart/items/${key}`, {
  method: 'PATCH',
  body: JSON.stringify({
    quantity: 1
  })
});
```

### `DELETE /api/cart/items/:key`

Removes item from the cart.

```js
const key = 'key-hash';
const response = await fetch(`/api/cart/items/${key}`, {
  method: 'DELETE'
});
```

### `GET /api/cart/snippet`

Get cart snippet.

```js
const response = await fetch('/api/cart/snippet', {
  method: 'GET'
});
```

Response:

```json
{
  "status": "ok",
  "snippet": "<div> <!-- HTML content --> </div>"
}
```

## Translations

Currently, this plugin is only available in English and Portuguese (Portugal).

If you want to add a new translation, check the [`translations`](#translations) option in the [`Options`](#options) section.

If you want to contribute with a translation (to be bundled with the plugin), go to the `translations` directory and create a new YAML file named with the locale that you wish to translate.
For example, if you want to add the German translation, create a `de.yml` file.

It will be very appreciated if you can contribute by making a pull request with the translation you wish to add.

## Setup

Below are the steps required to set up a Stripe Checkout online shop, both in `hosted` and `embedded` mode.

> [!TIP]
> It is recommended that you use a library that enables environment variables
> to store your project credentials in a separate place from your code
> and to have separate development and production access keys.

Considering that you already have a Stripe account:

### Step 1.

Grab your public and secret keys from the Stripe Dashboard
and add them to the [`stripePublicKey`](#stripepublickey) and [`stripeSecretKey`](#stripesecretkey) options.

> [!IMPORTANT]
> Make sure to grab the test keys when in development mode,
> and only use the production keys when the website is live.

### Step 2.

Create a webhook to listen to Stripe Checkout events.

When creating a webhook in the Stripe Dashboard (should be in the Developers page),
make sure to select the following Checkout events; otherwise it will not work correctly:
- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`

The endpoint URL must be set to the following: `https://yourdomain.com/stripe/checkout/webhook`.
This is, your base URL followed by `/stripe/checkout/webhook`.

When the webhook is created, grab its secret key and add it to the [`stripeWebhookSecret`](#stripewebhooksecret) option.

> [!IMPORTANT]
> The webhook will not work properly when developing locally,
> since the request cannot reach a local endpoint that only exists on your computer.
> Check the [Development](#development) section for more information on how to work with webhooks in development.

### Step 3.

For the panel, you need to create a `orders` and a `order` blueprint.
You can change the `orders` name with the [`ordersPage`](#orderspage) option.

```yaml
# blueprints/pages/orders.yml

extends: stripe-checkout.pages/orders
```

```yaml
# blueprints/pages/order.yml

extends: stripe-checkout.pages/order
```

> [!NOTE]
> Remember to create a `orders` directory at `/content` with a `orders.txt` file.
> Otherwise, the page will not be found.

### Step 4 (optional).

Similar to the previous step, create a `checkout-settings` blueprint.
You can change the `checkout-settings` name with the [`settingsPage`](#settingspage) option.

Currently, the only existing settings are to manage shipping data, like allowed countries and shipping rates.
If you don't need this information for your website (for example, if you are selling digital assets, where shipping information is not needed),
you can skip this step.

```yaml
# blueprints/pages/checkout-settings.yml

extends: stripe-checkout.pages/checkout-settings
```

### Step 5.

You can create a product blueprint with any name.

Make sure that you have a `price` field (it is required).
To add an image, add a `thumbnail` field (it is optional).

The plugin already comes with both blueprints fields, in case you want to use them:

```yaml
# blueprints/pages/product.yml

title: Product

fields:
  price: stripe-checkout.fields/price
  thumbnail: stripe-checkout.fields/thumbnail # optional
```

### `hosted` versus `embedded` mode

Depending on the mode you are using, jump to the respective step below:
- [Step 6: `hosted` mode](#step-6-hosted-mode)
- [Step 6: `embedded` mode](#step-6-embedded-mode)

For more information about the difference between both modes, check the [`uiMode`](#uimode) option.

### Step 6: `hosted` mode.

When in `hosted` mode, you need to add a link to the website
with the URL generated by the following method `stripeCheckout()->checkoutUrl()`.

This link usually exists in the cart component or when reviewing the order before proceeding to the checkout.

Something like:

```html
<div>
  <!-- content with the order review and/or cart items -->
  <!-- ... -->

  <a href="/shop">Continue shopping</a>
  <a href="<?= stripeCheckout()->checkoutUrl() ?>">Proceed to checkout</a>
</div>
```

Make sure to have at least one item added to the cart (check the [`Cart`](#cart) section) or it will throw an error.

It is also required to set the [`successPage`](#successpage) and the [`cancelPage`](#cancelpage) options.

### Step 6: `embedded` mode.

When in `embedded` mode, you need to use the [Stripe.js](https://docs.stripe.com/js) library
as well as the following method `stripeCheckout()->checkoutEmbeddedUrl()`.

You have to create your own checkout page.

Something like:

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Checkout Page</title>

    <!-- it is recommended by Stripe to include the script on all website pages to improve fraud detection -->
    <!-- https://docs.stripe.com/js/including -->
    <script src="https://js.stripe.com/v3/"></script>

    <script defer>
      // to initialize Stripe using the stripeCheckout()->stripePublicKey() method
      // you can also use option('programmatordev.stripe-checkout.stripePublicKey')
      const stripe = Stripe('<?= stripeCheckout()->stripePublicKey() ?>');

      async function initialize() {
        const fetchClientSecret = async () => {
          // use the stripeCheckout()->checkoutEmbeddedUrl() method to fetch the client secret
          // make sure it is a POST request
          const response = await fetch('<?= stripeCheckout()->checkoutEmbeddedUrl() ?>', { method: 'POST' });
          const { clientSecret } = await response.json();

          return clientSecret;
        };

        const checkout = await stripe.initEmbeddedCheckout({ fetchClientSecret });

        checkout.mount('#checkout-form');
      }

      initialize();
    </script>
</head>

<body>
    <div id="checkout-form"></div>
</body>
</html>
```

Make sure to have at least one item added to the cart (check the [`Cart`](#cart) section) or it will throw an error.

It is also required to set the [`returnPage`](#returnpage) option.

## Development

### Webhook

#### Listen to events

Stripe webhooks will not work properly when developing locally,
since the request cannot reach a local endpoint that only exists on your computer.

To solve this, Stripe has a [CLI](https://docs.stripe.com/stripe-cli) that you can install on your computer.
Follow the instructions in their [Stripe CLI](https://docs.stripe.com/stripe-cli) documentation page before proceeding.

To forward the events to a local endpoint, use the following command
(you can also check their [documentation](https://docs.stripe.com/webhooks#test-webhook) for more information):

```bash
stripe listen --forward-to https://yourlocaldomain.com/stripe/checkout/webhook \
  --events checkout.session.completed,checkout.session.async_payment_succeeded,checkout.session.async_payment_failed
```

This command will forward the events `checkout.session.completed`, `checkout.session.async_payment_succeeded`
and `checkout.session.async_payment_failed` to the `https://yourlocaldomain.com/stripe/checkout/webhook` endpoint.

The endpoint must always be your local base URL followed by `/stripe/checkout/webhook`.

After running the command, it will show you the webhook secret.
Add this secret to the [`stripeWebhookSecret`](#stripewebhooksecret) option.

Now, if you submit the Stripe Checkout form (in `hosted` or `embedded` mode), it will be able to listen to the events.

#### Trigger events

If you want to trigger the events without the need to submit the form every time, you can use the following command
(make sure to open another terminal window, do not close the window where you ran the `listen` command):

```bash
stripe trigger checkout.session.async_payment_succeeded --add checkout_session:metadata.order_id=xxxxxx
```

This command will trigger the `checkout.session.async_payment_succeeded` event (you can trigger any event).
Make sure to always include the `--add checkout_session:metadata.order_id=xxxxxx`.

This is required because the plugin needs to share the Kirby order id across all events (to be in sync).
You can set any `order_id` value, as long as it is alphanumeric.

## Production

Make sure to change the [`stripePublicKey`](#stripepublickey), [`stripeSecretKey`](#stripesecretkey)
and [`stripeWebhookSecret`](#stripewebhooksecret) options for their respective live values.

> [!TIP]
> It is recommended that you use a library that enables environment variables
> to store your project credentials in a separate place from your code
> and to have separate development and production access keys.

## Contributing

Any form of contribution to improve this library (including requests) will be welcome and appreciated.
Make sure to open a pull request or issue.

## License

This project is licensed under the MIT license.
Please see the [LICENSE](LICENSE) file distributed with this source code for further information regarding copyright and licensing.
