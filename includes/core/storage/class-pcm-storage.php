<?php
/**
 * PCM Storage Adapter
 *
 * Replaces the Node.js S3/Forge storage proxy with WordPress Media Library.
 * All assets (generated images, scraped images, exported files) are stored
 * as WP attachments, providing:
 *   - Native media management (edit, crop, resize via WP)
 *   - Consistent URL generation via wp_get_attachment_url()
 *   - Automatic cleanup via wp_delete_attachment()
 *   - Thumbnail generation for images
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Storage
{

    /**
     * Custom taxonomy for organizing PCM attachments.
     *
     * @var string
     */
    const META_KEY = '_pcm_context';

    /**
     * Upload a file from a local path to the WP Media Library.
     *
     * @param string $file_path  Absolute path to the file on disk.
     * @param string $context    Context label (e.g. 'image-gen', 'scraper', 'brand-asset').
     * @param string $filename   Optional custom filename. Defaults to basename of $file_path.
     * @param bool   $generate_image_subsizes Whether WordPress should synchronously create
     *                                        thumbnails and other registered image sizes.
     *                                        Defaults to true for backward compatibility.
     *
     * @return array{id: int, url: string} Attachment ID and public URL.
     * @throws \RuntimeException On upload failure.
     */
    public static function upload(
        string $file_path,
        string $context = 'general',
        string $filename = '',
        bool $generate_image_subsizes = true
    ): array
    {
        // Require WP media handling functions.
        // NOTE: Each file is checked independently because download_external()
        // may have already loaded file.php (defining wp_handle_sideload),
        // causing this block to skip media.php and image.php entirely.
        if (!function_exists('wp_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Build the file array expected by wp_handle_sideload
        $file_array = array(
            'name' => $filename ?: basename($file_path),
            'tmp_name' => $file_path,
            'error' => 0,
            'size' => filesize($file_path),
        );

        // Sideload moves the file into wp-content/uploads/
        $upload = wp_handle_sideload($file_array, array('test_form' => false));

        if (isset($upload['error'])) {
            throw new \RuntimeException(
                sprintf('PCM Storage upload failed: %s', $upload['error'])
                );
        }

        // Create an attachment post for the uploaded file
        $attachment_data = array(
            'post_mime_type' => $upload['type'],
            'post_title' => sanitize_file_name(pathinfo($upload['file'], PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
        );

        $attachment_id = wp_insert_attachment($attachment_data, $upload['file']);

        if (is_wp_error($attachment_id)) {
            throw new \RuntimeException(
                sprintf('PCM Storage: failed to create attachment — %s', $attachment_id->get_error_message())
                );
        }

        // Inline editor images render from their original URL and never consume
        // Media Library derivatives. Their opt-in fast path records the same
        // core identity metadata without invoking the image-editor pipeline or
        // third-party attachment-metadata hooks. Every default call still uses
        // WordPress's complete generation flow.
        $metadata = $generate_image_subsizes
            ? wp_generate_attachment_metadata($attachment_id, $upload['file'])
            : self::build_original_image_metadata($upload['file']);
        wp_update_attachment_metadata($attachment_id, $metadata);

        // Tag with our context for easy querying
        update_post_meta($attachment_id, self::META_KEY, $context);

        return array(
            'id' => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
        );
    }

    /**
     * Build valid attachment metadata for an image whose original file is the
     * only rendition a consumer needs.
     *
     * This intentionally omits EXIF parsing, editor conversion and registered
     * subsizes. Canvas-produced inline PNGs carry no camera metadata, and their
     * editor documents reference the original attachment URL directly.
     *
     * @param string $file Absolute uploaded image path.
     *
     * @return array{width: int, height: int, file: string, filesize: int, sizes: array}
     * @throws \RuntimeException When the uploaded bytes are not a readable image.
     */
    private static function build_original_image_metadata(string $file): array
    {
        $image_size = wp_getimagesize($file);
        if (empty($image_size[0]) || empty($image_size[1])) {
            throw new \RuntimeException('PCM Storage: uploaded inline image is not readable.');
        }

        return array(
            'width' => (int) $image_size[0],
            'height' => (int) $image_size[1],
            'file' => _wp_relative_upload_path($file),
            'filesize' => (int) wp_filesize($file),
            'sizes' => array(),
        );
    }

    /**
     * Download an external URL and save to WP Media Library.
     *
     * Useful for scraped images, AI-generated images returned as URLs, etc.
     *
     * @param string $url      External URL to download.
     * @param string $context  Context label.
     * @param string $filename Optional override filename.
     * @param int    $timeout  Download timeout in seconds. Default 15 for images; use higher for videos.
     *
     * @return array{id: int, url: string}
     * @throws \RuntimeException On download or upload failure.
     */
    public static function download_external(string $url, string $context = 'external', string $filename = '', int $timeout = 15): array
    {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Download to a temp file with configurable timeout
        $tmp_file = download_url($url, $timeout);

        if (is_wp_error($tmp_file)) {
            throw new \RuntimeException(
                sprintf('PCM Storage: download failed for %s — %s', $url, $tmp_file->get_error_message())
                );
        }

        // Determine filename from URL if not provided
        if (empty($filename)) {
            $parsed = wp_parse_url($url);
            $basename = basename($parsed['path'] ?? 'file');
            // Strip query strings from filename
            $filename = preg_replace('/\?.*$/', '', $basename) ?: 'downloaded-file';
        }

        try {
            $result = self::upload($tmp_file, $context, $filename);
        }
        finally {
            // Clean up temp file if upload() didn't move it
            if (file_exists($tmp_file)) {
                @unlink($tmp_file); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }

        return $result;
    }

    /**
     * Get the public URL for an attachment.
     *
     * @param int $attachment_id WP attachment ID.
     *
     * @return string|null URL or null if not found.
     */
    public static function get_url(int $attachment_id): ?string
    {
        $url = wp_get_attachment_url($attachment_id);
        return $url ?: null;
    }

    /**
     * Delete an attachment and its files from disk.
     *
     * @param int  $attachment_id WP attachment ID.
     * @param bool $force_delete  Skip trash and permanently delete. Default true.
     *
     * @return bool True if deleted successfully.
     */
    public static function delete(int $attachment_id, bool $force_delete = true): bool
    {
        $result = wp_delete_attachment($attachment_id, $force_delete);
        return $result !== false && $result !== null;
    }

    /**
     * Get all PCM attachments for a given context.
     *
     * @param string $context Context filter (e.g. 'image-gen', 'scraper').
     * @param int    $limit   Max results. Default 100.
     *
     * @return array List of { id, url, filename, mime_type, date }.
     */
    public static function get_by_context(string $context, int $limit = 100): array
    {
        $query = new \WP_Query(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $limit,
            'meta_key' => self::META_KEY,
            'meta_value' => $context,
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        $results = array();
        foreach ($query->posts as $post) {
            $results[] = array(
                'id' => $post->ID,
                'url' => wp_get_attachment_url($post->ID),
                'filename' => basename(get_attached_file($post->ID)),
                'mime_type' => $post->post_mime_type,
                'date' => $post->post_date,
            );
        }

        return $results;
    }

    /**
     * Save raw binary data (e.g. from an API response) to Media Library.
     *
     * @param string $data       Raw binary content.
     * @param string $filename   Desired filename with extension.
     * @param string $mime_type  MIME type (e.g. 'image/png').
     * @param string $context    Context label.
     * @param bool   $generate_image_subsizes Whether WordPress should synchronously create
     *                                        registered image sizes. Defaults to true.
     *
     * @return array{id: int, url: string}
     */
    public static function save_data(
        string $data,
        string $filename,
        string $mime_type,
        string $context = 'api-response',
        bool $generate_image_subsizes = true
    ): array
    {
        // Ensure WP file functions are available (not auto-loaded in REST context)
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Write to a temp file, then upload
        $tmp = wp_tempnam($filename);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($tmp, $data);

        try {
            $result = self::upload($tmp, $context, $filename, $generate_image_subsizes);
        }
        finally {
            if (file_exists($tmp)) {
                @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }

        return $result;
    }
}
