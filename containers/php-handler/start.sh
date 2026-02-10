#!/bin/sh

# ============================================================================
# Azure Alt-Text Pipeline - PHP Handler Start Script
# ============================================================================
# Starts PHP built-in server for Azure Container Apps.
#
# Environment variables:
#   - PORT: HTTP port (default: 8080)
#   - LOG_LEVEL: info, debug, warning, error (default: info)
#   - WORKERS: Number of PHP CLI server workers (default: 4)

set -e

# Configuration
PORT=${PORT:-8080}
WORKERS=${PHP_CLI_SERVER_WORKERS:-4}
LOG_LEVEL=${LOG_LEVEL:-info}

echo "=========================================="
echo "Azure Alt-Text Pipeline - PHP Handler"
echo "=========================================="
echo "Port: $PORT"
echo "Workers: $WORKERS"
echo "Log Level: $LOG_LEVEL"
echo ""

# Check if .env exists; if not, warn that env vars should be set
if [ ! -f "/app/.env" ]; then
    echo "⚠️  .env file not found. Ensure environment variables are set:"
    echo "   - DESCRIBER"
    echo "   - TRANSLATOR"
    echo "   - LOCALES"
    echo "   - AZURE_FOUNDRY_ENDPOINT"
    echo "   - AZURE_STORAGE_ACCOUNT"
    echo "   - AZURE_CLIENT_ID (for Managed Identity)"
    echo ""
fi

# Verify essential configuration
if [ -z "$AZURE_STORAGE_ACCOUNT" ]; then
    echo "❌ AZURE_STORAGE_ACCOUNT environment variable is not set."
    exit 1
fi

echo "✅ Configuration verified."
echo ""
echo "🚀 Starting PHP server on 0.0.0.0:$PORT"
echo "📍 Endpoint: http://localhost:$PORT"
echo "💚 Health check: GET http://localhost:$PORT/health"
echo "📤 Pipeline: POST http://localhost:$PORT/describe"
echo ""

# Start PHP built-in server
exec php -S 0.0.0.0:"$PORT" handler.php
