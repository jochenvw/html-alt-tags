# ADR-004: Terse, Readable Code Style & Naming Conventions

**Status:** Accepted

**Context:**
Code readability directly impacts maintenance cost, bug fix speed, and team velocity. Consistent naming and structure reduce cognitive load during code review and onboarding.

**Decision:**
- Optimize for terse-yet-clear logic: compact code that's immediately understandable
- Use consistent naming patterns: verb-noun method names, boolean prefixes (is, has, should)
- Apply early returns to minimize nesting (max 1-2 levels of indentation)
- Use specific exception types with contextual error messages
- Implement single responsibility: one function = one job
- Comment on *why*, not *what*; code explains intent, comments explain context
- Follow PSR-12 formatting: 4-space indentation, 80-char aim (120-char hard limit)
- Prefer clarity over cleverness: avoid nested ternaries, overly condensed logic

**Consequences:**
- **Positive:** Code reviews become faster; onboarding new developers is quicker; bugs are easier to locate; refactoring is safer
- **Negative:** May require more lines of code in some cases; developers accustomed to dense coding may find it verbose
- **Operational:** Linting tools can enforce PSR-12; naming conventions require code review discipline; exception hierarchy must be maintained

---

**Naming Patterns:**

| Context | Pattern | Example |
|---------|---------|---------|
| Retrieve | `get*()` | `getUser()` |
| Create | `create*()` | `createBlob()` |
| Validate | `validate*()` | `validateInput()` |
| Boolean property | `is*()`, `has*()` | `isValid()`, `hasError()` |
| Count | `count*()`, `total*()` | `totalItems()` |
| Config | Descriptive noun | `$apiUrl`, `$timeout`, `$maxRetries` |
| Boolean variable | `is`, `has`, `should` | `$isDebug`, `$hasError` |
| Collections | Plural | `$users`, `$configs` |

**Code Structure:**
- Early returns eliminate deep nesting
- Named constants over magic values: `private const MAX_RETRIES = 3;`
- Match expressions over switch (PHP 8+)
- Array functions (filter, map) over loops when logic is simple
- Type hints required everywhere; nullable types explicit (`?string`)

**Exception Handling:**
```php
if ($amount < 0) {
    throw new InvalidArgumentException('Amount must be non-negative');
}

try {
    $result = $this->operation();
} catch (TransientException $e) {
    return $this->retry();
} catch (PermanentException $e) {
    $this->logger->error('Permanent failure', ['error' => $e]);
    throw $e;
}
```
