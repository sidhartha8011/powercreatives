<?php
/**
 * Google Provider
 *
 * Implements image generation via Google Imagen API.
 * Extracted from PCM_REST_Image::google_imagen_generate().
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Provider_Google implements PCM_Provider_Interface
{

    /** @var string API key for Google AI. */
    private string $api_key;

    /** @var string Base URL for Generative Language API. */
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    /** @var int Request timeout in seconds. */
    private const TIMEOUT = 120;

    /**
     * @param string $api_key Google AI API key.
     */
    public function __construct(string $api_key)
    {
        $this->api_key = $api_key;
    }

    public function get_id(): string
    {
        return 'google';
    }

    public function supports(string $capability): bool
    {
        // Google supports both image (Imagen) and video (Veo direct API).
        return in_array($capability, ['image', 'video'], true);
    }

    /**
     * Generate an image via Google Imagen.
     *
     * Google Imagen returns base64-encoded images, so we decode and save
     * via PCM_Storage rather than returning a URL directly.
     *
     * @param string $model_id Imagen model ID (e.g. 'imagen-3.0-generate-002'). Enforced by interface.
     * @param array  $params { prompt }
     * @return array { url: string }
     */
    public function generate_image(string $model_id, array $params): array
    {
        $prompt = $params['prompt'] ?? '';

        $body = [
            'instances' => [['prompt' => $prompt]],
            'parameters' => ['sampleCount' => 1],
        ];

        $url = self::API_BASE . "/{$model_id}:predict?key={$this->api_key}";

        $response = wp_remote_post($url, [
            'body' => wp_json_encode($body),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => self::TIMEOUT,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Google API error: ' . $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['predictions'][0]['bytesBase64Encoded'])) {
            $error_msg = $data['error']['message'] ?? 'Imagen generation returned no image.';
            throw new \RuntimeException("Google Imagen failed: {$error_msg}");
        }

        // Google returns base64 — decode and save via PCM_Storage
        $image_data = base64_decode($data['predictions'][0]['bytesBase64Encoded']);
        $result = PCM_Storage::save_data(
            $image_data,
            'pcm-imagen-' . wp_generate_uuid4() . '.png',
            'image/png',
            'image-gen'
        );

        return ['url' => $result['url']];
    }

    /**
     * Generate video via Google Veo API.
     *
     * Delegates to PCM_Google_Veo_Api which handles the full async flow:
     * submit (predictLongRunning) → poll (operation status) → download (video URI).
     *
     * @param string $model_id Veo model ID (e.g. 'veo-2.0-generate-001'). Enforced by interface.
     * @param array  $params { prompt, aspectRatio? }
     * @return array { url: string }
     */
    public function generate_video(string $model_id, array $params): array
    {
        return PCM_Google_Veo_Api::generate_video($this->api_key, $model_id, $params);
    }

    /**
     * Edit image — not natively supported in current implementation.
     */
    public function edit_image(string $model_id, array $params): array
    {
        throw new \RuntimeException('Google Imagen does not support image editing yet.');
    }

    /**
     * Validate a Google AI API key by listing models.
     */
    public function validate_key(string $api_key): array
    {
        $response = wp_remote_get(self::API_BASE . "?key={$api_key}", [
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return ['valid' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 400 || $code === 403) {
            return ['valid' => false, 'error' => 'Invalid API key'];
        }

        return ['valid' => $code === 200];
    }
}
