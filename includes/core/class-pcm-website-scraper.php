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
        $business  = self::extract_business_data($dom, $xpath);
        $images = self::extract_images($dom, $xpath, $base_url);
        $colors = self::extract_colors($dom, $xpath, $html, $url);

        // Debug: log extraction results (controlled via WP_DEBUG_LOG)
        error_log('[PCM] Scraper: url=' . $url . ' | images=' . count($images) . ' | colors=' . count($colors)
            . ' | niche=' . ($business['niche'] !== '' ? 'y' : 'n')
            . ' | location=' . ($business['location'] !== '' ? 'y' : 'n')
            . ' | phone=' . ($business['phone'] !== '' ? 'y' : 'n'));

        return array(
            'title'          => $text_data['title'],
            'description'    => $text_data['description'],
            'h1'             => $text_data['h1'],
            'lang'           => $text_data['lang'],
            'niche'          => $business['niche'],
            'location'       => $business['location'],
            'phone'          => $business['phone'],
            'images'         => $images,
            'colors'         => $colors,
            'url'            => $url,
        );
    }

    // =========================================================================
    // BUSINESS DATA EXTRACTION (niche / location / phone, no AI)
    // =========================================================================

    /**
     * Niche, location and phone straight out of the markup.
     *
     * These three used to exist ONLY if an LLM returned them, so a missing key, a
     * retired model or a provider quirk left the fields blank on a page that
     * plainly states its address in the footer. Sites publish this in
     * machine-readable form; reading it costs nothing and cannot fail.
     *
     * Sources, best first:
     *   1. JSON-LD (schema.org) — `address`, `telephone`, and `@type`, which names
     *      the business category outright ("Dentist", "MovieTheater").
     *   2. Open Graph / business meta tags — og:locality, og:region, og:country-name.
     *   3. The <address> element, and a tel: link for the phone.
     *   4. <meta name="keywords"> first term as a last-resort niche hint.
     *
     * The LLM still overrides anything it returns — this is the floor, not a cap.
     *
     * @param \DOMDocument $dom   Parsed DOM.
     * @param \DOMXPath    $xpath XPath query engine.
     * @return array{niche: string, location: string, phone: string}
     */
    private static function extract_business_data(\DOMDocument $dom, \DOMXPath $xpath): array
    {
        $niche = '';
        $location = '';
        $phone = '';

        // ── 1. JSON-LD ──
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: array() as $node) {
            $data = json_decode(trim($node->textContent), true);
            if (!is_array($data)) {
                continue;
            }
            foreach (self::flatten_json_ld($data) as $entity) {
                if (!is_array($entity)) {
                    continue;
                }
                if ($niche === '') {
                    $niche = self::niche_from_json_ld($entity);
                }
                if ($location === '' && !empty($entity['address'])) {
                    $location = self::format_postal_address($entity['address']);
                }
                if ($phone === '' && !empty($entity['telephone']) && is_string($entity['telephone'])) {
                    $phone = trim($entity['telephone']);
                }
            }
        }

        // ── 2. Meta tags ──
        if ($location === '') {
            $parts = array();
            foreach (array('business:contact_data:locality', 'og:locality') as $p) {
                $v = self::meta_content($xpath, $p);
                if ($v !== '') { $parts[] = $v; break; }
            }
            foreach (array('business:contact_data:region', 'og:region') as $p) {
                $v = self::meta_content($xpath, $p);
                if ($v !== '') { $parts[] = $v; break; }
            }
            foreach (array('business:contact_data:country_name', 'og:country-name') as $p) {
                $v = self::meta_content($xpath, $p);
                if ($v !== '') { $parts[] = $v; break; }
            }
            $location = implode(', ', array_unique(array_filter($parts)));
        }
        if ($phone === '') {
            $phone = self::meta_content($xpath, 'business:contact_data:phone_number');
        }

        // ── 3. <address> and tel: links ──
        if ($location === '') {
            $nodes = $dom->getElementsByTagName('address');
            if ($nodes->length > 0) {
                // Collapse the whitespace a multi-line address block carries.
                $location = trim(preg_replace('/\s+/', ' ', $nodes->item(0)->textContent) ?? '');
            }
        }
        if ($phone === '') {
            $tel = $xpath->query('//a[starts-with(@href, "tel:")]/@href');
            if ($tel && $tel->length > 0) {
                $phone = trim(str_replace('tel:', '', rawurldecode($tel->item(0)->nodeValue)));
            }
        }

        // ── 4. Keywords, as a niche of last resort ──
        if ($niche === '') {
            $kw = self::meta_content($xpath, 'keywords');
            if ($kw !== '') {
                $first = trim(explode(',', $kw)[0]);
                // A keyword list often opens with the brand name; only take it when
                // it reads like a category rather than a single proper noun.
                if ($first !== '' && str_word_count($first) >= 2) {
                    $niche = $first;
                }
            }
        }

        // ── 5. The <title>, which almost every site uses to state its trade ──
        // "Film Production & Video Production UK | Creative Film Agency" describes
        // the business plainly, and plenty of sites carry no JSON-LD, no keywords
        // and no meta description at all — leaving Niche permanently blank for them.
        if ($niche === '') {
            $niche = self::niche_from_title($dom);
        }

        // Guard against a runaway <address> or keyword blob reaching a form field.
        return array(
            'niche'    => self::clip($niche, 100),
            'location' => self::clip($location, 200),
            'phone'    => self::clip($phone, 40),
        );
    }

    /**
     * A niche from the page <title>.
     *
     * Titles are conventionally "<what we do> | <Brand>" or "<Brand> | <what we
     * do>", so the descriptive half is usually sitting right there. This splits on
     * the usual separators, throws away the half that repeats the brand (matched
     * against the <h1>, the best available brand name), and keeps a segment only if
     * it reads like a category — 2 to 6 words, no sentence punctuation.
     *
     * The LAST qualifying segment wins: a trailing descriptor ("… | Creative Film
     * Agency") is a tighter category than a leading headline, which tends to carry
     * marketing words and place names.
     *
     * @param \DOMDocument $dom Parsed DOM.
     * @return string Niche, or '' when the title says nothing useful.
     */
    private static function niche_from_title(\DOMDocument $dom): string
    {
        $titles = $dom->getElementsByTagName('title');
        if ($titles->length === 0) {
            return '';
        }
        $title = trim(preg_replace('/\s+/', ' ', $titles->item(0)->textContent) ?? '');
        if ($title === '') {
            return '';
        }

        // The <h1> is the closest thing to a brand name available here.
        $brand = '';
        $h1s = $dom->getElementsByTagName('h1');
        if ($h1s->length > 0) {
            $brand = strtolower(trim(preg_replace('/\s+/', ' ', $h1s->item(0)->textContent) ?? ''));
        }

        $best = '';
        foreach (preg_split('/\s*[|\x{2013}\x{2014}\x{00B7}\x{2022}]\s*|\s+[-–—]\s+/u', $title) ?: array() as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            // A segment echoing the brand describes WHO, not WHAT.
            if ($brand !== '' && (str_contains(strtolower($segment), $brand) || str_contains($brand, strtolower($segment)))) {
                continue;
            }
            // Sentence punctuation means it is a tagline, not a category.
            if (preg_match('/[.!?,:;]/', $segment)) {
                continue;
            }
            $words = str_word_count($segment);
            if ($words < 2 || $words > 6) {
                continue;
            }
            $best = $segment;
        }

        return $best;
    }

    /**
     * Flatten a JSON-LD payload into a list of entities.
     *
     * A document may be a single object, a bare array, or an `@graph` wrapper, and
     * the useful entity is often nested inside — so all three shapes are walked.
     *
     * @param mixed $data  Decoded JSON-LD.
     * @param int   $depth Recursion guard.
     * @return array List of entity arrays.
     */
    private static function flatten_json_ld($data, int $depth = 0): array
    {
        if (!is_array($data) || $depth > 4) {
            return array();
        }
        $out = array();
        if (isset($data['@type']) || isset($data['address']) || isset($data['telephone'])) {
            $out[] = $data;
        }
        foreach (array('@graph', 'itemListElement', 'mainEntity', 'publisher', 'provider', 'author') as $key) {
            if (!empty($data[$key])) {
                $out = array_merge($out, self::flatten_json_ld($data[$key], $depth + 1));
            }
        }
        // A bare list of entities.
        if (array_keys($data) === range(0, count($data) - 1)) {
            foreach ($data as $item) {
                $out = array_merge($out, self::flatten_json_ld($item, $depth + 1));
            }
        }
        return $out;
    }

    /** Schema.org types that describe the SITE, not the business behind it. */
    private const GENERIC_LD_TYPES = array(
        'website', 'webpage', 'webside', 'organization', 'thing', 'article',
        'blogposting', 'breadcrumblist', 'itemlist', 'searchaction', 'person',
        'imageobject', 'sitenavigationelement', 'collectionpage', 'aboutpage',
        'contactpage', 'localbusiness', 'corporation',
    );

    /**
     * A readable niche from a JSON-LD entity.
     *
     * Prefers an explicit category field, then `@type` — but only when the type is
     * SPECIFIC. "Organization" or "WebSite" says nothing about the trade, so those
     * are skipped rather than written into the Niche box.
     *
     * @param array $entity JSON-LD entity.
     * @return string Niche, or '' when nothing specific is known.
     */
    private static function niche_from_json_ld(array $entity): string
    {
        foreach (array('knowsAbout', 'industry', 'category') as $key) {
            $v = $entity[$key] ?? null;
            if (is_array($v)) { $v = reset($v); }
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        $types = $entity['@type'] ?? '';
        foreach ((array) $types as $type) {
            if (!is_string($type) || $type === '') {
                continue;
            }
            if (in_array(strtolower($type), self::GENERIC_LD_TYPES, true)) {
                continue;
            }
            // "MovieTheater" / "HVACBusiness" → "Movie Theater" / "HVAC Business".
            $spaced = preg_replace('/(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $type);
            return trim($spaced ?? $type);
        }

        return '';
    }

    /**
     * Render a schema.org PostalAddress as one human line.
     *
     * @param mixed $address JSON-LD address (string or PostalAddress array).
     * @return string
     */
    private static function format_postal_address($address): string
    {
        if (is_string($address)) {
            return trim(preg_replace('/\s+/', ' ', $address) ?? '');
        }
        if (!is_array($address)) {
            return '';
        }
        // A site may attach several; the first is the primary one.
        if (isset($address[0]) && is_array($address[0])) {
            $address = $address[0];
        }
        $parts = array();
        foreach (array('streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry') as $key) {
            $v = $address[$key] ?? '';
            if (is_array($v)) {
                // addressCountry is sometimes a nested Country entity.
                $v = $v['name'] ?? '';
            }
            if (is_string($v) && trim($v) !== '') {
                $parts[] = trim($v);
            }
        }
        return implode(', ', array_unique($parts));
    }

    /**
     * Content of a <meta> tag, matched on either `property` or `name`.
     *
     * @param \DOMXPath $xpath XPath engine.
     * @param string    $key   Property/name to look up.
     * @return string
     */
    private static function meta_content(\DOMXPath $xpath, string $key): string
    {
        $nodes = $xpath->query(
            '//meta[@property="' . $key . '" or @name="' . $key . '"]/@content'
        );
        return ($nodes && $nodes->length > 0) ? trim($nodes->item(0)->nodeValue) : '';
    }

    /** Trim a scraped value to a sane length for a single form field. */
    private static function clip(string $value, int $max): string
    {
        $value = trim($value);
        return strlen($value) > $max ? rtrim(substr($value, 0, $max)) : $value;
    }

    // =========================================================================
    // TEXT EXTRACTION
    // =========================================================================

    /**
     * Extract basic business text from the page.
     *
     * @param \DOMDocument $dom   Parsed DOM.
     * @param \DOMXPath    $xpath XPath query engine.
     * @return array { title, description, h1, lang }
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

        // Page language — `<html lang>` first, then `<meta property="og:locale">`.
        // This is the site's own declaration, so it is both free and more reliable
        // than asking a model to guess from the copy. Reduced to the ISO 639-1
        // primary subtag ("sv-SE" -> "sv") to match what the LLM path returns and
        // what the brand's language field stores.
        $lang = '';
        $html_nodes = $dom->getElementsByTagName('html');
        if ($html_nodes->length > 0) {
            $lang = trim($html_nodes->item(0)->getAttribute('lang'));
        }
        if ($lang === '') {
            $locale_nodes = $xpath->query('//meta[@property="og:locale"]');
            if ($locale_nodes && $locale_nodes->length > 0) {
                $lang = trim($locale_nodes->item(0)->getAttribute('content'));
            }
        }
        if ($lang !== '') {
            $lang = strtolower(substr(str_replace('_', '-', $lang), 0, 2));
            if (!preg_match('/^[a-z]{2}$/', $lang)) {
                $lang = '';
            }
        }

        return array(
            'title'       => $title,
            'description' => $description,
            'h1'          => $h1,
            'lang'        => $lang,
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
        // Present as a real browser. Many sites (and CDNs/WAFs like Cloudflare)
        // return 403 to non-browser user-agents, so a bare "Brand Scraper" UA gets
        // blocked. A realistic UA + Accept headers clears the common UA-sniffing
        // 403s (it does NOT defeat full JS/challenge-based bot protection).
        $response = wp_remote_get($url, array(
            'timeout'     => self::HTTP_TIMEOUT,
            'user-agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'sslverify'   => false,
            'redirection' => 5,
            'headers'     => array(
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ),
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException('Failed to fetch URL: ' . $response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status >= 400) {
            // 401/403/429 are almost always the target site's bot protection, not a
            // bug on our side — give the user an actionable hint.
            if (in_array($status, array(401, 403, 429), true)) {
                throw new \RuntimeException("The website blocked our request (HTTP {$status}) — it likely has bot protection. Try entering the brand details manually.");
            }
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
