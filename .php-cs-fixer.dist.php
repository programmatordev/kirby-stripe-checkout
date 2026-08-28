<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->files()
    ->in(__DIR__ . '/tests')
    ->append([__DIR__ . '/index.php']);

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
