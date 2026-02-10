<?php

namespace AltPipeline\Storage;

use AltPipeline\Auth\ManagedIdentityCredential;
use Psr\Log\LoggerInterface;

/**
 * BlobClient - Azure Blob Storage with Managed Identity
 *
 * Uses Managed Identity for secure, keyless access to Azure Blob Storage.
 * All operations use REST API with Bearer token authentication.
 */
class BlobClient {
    private string $accountName;
    private ?ManagedIdentityCredential $credential;
    private LoggerInterface $logger;

    public function __construct(
        string $accountName,
        ?string $accountKey,
        ?string $connectionString,
        LoggerInterface $logger,
        ?ManagedIdentityCredential $credential = null
    ) {
        $this->accountName = $accountName;
        $this->credential = $credential;
        $this->logger = $logger;
    }

    /**
     * Read blob content using Managed Identity
     */
    public function readBlob(string $container, string $blobName): ?string {
        try {
            if (!$this->credential) {
                $this->logger->error("Managed Identity credential not configured for readBlob");
                return null;
            }

            $this->logger->debug("Reading blob", ['container' => $container, 'blob' => $blobName]);

            $token = $this->credential->getToken('https://storage.azure.com');
            if (!$token) {
                $this->logger->error("Failed to acquire token for storage read");
                return null;
            }

            $url = "https://{$this->accountName}.blob.core.windows.net/{$container}/{$blobName}";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$token}",
                    "x-ms-version: 2021-08-06",
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                $this->logger->error("cURL error reading blob: $curlError");
                return null;
            }

            if ($httpCode === 404) {
                $this->logger->debug("Blob not found: {$container}/{$blobName}");
                return null;
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                $this->logger->error("Failed to read blob: HTTP {$httpCode}", ['response' => substr($response, 0, 200)]);
                return null;
            }

            $this->logger->debug("Blob read successfully", ['size' => strlen($response)]);
            return $response;

        } catch (\Exception $e) {
            $this->logger->error("Failed to read blob: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Upload blob content using Managed Identity
     */
    public function uploadBlob(string $container, string $blobName, string $content, string $contentType = 'application/octet-stream'): void {
        try {
            if (!$this->credential) {
                $msg = "Managed Identity credential not configured";
                $this->logger->error($msg);
                throw new \Exception($msg);
            }

            $this->logger->info("Uploading blob with Managed Identity", [
                'container' => $container,
                'blob' => $blobName,
                'size' => strlen($content),
                'contentType' => $contentType,
            ]);

            // Get Bearer token for storage
            $this->logger->debug("Acquiring token for https://storage.azure.com...");
            $token = $this->credential->getToken('https://storage.azure.com');
            
            if (!$token) {
                $msg = "Failed to acquire token for storage - token is empty";
                $this->logger->error($msg);
                throw new \Exception($msg);
            }

            $this->logger->debug("Token acquired successfully, token length: " . strlen($token));
            
            // Log token details for debugging (first 50 and last 10 chars)
            $tokenDebug = substr($token, 0, 50) . "..." . substr($token, -10);
            $this->logger->debug("Token preview: $tokenDebug");
            
            // Decode JWT to inspect claims
            try {
                $parts = explode('.', $token);
                if (count($parts) === 3) {
                    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
                    if ($payload) {
                        $this->logger->debug("Token claims", [
                            'oid' => $payload['oid'] ?? 'N/A',
                            'appid' => $payload['appid'] ?? 'N/A',
                            'aud' => $payload['aud'] ?? 'N/A',
                            'exp' => $payload['exp'] ?? 'N/A',
                        ]);
                    }
                }
            } catch (\Exception $e) {
                $this->logger->debug("Could not decode token: " . $e->getMessage());
            }

            // Build blob REST API URL
            $url = "https://{$this->accountName}.blob.core.windows.net/{$container}/{$blobName}";
            $this->logger->debug("Uploading to: $url");
            
            // Build headers for Blob Storage REST API with Bearer token
            $contentLength = strlen($content);
            
            // Make PUT request with Bearer token
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$token}",
                    "Content-Type: {$contentType}",
                    "Content-Length: {$contentLength}",
                    "x-ms-blob-type: BlockBlob",
                    "x-ms-version: 2021-08-06",
                ],
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => $content,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $this->logger->debug("Upload response: HTTP $httpCode", ['error' => $error, 'response_length' => strlen($response)]);

            if ($httpCode < 200 || $httpCode >= 300) {
                $msg = "Upload failed with HTTP {$httpCode}: " . substr($response, 0, 200);
                $this->logger->error($msg);
                throw new \Exception($msg);
            }

            $this->logger->info("Blob uploaded successfully", ['url' => $url]);

        } catch (\Exception $e) {
            $this->logger->error("Failed to upload blob: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Read blob tags
     */
    public function getTags(string $container, string $blobName): array {
        try {
            // TODO: Implement using Azure SDK
            // For now, return empty tags
            $this->logger->debug("Getting tags", ['container' => $container, 'blob' => $blobName]);
            return [];
        } catch (\Exception $e) {
            $this->logger->error("Failed to get tags: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Set blob tags using Managed Identity
     */
    public function setTags(string $container, string $blobName, array $tags): void {
        try {
            if (!$this->credential) {
                $this->logger->warning("Cannot set tags: no credential");
                return;
            }

            $token = $this->credential->getToken('https://storage.azure.com');
            if (!$token) {
                $this->logger->error("Failed to acquire token for setting tags");
                return;
            }

            $url = "https://{$this->accountName}.blob.core.windows.net/{$container}/{$blobName}?comp=tags";

            // Build XML body for Set Blob Tags API
            $xml = '<?xml version="1.0" encoding="utf-8"?><Tags><TagSet>';
            foreach ($tags as $key => $value) {
                $xml .= '<Tag><Key>' . htmlspecialchars($key) . '</Key><Value>' . htmlspecialchars($value) . '</Value></Tag>';
            }
            $xml .= '</TagSet></Tags>';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$token}",
                    "Content-Type: application/xml",
                    "Content-Length: " . strlen($xml),
                    "x-ms-version: 2021-08-06",
                ],
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_POSTFIELDS => $xml,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode < 200 || $httpCode >= 300) {
                $this->logger->error("Failed to set tags: HTTP {$httpCode}", ['response' => substr($response, 0, 200)]);
            } else {
                $this->logger->info("Blob tags set", ['container' => $container, 'blob' => $blobName, 'tags' => $tags]);
            }

        } catch (\Exception $e) {
            $this->logger->error("Failed to set tags: " . $e->getMessage());
        }
    }

    /**
     * Copy blob from one container to another using Managed Identity
     */
    public function copyBlob(string $sourceContainer, string $sourceBlobName, string $destContainer, string $destBlobName): void {
        try {
            if (!$this->credential) {
                $this->logger->warning("Cannot copy blob: no credential");
                return;
            }

            $token = $this->credential->getToken('https://storage.azure.com');
            if (!$token) {
                throw new \Exception("Failed to acquire token for blob copy");
            }

            $sourceUrl = "https://{$this->accountName}.blob.core.windows.net/{$sourceContainer}/{$sourceBlobName}";
            $destUrl = "https://{$this->accountName}.blob.core.windows.net/{$destContainer}/{$destBlobName}";

            $ch = curl_init($destUrl);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$token}",
                    "x-ms-copy-source: {$sourceUrl}",
                    "x-ms-version: 2021-08-06",
                ],
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode < 200 || $httpCode >= 300) {
                $this->logger->error("Failed to copy blob: HTTP {$httpCode}", ['response' => substr($response, 0, 200)]);
                throw new \Exception("Copy blob failed: HTTP {$httpCode}");
            }

            $this->logger->info("Blob copied", [
                'source' => "$sourceContainer/$sourceBlobName",
                'dest' => "$destContainer/$destBlobName",
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Failed to copy blob: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Read and parse YAML metadata sidecar
     *
     * @return array|null Parsed YAML as associative array, or null if not found
     */
    public function readYamlMetadata(string $container, string $blobName): ?array {
        try {
            // Construct metadata filename (e.g., "img_0.png" → "img_0.yml")
            $yamlName = $this->getYamlName($blobName);

            $yaml = $this->readBlob($container, $yamlName);
            if (!$yaml) {
                $this->logger->debug("YAML sidecar not found: $yamlName");
                return null;
            }

            // Parse YAML using Symfony YAML component
            $parsed = \Symfony\Component\Yaml\Yaml::parse($yaml);
            if (!is_array($parsed)) {
                $this->logger->warning("YAML parsed to non-array", ['file' => $yamlName]);
                return null;
            }

            $this->logger->info("Read YAML metadata", ['file' => $yamlName, 'keys' => array_keys($parsed)]);
            return $parsed;

        } catch (\Exception $e) {
            $this->logger->debug("YAML metadata not found or parse error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get YAML filename for blob (e.g., "img_0.png" → "img_0.yml")
     */
    private function getYamlName(string $blobName): string {
        $pathInfo = pathinfo($blobName);
        return $pathInfo['dirname'] !== '.'
            ? $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.yml'
            : $pathInfo['filename'] . '.yml';
    }

    /**
     * Get blob URL with SAS token using user delegation key
     * Falls back to plain URL if SAS generation fails
     */
    public function getBlobUrl(string $container, string $blobName, ?string $sasDuration = 'PT1H'): string {
        $baseUrl = "https://{$this->accountName}.blob.core.windows.net/{$container}/{$blobName}";

        try {
            if (!$this->credential) {
                return $baseUrl;
            }

            // Read the blob and return a base64 data URL for AI model consumption
            // This avoids needing SAS token generation which requires complex signing
            $content = $this->readBlob($container, $blobName);
            if ($content) {
                $extension = strtolower(pathinfo($blobName, PATHINFO_EXTENSION));
                $mimeTypes = [
                    'png' => 'image/png',
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                ];
                $mime = $mimeTypes[$extension] ?? 'application/octet-stream';
                $base64 = base64_encode($content);
                $this->logger->debug("Generated data URL for blob", ['blob' => $blobName, 'size' => strlen($content)]);
                return "data:{$mime};base64,{$base64}";
            }
        } catch (\Exception $e) {
            $this->logger->warning("Failed to generate data URL, using plain URL: " . $e->getMessage());
        }

        return $baseUrl;
    }
}
