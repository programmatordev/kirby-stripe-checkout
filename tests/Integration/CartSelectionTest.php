<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Uuid\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartEntry;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartMutator;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SelectionCanonicalizer;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Test\Support\Cart\InMemoryCartStore;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

final class CartSelectionTest extends KirbyTestCase
{
    #[DataProvider('uuidFormats')]
    public function testUsesExistingProductResolutionAndConfiguredKirbyIds(string|bool $format, string $pattern): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'content.uuid' => $format,
            'programmatordev.stripe-checkout' => [
                'settings' => ['currency' => 'EUR', 'defaultRequiresShipping' => false],
            ],
        ]);
        $this->kirby = $this->environment->app();
        $page = $this->kirby->site()->createChild([
            'slug' => 'shirt',
            'template' => 'default',
            'content' => ['title' => 'Shirt', 'price' => '16.00'],
        ])->changeStatus('unlisted');
        $plugin = new StripeCheckout($this->kirby);
        $selections = new SelectionCanonicalizer($plugin->resolveProduct(...));
        $store = new InMemoryCartStore(new CartSnapshot(Uuid::generate(), 'initial-revision', [], 100, 100));
        $cart = new CartMutator($store, $selections, Uuid::generate(...));
        $first = $cart->add(new ProductRequest($page->id(), 2));
        $merged = $cart->add(new ProductRequest($page->uuid()->toString(), 3));

        $this->assertCount(1, $merged->entries());
        $this->assertSame($first->entries()[0]->id(), $merged->entries()[0]->id());
        $this->assertSame($page->uuid()->toString(), $merged->entries()[0]->request()->reference());
        $this->assertSame(5, $merged->entries()[0]->request()->quantity());
        $this->assertMatchesRegularExpression($pattern, $merged->id());
        $this->assertMatchesRegularExpression($pattern, $merged->entries()[0]->id());
        $this->assertNotSame($first->revision(), $merged->revision());
        $this->assertEquals(
            array_map(static fn(CartEntry $entry): ProductRequest => $entry->request(), $merged->entries()),
            $selections->direct([
                ['reference' => $page->id(), 'quantity' => 2],
                ['reference' => $page->uuid()->toString(), 'quantity' => 3],
            ]),
        );
    }

    /** @return iterable<string, array{string|bool, string}> */
    public static function uuidFormats(): iterable
    {
        yield 'Kirby default' => [true, '/^[a-z0-9]{16}$/'];
        yield 'configured v4' => ['uuid-v4', '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/'];
    }
}
