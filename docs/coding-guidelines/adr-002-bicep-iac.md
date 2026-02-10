# ADR-002: Infrastructure as Code with Bicep & Azure CLI

**Status:** Accepted

**Context:**
Azure infrastructure must be reproducible, versioned, and automated. Manual infrastructure configuration is error-prone and creates knowledge silos.

**Decision:**
- Define all infrastructure in Bicep templates (not generated from ARM JSON)
- Use modular, composable Bicep templates with clear parameters and outputs
- Execute deployments via Azure CLI (`az deployment` commands)
- Store environment-specific values in parameter files (`.json`)
- Never commit secrets; reference Key Vault instead
- Use consistent resource naming: kebab-case with descriptive prefixes (e.g., `myapp-storage-prod`)
- Configure RBAC at resource creation time via Bicep role assignments

**Consequences:**
- **Positive:** Infrastructure is version-controlled and reproducible; CI/CD deployment becomes deterministic; team has shared understanding of environment topology; rollback is achievable
- **Negative:** Bicep syntax learning curve; requires discipline to avoid manual post-deployment changes; parameter file management adds complexity
- **Operational:** All infrastructure changes must go through Bicep + CLI workflow; validation steps (bicep build, deployment validate) are mandatory; outputs must be documented

---

**Guides:**
- Use `@description()` metadata on all parameters
- Output only downstream dependencies (storage IDs, connection strings)
- Validate with: `az bicep build --file main.bicep` before deployment
- Capture outputs for application configuration: `az deployment group show --query 'properties.outputs'`
- Tag all resources consistently (environment, managed-by, project)
- Enable diagnostic logging on all resources
- Apply firewall rules and private endpoints for secure-by-default patterns
