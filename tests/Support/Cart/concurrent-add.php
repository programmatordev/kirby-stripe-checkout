<?php

declare(strict_types=1);

// A separate PHP process is necessary to exercise Kirby's actual file lock.
// Token data travels through stdin rather than command-line arguments/logs.
use Kirby\Session\Sessions;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartMutator;
use ProgrammatorDev\StripeCheckout\Cart\Internal\KirbySessionCartStore;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SelectionCanonicalizer;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** @var array{root: string, token: string} $input */
$input = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
$session = (new Sessions($input['root'], ['mode' => 'manual', 'gcInterval' => false]))->get($input['token']);
$store = new KirbySessionCartStore($session, static fn(): string => bin2hex(random_bytes(8)));
$mutator = new CartMutator($store, new SelectionCanonicalizer(static fn(ProductRequest $request): ResolvedProduct => new ResolvedProduct(
    $request,
    'Shirt',
    false,
    new StripePriceReference('price_fixture'),
)), static fn(): string => bin2hex(random_bytes(8)));
$session->commit();
// Signal initialization separately; the parent sends "go" while holding the
// session lock, so this add must wait and then reload the parent's committed state.
fwrite(STDOUT, "ready\n");
fgets(STDIN);
$result = $mutator->add(new ProductRequest('shirt', 3));
fwrite(STDOUT, $result->entries()[0]->request()->quantity() . "\n");
