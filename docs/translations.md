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

These translations cover plugin-owned Kirby UI. Stripe Checkout localizes its hosted or embedded payment interface separately.

See [Configuration](configuration.md) for the complete plugin option structure.
