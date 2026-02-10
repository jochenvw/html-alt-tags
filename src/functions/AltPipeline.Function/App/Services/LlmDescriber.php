<?php

namespace AltPipeline\Services;

use AltPipeline\Contracts\ImageDescriber;
use AltPipeline\Auth\ManagedIdentityCredential;
use Psr\Log\LoggerInterface;

/**
 * LlmDescriber - Large Language Model with Managed Identity
 *
 * Uses Azure OpenAI LLM (GPT-4) for richer, more contextual alt text.
 * Authenticates using Managed Identity for secure, keyless access.
 * More expensive than SLM but can provide better context matching.
 */
class LlmDescriber implements ImageDescriber {
    private string $endpoint;
    private ?ManagedIdentityCredential $credential;
    private string $deploymentName;
    private LoggerInterface $logger;
    private string $promptsPath;

    public function __construct(
        string $endpoint,
        ?ManagedIdentityCredential $credential,
        string $deploymentName,
        LoggerInterface $logger,
        string $promptsPath = '/app/config/prompts'
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->credential = $credential;
        $this->deploymentName = $deploymentName;
        $this->logger = $logger;
        $this->promptsPath = rtrim($promptsPath, '/');
    }

    public function describe(
        string $blobName,
        ?string $sasUrl,
        array $sidecar,
        ?array $visionHints = null
    ): array {
        // Delegate to SlmDescriber with LLM deployment
        $slm = new SlmDescriber(
            $this->endpoint,
            $this->credential,
            $this->deploymentName,
            $this->logger,
            $this->promptsPath
        );
        return $slm->describe($blobName, $sasUrl, $sidecar, $visionHints);
    }
}
