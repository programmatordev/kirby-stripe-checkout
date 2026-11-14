# Translations

The plugin ships complete English and Portuguese Panel catalogues. English is the deterministic fallback.

Override a known message or add a partial locale with suffix-only keys:

```php
'programmatordev.stripe-checkout' => [
    'translations' => [
        'pt_PT' => [
            'settings.locked' => 'Configurado em PHP.',
        ],
    ],
],
```

Unknown suffixes and blank values are rejected so translation mistakes appear in diagnostics.

## Rename the Panel area

Override `area.label` when the project needs a more familiar name such as **Shop**, **Store** or **Tickets**:

```php
'programmatordev.stripe-checkout' => [
    'translations' => [
        'en' => [
            'area.label' => 'Shop',
        ],
        'pt_PT' => [
            'area.label' => 'Loja',
        ],
    ],
],
```

The override follows the current Panel user's language and changes the area label, its page title and the link back from an Order.
It does not rename plugin routes or stored data.

These translations cover plugin-owned Kirby UI. Stripe Checkout localizes its hosted or embedded payment interface separately.

See [Configuration](configuration.md) for the complete plugin option structure.
