# ADR-003: Managed Identity-First Authentication & Security

**Status:** Accepted

**Context:**
Traditional credential management (API keys, connection strings) creates security risks, rotation overhead, and operational complexity. Azure provides native managed identity for zero-credential communication between services.

**Decision:**
- **Default to managed identity** for all Azure service-to-service communication (no exceptions without security review)
- Prefer **system-assigned managed identity** wherever possible; use user-assigned only when identity must be shared or reused
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

- System-assigned managed identity is the default for Functions, App Services, and Container Apps unless a shared identity is required

**PHP Implementation:**
```php
use Azure\Identity\ManagedIdentityCredential;
use Azure\Storage\Blobs\BlobClient;

$credential = new ManagedIdentityCredential();
$blobClient = new BlobClient(
    uri: 'https://mystorageaccount.blob.core.windows.net/container',
    credential: $credential
);
```

**Bicep Configuration:**
```bicep
resource functionApp 'Microsoft.Web/sites@2021-02-01' = {
  identity: { type: 'SystemAssigned' }
  // ...
}

resource roleAssignment 'Microsoft.Authorization/roleAssignments@2020-08-01-preview' = {
  scope: storageAccount
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', '2c34c05e-6d7d-4a8f-bc07-48857e7c0f51')
    principalId: functionApp.identity.principalId
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
