<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use ProgrammatorDev\StripeCheckout\Development\PhpCsFixer\BlankLineAfterControlStructureFixer;

require_once __DIR__ . '/tools/PhpCsFixer/BlankLineAfterControlStructureFixer.php';

$finder = Finder::create()
    ->files()
    ->in([
        __DIR__ . '/config',
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/tools/PhpCsFixer',
    ])
    ->append([__DIR__ . '/index.php']);

return (new Config())
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->registerCustomFixers([new BlankLineAfterControlStructureFixer()])
    ->setRules([
        '@PER-CS2x0' => true,
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_before_statement' => [
            'statements' => ['if', 'for', 'foreach', 'while', 'do', 'switch', 'try'],
        ],
        'StripeCheckout/blank_line_after_control_structure' => true,
        'class_attributes_separation' => [
            'elements' => ['method' => 'one'],
        ],
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => [
            'elements' => ['arguments', 'arrays', 'parameters'],
        ],
    ]);
