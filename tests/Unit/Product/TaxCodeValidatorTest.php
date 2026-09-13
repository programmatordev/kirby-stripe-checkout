<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Product;

use Kirby\Cache\Cache;
use Kirby\Cache\MemoryCache;
use Kirby\Cms\Site;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Internal\TaxCodeValidator;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeCatalogue;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;
use ProgrammatorDev\StripeCheckout\Tax\TaxErrorCode;

final class TaxCodeValidatorTest extends TestCase
{
    public function testRepeatedAndDistinctClassificationsShareOneLastGoodSnapshot(): void
    {
        $cache = $this->createMock(Cache::class);
        $cache->expects($this->once())->method('get')->with('test')->willReturn([
            ...$this->snapshot(),
            'failedAt' => time(),
        ]);
        $validator = new TaxCodeValidator(new TaxCodeCatalogue($cache, null, 'test'));
        $context = $this->context();

        for ($index = 0; $index < 50; $index++) {
            $validator->validate(new TaxCode('txcd_first'), $context);
            $validator->validate(new TaxCode('txcd_second'), $context);
        }

        $this->expectException(InvalidProductException::class);
        $this->expectExceptionMessage(TaxErrorCode::CODE_INVALID);
        $validator->validate(new TaxCode('txcd_unknown'), $context);
    }

    public function testOmittedAndInactiveClassificationDoNotReadTheCache(): void
    {
        $cache = $this->createMock(Cache::class);
        $cache->expects($this->never())->method('get');
        $validator = new TaxCodeValidator(new TaxCodeCatalogue($cache, null, 'test'));
        $validator->validate(null, $this->context());
        $validator->validate(new TaxCode('txcd_first'), $this->context(automaticTax: false));
        $validator->validate(new TaxCode('txcd_first'), $this->context(priceSource: PriceSource::Stripe));
    }

    public function testLaterOperationsReadAnUpdatedSnapshot(): void
    {
        $cache = new MemoryCache();
        $cache->set('test', $this->snapshot());
        $catalogue = new TaxCodeCatalogue($cache, null, 'test');
        $validator = new TaxCodeValidator($catalogue);
        $context = $this->context();
        $code = new TaxCode('txcd_first');
        $validator->validate($code, $context);
        $cache->set('test', [...$this->snapshot(), 'items' => []]);
        $validator->validate($code, $context);

        $this->expectException(InvalidProductException::class);
        $this->expectExceptionMessage(TaxErrorCode::CODE_INVALID);
        (new TaxCodeValidator($catalogue))->validate($code, $context);
    }

    private function context(bool $automaticTax = true, PriceSource $priceSource = PriceSource::Kirby): ProductResolutionContext
    {
        $settings = (new ConfigurationResolver())->resolve([
            'programmatordev.stripe-checkout.settings.currency' => 'EUR',
            'programmatordev.stripe-checkout.settings.automaticTax' => $automaticTax,
            'programmatordev.stripe-checkout.settings.priceSource' => $priceSource->value,
        ])->configurationOrFail()->settings();

        return new ProductResolutionContext(
            site: $this->createStub(Site::class),
            user: null,
            languageCode: null,
            locale: 'en_US',
            priceSource: $priceSource,
            settings: $settings,
        );
    }

    /** @return array{items: list<array{id: string, name: string, description: string}>, refreshedAt: int, failedAt: null} */
    private function snapshot(): array
    {
        return [
            'items' => [
                ['id' => 'txcd_first', 'name' => 'First', 'description' => 'First classification'],
                ['id' => 'txcd_second', 'name' => 'Second', 'description' => 'Second classification'],
            ],
            'refreshedAt' => time(),
            'failedAt' => null,
        ];
    }
}
