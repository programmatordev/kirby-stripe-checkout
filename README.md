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

## Requirements

- Kirby CMS `4.0` or higher;
- [Stripe account](https://dashboard.stripe.com/register).

## Installation

Install the plugin via [Composer](https://getcomposer.org/):

```bash
composer require programmatordev/kirby-stripe-checkout
```

## Options

*Document the options and APIs that this plugin offers*

## Setup

More complex plugins are not always easy to set up in Kirby.
This is one of those plugins.

> [!NOTE]
> A Kirby Stripe Checkout Starter repository will be created in the future with a ready-to-use setup.

Important to note that these steps take into account a setup for a project in **production**.
Notes are available for **development** when necessary.

Before starting, make sure that you already have a [Stripe account](#requirements).

`STEP 1`

Grab your Stripe Public Key and Secret Key from the Stripe Dashboard.
These are required in the

## Events

## Site Methods

## Cart

## Translations

## Development

*Add instructions on how to help working on the plugin (e.g. npm setup, Composer dev dependencies, etc.)*

## Production

## Contributing

Any form of contribution to improve this library (including requests) will be welcome and appreciated.
Make sure to open a pull request or issue.

## License

This project is licensed under the MIT license.
Please see the [LICENSE](LICENSE) file distributed with this source code for further information regarding copyright and licensing.
