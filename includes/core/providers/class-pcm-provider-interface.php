<?php
/**
 * Provider Interface
 *
 * Contract that every generation provider must implement.
 * Each provider knows how to talk to one external API
 * (OpenAI, Google, Kie.ai, etc.) and hide the API-specific
 * details behind a uniform interface.
 *
 * Usage in REST controllers:
 *   $provider = PCM_Provider_Registry::get('openai', $api_key);
 *   $result   = $provider->generate_image($params);
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

interface PCM_Provider_Interface
{

    /**
     * Generate an image.
     *
     * @param string $model_id Model ID (e.g. 'dall-e-3', 'kie-imagen4-fast'). Required — enforced by signature.
     * @param array  $params {
     *     @type string $prompt      Generation prompt (required).
     *     @type int    $width       Width in pixels.
     *     @type int    $height      Height in pixels.
     *     @type string $style       Style preset.
     *     @type string $quality     Quality level.
     *     @type string $aspectRatio Aspect ratio (e.g. '16:9').
     *     @type array  $inputUrls   Reference/input images.
     * }
     * @return array { url: string } The generated image URL.
     * @throws \RuntimeException On failure.
     */
    public function generate_image(string $model_id, array $params): array;

    /**
     * Generate a video.
     *
     * @param string $model_id Model ID (e.g. 'kie-kling-2.6-t2v'). Required — enforced by signature.
     * @param array  $params {
     *     @type string $prompt      Generation prompt (required).
     *     @type string $duration    Duration in seconds.
     *     @type string $aspectRatio Aspect ratio.
     *     @type array  $inputUrls   Reference/input images.
     * }
     * @return array { url: string } The generated video URL.
     * @throws \RuntimeException On failure.
     */
    public function generate_video(string $model_id, array $params): array;

    /**
     * Edit an existing image.
     *
     * @param string $model_id Model ID. Required — enforced by signature.
     * @param array  $params {
     *     @type string $image_url Source image URL (required).
     *     @type string $prompt    Edit instruction (required).
     *     @type string $mask      Mask data (for in-painting).
     * }
     * @return array { url: string } The edited image URL.
     * @throws \RuntimeException On failure.
     */
    public function edit_image(string $model_id, array $params): array;

    /**
     * Validate an API key for this provider.
     *
     * @param string $api_key The key to validate.
     * @return array { valid: bool, models?: array, error?: string }
     */
    public function validate_key(string $api_key): array;

    /**
     * Get the provider's unique identifier.
     *
     * @return string Provider ID (e.g. 'openai', 'google', 'kieai').
     */
    public function get_id(): string;

    /**
     * Check if this provider supports a given capability.
     *
     * @param string $capability One of: 'image', 'video', 'edit', 'upscale'.
     * @return bool
     */
    public function supports(string $capability): bool;
}
