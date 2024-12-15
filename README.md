# Kirby Stripe Checkout

[Stripe Checkout](https://stripe.com/en-pt/payments/checkout) for [Kirby CMS](https://getkirby.com).

> [!IMPORTANT]
> This plugin is still in its early stages.
> This means that it should not be considered stable, so use it at your own risk.
> Expect some breaking changes until version `1.0`.

## Features

- 🔥 Stripe Checkout for both `hosted` and `embedded` modes;
- 💸 Handles sync and async payments (credit card, bank transfer, etc.);
- 📦 Orders panel page;
- ⚙️ Checkout Settings panel page;
- 🪝 Hooks for all payments status (completed, pending and failed), orders, Checkout sessions, and more;
- 🛒 Cart management;
- ...and more.

## Documentation

- [Requirements](#requirements)
- [Installation](#installation)
- [Options](#options)
- [Hooks](#hooks)
- [Site methods](#site-methods)
- [Cart](#cart)
- [Translations](#translations)
- [Setup](#setup)
- [Development](#development)
- [Production](#production)

## Requirements

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
        'settingsPage' => 'checkout-settings'
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

## Hooks

- [stripe-checkout.session.created:before](#stripe-checkoutsessioncreatedbefore)
- [stripe-checkout.order.created:before](#stripe-checkoutordercreatedbefore)
- [stripe-checkout.payment:succeeded](#stripe-checkoutpaymentsucceeded)
- [stripe-checkout.payment:pending](#stripe-checkoutpaymentpending)
- [stripe-checkout.payment:failed](#stripe-checkoutpaymentfailed)
- [stripe-checkout.cart.addItem:before](#stripe-checkoutcartadditembefore)

### `stripe-checkout.session.created:before`

Triggered before creating a Checkout Session.
Useful to set additional Checkout Session parameters.

You can check all the available parameters in the Stripe API [documentation page](https://docs.stripe.com/api/checkout/sessions/create?lang=php).

```php
// config.php

return [
    'hooks' => [
        'stripe-checkout.session.created:before' => function (array $sessionParams): array
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

### `stripe-checkout.order.created:before`

Triggered before creating an Order page in the Panel.
Useful to set additional Order data in case you add additional fields in the blueprint or want to change existing ones.

```php
// config.php

use Stripe\Checkout\Session;

return [
    'hooks' => [
        'stripe-checkout.order.created:before' => function (array $orderContent, Session $checkoutSession): array
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

return [
    'hooks' => [
        'stripe-checkout.payment:succeeded' => function (Page $orderPage, Session $checkoutSession): void
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

return [
    'hooks' => [
        'stripe-checkout.payment:pending' => function (Page $orderPage, Session $checkoutSession): void
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

return [
    'hooks' => [
        'stripe-checkout.payment:failed' => function (Page $orderPage, Session $checkoutSession): void
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

### `stripe-checkout.cart.addItem:before`

Triggered before adding an item to the cart.

Check the [Cart](#cart) section for more information.

```php
// config.php

use Kirby\Cms\Page;

return [
    'hooks' => [
        'stripe-checkout.cart.addItem:before' => function (array $itemContent, Page $productPage): array
        {
            // change cart item content
            // ...

            return $itemContent;
        }
    ]
];
```

> [!WARNING]
> Take into account that the `itemContent` variable contains data required to add an item to the cart.
> You may change these but at your own risk.

## Site methods

List of all available site helper methods, used with the `site()` function or in blueprints with `{{ site }}`:

- [stripeCheckoutUrl](#stripecheckouturl)
- [stripeCheckoutEmbeddedUrl](#stripecheckoutembeddedurl)
- [stripeCurrencySymbol](#stripecurrencysymbol)
- [stripeCountriesUrl](#stripecountriesurl)

### `stripeCheckoutUrl`

```php
stripeCheckoutUrl(): string
```

URL that handles the Checkout Session and redirects the customer when `uiMode` is `hosted`.

Check the [Setup](#setup) section for more information.

```php
site()->stripeCheckoutUrl();
```

### `stripeCheckoutEmbeddedUrl`

```php
stripeCheckoutEmbeddedUrl(): string
```

URL that handles the Checkout Session and fetches the client secret when `uiMode` is `embedded`.

Check the [Setup](#setup) section for more information.

```php
site()->stripeCheckoutEmbeddedUrl();
```

### `stripeCurrencySymbol`

```php
stripeCurrencySymbol(): string
```

Get the configured currency symbol.
The symbol is obtained based on the [`currency`](#currency) option.

```php
// if currency is "EUR", it will return "€"
site()->stripeCurrencySymbol();
```

### `stripeCountriesUrl`

```php
stripeCountriesUrl(?string $locale = null): string
```

URL to get all Stripe supported countries in JSON format.

```php
// will return the URL to get all supported countries
site()->stripeCountriesUrl();

// you can also set the locale to generate the URL for a specific locale
site()->stripeCountriesUrl('pt_PT');
```

## Cart

A cart management system already exists and is required to be able to create a Checkout Session.
The reason for this is that the checkout line items are generated based on the current cart contents.
This means that the cart must have at least one added item, otherwise it will throw an error.

### PHP

A `cart()` function is available to manage the cart contents.

```php
use ProgrammatorDev\StripeCheckout\Cart;

cart(): Cart
```

Default options:

```php
cart([
    // the same as configured in the plugin options
    'currency' => option('programmatordev.stripe-checkout.currency')
]);
```

Available methods:

- [addItem](#additem)
- [updateItem](#updateitem)
- [removeItem](#removeitem)
- [getItems](#getitems)
- [getTotalQuantity](#gettotalquantity)
- [getTotalAmount](#gettotalamount)
- [getTotalAmountFormatted](#gettotalamountformatted)
- [getContents](#getcontents)
- [destroy](#destroy)

#### `addItem`

```php
addItem(array $data): string
```

Adds an item to the cart.

The `id` and `quantity` values are required.
An additional `options` value is available to set the options of a product (color, size, etc.).

Important to note that the `id` must be a valid Kirby page id and the page must include a valid `price` field.
Otherwise, an exception will be thrown.
Check the [Setup](#setup) section for more information.

Information related to the `price`, `name` and `image` are added to the item based on the given `id` (and related Kirby page).

If the item that is being added already exists in the cart, the sum of its quantities will be made into a single item.

If the same item is added but with different options, it will be considered different items in the cart.
For example, a t-shirt with color blue, and the same t-shirt with color red will be different items.

A line item id is returned that uniquely identifies the item in the cart.

```php
$cart = cart();

// a line item id is returned and uniquely identifies that item in the cart
$lineItemId = $cart->addItem([
    'id' => 'products/cd',
    'quantity' => 1
]);

// you can add options per item
$lineItemId = $cart->addItem([
    'id' => 'products/t-shirt',
    'quantity' => 1,
    'options' => [
        'Color' => 'Green',
        'Size' => 'Medium'
    ]
]);
```

#### `updateItem`

```php
updateItem(string $lineItemId, array $data): void
```

Updates and item in the cart.

Currently, it is only possible to update the `quantity` of the item in the cart.

```php
$cart = cart();

$lineItemId = $cart->addItem([
    'id' => 'products/cd',
    'quantity' => 2
]);

$cart->updateItem($lineItemId, [
    'quantity' => 1
]);
```

#### `removeItem`

```php
removeItem(string $lineItemId): void
```

Removes an item from the cart.

```php
$cart = cart();

$lineItemId = $cart->addItem([
    'id' => 'products/cd',
    'quantity' => 1
]);

$cart->removeItem($lineItemId);
```

#### `getItems`

```php
getItems(): array
```

Get all items in the cart.

```php
$cart = cart();
$items = $cart->getItems();

foreach ($items as $lineItemId => $item) {
    print_r($item);
    // Array(
    //  'id' => 'products/item',
    //  'image' => 'https://path.com/to/image.jpg',
    //  'name' => 'Item',
    //  'price' => 10,
    //  'quantity' => 2,
    //  'subtotal' => 20,
    //  'options' => null,
    //  'priceFormatted' => '€ 10.00',
    //  'subtotalFormatted' => '€ 20.00'
    // )
}
```

#### `getTotalQuantity`

```php
getTotalQuantity(): int
```

Get total quantity of items in the cart.

```php
$cart = cart();
echo $cart->getTotalQuantity(); // 3
```

#### `getTotalAmount`

```php
getTotalAmount(): int|float
```

Get total amount in the cart.

```php
$cart = cart();
echo $cart->getTotalAmount(); // 100
```

#### `getTotalAmountFormatted`

```php
getTotalAmountFormatted(): string
```

Get total amount in the cart formatted according to currency.

```php
$cart = cart();
echo $cart->getTotalAmountFormatted(); // € 100.00
```

#### `getContents`

```php
getContents(): array
```

Get all contents and related data from the cart.

```php
$cart = cart();

print_r($cart->getContents());
// Array(
//  'items' => Array(
//    'line-item-id-hash' => Array(
//      'id' => 'products/item',
//      'image' => 'https://path.com/to/image.jpg',
//      'name' => 'Item',
//      'price' => 10,
//      'quantity' => 2,
//      'subtotal' => 20,
//      'options' => null,
//      'priceFormatted' => '€ 10.00',
//      'subtotalFormatted' => '€ 20.00'
//    )
//  )
//  'totalAmount' => 20,
//  'totalQuantity' => 2,
//  'totalAmountFormatted' => '€ 20.00'
// )
```

#### `destroy`

```php
destroy(): void
```

Destroy all contents and reset cart to initial state.

```php
$cart = cart();
$cart->destroy();
```

### JavaScript

A JavaScript library is currently being developed.
Meanwhile, check the [API endpoints](#api-endpoints) section below for examples on how to use with JavaScript.

### API endpoints

Endpoints are available to help manage the cart system in the frontend.
You can make requests to these to add, update and remove items and get the cart contents.

All successful responses will have the following structure:

```json
{
  "status": "ok",
  "data": {
    "items": {
      "line-item-id-1": {
        "id": "products/item-1",
        "image": "https://path.com/to/image.jpg",
        "name": "Item 1",
        "price": 10,
        "quantity": 2,
        "subtotal": 20,
        "options": null,
        "priceFormatted": "€ 10.00",
        "subtotalFormatted": "€ 20.00"
      },
      "line-item-id-2": {
        "id": "products/item-2",
        "image": "https://path.com/to/image.jpg",
        "name": "Item 2",
        "price": 10,
        "quantity": 1,
        "subtotal": 10,
        "options": null,
        "priceFormatted": "€ 10.00",
        "subtotalFormatted": "€ 10.00"
      }
    },
    "totalAmount": 30,
    "totalQuantity": 3,
    "totalAmountFormatted": "€ 30.00"
  }
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
const response = await fetch('api/cart/items', {
  method: "POST",
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

### `PATCH /api/cart/items/:lineItemId`

Updates item in the cart.

```js
const lineItemId = 'line-item-id-hash';
const response = await fetch('api/cart/items/' + lineItemId, {
  method: "PATCH",
  body: JSON.stringify({
    quantity: 1
  })
});
```

### `DELETE /api/cart/items/:lineItemId`

Removes item from the cart.

```js
const lineItemId = 'line-item-id-hash';
const response = await fetch('api/cart/items/' + lineItemId, {
  method: "DELETE"
});
```

## Translations

Currently, this plugin is only available in English and Portuguese (Portugal).

If you want to add a new language, go to the `translations` directory and create a new YAML file named with the locale that you wish to translate.
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
make sure to select the following Checkout events, otherwise it will not work correctly:
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

Just make sure that you have a `price` field (it is required).
To add an image, add a `cover` field (it is optional).

The plugin already comes with both blueprints fields, in case you want to use them:

```yaml
# blueprints/pages/product.yml

title: Product

fields:
  price: stripe-checkout.fields/price
  # optional
  cover: stripe-checkout.fields/cover
```

### `hosted` versus `embedded` mode

Depending on the mode you are using, jump to the respective step bellow:
- [Step 6: `hosted` mode](#step-6-hosted-mode)
- [Step 6: `embedded` mode](#step-6-embedded-mode)

For more information about the difference between both modes, check the [`uiMode`](#uimode) option.

### Step 6: `hosted` mode.

When in `hosted` mode, you just need to add a link to the website
with the URL generated by the site method [`stripeCheckoutUrl()`](#stripecheckouturl).

This link usually exists in the cart component or when reviewing the order before proceeding to the checkout.

Something like:

```html
<div>
  <!-- content with the order review and/or cart items -->
  <!-- ... -->

  <a href="/shop">Continue shopping</a>
  <a href="<?= $site->stripeCheckoutUrl() ?>">Proceed to checkout</a>
</div>
```

Just make sure to have at least one item added to the cart (check the [`Cart`](#cart) section) or it will throw an error.

It is also required to set the [`successPage`](#successpage) and the [`cancelPage`](#cancelpage) options.

### Step 6: `embedded` mode.

When in `embedded` mode, you need to use the [Stripe.js](https://docs.stripe.com/js) library
as well as the site method [`stripeCheckoutEmbeddedUrl()`](#stripecheckoutembeddedurl).

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
      // initialize Stripe using the stripePublicKey option
      const stripe = Stripe('<?= option('programmatordev.stripe-checkout.stripePublicKey') ?>');

      async function initialize() {
        const fetchClientSecret = async () => {
          // use the stripeCheckoutEmbeddedUrl() method to fetch the client secret
          // make sure it is a POST request
          const response = await fetch('<?= $site->stripeCheckoutEmbeddedUrl() ?>', { method: 'POST' });
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

Just make sure to have at least one item added to the cart (check the [`Cart`](#cart) section) or it will throw an error.

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
Just make sure to always include the `--add checkout_session:metadata.order_id=xxxxxx`.

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
