# Architecture Decision Records (ADRs)

This directory contains minimalist Architecture Decision Records (ADRs) that define critical architectural decisions, coding standards, and best practices for this project. Each ADR follows a lightweight template: **Status** → **Context** → **Decision** → **Consequences**.

## Quick Reference

| ADR | Decision | Status |
|-----|----------|--------|
| [ADR-001](adr-001-php-standards.md) | PHP 8.0+ with strict types, PSR-12, PHPUnit | ✅ Accepted |
| [ADR-002](adr-002-bicep-iac.md) | Infrastructure as Code via Bicep + Azure CLI | ✅ Accepted |
| [ADR-003](adr-003-managed-identity-auth.md) | Managed Identity-first security model | ✅ Accepted |
| [ADR-004](adr-004-code-style.md) | Terse, readable code with consistent naming | ✅ Accepted |

## ADR Details

### [ADR-001: PHP Coding Standards & Idiomatic Practices](adr-001-php-standards.md)
Establish PHP 8.0+ as the language standard with strict types, PSR-12 formatting, and modern syntax patterns.

### [ADR-002: Infrastructure as Code with Bicep & Azure CLI](adr-002-bicep-iac.md)
All Azure infrastructure defined in Bicep, deployed via Azure CLI, with parameterized environments.

### [ADR-003: Managed Identity-First Authentication & Security](adr-003-managed-identity-auth.md)
Default to **user-assigned managed identity** for Azure service-to-service authentication; never embed credentials in code.

### [ADR-004: Terse, Readable Code Style & Naming Conventions](adr-004-code-style.md)
Consistent naming patterns (verb-noun methods, boolean prefixes) with early returns and specific exceptions.

## How to Use These Guidelines

### In LLM Conversations
1. **Include relevant ADRs** in your context when asking for code generation
2. **Reference by name** for specific guidance (e.g., "Follow ADR-003")
3. **Combine ADRs** for comprehensive context (e.g., PHP + Auth + Style)

### Example Context Inclusion
```
Generate a PHP service for blob storage access.
Context: Follow ADRs:
- ADR-001
- ADR-003
- ADR-004

Ensure:
- Managed identity for authentication (user-assigned via `AZURE_CLIENT_ID`)
- Proper type hints and strict types
- Error handling with specific exceptions
- Clear naming and structure
```

### Project Integration
- These guidelines define the coding standards for the project
- Use them in code reviews and PR templates
- Reference them when onboarding new team members
- Update as project practices evolve

## Key Principles

### Authentication
✅ **Always use managed identity** for Azure service-to-service communication (user-assigned for Container Apps)
❌ Never embed API keys, connection strings, or credentials in code

### PHP Style
✅ Idiomatic, modern PHP (8.0+) with strict types
✅ Readable, terse logic that's immediately understandable
❌ Avoid premature optimization and overly clever code

### Infrastructure
✅ Bicep for all infrastructure definitions
✅ Azure CLI for deployments and operational tasks
✅ Modular, reusable templates with clear parameters
❌ No manual post-deployment configuration

### Code Quality
✅ Early returns to minimize nesting
✅ Specific exception types with clear messages
✅ Self-documenting code with clear names
❌ Over-commenting or stating the obvious

## Version & Updates

- **Last Updated:** February 2026
- **PHP Version:** 8.0+
- **Bicep Version:** Latest (auto-updated)
- **Azure CLI:** Latest version recommended

---

For questions or updates to these guidelines, review the guidelines and create an issue describing the needed change.
