<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use Closure;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Collection\BillingAddressCollection;
use ProgrammatorDev\StripeCheckout\Collection\NameCollectionMode;
use ProgrammatorDev\StripeCheckout\Collection\TaxIdCollection;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Product\ProductResolverInterface;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;
use SensitiveParameter;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Validates operation inputs and resolves the initial immutable configuration.
 *
 * @internal
 */
final class ConfigurationResolver
{
    private const ROOT_KEYS = ['cart', 'housekeeping', 'orders', 'products', 'settings', 'stripe', 'translations'];
    private const PRODUCT_FIELD_KEYS = [
        'name',
        'description',
        'images',
        'sku',
        'price',
        'stripePrice',
        'requiresShipping',
        'options',
    ];
    private const PRODUCT_KEYS = ['fields', 'resolver'];
    private const STRIPE_KEYS = ['publishableKey', 'secretKey', 'webhookSecret'];

    public function __construct(
        private readonly OptionExtractor $extractor = new OptionExtractor(),
        private readonly StripeCurrencyRegistry $currencies = new StripeCurrencyRegistry(),
        private readonly ?string $languageCode = null,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function resolve(
        #[SensitiveParameter]
        array $options,
        ?PageSettings $pageSettings = null,
    ): ConfigurationReport {
        try {
            $root = $this->resolveRoot($this->extractor->extract($options));
            $this->orderNumberFormatter($options);
            $housekeeping = $this->housekeeping($options);
            $cartEnabled = $this->resolveCart($root['cart']);
            $products = $this->resolveProducts($root['products']);
            $stripe = $this->resolveStripe($root['stripe']);
            $settings = $this->resolveSettings($root['settings'], $pageSettings);
            $translations = $this->resolveTranslations($root['translations']);

            $this->validateCredentialCombination($stripe);

            return ConfigurationReport::valid(new Configuration(
                settings: $settings,
                stripe: $stripe,
                translations: $translations,
                products: $products,
                cartEnabled: $cartEnabled,
                housekeeping: $housekeeping,
            ));
        } catch (ConfigurationException $error) {
            return ConfigurationReport::invalid($error);
        }
    }

    /**
     * Resolve the structural switch independently so broken product settings
     * cannot prevent customers from removing stale selections.
     *
     * @param array<string, mixed> $options
     */
    public function cartEnabled(#[SensitiveParameter] array $options): bool
    {
        return $this->resolveCart($this->cartOptions($options));
    }

    /**
     * @param array<string, mixed> $options
     * @return array{intervalHours: int, batchSize: int}
     */
    public function housekeeping(#[SensitiveParameter] array $options): array
    {
        $root = $this->extractor->extract($options);
        $values = array_key_exists('housekeeping', $root) ? $root['housekeeping'] : [];

        if (is_array($values) === false) {
            throw new ConfigurationException('configuration.type_invalid', 'housekeeping');
        }

        $this->assertKnownKeys($values, ['intervalHours', 'batchSize'], 'housekeeping');
        $values = [...Defaults::HOUSEKEEPING, ...$values];

        foreach ($values as $name => $value) {
            if (is_int($value) === false) {
                throw new ConfigurationException('configuration.type_invalid', 'housekeeping.' . $name);
            }

            if ($value < 1 || $name === 'batchSize' && $value > 100) {
                throw new ConfigurationException('configuration.value_invalid', 'housekeeping.' . $name);
            }
        }

        /** @var array{intervalHours: positive-int, batchSize: positive-int} $values */
        return $values;
    }

    /** @param array<string, mixed> $options */
    public function orderNumberFormatter(#[SensitiveParameter] array $options): ?Closure
    {
        $root = $this->extractor->extract($options);
        $orders = array_key_exists('orders', $root) ? $root['orders'] : [];

        if (is_array($orders) === false) {
            throw new ConfigurationException('configuration.type_invalid', 'orders');
        }

        $this->assertKnownKeys($orders, ['numberFormatter'], 'orders');
        $formatter = $orders['numberFormatter'] ?? null;

        if ($formatter !== null && $formatter instanceof Closure === false) {
            throw new ConfigurationException('configuration.type_invalid', 'orders.numberFormatter');
        }

        return $formatter;
    }

    /** @param array<string, mixed> $options */
    public function cartRenderer(#[SensitiveParameter] array $options): ?Closure
    {
        $cart = $this->cartOptions($options);
        $this->resolveCart($cart);

        /** @var Closure|null */
        return $cart['renderer'] ?? null;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function cartOptions(#[SensitiveParameter] array $options): array
    {
        $root = $this->extractor->extract($options);
        $cart = array_key_exists('cart', $root) ? $root['cart'] : [];

        if (is_array($cart) === false) {
            throw new ConfigurationException('configuration.type_invalid', 'cart');
        }

        /** @var array<string, mixed> $cart */
        return $cart;
    }

    /**
     * @param array<string, mixed> $root
     * @return array{cart: array<string, mixed>, housekeeping: array<string, mixed>, orders: array<string, mixed>, products: array<string, mixed>, settings: array<string, mixed>, stripe: array<string, mixed>, translations: array<mixed, mixed>}
     */
    private function resolveRoot(#[SensitiveParameter] array $root): array
    {
        $this->assertKnownKeys($root, self::ROOT_KEYS);

        foreach (self::ROOT_KEYS as $key) {
            if (array_key_exists($key, $root) && is_array($root[$key]) === false) {
                throw new ConfigurationException('configuration.type_invalid', $key);
            }
        }

        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'cart' => [],
            'housekeeping' => [],
            'orders' => [],
            'products' => [],
            'settings' => [],
            'stripe' => [],
            'translations' => [],
        ]);
        $resolver->setAllowedTypes('products', 'array');
        $resolver->setAllowedTypes('cart', 'array');
        $resolver->setAllowedTypes('housekeeping', 'array');
        $resolver->setAllowedTypes('orders', 'array');
        $resolver->setAllowedTypes('settings', 'array');
        $resolver->setAllowedTypes('stripe', 'array');
        $resolver->setAllowedTypes('translations', 'array');

        /** @var array{cart: array<string, mixed>, housekeeping: array<string, mixed>, orders: array<string, mixed>, products: array<string, mixed>, settings: array<string, mixed>, stripe: array<string, mixed>, translations: array<mixed, mixed>} */
        return $resolver->resolve($root);
    }

    /** @param array<string, mixed> $cart */
    private function resolveCart(array $cart): bool
    {
        $this->assertKnownKeys($cart, ['enabled', 'renderer'], 'cart');

        if (isset($cart['renderer']) && $cart['renderer'] instanceof Closure === false) {
            throw new ConfigurationException('configuration.type_invalid', 'cart.renderer');
        }

        $enabled = array_key_exists('enabled', $cart) ? $cart['enabled'] : true;

        if (is_bool($enabled) === false) {
            throw new ConfigurationException('configuration.type_invalid', 'cart.enabled');
        }

        return $enabled;
    }

    /** @param array<string, mixed> $products */
    private function resolveProducts(array $products): ProductConfiguration
    {
        $this->assertKnownKeys($products, self::PRODUCT_KEYS, 'products');
        $resolver = $products['resolver'] ?? null;

        if ($resolver !== null && $resolver instanceof ProductResolverInterface === false && $resolver instanceof Closure === false) {
            throw new ConfigurationException('configuration.type_invalid', 'products.resolver');
        }

        $fields = $products['fields'] ?? [];

        if (is_array($fields) === false) {
            throw new ConfigurationException('configuration.type_invalid', 'products.fields');
        }

        /** @var array<string, mixed> $fields */
        $this->assertKnownKeys($fields, self::PRODUCT_FIELD_KEYS, 'products.fields');
        $fields = [
            'name' => 'title',
            'description' => 'description',
            'images' => ['productImages'],
            'sku' => 'sku',
            'price' => 'price',
            'stripePrice' => 'stripePrice',
            'requiresShipping' => 'requiresShipping',
            'options' => 'options',
            ...$fields,
        ];

        foreach (self::PRODUCT_FIELD_KEYS as $key) {
            $value = $fields[$key];

            if ($key === 'description' && $value === null) {
                continue;
            }

            if ($key === 'images') {
                $fields[$key] = $this->resolveImageFields($value);

                continue;
            }

            if ($this->validFieldHandle($value) === false) {
                throw new ConfigurationException('configuration.value_invalid', 'products.fields.' . $key);
            }
        }

        /** @var array{name: string, description: ?string, images: list<string>, sku: string, price: string, stripePrice: string, requiresShipping: string, options: string} $fields */
        return new ProductConfiguration($resolver, $fields);
    }

    /** @return list<string> */
    private function resolveImageFields(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $values = is_string($value) ? [$value] : $value;

        if (is_array($values) === false || array_is_list($values) === false || $values === []) {
            throw new ConfigurationException('configuration.value_invalid', 'products.fields.images');
        }

        $normalized = [];

        foreach ($values as $field) {
            if (is_string($field) === false || $this->validFieldHandle($field) === false || isset($normalized[$field])) {
                throw new ConfigurationException('configuration.value_invalid', 'products.fields.images');
            }

            $normalized[$field] = true;
        }

        return array_keys($normalized);
    }

    private function validFieldHandle(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $value) === 1;
    }

    /**
     * @param array<string, mixed> $stripe
     */
    private function resolveStripe(#[SensitiveParameter] array $stripe): StripeConfiguration
    {
        $this->assertKnownKeys($stripe, self::STRIPE_KEYS, 'stripe');

        foreach (self::STRIPE_KEYS as $key) {
            if (array_key_exists($key, $stripe) === false) {
                continue;
            }

            $value = $stripe[$key];
            $path = 'stripe.' . $key;

            if ($value !== null && is_string($value) === false) {
                throw new ConfigurationException('configuration.type_invalid', $path);
            }

            if (is_string($value) && ($value === '' || trim($value) !== $value)) {
                throw new ConfigurationException('configuration.value_invalid', $path);
            }
        }

        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'publishableKey' => null,
            'secretKey' => null,
            'webhookSecret' => null,
        ]);
        $resolver->setAllowedTypes('publishableKey', ['null', 'string']);
        $resolver->setAllowedTypes('secretKey', ['null', 'string']);
        $resolver->setAllowedTypes('webhookSecret', ['null', 'string']);

        /** @var array{publishableKey: string|null, secretKey: string|null, webhookSecret: string|null} $credentials */
        $credentials = $resolver->resolve($stripe);

        return new StripeConfiguration(
            secretKey: $credentials['secretKey'],
            publishableKey: $credentials['publishableKey'],
            webhookSecret: $credentials['webhookSecret'],
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveSettings(
        array $settings,
        ?PageSettings $pageSettings,
    ): Settings {
        $this->assertKnownKeys($settings, array_keys(Defaults::SETTINGS), 'settings');

        if (
            array_key_exists('priceSource', $settings)
            && $settings['priceSource'] !== null
            && is_string($settings['priceSource']) === false
        ) {
            throw new ConfigurationException('configuration.type_invalid', 'settings.priceSource');
        }

        if (
            is_string($settings['priceSource'] ?? null)
            && PriceSource::tryFrom($settings['priceSource']) === null
        ) {
            throw new ConfigurationException('configuration.value_invalid', 'settings.priceSource');
        }

        if (
            array_key_exists('currency', $settings)
            && $settings['currency'] !== null
            && is_string($settings['currency']) === false
        ) {
            throw new ConfigurationException('configuration.type_invalid', 'settings.currency');
        }

        $currency = $settings['currency'] ?? null;

        if (
            is_string($currency)
            && $this->currencies->supports($currency) === false
        ) {
            throw new ConfigurationException('configuration.value_invalid', 'settings.currency');
        }

        if (
            array_key_exists('defaultRequiresShipping', $settings)
            && $settings['defaultRequiresShipping'] !== null
            && is_bool($settings['defaultRequiresShipping']) === false
        ) {
            throw new ConfigurationException(
                'configuration.type_invalid',
                'settings.defaultRequiresShipping',
            );
        }

        $uiMode = $settings['uiMode'] ?? null;

        if ($uiMode !== null && is_string($uiMode) === false) {
            throw new ConfigurationException('configuration.type_invalid', 'settings.uiMode');
        }

        if (is_string($uiMode) && UiMode::tryFrom($uiMode) === null) {
            throw new ConfigurationException('configuration.value_invalid', 'settings.uiMode');
        }

        foreach (['successDestination', 'cancelDestination', 'returnDestination'] as $name) {
            $destination = $settings[$name] ?? null;

            if ($destination !== null && is_string($destination) === false) {
                throw new ConfigurationException('configuration.type_invalid', 'settings.' . $name);
            }

            if (is_string($destination) && ($destination === '' || trim($destination) !== $destination)) {
                throw new ConfigurationException('configuration.value_invalid', 'settings.' . $name);
            }
        }

        $choiceSettings = [
            'billingAddressCollection' => array_column(BillingAddressCollection::cases(), 'value'),
            'individualNameCollection' => array_column(NameCollectionMode::cases(), 'value'),
            'businessNameCollection' => array_column(NameCollectionMode::cases(), 'value'),
            'taxIdCollection' => array_column(TaxIdCollection::cases(), 'value'),
        ];

        foreach ($choiceSettings as $name => $allowedValues) {
            $value = $settings[$name] ?? null;

            if ($value !== null && is_string($value) === false) {
                throw new ConfigurationException('configuration.type_invalid', 'settings.' . $name);
            }

            if (is_string($value) && in_array($value, $allowedValues, true) === false) {
                throw new ConfigurationException('configuration.value_invalid', 'settings.' . $name);
            }
        }

        $booleanSettings = [
            'phoneNumberCollection',
            'termsOfServiceConsent',
            'promotionsConsent',
            'allowPromotionCodes',
        ];

        foreach ($booleanSettings as $name) {
            if (
                array_key_exists($name, $settings)
                && $settings[$name] !== null
                && is_bool($settings[$name]) === false
            ) {
                throw new ConfigurationException('configuration.type_invalid', 'settings.' . $name);
            }
        }

        if (array_key_exists('customFields', $settings) && $settings['customFields'] !== null) {
            if (is_array($settings['customFields']) === false) {
                throw new ConfigurationException('configuration.type_invalid', 'settings.customFields');
            }

            $settings['customFields'] = (new CustomFieldFactory($this->languageCode))
                ->normalize($settings['customFields']);
        }

        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'priceSource' => null,
            'currency' => null,
            'defaultRequiresShipping' => null,
            'uiMode' => null,
            'successDestination' => null,
            'cancelDestination' => null,
            'returnDestination' => null,
            'billingAddressCollection' => null,
            'individualNameCollection' => null,
            'businessNameCollection' => null,
            'phoneNumberCollection' => null,
            'taxIdCollection' => null,
            'termsOfServiceConsent' => null,
            'promotionsConsent' => null,
            'customFields' => null,
            'allowPromotionCodes' => null,
        ]);
        $resolver->setAllowedTypes('priceSource', ['null', 'string']);
        $resolver->setAllowedTypes('currency', ['null', 'string']);
        $resolver->setAllowedTypes('defaultRequiresShipping', ['null', 'bool']);
        $resolver->setAllowedTypes('uiMode', ['null', 'string']);
        $resolver->setAllowedTypes('successDestination', ['null', 'string']);
        $resolver->setAllowedTypes('cancelDestination', ['null', 'string']);
        $resolver->setAllowedTypes('returnDestination', ['null', 'string']);
        $resolver->setAllowedTypes('billingAddressCollection', ['null', 'string']);
        $resolver->setAllowedTypes('individualNameCollection', ['null', 'string']);
        $resolver->setAllowedTypes('businessNameCollection', ['null', 'string']);
        $resolver->setAllowedTypes('phoneNumberCollection', ['null', 'bool']);
        $resolver->setAllowedTypes('taxIdCollection', ['null', 'string']);
        $resolver->setAllowedTypes('termsOfServiceConsent', ['null', 'bool']);
        $resolver->setAllowedTypes('promotionsConsent', ['null', 'bool']);
        $resolver->setAllowedTypes('customFields', ['null', 'array']);
        $resolver->setAllowedTypes('allowPromotionCodes', ['null', 'bool']);
        $resolver->setAllowedValues('priceSource', [
            null,
            PriceSource::Kirby->value,
            PriceSource::Stripe->value,
        ]);
        $resolver->setAllowedValues('uiMode', [
            null,
            UiMode::Hosted->value,
            UiMode::Embedded->value,
        ]);

        foreach ($choiceSettings as $name => $allowedValues) {
            $resolver->setAllowedValues($name, [null, ...$allowedValues]);
        }

        foreach (Defaults::RETENTION as $name => $default) {
            $value = $settings[$name] ?? null;

            if ($value !== null && (is_bool($default) ? is_bool($value) === false : is_int($value) === false)) {
                throw new ConfigurationException('configuration.type_invalid', 'settings.' . $name);
            }

            if (is_int($value) && $value < 1) {
                throw new ConfigurationException('configuration.value_invalid', 'settings.' . $name);
            }

            $resolver->setDefault($name, null);
            $resolver->setAllowedTypes($name, ['null', is_bool($default) ? 'bool' : 'int']);
        }

        /** @var array<string, mixed> $phpSettings */
        $phpSettings = $resolver->resolve($settings);

        /** @var array<string, Setting> $effective */
        $effective = [];

        foreach (Defaults::SETTINGS as $name => $default) {
            $effective[$name] = $this->resolveSetting($phpSettings[$name], $pageSettings?->value($name), $default);
        }

        $customFields = $effective['customFields']->value();

        if (is_array($customFields) === false) {
            throw new ConfigurationException('configuration.type_invalid', 'settings.customFields');
        }

        $customFieldFactory = new CustomFieldFactory($this->languageCode);

        return new Settings(
            settings: $effective,
            customFields: $customFieldFactory->createAll($customFields),
        );
    }

    private function resolveSetting(
        mixed $phpValue,
        mixed $pageValue,
        mixed $internalDefault = null,
    ): Setting {
        // Null is deliberately "unset" and therefore cannot create a PHP lock.
        return match (true) {
            $phpValue !== null => new Setting(
                settingValue: $phpValue,
                settingSource: SettingSource::Php,
                shadowed: $pageValue !== null,
                pageShadow: $pageValue,
            ),
            $pageValue !== null => new Setting(
                settingValue: $pageValue,
                settingSource: SettingSource::Page,
            ),
            default => new Setting(
                settingValue: $internalDefault,
                settingSource: SettingSource::InternalDefault,
            ),
        };
    }

    /**
     * @param array<mixed> $translations
     * @return array<string, array<string, string>>
     */
    private function resolveTranslations(array $translations): array
    {
        $translationOverrides = [];
        $knownSuffixes = array_fill_keys(Catalogue::suffixes(), true);

        foreach ($translations as $locale => $overrides) {
            if (
                is_string($locale) === false
                || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $locale) !== 1
            ) {
                throw new ConfigurationException('configuration.translation_invalid', 'translations');
            }

            if (is_array($overrides) === false) {
                throw new ConfigurationException('configuration.translation_invalid', 'translations.' . $locale);
            }

            foreach ($overrides as $key => $value) {
                $path = 'translations.' . $locale;

                if (is_string($key) === false || trim($key) === '') {
                    throw new ConfigurationException('configuration.translation_invalid', $path);
                }

                $path .= '.' . $key;

                if (isset($knownSuffixes[$key]) === false) {
                    throw new ConfigurationException('configuration.translation_invalid', $path);
                }

                if (
                    is_string($value) === false
                    || trim($value) === ''
                    || TextValidator::isSingleLine($value) === false
                ) {
                    throw new ConfigurationException('configuration.translation_invalid', $path);
                }

                $translationOverrides[$locale][$key] = $value;
            }
        }

        ksort($translationOverrides);

        foreach ($translationOverrides as $locale => $overrides) {
            ksort($overrides);
            $translationOverrides[$locale] = $overrides;
        }

        return $translationOverrides;
    }

    private function validateCredentialCombination(StripeConfiguration $stripe): void
    {
        $secretKeyMode = $stripe->secretKeyMode();
        $publishableKeyMode = $stripe->publishableKeyMode();

        // Reject only a mismatch that can be proven from recognized prefixes;
        // Stripe remains authoritative for unknown or future key formats.
        if (
            $stripe->hasSecretKey()
            && $stripe->hasPublishableKey()
            && $secretKeyMode !== CredentialMode::Unknown
            && $publishableKeyMode !== CredentialMode::Unknown
            && $secretKeyMode !== $publishableKeyMode
        ) {
            throw new ConfigurationException(
                'configuration.credential_mode_mismatch',
                'stripe.publishableKey',
            );
        }
    }

    /**
     * @param array<mixed> $values
     * @param list<string> $knownKeys
     */
    private function assertKnownKeys(
        #[SensitiveParameter]
        array $values,
        array $knownKeys,
        ?string $parent = null,
    ): void {
        foreach (array_keys($values) as $key) {
            if (is_string($key) && in_array($key, $knownKeys, true)) {
                continue;
            }

            $path = $parent === null ? (string) $key : $parent . '.' . $key;

            throw new ConfigurationException('configuration.option_unknown', $path);
        }
    }
}
