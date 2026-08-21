<?php

declare(strict_types=1);

use Kirby\Cms\App;

define('KIRBY_STRIPE_CHECKOUT_ROOT', dirname(__DIR__));

require KIRBY_STRIPE_CHECKOUT_ROOT . '/vendor/autoload.php';

// Kirby's Whoops handler must not replace PHPUnit's own error handlers.
App::$enableWhoops = false;
