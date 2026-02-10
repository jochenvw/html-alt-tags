<?php

namespace AltPipeline\Services;

use AltPipeline\Contracts\TextTranslator;
use AltPipeline\Auth\ManagedIdentityCredential;
use Psr\Log\LoggerInterface;

/**
 * TranslatorService - Azure AI Translator with Managed Identity
 *
 * Translates English alt text to multiple languages using Azure Translator.
 * Authenticates using Managed Identity for secure, keyless access.
 * Fast, reliable, supports 100+ languages.
 */
class TranslatorService implements TextTranslator {
    private string $endpoint;
    private ?ManagedIdentityCredential $credential;
    private string $region;
    private LoggerInterface $logger;

    public function __construct(
        string $endpoint,
        ?ManagedIdentityCredential $credential,
        string $region,
        LoggerInterface $logger
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->credential = $credential;
        $this->region = $region;
        $this->logger = $logger;
    }

    public function translate(
        string $text,
        array $targetLanguages,
        ?array $context = null
    ): array {
        try {
            $result = [];

            // Map language codes to Azure Translator supported codes
            // YAML may use non-standard codes like "JP" -> should be "ja"
            $langMap = [
                'jp' => 'ja',  // Japanese
                'cn' => 'zh-Hans', // Chinese Simplified
                'tw' => 'zh-Hant', // Chinese Traditional
                'kr' => 'ko',  // Korean
                'br' => 'pt',  // Portuguese (Brazil)
                'cz' => 'cs',  // Czech
                'dk' => 'da',  // Danish
                'gr' => 'el',  // Greek
                'se' => 'sv',  // Swedish
                'no' => 'nb',  // Norwegian Bokmål
            ];

            foreach ($targetLanguages as $lang) {
                $langCode = strtolower(substr($lang, 0, 2));
                if ($langCode === 'en') {
                    $result[$langCode] = $text;
                    continue;
                }

                // Map non-standard codes to Azure Translator codes
                $translatorCode = $langMap[$langCode] ?? $langCode;
                
                try {
                    $translated = $this->translateSingle($text, $translatorCode);
                    // Store under original 2-letter key for consistency
                    $result[$langCode] = $translated;
                } catch (\Exception $e) {
                    $this->logger->error("Translation failed for language: {$langCode} ({$translatorCode})", [
                        'error' => $e->getMessage()
                    ]);
                    // Skip failed language but continue with others
                    $result[$langCode] = $text; // fallback to English
                }
            }

            $this->logger->info("TranslatorService completed", ['languages' => count($result)]);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error("TranslatorService error: " . $e->getMessage());
            return [];
        }
    }

    private function translateSingle(string $text, string $targetLanguage): string {
        // Detect whether we're using a custom subdomain (MI auth)
        // or the global endpoint (key auth)
        if (str_contains($this->endpoint, '.cognitiveservices.azure.com')) {
            // Custom subdomain: version is in the path, no api-version param
            $url = "{$this->endpoint}/translator/text/v3.0/translate?from=en&to={$targetLanguage}";
        } else {
            // Global endpoint: version in query string
            $url = "{$this->endpoint}/translate?api-version=3.0&from=en&to={$targetLanguage}";
        }

        // Get access token for Cognitive Services using Managed Identity
        $token = $this->credential 
            ? $this->credential->getToken('https://cognitiveservices.azure.com/.default')
            : null;

        if (!$token) {
            throw new \Exception("Failed to acquire token for Translator");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}",
                "Ocp-Apim-Subscription-Region: {$this->region}",
            ],
            CURLOPT_POSTFIELDS => json_encode([['text' => $text]]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->logger->error("Translator API error", [
                'http_code' => $httpCode,
                'url' => $url,
                'response' => substr($response, 0, 300),
            ]);
            throw new \Exception("Translator API error: HTTP $httpCode - " . substr($response, 0, 200));
        }

        $data = json_decode($response, true);
        return $data[0]['translations'][0]['text'] ?? $text;
    }
}
