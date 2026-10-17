<?php

declare(strict_types=1);

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\StripeCheckout;

return [
    'stripeCheckout' => function (): StripeCheckout {
        static $app = null;
        static $instance = null;

        // Kirby binds Site methods to the active Site instance at call time.
        /** @phpstan-ignore-next-line variable.undefined, method.nonObject */
        $kirby = $this->kirby();
        /** @var App $kirby */

        // Replace the facade when another App becomes active in the same PHP process.
        if ($app !== $kirby || $instance === null) {
            $app = $kirby;
            $instance = new StripeCheckout($kirby);
        }

        // Independent template and snippet calls must share one-use browser results during this App lifecycle.
        /** @var StripeCheckout $instance */
        return $instance;
    },
];
