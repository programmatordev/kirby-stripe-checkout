<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->files()
    ->in([
        __DIR__ . '/config',
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // These paths belong to the pre-rework implementation. Remove each
    // exclusion when its production code is replaced or deliberately retained.
    ->exclude([
        'Cart',
        'Exception',
    ])
    ->notPath([
        'api.php',
        'blueprints.php',
        'MoneyFormatter.php',
        'routes.php',
        'siteMethods.php',
        'StripeCheckout.php',
        'translations.php',
    ]);

return (new Config())
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2x0' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => [
            'elements' => ['arguments', 'arrays', 'parameters'],
        ],
    ]);
