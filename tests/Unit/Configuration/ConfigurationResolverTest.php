<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Configuration;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationReport;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Configuration\CredentialMode;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Configuration\SettingSource;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use SensitiveParameter;

final class ConfigurationResolverTest extends TestCase
{
    private const PREFIX = 'programmatordev.stripe-checkout';

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
        $this->assertFalse($configuration->stripe()->hasSecretKey());
        $this->assertFalse($configuration->stripe()->hasPublishableKey());
        $this->assertFalse($configuration->stripe()->hasWebhookSecret());
    }

    public function testResolvesNestedPhpConfigurationAndProvenance(): void
    {
        $configuration = $this->resolve([
            self::PREFIX => [
                'settings' => [
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
        $this->assertNotNull($setting);
        $this->assertSame(SettingSource::Php, $setting->source());
        $this->assertTrue($setting->isLocked());
        $this->assertTrue($configuration->stripe()->hasSecretKey());
        $this->assertTrue($configuration->stripe()->hasPublishableKey());
        $this->assertTrue($configuration->stripe()->hasWebhookSecret());
        $this->assertSame(CredentialMode::Test, $configuration->stripe()->serverMode());
        $this->assertSame(CredentialMode::Test, $configuration->stripe()->publishableMode());
    }

    public function testResolvesFullyDottedConfiguration(): void
    {
        $configuration = $this->resolve([
            self::PREFIX . '.settings.priceSource' => 'stripe',
            self::PREFIX . '.stripe.secretKey' => 'custom-server-key',
            self::PREFIX . '.stripe.publishableKey' => 'custom-public-key',
        ])->configurationOrFail();

        $this->assertSame(PriceSource::Stripe, $configuration->settings()->priceSource());
        $this->assertSame(CredentialMode::Unknown, $configuration->stripe()->serverMode());
        $this->assertSame(CredentialMode::Unknown, $configuration->stripe()->publishableMode());
    }

    public function testResolvesKirbysNormalizedDottedConfiguration(): void
    {
        $configuration = $this->resolve([
            self::PREFIX => [
                'settings.priceSource' => 'stripe',
                'stripe.secretKey' => 'sk_live_server',
                'stripe.publishableKey' => 'pk_live_public',
            ],
        ])->configurationOrFail();

        $this->assertSame(PriceSource::Stripe, $configuration->settings()->priceSource());
        $this->assertSame(CredentialMode::Live, $configuration->stripe()->serverMode());
        $this->assertSame(CredentialMode::Live, $configuration->stripe()->publishableMode());
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
                    'pt' => ['z' => 'Z', 'a' => 'A'],
                    'en' => ['b' => 'B'],
                ],
            ],
        ])->configurationOrFail()->translations();

        $this->assertSame([
            'en' => ['b' => 'B'],
            'pt' => ['a' => 'A', 'z' => 'Z'],
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

        $this->assertSame(['priceSource'], array_keys($settings->all()));
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

    /** @param array<string, mixed> $options */
    private function resolve(#[SensitiveParameter] array $options): ConfigurationReport
    {
        return (new ConfigurationResolver())->resolve($options);
    }
}
