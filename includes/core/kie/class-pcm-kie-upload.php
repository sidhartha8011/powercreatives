<?php
/**
 * Kie.ai File Upload Service
 *
 * Handles uploading local files (base64 data URLs) to Kie.ai's temporary
 * file storage. Returns HTTP URLs that Kie.ai can access for image-to-video
 * and other input-image workflows.
 *
 * Uploaded files are temporary and automatically deleted after 3 days.
 *
 * API docs: https://kie.ai — Base64 File Upload
 * Endpoint:  POST https://kieai.redpandaai.co/api/file-base64-upload
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Kie_Upload
{

    /** @var string Kie.ai file upload endpoint for base64 data. */
    private const UPLOAD_ENDPOINT = 'https://kieai.redpandaai.co/api/file-base64-upload';

    /** @var int Upload request timeout in seconds. */
    private const TIMEOUT = 30;

    /**
     * Upload a base64-encoded image to Kie.ai temporary storage.
     *
     * Accepts a base64 data URL (e.g. "data:image/png;base64,iVBORw0...")
     * and uploads it to Kie.ai's file storage. Returns a public HTTP URL
     * that can be used as `inputUrl` for video/image generation.
     *
     * Usage:
     *   $http_url = PCM_Kie_Upload::upload_base64($api_key, $data_url);
     *   // $http_url = "https://files.kie.ai/..."
     *
     * @param string $api_key   Kie.ai API key (Bearer token).
     * @param string $data_url  Base64 data URL string.
     * @param string $filename  Optional filename for the uploaded file.
     *
     * @return string Public HTTP URL for the uploaded file.
     * @throws \RuntimeException On upload failure or invalid response.
     */
    public static function upload_base64(string $api_key, string $data_url, string $filename = ''): string
    {
        // Auto-generate filename from MIME type if not provided
        if (empty($filename)) {
            $filename = self::generate_filename($data_url);
        }

        $body = array(
            'base64Data' => $data_url,
            'fileName' => $filename,
            'uploadPath' => 'pcm-uploads',
        );

        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[PCM_Kie_Upload] Uploading base64 file (%s, ~%d KB)',
                $filename,
                (int)(strlen($data_url) * 0.75 / 1024)
            ));
        }

        $response = wp_remote_post(self::UPLOAD_ENDPOINT, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($body),
            'timeout' => self::TIMEOUT,
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException(
                'Kie.ai file upload failed: ' . $response->get_error_message()
                );
        }

        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300) {
            $msg = $data['msg'] ?? $data['message'] ?? 'Unknown error';
            throw new \RuntimeException(
                sprintf('Kie.ai file upload error %d: %s', $status, $msg)
                );
        }

        // Extract the download URL from the response.
        // Kie.ai returns: { data: { downloadUrl: "https://..." } }
        $download_url = $data['data']['downloadUrl']
            ?? $data['downloadUrl']
            ?? $data['data']['url']
            ?? $data['url']
            ?? null;

        if (empty($download_url)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[PCM_Kie_Upload] Unexpected response: ' . wp_json_encode($data));
            }
            throw new \RuntimeException(
                'Kie.ai upload succeeded but returned no download URL.'
                );
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[PCM_Kie_Upload] Upload OK → %s', $download_url));
        }

        return $download_url;
    }

    /**
     * Check if a string is a base64 data URL (not an HTTP URL).
     *
     * @param string $url The URL or data string to check.
     * @return bool True if this is a base64 data URL that needs uploading.
     */
    public static function is_base64_data_url(string $url): bool
    {
        return str_starts_with($url, 'data:');
    }

    /**
     * Resolve an array of input URLs, uploading any base64 entries.
     *
     * Iterates through inputUrls and converts any base64 data URLs to HTTP
     * URLs via Kie.ai upload. Already-HTTP URLs are passed through unchanged.
     *
     * @param string $api_key    Kie.ai API key.
     * @param array  $input_urls Array of URL strings (HTTP or base64).
     *
     * @return array Array of HTTP URLs (all base64 entries resolved).
     */
    public static function resolve_input_urls(string $api_key, array $input_urls): array
    {
        $resolved = array();

        foreach ($input_urls as $url) {
            // Step 1: Resolve local/private URLs → base64 data URLs
            // This ensures external APIs can access the image data.
            $url = PCM_Input_Resolver::ensure_accessible($url);

            if (self::is_base64_data_url($url)) {
                // Step 2: Upload base64 to Kie.ai CDN → get public HTTP URL
                $resolved[] = self::upload_base64($api_key, $url);
            }
            else {
                // Already a public HTTP URL — pass through
                $resolved[] = $url;
            }
        }

        return $resolved;
    }

    /**
     * Generate a descriptive filename from a base64 data URL's MIME type.
     *
     * @param string $data_url Base64 data URL.
     * @return string Generated filename (e.g. "pcm-input-67bc3a1f.png").
     */
    private static function generate_filename(string $data_url): string
    {
        // Extract MIME type: data:image/png;base64,... → image/png
        $extension = 'png';
        if (preg_match('/^data:image\/(\w+);/', $data_url, $matches)) {
            $extension = $matches[1];
            // Normalize jpeg variants
            if ($extension === 'jpeg') {
                $extension = 'jpg';
            }
        }

        $short_id = substr(uniqid(), -8);
        return "pcm-input-{$short_id}.{$extension}";
    }
}
