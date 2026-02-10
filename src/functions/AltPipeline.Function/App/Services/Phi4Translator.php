<?php

namespace AltPipeline\Services;

use AltPipeline\Contracts\TextTranslator;
use AltPipeline\Auth\ManagedIdentityCredential;
use Psr\Log\LoggerInterface;

/**
 * Phi4Translator - Microsoft Phi-4-multimodal-instruct with Managed Identity
 *
 * Uses Phi-4 model for contextual translation of product alt text.
 * Authenticates using Managed Identity for secure, keyless access.
 * Treats translation task as a language understanding problem for consistency with base model.
 */
class Phi4Translator implements TextTranslator {
    private string $endpoint;
    private ?ManagedIdentityCredential $credential;
    private string $deploymentName;
    private string $region;
    private LoggerInterface $logger;

    public function __construct(
        string $endpoint,
        ?ManagedIdentityCredential $credential,
        string $deploymentName = 'Phi-4-multimodal-instruct',
        string $region = 'swedencentral',
        LoggerInterface $logger = null
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->credential = $credential;
        $this->deploymentName = $deploymentName;
        $this->region = $region;
        $this->logger = $logger ?? new \AltPipeline\Bootstrap\SimpleLogger();
    }

    public function translate(
        string $text,
        array $targetLanguages,
        ?array $context = null
    ): array {
        try {
            $result = [];

            foreach ($targetLanguages as $lang) {
                $langCode = strtolower(substr($lang, 0, 2));
                if ($langCode === 'en') {
                    $result[$langCode] = $text;
                    continue;
                }

                $translated = $this->translateWithPhi4($text, $langCode);
                $result[$langCode] = $translated;
            }

            return $result;

        } catch (\Exception $e) {
            $this->logger->error("Phi4Translator error: " . $e->getMessage());
            return [];
        }
    }

    private function translateWithPhi4(string $text, string $langCode): string {
        $languageNames = [
            'nl' => 'Dutch',
            'fr' => 'French',
            'de' => 'German',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ja' => 'Japanese',
            'zh' => 'Simplified Chinese',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'no' => 'Norwegian',
        ];

        $langName = $languageNames[$langCode] ?? ucfirst($langCode);

        $systemPrompt = <<<'PROMPT'
You are an expert translator specializing in e-commerce product descriptions and alt text.

Your task is to translate product alt text accurately and concisely, maintaining:
1. Exact product name/model (do not translate brand or model numbers)
2. Conciseness (max 125 characters)
3. Clarity and accessibility for screen reader users
4. Suitability for e-commerce context

Always respond with ONLY the translated text, no additional explanation or quotes.
PROMPT;

        $userPrompt = "Translate this product alt text to $langName, keeping it concise (max 125 chars):\n\n\"$text\"";

        // Construct full endpoint URL with API version
        $url = $this->endpoint;
        if (!str_contains($url, 'api-version')) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . 'api-version=2024-05-01-preview';
        }

        $payload = json_encode([
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.3,
            'max_tokens' => 150,
        ]);

        // Get Bearer token for Azure AI services via Managed Identity
        $token = $this->credential->getToken('https://cognitiveservices.azure.com/.default');
        if (!$token) {
            throw new \Exception("Failed to acquire token for Azure AI Foundry (Phi-4 Translator)");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}",
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception("Phi-4 Translator API error: HTTP $httpCode - $response");
        }

        $data = json_decode($response, true);
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \Exception("No content in Phi-4 response");
        }

        return trim($data['choices'][0]['message']['content'], '" ');
    }
}
