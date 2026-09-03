<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    ->append([__DIR__.'/bootstrap.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'method_argument_space' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'single_quote' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],
        'concat_space' => ['spacing' => 'one'],
        'function_declaration' => ['closure_function_spacing' => 'one'],
        'no_extra_blank_lines' => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'no_whitespace_in_blank_line' => true,
        'single_line_throw' => false,
        'space_after_semicolon' => true,
    ])
    ->setFinder($finder);
