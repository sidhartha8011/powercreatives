<?php
/**
 * Input URL Resolver
 *
 * Shared utility for resolving input image/video URLs into formats
 * that external AI providers can access. Handles three cases:
 *
 * 1. Public HTTP(S) URLs → passed through unchanged
 * 2. Local WordPress URLs → downloaded via wp_remote_get → base64 data URL
 * 3. Base64 data URLs → passed through unchanged
 *
 * This enables all providers (Kie.ai, Fal.ai, Google, etc.) to receive
 * accessible image data regardless of whether the source is a local
 * WordPress media library file or a public URL.
 *
 * Usage:
 *   // Single URL
 *   $accessible = PCM_Input_Resolver::ensure_accessible($local_url);
 *
 *   // Array of URLs
 *   $resolved = PCM_Input_Resolver::resolve_all($input_urls);
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Input_Resolver
{

    /** @var int Download timeout for local images (seconds). */
    private const DOWNLOAD_TIMEOUT = 15;

    /** @var int Maximum file size to download and convert (bytes). 10 MB. */
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * Ensure a URL is accessible by external APIs.
     *
     * - Public HTTP(S) URLs pass through unchanged.
     * - Local WordPress URLs are downloaded and converted to base64 data URLs.
     * - Base64 data URLs pass through unchanged.
     *
     * @param string $url Input URL (HTTP, local HTTP, or base64 data URL).
     * @return string Accessible URL (public HTTP or base64 data URL).
     * @throws \RuntimeException If the local image cannot be downloaded.
     */
    public static function ensure_accessible(string $url): string
    {
        // Already a base64 data URL — no resolution needed
        if (self::is_base64_data_url($url)) {
            return $url;
        }

        // Not an HTTP(S) URL — return as-is (safety fallback)
        if (!self::is_http_url($url)) {
            return $url;
        }

        // Local WordPress URL — download and convert to base64
        if (self::is_local_url($url)) {
            return self::download_as_base64($url);
        }

        // Public HTTP(S) URL — pass through unchanged
        return $url;
    }

    /**
     * Resolve an array of input URLs, converting any local URLs to base64.
     *
     * Convenience method for batch resolution. Each URL is processed
     * independently — one failure does not block others.
     *
     * @param array $urls Array of URL strings.
     * @return array Array of resolved URLs (public HTTP or base64).
     * @throws \RuntimeException If any local download fails.
     */
    public static function resolve_all(array $urls): array
    {
        $resolved = array();

        foreach ($urls as $url) {
            $resolved[] = self::ensure_accessible($url);
        }

        return $resolved;
    }

    /**
     * Check if a string is a base64 data URL.
     *
     * @param string $url The URL or data string to check.
     * @return bool True if this is a base64 data URL.
     */
    public static function is_base64_data_url(string $url): bool
    {
        return str_starts_with($url, 'data:');
    }

    /**
     * Check if a string is an HTTP or HTTPS URL.
     *
     * @param string $url The URL to check.
     * @return bool True if this starts with http:// or https://.
     */
    public static function is_http_url(string $url): bool
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    /**
     * Check if a URL points to the local WordPress site.
     *
     * Compares the URL's host against the WordPress site URL.
     * This catches URLs like http://powercreativesv2.local/wp-content/uploads/...
     * that external APIs cannot reach.
     *
     * @param string $url The URL to check.
     * @return bool True if the URL is on the local WordPress domain.
     */
    public static function is_local_url(string $url): bool
    {
        $site_host = wp_parse_url(site_url(), PHP_URL_HOST);
        $url_host = wp_parse_url($url, PHP_URL_HOST);

        if (empty($site_host) || empty($url_host)) {
            return false;
        }

        // Exact match — same domain
        if (strtolower($url_host) === strtolower($site_host)) {
            return true;
        }

        // Also catch common local development patterns
        $local_patterns = array(
            'localhost',
            '127.0.0.1',
            '::1',
        );

        // If site is on a local pattern, any URL to that same domain is local
        foreach ($local_patterns as $pattern) {
            if (str_contains(strtolower($url_host), $pattern)) {
                return true;
            }
        }

        // Catch .local TLD (Local by Flywheel, MAMP, etc.)
        if (str_ends_with(strtolower($url_host), '.local')) {
            return true;
        }

        return false;
    }

    /**
     * Download a local image and convert it to a base64 data URL.
     *
     * Uses WordPress HTTP API (wp_remote_get) which can make self-requests
     * since it runs on the same server. The response body is encoded as
     * a base64 data URL with the correct MIME type.
     *
     * @param string $url Local WordPress image URL.
     * @return string Base64 data URL (e.g. "data:image/png;base64,iVBORw0...").
     * @throws \RuntimeException If download fails or image is too large.
     */
    public static function download_as_base64(string $url): string
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[PCM_Input_Resolver] Downloading local image: %s',
                $url
            ));
        }

        // Try to read from local filesystem first (faster, no HTTP overhead)
        $local_path = self::url_to_local_path($url);
        if ($local_path && file_exists($local_path)) {
            return self::file_to_base64($local_path);
        }

        // Fallback: HTTP self-request (works for non-standard upload directories)
        $response = wp_remote_get($url, array(
            'timeout' => self::DOWNLOAD_TIMEOUT,
            'sslverify' => false, // Local self-requests may use self-signed certs
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException(sprintf(
                'Failed to download local image %s: %s',
                $url,
                $response->get_error_message()
            ));
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf(
                'Failed to download local image %s: HTTP %d',
                $url,
                $status
            ));
        }

        $body = wp_remote_retrieve_body($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');

        if (empty($body)) {
            throw new \RuntimeException(sprintf(
                'Downloaded empty body from local image: %s',
                $url
            ));
        }

        // Size check — prevent base64-encoding enormous files
        if (strlen($body) > self::MAX_FILE_SIZE) {
            throw new \RuntimeException(sprintf(
                'Local image too large (%d MB, max %d MB): %s',
                (int)(strlen($body) / 1024 / 1024),
                (int)(self::MAX_FILE_SIZE / 1024 / 1024),
                $url
            ));
        }

        // Clean content-type (remove charset etc.)
        $mime = 'image/png'; // Safe default
        if (!empty($content_type)) {
            $mime = explode(';', $content_type)[0];
            $mime = trim($mime);
        }

        $base64 = base64_encode($body);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[PCM_Input_Resolver] Converted local image → base64 (%s, ~%d KB)',
                $mime,
                (int)(strlen($base64) * 0.75 / 1024)
            ));
        }

        return "data:{$mime};base64,{$base64}";
    }

    /**
     * Convert a WordPress URL to a local filesystem path.
     *
     * Maps upload directory URLs to their corresponding local paths
     * for direct file reading (faster than HTTP self-request).
     *
     * @param string $url WordPress URL.
     * @return string|null Local filesystem path, or null if not mappable.
     */
    private static function url_to_local_path(string $url): ?string
    {
        $upload_dir = wp_get_upload_dir();
        $upload_url = $upload_dir['baseurl'] ?? '';
        $upload_path = $upload_dir['basedir'] ?? '';

        if (empty($upload_url) || empty($upload_path)) {
            return null;
        }

        // Check if the URL is within the uploads directory
        if (str_starts_with($url, $upload_url)) {
            $relative = substr($url, strlen($upload_url));
            $local_path = $upload_path . $relative;

            // Normalize path separators for Windows
            $local_path = str_replace('/', DIRECTORY_SEPARATOR, $local_path);

            return $local_path;
        }

        return null;
    }

    /**
     * Read a local file and convert to base64 data URL.
     *
     * @param string $path Absolute filesystem path to the image.
     * @return string Base64 data URL.
     * @throws \RuntimeException If file is too large or unreadable.
     */
    private static function file_to_base64(string $path): string
    {
        $size = filesize($path);

        if ($size > self::MAX_FILE_SIZE) {
            throw new \RuntimeException(sprintf(
                'Local image too large (%d MB, max %d MB): %s',
                (int)($size / 1024 / 1024),
                (int)(self::MAX_FILE_SIZE / 1024 / 1024),
                $path
            ));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(sprintf(
                'Failed to read local image: %s',
                $path
            ));
        }

        // Detect MIME type from file extension
        $mime = wp_check_filetype(basename($path))['type'] ?? 'image/png';

        $base64 = base64_encode($content);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[PCM_Input_Resolver] Read local file → base64 (%s, ~%d KB)',
                $mime,
                (int)(strlen($base64) * 0.75 / 1024)
            ));
        }

        return "data:{$mime};base64,{$base64}";
    }
}
