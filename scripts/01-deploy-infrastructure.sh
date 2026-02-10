#!/bin/bash

# ============================================================================
# Azure Alt-Text Pipeline - Deploy Infrastructure
# ============================================================================
# Deploys resource group, storage, ACR, and ACA via Bicep
# Usage: ./scripts/01-deploy-infrastructure.sh
# ============================================================================

set -e

# ============================================================================
# Set Variables
# ============================================================================

# Load .env if available
if [ -f .env ]; then
    export $(grep -v '^#' .env | grep -v '^$' | xargs)
fi

SUBSCRIPTION_ID="e3825c9a-4bce-43e1-899e-1610156c9383"
TENANT_ID="16b3c013-d300-468d-ac64-7eda0820b6d3"
RESOURCE_GROUP="${RESOURCE_GROUP:-html-alt-texts}"
LOCATION="${LOCATION:-swedencentral}"
ACR_NAME="${ACR_NAME:-alttextacr}"
ACR_RESOURCE_GROUP="${RESOURCE_GROUP}"
STORAGE_ACCOUNT_PREFIX="alttxt"
CONTAINER_APP_NAME="${CONTAINER_APP_NAME:-php-handler}"
CONTAINER_APP_ENV="${CONTAINER_APP_ENV:-alt-text-env}"
IMAGE_TAG="${IMAGE_TAG:-latest}"

# Prompt for tenant ID if not set
if [ -z "$TENANT_ID" ]; then
    echo "⚠️  AZURE_TENANT_ID not found in .env or environment"
    read -p "   Enter your Azure Tenant ID: " TENANT_ID
fi

# ============================================================================
# 1. Authenticate & Setup
# ============================================================================

echo "🔐 Checking Azure authentication..."

# Check if already logged in
if az account show &>/dev/null; then
    echo "✅ Already logged in to Azure"
else
    echo "   Logging in with tenant: $TENANT_ID"
    az login --tenant "$TENANT_ID"
fi

echo "📋 Setting active subscription..."
az account set --subscription "$SUBSCRIPTION_ID"

echo "✅ Verifying authentication..."
az account show

# ============================================================================
# 2. Create Resource Group
# ============================================================================

echo ""
echo "📁 Creating resource group: $RESOURCE_GROUP in $LOCATION..."
az group create \
  --name "$RESOURCE_GROUP" \
  --location "$LOCATION"

echo "✅ Resource group created: $RESOURCE_GROUP"

# ============================================================================
# 3. Create Azure Container Registry
# ============================================================================

echo ""
echo "🐳 Creating Azure Container Registry: $ACR_NAME..."
az acr create \
  --resource-group "$RESOURCE_GROUP" \
  --name "$ACR_NAME" \
  --sku Basic \
  --admin-enabled true

# Get ACR login server
ACR_LOGIN_SERVER=$(az acr show \
  --resource-group "$RESOURCE_GROUP" \
  --name "$ACR_NAME" \
  --query loginServer -o tsv)

echo "✅ ACR created: $ACR_LOGIN_SERVER"

# ============================================================================
# 3b. Build Placeholder Image in ACR
# ============================================================================

echo ""
echo "🐳 Building placeholder image in ACR..."
echo "   (Required before Container App deployment)"

# Create temporary build context
mkdir -p /tmp/acr-placeholder-build
cat > /tmp/acr-placeholder-build/Dockerfile <<'EOF'
FROM php:8.3-cli
RUN echo '<?php http_response_code(503); echo "Container initializing...";' > /tmp/index.php
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/tmp"]
EOF

# Build directly in ACR (no local Docker required)
az acr build --registry "$ACR_NAME" \
  --image "php-handler:$IMAGE_TAG" \
  --file /tmp/acr-placeholder-build/Dockerfile \
  /tmp/acr-placeholder-build

echo "✅ Placeholder image built in $ACR_LOGIN_SERVER/php-handler:$IMAGE_TAG"
rm -rf /tmp/acr-placeholder-build

# ============================================================================
# 4. Deploy Infrastructure via Bicep
# ============================================================================

echo ""
echo "🏗️  Validating Bicep template..."
az bicep build --file ./infra/bicep/main.bicep

echo "🚀 Deploying infrastructure via ARM..."
DEPLOY_OUTPUT=$(az deployment group create \
  --resource-group "$RESOURCE_GROUP" \
  --template-file ./infra/bicep/main.bicep \
  --parameters ./infra/bicep/parameters.json \
  --parameters containerRegistry="$ACR_LOGIN_SERVER" \
  --parameters containerImageTag="$IMAGE_TAG" \
  --output json)

echo "✅ Infrastructure deployed"

# Extract outputs
STORAGE_ACCOUNT=$(echo "$DEPLOY_OUTPUT" | jq -r '.properties.outputs.storageAccountName.value')
ACA_FQDN=$(echo "$DEPLOY_OUTPUT" | jq -r '.properties.outputs.acaFqdn.value')
MANAGED_IDENTITY_ID=$(echo "$DEPLOY_OUTPUT" | jq -r '.properties.outputs.managedIdentityId.value')

echo ""
echo "📦 Storage Account: $STORAGE_ACCOUNT"
echo "🌐 ACA FQDN: $ACA_FQDN"
echo "🔐 Managed Identity: $MANAGED_IDENTITY_ID"

# ============================================================================
# 5. Create Blob Containers (if not already in Bicep)
# ============================================================================

echo ""
echo "📦 Creating blob containers..."

# Get storage account key
STORAGE_KEY=$(az storage account keys list \
  --resource-group "$RESOURCE_GROUP" \
  --account-name "$STORAGE_ACCOUNT" \
  --query "[0].value" -o tsv)

# Create ingest container (private)
az storage container create \
  --account-name "$STORAGE_ACCOUNT" \
  --account-key "$STORAGE_KEY" \
  --name "ingest" \
  --public-access "off" 2>/dev/null || true

# Create public container (public)
az storage container create \
  --account-name "$STORAGE_ACCOUNT" \
  --account-key "$STORAGE_KEY" \
  --name "public" \
  --public-access "blob" 2>/dev/null || true

echo "✅ Blob containers created: ingest (private), public (public)"

# ============================================================================
# 6. Assign Roles to Managed Identity
# ============================================================================

echo ""
echo "🔑 Assigning RBAC roles..."

STORAGE_ACCOUNT_ID=$(az storage account show \
  --resource-group "$RESOURCE_GROUP" \
  --name "$STORAGE_ACCOUNT" \
  --query id -o tsv)

PRINCIPAL_ID=$(echo "$DEPLOY_OUTPUT" | jq -r '.properties.outputs.managedIdentityPrincipalId.value')

# Grant Storage Blob Data Contributor role
az role assignment create \
  --assignee-object-id "$PRINCIPAL_ID" \
  --role "Storage Blob Data Contributor" \
  --scope "$STORAGE_ACCOUNT_ID" 2>/dev/null || true

echo "✅ RBAC role assigned: Storage Blob Data Contributor"

# ============================================================================
# 7. Grant AI Services Access to Managed Identity
# ============================================================================

echo ""
echo "🤖 Assigning AI Services roles to Managed Identity..."

# Get AI service resource IDs
CV_NAME=$(az cognitiveservices account list -g "$RESOURCE_GROUP" --query "[?kind=='ComputerVision'] | [0].name" -o tsv)
TR_NAME=$(az cognitiveservices account list -g "$RESOURCE_GROUP" --query "[?kind=='TextTranslation'] | [0].name" -o tsv)

if [ ! -z "$CV_NAME" ]; then
  CV_ID=$(az cognitiveservices account show -g "$RESOURCE_GROUP" -n "$CV_NAME" --query id -o tsv)
  az role assignment create \
    --role "Cognitive Services User" \
    --assignee-object-id "$PRINCIPAL_ID" \
    --assignee-principal-type ServicePrincipal \
    --scope "$CV_ID" 2>/dev/null || true
  echo "✅ Granted Cognitive Services User role for Computer Vision"
fi

if [ ! -z "$TR_NAME" ]; then
  TR_ID=$(az cognitiveservices account show -g "$RESOURCE_GROUP" -n "$TR_NAME" --query id -o tsv)
  az role assignment create \
    --role "Cognitive Services User" \
    --assignee-object-id "$PRINCIPAL_ID" \
    --assignee-principal-type ServicePrincipal \
    --scope "$TR_ID" 2>/dev/null || true
  echo "✅ Granted Cognitive Services User role for Translator"
fi

# ============================================================================
# 8. Grant Current User Storage Access for Testing
# ============================================================================

echo ""
echo "👤 Granting current user Storage Blob Data Contributor role..."

USER_ID=$(az ad signed-in-user show --query id -o tsv)
az role assignment create \
  --role "Storage Blob Data Contributor" \
  --assignee "$USER_ID" \
  --scope "$STORAGE_ACCOUNT_ID" 2>/dev/null || true

echo "✅ Current user granted Storage Blob Data Contributor role"

# ============================================================================
# 9. Enable Shared Key Access on Storage Account
# ============================================================================

echo ""
echo "🔓 Enabling shared key access on storage account..."

az storage account update \
  -n "$STORAGE_ACCOUNT" \
  -g "$RESOURCE_GROUP" \
  --allow-shared-key-access true \
  --output none 2>/dev/null || true

echo "✅ Shared key access enabled"

# ============================================================================
# 10. Save outputs for later use
# ============================================================================

echo ""
echo "💾 Saving deployment outputs..."

# Get AI service endpoints
if [ ! -z "$CV_NAME" ]; then
  CV_ENDPOINT=$(az cognitiveservices account show -g "$RESOURCE_GROUP" -n "$CV_NAME" --query 'properties.endpoint' -o tsv)
else
  CV_ENDPOINT=""
fi

if [ ! -z "$TR_NAME" ]; then
  TR_ENDPOINT=$(az cognitiveservices account show -g "$RESOURCE_GROUP" -n "$TR_NAME" --query 'properties.endpoint' -o tsv)
else
  TR_ENDPOINT=""
fi

cat > .deployment-output <<EOF
SUBSCRIPTION_ID=$SUBSCRIPTION_ID
RESOURCE_GROUP=$RESOURCE_GROUP
LOCATION=$LOCATION
ACR_NAME=$ACR_NAME
ACR_LOGIN_SERVER=$ACR_LOGIN_SERVER
STORAGE_ACCOUNT=$STORAGE_ACCOUNT
STORAGE_KEY=$STORAGE_KEY
CONTAINER_APP_NAME=$CONTAINER_APP_NAME
CONTAINER_APP_ENV=$CONTAINER_APP_ENV
ACA_FQDN=$ACA_FQDN
MANAGED_IDENTITY_ID=$MANAGED_IDENTITY_ID
PRINCIPAL_ID=$PRINCIPAL_ID
STORAGE_ACCOUNT_ID=$STORAGE_ACCOUNT_ID
CV_ENDPOINT=$CV_ENDPOINT
TR_ENDPOINT=$TR_ENDPOINT
EOF

echo "✅ Deployment outputs saved to .deployment-output"
echo ""
echo "🎉 Infrastructure deployment complete!"
