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

## Customize the request

Register Kirby's namespaced apply-filter when one Checkout attempt needs values that are not available from Settings, or when project logic needs to change a Settings-owned value:

```php
<?php

use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestContext;

return [
    'hooks' => [
        'programmatordev.stripe-checkout.session.parameters' => function (
            array $parameters,
            SessionRequestContext $context,
        ): array {
            $parameters['billing_address_collection'] = 'required';
            $parameters['metadata']['sales_channel'] = 'website';
            $parameters['payment_intent_data']['description'] =
                'Order ' . $context->order()->orderNumber();

            return $parameters;
        },
    ],
];
```

The filter receives the complete standard request, not an empty additions map. It can add a Stripe parameter, remove an optional parameter, or replace a Settings-owned value for this attempt. Kirby passes each handler's result to the next matching handler, so every handler must return the complete `$parameters` map. Keep the argument names as `$parameters` and `$context`; Kirby resolves hook arguments by name.

The immutable `SessionRequestContext` contains information known before the customer enters Checkout: the order snapshot, language and Stripe locale, initiating URL, destinations, expiry, and UI mode. It cannot contain an address or other value first entered on Stripe's page. Location-dependent logic must therefore use information collected before Checkout or wait for the later Stripe result.

The final request is stored on the Order exactly as submitted. Do not place secrets, payment credentials, or unnecessary personal data in it. If an uncertain Stripe call is retried, the plugin reuses that saved request unchanged and does not run the filter again.

## Validation boundaries

Every filter result passes final validation before an order or Stripe Session can be created. The validator has three responsibilities:

1. Parameters the plugin officially supports through Settings are fully validated when present. This currently covers billing-address, name, phone, tax-ID and consent collection, custom fields, and promotion-code entry.
2. Values required by the order and payment lifecycle are protected from changes.
3. Other serializable Stripe parameters pass through without the plugin duplicating Stripe's semantic validation. A malformed or incompatible provider-owned value is rejected by Stripe and the Order records a safe `creation_failed` outcome.

The protected lifecycle values are:

- one-time `payment` mode and the configured hosted or embedded UI mode;
- the exact store currency, fixed lifetime, locale, and installation identifier;
- the plugin's internal success/cancel/return routes and literal `{CHECKOUT_SESSION_ID}` placeholder;
- the order reference and private Session, PaymentIntent, and line-item metadata;
- the original line count, quantities, price source, Price IDs or inline amounts, and currencies.

The validator also rejects lifecycle shapes the current order model cannot represent: subscriptions and setup mode, Connect transfers or fees, manual capture, saved/future payment methods, adjustable quantities, optional items, Adaptive Pricing, Managed Payments, recovery Sessions, and server-controlled dynamic shipping updates. Provider hints such as `origin_context`, explicit customer references, payment-method configuration, discounts, invoice creation, and other Stripe-owned parameters are allowed when they do not change those guarantees.

Stripe remains the authority for unsupported parameters and payment-method-specific combinations. A project can therefore use newly available Stripe values through the filter without waiting for a plugin release, but errors for those values surface only when Stripe receives the request. The plugin validates a parameter itself only when it officially supports that parameter or needs to protect an architectural invariant.

Setting `payment_intent_data.receipt_email` makes Stripe send a live-mode receipt regardless of the account's normal email setting. Add it only when that behavior is intentional.

`SessionRequest` deliberately contains only Stripe-shaped scalar, list, and map data. It normalizes map order and exposes a stable `fingerprint()` without coupling project code to stripe-php request objects.
