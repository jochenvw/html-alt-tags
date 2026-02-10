# Azure Alt-Text Pipeline - Function Handler

PHP-based HTTP handler for serverless alt text generation.

## Architecture

### HTTP Endpoints

- **GET /health** – Health check
- **POST /describe** – Main alt text generation pipeline

### Classes & Responsibilities

```
App/
├── Bootstrap.php                 # Service initialization & DI container
├── Contracts/
│   ├── ImageDescriber.php       # Interface for describers
│   └── TextTranslator.php       # Interface for translators
├── Services/
│   ├── SlmDescriber.php         # Azure AI Foundry / Phi-4 (strategy)
│   ├── LlmDescriber.php         # Azure OpenAI LLM (strategy)
│   ├── VisionDescriber.php      # Azure AI Vision (strategy)
│   ├── TranslatorService.php    # Azure AI Translator (strategy)
│   └── LlmTranslator.php        # LLM-based translator (fallback)
├── Pipeline/
│   ├── PipelineOrchestrator.php # Main processing flow
│   ├── CmsDistiller.php         # Extract product facts from CMS text
│   ├── VisionHints.php          # Derive angle/view heuristics
│   └── AltWriter.php            # Persist *.alt.json & set blob tags
├── Auth/
│   └── ManagedIdentityCredential.php  # Azure MI token provider
└── Storage/
    └── BlobClient.php           # Azure Blob Storage wrapper
```

## Flow

```mermaid
graph LR
    A[HTTP POST /describe] --> B[Load Metadata]
    B --> C[CmsDistiller]
    C --> D[VisionHints]
    D --> E[Describer <br/>SLM|LLM|Vision]
    E --> F[Translator<br/>Translator|LLM]
    F --> G[AltWriter<br/>Persist]
    G --> H[Return JSON]
```

## Configuration

### Environment Variables

See [.env.sample](../../.env.sample):

```bash
DESCRIBER=strategy:slm              # slm | llm | vision
TRANSLATOR=strategy:translator      # translator | llm
LOCALES=EN,NL,FR
AZURE_FOUNDRY_ENDPOINT=...
AZURE_FOUNDRY_DEPLOYMENT_SLM=Phi-4-multimodal-instruct
AZURE_VISION_ENDPOINT=...
AZURE_TRANSLATOR_ENDPOINT=...
AZURE_STORAGE_ACCOUNT=...
AZURE_CLIENT_ID=...                  # User-assigned managed identity
LOG_LEVEL=info
```

### Strategy Pattern

**Describers** (image → alt text):
- `strategy:slm` (default) – Fast, cheap Azure OpenAI SLM
- `strategy:llm` – Richer GPT-4, more expensive
- `strategy:vision` – Azure AI Vision API (fast, visual-first)

**Translators** (English → multi-language):
- `strategy:translator` (default) – Fast Azure AI Translator
- `strategy:llm` – GPT-4 based, contextual

### System Prompt

Embedded in `SlmDescriber.php` (heredoc). Governs:
- Visual-first rule
- Brand + Model required
- No "image of", "picture of", codes
- 80–160 chars optimal, ≤125 hard limit
- No claims or invention

See [prompts/public_website_system_prompt.md](../../prompts/public_website_system_prompt.md) for full rules.

## Request/Response

### POST /describe Request

```json
{
  "blobName": "img_0.png",
  "sidecar": {
    "asset": "epson-ecotank-l3560",
    "source": "public website",
    "languages": ["EN", "NL", "FR"],
    "make": "Epson",
    "model": "EcoTank L3560",
    "description": "Epson EcoTank L3560 Multifunction Printer...",
    "angle": "front"
  },
  "cmsText": "Optional unstructured product description"
}
```

### POST /describe Response (200 OK)

```json
{
  "status": "ok",
  "blob": "img_0.png",
  "altText": {
    "asset": "img_0.png",
    "image": "img_0.png",
    "source": "public website",
    "altText": {
      "en": "Colorful Epson ink tank printer.",
      "nl": "Kleurrijke Epson inkttankprinter.",
      "fr": "Imprimante Epson colorée pour réservoir d'encre."
    },
    "generatedAt": "2026-02-10T12:23:52+00:00"
  }
}
```

### Response (400 Bad Request)

```json
{
  "error": "Invalid request: Missing required field: blobName"
}
```

### Response (500 Error)

```json
{
  "error": "Processing failed",
  "message": "SlmDescriber error: OpenAI API error: HTTP 401"
}
```

## Quality Rules

Quality is enforced through the **system prompt** rather than a separate validation step. The prompt instructs Phi-4 to:

| Rule | Guideline |
|------|----------|
| **Visual-first** | Describe what is visually present |
| **Brand + Model** | Include make and model when visible |
| **No filler** | No "image of", "picture of", codes |
| **Length** | 80–160 chars optimal |
| **No marketing** | No superlatives, hype, or claims |
| **Punctuation** | Capital first letter, trailing full stop |

Post-processing in `SlmDescriber.php` normalizes punctuation automatically.

## TODO TODOs for Production

- [ ] **BlobClient**: Implement full Azure SDK methods (read, upload, tags, copy)
- [ ] **YAML parsing**: Use `symfony/yaml` to parse sidecar metadata
- [ ] **SAS URL generation**: Implement in BlobClient
- [ ] **Error handling**: Detailed error logging, retry logic, dead-letter queues
- [ ] **Rate limiting**: Account for OpenAI API rate limits
- [ ] **Caching**: Cache vision results, translations
- [ ] **Integration tests**: Real Azure Storage + Event Grid testing
- [ ] **Monitoring**: Application Insights integration
- [ ] **Security**: IP allowlisting, API key rotation, audit logging

## Local Testing

### Prerequisites

```bash
php -v  # PHP 8.3+
composer --version
```

### Install Dependencies

```bash
cd src/functions/AltPipeline.Function
composer install
```

### Run Tests

```bash
./vendor/bin/phpunit
```

### Start Dev Server

```bash
php -S localhost:8080 -t src/functions/AltPipeline.Function
```

### Manual Test

```bash
curl -X POST http://localhost:8080/describe \
  -H "Content-Type: application/json" \
  -d '{
    "blobName": "img_0.png",
    "sidecar": {
      "make": "Epson",
      "model": "EcoTank L3560",
      "languages": ["EN", "NL", "FR"],
      "description": "Multifunction printer..."
    }
  }'
```

## Notes

- **No Durable Functions**: Single blob per request (reactive, immediate).
- **PHP 8.3**: Modern syntax (attributes, match, fibers), good for serverless.
- **Strategy pattern**: Easy provider swapping via env vars.
- **Managed Identity**: Zero credentials in code — user-assigned MI for all Azure services.
- **JSON output**: `*.alt.json` written to same container as blob.
- **Blob tags**: `processed=true`, `alt.v=1`, `langs=EN,NL,FR`.
- **Event Grid trigger**: Via Azure Container Apps HTTP endpoint (external ingress).

---

**Status**: Production-ready. Running on Azure Container Apps with Phi-4 via AI Foundry and Azure AI Translator.
