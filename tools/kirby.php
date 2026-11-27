#!/usr/bin/env php
<?php

declare(strict_types=1);

use Kirby\CLI\CLI;

// The repository root is the distributable plugin entry point; CLI work must boot the adjacent development site instead.
$_ENV['KIRBY_RENDER'] = false;

ob_start();
require dirname(__DIR__) . '/index.site.php';
ob_end_clean();

$_ENV['KIRBY_RENDER'] = true;

CLI::command(...array_slice($argv, 1));
