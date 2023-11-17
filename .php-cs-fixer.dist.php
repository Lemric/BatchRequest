<?php

/**
 * This file is part of the Lemric package.
 * (c) Lemric
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Dominik Labudzinski <dominik@labudzinski.com>
 */
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

return (new PhpCsFixer\Config())
    ->setRules([
        '@PHP81Migration' => true,
        '@PHPUnit84Migration:risky' => true,
        '@PSR12' => true,
        '@PSR2' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'strict_param' => true,
        'mb_str_functions' => true,
        'single_import_per_statement' => false,
        'group_import' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'protected_to_private' => false,
        'native_constant_invocation' => [
            'strict' => false,
        ],
        'nullable_type_declaration_for_default_null_value' => [
            'use_nullable_type_declaration' => false,
        ],
        'header_comment' => [
            'header' => $fileHeaderComment,
            'separate' => 'top',
            'comment_type' => 'PHPDoc',
            'location' => 'after_open',
        ],
        'modernize_strpos' => true,
        'ordered_class_elements' => [
            'sort_algorithm' => 'alpha',
            'order' => ['property_public_readonly', 'property_protected_readonly',
                'property_private_readonly', 'use_trait', 'case', 'constant_public', 'constant_protected', 'constant_private', 'property_public', 'property_protected', 'property_private', 'construct', 'destruct', 'magic', 'phpunit', 'method_public', 'method_protected', 'method_private', ],
        ],
        'array_syntax'           => ['syntax' => 'short'],

        // keep aligned = and => operators as they are: do not force aligning, but do not remove it
        'binary_operator_spaces' => ['operators' => ['=' => null, '=>' => null]],

        'blank_line_before_statement'         => ['statements' => ['return']],
        'encoding'                            => true,
        'function_typehint_space'             => true,
        'single_line_comment_style'           => ['comment_types' => ['hash']],
        'lowercase_cast'                      => true,
        'magic_constant_casing'               => true,
        'method_argument_space'               => ['on_multiline' => 'ignore'],
        'class_attributes_separation'         => ['elements' => ['method' => 'one']],
        'native_function_casing'              => true,
        'no_blank_lines_after_class_opening'  => true,
        'no_blank_lines_after_phpdoc'         => true,
        'no_empty_comment'                    => true,
        'no_empty_phpdoc'                     => true,
        'no_empty_statement'                  => true,
        'no_extra_blank_lines'                => true,
        'no_leading_import_slash'             => true,
        'no_leading_namespace_whitespace'     => true,
        'no_short_bool_cast'                  => true,
        'no_spaces_around_offset'             => true,
        'no_unneeded_control_parentheses'     => true,
        'no_unused_imports'                   => true,
        'no_whitespace_before_comma_in_array' => true,
        'no_whitespace_in_blank_line'         => true,
        'object_operator_without_whitespace'  => true,
        'ordered_imports'                     => true,
        'phpdoc_indent'                       => true,
        'phpdoc_no_useless_inheritdoc'        => true,
        'phpdoc_scalar'                       => true,
        'phpdoc_separation'                   => true,
        'phpdoc_single_line_var_spacing'      => true,
        'return_type_declaration'             => true,
        'short_scalar_cast'                   => true,
        'blank_lines_before_namespace'        => true,
        'single_quote'                        => true,
        'space_after_semicolon'               => true,
        'standardize_not_equals'              => true,
        'ternary_operator_spaces'             => true,
        'whitespace_after_comma_in_array'     => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder(
        (new PhpCsFixer\Finder())
            ->in([__DIR__.'/src', __DIR__.'/tests'])
            ->append([__FILE__])
            ->notPath('#/Fixtures/#')
    )
    ->setCacheFile('.php-cs-fixer.cache');
