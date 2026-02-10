<?php

namespace AltPipeline\Tests;

use AltPipeline\Pipeline\Guardrails;
use PHPUnit\Framework\TestCase;

/**
 * Guardrails Unit Tests
 *
 * Tests policy compliance validation
 */
class GuardrailsTest extends TestCase {
    private Guardrails $guardrails;

    protected function setUp(): void {
        $this->guardrails = new Guardrails(
            new \AltPipeline\SimpleLogger('debug')
        );
    }

    public function testValidAltTextPasses(): void {
        $result = $this->guardrails->validate(
            altText: 'Epson EcoTank L3560 A4 multifunction ink tank printer in black, front view with compact desktop design.',
            confidence: 0.89,
            make: 'Epson',
            model: 'EcoTank L3560'
        );

        $this->assertTrue($result['policyCompliant']);
        $this->assertEmpty($result['violations']);
    }

    public function testForbiddenPhraseDetected(): void {
        $result = $this->guardrails->validate(
            altText: 'Image of Epson EcoTank L3560 printer',
            confidence: 0.89,
            make: 'Epson',
            model: 'EcoTank L3560'
        );

        $this->assertFalse($result['policyCompliant']);
        $this->assertContains('forbidden_phrase_image_of', $result['violations']);
    }

    public function testLengthValidation(): void {
        $result = $this->guardrails->validate(
            altText: 'A' . str_repeat('B', 125), // > 125 chars
            confidence: 0.89,
            make: 'Epson',
            model: 'EcoTank L3560'
        );

        $this->assertFalse($result['policyCompliant']);
        $this->assertContains('length_exceeded', $result['violations']);
    }

    public function testTooShortDetected(): void {
        $result = $this->guardrails->validate(
            altText: 'Printer',
            confidence: 0.89,
            make: 'Epson',
            model: 'EcoTank L3560'
        );

        $this->assertFalse($result['policyCompliant']);
        $this->assertContains('too_short', $result['violations']);
    }

    public function testMissingBrandModelDetected(): void {
        $result = $this->guardrails->validate(
            altText: 'Multifunction printer in black, front view with compact desktop design.',
            confidence: 0.89,
            make: 'Epson',
            model: 'EcoTank L3560'
        );

        $this->assertFalse($result['policyCompliant']);
        $this->assertContains('missing_brand_model', $result['violations']);
    }

    public function testLowConfidenceDetected(): void {
        $result = $this->guardrails->validate(
            altText: 'Epson EcoTank L3560 A4 multifunction printer',
            confidence: 0.65, // < 0.7 threshold
            make: 'Epson',
            model: 'EcoTank L3560'
        );

        $this->assertFalse($result['policyCompliant']);
        $this->assertContains('low_confidence', $result['violations']);
    }

    public function testMarketingClaimsDetected(): void {
        $result = $this->guardrails->validate(
            altText: 'Epson EcoTank L3560 - Best-selling multifunction printer on the market',
            confidence: 0.89,
            make: 'Epson',
            model: 'EcoTank L3560'
        );

        $this->assertFalse($result['policyCompliant']);
        $this->assertContains('marketing_claim', $result['violations']);
    }

    public function testOptimalLengthRange(): void {
        // 80–160 chars is optimal
        $altText = 'Epson EcoTank L3560 A4 multifunction ink tank printer in black, front view with compact desktop design. Easy-to-refill tanks.';
        
        $this->assertGreaterThanOrEqual(80, strlen($altText));
        $this->assertLessThanOrEqual(160, strlen($altText));

        $result = $this->guardrails->validate(
            altText: $altText,
            confidence: 0.89,
            make: 'Epson',
            model: 'EcoTank L3560'
        );

        $this->assertTrue($result['policyCompliant']);
    }

    public function testBrandOrModelSuffices(): void {
        // Make alone is enough
        $result = $this->guardrails->validate(
            altText: 'Epson multifunction printer with refillable ink tanks, front view.',
            confidence: 0.89,
            make: 'Epson',
            model: 'L3560'
        );

        $this->assertTrue($result['policyCompliant']);

        // Model alone is enough
        $result = $this->guardrails->validate(
            altText: 'EcoTank L3560 multifunction printer with refillable ink tanks.',
            confidence: 0.89,
            make: 'Epson',
            model: 'EcoTank L3560'
        );

        $this->assertTrue($result['policyCompliant']);
    }
}
