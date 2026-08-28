<?php

declare(strict_types=1);

use ProgrammatorDev\StripeCheckout\StripeCheckout;

return [
    'stripeCheckout' => function (): StripeCheckout {
        // Kirby binds Site methods to the active Site instance at call time.
        /** @phpstan-ignore-next-line variable.undefined, method.nonObject, argument.type */
        return new StripeCheckout($this->kirby());
    },
];
