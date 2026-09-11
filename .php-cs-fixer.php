<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/app', __DIR__ . '/config', __DIR__ . '/database', __DIR__ . '/tests', __DIR__ . '/routes'])
    ->exclude('vendor')
    ->name('*.php')
    ->notName('*.blade.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'no_extra_blank_lines' => true,
        'no_trailing_whitespace' => true,
        'blank_line_before_statement' => ['statements' => ['return']],
        'braces' => ['allow_single_line_closure' => true],
        'cast_spaces' => true,
        'concat_space' => ['spacing' => 'one'],
        'declare_equal_normalize' => true,
        'function_typehint_space' => true,
        'include' => true,
        'lowercase_cast' => true,
        'native_function_casing' => true,
        'no_blank_lines_after_class_opening' => true,
        'no_empty_statement' => true,
        'no_leading_import_slash' => true,
        'no_whitespace_in_blank_line' => true,
        'phpdoc_single_line_var_spacing' => true,
        // single_blank_line_before_namespace — PSR12 already covers this
        'ternary_operator_spaces' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'trim_array_spaces' => true,
        'whitespace_after_comma_in_array' => true,
    ])
    ->setFinder($finder);
