# PHP Coding Guidelines for LLM-Assisted Development

## Core Principles
- **Idiomatic PHP**: Follow PSR-12 code style and modern PHP conventions
- **Readability over brevity**: Terse logic but clear intent
- **Type safety**: Use strict types and type hints throughout
- **Error handling**: Explicit exception handling, meaningful error messages

## Language Standards
- PHP 8.0+ syntax (strict_types, named arguments, match expressions)
- Use `declare(strict_types=1);` at the top of all files
- Type declarations required for all function parameters and return types
- Use nullable types where appropriate: `?string`, `?int`

## Code Style
- PSR-4 autoloading (namespace structure matches directory structure)
- Short variable names acceptable where context is clear
- Method names: camelCase, descriptive verb-noun pattern (`getUser()`, `validateInput()`)
- Class names: PascalCase, nouns for classes, interfaces prefixed with `Interface`
- Constants: UPPER_SNAKE_CASE

## Common Patterns
```php
// Constructor property promotion (PHP 8+)
public function __construct(private ServiceInterface $service) {}

// Named arguments for clarity
$client->authenticate(apiKey: $key, endpoint: $url);

// Match expressions over switch for cleaner logic
$status = match($code) {
    200 => 'success',
    404 => 'not_found',
    default => 'error',
};

// Use early returns to reduce nesting
if (!$condition) {
    throw new InvalidArgumentException('...');
}
// Main logic here (1 indentation level)
```

## Dependencies & Autoloading
- Use Composer for all dependencies
- Import classes explicitly with `use` statements
- Prefer composition over inheritance
- Avoid global functions; use static factory methods instead

## Testing
- PHPUnit for unit tests
- Test file organization mirrors source structure with `Tests/` suffix
- Descriptive test method names: `testThrowsExceptionWhenInputInvalid()`
- Use assertions with messages for clarity

## File Organization
```
AltPipeline.Function/
├── handler.php          (entry point)
├── composer.json
├── function.json
├── App/
│   ├── Bootstrap.php    (initialization)
│   ├── Services/        (business logic)
│   ├── Contracts/       (interfaces)
│   ├── Pipeline/        (processing chains)
│   ├── Storage/         (data access)
│   └── Auth/            (authentication)
└── Tests/               (unit tests)
```

## Logging & Debugging
- Use structured logging with context arrays
- Log at appropriate levels: DEBUG, INFO, WARNING, ERROR
- Include correlation IDs for tracing across services
- Never log sensitive data (API keys, credentials, tokens)

## Performance Considerations
- Stream large files; avoid loading entire content into memory
- Use generators for large collections
- Cache computed values appropriately
- Use lazy initialization where expensive operations are needed conditionally
