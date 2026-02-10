# System Design — Alt-Text Pipeline

> **Audience:** Product owners, managers, and stakeholders with limited Azure or cloud experience.
> This document explains what each part of the system does and why it exists, followed by a bill of materials and cost estimate.

---

## What This System Does

This system **automatically generates alt text for product images** — the short descriptions that screen readers use to help visually impaired users understand what an image shows. When someone uploads a product image (for example, a printer photo for an e-commerce website), the system looks at the image using AI, writes a clear description, translates it into multiple languages, and saves the result. The entire process is hands-free: upload an image, and the pipeline does the rest.

---

## How It Works — The Big Picture

```
┌──────────┐     ┌────────────┐     ┌───────────────┐     ┌───────────┐     ┌─────────┐
│  Upload   │────▶│  Detect    │────▶│  Describe     │────▶│ Translate │────▶│  Save   │
│  Image    │     │  (Event    │     │  (AI Model)   │     │ (Azure    │     │         │
│           │     │   Grid)    │     │               │     │  Translator)│     │         │
└──────────┘     └────────────┘     └───────────────┘     └───────────┘     └─────────┘
  Storage           Trigger           Azure AI              Azure AI          Storage
                                      Foundry               Translator
```

1. An image and its product metadata are uploaded to cloud storage.
2. Azure Event Grid detects the new file and notifies the processing application.
3. The application sends the image to an AI model (Phi-4) that writes a description in English.
4. The description is translated into the required languages (e.g., Dutch, French, Japanese).
5. The final result is saved alongside the original image, and processed images are copied to a "ready to publish" area.

---

## Components

### Azure Blob Storage

Azure Blob Storage is the system's file cabinet — it is where all images and their metadata live. We chose Blob Storage because it is designed specifically for storing large files like images at very low cost, and it integrates natively with the rest of the Azure platform. The system uses three separate areas (called "containers"): **ingest** for incoming images waiting to be processed, **public** for approved images ready for the website, and **deadletter** for any events that failed to process. Storage is configured with geo-redundancy (Standard_GRS), meaning files are automatically copied to a second Azure region for disaster recovery.

### Azure Event Grid

Event Grid is the system's notification service — it watches the storage account and fires an alert the instant a new image lands in the ingest container. Without Event Grid we would need to continuously poll storage for new files, which wastes resources and adds delay. Event Grid delivers the notification to our processing application within seconds, making the pipeline near real-time. It includes built-in retry logic (up to 5 attempts over 60 minutes) and routes failed events to a dead-letter container so nothing is silently lost.

### Azure Container Apps

Azure Container Apps is where the actual processing code runs. Think of it as a lightweight server that Azure manages for us — we do not need to worry about operating systems, patches, or load balancers. The application is written in PHP 8.3, packaged into a container image, and deployed to Container Apps. It automatically scales from 1 to 5 instances based on how many images are being processed at the same time, and scales back down during quiet periods to save cost. The application exposes an HTTPS endpoint that Event Grid calls whenever a new image arrives.

### Azure AI Foundry — Phi-4-multimodal-instruct

Azure AI Foundry is the AI model hosting platform, and **Phi-4-multimodal-instruct** is the specific model we use to generate image descriptions. Phi-4 is a "small language model" (SLM) developed by Microsoft that can understand both images and text in a single request — we send it the product photo and some product context, and it returns a concise alt-text description. We chose Phi-4 over larger models like GPT-4 because it is significantly cheaper per request while still producing high-quality, structured descriptions for this specific task. For a high-volume pipeline processing thousands of images, the cost difference is substantial. The model is deployed in the Sweden Central region to support European data residency requirements.

### Azure AI Translator

Azure AI Translator is a dedicated translation service that converts the English alt text into other languages — currently Dutch (NL), French (FR), and Japanese (JP). We use a purpose-built translation service rather than asking the AI model to translate because specialised translation APIs are faster, cheaper, and more consistent for straightforward text translation. The Translator service supports over 100 languages, so adding new markets in the future requires only a configuration change.

### Azure Computer Vision

Azure Computer Vision is a fallback image analysis service. If the primary AI model (Phi-4) is unavailable or returns a low-confidence result, the system can fall back to Computer Vision for basic image captioning and tag extraction. This provides resilience — the pipeline can still produce a reasonable description even if the primary AI model has an outage. In normal operation, this service is rarely used.

### Azure Container Registry (ACR)

Azure Container Registry is a private repository that stores the packaged application code (the container image). When Container Apps starts or scales up, it pulls the latest application image from ACR. This is similar to how an app store hosts apps — ACR hosts our application so Azure can deploy it on demand. We use the Basic tier, which is sufficient for a single application with infrequent updates.

### Azure Log Analytics

Log Analytics is the system's centralised logging and monitoring hub. Every component writes its logs here — application errors, processing times, AI model responses, and Event Grid delivery attempts. Logs are retained for 30 days, giving the team a window to investigate issues. This is essential for understanding pipeline health at a glance and debugging any failed image descriptions.

### User-Assigned Managed Identity

Managed Identity is how all the components authenticate with each other without any passwords or API keys stored in the code. Azure issues each service an identity (like an employee badge), and we grant that identity specific permissions to access storage, AI models, and the container registry. This eliminates the risk of leaked credentials and removes the need for manual secret rotation. It is a security best practice recommended by Microsoft for all Azure workloads.

### Quality Rules via System Prompts

Alt-text quality is enforced through the AI model's system prompt rather than through a separate validation step. The system prompt includes specific guidelines that the model follows when generating descriptions: sentences must start with a capital letter and end with a full stop, "image of" and "picture of" phrases are forbidden, marketing hype is not allowed, and the description should be 80–160 characters. Post-processing (the `normalizePunctuation` method) ensures these rules are consistently applied even if the model's output varies slightly. This approach is simpler and more reliable than a separate validation layer, because the model generates compliant text from the start rather than generating text that then needs to be checked and potentially rejected.

---

## Architecture Diagram

```
                          ┌──────────────────────────────┐
                          │     External Source System    │
                          │  (CMS, DAM, CI/CD Pipeline)  │
                          └──────────────┬───────────────┘
                                         │ Upload image + YAML metadata
                                         ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                        AZURE BLOB STORAGE (Standard_GRS)               │
│                                                                         │
│  ┌─────────────┐       ┌──────────────┐       ┌────────────────┐       │
│  │   ingest/   │       │   public/    │       │  deadletter/   │       │
│  │ (incoming)  │       │ (approved)   │       │ (failed events)│       │
│  └──────┬──────┘       └──────────────┘       └────────────────┘       │
└─────────┼──────────────────────────────────────────────────────────────┘
          │ BlobCreated event
          ▼
┌──────────────────────┐
│  AZURE EVENT GRID    │
│  (5 retries, 60 min) │
└──────────┬───────────┘
           │ HTTPS webhook
           ▼
┌──────────────────────────────────────────────────────────────────┐
│              AZURE CONTAINER APPS  (1–5 replicas)               │
│                                                                  │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌────────────────┐  │
│  │ CMS      │  │ Vision   │  │ AI       │  │ Translate      │  │
│  │ Distiller│─▶│ Hints    │─▶│ Describe │─▶│ & Save         │  │
│  └──────────┘  └──────────┘  └────┬─────┘  └───────┬────────┘  │
│                                   │                 │           │
│                                   ▼                 ▼           │
│                          ┌──────────────┐  ┌──────────────┐    │
│                          │ AI Foundry   │  │ AI Translator│    │
│                          │ (Phi-4)      │  │ (EN→NL,FR,JP)│    │
│                          └──────────────┘  └──────────────┘    │
└──────────────────────────────────────────────────────────────────┘
           │                         │
           ▼                         ▼
┌──────────────────┐     ┌──────────────────────┐
│  AZURE AI        │     │  AZURE AI TRANSLATOR │
│  FOUNDRY         │     │  (EN → NL, FR, JP)   │
│  Phi-4-multimodal│     └──────────────────────┘
└──────────────────┘

┌────────────────────────────────────────────┐
│         SUPPORTING SERVICES                │
│                                            │
│  🔐 Managed Identity (keyless auth)       │
│  📦 Container Registry (app images)       │
│  📊 Log Analytics (centralised logging)   │
│  👁️ Computer Vision (fallback describer)  │
└────────────────────────────────────────────┘
```

---

## Bill of Materials

| #  | Component                  | Azure Service                     | SKU / Tier      | Quantity | Purpose                                       |
|----|----------------------------|-----------------------------------|-----------------|----------|------------------------------------------------|
| 1  | Image & metadata storage   | Azure Blob Storage                | Standard_GRS    | 1        | Store images, metadata, and results            |
| 2  | Event notification         | Azure Event Grid                  | Included*       | 1        | Trigger processing on new image upload         |
| 3  | Application hosting        | Azure Container Apps              | Consumption     | 1–5      | Run the PHP processing pipeline                |
| 4  | AI image description       | Azure AI Foundry (Phi-4)          | Pay-per-token   | 1        | Generate alt text from images                  |
| 5  | Text translation           | Azure AI Translator               | S1              | 1        | Translate alt text to multiple languages        |
| 6  | Fallback image analysis    | Azure Computer Vision             | S1              | 1        | Backup image captioning service                |
| 7  | Application image store    | Azure Container Registry          | Basic           | 1        | Host the container application image           |
| 8  | Logging & monitoring       | Azure Log Analytics               | PerGB2018       | 1        | Centralised logs with 30-day retention         |
| 9  | Service authentication     | User-Assigned Managed Identity    | Free            | 1        | Keyless authentication between all services    |
| 10 | Resource group             | Azure Resource Group              | Free            | 1        | Logical grouping of all resources              |

\* Event Grid charges are consumption-based (per operation) with no fixed SKU cost.

---

## Cost breakdown (upper bound, conservative)

This is a planning estimate, not an Azure quote. Use the [Azure Pricing Calculator](https://azure.microsoft.com/en-us/pricing/calculator/) to validate before purchasing.

**Assumptions:**
- **Captioning model:** Phi-4-multimodal-instruct
- **Token upper bound per image:** 4,000 total (≈90% input / 10% output); measured average is 3,243, we round up for safety
- **Model unit prices:** $0.00008 per 1K input tokens; $0.00032 per 1K output tokens
- **Translation:** Azure AI Translator S1 at $10.00 per 1M characters
- **Translation length:** 200 characters per language per image
- **Languages:** 21 per image
- **ACA hot replica (consumption, 1 vCPU / 1 GiB):** $15.77/month
- **Event Grid + Blob Storage:** $0.02 per 1K images (conservative upper bound for ops + small JSON payloads)

---

### Per 1,000 images

- **Translator:** 200 chars/image × 21 languages = 4,200 chars/image → 4.2M chars per 1K → **$42.00**
- **Phi-4 captioning (4K tokens/image):**
  - Input: 3,600K tokens × $0.00008 = **$0.29**
  - Output: 400K tokens × $0.00032 = **$0.13**
  - Subtotal: **$0.42**
- **Event Grid + Storage:** **$0.02**
- **ACA (two scenarios):**
  - **(A) High volume (≥11K images/month):** $15.77 ÷ 11 = **$1.43** per 1K
  - **(B) Low volume (~1K images/month):** **$15.77** per 1K

**Totals per 1,000 images:**
- **(A) High-volume scenario:** $42.00 + $0.42 + $1.43 + $0.02 = **$43.87** → **$43.87**
- **(B) Low-volume scenario:** $42.00 + $0.42 + $15.77 + $0.02 = **$58.21** → **$58.21**

---

### Per 11,000 images (monthly)

- **Translator:** 4.2M chars × 11 = 46.2M chars → **$462.00**
- **Phi-4 captioning:** $0.42 × 11 = **$4.62**
- **ACA hot replica:** **$15.77** (fixed monthly cost)
- **Event Grid + Storage:** $0.02 × 11 = **$0.22**

**Total (11,000 images in one month):** $462.00 + $4.62 + $15.77 + $0.22 = **$482.61** → **$482.61**

---

### Notes & disclaimers

- **Translation dominates cost:** It accounts for ~95% of variable expenses. Reducing languages or average character length has the highest cost impact.
- **ACA cost varies by volume:** If you process fewer than 11K images/month, the fixed $15.77 replica cost dominates, making per-image cost much higher. Consider **scaling to 0 replicas at rest** for infrequent workloads (pay only vCPU/s during active processing).
- **Event Grid is metered by 64 KB ops:** Retries and fan-out (e.g., copying approved images to public) increase operation count. Our estimate assumes one event per image.
- **Phi-4 model inference is negligible** (~$0.42 per 1K images) compared to translation. Switching to a larger model (GPT-4, GPT-4o) would increase this 8–15 times.
- **Validate with your Azure account team:** Enterprise agreements, reservations, and regional pricing variations may reduce actual costs. Test with real workloads before committing.

---

Machine-readable cost constants (for automation):

```
PHI4_INPUT_PRICE_PER_1M_TOKENS = 0.000080  # USD
PHI4_OUTPUT_PRICE_PER_1M_TOKENS = 0.000320  # USD
PHI4_TOKENS_PER_IMAGE = 4000
PHI4_INPUT_PCT = 0.90
PHI4_OUTPUT_PCT = 0.10

TRANSLATOR_PRICE_PER_1M_CHARS = 10.00  # USD
TRANSLATOR_CHARS_PER_LANGUAGE = 200
TRANSLATOR_LANGUAGES = 21

ACA_PRICE_PER_MONTH = 15.77  # USD, hot replica (1 vCPU / 1 GiB)
ACA_AMORTIZE_AT_IMAGES_PER_MONTH = 11000

EVENT_GRID_STORAGE_PRICE_PER_1K_IMAGES = 0.02  # USD
```


---

## Region

All resources are deployed to **Sweden Central** (`swedencentral`). This region was chosen for:
- EU data residency compliance
- Availability of Azure AI Foundry and Phi-4 model deployments
- Low latency for European end users
