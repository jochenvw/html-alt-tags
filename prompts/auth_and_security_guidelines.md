# Authentication & Security Guidelines

## Managed Identity (Primary Method)

### Principles
- **Default to managed identity** for all Azure service-to-service communication
- No API keys, connection strings, or secrets in code
- System-assigned identities for single-purpose services
- User-assigned identities for shared/multi-use services
- Reduces credential rotation overhead and attack surface

### Implementation in PHP
```php
// Use managed identity credential
use Azure\Identity\ManagedIdentityCredential;
use Azure\Storage\Blobs\BlobClient;

$credential = new ManagedIdentityCredential();
$blobClient = new BlobClient(
    uri: 'https://mystorageaccount.blob.core.windows.net/container',
    credential: $credential
);

// Client automatically handles token acquisition and refresh
$blob = $blobClient->downloadBlob('filename.txt');
```

### Bicep Configuration
```bicep
// Assign managed identity to function
param functionAppName string
param storageAccountName string

resource functionApp 'Microsoft.Web/sites@2021-02-01' = {
  name: functionAppName
  location: location
  kind: 'functionapp'
  identity: {
    type: 'SystemAssigned'  // Create system-assigned identity
  }
  // ... other properties
}

// Grant storage access via RBAC
resource roleAssignment 'Microsoft.Authorization/roleAssignments@2020-08-01-preview' = {
  scope: storageAccount
  name: guid(functionApp.id, storageAccount.id, 'Storage Blob Data Contributor')
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', '2c34c05e-6d7d-4a8f-bc07-48857e7c0f51')  // Storage Blob Data Contributor
    principalId: functionApp.identity.principalId
    principalType: 'ServicePrincipal'
  }
}
```

### Bicep Outputs for Reference
```bicep
output managedIdentityPrincipalId string = functionApp.identity.principalId
output managedIdentityTenantId string = subscription().tenantId
```

## Role-Based Access Control (RBAC)

### Common Azure Roles
| Role | Use Case |
|------|----------|
| `Storage Blob Data Contributor` | Read/write blob storage |
| `Storage Blob Data Reader` | Read-only blob access |
| `Key Vault Secrets User` | Read secrets from Key Vault |
| `Cosmos DB Built-in Data Contributor` | Read/write Cosmos DB |
| `Database User` | SQL Server database access |

### Bicep RBAC Pattern
```bicep
// Define role assignment
param principalId string  // Managed identity principal ID
param roleDefinitionId string  // Role GUID

resource roleAssignment 'Microsoft.Authorization/roleAssignments@2020-08-01-preview' = {
  scope: targetResource
  name: guid(targetResource.id, principalId, roleDefinitionId)
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', roleDefinitionId)
    principalId: principalId
    principalType: 'ServicePrincipal'
  }
}
```

## Key Vault Integration

### When to Use
- Application secrets (API keys, connection strings)
- Certificates for TLS/mTLS
- Encryption keys
- Any sensitive configuration

### Bicep Reference Pattern
```bicep
resource keyVault 'Microsoft.KeyVault/vaults@2021-06-01-preview' = {
  name: keyVaultName
  location: location
  properties: {
    tenantId: subscription().tenantId
    sku: {
      family: 'A'
      name: 'standard'
    }
    accessPolicies: [
      {
        tenantId: subscription().tenantId
        objectId: functionAppPrincipalId
        permissions: {
          secrets: ['get', 'list']
        }
      }
    ]
  }
}

// Store secret
resource secret 'Microsoft.KeyVault/vaults/secrets@2021-06-01-preview' = {
  name: 'my-secret'
  parent: keyVault
  properties: {
    value: secretValue
  }
}
```

### PHP Client with Managed Identity
```php
use Azure\Identity\ManagedIdentityCredential;
use Azure\Security\KeyVault\Secrets\SecretClient;

$credential = new ManagedIdentityCredential();
$client = new SecretClient(
    vaultUrl: 'https://myvault.vault.azure.net/',
    credential: $credential
);

$secret = $client->getSecret('secret-name');
$secretValue = $secret->getValue();
```

## Azure CLI for RBAC Operations
```bash
# Check identity
PRINCIPAL_ID=$(az identity show \
  --resource-group myRg \
  --name myIdentity \
  --query principalId -o tsv)

# Assign role
az role assignment create \
  --role "Storage Blob Data Contributor" \
  --assignee-object-id $PRINCIPAL_ID \
  --assignee-principal-type ServicePrincipal \
  --scope "/subscriptions/{sub-id}/resourceGroups/{rg}/providers/Microsoft.Storage/storageAccounts/{account}"
```

## Security Checklist
- [ ] All Azure service credentials use managed identity
- [ ] No API keys or connection strings in code/config files
- [ ] RBAC roles follow least-privilege principle
- [ ] Key Vault enabled for all secrets
- [ ] Diagnostic logging configured and monitored
- [ ] Network access restricted (firewall, private endpoints)
- [ ] TLS/HTTPS enforced for all communication
- [ ] Input validation on all entry points
- [ ] Output encoding to prevent injection attacks
- [ ] Error messages don't expose implementation details
