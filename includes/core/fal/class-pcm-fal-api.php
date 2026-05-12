<?php
/**
 * Fal.ai API Service
 *
 * Handles all HTTP communication with the Fal.ai REST API.
 * Uses the synchronous endpoint (fal.run) for direct response.
 *
 * API Pattern:
 *   POST https://fal.run/{model_endpoint}
 *   Headers: Authorization: Key {api_key}
 *   Body: { prompt: "...", image_size: "...", ... }
 *   → Response: { images: [{ url: "https://...", width, height }] }
 *
 * All input mapping is delegated to PCM_Fal_Input_Mapper.
 * This class only handles HTTP transport and output normalization.
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Fal_Api
{

    /**
     * Base URL for synchronous Fal.ai requests.
     * Returns result directly (no polling needed for image models).
     *
     * @var string
     */
    private const SYNC_BASE = 'https://fal.run';

    /**
     * Request timeout in seconds.
     * All image models respond within 20s; 120s gives generous buffer.
     *
     * @var int
     */
    private const REQUEST_TIMEOUT = 120;

    // ─── Model Endpoint Registry ────────────────────────────────────────

    /**
     * Map of our internal model IDs to Fal.ai API endpoint IDs.
     *
     * Our model IDs (stored in wp_pcm_models.modelId) use a `fal-` prefix
     * and simplified names. The Fal.ai API uses slash-separated paths.
     *
     * @var array<string, string>
     */
    private const MODEL_ENDPOINTS = [
        'fal-nano-banana-pro' => 'fal-ai/nano-banana-pro',
        'fal-nano-banana-2' => 'fal-ai/nano-banana-2',
        'fal-flux-kontext' => 'fal-ai/flux-pro/kontext',
        'fal-flux-2-flex' => 'fal-ai/flux-2-flex',
        'fal-recraft-v4' => 'fal-ai/recraft/v4/pro/text-to-image',
        'fal-ideogram-v3' => 'fal-ai/ideogram/v3',
    ];

    // ─── Public API ─────────────────────────────────────────────────────

    /**
     * Generate an image using Fal.ai.
     *
     * @param string $api_key  Fal.ai API key (format: {key_id}:{key_secret}).
     * @param string $model_id Our internal model ID (e.g. 'fal-flux-2-flex').
     * @param array  $params   Normalized generation parameters (see PCM_Fal_Input_Mapper).
     * @return array { url: string } The generated image URL.
     * @throws \RuntimeException On API errors.
     */
    public static function generate_image(string $api_key, string $model_id, array $params): array
    {
        $fal_endpoint = self::resolve_endpoint($model_id);
        $input = PCM_Fal_Input_Mapper::build_input($fal_endpoint, $params);

        error_log(sprintf(
            '[Fal.ai] POST %s/%s prompt="%s"',
            self::SYNC_BASE,
            $fal_endpoint,
            mb_substr($input['prompt'] ?? '', 0, 50)
        ));

        $url = self::SYNC_BASE . '/' . $fal_endpoint;

        $response = wp_remote_post($url, [
            'headers' => self::build_headers($api_key),
            'body' => wp_json_encode($input),
            'timeout' => self::REQUEST_TIMEOUT,
        ]);

        // --- Handle WP HTTP errors ---
        if (is_wp_error($response)) {
            throw new \RuntimeException(
                'Fal.ai API request failed: ' . $response->get_error_message()
                );
        }

        // --- Parse response ---
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // --- Handle HTTP errors ---
        if ($status_code !== 200) {
            $error_msg = $data['detail'] ?? $data['message'] ?? "HTTP {$status_code}";
            throw new \RuntimeException("Fal.ai error ({$status_code}): {$error_msg}");
        }

        // --- Extract result URL ---
        return self::extract_result($data, $model_id);
    }

    /**
     * Validate a Fal.ai API key by making a lightweight request.
     *
     * Uses a minimal Flux Dev request with 1 inference step.
     * This is the cheapest way to verify the key works.
     *
     * @param string $api_key Fal.ai API key.
     * @return array { valid: bool, error?: string }
     */
    public static function validate_key(string $api_key): array
    {
        // Use a lightweight health-check approach: list user info or make a tiny request
        $url = self::SYNC_BASE . '/fal-ai/flux/dev';

        $response = wp_remote_post($url, [
            'headers' => self::build_headers($api_key),
            'body' => wp_json_encode([
                'prompt' => 'test',
                'image_size' => 'square',
                'num_images' => 1,
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return [
                'valid' => false,
                'error' => 'Connection failed: ' . $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code === 200 && !empty($body['images'])) {
            return ['valid' => true];
        }

        // Check for auth-specific errors
        $error = $body['detail'] ?? $body['message'] ?? "HTTP {$status_code}";
        return [
            'valid' => false,
            'error' => $error,
        ];
    }

    // ─── Private Helpers ────────────────────────────────────────────────

    /**
     * Resolve our internal model ID to a Fal.ai API endpoint.
     *
     * @param string $model_id Our model ID (e.g. 'fal-flux-2-flex').
     * @return string Fal.ai endpoint (e.g. 'fal-ai/flux-2-flex').
     * @throws \RuntimeException If model ID is not recognized.
     */
    private static function resolve_endpoint(string $model_id): string
    {
        $endpoint = self::MODEL_ENDPOINTS[$model_id] ?? null;

        if (!$endpoint) {
            throw new \RuntimeException("Unknown Fal.ai model: {$model_id}");
        }

        return $endpoint;
    }

    /**
     * Build HTTP headers for Fal.ai API requests.
     *
     * @param string $api_key Fal.ai API key (key_id:key_secret).
     * @return array HTTP headers.
     */
    private static function build_headers(string $api_key): array
    {
        return [
            'Authorization' => 'Key ' . $api_key,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Extract the result URL from a Fal.ai response.
     *
     * All Fal.ai image models return: { images: [{ url: "..." }] }
     * We normalize this to our standard: ['url' => 'https://...']
     *
     * @param array  $data     Decoded API response.
     * @param string $model_id Our model ID (for error messages).
     * @return array { url: string }
     * @throws \RuntimeException If no image URL is found.
     */
    private static function extract_result(array $data, string $model_id): array
    {
        $images = $data['images'] ?? [];

        if (empty($images) || empty($images[0]['url'])) {
            throw new \RuntimeException(
                "Fal.ai returned success but no image URL for model: {$model_id}"
                );
        }

        $url = $images[0]['url'];

        error_log(sprintf('[Fal.ai] ✓ Image generated: %s', $url));

        return ['url' => $url];
    }
}
