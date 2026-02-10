<?php

namespace AltPipeline\Pipeline;

use Psr\Log\LoggerInterface;

/**
 * Guardrails - Validate alt text against system prompt rules
 *
 * Enforces:
 * - No forbidden phrases ("image of", "picture of", codes)
 * - Brand + Model present
 * - Length ≤125 chars (optimal: 80–160)
 * - Confidence ≥ threshold
 * - No promotional claims
 */
class Guardrails {
    private LoggerInterface $logger;
    private const FORBIDDEN_PHRASES = [
        'image of',
        'picture of',
        'photo of',
        'screenshot of',
        'illustration of',
        'diagram of',
    ];

    private const MARKETING_CLAIMS = [
        '/\bbest\b/i',
        '/\btop-rated\b/i',
        '/\baward[- ]winning\b/i',
        '/\bworld[- ]class\b/i',
        '/\bonly\s+\d+/i',
        '/\bfree\b/i',
        '/(limited|offer|sale|promotion)/i',
    ];

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    /**
     * Validate alt text and describer result
     */
    public function validate(
        string $altText,
        float $confidence,
        string $make,
        string $model
    ): array {
        $violations = [];
        $policyCompliant = true;

        // Check for forbidden phrases
        foreach (self::FORBIDDEN_PHRASES as $phrase) {
            if (stripos($altText, $phrase) !== false) {
                $violations[] = 'forbidden_phrase_' . str_replace(' ', '_', $phrase);
                $policyCompliant = false;
            }
        }

        // Check for marketing claims
        foreach (self::MARKETING_CLAIMS as $pattern) {
            if (preg_match($pattern, $altText)) {
                $violations[] = 'marketing_claim';
                $policyCompliant = false;
                break;
            }
        }

        // Check length
        if (strlen($altText) > 125) {
            $violations[] = 'length_exceeded';
            $policyCompliant = false;
        } elseif (strlen($altText) < 20) {
            $violations[] = 'too_short';
            $policyCompliant = false;
        }

        // Check brand + model presence
        if (!$this->containsBrandModel($altText, $make, $model)) {
            $violations[] = 'missing_brand_model';
            $policyCompliant = false;
        }

        // Check confidence
        if ($confidence < 0.7) {
            $violations[] = 'low_confidence';
            $policyCompliant = false;
        }

        if ($violations) {
            $this->logger->warning("Guardrails violations", [
                'violations' => $violations,
                'altText' => substr($altText, 0, 50),
            ]);
        }

        return [
            'policyCompliant' => $policyCompliant,
            'violations' => $violations,
        ];
    }

    /**
     * Check if alt text contains brand and/or model
     */
    private function containsBrandModel(string $altText, string $make, string $model): bool {
        $altLower = strtolower($altText);
        $makeLower = strtolower($make);
        $modelLower = strtolower($model);

        return (
            str_contains($altLower, $makeLower) ||
            ($model && str_contains($altLower, $modelLower))
        );
    }
}
