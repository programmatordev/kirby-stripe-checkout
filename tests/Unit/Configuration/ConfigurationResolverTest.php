<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Configuration;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Collection\BillingAddressCollection;
use ProgrammatorDev\StripeCheckout\Collection\CustomFieldType;
use ProgrammatorDev\StripeCheckout\Collection\NameCollectionMode;
use ProgrammatorDev\StripeCheckout\Collection\TaxIdCollection;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationReport;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Configuration\PageSettings;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Configuration\SettingSource;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use SensitiveParameter;

final class ConfigurationResolverTest extends TestCase
{
    private const PREFIX = 'programmatordev.stripe-checkout';

    public function testRetentionDefaultsPageValuesAndPhpLocks(): void
    {
        $resolver = new ConfigurationResolver();
        $defaults = $resolver->resolve([])->configurationOrFail()->settings();
        $this->assertTrue($defaults->cleanupCreationFailures());
        $this->assertSame(7, $defaults->creationFailureRetentionDays());
        $this->assertTrue($defaults->cleanupUnpaidOrders());
        $this->assertSame(30, $defaults->unpaidOrderRetentionDays());
        $page = new PageSettings(cleanupCreationFailures: 'false', creationFailureRetentionDays: '14', cleanupUnpaidOrders: 'false', unpaidOrderRetentionDays: '60');
        $settings = $resolver->resolve([
            self::PREFIX . '.settings.cleanupCreationFailures' => true,
            self::PREFIX . '.settings.unpaidOrderRetentionDays' => 90,
        ], $page)->configurationOrFail()->settings();
        $this->assertTrue($settings->cleanupCreationFailures());
        $this->assertSame(14, $settings->creationFailureRetentionDays());
        $this->assertFalse($settings->cleanupUnpaidOrders());
        $this->assertSame(90, $settings->unpaidOrderRetentionDays());
        $this->assertTrue($settings->setting('cleanupCreationFailures')?->isLocked());
        $this->assertFalse($settings->setting('cleanupCreationFailures')->shadowedValue());
        $this->assertSame(SettingSource::Page, $settings->setting('creationFailureRetentionDays')?->source());
        $this->assertNull($settings->setting('housekeeping'));
    }

    public function testHousekeepingIsPhpOnlyAndValidatesIntegerBounds(): void
    {
        $resolver = new ConfigurationResolver();
        $this->assertSame([
            'intervalHours' => 24,
            'batchSize' => 25,
        ], $resolver->housekeeping([]));
        $this->assertSame([
            'intervalHours' => 12,
            'batchSize' => 100,
        ], $resolver->resolve([
            self::PREFIX . '.housekeeping.intervalHours' => 12,
            self::PREFIX . '.housekeeping.batchSize' => 100,
        ])->configurationOrFail()->housekeeping());
        $invalid = [
            ['intervalHours' => '24'],
            ['intervalHours' => null],
            ['intervalHours' => 0],
            ['batchSize' => 0],
            ['batchSize' => 101],
            ['batchSize' => 2.5],
            ['batchSize' => true],
            ['unknown' => 1],
        ];

        foreach ($invalid as $values) {
            $this->assertFalse($resolver->resolve([self::PREFIX => ['housekeeping' => $values]])->isValid());
        }

        $this->assertFalse($resolver->resolve([
            self::PREFIX => ['housekeeping' => ['batchSize' => 1]],
            self::PREFIX . '.housekeeping.batchSize' => 2,
        ])->isValid());
    }

    #[DataProvider('invalidRetentionSettings')]
    public function testRejectsInvalidRetentionPhpValues(string $name, mixed $value): void
    {
        $report = $this->resolve([self::PREFIX => ['settings' => [$name => $value]]]);
        $this->assertFalse($report->isValid());
        $this->assertSame('settings.' . $name, $report->error()?->path());
    }

    /** @return iterable<array{string, mixed}> */
    public static function invalidRetentionSettings(): iterable
    {
        yield ['cleanupCreationFailures', 'true'];
        yield ['cleanupUnpaidOrders', 0];
        yield ['creationFailureRetentionDays', 0];
        yield ['creationFailureRetentionDays', -1];
        yield ['creationFailureRetentionDays', '7'];
        yield ['unpaidOrderRetentionDays', 2.5];
        yield ['unpaidOrderRetentionDays', true];
    }

    public function testOrderNumberFormatterSupportsDottedOptionsAndValidatesTheGroup(): void
    {
        $resolver = new ConfigurationResolver();
        $formatter = static fn(string $uuid): string => 'CUSTOM';
        $options = [self::PREFIX . '.orders.numberFormatter' => $formatter];
        $this->assertSame($formatter, $resolver->orderNumberFormatter($options));
        $this->assertTrue($resolver->resolve($options)->isValid());
        $this->assertNull($resolver->orderNumberFormatter([]));
        $invalid = [null, false, ['unknown' => true], ['numberFormatter' => 'trim']];

        foreach ($invalid as $orders) {
            $this->assertFalse($resolver->resolve([self::PREFIX => ['orders' => $orders]])->isValid());
        }
    }

    public function testResolvesTheInternalDefaultWithoutCredentials(): void
    {
        $configuration = $this->resolve([])->configurationOrFail();
        $settings = $configuration->settings();
        $priceSource = $settings->setting('priceSource');

        $this->assertSame(PriceSource::Kirby, $settings->priceSource());
        $this->assertNotNull($priceSource);
        $this->assertSame(PriceSource::Kirby->value, $priceSource->value());
        $this->assertSame(SettingSource::InternalDefault, $priceSource->source());
        $this->assertFalse($priceSource->isLocked());
        $this->assertFalse($priceSource->hasShadowedValue());
        $this->assertNull($priceSource->shadowedValue());
        $this->assertNull($settings->currency());
        $this->assertNull($settings->defaultRequiresShipping());
        $this->assertSame('hosted', $settings->uiMode()->value);
        $this->assertNull($settings->successDestination());
        $this->assertNull($settings->cancelDestination());
        $this->assertNull($settings->returnDestination());
        $this->assertSame(BillingAddressCollection::Auto, $settings->billingAddressCollection());
        $this->assertSame(NameCollectionMode::Optional, $settings->individualNameCollection());
        $this->assertSame(NameCollectionMode::Off, $settings->businessNameCollection());
        $this->assertFalse($settings->phoneNumberCollection());
        $this->assertSame(TaxIdCollection::Off, $settings->taxIdCollection());
        $this->assertFalse($settings->termsOfServiceConsent());
        $this->assertFalse($settings->promotionsConsent());
        $this->assertSame([], $settings->customFields());
        $this->assertFalse($settings->allowPromotionCodes());
        $this->assertFalse($configuration->stripe()->hasSecretKey());
        $this->assertFalse($configuration->stripe()->hasPublishableKey());
        $this->assertFalse($configuration->stripe()->hasWebhookSecret());
    }

    public function testResolvesNestedPhpConfigurationAndProvenance(): void
    {
        $configuration = $this->resolve([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => false,
                    'priceSource' => 'stripe',
                ],
                'stripe' => [
                    'secretKey' => 'sk_test_secret',
                    'publishableKey' => 'pk_test_public',
                    'webhookSecret' => 'whsec_example',
                ],
            ],
        ])->configurationOrFail();
        $setting = $configuration->settings()->setting('priceSource');

        $this->assertSame(PriceSource::Stripe, $configuration->settings()->priceSource());
        $this->assertSame('EUR', $configuration->settings()->currency());
        $this->assertFalse($configuration->settings()->defaultRequiresShipping());
        $this->assertNotNull($setting);
        $this->assertSame(SettingSource::Php, $setting->source());
        $this->assertTrue($setting->isLocked());
        $this->assertTrue($configuration->stripe()->hasSecretKey());
        $this->assertTrue($configuration->stripe()->hasPublishableKey());
        $this->assertTrue($configuration->stripe()->hasWebhookSecret());
        $this->assertSame(CredentialMode::Test, $configuration->stripe()->secretKeyMode());
        $this->assertSame(CredentialMode::Test, $configuration->stripe()->publishableKeyMode());
    }

    public function testResolvesFullyDottedConfiguration(): void
    {
        $configuration = $this->resolve([
            self::PREFIX . '.settings.priceSource' => 'stripe',
            self::PREFIX . '.settings.currency' => 'USD',
            self::PREFIX . '.settings.defaultRequiresShipping' => true,
            self::PREFIX . '.settings.uiMode' => 'embedded',
            self::PREFIX . '.settings.successDestination' => '/complete',
            self::PREFIX . '.settings.cancelDestination' => '/cancel',
            self::PREFIX . '.settings.returnDestination' => '/return',
            self::PREFIX . '.settings.billingAddressCollection' => 'required',
            self::PREFIX . '.settings.individualNameCollection' => 'required',
            self::PREFIX . '.settings.businessNameCollection' => 'optional',
            self::PREFIX . '.settings.phoneNumberCollection' => true,
            self::PREFIX . '.settings.taxIdCollection' => 'required_if_supported',
            self::PREFIX . '.settings.termsOfServiceConsent' => true,
            self::PREFIX . '.settings.promotionsConsent' => true,
            self::PREFIX . '.settings.allowPromotionCodes' => true,
            self::PREFIX . '.settings.customFields' => [[
                'key' => 'reference',
                'label' => 'Reference',
                'type' => 'text',
            ]],
            self::PREFIX . '.stripe.secretKey' => 'custom-server-key',
            self::PREFIX . '.stripe.publishableKey' => 'custom-public-key',
        ])->configurationOrFail();

        $this->assertSame(PriceSource::Stripe, $configuration->settings()->priceSource());
        $this->assertSame('USD', $configuration->settings()->currency());
        $this->assertTrue($configuration->settings()->defaultRequiresShipping());
        $this->assertSame('embedded', $configuration->settings()->uiMode()->value);
        $this->assertSame('/complete', $configuration->settings()->successDestination());
        $this->assertSame('/cancel', $configuration->settings()->cancelDestination());
        $this->assertSame('/return', $configuration->settings()->returnDestination());
        $this->assertSame(BillingAddressCollection::Required, $configuration->settings()->billingAddressCollection());
        $this->assertSame(NameCollectionMode::Required, $configuration->settings()->individualNameCollection());
        $this->assertSame(NameCollectionMode::Optional, $configuration->settings()->businessNameCollection());
        $this->assertTrue($configuration->settings()->phoneNumberCollection());
        $this->assertSame(TaxIdCollection::RequiredIfSupported, $configuration->settings()->taxIdCollection());
        $this->assertTrue($configuration->settings()->termsOfServiceConsent());
        $this->assertTrue($configuration->settings()->promotionsConsent());
        $this->assertTrue($configuration->settings()->allowPromotionCodes());
        $this->assertSame('reference', $configuration->settings()->customFields()[0]->key());
        $this->assertSame(CredentialMode::Unknown, $configuration->stripe()->secretKeyMode());
        $this->assertSame(CredentialMode::Unknown, $configuration->stripe()->publishableKeyMode());
    }

    public function testAcceptsStripesMaximumCustomFieldIdentifierLengths(): void
    {
        $configuration = $this->resolve([
            self::PREFIX => [
                'settings' => [
                    'customFields' => [[
                        'key' => str_repeat('k', 200),
                        'label' => 'Reference',
                        'type' => 'dropdown',
                        'options' => [[
                            'value' => str_repeat('o', 100),
                            'label' => 'Option',
                        ]],
                    ]],
                ],
            ],
        ])->configurationOrFail();
        $customField = $configuration->settings()->customFields()[0];

        $this->assertSame(200, strlen($customField->key()));
        $this->assertSame(100, strlen($customField->options()[0]->value()));
    }

    public function testResolvesKirbysNormalizedDottedConfiguration(): void
    {
        $configuration = $this->resolve([
            self::PREFIX => [
                'settings.currency' => 'GBP',
                'settings.defaultRequiresShipping' => false,
                'settings.priceSource' => 'stripe',
                'stripe.secretKey' => 'sk_live_server',
                'stripe.publishableKey' => 'pk_live_public',
            ],
        ])->configurationOrFail();

        $this->assertSame(PriceSource::Stripe, $configuration->settings()->priceSource());
        $this->assertSame('GBP', $configuration->settings()->currency());
        $this->assertFalse($configuration->settings()->defaultRequiresShipping());
        $this->assertSame(CredentialMode::Live, $configuration->stripe()->secretKeyMode());
        $this->assertSame(CredentialMode::Live, $configuration->stripe()->publishableKeyMode());
    }

    public function testExplicitNullSettingFallsThroughToTheInternalDefault(): void
    {
        $settings = $this->resolve([
            self::PREFIX => [
                'settings' => [
                    'priceSource' => null,
                ],
            ],
        ])->configurationOrFail()->settings();
        $setting = $settings->setting('priceSource');

        $this->assertSame(PriceSource::Kirby, $settings->priceSource());
        $this->assertNotNull($setting);
        $this->assertSame(SettingSource::InternalDefault, $setting->source());
        $this->assertFalse($setting->isLocked());
    }

    public function testResolvesLocalizedCustomFieldsFromPhpConfiguration(): void
    {
        $settings = (new ConfigurationResolver(languageCode: 'pt'))->resolve([
            self::PREFIX => [
                'settings' => [
                    'customFields' => [
                        [
                            'key' => 'nif',
                            'label' => 'Tax number',
                            'labels' => ['pt' => 'NIF'],
                            'type' => 'text',
                            'minimumLength' => 9,
                            'maximumLength' => 9,
                        ],
                        [
                            'key' => 'delivery',
                            'label' => 'Delivery preference',
                            'type' => 'dropdown',
                            'required' => true,
                            'defaultValue' => 'morning',
                            'options' => [
                                [
                                    'value' => 'morning',
                                    'label' => 'Morning',
                                    'labels' => ['pt' => 'Manhã'],
                                ],
                                [
                                    'value' => 'afternoon',
                                    'label' => 'Afternoon',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])->configurationOrFail()->settings();
        $fields = $settings->customFields();

        $this->assertCount(2, $fields);
        $this->assertSame('nif', $fields[0]->key());
        $this->assertSame('NIF', $fields[0]->label());
        $this->assertSame(CustomFieldType::Text, $fields[0]->type());
        $this->assertFalse($fields[0]->isRequired());
        $this->assertSame(9, $fields[0]->minimumLength());
        $this->assertSame(9, $fields[0]->maximumLength());
        $this->assertSame('Manhã', $fields[1]->options()[0]->label());
        $this->assertSame('Afternoon', $fields[1]->options()[1]->label());
        $this->assertSame('morning', $fields[1]->defaultValue());
        $customFieldSetting = $settings->setting('customFields');
        $this->assertNotNull($customFieldSetting);
        $this->assertTrue($customFieldSetting->isLocked());
        $rawFields = $customFieldSetting->value();
        $this->assertIsArray($rawFields);
        $rawField = $rawFields[0] ?? null;
        $this->assertIsArray($rawField);
        $this->assertSame('Tax number', $rawField['label'] ?? null);
    }

    #[DataProvider('invalidCollectionSettingProvider')]
    public function testRejectsInvalidCollectionSettings(string $name, mixed $value): void
    {
        $report = $this->resolve([
            self::PREFIX => ['settings' => [$name => $value]],
        ]);

        $this->assertFalse($report->isValid());
        $this->assertSame('settings.' . $name, $report->error()?->path());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidCollectionSettingProvider(): iterable
    {
        yield 'billing type' => ['billingAddressCollection', true];
        yield 'billing value' => ['billingAddressCollection', 'optional'];
        yield 'individual name' => ['individualNameCollection', 'auto'];
        yield 'business name' => ['businessNameCollection', 1];
        yield 'phone' => ['phoneNumberCollection', 'true'];
        yield 'tax ID' => ['taxIdCollection', 'required'];
        yield 'terms' => ['termsOfServiceConsent', 1];
        yield 'promotions consent' => ['promotionsConsent', 'false'];
        yield 'promotion codes' => ['allowPromotionCodes', 0];
    }

    /** @param array<mixed, mixed> $customFields */
    #[DataProvider('invalidCustomFieldsProvider')]
    public function testRejectsInvalidCustomFieldConfiguration(array $customFields, string $path): void
    {
        $report = $this->resolve([
            self::PREFIX => ['settings' => ['customFields' => $customFields]],
        ]);

        $this->assertFalse($report->isValid());
        $this->assertSame($path, $report->error()?->path());
    }

    /** @return iterable<string, array{array<mixed, mixed>, string}> */
    public static function invalidCustomFieldsProvider(): iterable
    {
        $text = [
            'key' => 'reference',
            'label' => 'Reference',
            'type' => 'text',
        ];
        $dropdown = [
            'key' => 'delivery',
            'label' => 'Delivery',
            'type' => 'dropdown',
            'options' => [
                [
                    'value' => 'morning',
                    'label' => 'Morning',
                ],
            ],
        ];

        yield 'map instead of list' => [['field' => $text], 'settings.customFields'];
        yield 'more than three' => [[
            $text,
            [...$text, 'key' => 'second'],
            [...$text, 'key' => 'third'],
            [...$text, 'key' => 'fourth'],
        ], 'settings.customFields'];
        yield 'missing key' => [[array_diff_key($text, ['key' => true])], 'settings.customFields.0.key'];
        yield 'uppercase key' => [[[...$text, 'key' => 'Reference']], 'settings.customFields.0.key'];
        yield 'oversized key' => [[[...$text, 'key' => str_repeat('a', 201)]], 'settings.customFields.0.key'];
        yield 'duplicate key' => [[$text, $text], 'settings.customFields.1.key'];
        yield 'unknown type' => [[[...$text, 'type' => 'date']], 'settings.customFields.0.type'];
        yield 'invalid required type' => [[[...$text, 'required' => 'yes']], 'settings.customFields.0.required'];
        yield 'reversed bounds' => [[[...$text, 'minimumLength' => 10, 'maximumLength' => 5]], 'settings.customFields.0.maximumLength'];
        yield 'options on text' => [[[...$text, 'options' => $dropdown['options']]], 'settings.customFields.0.options'];
        yield 'empty dropdown' => [[[...$dropdown, 'options' => []]], 'settings.customFields.0.options'];
        yield 'oversized dropdown value' => [[[
            ...$dropdown,
            'options' => [[
                'value' => str_repeat('a', 101),
                'label' => 'Morning',
            ]],
        ]], 'settings.customFields.0.options.0.value'];
        yield 'length bound on dropdown' => [[[...$dropdown, 'minimumLength' => 1]], 'settings.customFields.0.minimumLength'];
        yield 'unknown default' => [[[...$dropdown, 'defaultValue' => 'evening']], 'settings.customFields.0.defaultValue'];
        yield 'invalid translated labels' => [[[...$text, 'labels' => ['invalid code' => 'Referência']]], 'settings.customFields.0.labels'];
        yield 'unknown property' => [[[...$text, 'placeholder' => 'Optional']], 'settings.customFields.0.placeholder'];
        yield 'invalid UTF-8 label' => [[[...$text, 'label' => "Invalid\xff"]], 'settings.customFields.0.label'];
        yield 'invalid UTF-8 translated label' => [[[...$text, 'labels' => ['pt' => "Invalid\xff"]]], 'settings.customFields.0.labels.pt'];
        yield 'invalid UTF-8 default' => [[[...$text, 'defaultValue' => "Invalid\xff"]], 'settings.customFields.0.defaultValue'];
        yield 'invalid UTF-8 option label' => [[[...$dropdown, 'options' => [[
            'value' => 'morning',
            'label' => "Invalid\xff",
        ]]]], 'settings.customFields.0.options.0.label'];
    }

    public function testResolvesProductDefaultsAndDottedFieldOverrides(): void
    {
        $resolver = static fn(): never => throw new LogicException('Not called.');
        $products = $this->resolve([
            self::PREFIX . '.products.resolver' => $resolver,
            self::PREFIX . '.products.fields.name' => 'productName',
            self::PREFIX . '.products.fields.description' => null,
            self::PREFIX . '.products.fields.images' => ['thumbnail', 'gallery'],
            self::PREFIX . '.products.fields.price' => 'unitPrice',
            self::PREFIX . '.products.fields.stripePrice' => 'paymentPrice',
        ])->configurationOrFail()->products();

        $this->assertSame($resolver, $products->resolver());
        $this->assertSame([
            'name' => 'productName',
            'description' => null,
            'images' => ['thumbnail', 'gallery'],
            'sku' => 'sku',
            'price' => 'unitPrice',
            'stripePrice' => 'paymentPrice',
            'requiresShipping' => 'requiresShipping',
            'options' => 'options',
        ], $products->fields());
    }

    public function testPageSettingOverridesTheInternalDefault(): void
    {
        $settings = (new ConfigurationResolver())->resolve(
            [],
            new PageSettings(PriceSource::Stripe->value),
        )->configurationOrFail()->settings();
        $setting = $settings->setting('priceSource');

        $this->assertSame(PriceSource::Stripe, $settings->priceSource());
        $this->assertNotNull($setting);
        $this->assertSame(SettingSource::Page, $setting->source());
        $this->assertFalse($setting->isLocked());
        $this->assertFalse($setting->hasShadowedValue());
    }

    public function testPhpSettingOverridesAndReportsThePageShadow(): void
    {
        $settings = (new ConfigurationResolver())->resolve(
            [
                self::PREFIX => [
                    'settings' => ['priceSource' => PriceSource::Stripe->value],
                ],
            ],
            new PageSettings(PriceSource::Kirby->value),
        )->configurationOrFail()->settings();
        $setting = $settings->setting('priceSource');

        $this->assertSame(PriceSource::Stripe, $settings->priceSource());
        $this->assertNotNull($setting);
        $this->assertSame(SettingSource::Php, $setting->source());
        $this->assertTrue($setting->isLocked());
        $this->assertTrue($setting->hasShadowedValue());
        $this->assertSame(PriceSource::Kirby->value, $setting->shadowedValue());
    }

    public function testEveryCommerceSettingRetainsIndependentPageAndPhpProvenance(): void
    {
        $settings = (new ConfigurationResolver())->resolve(
            [
                self::PREFIX => [
                    'settings' => [
                        'currency' => 'USD',
                        'defaultRequiresShipping' => false,
                        'uiMode' => 'embedded',
                        'successDestination' => '/php-success',
                    ],
                ],
            ],
            new PageSettings(
                currency: 'EUR',
                defaultRequiresShipping: 'yes',
                uiMode: 'hosted',
                successDestination: '/page-success',
            ),
        )->configurationOrFail()->settings();
        $currency = $settings->setting('currency');
        $shipping = $settings->setting('defaultRequiresShipping');
        $uiMode = $settings->setting('uiMode');
        $success = $settings->setting('successDestination');

        $this->assertSame('USD', $settings->currency());
        $this->assertFalse($settings->defaultRequiresShipping());
        $this->assertNotNull($currency);
        $this->assertSame(SettingSource::Php, $currency->source());
        $this->assertSame('EUR', $currency->shadowedValue());
        $this->assertNotNull($shipping);
        $this->assertSame(SettingSource::Php, $shipping->source());
        $this->assertTrue($shipping->shadowedValue());
        $this->assertSame('embedded', $settings->uiMode()->value);
        $this->assertNotNull($uiMode);
        $this->assertSame(SettingSource::Php, $uiMode->source());
        $this->assertSame('hosted', $uiMode->shadowedValue());
        $this->assertSame('/php-success', $settings->successDestination());
        $this->assertNotNull($success);
        $this->assertSame(SettingSource::Php, $success->source());
        $this->assertSame('/page-success', $success->shadowedValue());
    }

    public function testInvalidPageSettingUsesTheSafePersistenceFailure(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('persistence.content_invalid');

        new PageSettings('remote');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string|null}>
     */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'null root' => [
            [self::PREFIX => null],
            'configuration.root_invalid',
            null,
        ];
        yield 'scalar root' => [
            [self::PREFIX => false],
            'configuration.root_invalid',
            null,
        ];
        yield 'duplicate nested and dotted value' => [
            [
                self::PREFIX => ['settings' => ['priceSource' => 'kirby']],
                self::PREFIX . '.settings.priceSource' => 'stripe',
            ],
            'configuration.option_duplicate',
            'settings.priceSource',
        ];
        yield 'dotted value cannot replace malformed section' => [
            [
                self::PREFIX => ['settings' => false],
                self::PREFIX . '.settings.priceSource' => 'stripe',
            ],
            'configuration.type_invalid',
            'settings',
        ];
        yield 'unknown root key' => [
            [self::PREFIX => ['other' => true]],
            'configuration.option_unknown',
            'other',
        ];
        yield 'numeric root key' => [
            [self::PREFIX => [0 => true]],
            'configuration.option_unknown',
            '0',
        ];
        yield 'unknown setting' => [
            [self::PREFIX => ['settings' => ['other' => true]]],
            'configuration.option_unknown',
            'settings.other',
        ];
        yield 'removed expiration setting' => [
            [self::PREFIX => ['settings' => ['checkoutExpirationMinutes' => 60]]],
            'configuration.option_unknown',
            'settings.checkoutExpirationMinutes',
        ];
        yield 'unknown dotted option' => [
            [self::PREFIX . '.settings.other' => true],
            'configuration.option_unknown',
            'settings.other',
        ];
        yield 'settings section has wrong type' => [
            [self::PREFIX => ['settings' => 'stripe']],
            'configuration.type_invalid',
            'settings',
        ];
        yield 'removed checkout configuration group' => [
            [self::PREFIX => ['checkout' => ['sessionRequestFactory' => 'factory']]],
            'configuration.option_unknown',
            'checkout',
        ];
        yield 'price source boolean has wrong type' => [
            [self::PREFIX => ['settings' => ['priceSource' => false]]],
            'configuration.type_invalid',
            'settings.priceSource',
        ];
        yield 'price source integer has wrong type' => [
            [self::PREFIX => ['settings' => ['priceSource' => 0]]],
            'configuration.type_invalid',
            'settings.priceSource',
        ];
        yield 'price source array has wrong type' => [
            [self::PREFIX => ['settings' => ['priceSource' => []]]],
            'configuration.type_invalid',
            'settings.priceSource',
        ];
        yield 'empty price source is invalid' => [
            [self::PREFIX => ['settings' => ['priceSource' => '']]],
            'configuration.value_invalid',
            'settings.priceSource',
        ];
        yield 'unknown price source is invalid' => [
            [self::PREFIX => ['settings' => ['priceSource' => 'remote']]],
            'configuration.value_invalid',
            'settings.priceSource',
        ];
        yield 'currency boolean has wrong type' => [
            [self::PREFIX => ['settings' => ['currency' => false]]],
            'configuration.type_invalid',
            'settings.currency',
        ];
        yield 'currency must be uppercase' => [
            [self::PREFIX => ['settings' => ['currency' => 'eur']]],
            'configuration.value_invalid',
            'settings.currency',
        ];
        yield 'currency must be supported by Stripe' => [
            [self::PREFIX => ['settings' => ['currency' => 'XXX']]],
            'configuration.value_invalid',
            'settings.currency',
        ];
        yield 'shipping default must be boolean' => [
            [self::PREFIX => ['settings' => ['defaultRequiresShipping' => 'yes']]],
            'configuration.type_invalid',
            'settings.defaultRequiresShipping',
        ];
        yield 'UI mode must be a string' => [
            [self::PREFIX => ['settings' => ['uiMode' => false]]],
            'configuration.type_invalid',
            'settings.uiMode',
        ];
        yield 'UI mode must be supported' => [
            [self::PREFIX => ['settings' => ['uiMode' => 'inline']]],
            'configuration.value_invalid',
            'settings.uiMode',
        ];
        yield 'Checkout destination must be a string' => [
            [self::PREFIX => ['settings' => ['successDestination' => false]]],
            'configuration.type_invalid',
            'settings.successDestination',
        ];
        yield 'Checkout destination cannot have surrounding whitespace' => [
            [self::PREFIX => ['settings' => ['returnDestination' => ' /return ']]],
            'configuration.value_invalid',
            'settings.returnDestination',
        ];
        yield 'product section has wrong type' => [
            [self::PREFIX => ['products' => false]],
            'configuration.type_invalid',
            'products',
        ];
        yield 'product resolver must be typed or a Closure' => [
            [self::PREFIX => ['products' => ['resolver' => 'resolver']]],
            'configuration.type_invalid',
            'products.resolver',
        ];
        yield 'unknown product field mapping is rejected' => [
            [self::PREFIX => ['products' => ['fields' => ['stock' => 'stock']]]],
            'configuration.option_unknown',
            'products.fields.stock',
        ];
        yield 'invalid product field handle is rejected' => [
            [self::PREFIX => ['products' => ['fields' => ['price' => 'unit.price']]]],
            'configuration.value_invalid',
            'products.fields.price',
        ];
        yield 'duplicate image mappings are rejected' => [
            [self::PREFIX => ['products' => ['fields' => ['images' => ['gallery', 'gallery']]]]],
            'configuration.value_invalid',
            'products.fields.images',
        ];
        yield 'credential has wrong type' => [
            [self::PREFIX => ['stripe' => ['secretKey' => false]]],
            'configuration.type_invalid',
            'stripe.secretKey',
        ];
        yield 'blank credential is invalid' => [
            [self::PREFIX => ['stripe' => ['secretKey' => '']]],
            'configuration.value_invalid',
            'stripe.secretKey',
        ];
        yield 'credential with surrounding whitespace is invalid' => [
            [self::PREFIX => ['stripe' => ['publishableKey' => ' pk_test_private ']]],
            'configuration.value_invalid',
            'stripe.publishableKey',
        ];
        yield 'recognizable credential modes must match' => [
            [
                self::PREFIX => [
                    'stripe' => [
                        'secretKey' => 'sk_test_private',
                        'publishableKey' => 'pk_live_public',
                    ],
                ],
            ],
            'configuration.credential_mode_mismatch',
            'stripe.publishableKey',
        ];
        yield 'translation locale must be text' => [
            [self::PREFIX => ['translations' => [0 => ['label' => 'Label']]]],
            'configuration.translation_invalid',
            'translations',
        ];
        yield 'translation locale cannot be blank' => [
            [self::PREFIX => ['translations' => [' ' => ['label' => 'Label']]]],
            'configuration.translation_invalid',
            'translations',
        ];
        yield 'translation locale must be a safe locale identifier' => [
            [self::PREFIX => ['translations' => ['../pt' => ['area.label' => 'Loja']]]],
            'configuration.translation_invalid',
            'translations',
        ];
        yield 'translation locale value must be a map' => [
            [self::PREFIX => ['translations' => ['en' => 'Label']]],
            'configuration.translation_invalid',
            'translations.en',
        ];
        yield 'translation key cannot be blank' => [
            [self::PREFIX => ['translations' => ['en' => [' ' => 'Label']]]],
            'configuration.translation_invalid',
            'translations.en',
        ];
        yield 'translation value cannot be blank' => [
            [self::PREFIX => ['translations' => ['en' => ['label' => ' ']]]],
            'configuration.translation_invalid',
            'translations.en.label',
        ];
        yield 'translation value must use valid UTF-8' => [
            [self::PREFIX => ['translations' => ['en' => ['label' => "Invalid\xff"]]]],
            'configuration.translation_invalid',
            'translations.en.label',
        ];
        yield 'translation value must be single-line text' => [
            [self::PREFIX => ['translations' => ['en' => ['label' => "Invalid\nlabel"]]]],
            'configuration.translation_invalid',
            'translations.en.label',
        ];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidConfigurationProvider')]
    public function testReportsStableConfigurationFailures(
        array $options,
        string $errorCode,
        ?string $path,
    ): void {
        $report = $this->resolve($options);
        $error = $report->error();

        $this->assertFalse($report->isValid());
        $this->assertNull($report->configuration());
        $this->assertInstanceOf(ConfigurationException::class, $error);
        $this->assertSame($errorCode, $error->errorCode());
        $this->assertSame($path, $error->path());
        $this->assertStringNotContainsString('private', $error->getMessage());
    }

    public function testSortsValidTranslationOverridesDeterministically(): void
    {
        $translations = $this->resolve([
            self::PREFIX => [
                'translations' => [
                    'pt' => [
                        'tabs.settings' => 'Definições',
                        'area.label' => 'Stripe Checkout',
                    ],
                    'en' => ['diagnostics.description' => 'Checks'],
                ],
            ],
        ])->configurationOrFail()->translations();

        $this->assertSame([
            'en' => ['diagnostics.description' => 'Checks'],
            'pt' => [
                'area.label' => 'Stripe Checkout',
                'tabs.settings' => 'Definições',
            ],
        ], $translations);
    }

    public function testPublicSettingsExposeOnlySafeRelativeKeys(): void
    {
        $settings = $this->resolve([
            self::PREFIX => [
                'settings' => ['priceSource' => 'stripe'],
                'stripe' => ['secretKey' => 'sk_test_private'],
            ],
        ])->configurationOrFail()->settings();

        $this->assertSame(
            [
                'priceSource',
                'currency',
                'defaultRequiresShipping',
                'uiMode',
                'successDestination',
                'cancelDestination',
                'returnDestination',
                'billingAddressCollection',
                'individualNameCollection',
                'businessNameCollection',
                'phoneNumberCollection',
                'taxIdCollection',
                'termsOfServiceConsent',
                'promotionsConsent',
                'customFields',
                'allowPromotionCodes',
                'automaticTax',
                'taxBehavior',
                'cleanupCreationFailures',
                'creationFailureRetentionDays',
                'cleanupUnpaidOrders',
                'unpaidOrderRetentionDays',
            ],
            array_keys($settings->all()),
        );
        $this->assertNull($settings->setting('settings.priceSource'));
        $this->assertNull($settings->setting('stripe'));
        $this->assertNull($settings->setting('secretKey'));
        $this->assertNull($settings->setting('stripe.secretKey'));
    }

    public function testCredentialConfigurationCannotLeakThroughDebuggingOrSerialization(): void
    {
        $stripe = $this->resolve([
            self::PREFIX => [
                'stripe' => [
                    'secretKey' => 'sk_test_private-value',
                    'publishableKey' => 'pk_test_public-value',
                    'webhookSecret' => 'whsec_private-value',
                ],
            ],
        ])->configurationOrFail()->stripe();

        ob_start();
        var_dump($stripe);
        $debugOutput = ob_get_clean();

        $this->assertIsString($debugOutput);
        $this->assertStringNotContainsString('private-value', $debugOutput);
        $this->assertStringNotContainsString('public-value', $debugOutput);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Stripe credentials cannot be serialized.');

        serialize($stripe);
    }

    public function testConfigurationErrorsDoNotRetainCredentialArgumentsInTheirTrace(): void
    {
        $error = $this->resolve([
            self::PREFIX => [
                'stripe' => [
                    'secretKey' => ' sk_test_private-trace-value ',
                ],
            ],
        ])->error();

        $this->assertInstanceOf(ConfigurationException::class, $error);
        $this->assertStringNotContainsString(
            'private-trace-value',
            print_r($error->getTrace(), true),
        );
    }

    public function testConfigurationErrorsCanRetainASafeUnderlyingCause(): void
    {
        $cause = new LogicException('Internal storage context');
        $error = new ConfigurationException(
            'persistence.write_failed',
            'stripe-checkout',
            previous: $cause,
        );

        $this->assertSame($cause, $error->getPrevious());
        $this->assertStringNotContainsString($cause->getMessage(), $error->getMessage());
    }

    /** @param array<string, mixed> $options */
    private function resolve(#[SensitiveParameter] array $options): ConfigurationReport
    {
        return (new ConfigurationResolver())->resolve($options);
    }
}
