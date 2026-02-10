<?php

namespace AltPipeline\Services;

use AltPipeline\Contracts\ImageDescriber;
use AltPipeline\Auth\ManagedIdentityCredential;
use AltPipeline\Pipeline\CmsDistiller;
use Psr\Log\LoggerInterface;

/**
 * Phi4Describer - Microsoft Phi-4-multimodal-instruct with Managed Identity
 *
 * Uses Phi-4-multimodal-instruct model for image description generation.
 * Authenticates using Managed Identity for secure, keyless access.
 * Optimized for both visual understanding and natural language generation.
 * Supports base64 image encoding for direct image analysis.
 */
class Phi4Describer implements ImageDescriber {
    private string $endpoint;
    private ?ManagedIdentityCredential $credential;
    private string $deploymentName;
    private string $region;
    private LoggerInterface $logger;
    private string $promptsPath;

    public function __construct(
        string $endpoint,
        ?ManagedIdentityCredential $credential,
        string $deploymentName = 'Phi-4-multimodal-instruct',
        string $region = 'swedencentral',
        LoggerInterface $logger = null,
        string $promptsPath = '/app/config/prompts'
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->credential = $credential;
        $this->deploymentName = $deploymentName;
        $this->region = $region;
        $this->logger = $logger ?? new \AltPipeline\Bootstrap\SimpleLogger();
        $this->promptsPath = rtrim($promptsPath, '/');
    }

    public function describe(
        string $blobName,
        ?string $sasUrl,
        array $sidecar,
        ?array $visionHints = null
    ): array {
        try {
            // Prepare context
            $make = $sidecar['make'] ?? 'Unknown';
            $model = $sidecar['model'] ?? '';
            $description = $sidecar['description'] ?? '';

            // Distill marketing copy
            $distiller = new CmsDistiller($this->logger);
            $facts = $distiller->extract($description);

            // Build prompt
            $source = $sidecar['source'] ?? 'default';
            $systemPrompt = $this->getSystemPrompt($source);
            $userPrompt = $this->buildUserPrompt(
                blobName: $blobName,
                make: $make,
                model: $model,
                facts: $facts,
                visionHints: $visionHints,
                sasUrl: $sasUrl
            );

            // Call Phi-4 via Foundry
            $response = $this->callPhi4($systemPrompt, $userPrompt, $sasUrl);

            // Parse response
            $result = json_decode($response, true);
            if (!is_array($result)) {
                throw new \Exception("Invalid JSON response from Phi-4: $response");
            }

            $this->logger->info("Phi4Describer result for {$blobName}", $result);

            return [
                'alt_en' => $result['alt_en'] ?? '',
                'confidence' => $result['confidence'] ?? 0.5,
                'policy_compliant' => $result['policy_compliant'] ?? false,
                'tags' => $result['tags'] ?? [],
                'violations' => $result['violations'] ?? [],
            ];

        } catch (\Exception $e) {
            $this->logger->error("Phi4Describer error: " . $e->getMessage());
            return [
                'alt_en' => '',
                'confidence' => 0.0,
                'policy_compliant' => false,
                'tags' => [],
                'violations' => ['phi4_error'],
            ];
        }
    }

    /**
     * Get system prompt based on source
     * Loads from external file: {source}_system_prompt.md
     * Falls back to default if specific source prompt not found
     * Always appends shared response format for uniform parsing
     */
    private function getSystemPrompt(string $source): string {
        // Normalize source for filename (e.g., "public website" -> "public_website")
        $normalizedSource = str_replace([' ', '-'], '_', strtolower(trim($source)));
        $promptFile = $this->promptsPath . '/' . $normalizedSource . '_system_prompt.md';
        
        $basePrompt = '';
        
        // Try to load source-specific prompt
        if (file_exists($promptFile)) {
            $this->logger->info("Loading system prompt for source: {$source}", ['file' => $promptFile]);
            $prompt = file_get_contents($promptFile);
            if ($prompt !== false && !empty(trim($prompt))) {
                $basePrompt = trim($prompt);
            }
        }
        
        // Try default prompt if source-specific not found
        if (empty($basePrompt)) {
            $defaultPromptFile = $this->promptsPath . '/default_system_prompt.md';
            if (file_exists($defaultPromptFile)) {
                $this->logger->warning("Source-specific prompt not found for '{$source}', using default", [
                    'attempted' => $promptFile,
                    'fallback' => $defaultPromptFile
                ]);
                $prompt = file_get_contents($defaultPromptFile);
                if ($prompt !== false && !empty(trim($prompt))) {
                    $basePrompt = trim($prompt);
                }
            }
        }
        
        // Last resort: hardcoded fallback
        if (empty($basePrompt)) {
            $this->logger->error("No system prompt files found, using hardcoded fallback", [
                'source' => $source,
                'prompts_path' => $this->promptsPath
            ]);
            $basePrompt = $this->getFallbackSystemPrompt();
        }
        
        // Always append shared response format for uniform parsing
        $responseFormat = $this->getResponseFormat();
        
        return $basePrompt . "\n\n" . $responseFormat;
    }
    
    /**
     * Get shared response format instructions
     * Ensures all responses are parseable regardless of source prompt
     */
    private function getResponseFormat(): string {
        $formatFile = $this->promptsPath . '/_response_format.md';
        
        if (file_exists($formatFile)) {
            $format = file_get_contents($formatFile);
            if ($format !== false && !empty(trim($format))) {
                return trim($format);
            }
        }
        
        // Hardcoded fallback response format
        $this->logger->warning("Response format file not found, using hardcoded format", [
            'expected' => $formatFile
        ]);
        
        return <<<'FORMAT'
## Response Format

You MUST respond with valid JSON in this exact structure:

```json
{
  "alt_en": "string (the generated alt text in English)",
  "confidence": 0.0–1.0 (numeric confidence score),
  "policy_compliant": true|false (boolean compliance indicator),
  "tags": ["array", "of", "strings"],
  "violations": ["array", "of", "violation", "codes"]
}
```

Return ONLY valid JSON. All five fields are required.
FORMAT;
    }
    
    /**
     * Hardcoded fallback system prompt (used only if files are missing)
     */
    private function getFallbackSystemPrompt(): string {
        return <<<'PROMPT'
You are an expert at writing short, accessible HTML alt text for product marketing images.

Your task is to generate a single, concise alt text that:
1. Describes what is VISIBLY present in the image (visual-first rule)
2. ALWAYS includes the brand and model name (e.g., "Epson EcoTank L3560")
3. Highlights ONE or TWO key, customer-relevant visible features
4. Is 80–160 characters (aim for this range; hard limit: 125 chars)
5. NEVER includes the phrases: "image of", "picture of", internal codes, marketing hype, or unverifiable claims
6. Does NOT add details not visible in the image or provided metadata
7. Is precise, concrete, and accessible to all users (including screen reader users)

Format your response as a JSON object with these fields:
{
  "alt_en": "...",
  "confidence": 0.0–1.0,
  "policy_compliant": true|false,
  "tags": [...],
  "violations": [...]
}

Keep the response valid JSON. Use only the fields above.
PROMPT;
    }

    private function buildUserPrompt(
        string $blobName,
        string $make,
        string $model,
        array $facts,
        ?array $visionHints = null,
        ?string $sasUrl = null
    ): string {
        $prompt = "Image filename: $blobName\n\n";
        $prompt .= "**Product Metadata (from YAML context):**\n";
        $prompt .= "- Make: $make\n";
        $prompt .= "- Model: $model\n";

        if (!empty($facts)) {
            $prompt .= "\n**Product Facts (from YAML context):**\n";
            foreach ($facts as $key => $value) {
                $prompt .= "- $key: $value\n";
            }
        }

        if ($visionHints) {
            $prompt .= "\n**Visual Hints (derived from filename and context):**\n";
            $prompt .= "- Angle/View: " . ($visionHints['angle'] ?? 'unknown') . "\n";
            if (!empty($visionHints['objects'])) {
                $prompt .= "- Objects visible: " . implode(', ', $visionHints['objects']) . "\n";
            }
        }

        $prompt .= "\n**Task:**\n";
        $prompt .= "Analyze the provided image along with the context above. ";
        $prompt .= "Generate a concise, policy-compliant alt text (80–160 chars) that includes the brand and model.";

        return $prompt;
    }

    private function callPhi4(string $systemPrompt, string $userPrompt, ?string $sasUrl = null): string {
        // Construct full endpoint URL with API version
        $url = $this->endpoint;
        if (!str_contains($url, 'api-version')) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . 'api-version=2024-05-01-preview';
        }

        // Build message content
        $userContent = [];
        
        // Add image URL if available
        if ($sasUrl) {
            $userContent[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $sasUrl,
                ],
            ];
        }

        // Add text prompt
        $userContent[] = [
            'type' => 'text',
            'text' => $userPrompt,
        ];

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent],
        ];

        $payload = json_encode([
            'messages' => $messages,
            'temperature' => 0.3,
            'max_tokens' => 300,
            'top_p' => 0.95,
            'frequency_penalty' => 0,
            'presence_penalty' => 0,
        ]);

        // Get Bearer token for Azure AI services via Managed Identity
        $token = $this->credential->getToken('https://cognitiveservices.azure.com/.default');
        if (!$token) {
            throw new \Exception("Failed to acquire token for Azure AI Foundry (Phi-4)");
        }

        $this->logger->debug("Calling Phi-4 via Foundry", ['url' => $url]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}",
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception("Phi-4 API error: HTTP $httpCode - $response");
        }

        $data = json_decode($response, true);
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \Exception("No content in Phi-4 response");
        }

        return $data['choices'][0]['message']['content'];
    }
}
