<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Collection\TaxIdCollection;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Configuration\PageSettings;
use ProgrammatorDev\StripeCheckout\Configuration\SettingSource;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;

final class TaxSettingsTest extends TestCase
{
    private const PREFIX = 'programmatordev.stripe-checkout.settings';

    public function testDefaultsDoNotEnableTaxOrGuessItsInclusionPolicy(): void
    {
        $settings = (new ConfigurationResolver())->resolve([])->configurationOrFail()->settings();

        $this->assertFalse($settings->automaticTax());
        $this->assertSame(TaxBehavior::StripeDefault, $settings->taxBehavior());
        $this->assertSame(SettingSource::InternalDefault, $settings->setting('automaticTax')?->source());
        $this->assertSame(SettingSource::InternalDefault, $settings->setting('taxBehavior')?->source());
    }

    #[DataProvider('taxBehaviors')]
    public function testPageValuesAndPhpLocksUseTheSamePolicy(TaxBehavior $taxBehavior): void
    {
        $resolver = new ConfigurationResolver();
        $page = new PageSettings(automaticTax: 'true', taxBehavior: $taxBehavior->value);
        $settings = $resolver->resolve([], $page)->configurationOrFail()->settings();

        $this->assertTrue($settings->automaticTax());
        $this->assertSame($taxBehavior, $settings->taxBehavior());
        $this->assertSame(SettingSource::Page, $settings->setting('taxBehavior')?->source());

        $locked = $resolver->resolve([
            self::PREFIX . '.automaticTax' => false,
            self::PREFIX . '.taxBehavior' => $taxBehavior->value,
        ], $page)->configurationOrFail()->settings();

        $this->assertFalse($locked->automaticTax());
        $this->assertSame($taxBehavior, $locked->taxBehavior());
        $this->assertTrue($locked->setting('automaticTax')?->isLocked());
        $this->assertTrue($locked->setting('automaticTax')->shadowedValue());
        $this->assertTrue($locked->setting('taxBehavior')?->isLocked());
        $this->assertSame($taxBehavior->value, $locked->setting('taxBehavior')->shadowedValue());
    }

    /** @return iterable<string, array{TaxBehavior}> */
    public static function taxBehaviors(): iterable
    {
        foreach (TaxBehavior::cases() as $taxBehavior) {
            yield $taxBehavior->value => [$taxBehavior];
        }
    }

    public function testNullPhpValuesLeavePageValuesUnlocked(): void
    {
        $settings = (new ConfigurationResolver())->resolve([
            self::PREFIX . '.automaticTax' => null,
            self::PREFIX . '.taxBehavior' => null,
        ], new PageSettings(automaticTax: true, taxBehavior: 'inclusive'))->configurationOrFail()->settings();

        $this->assertTrue($settings->automaticTax());
        $this->assertSame(TaxBehavior::Inclusive, $settings->taxBehavior());
        $this->assertFalse($settings->setting('automaticTax')?->isLocked());
        $this->assertFalse($settings->setting('taxBehavior')?->isLocked());
    }

    public function testBlankPageValuesUseDefaultsAndTaxIdCollectionRemainsIndependent(): void
    {
        $settings = (new ConfigurationResolver())->resolve([], new PageSettings(
            automaticTax: '',
            taxBehavior: '',
            taxIdCollection: 'optional',
        ))->configurationOrFail()->settings();

        $this->assertFalse($settings->automaticTax());
        $this->assertSame(TaxBehavior::StripeDefault, $settings->taxBehavior());
        $this->assertSame(TaxIdCollection::Optional, $settings->taxIdCollection());
    }

    #[DataProvider('invalidPhpValues')]
    public function testInvalidPhpValuesReturnTheExactSafePath(string $name, mixed $value, string $errorCode): void
    {
        $error = (new ConfigurationResolver())->resolve([
            self::PREFIX . '.' . $name => $value,
        ])->error();

        $this->assertNotNull($error);
        $this->assertSame($errorCode, $error->errorCode());
        $this->assertSame('settings.' . $name, $error->path());
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function invalidPhpValues(): iterable
    {
        yield 'text toggle' => ['automaticTax', 'true', 'configuration.type_invalid'];
        yield 'numeric toggle' => ['automaticTax', 1, 'configuration.type_invalid'];
        yield 'boolean policy' => ['taxBehavior', false, 'configuration.type_invalid'];
        yield 'unknown policy' => ['taxBehavior', 'automatic', 'configuration.value_invalid'];
        yield 'blank policy' => ['taxBehavior', '', 'configuration.value_invalid'];
    }

    #[DataProvider('invalidPageValues')]
    public function testInvalidPageValuesRejectTransportCoercion(string $name, mixed $value): void
    {
        try {
            new PageSettings(...[$name => $value]);
            $this->fail('Expected invalid tax settings to be rejected.');
        } catch (ConfigurationException $error) {
            $this->assertSame('persistence.content_invalid', $error->errorCode());
            $this->assertSame('settings.' . $name, $error->path());
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidPageValues(): iterable
    {
        yield 'numeric toggle' => ['automaticTax', 1];
        yield 'yes toggle' => ['automaticTax', 'yes'];
        yield 'unknown policy' => ['taxBehavior', 'automatic'];
        yield 'boolean policy' => ['taxBehavior', true];
    }
}
