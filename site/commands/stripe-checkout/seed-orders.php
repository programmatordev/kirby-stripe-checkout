<?php

declare(strict_types=1);

use Kirby\CLI\CLI;
use ProgrammatorDev\StripeCheckout\Development\OrderPanelFixtureSeeder;

require_once dirname(__DIR__, 3) . '/tools/OrderPanelFixtureSeeder.php';

return [
    'description' => 'Seed deterministic development Orders for Panel review',
    'args' => [],
    'command' => static function (CLI $cli): void {
        $created = 0;
        $reused = 0;
        $kirby = $cli->kirby() ?? throw new RuntimeException('The Kirby application is unavailable.');
        $seeder = new OrderPanelFixtureSeeder($kirby);

        foreach ($seeder->seed() as $orderNumber => $wasCreated) {
            if ($wasCreated) {
                $created++;
                $cli->out('Created ' . $orderNumber);
            } else {
                $reused++;
                $cli->out('Reused ' . $orderNumber);
            }
        }

        $cli->success(sprintf('Order fixtures ready: %d created, %d reused.', $created, $reused));
    },
];
