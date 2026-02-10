# AI Prompt Design — How Context Is Sent to Phi-4

> **Audience:** Developers and stakeholders who are new to working with large language models (LLMs).
> This document explains what information the system sends to the AI model, how that information is structured, and why each piece matters for the quality of the output.

---

## Background: How AI Models Like Phi-4 Work

Phi-4-multimodal-instruct is an AI model that can look at an image and read text at the same time. You communicate with it by sending a structured **message** made up of three parts:

1. **System prompt** — standing instructions that define the model's role, rules, and output format. Think of this as a job description that stays the same across many requests.
2. **User message** — the specific request for this image, including product context and the task to perform.
3. **Image** — the actual product photo, sent as a URL that the model can "see".

The model reads all three parts together and returns a single response. The quality of that response depends heavily on **what context you give it** — the more relevant facts you provide, the more accurate and useful the output will be.

---

## The Three Layers of Context

```
┌─────────────────────────────────────────────────────────┐
│                   SYSTEM PROMPT                          │
│  "You are an expert at writing alt text..."             │
│  Selected based on source field in YAML                 │
│  + shared response format (always appended)             │
├─────────────────────────────────────────────────────────┤
│                   USER MESSAGE                           │
│  Product metadata (brand, model)                        │
│  Product facts (extracted from YAML description)        │
│  Vision hints (camera angle derived from filename)      │
│  Task instruction                                       │
├─────────────────────────────────────────────────────────┤
│                   IMAGE                                  │
│  The actual product photo (sent as a URL)               │
│  Model "sees" and analyses the image directly           │
└─────────────────────────────────────────────────────────┘
```

---

## Layer 1: The YAML Sidecar — Where Context Begins

Every uploaded image has a companion YAML file (called a "sidecar") that travels alongside it. For example, when `img_0.png` is uploaded, a matching `img_0.yml` is uploaded too. This YAML file is the **single source of truth** for product context.

Here is a real example from [assets/img_0.yml](../assets/img_0.yml):

```yaml
asset: img_0.yml
source: public website                    # ← Controls which system prompt is loaded
languages:
  - EN
  - JP
  - NL
  - FR
description: |                            # ← Rich product description, used as context
  Epson EcoTank L3560 is a multifunction A4 inkjet printer designed for home
  and small office use. Manufactured by Epson, a global imaging and printing
  technology company...

  The device supports print, scan, and copy functions...
  Built-in Wi-Fi and Wi-Fi Direct connectivity...
  Maximum print resolution reaches up to 4800 x 1200 dpi...
```

Three fields from this YAML drive the prompt construction:

| YAML Field | What It Controls | Example |
|---|---|---|
| `source` | Which system prompt file is loaded (see Layer 2) | `public website` → loads the public website prompt |
| `description` | Product facts extracted and sent as context | Technical specs, features, measurements |
| `languages` | Which languages the final alt text is translated into (after AI generation) | `EN, JP, NL, FR` |

The description field is deliberately detailed — it contains the kind of content a product manager or marketer would write for a website listing. The system does **not** send this raw text to the model. Instead, it first passes through a filtering step.

### The CMS Distiller: Cleaning Up the Description

Before the description reaches the AI model, a component called the **CMS Distiller** ([App/Pipeline/CmsDistiller.php](../src/functions/AltPipeline.Function/App/Pipeline/CmsDistiller.php)) strips out marketing language and extracts only factual statements. Lines containing words like "best", "revolutionary", "free", "warranty", or "discount" are removed. What remains are concrete product facts — dimensions, speeds, technologies, and features.

This matters because the AI model should describe **what it sees**, not repeat marketing copy. By filtering the context before it reaches the model, we reduce the risk of promotional language leaking into the alt text.

---

## Layer 2: System Prompt — Different Rules for Different Contexts

The `source` field in the YAML determines which system prompt file is loaded. This is the key design decision: **the same pipeline can produce different styles of alt text depending on where the image will be used**.

### How Prompt Selection Works

The source value is converted to a filename:

```
source: "public website"  →  prompts/public_website_system_prompt.md
source: "internal docs"   →  prompts/internal_docs_system_prompt.md
source: (anything else)   →  prompts/default_system_prompt.md  (fallback)
```

This logic is in the `getSystemPrompt()` method of both [Phi4Describer.php](../src/functions/AltPipeline.Function/App/Services/Phi4Describer.php) and [SlmDescriber.php](../src/functions/AltPipeline.Function/App/Services/SlmDescriber.php).

### What the System Prompts Contain

Each system prompt defines **who the model is**, **what rules to follow**, and **what tone to use**. Here are the two prompts currently in the system:

**Public Website prompt** ([prompts/public_website_system_prompt.md](../prompts/public_website_system_prompt.md)) — optimised for customer-facing e-commerce pages:
- Tone: neutral, descriptive, customer-oriented
- Audience: end customers (home users and small offices)
- Goal: accessibility + SEO + product clarity
- Length: 80–160 characters
- Rules: describe what is visually present, emphasise brand + model, no marketing hype, no "image of" phrases
- Includes four worked examples so the model understands the expected style

**Default prompt** ([prompts/default_system_prompt.md](../prompts/default_system_prompt.md)) — a generic fallback for any image source:
- Same core rules but without source-specific tone guidance
- Used when no source-specific prompt file exists.

### The Shared Response Format

Regardless of which system prompt is loaded, **the same response format is always appended** at the end. This format lives in [prompts/_response_format.md](../prompts/_response_format.md) and tells the model exactly what JSON structure to return:

```json
{
  "alt_en": "Epson EcoTank L3560 A4 multifunction ink tank printer in black",
  "confidence": 0.92,
  "policy_compliant": true,
  "tags": ["printer", "ecotank", "multifunction"],
  "violations": []
}
```

This separation is intentional. The **system prompt** controls the writing style and rules (and can vary per source). The **response format** ensures the output is always machine-parseable (and never varies). This means you can add a new source type by creating a single markdown file — the output format stays compatible with the rest of the pipeline.

### Why This Matters

Without source-specific prompts, a single set of instructions would need to handle every possible use case — a public website, an internal knowledge base, a repair manual, a mobile app. Each of those contexts has different audiences, tone requirements, and length constraints. By selecting the prompt based on the YAML's `source` field, each image gets instructions tailored to its destination, while the pipeline code stays the same.

---

## Layer 3: The User Message — Image-Specific Context

The user message is built fresh for every image. It combines product metadata from the YAML with derived hints about the image. This logic is in the `buildUserPrompt()` method of [Phi4Describer.php](../src/functions/AltPipeline.Function/App/Services/Phi4Describer.php) and [SlmDescriber.php](../src/functions/AltPipeline.Function/App/Services/SlmDescriber.php).

Here is what a typical user message looks like:

```
Image filename: img_0.png

**Product Metadata (from YAML context):**
- Make: Epson
- Model: EcoTank L3560

**Product Facts (from YAML context):**
- multifunction_a4_inkjet: designed for home and small office use
- print_scan_copy: compact desktop form factor
- wifi_direct: wireless printing from laptops, smartphones, tablets
- ecotank_system: high-yield ink bottles with key-lock refill mechanism

**Visual Hints (derived from filename and context):**
- Angle/View: front

**Task:**
Analyze the provided image along with the context above.
Generate a concise, policy-compliant alt text (80–160 chars) that includes the brand and model.
```

### What Each Section Provides

| Section | Source | Purpose |
|---|---|---|
| **Filename** | The blob name in storage | Gives the model a hint about the image content (e.g., `front_view.png`) |
| **Product Metadata** | `make` and `model` fields from YAML | Ensures the brand and model appear in the alt text — critical for the guardrails check |
| **Product Facts** | Description field, filtered by CMS Distiller | Gives the model factual context it cannot see in the image (e.g., "supports Wi-Fi Direct") |
| **Vision Hints** | Derived from filename, tags, or YAML by [VisionHints.php](../src/functions/AltPipeline.Function/App/Pipeline/VisionHints.php) | Tells the model the camera angle (front, side, top, detail, action) so it can frame the description appropriately |
| **Task** | Hardcoded instruction | The explicit ask — what to produce and the constraints to respect |

### The Image Itself

The image is sent alongside the text as a **URL** that the model fetches and analyses visually. In the API call, the user message contains both:

```json
{
  "role": "user",
  "content": [
    { "type": "image_url", "image_url": { "url": "data:image/png;base64,..." } },
    { "type": "text", "text": "(the user message above)" }
  ]
}
```

Phi-4 is a **multimodal** model, which means it processes the image and the text together in a single request. It can see what is in the photo (a printer, an ink tank, an LCD screen) and combine that with the product facts you provided (brand name, specifications) to write a description that is both visually accurate and factually complete.

---

## The Complete Message Sent to Phi-4

Putting all three layers together, here is what the AI model receives for a single image:

```
┌─── SYSTEM MESSAGE ───────────────────────────────────────────────┐
│                                                                   │
│  You generate high-quality HTML alt text for images from a       │
│  public-facing marketing website.                                │
│                                                                   │
│  Context:                                                        │
│  - Source system: public website                                 │
│  - Audience: end customers                                       │
│  - Tone: neutral, descriptive, customer-oriented                 │
│  - Length: 80–160 characters                                     │
│  - No keyword stuffing                                           │
│                                                                   │
│  Guidelines:                                                     │
│  1. Describe what is visually present first.                     │
│  2. Emphasize product identity (brand + model).                  │
│  3. No "image of" or "picture of".                               │
│  4. No marketing hype.                                           │
│  ...                                                             │
│                                                                   │
│  ## Response Format                                              │
│  You MUST respond with valid JSON:                               │
│  { "alt_en": "...", "confidence": 0.0–1.0, ... }                │
│                                                                   │
└──────────────────────────────────────────────────────────────────┘

┌─── USER MESSAGE ─────────────────────────────────────────────────┐
│                                                                   │
│  [IMAGE: product photo as URL]                                   │
│                                                                   │
│  Image filename: img_0.png                                       │
│  Product Metadata: Make: Epson, Model: EcoTank L3560             │
│  Product Facts: multifunction, Wi-Fi Direct, ink tank system...  │
│  Visual Hints: Angle/View: front                                 │
│  Task: Generate concise, policy-compliant alt text (80–160 chars)│
│                                                                   │
└──────────────────────────────────────────────────────────────────┘
```

The model responds with structured JSON:

```json
{
  "alt_en": "Epson EcoTank L3560 A4 multifunction ink tank printer in black, front view with compact desktop design",
  "confidence": 0.89,
  "policy_compliant": true,
  "tags": ["printer", "ecotank", "multifunction", "ink tank"],
  "violations": []
}
```

---

## API Call Parameters

The model is called with these settings (configured in [Phi4Describer.php](../src/functions/AltPipeline.Function/App/Services/Phi4Describer.php) and [SlmDescriber.php](../src/functions/AltPipeline.Function/App/Services/SlmDescriber.php)):

| Parameter | Value | What It Means |
|---|---|---|
| `temperature` | 0.3 | Low randomness — the model produces consistent, predictable descriptions rather than creative or varied ones |
| `max_tokens` | 300 (Phi4) / 500 (Slm) | Maximum length of the response in tokens (~words); keeps the output concise |
| `top_p` | 0.95 | Considers the top 95% most likely words; slightly limits extreme word choices |
| `frequency_penalty` | 0 | No penalty for repeating words (brand and model names often repeat and that is fine) |
| `presence_penalty` | 0 | No penalty for mentioning previously-used topics |

The low temperature (0.3) is particularly important. For creative writing you might set this to 0.8 or higher, but for alt text we want **reliable, repeatable, factual descriptions** — the same image with the same context should produce a very similar description every time.

---

## Adding a New Source Type

To create alt text rules for a new context (for example, an internal repair manual), you only need to:

1. Create a new file: `prompts/repair_manual_system_prompt.md`
2. Write the guidelines for that context (tone, audience, length, rules)
3. Set `source: repair manual` in the YAML sidecar for those images

No code changes are needed. The prompt selection logic will automatically find the new file, append the shared response format, and use it for all images with that source value.

**Related source files:**
- Prompt selection logic: [Phi4Describer.php](../src/functions/AltPipeline.Function/App/Services/Phi4Describer.php) and [SlmDescriber.php](../src/functions/AltPipeline.Function/App/Services/SlmDescriber.php) — `getSystemPrompt()` method
- CMS Distiller (fact extraction): [App/Pipeline/CmsDistiller.php](../src/functions/AltPipeline.Function/App/Pipeline/CmsDistiller.php)
- Vision Hints (angle detection): [App/Pipeline/VisionHints.php](../src/functions/AltPipeline.Function/App/Pipeline/VisionHints.php)
- System prompts directory: [prompts/](../prompts/)
- Response format: [prompts/_response_format.md](../prompts/_response_format.md)
- Pipeline orchestration: [App/Pipeline/PipelineOrchestrator.php](../src/functions/AltPipeline.Function/App/Pipeline/PipelineOrchestrator.php)
