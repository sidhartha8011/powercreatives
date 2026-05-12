<?php
/**
 * Google Veo API Service
 *
 * Handles all Google Veo REST API communication:
 * submit (predictLongRunning) → poll (operation status) → download (video URI).
 *
 * Reference: docs/api-references/google/veo-api-reference.md
 * Official:  https://ai.google.dev/gemini-api/docs/video
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Google_Veo_Api
{
    /** @var string Base URL for Google Generative Language API. */
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    /** @var int Maximum time to wait for video generation (seconds). */
    private const MAX_WAIT_SEC = 600;

    /** @var int Poll interval between status checks (seconds). Official recommendation: 10s. */
    private const POLL_INTERVAL = 10;

    /** @var int HTTP request timeout for individual API calls (seconds). */
    private const REQUEST_TIMEOUT = 30;

    /**
     * Generate a video via Google Veo API.
     *
     * Orchestrates the full flow: submit → poll → download → return URL.
     * Downloads the video from Google's temporary signed URI (expires in 2 days)
     * and saves it locally via PCM_Storage for permanent access.
     *
     * @param string $api_key  Google AI API key.
     * @param string $model_id Veo model identifier (e.g. veo-2.0-generate-001).
     * @param array  $params {
     *     @type string $prompt      Text prompt describing the video.
     *     @type string $aspectRatio Optional aspect ratio: '16:9' (default) or '9:16'.
     * }
     * @return array { url: string } Local URL to the saved video.
     * @throws RuntimeException On API error, timeout, or download failure.
     */
    public static function generate_video(string $api_key, string $model_id, array $params): array
    {
        // Step 1: Submit generation request → get operation name
        $operation_name = self::submit_task($api_key, $model_id, $params);

        // Step 2: Poll operation until done → get video URI
        $video_uri = self::poll_operation($api_key, $operation_name);

        // Step 3: Download video from temporary signed URI → save locally
        $url = self::download_video($api_key, $video_uri, $model_id);

        return ['url' => $url];
    }

    /**
     * Submit a video generation task via predictLongRunning.
     *
     * POST {API_BASE}/models/{model}:predictLongRunning
     *
     * @param string $api_key  Google AI API key.
     * @param string $model_id Veo model identifier.
     * @param array  $params   Generation parameters.
     * @return string Operation name for polling.
     * @throws RuntimeException On API error.
     */
    private static function submit_task(string $api_key, string $model_id, array $params): string
    {
        $prompt = $params['prompt'] ?? '';
        if (empty($prompt)) {
            throw new \RuntimeException('Google Veo: prompt is required.');
        }

        // Build request body per official API spec
        $body = [
            'instances' => [['prompt' => $prompt]],
            'parameters' => [],
        ];

        // Aspect ratio: '16:9' (default) or '9:16'
        $aspect_ratio = $params['aspectRatio'] ?? $params['format'] ?? '16:9';
        $body['parameters']['aspectRatio'] = $aspect_ratio;

        // Person generation: only send if explicitly provided by the caller.
        // Google's API default already allows adults. Veo 2.0 rejects this param entirely.
        if (!empty($params['personGeneration'])) {
            $body['parameters']['personGeneration'] = $params['personGeneration'];
        }

        $url = self::API_BASE . "/models/{$model_id}:predictLongRunning";

        error_log(sprintf('[Google Veo] POST %s (model: %s)', $url, $model_id));

        $response = wp_remote_post($url, [
            'headers' => [
                'x-goog-api-key' => $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
            'timeout' => self::REQUEST_TIMEOUT,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Google Veo API error: ' . $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $http_code = wp_remote_retrieve_response_code($response);

        if ($http_code !== 200) {
            $error_msg = $data['error']['message'] ?? "HTTP {$http_code}";
            throw new \RuntimeException("Google Veo submit failed: {$error_msg}");
        }

        $operation_name = $data['name'] ?? null;
        if (empty($operation_name)) {
            throw new \RuntimeException('Google Veo: API returned success but no operation name.');
        }

        error_log(sprintf('[Google Veo] Operation started: %s', $operation_name));

        return $operation_name;
    }

    /**
     * Poll an operation until video generation is complete.
     *
     * GET {API_BASE}/{operation_name}
     * Checks .done field. When true, extracts video URI from:
     * response.generateVideoResponse.generatedSamples[0].video.uri
     *
     * @param string $api_key        Google AI API key.
     * @param string $operation_name Operation name from submit_task().
     * @return string Video URI for download.
     * @throws RuntimeException On timeout or generation failure.
     */
    private static function poll_operation(string $api_key, string $operation_name): string
    {
        $start_time = time();
        $url = self::API_BASE . "/{$operation_name}";

        while ((time() - $start_time) < self::MAX_WAIT_SEC) {
            $response = wp_remote_get($url, [
                'headers' => ['x-goog-api-key' => $api_key],
                'timeout' => self::REQUEST_TIMEOUT,
            ]);

            if (is_wp_error($response)) {
                error_log('[Google Veo] Poll error: ' . $response->get_error_message());
                sleep(self::POLL_INTERVAL);
                continue;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);

            // Check if operation is done
            if (!empty($data['done'])) {
                // Check for error in response
                if (!empty($data['error'])) {
                    $error_msg = $data['error']['message'] ?? 'Unknown generation error';
                    throw new \RuntimeException("Google Veo generation failed: {$error_msg}");
                }

                // Extract video URI from nested response
                $video_uri = $data['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'] ?? null;

                if (empty($video_uri)) {
                    throw new \RuntimeException('Google Veo: generation completed but no video URI in response.');
                }

                $elapsed = time() - $start_time;
                error_log(sprintf('[Google Veo] Video ready after %ds: %s', $elapsed, $video_uri));

                return $video_uri;
            }

            sleep(self::POLL_INTERVAL);
        }

        throw new \RuntimeException("Google Veo: generation timed out after " . self::MAX_WAIT_SEC . "s.");
    }

    /**
     * Download video from Google's temporary signed URI and save locally.
     *
     * Google Veo video URIs are temporary (expire in 2 days) and require
     * the API key header + redirect following for download. We download
     * the binary data and save via PCM_Storage for permanent access.
     *
     * @param string $api_key  Google AI API key.
     * @param string $video_uri Temporary signed URI from poll response.
     * @param string $model_id  Model ID (used in filename).
     * @return string Local URL to the saved video.
     * @throws RuntimeException On download or save failure.
     */
    private static function download_video(string $api_key, string $video_uri, string $model_id): string
    {
        error_log(sprintf('[Google Veo] Downloading video from: %s', $video_uri));

        // Google Veo URIs require API key header and follow redirects
        $response = wp_remote_get($video_uri, [
            'headers' => ['x-goog-api-key' => $api_key],
            'timeout' => 120,
            'redirection' => 5,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('Google Veo download failed: ' . $response->get_error_message());
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            throw new \RuntimeException("Google Veo download failed: HTTP {$http_code}");
        }

        $video_data = wp_remote_retrieve_body($response);
        if (empty($video_data)) {
            throw new \RuntimeException('Google Veo: downloaded video is empty.');
        }

        // Save via PCM_Storage — same pattern as generate_image in Google provider
        $filename = 'pcm-veo-' . wp_generate_uuid4() . '.mp4';
        $result = PCM_Storage::save_data(
            $video_data,
            $filename,
            'video/mp4',
            'video-gen'
        );

        error_log(sprintf('[Google Veo] Video saved: %s', $result['url']));

        return $result['url'];
    }
}
