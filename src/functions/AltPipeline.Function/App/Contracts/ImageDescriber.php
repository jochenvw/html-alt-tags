<?php

namespace AltPipeline\Contracts;

/**
 * ImageDescriber Contract
 *
 * Responsible for generating alt text descriptions from images.
 * Implementations: SlmDescriber, LlmDescriber, VisionDescriber
 */
interface ImageDescriber {
    /**
     * Generate alt text description
     *
     * @param string $blobName Blob filename (e.g., "img_0.png")
     * @param ?string $sasUrl SAS-signed blob URL (with read permission)
     * @param array $sidecar Metadata sidecar: { asset, make, model, description, languages, ... }
     * @param ?array $visionHints Optional vision analysis hints: { angle, objects, colors, ... }
     * @return array { alt_en: string, confidence: float, policy_compliant: bool, tags: array, violations: array }
     */
    public function describe(
        string $blobName,
        ?string $sasUrl,
        array $sidecar,
        ?array $visionHints = null
    ): array;
}
