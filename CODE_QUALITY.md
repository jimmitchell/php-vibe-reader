# Code Quality Tools

This project uses PHPStan for static analysis and PHP-CS-Fixer for code style enforcement.

## PHPStan - Static Analysis

PHPStan analyzes code for potential bugs, type errors, and code quality issues without running the code.

### Configuration

- **Level**: 5 (moderate strictness)
- **Config file**: `phpstan.neon`
- **Paths analyzed**: `src/`

### Usage

```bash
# Run analysis
composer analyse

# Or directly
docker exec vibereader php vendor/bin/phpstan analyse --configuration=phpstan.neon

# Generate baseline (ignore current errors)
composer analyse-baseline
```

### Current Status

PHPStan is configured at level 5 and analyzes all source files. Some known limitations:
- Monolog classes are ignored (they exist but PHPStan can't always detect them)
- RedisCache is excluded (may not have Redis extension in all environments)

## PHP-CS-Fixer - Code Style

PHP-CS-Fixer automatically fixes code style to match PSR-12 standards.

### Configuration

- **Standard**: PSR-12
- **Config file**: `.php-cs-fixer.php`
- **Rules**: Array syntax, ordered imports, trailing commas, etc.

### Usage

```bash
# Check what would be changed (dry-run)
composer cs-check

# Fix code style issues
composer cs-fix

# Or directly
docker exec vibereader php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php src/
```

### What It Fixes

- PSR-12 compliance
- Array syntax (`[]` instead of `array()`)
- Ordered imports (alphabetically)
- Removes unused imports
- Trailing commas in multiline arrays
- Spacing around operators
- Consistent formatting

## Integration with Development Workflow

### Before Committing

1. Run PHP-CS-Fixer to ensure consistent style:
   ```bash
   composer cs-fix
   ```

2. Run PHPStan to catch potential issues:
   ```bash
   composer analyse
   ```

3. Run tests:
   ```bash
   composer test
   ```

### CI/CD Integration

These tools can be integrated into CI/CD pipelines:

```yaml
# Example GitHub Actions
- name: Check code style
  run: composer cs-check

- name: Run static analysis
  run: composer analyse

- name: Run tests
  run: composer test
```

## Docker Usage

Since the project runs in Docker, use these commands:

```bash
# PHPStan
docker exec vibereader php vendor/bin/phpstan analyse --configuration=phpstan.neon

# PHP-CS-Fixer
docker exec vibereader php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php src/
```

## Troubleshooting

### PHPStan Can't Find Classes

If PHPStan reports classes as "not found" but they exist:
1. Check that `vendor/autoload.php` is in `bootstrapFiles`
2. Verify the class is in the autoloaded namespace
3. Add to `ignoreErrors` if it's a false positive

### PHP-CS-Fixer Changes Too Much

If PHP-CS-Fixer makes unwanted changes:
1. Review `.php-cs-fixer.php` configuration
2. Adjust rules in the `setRules()` array
3. Run with `--dry-run` first to preview changes

### Memory Issues

If PHPStan runs out of memory:
```bash
php vendor/bin/phpstan analyse --memory-limit=1G
```

## Benefits

1. **Early Bug Detection**: PHPStan finds issues before runtime
2. **Consistent Style**: PHP-CS-Fixer ensures uniform code formatting
3. **Better IDE Support**: Static analysis improves autocomplete and error detection
4. **Code Review**: Automated checks reduce manual review burden
5. **Maintainability**: Consistent style makes code easier to read and maintain
