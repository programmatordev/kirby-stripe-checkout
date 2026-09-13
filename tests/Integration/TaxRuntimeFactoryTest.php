<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use ProgrammatorDev\StripeCheckout\Configuration\StripeConfiguration;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeCatalogue;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeListResult;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxReadiness;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxSettingsRecord;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\Stripe\FakeTaxProvider;

final class TaxRuntimeFactoryTest extends KirbyTestCase
{
    public function testUsesCredentialPartitionedNativeCachesWithoutProviderTraffic(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout.stripe.secretKey' => 'sk_test_tax_runtime',
        ]);
        $this->kirby = $this->environment->app();
        $credentials = new StripeConfiguration(secretKey: 'sk_test_tax_runtime', publishableKey: null, webhookSecret: null);
        $foreignCredentials = new StripeConfiguration(secretKey: 'sk_live_foreign', publishableKey: null, webhookSecret: null);
        $codesCache = $this->kirby->cache('programmatordev.stripe-checkout.taxCodes');
        $settingsCache = $this->kirby->cache('programmatordev.stripe-checkout.taxSettings');
        $provider = new FakeTaxProvider(
            pages: ['first' => new TaxCodeListResult([new TaxCodeRecord('txcd_test', 'Test category', 'Test description')], false)],
            settings: new TaxSettingsRecord('active', false, 'txcd_test', 'inclusive'),
        );
        (new TaxCodeCatalogue($codesCache, $provider, $credentials->secretKeyFingerprint('tax-codes')))->refresh();
        (new TaxReadiness($settingsCache, $provider, $credentials->secretKeyFingerprint('tax-settings')))->load();
        $provider->pages = ['first' => new TaxCodeListResult([new TaxCodeRecord('txcd_foreign', 'Foreign category', 'Foreign description')], false)];
        $provider->settings = new TaxSettingsRecord('active', true, 'txcd_foreign', 'exclusive');
        (new TaxCodeCatalogue($codesCache, $provider, $foreignCredentials->secretKeyFingerprint('tax-codes')))->refresh();
        (new TaxReadiness($settingsCache, $provider, $foreignCredentials->secretKeyFingerprint('tax-settings')))->load();
        $runtime = new RuntimeFactory($this->kirby);

        $this->assertSame('txcd_test', $runtime->taxCodeCatalogue()->cached()['items'][0]->id());
        $this->assertNull($runtime->taxCodeCatalogue()->find('txcd_foreign'));
        $this->assertSame('txcd_test', $runtime->taxReadiness()->requireReady()->defaultTaxCode());
        $this->assertFalse($runtime->taxReadiness()->load()->liveMode());
        $this->assertSame($runtime->taxReadiness(), $runtime->taxReadiness());
        $this->assertNotNull($codesCache->retrieve($credentials->secretKeyFingerprint('tax-codes')));
        $this->assertNotNull($settingsCache->retrieve($credentials->secretKeyFingerprint('tax-settings')));
    }

    public function testCreatingTheReadOnlyServicesDoesNotFetchStripe(): void
    {
        $runtime = new RuntimeFactory($this->kirby);

        $this->assertSame([], $runtime->taxCodeCatalogue()->cached()['items']);
        $this->assertNull($runtime->taxReadiness()->cached());
    }
}
