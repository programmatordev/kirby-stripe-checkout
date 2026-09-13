<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Stripe;

use Kirby\Cache\MemoryCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeCatalogue;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeListResult;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeRecord;
use ProgrammatorDev\StripeCheckout\Tax\TaxCodeReference;
use ProgrammatorDev\StripeCheckout\Test\Support\Stripe\FakeTaxProvider;

final class TaxCodeCatalogueTest extends TestCase
{
    public function testReadsAllPagesAndPreservesProviderDescriptions(): void
    {
        $provider = new FakeTaxProvider(pages: [
            'first' => new TaxCodeListResult([self::record('txcd_first', 'Zebra')], true),
            'txcd_first' => new TaxCodeListResult([self::record('txcd_second', 'Alpha')], false),
        ]);
        $catalogue = new TaxCodeCatalogue(new MemoryCache(), $provider, 'test-account');
        $result = $catalogue->load();

        $this->assertSame([null, 'txcd_first'], $provider->listCursors);
        $this->assertSame(['txcd_second', 'txcd_first'], array_map(static fn($code): string => $code->id(), $result['items']));
        $this->assertTrue($result['items'][0]->isConfirmed());
        $this->assertSame('Alpha description', $result['items'][0]->providerDescription());
        $this->assertSame('Alpha', $result['items'][0]->label());
        $this->assertNotNull($result['refreshedAt']);
        $this->assertNull($result['error']);
        $this->assertSame('Alpha', $catalogue->find('txcd_second')?->providerName());
        $this->assertNull($catalogue->find('txcd_missing'));
        $catalogue->load();
        $this->assertSame([null, 'txcd_first'], $provider->listCursors);
    }

    public function testSearchAndPaginationUseCachedFacts(): void
    {
        $records = [];

        for ($index = 1; $index <= 25; $index++) {
            $records[] = self::record('txcd_' . $index, sprintf('Category %02d', $index));
        }

        $provider = new FakeTaxProvider(pages: ['first' => new TaxCodeListResult($records, false)]);
        $catalogue = new TaxCodeCatalogue(new MemoryCache(), $provider, 'test-account');
        $page = $catalogue->search(page: 99);
        $match = $catalogue->search('CATEGORY 25 DESCRIPTION', page: 0);

        $this->assertCount(5, $page['items']);
        $this->assertSame(25, $page['total']);
        $this->assertSame(2, $page['page']);
        $this->assertSame(2, $page['pages']);
        $this->assertSame('txcd_25', $match['items'][0]->id());
        $this->assertSame(1, $match['page']);
        $this->assertSame([null], $provider->listCursors);
    }

    public function testMonthlyRefreshRetainsLastGoodDataAndBacksOffForADay(): void
    {
        $cache = new MemoryCache();
        $provider = new FakeTaxProvider(pages: ['first' => new TaxCodeListResult([self::record()], false)]);
        $catalogue = new TaxCodeCatalogue($cache, $provider, 'test-account');
        $catalogue->load();
        /** @var array<string, mixed> $state */
        $state = $cache->get('test-account');
        $state['refreshedAt'] = time() - 30 * 86_400 - 1;
        $cache->set('test-account', $state);
        $provider->failLists = true;
        $failed = $catalogue->load();

        $this->assertSame('tax_codes.refresh_failed', $failed['error']);
        $this->assertNotNull($failed['failedAt']);
        $this->assertSame('txcd_test', $failed['items'][0]->id());
        $this->assertSame($state['refreshedAt'], $failed['refreshedAt']);
        $catalogue->load();
        $this->assertSame([null, null], $provider->listCursors);
        $this->assertNotNull($catalogue->find('txcd_test'));
        /** @var array<string, mixed> $state */
        $state = $cache->get('test-account');
        $state['failedAt'] = time() - 86_401;
        $cache->set('test-account', $state);
        $provider->failLists = false;
        $refreshed = $catalogue->load();

        $this->assertSame([null, null, null], $provider->listCursors);
        $this->assertNull($refreshed['failedAt']);
        $this->assertNull($refreshed['error']);
    }

    public function testManualRefreshBypassesBothAgeAndFailureBackoff(): void
    {
        $provider = new FakeTaxProvider(pages: ['first' => new TaxCodeListResult([self::record()], false)]);
        $catalogue = new TaxCodeCatalogue(new MemoryCache(), $provider, 'test-account');
        $catalogue->load();
        $provider->failLists = true;
        $catalogue->refresh();
        $provider->failLists = false;
        $provider->pages = ['first' => new TaxCodeListResult([self::record('txcd_replacement', 'Updated')], false)];
        $updated = $catalogue->refresh();

        $this->assertSame([null, null, null], $provider->listCursors);
        $this->assertSame('Updated', $updated['items'][0]->providerName());
        $this->assertNull($catalogue->find('txcd_test'));
        $this->assertNull($updated['error']);
    }

    public function testCachedLookupsNeverStartAProviderReadEvenWhenTheCatalogueIsOld(): void
    {
        $cache = new MemoryCache();
        $provider = new FakeTaxProvider(pages: ['first' => new TaxCodeListResult([self::record()], false)]);
        $catalogue = new TaxCodeCatalogue($cache, $provider, 'test-account');
        $this->assertNull($catalogue->find('txcd_test'));
        $this->assertSame([], $provider->listCursors);
        $catalogue->load();
        /** @var array<string, mixed> $state */
        $state = $cache->get('test-account');
        $state['refreshedAt'] = time() - 31 * 86_400;
        $cache->set('test-account', $state);
        $this->assertNotNull($catalogue->find('txcd_test'));
        $this->assertSame([null], $provider->listCursors);
    }

    public function testFailedFirstLoadWaitsBeforeRetryingAndReturnsNoProviderMessage(): void
    {
        $provider = new FakeTaxProvider();
        $provider->failLists = true;
        $catalogue = new TaxCodeCatalogue(new MemoryCache(), $provider, 'test-account');
        $first = $catalogue->load();

        $this->assertSame($first, $catalogue->load());
        $this->assertSame([], $first['items']);
        $this->assertSame('tax_codes.refresh_failed', $first['error']);
        $this->assertSame([null], $provider->listCursors);
        $this->assertSame('tax_codes.refresh_failed', (new TaxCodeCatalogue(new MemoryCache(), null, 'missing'))->load()['error']);
    }

    /** @param array<string, TaxCodeListResult> $pages */
    #[DataProvider('invalidPages')]
    public function testIncompleteOrInvalidProviderPagesDoNotReplaceLastGoodFacts(array $pages): void
    {
        $provider = new FakeTaxProvider(pages: ['first' => new TaxCodeListResult([self::record()], false)]);
        $catalogue = new TaxCodeCatalogue(new MemoryCache(), $provider, 'test-account');
        $original = $catalogue->load();
        $provider->pages = $pages;
        $failed = $catalogue->refresh();

        $this->assertSame('tax_codes.refresh_failed', $failed['error']);
        $this->assertSame($original['refreshedAt'], $failed['refreshedAt']);
        $this->assertSame('txcd_test', $failed['items'][0]->id());
    }

    /** @return iterable<string, array{array<string, TaxCodeListResult>}> */
    public static function invalidPages(): iterable
    {
        yield 'empty intermediate page' => [['first' => new TaxCodeListResult([], true)]];
        yield 'repeated cursor' => [[
            'first' => new TaxCodeListResult([self::record()], true),
            'txcd_test' => new TaxCodeListResult([self::record()], true),
        ]];
        yield 'invalid provider ID' => [['first' => new TaxCodeListResult([self::record('wrong')], false)]];
        yield 'blank provider name' => [['first' => new TaxCodeListResult([self::record(name: '')], false)]];
        yield 'invalid UTF-8 description' => [['first' => new TaxCodeListResult([new TaxCodeRecord('txcd_test', 'Test', "\xFF")], false)]];
    }

    public function testMalformedCachedDataIsDiscardedAndAccountsAreIsolated(): void
    {
        $cache = new MemoryCache();
        $provider = new FakeTaxProvider(pages: ['first' => new TaxCodeListResult([self::record()], false)]);
        $first = new TaxCodeCatalogue($cache, $provider, 'first-account');
        $first->load();
        $second = new TaxCodeCatalogue($cache, null, 'second-account');
        $this->assertSame([], $second->cached()['items']);
        /** @var array<string, mixed> $state */
        $state = $cache->get('first-account');
        $state['items'] = [[
            'id' => 'wrong',
            'name' => 'Test',
            'description' => 'Test',
        ]];
        $cache->set('first-account', $state);
        $this->assertSame([], $first->cached()['items']);
        $this->assertNull($first->cached()['refreshedAt']);
        $first->load();
        $this->assertSame([null, null], $provider->listCursors);
    }

    public function testLocalLabelsDoNotReplaceProviderIdentity(): void
    {
        $reference = new TaxCodeReference('txcd_test', 'My category');
        $this->assertSame('My category', $reference->label());
        $this->assertSame('txcd_test', $reference->id());
        $this->assertSame('', $reference->providerName());
        $this->assertSame('', $reference->providerDescription());
        $this->assertFalse($reference->isConfirmed());
        $this->assertSame('txcd_test', (new TaxCodeReference('txcd_test'))->label());
        $this->expectException(ConfigurationException::class);
        new TaxCodeReference('txcd_test', "Bad\nlabel");
    }

    private static function record(string $id = 'txcd_test', string $name = 'Test'): TaxCodeRecord
    {
        return new TaxCodeRecord($id, $name, $name . ' description');
    }
}
