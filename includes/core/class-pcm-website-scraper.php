<?php
/**
 * Website Scraper — Unified HTML/CSS extraction utility
 *
 * Pure PHP scraper that extracts brand identity data from any URL:
 *   - Page images with dimensions (sorted by resolution)
 *   - Brand colors (CSS custom properties, inline styles, style blocks)
 *   - All page images (img tags with src, filtered by size)
 *   - Business info text (title, meta description, h1)
 *
 * Uses DOMDocument + DOMXPath (PHP native) — zero external dependencies.
 * Does NOT require LLM — works entirely via HTML/CSS parsing.
 *
 * This utility is consumed by PCM_Brands_Service::scrape_and_prepare()
 * which optionally adds LLM-enriched business info on top.
 *
 * @package PowerCreatives
 * @since   1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Website_Scraper
{

    /**
     * Maximum number of images to return from a page.
     */
    private const MAX_IMAGES = 20;

    /**
     * HTTP timeout for fetching the webpage.
     */
    private const HTTP_TIMEOUT = 15;

    /**
     * Minimum image dimension (width or height) to include.
     * Filters out tracking pixels, spacers, and tiny icons.
     */
    private const MIN_IMAGE_SIZE = 30;

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    /**
     * Scrape a URL and extract all brand identity data.
     *
     * Returns an associative array with keys:
     *   - title:           string   Page <title> text
     *   - description:     string   Meta description content
     *   - h1:              string   First <h1> text
     *   - images:          array    Image objects with url, width, height (sorted by resolution)
     *   - colors:          array    Hex color strings extracted from CSS
     *   - url:             string   Normalized URL that was scraped
     *
     * @param string $url Website URL to scrape.
     * @return array Scraped data.
     * @throws \RuntimeException On HTTP fetch failure.
     */
    public static function scrape(string $url): array
    {
        // Normalize URL
        $url = self::normalize_url($url);

        // Fetch HTML
        $html = self::fetch_html($url);

        // Parse the base URL for resolving relative paths
        $parsed = wp_parse_url($url);
        $base_url = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

        // Suppress DOMDocument warnings for malformed HTML
        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        $xpath = new \DOMXPath($dom);

        libxml_clear_errors();

        // Extract all data categories
        $text_data = self::extract_text_data($dom, $xpath);
        $images = self::extract_images($dom, $xpath, $base_url);
        $colors = self::extract_colors($dom, $xpath, $html, $url);

        // Debug: log extraction results (controlled via WP_DEBUG_LOG)
        error_log('[PCM] Scraper: url=' . $url . ' | images=' . count($images) . ' | colors=' . count($colors));

        return array(
            'title'          => $text_data['title'],
            'description'    => $text_data['description'],
            'h1'             => $text_data['h1'],
            'images'         => $images,
            'colors'         => $colors,
            'url'            => $url,
        );
    }

    // =========================================================================
    // TEXT EXTRACTION
    // =========================================================================

    /**
     * Extract basic business text from the page.
     *
     * @param \DOMDocument $dom   Parsed DOM.
     * @param \DOMXPath    $xpath XPath query engine.
     * @return array { title, description, h1 }
     */
    private static function extract_text_data(\DOMDocument $dom, \DOMXPath $xpath): array
    {
        // <title>
        $title = '';
        $title_nodes = $dom->getElementsByTagName('title');
        if ($title_nodes->length > 0) {
            $title = trim($title_nodes->item(0)->textContent);
        }

        // <meta name="description">
        $description = '';
        $meta_nodes = $xpath->query('//meta[@name="description"]');
        if ($meta_nodes && $meta_nodes->length > 0) {
            $description = trim($meta_nodes->item(0)->getAttribute('content'));
        }

        // First <h1>
        $h1 = '';
        $h1_nodes = $dom->getElementsByTagName('h1');
        if ($h1_nodes->length > 0) {
            $h1 = trim($h1_nodes->item(0)->textContent);
        }

        return array(
            'title'       => $title,
            'description' => $description,
            'h1'          => $h1,
        );
    }

    // =========================================================================
    // IMAGE EXTRACTION
    // =========================================================================

    /**
     * Extract all page images (filtered for relevance).
     *
     * Filters out:
     *   - Data URIs
     *   - Tracking pixels (1x1)
     *   - Images with explicit tiny dimensions
     *   - SVG inline (data:image/svg+xml)
     *
     * @param \DOMDocument $dom      Parsed DOM.
     * @param \DOMXPath    $xpath    XPath query engine.
     * @param string       $base_url Base URL for resolving relative paths.
     * @return array Array of absolute image URLs (max MAX_IMAGES).
     */
    private static function extract_images(\DOMDocument $dom, \DOMXPath $xpath, string $base_url): array
    {
        $images = array();
        $img_nodes = $dom->getElementsByTagName('img');

        foreach ($img_nodes as $node) {
            // Try src first, then data-src (lazy loading)
            $src = $node->getAttribute('src');
            if (empty($src) || str_starts_with($src, 'data:')) {
                $src = $node->getAttribute('data-src');
            }

            if (empty($src) || str_starts_with($src, 'data:')) {
                continue;
            }

            // Filter by explicit dimensions if available
            $width = (int) $node->getAttribute('width');
            $height = (int) $node->getAttribute('height');
            if (($width > 0 && $width < self::MIN_IMAGE_SIZE) ||
                ($height > 0 && $height < self::MIN_IMAGE_SIZE)) {
                continue;
            }

            $resolved = self::resolve_url($src, $base_url);
            if (!empty($resolved)) {
                $images[] = $resolved;
            }

            if (count($images) >= self::MAX_IMAGES) {
                break;
            }
        }

        // Also check <source> inside <picture> elements
        $source_nodes = $xpath->query('//picture/source[@srcset]');
        if ($source_nodes) {
            foreach ($source_nodes as $node) {
                $srcset = $node->getAttribute('srcset');
                // Take the first URL from the srcset
                $first_url = self::parse_first_srcset_url($srcset);
                if (!empty($first_url)) {
                    $resolved = self::resolve_url($first_url, $base_url);
                    if (!empty($resolved)) {
                        $images[] = $resolved;
                    }
                }

                if (count($images) >= self::MAX_IMAGES) {
                    break;
                }
            }
        }

        return self::probe_and_sort_images(array_values(array_unique($images)));
    }

    /**
     * Probe image dimensions via Range-header requests, then sort by resolution.
     *
     * Each image is returned as { url, width, height }. SVGs and unreachable
     * images get width=0, height=0.
     *
     * @param array $urls Array of absolute image URLs.
     * @return array Array of { url: string, width: int, height: int } sorted by w×h DESC.
     */
    private static function probe_and_sort_images(array $urls): array
    {
        $results = array();

        foreach ($urls as $url) {
            $dims = PCM_Image_Utils::get_remote_image_dimensions($url);
            $results[] = array(
                'url'    => $url,
                'width'  => $dims['width'],
                'height' => $dims['height'],
            );
        }

        // Sort by resolution (width × height) descending — largest first
        usort($results, function ($a, $b) {
            return ($b['width'] * $b['height']) - ($a['width'] * $a['height']);
        });

        return $results;
    }

    // =========================================================================
    // COLOR EXTRACTION
    // =========================================================================

    /**
     * Extract brand colors from CSS within the page.
     *
     * Sources (in priority order):
     *   1. CSS custom properties (--brand-color, --primary, etc.)
     *   2. Inline style attributes on key elements (header, nav, buttons)
     *   3. <style> block declarations (background-color, color on body/header)
     *
     * @param \DOMDocument $dom   Parsed DOM.
     * @param \DOMXPath    $xpath XPath query engine.
     * @param string       $html  Raw HTML for regex-based style parsing.
     * @param string       $url   Website URL for fetching rendered screenshots.
     * @return array Array of unique hex color strings (e.g. ['#ff0000']).
     */
    private static function extract_colors(\DOMDocument $dom, \DOMXPath $xpath, string $html, string $url): array
    {
        $colors = array();

        // 1. Extract Rendered Colors (Favicon)
        // The Favicon is the ultimate Source of Truth for brand colors.
        $favicon_colors = self::extract_colors_from_images($url);
        if (!empty($favicon_colors)) {
            $colors = array_merge($colors, $favicon_colors);
        }

        // 2. CSS custom properties from <style> blocks (Strict Whitelist)
        // Match ONLY semantic variables like: --primary-color: #ff0000; or --brand: rgb(...)
        // explicitly ignoring utility frameworks like Tailwind (--tw) or Bootstrap (--bs).
        if (preg_match_all('/--(?:primary|brand|accent|main|theme)[\w-]*\s*:\s*(#[0-9a-fA-F]{3,8}|rgb\([^)]+\)|rgba\([^)]+\))\s*;/i', $html, $matches)) {
            foreach ($matches[1] as $color_value) {
                $hex = PCM_Image_Utils::css_color_to_hex($color_value);
                if ($hex) {
                    $colors[] = $hex;
                }
            }
        }

        // 2. Inline styles on structural elements (header, nav, footer, main buttons)
        $structural_queries = array(
            '//header',
            '//nav',
            '//footer',
            '//a[contains(@class, "btn") or contains(@class, "button")]',
            '//button',
        );

        foreach ($structural_queries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes) continue;

            foreach ($nodes as $node) {
                $style = $node->getAttribute('style');
                if (!empty($style)) {
                        $extracted = PCM_Image_Utils::extract_colors_from_inline_style($style);
                    $colors = array_merge($colors, $extracted);
                }
            }
        }

        // We no longer scan generic <style> blocks for 'background-color' or 'color',
        // because that regex blindly picks up all utility classes (e.g. Tailwind/Bootstrap
        // rainbow palettes). By removing it, we enforce "foolproof" heuristic extraction.

        // Deduplicate and filter noise colors (near-white, near-black, pure gray)
        $colors = array_unique($colors);
        $filtered = array();
        foreach ($colors as $color) {
            if (!PCM_Image_Utils::is_noise_color($color)) {
                $filtered[] = $color;
            }
        }

        // Return top colors (max 10), removing duplicates that are too similar
        return PCM_Image_Utils::deduplicate_similar_colors(array_values($filtered), 10);
    }

    /**
     * Fetch rendered colors from the brand's Favicon.
     *
     * @param string $url Website URL.
     * @return array Array of hex colors extracted from the favicon.
     */
    private static function extract_colors_from_images(string $url): array
    {
        $colors = array();

        // Google Favicon API (Highest priority, always returns a reliable image fast)
        $favicon_url = 'https://s2.googleusercontent.com/s2/favicons?domain=' . urlencode($url) . '&sz=128';
        $fav_resp = wp_remote_get($favicon_url, array('timeout' => 5));
        if (!is_wp_error($fav_resp) && wp_remote_retrieve_response_code($fav_resp) === 200) {
            $body = wp_remote_retrieve_body($fav_resp);
            if (!empty($body)) {
                $tmp = get_temp_dir() . 'pcm_fav_' . wp_generate_uuid4() . '.png';
                file_put_contents($tmp, $body);
                $fav_colors = PCM_Image_Utils::extract_dominant_colors($tmp, 5);
                $colors = array_merge($colors, $fav_colors);
                @unlink($tmp);
            }
        }

        return $colors;
    }

    // =========================================================================
    // URL HELPERS
    // =========================================================================

    /**
     * Normalize a URL — add https:// if missing, remove trailing slash.
     *
     * @param string $url Raw URL input.
     * @return string Normalized URL.
     */
    private static function normalize_url(string $url): string
    {
        $url = trim($url);
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }
        return rtrim($url, '/');
    }

    /**
     * Fetch raw HTML from a URL using WordPress HTTP API.
     *
     * @param string $url URL to fetch.
     * @return string HTML content.
     * @throws \RuntimeException On failure.
     */
    private static function fetch_html(string $url): string
    {
        $response = wp_remote_get($url, array(
            'timeout'    => self::HTTP_TIMEOUT,
            'user-agent' => 'PowerCreatives/1.0 (Brand Scraper)',
            'sslverify'  => false,
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException('Failed to fetch URL: ' . $response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status >= 400) {
            throw new \RuntimeException("HTTP {$status} error fetching URL.");
        }

        return wp_remote_retrieve_body($response);
    }

    /**
     * Resolve a potentially relative URL against a base URL.
     *
     * @param string $url      The URL to resolve (may be relative).
     * @param string $base_url The base URL (scheme + host).
     * @return string Absolute URL or empty string on failure.
     */
    private static function resolve_url(string $url, string $base_url): string
    {
        $url = trim($url);

        // Already absolute
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        // Protocol-relative (//cdn.example.com/...)
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        // Relative path
        if (str_starts_with($url, '/')) {
            return $base_url . $url;
        }

        // Relative without leading slash
        return $base_url . '/' . $url;
    }

    /**
     * Parse the first URL from a srcset attribute value.
     *
     * srcset format: "url 1x, url 2x" or "url 100w, url 200w"
     *
     * @param string $srcset Raw srcset value.
     * @return string First URL or empty string.
     */
    private static function parse_first_srcset_url(string $srcset): string
    {
        $parts = explode(',', $srcset);
        if (empty($parts)) return '';

        $first = trim($parts[0]);
        $tokens = preg_split('/\s+/', $first);

        return $tokens[0] ?? '';
    }

}
