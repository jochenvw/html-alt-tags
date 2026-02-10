<?php

namespace AltPipeline\Services;

use AltPipeline\Contracts\ImageDescriber;
use AltPipeline\Auth\ManagedIdentityCredential;
use Psr\Log\LoggerInterface;

/**
 * VisionDescriber - Azure AI Vision with Managed Identity
 *
 * Uses Azure AI Vision API to generate captions and extract tags from images.
 * Authenticates using Managed Identity for secure, keyless access.
 */
class VisionDescriber implements ImageDescriber {
    private string $endpoint;
    private ?ManagedIdentityCredential $credential;
    private LoggerInterface $logger;

    public function __construct(
        string $endpoint,
        ?ManagedIdentityCredential $credential,
        LoggerInterface $logger
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->credential = $credential;
        $this->logger = $logger;
    }

    public function describe(
        string $blobName,
        ?string $sasUrl,
        array $sidecar,
        ?array $visionHints = null
    ): array {
        try {
            if (!$sasUrl) {
                throw new \Exception("VisionDescriber requires a valid SAS URL");
            }

            // Call Vision API for caption
            $caption = $this->getCaption($sasUrl);
            $tags = $this->getTags($sasUrl);

            // Merge with product metadata
            $make = $sidecar['make'] ?? 'Unknown';
            $model = $sidecar['model'] ?? '';

            // Build alt text
            $altText = $this->buildAltText($caption, $tags, $make, $model);

            // Basic validation
            $violations = [];
            if (strlen($altText) > 125) {
                $violations[] = 'length_exceeded';
            }
            if (!str_contains($altText, $make) && !str_contains($altText, $model)) {
                $violations[] = 'missing_brand_model';
            }

            return [
                'alt_en' => $altText,
                'confidence' => 0.75,
                'policy_compliant' => empty($violations),
                'tags' => $tags,
                'violations' => $violations,
            ];

        } catch (\Exception $e) {
            $this->logger->error("VisionDescriber error: " . $e->getMessage());
            return [
                'alt_en' => '',
                'confidence' => 0.0,
                'policy_compliant' => false,
                'tags' => [],
                'violations' => ['vision_error'],
            ];
        }
    }

    private function getCaption(string $sasUrl): string {
        $url = "{$this->endpoint}/vision/v3.2/analyze?visualFeatures=Description&language=en";

        // Get access token for Cognitive Services using Managed Identity
        $token = $this->credential 
            ? $this->credential->getToken('https://cognitiveservices.azure.com/.default')
            : null;

        if (!$token) {
            throw new \Exception("Failed to acquire token for Computer Vision");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}",
            ],
            CURLOPT_POSTFIELDS => json_encode(['url' => $sasUrl]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception("Vision API error: HTTP $httpCode");
        }

        $data = json_decode($response, true);
        return $data['description']['captions'][0]['text'] ?? '';
    }

    private function getTags(string $sasUrl): array {
        $url = "{$this->endpoint}/vision/v3.2/analyze?visualFeatures=Tags&language=en";

        // Get access token for Cognitive Services using Managed Identity
        $token = $this->credential 
            ? $this->credential->getToken('https://cognitiveservices.azure.com/.default')
            : null;

        if (!$token) {
            $this->logger->warning("Failed to acquire token for tags, skipping");
            return [];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$token}",
            ],
            CURLOPT_POSTFIELDS => json_encode(['url' => $sasUrl]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return [];
        }

        $data = json_decode($response, true);
        return array_map(fn($tag) => $tag['name'], $data['tags'] ?? []);
    }

    private function buildAltText(string $caption, array $tags, string $make, string $model): string {
        // Shorten caption and prepend brand/model
        $shortCaption = substr($caption, 0, 100);
        $altText = "{$make} {$model} {$shortCaption}";

        // Trim to 125 chars
        if (strlen($altText) > 125) {
            $altText = substr($altText, 0, 122) . '...';
        }

        return $altText;
    }
}
