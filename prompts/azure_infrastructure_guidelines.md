# Azure Infrastructure Guidelines (Bicep + CLI)

## Core Principles
- **Infrastructure as Code (IaC)**: All infrastructure defined in Bicep
- **Bicep-first**: Use Bicep for Azure resource definitions, generate JSON only when needed
- **CLI for operations**: Azure CLI for deployments, configuration, and operational tasks
- **Modular design**: Reusable, composable Bicep templates

## Bicep Standards
- Use `metadata` blocks for documentation
- Parameter descriptions with examples
- Output only what's needed downstream
- Use symbolic names; avoid magic strings
- Consistent spacing and formatting

```bicep
// Parameter with metadata
param location string = resourceGroup().location
@description('Name of the storage account')
param storageAccountName string

@description('Tags to apply to all resources')
param tags object = {
  environment: 'dev'
  managed: 'bicep'
}

// Clear resource declarations
resource storageAccount 'Microsoft.Storage/storageAccounts@2021-09-01' = {
  name: storageAccountName
  location: location
  kind: 'StorageV2'
  sku: {
    name: 'Standard_LRS'
  }
  properties: {
    accessTier: 'Hot'
  }
  tags: tags
}

// Outputs for downstream use
output storageAccountId string = storageAccount.id
output connectionString string = 'DefaultEndpointsProtocol=https;AccountName=${storageAccountName};...'
```

## Azure CLI Integration
- Use `az deployment` for Bicep deployments
- Leverage `--parameters` for passing values
- Capture outputs with `--query` for chaining operations
- Save subscription context: `az account show --query id -o tsv`

```bash
# Deploy infrastructure
az deployment group create \
  --resource-group myRg \
  --template-file main.bicep \
  --parameters \
    location=eastus \
    environment=prod

# Query outputs
STORAGE_ID=$(az deployment group show \
  --resource-group myRg \
  --name main \
  --query 'properties.outputs.storageAccountId.value' -o tsv)
```

## Naming Conventions
- **Resources**: kebab-case, descriptive prefix (e.g., `myapp-storage-prod`)
- **Parameters**: camelCase (e.g., `storageAccountName`)
- **Variables**: camelCase, compact (e.g., `apiUrl`, `dbConn`)
- **Outputs**: camelCase, descriptive (e.g., `functionAppId`, `connectionString`)

## Configuration Management
- Use parameter files (`.json`) for environment-specific values
- Store sensitive values in Azure Key Vault, reference in Bicep
- Use symbolic constants in scripts, avoid hardcoded values
- Parameter file structure mirrors Bicep parameters

```json
{
  "$schema": "https://schema.management.azure.com/schemas/2019-04-01/deploymentParameters.json#",
  "contentVersion": "1.0.0.0",
  "parameters": {
    "location": {
      "value": "eastus"
    },
    "environment": {
      "value": "prod"
    }
  }
}
```

## Deployment Workflow
1. Validate Bicep: `az bicep build --file main.bicep`
2. Validate deployment: `az deployment group validate ...`
3. Deploy: `az deployment group create ...`
4. Verify outputs and health checks
5. Document outputs for application configuration

## Resource Organization
- Group related resources logically
- Use consistent tagging strategy
- Enable diagnostic logging for monitoring
- Configure RBAC at resource creation time
- Avoid manual post-deployment steps

## Common Resource Patterns
- **Storage**: Secure by default (private endpoints, firewall rules)
- **App Services**: Managed identity enabled, environment variables from Key Vault
- **Databases**: Private endpoints, firewall rules, encrypted by default
- **APIs**: Authentication enforced, rate limiting, CORS configured
- **Functions**: Runtime version specified, managed identity assigned

## Secrets & Security
- Never commit secrets to Bicep files
- Use Key Vault references: `@Microsoft.KeyVault(SecretUri=...)`
- Use managed identity for inter-service communication
- Store connection strings in Key Vault
- Tags should not contain sensitive information
