<?php

/**
 * Azure Alt-Text Pipeline - HTTP Handler
 *
 * Routes incoming Event Grid and manual requests to appropriate handlers.
 * Endpoints:
 *   - GET  /health               - Health check
 *   - POST /login                - Multi-tenant authentication
 *   - POST /describe             - Main alt-text generation pipeline
 *   - POST /describe/webhook     - Event Grid webhook (auto-triggers)
 *
 * Event Grid Integration:
 *   The /describe endpoint handles both:
 *   1. Event Grid subscription validation (SubscriptionValidationEvent)
 *   2. Blob creation events (BlobCreated) via Event Grid
 */

declare(strict_types=1);

// ============================================================================
// Bootstrap & Configuration
// ============================================================================

require __DIR__ . '/vendor/autoload.php';

use AltPipeline\Bootstrap;
use AltPipeline\Pipeline\PipelineOrchestrator;

// Load environment variables
try {
    if (file_exists(__DIR__ . '/.env')) {
        // Try modern Symfony Dotenv API
        if (method_exists(\Symfony\Component\Dotenv\Dotenv::class, 'createUnsafe')) {
            $dotenv = \Symfony\Component\Dotenv\Dotenv::createUnsafe();
        } else {
            $dotenv = new \Symfony\Component\Dotenv\Dotenv();
        }
        $dotenv->loadEnv(__DIR__ . '/.env');
    }
} catch (\Exception $e) {
    error_log("Warning: Could not load .env file: " . $e->getMessage());
}

// Initialize services from environment
$app = Bootstrap::initialize();

// ============================================================================
// Request Routing
// ============================================================================

function main() {
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = preg_replace('#^/(?:api/)?#', '/', $path);

    // Health check
    if ($path === '/health' || $path === '/' && $method === 'GET') {
        return respondJson(['status' => 'ok', 'timestamp' => time()], 200);
    }

    // Login endpoint (multi-tenant support)
    if ($path === '/login' && $method === 'POST') {
        return handleLogin();
    }

    // Description endpoint
    if (str_starts_with($path, '/describe')) {
        return handleDescribe($method);
    }

    // Not found
    return respondJson(['error' => 'Not found', 'path' => $path], 404);
}

// ============================================================================
// POST /login Handler (Multi-Tenant Support)
// ============================================================================

function handleLogin(): void {
    try {
        // Parse request body
        $body = file_get_contents('php://input');
        $payload = json_decode($body, true, depth: 512, flags: JSON_THROW_ON_ERROR) ?? [];

        // Get tenant ID from request or use default
        $tenantId = $payload['tenant_id'] ?? getenv('AZURE_TENANT_ID') ?: 'default';
        $userName = $payload['user_id'] ?? null;

        // Validate multi-tenant mode is enabled
        $multiTenantEnabled = getenv('MULTI_TENANT_ENABLED') === 'true';

        if (!$multiTenantEnabled && $tenantId !== 'default') {
            respondJson(
                ['error' => 'Multi-tenant mode is not enabled'],
                400
            );
            return;
        }

        // Generate session token (simple JWT-like token for demo)
        $sessionToken = base64_encode(json_encode([
            'tenant_id' => $tenantId,
            'user_id' => $userName,
            'issued_at' => time(),
            'expires_at' => time() + 3600, // 1 hour
        ]));

        // Return login response
        respondJson(
            [
                'status' => 'ok',
                'session_token' => $sessionToken,
                'tenant_id' => $tenantId,
                'user_id' => $userName,
                'expires_in' => 3600,
                'message' => "Logged in to tenant: $tenantId",
            ],
            200
        );

    } catch (\JsonException $e) {
        respondJson(['error' => 'Invalid JSON in request'], 400);
    } catch (\Exception $e) {
        error_log("Login error: " . $e->getMessage());
        respondJson(['error' => 'Login failed', 'message' => $e->getMessage()], 500);
    }
}

// ============================================================================
// POST /describe Handler
// ============================================================================

function handleDescribe(string $method): void {
    if ($method !== 'POST') {
        respondJson(['error' => 'Method not allowed'], 405);
        return;
    }

    try {
        // Parse request body
        $body = file_get_contents('php://input');
        
        // Log incoming request for debugging
        error_log("Incoming POST /describe: " . strlen($body) . " bytes");
        
        $payload = json_decode($body, true, depth: 512, flags: JSON_THROW_ON_ERROR);
        
        // Log parsed payload
        if (is_array($payload) && isset($payload[0]['eventType'])) {
            error_log("Event type: " . $payload[0]['eventType']);
        }

        // Handle Event Grid subscription validation
        if (is_array($payload) && count($payload) > 0 && isset($payload[0]['eventType'])) {
            if ($payload[0]['eventType'] === 'Microsoft.EventGrid.SubscriptionValidationEvent') {
                $validationCode = $payload[0]['data']['validationCode'] ?? null;
                error_log("Event Grid validation request detected. Code: " . ($validationCode ?? 'NONE'));
                if ($validationCode) {
                    error_log("Sending validation response");
                    respondJson(['validationResponse' => $validationCode], 200);
                    return;
                }
            }
        }

        // For now, respond OK to all other requests without processing
        // This allows Event Grid to validate and send events
        // Full processing can be implemented after AI services are configured
        
        if (!isset($payload['blobName']) && !isset($payload[0])) {
            respondJson(
                [
                    'status' => 'pending',
                    'message' => 'Handler initialized but AI services not yet configured',
                    'note' => 'Configure AZURE_VISION_ENDPOINT and AZURE_TRANSLATOR_ENDPOINT to enable processing'
                ],
                202
            );
            return;
        }

        // Extract blob information from Event Grid payload
        if (is_array($payload) && isset($payload[0]['eventType']) && $payload[0]['eventType'] === 'Microsoft.Storage.BlobCreated') {
            $blobUrl = $payload[0]['data']['url'] ?? null;
            if (!$blobUrl) {
                throw new \InvalidArgumentException('Could not extract blob URL from Event Grid message');
            }
            
            // Extract blob name from URL
            // URL format: https://account.blob.core.windows.net/container/blobname
            $urlParts = parse_url($blobUrl);
            $pathParts = explode('/', trim($urlParts['path'], '/'));
            $containerName = $pathParts[0] ?? '';
            $blobName = implode('/', array_slice($pathParts, 1));
            
            error_log("Processing blob: $blobName from container: $containerName");
            
            // Only process image files (skip .yml, .json files)
            if (!preg_match('/\.(png|jpg|jpeg|gif|webp)$/i', $blobName)) {
                error_log("Skipping non-image file: $blobName");
                respondJson(
                    [
                        'status' => 'skipped',
                        'blob' => $blobName,
                        'reason' => 'Not an image file'
                    ],
                    200
                );
                return;
            }
            
            // Run the real alt-text pipeline (Phi-4 description + Azure Translator)
            $app = Bootstrap::initialize();
            $logger = $app['logger'];
            
            $logger->info("Starting alt-text pipeline for: $blobName");
            
            $orchestrator = new PipelineOrchestrator($app);
            $result = $orchestrator->processBlob($blobName);
            
            $altTexts = $result['altJson']['altText'] ?? [];
            $confidence = $result['altJson']['confidence'] ?? 0.0;
            $policyCompliant = $result['altJson']['policyCompliant'] ?? false;
            
            $logger->info("Pipeline completed for: $blobName", [
                'confidence' => $confidence,
                'policyCompliant' => $policyCompliant,
                'languages' => array_keys($altTexts),
            ]);
            
            respondJson(
                [
                    'status' => 'processed',
                    'blob' => $blobName,
                    'altText' => $altTexts,
                    'confidence' => $confidence,
                    'policyCompliant' => $policyCompliant,
                    'violations' => $result['altJson']['violations'] ?? [],
                ],
                200
            );
            return;
        }

        // Manual request - return pending
        $blobName = $payload['blobName'] ?? 'unknown';
        respondJson(
            [
                'status' => 'pending',
                'blob' => $blobName,
                'message' => 'AI services not yet configured'
            ],
            202
        );

    } catch (\JsonException $e) {
        respondJson(['error' => 'Invalid JSON in request'], 400);
    } catch (\Exception $e) {
        // Log error
        error_log("Pipeline error: " . $e->getMessage() . "\n" . $e->getTraceAsString());

        respondJson(
            [
                'error' => 'Processing failed',
                'message' => $e->getMessage(),
            ],
            500
        );
    }
}

// ============================================================================
// Helper Functions
// ============================================================================

/**
 * Validate session token (simple verification for demo)
 */
function validateSessionToken(string $token, string $expectedTenantId): void {
    try {
        $decoded = json_decode(base64_decode($token), true);
        
        if (!is_array($decoded)) {
            throw new \Exception('Invalid token format');
        }

        if (($decoded['expires_at'] ?? 0) < time()) {
            throw new \Exception('Token has expired');
        }

        if (($decoded['tenant_id'] ?? null) !== $expectedTenantId) {
            throw new \Exception('Token tenant does not match request tenant');
        }
    } catch (\Exception $e) {
        throw new \Exception("Session validation failed: " . $e->getMessage());
    }
}

function respondJson(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

// ============================================================================
// Execute
// ============================================================================

main();
