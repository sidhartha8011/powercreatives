<?php
/**
 * Fal.ai Provider
 *
 * Thin wrapper that implements PCM_Provider_Interface for Fal.ai.
 * All API logic is delegated to PCM_Fal_Api.
 * All input mapping is delegated to PCM_Fal_Input_Mapper.
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Provider_Fal implements PCM_Provider_Interface
{

    /**
     * Fal.ai API key (format: key_id:key_secret).
     *
     * @var string
     */
    private string $api_key;

    /**
     * @param string $api_key Fal.ai API key.
     */
    public function __construct(string $api_key)
    {
        $this->api_key = $api_key;
    }

    /**
     * Provider identifier — must match the key in PCM_Provider_Registry::PROVIDERS
     * and the `provider` field in wp_pcm_models rows.
     *
     * @return string
     */
    public function get_id(): string
    {
        return 'fal';
    }

    /**
     * Check if this provider supports a given capability.
     *
     * Fal.ai supports image generation and image editing (via Flux Kontext).
     * Video support may be added later.
     *
     * @param string $capability One of: 'image', 'video', 'edit', 'upscale'.
     * @return bool
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, ['image', 'edit'], true);
    }

    /**
     * Generate an image via Fal.ai.
     *
     * Delegates entirely to PCM_Fal_Api::generate_image().
     *
     * @param string $model_id Our internal model ID (e.g. 'fal-flux-2-flex').
     * @param array  $params   Normalized generation parameters.
     * @return array { url: string }
     * @throws \RuntimeException On failure.
     */
    public function generate_image(string $model_id, array $params): array
    {
        // Resolve local/private URLs → base64 data URLs (Fal.ai accepts inline base64)
        $input_urls = $params['inputUrls'] ?? [];
        if (!empty($input_urls)) {
            $params['inputUrls'] = PCM_Input_Resolver::resolve_all($input_urls);
        }

        return PCM_Fal_Api::generate_image($this->api_key, $model_id, $params);
    }

    /**
     * Generate a video.
     *
     * Not yet implemented for Fal.ai. Throws if called.
     *
     * @param string $model_id Model ID.
     * @param array  $params   Generation parameters.
     * @return array
     * @throws \RuntimeException Always — video not supported yet.
     */
    public function generate_video(string $model_id, array $params): array
    {
        throw new \RuntimeException('Fal.ai video generation is not yet implemented.');
    }

    /**
     * Edit an existing image via Fal.ai (Flux Kontext).
     *
     * Routes to generate_image with the image_url included in params,
     * since Flux Kontext handles editing through the standard generation endpoint.
     *
     * @param string $model_id Model ID.
     * @param array  $params   { image_url: string, prompt: string }
     * @return array { url: string }
     * @throws \RuntimeException On failure.
     */
    public function edit_image(string $model_id, array $params): array
    {
        // Normalize: edit_image uses image_url, but our API class expects inputUrls
        if (!empty($params['image_url']) && empty($params['inputUrls'])) {
            $params['inputUrls'] = [$params['image_url']];
        }

        // Resolve local/private URLs → base64 data URLs (Fal.ai accepts inline base64)
        $input_urls = $params['inputUrls'] ?? [];
        if (!empty($input_urls)) {
            $params['inputUrls'] = PCM_Input_Resolver::resolve_all($input_urls);
        }

        return PCM_Fal_Api::generate_image($this->api_key, $model_id, $params);
    }

    /**
     * Validate the Fal.ai API key.
     *
     * @param string $api_key Fal.ai API key.
     * @return array { valid: bool, error?: string }
     */
    public function validate_key(string $api_key): array
    {
        return PCM_Fal_Api::validate_key($api_key);
    }
}
