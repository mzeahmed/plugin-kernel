<?php

declare(strict_types=1);

/**
 * Configuration php-cs-fixer du package.
 *
 * Usage :
 *   composer run lint      (vérification, sans modification)
 *   composer run lint:fix  (correction)
 */

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PluginKernelTools\CsFixer\SplitMethodAttributeArgsFixer;
use PluginKernelTools\CsFixer\BlankLineAfterControlStructureFixer;

require __DIR__ . '/tools/CsFixer/SplitMethodAttributeArgsFixer.php';
require __DIR__ . '/tools/CsFixer/BlankLineAfterControlStructureFixer.php';

$finder = Finder::create()
                ->in([__DIR__ . '/src', __DIR__ . '/tools'])
                ->append([__DIR__ . '/rector.php', __DIR__ . '/.php-cs-fixer.php'])
                ->ignoreDotFiles(true)
                ->ignoreVCS(true);

return (new Config())
    ->registerCustomFixers([
        new SplitMethodAttributeArgsFixer(),
        new BlankLineAfterControlStructureFixer(),
    ])
    ->setRules([
        // PSR-12 + imports inutilisés supprimés
        '@PSR12' => true,
        'no_unused_imports' => true,

        'declare_strict_types' => true,
        'array_syntax' => [
            'syntax' => 'short',
        ],
        'fully_qualified_strict_types' => [
            'leading_backslash_in_global_namespace' => true,
        ],
        'phpdoc_separation' => true,
        'not_operator_with_successor_space' => false,
        'single_line_empty_body' => false,
        'curly_braces_position' => [
            'allow_single_line_empty_anonymous_classes' => false,
            'allow_single_line_anonymous_functions' => false,
        ],
        'phpdoc_align' => [
            'align' => 'left',
        ],
        'ordered_imports' => [
            'sort_algorithm' => 'length',
        ],
        'trailing_comma_in_multiline' => true,
        'binary_operator_spaces' => [
            'default' => 'single_space',
        ],
        'no_extra_blank_lines' => true,
        'no_whitespace_in_blank_line' => true,
        'single_space_around_construct' => true,
        'unary_operator_spaces' => true,
        'phpdoc_single_line_var_spacing' => true,
        'types_spaces' => [
            'space' => 'none',
        ],
        'concat_space' => [
            'spacing' => 'one',
        ],
        'align_multiline_comment' => false,
        'single_quote' => true,
        'class_attributes_separation' => [
            'elements' => [
                'method' => 'one',
            ],
        ],
        'combine_consecutive_unsets' => true,
        'combine_consecutive_issets' => true,
        'type_declaration_spaces' => true,
        'statement_indentation' => [
            'stick_comment_to_next_continuous_control_statement' => true,
        ],
        'echo_tag_syntax' => [
            'format' => 'short',
        ],

        // Règles custom (tools/CsFixer)
        'PluginKernel/split_method_attribute_args' => true,
        'PluginKernel/blank_line_after_control_structure' => true,
    ])
    ->setRiskyAllowed(true)
    ->setUsingCache(true)
    ->setUnsupportedPhpVersionAllowed(true)
    ->setFinder($finder);
