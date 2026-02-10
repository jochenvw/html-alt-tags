# ADR-001: PHP Coding Standards & Idiomatic Practices

**Status:** Accepted

**Context:**
The project uses PHP for Azure Function handlers. We need consistent, maintainable code that leverages modern PHP features while remaining readable and type-safe across the team.

**Decision:**
- Adopt PSR-12 code style and PHP 8.0+ features (strict types, named arguments, match expressions)
- Require explicit type hints on all function parameters and return values
- Use `declare(strict_types=1);` at the top of all files
- Follow PSR-4 autoloading with namespace structure matching directory structure
- Use Composer for all dependency management
- Implement PHPUnit for unit testing with descriptive test method names
- Apply early returns to minimize nesting depth
- Use specific, contextual exception types with clear error messages

**Consequences:**
- **Positive:** Type safety prevents large classes of bugs; modern syntax is more expressive; PHPUnit integration enables regression prevention; consistent structure reduces cognitive load during code review
- **Negative:** Requires team familiarity with PHP 8.0+ syntax; strict types may add minor boilerplate; newer developers need onboarding on modern PHP practices
- **Operational:** All code must validate against PSR-12 in CI/CD; Composer dependency audit required before releases

---

**Guides:**
- Method signatures use camelCase with verb-noun patterns (getUser, validateInput)
- Class names are PascalCase; constants are UPPER_SNAKE_CASE
- Constructor property promotion for brevity: `public function __construct(private ServiceInterface $service) {}`
- Prefer composition over inheritance
- Stream large files; avoid loading entire content into memory
- Use generators for large collections
- Never log sensitive data (API keys, credentials, tokens)
