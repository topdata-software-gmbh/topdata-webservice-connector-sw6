# PHP CS Fixer Usage Instructions

PHP CS Fixer is a tool for automatically fixing PHP coding standards issues in your code. This document provides basic instructions on how to use PHP CS Fixer in this project.

## Running PHP CS Fixer

1. To perform a dry run (see what would be fixed without actually changing files):
   ```
   php php-cs-fixer.phar fix --dry-run
   ```

2. To fix files:
   ```
   php php-cs-fixer.phar fix
   ```

3. To fix a specific file or directory:
   ```
   php php-cs-fixer.phar fix path/to/file/or/directory
   ```

## Configuration

The PHP CS Fixer configuration is stored in `.php-cs-fixer.dist.php`. You can modify this file to change the coding standards rules applied to your project.

## Integrating with Your Workflow

- Consider running PHP CS Fixer before committing your code or as part of your CI/CD pipeline.
- You can add a pre-commit hook to automatically run PHP CS Fixer on changed files.

For more detailed information, visit the [PHP CS Fixer documentation](https://cs.symfony.com/).
