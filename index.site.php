<?php

use Kirby\Cms\App;

require_once __DIR__ . '/kirby/bootstrap.php';

echo (new App([
    'roots' => [
        'index' => __DIR__
    ]
]))->render();
