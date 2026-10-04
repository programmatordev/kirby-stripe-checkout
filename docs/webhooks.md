# Stripe webhooks

The plugin registers `POST /stripe-checkout/webhook` automatically through its Composer bootstrap. The endpoint has no language prefix and remains available when the built-in cart is disabled. It needs neither a browser session nor a CSRF token; Stripe's signature authenticates the delivery.

Customer Checkout and return routes are still under development. This endpoint reconciles orders already created by the plugin's protected Checkout workflow; it does not create an order from an arbitrary Stripe event.

## Configure Stripe

In the matching Stripe sandbox or live account, create an account webhook destination for:

```text
https://your-site.example/stripe-checkout/webhook
```

Use snapshot events and the API version pinned by the installed Stripe PHP SDK. The current dependency baseline uses `2026-09-30.endive`; after an SDK upgrade, check `Stripe\Util\ApiVersion::CURRENT` and update the destination deliberately.

Select these eight events:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `checkout.session.expired`
- `payment_intent.requires_action`
- `refund.created`
- `refund.updated`
- `refund.failed`

The destination must be publicly reachable over HTTPS. Select your own account rather than connected accounts. Copy its `whsec_…` signing secret into deployment configuration. See [Stripe's endpoint setup](https://docs.stripe.com/webhooks#set-up-your-endpoint).

```php
// site/config/config.php
return [
    'programmatordev.stripe-checkout' => [
        'stripe' => [
            'secretKey' => getenv('KIRBY_STRIPE_CHECKOUT_SECRET_KEY') ?: null,
            'webhookSecret' => getenv('KIRBY_STRIPE_CHECKOUT_WEBHOOK_SECRET') ?: null,
        ],
    ],
];
```

Use the server key for the same account and mode as the destination. Keep both secrets outside committed files. An endpoint secret and an API key serve different purposes; neither replaces the other.

## Processing and acknowledgments

The endpoint verifies the original request bytes with the Stripe SDK and its default five-minute timestamp tolerance. Keep the server clock accurate and preserve the body and `Stripe-Signature` header through any proxy. Stripe can include overlapping signatures during secret rotation. See [Stripe's signature guidance](https://docs.stripe.com/webhooks/signature).

For a supported plugin-owned event, the saved order reference identifies the purchase. The reconciler retrieves current Stripe resources and commits validated payment and Checkout facts together. A browser return is not proof of payment. An older event therefore cannot overwrite a newer payment result merely because it arrives later.

An early Checkout event can repair a missing local Session association. A requires-action event arriving before that association returns `503`; a later delivery can use the saved backlink. This waits only for correlation evidence, not for Checkout completion.

Refund events establish ownership through the current parent PaymentIntent, including refunds created in the Dashboard without plugin metadata. A Charge backlink resolves a missing PaymentIntent reference. The reconciler reads every current refund for that payment and refreshes Checkout/payment facts before committing both together. Refund status comes from these current reads; the historical event only identifies the trigger.

When a refund's local Session association is missing, the reconciler looks up Checkout Sessions by that exact PaymentIntent. No match returns `503`; multiple or truncated results return `500`. It accepts only one fully correlated Session. A processed refund duplicate can still require initial Refund/parent reads to locate the order, then skips the complete Checkout/refund refresh and hooks.

Every handled POST returns an empty body with `Cache-Control: no-store`:

| Status | Meaning |
| --- | --- |
| `204` | Processed, already processed, or safely ignored because the event is unsupported or belongs elsewhere. |
| `400` | Invalid signature, timestamp, body or required envelope. |
| `503` | Missing credentials or temporarily unavailable provider, persistence or correlation evidence. |
| `500` | Contradictory owned facts, incompatible required provider data, invalid local configuration or another permanent processing failure. |

Stripe treats every non-`2xx` response as a failed delivery eligible for retry. Live delivery retries last up to three days with exponential backoff; sandbox retries occur three times over a few hours. The distinction between `500` and `503` is the plugin's classification, not a way to control Stripe's retry schedule. See [Stripe's automatic retries](https://docs.stripe.com/webhooks#automatic-retries).

A successful commit remains acknowledged even if an optional lifecycle hook fails. Redelivering its processed Stripe Event does not retry that hook. Lost responses and manual resends are safe through the saved Event identity and transition deduplication; project hook consumers must still make their own external effects idempotent and keep synchronous work short.

Order-correlated failures use the order's Event ledger. Failures that cannot safely attach to an order return the appropriate failure status and log only a stable error code through PHP's configured error log; no separate incident file is written. Inspect Event payloads and delivery attempts in [Stripe Workbench](https://docs.stripe.com/workbench/event-destinations#view-event-deliveries). Raw webhook bodies, signatures and private provider messages are not retained locally. Operator retry tools are not implemented yet.

Dispute events, `charge.refunded` and `charge.refund.updated` remain unsupported and are acknowledged without changing orders. Use the three dedicated refund events above to update the [saved refund collection](orders.md#refund-facts).

## Optional local forwarding

You can test a local endpoint before registering a public destination. Authenticate the operating-system Stripe CLI, then forward only the supported snapshot events:

```bash
stripe login
stripe listen \
  --events checkout.session.completed,checkout.session.async_payment_succeeded,checkout.session.async_payment_failed,checkout.session.expired,payment_intent.requires_action,refund.created,refund.updated,refund.failed \
  --forward-to https://your-project.ddev.site/stripe-checkout/webhook
```

Use the signing secret printed by `stripe listen` in the local environment. It differs from a Dashboard destination's secret. If the CLI cannot trust a local development certificate, use its development-only `--skip-verify` option. See [Stripe's local webhook testing](https://docs.stripe.com/webhooks/quickstart).

Generic `stripe trigger` fixtures do not carry the plugin's owned order correlation, so acknowledgment alone does not prove order reconciliation. Exercise payment events for a Session created by the plugin when performing an account-assisted check. The default repository suite uses signed offline fixtures and disposable Kirby storage instead.
