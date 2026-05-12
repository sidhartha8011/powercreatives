<?php
/**
 * OpenAI Provider
 *
 * Implements image generation via DALL-E 2/3 API.
 * Extracted from PCM_REST_Image::openai_generate().
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Provider_OpenAI implements PCM_Provider_Interface
{

    /** @var string API key for OpenAI. */
    private string $api_key;

    /** @var string OpenAI images endpoint. */
    private const API_URL = 'https://api.openai.com/v1/images/generations';

    /** @var int Request timeout in seconds. */
    private const TIMEOUT = 120;

    /** @var array Valid DALL-E 3 sizes. */
    private const VALID_SIZES = ['1024x1024', '1792x1024', '1024x1792'];

    /**
     * @param string $api_key OpenAI API key.
     */
    public function __construct(string $api_key)
    {
        $this->api_key = $api_key;
    }

    public function get_id(): string
    {
        return 'openai';
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, ['image', 'edit'], true);
    }

    /**
     * Generate an image via DALL-E.
     *
     * @param string $model_id Model ID (e.g. 'dall-e-3'). Enforced by interface.
     * @param array  $params { prompt, width?, height?, style?, quality? }
     * @return array { url: string }
     */
    public function generate_image(string $model_id, array $params): array
    {
        $prompt = $params['prompt'] ?? '';
        $size = $this->resolve_size($params);
        $quality = $params['quality'] ?? 'standard';
        $style = $params['style'] ?? 'vivid';

        $body = [
            'model' => $model_id,
            'prompt' => $prompt,
            'n' => 1,
            'size' => $size,
        ];

        // DALL-E 3 supports quality and style parameters
        if ($model_id === 'dall-e-3') {
            $body['quality'] = $quality;
            $body['style'] = $style;
        }

        $response = wp_remote_post(self::API_URL, [
            'body' => wp_json_encode($body),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'timeout' => self::TIMEOUT,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('OpenAI API error: ' . $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['data'][0]['url'])) {
            $error_msg = $data['error']['message'] ?? 'Unknown error';
            throw new \RuntimeException("DALL-E generation failed: {$error_msg}");
        }

        return ['url' => $data['data'][0]['url']];
    }

    /**
     * Generate video — not supported by OpenAI.
     */
    public function generate_video(string $model_id, array $params): array
    {
        throw new \RuntimeException('OpenAI does not support video generation.');
    }

    /**
     * Edit an image — delegates to generation with edit prompt.
     * Full DALL-E edit API (masks, in-painting) can be added later.
     */
    public function edit_image(string $model_id, array $params): array
    {
        $params['prompt'] = 'Edit the following image: ' . ($params['prompt'] ?? '');
        return $this->generate_image($model_id, $params);
    }

    /**
     * Validate an OpenAI API key by listing models.
     */
    public function validate_key(string $api_key): array
    {
        $response = wp_remote_get('https://api.openai.com/v1/models', [
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return ['valid' => false, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 401) {
            return ['valid' => false, 'error' => 'Invalid API key'];
        }

        return ['valid' => $code === 200];
    }

    /**
     * Resolve DALL-E size string from width/height params.
     *
     * @param array $params { width?, height? }
     * @return string e.g. '1024x1024'
     */
    private function resolve_size(array $params): string
    {
        $width = (int)($params['width'] ?? 1024);
        $height = (int)($params['height'] ?? 1024);
        $size = "{$width}x{$height}";

        return in_array($size, self::VALID_SIZES, true) ? $size : '1024x1024';
    }
}
