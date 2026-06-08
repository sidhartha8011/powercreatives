<?php
/**
 * Brands Service — Business Logic Layer
 *
 * Contains brand management business logic: asset handling (upload, URL
 * download, website scraping), color management, bulk operations, formatting.
 *
 * Extracted from PCM_REST_Brands for testability and reusability.
 *
 * @package PowerCreatives
 * @since   1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Brands_Service
{

    // =========================================================================
    // DATA QUERIES
    // =========================================================================

    /**
     * Find a brand by website domain.
     *
     * @param int    $user_id PCM user ID.
     * @param string $website Website URL.
     *
     * @return object|null Brand row or null.
     */
    public function find_by_website(int $user_id, string $website): ?object
    {
        global $wpdb;

        $table = PCM_Schema::table('brands');
        $domain = wp_parse_url($website, PHP_URL_HOST) ?: $website;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE userId = %d AND website LIKE %s LIMIT 1",
            $user_id,
            '%' . $wpdb->esc_like($domain) . '%'
        ));
    }

    // =========================================================================
    // UNIFIED URL SCRAPING
    // =========================================================================

    /**
     * Scrape a URL and prepare brand identity data.
     *
     * Orchestrates:
     *   1. PCM_Website_Scraper::scrape() — HTML/CSS parsing (always works)
     *   2. Optional: PCM_Copy_Service::extract_business_info() — LLM enrichment
     *
     * Returns a merged result with both DOM-parsed and LLM-enriched data.
     *
     * @param string      $url   Website URL to scrape.
     * @param string|null $model LLM model ID for enrichment (null = skip LLM).
     * @return array Unified scrape result.
     * @throws \RuntimeException On fetch failure.
     */
    public function scrape_and_prepare(string $url, ?string $model = null): array
    {
        // 1. Always: HTML/CSS parsing (no LLM needed)
        $scraped = PCM_Website_Scraper::scrape($url);

        $result = array(
            'businessInfo' => array(
                'business_name' => $scraped['h1'] ?: $scraped['title'],
                'business_summary' => $scraped['description'],
                'website' => $scraped['url'],
            ),
            'images' => $scraped['images'],
            'colors' => $scraped['colors'],
        );

        // 2. Optional: LLM enrichment for deeper business info
        if (!empty($model)) {
            try {
                $copy_service = new PCM_Copy_Service();
                $llm_data = $copy_service->extract_business_info($url, $model);

                if (!empty($llm_data)) {
                    // LLM data overrides DOM-parsed data where present
                    if (!empty($llm_data['business_name'])) {
                        $result['businessInfo']['business_name'] = $llm_data['business_name'];
                    }
                    if (!empty($llm_data['niche'])) {
                        $result['businessInfo']['niche'] = $llm_data['niche'];
                    }
                    if (!empty($llm_data['location'])) {
                        $result['businessInfo']['location'] = $llm_data['location'];
                    }
                    if (!empty($llm_data['phone'])) {
                        $result['businessInfo']['phone'] = $llm_data['phone'];
                    }
                    if (!empty($llm_data['business_summary'])) {
                        $result['businessInfo']['business_summary'] = $llm_data['business_summary'];
                    }
                    if (!empty($llm_data['language'])) {
                        $result['businessInfo']['language'] = $llm_data['language'];
                    }

                    // Merge LLM colors with CSS-parsed colors (LLM first, then CSS)
                    if (!empty($llm_data['brand_colors']) && is_array($llm_data['brand_colors'])) {
                        $merged_colors = $llm_data['brand_colors'];
                        foreach ($result['colors'] as $css_color) {
                            $is_dup = false;
                            foreach ($merged_colors as $existing) {
                                if (PCM_Image_Utils::colors_are_similar($css_color, $existing, 30)) {
                                    $is_dup = true;
                                    break;
                                }
                            }
                            if (!$is_dup) {
                                $merged_colors[] = $css_color;
                            }
                        }
                        $result['colors'] = $merged_colors;
                    }
                }
            }
            catch (\Throwable $e) {
                error_log('[PCM] Brand scrape LLM failed: ' . $e->getMessage());
                throw new \RuntimeException('LLM enrichment failed: ' . $e->getMessage(), 0, $e);
            }
        }

        // Debug: log what is returned to the frontend
        error_log('[PCM] scrape_and_prepare: images=' . count($result['images']) . ' | colors=' . count($result['colors']) . ' | url=' . $url);

        return $result;
    }

    // =========================================================================
    // BULK OPERATIONS
    // =========================================================================

    /**
     * Duplicate a brand, copying all data except generating new ID.
     *
     * The duplicate gets a cleared domain/website so it doesn't collide
     * with the UNIQUE constraint on domain. The user can then update
     * the website on the copy to differentiate it.
     *
     * @param object $source  Source brand DB row.
     * @param int    $user_id PCM user ID.
     *
     * @return int|false New brand ID or false on failure.
     */
    public function duplicate_brand(object $source, int $user_id): int|false
    {
        return PCM_DB::create_brand(array(
            'userId' => $user_id,
            'name' => $source->name . ' (copy)',
            'website' => '',
            'domain' => null, // Clear to avoid UNIQUE constraint collision
            'niche' => $source->niche ?? '',
            'location' => $source->location ?? '',
            'phone' => $source->phone ?? '',
            'businessSummary' => $source->businessSummary ?? '',
            'language' => $source->language ?? '',
            'description' => $source->description ?? '',
            'tonOfVoice' => $source->tonOfVoice ?? '',
            'colors' => $source->colors ?? '[]',
            'fonts' => $source->fonts ?? '[]',
            'assets' => $source->assets ?? '[]',
        ));
    }

    // =========================================================================
    // ASSET MANAGEMENT
    // =========================================================================

    /**
     * Handle file upload and create a brand asset entry.
     *
     * Uses WordPress Media Library (wp_handle_upload).
     *
     * @param array  $file    $_FILES entry from request.
     * @param int    $brand_id Brand ID.
     * @param int    $user_id  PCM user ID.
     * @param object $brand    Brand DB row (for existing assets/colors).
     * @param string $role     Required role: 'logo' | 'certification' | 'reference'.
     *
     * @return array Asset data { fileKey, url, mimeType, source, addedAt, role }
     * @throws \InvalidArgumentException If role is empty or not in the allowed set.
     * @throws \RuntimeException On upload failure.
     */
    public function upload_asset(array $file, int $brand_id, int $user_id, object $brand, string $role): array
    {
        $this->assert_valid_role($role);

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload = wp_handle_upload($file, array('test_form' => false));

        if (isset($upload['error'])) {
            throw new \RuntimeException('Upload failed: ' . $upload['error']);
        }

        $upload_url  = $upload['url'];
        $upload_type = $upload['type'];
        $upload_file = $upload['file'];

        // SVG → PNG rasterization:
        // AI image generation models cannot process SVG vector files.
        // Convert at upload time so all downstream consumers get PNG.
        if (PCM_Image_Utils::is_svg($upload_type)) {
            $rasterized = PCM_Image_Utils::rasterize_svg_to_png($upload_file);
            if ($rasterized) {
                $upload_file = $rasterized['path'];
                $upload_type = $rasterized['mime'];
                // Rebuild the URL: same directory, new filename
                $upload_url = dirname($upload_url) . '/' . $rasterized['url_filename'];
            }
        }

        $asset = $this->create_asset_entry($brand_id, $upload_url, $upload_type, 'upload', $role);

        // Append to brand assets
        $this->append_asset($brand_id, $user_id, $brand, $asset);

        // Extract dominant colors from the uploaded image.
        // Return them in the response so the frontend can show a
        // toast notification asking the user to approve/dismiss.
        $extracted = PCM_Image_Utils::extract_dominant_colors($upload_file, 5);
        $existing = json_decode($brand->colors ?? '[]', true) ?: array();

        // Filter out colors that already exist or are too similar
        $new_colors = PCM_Image_Utils::filter_new_colors($extracted, $existing);

        // Include the newly discovered colors in the response
        $asset['extractedColors'] = $new_colors;

        return $asset;
    }

    /**
     * Download an image from URL and add as brand asset.
     *
     * @param string $url       Image URL to download.
     * @param int    $brand_id  Brand ID.
     * @param int    $user_id   PCM user ID.
     * @param object $brand     Brand DB row.
     * @param string $role      Required role: 'logo' | 'certification' | 'reference'.
     *
     * @return array Asset data.
     * @throws \InvalidArgumentException If role is empty or not in the allowed set.
     * @throws \RuntimeException On download or save failure.
     */
    public function add_asset_from_url(string $url, int $brand_id, int $user_id, object $brand, string $role): array
    {
        $this->assert_valid_role($role);

        $response = wp_remote_get($url, array('timeout' => 30));
        if (is_wp_error($response)) {
            throw new \RuntimeException('Failed to download image: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $mime_type = wp_remote_retrieve_header($response, 'content-type');

        if (empty($body)) {
            throw new \RuntimeException('Downloaded image is empty.');
        }

        // Save to uploads directory
        $upload_dir = wp_upload_dir();
        $file_name = 'pcm-brand-' . wp_generate_uuid4() . $this->mime_to_ext($mime_type);
        $file_path = $upload_dir['path'] . '/' . $file_name;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($file_path, $body);

        // SVG → PNG rasterization:
        // AI image generation models (Flux, DALL-E, etc.) cannot process SVG vector
        // files. Convert to PNG at storage time so every downstream consumer gets
        // a rasterized image automatically — no module-level workarounds needed.
        if (PCM_Image_Utils::is_svg($mime_type)) {
            $rasterized = PCM_Image_Utils::rasterize_svg_to_png($file_path, $upload_dir['path']);
            if ($rasterized) {
                $file_name = $rasterized['url_filename'];
                $file_path = $rasterized['path'];
                $mime_type = $rasterized['mime'];
            }
            // If rasterization fails, we keep the original SVG — better than nothing.
        }

        $asset = $this->create_asset_entry($brand_id, $upload_dir['url'] . '/' . $file_name, $mime_type, 'url_fetch', $role);

        // Append to brand assets
        $this->append_asset($brand_id, $user_id, $brand, $asset);

        // Extract dominant colors from the downloaded image.
        // Return them in the response so the frontend can show a
        // toast notification asking the user to approve/dismiss.
        $extracted = PCM_Image_Utils::extract_dominant_colors($file_path, 5);
        $existing = json_decode($brand->colors ?? '[]', true) ?: array();

        // Filter out colors that already exist or are too similar
        $new_colors = PCM_Image_Utils::filter_new_colors($extracted, $existing);

        // Include the newly discovered colors in the response
        $asset['extractedColors'] = $new_colors;

        return $asset;
    }

    /**
     * Fetch brand-related images from a website (OG image, favicon, apple-touch-icon).
     *
     * @param string $url Website URL to scrape.
     *
     * @return array Array of image URLs found on the page.
     * @throws \RuntimeException On fetch failure.
     */
    public function fetch_website_assets(string $url): array
    {
        $response = wp_remote_get($url, array('timeout' => 15));
        if (is_wp_error($response)) {
            throw new \RuntimeException('Failed to fetch website: ' . $response->get_error_message());
        }

        $html = wp_remote_retrieve_body($response);
        $images = array();

        // Open Graph image
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) {
            $images[] = $m[1];
        }

        // Favicon
        if (preg_match('/<link[^>]+rel=["\'](?:icon|shortcut icon)["\'][^>]+href=["\']([^"\']+)["\']/', $html, $m)) {
            $favicon = $m[1];
            // Resolve relative URLs
            if (!str_starts_with($favicon, 'http')) {
                $parsed = wp_parse_url($url);
                $base = $parsed['scheme'] . '://' . $parsed['host'];
                $favicon = $base . '/' . ltrim($favicon, '/');
            }
            $images[] = $favicon;
        }

        // Apple touch icon
        if (preg_match('/<link[^>]+rel=["\']apple-touch-icon["\'][^>]+href=["\']([^"\']+)["\']/', $html, $m)) {
            $images[] = $m[1];
        }

        return array_values(array_unique($images));
    }

    /**
     * Remove an asset by fileKey from a brand's asset list.
     *
     * @param int    $brand_id Brand ID.
     * @param int    $user_id  PCM user ID.
     * @param object $brand    Brand DB row.
     * @param string $file_key Asset file key to remove.
     *
     * @return void
     */
    public function remove_asset(int $brand_id, int $user_id, object $brand, string $file_key): void
    {
        $assets = json_decode($brand->assets ?? '[]', true) ?: array();
        $filtered = array_values(array_filter($assets, fn($a) => $a['fileKey'] !== $file_key));

        PCM_DB::update_brand($brand_id, $user_id, array(
            'assets' => wp_json_encode($filtered),
        ));
    }

    /**
     * Reorder brand assets based on a provided fileKey order.
     *
     * @param int    $brand_id  Brand ID.
     * @param int    $user_id   PCM user ID.
     * @param object $brand     Brand DB row.
     * @param array  $file_keys Ordered array of file keys.
     *
     * @return void
     */
    public function reorder_assets(int $brand_id, int $user_id, object $brand, array $file_keys): void
    {
        $assets = json_decode($brand->assets ?? '[]', true) ?: array();

        // Build a lookup map
        $lookup = array();
        foreach ($assets as $asset) {
            $lookup[$asset['fileKey']] = $asset;
        }

        // Rebuild in new order
        $reordered = array();
        foreach ($file_keys as $key) {
            if (isset($lookup[$key])) {
                $reordered[] = $lookup[$key];
            }
        }

        PCM_DB::update_brand($brand_id, $user_id, array(
            'assets' => wp_json_encode($reordered),
        ));
    }

    /**
     * Promote an asset to 'logo' role, demoting any existing logo to 'reference'.
     *
     * This is an atomic role-swap operation that replaces the old position-based
     * approach (reorder to index 0). The role field is the single source of truth
     * for determining which asset is the brand logo.
     *
     * @param int    $brand_id Brand ID.
     * @param int    $user_id  PCM user ID.
     * @param object $brand    Brand DB row.
     * @param string $file_key fileKey of the asset to promote to 'logo'.
     */
    public function set_asset_as_logo(int $brand_id, int $user_id, object $brand, string $file_key): void
    {
        $assets = json_decode($brand->assets ?? '[]', true) ?: array();

        foreach ($assets as &$asset) {
            if ($asset['fileKey'] === $file_key) {
                // Promote target to logo
                $asset['role'] = 'logo';
            } elseif (($asset['role'] ?? '') === 'logo') {
                // Demote previous logo to reference
                $asset['role'] = 'reference';
            }
        }
        unset($asset);

        PCM_DB::update_brand($brand_id, $user_id, array(
            'assets' => wp_json_encode($assets),
        ));
    }

    // =========================================================================
    // COLOR MANAGEMENT
    // =========================================================================

    /**
     * Validate and filter hex color values.
     *
     * @param array $colors Array of potential hex color strings.
     *
     * @return array Validated hex color strings (format: #RRGGBB).
     */
    public function validate_colors(array $colors): array
    {
        $validated = array();
        foreach ($colors as $color) {
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $validated[] = $color;
            }
        }
        return $validated;
    }

    // =========================================================================
    // FORMATTING
    // =========================================================================

    /**
     * Format a brand DB row for JSON API output.
     *
     * @param object $brand Raw DB row.
     *
     * @return array Formatted brand data.
     */
    public function format_brand(object $brand): array
    {
        return array(
            'id' => (int)$brand->id,
            'name' => $brand->name,
            'website' => $brand->website ?? '',
            'domain' => $brand->domain ?? null,
            'niche' => $brand->niche ?? '',
            'location' => $brand->location ?? '',
            'phone' => $brand->phone ?? '',
            'clientEmail' => $brand->clientEmail ?? '',
            'businessSummary' => $brand->businessSummary ?? '',
            'language' => $brand->language ?? '',
            'description' => $brand->description ?? '',
            'tonOfVoice' => $brand->tonOfVoice ?? '',
            'colors' => json_decode($brand->colors ?? '[]', true) ?: array(),
            'fonts' => json_decode($brand->fonts ?? '[]', true) ?: array(),
            'assets' => json_decode($brand->assets ?? '[]', true) ?: array(),
            'scrapedAt' => $brand->scrapedAt ?? null,
            'createdAt' => $brand->createdAt,
            'updatedAt' => $brand->updatedAt,
        );
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Create an asset entry structure.
     *
     * @param int    $brand_id  Brand ID.
     * @param string $url       Asset URL.
     * @param string $mime_type MIME type.
     * @param string $source    Source type (upload, url_fetch).
     * @param string $role      Required role: 'logo' | 'certification' | 'reference'.
     *                          Callers must validate via assert_valid_role() before this point.
     *
     * @return array Asset entry { fileKey, url, mimeType, source, addedAt, role }
     */
    private function create_asset_entry(int $brand_id, string $url, string $mime_type, string $source, string $role): array
    {
        return array(
            'fileKey' => 'brand_' . $brand_id . '_' . wp_generate_uuid4(),
            'url' => $url,
            'mimeType' => $mime_type,
            'source' => $source,
            'addedAt' => current_time('c'),
            'role' => $role,
        );
    }

    /**
     * Allowed brand-asset roles. Extend here when adding new types
     * (e.g. 'font', 'video') — keep in sync with shared/brandTypes.ts.
     */
    private const ALLOWED_ASSET_ROLES = array('logo', 'certification', 'reference');

    /**
     * Validate that the provided role is non-empty and in the allowed set.
     *
     * Throws InvalidArgumentException so REST controllers convert it to a
     * 400 response — caller's contract violation, not a server error.
     *
     * @param string $role Role to validate.
     * @return void
     * @throws \InvalidArgumentException If role is empty or unknown.
     */
    private function assert_valid_role(string $role): void
    {
        if ($role === '') {
            throw new \InvalidArgumentException('Asset role is required.');
        }
        if (!in_array($role, self::ALLOWED_ASSET_ROLES, true)) {
            throw new \InvalidArgumentException(
                'Invalid asset role "' . $role . '". Allowed: ' . implode(', ', self::ALLOWED_ASSET_ROLES) . '.'
            );
        }
    }

    /**
     * Append an asset to a brand's asset list and persist.
     *
     * @param int    $brand_id Brand ID.
     * @param int    $user_id  PCM user ID.
     * @param object $brand    Brand DB row.
     * @param array  $asset    Asset entry to append.
     *
     * @return void
     */
    private function append_asset(int $brand_id, int $user_id, object $brand, array $asset): void
    {
        $existing_assets = json_decode($brand->assets ?? '[]', true) ?: array();
        $existing_assets[] = $asset;

        PCM_DB::update_brand($brand_id, $user_id, array(
            'assets' => wp_json_encode($existing_assets),
        ));
    }

    /**
     * Map MIME type to file extension.
     *
     * @param string $mime MIME type.
     *
     * @return string File extension with leading dot.
     */
    private function mime_to_ext(string $mime): string
    {
        $map = array(
            'image/png' => '.png',
            'image/jpeg' => '.jpg',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            'image/svg+xml' => '.svg',
        );

        return $map[$mime] ?? '.png';
    }
}
