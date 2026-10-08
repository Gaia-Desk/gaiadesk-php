<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/examples', __DIR__.'/tools'])
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'phpdoc_to_comment' => false,
        'phpdoc_separation' => false,
        'phpdoc_align' => false,
        'yoda_style' => true,
        'fopen_flags' => ['b_mode' => true],
        'concat_space' => ['spacing' => 'none'],
        'global_namespace_import' => false,
        'single_line_throw' => false,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache');
