<?php

declare(strict_types=1);

/*
 * php-cs-fixer configuration for context-shuttle.
 * Formatter gate for CI (Guiding Light §2.1).
 */

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        '@PhpCsFixer' => true,
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => true, 'import_functions' => true],
        'php_unit_method_casing' => ['case' => 'snake_case'],
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);