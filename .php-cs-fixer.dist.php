<?php

/**
 * This file is part of the Lemric package.
 * (c) Lemric
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Dominik Labudzinski <dominik@labudzinski.com>
 */
declare(strict_types=1);
if (!file_exists(__DIR__.'/src')) {
    exit(0);
}

$fileHeaderComment = <<<'EOF'
    This file is part of the Lemric package.
    (c) Lemric
    For the full copyright and license information, please view the LICENSE
    file that was distributed with this source code.

    @author Dominik Labudzinski <dominik@labudzinski.com>
    EOF;

$rules = [
    '@PHP8x4Migration' => true,
    '@PSR12' => true,
    '@Symfony' => true,

    'declare_strict_types' => true,
    'strict_param' => true,
    'mb_str_functions' => true,
    'native_function_casing' => true,
    'native_constant_invocation' => ['strict' => false],
    'nullable_type_declaration_for_default_null_value' => true,
    'single_import_per_statement' => false,
    'group_import' => true,
    'global_namespace_import' => [
        'import_classes' => true,
        'import_constants' => true,
        'import_functions' => true,
    ],
    'no_unused_imports' => true,
    'ordered_imports' => [
        'sort_algorithm' => 'alpha',
        'imports_order' => ['class', 'function', 'const'],
    ],
    'blank_lines_before_namespace' => true,

    'array_syntax' => ['syntax' => 'short'],
    'trailing_comma_in_multiline' => [
        'elements' => ['arrays', 'arguments', 'parameters'],
    ],
    'blank_line_before_statement' => ['statements' => ['return']],
    'class_attributes_separation' => [
        'elements' => [
            'const' => 'one',
            'property' => 'one',
            'method' => 'one',
            'trait_import' => 'one',
            'case' => 'one',
        ],
    ],
    'encoding' => true,
    'lowercase_cast' => true,
    'magic_constant_casing' => true,
    'method_argument_space' => [
        'on_multiline' => 'ignore',
        'keep_multiple_spaces_after_comma' => false,
    ],
    'modernize_strpos' => true,
    'no_blank_lines_after_class_opening' => true,
    'no_blank_lines_after_phpdoc' => true,
    'no_empty_comment' => true,
    'no_empty_phpdoc' => true,
    'no_empty_statement' => true,
    'no_extra_blank_lines' => true,
    'no_leading_import_slash' => true,
    'no_leading_namespace_whitespace' => true,
    'no_short_bool_cast' => true,
    'no_spaces_around_offset' => true,
    'no_unneeded_control_parentheses' => true,
    'no_whitespace_before_comma_in_array' => true,
    'no_whitespace_in_blank_line' => true,
    'object_operator_without_whitespace' => true,
    'phpdoc_indent' => true,
    'phpdoc_no_useless_inheritdoc' => true,
    'phpdoc_scalar' => true,
    'phpdoc_separation' => true,
    'phpdoc_single_line_var_spacing' => true,
    'return_type_declaration' => true,
    'short_scalar_cast' => true,
    'single_quote' => true,
    'space_after_semicolon' => true,
    'standardize_not_equals' => true,
    'ternary_operator_spaces' => true,
    'whitespace_after_comma_in_array' => true,

    'header_comment' => [
        'header' => $fileHeaderComment,
        'separate' => 'top',
        'comment_type' => 'PHPDoc',
        'location' => 'after_open',
    ],

    'ordered_class_elements' => [
        'sort_algorithm' => 'alpha',
        'order' => [
            'property_public_readonly',
            'property_protected_readonly',
            'property_private_readonly',
            'use_trait',
            'case',
            'constant_public',
            'constant_protected',
            'constant_private',
            'property_public',
            'property_protected',
            'property_private',
            'construct',
            'destruct',
            'magic',
            'phpunit',
            'method_public',
            'method_protected',
            'method_private',
        ],
    ],
    'single_line_comment_style' => ['comment_types' => ['hash']],
];

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    ->exclude(['vendor', 'var'])
    ->name('*.php')
    ->append([__FILE__])
    ->notPath('#/Fixtures/#');

return( new PhpCsFixer\Config())
    ->setRules($rules)
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setCacheFile('.php-cs-fixer.cache');
