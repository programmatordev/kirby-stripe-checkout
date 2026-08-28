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
    stripe-checkout: true
  programmatordev.stripe-checkout:
    settings.read: true
    settings.update: true
    diagnostics.read: true
```

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

The Diagnostics tab checks the PHP, Kirby, and Stripe SDK versions; configuration validity; credential presence and detectable test/live mode; and protected Page ownership. These checks are local and never make a Stripe API request.

Configuration errors expose a stable code and safe option path:

- `configuration.root_invalid`, `configuration.option_duplicate`, `configuration.option_unknown`, `configuration.type_invalid`, `configuration.value_invalid`, and `configuration.combination_invalid` identify malformed options.
- `configuration.required_missing`, `configuration.setting_locked`, `configuration.credential_missing`, `configuration.credential_mode_mismatch`, and `configuration.translation_invalid` identify operation or setup failures.
- `persistence.model_mismatch`, `persistence.owner_mismatch`, `persistence.schema_unsupported`, `persistence.content_invalid`, `persistence.write_failed`, and `persistence.verify_failed` identify protected Page failures.

Fix the reported PHP configuration path. For Page ownership errors, move unrelated content away from the fixed `stripe-checkout` ID before trying again. Diagnostics never include the rejected value for a sensitive path.

See [Configuration](configuration.md) for credentials, effective settings, and PHP locks.
