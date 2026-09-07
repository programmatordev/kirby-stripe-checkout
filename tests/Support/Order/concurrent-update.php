<?php

declare(strict_types=1);

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__, 3) . '/index.php';

/** @var array{roots: array<string, string>, uuid: string} $input */
$input = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
$kirby = new App([
    'roots' => $input['roots'],
    'options' => [
        'cache' => false,
        'debug' => false,
    ],
]);
$kirby->impersonate('kirby');
// Boot first, then wait for the parent to hold the order lock. The attempted
// transition must be checked against the parent's committed state, not this boot.
fwrite(STDOUT, "ready\n");
fgets(STDIN);

try {
    (new OrderPageStore($kirby))->update($input['uuid'], static function (array $data): array {
        unset($data['stripeCheckoutSessionId'], $data['checkoutOpenedAt']);

        return [
            ...$data,
            'checkoutStatus' => 'creation_uncertain',
            'creationUncertainAt' => $data['createdAt'],
        ];
    });
    fwrite(STDOUT, "updated\n");
} catch (OrderDataException) {
    fwrite(STDOUT, "rejected\n");
}
