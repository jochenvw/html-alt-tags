<?php

namespace AltPipeline\Pipeline;

/**
 * VisionHints - Derive angle/view and visible objects
 *
 * Heuristics to extract angle (front, top, side, detail, etc.) and
 * visible objects from image metadata or Vision API tags.
 */
class VisionHints {
    private const ANGLE_KEYWORDS = [
        'front' => ['front view', 'front-facing', 'face-on', 'straight on', 'frontal'],
        'angle' => ['angled', 'perspective', 'iso', '3/4 view', 'three-quarter'],
        'side' => ['side view', 'profile', 'left side', 'right side'],
        'top' => ['top view', 'overhead', 'above', 'bird\'s eye'],
        'detail' => ['close-up', 'close up', 'detail', 'macro', 'zoom'],
        'action' => ['in use', 'action shot', 'printing', 'scanning', 'operating'],
    ];

    /**
     * Derive hints from vision tags, filename, or metadata
     */
    public static function derive(
        string $blobName,
        ?array $visionTags = [],
        ?array $sidecar = []
    ): array {
        $hints = [
            'angle' => self::guessAngle($blobName, $visionTags, $sidecar),
            'objects' => $visionTags ?? [],
        ];

        return array_filter($hints);
    }

    /**
     * Guess image angle from filename, vision tags, or sidecar metadata
     */
    private static function guessAngle(string $blobName, array $visionTags, array $sidecar): ?string {
        // Check filename
        $blobLower = strtolower($blobName);
        foreach (self::ANGLE_KEYWORDS as $angle => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($blobLower, $keyword)) {
                    return $angle;
                }
            }
        }

        // Check vision tags
        foreach ($visionTags as $tag) {
            $tagLower = strtolower($tag);
            foreach (self::ANGLE_KEYWORDS as $angle => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($tagLower, $keyword)) {
                        return $angle;
                    }
                }
            }
        }

        // Check sidecar metadata
        if (isset($sidecar['angle'])) {
            return (string)$sidecar['angle'];
        }

        return null;
    }
}
