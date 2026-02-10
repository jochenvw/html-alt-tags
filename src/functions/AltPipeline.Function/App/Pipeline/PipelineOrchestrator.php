<?php

namespace AltPipeline\Pipeline;

use AltPipeline\Bootstrap;
use AltPipeline\Pipeline\{CmsDistiller, VisionHints, Guardrails, AltWriter};

/**
 * PipelineOrchestrator - Main Pipeline Orchestration
 *
 * Coordinates the entire flow:
 * 1. Load blob metadata (YAML sidecar)
 * 2. Extract product facts (CmsDistiller)
 * 3. Derive vision hints
 * 4. Generate alt text (Describer strategy)
 * 5. Validate (Guardrails)
 * 6. Translate (Translator strategy)
 * 7. Persist (AltWriter)
 */
class PipelineOrchestrator {
    private array $app;

    public function __construct(array $app) {
        $this->app = $app;
    }

    /**
     * Process a single blob
     */
    public function processBlob(
        string $blobName,
        array $sidecar = [],
        ?string $cmsText = null
    ): array {
        $logger = $this->app['logger'];
        $blobClient = $this->app['blobClient'];
        $describer = $this->app['describer'];
        $translator = $this->app['translator'];

        $logger->info("Pipeline start", ['blob' => $blobName]);

        try {
            // Step 1: Load Complete Metadata
            $metadata = $sidecar;
            if (empty($sidecar)) {
                $loaded = $blobClient->readYamlMetadata('ingest', $blobName);
                if ($loaded) {
                    $metadata = $loaded;
                }
            }

            $make = $metadata['make'] ?? 'Unknown';
            $model = $metadata['model'] ?? '';
            $languages = $metadata['languages'] ?? ['en'];

            // Step 2: Extract Product Facts
            $distiller = new CmsDistiller($logger);
            $productDescription = $cmsText ?? $metadata['description'] ?? '';
            $facts = $distiller->extract($productDescription);

            // Step 3: Derive Vision Hints
            $visionHints = VisionHints::derive($blobName, [], $metadata);

            // Step 4: Generate Alt Text
            $sasUrl = $blobClient->getBlobUrl('ingest', $blobName, 'PT1H');
            $describerResult = $describer->describe(
                blobName: $blobName,
                sasUrl: $sasUrl,
                sidecar: $metadata,
                visionHints: $visionHints
            );

            $altText = $describerResult['alt_en'] ?? '';
            $confidence = $describerResult['confidence'] ?? 0.0;

            // Step 5: Validate Against Guardrails
            $guardrails = new Guardrails($logger);
            $validation = $guardrails->validate($altText, $confidence, $make, $model);

            // Step 6: Translate
            $normalizedLanguages = array_map('strtolower', array_map(fn($l) => substr($l, 0, 2), $languages));
            $translations = $translator->translate($altText, $normalizedLanguages, $metadata);

            $altTexts = ['en' => $altText] + $translations;

            // Step 7: Build Final Alt JSON
            $altJson = [
                'asset' => $metadata['asset'] ?? 'unknown',
                'image' => $blobName,
                'source' => $metadata['source'] ?? 'unknown',
                'altText' => $altTexts,
                'confidence' => $confidence,
                'policyCompliant' => $validation['policyCompliant'],
                'violations' => $validation['violations'],
                'generatedAt' => date('c'),
            ];

            // Step 8: Persist
            $altWriter = new AltWriter($blobClient, $logger);
            $approved = $validation['policyCompliant'] && $confidence >= 0.7;
            $writeResult = $altWriter->write($blobName, $altJson, $approved);

            $logger->info("Pipeline complete", [
                'blob' => $blobName,
                'approved' => $approved,
                'violations' => count($validation['violations']),
            ]);

            return [
                'altJson' => $altJson,
                'tags' => [
                    'processed' => 'true',
                    'alt.v' => '1',
                    'langs' => implode(',', $languages),
                    'policy_compliant' => $approved ? 'true' : 'false',
                ],
                'writeResult' => $writeResult,
            ];

        } catch (\Exception $e) {
            $logger->error("Pipeline error: " . $e->getMessage(), [
                'blob' => $blobName,
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
