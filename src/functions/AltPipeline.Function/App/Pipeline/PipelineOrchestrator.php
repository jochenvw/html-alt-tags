<?php

namespace AltPipeline\Pipeline;

use AltPipeline\Bootstrap;
use AltPipeline\Pipeline\{CmsDistiller, VisionHints, AltWriter};

/**
 * PipelineOrchestrator - Main Pipeline Orchestration
 *
 * Coordinates the entire flow:
 * 1. Load blob metadata (YAML sidecar)
 * 2. Extract product facts (CmsDistiller)
 * 3. Derive vision hints
 * 4. Generate alt text (Describer strategy)
 * 5. Translate (Translator strategy)
 * 6. Persist (AltWriter)
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
            $tokenUsage = $describerResult['tokenUsage'] ?? null;

            // Step 5: Translate
            $normalizedLanguages = array_map('strtolower', array_map(fn($l) => substr($l, 0, 2), $languages));
            $translations = $translator->translate($altText, $normalizedLanguages, $metadata);

            $altTexts = ['en' => $altText] + $translations;

            // Step 6: Build Final Alt JSON
            $altJson = [
                'asset' => $metadata['asset'] ?? 'unknown',
                'image' => $blobName,
                'source' => $metadata['source'] ?? 'unknown',
                'altText' => $altTexts,
                'generatedAt' => date('c'),
            ];

            // Step 7: Persist
            $altWriter = new AltWriter($blobClient, $logger);
            $writeResult = $altWriter->write($blobName, $altJson);

            $logger->info("Pipeline complete", [
                'blob' => $blobName,
                'languages' => array_keys($altTexts),
                'tokenUsage' => $tokenUsage,
            ]);

            return [
                'altJson' => $altJson,
                'tags' => [
                    'processed' => 'true',
                    'alt.v' => '1',
                    'langs' => implode(',', $languages),
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
