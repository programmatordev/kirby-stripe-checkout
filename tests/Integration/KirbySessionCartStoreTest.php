<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Http\Cookie;
use Kirby\Session\AutoSession;
use Kirby\Session\Sessions;
use Kirby\Uuid\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartMutationException;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartMutator;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Cart\Internal\KirbySessionCartStore;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SelectionCanonicalizer;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use RuntimeException;

final class KirbySessionCartStoreTest extends KirbyTestCase
{
    public function testIndependentRequestsReloadUnderTheLockAndDoNotLoseAdds(): void
    {
        $session = $this->sessions()->create();
        $first = new KirbySessionCartStore($session, Uuid::generate(...));
        $initial = $first->read();
        $token = $session->token();
        $this->assertNotNull($token);
        // Both requests initially see the same state; separate native stores
        // ensure this isn't merely testing one object's in-memory cache.
        $secondSession = $this->sessions()->get($token);
        $second = new KirbySessionCartStore($secondSession, Uuid::generate(...));
        $this->assertSame($initial->revision(), $second->read()->revision());
        $this->mutator($first)->add(new ProductRequest('shirt', 2));
        $merged = $this->mutator($second)->add(new ProductRequest('shirt', 3));
        $this->assertSame(5, $merged->entries()[0]->request()->quantity());
        $this->assertSame($merged->revision(), $first->read()->revision());
        $this->assertSame($initial->id(), $merged->id());

        try {
            $this->mutator($first)->clear($initial->revision());
            $this->fail('Expected a conflict.');
        } catch (CartMutationException) {
            $this->assertSame(5, $second->read()->entries()[0]->request()->quantity());
        }

        $session->destroy();
    }

    #[DataProvider('invalidPayloads')]
    public function testMalformedPayloadResetsOnlyTheCartAndRecordsASafeDiagnostic(mixed $payload): void
    {
        $session = $this->kirby->session();
        $session->data()->set(['other' => 'keep', KirbySessionCartStore::KEY => $payload]);
        $codes = [];
        $store = new KirbySessionCartStore($session, Uuid::generate(...), static function (string $code) use (&$codes): void {
            $codes[] = $code;
        });
        $snapshot = $store->read();
        $this->assertSame([], $snapshot->entries());
        $this->assertSame('keep', $session->data()->get('other'));
        $this->assertSame(['cart.session_reset'], $codes);
        $this->assertSame($snapshot->revision(), $store->read()->revision());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPayloads(): iterable
    {
        yield 'scalar' => ['private-data'];
        yield 'empty' => [[]];
        $base = ['schema' => 1, 'id' => 'cart', 'revision' => 'revision', 'createdAt' => 1, 'updatedAt' => 1, 'destinationCountry' => null, 'entries' => []];
        yield 'unknown schema' => [array_replace($base, ['schema' => 2])];
        yield 'impossible times' => [array_replace($base, ['updatedAt' => 0])];
        yield 'protected fields' => [array_replace($base, ['entries' => [['id' => 'item', 'request' => ['reference' => 'shirt', 'price' => '1.00']]]])];
        yield 'string quantity' => [array_replace($base, ['entries' => [['id' => 'item', 'request' => ['reference' => 'shirt', 'quantity' => '1']]]])];
        $entry = ['id' => 'item', 'request' => ['reference' => 'shirt']];
        yield 'duplicate entries' => [array_replace($base, ['entries' => [$entry, $entry]])];
    }

    public function testCallbackFailureReleasesTheLockWithoutWritingPartialState(): void
    {
        $session = $this->sessions()->create();
        $store = new KirbySessionCartStore($session, Uuid::generate(...));
        $initial = $store->read();

        try {
            $store->mutate(static function (CartSnapshot $current): CartSnapshot {
                throw new RuntimeException('Simulated validation failure');
            });
            $this->fail('Expected failure');
        } catch (RuntimeException) {
            $token = $session->token();
            $this->assertNotNull($token);
            $other = new KirbySessionCartStore($this->sessions()->get($token), Uuid::generate(...));
            $this->assertSame($initial->revision(), $other->read()->revision());
        }

        $session->destroy();
    }

    public function testCompareAndClearPreservesNewerOrDifferentCarts(): void
    {
        $store = new KirbySessionCartStore($this->kirby->session(), Uuid::generate(...));
        $mutator = $this->mutator($store);
        $first = $mutator->add(new ProductRequest('shirt'));
        $second = $mutator->add(new ProductRequest('shirt'));
        $this->assertSame($second->revision(), $mutator->clearIfMatches($first->id(), $first->revision())->revision());
        $this->assertSame($second->revision(), $mutator->clearIfMatches('other-cart', $second->revision())->revision());
        $cleared = $mutator->clearIfMatches($second->id(), $second->revision());
        $this->assertSame([], $cleared->entries());
        $this->assertSame($second->id(), $cleared->id());
    }

    public function testSeparateBrowserSessionsNeverShareCarts(): void
    {
        $firstSession = $this->sessions()->create();
        $secondSession = $this->sessions()->create();
        $first = new KirbySessionCartStore($firstSession, Uuid::generate(...));
        $second = new KirbySessionCartStore($secondSession, Uuid::generate(...));
        $this->mutator($first)->add(new ProductRequest('shirt'));
        $this->assertSame([], $second->read()->entries());
        $this->assertNotSame($first->read()->id(), $second->read()->id());
        $firstSession->destroy();
        $secondSession->destroy();
    }

    public function testOverlappingProcessesSerializeNativeSessionWrites(): void
    {
        $session = $this->sessions()->create();
        $store = new KirbySessionCartStore($session, Uuid::generate(...));
        $store->read();
        $process = null;
        $pipes = [];

        try {
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/Cart/concurrent-add.php'], [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes);
            $this->assertIsResource($process);
            fwrite($pipes[0], json_encode(['root' => $this->environment->workspace()->roots()['sessions'], 'token' => $session->token()], JSON_THROW_ON_ERROR) . "\n");
            $this->assertSame("ready\n", $this->readWorkerLine($pipes[1]));
            $store->mutate(function (CartSnapshot $current) use ($pipes): CartSnapshot {
                fwrite($pipes[0], "go\n");
                $read = [$pipes[1]];
                $write = $except = [];
                // The worker has reached its mutation but cannot finish while
                // the parent holds Kirby's lock. No timing-dependent lost-add assertion.
                $this->assertSame(0, stream_select($read, $write, $except, 0, 100000));

                return new CartSnapshot($current->id(), 'parent-revision', [
                    new \ProgrammatorDev\StripeCheckout\Cart\Internal\CartEntry('parent-item', new ProductRequest('shirt', 2)),
                ], $current->createdAt(), $current->updatedAt());
            });
            $this->assertSame("5\n", $this->readWorkerLine($pipes[1]));
            $this->assertSame(5, $store->read()->entries()[0]->request()->quantity());
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);

                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }

                proc_close($process);
            }

            $session->destroy();
        }
    }

    /** @param resource $pipe */
    private function readWorkerLine($pipe): string|false
    {
        $read = [$pipe];
        $write = $except = [];
        $this->assertSame(1, stream_select($read, $write, $except, 5), 'The cart test worker timed out.');

        return fgets($pipe);
    }

    public function testExpiredKirbySessionStartsAnEmptyCart(): void
    {
        $session = $this->sessions()->create(['expiryTime' => time() + 2, 'renewable' => false]);
        $store = new KirbySessionCartStore($session, Uuid::generate(...));
        $old = $this->mutator($store)->add(new ProductRequest('shirt'));
        $cookie = 'stripe_cart_expiry_test';
        $token = $session->token();
        $this->assertNotNull($token);
        Cookie::set($cookie, $token);

        try {
            $beforeExpiry = new AutoSession($this->environment->workspace()->roots()['sessions'], ['cookieName' => $cookie, 'durationNormal' => 1, 'gcInterval' => false]);
            $resumed = new KirbySessionCartStore($beforeExpiry->get(), Uuid::generate(...));
            $this->assertSame($old->id(), $resumed->read()->id());
            // Exercise normal expiry without changing signed session files or
            // mocking core internals. Bounded to three seconds.
            sleep(3);
            $automatic = new AutoSession($this->environment->workspace()->roots()['sessions'], ['cookieName' => $cookie, 'gcInterval' => false]);
            $freshSession = $automatic->get();
            $fresh = (new KirbySessionCartStore($freshSession, Uuid::generate(...)))->read();
            $this->assertSame([], $fresh->entries());
            $this->assertNotSame($old->id(), $fresh->id());
            $freshSession->destroy();
        } finally {
            unset($_COOKIE[$cookie]);
        }
    }

    private function sessions(): Sessions
    {
        return new Sessions($this->environment->workspace()->roots()['sessions'], ['mode' => 'manual', 'gcInterval' => false]);
    }

    private function mutator(KirbySessionCartStore $store): CartMutator
    {
        return new CartMutator($store, new SelectionCanonicalizer(static fn(ProductRequest $request): ResolvedProduct => new ResolvedProduct(
            $request,
            'Shirt',
            false,
            new StripePriceReference('price_fixture'),
        )), Uuid::generate(...));
    }
}
