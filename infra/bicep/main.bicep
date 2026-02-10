// ============================================================================
// Azure Alt-Text Pipeline - Bicep Infrastructure
// ============================================================================
// Deploys:
// - Azure Storage Account (ingest+public containers)
// - Event Grid subscription (BlobCreated → HTTP webhook)
// - Azure Container Apps (PHP handler)
// - User-Assigned Managed Identity
// Optional comments for CDN, Key Vault, Private Endpoints

@minLength(3)
@maxLength(24)
param storageAccountName string = 'alttxtst${uniqueString(resourceGroup().id)}'

@minLength(1)
@maxLength(64)
param containerAppName string = 'php-handler'

@minLength(1)
@maxLength(64)
param containerAppEnvName string = 'alt-text-env'

param location string = resourceGroup().location

param containerImageName string = 'php-handler'

@description('Container image tag (commit SHA or version)')
param containerImageTag string = 'latest'

param containerRegistry string

@description('Azure region for Container Apps')
param containerAppRegion string = location

@description('Skip Event Grid subscription creation (create it manually after app deployment)')
param skipEventGrid bool = false

@description('Azure AI Foundry gateway endpoint (base URL)')
param foundryEndpoint string = ''

@description('Azure AI Foundry deployment name for SLM')
param foundryDeploymentSlm string = 'Phi-4-multimodal-instruct'

var managedIdentityName = 'id-alt-pipeline'
var eventGridSubscriptionName = 'blob-to-handler'
var ingestContainerName = 'ingest'
var publicContainerName = 'public'
var storageBlobContributorRoleId = 'ba92f5b4-2d11-453d-a403-e96b0029c9fe'
var storageQueueDataContributorRoleId = '974c5e8b-45b9-4653-ba55-5f855dd0fb88'
var acrPullRoleId = '7f951dda-4ed3-4680-a7ca-43fe172d538d'

// ============================================================================
// User-Assigned Managed Identity
// ============================================================================
resource managedIdentity 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: managedIdentityName
  location: location
}

// ============================================================================
// Storage Account
// ============================================================================
resource storageAccount 'Microsoft.Storage/storageAccounts@2023-01-01' = {
  name: storageAccountName
  location: location
  kind: 'StorageV2'
  sku: {
    name: 'Standard_GRS'
  }
  properties: {
    accessTier: 'Hot'
    allowBlobPublicAccess: false
    allowSharedKeyAccess: true
    minimumTlsVersion: 'TLS1_2'
    publicNetworkAccess: 'Enabled'
  }
}

// Blob service (required for containers)
resource blobService 'Microsoft.Storage/storageAccounts/blobServices@2023-01-01' = {
  parent: storageAccount
  name: 'default'
}

// Ingest container (private)
resource ingestContainer 'Microsoft.Storage/storageAccounts/blobServices/containers@2023-01-01' = {
  parent: blobService
  name: ingestContainerName
  properties: {
    publicAccess: 'None'
  }
}

// Public container (accessible via SAS tokens)
resource publicContainer 'Microsoft.Storage/storageAccounts/blobServices/containers@2023-01-01' = {
  parent: blobService
  name: publicContainerName
  properties: {
    publicAccess: 'None'
  }
}

// Dead-letter container (for failed Event Grid messages)
resource deadletterContainer 'Microsoft.Storage/storageAccounts/blobServices/containers@2023-01-01' = {
  parent: blobService
  name: 'deadletter'
  properties: {
    publicAccess: 'None'
  }
}

// Dead-letter queue (optional, for failed Event Grid messages)
resource storageQueueServices 'Microsoft.Storage/storageAccounts/queueServices@2023-01-01' = {
  parent: storageAccount
  name: 'default'
}

resource dlqQueue 'Microsoft.Storage/storageAccounts/queueServices/queues@2023-01-01' = {
  parent: storageQueueServices
  name: 'dlq'
}

// ============================================================================
// Role Assignments (Managed Identity)
// ============================================================================

// Storage Blob Data Contributor
resource blobContributorRole 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  scope: storageAccount
  name: guid(storageAccount.id, managedIdentity.id, storageBlobContributorRoleId)
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', storageBlobContributorRoleId)
    principalId: managedIdentity.properties.principalId
    principalType: 'ServicePrincipal'
  }
}

// Queue Data Contributor (for DLQ)
resource queueContributorRole 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  scope: storageAccount
  name: guid(storageAccount.id, managedIdentity.id, storageQueueDataContributorRoleId)
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', storageQueueDataContributorRoleId)
    principalId: managedIdentity.properties.principalId
    principalType: 'ServicePrincipal'
  }
}

// ============================================================================
// Azure Container Registry
// ============================================================================

// Reference existing ACR (created externally in script)
resource acr 'Microsoft.ContainerRegistry/registries@2023-07-01' existing = {
  name: split(containerRegistry, '.')[0]
  scope: resourceGroup()
}

// ACR Pull Role for Managed Identity
resource acrPullRole 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  scope: acr
  name: guid(acr.id, managedIdentity.id, acrPullRoleId)
  properties: {
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', acrPullRoleId)
    principalId: managedIdentity.properties.principalId
    principalType: 'ServicePrincipal'
  }
}

// ============================================================================
// Azure AI Services
// ============================================================================

// Computer Vision (for VisionDescriber)
resource computerVision 'Microsoft.CognitiveServices/accounts@2023-05-01' = {
  name: 'cv-alt-pipeline-${uniqueString(resourceGroup().id)}'
  location: location
  kind: 'ComputerVision'
  sku: {
    name: 'S1'
  }
  properties: {
    customSubDomainName: 'cv-alt-${uniqueString(resourceGroup().id)}'
    publicNetworkAccess: 'Enabled'
    disableLocalAuth: false
  }
}

// Translator (for TranslatorService)
resource translator 'Microsoft.CognitiveServices/accounts@2023-05-01' = {
  name: 'tr-alt-pipeline-${uniqueString(resourceGroup().id)}'
  location: location
  kind: 'TextTranslation'
  sku: {
    name: 'S1'
  }
  properties: {
    customSubDomainName: 'tr-alt-${uniqueString(resourceGroup().id)}'
    publicNetworkAccess: 'Enabled'
    disableLocalAuth: false
  }
}

// ============================================================================
// Azure Container Apps Environment
// ============================================================================
resource containerAppEnv 'Microsoft.App/managedEnvironments@2023-11-02-preview' = {
  name: containerAppEnvName
  location: containerAppRegion
  properties: {
    appLogsConfiguration: {
      destination: 'log-analytics'
      logAnalyticsConfiguration: {
        customerId: logAnalytics.properties.customerId
        sharedKey: logAnalytics.listKeys().primarySharedKey
      }
    }
  }
}

// Log Analytics Workspace (required for ACA env)
resource logAnalytics 'Microsoft.OperationalInsights/workspaces@2022-10-01' = {
  name: 'la-alt-pipeline-${uniqueString(resourceGroup().id)}'
  location: location
  properties: {
    sku: {
      name: 'PerGB2018'
    }
    retentionInDays: 30
  }
}

// ============================================================================
// Azure Container Apps - PHP Handler
// ============================================================================
resource containerApp 'Microsoft.App/containerApps@2023-11-02-preview' = {
  name: containerAppName
  location: containerAppRegion
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: {
      '${managedIdentity.id}': {}
    }
  }
  properties: {
    managedEnvironmentId: containerAppEnv.id
    configuration: {
      ingress: {
        external: true
        targetPort: 8080
        transport: 'auto'
        allowInsecure: false
      }
      registries: [
        {
          server: containerRegistry
          identity: managedIdentity.id
        }
      ]
      secrets: [
        {
          name: 'storage-connection'
          value: 'DefaultEndpointsProtocol=https;AccountName=${storageAccount.name};AccountKey=${storageAccount.listKeys().keys[0].value};EndpointSuffix=${environment().suffixes.storage}'
        }
      ]
    }
    template: {
      containers: [
        {
          name: containerAppName
          image: '${containerRegistry}/${containerImageName}:${containerImageTag}'
          resources: {
            cpu: json('0.25')
            memory: '0.5Gi'
          }
          env: [
            {
              name: 'DESCRIBER'
              value: 'strategy:slm'
            }
            {
              name: 'TRANSLATOR'
              value: 'strategy:translator'
            }
            {
              name: 'LOCALES'
              value: 'EN,NL,FR'
            }
            {
              name: 'AZURE_STORAGE_ACCOUNT'
              value: storageAccount.name
            }
            {
              name: 'AZURE_STORAGE_CONNECTION_STRING'
              secretRef: 'storage-connection'
            }
            {
              name: 'AZURE_CLIENT_ID'
              value: managedIdentity.properties.clientId
            }
            {
              name: 'LOG_LEVEL'
              value: 'info'
            }
            {
              name: 'AZURE_VISION_ENDPOINT'
              value: computerVision.properties.endpoint
            }
            {
              name: 'AZURE_TRANSLATOR_ENDPOINT'
              value: translator.properties.endpoint
            }
            {
              name: 'AZURE_TRANSLATOR_REGION'
              value: location
            }
            {
              name: 'AZURE_FOUNDRY_ENDPOINT'
              value: foundryEndpoint
            }
            {
              name: 'AZURE_FOUNDRY_DEPLOYMENT_SLM'
              value: foundryDeploymentSlm
            }
          ]
        }
      ]
      scale: {
        minReplicas: 1
        maxReplicas: 5
        rules: [
          {
            name: 'http-scaling'
            http: {
              metadata: {
                concurrentRequests: '100'
              }
            }
          }
        ]
      }
    }
  }
  dependsOn: [
    acrPullRole
  ]
}

// ============================================================================
// Event Grid - Blob Storage Subscription (Optional)
// ============================================================================
resource eventGridSubscription 'Microsoft.EventGrid/eventSubscriptions@2023-12-15-preview' = if (!skipEventGrid) {
  name: eventGridSubscriptionName
  scope: storageAccount
  properties: {
    destination: {
      endpointType: 'WebHook'
      properties: {
        endpointUrl: 'https://${containerApp.properties.configuration.ingress.fqdn}/describe'
      }
    }
    filter: {
      subjectBeginsWith: '/blobServices/default/containers/${ingestContainerName}'
      includedEventTypes: [
        'Microsoft.Storage.BlobCreated'
      ]
      isSubjectCaseSensitive: false
    }
    eventDeliverySchema: 'EventGridSchema'
    retryPolicy: {
      maxDeliveryAttempts: 5
      eventTimeToLiveInMinutes: 60
    }
    deadLetterDestination: {
      endpointType: 'StorageBlob'
      properties: {
        resourceId: storageAccount.id
        blobContainerName: 'deadletter'
      }
    }
  }
}

// ============================================================================
// Outputs
// ============================================================================
output acaFqdn string = containerApp.properties.configuration.ingress.fqdn
output storageAccountName string = storageAccount.name
output storageAccountId string = storageAccount.id
output managedIdentityId string = managedIdentity.id
output managedIdentityPrincipalId string = managedIdentity.properties.principalId
output eventGridSubscriptionId string = eventGridSubscription.id
output ingestContainerUrl string = 'https://${storageAccount.name}.blob.${environment().suffixes.storage}/${ingestContainerName}'
output publicContainerUrl string = 'https://${storageAccount.name}.blob.${environment().suffixes.storage}/${publicContainerName}'
output computerVisionEndpoint string = computerVision.properties.endpoint
output translatorEndpoint string = translator.properties.endpoint
output logAnalyticsWorkspaceId string = logAnalytics.properties.customerId

// ============================================================================
// Optional: CDN/Front Door
// ============================================================================
// To enable Front Door for /public container:
// 1. Uncomment the resources below
// 2. Add frontDoorProfile parameter
// 3. Deploy with CDN prefix for public assets

// resource frontDoorProfile 'Microsoft.Cdn/profiles@2023-05-01' = {}

// ============================================================================
// Optional: Key Vault
// ============================================================================
// To add Key Vault for secrets management:
// 1. Add kvName parameter
// 2. Deploy Key Vault with secrets for AI endpoints
// 3. Update managed identity with Key Vault Secret User role
// 4. Update ACA environment variables to reference Key Vault URIs

// resource keyVault 'Microsoft.KeyVault/vaults@2023-07-01' = {}

// ============================================================================
// Optional: Private Endpoints
// ============================================================================
// To restrict storage to private network:
// 1. Deploy inside VNET (requires Bicep modules)
// 2. Create private endpoints for blob + queue services
// 3. Disable public access on containers
// 4. Update Event Grid to use private webhook URLs
