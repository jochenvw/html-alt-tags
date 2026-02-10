<?php

namespace AltPipeline\Pipeline;

use AltPipeline\Storage\BlobClient;
use Psr\Log\LoggerInterface;

/**
 * AltWriter - Persist alt text and metadata
 *
 * Writes *.alt.json sidecar next to blob.
 * Sets blob tags: processed=true, alt.v=1, langs=...
 * Copies blob to /public/ container.
 */
class AltWriter {
    private BlobClient $blobClient;
    private LoggerInterface $logger;

    public function __construct(BlobClient $blobClient, LoggerInterface $logger) {
        $this->blobClient = $blobClient;
        $this->logger = $logger;
    }

    /**
     * Write alt text result to storage
     *
     * @param string $blobName Original blob filename (e.g., "img_0.png")
     * @param array $altJson Alt text result: { asset, image, altText: { en, nl, fr }, generatedAt }
     * @return array Write result: { sidecarName, blobs_written, tags_set, copied_to_public }
     */
    public function write(
        string $blobName,
        array $altJson
    ): array {
        $result = [
            'sidecarName' => '',
            'blobs_written' => [],
            'tags_set' => false,
            'copied_to_public' => false,
        ];

        try {
            // Write *.alt.json sidecar
            $sidecarName = $this->getSidecarName($blobName);
            $sidecarJson = json_encode($altJson, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

            $this->blobClient->uploadBlob(
                container: 'ingest',
                blobName: $sidecarName,
                content: $sidecarJson,
                contentType: 'application/json'
            );

            $result['sidecarName'] = $sidecarName;
            $result['blobs_written'][] = $sidecarName;

            // Set blob tags
            $languages = implode(',', array_keys($altJson['altText'] ?? ['en' => '']));
            $tags = [
                'processed' => 'true',
                'alt.v' => '1',
                'langs' => $languages,
            ];

            $this->blobClient->setTags('ingest', $blobName, $tags);
            $result['tags_set'] = true;

            // Copy to public/
            if (!$this->isJsonFile($blobName)) {
                $this->blobClient->copyBlob('ingest', $blobName, 'public', $blobName);
                $result['copied_to_public'] = true;
                $this->logger->info("Copied $blobName to /public/ container");
            }

            $this->logger->info("AltWriter completed", $result);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error("AltWriter error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get sidecar filename for blob (e.g., "img_0.png" → "img_0.alt.json")
     */
    private function getSidecarName(string $blobName): string {
        $pathInfo = pathinfo($blobName);
        return $pathInfo['dirname'] !== '.' 
            ? $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.alt.json'
            : $pathInfo['filename'] . '.alt.json';
    }

    /**
     * Check if blob is already a JSON file
     */
    private function isJsonFile(string $blobName): bool {
        return str_ends_with(strtolower($blobName), '.json');
    }
}
