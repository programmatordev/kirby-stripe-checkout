<?php

declare(strict_types=1);

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Housekeeping\Internal\HousekeepingStateStore;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** @var array{roots: array<string, string>} $input */
$input = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
$kirby = new App([
    'roots' => $input['roots'],
    'options' => [
        'cache' => false,
        'debug' => false,
    ],
]);
$store = new HousekeepingStateStore($kirby);

fwrite(STDOUT, "ready\n");
fgets(STDIN);
fwrite(STDOUT, ($store->read()->continueAfterPageUuid() ?? 'empty') . "\n");
