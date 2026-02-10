# Getting Started

Quick guide to deploy and run the Azure Alt-Text Pipeline.

## Prerequisites

- Azure subscription with access to:
  - Azure Foundry (Phi-4-multimodal-instruct endpoint)
  - Azure Container Apps
  - Azure Storage
  - Azure Container Registry
- Azure CLI installed (automatically included in devcontainer)
- Multi-tenant access (if working across multiple Azure AD tenants)

## Configuration

1. **Clone and open in devcontainer:**
   ```bash
   git clone https://github.com/jochenvw/html-alt-tags.git
   cd html-alt-tags
   # Open in VS Code and "Reopen in Container"
   ```

2. **Configure environment variables:**
   
   Edit [.env](.env) with your Azure credentials:
   ```bash
   # Azure Foundry (Phi-4)
   AZURE_FOUNDRY_ENDPOINT=https://your-foundry-gateway.azure-api.net/.../chat/completions
   AZURE_FOUNDRY_KEY=your-api-key
   
   # Azure Tenant
   AZURE_TENANT_ID=your-tenant-id
   AZURE_REGION=swedencentral
   
   # Multi-tenant support
   MULTI_TENANT_ENABLED=true
   ```

## Deployment

Run the deployment scripts in order:

### 1. Deploy Infrastructure

Creates resource group, storage, ACR, and Container Apps:

```bash
cd scripts
./01-deploy-infrastructure.sh
```

**What it does:**
- Authenticates with Azure (skips if already logged in)
- Creates resource group in `swedencentral`
- Creates Azure Container Registry
- Builds placeholder container image in ACR
- Deploys Bicep template (storage, Event Grid, Container Apps)
- Saves outputs to `.deployment-output`

**Duration:** ~5-10 minutes

### 2. Build & Deploy Application

Builds the real PHP application and deploys to Container Apps:

```bash
./02-build-and-push.sh
```

**What it does:**
- Builds Docker image in ACR (cloud build, no local Docker needed)
- Updates Container App with new image
- Verifies deployment

**Duration:** ~3-5 minutes

### 3. Verify Event Grid (Optional)

Event Grid is automatically configured by Bicep, but you can verify:

```bash
./03-configure-event-grid.sh
```

### 4. Test End-to-End

Upload sample images and verify the pipeline:

```bash
./04-test-end-to-end.sh
```

**What it does:**
- Uploads sample images from `assets/` to storage
- Waits for Event Grid to trigger pipeline
- Shows generated alt-text JSON
- Lists processed blobs

## Utility Commands

Load helper functions for common tasks:

```bash
source ./scripts/utils.sh

# Stream container logs
aca-logs

# List blobs in containers
ingest-list
public-list

# Download alt-text results
blob-download img_0.alt.json ingest

# Check Event Grid status
eventgrid-status

# View pipeline status
status
```

## Architecture Overview

```
Upload Image → Storage (ingest) → Event Grid → Container App (PHP)
                                                     ↓
                                                Phi-4 Foundry
                                                     ↓
                                           Generate Alt-Text
                                                     ↓
                              Storage (public) ← JSON Sidecar
```

## Application Code

The PHP application is in [`src/functions/AltPipeline.Function/`](src/functions/AltPipeline.Function/):

```
App/
├── Bootstrap.php              # Service initialization
├── Contracts/
│   ├── ImageDescriber.php     # Interface for description services
│   └── TextTranslator.php     # Interface for translation services
├── Services/
│   ├── Phi4Describer.php      # Phi-4 image description
│   ├── Phi4Translator.php     # Phi-4 translation
│   ├── SlmDescriber.php       # GPT-3.5 alternative
│   ├── LlmDescriber.php       # GPT-4 alternative
│   ├── VisionDescriber.php    # Azure Vision alternative
│   └── TranslatorService.php  # Azure Translator alternative
├── Pipeline/
│   ├── PipelineOrchestrator.php  # Main workflow
│   ├── CmsDistiller.php          # Extract facts from metadata
│   ├── VisionHints.php           # Optional vision pre-analysis
│   ├── Guardrails.php            # Validate alt-text quality
│   └── AltWriter.php             # Write results to storage
└── Storage/
    └── BlobClient.php         # Azure Storage operations
```

### Key Configuration

Strategy selection in `.env`:

```bash
# Use Phi-4 for both tasks (recommended)
DESCRIBER=strategy:phi4
TRANSLATOR=strategy:phi4

# Alternative strategies
DESCRIBER=strategy:slm      # GPT-3.5
DESCRIBER=strategy:llm      # GPT-4
DESCRIBER=strategy:vision   # Azure Vision

TRANSLATOR=strategy:translator  # Azure Translator
TRANSLATOR=strategy:llm         # GPT-4 translation
```

## Multi-Tenant Usage

If you work across multiple Azure AD tenants:

### 1. Login with Tenant ID

```bash
curl -X POST http://localhost:8080/login \
  -H "Content-Type: application/json" \
  -d '{
    "tenant_id": "your-tenant-id",
    "user_id": "user@company.com"
  }'
```

**Response:**
```json
{
  "status": "ok",
  "session_token": "eyJ0ZW5hbnRfaWQ...",
  "tenant_id": "your-tenant-id",
  "expires_in": 3600
}
```

### 2. Use Session Token in Requests

Include the token in `/describe` requests:

```bash
curl -X POST http://localhost:8080/describe \
  -H "Content-Type: application/json" \
  -H "X-Session-Token: <session_token>" \
  -H "X-Tenant-ID: your-tenant-id" \
  -d '{
    "blobName": "img_0.png",
    "sidecar": {
      "asset": "product-id",
      "languages": ["EN", "NL", "FR"]
    }
  }'
```

## Testing Locally

```bash
# Install dependencies
cd src/functions/AltPipeline.Function
composer install

# Run tests
./vendor/bin/phpunit

# Start local dev server
php -S localhost:8080 -t /workspace/src/functions/AltPipeline.Function

# Test health endpoint
curl http://localhost:8080/health
```

## Monitoring

### View Container Logs

```bash
az containerapp logs show \
  -n php-handler \
  -g html-alt-texts \
  --follow
```

### Check Event Grid Delivery

```bash
az eventgrid event-subscription show \
  --name blob-to-handler \
  --source-resource-id $(az storage account show -n <account> -g html-alt-texts --query id -o tsv)
```

### List Storage Blobs

```bash
# Ingest (input)
az storage blob list \
  --account-name <account> \
  --container-name ingest \
  --output table

# Public (output)
az storage blob list \
  --account-name <account> \
  --container-name public \
  --output table
```

## Troubleshooting

### "Image pull failed"
- Ensure managed identity has `AcrPull` role on the registry
- Verify image exists: `az acr repository show -n alttextacr --image php-handler:latest`

### "Event Grid not triggering"
- Check Event Grid subscription exists in Bicep deployment outputs
- Verify webhook URL matches Container App FQDN
- Check dead-letter container for failed events

### "Alt-text not generated"
- Check container logs for errors
- Verify Phi-4 Foundry endpoint and key are correct
- Ensure storage account has public blob access enabled

## Cleanup

Delete all resources:

```bash
az group delete --name html-alt-texts --yes
```

## Next Steps

- Review [Alt-Text System Prompt](prompts/public_website_system_prompt.md) for generation rules
- Customize [Bicep templates](infra/bicep/) for your environment
- Add CI/CD with [GitHub Actions](.github/workflows/)
- Enable [Application Insights](https://learn.microsoft.com/azure/azure-monitor/app/app-insights-overview) for monitoring

## Support

- **Issues:** https://github.com/jochenvw/html-alt-tags/issues
- **Documentation:** [README.md](README.md)
- **Code Reference:** [src/functions/AltPipeline.Function/App/README.md](src/functions/AltPipeline.Function/App/README.md)
