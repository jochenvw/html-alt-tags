#!/bin/bash

# ============================================================================
# Azure Alt-Text Pipeline - Configure Event Grid Subscription
# ============================================================================
# Sets up Event Grid to trigger the PHP handler on blob upload
# Usage: ./scripts/03-configure-event-grid.sh
# ============================================================================

set -e

# Load deployment output variables
if [ ! -f .deployment-output ]; then
    echo "❌ Error: .deployment-output not found"
    echo "   Run 01-deploy-infrastructure.sh first"
    exit 1
fi

source .deployment-output

# ============================================================================
# 1. Create Event Grid Subscription
# ============================================================================

echo "🔌 Creating Event Grid subscription..."
echo "   Webhook: https://$ACA_FQDN/describe"
echo "   (This validates the endpoint is ready to receive events)"

# Storage account resource ID
STORAGE_ACCOUNT_ID="$STORAGE_ACCOUNT_ID"

# If not in deployment output, retrieve it
if [ -z "$STORAGE_ACCOUNT_ID" ]; then
  STORAGE_ACCOUNT_ID=$(az storage account show \
    --name "$STORAGE_ACCOUNT" \
    --resource-group "$RESOURCE_GROUP" \
    --query id -o tsv)
fi

# Create Event Grid subscription
az eventgrid event-subscription create \
  --name "blob-to-handler" \
  --source-resource-id "$STORAGE_ACCOUNT_ID" \
  --endpoint "https://$ACA_FQDN/describe" \
  --endpoint-type webhook \
  --included-event-types Microsoft.Storage.BlobCreated \
  --subject-begins-with "/blobServices/default/containers/ingest" \
  --max-delivery-attempts 5 \
  --event-ttl 60 \
  --output table

echo ""
echo "✅ Event Grid subscription created: blob-to-handler"

# ============================================================================
# 2. Show Event Grid Subscription Details
# ============================================================================

echo ""
echo "🔍 Verifying Event Grid subscription..."
az eventgrid event-subscription show \
  --name "blob-to-handler" \
  --source-resource-id "$STORAGE_ACCOUNT_ID" \
  --output table

echo ""
echo "🎉 Event Grid configuration complete!"
echo ""
echo "✨ Next steps:"
echo "   1. Run 04-test-end-to-end.sh to upload sample images"
echo "   2. Monitor logs with: az containerapp logs show -n $CONTAINER_APP_NAME -g $RESOURCE_GROUP --follow"
