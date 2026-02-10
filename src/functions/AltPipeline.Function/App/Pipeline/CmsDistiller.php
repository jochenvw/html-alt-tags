<?php

namespace AltPipeline\Pipeline;

use Psr\Log\LoggerInterface;

/**
 * CmsDistiller - Extract Product Facts from Unstructured CMS Text
 *
 * Parses YAML description field and extracts salient product facts.
 * Strips promotional language, claims, warranty terms, etc.
 */
class CmsDistiller {
    private LoggerInterface $logger;

    private const IGNORE_PATTERNS = [
        '/warranty|guarantee|limited warranty/i',
        '/free|complimentary|included at no extra cost/i',
        '/best|revolutionary|innovative|cutting-edge/i',
        '/certified|patented|proprietary/i',
        '/savings|discount|reduced price/i',
    ];

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    /**
     * Extract salient facts from product description
     */
    public function extract(string $description): array {
        $facts = [];

        // Parse description for key facts
        $lines = array_filter(array_map('trim', explode("\n", $description)));

        foreach ($lines as $line) {
            // Skip marketing language
            if ($this->isMarketingClaim($line)) {
                continue;
            }

            // Extract specs (e.g., "Print: 15 ppm", "Scan: 1200 dpi")
            if (preg_match('/^([A-Za-z\s]+):\s*(.+)$/', $line, $matches)) {
                $key = strtolower(str_replace(' ', '_', trim($matches[1])));
                $value = trim($matches[2]);
                if (strlen($value) < 100) { // Skip suspiciously long values
                    $facts[$key] = $value;
                }
            }
        }

        $this->logger->debug("CmsDistiller extracted facts", ['count' => count($facts)]);

        return $facts;
    }

    /**
     * Check if line is promotional/marketing language
     */
    private function isMarketingClaim(string $line): bool {
        foreach (self::IGNORE_PATTERNS as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }
        return false;
    }
}
