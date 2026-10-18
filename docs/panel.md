# Panel and diagnostics

## Automatic setup

Composer installation automatically adds a **Stripe Checkout** area to Kirby's default Panel menu. On the first Kirby boot after installation, the plugin creates its protected `stripe-checkout` draft Page.

The area is Kirby's native Page view, with plugin-owned Overview, Settings, and Diagnostics blueprint tabs. Kirby provides the fields, validation, versions, permissions, locks, and save flow without a duplicated editor component. No separate setup action is required.

The schema and layout are plugin-owned so conditional settings, locks, translations, validation, and documentation remain consistent. Projects cannot replace the `stripe-checkout` blueprint. Keep unrelated project settings in the Site blueprint or another project-owned Page.

If the fixed identifier already belongs to unrelated content or Kirby cannot create the Page, the plugin leaves existing content unchanged. The Panel area reports the problem without preventing unrelated site requests.

## Permissions

Admins receive all plugin permissions. Custom roles opt in explicitly:

```yaml
permissions:
  access:
    panel: true
    stripe-checkout: true
  programmatordev.stripe-checkout:
    settings.read: true
    settings.update: true
    diagnostics.read: true
    prices.read: true
    taxCodes.read: true
    orders.read: true
    orders.update: true
    orders.reconcile: true
    orders.delete: true
    lifecycle.retry: true
    housekeeping.run: true
```

`prices.read` allows a role to search and explicitly refresh the read-only Stripe Price catalogue from product fields. Page-update permission still controls whether the selected scalar Price ID can be saved.

`taxCodes.read` permits reading, searching, and refreshing the full Tax Code catalogue from product/variant pickers. Saving a selection still requires native Page-update permission. The Tax Code catalogue diagnostic reports only cached counts and successful/failed refresh times; it never checks Stripe Tax setup or account readiness and never refreshes Stripe itself.

`orders.read` permits viewing native order Pages. `orders.update` additionally permits editing project-owned fields; canonical payment/order fields remain protected. See [Orders](orders.md) for blueprint customization. The orders container is also initialized automatically; the main hub does not yet include an Orders tab.

The remaining permissions authorize explicit operational requests. `orders.reconcile` and `orders.delete` also require `orders.read`; `lifecycle.retry` requires `orders.read` when retrying one failed order hook. `housekeeping.run` starts one configured housekeeping batch using the same continuation state and non-overlapping lock as the CLI command. These permissions are disabled by default for custom roles. The authenticated request backend is available for the later Panel action UI; this version does not add its buttons or expose public mutation routes.

Every operational request uses the same response envelope. `code` is the HTTP status, `status` is `success`, `partial_failure` or `failed`, `result` contains only operation-specific successful output, and `error` is either `null` or a safe code plus explicit retryability:

```json
{
  "code": 200,
  "status": "success",
  "result": {
    "deleted": true
  },
  "error": null
}
```

Failed requests set `result` to `null`. A completed housekeeping batch can return `partial_failure` with its sanitized counters in `result` and its stable issue in `error`. `retryable` is true only when the operation specifically classifies a later explicit invocation as useful; it never schedules or automatically replays an action.

## Panel menu

The area stays registered even when omitted from a custom `panel.menu`. Use Kirby's normal menu option to reorder, rename, hide, or link directly to a tab:

```php
'panel.menu' => [
    'site',
    'stripe-checkout' => [
        'label' => 'Store',
        'link' => 'stripe-checkout?tab=settings',
    ],
    'users',
],
```

The area path is `stripe-checkout`. Its native blueprint tabs use `overview`, `settings`, and `diagnostics` query values, for example `stripe-checkout?tab=diagnostics`. Menu visibility does not replace the area and plugin permission checks.

## Local diagnostics

The Diagnostics tab checks the PHP, Kirby, and Stripe SDK versions; configuration validity; credential presence and detectable test/live mode; store currency and default shipping configuration; protected Page ownership; and invalid order records. It also shows retention/housekeeping configuration, pending and failed hook executions, and a warning if the last deleted-order hook failed. These refer to the plugin's local lifecycle hooks, not shipping or Stripe webhook deliveries. These checks are local and never make a Stripe API request or execute cleanup.

Configuration errors expose a stable code and safe option path:

- `configuration.root_invalid`, `configuration.option_duplicate`, `configuration.option_unknown`, `configuration.type_invalid`, `configuration.value_invalid`, and `configuration.combination_invalid` identify malformed options.
- `configuration.required_missing`, `configuration.setting_locked`, `configuration.credential_missing`, `configuration.credential_mode_mismatch`, and `configuration.translation_invalid` identify operation or setup failures.
- `persistence.model_mismatch`, `persistence.owner_mismatch`, `persistence.schema_unsupported`, `persistence.content_invalid`, `persistence.write_failed`, and `persistence.verify_failed` identify protected Page failures.

Fix the reported PHP configuration path. For Page ownership errors, move unrelated content away from the fixed `stripe-checkout` ID before trying again. Diagnostics never include the rejected value for a sensitive path.

See [Configuration](configuration.md) for credentials, effective settings, and PHP locks.
