<?php

// .php-cs-fixer.dist.php - PHP CS Fixer configuration file
// 2024-06-26 created
// 2024-10-16 updated - added parallel config
// This file defines the coding standards and rules for the project.

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    // ->in(__DIR__) // Uncomment to search in the current directory
    ->in(__DIR__ . '/src') // Searches for PHP files in the 'src' directory
    ->name('*.php') // Only consider files with .php extension
    ->exclude('vendor') // Excludes the 'vendor' directory
    ->exclude('var'); // Excludes the 'var' directory

$config = new Config();
return $config
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect()) // Configures parallel processing based on system capabilities
    ->setRules([
        '@PSR12' => true, // Applies PSR-12 coding standard
        'array_syntax' => ['syntax' => 'short'], // Forces use of short array syntax []
        'binary_operator_spaces' => [
            'default' => 'align_single_space_minimal', // Aligns operators with minimal space
        ],
        'blank_line_after_namespace' => true, // Ensures there's a blank line after namespace declarations
        'blank_line_after_opening_tag' => true, // Adds a blank line after the opening PHP tag
        'blank_line_before_statement' => [
            'statements' => ['return'], // Adds a blank line before return statements
        ],
        'braces' => [
            'position_after_functions_and_oop_constructs' => 'next', // Places opening braces on the next line for functions and classes
        ],
        'cast_spaces' => ['space' => 'none'], // Removes spaces around cast operations
        'concat_space' => ['spacing' => 'one'], // Ensures one space around the concatenation operator
        'declare_equal_normalize' => ['space' => 'none'], // Removes spaces around the equal sign in declare statements
        'function_typehint_space' => true, // Ensures space after type hints in function declarations
        'include' => true, // Ensures proper spacing for include statements
        'lowercase_cast' => true, // Forces lowercase for cast operations
        'no_extra_blank_lines' => [
            'tokens' => [
                'extra',
                'throw',
                'use',
            ], // Removes extra blank lines around specified tokens
        ],
        'no_trailing_whitespace' => true, // Removes trailing whitespace at the end of lines
        'no_whitespace_before_comma_in_array' => true, // Removes whitespace before commas in arrays
        'single_quote' => true, // Converts double quotes to single quotes for strings
        'ternary_operator_spaces' => true, // Standardizes spaces around ternary operators
        'trailing_comma_in_multiline' => ['elements' => ['arrays']], // Adds trailing commas in multiline arrays
        'trim_array_spaces' => true, // Removes extra spaces around array brackets
        'unary_operator_spaces' => true, // Removes space after unary operators
        'no_closing_tag' => true, // Removes closing PHP tag
    ])
    ->setFinder($finder);