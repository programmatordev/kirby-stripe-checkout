<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use ProgrammatorDev\StripeCheckout\Configuration\StripeConfiguration;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeCatalogue;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeListResult;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeRecord;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ProgrammatorDev\StripeCheckout\Test\Support\Stripe\FakeTaxProvider;

final class TaxRuntimeFactoryTest extends KirbyTestCase
{
    public function testUsesCredentialPartitionedNativeCacheWithoutProviderTraffic(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout.stripe.secretKey' => 'sk_test_tax_runtime',
        ]);
        $this->kirby = $this->environment->app();
        $credentials = new StripeConfiguration(secretKey: 'sk_test_tax_runtime', publishableKey: null, webhookSecret: null);
        $foreignCredentials = new StripeConfiguration(secretKey: 'sk_live_foreign', publishableKey: null, webhookSecret: null);
        $codesCache = $this->kirby->cache('programmatordev.stripe-checkout.taxCodes');
        $provider = new FakeTaxProvider(
            pages: ['first' => new TaxCodeListResult([new TaxCodeRecord('txcd_test', 'Test category', 'Test description')], false)],
        );
        (new TaxCodeCatalogue($codesCache, $provider, $credentials->secretKeyFingerprint('tax-codes')))->refresh();
        $provider->pages = ['first' => new TaxCodeListResult([new TaxCodeRecord('txcd_foreign', 'Foreign category', 'Foreign description')], false)];
        (new TaxCodeCatalogue($codesCache, $provider, $foreignCredentials->secretKeyFingerprint('tax-codes')))->refresh();
        $runtime = new RuntimeFactory($this->kirby);

        $this->assertSame('txcd_test', $runtime->taxCodeCatalogue()->cached()['items'][0]->id());
        $this->assertNull($runtime->taxCodeCatalogue()->find('txcd_foreign'));
        $this->assertNotNull($codesCache->retrieve($credentials->secretKeyFingerprint('tax-codes')));
    }

    public function testCreatingTheCatalogueDoesNotFetchStripe(): void
    {
        $runtime = new RuntimeFactory($this->kirby);

        $this->assertSame([], $runtime->taxCodeCatalogue()->cached()['items']);
    }
}
