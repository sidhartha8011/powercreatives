<?php
/**
 * Unit Tests — Brands Service
 *
 * Tests the PCM_Brands_Service class: color validation, formatting,
 * asset entry creation, URL resolution.
 *
 * Pure unit tests — does not require database or WordPress runtime.
 * WordPress functions are mocked via WP_Mock.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class BrandsServiceTest extends TestCase
{
    /**
     * Service under test.
     *
     * @var PCM_Brands_Service
     */
    private PCM_Brands_Service $service;

    /**
     * Set up service instance before each test.
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->service = new PCM_Brands_Service();
    }

    // =========================================================================
    // COLOR VALIDATION
    // =========================================================================

    /**
     * Test: validate_colors accepts valid hex colors.
     */
    public function test_validate_colors_accepts_valid_hex(): void
    {
        $colors = array('#FF0000', '#00ff00', '#0000FF', '#abcdef');
        $result = $this->service->validate_colors($colors);

        $this->assertCount(4, $result);
        $this->assertContains('#FF0000', $result);
        $this->assertContains('#abcdef', $result);
    }

    /**
     * Test: validate_colors rejects invalid formats.
     */
    public function test_validate_colors_rejects_invalid(): void
    {
        $colors = array(
            'red', // Named color
            '#FFF', // 3-char shorthand
            '#GGGGGG', // Invalid hex chars
            'rgb(0,0,0)', // CSS function
            '#FF000000', // 8-char (with alpha)
            '', // Empty string
        );

        $result = $this->service->validate_colors($colors);
        $this->assertCount(0, $result, 'All invalid colors should be filtered out');
    }

    /**
     * Test: validate_colors handles mixed valid and invalid.
     */
    public function test_validate_colors_mixed(): void
    {
        $colors = array('#FF0000', 'invalid', '#00FF00', '123456');
        $result = $this->service->validate_colors($colors);

        $this->assertCount(2, $result);
        $this->assertContains('#FF0000', $result);
        $this->assertContains('#00FF00', $result);
    }

    /**
     * Test: validate_colors returns empty array for empty input.
     */
    public function test_validate_colors_empty_input(): void
    {
        $result = $this->service->validate_colors(array());
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // FORMAT BRAND
    // =========================================================================

    /**
     * Test: format_brand converts DB row to correct array structure.
     */
    public function test_format_brand_returns_correct_structure(): void
    {
        $brand = (object)array(
            'id' => '42',
            'name' => 'Test Brand',
            'website' => 'https://example.com',
            'description' => 'A test brand',
            'tonOfVoice' => 'Professional',
            'colors' => '["#FF0000","#00FF00"]',
            'fonts' => '["Inter"]',
            'assets' => '[]',
            'createdAt' => '2026-01-01 00:00:00',
            'updatedAt' => '2026-01-02 00:00:00',
        );

        $result = $this->service->format_brand($brand);

        // Verify structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('website', $result);
        $this->assertArrayHasKey('colors', $result);
        $this->assertArrayHasKey('fonts', $result);
        $this->assertArrayHasKey('assets', $result);

        // Verify types
        $this->assertIsInt($result['id']);
        $this->assertSame(42, $result['id']);
        $this->assertSame('Test Brand', $result['name']);

        // Verify JSON fields are decoded
        $this->assertIsArray($result['colors']);
        $this->assertCount(2, $result['colors']);
        $this->assertSame('#FF0000', $result['colors'][0]);

        $this->assertIsArray($result['fonts']);
        $this->assertSame('Inter', $result['fonts'][0]);
    }

    /**
     * Test: format_brand handles null/missing optional fields.
     */
    public function test_format_brand_handles_null_fields(): void
    {
        $brand = (object)array(
            'id' => '1',
            'name' => 'Minimal',
            'website' => null,
            'description' => null,
            'tonOfVoice' => null,
            'colors' => null,
            'fonts' => null,
            'assets' => null,
            'createdAt' => '2026-01-01',
            'updatedAt' => '2026-01-01',
        );

        $result = $this->service->format_brand($brand);

        // Null should fallback to defaults
        $this->assertSame('', $result['website']);
        $this->assertSame('', $result['description']);
        $this->assertIsArray($result['colors']);
        $this->assertEmpty($result['colors']);
        $this->assertIsArray($result['assets']);
    }

    /**
     * Test: format_brand handles malformed JSON gracefully.
     */
    public function test_format_brand_handles_malformed_json(): void
    {
        $brand = (object)array(
            'id' => '1',
            'name' => 'Bad JSON',
            'website' => '',
            'description' => '',
            'tonOfVoice' => '',
            'colors' => '{invalid json}',
            'fonts' => 'not json',
            'assets' => '---',
            'createdAt' => '2026-01-01',
            'updatedAt' => '2026-01-01',
        );

        $result = $this->service->format_brand($brand);

        // Malformed JSON should fallback to empty array
        $this->assertIsArray($result['colors']);
        $this->assertEmpty($result['colors']);
        $this->assertIsArray($result['fonts']);
        $this->assertEmpty($result['fonts']);
    }
}
