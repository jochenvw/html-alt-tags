#!/bin/bash

# ============================================================================
# Azure Alt-Text Pipeline - Build & Push Container Image
# ============================================================================
# Builds Docker image and pushes to Azure Container Registry
# Usage: ./scripts/02-build-and-push.sh
# ============================================================================

set -e

# Load deployment output variables
if [ ! -f .deployment-output ]; then
    echo "❌ Error: .deployment-output not found"
    echo "   Run 01-deploy-infrastructure.sh first"
    exit 1
fi

source .deployment-output

IMAGE_TAG="${IMAGE_TAG:-latest}"

# ============================================================================
# 1. Build & Push Image in ACR
# ============================================================================

echo "🔨 Building Docker image in ACR..."
echo "   (Building in cloud - no local Docker required)"

# Build directly in ACR from workspace root
az acr build --registry "$ACR_NAME" \
  --image "php-handler:$IMAGE_TAG" \
  --image "php-handler:$(date +%Y%m%d-%H%M%S)" \
  --file ./containers/php-handler/Dockerfile \
  /workspace

echo "✅ Docker image built: $ACR_LOGIN_SERVER/php-handler:$IMAGE_TAG"

# ============================================================================
# 2. Update ACA Container App with New Image
# ============================================================================

echo ""
echo "🚀 Updating Container App with new image..."
az containerapp update \
  --name "$CONTAINER_APP_NAME" \
  --resource-group "$RESOURCE_GROUP" \
  --image "$ACR_LOGIN_SERVER/php-handler:$IMAGE_TAG"

echo "✅ Container App updated with new image"

# Verify deployment
echo ""
echo "🔍 Verifying deployment..."
DEPLOYED_IMAGE=$(az containerapp show \
  --name "$CONTAINER_APP_NAME" \
  --resource-group "$RESOURCE_GROUP" \
  --query "properties.template.containers[0].image" -o tsv)

echo "🖼️  Deployed image: $DEPLOYED_IMAGE"
echo ""
echo "🎉 Build and push complete!"
