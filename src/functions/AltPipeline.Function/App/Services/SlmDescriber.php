<?php

namespace AltPipeline\Services;

use AltPipeline\Contracts\ImageDescriber;
use AltPipeline\Auth\ManagedIdentityCredential;
use AltPipeline\Pipeline\CmsDistiller;
use Psr\Log\LoggerInterface;

/**
 * SlmDescriber - Small Language Model with Managed Identity
 *
 * Uses Azure OpenAI SLM (small language model) to generate alt text.
 * Authenticates using Managed Identity for secure, keyless access.
 * Faster and cheaper than LLM for this specific task.
 */
class SlmDescriber implements ImageDescriber {
    private string $endpoint;
    private ?ManagedIdentityCredential $credential;
    private string $deploymentName;
    private LoggerInterface $logger;
    private string $promptsPath;

    public function __construct(
        string $endpoint,
        ?ManagedIdentityCredential $credential,
        string $deploymentName,
        LoggerInterface $logger,
        string $promptsPath = '/app/config/prompts'
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->credential = $credential;
        $this->deploymentName = $deploymentName;
        $this->logger = $logger;
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
                visionHints: $visionHints
            );

            // Call Azure OpenAI with image
            $response = $this->callOpenAI($systemPrompt, $userPrompt, $sasUrl);

            // Parse response - extract JSON robustly
            $result = $this->extractJson($response);

            $altEn = $result['alt_en'] ?? '';
            $this->logger->info("SlmDescriber result for {$blobName}", ['alt_en' => $altEn]);

            return ['alt_en' => $altEn];

        } catch (\Exception $e) {
            $this->logger->error("SlmDescriber error: " . $e->getMessage());
            return ['alt_en' => ''];
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

Respond with ONLY the alt text string. No JSON, no markdown, no explanation.
The text must start with a capital letter and end with a full stop.
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
8. Uses correct punctuation: starts with a capital letter, ends with a full stop (.)

Respond with ONLY the alt text string. No JSON, no markdown, no explanation.
PROMPT;
    }

    private function buildUserPrompt(
        string $blobName,
        string $make,
        string $model,
        array $facts,
        ?array $visionHints = null
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

    /**
     * Extract JSON from model response, handling markdown fences and prose
     */
    private function extractJson(string $response): array {
        $this->logger->debug("Extracting JSON from response", ['length' => strlen($response), 'preview' => substr($response, 0, 300)]);

        // Try direct JSON parse first
        $result = json_decode($response, true);
        if (is_array($result) && isset($result['alt_en'])) {
            $result['alt_en'] = $this->normalizePunctuation($result['alt_en']);
            return $result;
        }

        // Try extracting JSON from markdown code fences: ```json ... ``` or ``` ... ```
        if (preg_match('/```(?:json)?\s*\n?(.+?)\n?```/s', $response, $matches)) {
            $result = json_decode(trim($matches[1]), true);
            if (is_array($result) && isset($result['alt_en'])) {
                $this->logger->debug("Extracted JSON from code fence");
                $result['alt_en'] = $this->normalizePunctuation($result['alt_en']);
                return $result;
            }
        }

        // Try finding a JSON object anywhere in the text
        if (preg_match('/\{[^{}]*"alt_en"[^{}]*\}/s', $response, $matches)) {
            $result = json_decode($matches[0], true);
            if (is_array($result) && isset($result['alt_en'])) {
                $this->logger->debug("Extracted JSON object from text");
                $result['alt_en'] = $this->normalizePunctuation($result['alt_en']);
                return $result;
            }
        }

        // Try finding any JSON object
        if (preg_match('/\{.+\}/s', $response, $matches)) {
            $result = json_decode($matches[0], true);
            if (is_array($result)) {
                $this->logger->debug("Extracted generic JSON object from text");
                if (isset($result['alt_en'])) {
                    $result['alt_en'] = $this->normalizePunctuation($result['alt_en']);
                }
                return $result;
            }
        }

        // Fallback: use the raw text as the alt text
        $cleanText = trim($response);
        // Strip markdown formatting
        $cleanText = preg_replace('/^#+\s+/', '', $cleanText);
        $cleanText = preg_replace('/\*\*(.+?)\*\*/', '$1', $cleanText);
        // Take first meaningful line as alt text
        $lines = array_filter(explode("\n", $cleanText), fn($l) => strlen(trim($l)) > 10);
        $altText = trim(reset($lines) ?: $cleanText);
        // Truncate to reasonable alt text length
        if (strlen($altText) > 200) {
            $altText = substr($altText, 0, 197) . '...';
        }

        // Normalize punctuation
        $altText = $this->normalizePunctuation($altText);

        $this->logger->warning("Could not extract JSON from model response, using raw text as alt", [
            'raw_preview' => substr($response, 0, 200),
            'alt_text' => $altText
        ]);

        return ['alt_en' => $altText];
    }

    /**
     * Normalize punctuation: capitalize first letter, ensure trailing full stop.
     */
    private function normalizePunctuation(string $text): string {
        $text = trim($text);
        if (empty($text)) {
            return $text;
        }

        // Capitalize first letter (multibyte-safe)
        $text = mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);

        // Ensure trailing full stop (don't double-add if already ends with punctuation)
        $lastChar = mb_substr($text, -1);
        if (!in_array($lastChar, ['.', '!', '?'], true)) {
            $text .= '.';
        }

        return $text;
    }

    private function callOpenAI(string $systemPrompt, string $userPrompt, ?string $sasUrl = null): string {
        // Construct endpoint URL for Azure AI Foundry (OpenAI-compatible)
        $url = "{$this->endpoint}/openai/deployments/{$this->deploymentName}/chat/completions?api-version=2024-05-01-preview";

        // Build user content with image and text
        $userContent = [];
        
        // Add image if available
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
            'max_tokens' => 500,
            'top_p' => 0.95,
            'frequency_penalty' => 0,
            'presence_penalty' => 0,
        ]);

        // Get Bearer token for Azure AI services via Managed Identity
        $token = $this->credential->getToken('https://cognitiveservices.azure.com/.default');
        if (!$token) {
            throw new \Exception("Failed to acquire token for Azure AI Foundry");
        }

        $this->logger->debug("Calling Azure AI Foundry", ['url' => $url, 'deployment' => $this->deploymentName]);

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
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new \Exception("cURL error calling Foundry: $curlError");
        }

        if ($httpCode !== 200) {
            $this->logger->error("Foundry API error", ['http_code' => $httpCode, 'response' => substr($response, 0, 500)]);
            throw new \Exception("Foundry API error: HTTP $httpCode - " . substr($response, 0, 300));
        }

        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? '';

        $this->logger->debug("Foundry raw response", ['content' => substr($content, 0, 200)]);

        return $content;
    }
}
