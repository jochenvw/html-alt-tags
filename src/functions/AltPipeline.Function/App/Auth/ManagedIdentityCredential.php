<?php

namespace AltPipeline\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Managed Identity Credential for Azure Container Apps
 * 
 * Obtains access tokens using Azure Managed Identity (MSI)
 */
class ManagedIdentityCredential
{
    private Client $httpClient;
    private LoggerInterface $logger;
    private array $tokenCache = [];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->httpClient = new Client(['timeout' => 10]);
    }

    /**
     * Get an access token for the specified resource
     * 
     * @param string $resource The resource to get a token for (e.g., 'https://cognitiveservices.azure.com')
     * @return string The access token
     * @throws \Exception if token acquisition fails
     */
    public function getToken(string $resource): string
    {
        // Normalize resource: MSI endpoint uses resource URL without /.default suffix
        $resource = rtrim(preg_replace('#/\.default$#', '', $resource), '/');

        // Check cache (tokens are valid for ~1 hour, we'll cache for 50 minutes)
        $cacheKey = md5($resource);
        if (isset($this->tokenCache[$cacheKey])) {
            $cached = $this->tokenCache[$cacheKey];
            if ($cached['expires_at'] > time() + 300) { // 5 minute buffer
                $this->logger->debug("Using cached token for $resource");
                return $cached['token'];
            }
        }

        // Get token from Managed Identity endpoint
        $token = $this->acquireToken($resource);
        
        // Cache the token
        $this->tokenCache[$cacheKey] = [
            'token' => $token['access_token'],
            'expires_at' => time() + ($token['expires_in'] ?? 3600)
        ];

        return $token['access_token'];
    }

    /**
     * Acquire a new token from the Managed Identity endpoint
     */
    private function acquireToken(string $resource): array
    {
        // Azure Container Apps / App Service use these environment variables
        $identityEndpoint = getenv('IDENTITY_ENDPOINT') ?: getenv('MSI_ENDPOINT');
        $identityHeader = getenv('IDENTITY_HEADER') ?: getenv('MSI_SECRET');
        
        // For user-assigned identity, we need the client ID
        // Available as AZURE_CLIENT_ID in Container Apps with user-assigned identity
        $clientId = getenv('AZURE_CLIENT_ID');

        $this->logger->debug("Token acquisition details", [
            'has_identity_endpoint' => !empty($identityEndpoint),
            'has_identity_header' => !empty($identityHeader),
            'has_client_id' => !empty($clientId),
            'client_id' => $clientId,
        ]);

        if (!$identityEndpoint) {
            // Fallback to Azure Instance Metadata Service (IMDS)
            $this->logger->info("No IDENTITY_ENDPOINT, falling back to IMDS");
            return $this->acquireTokenFromIMDS($resource);
        }

        $this->logger->info("Acquiring token for $resource using Managed Identity Endpoint");

        try {
            $query = [
                'resource' => $resource,
                'api-version' => '2019-08-01'
            ];
            
            // For user-assigned identity, add client_id parameter
            if ($clientId) {
                $query['client_id'] = $clientId;
                $this->logger->debug("Added client_id to token request: $clientId");
            }
            
            $this->logger->debug("Token request", [
                'endpoint' => $identityEndpoint,
                'query' => json_encode($query),
            ]);

            $response = $this->httpClient->get($identityEndpoint, [
                'query' => $query,
                'headers' => [
                    'X-IDENTITY-HEADER' => $identityHeader,
                    'Metadata' => 'true'
                ]
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($body['access_token'])) {
                $this->logger->error("No access_token in response", ['response_keys' => array_keys($body)]);
                throw new \Exception('No access_token in response');
            }

            $this->logger->info("Successfully acquired token for $resource");
            return $body;

        } catch (GuzzleException $e) {
            $this->logger->error("Failed to acquire token: " . $e->getMessage(), ['exception' => $e]);
            throw new \Exception("Failed to acquire Managed Identity token: " . $e->getMessage());
        }
    }

    /**
     * Acquire token from Azure Instance Metadata Service (IMDS)
     * Used when IDENTITY_ENDPOINT is not available
     */
    private function acquireTokenFromIMDS(string $resource): array
    {
        $this->logger->info("Acquiring token from IMDS for $resource");

        try {
            $response = $this->httpClient->get('http://169.254.169.254/metadata/identity/oauth2/token', [
                'query' => [
                    'api-version' => '2018-02-01',
                    'resource' => $resource
                ],
                'headers' => [
                    'Metadata' => 'true'
                ]
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            
            if (!isset($body['access_token'])) {
                throw new \Exception('No access_token in response');
            }

            $this->logger->info("Successfully acquired token from IMDS for $resource");
            return $body;

        } catch (GuzzleException $e) {
            $this->logger->error("Failed to acquire token from IMDS: " . $e->getMessage());
            throw new \Exception("Failed to acquire token from IMDS: " . $e->getMessage());
        }
    }
}
