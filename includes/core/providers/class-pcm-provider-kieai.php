<?php
/**
 * Kie.ai Provider
 *
 * Implements image and video generation via Kie.ai.
 * Delegates to the Kie integration layer (PCM_Kie_Api)
 * which handles marketplace vs dedicated model routing.
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Provider_KieAI implements PCM_Provider_Interface
{

    /** @var string API key for Kie.ai. */
    private string $api_key;

    /**
     * @param string $api_key Kie.ai API key.
     */
    public function __construct(string $api_key)
    {
        $this->api_key = $api_key;
    }

    public function get_id(): string
    {
        return 'kieai';
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, ['image', 'video'], true);
    }

    /**
     * Generate an image via Kie.ai (marketplace or dedicated model).
     *
     * If inputUrls contain base64 data URLs, they are first uploaded
     * to Kie.ai's temporary file storage and replaced with HTTP URLs.
     *
     * @param string $model_id Kie.ai model ID (e.g. 'kie-imagen4-fast'). Enforced by interface.
     * @param array  $params { prompt, aspectRatio?, inputUrls? }
     * @return array { url: string }
     */
    public function generate_image(string $model_id, array $params): array
    {
        // --- DEBUG: Log provider entry point ---
        error_log( sprintf( '[KieAI Provider DEBUG] generate_image called: model_id=%s', $model_id ) );
        error_log( sprintf( '[KieAI Provider DEBUG] raw params: %s', wp_json_encode( array_keys( $params ) ) ) );

        // Resolve base64 data URLs → HTTP URLs (same pattern as generate_video)
        $input_urls = $params['inputUrls'] ?? [];
        if (!empty($input_urls)) {
            error_log( sprintf( '[KieAI Provider DEBUG] Resolving %d input URL(s)', count( $input_urls ) ) );
            $input_urls = PCM_Kie_Upload::resolve_input_urls($this->api_key, $input_urls);
        }

        $mapped_params = [
            'prompt' => $params['prompt'] ?? '',
            'aspectRatio' => $params['aspectRatio'] ?? $params['format'] ?? '1:1',
            'inputUrls' => $input_urls,
        ];
        error_log( sprintf( '[KieAI Provider DEBUG] Mapped params: prompt_length=%d aspectRatio=%s inputUrls_count=%d', strlen( $mapped_params['prompt'] ), $mapped_params['aspectRatio'], count( $mapped_params['inputUrls'] ) ) );

        return PCM_Kie_Api::generate_image($this->api_key, $model_id, $mapped_params);
    }

    /**
     * Generate a video via Kie.ai (marketplace or dedicated model).
     *
     * If inputUrls contain base64 data URLs (from local file uploads),
     * they are first uploaded to Kie.ai's temporary file storage and
     * replaced with HTTP URLs before the generation request.
     *
     * @param string $model_id Kie.ai model ID (e.g. 'kie-kling-2.6-t2v'). Enforced by interface.
     * @param array  $params { prompt, duration?, aspectRatio?, inputUrls? }
     * @return array { url: string }
     */
    public function generate_video(string $model_id, array $params): array
    {
        // Resolve base64 data URLs → HTTP URLs via Kie.ai file upload.
        // This enables image-to-video from locally uploaded images (base64)
        // that external APIs cannot access directly.
        $input_urls = $params['inputUrls'] ?? [];
        if (!empty($input_urls)) {
            $input_urls = PCM_Kie_Upload::resolve_input_urls($this->api_key, $input_urls);
        }

        return PCM_Kie_Api::generate_video($this->api_key, $model_id, [
            'prompt' => $params['prompt'] ?? '',
            'aspectRatio' => $params['aspectRatio'] ?? $params['format'] ?? '16:9',
            // Duration: pass through as-is. The input mapper resolves:
            //   - null (smart) → max valid duration ≤ 15s
            //   - explicit but invalid → nearest valid ≤ requested
            //   - exact match → pass through
            'duration' => $params['duration'] ?? null,
            'inputUrls' => $input_urls,
            // model_id needed by build_dedicated_body() for model variant selection
            // (e.g. veo3 vs veo3_fast based on 'quality' in model ID)
            'model_id' => $model_id,
        ]);
    }

    /**
     * Edit image — delegates to Kie.ai image generation with editing prompt.
     */
    public function edit_image(string $model_id, array $params): array
    {
        $edit_params = $params;
        $edit_params['inputUrls'] = [$params['image_url'] ?? $params['imageUrl'] ?? ''];
        return $this->generate_image($model_id, $edit_params);
    }

    /**
     * Validate a Kie.ai API key by calling the credits endpoint.
     */
    public function validate_key(string $api_key): array
    {
        $response = wp_remote_get('https://api.kie.ai/api/v1/user/credits', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return ['valid' => false, 'error' => $response->get_error_message()];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $code = $data['code'] ?? wp_remote_retrieve_response_code($response);

        if ((int)$code === 401) {
            return ['valid' => false, 'error' => 'Invalid API key'];
        }

        return ['valid' => (int)$code === 200];
    }
}
