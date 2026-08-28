<?php

declare(strict_types=1);

use Kirby\Cms\App;

App::plugin(
    name: 'programmatordev/stripe-checkout',
    extends: [
        // Business defaults stay in the resolver so explicit project values
        // remain distinguishable from plugin defaults.
        'options' => [],
    ],
    version: '0.7.0',
);
