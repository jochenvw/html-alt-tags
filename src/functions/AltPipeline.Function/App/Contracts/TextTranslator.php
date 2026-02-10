<?php

namespace AltPipeline\Contracts;

/**
 * TextTranslator Contract
 *
 * Responsible for translating alt text to multiple languages.
 * Implementations: TranslatorService, LlmTranslator
 */
interface TextTranslator {
    /**
     * Translate text to target languages
     *
     * @param string $text Source text (English alt text)
     * @param array $targetLanguages ISO language codes: ["NL", "FR", "JP", ...]
     * @param ?array $context Optional context for better translation: { asset, make, model, ... }
     * @return array { nl: string, fr: string, ... } (only requested languages)
     */
    public function translate(
        string $text,
        array $targetLanguages,
        ?array $context = null
    ): array;
}
