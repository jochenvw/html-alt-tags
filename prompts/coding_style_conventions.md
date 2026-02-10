# Coding Style & Conventions

## General Philosophy
- **Terse but readable**: Compact logic that's immediately understandable
- **No premature optimization**: Optimize for clarity first, performance when needed
- **Consistency over cleverness**: Follow established patterns
- **Self-documenting code**: Clear names reduce need for comments

## Naming Patterns

### Functions & Methods
```php
// Action verbs + object (clear intent)
get*()      // retrieve data
set*()      // assign value
create*()   // instantiate new
parse*()    // transform format
validate*() // check correctness
process*()  // execute logic
handle*()   // respond to event
is*()       // boolean check
has*()      // boolean property
```

### Variables
| Context | Pattern | Example |
|---------|---------|---------|
| Boolean | `is`, `has`, `should` | `$isValid`, `$hasError` |
| Count/Length | `count`, `total`, `num` | `$totalItems`, `$numRetries` |
| Config/Options | Descriptive noun | `$apiUrl`, `$timeout`, `$maxRetries` |
| Temporary/Loop | Short (i, x, item, record) | `foreach ($records as $record)` |
| Collections | Plural noun | `$users`, `$configs`, `$items` |
| Flags/Settings | Prefix with `$` | `$debug`, `$enableLogging` |

## Code Structure

### Single Responsibility
```php
// Good: each function has one job
private function validateInput(array $data): void
private function transformData(array $data): array
private function persistData(array $data): void

// Not: mixing concerns
private function validateAndSave(array $data): void
```

### Early Returns
```php
// Good: reduce nesting
public function process(File $file): Result
{
    if (!$file->exists()) {
        throw new FileNotFoundException('...');
    }
    
    if ($file->isLocked()) {
        throw new FileLockedException('...');
    }
    
    // Main logic here
    return $this->doProcess($file);
}
```

### Default Parameters & Constants
```php
// Named constants over magic values
private const MAX_RETRIES = 3;
private const TIMEOUT_SECONDS = 30;
private const RETRY_DELAY_MS = 1000;

// Use throughout logic
for ($i = 0; $i < self::MAX_RETRIES; $i++) {
    // retry logic
}
```

## Comments & Documentation

### When to Comment
- **Why** not what: code explains *what*, comments explain *why*
- Complex algorithms or non-obvious logic
- Workarounds for known issues
- Integration points with external systems

### Documentation Standards
```php
/**
 * Brief description (1 line, ends with period).
 *
 * Longer description if needed (optional).
 * Explains purpose and important behavior.
 *
 * @param string $name User name
 * @param int $age User age in years
 * @return bool True if valid, false otherwise
 * @throws InvalidArgumentException If age is negative
 */
public function validate(string $name, int $age): bool
```

### Avoid Over-Commenting
```php
// Bad: obvious comments waste attention
$count = 0;  // Initialize count to zero
$count++;    // Increment count

// Good: comments add useful context
$count = 0;  // Track failed attempts for exponential backoff
$count++;    // Retry count for transient failures
```

## Error Handling

### Exception Use
```php
// Be specific about exceptions
if ($amount < 0) {
    throw new InvalidArgumentException('Amount must be non-negative');
}

if (!$resource->exists()) {
    throw new ResourceNotFoundException('Resource not found');
}

if ($service->isFailing()) {
    throw new ServiceUnavailableException('Service temporarily unavailable');
}

// Provide context in exception message
throw new ProcessingException(
    sprintf('Failed to process %s: %s', $filename, $error->getMessage())
);
```

### Try-Catch Pattern
```php
try {
    $result = $this->riskyOperation();
} catch (TransientException $e) {
    // Retry logic for temporary failures
    return $this->retry();
} catch (PermanentException $e) {
    // Log and fail fast
    $this->logger->error('Permanent failure', ['error' => $e]);
    throw $e;
}
```

## Formatting & Spacing

### Indentation
- 4 spaces (PSR-12 standard)
- No tabs

### Line Length
- Aim for 80 characters (hard limit 120)
- Break long logical statements at operators

### Blank Lines
```php
class MyClass
{
    // Blank line after properties
    private string $prop1;
    private string $prop2;

    // Blank line between methods
    public function method1(): void
    {
        // Code here
    }

    public function method2(): void
    {
        // Code here
    }
}
```

## Array Operations
```php
// Use short array syntax
$config = ['key' => 'value', 'nested' => ['child' => 'data']];

// Spread operator for merging
$merged = [...$default, ...$custom];

// Array functions over loops when clearer
$items = array_filter($list, fn($item) => $item->isValid());
$names = array_map(fn($user) => $user->getName(), $users);

// Explicit loops for complex logic
foreach ($items as $item) {
    // Complex multi-step processing
}
```

## Conditional Logic
```php
// Match over switch (PHP 8+)
$response = match($status) {
    'active' => 'process',
    'paused' => 'skip',
    'failed' => 'retry',
    default => 'log',
};

// Ternary for simple cases
$mode = $isDebug ? 'verbose' : 'silent';

// Not: nested ternaries (hard to read)
$result = $a ? $b ? 'x' : 'y' : 'z';  // Avoid!

// Use explicit if-else for complex conditions
if ($condition1 && $condition2) {
    // Logic here
}
```

## Type Hints & Strict Types
```php
<?php declare(strict_types=1);

// Always: parameters and returns
public function fetch(int $id, string $type): ?array
{
    // Explicit casting when needed
    $timeout = (int)($maxWait / 1000);
    
    return null;  // Explicit null return
}

// Collections: document with type
/** @var array<int, User> $users */
$users = [];
```
