# Checkout Session requests

The plugin builds the Stripe Checkout Session request from trusted product, order, and Settings values. The standard request is the recommended path: it fixes the store currency, presentation mode, internal return routes, and order correlation while leaving payment-method selection to the Stripe Dashboard.

The current package contains the service-level creation pipeline, but does not yet expose the public Checkout creation route. The examples below customize the request that pipeline will use once the browser route calls it; registering them alone does not create a Session.

## Creation and safe retries

The pipeline validates products, prices, configuration, and the complete request before creating anything. It then:

1. Creates one protected Kirby order in `creating` state.
2. Saves the exact Session request, its fingerprint, the pinned Stripe API version, and a UUID-derived idempotency key.
3. Releases local locks before sending the request to Stripe.
4. Saves the returned Session ID and changes the order to `open`.
5. Returns either the hosted Checkout URL or the embedded client secret for the current response only.

Before submission, the plugin generates an opaque attempt token containing a Kirby-generated future order UUID and an independent random nonce. The UUID lets a retry locate the Order Page directly; the nonce prevents the public order UUID from being the complete retry token. Only the hash of the complete token is stored. Repeating that exact token locates the order by its UUID, then verifies the complete token hash and initiating context before reusing it.

The hosted URL and client secret are not stored either. An uncertain request may be sent again only with the exact saved request and key and only within the internal 23-hour deadline; request customization is not run again. A definite provider rejection becomes `creation_failed`, while a network or incompatible-response uncertainty becomes `creation_uncertain` for later diagnosis or recovery.

## Add safe parameters

For a small project-specific addition, register Kirby's namespaced apply-filter:

```php
<?php

use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;

return [
    'hooks' => [
        'programmatordev.stripe-checkout.session.parameters' => function (
            array $parameters,
            SessionRequestContext $context,
        ): array {
            $metadata = $parameters['metadata'] ?? [];
            $paymentIntentData = $parameters['payment_intent_data'] ?? [];

            return [
                ...$parameters,
                'metadata' => [
                    ...$metadata,
                    'sales_channel' => 'website',
                ],
                'payment_intent_data' => [
                    ...$paymentIntentData,
                    'description' => 'Order ' . $context->order()->orderNumber(),
                ],
            ];
        },
    ],
];
```

Kirby passes the result from one matching handler to the next. Each handler must therefore accept and return the complete accumulated `$parameters` map. Keep the named arguments as `$parameters` and `$context`; Kirby resolves hook arguments by name.

The additive filter currently accepts:

- string project metadata under `metadata` and `payment_intent_data.metadata`, outside the private `kirby_stripe_checkout_*` namespace and within [Stripe's metadata limits](https://docs.stripe.com/metadata#configuration-data);
- `payment_intent_data.description`;
- `payment_intent_data.receipt_email`;
- `payment_intent_data.statement_descriptor_suffix`.

An addition cannot repeat a built-in path, even with the same value. Other paths are rejected instead of being treated as implicitly supported when stripe-php adds a parameter. Use typed plugin Settings for supported Checkout features as they become available.

Setting `payment_intent_data.receipt_email` makes Stripe send a live-mode receipt regardless of the account's normal email setting. Add it only when that behavior is intentional.

## Customize advanced construction

Projects that genuinely need a complete uncommon one-time request can configure the PHP-only `checkout.sessionRequestFactory` option. It accepts a `SessionRequestFactoryInterface` implementation or a Closure with the same signature:

```php
<?php

use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;

return [
    'programmatordev.stripe-checkout' => [
        'checkout' => [
            'sessionRequestFactory' => function (
                SessionRequestContext $context,
                SessionRequest $request,
            ): SessionRequest {
                $parameters = $request->parameters();
                $parameters['branding_settings'] = [
                    'display_name' => 'Example Store',
                ];

                return new SessionRequest($parameters);
            },
        ],
    ],
];
```

The factory receives the immutable resolved context and the complete accumulated request. The request contains the plugin's standard parameters plus any parameters returned by the additive filter. The factory returns a new complete `SessionRequest`; it does not receive Stripe credentials, a client, a mutable order Page, or responsibility for making the API call.

Customization runs in a fixed order: the plugin builds its standard request, applies all matching additive filters sequentially, invokes the configured factory with that result, and validates the final request. This lets integrations contribute safe additions while leaving the project-level factory with visibility and final control over those additions.

## Mandatory safety rules

Every result passes the same final validation before an order or Stripe Session can be created. Advanced construction must preserve:

- one-time `payment` mode and the configured hosted or embedded UI mode;
- the exact store currency, fixed lifetime, locale, and installation identifier;
- the plugin's internal success/cancel/return routes and literal `{CHECKOUT_SESSION_ID}` placeholder;
- the order reference and private Session, PaymentIntent, and line-item metadata;
- the original line count, quantities, price source, Price IDs or inline amounts, and currencies.

The validator rejects subscriptions and setup mode, Connect transfers or fees, manual capture, saved/future payment methods, adjustable quantities, optional items, customer mapping, Adaptive Pricing, managed payments, and explicit payment-method lists. These restrictions preserve the order lifecycle and keep payment-method configuration in Stripe. A project that needs to replace those guarantees needs its own integration rather than this extension point.

`SessionRequest` deliberately contains only Stripe-shaped scalar, list, and map data. It normalizes map order and exposes a stable `fingerprint()` without coupling project code to stripe-php request objects.
