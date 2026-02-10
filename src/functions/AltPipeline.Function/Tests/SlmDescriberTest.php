<?php

namespace AltPipeline\Tests;

use AltPipeline\Services\SlmDescriber;
use PHPUnit\Framework\TestCase;

/**
 * SlmDescriber Unit Tests Stub
 *
 * TODO: Complete with real test cases when Azure OpenAI integration is ready
 */
class SlmDescriberTest extends TestCase {
    private SlmDescriber $describer;

    protected function setUp(): void {
        // Mock Azure OpenAI endpoint and key
        $this->describer = new SlmDescriber(
            endpoint: 'https://test.openai.azure.com',
            apiKey: 'test-key',
            deploymentName: 'gpt-35-turbo',
            logger: new \AltPipeline\Bootstrap\SimpleLogger('debug'),
            promptsPath: __DIR__ . '/../../../prompts'
        );
    }

    public function testDescribeReturnsValidStructure(): void {
        // TODO: Mock OpenAI API response
        $result = $this->describer->describe(
            blobName: 'img_0.png',
            sasUrl: 'https://example.blob.core.windows.net/ingest/img_0.png?sv=...',
            sidecar: [
                'make' => 'Epson',
                'model' => 'EcoTank L3560',
                'description' => 'Multifunction printer',
            ]
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('alt_en', $result);
    }

    public function testAltTextIncludesBrandModel(): void {
        // TODO: Implement test with mocked API response
        $this->markTestSkipped('Requires Azure OpenAI integration');
    }

    public function testSystemPromptIsLoadedFromFile(): void {
        // Verify system prompt can be loaded from file
        $reflection = new \ReflectionClass(SlmDescriber::class);
        $method = $reflection->getMethod('getSystemPrompt');
        $method->setAccessible(true);
        
        // Test with a source that has a prompt file
        $prompt = $method->invoke($this->describer, 'public website');
        
        $this->assertNotEmpty($prompt);
        $this->assertStringContainsString('alt text', strtolower($prompt));
    }
    
    public function testSystemPromptFallsBackToDefault(): void {
        // Verify fallback to default prompt works
        $reflection = new \ReflectionClass(SlmDescriber::class);
        $method = $reflection->getMethod('getSystemPrompt');
        $method->setAccessible(true);
        
        // Test with a source that doesn't have a specific prompt file
        $prompt = $method->invoke($this->describer, 'nonexistent_source');
        
        $this->assertNotEmpty($prompt);
        $this->assertStringContainsString('alt text', strtolower($prompt));
    }
}
