<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Configuration\StripeConfiguration;
use ProgrammatorDev\StripeCheckout\Money\MoneySnapshot;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Stripe\Price\StripePrice;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

final class PriceRuntimeFactoryTest extends KirbyTestCase
{
    #[DataProvider('credentialProvider')]
    public function testNativeCatalogueUsesOnlyTheConfiguredCredential(?string $secretKey): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout.stripe.secretKey' => $secretKey,
        ]);
        $this->kirby = $this->environment->app();
        $cache = $this->kirby->cache('programmatordev.stripe-checkout.prices');
        $keys = ['sk_test_price_first', 'sk_live_price_second', 'sk_test_price_rotated'];

        foreach ($keys as $key) {
            $stripe = new StripeConfiguration(secretKey: $key, publishableKey: null, webhookSecret: null);
            $price = new StripePrice(
                priceId: 'price_shared',
                productId: 'prod_shared',
                name: $key,
                unitPrice: new MoneySnapshot('EUR', 1600),
                taxBehavior: 'exclusive',
            );
            $cache->set($stripe->secretKeyFingerprint('prices') . '-eur', [
                'items' => [$price->toArray()],
                'refreshedAt' => time(),
                'failedAt' => null,
                'error' => null,
            ]);
        }

        $catalogue = (new RuntimeFactory($this->kirby))->stripePriceCatalogue();
        $items = $catalogue->cached('EUR')['items'];

        if ($secretKey === null) {
            $this->assertSame([], $items);

            return;
        }

        $this->assertCount(1, $items);
        $this->assertSame($secretKey, $items[0]->name());
        $this->assertSame($secretKey, $catalogue->load('EUR')['items'][0]->name());
        $this->assertSame([], $catalogue->cached('USD')['items']);
    }

    /** @return array<string, array{?string}> */
    public static function credentialProvider(): array
    {
        return [
            'test account' => ['sk_test_price_first'],
            'live account' => ['sk_live_price_second'],
            'rotated test key' => ['sk_test_price_rotated'],
            'unconfigured' => [null],
        ];
    }
}
