# Kirby Stripe Checkout

[Stripe Checkout](https://stripe.com/en-pt/payments/checkout) for [Kirby CMS](https://getkirby.com).

> [!IMPORTANT]
> This plugin is still in its early stages.
> This means that it should not be considered stable even though it is currently being used in production.
> Expect some breaking changes until version `1.0`.

## Features

- Stripe Checkout for both hosted and embedded UI modes;
- Handles instant and async payments (credit card, bank transfer, etc.);
- Orders panel page. Overview of all orders and respective data (customer, line items, shipping, billing, etc.);
- Checkout Settings panel page. Currently only able to manage shipping settings (allowed countries, shipping rates, etc.);
- Events for all payments status (completed, pending and failed), order creation, Checkout session creation and so on;
- Cart management;
- ...and more.

## Documentation

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

### `stripePublicKey`

type: `string` `required`

Stripe public key found on the Stripe Dashboard.

### `stripeSecretKey`

type: `string` `required`

Stripe secret key found on the Stripe Dashboard.

### `stripeWebhookSecret`

type: `string` `required`

Webhook secret found when creating a Webhook in the Stripe Dashboard.

Check the [Setup](#setup) section for more information.

### `currency`

type: `string` default: `EUR` `required`

Three-letter [ISO currency code](https://www.iso.org/iso-4217-currency-codes.html).
Must be a [supported currency](https://stripe.com/docs/currencies).

### `uiMode`

type: `string` default: `hosted` `required`

The UI mode of the Checkout Session.

Available options:
- `hosted` the Checkout Session will be displayed on a hosted page that users will be redirected to;
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

return [
    'hooks' => [
        'stripe-checkout.order.created:before' => function (array $orderContent, \Stripe\Checkout\Session $checkoutSession): array
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

Useful, for example, to email the costumer about the order.

```php
// config.php

return [
    'hooks' => [
        'stripe-checkout.payment:succeeded' => function (\Kirby\Cms\Page $orderPage, \Stripe\Checkout\Session $checkoutSession): void
        {
            kirby()->email([
                'from' => 'orders@myshop.com',
                'to' => $orderPage->customer()->toObject()->email()->value(),
                'subject' => 'Order success!',
                'body' => 'Order will be processed soon.',
            ]);
        }
    ]
];
```

> [!WARNING]
> Take into account that the `orderContent` variable contains all data required to create an Order page.
> You may change these but at your own risk.

### `stripe-checkout.payment:pending`

### `stripe-checkout.payment:failed`

### `stripe-checkout.cart.addItem:before`

## Site Methods

## Cart

## Translations

## Setup

## Development

*Add instructions on how to help working on the plugin (e.g. npm setup, Composer dev dependencies, etc.)*

## Production

## Contributing

Any form of contribution to improve this library (including requests) will be welcome and appreciated.
Make sure to open a pull request or issue.

## License

This project is licensed under the MIT license.
Please see the [LICENSE](LICENSE) file distributed with this source code for further information regarding copyright and licensing.
