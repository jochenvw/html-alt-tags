# Azure Alt-Text Pipeline

Serverless image alt-text generator powered by Azure Foundry (Phi-4-multimodal-instruct), Event Grid, and Container Apps.

Automatically generates accessible, SEO-friendly HTML alt text for product images when uploaded to Azure Blob Storage.

## How It Works

1. **Upload** - Drop image + YAML metadata into Azure Storage `/ingest` container
2. **Trigger** - Event Grid detects upload and calls Container App webhook
3. **Process** - PHP handler uses Phi-4 to generate alt-text in multiple languages
4. **Validate** - Guardrails check compliance (brand+model required, length limits, no marketing fluff)
5. **Output** - JSON sidecar written to storage, blob tags set, approved assets copied to `/public`

```
📤 Upload (img.png + img.yml)
    ↓
📦 Storage (ingest)
    ↓
⚡ Event Grid
    ↓
🐳 Container App (PHP)
    ↓
🤖 Phi-4 Foundry
    ↓
✅ Alt-Text Generated
    ↓
📄 JSON + Tags + Public Copy
```

## Key Features

- **Multimodal AI** - Phi-4-multimodal-instruct for vision + text understanding
- **Multi-language** - Translate to any configured languages
- **Reactive** - Event-driven processing (no batch jobs)
- **Pluggable** - Switch between Phi-4, GPT-4, GPT-3.5, or Azure Vision
- **Compliant** - Strict rules: 80-160 chars, brand+model required, no fluff
- **Multi-tenant** - Support for users across multiple Azure AD tenants
- **Serverless** - Scales automatically, pay per use

## Assumptions

This pipeline assumes you have:

✅ **Azure Foundry Access** - Phi-4-multimodal-instruct endpoint via Azure API Management  
✅ **Azure Subscription** - With permissions to create Container Apps, Storage, ACR  
✅ **Tenant ID** - For authentication (supports multi-tenant scenarios)  
✅ **Regional Deployment** - Defaults to `swedencentral`

**Optional (fallback strategies):**
- Azure OpenAI (GPT-3.5/GPT-4) for alternative description/translation
- Azure AI Vision for image-only analysis
- Azure AI Translator for dedicated translation service

## Quick Start

**Deploy in 3 commands:**

```bash
cd scripts
./01-deploy-infrastructure.sh  # Deploy Azure resources (~5-10 min)
./02-build-and-push.sh         # Build & deploy app (~3-5 min)
./04-test-end-to-end.sh        # Upload test images
```

**📖 [Complete Getting Started Guide →](GETTING_STARTED.md)**

## Architecture

**Services:**
- **Azure Container Apps** - Host PHP handler (serverless, auto-scaling)
- **Azure Storage** - Blob containers (ingest/public) + Event Grid source
- **Azure Container Registry** - Store container images (cloud-built)
- **Azure Foundry** - Phi-4-multimodal-instruct API via API Management
- **Event Grid** - React to blob uploads with webhook triggers
- **Managed Identity** - Secure access to ACR and Storage (no secrets)

**Technology:**
- PHP 8.3 (modern, performant, great for serverless)
- Bicep (Infrastructure as Code)
- Composer (dependency management)
- PHPUnit (testing)

## Configuration

Minimal `.env` setup:

```bash
# AI Strategy (phi4 | slm | llm | vision)
DESCRIBER=strategy:phi4
TRANSLATOR=strategy:phi4

# Azure Foundry
AZURE_FOUNDRY_ENDPOINT=https://your-gateway.azure-api.net/.../chat/completions
AZURE_FOUNDRY_KEY=your-api-key
AZURE_REGION=swedencentral

# Multi-Tenant
AZURE_TENANT_ID=your-tenant-id
MULTI_TENANT_ENABLED=true

# Languages
LOCALES=EN,NL,FR
```

## Project Structure

```
.
├── scripts/                  # Deployment automation
│   ├── 01-deploy-infrastructure.sh
│   ├── 02-build-and-push.sh
│   ├── 03-configure-event-grid.sh
│   ├── 04-test-end-to-end.sh
│   └── utils.sh             # Helper commands
├── infra/bicep/             # Infrastructure as Code
│   ├── main.bicep
│   └── parameters.json
├── src/functions/
│   └── AltPipeline.Function/  # PHP application
│       ├── handler.php          # HTTP router
│       ├── App/
│       │   ├── Services/        # AI integrations
│       │   ├── Pipeline/        # Processing workflow
│       │   └── Storage/         # Azure Storage client
│       └── Tests/               # PHPUnit tests
├── containers/
│   └── php-handler/
│       ├── Dockerfile
│       └── start.sh
├── assets/                  # Sample test images
└── prompts/                 # System prompt rules
```

## API

### POST `/describe`

Generate alt-text for an image:

```json
{
  "blobName": "img_0.png",
  "sidecar": {
    "make": "Epson",
    "model": "EcoTank L3560",
    "description": "Multifunction printer..."
  }
}
```

**Response:**
```json
{
  "status": "ok",
  "tenant_id": "your-tenant-id",
  "altJson": {
    "image": "img_0.png",
    "altText": {
      "en": "Epson EcoTank L3560 A4 multifunction ink tank printer, front view",
      "nl": "Epson EcoTank L3560 A4 multifunctie-printer...",
      "fr": "Imprimante multifonction EcoTank Epson L3560..."
    },
    "confidence": 0.89,
    "policyCompliant": true
  }
}
```

### POST `/login` (Multi-tenant)

Get session token for tenant:

```json
{
  "tenant_id": "my-tenant-id",
  "user_id": "user@company.com"
}
```

### GET `/health`

Health check endpoint.

## Development

```bash
# Open in devcontainer (VS Code)
# Azure CLI, PHP 8.3, Composer pre-installed

# Install dependencies
cd src/functions/AltPipeline.Function
composer install

# Run tests
./vendor/bin/phpunit

# Local dev server
php -S localhost:8080 -t src/functions/AltPipeline.Function

# Load utility commands
source scripts/utils.sh
```

## System Prompt

Alt-text follows strict rules from [`prompts/public_website_system_prompt.md`](prompts/public_website_system_prompt.md):

- ✅ **Visual-first** - Describe what's visible before context
- ✅ **Brand + Model required** - Always include (e.g., "Epson EcoTank L3560")
- ✅ **80-160 chars** - Target range (hard limit: 125)
- ❌ **No fluff** - Forbidden: "image of", "picture of", marketing hype
- ❌ **No invention** - Only visible content + metadata

## Monitoring

```bash
# Stream logs
az containerapp logs show -n php-handler -g html-alt-texts --follow

# List processed blobs
source scripts/utils.sh
ingest-list
public-list

# Check Event Grid status
eventgrid-status
```

## Documentation

- **[Getting Started Guide](GETTING_STARTED.md)** - Deployment and usage
- **[System Prompt Rules](prompts/public_website_system_prompt.md)** - Alt-text generation guidelines
- **[Application Code README](src/functions/AltPipeline.Function/App/README.md)** - Code architecture

## Contributing

1. Fork and create feature branch
2. Make changes and add tests
3. Run `./vendor/bin/phpunit`
4. Submit pull request

## License

MIT

## Support

- **Issues:** https://github.com/jochenvw/html-alt-tags/issues
- **Getting Started:** [GETTING_STARTED.md](GETTING_STARTED.md)
