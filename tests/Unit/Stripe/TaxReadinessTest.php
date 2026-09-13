<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Stripe;

use Kirby\Cache\MemoryCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxReadiness;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxSettingsRecord;
use ProgrammatorDev\StripeCheckout\Test\Support\Stripe\FakeTaxProvider;

final class TaxReadinessTest extends TestCase
{
    public function testReadsOncePerOperationAndReusesTheOneHourNativeCache(): void
    {
        $cache = new MemoryCache();
        $provider = new FakeTaxProvider(settings: self::record());
        $readiness = new TaxReadiness($cache, $provider, 'test-account', liveMode: false);
        $this->assertNull($readiness->cached());
        $settings = $readiness->requireReady();

        $this->assertTrue($settings->isActive());
        $this->assertFalse($settings->liveMode());
        $this->assertSame('txcd_test', $settings->defaultTaxCode());
        $this->assertSame('inferred_by_currency', $settings->defaultTaxBehavior());
        $this->assertSame([], $settings->missingFields());
        $this->assertSame($settings, $readiness->load());
        $this->assertSame($settings, $readiness->requireReady(requiresDefaultTaxCode: true, requiresDefaultTaxBehavior: true));
        $this->assertSame(1, $provider->settingsReads);
        $value = $cache->retrieve('test-account');
        $this->assertNotNull($value);
        $this->assertSame(3600, $value->expires() - $value->created());
        $nextOperation = new TaxReadiness($cache, $provider, 'test-account', liveMode: false);
        $this->assertSame($settings->toArray(), $nextOperation->load()->toArray());
        $this->assertSame(1, $provider->settingsReads);
    }

    #[DataProvider('defaultTaxBehaviors')]
    public function testPreservesStripeDefaultPoliciesWithoutCurrencyInference(?string $taxBehavior): void
    {
        $provider = new FakeTaxProvider(settings: self::record(taxBehavior: $taxBehavior));
        $readiness = new TaxReadiness(new MemoryCache(), $provider, 'test-account');

        $this->assertSame($taxBehavior, $readiness->requireReady()->defaultTaxBehavior());
    }

    /** @return iterable<string, array{?string}> */
    public static function defaultTaxBehaviors(): iterable
    {
        yield 'unconfigured' => [null];
        yield 'inclusive' => ['inclusive'];
        yield 'exclusive' => ['exclusive'];
        yield 'currency inferred by Stripe' => ['inferred_by_currency'];
    }

    public function testRefreshesExpiredReadinessRatherThanUsingStaleFactsOnFailure(): void
    {
        $cache = new MemoryCache();
        $provider = new FakeTaxProvider(settings: self::record());
        $first = new TaxReadiness($cache, $provider, 'test-account');
        $settings = $first->load();
        // Expire the native entry instead of sleeping or adding a plugin clock.
        $cache->set('test-account', $settings->toArray(), -1);
        $provider->failSettings = true;
        $expired = new TaxReadiness($cache, $provider, 'test-account');
        $this->assertNull($expired->cached());
        $this->assertFailure($expired, 'tax.settings_unavailable', 'stripe.taxSettings');
        $this->assertFailure($expired, 'tax.settings_unavailable', 'stripe.taxSettings');
        $this->assertSame(2, $provider->settingsReads);
        $this->assertNull($expired->cached());
        $provider->failSettings = false;
        $provider->settings = self::record(taxCode: 'txcd_updated');
        $next = new TaxReadiness($cache, $provider, 'test-account');
        $this->assertSame('txcd_updated', $next->load()->defaultTaxCode());
        $this->assertSame(3, $provider->settingsReads);
    }

    public function testPendingSettingsRemainReadableButCannotPassReadiness(): void
    {
        $provider = new FakeTaxProvider(settings: new TaxSettingsRecord(
            status: 'pending',
            liveMode: false,
            defaultTaxCode: null,
            defaultTaxBehavior: null,
            missingFields: ['head_office'],
        ));
        $readiness = new TaxReadiness(new MemoryCache(), $provider, 'test-account');
        $this->assertSame('pending', $readiness->load()->status());
        $this->assertSame(['head_office'], $readiness->load()->missingFields());
        $this->assertFailure($readiness, 'tax.settings_pending', 'stripe.taxSettings');
        $this->assertSame(1, $provider->settingsReads);
    }

    public function testRequiresAccountDefaultsOnlyForOmittedProductFacts(): void
    {
        $provider = new FakeTaxProvider(settings: self::record(taxCode: null, taxBehavior: null));
        $readiness = new TaxReadiness(new MemoryCache(), $provider, 'test-account');
        $this->assertTrue($readiness->requireReady()->isActive());
        $this->assertFailure(
            $readiness,
            'tax.default_code_missing',
            'stripe.taxSettings.defaults.taxCode',
            requiresDefaultTaxCode: true,
        );
        $this->assertFailure(
            $readiness,
            'tax.default_behavior_missing',
            'stripe.taxSettings.defaults.taxBehavior',
            requiresDefaultTaxBehavior: true,
        );
        $this->assertSame(1, $provider->settingsReads);
    }

    public function testCredentialModesAndCachePartitionsCannotBeMixed(): void
    {
        $cache = new MemoryCache();
        $provider = new FakeTaxProvider(settings: self::record());
        $test = new TaxReadiness($cache, $provider, 'test-account', liveMode: false);
        $test->requireReady();
        $live = new TaxReadiness($cache, $provider, 'live-account', liveMode: true);
        $this->assertNull($live->cached());
        $this->assertFailure($live, 'tax.settings_mode_mismatch', 'stripe.taxSettings');
        $this->assertSame(2, $provider->settingsReads);
        $this->assertNull($live->cached());
        $this->assertNotNull($test->cached());
    }

    #[DataProvider('invalidSettings')]
    public function testMalformedProviderFactsAreNotCached(TaxSettingsRecord $record): void
    {
        $provider = new FakeTaxProvider(settings: $record);
        $readiness = new TaxReadiness(new MemoryCache(), $provider, 'test-account');
        $this->assertFailure($readiness, 'tax.settings_invalid', 'stripe.taxSettings');
        $this->assertNull($readiness->cached());
    }

    /** @return iterable<string, array{TaxSettingsRecord}> */
    public static function invalidSettings(): iterable
    {
        yield 'unsupported status' => [self::record(status: 'unknown')];
        yield 'plugin sentinel is not a provider policy' => [self::record(taxBehavior: 'stripe_default')];
        yield 'automatic is not the API policy name' => [self::record(taxBehavior: 'automatic')];
        yield 'malformed default code' => [self::record(taxCode: 'wrong')];
        yield 'blank missing-field name' => [new TaxSettingsRecord('pending', false, null, null, [''])];
        yield 'multiline missing-field name' => [new TaxSettingsRecord('pending', false, null, null, ["head\noffice"])];
    }

    public function testMalformedCachedFactsAreRefetchedAndMissingCredentialsAreSafe(): void
    {
        $cache = new MemoryCache();
        $provider = new FakeTaxProvider(settings: self::record());
        $readiness = new TaxReadiness($cache, $provider, 'test-account');
        $settings = $readiness->load();
        $cache->set('test-account', [...$settings->toArray(), 'defaultTaxBehavior' => 'stripe_default']);
        $next = new TaxReadiness($cache, $provider, 'test-account');
        $this->assertNull($next->cached());
        $this->assertSame('inferred_by_currency', $next->requireReady()->defaultTaxBehavior());
        $this->assertSame(2, $provider->settingsReads);
        $this->assertFailure(
            new TaxReadiness(new MemoryCache(), null, 'missing'),
            'configuration.credential_missing',
            'stripe.secretKey',
        );
    }

    private function assertFailure(
        TaxReadiness $readiness,
        string $errorCode,
        string $path,
        bool $requiresDefaultTaxCode = false,
        bool $requiresDefaultTaxBehavior = false,
    ): void {
        try {
            $readiness->requireReady(
                requiresDefaultTaxCode: $requiresDefaultTaxCode,
                requiresDefaultTaxBehavior: $requiresDefaultTaxBehavior,
            );
            $this->fail('Expected tax readiness to fail.');
        } catch (ConfigurationException $error) {
            $this->assertSame($errorCode, $error->errorCode());
            $this->assertSame($path, $error->path());
            $this->assertStringNotContainsString('PRIVATE', $error->getMessage());
        }
    }

    private static function record(
        ?string $taxCode = 'txcd_test',
        ?string $taxBehavior = 'inferred_by_currency',
        string $status = 'active',
    ): TaxSettingsRecord {
        return new TaxSettingsRecord(
            status: $status,
            liveMode: false,
            defaultTaxCode: $taxCode,
            defaultTaxBehavior: $taxBehavior,
        );
    }
}
