# LLM-Assisted Coding Prompts

This directory contains structured guidelines and prompt templates for LLM-assisted development in this project.

## Available Prompts

### 1. **PHP Coding Guidelines** (`php_coding_guidelines.md`)
Best practices for writing idiomatic, maintainable PHP code in this project.

**Key Topics:**
- PSR-12 code style and modern PHP 8.0+ syntax
- Type safety and strict types
- Code organization and autoloading
- Testing patterns with PHPUnit
- Performance considerations

### 2. **Azure Infrastructure Guidelines** (`azure_infrastructure_guidelines.md`)
Infrastructure as Code patterns using Bicep and Azure CLI.

**Key Topics:**
- Bicep template structure and standards
- Azure CLI deployment workflows
- Naming conventions for resources
- Configuration management and parameter files
- RBAC and resource patterns

### 3. **Authentication & Security Guidelines** (`auth_and_security_guidelines.md`)
Security practices with emphasis on managed identity and least-privilege access.

**Key Topics:**
- Managed identity implementation (primary method)
- RBAC configuration in Bicep
- Key Vault integration
- Azure CLI for role assignments
- Security checklist

### 4. **Coding Style & Conventions** (`coding_style_conventions.md`)
Unified style conventions for terse but readable code.

**Key Topics:**
- Naming patterns and consistency
- Code structure and single responsibility
- Error handling and exceptions
- Formatting and spacing (PSR-12)
- Type hints and null handling

## How to Use These Prompts

### In LLM Conversations
1. **Include relevant prompts** in your context when asking for code generation
2. **Reference by name** for specific guidance (e.g., "Follow auth_and_security_guidelines.md")
3. **Combine prompts** for comprehensive context (e.g., PHP + Auth + Style)

### Example Context Inclusion
```
Generate a PHP service for blob storage access.
Context: Follow guidelines from:
- php_coding_guidelines.md
- auth_and_security_guidelines.md
- coding_style_conventions.md

Ensure:
- Managed identity for authentication
- Proper type hints and strict types
- Error handling with specific exceptions
- Clear naming and structure
```

### Project Integration
- These prompts define the coding standards for the project
- Use them in code reviews and PR templates
- Reference them when onboarding new team members
- Update as project practices evolve

## Key Principles

### Authentication
✅ **Always use managed identity** for Azure service-to-service communication
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
