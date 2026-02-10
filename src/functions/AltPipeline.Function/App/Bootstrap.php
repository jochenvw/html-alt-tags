<?php

namespace AltPipeline;

use AltPipeline\Contracts\ImageDescriber;
use AltPipeline\Contracts\TextTranslator;
use AltPipeline\Services\{SlmDescriber, VisionDescriber, LlmDescriber, TranslatorService, LlmTranslator, Phi4Describer, Phi4Translator};
use AltPipeline\Auth\ManagedIdentityCredential;
use AltPipeline\Storage\BlobClient;
use Psr\Log\LoggerInterface;

/**
 * Bootstrap - Service Container & Configuration
 *
 * Initializes all services based on environment variables.
 * Provides factory methods for describers and translators with strategy pattern.
 * Uses Managed Identity for secure, keyless authentication to Azure services.
 */
class Bootstrap {
    private static array $services = [];
    private static ?ManagedIdentityCredential $credential = null;

    /**
     * Initialize all services from environment variables
     */
    public static function initialize(): array {
        // Prevent re-initialization
        if (!empty(self::$services)) {
            return self::$services;
        }

        // Logger (simple)
        $logger = new SimpleLogger(getenv('LOG_LEVEL') ?: 'info');
        self::$services['logger'] = $logger;

        // Managed Identity Credential (shared across services)
        self::$credential = new ManagedIdentityCredential($logger);

        // Storage client with Managed Identity
        $storageAccount = getenv('AZURE_STORAGE_ACCOUNT');
        $storageKey = getenv('AZURE_STORAGE_ACCOUNT_KEY');
        $connectionString = getenv('AZURE_STORAGE_CONNECTION_STRING');

        self::$services['blobClient'] = new BlobClient(
            accountName: $storageAccount,
            accountKey: $storageKey,
            connectionString: $connectionString,
            logger: $logger,
            credential: self::$credential
        );

        // Image Describer (strategy)
        self::$services['describer'] = self::createDescriber($logger);

        // Text Translator (strategy)
        self::$services['translator'] = self::createTranslator($logger);

        return self::$services;
    }

    /**
     * Create Describer service based on DESCRIBER env var
     * Format: "strategy:slm|llm|vision|phi4"
     */
    private static function createDescriber(SimpleLogger $logger): ImageDescriber {
        $strategy = getenv('DESCRIBER') ?: 'strategy:slm';
        $strategy = preg_replace('/^strategy:/', '', $strategy);

        return match ($strategy) {
            'slm' => new SlmDescriber(
                endpoint: getenv('AZURE_FOUNDRY_ENDPOINT'),
                credential: self::$credential,
                deploymentName: getenv('AZURE_FOUNDRY_DEPLOYMENT_SLM') ?: 'Phi-4-multimodal-instruct',
                logger: $logger
            ),
            'llm' => new LlmDescriber(
                endpoint: getenv('AZURE_FOUNDRY_ENDPOINT'),
                credential: self::$credential,
                deploymentName: getenv('AZURE_FOUNDRY_DEPLOYMENT_LLM') ?: 'gpt-4',
                logger: $logger
            ),
            'vision' => new VisionDescriber(
                endpoint: getenv('AZURE_VISION_ENDPOINT'),
                credential: self::$credential,
                logger: $logger
            ),
            'phi4' => new Phi4Describer(
                endpoint: getenv('AZURE_FOUNDRY_ENDPOINT'),
                credential: self::$credential,
                deploymentName: getenv('AZURE_FOUNDRY_DEPLOYMENT') ?: 'Phi-4-multimodal-instruct',
                region: getenv('AZURE_REGION') ?: 'swedencentral',
                logger: $logger
            ),
            default => throw new \InvalidArgumentException("Unknown describer strategy: $strategy"),
        };
    }

    /**
     * Create Translator service based on TRANSLATOR env var
     * Format: "strategy:translator|llm|phi4"
     */
    private static function createTranslator(SimpleLogger $logger): TextTranslator {
        $strategy = getenv('TRANSLATOR') ?: 'strategy:translator';
        $strategy = preg_replace('/^strategy:/', '', $strategy);

        return match ($strategy) {
            'translator' => new TranslatorService(
                endpoint: getenv('AZURE_TRANSLATOR_ENDPOINT'),
                credential: self::$credential,
                region: getenv('AZURE_TRANSLATOR_REGION') ?: 'swedencentral',
                logger: $logger
            ),
            'llm' => new LlmTranslator(
                endpoint: getenv('AZURE_FOUNDRY_ENDPOINT'),
                credential: self::$credential,
                deploymentName: getenv('AZURE_FOUNDRY_DEPLOYMENT_LLM') ?: 'gpt-4',
                logger: $logger
            ),
            'phi4' => new Phi4Translator(
                endpoint: getenv('AZURE_FOUNDRY_ENDPOINT'),
                credential: self::$credential,
                deploymentName: getenv('AZURE_FOUNDRY_DEPLOYMENT') ?: 'Phi-4-multimodal-instruct',
                region: getenv('AZURE_REGION') ?: 'swedencentral',
                logger: $logger
            ),
            default => throw new \InvalidArgumentException("Unknown translator strategy: $strategy"),
        };
    }

    /**
     * Get initialized service
     */
    public static function get(string $key): mixed {
        if (!isset(self::$services)) {
            self::initialize();
        }
        return self::$services[$key] ?? throw new \InvalidArgumentException("Service not found: $key");
    }
}

/**
 * Simple Logger Implementation (PSR-3 compatible)
 */
class SimpleLogger implements LoggerInterface {
    private string $level;
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];

    public function __construct(string $level = 'info') {
        $this->level = $level;
    }

    public function log($level, $message, array $context = []): void {
        if (self::LEVELS[$level] ?? 1 >= self::LEVELS[$this->level] ?? 1) {
            $msg = "[" . strtoupper($level) . "] " . $message;
            if ($context) {
                $msg .= " " . json_encode($context);
            }
            error_log($msg);
        }
    }

    public function emergency($message, array $context = []): void {
        $this->log('critical', $message, $context);
    }

    public function alert($message, array $context = []): void {
        $this->log('critical', $message, $context);
    }

    public function critical($message, array $context = []): void {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = []): void {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = []): void {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = []): void {
        $this->log('info', $message, $context);
    }

    public function info($message, array $context = []): void {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = []): void {
        $this->log('debug', $message, $context);
    }
}
