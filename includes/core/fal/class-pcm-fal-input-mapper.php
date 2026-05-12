<?php
/**
 * Fal.ai Input Mapper
 *
 * Translates our normalized generation parameters to the exact field names
 * each Fal.ai model expects, based on the model's endpoint definition.
 *
 * This mapper handles two key differences between Fal.ai models:
 *
 * 1. **Aspect Ratio Format:**
 *    - Type A (ratio string): `aspect_ratio: "16:9"` → Nano Banana, Flux Kontext
 *    - Type B (named enum):   `image_size: "landscape_16_9"` → Flux 2 Flex, Recraft, Ideogram
 *
 * 2. **Image Input Format:**
 *    - `image_url` (single string) → Flux Kontext
 *    - `image_urls` (array)        → Ideogram (style references)
 *    - Not supported               → text-to-image only models
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Fal_Input_Mapper
{

    // ─── Aspect Ratio Type Detection ───────────────────────────────────

    /**
     * Models that use `aspect_ratio` (ratio string format like "16:9").
     * All other models use `image_size` (named enum format like "landscape_16_9").
     *
     * @var string[]
     */
    private const RATIO_STRING_MODELS = [
        'fal-ai/nano-banana-pro',
        'fal-ai/nano-banana-2',
        'fal-ai/flux-pro/kontext',
    ];

    /**
     * Mapping from our normalized ratio format to Fal.ai `image_size` enum values.
     * Used for Type B models (Flux 2 Flex, Recraft V4, Ideogram v3).
     *
     * @var array<string, string>
     */
    private const RATIO_TO_IMAGE_SIZE = [
        '1:1' => 'square_hd',
        '16:9' => 'landscape_16_9',
        '9:16' => 'portrait_16_9',
        '4:3' => 'landscape_4_3',
        '3:4' => 'portrait_4_3',
    ];

    /**
     * Mapping from our format names to ratio strings.
     * Used when frontend sends 'portrait' / 'landscape' instead of explicit ratio.
     *
     * @var array<string, string>
     */
    private const FORMAT_TO_RATIO = [
        'portrait' => '9:16',
        'landscape' => '16:9',
    ];

    // ─── Main Mapper ────────────────────────────────────────────────────

    /**
     * Build the request body for a Fal.ai API call.
     *
     * Reads the Fal.ai endpoint ID to determine which aspect ratio format
     * and image input format to use. Zero model-specific logic beyond
     * the model lists defined as class constants.
     *
     * @param string $fal_endpoint  Fal.ai endpoint ID (e.g. 'fal-ai/flux-2-flex').
     * @param array  $params        Normalized generation parameters:
     *                              - prompt: string (required)
     *                              - aspectRatio: string|null (e.g. '16:9')
     *                              - format: 'portrait'|'landscape'|null
     *                              - inputUrls: string[]|null
     *                              - negativePrompt: string|null
     * @return array The request body ready for the API call.
     */
    public static function build_input(string $fal_endpoint, array $params): array
    {
        $input = [];

        // --- Prompt (always required) ---
        $input['prompt'] = $params['prompt'] ?? '';

        // --- Aspect ratio ---
        $aspect = self::resolve_aspect_ratio($fal_endpoint, $params);
        if ($aspect !== null) {
            if (self::uses_ratio_string($fal_endpoint)) {
                $input['aspect_ratio'] = $aspect;
            }
            else {
                $input['image_size'] = $aspect;
            }
        }

        // --- Image input (model-dependent field name) ---
        if (!empty($params['inputUrls'])) {
            $urls = $params['inputUrls'];

            if (self::is_kontext_model($fal_endpoint)) {
                // Flux Kontext: single image_url string
                $input['image_url'] = $urls[0];
            }
            elseif (self::is_ideogram_model($fal_endpoint)) {
                // Ideogram: image_urls array (style references)
                $input['image_urls'] = $urls;
            }
        // Other models: text-to-image only, no image input
        }

        // --- Negative prompt (Ideogram only) ---
        if (!empty($params['negativePrompt']) && self::is_ideogram_model($fal_endpoint)) {
            $input['negative_prompt'] = $params['negativePrompt'];
        }

        // --- Number of images (always 1 for our use case) ---
        $input['num_images'] = 1;

        // --- Output format (prefer PNG for quality) ---
        $input['output_format'] = 'png';

        return $input;
    }

    // ─── Aspect Ratio Helpers ───────────────────────────────────────────

    /**
     * Resolve the aspect ratio value for a model.
     *
     * @param string $fal_endpoint Fal.ai endpoint ID.
     * @param array  $params       Normalized params.
     * @return string|null Resolved value in the correct format for the model.
     */
    private static function resolve_aspect_ratio(string $fal_endpoint, array $params): ?string
    {
        // Resolve raw ratio from params
        $ratio = $params['aspectRatio'] ?? null;

        // If format (portrait/landscape) is provided, translate to ratio
        if (empty($ratio) && !empty($params['format'])) {
            $normalized = strtolower($params['format']);
            $ratio = self::FORMAT_TO_RATIO[$normalized] ?? null;
        }

        // Default to 1:1 if nothing provided
        if (empty($ratio)) {
            $ratio = '1:1';
        }

        // Type A models: return ratio string directly
        if (self::uses_ratio_string($fal_endpoint)) {
            return $ratio;
        }

        // Type B models: translate to named enum
        return self::RATIO_TO_IMAGE_SIZE[$ratio] ?? 'square_hd';
    }

    /**
     * Check if a model uses ratio string format (Type A).
     *
     * @param string $fal_endpoint Fal.ai endpoint ID.
     * @return bool
     */
    private static function uses_ratio_string(string $fal_endpoint): bool
    {
        return in_array($fal_endpoint, self::RATIO_STRING_MODELS, true);
    }

    // ─── Model Type Helpers ─────────────────────────────────────────────

    /**
     * Check if the model is Flux Kontext (image-to-image editing).
     *
     * @param string $fal_endpoint Fal.ai endpoint ID.
     * @return bool
     */
    private static function is_kontext_model(string $fal_endpoint): bool
    {
        return str_contains($fal_endpoint, 'kontext');
    }

    /**
     * Check if the model is Ideogram (supports style references).
     *
     * @param string $fal_endpoint Fal.ai endpoint ID.
     * @return bool
     */
    private static function is_ideogram_model(string $fal_endpoint): bool
    {
        return str_contains($fal_endpoint, 'ideogram');
    }
}
