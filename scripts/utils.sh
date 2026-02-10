#!/bin/bash

# ============================================================================
# Azure Alt-Text Pipeline - Common Azure CLI Commands
# ============================================================================
# Utility functions for monitoring, troubleshooting, and cleanup
# Usage: source ./scripts/utils.sh
# ============================================================================

# Load deployment output variables if available
if [ -f .deployment-output ]; then
    source .deployment-output
fi

# ============================================================================
# Monitoring & Troubleshooting
# ============================================================================

aca-logs() {
    echo "📊 Streaming ACA container logs..."
    az containerapp logs show \
        --name "${CONTAINER_APP_NAME:-php-handler}" \
        --resource-group "$RESOURCE_GROUP" \
        --follow --tail 50
}

aca-show() {
    echo "ℹ️  Container App details..."
    az containerapp show \
        --name "${CONTAINER_APP_NAME:-php-handler}" \
        --resource-group "$RESOURCE_GROUP" \
        --output json | jq '.properties'
}

eventgrid-list() {
    echo "📋 Event Grid subscriptions..."
    az eventgrid event-subscription list \
        --source-resource-id "$STORAGE_ACCOUNT_ID" \
        --output table
}

eventgrid-status() {
    echo "🔍 Event Grid subscription delivery status..."
    az eventgrid event-subscription show \
        --name "blob-to-handler" \
        --source-resource-id "$STORAGE_ACCOUNT_ID" \
        --query "properties.deliveryWithResourceIdentity.delivery" -o json
}

dlq-check() {
    echo "⚠️  Checking dead-letter queue..."
    az storage queue list \
        --account-name "$STORAGE_ACCOUNT" \
        --account-key "$STORAGE_KEY" \
        --output table
}

# ============================================================================
# Storage & Assets Management
# ============================================================================

ingest-list() {
    echo "📦 Blobs in ingest container..."
    az storage blob list \
        --account-name "$STORAGE_ACCOUNT" \
        --account-key "$STORAGE_KEY" \
        --container-name "ingest" \
        --output table
}

public-list() {
    echo "📦 Blobs in public container..."
    az storage blob list \
        --account-name "$STORAGE_ACCOUNT" \
        --account-key "$STORAGE_KEY" \
        --container-name "public" \
        --output table
}

blob-delete() {
    local blob_name="$1"
    local container="${2:-ingest}"
    
    if [ -z "$blob_name" ]; then
        echo "❌ Usage: blob-delete <blob-name> [container]"
        return 1
    fi
    
    echo "🗑️  Deleting blob: $blob_name from $container..."
    az storage blob delete \
        --account-name "$STORAGE_ACCOUNT" \
        --account-key "$STORAGE_KEY" \
        --container-name "$container" \
        --name "$blob_name"
    
    echo "✅ Blob deleted"
}

blob-download() {
    local blob_name="$1"
    local container="${2:-ingest}"
    local output_file="${3:-./$blob_name}"
    
    if [ -z "$blob_name" ]; then
        echo "❌ Usage: blob-download <blob-name> [container] [output-file]"
        return 1
    fi
    
    echo "⬇️  Downloading blob: $blob_name..."
    az storage blob download \
        --account-name "$STORAGE_ACCOUNT" \
        --account-key "$STORAGE_KEY" \
        --container-name "$container" \
        --name "$blob_name" \
        --file "$output_file" \
        --output none
    
    echo "✅ Downloaded to: $output_file"
    [ "${blob_name##*.}" = "json" ] && cat "$output_file" | jq .
}

blob-tags() {
    local blob_name="$1"
    local container="${2:-ingest}"
    
    if [ -z "$blob_name" ]; then
        echo "❌ Usage: blob-tags <blob-name> [container]"
        return 1
    fi
    
    echo "🏷️  Tags on $blob_name..."
    az storage blob show \
        --account-name "$STORAGE_ACCOUNT" \
        --account-key "$STORAGE_KEY" \
        --container-name "$container" \
        --name "$blob_name" \
        --query tags
}

# ============================================================================
# Container App Management
# ============================================================================

aca-restart() {
    echo "🔄 Restarting container app..."
    local revision=$(az containerapp revision list \
        --name "${CONTAINER_APP_NAME:-php-handler}" \
        --resource-group "$RESOURCE_GROUP" \
        --query "[0].name" -o tsv)
    
    az containerapp revision deactivate \
        --app "${CONTAINER_APP_NAME:-php-handler}" \
        --resource-group "$RESOURCE_GROUP" \
        --revision "$revision"
    
    echo "✅ Container app restarted"
}

aca-scale() {
    local min_replicas="${1:-1}"
    local max_replicas="${2:-10}"
    
    echo "📈 Scaling container app: min=$min_replicas, max=$max_replicas..."
    az containerapp update \
        --name "${CONTAINER_APP_NAME:-php-handler}" \
        --resource-group "$RESOURCE_GROUP" \
        --min-replicas "$min_replicas" \
        --max-replicas "$max_replicas"
    
    echo "✅ Scaling updated"
}

aca-env-set() {
    local key="$1"
    local value="$2"
    
    if [ -z "$key" ] || [ -z "$value" ]; then
        echo "❌ Usage: aca-env-set <KEY> <VALUE>"
        return 1
    fi
    
    echo "⚙️  Setting environment variable: $key..."
    az containerapp update \
        --name "${CONTAINER_APP_NAME:-php-handler}" \
        --resource-group "$RESOURCE_GROUP" \
        --set-env-vars "$key=$value"
    
    echo "✅ Environment variable updated"
}

# ============================================================================
# Cleanup (⚠️ DESTRUCTIVE)
# ============================================================================

cleanup-blobs() {
    local container="${1:-ingest}"
    echo "⚠️  WARNING: Deleting all blobs in $container container..."
    read -p "   Are you sure? (type 'yes' to confirm): " confirm
    
    if [ "$confirm" = "yes" ]; then
        az storage blob list \
            --account-name "$STORAGE_ACCOUNT" \
            --account-key "$STORAGE_KEY" \
            --container-name "$container" \
            --query "[].name" -o tsv | while read blob; do
            echo "   🗑️  Deleting: $blob"
            blob-delete "$blob" "$container"
        done
        echo "✅ All blobs deleted from $container"
    else
        echo "❌ Cancelled"
    fi
}

cleanup-all() {
    echo "🚨 WARNING: Deleting entire resource group: $RESOURCE_GROUP"
    echo "   This will delete ALL resources including storage, ACA, ACR, etc."
    read -p "   Type resource group name to confirm: " confirm
    
    if [ "$confirm" = "$RESOURCE_GROUP" ]; then
        echo "🗑️  Deleting resource group..."
        az group delete \
            --name "$RESOURCE_GROUP" \
            --yes --no-wait
        echo "✅ Resource group deletion initiated"
    else
        echo "❌ Cancelled"
    fi
}

# ============================================================================
# Helpers
# ============================================================================

status() {
    echo ""
    echo "📊 ═══════════════════════════════════════════════════════════"
    echo "  Azure Alt-Text Pipeline Status"
    echo "═══════════════════════════════════════════════════════════"
    echo ""
    echo "  🔹 Resource Group: $RESOURCE_GROUP"
    echo "  🔹 Location: $LOCATION"
    echo "  🔹 Storage Account: $STORAGE_ACCOUNT"
    echo "  🔹 Container App: $CONTAINER_APP_NAME"
    echo "  🔹 ACR: $ACR_LOGIN_SERVER"
    echo "  🔹 ACA FQDN: $ACA_FQDN"
    echo ""
    echo "📋 Available commands:"
    echo "   Monitoring:     aca-logs, aca-show, eventgrid-list, eventgrid-status, dlq-check"
    echo "   Storage:        ingest-list, public-list, blob-delete, blob-download, blob-tags"
    echo "   Management:     aca-restart, aca-scale, aca-env-set"
    echo "   Cleanup:        cleanup-blobs, cleanup-all"
    echo ""
}

help() {
    echo ""
    echo "📖 Azure Alt-Text Pipeline - Utility Functions"
    echo ""
    echo "  Usage: source ./scripts/utils.sh"
    echo ""
    echo "  Monitoring & Logs:"
    echo "    aca-logs              - Stream container app logs"
    echo "    aca-show              - Show container app details"
    echo "    eventgrid-list        - List Event Grid subscriptions"
    echo "    eventgrid-status      - Show Event Grid delivery status"
    echo "    dlq-check             - Check dead-letter queue"
    echo ""
    echo "  Storage Operations:"
    echo "    ingest-list           - List blobs in ingest container"
    echo "    public-list           - List blobs in public container"
    echo "    blob-delete <name>    - Delete a blob"
    echo "    blob-download <name>  - Download a blob"
    echo "    blob-tags <name>      - Show blob tags"
    echo ""
    echo "  Container App Management:"
    echo "    aca-restart           - Restart container app"
    echo "    aca-scale [min] [max] - Scale container app"
    echo "    aca-env-set <K> <V>   - Set environment variable"
    echo ""
    echo "  Cleanup (destructive):"
    echo "    cleanup-blobs         - Delete all blobs in container"
    echo "    cleanup-all           - Delete entire resource group"
    echo ""
    echo "  Utility:"
    echo "    status                - Show pipeline status"
    echo "    help                  - Show this help message"
    echo ""
}

echo "✅ Utilities loaded. Type 'help' for available commands."
