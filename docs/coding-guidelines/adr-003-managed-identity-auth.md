# ADR-003: Managed Identity-First Authentication & Security

**Status:** Accepted

**Context:**
Traditional credential management (API keys, connection strings) creates security risks, rotation overhead, and operational complexity. Azure provides native managed identity for zero-credential communication between services.

**Decision:**
- **Default to managed identity** for all Azure service-to-service communication (no exceptions without security review)
- Use **user-assigned managed identity** (`id-alt-pipeline`) so the same identity is reused across Container Apps, Storage, AI Foundry, and Translator
- Assign RBAC roles via Bicep at infrastructure creation time
- Store application secrets in Azure Key Vault; never embed in code or config files
- Implement least-privilege access: grant only required role permissions
- No API keys, connection strings, or credentials in source code, environment variables, or application config

**Consequences:**
- **Positive:** Eliminates credential management burden; reduces attack surface; enables audit trails via Azure RBAC; tokens auto-refresh; complies with security best practices
- **Negative:** Requires Azure identity infrastructure (not portable to non-Azure); debugging cross-service auth requires understanding RBAC; local development needs emulation
- **Operational:** Every service must have explicit RBAC assignments; Key Vault access requires roles; token expiry is handled by SDKs transparently

---

**Guides:**

- User-assigned managed identity (`id-alt-pipeline`) is used for Container Apps so the same identity can authenticate to all downstream Azure services (Storage, AI Foundry, Translator)

**PHP Implementation:**
```php
use App\Auth\ManagedIdentityCredential;

$credential = new ManagedIdentityCredential(clientId: $env['AZURE_CLIENT_ID']);
$token = $credential->getToken('https://storage.azure.com/.default');
```

**Bicep Configuration:**
```bicep
resource managedIdentity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: 'id-alt-pipeline'
  location: location
}

resource containerApp 'Microsoft.App/containerApps@2023-05-01' = {
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: { '${managedIdentity.id}': {} }
  }
}
```

**Common Roles:**
| Role | Permission |
|------|-----------|
| Storage Blob Data Contributor | Read/write blobs |
| Storage Blob Data Reader | Read-only blobs |
| Key Vault Secrets User | Read secrets |
| Cosmos DB Built-in Data Contributor | Read/write Cosmos DB |

**Key Vault:** Store API keys, certificates, and connection strings
