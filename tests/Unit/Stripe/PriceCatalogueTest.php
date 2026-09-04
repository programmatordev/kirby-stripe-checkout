<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Stripe;

use Kirby\Cache\MemoryCache;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceCatalogue;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceListResult;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceResolver;
use ProgrammatorDev\StripeCheckout\Test\Support\Stripe\FakePriceProvider;

final class PriceCatalogueTest extends TestCase
{
    public function testEmptyCatalogueLoadsEveryProviderPageAndCachesEligiblePrices(): void
    {
        $first = self::record('price_first', 'First');
        $second = self::record('price_second', 'Second');
        $ineligible = self::record('price_recurring', 'Recurring', type: 'recurring');
        $provider = new FakePriceProvider(pages: [
            'first' => new PriceListResult([$first], true),
            'price_first' => new PriceListResult([$ineligible, $second], false),
        ]);
        $cache = new MemoryCache();
        $catalogue = new PriceCatalogue($cache, $provider, new PriceResolver($provider));

        $result = $catalogue->search('EUR');

        $this->assertSame([null, 'price_first'], $provider->listCursors);
        $this->assertSame(['EUR', 'EUR'], $provider->listCurrencies);
        $this->assertSame(['price_first', 'price_second'], array_map(
            static fn($price): string => $price->priceId(),
            $result['items'],
        ));
        $this->assertSame(2, $result['total']);
        $this->assertNotNull($result['refreshedAt']);

        $catalogue->search('EUR');
        $this->assertSame([null, 'price_first'], $provider->listCursors);
    }

    public function testSearchFiltersTheLocalCacheAndPaginatesWithoutStripe(): void
    {
        $records = [];

        for ($index = 1; $index <= 25; $index++) {
            $records[] = self::record(
                sprintf('price_%02d', $index),
                sprintf('Product %02d', $index),
            );
        }

        $provider = new FakePriceProvider(pages: [
            'first' => new PriceListResult($records, false),
        ]);
        $catalogue = new PriceCatalogue(
            new MemoryCache(),
            $provider,
            new PriceResolver($provider),
        );

        $secondPage = $catalogue->search('EUR', page: 2);
        $match = $catalogue->search('EUR', 'Product 25');

        $this->assertCount(5, $secondPage['items']);
        $this->assertSame(2, $secondPage['pages']);
        $this->assertSame('price_25', $match['items'][0]->priceId());
        $this->assertSame([null], $provider->listCursors);
    }

    public function testLoadingAnExpiredCatalogueRefreshesItOnDemand(): void
    {
        $cache = new MemoryCache();
        $cachedProvider = new FakePriceProvider(pages: [
            'first' => new PriceListResult([self::record('price_current', 'Current')], false),
        ]);
        (new PriceCatalogue($cache, $cachedProvider, new PriceResolver($cachedProvider)))
            ->refresh('EUR');
        /** @var array<string, mixed> $cached */
        $cached = $cache->get('catalogue-eur');
        $cached['refreshedAt'] = time() - 86_401;
        $cache->set('catalogue-eur', $cached);

        $freshProvider = new FakePriceProvider(pages: [
            'first' => new PriceListResult([self::record('price_current', 'Current', amount: 2400)], false),
        ]);
        $catalogue = new PriceCatalogue($cache, $freshProvider, new PriceResolver($freshProvider));
        $result = $catalogue->load('EUR');

        $this->assertSame([null], $freshProvider->listCursors);
        $this->assertSame(2400, $result['items'][0]->unitPrice()->minorAmount());
    }

    public function testLoadingAnExpiredCatalogueRespectsTheFailureCooldown(): void
    {
        $cache = new MemoryCache();
        $provider = new FakePriceProvider(pages: [
            'first' => new PriceListResult([self::record('price_cached', 'Cached')], false),
        ]);
        $catalogue = new PriceCatalogue($cache, $provider, new PriceResolver($provider));
        $catalogue->refresh('EUR');
        /** @var array<string, mixed> $cached */
        $cached = $cache->get('catalogue-eur');
        $cached['refreshedAt'] = time() - 86_401;
        $cached['failedAt'] = time();
        $cached['error'] = 'prices.refresh_failed';
        $cache->set('catalogue-eur', $cached);

        $provider->listCursors = [];
        $result = $catalogue->load('EUR');

        $this->assertSame([], $provider->listCursors);
        $this->assertSame('price_cached', $result['items'][0]->priceId());
        $this->assertSame('prices.refresh_failed', $result['error']);
    }

    public function testFailedRefreshPreservesAndMarksTheLastGoodCatalogue(): void
    {
        $record = self::record('price_cached', 'Cached');
        $provider = new FakePriceProvider(pages: [
            'first' => new PriceListResult([$record], false),
        ]);
        $catalogue = new PriceCatalogue(
            new MemoryCache(),
            $provider,
            new PriceResolver($provider),
        );
        $catalogue->refresh('EUR');
        $provider->failLists = true;

        $failed = $catalogue->refresh('EUR');
        $cached = $catalogue->current('EUR');

        $this->assertSame('prices.refresh_failed', $failed['error']);
        $this->assertNotNull($failed['failedAt']);
        $this->assertSame('price_cached', $failed['items'][0]->priceId());
        $this->assertSame('price_cached', $cached['items'][0]->priceId());
    }

    public function testFreshResolutionDoesNotTrustTheCachedAmount(): void
    {
        $cached = self::record('price_current', 'Current');
        $fresh = self::record('price_current', 'Current', amount: 2400);
        $provider = new FakePriceProvider(
            pages: ['first' => new PriceListResult([$cached], false)],
            prices: ['price_current' => $fresh],
        );
        $resolver = new PriceResolver($provider);
        $catalogue = new PriceCatalogue(new MemoryCache(), $provider, $resolver);

        $listed = $catalogue->search('EUR')['items'][0];
        $resolved = $resolver->resolve('price_current', 'EUR');

        $this->assertSame(1600, $listed->unitPrice()->minorAmount());
        $this->assertSame(2400, $resolved->unitPrice()->minorAmount());
        $this->assertSame(['price_current'], $provider->retrievedIds);
    }

    private static function record(
        string $priceId,
        string $name,
        string $type = 'one_time',
        int $amount = 1600,
    ): PriceRecord {
        return new PriceRecord(
            priceId: $priceId,
            active: true,
            billingScheme: 'per_unit',
            currency: 'eur',
            hasCustomUnitAmount: false,
            nickname: null,
            hasRecurring: $type === 'recurring',
            taxBehavior: 'unspecified',
            hasTiers: false,
            tiersMode: null,
            hasQuantityTransform: false,
            type: $type,
            unitAmount: $amount,
            unitAmountDecimal: (string) $amount,
            productId: 'prod_' . substr($priceId, 6),
            productActive: true,
            productName: $name,
            productDescription: null,
            productImages: [],
            productTaxCode: null,
        );
    }
}
