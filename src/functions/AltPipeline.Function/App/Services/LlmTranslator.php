<?php

namespace AltPipeline\Services;

use AltPipeline\Contracts\TextTranslator;
use AltPipeline\Auth\ManagedIdentityCredential;
use Psr\Log\LoggerInterface;

/**
 * LlmTranslator - Large Language Model Translation with Managed Identity
 *
 * Fallback translator using Azure OpenAI LLM for contextual translation.
 * Authenticates using Managed Identity for secure, keyless access.
 * More expensive but useful if Translator service has issues.
 */
class LlmTranslator implements TextTranslator {
    private string $endpoint;
    private ?ManagedIdentityCredential $credential;
    private string $deploymentName;
    private LoggerInterface $logger;

    public function __construct(
        string $endpoint,
        ?ManagedIdentityCredential $credential,
        string $deploymentName,
        LoggerInterface $logger
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->credential = $credential;
        $this->deploymentName = $deploymentName;
        $this->logger = $logger;
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

                $translated = $this->translateWithLLM($text, $langCode);
                $result[$langCode] = $translated;
            }

            return $result;

        } catch (\Exception $e) {
            $this->logger->error("LlmTranslator error: " . $e->getMessage());
            return [];
        }
    }

    private function translateWithLLM(string $text, string $langCode): string {
        $languageNames = [
            'nl' => 'Dutch',
            'fr' => 'French',
            'de' => 'German',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ja' => 'Japanese',
            'zh' => 'Simplified Chinese',
        ];

        $langName = $languageNames[$langCode] ?? ucfirst($langCode);

        $prompt = "Translate this product alt text to $langName, keeping it concise (max 125 chars) and suitable for e-commerce:\n\n\"$text\"\n\nRespond ONLY with the translated text, no quotes.";

        $url = "{$this->endpoint}/openai/deployments/{$this->deploymentName}/chat/completions?api-version=2024-05-01-preview";

        // Get Bearer token for Azure AI services via Managed Identity
        $token = $this->credential->getToken('https://cognitiveservices.azure.com/.default');
        if (!$token) {
            throw new \Exception("Failed to acquire token for Azure AI Foundry (LLM Translator)");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}",
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
                'max_tokens' => 150,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception("LLM Translator API error: HTTP $httpCode");
        }

        $data = json_decode($response, true);
        return trim($data['choices'][0]['message']['content'] ?? $text, '" ');
    }
}
