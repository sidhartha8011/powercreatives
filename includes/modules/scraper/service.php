<?php
/**
 * Scraper Service — Business Logic Layer
 *
 * Contains all scraping, HTML parsing, image analysis, and smart selection
 * logic. Extracted from the controller for testability and reusability.
 *
 * @package PowerCreatives
 * @since   1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Scraper_Service
{

    // =========================================================================
    // URL FETCHING
    // =========================================================================

    /**
     * Fetch HTML content from a URL.
     *
     * @param string $url       URL to fetch.
     * @param string $bot_name  User-agent purpose label.
     * @param int    $timeout   Request timeout in seconds.
     *
     * @return array { html: string, status_code: int }
     * @throws \RuntimeException On fetch failure or HTTP error.
     */
    public function fetch_html(string $url, string $bot_name = 'Image Scraper', int $timeout = 30): array
    {
        $response = wp_remote_get($url, array(
            'timeout' => $timeout,
            'user-agent' => "PowerCreatives/1.0 ({$bot_name})",
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException('Failed to fetch URL: ' . $response->get_error_message());
        }

        $html = wp_remote_retrieve_body($response);
        $status_code = (int)wp_remote_retrieve_response_code($response);

        if ($status_code >= 400) {
            throw new \RuntimeException("URL returned HTTP {$status_code}.");
        }

        return array('html' => $html, 'status_code' => $status_code);
    }

    // =========================================================================
    // IMAGE EXTRACTION FROM HTML
    // =========================================================================

    /**
     * Extract image URLs from HTML content.
     *
     * Finds img src, source srcset, and CSS background-image URLs.
     * Converts relative to absolute. Filters by allowed image extensions.
     *
     * @param string $html     HTML content.
     * @param string $base_url Base URL for resolving relative paths.
     *
     * @return array Array of absolute image URLs (deduplicated).
     */
    public function extract_images_from_html(string $html, string $base_url): array
    {
        $images = array();
        $seen = array();

        // Extract from <img> tags
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $src) {
                $absolute = $this->resolve_url($src, $base_url);
                if ($absolute && !isset($seen[$absolute])) {
                    $seen[$absolute] = true;
                    $images[] = $absolute;
                }
            }
        }

        // Extract from <source> tags (for picture elements)
        if (preg_match_all('/<source[^>]+srcset=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $srcset) {
                // srcset can have multiple URLs with size descriptors
                $parts = explode(',', $srcset);
                foreach ($parts as $part) {
                    $url_part = trim(explode(' ', trim($part))[0]);
                    $absolute = $this->resolve_url($url_part, $base_url);
                    if ($absolute && !isset($seen[$absolute])) {
                        $seen[$absolute] = true;
                        $images[] = $absolute;
                    }
                }
            }
        }

        // Extract from CSS background-image inline styles
        if (preg_match_all('/background-image\s*:\s*url\(["\']?([^"\'()]+)["\']?\)/i', $html, $matches)) {
            foreach ($matches[1] as $bg_url) {
                $absolute = $this->resolve_url($bg_url, $base_url);
                if ($absolute && !isset($seen[$absolute])) {
                    $seen[$absolute] = true;
                    $images[] = $absolute;
                }
            }
        }

        // Filter: only keep common image extensions
        $allowed_exts = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif');
        $filtered = array();

        foreach ($images as $img_url) {
            $path = wp_parse_url($img_url, PHP_URL_PATH);
            if ($path) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, $allowed_exts, true)) {
                    $filtered[] = $img_url;
                }
            }
        }

        return $filtered;
    }

    // =========================================================================
    // TEXT EXTRACTION FROM HTML
    // =========================================================================

    /**
     * Extract structured text content from HTML.
     *
     * Strips scripts and styles, then extracts headings, paragraphs,
     * page title, and meta description.
     *
     * @param string $html HTML content.
     * @param string $url  Source URL.
     *
     * @return array { url, title, description, headings, paragraphs, fullText }
     */
    public function extract_text_content(string $html, string $url): array
    {
        // Strip scripts, styles
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);

        // Extract headings
        $headings = array();
        if (preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $html, $matches)) {
            foreach ($matches[1] as $heading) {
                $clean = trim(wp_strip_all_tags($heading));
                if (!empty($clean)) {
                    $headings[] = $clean;
                }
            }
        }

        // Extract paragraphs
        $paragraphs = array();
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $matches)) {
            foreach ($matches[1] as $para) {
                $clean = trim(wp_strip_all_tags($para));
                if (strlen($clean) > 20) { // Filter out short garbage
                    $paragraphs[] = $clean;
                }
            }
        }

        $title = $this->extract_page_title($html);

        // Extract meta description
        $description = '';
        if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\'](.+?)["\']/is', $html, $match)) {
            $description = trim($match[1]);
        }

        return array(
            'url' => $url,
            'title' => $title,
            'description' => $description,
            'headings' => array_slice($headings, 0, 20),
            'paragraphs' => array_slice($paragraphs, 0, 30),
            'fullText' => implode("\n\n", array_merge($headings, $paragraphs)),
        );
    }

    // =========================================================================
    // COLLECTION DB OPERATIONS
    // =========================================================================

    /**
     * Create a scrape collection and store extracted images.
     *
     * @param int    $user_id     PCM user ID.
     * @param string $url         Source URL.
     * @param string $html        Raw HTML content.
     * @param int    $status_code HTTP status code from fetch.
     *
     * @return array { collectionId, imageCount, images }
     * @throws \RuntimeException On insert failure.
     */
    public function create_collection_with_images(int $user_id, string $url, string $html, int $status_code): array
    {
        global $wpdb;

        $images = $this->extract_images_from_html($html, $url);

        $col_table = PCM_Schema::prefix() . 'scraped_collections';

        // Insert collection with correct DB column names
        $wpdb->insert($col_table, array(
            'userId'     => $user_id,
            'sourceUrl'  => $url,
            'title'      => $this->extract_page_title($html) ?: wp_parse_url($url, PHP_URL_HOST),
            'status'     => 'ready',
            'imageCount' => count($images),
        ), array('%d', '%s', '%s', '%s', '%d'));

        $collection_id = $wpdb->insert_id;

        if (!$collection_id) {
            throw new \RuntimeException('Failed to create scrape collection.');
        }

        // Insert scraped images with correct DB column names
        $img_table = PCM_Schema::prefix() . 'scraped_images';
        $inserted = 0;

        foreach ($images as $i => $img_url) {
            $wpdb->insert($img_table, array(
                'collectionId' => $collection_id,
                'userId'       => $user_id,
                'originalUrl'  => $img_url,
            ), array('%d', '%d', '%s'));

            if ($wpdb->insert_id) {
                $inserted++;
            }
        }

        // Update collection with actual inserted count
        $wpdb->update(
            $col_table,
            array('imageCount' => $inserted),
            array('id' => $collection_id),
            array('%d'),
            array('%d')
        );

        return array(
            'collectionId' => $collection_id,
            'sourceUrl'    => $url,
            'domain'       => wp_parse_url($url, PHP_URL_HOST) ?: '',
            'status'       => 'ready',
            'imageCount'   => $inserted,
        );
    }

    /**
     * Get a collection with ownership check.
     *
     * @param int $collection_id Collection ID.
     * @param int $user_id       PCM user ID.
     *
     * @return object|null Collection row or null.
     */
    public function get_collection(int $collection_id, int $user_id): ?object
    {
        global $wpdb;

        $col_table = PCM_Schema::prefix() . 'scraped_collections';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$col_table} WHERE id = %d AND userId = %d",
            $collection_id,
            $user_id
        ));
    }

    /**
     * Get images for a collection, optionally filtered by specific IDs.
     *
     * @param int   $collection_id Collection ID.
     * @param array $image_ids     Optional specific image IDs.
     * @param int   $limit         Max images when no IDs specified.
     *
     * @return array Image DB rows.
     */
    public function get_images(int $collection_id, array $image_ids = array(), int $limit = 20): array
    {
        global $wpdb;

        $img_table = PCM_Schema::prefix() . 'scraped_images';

        if (!empty($image_ids)) {
            $placeholders = implode(',', array_fill(0, count($image_ids), '%d'));
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$img_table} WHERE collectionId = %d AND id IN ({$placeholders})",
                array_merge(array($collection_id), array_map('absint', $image_ids))
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$img_table} WHERE collectionId = %d ORDER BY id ASC LIMIT %d",
            $collection_id,
            $limit
        ));
    }

    // =========================================================================
    // AI ANALYSIS
    // =========================================================================

    /**
     * Analyze images using AI Vision model.
     *
     * Builds vision prompt, invokes LLM, stores analysis results.
     *
     * @param array  $images  Array of image DB row objects.
     * @param int    $user_id PCM user ID.
     * @param string $model   Model to use for analysis.
     *
     * @return array Analysis results from LLM.
     * @throws \RuntimeException On LLM failure.
     */
    public function analyze_images(array $images, int $user_id, string $model = 'gpt-4o-mini'): array
    {
        // Build vision prompt for batch analysis
        $image_contents = array();
        foreach ($images as $img) {
            $image_contents[] = array(
                'type' => 'image_url',
                'image_url' => array('url' => $img->url, 'detail' => 'low'),
            );
        }

        $image_contents[] = array(
            'type' => 'text',
            'text' => 'Analyze each image above. For each image, provide: '
            . '1) A short description (max 20 words) '
            . '2) Category (product, lifestyle, logo, icon, background, person, illustration, other) '
            . '3) Tags (3-5 relevant keywords) '
            . '4) Quality score (1-10, considering resolution, composition, relevance for ads). '
            . 'Return as JSON array with objects: {index, description, category, tags, quality}.',
        );

        $result = PCM_LLM::invoke_json(
            array(
                array(
                'role' => 'system',
                'content' => 'You are an expert image analyst for advertising. Analyze images and return structured JSON data.',
            ),
                array(
                'role' => 'user',
                'content' => $image_contents,
            ),
        ),
            array(
            'type' => 'object',
            'properties' => array(
                'analyses' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'index' => array('type' => 'integer'),
                            'description' => array('type' => 'string'),
                            'category' => array('type' => 'string'),
                            'tags' => array('type' => 'array', 'items' => array('type' => 'string')),
                            'quality' => array('type' => 'integer'),
                        ),
                    ),
                ),
            ),
        ),
            array(
            'user_id' => $user_id,
            'model' => $model,
        )
        );

        $analyses = $result['analyses'] ?? array();

        // Update image records with analysis results
        $this->store_analysis_results($images, $analyses);

        return $analyses;
    }

    /**
     * Persist analysis results to the database.
     *
     * @param array $images   Image DB rows (indexed by position).
     * @param array $analyses LLM analysis results.
     *
     * @return void
     */
    public function store_analysis_results(array $images, array $analyses): void
    {
        global $wpdb;

        $img_table = PCM_Schema::prefix() . 'scraped_images';

        foreach ($analyses as $analysis) {
            $idx = $analysis['index'] ?? -1;
            if ($idx >= 0 && $idx < count($images)) {
                $img = $images[$idx];
                // Write to individual DB columns — no JSON packing
                $wpdb->update(
                    $img_table,
                    array(
                        'aiTags'        => wp_json_encode($analysis['tags'] ?? array()),
                        'aiCategory'    => $analysis['category'] ?? 'other',
                        'aiDescription' => $analysis['description'] ?? '',
                        'aiScore'       => (float) ($analysis['quality'] ?? 5),
                    ),
                    array('id' => $img->id),
                    array('%s', '%s', '%s', '%f'),
                    array('%d')
                );
            }
        }
    }

    /**
     * AI-powered smart image selection for ad creatives.
     *
     * @param array  $images  Array of image DB rows.
     * @param array  $context Campaign context { brand?, audience?, adFormat?, objective? }.
     * @param int    $limit   How many images to select.
     * @param int    $user_id PCM user ID.
     * @param string $model   Model to use.
     *
     * @return array { selected: array, reasoning: string }
     * @throws \RuntimeException On LLM failure.
     */
    public function smart_select(array $images, array $context, int $limit, int $user_id, string $model = 'gpt-4o-mini'): array
    {
        // Build context description for the LLM
        $image_list = array();
        foreach ($images as $i => $img) {
            $tags = json_decode($img->aiTags ?? '[]', true);
            $image_list[] = sprintf(
                "Image %d: URL=%s | Description: %s | Category: %s | Tags: %s | Quality: %s",
                $i,
                $img->originalUrl,
                $img->aiDescription ?? 'N/A',
                $img->aiCategory ?? 'N/A',
                implode(', ', $tags ?: array()),
                $img->aiScore ?? 0
            );
        }

        $context_text = '';
        if (!empty($context['brand'])) {
            $context_text .= "Brand: {$context['brand']}. ";
        }
        if (!empty($context['audience'])) {
            $context_text .= "Target Audience: {$context['audience']}. ";
        }
        if (!empty($context['adFormat'])) {
            $context_text .= "Ad Format: {$context['adFormat']}. ";
        }
        if (!empty($context['objective'])) {
            $context_text .= "Objective: {$context['objective']}. ";
        }

        $result = PCM_LLM::invoke_json(
            array(
                array(
                'role' => 'system',
                'content' => 'You are an expert ad creative director. Select the best images for an advertising campaign.',
            ),
                array(
                'role' => 'user',
                'content' => "Given this context: {$context_text}\n\n"
                . "Available images:\n" . implode("\n", $image_list) . "\n\n"
                . "Select the {$limit} best images for this campaign. "
                . "Return JSON with: {selectedIndices: number[], reasoning: string}",
            ),
        ),
            array(
            'type' => 'object',
            'properties' => array(
                'selectedIndices' => array('type' => 'array', 'items' => array('type' => 'integer')),
                'reasoning' => array('type' => 'string'),
            ),
        ),
            array(
            'user_id' => $user_id,
            'model' => $model,
        )
        );

        $selected_indices = $result['selectedIndices'] ?? array();
        $selected_images = array();

        foreach ($selected_indices as $idx) {
            if (isset($images[$idx])) {
                $img = $images[$idx];
                $selected_images[] = array(
                    'id'            => $img->id,
                    'originalUrl'   => $img->originalUrl,
                    'aiDescription' => $img->aiDescription ?? '',
                    'aiCategory'    => $img->aiCategory ?? 'other',
                    'aiScore'       => $img->aiScore ?? 0,
                );
            }
        }

        return array(
            'selected' => $selected_images,
            'reasoning' => $result['reasoning'] ?? '',
        );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Resolve a potentially relative URL against a base URL.
     *
     * @param string $url      The URL to resolve.
     * @param string $base_url The base URL.
     *
     * @return string|null Absolute URL or null if invalid.
     */
    public function resolve_url(string $url, string $base_url): ?string
    {
        $url = trim($url);

        // Skip data URIs and empty
        if (empty($url) || str_starts_with($url, 'data:')) {
            return null;
        }

        // Already absolute
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        // Protocol-relative
        if (str_starts_with($url, '//')) {
            $scheme = wp_parse_url($base_url, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $url;
        }

        // Relative URL — resolve against base
        $parsed = wp_parse_url($base_url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        if (str_starts_with($url, '/')) {
            // Absolute path
            return "{$scheme}://{$host}{$url}";
        }

        // Relative path
        $base_path = $parsed['path'] ?? '/';
        $base_dir = rtrim(dirname($base_path), '/');

        return "{$scheme}://{$host}{$base_dir}/{$url}";
    }

    /**
     * Extract the page title from HTML.
     *
     * @param string $html HTML content.
     *
     * @return string Page title or empty string.
     */
    public function extract_page_title(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match)) {
            return trim(wp_strip_all_tags($match[1]));
        }
        return '';
    }
}
