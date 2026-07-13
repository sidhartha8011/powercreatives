<?php
/**
 * SEO Service — content-SEO business logic.
 *
 * Phase 1: the cross-plugin SEO-meta read/write core (faithful port of the
 * source plugin's seo-integration.php) plus the content row builder and the
 * inline cell-save whitelist. Reads/writes the live WordPress posts & pages.
 *
 * Cross-plugin strategy: detect the active SEO plugin (Yoast / Rank Math /
 * SEOPress) and read/write its native meta keys, ALWAYS mirroring to an
 * internal `pcm_seo_*` backup so values survive a plugin switch.
 *
 * @package PowerCreatives
 * @since   1.23.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Loaded here (service.php runs every request via the module-loader) so each
// file's frontend hooks register: AI-Readiness virtual routes, the Schema.org
// JSON-LD renderer (wp_head), and the site-wide robots/schema/meta hooks.
require_once __DIR__ . '/ai-readiness.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/site.php';
require_once __DIR__ . '/gbp.php';
require_once __DIR__ . '/export.php';
require_once __DIR__ . '/class-pcm-text-matcher.php';

class PCM_SEO_Service
{
    /** Content types this module operates on. */
    public const VALID_TYPES = ['post', 'page'];

    /** Editable WP post statuses (dropdown source + save validation). */
    public const VALID_STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

    /** Max rows fetched per content type. */
    public const PER_TYPE = 100;

    // =====================================================================
    // Cross-plugin SEO meta registry (ported verbatim; pcm_seo_ backup keys)
    // =====================================================================

    /**
     * Map of supported SEO plugins to their meta key names.
     * Each entry: slug => [title, description, keyword, meta_keywords].
     *
     * @return array<string, array<string, string>>
     */
    public static function seo_key_map(): array
    {
        return [
            'yoast' => [
                'title'         => '_yoast_wpseo_title',
                'description'   => '_yoast_wpseo_metadesc',
                'keyword'       => '_yoast_wpseo_focuskw',
                'meta_keywords' => 'pcm_seo_meta_keywords', // no native Yoast support
            ],
            'rankmath' => [
                'title'         => 'rank_math_title',
                'description'   => 'rank_math_description',
                'keyword'       => 'rank_math_focus_keyword',
                'meta_keywords' => 'pcm_seo_meta_keywords',
            ],
            'seopress' => [
                'title'         => '_seopress_titles_title',
                'description'   => '_seopress_titles_desc',
                'keyword'       => '_seopress_analysis_target_kw',
                'meta_keywords' => 'pcm_seo_meta_keywords',
            ],
            'simple' => [
                'title'         => 'pcm_seo_meta_title',
                'description'   => 'pcm_seo_meta_description',
                'keyword'       => 'pcm_seo_primary_keyword',
                'meta_keywords' => 'pcm_seo_meta_keywords',
            ],
        ];
    }

    /**
     * Detect the active SEO plugin via class/constant/function checks
     * (works with must-use plugins / custom paths). Priority by market
     * share: Yoast > Rank Math > SEOPress > internal fallback.
     *
     * @return string One of: yoast | rankmath | seopress | simple.
     */
    public static function detect_seo_plugin(): string
    {
        if (defined('WPSEO_VERSION')) {
            return 'yoast';
        }
        if (class_exists('RankMath')) {
            return 'rankmath';
        }
        if (function_exists('seopress_init')) {
            return 'seopress';
        }
        return 'simple';
    }

    /**
     * Read an SEO field: active plugin key → internal backup → ''.
     *
     * @param int    $post_id Post id.
     * @param string $field   title | description | keyword | meta_keywords.
     * @return string
     */
    public static function seo_get(int $post_id, string $field): string
    {
        $map    = self::seo_key_map();
        $plugin = self::detect_seo_plugin();

        if (isset($map[$plugin][$field])) {
            $value = get_post_meta($post_id, $map[$plugin][$field], true);
            if ($value !== '' && $value !== false) {
                return (string) $value;
            }
        }
        if ($plugin !== 'simple' && isset($map['simple'][$field])) {
            $value = get_post_meta($post_id, $map['simple'][$field], true);
            if ($value !== '' && $value !== false) {
                return (string) $value;
            }
        }
        return '';
    }

    /**
     * Write an SEO field to the active plugin's key AND the internal backup
     * (dual-write so the value survives a plugin switch).
     *
     * @param int    $post_id Post id.
     * @param string $field   title | description | keyword | meta_keywords.
     * @param string $value   Sanitized value.
     */
    public static function seo_update(int $post_id, string $field, string $value): void
    {
        $map    = self::seo_key_map();
        $plugin = self::detect_seo_plugin();

        if (isset($map[$plugin][$field])) {
            update_post_meta($post_id, $map[$plugin][$field], $value);
        }
        if ($plugin !== 'simple' && isset($map['simple'][$field])) {
            update_post_meta($post_id, $map['simple'][$field], $value);
        }
    }

    // =====================================================================
    // Content rows
    // =====================================================================

    /**
     * List content rows for the requested types (post/page), newest first.
     *
     * @param string[] $types Subset of VALID_TYPES.
     * @return array[] Row arrays.
     */
    public function list_content(array $types): array
    {
        $types = array_values(array_intersect($types, self::VALID_TYPES));
        if (empty($types)) {
            $types = self::VALID_TYPES;
        }

        $rows = array();
        foreach ($types as $type) {
            $query = new WP_Query(array(
                'post_type'      => $type,
                'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
                'posts_per_page' => self::PER_TYPE,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            ));
            foreach ($query->posts as $post) {
                $rows[] = $this->build_row($post);
            }
        }
        return $rows;
    }

    /**
     * Build a single content row (SEO-focused field set).
     *
     * @param WP_Post $post Post object.
     * @return array
     */
    public function build_row(WP_Post $post): array
    {
        $id = (int) $post->ID;
        return array(
            'id'                 => $id,
            'type'               => $post->post_type,
            'title'              => $post->post_title,
            'slug'               => $post->post_name,
            'status'             => $post->post_status,
            'date'               => $post->post_date,
            'authorId'           => (int) $post->post_author,
            'author'             => get_the_author_meta('display_name', (int) $post->post_author),
            'permalink'          => get_permalink($id),
            'editUrl'            => get_edit_post_link($id, 'raw'),
            'featuredImage'      => (string) get_the_post_thumbnail_url($id, 'thumbnail'),
            'featuredImageId'    => (int) get_post_thumbnail_id($id),
            'excerpt'            => wp_trim_words(wp_strip_all_tags($post->post_content), 20, '…'),
            'metaTitle'          => self::seo_get($id, 'title'),
            'metaDescription'    => self::seo_get($id, 'description'),
            'primaryKeyword'     => self::seo_get($id, 'keyword'),
            'metaKeywords'       => self::seo_get($id, 'meta_keywords'),
            'supportingKeyword'  => (string) get_post_meta($id, 'pcm_seo_supporting_keyword', true),
            'clusterLabel'       => (string) get_post_meta($id, 'pcm_seo_cluster_label', true),
            'schemaTypes'        => class_exists('PCM_SEO_Schema') ? PCM_SEO_Schema::types_for($id) : array(),
            'internalLinks'      => self::link_count_meta($id, 'internal'),
            'externalLinks'      => self::link_count_meta($id, 'external'),
            'brokenLinks'        => self::link_count_meta($id, 'broken'),
            'linksScannedAt'     => (string) get_post_meta($id, 'pcm_seo_links_scanned_at', true),
        );
    }

    /** Stored link-scan count for a post, or null when it was never scanned. */
    private static function link_count_meta(int $id, string $which): ?int
    {
        if (get_post_meta($id, 'pcm_seo_links_scanned_at', true) === '') {
            return null;
        }
        return (int) get_post_meta($id, "pcm_seo_{$which}_links", true);
    }

    /**
     * Scan a post's links: count internal/external + detect broken (HTTP).
     * Caches counts in post meta (read back by build_row). Ported from
     * Optimizer Simple's link-analyzer (max 20 broken-checks, 4s timeout each).
     *
     * @return array{internal:int,external:int,broken:int,scannedAt:string}
     */
    public function scan_links(int $post_id, bool $check_status = true): array
    {
        $post    = get_post($post_id);
        $content = $post ? (string) $post->post_content : '';
        $from    = get_permalink($post_id) ?: '';
        $links   = self::scan_link_details($content, $from, home_url(), $check_status);
        $internal = 0; $external = 0; $broken = 0;
        foreach ($links as $l) {
            if ($l['kind'] === 'internal') { $internal++; } else { $external++; }
            if (!empty($l['broken'])) { $broken++; }
        }
        $now = current_time('mysql');
        update_post_meta($post_id, 'pcm_seo_internal_links', $internal);
        update_post_meta($post_id, 'pcm_seo_external_links', $external);
        update_post_meta($post_id, 'pcm_seo_broken_links', $broken);
        update_post_meta($post_id, 'pcm_seo_links_scanned_at', $now);
        // wp_slash: update_metadata() runs wp_unslash() on the value, which would strip the
        // backslashes JSON uses to escape the quotes inside each link's <a href="…"> HTML and
        // corrupt the stored JSON (counts saved fine, but the popup detail list came back empty).
        update_post_meta($post_id, 'pcm_seo_links', wp_slash(wp_json_encode($links)));
        return array('internal' => $internal, 'external' => $external, 'broken' => $broken, 'scannedAt' => $now);
    }

    /**
     * Parse every <a> in content into a detailed record: anchor text, source URL,
     * target, the exact HTML, an HTTP status, internal/external kind, and broken flag.
     * Shared by local + remote scanning. HTTP status is checked (capped) so a big
     * page doesn't time out; uncapped links get status 0 (unchecked).
     *
     * @return array<int,array{anchor:string,from:string,to:string,html:string,status:int,kind:string,broken:bool}>
     */
    public static function scan_link_details(string $content, string $from_url, string $site_url, bool $check_status = true): array
    {
        if ($content === '' || !preg_match_all('/<a\s([^>]*?)>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER)) {
            return array();
        }
        $site_host = wp_parse_url($site_url, PHP_URL_HOST);
        $args = array('timeout' => 4, 'redirection' => 5, 'user-agent' => 'WordPress/PowerCreatives; ' . home_url(), 'sslverify' => false);
        $checked = 0;
        $links   = array();
        foreach ($matches as $m) {
            if (!preg_match('/href=[\'"]([^\'"]+)[\'"]/i', $m[1], $h)) {
                continue;
            }
            $href = trim($h[1]);
            if ($href === '' || preg_match('#^(\#|tel:|mailto:|javascript:|data:)#i', $href)) {
                continue;
            }
            $anchor = trim(wp_strip_all_tags($m[2]));
            $host   = wp_parse_url($href, PHP_URL_HOST);
            $kind   = (!$host || $host === $site_host || ($site_host && str_ends_with($host, '.' . $site_host))) ? 'internal' : 'external';

            $check_url = str_starts_with($href, '/') ? rtrim($site_url, '/') . $href : $href;
            $status = 0;
            $broken = false;
            if ($check_status && $checked < 30 && preg_match('#^https?://#i', $check_url)) {
                $resp = wp_remote_head($check_url, $args);
                if (is_wp_error($resp)) {
                    $broken = true;
                } else {
                    $status = (int) wp_remote_retrieve_response_code($resp);
                    if ($status === 405) {
                        $resp   = wp_remote_get($check_url, $args);
                        $status = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
                    }
                    $broken = ($status === 0 || $status >= 400);
                }
                $checked++;
            }
            $links[] = array(
                'anchor' => $anchor,
                'from'   => $from_url,
                'to'     => $href,
                'html'   => $m[0],
                'status' => $status,
                'kind'   => $kind,
                'broken' => $broken,
            );
        }
        return $links;
    }

    /** Stored per-link details for a local post (from the last scan), with index ids. */
    public function get_post_links(int $post_id): array
    {
        $raw   = get_post_meta($post_id, 'pcm_seo_links', true);
        $links = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : array();
        if (!is_array($links)) {
            $links = array();
        }
        return array_values(array_map(static function ($l, $i) {
            $l = is_array($l) ? $l : array();
            $l['id'] = (int) $i;
            $l['editable'] = true; // local links live in post_content → always editable
            return $l;
        }, $links, array_keys($links)));
    }

    /**
     * Byte offset of the link at $index within $content — OCCURRENCE-AWARE so that
     * duplicate <a> HTML (the same anchor + href appearing several times, e.g. repeated
     * "View Details" buttons) resolves to the CORRECT occurrence instead of always the
     * first. Returns null when not found (content changed since the scan the index came
     * from). $links must be in document order (as scan_link_details returns them).
     */
    private static function nth_link_pos(string $content, array $links, int $index): ?int
    {
        if (!isset($links[$index]['html'])) {
            return null;
        }
        $html = (string) $links[$index]['html'];
        if ($html === '') {
            return null;
        }
        // Count earlier links with the exact same HTML → which occurrence to target.
        $occurrence = 0;
        for ($i = 0; $i < $index; $i++) {
            if (isset($links[$i]['html']) && (string) $links[$i]['html'] === $html) {
                $occurrence++;
            }
        }
        $pos    = false;
        $offset = 0;
        for ($n = 0; $n <= $occurrence; $n++) {
            $pos = strpos($content, $html, $offset);
            if ($pos === false) {
                return null;
            }
            $offset = $pos + 1;
        }
        return ($pos === false) ? null : (int) $pos;
    }

    /** Fire known WP + page-builder + CDN-bridge cache purges for a post so a programmatic edit
     *  (which many cache plugins SKIP vs. an editor save) shows on the live page. A bare Cloudflare
     *  proxy with no WP integration must be purged manually. Mirrors the connector's purge so the
     *  OWN site behaves like a connected one. */
    private static function purge_post_caches(int $post_id): void
    {
        if (function_exists('clean_post_cache'))            { clean_post_cache($post_id); }
        if (function_exists('rocket_clean_post'))           { rocket_clean_post($post_id); }            // WP Rocket
        if (function_exists('w3tc_flush_post'))             { w3tc_flush_post($post_id); }              // W3 Total Cache
        if (function_exists('wp_cache_post_change'))        { wp_cache_post_change($post_id); }         // WP Super Cache
        if (function_exists('wpfc_clear_post_cache_by_id')) { wpfc_clear_post_cache_by_id($post_id); }  // WP Fastest Cache
        do_action('litespeed_purge_post', $post_id);
        do_action('cache_enabler_clear_page_cache_by_post', $post_id);
        do_action('breeze_clear_all_cache');
        do_action('siteground_optimizer_flush_cache');
        do_action('swcfpc_purge_cache');           // Super Page Cache for Cloudflare → purges CF edge
        do_action('autoptimize_flush_pagecache');
        do_action('elementor/core/files/clear_cache');
    }

    /** Recursively replace strings inside a value (string / array / object) — serialization-safe. */
    private static function deep_str_replace(array $search, array $replace, $val, int &$count)
    {
        if (is_string($val)) { $c = 0; $out = str_replace($search, $replace, $val, $c); $count += $c; return $out; }
        if (is_array($val))  { foreach ($val as $k => $v) { $val[$k] = self::deep_str_replace($search, $replace, $v, $count); } return $val; }
        if (is_object($val)) { foreach (get_object_vars($val) as $k => $v) { $val->$k = self::deep_str_replace($search, $replace, $v, $count); } return $val; }
        return $val;
    }

    /** Replace an old URL with a new one across EVERY custom field — page builders (Elementor/Divi/
     *  Beaver/etc.) store the layout in meta and render from THERE, not post_content. Serialization-
     *  safe (JSON strings, PHP-serialized arrays/objects). Returns the number of places changed. */
    private static function replace_url_in_meta(int $post_id, string $old, string $new): int
    {
        if ($old === '' || $new === '' || $old === $new) { return 0; }
        global $wpdb;
        $search = array($old); $replace = array($new);
        $oe = str_replace('/', '\\/', $old); // slash-escaped (JSON-in-meta) form
        if ($oe !== $old) { $search[] = $oe; $replace[] = str_replace('/', '\\/', $new); }
        $changed = 0;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id));
        foreach ((array) $rows as $row) {
            $raw = (string) $row->meta_value; $hit = false;
            foreach ($search as $s) { if ($s !== '' && strpos($raw, $s) !== false) { $hit = true; break; } }
            if (!$hit) { continue; }
            $cnt = 0;
            $newVal = self::deep_str_replace($search, $replace, maybe_unserialize($raw), $cnt);
            if ($cnt > 0) { update_metadata_by_mid('post', (int) $row->meta_id, wp_slash($newVal)); $changed += $cnt; }
        }
        return $changed;
    }

    /**
     * Edit a link in a local post's content: replace its href and/or anchor text in
     * the stored <a> HTML, save the post, re-scan. Returns the refreshed link list.
     */
    public function update_post_link(int $post_id, int $index, ?string $anchor, ?string $href)
    {
        $links = $this->get_post_links($post_id);
        if (!isset($links[$index])) {
            return new WP_Error('pcm_seo_link_not_found', __('Link not found — re-scan and try again.', 'power-creatives'), array('status' => 404));
        }
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('pcm_seo_not_found', __('Content not found.', 'power-creatives'), array('status' => 404));
        }
        $old_html = (string) $links[$index]['html'];
        $new_href = $href !== null ? esc_url_raw($href) : (string) $links[$index]['to'];
        $new_text = $anchor !== null ? wp_kses_post($anchor) : (string) $links[$index]['anchor'];

        // Rebuild the <a> (callbacks avoid $-escaping issues in replacements).
        $new_html = preg_replace_callback('/href=[\'"][^\'"]*[\'"]/i', static fn() => 'href="' . $new_href . '"', $old_html, 1);
        $new_html = preg_replace_callback('/(<a\s[^>]*>)(.*)(<\/a>)/is', static fn($m) => $m[1] . $new_text . $m[3], $new_html, 1);

        $content = (string) $post->post_content;
        $pos = self::nth_link_pos($content, $links, $index);
        if ($pos === null) {
            return new WP_Error('pcm_seo_link_stale', __('The page changed — re-scan and try again.', 'power-creatives'), array('status' => 409));
        }
        $content = substr_replace($content, (string) $new_html, $pos, strlen($old_html));
        wp_update_post(array('ID' => $post_id, 'post_content' => $content));

        // If the link's TARGET changed, propagate it across custom fields too — page builders store
        // the layout in meta and render from THERE, not post_content — then purge caches so the live
        // page reflects it. Brings the OWN site to parity with the connector's remote behaviour.
        $old_url = (string) $links[$index]['to'];
        if ($new_href !== '' && $new_href !== $old_url) {
            self::replace_url_in_meta($post_id, $old_url, $new_href);
        }
        self::purge_post_caches($post_id);

        $this->scan_links($post_id, false); // fast refresh — skip per-link HTTP checks (avoid timeout)
        return $this->get_post_links($post_id);
    }

    /** Remove a link from a local post's content (unwrap the <a>, keep its text), save, re-scan. */
    public function remove_post_link(int $post_id, int $index)
    {
        $links = $this->get_post_links($post_id);
        if (!isset($links[$index])) {
            return new WP_Error('pcm_seo_link_not_found', __('Link not found — re-scan and try again.', 'power-creatives'), array('status' => 404));
        }
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('pcm_seo_not_found', __('Content not found.', 'power-creatives'), array('status' => 404));
        }
        $old_html = (string) $links[$index]['html'];
        $inner    = preg_replace('/^<a\s[^>]*>(.*)<\/a>$/is', '$1', $old_html);
        $content  = (string) $post->post_content;
        $pos      = self::nth_link_pos($content, $links, $index);
        if ($pos === null) {
            return new WP_Error('pcm_seo_link_stale', __('The page changed — re-scan and try again.', 'power-creatives'), array('status' => 409));
        }
        $content = substr_replace($content, (string) $inner, $pos, strlen($old_html));
        wp_update_post(array('ID' => $post_id, 'post_content' => $content));
        self::purge_post_caches($post_id);
        $this->scan_links($post_id, false); // fast refresh — skip per-link HTTP checks (avoid timeout)
        return $this->get_post_links($post_id);
    }

    // =====================================================================
    // HEADINGS (H1–H6) — the SEO table's expandable heading editor
    // =====================================================================

    /** Parse every <h1>..<h6> out of an HTML string, in document order. */
    public static function parse_heading_details(string $content): array
    {
        if ($content === '' || !preg_match_all('#<h([1-6])(\s[^>]*)?>(.*?)</h\1>#is', $content, $m, PREG_SET_ORDER)) {
            return array();
        }
        $out = array();
        foreach ($m as $mm) {
            $text = trim(wp_strip_all_tags($mm[3]));
            if ($text === '') { continue; } // skip empty/spacer headings
            $out[] = array(
                'level'  => (int) $mm[1],
                'text'   => $text,
                'html'   => $mm[0],
                'source' => 'content',
                'elId'   => '',
                'field'  => '',
                'tagKey' => '',
                'textKey' => '',
            );
        }
        return $out;
    }

    /** Rebuild a heading's HTML with a new tag level and/or new inner text, preserving attributes. */
    private static function rebuild_heading_html(string $old_html, int $old_level, int $new_level, ?string $new_text): string
    {
        $html = $old_html;
        if ($new_level !== $old_level && $new_level >= 1 && $new_level <= 6) {
            $html = preg_replace('/^<h[1-6]/i', '<h' . $new_level, $html, 1);
            $html = preg_replace('/<\/h[1-6]>(\s*)$/i', '</h' . $new_level . '>$1', $html, 1);
        }
        if ($new_text !== null) {
            $safe = wp_kses_post($new_text);
            // Callback keeps $-sequences in the replacement literal.
            $html = preg_replace_callback('/(<h[1-6][^>]*>)(.*)(<\/h[1-6]>)/is', static fn($m) => $m[1] . $safe . $m[3], $html, 1);
        }
        return (string) $html;
    }

    /** Local post's headings (parsed from post_content), indexed for editing. */
    public function get_post_headings(int $post_id): array
    {
        $post    = get_post($post_id);
        $content = $post ? (string) $post->post_content : '';
        $list    = self::parse_heading_details($content);
        return array_values(array_map(static function ($h, $i) {
            $h['index']    = (int) $i;
            $h['id']       = (int) $i;
            $h['editable'] = true; // local content headings live in post_content → editable
            return $h;
        }, $list, array_keys($list)));
    }

    // =====================================================================
    // CONTENT NODES (headings + paragraphs) — the SEO outline's page-content
    // rows (dynamic-optimization pair 1). NODE IDENTITY CONTRACT v1: a node
    // is addressed by { kind, index (position in THIS ordered list),
    // normalized text } — the exact identity dynamic rules will target and
    // the AI optimizer will rewrite. Never change the ordering or the skip
    // rules without bumping the contract version (documented in
    // docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md → "Node identity contract").
    // =====================================================================

    /**
     * Parse an HTML string into ordered content nodes: every H1–H6 heading
     * (exactly as parse_heading_details sees them — same skip rule, so
     * heading order/count always matches the heading scan) plus every <p>
     * paragraph, in document order.
     *
     * Classic-editor content stores paragraphs as bare newline-separated text
     * (no <p> tags — WP adds them at render via wpautop). When the content
     * contains no <p> at all, the same wpautop is applied for the parse so
     * the nodes mirror what the site actually serves. Headings are untouched
     * by wpautop, so this never desyncs the heading indices.
     *
     * @param string $content Raw post content HTML.
     * @return array<int,array{kind:string,level?:int,text:string,html:string}>
     */
    public static function parse_content_nodes(string $content): array
    {
        if (trim($content) === '') {
            return array();
        }
        if (stripos($content, '<p') === false && function_exists('wpautop')) {
            $content = wpautop($content);
        }
        // One combined pattern keeps document order: heading branch (groups
        // 1–3, with the </h\1> backreference) OR paragraph branch (groups 4–5).
        if (!preg_match_all('#<h([1-6])(\s[^>]*)?>(.*?)</h\1>|<p(\s[^>]*)?>(.*?)</p>#is', $content, $m, PREG_SET_ORDER)) {
            return array();
        }
        $out = array();
        foreach ($m as $mm) {
            if (($mm[1] ?? '') !== '') {
                $text = trim(wp_strip_all_tags($mm[3]));
                if ($text === '') { continue; } // same skip rule as parse_heading_details
                $out[] = array('kind' => 'heading', 'level' => (int) $mm[1], 'text' => $text, 'html' => $mm[0]);
            } else {
                $text  = trim(wp_strip_all_tags((string) ($mm[5] ?? '')));
                // Skip spacer paragraphs: empty after stripping tags AND
                // decoding entities (a lone &nbsp; is a spacer, not content).
                $plain = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\xC2\xA0");
                if ($plain === '') { continue; }
                $out[] = array('kind' => 'paragraph', 'text' => $text, 'html' => $mm[0]);
            }
        }
        return $out;
    }

    /**
     * Local post's ordered content nodes for the SEO outline, indexed.
     *
     * `index` = the node's position in THIS list (the rule-target identity).
     * Heading nodes ALSO carry `headingIndex` — their position in the
     * headings-only list (get_post_headings) — so the existing heading
     * edit/optimize endpoints keep working unchanged from the combined view.
     * Paragraphs are read-only here (they become editable via dynamic rules,
     * pair 3 — never via source writes).
     *
     * @return array[] Node rows.
     */
    public function get_post_content_nodes(int $post_id): array
    {
        $post      = get_post($post_id);
        $content   = $post ? (string) $post->post_content : '';
        $nodes     = self::parse_content_nodes($content);
        $out       = array();
        $heading_i = 0;
        foreach ($nodes as $i => $n) {
            $n['index']  = (int) $i;
            $n['id']     = (int) $i;
            $n['source'] = 'content';
            $n['elId']   = '';
            if ($n['kind'] === 'heading') {
                $n['headingIndex'] = $heading_i++;
                $n['editable']     = true; // via the existing heading endpoints (headingIndex)
                $n['field']        = '';
                $n['tagKey']       = '';
                $n['textKey']      = '';
            } else {
                $n['editable'] = false; // read-only until the dynamic-rule path (pair 3)
            }
            $out[] = $n;
        }
        return $out;
    }

    /**
     * Edit a local heading: change its text and/or tag level in post_content (occurrence-aware),
     * propagate to any content-based builder meta, purge caches, re-scan. Returns the heading list.
     */
    public function update_post_heading(int $post_id, int $index, ?string $text, ?int $level)
    {
        $headings = $this->get_post_headings($post_id);
        if (!isset($headings[$index])) {
            return new WP_Error('pcm_seo_heading_not_found', __('Heading not found — re-open and try again.', 'power-creatives'), array('status' => 404));
        }
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('pcm_seo_not_found', __('Content not found.', 'power-creatives'), array('status' => 404));
        }
        $old_html  = (string) $headings[$index]['html'];
        $old_level = (int) $headings[$index]['level'];
        $new_level = ($level !== null) ? max(1, min(6, $level)) : $old_level;
        $new_html  = self::rebuild_heading_html($old_html, $old_level, $new_level, $text);
        if ($new_html === $old_html) {
            return $this->get_post_headings($post_id); // no-op
        }

        $content = (string) $post->post_content;
        $pos     = self::nth_link_pos($content, $headings, $index); // html-generic occurrence finder
        if ($pos === null) {
            return new WP_Error('pcm_seo_heading_stale', __('The page changed — re-open and try again.', 'power-creatives'), array('status' => 409));
        }
        $content = substr_replace($content, $new_html, $pos, strlen($old_html));
        wp_update_post(array('ID' => $post_id, 'post_content' => $content));

        // Content-based page builders (Divi/WPBakery shortcodes, etc.) keep the heading HTML in meta
        // too — replace it there, serialization-safe (incl. the JSON-slash-escaped variant), so the
        // live page reflects the edit. (Field-based builders — Elementor heading widget — store text
        // and level in separate meta fields; those are edited on the remote via the connector.)
        self::replace_url_in_meta($post_id, $old_html, $new_html);
        self::purge_post_caches($post_id);

        return $this->get_post_headings($post_id);
    }

    /** Shared AI runner for a prompt section (resolve override → substitute → invoke → sanitize).
     *  $single_line: true = pick-the-value-line sanitize (titles/headings/keywords);
     *  false = keep the whole text as ONE flowing block (paragraphs: strip fences,
     *  collapse whitespace, strip matched surrounding quotes — never drop sentences). */
    private static function run_prompt_section(string $section, string $mode, array $vars, int $max, ?string $model, ?int $user_id, ?string $provider, ?int $template_id, bool $single_line = true)
    {
        $prompts = self::field_prompts();
        if (empty($prompts[$section][$mode])) {
            return new WP_Error('pcm_seo_no_prompt', __('No prompt configured for this field.', 'power-creatives'), array('status' => 500));
        }
        $default = (string) $prompts[$section][$mode];
        $tpl     = self::resolve_prompt($section . '_' . $mode, $default, $user_id, $template_id);
        // A user's free-form INSTRUCTION ('topic') must reach the model even when
        // the resolved template predates the {{topic}} placeholder (already-seeded
        // or user-edited templates are never rewritten) — append it honestly.
        if (!empty($vars['topic']) && strpos($tpl, '{{topic}}') === false) {
            $tpl .= "\n\nExtra instruction (follow it): {{topic}}";
        }
        $prompt  = self::substitute_vars($tpl, $vars);
        if (!class_exists('PCM_LLM')) {
            return new WP_Error('pcm_seo_no_llm', __('AI provider is unavailable.', 'power-creatives'), array('status' => 500));
        }
        try {
            $opts = array('max_tokens' => $max);
            if (!empty($model))    { $opts['model'] = $model; }
            if (!empty($provider)) { $opts['provider'] = $provider; }
            $result = PCM_LLM::invoke(array(array('role' => 'user', 'content' => $prompt)), $opts);
            $raw    = (string) ($result['content'] ?? '');
            if ($single_line) {
                $value = self::sanitize_ai_output($raw);
            } else {
                $value = trim((string) preg_replace('/^```[a-zA-Z0-9]*\s*|\s*```$/', '', trim($raw)));
                $value = trim((string) preg_replace('/\s+/u', ' ', $value));
                $len   = strlen($value);
                if ($len >= 2 && (($value[0] === '"' && $value[$len - 1] === '"') || ($value[0] === "'" && $value[$len - 1] === "'"))) {
                    $value = trim(substr($value, 1, $len - 2));
                }
            }
            if ($value === '') {
                return new WP_Error('pcm_seo_empty', __('The model returned no text — try again.', 'power-creatives'), array('status' => 502));
            }
            return $value;
        } catch (\Throwable $e) {
            return new WP_Error('pcm_seo_generate_failed', $e->getMessage(), array('status' => 502));
        }
    }

    /** AI-optimize a single heading's text (NOT saved). Returns { value }. */
    public function optimize_heading(int $post_id, string $text, ?int $brand_id = null, ?string $model = null, ?int $user_id = null, ?string $provider = null, ?int $template_id = null)
    {
        $vars = $this->build_field_vars($post_id, $brand_id);
        $vars['current_value'] = $text;
        $mode = ($text !== '') ? 'optimize' : 'generate';
        $max  = (int) (self::field_prompts()['heading']['max'] ?? 80);
        $val  = self::run_prompt_section('heading', $mode, $vars, $max, $model, $user_id, $provider, $template_id);
        return ($val instanceof WP_Error) ? $val : array('value' => $val);
    }

    /** Count internal vs external <a href> links in content. */
    private static function count_links(string $content, string $site_url): array
    {
        if ($content === '') {
            return array('internal' => 0, 'external' => 0);
        }
        $site_host = wp_parse_url($site_url, PHP_URL_HOST);
        preg_match_all('/<a\s[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>/i', $content, $matches);
        $internal = 0;
        $external = 0;
        foreach (($matches[1] ?? array()) as $url) {
            $url = trim($url);
            if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'tel:') || str_starts_with($url, 'mailto:')) {
                continue;
            }
            $host = wp_parse_url($url, PHP_URL_HOST);
            if ($host) {
                if ($host === $site_host || ($site_host && str_ends_with($host, '.' . $site_host))) {
                    $internal++;
                } else {
                    $external++;
                }
            } elseif (!str_starts_with($url, 'javascript:') && !str_starts_with($url, 'data:')) {
                $internal++;
            }
        }
        return array('internal' => $internal, 'external' => $external);
    }

    /** Count broken links (HTTP 4xx/5xx/error). Caps at 20 checks, 4s each. */
    private static function check_broken_links(string $content, ?string $base = null): int
    {
        if ($content === '') {
            return 0;
        }
        preg_match_all('/<a\s[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>/i', $content, $matches);
        $broken  = 0;
        $checked = 0;
        $args    = array(
            'timeout'     => 4,
            'redirection' => 5,
            'user-agent'  => 'WordPress/PowerCreatives; ' . home_url(),
            'sslverify'   => false,
        );
        foreach (array_unique($matches[1] ?? array()) as $url) {
            if ($checked >= 20) {
                break;
            }
            $url = trim($url);
            if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'tel:')
                || str_starts_with($url, 'mailto:') || str_starts_with($url, 'javascript:') || str_starts_with($url, 'data:')) {
                continue;
            }
            if (str_starts_with($url, '/')) {
                $url = rtrim($base ?: home_url('/'), '/') . $url;
            }
            $response = wp_remote_head($url, $args);
            if (is_wp_error($response)) {
                $broken++;
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code === 405) {
                    $response = wp_remote_get($url, $args);
                    $code     = is_wp_error($response) ? 400 : (int) wp_remote_retrieve_response_code($response);
                }
                if ($code >= 400) {
                    $broken++;
                }
            }
            $checked++;
        }
        return $broken;
    }

    /**
     * Fields accepted by the inline cell-save endpoint, with how each maps to
     * storage. SEO fields go through the cross-plugin dual-write; native
     * fields update the post; the rest are internal `pcm_seo_*` meta.
     *
     * @return array<string, string> field => handler kind.
     */
    public static function save_cell_fields(): array
    {
        return array(
            // Native post columns.
            'title'             => 'post_title',
            'slug'              => 'post_name',
            'status'            => 'post_status',
            'author'            => 'post_author',
            // Cross-plugin SEO meta (dual-write).
            'metaTitle'         => 'seo:title',
            'metaDescription'   => 'seo:description',
            'primaryKeyword'    => 'seo:keyword',
            'metaKeywords'      => 'seo:meta_keywords',
            // Internal SEO meta.
            'supportingKeyword' => 'meta:pcm_seo_supporting_keyword',
            'clusterLabel'      => 'meta:pcm_seo_cluster_label',
        );
    }

    /**
     * Duplicate a post/page as a DRAFT, copying its content, post meta (incl.
     * every SEO plugin's meta + our pcm_seo_ backups) and taxonomy terms.
     * Caller has already checked create + per-post edit capability.
     *
     * @param int $post_id Source post id.
     * @return int|WP_Error New post id, or error.
     */
    public function duplicate(int $post_id)
    {
        $src = get_post($post_id);
        if (!$src) {
            return new WP_Error('pcm_seo_not_found', __('Content not found.', 'power-creatives'), array('status' => 404));
        }

        $new_id = wp_insert_post(array(
            'post_type'      => $src->post_type,
            'post_status'    => 'draft',
            'post_title'     => $src->post_title . ' ' . __('(Copy)', 'power-creatives'),
            'post_content'   => $src->post_content,
            'post_excerpt'   => $src->post_excerpt,
            'post_author'    => get_current_user_id(),
            'comment_status' => $src->comment_status,
            'ping_status'    => $src->ping_status,
            'post_parent'    => $src->post_parent,
            'menu_order'     => $src->menu_order,
        ), true);
        if (is_wp_error($new_id)) {
            return $new_id;
        }
        $new_id = (int) $new_id;

        // Copy post meta (SEO keys + pcm_seo_ backups), skipping WP internals.
        foreach (get_post_meta($post_id) as $key => $values) {
            if (in_array($key, array('_edit_lock', '_edit_last', '_wp_old_slug'), true)) {
                continue;
            }
            foreach ($values as $value) {
                add_post_meta($new_id, $key, maybe_unserialize($value));
            }
        }

        // Copy taxonomy terms (categories, tags, custom) for a faithful copy.
        foreach (get_object_taxonomies($src->post_type) as $tax) {
            $terms = wp_get_object_terms($post_id, $tax, array('fields' => 'ids'));
            if (!is_wp_error($terms) && !empty($terms)) {
                wp_set_object_terms($new_id, $terms, $tax);
            }
        }

        return $new_id;
    }

    /**
     * Save a single content cell. Returns the canonical stored value or a
     * WP_Error on validation failure. Caller has already verified the
     * per-post edit capability.
     *
     * @param int    $post_id Post id.
     * @param string $field   Field key (see save_cell_fields()).
     * @param mixed  $value   Raw request value.
     * @return array|WP_Error { field, value } or error.
     */
    public function save_cell(int $post_id, string $field, $value)
    {
        // Featured image — a post-thumbnail action (set/clear by attachment id),
        // not a column/meta field. Mirrors the WP "Featured image" behavior.
        if ($field === 'featuredImage') {
            $att_id = (int) $value;
            if ($att_id > 0) {
                $ptype = get_post_type($post_id);
                if ($ptype && !post_type_supports($ptype, 'thumbnail')) {
                    add_post_type_support($ptype, 'thumbnail');
                }
                if (!set_post_thumbnail($post_id, $att_id)) {
                    return new WP_Error('pcm_seo_thumbnail_failed', __('Failed to set featured image.', 'power-creatives'), array('status' => 500));
                }
            } else {
                delete_post_thumbnail($post_id);
            }
            $tid = (int) get_post_thumbnail_id($post_id);
            return array(
                'field'           => 'featuredImage',
                'value'           => $tid ? (string) wp_get_attachment_image_url($tid, 'thumbnail') : '',
                'featuredImageId' => $tid,
            );
        }

        $fields = self::save_cell_fields();
        if (!isset($fields[$field])) {
            return new WP_Error('pcm_seo_bad_field', __('Unknown field.', 'power-creatives'), array('status' => 400));
        }
        $handler = $fields[$field];

        // Cross-plugin SEO meta.
        if (strpos($handler, 'seo:') === 0) {
            $seo_field = substr($handler, 4);
            $clean     = sanitize_text_field((string) $value);
            self::seo_update($post_id, $seo_field, $clean);
            return array('field' => $field, 'value' => $clean);
        }

        // Internal meta.
        if (strpos($handler, 'meta:') === 0) {
            $meta_key = substr($handler, 5);
            $clean    = sanitize_text_field((string) $value);
            update_post_meta($post_id, $meta_key, $clean);
            return array('field' => $field, 'value' => $clean);
        }

        // Native post fields.
        switch ($handler) {
            case 'post_title':
                $clean = sanitize_text_field((string) $value);
                wp_update_post(array('ID' => $post_id, 'post_title' => $clean));
                return array('field' => $field, 'value' => $clean);

            case 'post_name':
                $clean = sanitize_title((string) $value);
                wp_update_post(array('ID' => $post_id, 'post_name' => $clean));
                // sanitize_title can dedupe — read back the stored slug.
                $stored = get_post_field('post_name', $post_id);
                return array('field' => $field, 'value' => (string) $stored);

            case 'post_status':
                $clean = (string) $value;
                if (!in_array($clean, self::VALID_STATUSES, true)) {
                    return new WP_Error('pcm_seo_bad_status', __('Invalid status.', 'power-creatives'), array('status' => 400));
                }
                wp_update_post(array('ID' => $post_id, 'post_status' => $clean));
                return array('field' => $field, 'value' => $clean);

            case 'post_author':
                $author_id = (int) $value;
                if ($author_id <= 0 || !get_userdata($author_id)) {
                    return new WP_Error('pcm_seo_bad_author', __('Invalid author.', 'power-creatives'), array('status' => 400));
                }
                wp_update_post(array('ID' => $post_id, 'post_author' => $author_id));
                return array('field' => $field, 'value' => $author_id);
        }

        return new WP_Error('pcm_seo_bad_field', __('Unhandled field.', 'power-creatives'), array('status' => 400));
    }

    // ── Remote-site SEO (connected sites via the connector proxy; Phase 1: read + edit) ──

    /** Ensure the Sites service (remote proxy + credential decrypt) is loaded. */
    private static function ensure_sites_service(): void
    {
        if (!class_exists('PCM_Sites_Service')) {
            require_once dirname(__DIR__) . '/sites/service.php';
        }
    }

    /** Remote SEO meta-key candidates per editable field (pcm first, then Yoast/RankMath/SEOPress). */
    private static function remote_meta_keys(string $field): array
    {
        $map = array(
            'metaTitle'       => array('pcm_seo_meta_title', '_yoast_wpseo_title', 'rank_math_title', '_seopress_titles_title'),
            'metaDescription' => array('pcm_seo_meta_description', '_yoast_wpseo_metadesc', 'rank_math_description', '_seopress_titles_desc'),
            'primaryKeyword'  => array('pcm_seo_primary_keyword', '_yoast_wpseo_focuskw', 'rank_math_focus_keyword', '_seopress_analysis_target_kw'),
            'metaKeywords'    => array('pcm_seo_meta_keywords'),
            'supportingKeyword' => array('pcm_seo_supporting_keyword'),
            'clusterLabel'      => array('pcm_seo_cluster_label'),
        );
        return $map[$field] ?? array();
    }

    /** Map a remote WP REST post/page item → a SeoRow-shaped array (matches build_row). */
    private static function remote_row(array $item, string $type, object $site): array
    {
        $meta = (isset($item['meta']) && is_array($item['meta'])) ? $item['meta'] : array();
        $pick = static function (array $keys) use ($meta) {
            foreach ($keys as $k) {
                if (!empty($meta[$k])) {
                    return (string) $meta[$k];
                }
            }
            return '';
        };
        $id     = (int) ($item['id'] ?? 0);
        $schema = array();
        if (!empty($meta['pcm_seo_schema'])) {
            $decoded = json_decode((string) $meta['pcm_seo_schema'], true);
            if (is_array($decoded)) {
                $schema = array_values(array_filter(array_map('strval', $decoded)));
            }
        }
        return array(
            'id'                => $id,
            'type'              => $type,
            'title'             => (string) ($item['title']['rendered'] ?? ''),
            'slug'              => (string) ($item['slug'] ?? ''),
            'status'            => (string) ($item['status'] ?? ''),
            'date'              => (string) ($item['date'] ?? ''),
            'authorId'          => (int) ($item['author'] ?? 0),
            'author'            => (string) ($item['_embedded']['author'][0]['name'] ?? ''),
            'permalink'         => (string) ($item['link'] ?? ''),
            'editUrl'           => rtrim((string) $site->url, '/') . '/wp-admin/post.php?post=' . $id . '&action=edit',
            'featuredImage'     => (string) (
                $item['_embedded']['wp:featuredmedia'][0]['media_details']['sizes']['thumbnail']['source_url']
                    ?? $item['_embedded']['wp:featuredmedia'][0]['source_url']
                    ?? ''
            ),
            'excerpt'           => isset($item['excerpt']['rendered'])
                ? wp_trim_words(wp_strip_all_tags((string) $item['excerpt']['rendered']), 20, '…')
                : '',
            'metaTitle'         => $pick(self::remote_meta_keys('metaTitle')),
            'metaDescription'   => $pick(self::remote_meta_keys('metaDescription')),
            'primaryKeyword'    => $pick(self::remote_meta_keys('primaryKeyword')),
            'metaKeywords'      => $pick(self::remote_meta_keys('metaKeywords')),
            'supportingKeyword' => $pick(self::remote_meta_keys('supportingKeyword')),
            'clusterLabel'      => $pick(self::remote_meta_keys('clusterLabel')),
            'schemaTypes'       => $schema,
            'internalLinks'     => null,
            'externalLinks'     => null,
            'brokenLinks'       => null,
            'linksScannedAt'    => '',
        );
    }

    /**
     * List a connected site's posts + pages as SeoRows, via the connector proxy.
     * Best-effort: skips a post type on a proxy error rather than failing the whole list.
     */
    public static function remote_list_content(object $site): array
    {
        self::ensure_sites_service();
        $rows   = array();
        $fields = 'id,title,slug,status,date,link,author,featured_media,excerpt,meta,_embedded.author,_embedded.wp:featuredmedia';
        foreach (array('post' => '/wp/v2/posts', 'page' => '/wp/v2/pages') as $type => $route) {
            $res = PCM_Sites_Service::remote_rest($site, 'GET', $route, array(
                'per_page' => 100,
                'status'   => 'publish,future,draft,pending,private',
                '_embed'   => '1',
                '_fields'  => $fields,
                'orderby'  => 'modified',
            ));
            if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
                continue;
            }
            foreach ($res['body'] as $item) {
                if (is_array($item)) {
                    $rows[] = self::remote_row($item, $type, $site);
                }
            }
        }
        return $rows;
    }

    /**
     * Fetch a connected site's page HTML AUTHENTICATED (Basic auth via the connector's
     * Application Password) so it can be previewed SAME-ORIGIN with the WP admin bar.
     * The connector enables app-password auth on front-end requests; rendering server-
     * side here avoids the cross-origin third-party-cookie block that strips the admin
     * bar from a direct iframe. A `<base href>` is injected so relative assets resolve.
     * Only fetches the site's OWN host (the Basic-auth creds must not leak elsewhere).
     *
     * @return array{html:string}|\WP_Error
     */
    public static function remote_preview_html(object $site, string $url)
    {
        $url = trim($url);
        if ($url === '') {
            return new WP_Error('pcm_seo_bad_preview_url', __('No preview URL.', 'power-creatives'), array('status' => 400));
        }
        $url_host  = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $site_host = strtolower((string) wp_parse_url((string) $site->url, PHP_URL_HOST));
        if ($url_host === '' || $site_host === '' || $url_host !== $site_host) {
            return new WP_Error('pcm_seo_bad_preview_url', __('Preview URL is not on this site.', 'power-creatives'), array('status' => 400));
        }
        self::ensure_sites_service();
        $password = PCM_Sites_Service::decrypt_password((string) $site->appPassword);
        $res = wp_remote_get($url, array(
            'timeout'     => 20,
            'redirection' => 5,
            'sslverify'   => false,
            'headers'     => array(
                'Authorization' => 'Basic ' . base64_encode($site->username . ':' . $password),
                'Accept'        => 'text/html',
            ),
            'user-agent'  => 'Mozilla/5.0 (compatible; PowerCreatives/1.0; +' . home_url() . ')',
        ));
        if (is_wp_error($res)) {
            return new WP_Error('pcm_seo_preview_failed', $res->get_error_message(), array('status' => 502));
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $html = (string) wp_remote_retrieve_body($res);
        if ($code < 200 || $code >= 400 || $html === '') {
            return new WP_Error('pcm_seo_preview_failed', sprintf(__('The page returned HTTP %d.', 'power-creatives'), $code), array('status' => 502));
        }
        $scheme   = (string) (wp_parse_url($url, PHP_URL_SCHEME) ?: 'https');
        $base_tag = '<base href="' . esc_url($scheme . '://' . $url_host . '/') . '">';
        if (preg_match('/<head[^>]*>/i', $html)) {
            $html = preg_replace('/(<head[^>]*>)/i', '$1' . $base_tag, $html, 1);
        } else {
            $html = $base_tag . $html;
        }
        return array('html' => $html);
    }

    /**
     * Save one SEO field to a connected site's post/page. Native fields (title/slug)
     * write directly; meta fields dual-write the pcm_* key plus Yoast/RankMath/SEOPress
     * keys so the value lands regardless of the remote's active SEO plugin.
     *
     * @return array{field:string,value:string}|\WP_Error
     */
    public static function remote_save_cell(object $site, int $post_id, string $type, string $field, string $value)
    {
        self::ensure_sites_service();
        $route   = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
        $payload = array();
        if ($field === 'title') {
            $payload['title'] = $value;
        } elseif ($field === 'slug') {
            $payload['slug'] = sanitize_title($value);
        } elseif ($field === 'status') {
            $payload['status'] = $value; // native post field (publish/draft/…) — core REST validates
        } else {
            $keys = self::remote_meta_keys($field);
            if (empty($keys)) {
                return new WP_Error('pcm_seo_bad_field', __('This field is not editable on a remote site.', 'power-creatives'), array('status' => 400));
            }
            $payload['meta'] = array();
            foreach ($keys as $k) {
                $payload['meta'][$k] = $value;
            }
        }
        $res = PCM_Sites_Service::remote_rest($site, 'POST', $route, array(), $payload);
        if (is_wp_error($res)) {
            return new WP_Error('pcm_seo_remote_save', $res->get_error_message(), array('status' => 502));
        }
        if ((int) ($res['status'] ?? 0) >= 300) {
            $msg = (is_array($res['body'] ?? null) && !empty($res['body']['message']))
                ? (string) $res['body']['message']
                : ('HTTP ' . (int) ($res['status'] ?? 0));
            return new WP_Error('pcm_seo_remote_save', $msg, array('status' => 502));
        }
        // Meta only persists if the remote REGISTERS those keys in REST (the connector
        // plugin, or a REST-aware SEO plugin). WordPress silently DROPS unregistered meta
        // and still returns 200 — so re-read and confirm the key exists, otherwise report
        // the phantom save instead of faking success. (title/slug are native — always OK.)
        if (!in_array($field, array('title', 'slug', 'status'), true)) {
            $verify = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('_fields' => 'meta'));
            $saved  = (!is_wp_error($verify) && is_array($verify['body'] ?? null) && isset($verify['body']['meta']) && is_array($verify['body']['meta']))
                ? $verify['body']['meta']
                : array();
            $landed = false;
            foreach ($keys as $k) {
                if (array_key_exists($k, $saved)) {
                    $landed = true;
                    break;
                }
            }
            if (!$landed) {
                return new WP_Error(
                    'pcm_seo_remote_meta_unsupported',
                    __('The remote site didn’t store this SEO field — its REST API doesn’t expose SEO meta. Install the Power Creatives connector plugin on that site to edit its meta (Title & Slug work without it).', 'power-creatives'),
                    array('status' => 422)
                );
            }
        }
        // Reflect the canonical stored slug (WP may dedupe it server-side).
        if ($field === 'slug' && is_array($res['body'] ?? null) && !empty($res['body']['slug'])) {
            $value = (string) $res['body']['slug'];
        }
        return array('field' => $field, 'value' => $value);
    }

    /**
     * Create a draft post/page on a connected site (via the proxy). Returns {id,type}.
     *
     * @return array{id:int,type:string}|\WP_Error
     */
    public static function remote_create_content(object $site, string $type)
    {
        self::ensure_sites_service();
        $route = ($type === 'page') ? '/wp/v2/pages' : '/wp/v2/posts';
        $res = PCM_Sites_Service::remote_rest($site, 'POST', $route, array(), array(
            'title'  => __('Untitled', 'power-creatives'),
            'status' => 'draft',
        ));
        if (is_wp_error($res)) {
            return new WP_Error('pcm_seo_remote_create', $res->get_error_message(), array('status' => 502));
        }
        if ((int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null) || empty($res['body']['id'])) {
            $msg = (is_array($res['body'] ?? null) && !empty($res['body']['message']))
                ? (string) $res['body']['message']
                : ('HTTP ' . (int) ($res['status'] ?? 0));
            return new WP_Error('pcm_seo_remote_create', $msg, array('status' => 502));
        }
        return array('id' => (int) $res['body']['id'], 'type' => $type);
    }

    /**
     * Duplicate a connected site's post/page via the connector: read the source
     * (edit context → raw title/content + SEO meta) and create a draft "(Copy)".
     * Mirrors the local duplicate(); the connector exposes the SEO meta keys in REST.
     *
     * @return array{id:int,type:string}|\WP_Error
     */
    public static function remote_duplicate_content(object $site, int $post_id, string $type)
    {
        self::ensure_sites_service();
        $base = ($type === 'page') ? '/wp/v2/pages' : '/wp/v2/posts';

        // Read the source with edit context so we get raw title/content + meta.
        $get = PCM_Sites_Service::remote_rest($site, 'GET', $base . '/' . $post_id, array(
            'context' => 'edit',
            '_fields' => 'title,content,excerpt,meta',
        ));
        if (is_wp_error($get)) {
            return new WP_Error('pcm_seo_remote_dup', $get->get_error_message(), array('status' => 502));
        }
        if ((int) ($get['status'] ?? 0) >= 300 || !is_array($get['body'] ?? null)) {
            return new WP_Error('pcm_seo_remote_dup', __('Could not read the source content.', 'power-creatives'), array('status' => 502));
        }
        $src     = $get['body'];
        $title   = (string) ($src['title']['raw'] ?? $src['title']['rendered'] ?? __('Untitled', 'power-creatives'));
        $content = (string) ($src['content']['raw'] ?? '');
        $excerpt = (string) ($src['excerpt']['raw'] ?? '');

        $body = array(
            'title'   => $title . ' ' . __('(Copy)', 'power-creatives'),
            'content' => $content,
            'excerpt' => $excerpt,
            'status'  => 'draft',
        );
        // Carry over the SEO meta the connector exposes (Yoast/RankMath/SEOPress/pcm_*).
        if (isset($src['meta']) && is_array($src['meta']) && !empty($src['meta'])) {
            $body['meta'] = $src['meta'];
        }

        $post = PCM_Sites_Service::remote_rest($site, 'POST', $base, array(), $body);
        if (is_wp_error($post)) {
            return new WP_Error('pcm_seo_remote_dup', $post->get_error_message(), array('status' => 502));
        }
        if ((int) ($post['status'] ?? 0) >= 300 || !is_array($post['body'] ?? null) || empty($post['body']['id'])) {
            $msg = (is_array($post['body'] ?? null) && !empty($post['body']['message']))
                ? (string) $post['body']['message']
                : ('HTTP ' . (int) ($post['status'] ?? 0));
            return new WP_Error('pcm_seo_remote_dup', $msg, array('status' => 502));
        }
        return array('id' => (int) $post['body']['id'], 'type' => $type);
    }

    /**
     * Trash a post/page on a connected site (via the proxy). force=false → Trash, not a
     * permanent delete, so a mistaken bulk delete on a client site is recoverable.
     *
     * @return array{id:int,trashed:bool}|\WP_Error
     */
    public static function remote_delete_content(object $site, int $post_id, string $type)
    {
        self::ensure_sites_service();
        $route = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
        $res   = PCM_Sites_Service::remote_rest($site, 'DELETE', $route, array('force' => 'false'));
        if (is_wp_error($res)) {
            return new WP_Error('pcm_seo_remote_delete', $res->get_error_message(), array('status' => 502));
        }
        if ((int) ($res['status'] ?? 0) >= 300) {
            $msg = (is_array($res['body'] ?? null) && !empty($res['body']['message']))
                ? (string) $res['body']['message']
                : __('Could not delete on the remote site.', 'power-creatives');
            return new WP_Error('pcm_seo_remote_delete', $msg, array('status' => 502));
        }
        return array('id' => $post_id, 'trashed' => true);
    }

    /**
     * Set a connected post's featured image from an image URL: uploads it into the remote
     * media library, then sets featured_media. Returns the new thumbnail (url + id).
     *
     * @return array{featuredImage:string,featuredImageId:int}|\WP_Error
     */
    public static function remote_set_featured_image(object $site, int $post_id, string $type, string $image_url)
    {
        self::ensure_sites_service();
        $media = PCM_Sites_Service::remote_upload_media($site, $image_url);
        if ($media instanceof WP_Error) {
            return $media;
        }
        $route = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
        $res   = PCM_Sites_Service::remote_rest($site, 'POST', $route, array(), array('featured_media' => (int) $media['id']));
        if (is_wp_error($res)) {
            return new WP_Error('pcm_seo_remote_featured', $res->get_error_message(), array('status' => 502));
        }
        if ((int) ($res['status'] ?? 0) >= 300) {
            return new WP_Error('pcm_seo_remote_featured', __('Could not set the featured image on the remote site.', 'power-creatives'), array('status' => 502));
        }
        return array('featuredImage' => (string) $media['url'], 'featuredImageId' => (int) $media['id']);
    }

    /**
     * Scan a connected site's post links — analysis runs on the HUB over the remote's
     * rendered content (internal/external counts + broken-link HEAD checks). Counts are
     * returned for the table (not persisted on the remote).
     *
     * @return array{internal:int,external:int,broken:int,scannedAt:string}|\WP_Error
     */
    public static function remote_scan_links(object $site, int $post_id, string $type)
    {
        // Count from the SAME source the popup lists from (remote_get_links → the post's raw
        // content + scan_link_details), so the table counts always match the popup detail.
        // (Previously this counted rendered content via count_links → counts could show links
        // the popup, which reads raw, never displayed.)
        $links    = self::remote_get_links($site, $post_id, $type);
        $internal = 0; $external = 0; $broken = 0;
        foreach ($links as $l) {
            if (($l['kind'] ?? '') === 'internal') { $internal++; } else { $external++; }
            if (!empty($l['broken'])) { $broken++; }
        }
        return array(
            'internal'  => $internal,
            'external'  => $external,
            'broken'    => $broken,
            'scannedAt' => current_time('mysql'),
        );
    }

    /** Installed version of the Power Creatives Connector on a connected site — delegates to
     *  the SINGLE reader in PCM_Sites_Service (also used by the Sites module's Connector column). */
    private static function remote_connector_version(object $site): string
    {
        self::ensure_sites_service();
        return PCM_Sites_Service::remote_connector_version($site);
    }

    /** Per-link details for a connected post (computed on-demand from its raw content). */
    /** HTTP status of a URL (HEAD; 0 on transport error). Note: bot-protected hosts (Google,
     *  Cloudflare challenge) can answer 403/503 to automated checks — a known false-positive. */
    private static function link_http_status(string $url): int
    {
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return 0;
        }
        $resp = wp_remote_head($url, array(
            'timeout'     => 8,
            'redirection' => 3,
            'sslverify'   => false,
            'user-agent'  => 'Mozilla/5.0 (compatible; PowerCreatives link check)',
        ));
        return is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
    }

    public static function remote_get_links(object $site, int $post_id, string $type, bool $check_status = true): array
    {
        self::ensure_sites_service();

        // Prefer the connector's BUILDER-AWARE scan (v2.1.0+): it reads links from post_content AND
        // builder data (Elementor/Divi/etc. custom fields) that the post-body-only scan misses — the
        // real on-page links (e.g. Elementor button URLs) live there, not in post_content.
        $scan = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/scan-links', array('post_id' => $post_id));
        if (!is_wp_error($scan) && (int) ($scan['status'] ?? 0) < 300 && is_array($scan['body']['links'] ?? null)) {
            $site_host = (string) (wp_parse_url((string) $site->url, PHP_URL_HOST) ?: '');

            // "From" = the PAGE the links live on (its permalink), not the site root.
            $from = (string) $site->url;
            $perma = PCM_Sites_Service::remote_rest(
                $site,
                'GET',
                ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id,
                array('_fields' => 'link')
            );
            if (!is_wp_error($perma) && !empty($perma['body']['link']) && is_string($perma['body']['link'])) {
                $from = (string) $perma['body']['link'];
            }

            // De-dupe before the per-link HTTP checks: the connector scans EVERY postmeta, so a
            // builder's rendered-HTML cache meta re-captures the same link its editable source data
            // already provided (same to+anchor; the cache copy carries no element id). Keep every
            // elId row (distinct on-page elements — several identical buttons stay separate) and
            // drop elId-less rows that duplicate an elId row's to+anchor, collapsing repeated
            // elId-less copies to one.
            $links = is_array($scan['body']['links']) ? $scan['body']['links'] : array();
            $sourced = array(); // to|anchor keys owned by an elId (editable source) row
            foreach ($links as $l) {
                if ((string) ($l['elId'] ?? '') !== '') {
                    $sourced[(string) ($l['to'] ?? '') . '|' . (string) ($l['anchor'] ?? '')] = true;
                }
            }
            $seen_orphan = array();
            $links = array_values(array_filter($links, static function ($l) use ($sourced, &$seen_orphan) {
                if ((string) ($l['elId'] ?? '') !== '') {
                    return true;
                }
                $k = (string) ($l['to'] ?? '') . '|' . (string) ($l['anchor'] ?? '');
                if (isset($sourced[$k]) || isset($seen_orphan[$k])) {
                    return false;
                }
                $seen_orphan[$k] = true;
                return true;
            }));

            $out = array();
            $i = 0;
            foreach ($links as $l) {
                $to = (string) ($l['to'] ?? '');
                if ($to === '') { continue; }
                $host     = (string) (wp_parse_url($to, PHP_URL_HOST) ?: '');
                $internal = $host !== '' && $site_host !== '' && strcasecmp($host, $site_host) === 0;
                $status   = $check_status ? self::link_http_status($to) : 0;
                $anchor   = (string) ($l['anchor'] ?? '');
                $html     = (string) ($l['html'] ?? '');
                // Builder links (Elementor button widgets etc.) aren't <a> tags, so the connector
                // can't return real markup — synthesize a readable anchor so the HTML column isn't
                // blank. The To (URL) is the real, editable target.
                if ($html === '') {
                    $html = '<a href="' . esc_url($to) . '">' . esc_html($anchor !== '' ? $anchor : $to) . '</a>';
                }
                $out[] = array(
                    'id'       => $i++,
                    'anchor'   => $anchor,
                    'from'     => $from,
                    'to'       => $to,
                    'html'     => $html,
                    'status'   => $status,
                    'kind'     => $internal ? 'internal' : 'external',
                    'broken'   => $status >= 400,
                    'editable' => true, // edited via the connector's URL-replace (works in builder data)
                    'elId'     => (string) ($l['elId'] ?? ''), // builder element id → edit just this one
                );
            }
            return $out;
        }

        // Fallback (older connector / no connector): scan the post body only.
        $route = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
        $res = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('context' => 'edit', '_fields' => 'content,link'));
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return array();
        }
        $from = (string) ($res['body']['link'] ?? $site->url);
        $raw  = (string) ($res['body']['content']['raw'] ?? '');
        // Editable links live in the post's editable content (raw). When raw is empty
        // (a page-builder / block-stored layout), fall back to rendered HTML for VISIBILITY
        // but flag those links non-editable — so the popup shows them read-only instead of
        // letting a Save fail later as "link not found" / "not editable".
        $editable = ($raw !== '');
        $content  = $editable ? $raw : (string) ($res['body']['content']['rendered'] ?? '');
        $links    = self::scan_link_details($content, $from, (string) $site->url, $check_status);
        return array_values(array_map(static function ($l, $i) use ($editable) {
            $l['id']       = (int) $i;
            $l['editable'] = $editable;
            return $l;
        }, $links, array_keys($links)));
    }

    /** Fetch raw content, mutate the indexed link's <a> HTML via $build, PUT it back, re-read.
     *  $rendered_needle (a URL): if set and it does NOT appear in the page's rendered output
     *  after saving, the live page is produced by a page builder / template that ignores
     *  post_content — surfaced as an honest error instead of a misleading "saved". */
    private static function remote_rewrite_link_content(object $site, int $post_id, string $type, int $index, callable $build, ?string $rendered_needle = null)
    {
        self::ensure_sites_service();
        $route = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
        // context=edit returns content.raw — required to rewrite the post's stored content
        // (and needs an app password whose user can edit posts on the remote).
        $res = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('context' => 'edit', '_fields' => 'content,link'));
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return new WP_Error('pcm_seo_remote_link', __('Could not read the remote post — the connector’s app password may not have edit access.', 'power-creatives'), array('status' => 502));
        }
        $raw  = (string) ($res['body']['content']['raw'] ?? '');
        $from = (string) ($res['body']['link'] ?? $site->url);
        // No editable raw content (e.g. a page-builder layout stores it outside the editor) →
        // its links can't be rewritten via post content. Say so clearly rather than failing as
        // "link not found".
        if ($raw === '') {
            return new WP_Error('pcm_seo_no_raw', __('This page’s content isn’t editable through the API (e.g. a page-builder layout), so its links can’t be edited here.', 'power-creatives'), array('status' => 422));
        }
        $links = self::scan_link_details($raw, $from, (string) $site->url, false); // find by index; no HTTP checks
        if (!isset($links[$index])) {
            return new WP_Error('pcm_seo_link_not_found', __('Link not found — re-scan and try again.', 'power-creatives'), array('status' => 404));
        }
        $old_html = (string) $links[$index]['html'];
        $new_html = (string) $build($old_html, $links[$index]);
        $pos = self::nth_link_pos($raw, $links, $index);
        if ($pos === null) {
            return new WP_Error('pcm_seo_link_stale', __('The page changed — re-scan and try again.', 'power-creatives'), array('status' => 409));
        }
        $new_content = substr_replace($raw, $new_html, $pos, strlen($old_html));
        $put = PCM_Sites_Service::remote_rest($site, 'POST', $route, array(), array('content' => $new_content));
        if (is_wp_error($put)) {
            return new WP_Error('pcm_seo_remote_link', $put->get_error_message(), array('status' => 502));
        }
        if ((int) ($put['status'] ?? 0) >= 300) {
            return new WP_Error('pcm_seo_remote_link', sprintf(__('Could not save the remote post (HTTP %d) — the connector’s user may lack edit permission.', 'power-creatives'), (int) $put['status']), array('status' => 502));
        }

        // If the link's TARGET changed, ask the connector to replace the old URL across the post's
        // content + ALL custom fields (page builders store the layout in meta — Elementor as JSON,
        // Beaver/Divi as serialized arrays — and render from there, not post_content) + bust caches,
        // so the change appears on the live page. Returns {replaced, where[]}. Best-effort: a no-op
        // (404) on a connector older than the replace-url endpoint.
        $replace_count = null; // null = connector didn't answer; int = how many places changed
        $replace_builders = array(); // page builders the connector detected + regenerated
        $replace_diag = '';          // when null: WHY the builder-aware write didn't run (for the error)
        if ($rendered_needle === null) {
            $replace_diag = __('this was an anchor-text edit, not a URL change — anchor edits don’t yet have a builder-aware path, so they only save via the post body', 'power-creatives');
        } else {
            $old_url = (string) ($links[$index]['to'] ?? '');
            if ($old_url === '') {
                $replace_diag = __('the link has no source URL to match', 'power-creatives');
            } elseif ($old_url === $rendered_needle) {
                $replace_diag = __('the URL is unchanged', 'power-creatives');
            } else {
                // Generous timeout (60s): builder-aware replace re-reads ALL meta, regenerates the
                // builder + verifies — slow on big Elementor pages. A short timeout was making the
                // hub give up while the connector was still (successfully) finishing server-side.
                $rep = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/replace-url', array(), array(
                    'post_id' => $post_id,
                    'old'     => $old_url,
                    'new'     => $rendered_needle,
                ), 60);
                if (is_wp_error($rep)) {
                    $err = $rep->get_error_message();
                    $replace_diag = (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false || stripos($err, 'cURL error 28') !== false)
                        ? __('the connector took too long to respond (a large page) — the change likely DID apply on the site; re-scan and check, then clear the page/CDN cache', 'power-creatives')
                        : sprintf(__('the connector’s replace-url call failed (%s)', 'power-creatives'), $err);
                } elseif ((int) ($rep['status'] ?? 0) >= 300) {
                    $replace_diag = sprintf(__('the connector’s replace-url returned HTTP %d — likely blocked by a security plugin or Cloudflare bot protection on the connected site', 'power-creatives'), (int) ($rep['status'] ?? 0));
                } elseif (!isset($rep['body']['replaced'])) {
                    $replace_diag = __('the connector’s replace-url gave no result (an outdated connector, or a proxy stripped the response)', 'power-creatives');
                } else {
                    $replace_count = (int) $rep['body']['replaced'];
                    if (!empty($rep['body']['builders']) && is_array($rep['body']['builders'])) {
                        $replace_builders = array_map('strval', $rep['body']['builders']);
                    }
                }
            }
        }

        // Verify the edit actually persisted. Some remotes return 200 but keep the old content
        // (a security plugin locking REST writes, an aggressive page cache, or a user who can
        // read but not save) — which would otherwise show a misleading "saved". Re-read the raw
        // content and confirm it changed; if not, surface an honest error instead of success.
        $verify = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('context' => 'edit', '_fields' => 'content'));
        if (!is_wp_error($verify) && is_array($verify['body'] ?? null)) {
            $saved_raw = (string) ($verify['body']['content']['raw'] ?? '');
            // "Body unchanged" only counts as a failure when the connector ALSO didn't change
            // anything ($replace_count === null = its builder-aware /replace-url wasn't consulted
            // or didn't answer — e.g. an anchor-only edit, or a connector that can't reach it). On a
            // page builder the body is regenerated from the builder's meta on save, so post_content
            // legitimately reverts — but /replace-url has already updated the link in that meta
            // ($replace_count is 0 or >0). In that case fall through to the rendered-source check
            // below, which reports accurately (updated / cached / not found) instead of this
            // misleading "REST is locked" error.
            if ($saved_raw !== '' && $saved_raw === $raw && $replace_count === null) {
                $why = $replace_diag !== '' ? ' ' . sprintf(__('Reason: %s.', 'power-creatives'), $replace_diag) : '';
                return new WP_Error(
                    'pcm_seo_link_not_saved',
                    __('The remote site accepted the request but kept the old content, so the link was NOT changed.', 'power-creatives') . $why
                    . ' ' . __('Edit the To/URL (not the anchor) for builder pages; otherwise the post is likely locked to REST edits (a security plugin or Cloudflare), or the connector’s user can’t edit it — change it on the site, or check the connector’s app-password permissions.', 'power-creatives'),
                    array('status' => 409)
                );
            }

            // Rendered-source check: the edit DID save to post_content (raw changed above), but
            // if the new target doesn't appear in the page's RENDERED output, the live page is
            // produced by a page builder / theme template that ignores post_content — so the
            // change won't show. Tell the user honestly. (Compare on host+path so URL encoding of
            // query strings doesn't cause false negatives.)
            if ($rendered_needle !== null) {
                $rendered = (string) ($verify['body']['content']['rendered'] ?? '');
                $parts    = wp_parse_url($rendered_needle);
                $needle   = (string) ($parts['host'] ?? '') . (string) ($parts['path'] ?? '');
                if ($rendered !== '' && $needle !== '' && strpos($rendered, $needle) === false) {
                    // Use the connector's replace result to give an ACCURATE, actionable reason.
                    if ($replace_count === null) {
                        // The connector didn't answer the replace-url call — read its INSTALLED version
                        // so the user knows exactly whether the update actually took (vs. a different error).
                        $ver       = self::remote_connector_version($site);
                        $site_host = (string) (wp_parse_url((string) ($site->url ?? ''), PHP_URL_HOST) ?: ($site->name ?? __('the connected site', 'power-creatives')));
                        if ($ver !== '' && version_compare($ver, '2.0.0', '>=')) {
                            $msg = sprintf(__('Saved to the post content, but the live page is built by a page builder/theme that ignores it. The connector (v%s) is current but couldn’t apply the change to the builder — the post’s REST API may be restricted, or the page is hardcoded. Edit this link in the page builder on the site.', 'power-creatives'), $ver);
                        } elseif ($ver !== '') {
                            // The Connector is a SEPARATE plugin on the CONNECTED site — reinstalling
                            // Power Creatives on THIS hub never updates it. Spell that out so a hub
                            // reinstall isn't mistaken for a connector update.
                            $msg = sprintf(__('Saved to the post content, but the live page is built by a page builder/theme that ignores it. Builder-aware editing needs Connector v2.0.0+ — %1$s is still on v%2$s. The Connector is a SEPARATE plugin that lives on %1$s, NOT this hub, so reinstalling Power Creatives here will not update it. On %1$s: Plugins → Add New → Upload Plugin → the connector zip (get it from Sites → Download connector here) → “Replace current with uploaded” → Activate. Its version should then read 2.0.0 under Plugins on %1$s. Then try again.', 'power-creatives'), $site_host, $ver);
                        } else {
                            $msg = sprintf(__('Saved to the post content, but the live page is built by a page builder/theme that ignores it, and the connector didn’t respond. The Connector is a SEPARATE plugin on %1$s (not this hub): get the latest from Sites → Download connector, then on %1$s install it via Plugins → Add New → Upload → “Replace current with uploaded” → Activate (it should read 2.0.0 under Plugins), then try again.', 'power-creatives'), $site_host);
                        }
                    } elseif ($replace_count > 0) {
                        // We DID update builder data, but the rendered output is still old → render cache.
                        $in = !empty($replace_builders) ? sprintf(' (%s)', implode(', ', $replace_builders)) : '';
                        $msg = sprintf(__('Updated the link in %d place(s)%s on the connected site, but its cached/rendered output still shows the old link. Clear the site’s page + page-builder cache (or re-save the page in the builder) and it will update.', 'power-creatives'), $replace_count, $in);
                    } else {
                        // replaced === 0: the URL isn't in post content OR any custom field.
                        $msg = __('This link isn’t stored in the page’s content or any custom field on the connected site — it’s likely hardcoded in the theme template, a navigation menu, or a widget. Edit it there on the site.', 'power-creatives');
                    }
                    return new WP_Error('pcm_seo_link_not_rendered', $msg, array('status' => 409));
                }
            }
        }
        // Fast refresh: re-list links WITHOUT the per-link HTTP status checks (up to 30 ×
        // ~4s). Those checks on the edit response could push the whole request past the
        // remote/PHP time limit so the save appeared to "do nothing" — the popup just
        // needs the updated list; statuses refresh on the next explicit scan.
        return self::remote_get_links($site, $post_id, $type, false);
    }

    /** Edit a connected post's link (href/anchor), via the connector. */
    public static function remote_update_link(object $site, int $post_id, string $type, int $index, ?string $anchor, ?string $href, ?string $old_href = null, ?string $el_id = null, ?string $old_anchor = null)
    {
        // Builder-aware path: given the link's CURRENT url + a NEW url, replace it across the page's
        // content AND builder data via the connector — the only way to edit links the post-body scan
        // can't reach (Elementor/Divi button URLs live in meta, not post_content). When the link
        // carries a builder element id, the connector rewrites just THAT element (not every link with
        // the same URL).
        $new_url = ($href !== null && trim($href) !== '') ? esc_url_raw($href) : '';
        $old_url = $old_href !== null ? esc_url_raw($old_href) : '';
        $el      = (string) ($el_id ?? '');
        if ($new_url !== '' && $old_url !== '' && $new_url !== $old_url) {
            return self::remote_replace_link_url($site, $post_id, $type, $old_url, $new_url, $el);
        }

        // Builder-aware ANCHOR (link text) path: a builder link's text lives in meta, not post_content,
        // so the legacy rewrite below would fail on a page-builder page (empty content.raw → 422). When
        // the link carries an element id and only the anchor changed, update it via the connector.
        if ($el !== '' && $anchor !== null && $old_anchor !== null && (string) $anchor !== (string) $old_anchor) {
            return self::remote_replace_link_anchor($site, $post_id, $type, $el, ($old_url !== '' ? $old_url : $new_url), (string) $old_anchor, (string) $anchor);
        }

        // Legacy/anchor path: rewrite the indexed <a> in post_content (body links only).
        $needle = $new_url !== '' ? $new_url : null;
        return self::remote_rewrite_link_content($site, $post_id, $type, $index, static function ($old_html, $link) use ($anchor, $href) {
            $new_href = $href !== null ? esc_url_raw($href) : (string) $link['to'];
            $new_text = $anchor !== null ? wp_kses_post($anchor) : (string) $link['anchor'];
            $h = preg_replace_callback('/href=[\'"][^\'"]*[\'"]/i', static fn() => 'href="' . $new_href . '"', $old_html, 1);
            return preg_replace_callback('/(<a\s[^>]*>)(.*)(<\/a>)/is', static fn($m) => $m[1] . $new_text . $m[3], $h, 1);
        }, $needle);
    }

    /** Builder-aware remote URL edit: replace $old_url → $new_url across the post's content + ALL
     *  builder/custom-field data via the connector (/replace-url, 60s), then re-scan. Reaches links
     *  the post-body scan can't (Elementor/Divi button URLs, etc.). */
    private static function remote_replace_link_url(object $site, int $post_id, string $type, string $old_url, string $new_url, string $el_id = '')
    {
        self::ensure_sites_service();
        $body = array(
            'post_id' => $post_id,
            'old'     => $old_url,
            'new'     => $new_url,
        );
        if ($el_id !== '') { $body['elId'] = $el_id; } // edit just this element, not every same-URL link
        $rep = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/replace-url', array(), $body, 60);
        if (is_wp_error($rep)) {
            $err = $rep->get_error_message();
            $msg = (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false || stripos($err, 'cURL error 28') !== false)
                ? __('The connector took too long (large page) — the change likely applied; re-scan and clear the page/CDN cache.', 'power-creatives')
                : sprintf(__('Could not reach the connector to edit the link (%s).', 'power-creatives'), $err);
            return new WP_Error('pcm_seo_remote_link', $msg, array('status' => 502));
        }
        if ((int) ($rep['status'] ?? 0) >= 300) {
            return new WP_Error('pcm_seo_remote_link', sprintf(__('The connector rejected the edit (HTTP %d) — check its app-password user can edit, and that the connector is v2.1.0+.', 'power-creatives'), (int) ($rep['status'] ?? 0)), array('status' => 502));
        }
        if ((int) ($rep['body']['replaced'] ?? 0) === 0) {
            return new WP_Error('pcm_seo_link_not_found', __('That link wasn’t found in the page content or any builder field — it may be hardcoded in the theme, a menu, or a widget. Edit it on the site.', 'power-creatives'), array('status' => 409));
        }
        return self::remote_get_links($site, $post_id, $type, false); // refreshed (builder-aware) list
    }

    /** Builder-aware remote ANCHOR (link text) edit: update just the element $el_id's link text via
     *  the connector (/replace-anchor), then re-scan. Reaches builder-stored labels (Elementor button
     *  text etc.) the post_content rewrite can't. */
    private static function remote_replace_link_anchor(object $site, int $post_id, string $type, string $el_id, string $url, string $old_anchor, string $new_anchor)
    {
        self::ensure_sites_service();
        $rep = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/replace-anchor', array(), array(
            'post_id'   => $post_id,
            'elId'      => $el_id,
            'url'       => $url,
            'oldAnchor' => $old_anchor,
            'newAnchor' => $new_anchor,
        ), 60);
        if (is_wp_error($rep)) {
            return new WP_Error('pcm_seo_remote_link', sprintf(__('Could not reach the connector to edit the link text (%s).', 'power-creatives'), $rep->get_error_message()), array('status' => 502));
        }
        // A 404 with no body means the route itself is missing → connector too old. A 404 whose body
        // is the connector's own not_found payload means the remote post was deleted — let it fall
        // through to the generic error below rather than misreport "update the connector".
        if ((int) ($rep['status'] ?? 0) === 404 && (string) ($rep['body']['error'] ?? '') !== 'not_found') {
            return new WP_Error('pcm_seo_conn_old', __('Editing link text on a page-builder page needs the connector at v2.1.4+ — update the connector on the connected site.', 'power-creatives'), array('status' => 502));
        }
        if ((int) ($rep['status'] ?? 0) >= 300) {
            return new WP_Error('pcm_seo_remote_link', sprintf(__('The connector rejected the text edit (HTTP %d).', 'power-creatives'), (int) ($rep['status'] ?? 0)), array('status' => 502));
        }
        if ((int) ($rep['body']['replaced'] ?? 0) === 0) {
            return new WP_Error('pcm_seo_link_not_found', __('Couldn’t find that link’s text to edit — it may be split by formatting or an icon. Re-scan, or edit it on the site.', 'power-creatives'), array('status' => 409));
        }
        return self::remote_get_links($site, $post_id, $type, false); // refreshed (builder-aware) list
    }

    /** Remove a connected post's link (unwrap the <a>, keep text), via the connector. */
    public static function remote_remove_link(object $site, int $post_id, string $type, int $index)
    {
        return self::remote_rewrite_link_content($site, $post_id, $type, $index, static function ($old_html) {
            return preg_replace('/^<a\s[^>]*>(.*)<\/a>$/is', '$1', $old_html);
        });
    }

    /**
     * Read a connected site's hub-managed Site settings (robots.txt rules + JSON-LD)
     * via the connector's /pcm-conn/v1/site route.
     *
     * @return array{robots:string,jsonld:string}|\WP_Error
     */
    public static function remote_site_get(object $site)
    {
        self::ensure_sites_service();
        $result = self::remote_site_result(PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/site'));
        if ($result instanceof WP_Error) {
            return $result;
        }
        // Site Title + Tagline are WP core (blogname/blogdescription) — via /wp/v2/settings.
        $settings = PCM_Sites_Service::remote_rest($site, 'GET', '/wp/v2/settings');
        $sb = (!is_wp_error($settings) && is_array($settings['body'] ?? null)) ? $settings['body'] : array();
        $result['siteTitle'] = (string) ($sb['title'] ?? '');
        $result['tagline']   = (string) ($sb['description'] ?? '');
        return $result;
    }

    /**
     * Write a connected site's hub-managed Site settings. Only the provided keys are saved.
     *
     * @param array{robots?:string,jsonld?:string} $fields
     * @return array{robots:string,jsonld:string}|\WP_Error
     */
    /**
     * The connector tunables the HUB controls (config schema v1, cleanup C5).
     * Values live in one hub option; the seeded defaults equal the connector's
     * own code defaults, so an un-pushed fleet behaves byte-identically.
     * Filterable for per-install tuning until a dedicated UI is requested.
     */
    public static function get_connector_config(): array
    {
        $defaults = array(
            'snapshotCacheTtl' => 600,
            'loopbackTimeout'  => 8,
            'loopbackLockTtl'  => 15,
            'chromeRegions'    => array('header', 'nav', 'footer', 'aside'),
            'statsThrottle'    => 300,
            'overridesCap'     => 200,
        );
        $stored = get_option('pcm_seo_connector_config', array());
        $cfg    = is_array($stored) ? array_merge($defaults, array_intersect_key($stored, $defaults)) : $defaults;
        /** @param array $cfg Effective connector config the hub pushes. */
        return (array) apply_filters('pcm_seo_connector_config', $cfg);
    }

    /**
     * Push the hub's connector config to one site (v3+ only — older connectors
     * have no config store; returns pushed:false honestly, never an error, so
     * callers can fire-and-forget on mixed fleets).
     *
     * @return array{pushed:bool}
     */
    public static function push_connector_config(object $site): array
    {
        self::ensure_sites_service();
        if (self::connector_rules_schema_version($site) < 3) {
            return array('pushed' => false);
        }
        $res = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/config', array(), self::get_connector_config(), 30);
        return array('pushed' => !is_wp_error($res) && (int) ($res['status'] ?? 0) < 300);
    }

    public static function remote_site_save(object $site, array $fields)
    {
        self::ensure_sites_service();
        // robots + JSON-LD → the connector (/pcm-conn/v1/site).
        $body = array();
        if (array_key_exists('robots', $fields)) {
            $body['robots'] = (string) $fields['robots'];
        }
        if (array_key_exists('jsonld', $fields)) {
            $body['jsonld'] = (string) $fields['jsonld'];
        }
        if ($body) {
            $res = self::remote_site_result(PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/site', array(), $body));
            if ($res instanceof WP_Error) {
                return $res;
            }
        }
        // Site Title + Tagline → WP core /wp/v2/settings (blogname / blogdescription).
        $settings = array();
        if (array_key_exists('siteTitle', $fields) && trim((string) $fields['siteTitle']) !== '') {
            $settings['title'] = (string) $fields['siteTitle'];
        }
        if (array_key_exists('tagline', $fields)) {
            $settings['description'] = (string) $fields['tagline'];
        }
        if ($settings) {
            $set = PCM_Sites_Service::remote_rest($site, 'POST', '/wp/v2/settings', array(), $settings);
            if (is_wp_error($set)) {
                return new WP_Error('pcm_seo_remote_site', $set->get_error_message(), array('status' => 502));
            }
            if ((int) ($set['status'] ?? 0) >= 300) {
                return new WP_Error('pcm_seo_remote_site', __('Could not save the site title/tagline on the remote site.', 'power-creatives'), array('status' => 502));
            }
        }
        // Config rides the same channel (cleanup C5): every successful site
        // save re-syncs the hub-controlled tunables. Best-effort by design —
        // a config miss must never fail a robots/title save.
        self::push_connector_config($site);
        return self::remote_site_get($site);
    }

    /** Normalise a /pcm-conn/v1/site proxy response into the Site shape or a WP_Error. */
    private static function remote_site_result($res)
    {
        if (is_wp_error($res)) {
            return new WP_Error('pcm_seo_remote_site', $res->get_error_message(), array('status' => 502));
        }
        $status = (int) ($res['status'] ?? 0);
        if (404 === $status || 401 === $status || 403 === $status) {
            return new WP_Error(
                'pcm_seo_connector_outdated',
                __('This site needs the latest Power Creatives connector (re-download it from Add Site) to manage Site settings.', 'power-creatives'),
                array('status' => 422)
            );
        }
        if ($status >= 300) {
            return new WP_Error('pcm_seo_remote_site', __('Could not reach the remote site settings.', 'power-creatives'), array('status' => 502));
        }
        $b = is_array($res['body'] ?? null) ? $res['body'] : array();
        return array('robots' => (string) ($b['robots'] ?? ''), 'jsonld' => (string) ($b['jsonld'] ?? ''));
    }

    /**
     * Read a connected site's hub-managed AI Readiness (llms.txt) via the connector's
     * /pcm-conn/v1/ai route.
     *
     * @return array{llms:string,enabled:bool,url:string}|\WP_Error
     */
    public static function remote_ai_get(object $site)
    {
        self::ensure_sites_service();
        $res = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/ai');
        return self::remote_ai_result($res);
    }

    /**
     * Write a connected site's AI Readiness settings. Only the provided keys are saved.
     *
     * @param array{llms?:string,enabled?:bool} $fields
     * @return array{llms:string,enabled:bool,url:string}|\WP_Error
     */
    public static function remote_ai_save(object $site, array $fields)
    {
        self::ensure_sites_service();
        $body = array();
        if (array_key_exists('llms', $fields)) {
            $body['llms'] = (string) $fields['llms'];
        }
        if (array_key_exists('enabled', $fields)) {
            $body['enabled'] = (bool) $fields['enabled'];
        }
        $res = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/ai', array(), $body);
        return self::remote_ai_result($res);
    }

    /** Normalise a /pcm-conn/v1/ai proxy response into the AI shape or a WP_Error. */
    private static function remote_ai_result($res)
    {
        if (is_wp_error($res)) {
            return new WP_Error('pcm_seo_remote_ai', $res->get_error_message(), array('status' => 502));
        }
        $status = (int) ($res['status'] ?? 0);
        if (in_array($status, array(401, 403, 404), true)) {
            return new WP_Error(
                'pcm_seo_connector_outdated',
                __('This site needs the latest Power Creatives connector (re-download it from Add Site) to manage AI Readiness.', 'power-creatives'),
                array('status' => 422)
            );
        }
        if ($status >= 300) {
            return new WP_Error('pcm_seo_remote_ai', __('Could not reach the remote AI Readiness settings.', 'power-creatives'), array('status' => 502));
        }
        $b = is_array($res['body'] ?? null) ? $res['body'] : array();
        return array('llms' => (string) ($b['llms'] ?? ''), 'enabled' => !empty($b['enabled']), 'url' => (string) ($b['url'] ?? ''));
    }

    /**
     * Build an llms.txt index from a connected site's published posts/pages (over the
     * proxy). Returns the generated text — the caller stages/saves it; nothing is written
     * to the remote here.
     */
    public static function remote_ai_build(object $site, string $desc = ''): string
    {
        $rows  = self::remote_list_content($site);
        $title = ($site->name ?? '') !== '' ? $site->name : (string) $site->url;
        $lines = array('# ' . $title, '');
        $desc  = trim($desc);
        if ($desc !== '') {
            $lines[] = '> ' . $desc;
            $lines[] = '';
        }
        $posts = array();
        $pages = array();
        foreach ($rows as $r) {
            if (($r['status'] ?? '') !== 'publish') {
                continue;
            }
            $t    = ($r['title'] ?? '') !== '' ? (string) $r['title'] : '(untitled)';
            $u    = (string) ($r['permalink'] ?? '');
            $e    = (string) ($r['excerpt'] ?? '');
            $line = '- [' . $t . '](' . $u . ')' . ($e !== '' ? ': ' . $e : '');
            if (($r['type'] ?? 'post') === 'page') {
                $pages[] = $line;
            } else {
                $posts[] = $line;
            }
        }
        if ($pages) {
            $lines[] = '## Pages';
            $lines   = array_merge($lines, $pages, array(''));
        }
        if ($posts) {
            $lines[] = '## Posts';
            $lines   = array_merge($lines, $posts, array(''));
        }
        return implode("\n", $lines);
    }

    /**
     * List a connected site's published posts/pages for the AI Readiness table — each with
     * its live `/{slug}.md` URL (served by the connector). Read-only; no connector change.
     *
     * @return array<int,array{id:int,title:string,type:string,status:string,mdUrl:string}>
     */
    public static function remote_ai_posts(object $site): array
    {
        $out = array();
        foreach (self::remote_list_content($site) as $r) {
            if (($r['status'] ?? '') !== 'publish') {
                continue;
            }
            $permalink = (string) ($r['permalink'] ?? '');
            $out[]     = array(
                'id'     => (int) ($r['id'] ?? 0),
                'title'  => ($r['title'] ?? '') !== '' ? (string) $r['title'] : '(untitled)',
                'type'   => (string) ($r['type'] ?? 'post'),
                'status' => (string) ($r['status'] ?? ''),
                'mdUrl'  => $permalink !== '' ? rtrim($permalink, '/') . '.md' : '',
            );
        }
        return $out;
    }

    /**
     * AI-generate a 1–2 sentence description for a connected site from its top page titles.
     *
     * @return array{description:string}|\WP_Error
     */
    public static function remote_ai_site_desc(object $site, ?string $model = null, ?int $user_id = null, ?string $provider = null)
    {
        if (!class_exists('PCM_LLM')) {
            return new WP_Error('pcm_seo_no_llm', __('AI provider is unavailable.', 'power-creatives'), array('status' => 500));
        }
        $titles = array();
        foreach (self::remote_list_content($site) as $r) {
            if (($r['status'] ?? '') === 'publish' && ($r['title'] ?? '') !== '') {
                $titles[] = '- ' . (string) $r['title'];
            }
            if (count($titles) >= 12) {
                break;
            }
        }
        $name   = ($site->name ?? '') !== '' ? $site->name : (string) $site->url;
        $prompt = "Write a concise 1-2 sentence description of this website for an AI/LLM index file (llms.txt). "
            . "Factual, answer-first, no marketing fluff. Plain text only — no quotes, labels, or markdown.\n\n"
            . "Site name: {$name}\nKey pages:\n" . implode("\n", $titles);
        try {
            $opts = array('max_tokens' => 120);
            if (!empty($model)) {
                $opts['model'] = $model;
            }
            if (!empty($provider)) {
                $opts['provider'] = $provider;
            }
            $res = PCM_LLM::invoke(array(array('role' => 'user', 'content' => $prompt)), $opts);
            $d   = trim((string) ($res['content'] ?? ''), " \t\n\r\"'");
            if ($d === '') {
                return new WP_Error('pcm_seo_empty', __('The model returned no text — try again.', 'power-creatives'), array('status' => 502));
            }
            return array('description' => $d);
        } catch (\Throwable $e) {
            return new WP_Error('pcm_seo_generate_failed', $e->getMessage(), array('status' => 502));
        }
    }

    /**
     * Set a connected post's Schema.org types (stored as the pcm_seo_schema meta, a JSON
     * array). Validates against the whitelist + verifies the meta landed (needs the
     * connector to expose pcm_seo_schema).
     *
     * @return array{types:string[]}|\WP_Error
     */
    public static function remote_set_schema(object $site, int $post_id, string $type, array $types)
    {
        self::ensure_sites_service();
        $allowed = class_exists('PCM_SEO_Schema') ? PCM_SEO_Schema::TYPES : array();
        $clean   = array_values(array_intersect($allowed, array_map('strval', $types)));
        $route   = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
        $payload = array('meta' => array('pcm_seo_schema' => wp_json_encode($clean)));
        $res     = PCM_Sites_Service::remote_rest($site, 'POST', $route, array(), $payload);
        if (is_wp_error($res)) {
            return new WP_Error('pcm_seo_remote_schema', $res->get_error_message(), array('status' => 502));
        }
        if ((int) ($res['status'] ?? 0) >= 300) {
            return new WP_Error('pcm_seo_remote_schema', __('Could not save schema to the remote site.', 'power-creatives'), array('status' => 502));
        }
        // WordPress silently drops unregistered meta and still returns 200 — confirm it landed.
        $verify = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('_fields' => 'meta'));
        $saved  = (!is_wp_error($verify) && isset($verify['body']['meta']['pcm_seo_schema']))
            ? json_decode((string) $verify['body']['meta']['pcm_seo_schema'], true)
            : null;
        if (!is_array($saved)) {
            return new WP_Error(
                'pcm_seo_remote_meta_unsupported',
                __('The remote site didn’t store schema — install/update the Power Creatives connector on that site.', 'power-creatives'),
                array('status' => 422)
            );
        }
        return array('types' => array_values(array_intersect($allowed, array_map('strval', $saved))));
    }

    /**
     * Build an /llm-info/ page body — an AI-search-optimized, positively-framed business
     * overview (semantic HTML). Shared by the local + connected-site generators. The prompt
     * is told to use ONLY the facts provided (no invented reviews/ratings/awards).
     *
     * @param array $ctx  { name, url, keywords, years, area, strengths, category, rating, reviews, address }
     * @return string|\WP_Error  Sanitized HTML body content.
     */
    public static function build_llm_info(array $ctx, ?string $model = null, ?int $user_id = null, ?string $provider = null)
    {
        if (!class_exists('PCM_LLM')) {
            return new WP_Error('pcm_seo_no_llm', __('AI provider is unavailable.', 'power-creatives'), array('status' => 500));
        }
        // Auto-derive target keywords from the site's own content when none were provided,
        // so the summary is optimized for what the site actually ranks/talks about.
        if (trim((string) ($ctx['keywords'] ?? '')) === '' && !empty($ctx['pages']) && is_array($ctx['pages'])) {
            $ctx['keywords'] = self::keywords_to_string(self::top_keywords($ctx['pages']));
        }
        $prompt = self::llm_info_prompt($ctx);
        try {
            $opts = array('max_tokens' => 1400);
            if (!empty($model)) {
                $opts['model'] = $model;
            }
            if (!empty($provider)) {
                $opts['provider'] = $provider;
            }
            $result = PCM_LLM::invoke(array(array('role' => 'user', 'content' => $prompt)), $opts);
            $html   = trim((string) ($result['content'] ?? ''));
            $html   = trim(preg_replace('#^```[a-z]*\s*|\s*```$#i', '', $html)); // strip stray code fences
            if ($html === '') {
                return new WP_Error('pcm_seo_empty', __('The model returned no text — try again.', 'power-creatives'), array('status' => 502));
            }
            return wp_kses_post($html);
        } catch (\Throwable $e) {
            return new WP_Error('pcm_seo_generate_failed', $e->getMessage(), array('status' => 502));
        }
    }

    /**
     * Most-used keywords across a content corpus (title + text), for /llm-info/ auto-fill.
     * Counts single words plus adjacent two-word phrases (bigrams give useful phrases like
     * "emergency plumber"), minus common English stopwords. Pure — no WP calls — so it's
     * unit-testable and works identically for local + remote corpora.
     *
     * @param array $pages  [ ['title'=>, 'text'=>], ... ] as from local_/remote_content_corpus().
     * @param int   $limit  Max terms to return.
     * @return array<int,array{term:string,count:int}>  Ranked most → least frequent.
     */
    public static function top_keywords(array $pages, int $limit = 12): array
    {
        static $stop = null;
        if ($stop === null) {
            $stop = array_flip(array_merge(
                // English noise
                explode(' ', 'the a an and or but if then else of to in on at by for from with without into onto over under about as is are was were be been being it its it\'s this that these those i you he she we they them us our your his her their my me him do does did done has have had having not no nor so than too very can will just should now also more most other some such only own same up down out off then once here there all any both each few how what when where which who whom why your yours we\'re you\'re our ours us get got new one two three per via etc com www http https'),
                // Swedish noise (connected sites are often Swedish — function words must not
                // surface as "keywords": att/och/till/som/kan/din/det/med etc.)
                explode(' ', 'och att det som en ett på är av för med till den de i om så men har du din dina ditt vi ni han hon vad var när här där kan ska skall vill från eller hur alla vid mycket också bara bli blir bra då sedan efter innan under över mellan genom mot utan samt både dess denna detta dessa vara varit hos man sig sin sina sitt oss er ert era mig dig honom henne dem vem vilken vilket vilka något någon några ingen inget inga annan annat andra samma nya ny nytt mer mest än upp ner ut in hela även redan kommer kommit gör göra gjort finns fanns fick få får'),
                // HTML-entity residue (when un-decoded copies slip into a corpus)
                explode(' ', 'nbsp amp quot apos middot ndash mdash hellip rsquo lsquo rdquo ldquo')
            ));
        }
        $text = '';
        foreach ($pages as $p) {
            $text .= ' ' . (string) ($p['title'] ?? '') . ' ' . (string) ($p['text'] ?? '');
        }
        // Decode entities (&middot; &amp; …) and keep UNICODE letters — the previous
        // [^a-z0-9] pass destroyed å/ä/ö words, so Swedish keywords vanished while
        // stopwords dominated the ranking.
        $text  = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text  = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text  = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $words = preg_split('/\s+/u', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: array();

        $len    = static function (string $w): int {
            return function_exists('mb_strlen') ? mb_strlen($w, 'UTF-8') : strlen($w);
        };
        $tokens = array();
        foreach ($words as $w) {
            if ($len($w) < 3 || ctype_digit($w) || isset($stop[$w])) {
                continue;
            }
            $tokens[] = $w;
        }

        $counts = array();
        $n      = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $counts[$tokens[$i]] = ($counts[$tokens[$i]] ?? 0) + 1;
            if ($i + 1 < $n) {
                $bi          = $tokens[$i] . ' ' . $tokens[$i + 1];
                $counts[$bi] = ($counts[$bi] ?? 0) + 1;
            }
        }
        arsort($counts);

        $out = array();
        foreach ($counts as $term => $count) {
            if ($count < 2) { // ignore one-off noise
                continue;
            }
            $out[] = array('term' => (string) $term, 'count' => (int) $count);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Flatten top_keywords() into a comma-separated string for the generator / keywords field. */
    public static function keywords_to_string(array $list, int $limit = 8): string
    {
        $terms = array();
        foreach ($list as $row) {
            $t = trim((string) ($row['term'] ?? ''));
            if ($t !== '') {
                $terms[] = $t;
            }
            if (count($terms) >= $limit) {
                break;
            }
        }
        return implode(', ', $terms);
    }

    /** The persuasive, truthful /llm-info/ prompt — only emits guidance for facts that are present. */
    private static function llm_info_prompt(array $ctx): string
    {
        $g         = static fn ($k) => trim((string) ($ctx[$k] ?? ''));
        $name      = $g('name') !== '' ? $g('name') : 'the business';
        $area      = $g('area');
        $years     = $g('years');
        $keywords  = $g('keywords');
        $strengths = $g('strengths');

        $facts = "Business name: {$name}\n";
        foreach (array(
            'url'       => 'Website',
            'category'  => 'Niche / category',
            'area'      => 'Primary area served',
            'years'     => 'Years in business',
            'keywords'  => 'Target keywords to rank for',
            'strengths' => 'Key strengths / recommendations',
            'address'   => 'Address',
        ) as $k => $label) {
            if ($g($k) !== '') {
                $facts .= "{$label}: " . $g($k) . "\n";
            }
        }
        if ($g('rating') !== '') {
            $facts .= 'Average rating: ' . $g('rating') . ($g('reviews') !== '' ? ' from ' . $g('reviews') . ' reviews' : '') . "\n";
        }

        // Site content corpus — the actual pages/posts the summary is generated FROM.
        $corpus = '';
        $pages  = (isset($ctx['pages']) && is_array($ctx['pages'])) ? $ctx['pages'] : array();
        if ($pages) {
            $budget = 14000;
            $used   = 0;
            $parts  = array();
            foreach ($pages as $pg) {
                $t = trim((string) ($pg['title'] ?? ''));
                $x = trim((string) ($pg['text'] ?? ''));
                if ($x === '') {
                    continue;
                }
                $block = ($t !== '' ? "## {$t}\n" : '') . $x;
                $used += strlen($block);
                if ($used > $budget) {
                    break;
                }
                $parts[] = $block;
            }
            if ($parts) {
                $corpus = "\nSITE CONTENT (the business's actual pages — base the summary on what they really do, their services, topics and expertise here; do not contradict or invent beyond it):\n"
                    . implode("\n\n", $parts) . "\n";
            }
        }

        return "You are writing the content for an /llm-info/ page — a concise, factual overview of a business, written so AI search engines (ChatGPT, Perplexity, Google AI Overviews) cite it accurately and favourably.\n\n"
            . "FACTS (use ONLY these — never invent reviews, ratings, awards, numbers, or any claim not given):\n{$facts}\n"
            . $corpus
            . "Write the page as clean semantic HTML body content. Rules:\n"
            . ($corpus !== '' ? "- Base the summary on the SITE CONTENT above — reflect the real services, topics and expertise found across the pages; if the FACTS and the content conflict, prefer the content.\n" : '')
            . "- Use only <h1>, <h2>, <h3>, <p>, <ul>, <li>, <strong>, <a> tags. No <html>/<head>/<body>, no markdown, no code fences.\n"
            . "- Open with a one-paragraph positioning summary naming {$name} and its specialist niche/expertise"
            . ($area !== '' ? ", and that it serves {$area}" : '') . ".\n"
            . ($keywords !== '' ? "- Naturally weave in the target keywords (no keyword stuffing).\n" : '')
            . "- Establish authority and specialist expertise within the niche.\n"
            . ($years !== '' ? "- Frame the years in business as a proven, trusted track record.\n" : '')
            . ($area !== '' ? "- Emphasise how local and dedicated the business is to {$area}.\n" : '')
            . ($strengths !== '' ? "- Present the strengths/recommendations positively — but only the facts provided.\n" : '')
            . "- Sections: an intro, \"What {$name} does\", \"Why choose {$name}\""
            . ($area !== '' ? ", \"Areas served\"" : '') . ", and a brief FAQ if useful.\n"
            . "- Be truthful and specific. Omit anything not provided. Output ONLY the HTML body content.";
    }

    /** Gather the local site's published pages/posts as a trimmed text corpus for /llm-info/. */
    public static function local_content_corpus(int $max_pages = 50, int $per_chars = 500): array
    {
        $posts = get_posts(array(
            'post_type'   => array('page', 'post'),
            'post_status' => 'publish',
            'numberposts' => $max_pages,
        ));
        $out = array();
        foreach ($posts as $p) {
            $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(strip_shortcodes((string) $p->post_content))));
            if ($text === '') {
                continue;
            }
            $out[] = array('title' => (string) $p->post_title, 'text' => mb_substr($text, 0, $per_chars));
        }
        return $out;
    }

    /** Gather a connected site's published pages/posts as a trimmed text corpus (via proxy). */
    public static function remote_content_corpus(object $site, int $max_pages = 50, int $per_chars = 500): array
    {
        self::ensure_sites_service();
        $out = array();
        foreach (array('/wp/v2/pages', '/wp/v2/posts') as $route) {
            $res = PCM_Sites_Service::remote_rest($site, 'GET', $route, array(
                'per_page' => min($max_pages, 100),
                'status'   => 'publish',
                '_fields'  => 'title,content',
            ));
            if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
                continue;
            }
            foreach ($res['body'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string) ($item['content']['rendered'] ?? ''))));
                if ($text === '') {
                    continue;
                }
                $out[] = array('title' => (string) ($item['title']['rendered'] ?? ''), 'text' => mb_substr($text, 0, $per_chars));
                if (count($out) >= $max_pages) {
                    break 2;
                }
            }
        }
        return $out;
    }

    /** Read a connected site's /llm-info/ (content + enabled + url) via the connector. */
    public static function remote_llminfo_get(object $site)
    {
        self::ensure_sites_service();
        return self::remote_llminfo_result(PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/llm-info'));
    }

    /** Write a connected site's /llm-info/ content + enabled flag (only provided keys). */
    public static function remote_llminfo_save(object $site, array $fields)
    {
        self::ensure_sites_service();
        $body = array();
        if (array_key_exists('content', $fields)) {
            $body['content'] = (string) $fields['content'];
        }
        if (array_key_exists('enabled', $fields)) {
            $body['enabled'] = (bool) $fields['enabled'];
        }
        return self::remote_llminfo_result(PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/llm-info', array(), $body));
    }

    /** Normalise a /pcm-conn/v1/llm-info proxy response into the shape or a WP_Error. */
    private static function remote_llminfo_result($res)
    {
        if (is_wp_error($res)) {
            return new WP_Error('pcm_seo_remote_llminfo', $res->get_error_message(), array('status' => 502));
        }
        $status = (int) ($res['status'] ?? 0);
        if (in_array($status, array(401, 403, 404), true)) {
            return new WP_Error(
                'pcm_seo_connector_outdated',
                __('This site needs the latest Power Creatives connector (re-download it from Add Site) to manage /llm-info/.', 'power-creatives'),
                array('status' => 422)
            );
        }
        if ($status >= 300) {
            return new WP_Error('pcm_seo_remote_llminfo', __('Could not reach the remote /llm-info/ settings.', 'power-creatives'), array('status' => 502));
        }
        $b = is_array($res['body'] ?? null) ? $res['body'] : array();
        return array('content' => (string) ($b['content'] ?? ''), 'enabled' => !empty($b['enabled']), 'url' => (string) ($b['url'] ?? ''));
    }

    /** Generate /llm-info/ HTML for a connected site (not saved — the caller saves on accept). */
    public static function remote_llminfo_build(object $site, array $inputs, ?string $model = null, ?int $user_id = null, ?string $provider = null)
    {
        $html = self::build_llm_info(array(
            'name'      => ($site->name ?? '') !== '' ? $site->name : (string) $site->url,
            'url'       => (string) $site->url,
            'keywords'  => (string) ($inputs['keywords'] ?? ''),
            'years'     => (string) ($inputs['years'] ?? ''),
            'area'      => (string) ($inputs['area'] ?? ''),
            'strengths' => (string) ($inputs['strengths'] ?? ''),
            'pages'     => self::remote_content_corpus($site),
        ), $model, $user_id, $provider);
        if ($html instanceof WP_Error) {
            return $html;
        }
        return array('content' => $html);
    }

    /** Prompt vars for a remote post (business context = the connected site). */
    private static function remote_field_vars(object $site, array $row): array
    {
        $url    = (string) $site->url;
        $host   = (string) wp_parse_url($url, PHP_URL_HOST);
        $name   = !empty($site->name) ? (string) $site->name : $host;
        $locale = get_locale();
        return array(
            'title'                     => (string) ($row['title'] ?? ''),
            'primary_keyword'           => (string) ($row['primaryKeyword'] ?? ''),
            'supporting_keyword'        => (string) ($row['supportingKeyword'] ?? ''),
            'meta_title'                => (string) ($row['metaTitle'] ?? ''),
            'meta_description'          => (string) ($row['metaDescription'] ?? ''),
            'post_type'                 => (string) ($row['type'] ?? ''),
            'site.lang'                 => $locale ? substr($locale, 0, 2) : 'en',
            'website.url'               => $url,
            'today'                     => gmdate('Y-m-d'),
            'business.name'             => $name,
            'business.tagline'          => '',
            'business.website'          => $url,
            'business.website|hostname' => $host,
            'business.address'          => '',
            'business.phone'            => '',
            'business.category'         => '',
            'business.hours'            => '',
            'business.rating'           => '',
            'business.lat'              => '',
            'business.lng'              => '',
            'business.types'            => '',
        );
    }

    /**
     * AI-generate (or optimize) one SEO field for a connected site's post/page.
     * Fetches the remote post, builds prompt vars from it, runs the SAME prompt +
     * LLM as the local generator, and returns the suggestion WITHOUT saving (the
     * caller stages it; remote_save_cell persists on accept).
     *
     * @return array{field:string,value:string}|\WP_Error
     */
    public static function remote_generate_field(object $site, int $post_id, string $type, string $field, ?string $model = null, ?int $user_id = null, ?string $provider = null, ?int $template_id = null)
    {
        $use_map = self::field_use_map();
        if (!isset($use_map[$field])) {
            return new WP_Error('pcm_seo_not_generatable', __('This field cannot be AI-generated.', 'power-creatives'), array('status' => 400));
        }
        $use     = $use_map[$field];
        $prompts = self::field_prompts();
        if (!isset($prompts[$use])) {
            return new WP_Error('pcm_seo_no_prompt', __('No prompt configured for this field.', 'power-creatives'), array('status' => 500));
        }
        self::ensure_sites_service();

        // Fetch the remote post so the prompt has its title + current SEO meta.
        $route = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
        $res   = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('_fields' => 'id,title,slug,link,author,meta'));
        if (is_wp_error($res)) {
            return new WP_Error('pcm_seo_remote_fetch', $res->get_error_message(), array('status' => 502));
        }
        if ((int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return new WP_Error('pcm_seo_remote_fetch', __('Could not read the remote post.', 'power-creatives'), array('status' => 502));
        }
        $row  = self::remote_row($res['body'], $type, $site);
        $vars = self::remote_field_vars($site, $row);

        // Current value of THIS field (drives optimize vs generate).
        $current_map = array(
            'title' => 'title', 'slug' => 'slug', 'metaTitle' => 'metaTitle',
            'metaDescription' => 'metaDescription', 'primaryKeyword' => 'primaryKeyword',
            'metaKeywords' => 'metaKeywords',
        );
        $current = (string) ($row[$current_map[$field] ?? ''] ?? '');
        $vars['current_value'] = $current;

        $mode    = (!empty($current) && !empty($prompts[$use]['optimize'])) ? 'optimize' : 'generate';
        $default = $prompts[$use][$mode];
        $tpl     = self::resolve_prompt($use . '_' . $mode, $default, $user_id, $template_id);
        $prompt  = self::substitute_vars($tpl, $vars);
        $max     = (int) ($prompts[$use]['max'] ?? 200);

        if (!class_exists('PCM_LLM')) {
            return new WP_Error('pcm_seo_no_llm', __('AI provider is unavailable.', 'power-creatives'), array('status' => 500));
        }
        try {
            $opts = array('max_tokens' => $max);
            if (!empty($model)) {
                $opts['model'] = $model;
            }
            if (!empty($provider)) {
                $opts['provider'] = $provider;
            }
            $result = PCM_LLM::invoke(array(array('role' => 'user', 'content' => $prompt)), $opts);
            $value  = self::sanitize_ai_output((string) ($result['content'] ?? ''));
            // The slug field must be a valid URL slug — slugify whatever the model
            // returned (handles chatty output / spaces / casing reliably).
            if ($field === 'slug') {
                $value = sanitize_title($value);
            }
            if ($value === '') {
                return new WP_Error('pcm_seo_empty', __('The model returned no text — try again.', 'power-creatives'), array('status' => 502));
            }
            return array('field' => $field, 'value' => $value);
        } catch (\Throwable $e) {
            return new WP_Error('pcm_seo_generate_failed', $e->getMessage(), array('status' => 502));
        }
    }

    // =====================================================================
    // REMOTE HEADINGS (connected sites, via the connector)
    // =====================================================================

    /** Every H1–H6 on a connected post. v3 connectors: ONE snapshot, parsed hub-side
     *  (cleanup C2/C3 — /scan-headings no longer exists there). Pre-3.0 fleet:
     *  builder-aware /scan-headings (v2.1.7+), then a post-body-only parse of
     *  content.raw on older connectors. */
    public static function remote_get_headings(object $site, int $post_id, string $type, ?int $user_id = null): array
    {
        self::ensure_sites_service();
        $inv = self::served_inventory($site, $post_id, $user_id);
        if ($inv !== null) {
            return $inv['headings'];
        }
        $scan = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/scan-headings', array('post_id' => $post_id));
        if (!is_wp_error($scan) && (int) ($scan['status'] ?? 0) < 300 && is_array($scan['body']['headings'] ?? null)) {
            $out = array(); $i = 0;
            foreach ($scan['body']['headings'] as $h) {
                $text = trim((string) ($h['text'] ?? ''));
                if ($text === '') { continue; }
                $out[] = array(
                    'index'    => $i,
                    'id'       => $i,
                    'level'    => max(1, min(6, (int) ($h['level'] ?? 2))),
                    'text'     => $text,
                    'html'     => (string) ($h['html'] ?? ''),
                    'source'   => (string) ($h['source'] ?? 'content'),
                    'elId'     => (string) ($h['elId'] ?? ''),
                    'field'    => (string) ($h['field'] ?? ''),
                    'tagKey'   => (string) ($h['tagKey'] ?? ''),
                    'textKey'  => (string) ($h['textKey'] ?? ''),
                    // Shared-source headings (Elementor Theme Builder templates / reusable blocks):
                    // the OWNING post id the edit must target, + a label so the UI can warn it
                    // changes every page using that source. 0 / '' for a page's own headings.
                    'sourcePostId' => (int) ($h['sourcePostId'] ?? 0),
                    'sourceType'   => (string) ($h['sourceType'] ?? ''),
                    'sourceLabel'  => (string) ($h['sourceLabel'] ?? ''),
                    'editable' => true,
                );
                $i++;
            }
            return $out;
        }

        // Fallback: parse the post body only (older / missing connector).
        $route = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
        $res = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('context' => 'edit', '_fields' => 'content'));
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return array();
        }
        $raw      = (string) ($res['body']['content']['raw'] ?? '');
        $editable = ($raw !== '');
        $content  = $editable ? $raw : (string) ($res['body']['content']['rendered'] ?? '');
        $list     = self::parse_heading_details($content);
        return array_values(array_map(static function ($h, $i) use ($editable) {
            $h['index']    = (int) $i;
            $h['id']       = (int) $i;
            $h['editable'] = $editable;
            return $h;
        }, $list, array_keys($list)));
    }

    /** Edit a connected post's heading (text and/or level) via the connector: builder-FIELD headings
     *  (Elementor/Bricks widgets) through /replace-heading, content/inline-HTML headings through the
     *  builder-aware /replace-url (oldHtml → newHtml). Returns the refreshed heading list. */
    /** Which post an edit must write to: a shared-source heading (Elementor Theme Builder template /
     *  reusable block) targets its OWNING post (`sourcePostId`); a page's own heading targets the page.
     *  Pure — unit-tested. */
    public static function heading_target_post_id(array $h, int $page_post_id): int
    {
        $src = (int) ($h['sourcePostId'] ?? 0);
        return $src > 0 ? $src : $page_post_id;
    }

    /** Apply a heading edit through the connector's render-time OVERRIDE layer (connector 2.6.0+):
     *  rewrites the matching `<hN>text</hN>` in the page output regardless of how/where the builder
     *  stores it. This is the universal path for headings whose stored form the builder-field /
     *  content replace can't reach (Brizy & other builders store headings as STRUCTURED nodes, not
     *  inline HTML, and regenerate their compiled HTML — so a string replace finds nothing / is lost).
     *  Returns the refreshed heading list on success, WP_Error otherwise. */
    private static function remote_apply_heading_override(object $site, int $post_id, string $type, string $old_text, int $old_level, ?string $new_text, int $new_level)
    {
        $rep = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/override-heading', array(), array(
            'post_id'  => $post_id,
            'oldText'  => $old_text,
            'oldLevel' => $old_level,
            'newText'  => ($new_text !== null ? $new_text : $old_text),
            'newLevel' => $new_level,
        ), 60);
        if (is_wp_error($rep)) {
            return new WP_Error('pcm_seo_remote_heading', $rep->get_error_message(), array('status' => 502));
        }
        if ((int) ($rep['status'] ?? 0) === 404) {
            return new WP_Error('pcm_seo_connector_outdated', __('This site needs connector v2.6.0+ to edit this heading — push it via Sites → "Update connectors" (or reinstall once), then retry.', 'power-creatives'), array('status' => 409));
        }
        if ((int) ($rep['status'] ?? 0) >= 300 || (int) ($rep['body']['replaced'] ?? 0) === 0) {
            return new WP_Error('pcm_seo_remote_heading', sprintf(__('The connector rejected the heading override (HTTP %d).', 'power-creatives'), (int) ($rep['status'] ?? 0)), array('status' => 502));
        }
        return self::remote_get_headings($site, $post_id, $type);
    }

    public static function remote_update_heading(object $site, int $post_id, string $type, int $index, ?string $text, ?int $level, ?int $user_id = null)
    {
        self::ensure_sites_service();
        $headings = self::remote_get_headings($site, $post_id, $type, $user_id);
        if (!isset($headings[$index])) {
            return new WP_Error('pcm_seo_heading_not_found', __('Heading not found — re-open and try again.', 'power-creatives'), array('status' => 404));
        }
        // Section rules key on the heading (identity = normalized text + occurrence) —
        // capture the OLD identity before the edit so a successful source-write can
        // RE-KEY those rules in the same operation. A heading fix must never strand
        // its section rule stale (interaction law, contracts v2).
        $texts    = array_map(static fn($hh) => (string) ($hh['text'] ?? ''), $headings);
        $old_norm = PCM_Text_Matcher::normalize((string) $headings[$index]['text']);
        $old_occ  = PCM_Text_Matcher::occurrence_of($texts, $index);
        $via      = '';
        $result   = self::remote_update_heading_apply($site, $post_id, $type, $index, $text, $level, $headings, $via, $user_id);
        // Re-key ONLY on SOURCE writes (2.x fleet): they change the rules-INPUT
        // text, so section identities must follow. A RULE-mediated edit
        // ($via 'override') changes DISPLAY only — the rules-input is untouched
        // and re-keying would corrupt the rule's own match identity (live-found
        // defect 2026-07-10: rule 9 re-keyed to its own new output and went
        // permanently stale).
        if ($user_id && $via === 'source' && !is_wp_error($result) && ($text !== null || $level !== null)) {
            $new_text  = ($text !== null && $text !== '') ? $text : (string) $headings[$index]['text'];
            $new_level = ($level !== null) ? max(1, min(6, $level)) : (int) $headings[$index]['level'];
            $new_texts = is_array($result) ? array_map(static fn($hh) => (string) ($hh['text'] ?? ''), $result) : array();
            $new_occ   = isset($new_texts[$index]) ? PCM_Text_Matcher::occurrence_of($new_texts, $index) : $old_occ;
            self::rekey_section_rules($user_id, $site, $post_id, $old_norm, $old_occ, PCM_Text_Matcher::normalize($new_text), $new_occ, $new_level);
        }
        return $result;
    }

    /** The heading edit's routing core (override / widget / content paths) — unchanged
     *  behavior, extracted so the public method can re-key section rules on success.
     *  `$via` reports which layer took the edit: 'source' (raw content changed) or
     *  'override' (render-time layer; source unchanged) — the re-key decision. */
    private static function remote_update_heading_apply(object $site, int $post_id, string $type, int $index, ?string $text, ?int $level, array $headings, string &$via = '', ?int $user_id = null)
    {
        $h = $headings[$index];
        // Shared-source headings (template / reusable block) edit their owning post, not the page.
        $target_pid = self::heading_target_post_id($h, $post_id);
        if (empty($h['editable'])) {
            return new WP_Error('pcm_seo_no_raw', __('This page’s content isn’t editable through the API (e.g. a page-builder layout on an older connector). Update the connector, or edit this heading in the page builder.', 'power-creatives'), array('status' => 422));
        }
        $old_level = (int) $h['level'];
        $new_level = ($level !== null) ? max(1, min(6, $level)) : $old_level;
        $new_text  = $text; // null = keep

        // v3 row (carries the v2.2 rule identity): EVERY heading edit is a
        // dynamic instruction — one mechanism, storage never rewritten. Scope
        // comes from the row (chrome ⇒ site-wide, content ⇒ this page + this
        // occurrence-th twin only).
        if (isset($h['scope']) && in_array((string) ($h['source'] ?? ''), array('snapshot', 'override'), true)) {
            if (!$user_id) {
                return new WP_Error('pcm_seo_no_user', __('Heading edits need a signed-in hub user.', 'power-creatives'), array('status' => 401));
            }
            $post_scope = ((string) $h['scope']) !== 'site';
            // One-owner law: a section/insert rule already owning this heading
            // takes the edit; never stack a heading rule on top of it. Served
            // rows carry the owner directly (attribution); input-view rows
            // resolve it by identity.
            $att = (isset($h['rule']) && is_array($h['rule'])) ? $h['rule'] : null;
            if ($att !== null && in_array((string) ($att['target'] ?? ''), array('section', 'sectionInsert'), true)) {
                $owned = self::update_owned_heading_unit((int) $user_id, $site, (int) ($att['id'] ?? 0), (int) ($att['unitFrom'] ?? 0), (string) $h['text'], $new_text, $new_level);
                if ($owned instanceof WP_Error) {
                    return $owned;
                }
                $via = 'override';
                return self::remote_get_headings($site, $post_id, $type, $user_id);
            }
            if ($post_scope && $att === null) {
                $owned = self::update_section_owned_heading((int) $user_id, $site, $post_id, $h, $new_text, $new_level);
                if ($owned !== null) {
                    if ($owned instanceof WP_Error) {
                        return $owned;
                    }
                    $via = 'override';
                    return self::remote_get_headings($site, $post_id, $type, $user_id);
                }
            }
            $saved      = self::save_heading_rule((int) $user_id, $site, array(
                'postId'       => $post_scope ? $post_id : 0,
                'matchText'    => (string) ($h['matchText'] ?? PCM_Text_Matcher::normalize((string) $h['text'])),
                'matchLevel'   => (int) ($h['matchLevel'] ?? $old_level),
                'occurrence'   => (int) ($h['matchOccurrence'] ?? 0),
                'currentText'  => (string) $h['text'],
                'currentLevel' => $old_level,
            ), $new_text, $new_level);
            if ($saved instanceof WP_Error) {
                return $saved;
            }
            $via = 'override';
            return self::remote_get_headings($site, $post_id, $type, $user_id);
        }

        // RENDERED-ONLY heading (theme PHP / nav menu / widget title — no DB source anywhere), or one
        // already edited via the override layer: goes straight to the render-time override.
        if (in_array((string) ($h['source'] ?? ''), array('rendered', 'override'), true)) {
            $via = 'override';
            return self::remote_apply_heading_override($site, $post_id, $type, (string) $h['text'], $old_level, $new_text, $new_level);
        }

        // Builder-FIELD heading (text + level in separate meta fields) → connector /replace-heading.
        if ((string) ($h['field'] ?? '') === 'widget') {
            $rep = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/replace-heading', array(), array(
                'post_id'  => $target_pid,
                'elId'     => (string) $h['elId'],
                'oldText'  => (string) $h['text'],
                'newText'  => ($new_text !== null ? $new_text : (string) $h['text']),
                'newLevel' => $new_level,
                'textKey'  => (string) ($h['textKey'] ?? ''),
                'tagKey'   => (string) ($h['tagKey'] ?? ''),
            ), 60);
            if (is_wp_error($rep)) {
                return new WP_Error('pcm_seo_remote_heading', $rep->get_error_message(), array('status' => 502));
            }
            if ((int) ($rep['status'] ?? 0) === 404) {
                return new WP_Error('pcm_seo_connector_outdated', __('This site needs Power Creatives connector v2.1.7+ to edit headings. Re-download it from Sites → Download connector and reinstall on the connected site.', 'power-creatives'), array('status' => 409));
            }
            if ((int) ($rep['status'] ?? 0) >= 300) {
                return new WP_Error('pcm_seo_remote_heading', sprintf(__('The connector rejected the heading edit (HTTP %d).', 'power-creatives'), (int) ($rep['status'] ?? 0)), array('status' => 502));
            }
            if ((int) ($rep['body']['replaced'] ?? 0) === 0) {
                // The widget field couldn't be matched (stale scan, or a builder that renders the
                // heading without the widget keys we edit) → fall back to the render-time override,
                // which rewrites the visible heading regardless of storage.
                $via = 'override';
                return self::remote_apply_heading_override($site, $post_id, $type, (string) $h['text'], $old_level, $new_text, $new_level);
            }
            $via = 'source';
            return self::remote_get_headings($site, $post_id, $type);
        }

        // Content / inline-HTML heading → builder-aware URL-style replace of the whole element HTML.
        $old_html = (string) $h['html'];
        if ($old_html === '') {
            // No stored markup to string-replace (e.g. a builder that keeps the heading as a
            // structured node) — go straight to the render-time override.
            $via = 'override';
            return self::remote_apply_heading_override($site, $post_id, $type, (string) $h['text'], $old_level, $new_text, $new_level);
        }
        $new_html = self::rebuild_heading_html($old_html, $old_level, $new_level, $new_text);
        if ($new_html === $old_html) {
            return self::remote_get_headings($site, $post_id, $type);
        }
        $body = array('post_id' => $target_pid, 'old' => $old_html, 'new' => $new_html);
        if ((string) ($h['elId'] ?? '') !== '') { $body['elId'] = (string) $h['elId']; }
        $rep = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/replace-url', array(), $body, 60);
        if (is_wp_error($rep)) {
            $err = $rep->get_error_message();
            $msg = (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false || stripos($err, 'cURL error 28') !== false)
                ? __('The connector took too long (large page) — the change likely applied; re-open and clear the page/CDN cache.', 'power-creatives')
                : sprintf(__('Could not reach the connector to edit the heading (%s).', 'power-creatives'), $err);
            return new WP_Error('pcm_seo_remote_heading', $msg, array('status' => 502));
        }
        if ((int) ($rep['status'] ?? 0) >= 300) {
            return new WP_Error('pcm_seo_remote_heading', sprintf(__('The connector rejected the heading edit (HTTP %d) — check its app-password user can edit, and that the connector is v2.1.7+.', 'power-creatives'), (int) ($rep['status'] ?? 0)), array('status' => 502));
        }
        if ((int) ($rep['body']['replaced'] ?? 0) === 0) {
            // The string replace matched nothing — the builder (Brizy, Divi, …) stores the heading as
            // a structured node, or regenerated its compiled HTML, so there's no literal <hN> to swap.
            // Fall back to the render-time override so the edit still applies on the visible page.
            $via = 'override';
            return self::remote_apply_heading_override($site, $post_id, $type, (string) $h['text'], $old_level, $new_text, $new_level);
        }
        $via = 'source';
        return self::remote_get_headings($site, $post_id, $type);
    }

    /** AI-optimize a connected post's heading text (NOT saved). Returns { value }. */
    public static function remote_optimize_heading(object $site, int $post_id, string $type, string $text, ?string $model = null, ?int $user_id = null, ?string $provider = null, ?int $template_id = null)
    {
        self::ensure_sites_service();
        $route = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
        $res   = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('_fields' => 'id,title,slug,link,author,meta'));
        $row   = (!is_wp_error($res) && is_array($res['body'] ?? null)) ? self::remote_row($res['body'], $type, $site) : array();
        $vars  = self::remote_field_vars($site, $row);
        $vars['current_value'] = $text;
        $mode  = ($text !== '') ? 'optimize' : 'generate';
        $max   = (int) (self::field_prompts()['heading']['max'] ?? 80);
        $val   = self::run_prompt_section('heading', $mode, $vars, $max, $model, $user_id, $provider, $template_id);
        return ($val instanceof WP_Error) ? $val : array('value' => $val);
    }

    // =====================================================================
    // DYNAMIC RULES (rule schema v1) + remote content inventory — pair 2.
    // Contracts: docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md.
    // =====================================================================

    /**
     * A connected post's paragraph inventory (scan-content v1) via the
     * connector — paragraphs in TRUE rendered document order, each with its
     * display anchor (nearest preceding heading). Heading rows keep coming
     * from remote_get_headings; their editing pipeline is untouched.
     *
     * @return array{supported:bool,nodes:array,error?:string}
     */
    public static function remote_get_content_nodes(object $site, int $post_id): array
    {
        self::ensure_sites_service();
        // v3 connectors have no /scan-content — the nodes come from the snapshot parse.
        $inv = self::served_inventory($site, $post_id, null);
        if ($inv !== null) {
            $out = array('supported' => true, 'nodes' => $inv['nodes']);
            if ($inv['error'] !== '') {
                $out['error'] = $inv['error'];
            }
            return $out;
        }
        $res = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/scan-content', array('post_id' => $post_id), null, 30);
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) === 404) {
            // Older connector (no scan-content) — the UI keeps its headings-only
            // view and says so; never an error toast, never fake nodes.
            return array('supported' => false, 'nodes' => array());
        }
        if ((int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return array('supported' => false, 'nodes' => array());
        }
        $body  = $res['body'];
        $nodes = (isset($body['nodes']) && is_array($body['nodes'])) ? $body['nodes'] : array();
        $out   = array('supported' => true, 'nodes' => $nodes);
        if (!empty($body['error'])) {
            $out['error'] = (string) $body['error']; // e.g. loopback_blocked — surfaced honestly
        }
        return $out;
    }

    // =====================================================================
    // PAGE INVENTORY (cleanup C2) — ONE hub-side parse of the connector's
    // snapshot replaces both remote scanners' parsing. Contracts:
    // docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md → "Cleanup contracts v3".
    // =====================================================================

    /**
     * Fetch a v3 connector's page snapshot (`{html, tier[, error]}`), or NULL
     * when the connector predates 3.0.0 (callers compose from the legacy
     * scanners instead). Two views (contracts v2.2): 'input' = the page as
     * RULES-INPUT (identity space), 'served' = what a visitor sees.
     */
    public static function remote_fetch_snapshot(object $site, int $post_id, string $mode = 'input'): ?array
    {
        self::ensure_sites_service();
        if (self::connector_rules_schema_version($site) < 3) {
            return null;
        }
        $args = array('post_id' => $post_id);
        if ($mode === 'served') {
            $args['mode'] = 'served';
        }
        $res = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/snapshot', $args, null, 30);
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return null;
        }
        return array(
            'html'  => (string) ($res['body']['html'] ?? ''),
            'tier'  => (string) ($res['body']['tier'] ?? ''),
            // 3.0.1 marks its view; a 3.0.0 connector ignores ?mode entirely —
            // the missing marker tells callers the served view is unavailable.
            'view'  => (string) ($res['body']['view'] ?? 'input'),
            'error' => isset($res['body']['error']) ? (string) $res['body']['error'] : '',
        );
    }

    /** Every ACTIVE rule shaping this post's served view (site scope included). */
    private static function rule_rows_for_display(int $user_id, int $site_id, int $post_id): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_dynamic_rules');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, postId, target, matchText, occurrence, replacement, anchorContext, active FROM {$table} WHERE userId = %d AND siteId = %d AND postId IN (0, %d) AND active = 1 ORDER BY id ASC",
            $user_id,
            $site_id,
            $post_id
        ), ARRAY_A);
    }

    /**
     * Split an ordered unit list (parse_replacement_units) into UNIT-SECTIONS:
     * each heading unit starts one; every following non-heading unit (p or
     * raw) extends it. Units before the first heading belong to NO section
     * (the leading no-heading zone — callers decide what it means).
     *
     * @return array<int,array{from:int,to:int,level:int,norm:string,
     *                         ptexts:array<int,string>}>
     *         from/to = unit indexes; norm = normalized heading visible text;
     *         ptexts = the section's paragraph visible texts (fingerprint input).
     */
    private static function split_unit_sections(array $units): array
    {
        $secs = array();
        $cur  = null;
        foreach ($units as $ui => $u) {
            if (preg_match('/^h([1-6])$/', (string) $u['tag'], $m)) {
                if ($cur !== null) {
                    $secs[] = $cur;
                }
                $cur = array(
                    'from'   => $ui,
                    'to'     => $ui,
                    'level'  => (int) $m[1],
                    'norm'   => PCM_Text_Matcher::normalize(PCM_Text_Matcher::visible_text((string) $u['inner'])),
                    'ptexts' => array(),
                );
            } elseif ($cur !== null) {
                $cur['to'] = $ui;
                if ((string) $u['tag'] === 'p') {
                    $cur['ptexts'][] = PCM_Text_Matcher::visible_text((string) $u['inner']);
                }
            }
        }
        if ($cur !== null) {
            $secs[] = $cur;
        }
        return $secs;
    }

    /**
     * Display-section pairing (the owner-locked grouping law): contiguous
     * same-anchor RUNS of paragraph nodes; the k-th run of a key belongs to
     * the k-th CONTENT heading with that key.
     *
     * @return array<int,array> heading index → its paragraph nodes.
     */
    private static function section_runs(array $headings, array $nodes): array
    {
        $runs = array();
        foreach ($nodes as $n) {
            $key  = (isset($n['anchor']) && is_array($n['anchor'])) ? ($n['anchor']['level'] . '|' . $n['anchor']['text']) : '';
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last]['key'] === $key) {
                $runs[$last]['paras'][] = $n;
            } else {
                $runs[] = array('key' => $key, 'paras' => array($n));
            }
        }
        $heads_by_key = array();
        foreach ($headings as $i => $h) {
            if (((string) ($h['scope'] ?? 'post')) === 'site') {
                continue;
            }
            $heads_by_key[$h['level'] . '|' . PCM_Text_Matcher::normalize((string) $h['text'])][] = $i;
        }
        $run_for = array();
        $run_occ = array();
        foreach ($runs as $r) {
            if ($r['key'] === '') {
                continue;
            }
            $occ               = isset($run_occ[$r['key']]) ? $run_occ[$r['key']] : 0;
            $run_occ[$r['key']] = $occ + 1;
            if (isset($heads_by_key[$r['key']][$occ])) {
                $run_for[$heads_by_key[$r['key']][$occ]] = $r['paras'];
            }
        }
        return $run_for;
    }

    /**
     * ATTRIBUTION (served-truth law, contracts v2.2): map every served row to
     * the rule that produced it. Section/sectionInsert replacements are split
     * into UNIT-SECTIONS (each heading unit starts one); a served section
     * matches a unit-section on (normalized heading, level, paragraph
     * fingerprint) — the row then carries `rule: {id, target, unitFrom,
     * unitTo, whole, sliceHtml}` and the editor edits that slice. Heading
     * rules attribute by their output (replacement text + newLevel);
     * paragraph rules mark their node `optimized`. Unmatched rows are
     * ORIGINAL content — their identity fields (computed from the served
     * parse) equal the rules-input identity by definition.
     */
    private static function attribute_inventory(array $headings, array $nodes, array $rules): array
    {
        $run_for = self::section_runs($headings, $nodes);
        // Rule outputs.
        $unitmaps  = array();
        $headrules = array();
        $pararules = array();
        foreach ($rules as $r) {
            $t = (string) ($r['target'] ?? '');
            if ($t === 'section' || $t === 'sectionInsert') {
                $units      = PCM_Text_Matcher::parse_replacement_units((string) $r['replacement']);
                $unitmaps[] = array('rule' => $r, 'sections' => self::split_unit_sections($units), 'unitCount' => count($units), 'units' => $units);
            } elseif ($t === 'heading') {
                $ctx         = json_decode((string) ($r['anchorContext'] ?? ''), true);
                $ctx         = is_array($ctx) ? $ctx : array();
                $headrules[] = array(
                    'id'    => (int) $r['id'],
                    'norm'  => PCM_Text_Matcher::normalize((string) $r['replacement']),
                    'level' => (int) ($ctx['newLevel'] ?? 0),
                    'scope' => ((string) ($ctx['scope'] ?? 'post')) === 'site' ? 'site' : 'post',
                );
            } elseif ($t === 'paragraph') {
                $pararules[] = array('id' => (int) $r['id'], 'norm' => PCM_Text_Matcher::normalize(PCM_Text_Matcher::visible_text((string) $r['replacement'])));
            }
        }
        foreach ($headings as $i => $h) {
            $norm    = PCM_Text_Matcher::normalize((string) $h['text']);
            $is_site = ((string) ($h['scope'] ?? 'post')) === 'site';
            if (!$is_site) {
                $paras = isset($run_for[$i]) ? $run_for[$i] : array();
                $fp    = PCM_Text_Matcher::fingerprint(array_map(static fn($p) => (string) $p['text'], $paras));
                $hit   = null;
                foreach ($unitmaps as $um) {
                    foreach ($um['sections'] as $sec) {
                        if ($sec['level'] !== (int) $h['level'] || $sec['norm'] !== $norm || PCM_Text_Matcher::fingerprint($sec['ptexts']) !== $fp) {
                            continue;
                        }
                        $slice = '';
                        for ($ui = $sec['from']; $ui <= $sec['to']; $ui++) {
                            $slice .= $um['units'][$ui]['html'];
                        }
                        $hit = array(
                            'id'        => (int) $um['rule']['id'],
                            'target'    => (string) $um['rule']['target'],
                            'unitFrom'  => (int) $sec['from'],
                            'unitTo'    => (int) $sec['to'],
                            'whole'     => ($sec['from'] === 0 && $sec['to'] === $um['unitCount'] - 1),
                            'sliceHtml' => $slice,
                        );
                        break 2;
                    }
                }
                if ($hit !== null) {
                    $headings[$i]['rule']        = $hit;
                    $headings[$i]['source']      = 'override';
                    $headings[$i]['sourceType']  = 'override';
                    $headings[$i]['sourceLabel'] = __('Optimized (render-time, this page)', 'power-creatives');
                    continue;
                }
            }
            foreach ($headrules as $hr) {
                if (($hr['scope'] === 'site') !== $is_site || $hr['level'] !== (int) $h['level'] || $hr['norm'] !== $norm) {
                    continue;
                }
                $headings[$i]['rule']        = array('id' => $hr['id'], 'target' => 'heading');
                $headings[$i]['source']      = 'override';
                $headings[$i]['sourceType']  = 'override';
                $headings[$i]['sourceLabel'] = $is_site
                    ? __('Site-wide override (render-time)', 'power-creatives')
                    : __('Optimized (render-time, this page)', 'power-creatives');
                break;
            }
        }
        foreach ($nodes as $j => $n) {
            $pn = PCM_Text_Matcher::normalize((string) $n['text']);
            foreach ($pararules as $pr) {
                if ($pr['norm'] === $pn) {
                    $nodes[$j]['optimized'] = true;
                    $nodes[$j]['ruleId']    = $pr['id'];
                    break;
                }
            }
        }
        return array('headings' => $headings, 'nodes' => $nodes);
    }

    /**
     * The SERVED-truth inventory (v3 connectors): parse the served view, map
     * every row to its producing rule. Falls back to the rules-input view +
     * display-state law when the served fetch fails (a display view may be
     * stale or missing, never wrong-serving). NULL = pre-v3 connector.
     */
    private static function served_inventory(object $site, int $post_id, ?int $user_id): ?array
    {
        $served = self::remote_fetch_snapshot($site, $post_id, 'served');
        if ($served === null) {
            return null;
        }
        if ($served['view'] !== 'served') {
            // 3.0.0 connector (no served view yet): honest input-view fallback.
            $served['html'] = '';
        }
        if ($served['html'] !== '') {
            $parsed = self::parse_page_snapshot($served['html']);
            if ($user_id) {
                $parsed = self::attribute_inventory($parsed['headings'], $parsed['nodes'], self::rule_rows_for_display((int) $user_id, (int) $site->id, $post_id));
            }
            return array(
                'view'        => 'served',
                'tier'        => $served['tier'],
                'headings'    => $parsed['headings'],
                'nodes'       => $parsed['nodes'],
                // The full-page editor's document (G2) — assembled from the
                // SAME parse the rows come from, never raw builder soup.
                'contentHtml' => self::assemble_content_html($served['html'], $parsed['headings']),
                'error'       => '',
            );
        }
        $in = self::remote_fetch_snapshot($site, $post_id, 'input');
        if ($in !== null && $in['html'] !== '') {
            $rules  = $user_id ? self::heading_instructions((int) $user_id, (int) $site->id, $post_id) : array();
            $parsed = self::parse_page_snapshot($in['html'], $rules);
            return array('view' => 'input', 'tier' => $in['tier'], 'headings' => $parsed['headings'], 'nodes' => $parsed['nodes'], 'error' => '');
        }
        return array('view' => 'served', 'tier' => (string) $served['tier'], 'headings' => array(), 'nodes' => array(), 'error' => (string) ($served['error'] !== '' ? $served['error'] : 'loopback_blocked'));
    }

    /**
     * Extract <img> tags from an HTML fragment. With $skip_added, images the
     * platform placed (`data-pcm-added`) stay IN PLACE — they are content,
     * not context, and must never drift to a section's end on round-trips.
     *
     * @return array{0:string,1:array<int,string>} [fragment without extracted imgs, extracted tags in order].
     */
    private static function extract_imgs(string $fragment, bool $skip_added = false): array
    {
        $imgs = array();
        $rest = (string) preg_replace_callback('#<img\b[^>]*>#i', static function ($m) use (&$imgs, $skip_added) {
            if ($skip_added && stripos((string) $m[0], 'data-pcm-added') !== false) {
                return (string) $m[0];
            }
            $imgs[] = (string) $m[0];
            return '';
        }, $fragment);
        return array($rest, $imgs);
    }

    /**
     * The full-page editor's document (G2): a CLEAN block-level assembly of
     * the served CONTENT region — never raw builder soup.
     *
     * - ORIGINAL sections emit bare <hN>/<p> blocks (inner HTML kept, wrapper
     *   attrs dropped — serving keeps the original block's attrs on the
     *   equal-count mapping, and the editor drops unknown attrs anyway).
     * - RULE-OWNED sections emit their owning rule's unit range (sliceHtml)
     *   so the rule's own lists/raw units survive the editor round-trip; the
     *   section's page blocks are skipped (they ARE that slice, served).
     * - EVERY <img> — between blocks, inside a block's inner HTML, or inside
     *   a slice — is extracted as a standalone locked block (data-pcm-locked):
     *   visible context in the editor, stripped again on save (F9 law —
     *   images persist as untouched between-content). Chrome regions are
     *   excluded from gap extraction (a header logo is not page content).
     * - Empty-text paragraphs are dropped (the parse's own empty-p law), so
     *   an untouched round-trip stays fingerprint-identical.
     */
    private static function assemble_content_html(string $html, array $headings): string
    {
        $blocks = PCM_Text_Matcher::content_blocks($html);
        $spans  = PCM_Text_Matcher::chrome_spans($html);
        // Content heading rows in document order — same skip laws as the parse,
        // so the cursor below stays aligned with the block walk.
        $rows = array_values(array_filter($headings, static fn($h) => ((string) ($h['scope'] ?? 'post')) !== 'site'));
        $lock = static fn(string $img): string => (string) preg_replace('#^<img\b#i', '<img data-pcm-locked="1"', $img);
        $gap_imgs = static function (int $from, int $to) use ($html, $spans): array {
            if ($to <= $from) {
                return array();
            }
            $imgs = array();
            if (preg_match_all('#<img\b[^>]*>#i', substr($html, $from, $to - $from), $mm, PREG_OFFSET_CAPTURE)) {
                foreach ($mm[0] as $m) {
                    // A SERVED platform-added img is rule content already
                    // emitted in place by its slice — lifting it as locked
                    // context would duplicate it (F9 genus, live-caught
                    // 2026-07-12: the duplicate even defeats unchanged-skip).
                    if (stripos((string) $m[0], 'data-pcm-added') !== false) {
                        continue;
                    }
                    $abs = $from + (int) $m[1];
                    foreach ($spans as $s) {
                        if ($abs >= $s[0] && $abs < $s[1]) {
                            continue 2;
                        }
                    }
                    $imgs[] = (string) $m[0];
                }
            }
            return $imgs;
        };
        $out      = array();
        $ci       = 0;     // content-heading row cursor
        $in_slice = false; // inside a rule-owned section: its blocks live in the emitted slice
        // Gap scanning covers the WHOLE non-chrome document — before the first
        // block, between blocks, and after the last (identity-completeness law:
        // the editor's image set must equal the connector's counting set, or
        // occurrence indexes diverge and edge images stay invisible/uneditable).
        $prev_end = 0;
        foreach ($blocks as $b) {
            foreach ($gap_imgs($prev_end, (int) $b['start']) as $img) {
                $out[] = $lock($img);
            }
            $prev_end = (int) $b['start'] + (int) $b['len'];
            if ($b['tag'] !== 'p') {
                if (trim((string) $b['text']) === '') {
                    continue; // parse law: empty headings are not rows — keep the cursor aligned
                }
                $row = $rows[$ci] ?? null;
                $ci++;
                $att = ($row && isset($row['rule']) && is_array($row['rule'])
                    && in_array((string) ($row['rule']['target'] ?? ''), array('section', 'sectionInsert'), true))
                    ? $row['rule'] : null;
                if ($att !== null) {
                    // Platform-added images stay in place (content); everything
                    // else lifts out as locked context.
                    list($slice, $imgs) = self::extract_imgs((string) ($att['sliceHtml'] ?? ''), true);
                    $out[]    = trim($slice);
                    foreach ($imgs as $img) {
                        $out[] = $lock($img);
                    }
                    $in_slice = true;
                    continue;
                }
                $in_slice = false;
                list($inner, $imgs) = self::extract_imgs((string) $b['inner']);
                $out[] = '<h' . (int) $b['level'] . '>' . $inner . '</h' . (int) $b['level'] . '>';
                foreach ($imgs as $img) {
                    $out[] = $lock($img);
                }
                continue;
            }
            if ($in_slice) {
                continue;
            }
            list($inner, $imgs) = self::extract_imgs((string) $b['inner']);
            $plain = trim(html_entity_decode(trim(PCM_Text_Matcher::visible_text($inner)), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\xC2\xA0");
            if ($plain !== '') {
                $out[] = '<p>' . $inner . '</p>';
            }
            foreach ($imgs as $img) {
                $out[] = $lock($img);
            }
        }
        // Tail gap: images after the last content block (footer stays chrome-excluded).
        foreach ($gap_imgs($prev_end, strlen($html)) as $img) {
            $out[] = $lock($img);
        }
        return implode("\n", array_filter($out, static fn($u) => trim((string) $u) !== ''));
    }

    /**
     * Active heading instructions (compiled overrides) that shape a post's
     * DISPLAY state: site-scope rows (postId 0) + the post's own — the hub
     * applies them to the parsed snapshot (display-state law, contracts v3).
     *
     * @return array[] [{matchText, replacement, level, newLevel}, …]
     */
    private static function heading_instructions(int $user_id, int $site_id, int $post_id): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_dynamic_rules');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT matchText, occurrence, replacement, anchorContext FROM {$table} WHERE userId = %d AND siteId = %d AND postId IN (0, %d) AND target = 'heading' AND active = 1",
            $user_id,
            $site_id,
            $post_id
        ), ARRAY_A);
        $out = array();
        foreach ($rows as $r) {
            $ctx   = json_decode((string) ($r['anchorContext'] ?? ''), true);
            $ctx   = is_array($ctx) ? $ctx : array();
            $out[] = array(
                'matchText'      => (string) $r['matchText'],
                'occurrence'     => (int) $r['occurrence'],
                'allOccurrences' => !empty($ctx['allOccurrences']),
                'replacement'    => (string) $r['replacement'],
                'level'          => (int) ($ctx['level'] ?? 0),
                'newLevel'       => (int) ($ctx['newLevel'] ?? ($ctx['level'] ?? 0)),
            );
        }
        return $out;
    }

    /**
     * Parse ONE snapshot into the outline's full inventory — heading rows +
     * paragraph nodes, exactly the shapes the legacy scanners produced:
     *
     * - Headings: EVERY body heading in document order (chrome INCLUDED —
     *   theme/nav headings are editable), deduped by level|text like the old
     *   rendered pass. Snapshot rows carry no storage handles by design
     *   (handle-resolution law): identity is {text, level, occurrence}.
     * - Paragraphs: chrome-stripped (scan-content parity), nearest preceding
     *   CONTENT heading as the anchor (normalized display text).
     * - Display-state law: active heading instructions transform text/level
     *   BEFORE dedupe/anchoring, so section keys match what the site serves.
     */
    public static function parse_page_snapshot(string $html, array $heading_rules = array()): array
    {
        if (($bpos = stripos($html, '<body')) !== false) {
            $html = substr($html, $bpos);
        }
        // Scope-decision law (v2.2): a heading inside a chrome span is SITE
        // chrome; everything else is PAGE content.
        $spans     = PCM_Text_Matcher::chrome_spans($html);
        $in_chrome = static function (int $start) use ($spans): bool {
            foreach ($spans as $s) {
                if ($start >= $s[0] && $start < $s[1]) {
                    return true;
                }
            }
            return false;
        };
        // Display-state law: heading instructions match in the ORIGINAL-text
        // space, occurrence-aware — exactly what serving does. $match_seen
        // counts CONTENT headings per (level, normalized original); chrome
        // headings never consume content occurrences.
        $match_seen = array();
        $instruct   = static function (int $level, string $text, bool $chrome) use ($heading_rules, &$match_seen): array {
            $norm = PCM_Text_Matcher::normalize($text);
            $occ  = -1;
            if (!$chrome) {
                $mkey              = $level . '|' . $norm;
                $occ               = isset($match_seen[$mkey]) ? $match_seen[$mkey] : 0;
                $match_seen[$mkey] = $occ + 1;
            }
            $out = array('level' => $level, 'text' => $text, 'instructed' => false, 'matchText' => $norm, 'matchLevel' => $level, 'matchOccurrence' => max(0, $occ));
            foreach ($heading_rules as $r) {
                if ((int) ($r['level'] ?? 0) !== $level || (string) ($r['matchText'] ?? '') !== $norm) {
                    continue;
                }
                if (empty($r['allOccurrences']) && (int) ($r['occurrence'] ?? 0) !== $occ) {
                    continue;
                }
                $out['level']      = max(1, min(6, (int) ($r['newLevel'] ?? $level)));
                $out['text']       = (string) ($r['replacement'] ?? $text);
                $out['instructed'] = true;
                break;
            }
            return $out;
        };
        $headings    = array();
        $seen_chrome = array();
        $disp_seen   = array();
        foreach (PCM_Text_Matcher::parse_blocks($html) as $b) {
            if ($b['tag'] === 'p') {
                continue;
            }
            $text = trim($b['text']);
            if ($text === '') {
                continue;
            }
            $chrome = $in_chrome((int) $b['start']);
            $d      = $instruct($b['level'], $text, $chrome);
            if ($chrome) {
                // One row per site-wide item (a menu title repeats in header + footer).
                $key = $d['level'] . '|' . $d['text'];
                if (isset($seen_chrome[$key])) {
                    continue;
                }
                $seen_chrome[$key] = 1;
                $occurrence        = 0;
            } else {
                // Content twins are NEVER deduped (v2.2): each is its own row,
                // individually editable. Occurrence here is DISPLAY-space —
                // the identity section keys use.
                $dkey             = $d['level'] . '|' . PCM_Text_Matcher::normalize($d['text']);
                $occurrence       = isset($disp_seen[$dkey]) ? $disp_seen[$dkey] : 0;
                $disp_seen[$dkey] = $occurrence + 1;
            }
            $headings[] = array(
                'level'           => $d['level'],
                'text'            => $d['text'],
                'html'            => $d['instructed'] ? '' : $b['html'],
                'scope'           => $chrome ? 'site' : 'post',
                'occurrence'      => $occurrence,
                // Rule identity (ORIGINAL-text space) — what an edit targets.
                'matchText'       => $d['matchText'],
                'matchLevel'      => $d['matchLevel'],
                'matchOccurrence' => $d['matchOccurrence'],
                'source'          => $d['instructed'] ? 'override' : 'snapshot',
                'elId'            => '',
                'field'           => '',
                'tagKey'          => '',
                'textKey'         => '',
                'sourcePostId'    => 0,
                'sourceType'      => $d['instructed'] ? 'override' : ($chrome ? 'chrome' : ''),
                'sourceLabel'     => $d['instructed']
                    ? ($chrome ? __('Site-wide override (render-time)', 'power-creatives') : __('Optimized (render-time, this page)', 'power-creatives'))
                    : ($chrome ? __('Site-wide (theme/menu) — an edit changes every page', 'power-creatives') : ''),
                'editable'        => true,
            );
        }
        foreach ($headings as $i => $unused) {
            $headings[$i]['index'] = $i;
            $headings[$i]['id']    = $i;
        }
        $nodes    = array();
        $anchor   = null;
        $occ_seen = array();
        $i        = 0;
        // Second walk = content blocks only; instruction matching restarts its
        // occurrence count (same document order, content headings only — the
        // exact space the first walk counted them in).
        $match_seen = array();
        foreach (PCM_Text_Matcher::content_blocks($html) as $b) {
            if ($b['tag'] !== 'p') {
                $text = trim($b['text']);
                if ($text === '') {
                    continue;
                }
                $d      = $instruct($b['level'], $text, false);
                $anchor = array('level' => $d['level'], 'text' => PCM_Text_Matcher::normalize($d['text']));
                continue;
            }
            $text  = trim($b['text']);
            $plain = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\xC2\xA0");
            if ($plain === '') {
                continue;
            }
            $norm            = PCM_Text_Matcher::normalize($text);
            $occ             = isset($occ_seen[$norm]) ? $occ_seen[$norm] : 0;
            $occ_seen[$norm] = $occ + 1;
            $nodes[]         = array(
                'kind'       => 'paragraph',
                'index'      => $i++,
                'text'       => $text,
                'html'       => $b['html'],
                'occurrence' => $occ,
                'source'     => 'rendered',
                'anchor'     => $anchor,
            );
        }
        return array('headings' => $headings, 'nodes' => $nodes);
    }

    /**
     * The connected post's FULL inventory in one call — the outline's single
     * read. v3 connector → one snapshot, one hub-side parse; pre-3.0 fleet →
     * honest composition from the legacy scanners (the same two calls the UI
     * used to make itself; this fallback dies with the C5 follow-up).
     */
    public static function remote_get_inventory(object $site, int $post_id, string $type, int $user_id): array
    {
        $inv = self::served_inventory($site, $post_id, $user_id);
        if ($inv !== null) {
            $out = array(
                'supported' => true,
                'source'    => 'snapshot',
                'view'      => $inv['view'],
                'tier'      => $inv['tier'],
                'headings'  => $inv['headings'],
                'nodes'     => $inv['nodes'],
            );
            if (isset($inv['contentHtml'])) {
                $out['contentHtml'] = (string) $inv['contentHtml'];
            }
            if ($inv['error'] !== '') {
                $out['error'] = $inv['error'];
            }
            return $out;
        }
        $headings = self::remote_get_headings($site, $post_id, $type, $user_id);
        $meta     = self::remote_get_content_nodes($site, $post_id);
        $out      = array(
            'supported' => (bool) $meta['supported'],
            'source'    => 'scan',
            'view'      => 'input',
            'headings'  => $headings,
            'nodes'     => (array) $meta['nodes'],
        );
        if (!empty($meta['error'])) {
            $out['error'] = (string) $meta['error'];
        }
        return $out;
    }

    /** Upload an image (by URL) into a connected site's media library — the
     *  SINGLE existing channel in PCM_Sites_Service (delegation, never duplicate). */
    public static function remote_add_media(object $site, string $image_url)
    {
        self::ensure_sites_service();
        return PCM_Sites_Service::remote_upload_media($site, $image_url);
    }

    // ── Slug-change redirects (connector 3.0.5): hub-owned store, connector-
    //    served copy. Same laws as rules: capability BEFORE any write, the
    //    connector receives the COMPLETE set, push-fail rolls the hub row back. ──

    /** Whether the site's connector answers /redirects (3.0.5+) — the capability handle. */
    public static function connector_supports_redirects(object $site): bool
    {
        static $memo = array();
        $key = (int) $site->id;
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        self::ensure_sites_service();
        $res = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/redirects');
        return $memo[$key] = (!is_wp_error($res) && (int) ($res['status'] ?? 0) === 200 && !empty($res['body']['supported']));
    }

    /** @return array[] the site's redirect rows (ARRAY_A, id-ordered). */
    private static function redirect_rows(int $user_id, int $site_id): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_redirects');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, fromPath, toUrl, code, active, createdAt FROM {$table} WHERE userId = %d AND siteId = %d ORDER BY id",
            $user_id,
            $site_id
        ), ARRAY_A);
    }

    /** Push the COMPLETE active set to the connector. WP_Error on any non-2xx. */
    private static function push_redirects(object $site, array $rows)
    {
        self::ensure_sites_service();
        $wire = array();
        foreach ($rows as $r) {
            if (empty($r['active'])) {
                continue;
            }
            $wire[] = array('from' => (string) $r['fromPath'], 'to' => (string) $r['toUrl'], 'code' => (int) $r['code']);
        }
        $res = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/redirects', array(), array('redirects' => $wire), 30);
        if (is_wp_error($res)) {
            return $res;
        }
        if ((int) ($res['status'] ?? 0) >= 300) {
            return new WP_Error('pcm_seo_redirect_push', __('The connector rejected the redirect set.', 'power-creatives'), array('status' => 502));
        }
        return array('pushed' => count($wire));
    }

    /** @return array{supported:bool,redirects:array[]} the settings panel's list. */
    public function list_redirects(int $user_id, object $site): array
    {
        return array(
            'supported' => self::connector_supports_redirects($site),
            'redirects' => self::redirect_rows($user_id, (int) $site->id),
        );
    }

    /**
     * Save a redirect (UPSERT on siteId+fromPath). Capability-first honest 409;
     * push-fail restores the exact previous row state (never a dangling row).
     *
     * @param array{from:string,to:string,code:int} $input
     * @return array|\WP_Error
     */
    public function save_redirect(int $user_id, object $site, array $input)
    {
        global $wpdb;
        if (!self::connector_supports_redirects($site)) {
            return new WP_Error(
                'pcm_seo_redirects_unsupported',
                __('Redirects need connector 3.0.5+ — update the connector from the Sites module, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        $from = PCM_Text_Matcher::normalize_path((string) ($input['from'] ?? ''));
        $to   = esc_url_raw((string) ($input['to'] ?? ''));
        $code = (int) ($input['code'] ?? 301);
        $code = in_array($code, array(301, 302, 307, 308), true) ? $code : 301;
        if ($from === '/') {
            return new WP_Error('pcm_seo_redirect_root', __('The front page can’t be redirected.', 'power-creatives'), array('status' => 400));
        }
        if ($to === '' || !preg_match('#^https?://#i', $to)) {
            return new WP_Error('pcm_seo_redirect_target', __('The redirect target must be a full http(s) URL.', 'power-creatives'), array('status' => 400));
        }
        $table   = PCM_Schema::table('seo_redirects');
        $site_id = (int) $site->id;
        $prev    = null;
        foreach (self::redirect_rows($user_id, $site_id) as $r) {
            if ((string) $r['fromPath'] === $from) {
                $prev = $r;
                break;
            }
        }
        if ($prev !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update($table, array('toUrl' => $to, 'code' => $code, 'active' => 1), array('id' => (int) $prev['id']), array('%s', '%d', '%d'), array('%d'));
            $row_id = (int) $prev['id'];
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert($table, array(
                'userId' => $user_id, 'siteId' => $site_id, 'fromPath' => $from, 'toUrl' => $to, 'code' => $code, 'active' => 1,
            ), array('%d', '%d', '%s', '%s', '%d', '%d'));
            $row_id = (int) $wpdb->insert_id;
        }
        $push = self::push_redirects($site, self::redirect_rows($user_id, $site_id));
        if ($push instanceof WP_Error) {
            if ($prev !== null) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($table, array('toUrl' => (string) $prev['toUrl'], 'code' => (int) $prev['code'], 'active' => (int) $prev['active']), array('id' => (int) $prev['id']), array('%s', '%d', '%d'), array('%d'));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete($table, array('id' => $row_id), array('%d'));
            }
            return $push;
        }
        $result = array('id' => $row_id, 'from' => $from, 'to' => $to, 'code' => $code);
        // Optional internal-link rewrite (the popup's checkbox): per-post
        // builder-aware /replace-url — the EXISTING writer path, looped
        // hub-side. Best-effort BY DESIGN: the redirect above is already
        // live, so rewrite failures are reported, never rolled back into.
        if (!empty($input['updateLinks'])) {
            $old_full = preg_match('#^https?://#i', (string) ($input['from'] ?? '')) ? (string) $input['from'] : rtrim((string) $site->url, '/') . $from;
            $links    = 0;
            $touched  = 0;
            foreach ((array) (self::remote_url_usage($site, $old_full)['posts'] ?? array()) as $p) {
                $rep = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/replace-url', array(), array(
                    'post_id' => (int) ($p['id'] ?? 0),
                    'old'     => $old_full,
                    'new'     => $to,
                ), 60);
                if (!is_wp_error($rep) && (int) ($rep['status'] ?? 0) < 300) {
                    $touched++;
                    $links += (int) ($rep['body']['replaced'] ?? 0);
                }
            }
            $result['linksUpdated'] = $links;
            $result['postsTouched'] = $touched;
        }
        return $result;
    }

    /** Delete a redirect; push-fail re-inserts the exact row (same id). */
    public function delete_redirect(int $user_id, object $site, int $redirect_id)
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_redirects');
        $prev  = null;
        foreach (self::redirect_rows($user_id, (int) $site->id) as $r) {
            if ((int) $r['id'] === $redirect_id) {
                $prev = $r;
                break;
            }
        }
        if ($prev === null) {
            return new WP_Error('pcm_seo_redirect_missing', __('Redirect not found.', 'power-creatives'), array('status' => 404));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete($table, array('id' => $redirect_id), array('%d'));
        $push = self::push_redirects($site, self::redirect_rows($user_id, (int) $site->id));
        if ($push instanceof WP_Error) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert($table, array(
                'id'     => (int) $prev['id'], 'userId' => $user_id, 'siteId' => (int) $site->id,
                'fromPath' => (string) $prev['fromPath'], 'toUrl' => (string) $prev['toUrl'],
                'code'   => (int) $prev['code'], 'active' => (int) $prev['active'], 'createdAt' => (string) $prev['createdAt'],
            ), array('%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s'));
            return $push;
        }
        return array('deleted' => $redirect_id);
    }

    /** Site-wide "who links to this URL" (connector /url-usage passthrough). */
    public static function remote_url_usage(object $site, string $url)
    {
        if (!self::connector_supports_redirects($site)) {
            return array('url' => $url, 'posts' => array(), 'total' => 0, 'supported' => false);
        }
        self::ensure_sites_service();
        $res = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/url-usage', array('url' => $url), null, 30);
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return array('url' => $url, 'posts' => array(), 'total' => 0, 'supported' => true);
        }
        return array(
            'url'       => $url,
            'posts'     => array_values((array) ($res['body']['posts'] ?? array())),
            'total'     => (int) ($res['body']['total'] ?? 0),
            'supported' => true,
        );
    }

    /** Whether a connected site's connector accepts rule schema v1 (capability check). */
    public static function connector_supports_rules(object $site): bool
    {
        return self::connector_rules_schema_version($site) >= 1;
    }

    /**
     * The highest rule-schema version a connected site's connector accepts:
     * 0 = none (pre-2.7.0), 1 = paragraph rules (2.7.x), 2 = + section rules
     * (2.8.0+). Read from GET /pcm-conn/v1/rules `schemaVersion` — the single
     * capability handle for every push decision.
     */
    public static function connector_rules_schema_version(object $site): int
    {
        // Per-request memo: several code paths check capability for the same
        // site in one request (inventory, heading edit, rule save) — one GET.
        static $memo = array();
        $key = (int) $site->id;
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        self::ensure_sites_service();
        $res = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/rules');
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || empty($res['body']['supported'])) {
            return $memo[$key] = 0;
        }
        return $memo[$key] = max(1, (int) ($res['body']['schemaVersion'] ?? 1));
    }

    /**
     * Push a post's COMPLETE rule set to its connector (schema v1 — replaces
     * the set; the connector re-normalizes + sanitizes defensively and purges
     * caches). Capability-checked first so an old connector fails honestly.
     *
     * @param array[] $rules Rule rows shaped per rule schema v1.
     * @return array{stored:int}|\WP_Error
     */
    public static function push_rules(object $site, int $post_id, array $rules)
    {
        self::ensure_sites_service();
        // v2 ONLY when the set contains section targets — posts with plain
        // paragraph rules keep pushing v1, byte-identical to before (zero
        // regression on un-updated connectors).
        $needs_v2 = false;
        $needs_v3 = ($post_id === 0); // site scope exists only in v3
        $needs_v4 = false;            // image target exists only in v4 (3.0.2+)
        $needs_v5 = false;            // sectionRemove + image hidden exist only in v5 (3.0.3+)
        foreach ($rules as $r) {
            $t = (string) ($r['target'] ?? '');
            if ($t === 'section' || $t === 'sectionInsert') {
                $needs_v2 = true;
            }
            if ($t === 'heading') {
                $needs_v3 = true; // SERVED heading rules exist only in v3
            }
            if ($t === 'sectionRemove') {
                $needs_v5 = true;
            }
            if ($t === 'image') {
                $needs_v4 = true;
                $set = json_decode((string) ($r['replacement'] ?? ''), true);
                if (is_array($set) && !empty($set['hidden'])) {
                    $needs_v5 = true;
                }
            }
        }
        $accepts = self::connector_rules_schema_version($site);
        if ($accepts < 1) {
            return new WP_Error(
                'pcm_seo_connector_no_rules',
                __('This site’s connector doesn’t support dynamic rules yet (needs v2.7.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        if ($needs_v5 && $accepts < 5) {
            return new WP_Error(
                'pcm_seo_connector_no_removal',
                __('This site’s connector doesn’t support section removal / image hiding yet (needs v3.0.3+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        if ($needs_v4 && $accepts < 4) {
            return new WP_Error(
                'pcm_seo_connector_no_image_rules',
                __('This site’s connector doesn’t support image metadata rules yet (needs v3.0.2+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        if ($needs_v3 && $accepts < 3) {
            return new WP_Error(
                'pcm_seo_connector_no_heading_rules',
                __('This site’s connector doesn’t support heading instructions yet (needs v3.0.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        if ($needs_v2 && $accepts < 2) {
            return new WP_Error(
                'pcm_seo_connector_no_sections',
                __('This site’s connector doesn’t support section rules yet (needs v2.8.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        $res = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/rules', array(), array(
            'schemaVersion' => $needs_v5 ? 5 : ($needs_v4 ? 4 : ($needs_v3 ? 3 : ($needs_v2 ? 2 : 1))),
            'postId'        => $post_id,
            'rules'         => array_values($rules),
        ), 60);
        if (is_wp_error($res)) {
            return new WP_Error('pcm_seo_rules_push', $res->get_error_message(), array('status' => 502));
        }
        if ((int) ($res['status'] ?? 0) >= 300) {
            $msg = (is_array($res['body'] ?? null) && !empty($res['body']['error']))
                ? (string) $res['body']['error']
                : ('HTTP ' . (int) ($res['status'] ?? 0));
            return new WP_Error('pcm_seo_rules_push', sprintf(__('The connector rejected the rule push (%s).', 'power-creatives'), $msg), array('status' => 502));
        }
        return array('stored' => (int) ($res['body']['stored'] ?? 0));
    }

    /** The hub's stored dynamic rules for one connected post (the UI overlay's source of truth). */
    public function list_dynamic_rules(int $user_id, int $site_id, int $post_id): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_dynamic_rules');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, target, matchText, occurrence, replacement, anchorContext, active, staleCount FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d ORDER BY id ASC",
            $user_id,
            $site_id,
            $post_id
        ), ARRAY_A);
        return array_map(static function ($r) {
            $ctx = null;
            if (!empty($r['anchorContext'])) {
                $decoded = json_decode((string) $r['anchorContext'], true);
                if (is_array($decoded)) {
                    $ctx = $decoded;
                }
            }
            return array(
                'id'          => (int) $r['id'],
                'target'      => (string) $r['target'],
                'matchText'   => (string) $r['matchText'],
                'occurrence'  => (int) $r['occurrence'],
                'replacement' => (string) $r['replacement'],
                'active'      => (bool) (int) $r['active'],
                'staleCount'  => (int) $r['staleCount'],
                // Section rules: {level, fingerprint, paragraphs} / {level, position}
                // (the phase-2 overlay + absorb read these; null on paragraph rules
                // whose anchorContext is display-anchor data, not section identity).
                'section'     => in_array((string) $r['target'], array('section', 'sectionInsert', 'sectionRemove'), true) ? $ctx : null,
            );
        }, (array) $rows);
    }

    /** Map hub rule rows to rule schema v1/v2 payload entries (the push shape). */
    private static function rules_to_schema(array $rows): array
    {
        return array_map(static function ($r) {
            $target = (string) $r['target'];
            $ctx    = null;
            if (!empty($r['anchorContext'])) {
                $decoded = json_decode((string) $r['anchorContext'], true);
                if (is_array($decoded)) {
                    $ctx = $decoded;
                }
            }
            $out = array(
                'id'          => (int) $r['id'],
                'target'      => $target,
                'match'       => array('text' => (string) $r['matchText'], 'occurrence' => (int) $r['occurrence']),
                'replacement' => (string) $r['replacement'],
                'active'      => (bool) (int) $r['active'],
            );
            if (in_array($target, array('section', 'sectionInsert', 'sectionRemove'), true)) {
                // v2/v2.4: anchorContext IS the section identity {level, fingerprint|position}.
                $out['section'] = array(
                    'level' => (int) ($ctx['level'] ?? 0),
                );
                if ($target === 'section' || $target === 'sectionRemove') {
                    $out['section']['fingerprint'] = (string) ($ctx['fingerprint'] ?? '');
                } else {
                    $out['section']['position'] = (string) ($ctx['position'] ?? 'after');
                }
                $out['anchor'] = null;
            } elseif ($target === 'heading') {
                // v3: heading instruction — anchorContext = {level, newLevel,
                // scope, originalText, allOccurrences}. allOccurrences keeps
                // compiled-override semantics (chrome/site + migrated rules);
                // without it serving targets the occurrence-th content twin.
                $out['scope']   = ((string) ($ctx['scope'] ?? 'post')) === 'site' ? 'site' : 'post';
                $out['section'] = array(
                    'level'          => (int) ($ctx['level'] ?? 0),
                    'newLevel'       => (int) ($ctx['newLevel'] ?? ($ctx['level'] ?? 0)),
                    // Site scope ⇒ override semantics BY LAW — rules created
                    // before the flag existed keep serving.
                    'allOccurrences' => !empty($ctx['allOccurrences']) || $out['scope'] === 'site',
                    'frameOnly'      => !empty($ctx['frameOnly']),
                );
                $out['anchor'] = null;
            } else {
                $out['anchor'] = $ctx;
            }
            return $out;
        }, $rows);
    }

    // NOTE (consolidation, 2026-07-11): save_paragraph_rule was DELETED with
    // its endpoints — paragraph-rule CREATION had zero UI callers (the page +
    // section editors superseded it). Existing paragraph rules keep serving
    // and displaying; each dies via the absorb law on the next save touching
    // its section. The connector's paragraph pass follows in the owner-gated
    // fleet-convergence cleanup (deletions ledger).

    // =====================================================================
    // SECTION RULES (contracts v2, FROZEN 2026-07-09) — section editor phase 1.
    // =====================================================================

    /** Every rule row of one connected post — snapshot source for rollback + push. */
    private static function post_rule_rows(int $user_id, int $site_id, int $post_id): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_dynamic_rules');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, userId, siteId, postId, target, matchText, occurrence, replacement, anchorContext, active, staleCount FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d",
            $user_id,
            $site_id,
            $post_id
        ), ARRAY_A);
    }

    /**
     * Restore a post's rule set from a snapshot (ROLLBACK after a failed push —
     * hub DB must mirror what the connector actually serves; ids are restored
     * explicitly so insert-rule identities survive the rollback).
     */
    private static function restore_rule_rows(int $user_id, int $site_id, int $post_id, array $rows): void
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_dynamic_rules');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete($table, array('userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id), array('%d', '%d', '%d'));
        foreach ($rows as $r) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert($table, array(
                'id'            => (int) $r['id'],
                'userId'        => (int) $r['userId'],
                'siteId'        => (int) $r['siteId'],
                'postId'        => (int) $r['postId'],
                'target'        => (string) $r['target'],
                'matchText'     => (string) $r['matchText'],
                'occurrence'    => (int) $r['occurrence'],
                'replacement'   => (string) $r['replacement'],
                'anchorContext' => $r['anchorContext'] !== null ? (string) $r['anchorContext'] : null,
                'active'        => (int) $r['active'],
                'staleCount'    => (int) $r['staleCount'],
            ), array('%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%d'));
        }
    }

    /** Push a post's CURRENT hub rule set; on failure restore $snapshot and return the error. */
    private static function push_current_rules_or_rollback(int $user_id, object $site, int $post_id, array $snapshot)
    {
        $rows = self::post_rule_rows($user_id, (int) $site->id, $post_id);
        $push = self::push_rules($site, $post_id, self::rules_to_schema($rows));
        if ($push instanceof WP_Error) {
            self::restore_rule_rows($user_id, (int) $site->id, $post_id, $snapshot);
            return $push;
        }
        return $push;
    }

    /**
     * Save a SECTION replace rule (contracts v2) — same atomicity law as
     * every rule save: capability BEFORE any write, push-fail ROLLS BACK.
     *
     * UPSERT identity: siteId+postId+target='section'+matchText(heading)+occurrence.
     * CLEAN REVERT: a replacement that reproduces the original section exactly
     * (same heading text+level, same paragraph fingerprint, nothing extra)
     * deletes the rule. ABSORB: paragraph rules covered by this section are
     * deleted in the same push — a paragraph rule must never fight a section
     * rule over the same block (interaction law).
     *
     * @param array{headingText:string,headingLevel:int,headingOccurrence:int,
     *              paragraphs:array<int,array{text:string,occurrence:int}>,
     *              replacement:string} $input
     * @return array|\WP_Error
     */
    public function save_section_rule(int $user_id, object $site, int $post_id, array $input)
    {
        global $wpdb;
        $table       = PCM_Schema::table('seo_dynamic_rules');
        $site_id     = (int) $site->id;
        $match_text  = PCM_Text_Matcher::normalize((string) ($input['headingText'] ?? ''));
        $level       = max(1, min(6, (int) ($input['headingLevel'] ?? 2)));
        $occurrence  = max(0, (int) ($input['headingOccurrence'] ?? 0));
        $replacement = wp_kses_post((string) ($input['replacement'] ?? ''));
        $paragraphs  = array();
        $para_texts  = array();
        foreach ((array) ($input['paragraphs'] ?? array()) as $p) {
            if (!is_array($p) || !isset($p['text'])) {
                continue;
            }
            $paragraphs[] = array('text' => PCM_Text_Matcher::normalize((string) $p['text']), 'occurrence' => max(0, (int) ($p['occurrence'] ?? 0)));
            $para_texts[] = (string) $p['text'];
        }
        $fingerprint = PCM_Text_Matcher::fingerprint($para_texts);
        if ($match_text === '') {
            return new WP_Error('pcm_seo_rule_no_match', __('The section has no matchable heading text.', 'power-creatives'), array('status' => 400));
        }
        $units = PCM_Text_Matcher::parse_replacement_units($replacement);
        if (empty($units)) {
            return new WP_Error('pcm_seo_rule_empty', __('The replacement section is empty.', 'power-creatives'), array('status' => 400));
        }
        // Capability BEFORE any write — an old connector fails honestly, nothing half-done.
        if (self::connector_rules_schema_version($site) < 2) {
            return new WP_Error(
                'pcm_seo_connector_no_sections',
                __('This site’s connector doesn’t support section rules yet (needs v2.8.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }

        // CLEAN REVERT: the replacement reproduces the original section exactly —
        // heading unchanged (text + level) and paragraph fingerprint identical.
        $reverted = false;
        if (($units[0]['tag'] ?? '') === 'h' . $level
            && PCM_Text_Matcher::normalize(PCM_Text_Matcher::visible_text($units[0]['inner'])) === $match_text) {
            $rest_all_p = true;
            $rest_texts = array();
            foreach (array_slice($units, 1) as $u) {
                if ($u['tag'] !== 'p') {
                    $rest_all_p = false;
                    break;
                }
                $rest_texts[] = PCM_Text_Matcher::visible_text($u['inner']);
            }
            $reverted = $rest_all_p && PCM_Text_Matcher::fingerprint($rest_texts) === $fingerprint;
        }

        $snapshot = self::post_rule_rows($user_id, $site_id, $post_id);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $prev = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'section' AND matchText = %s AND occurrence = %d",
            $user_id,
            $site_id,
            $post_id,
            $match_text,
            $occurrence
        ), ARRAY_A);

        if ($reverted) {
            if (!$prev) {
                return array('reverted' => true, 'stored' => count($snapshot)); // nothing to do — no push needed
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($table, array('id' => (int) $prev['id']), array('%d'));
        } else {
            $ctx = wp_json_encode(array('level' => $level, 'fingerprint' => $fingerprint, 'paragraphs' => $paragraphs));
            if ($prev) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($table, array('replacement' => $replacement, 'anchorContext' => $ctx, 'active' => 1), array('id' => (int) $prev['id']), array('%s', '%s', '%d'), array('%d'));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert($table, array(
                    'userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id,
                    'target' => 'section', 'matchText' => $match_text, 'occurrence' => $occurrence,
                    'replacement' => $replacement, 'anchorContext' => $ctx, 'active' => 1,
                ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d'));
            }
            // ABSORB (one-owner, tightened 2026-07-11): covered paragraph rules
            // die with this push. A caller may know a paragraph by its ORIGINAL
            // text (unruled display) or by the rule's SERVED OUTPUT (the rule was
            // serving when the section was captured) — matching only the original
            // let a serving paragraph rule survive beside its new section owner
            // (observed live: rules #2 + #21 coexisting). Both forms match now.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $p_rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT id, matchText, occurrence, replacement FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'paragraph'",
                $user_id,
                $site_id,
                $post_id
            ), ARRAY_A);
            foreach ($p_rows as $p_row) {
                $served_out = PCM_Text_Matcher::normalize(PCM_Text_Matcher::visible_text((string) $p_row['replacement']));
                foreach ($paragraphs as $p) {
                    if (((string) $p_row['matchText'] === $p['text'] && (int) $p_row['occurrence'] === $p['occurrence'])
                        || ($served_out !== '' && $served_out === $p['text'])) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->delete($table, array('id' => (int) $p_row['id']), array('%d'));
                        break;
                    }
                }
            }
            // ABSORB (one-owner law, v2.2): a post-scope heading rule whose OUTPUT
            // is this section's heading dies here — its ORIGINAL identity (text,
            // occurrence, level) becomes the section's match key, so the section
            // owns the whole element and no rule chain survives the save.
            $section_rule_id = $prev ? (int) $prev['id'] : (int) $wpdb->insert_id;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $h_rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT id, matchText, occurrence, replacement, anchorContext FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'heading'",
                $user_id,
                $site_id,
                $post_id
            ), ARRAY_A);
            foreach ($h_rows as $hr) {
                $hctx = json_decode((string) ($hr['anchorContext'] ?? ''), true);
                $hctx = is_array($hctx) ? $hctx : array();
                if (PCM_Text_Matcher::normalize((string) $hr['replacement']) !== $match_text || (int) ($hctx['newLevel'] ?? 0) !== $level) {
                    continue;
                }
                $match_text = (string) $hr['matchText'];
                $occurrence = (int) $hr['occurrence'];
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($table, array(
                    'matchText'     => $match_text,
                    'occurrence'    => $occurrence,
                    'anchorContext' => wp_json_encode(array('level' => max(1, min(6, (int) ($hctx['level'] ?? $level))), 'fingerprint' => $fingerprint, 'paragraphs' => $paragraphs)),
                ), array('id' => $section_rule_id), array('%s', '%d', '%s'), array('%d'));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete($table, array('id' => (int) $hr['id']), array('%d'));
                break;
            }
        }

        $push = self::push_current_rules_or_rollback($user_id, $site, $post_id, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        // Version history: record ONLY after the push succeeded (a rolled-back
        // save must never leave a ghost version). Reverts record nothing — the
        // Original is always in the dropdown, read live from the scan.
        if (!$reverted) {
            self::record_version($user_id, $site_id, $post_id, 'section', $match_text, $occurrence, $replacement);
        }
        $out = array('stored' => (int) ($push['stored'] ?? 0));
        if ($reverted) {
            $out['reverted'] = true;
        } else {
            $out['rule'] = array('matchText' => $match_text, 'occurrence' => $occurrence, 'level' => $level, 'fingerprint' => $fingerprint, 'active' => true);
        }
        return $out;
    }

    /** Cap per identity — same runaway-guard pattern as the heading overrides list. */
    private const VERSION_CAP = 20;

    /**
     * Record one accepted state in the version history. Keyed by the row
     * IDENTITY (target + matchText + occurrence: a section's heading key, or
     * target='page' + '' for the whole-page document) — history survives the
     * rule row's deletion on clean revert. Consecutive duplicates are
     * skipped; history is capped (oldest rows dropped).
     */
    private static function record_version(int $user_id, int $site_id, int $post_id, string $target, string $match_text, int $occurrence, string $replacement): void
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_rule_versions');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $last = $wpdb->get_var($wpdb->prepare(
            "SELECT replacement FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = %s AND matchText = %s AND occurrence = %d ORDER BY id DESC LIMIT 1",
            $user_id,
            $site_id,
            $post_id,
            $target,
            $match_text,
            $occurrence
        ));
        if ($last !== null && (string) $last === $replacement) {
            return; // accepting the same content twice must not stack versions
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert($table, array(
            'userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id,
            'target' => $target, 'matchText' => $match_text, 'occurrence' => $occurrence,
            'replacement' => $replacement,
        ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = %s AND matchText = %s AND occurrence = %d ORDER BY id DESC",
            $user_id,
            $site_id,
            $post_id,
            $target,
            $match_text,
            $occurrence
        ));
        foreach (array_slice(array_map('intval', $ids), self::VERSION_CAP) as $old_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($table, array('id' => $old_id), array('%d'));
        }
    }

    /** Delete one saved version (ownership-checked; the Original is never a row,
     *  so it can never be deleted — it always comes live from the scan). */
    public function delete_section_version(int $user_id, int $version_id)
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_rule_versions');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted = $wpdb->delete($table, array('id' => $version_id, 'userId' => $user_id), array('%d', '%d'));
        if (!$deleted) {
            return new WP_Error('pcm_seo_version_not_found', __('That version no longer exists.', 'power-creatives'), array('status' => 404));
        }
        return array('deleted' => true);
    }

    /** Saved versions for one identity (target + matchText + occurrence), newest first. */
    private static function list_versions(int $user_id, int $site_id, int $post_id, string $target, string $match_text, int $occurrence): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_rule_versions');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, replacement, createdAt FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = %s AND matchText = %s AND occurrence = %d ORDER BY id DESC",
            $user_id,
            $site_id,
            $post_id,
            $target,
            $match_text,
            $occurrence
        ), ARRAY_A);
        return array_map(static fn($r) => array(
            'id'          => (int) $r['id'],
            'replacement' => (string) $r['replacement'],
            'createdAt'   => (string) $r['createdAt'],
        ), $rows);
    }

    /**
     * A section's saved versions, newest first — the editor's dropdown.
     *
     * @return array[] [{id, replacement, createdAt}, …]
     */
    public function list_section_versions(int $user_id, int $site_id, int $post_id, string $heading_text, int $occurrence): array
    {
        return self::list_versions($user_id, $site_id, $post_id, 'section', PCM_Text_Matcher::normalize($heading_text), $occurrence);
    }

    /**
     * The PAGE editor's version dropdown in one read: every saved page
     * document (newest first) + the true no-rules ORIGINAL — the rules-INPUT
     * snapshot assembled with the same law as the editor's document. An
     * empty originalHtml is honest (input view unavailable), never a guess.
     *
     * @return array{versions:array[],originalHtml:string}
     */
    public function list_page_versions(int $user_id, object $site, int $post_id): array
    {
        $original = '';
        $in       = self::remote_fetch_snapshot($site, $post_id, 'input');
        if ($in !== null && $in['html'] !== '') {
            // No rules passed → no attribution → every section assembles as
            // original content, which is exactly what "Original" means.
            $parsed   = self::parse_page_snapshot($in['html']);
            $original = self::assemble_content_html($in['html'], $parsed['headings']);
        }
        return array(
            'versions'     => self::list_versions($user_id, (int) $site->id, $post_id, 'page', '', 0),
            'originalHtml' => $original,
        );
    }

    /**
     * Save a SECTION INSERT rule (contracts v2): a complete NEW section anchored
     * before/after an existing heading. Identity = hub rule id (several inserts
     * may share an anchor). Saving an existing insert with an EMPTY replacement
     * deletes it (clean removal). Same capability/rollback laws as above.
     *
     * @param array{anchorText:string,anchorLevel:int,anchorOccurrence:int,
     *              position:string,replacement:string,ruleId?:int} $input
     * @return array|\WP_Error
     */
    public function save_section_insert(int $user_id, object $site, int $post_id, array $input)
    {
        global $wpdb;
        $table       = PCM_Schema::table('seo_dynamic_rules');
        $site_id     = (int) $site->id;
        $match_text  = PCM_Text_Matcher::normalize((string) ($input['anchorText'] ?? ''));
        $level       = max(1, min(6, (int) ($input['anchorLevel'] ?? 2)));
        $occurrence  = max(0, (int) ($input['anchorOccurrence'] ?? 0));
        $position    = ((string) ($input['position'] ?? 'after')) === 'before' ? 'before' : 'after';
        $replacement = wp_kses_post((string) ($input['replacement'] ?? ''));
        $rule_id     = isset($input['ruleId']) ? absint($input['ruleId']) : 0;
        if ($match_text === '') {
            return new WP_Error('pcm_seo_rule_no_match', __('New sections need an existing heading to anchor to.', 'power-creatives'), array('status' => 400));
        }
        if ($rule_id === 0 && trim(PCM_Text_Matcher::visible_text($replacement)) === '') {
            return new WP_Error('pcm_seo_rule_empty', __('The new section is empty.', 'power-creatives'), array('status' => 400));
        }
        if (self::connector_rules_schema_version($site) < 2) {
            return new WP_Error(
                'pcm_seo_connector_no_sections',
                __('This site’s connector doesn’t support section rules yet (needs v2.8.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }

        $prev = null;
        if ($rule_id > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $prev = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table} WHERE id = %d AND userId = %d AND siteId = %d AND postId = %d AND target = 'sectionInsert'",
                $rule_id,
                $user_id,
                $site_id,
                $post_id
            ), ARRAY_A);
            if (!$prev) {
                return new WP_Error('pcm_seo_rule_not_found', __('This added section no longer exists — re-open the outline.', 'power-creatives'), array('status' => 404));
            }
        }

        $snapshot = self::post_rule_rows($user_id, $site_id, $post_id);
        $removed  = false;
        $ctx      = wp_json_encode(array('level' => $level, 'position' => $position));
        if ($prev && trim(PCM_Text_Matcher::visible_text($replacement)) === '') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($table, array('id' => (int) $prev['id']), array('%d'));
            $removed = true;
        } elseif ($prev) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $table,
                array('matchText' => $match_text, 'occurrence' => $occurrence, 'replacement' => $replacement, 'anchorContext' => $ctx, 'active' => 1),
                array('id' => (int) $prev['id']),
                array('%s', '%d', '%s', '%s', '%d'),
                array('%d')
            );
            $rule_id = (int) $prev['id'];
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert($table, array(
                'userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id,
                'target' => 'sectionInsert', 'matchText' => $match_text, 'occurrence' => $occurrence,
                'replacement' => $replacement, 'anchorContext' => $ctx, 'active' => 1,
            ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d'));
            $rule_id = (int) $wpdb->insert_id;
        }

        $push = self::push_current_rules_or_rollback($user_id, $site, $post_id, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        $out = array('stored' => (int) ($push['stored'] ?? 0));
        if ($removed) {
            $out['removed'] = true;
        } else {
            $out['rule'] = array('id' => $rule_id, 'matchText' => $match_text, 'occurrence' => $occurrence, 'level' => $level, 'position' => $position, 'active' => true);
        }
        return $out;
    }

    /**
     * Save a heading instruction (instruction stream v2.2 — THE heading edit
     * mechanism on v3 connectors). Identity decides scope: postId 0 = site
     * chrome (allOccurrences, compiled-override semantics), postId N = one
     * page, one occurrence-th twin. UPSERT matches the CURRENT value first
     * (re-edit), then the original identity (stale outline raced the last
     * edit); editing back to the exact original deletes the rule (clean
     * revert); push-fail rolls back.
     *
     * @param array{postId:int,matchText:string,matchLevel:int,occurrence:int,
     *              currentText:string,currentLevel:int} $identity
     */
    public static function save_heading_rule(int $user_id, object $site, array $identity, ?string $new_text, int $new_level)
    {
        global $wpdb;
        $table     = PCM_Schema::table('seo_dynamic_rules');
        $site_id   = (int) $site->id;
        $post_id   = max(0, (int) ($identity['postId'] ?? 0));
        $match     = (string) ($identity['matchText'] ?? '');
        $m_level   = max(1, min(6, (int) ($identity['matchLevel'] ?? 0)));
        $occ       = max(0, (int) ($identity['occurrence'] ?? 0));
        $cur_text  = trim((string) ($identity['currentText'] ?? ''));
        $cur_level = max(1, min(6, (int) ($identity['currentLevel'] ?? $m_level)));
        $new_t     = trim((string) ($new_text !== null && $new_text !== '' ? $new_text : $cur_text));
        if ($match === '' || $new_level < 1 || $new_level > 6) {
            return new WP_Error('pcm_seo_rule_no_match', __('The heading has no matchable text.', 'power-creatives'), array('status' => 400));
        }
        if (self::connector_rules_schema_version($site) < 3) {
            return new WP_Error(
                'pcm_seo_connector_no_heading_rules',
                __('This site’s connector doesn’t support heading instructions yet (needs v3.0.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, matchText, occurrence, replacement, anchorContext FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'heading'",
            $user_id,
            $site_id,
            $post_id
        ), ARRAY_A);
        $prev = null;
        foreach ($rows as $r) {
            $ctx = json_decode((string) ($r['anchorContext'] ?? ''), true);
            $ctx = is_array($ctx) ? $ctx : array();
            // Re-edit of an already-instructed heading: the incoming "current"
            // is the rule's own output (what the user sees).
            if ((string) $r['replacement'] === $cur_text && (int) ($ctx['newLevel'] ?? 0) === $cur_level) {
                $prev = array('row' => $r, 'ctx' => $ctx);
                break;
            }
            // Same ORIGINAL identity edited again.
            if ((string) $r['matchText'] === $match && (int) ($ctx['level'] ?? 0) === $m_level && (int) $r['occurrence'] === $occ) {
                $prev = array('row' => $r, 'ctx' => $ctx);
                break;
            }
        }
        $snapshot = self::post_rule_rows($user_id, $site_id, $post_id);
        if ($prev) {
            $ctx      = $prev['ctx'];
            $original = (string) ($ctx['originalText'] ?? '');
            if ($original !== '' && $new_t === $original && $new_level === (int) ($ctx['level'] ?? 0)) {
                // Clean revert: back to the exact original → the rule dies.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete($table, array('id' => (int) $prev['row']['id']), array('%d'));
            } else {
                $ctx['newLevel'] = $new_level;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($table, array('replacement' => $new_t, 'anchorContext' => wp_json_encode($ctx), 'active' => 1), array('id' => (int) $prev['row']['id']), array('%s', '%s', '%d'), array('%d'));
            }
        } else {
            if ($new_t === $cur_text && $new_level === $cur_level) {
                return array('stored' => count($snapshot), 'noop' => true); // no-op edit, no rule
            }
            // originalText: exact when the row was un-instructed (currentText
            // IS the original); an instructed row always resolves to $prev.
            $ctx = array(
                'level'          => $m_level,
                'newLevel'       => $new_level,
                'scope'          => $post_id === 0 ? 'site' : 'post',
                'originalText'   => $cur_text,
                'allOccurrences' => $post_id === 0,
                // Frame edits apply ONLY inside the site frame (header/nav/
                // footer/aside) — identical text in some page's CONTENT is
                // never touched. Migrated legacy overrides keep their old
                // whole-page semantics (no flag).
                'frameOnly'      => $post_id === 0,
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert($table, array(
                'userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id,
                'target' => 'heading', 'matchText' => $match, 'occurrence' => $occ,
                'replacement' => $new_t, 'anchorContext' => wp_json_encode($ctx), 'active' => 1,
            ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d'));
        }
        $push = self::push_current_rules_or_rollback($user_id, $site, $post_id, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        return array('stored' => (int) ($push['stored'] ?? 0));
    }

    /**
     * ONE-OWNER LAW (v2.2, part A): when an active SECTION rule owns this
     * heading (keyed on its identity, replacement carries the heading unit),
     * a heading edit rewrites THAT rule's first unit — never stacks a second
     * rule on the same element. Returns NULL when the heading is not
     * section-owned (caller proceeds to the heading-rule path); array|WP_Error
     * otherwise. The rule's match identity is untouched (it keys on the
     * rules-input, which the replacement never changes); versions + rollback
     * ride the section mechanics.
     */
    private static function update_section_owned_heading(int $user_id, object $site, int $post_id, array $h, ?string $new_text, int $new_level)
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_dynamic_rules');
        $norm  = PCM_Text_Matcher::normalize((string) ($h['text'] ?? ''));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, replacement FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'section' AND matchText = %s AND occurrence = %d AND active = 1",
            $user_id,
            (int) $site->id,
            $post_id,
            $norm,
            (int) ($h['occurrence'] ?? 0)
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        $units = PCM_Text_Matcher::parse_replacement_units((string) $row['replacement']);
        if (empty($units) || !preg_match('/^h[1-6]$/', (string) $units[0]['tag'])
            || PCM_Text_Matcher::normalize(PCM_Text_Matcher::visible_text((string) $units[0]['inner'])) !== $norm) {
            return null; // the replacement doesn't carry this heading — not owned
        }
        return self::update_owned_heading_unit($user_id, $site, (int) $row['id'], 0, (string) $h['text'], $new_text, $new_level);
    }

    /**
     * Swap ONE heading unit inside an owning rule's replacement (one-owner +
     * served-truth laws): text/level change, the unit's own attributes kept,
     * everything else untouched. Push-fail rolls back; section versions ride.
     */
    private static function update_owned_heading_unit(int $user_id, object $site, int $rule_id, int $unit_index, string $current_text, ?string $new_text, int $new_level)
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_dynamic_rules');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, postId, target, matchText, occurrence, replacement FROM {$table} WHERE id = %d AND userId = %d AND siteId = %d AND target IN ('section','sectionInsert') AND active = 1",
            $rule_id,
            $user_id,
            (int) $site->id
        ), ARRAY_A);
        if (!$row) {
            return new WP_Error('pcm_seo_rule_not_found', __('This section no longer exists — re-open the outline.', 'power-creatives'), array('status' => 404));
        }
        $units = PCM_Text_Matcher::parse_replacement_units((string) $row['replacement']);
        if (!isset($units[$unit_index]) || !preg_match('/^h[1-6]$/', (string) $units[$unit_index]['tag'])) {
            return new WP_Error('pcm_seo_heading_stale', __('This page changed since it was scanned — re-open the outline.', 'power-creatives'), array('status' => 409));
        }
        $new_t = trim((string) ($new_text !== null && $new_text !== '' ? $new_text : $current_text));
        $safe  = wp_kses_post($new_t);
        $unit  = preg_replace_callback(
            '/^<h[1-6]([^>]*)>.*<\/h[1-6]>$/is',
            static fn($m) => '<h' . $new_level . $m[1] . '>' . $safe . '</h' . $new_level . '>',
            (string) $units[$unit_index]['html']
        );
        $replacement = '';
        foreach ($units as $i => $u) {
            $replacement .= ($i === $unit_index) ? (string) $unit : $u['html'];
        }
        $rule_post = (int) $row['postId'];
        $snapshot  = self::post_rule_rows($user_id, (int) $site->id, $rule_post);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update($table, array('replacement' => $replacement), array('id' => (int) $row['id']), array('%s'), array('%d'));
        $push = self::push_current_rules_or_rollback($user_id, $site, $rule_post, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        if ((string) $row['target'] === 'section') {
            self::record_version($user_id, (int) $site->id, $rule_post, 'section', (string) $row['matchText'], (int) $row['occurrence'], $replacement);
        }
        return array('stored' => (int) ($push['stored'] ?? 0));
    }

    /**
     * Save a SLICE of an owning rule's replacement (served-truth law): the
     * edited unit range is spliced in, the rest of the rule untouched. An
     * empty slice deletes the units; a rule left with no visible content is
     * removed for inserts and rejected for section rules (an empty section
     * replacement is never valid — revert deletes the rule instead).
     */
    public function save_section_slice(int $user_id, object $site, int $post_id, array $input)
    {
        global $wpdb;
        $table       = PCM_Schema::table('seo_dynamic_rules');
        $rule_id     = absint($input['ruleId'] ?? 0);
        $from        = max(0, (int) ($input['unitFrom'] ?? 0));
        $to          = max($from, (int) ($input['unitTo'] ?? $from));
        $replacement = wp_kses_post((string) ($input['replacement'] ?? ''));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, postId, target, matchText, occurrence, replacement FROM {$table} WHERE id = %d AND userId = %d AND siteId = %d AND target IN ('section','sectionInsert') AND active = 1",
            $rule_id,
            $user_id,
            (int) $site->id
        ), ARRAY_A);
        if (!$row) {
            return new WP_Error('pcm_seo_rule_not_found', __('This section no longer exists — re-open the outline.', 'power-creatives'), array('status' => 404));
        }
        $units = PCM_Text_Matcher::parse_replacement_units((string) $row['replacement']);
        if ($from >= count($units)) {
            return new WP_Error('pcm_seo_heading_stale', __('This page changed since it was scanned — re-open the outline.', 'power-creatives'), array('status' => 409));
        }
        $to  = min($to, count($units) - 1);
        $new = '';
        foreach ($units as $i => $u) {
            if ($i < $from || $i > $to) {
                $new .= $u['html'];
            } elseif ($i === $from) {
                $new .= $replacement;
            }
        }
        $rule_post = (int) $row['postId'];
        $snapshot  = self::post_rule_rows($user_id, (int) $site->id, $rule_post);
        $removed   = false;
        if (trim(PCM_Text_Matcher::visible_text($new)) === '') {
            if ((string) $row['target'] !== 'sectionInsert') {
                return new WP_Error('pcm_seo_rule_empty', __('The replacement section is empty.', 'power-creatives'), array('status' => 400));
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($table, array('id' => (int) $row['id']), array('%d'));
            $removed = true;
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update($table, array('replacement' => $new), array('id' => (int) $row['id']), array('%s'), array('%d'));
        }
        $push = self::push_current_rules_or_rollback($user_id, $site, $rule_post, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        if (!$removed && (string) $row['target'] === 'section') {
            self::record_version($user_id, (int) $site->id, $rule_post, 'section', (string) $row['matchText'], (int) $row['occurrence'], $new);
        }
        $out = array('stored' => (int) ($push['stored'] ?? 0));
        if ($removed) {
            $out['removed'] = true;
        }
        return $out;
    }

    /**
     * Save the FULL-PAGE editor's document (G3): slice the edited HTML back
     * into unit-sections with the SAME parser that defines identity, align
     * them against the served baseline (1:1 by order when counts match; LCS
     * on level|norm(heading) keys as fallback), and route every changed pair
     * through the EXISTING save paths — page-level editing, section-level
     * storage, engine untouched:
     *
     * - unchanged (heading text+level, paragraph fingerprint AND raw-unit
     *   visible text identical) → skip;
     * - changed + attributed to a WHOLE section rule → save_section_rule
     *   keyed on the RULE's OWN stored identity (matchText/level/occurrence/
     *   paragraphs from its anchorContext) — the existing UPSERT and CLEAN
     *   REVERT laws apply, so editing a section back to its original deletes
     *   the rule exactly like the section editor;
     * - changed + attributed to a partial slice or an insert →
     *   save_section_slice on the owning rule;
     * - changed + original → save_section_rule with the row's identity
     *   (matchText/matchLevel/matchOccurrence — for a heading-ruled row the
     *   served identity IS the rule's output, so the absorb law rewires it to
     *   the original and the one-owner law holds);
     * - EXTRA edited sections → checked against ACTIVE sectionRemove rules
     *   first (a restored section DELETES its remove rule — clean revert),
     *   else save_section_insert anchored to the preceding served heading;
     * - FEWER sections (v2.4) → RENAME PAIRING first (leftovers pair
     *   positionally inside the same gap between matched anchors — a renamed
     *   heading is a replace, never remove+add), then per missing section:
     *   insert-born → its rule deletes; rule-owned → the rule deletes AND a
     *   sectionRemove lands on the ORIGINAL identity; original →
     *   sectionRemove on the row identity;
     * - IMAGES (v2.4) reconcile per normalized src: fewer copies in the doc
     *   than served → hide rules (from the tail of the visible original
     *   occurrences); more copies (a restored version) → hide rules delete.
     *   Images inside removed sections vanish from the doc and are hidden by
     *   the same reconciliation — the owner's "goes with the section" law.
     * - An EMPTY document is a legal FULL WIPE (owner order 2026-07-12):
     *   every section removes, every image hides — reversible via versions.
     *   (The former confirm gate is deleted by owner order: a broken load
     *   cannot reach a save since the load-once fix, so it only cost a click.)
     *
     * F9 LANDMINE (defused here + in assemble_content_html): the editor's
     * document NEVER contains the page's original between-content as
     * editable units — images enter only as locked context and are stripped
     * from every save (they persist as untouched between-content); original
     * lists never enter at all. Any remaining non-img raw unit is therefore
     * either the owning rule's own content (slices) or user-created — both
     * engine-legal (the expand/swap mapping the section editor already
     * allows) — so they are KEPT.
     *
     * Each routed save pushes and rolls itself back (existing atomicity law);
     * a mid-sequence failure stops honestly, reporting what already saved.
     *
     * @return array{saved:int,inserted:int,skipped:int,notes:array<int,string>}|\WP_Error
     */
    public function save_page_edits(int $user_id, object $site, int $post_id, string $html)
    {
        $inv = self::served_inventory($site, $post_id, $user_id);
        if ($inv === null || $inv['view'] !== 'served') {
            return new WP_Error(
                'pcm_seo_page_edit_unavailable',
                __('Page editing needs the served page view (connector 3.0.1+) — update the connector from the Sites module, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        // ── Baseline: the served content sections, exactly as the editor was assembled. ──
        $run_for  = self::section_runs($inv['headings'], $inv['nodes']);
        $baseline = array();
        foreach ($inv['headings'] as $i => $h) {
            if (((string) ($h['scope'] ?? 'post')) === 'site') {
                continue;
            }
            $paras = isset($run_for[$i]) ? $run_for[$i] : array();
            $att   = (isset($h['rule']) && is_array($h['rule'])
                && in_array((string) ($h['rule']['target'] ?? ''), array('section', 'sectionInsert'), true))
                ? $h['rule'] : null;
            $baseline[] = array(
                'key'             => (int) $h['level'] . '|' . PCM_Text_Matcher::normalize((string) $h['text']),
                'text'            => (string) $h['text'],
                'level'           => (int) $h['level'],
                'occurrence'      => (int) ($h['occurrence'] ?? 0),
                'matchText'       => (string) ($h['matchText'] ?? PCM_Text_Matcher::normalize((string) $h['text'])),
                'matchLevel'      => (int) ($h['matchLevel'] ?? $h['level']),
                'matchOccurrence' => (int) ($h['matchOccurrence'] ?? 0),
                'fp'              => PCM_Text_Matcher::fingerprint(array_map(static fn($p) => (string) $p['text'], $paras)),
                'paras'           => array_map(static fn($p) => array('text' => (string) $p['text'], 'occurrence' => (int) ($p['occurrence'] ?? 0)), $paras),
                'slice'           => $att ? array(
                    'ruleId'   => (int) $att['id'],
                    'unitFrom' => (int) ($att['unitFrom'] ?? 0),
                    'unitTo'   => (int) ($att['unitTo'] ?? 0),
                    'whole'    => !empty($att['whole']),
                    'target'   => (string) ($att['target'] ?? ''),
                    'html'     => (string) ($att['sliceHtml'] ?? ''),
                ) : null,
            );
        }
        // NOTE: an EMPTY baseline is legal — a fully wiped page serves no
        // sections, and restoring one arrives here with every edited section
        // as an "extra" that matches its sectionRemove rule (wipe must never
        // be a one-way door — live-caught 2026-07-12). The nothing-to-do case
        // is guarded after the edited document is parsed.

        // ── The edited document, sliced by the SAME parser that defines identity. ──
        $units = PCM_Text_Matcher::parse_replacement_units($html);
        $secs  = self::split_unit_sections($units);
        $notes = array();
        // Empty-p law (mirrors the parse): visible-text-empty paragraphs never
        // count toward a section's fingerprint — a trailing editor paragraph
        // must not make an untouched section look changed.
        $nonempty = static fn(array $texts): array => array_values(array_filter($texts, static fn($t) => PCM_Text_Matcher::normalize((string) $t) !== ''));
        $edited   = array();
        foreach ($secs as $s) {
            $edited[] = array(
                'key'   => $s['level'] . '|' . $s['norm'],
                'level' => (int) $s['level'],
                'norm'  => (string) $s['norm'],
                'label' => PCM_Text_Matcher::visible_text((string) $units[$s['from']]['inner']),
                'fp'    => PCM_Text_Matcher::fingerprint($nonempty($s['ptexts'])),
                'units' => array_slice($units, $s['from'], $s['to'] - $s['from'] + 1),
            );
        }
        // An EMPTY edited document is a legal full wipe (owner order 2026-07-12):
        // every baseline section removes, every image hides — all reversible
        // via the versions dropdown.
        if (empty($baseline) && empty($edited)) {
            return new WP_Error(
                'pcm_seo_page_edit_no_sections',
                __('This page has no editable sections (no content headings were found).', 'power-creatives'),
                array('status' => 409)
            );
        }

        // ── Leading no-heading zone: skipped by design, honestly noted when it changed. ──
        $first_from   = !empty($secs) ? $secs[0]['from'] : count($units);
        $orphan_texts = array();
        for ($ui = 0; $ui < $first_from; $ui++) {
            if ((string) $units[$ui]['tag'] === 'p') {
                $orphan_texts[] = PCM_Text_Matcher::visible_text((string) $units[$ui]['inner']);
            }
        }
        $base_orphans = array();
        foreach ($inv['nodes'] as $n) {
            if (!isset($n['anchor']) || !is_array($n['anchor'])) {
                $base_orphans[] = (string) $n['text'];
            }
        }
        if (PCM_Text_Matcher::fingerprint($nonempty($orphan_texts)) !== PCM_Text_Matcher::fingerprint($nonempty($base_orphans))) {
            $notes[] = __('Content before the first heading can’t be edited yet — those changes weren’t saved.', 'power-creatives');
        }

        // ── Replacement builder (the image KEEP LAW, 2026-07-12): an <img>
        //    survives a save ONLY when the platform placed it — the
        //    data-pcm-added marker AND a src on the client site's own host
        //    (server-verified: a marker alone could ride in on pasted or AI
        //    content, but only our delivery channel produces client-host
        //    files). Locked originals and everything foreign strip exactly
        //    as before — the live page's own images stay untouched
        //    between-content.
        $site_host = strtolower((string) (wp_parse_url((string) $site->url, PHP_URL_HOST) ?: ''));
        $keep_img  = static function (string $tag) use ($site_host): bool {
            if ($site_host === '' || stripos($tag, 'data-pcm-added') === false) {
                return false;
            }
            // A locked tag is assembly-produced CONTEXT even if it carries the
            // added marker (defense in depth with the gap-scan skip): keeping
            // it would clone the image into the rule on every save.
            if (stripos($tag, 'data-pcm-locked') !== false) {
                return false;
            }
            if (!preg_match('#(?<![\w-])src\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $tag, $m)) {
                return false;
            }
            $src = PCM_Text_Matcher::normalize_src($m[2] !== '' ? $m[2] : (isset($m[3]) ? $m[3] : ''));
            return strtolower((string) (wp_parse_url($src, PHP_URL_HOST) ?: '')) === $site_host;
        };
        $strip_img_tags = static function (array $unit_list) use ($keep_img): array {
            $out = array();
            foreach ($unit_list as $u) {
                if ((string) $u['tag'] !== '') {
                    // Block units keep platform-added inline images too (TipTap
                    // emits added images top-level, but kses round-trips can
                    // nest them) — same keep law, one discriminator.
                    $html = (string) preg_replace_callback('#<img\b[^>]*>#i', static fn($m) => $keep_img((string) $m[0]) ? (string) $m[0] : '', (string) $u['html']);
                    $out[] = array('tag' => $u['tag'], 'inner' => (string) preg_replace_callback('#<img\b[^>]*>#i', static fn($m) => $keep_img((string) $m[0]) ? (string) $m[0] : '', (string) $u['inner']), 'html' => $html);
                    continue;
                }
                $rest = (string) preg_replace_callback('#<img\b[^>]*>#i', static fn($m) => $keep_img((string) $m[0]) ? (string) $m[0] : '', (string) $u['html']);
                if (trim($rest) !== '') {
                    $out[] = array('tag' => '', 'inner' => '', 'html' => trim($rest));
                }
            }
            return $out;
        };
        $join = static fn(array $unit_list): string => implode('', array_map(static fn($u) => (string) $u['html'], $unit_list));
        // Raw-unit visible text (normalized, img-stripped) — the fingerprint is
        // paragraph-only, so list edits need their own unchanged-check input.
        $rawtext = static function (array $unit_list) use ($strip_img_tags): string {
            $texts = array();
            foreach ($strip_img_tags($unit_list) as $u) {
                if ((string) $u['tag'] === '') {
                    $texts[] = PCM_Text_Matcher::visible_text((string) $u['html']);
                }
            }
            return PCM_Text_Matcher::normalize(implode(' ', $texts));
        };
        // Kept-image identity (the skip law's fifth input, 2026-07-12): the
        // four text inputs above are image-blind by construction (visible
        // text, paragraph fingerprint) — so adding OR deleting a platform-
        // placed image is invisible to them and the section would skip.
        // Ordered normalized srcs of KEPT imgs per side close that hole.
        // Section IDENTITY (keys/rename pairing/restore match) stays
        // image-blind by design: images don't define WHAT a section is,
        // only WHETHER it changed.
        $kept_imgs = static function (array $unit_list) use ($keep_img): string {
            $srcs = array();
            foreach ($unit_list as $u) {
                if (!preg_match_all('#<img\b[^>]*>#i', (string) $u['html'], $mm)) {
                    continue;
                }
                foreach ($mm[0] as $tag) {
                    if ($keep_img((string) $tag)
                        && preg_match('#(?<![\w-])src\s*=\s*("([^"]*)"|\'([^\']*)\')#i', (string) $tag, $m)) {
                        $srcs[] = PCM_Text_Matcher::normalize_src($m[2] !== '' ? $m[2] : (isset($m[3]) ? $m[3] : ''));
                    }
                }
            }
            return implode('|', $srcs);
        };

        // ── Alignment: 1:1 by order when counts match; LCS on keys otherwise. ──
        $pairs   = array();
        $extras  = array();
        $removed = array();
        if (count($edited) === count($baseline)) {
            foreach ($baseline as $bi => $unused) {
                $pairs[] = array($bi, $bi);
            }
        } else {
            $n  = count($baseline);
            $m  = count($edited);
            $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
            for ($i = $n - 1; $i >= 0; $i--) {
                for ($j = $m - 1; $j >= 0; $j--) {
                    $dp[$i][$j] = ($baseline[$i]['key'] === $edited[$j]['key'])
                        ? $dp[$i + 1][$j + 1] + 1
                        : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
                }
            }
            $i = 0;
            $j = 0;
            while ($i < $n && $j < $m) {
                if ($baseline[$i]['key'] === $edited[$j]['key']) {
                    $pairs[] = array($i, $j);
                    $i++;
                    $j++;
                } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                    $removed[] = $i;
                    $i++;
                } else {
                    $extras[] = $j;
                    $j++;
                }
            }
            while ($i < $n) {
                $removed[] = $i++;
            }
            while ($j < $m) {
                $extras[] = $j++;
            }
            // RENAME PAIRING (v2.4): leftovers pair positionally IN ORDER when
            // they sit in the SAME gap between matched anchors — a renamed
            // heading is a replace, never remove+add. Only the true count
            // difference stays removed/extra.
            if (!empty($removed) && !empty($extras)) {
                $segment_of = static function (int $idx, array $prs, int $side): int {
                    $seg = 0;
                    foreach ($prs as $pr) {
                        if ($pr[$side] < $idx) {
                            $seg++;
                        }
                    }
                    return $seg;
                };
                $still_removed = array();
                foreach ($removed as $bi) {
                    $seg    = $segment_of($bi, $pairs, 0);
                    $paired = false;
                    foreach ($extras as $k => $ej) {
                        if ($segment_of($ej, $pairs, 1) === $seg) {
                            $pairs[] = array($bi, $ej);
                            unset($extras[$k]);
                            $paired = true;
                            break;
                        }
                    }
                    if (!$paired) {
                        $still_removed[] = $bi;
                    }
                }
                $removed = $still_removed;
                $extras  = array_values($extras);
                usort($pairs, static fn($a, $b) => $a[0] <=> $b[0]);
            }
        }

        global $wpdb;
        $rules_table = PCM_Schema::table('seo_dynamic_rules');

        // ── RESTORE detection (v2.4): an extra section matching an ACTIVE
        //    sectionRemove rule's identity is a restore — its rule deletes. ──
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $remove_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, matchText, occurrence, anchorContext FROM {$rules_table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'sectionRemove' AND active = 1",
            $user_id,
            (int) $site->id,
            $post_id
        ), ARRAY_A);
        $restores    = array();
        $true_extras = array();
        foreach ($extras as $ej) {
            $e   = $edited[$ej];
            $hit = null;
            foreach ($remove_rows as $rk => $rrow) {
                $rctx = json_decode((string) ($rrow['anchorContext'] ?? ''), true);
                $rctx = is_array($rctx) ? $rctx : array();
                if ((string) $rrow['matchText'] === $e['norm'] && (int) ($rctx['level'] ?? 0) === $e['level']
                    && (string) ($rctx['fingerprint'] ?? '') === $e['fp']) {
                    $hit = $rrow;
                    unset($remove_rows[$rk]); // one restore per rule
                    break;
                }
            }
            if ($hit !== null) {
                $restores[] = $hit;
            } else {
                $true_extras[] = $ej;
            }
        }
        $extras = $true_extras;

        // ── IMAGE reconciliation (v2.4): per normalized src, doc vs baseline.
        //    Fewer copies in the doc → hide rules (tail of the visible original
        //    occurrences); more copies (a restored version) → hide rules delete.
        //    Images inside removed sections are simply missing from the doc —
        //    the same arithmetic hides them (the "goes with the section" law). ──
        $img_list = static function (string $doc): array {
            $out = array();
            if (preg_match_all('#<img\b[^>]*>#i', $doc, $mm)) {
                foreach ($mm[0] as $tag) {
                    if (stripos($tag, 'data-pcm-added') !== false) {
                        continue; // platform-added images live and die with their RULE content — never hide-reconciled
                    }
                    if (!preg_match('#(?<![\w-])src\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $tag, $sm)) {
                        continue;
                    }
                    $src = PCM_Text_Matcher::normalize_src($sm[2] !== '' ? $sm[2] : (isset($sm[3]) ? $sm[3] : ''));
                    if ($src === '') {
                        continue;
                    }
                    $attr = static function (string $name) use ($tag): string {
                        if (!preg_match('#(?<![\w-])' . $name . '\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $tag, $am)) {
                            return '';
                        }
                        return html_entity_decode($am[2] !== '' ? $am[2] : (isset($am[3]) ? $am[3] : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    };
                    $out[] = array('src' => $src, 'alt' => $attr('alt'), 'title' => $attr('title'));
                }
            }
            return $out;
        };
        $per_src = static function (array $imgs): array {
            $by = array();
            foreach ($imgs as $im) {
                $by[$im['src']][] = $im;
            }
            return $by;
        };
        $base_by = $per_src($img_list((string) ($inv['contentHtml'] ?? '')));
        $doc_by  = $per_src($img_list($html));
        // Active hide rules per src (occurrences live in ORIGINAL space).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $img_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, matchText, occurrence, replacement FROM {$rules_table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'image' AND active = 1",
            $user_id,
            (int) $site->id,
            $post_id
        ), ARRAY_A);
        $hidden_by = array();
        foreach ($img_rows as $ir) {
            $set = json_decode((string) $ir['replacement'], true);
            if (is_array($set) && !empty($set['hidden'])) {
                $hidden_by[(string) $ir['matchText']][] = (int) $ir['occurrence'];
            }
        }
        $hides   = array();
        $unhides = array();
        foreach (array_unique(array_merge(array_keys($base_by), array_keys($doc_by))) as $src) {
            $v_list = isset($base_by[$src]) ? $base_by[$src] : array();
            $v      = count($v_list);
            $d      = isset($doc_by[$src]) ? count($doc_by[$src]) : 0;
            $h_occs = isset($hidden_by[$src]) ? $hidden_by[$src] : array();
            sort($h_occs);
            if ($d < $v) {
                // Visible original occurrences = [0 .. v+|h|-1] minus the hidden set.
                $vis = array();
                for ($o = 0, $total = $v + count($h_occs); $o < $total; $o++) {
                    if (!in_array($o, $h_occs, true)) {
                        $vis[] = $o;
                    }
                }
                for ($k = $v - 1; $k >= $d; $k--) {
                    $hides[] = array(
                        'src'        => $src,
                        'occurrence' => $vis[$k],
                        'alt'        => (string) ($v_list[$k]['alt'] ?? ''),
                        'title'      => (string) ($v_list[$k]['title'] ?? ''),
                    );
                }
            } elseif ($d > $v && !empty($h_occs)) {
                foreach (array_slice(array_reverse($h_occs), 0, min($d - $v, count($h_occs))) as $occ) {
                    $unhides[] = array('src' => $src, 'occurrence' => (int) $occ);
                }
            }
        }

        // ── Route every pair through the existing save paths, document order. ──
        $saved    = 0;
        $inserted = 0;
        $skipped  = 0;
        $fail     = static fn(string $label, WP_Error $err, int $done): WP_Error => new WP_Error(
            $err->get_error_code(),
            sprintf(
                /* translators: 1: sections already saved, 2: section heading, 3: reason */
                __('Saved %1$d section(s), then “%2$s” failed: %3$s The remaining sections were not attempted — re-open the editor to continue.', 'power-creatives'),
                $done,
                $label,
                rtrim($err->get_error_message()) . (str_ends_with(rtrim($err->get_error_message()), '.') ? '' : '.')
            ),
            $err->get_error_data()
        );
        foreach ($pairs as $pair) {
            list($bi, $ej) = $pair;
            $b = $baseline[$bi];
            $e = $edited[$ej];
            // Baseline units: a slice's own for owned sections; original
            // sections enter the editor with NO raw units and can never
            // contain kept imgs (added imgs exist only in rule content).
            $base_units = $b['slice'] !== null
                ? PCM_Text_Matcher::parse_replacement_units((string) $b['slice']['html'])
                : array();
            if ($e['level'] === $b['level'] && $e['norm'] === PCM_Text_Matcher::normalize($b['text'])
                && $e['fp'] === $b['fp'] && $rawtext($e['units']) === $rawtext($base_units)
                && $kept_imgs($e['units']) === $kept_imgs($base_units)) {
                $skipped++;
                continue;
            }
            $replacement = $join($strip_img_tags($e['units']));
            $res         = null;
            if ($b['slice'] !== null && $b['slice']['whole'] && $b['slice']['target'] === 'section') {
                // WHOLE section rule: route through save_section_rule keyed on
                // the rule's OWN stored identity — UPSERT updates it, and the
                // clean-revert law deletes it when the edit reproduces the
                // original (the slice path could never revert).
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
                $rrow = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, matchText, occurrence, anchorContext FROM {$rules_table} WHERE id = %d AND userId = %d AND siteId = %d AND target = 'section' AND active = 1",
                    $b['slice']['ruleId'],
                    $user_id,
                    (int) $site->id
                ), ARRAY_A);
                $rctx = $rrow ? json_decode((string) ($rrow['anchorContext'] ?? ''), true) : null;
                if ($rrow && is_array($rctx) && !empty($rctx['level'])) {
                    $res = $this->save_section_rule($user_id, $site, $post_id, array(
                        'headingText'       => (string) $rrow['matchText'],
                        'headingLevel'      => (int) $rctx['level'],
                        'headingOccurrence' => (int) $rrow['occurrence'],
                        'paragraphs'        => array_values(array_filter((array) ($rctx['paragraphs'] ?? array()), 'is_array')),
                        'replacement'       => $replacement,
                    ));
                }
            }
            if ($res === null && $b['slice'] !== null) {
                $res = $this->save_section_slice($user_id, $site, $post_id, array(
                    'ruleId'      => $b['slice']['ruleId'],
                    'unitFrom'    => $b['slice']['unitFrom'],
                    'unitTo'      => $b['slice']['unitTo'],
                    'replacement' => $replacement,
                ));
            } elseif ($res === null) {
                $res = $this->save_section_rule($user_id, $site, $post_id, array(
                    'headingText'       => $b['matchText'],
                    'headingLevel'      => $b['matchLevel'],
                    'headingOccurrence' => $b['matchOccurrence'],
                    'paragraphs'        => $b['paras'],
                    'replacement'       => $replacement,
                ));
            }
            if ($res instanceof WP_Error) {
                return $fail($b['text'], $res, $saved + $inserted);
            }
            $saved++;
        }
        // ── Extra edited sections → inserts anchored to the preceding section's served heading. ──
        $anchor_for = static function (int $ej) use ($pairs, $baseline): array {
            $prev = null;
            foreach ($pairs as $pair) {
                if ($pair[1] < $ej) {
                    $prev = $baseline[$pair[0]];
                }
            }
            return $prev !== null
                ? array('section' => $prev, 'position' => 'after')
                : array('section' => $baseline[0], 'position' => 'before');
        };
        foreach ($extras as $ej) {
            $e = $edited[$ej];
            if (empty($baseline)) {
                // Nothing on the page to anchor a NEW section to (restores
                // above need no anchor — this is a genuinely new section on a
                // fully wiped page).
                return $fail($e['label'], new WP_Error(
                    'pcm_seo_page_edit_no_anchor',
                    __('Adding a new section needs at least one existing section to anchor to — restore a version first.', 'power-creatives'),
                    array('status' => 409)
                ), $saved + $inserted);
            }
            $anchor = $anchor_for($ej);
            $res    = $this->save_section_insert($user_id, $site, $post_id, array(
                'anchorText'       => $anchor['section']['text'],
                'anchorLevel'      => $anchor['section']['level'],
                'anchorOccurrence' => $anchor['section']['occurrence'],
                'position'         => $anchor['position'],
                'replacement'      => $join($strip_img_tags($e['units'])),
            ));
            if ($res instanceof WP_Error) {
                return $fail($e['label'], $res, $saved + $inserted);
            }
            $inserted++;
        }

        // ── CONFIRMED removals (v2.4), per kind. Whole-rule groups collapse to
        //    ONE removal of the rule's ORIGINAL section (the rule expanded that
        //    one original — deleting the rule + removing the original erases
        //    everything it produced). ──
        $removed_count = 0;
        $rule_total    = array(); // section-rule id → sections it owns in the baseline
        foreach ($baseline as $bb) {
            if ($bb['slice'] !== null && $bb['slice']['target'] === 'section') {
                $rid              = $bb['slice']['ruleId'];
                $rule_total[$rid] = ($rule_total[$rid] ?? 0) + 1;
            }
        }
        $rule_removing = array();
        foreach ($removed as $bi) {
            $b = $baseline[$bi];
            if ($b['slice'] !== null && $b['slice']['target'] === 'section') {
                $rule_removing[$b['slice']['ruleId']][] = $bi;
            }
        }
        $rules_done = array();
        foreach ($removed as $bi) {
            $b   = $baseline[$bi];
            $res = null;
            if ($b['slice'] !== null && $b['slice']['target'] === 'sectionInsert') {
                // Content WE added — its rule simply dies (existing removal law).
                $res = $this->save_section_insert($user_id, $site, $post_id, array(
                    'anchorText'       => $b['text'],
                    'anchorLevel'      => $b['level'],
                    'anchorOccurrence' => 0,
                    'position'         => 'after',
                    'replacement'      => '',
                    'ruleId'           => $b['slice']['ruleId'],
                ));
            } elseif ($b['slice'] !== null) {
                $rid = $b['slice']['ruleId'];
                if (count($rule_removing[$rid] ?? array()) >= ($rule_total[$rid] ?? PHP_INT_MAX)) {
                    // EVERY section of this rule is going: delete the rule and
                    // remove its ORIGINAL section in one push.
                    if (isset($rules_done[$rid])) {
                        continue; // handled with the group's first member
                    }
                    $rules_done[$rid] = true;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
                    $rrow = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, matchText, occurrence, anchorContext FROM {$rules_table} WHERE id = %d AND userId = %d AND siteId = %d AND target = 'section' AND active = 1",
                        $rid,
                        $user_id,
                        (int) $site->id
                    ), ARRAY_A);
                    $rctx = $rrow ? json_decode((string) ($rrow['anchorContext'] ?? ''), true) : null;
                    if ($rrow && is_array($rctx) && !empty($rctx['level'])) {
                        $res = $this->save_section_remove($user_id, $site, $post_id, array(
                            'headingText'       => (string) $rrow['matchText'],
                            'headingLevel'      => (int) $rctx['level'],
                            'headingOccurrence' => (int) $rrow['occurrence'],
                            'paragraphs'        => array_values(array_filter((array) ($rctx['paragraphs'] ?? array()), 'is_array')),
                            'absorbRuleId'      => (int) $rrow['id'],
                        ));
                    }
                } else {
                    // Part of a bigger rule: splice this section's units out.
                    $res = $this->save_section_slice($user_id, $site, $post_id, array(
                        'ruleId'      => $rid,
                        'unitFrom'    => $b['slice']['unitFrom'],
                        'unitTo'      => $b['slice']['unitTo'],
                        'replacement' => '',
                    ));
                }
            } else {
                // Original page section: the new sectionRemove rule.
                $res = $this->save_section_remove($user_id, $site, $post_id, array(
                    'headingText'       => $b['matchText'],
                    'headingLevel'      => $b['matchLevel'],
                    'headingOccurrence' => $b['matchOccurrence'],
                    'paragraphs'        => $b['paras'],
                ));
            }
            if ($res instanceof WP_Error) {
                return $fail($b['text'], $res, $saved + $inserted + $removed_count);
            }
            if ($res !== null) {
                $removed_count++;
            }
        }

        // ── Restores: the section is back in the doc — its remove rule dies. ──
        $restored = 0;
        foreach ($restores as $rrow) {
            $rctx = json_decode((string) ($rrow['anchorContext'] ?? ''), true);
            $rctx = is_array($rctx) ? $rctx : array();
            $res  = $this->save_section_remove($user_id, $site, $post_id, array(
                'headingText'       => (string) $rrow['matchText'],
                'headingLevel'      => (int) ($rctx['level'] ?? 2),
                'headingOccurrence' => (int) $rrow['occurrence'],
                'restore'           => true,
            ));
            if ($res instanceof WP_Error) {
                return $fail((string) $rrow['matchText'], $res, $saved + $inserted + $removed_count);
            }
            $restored++;
        }

        // ── Image visibility (v2.4): hides from the doc diff, un-hides from restores. ──
        $hidden_count = 0;
        foreach ($hides as $hh) {
            $res = $this->save_image_rule($user_id, $site, $post_id, array(
                'src'           => $hh['src'],
                'occurrence'    => $hh['occurrence'],
                'hidden'        => true,
                'originalAlt'   => $hh['alt'],
                'originalTitle' => $hh['title'],
            ));
            if ($res instanceof WP_Error) {
                return $fail($hh['src'], $res, $saved + $inserted + $removed_count);
            }
            $hidden_count++;
        }
        $unhidden = 0;
        foreach ($unhides as $uh) {
            $res = $this->save_image_rule($user_id, $site, $post_id, array(
                'src'        => $uh['src'],
                'occurrence' => $uh['occurrence'],
                'revert'     => true,
            ));
            if ($res instanceof WP_Error) {
                return $fail($uh['src'], $res, $saved + $inserted + $removed_count);
            }
            $unhidden++;
        }

        // Page version: ONE row per changing save — the document as submitted
        // (duplicate-skip + cap ride record_version). No-op saves record nothing.
        $changed = $saved + $inserted + $removed_count + $restored + $hidden_count + $unhidden;
        if ($changed > 0) {
            self::record_version($user_id, (int) $site->id, $post_id, 'page', '', 0, $html);
        }
        return array(
            'saved'    => $saved,
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'removed'  => $removed_count,
            'restored' => $restored,
            'hidden'   => $hidden_count,
            'unhidden' => $unhidden,
            'notes'    => array_values(array_unique($notes)),
        );
    }

    /**
     * Save (or restore) a SECTION REMOVE rule (engine v2.4, connector 3.0.3):
     * identity = the section identity (normalized heading + level + occurrence
     * + paragraph fingerprint); serving verify-first locates like a replace
     * and removes the section's blocks — between-content stays, image
     * visibility is the image rule's job. `restore: true` DELETES the rule
     * (the section is present again — clean revert). Same atomicity laws as
     * every save: capability BEFORE any write, push-fail ROLLS BACK.
     * `absorbRuleId` deletes the section's owning rule in the same push (a
     * removed rule-born section = the rule dies AND its original is removed).
     *
     * @param array{headingText:string,headingLevel:int,headingOccurrence:int,
     *              paragraphs:array<int,array{text:string,occurrence:int}>,
     *              restore?:bool,absorbRuleId?:int} $input
     * @return array|\WP_Error
     */
    public function save_section_remove(int $user_id, object $site, int $post_id, array $input)
    {
        global $wpdb;
        $table      = PCM_Schema::table('seo_dynamic_rules');
        $site_id    = (int) $site->id;
        $match_text = PCM_Text_Matcher::normalize((string) ($input['headingText'] ?? ''));
        $level      = max(1, min(6, (int) ($input['headingLevel'] ?? 2)));
        $occurrence = max(0, (int) ($input['headingOccurrence'] ?? 0));
        $restore    = !empty($input['restore']);
        $absorb_id  = isset($input['absorbRuleId']) ? absint($input['absorbRuleId']) : 0;
        $para_texts = array();
        $paragraphs = array();
        foreach ((array) ($input['paragraphs'] ?? array()) as $p) {
            if (!is_array($p) || !isset($p['text'])) {
                continue;
            }
            $paragraphs[] = array('text' => PCM_Text_Matcher::normalize((string) $p['text']), 'occurrence' => max(0, (int) ($p['occurrence'] ?? 0)));
            $para_texts[] = (string) $p['text'];
        }
        $fingerprint = PCM_Text_Matcher::fingerprint($para_texts);
        if ($match_text === '') {
            return new WP_Error('pcm_seo_rule_no_match', __('The section has no matchable heading text.', 'power-creatives'), array('status' => 400));
        }
        if (self::connector_rules_schema_version($site) < 5) {
            return new WP_Error(
                'pcm_seo_connector_no_removal',
                __('This site’s connector doesn’t support section removal yet (needs v3.0.3+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        $snapshot = self::post_rule_rows($user_id, $site_id, $post_id);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $prev = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'sectionRemove' AND matchText = %s AND occurrence = %d",
            $user_id,
            $site_id,
            $post_id,
            $match_text,
            $occurrence
        ), ARRAY_A);
        if ($restore) {
            if (!$prev) {
                return array('restored' => true, 'stored' => count($snapshot)); // nothing to do — no push needed
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($table, array('id' => (int) $prev['id']), array('%d'));
        } else {
            $ctx = wp_json_encode(array('level' => $level, 'fingerprint' => $fingerprint, 'paragraphs' => $paragraphs));
            if ($prev) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($table, array('anchorContext' => $ctx, 'active' => 1), array('id' => (int) $prev['id']), array('%s', '%d'), array('%d'));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert($table, array(
                    'userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id,
                    'target' => 'sectionRemove', 'matchText' => $match_text, 'occurrence' => $occurrence,
                    'replacement' => '', 'anchorContext' => $ctx, 'active' => 1,
                ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d'));
            }
            if ($absorb_id > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete($table, array('id' => $absorb_id, 'userId' => $user_id, 'siteId' => $site_id), array('%d', '%d', '%d'));
            }
            // ABSORB (one-owner, 2026-07-12): paragraph rules covering the
            // removed section could never serve again (their blocks are gone)
            // and would stale-leak forever — they die here, matched by
            // ORIGINAL identity OR served output (the tightened law).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $p_rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT id, matchText, occurrence, replacement FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'paragraph'",
                $user_id,
                $site_id,
                $post_id
            ), ARRAY_A);
            foreach ($p_rows as $p_row) {
                $served_out = PCM_Text_Matcher::normalize(PCM_Text_Matcher::visible_text((string) $p_row['replacement']));
                foreach ($paragraphs as $p) {
                    if (((string) $p_row['matchText'] === $p['text'] && (int) $p_row['occurrence'] === $p['occurrence'])
                        || ($served_out !== '' && $served_out === $p['text'])) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->delete($table, array('id' => (int) $p_row['id']), array('%d'));
                        break;
                    }
                }
            }
        }
        $push = self::push_current_rules_or_rollback($user_id, $site, $post_id, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        $out = array('stored' => (int) ($push['stored'] ?? 0));
        if ($restore) {
            $out['restored'] = true;
        } else {
            $out['rule'] = array('matchText' => $match_text, 'occurrence' => $occurrence, 'level' => $level, 'active' => true);
        }
        return $out;
    }

    /**
     * Save an IMAGE metadata rule (engine v2.3, connector 3.0.2): identity =
     * normalized src + occurrence among same-src CONTENT images; replacement
     * = attribute set {alt,title} served as an attr rewrite ONLY (never
     * src/position/existence). anchorContext stores the ORIGINAL alt/title —
     * captured ONCE at rule creation (for an unruled image the editor's
     * current attrs ARE the originals) and never overwritten by re-edits.
     * CLEAN REVERT: editing both attrs back to the originals (or the explicit
     * revert flag) deletes the rule. Same atomicity laws as every save:
     * capability BEFORE any write, push-fail ROLLS BACK.
     *
     * @param array{src:string,occurrence:int,alt:string,title:string,
     *              originalAlt?:string,originalTitle?:string,revert?:bool} $input
     * @return array|\WP_Error
     */
    public function save_image_rule(int $user_id, object $site, int $post_id, array $input)
    {
        global $wpdb;
        $table      = PCM_Schema::table('seo_dynamic_rules');
        $site_id    = (int) $site->id;
        $src        = PCM_Text_Matcher::normalize_src((string) ($input['src'] ?? ''));
        $occurrence = max(0, (int) ($input['occurrence'] ?? 0));
        $alt        = sanitize_text_field((string) ($input['alt'] ?? ''));
        $title      = sanitize_text_field((string) ($input['title'] ?? ''));
        $revert     = !empty($input['revert']);
        $hidden     = !empty($input['hidden']); // v2.4: hide at render (never storage/media)
        if ($src === '') {
            return new WP_Error('pcm_seo_rule_no_match', __('The image has no usable src to match on.', 'power-creatives'), array('status' => 400));
        }
        $min_schema = $hidden ? 5 : 4;
        if (self::connector_rules_schema_version($site) < $min_schema) {
            return new WP_Error(
                $hidden ? 'pcm_seo_connector_no_removal' : 'pcm_seo_connector_no_image_rules',
                $hidden
                    ? __('This site’s connector doesn’t support image hiding yet (needs v3.0.3+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives')
                    : __('This site’s connector doesn’t support image metadata rules yet (needs v3.0.2+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $prev = $wpdb->get_row($wpdb->prepare(
            "SELECT id, anchorContext FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'image' AND matchText = %s AND occurrence = %d",
            $user_id,
            $site_id,
            $post_id,
            $src,
            $occurrence
        ), ARRAY_A);
        $prev_ctx = $prev ? json_decode((string) ($prev['anchorContext'] ?? ''), true) : null;
        $prev_ctx = is_array($prev_ctx) ? $prev_ctx : array();
        // Originals: the stored ones for an existing rule; for a NEW rule the
        // caller's current attrs (no rule has touched them = they ARE original).
        $orig_alt   = $prev ? (string) ($prev_ctx['originalAlt'] ?? '') : sanitize_text_field((string) ($input['originalAlt'] ?? ''));
        $orig_title = $prev ? (string) ($prev_ctx['originalTitle'] ?? '') : sanitize_text_field((string) ($input['originalTitle'] ?? ''));
        // A hide request is never an accidental revert — only the explicit
        // flag or an attrs-back-to-original METADATA save deletes the rule.
        $reverted = $revert || (!$hidden && $alt === $orig_alt && $title === $orig_title);

        $snapshot = self::post_rule_rows($user_id, $site_id, $post_id);
        if ($reverted) {
            if (!$prev) {
                return array('reverted' => true, 'stored' => count($snapshot)); // nothing to do — no push needed
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($table, array('id' => (int) $prev['id']), array('%d'));
        } else {
            $replacement = (string) wp_json_encode($hidden ? array('hidden' => true) : array('alt' => $alt, 'title' => $title));
            $ctx         = (string) wp_json_encode(array('originalAlt' => $orig_alt, 'originalTitle' => $orig_title));
            if ($prev) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($table, array('replacement' => $replacement, 'active' => 1), array('id' => (int) $prev['id']), array('%s', '%d'), array('%d'));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert($table, array(
                    'userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id,
                    'target' => 'image', 'matchText' => $src, 'occurrence' => $occurrence,
                    'replacement' => $replacement, 'anchorContext' => $ctx, 'active' => 1,
                ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d'));
            }
        }
        $push = self::push_current_rules_or_rollback($user_id, $site, $post_id, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        $out = array('stored' => (int) ($push['stored'] ?? 0));
        if ($reverted) {
            $out['reverted'] = true;
            // The editor applies these in place — it never reloads the document.
            $out['original'] = array('alt' => $orig_alt, 'title' => $orig_title);
        } else {
            $out['rule'] = array('src' => $src, 'occurrence' => $occurrence, 'alt' => $alt, 'title' => $title, 'hidden' => $hidden, 'active' => true);
        }
        return $out;
    }

    /**
     * MIGRATE one site's legacy heading overrides → site-scope heading
     * instructions (cleanup C4). Per site, verified before anything drops:
     * read the list via the 3.0.0 config channel → upsert heading rules →
     * ONE push (rollback on fail) → VERIFY the connector's stored siteRules
     * round-trip → clear the legacy option (the old buffer then no-ops via
     * its own empty-list early-return). Idempotent — re-running with an
     * empty list is a no-op.
     *
     * @return array{migrated:int,cleared:bool}|\WP_Error
     */
    public static function migrate_site_overrides(int $user_id, object $site)
    {
        global $wpdb;
        self::ensure_sites_service();
        if (self::connector_rules_schema_version($site) < 3) {
            return new WP_Error(
                'pcm_seo_connector_no_heading_rules',
                __('This site’s connector doesn’t support heading instructions yet (needs v3.0.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        $cfg = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/config');
        if (is_wp_error($cfg) || (int) ($cfg['status'] ?? 0) >= 300 || !is_array($cfg['body'] ?? null)) {
            return new WP_Error('pcm_seo_migrate_read', __('Could not read the site’s legacy override list.', 'power-creatives'), array('status' => 502));
        }
        $overrides = array_values(array_filter((array) ($cfg['body']['overrides'] ?? array()), 'is_array'));
        if (empty($overrides)) {
            return array('migrated' => 0, 'cleared' => false);
        }
        $table    = PCM_Schema::table('seo_dynamic_rules');
        $site_id  = (int) $site->id;
        $snapshot = self::post_rule_rows($user_id, $site_id, 0);
        $count    = 0;
        foreach ($overrides as $o) {
            $old_t = trim((string) ($o['oldText'] ?? ''));
            $new_t = trim((string) ($o['newText'] ?? ''));
            $old_l = max(1, min(6, (int) ($o['oldLevel'] ?? 0)));
            $new_l = max(1, min(6, (int) ($o['newLevel'] ?? 0)));
            if ($old_t === '' || $new_t === '') {
                continue;
            }
            $norm = PCM_Text_Matcher::normalize($old_t);
            // allOccurrences: migrated legacy overrides keep their apply-to-all semantics.
            $ctx  = wp_json_encode(array('level' => $old_l, 'newLevel' => $new_l, 'scope' => 'site', 'originalText' => $old_t, 'allOccurrences' => true));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $prev = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE userId = %d AND siteId = %d AND postId = 0 AND target = 'heading' AND matchText = %s",
                $user_id,
                $site_id,
                $norm
            ));
            if ($prev) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($table, array('replacement' => $new_t, 'anchorContext' => $ctx, 'active' => 1), array('id' => (int) $prev), array('%s', '%s', '%d'), array('%d'));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert($table, array(
                    'userId' => $user_id, 'siteId' => $site_id, 'postId' => 0,
                    'target' => 'heading', 'matchText' => $norm, 'occurrence' => 0,
                    'replacement' => $new_t, 'anchorContext' => $ctx, 'active' => 1,
                ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d'));
            }
            $count++;
        }
        $push = self::push_current_rules_or_rollback($user_id, $site, 0, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        // VERIFY the connector actually stores every migrated instruction
        // before the legacy list is cleared — no big-bang, per the plan.
        $check = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/rules');
        $stored = (!is_wp_error($check) && is_array($check['body']['siteRules'] ?? null))
            ? array_column(array_filter((array) $check['body']['siteRules'], 'is_array'), 'match')
            : array();
        $stored_texts = array_map(static fn($m) => (string) ($m['text'] ?? ''), $stored);
        foreach ($overrides as $o) {
            $old_t = trim((string) ($o['oldText'] ?? ''));
            $new_t = trim((string) ($o['newText'] ?? ''));
            if ($old_t === '' || $new_t === '') {
                continue;
            }
            if (!in_array(PCM_Text_Matcher::normalize($old_t), $stored_texts, true)) {
                return new WP_Error(
                    'pcm_seo_migrate_verify',
                    __('Verification failed: the connector did not store every migrated instruction — the legacy overrides were NOT cleared, nothing changed on the live site.', 'power-creatives'),
                    array('status' => 502)
                );
            }
        }
        $clear = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/config', array(), array('clearOverrides' => true), 30);
        if (is_wp_error($clear) || (int) ($clear['status'] ?? 0) >= 300) {
            // Instructions serve already (after the overrides — same output);
            // the legacy list stayed. Safe but unfinished — say exactly that.
            return new WP_Error('pcm_seo_migrate_clear', __('Instructions are live, but clearing the legacy override list failed — retry the migration.', 'power-creatives'), array('status' => 502));
        }
        return array('migrated' => $count, 'cleared' => true);
    }

    /**
     * RE-KEY section/sectionInsert rules after a successful heading source-edit
     * (interaction law): their matchText/occurrence/level follow the heading so
     * the rules keep serving. Push-fail restores the snapshot — hub and connector
     * then AGREE on the old-keyed (now honestly stale) state; the stale flag is
     * the surface, never a divergence.
     */
    private static function rekey_section_rules(int $user_id, object $site, int $post_id, string $old_norm, int $old_occ, string $new_norm, int $new_occ, int $new_level): void
    {
        global $wpdb;
        // NOTE: even when text+occurrence are unchanged the LEVEL may have changed —
        // the update below always runs against whatever rules key on the old identity.
        $table = PCM_Schema::table('seo_dynamic_rules');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, anchorContext FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target IN ('section','sectionInsert') AND matchText = %s AND occurrence = %d",
            $user_id,
            (int) $site->id,
            $post_id,
            $old_norm,
            $old_occ
        ), ARRAY_A);
        if (empty($rows)) {
            return;
        }
        $snapshot = self::post_rule_rows($user_id, (int) $site->id, $post_id);
        foreach ($rows as $r) {
            $ctx = json_decode((string) ($r['anchorContext'] ?? ''), true);
            $ctx = is_array($ctx) ? $ctx : array();
            $ctx['level'] = $new_level;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $table,
                array('matchText' => $new_norm, 'occurrence' => $new_occ, 'anchorContext' => wp_json_encode($ctx)),
                array('id' => (int) $r['id']),
                array('%s', '%d', '%s'),
                array('%d')
            );
        }
        self::push_current_rules_or_rollback($user_id, $site, $post_id, $snapshot);
    }

    /** AI-rewrite a whole section / draft a NEW one (NOT saved — staged). Returns { value } = block HTML. */
    public static function remote_optimize_section(object $site, int $post_id, string $type, string $html, string $topic = '', ?string $model = null, ?int $user_id = null, ?string $provider = null, ?int $template_id = null)
    {
        self::ensure_sites_service();
        $route = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
        $res   = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('_fields' => 'id,title,slug,link,author,meta'));
        $row   = (!is_wp_error($res) && is_array($res['body'] ?? null)) ? self::remote_row($res['body'], $type, $site) : array();
        $vars  = self::remote_field_vars($site, $row);
        $vars['current_value'] = $html;
        $vars['topic']         = $topic;
        $mode  = ($html !== '') ? 'optimize' : 'generate';
        $max   = (int) (self::field_prompts()['section']['max'] ?? 1200);
        $val   = self::run_prompt_section('section', $mode, $vars, $max, $model, $user_id, $provider, $template_id, false);
        if ($val instanceof WP_Error) {
            return $val;
        }
        return array('value' => wp_kses_post((string) $val));
    }

    /**
     * Dropdown option data for the content table (authors + statuses).
     *
     * @return array
     */
    public function get_options(): array
    {
        $authors = array();
        foreach (get_users(array('capability' => array('edit_posts'), 'fields' => array('ID', 'display_name'))) as $u) {
            $authors[] = array('id' => (int) $u->ID, 'name' => $u->display_name);
        }
        return array(
            'authors'    => $authors,
            'statuses'   => self::VALID_STATUSES,
            'types'      => self::VALID_TYPES,
            'seoPlugin'  => self::detect_seo_plugin(),
        );
    }

    // =====================================================================
    // Saved Views — per-user column/filter configurations
    // =====================================================================

    /**
     * List a user's saved views, newest first.
     *
     * @param int $userId PCM user id (wp_pcm_users.id).
     * @return array[] [{ id:int, name:string, config:array }, ...].
     */
    public function list_views(int $userId): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_views');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, config, isDefault FROM {$table} WHERE userId = %d ORDER BY id DESC",
            $userId
        ));

        $views = array();
        foreach ($rows as $row) {
            $config = json_decode((string) $row->config, true);
            $views[] = array(
                'id'        => (int) $row->id,
                'name'      => (string) $row->name,
                'config'    => is_array($config) ? $config : array(),
                'isDefault' => (bool) (int) $row->isDefault,
            );
        }
        return $views;
    }

    /**
     * Create a saved view for a user.
     *
     * @param int    $userId PCM user id.
     * @param string $name   Sanitized, non-empty view name.
     * @param array  $config { columns: {colKey:bool}, filters: {colKey:string} }.
     * @return array { id:int, name:string, config:array }.
     */
    public function create_view(int $userId, string $name, array $config): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_views');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            array(
                'userId' => $userId,
                'name'   => $name,
                'config' => wp_json_encode($config),
            ),
            array('%d', '%s', '%s')
        );

        return array(
            'id'        => (int) $wpdb->insert_id,
            'name'      => $name,
            'config'    => $config,
            'isDefault' => false,
        );
    }

    /**
     * Mark a view as the user's default (or clear it), enforcing at most one
     * default per user. Only affects views owned by the given user.
     *
     * @param int  $id        View id.
     * @param int  $userId    PCM user id.
     * @param bool $isDefault True to make this the default, false to unset it.
     * @return bool True if the target view exists and belongs to the user.
     */
    public function set_default_view(int $id, int $userId, bool $isDefault): bool
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_views');

        // Ownership check — never touch another user's views.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $owned = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE id = %d AND userId = %d",
            $id,
            $userId
        ));
        if ($owned === 0) {
            return false;
        }

        // Clear any existing default for this user (single-default invariant).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update($table, array('isDefault' => 0), array('userId' => $userId), array('%d'), array('%d'));

        if ($isDefault) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $table,
                array('isDefault' => 1),
                array('id' => $id, 'userId' => $userId),
                array('%d'),
                array('%d', '%d')
            );
        }
        return true;
    }

    /**
     * Delete a saved view, but only if it belongs to the given user.
     *
     * @param int $id     View id.
     * @param int $userId PCM user id.
     * @return bool True if a row was deleted, false if none matched.
     */
    public function delete_view(int $id, int $userId): bool
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_views');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted = $wpdb->delete(
            $table,
            array('id' => $id, 'userId' => $userId),
            array('%d', '%d')
        );
        return (int) $deleted > 0;
    }

    // =====================================================================
    // AI field generation (Phase 3) — reuses PC's PCM_LLM provider routing
    // =====================================================================

    /** Cell field → prompt `use` key. Only these fields are AI-generatable. */
    public static function field_use_map(): array
    {
        return array(
            'title'           => 'page_title',
            'metaTitle'       => 'meta_title',
            'metaDescription' => 'meta_description',
            'metaKeywords'    => 'meta_keywords',
            'primaryKeyword'  => 'primary_keyword',
            'slug'            => 'slug',
        );
    }

    /**
     * Default field prompts (verbatim port), filterable for site overrides.
     *
     * @return array<string, array{max:int, generate:string, optimize?:string}>
     */
    public static function field_prompts(): array
    {
        static $prompts = null;
        if ($prompts === null) {
            $prompts = require __DIR__ . '/prompts.php';
        }
        $filtered = apply_filters('pcm_seo_field_prompts', $prompts);
        return is_array($filtered) ? $filtered : $prompts;
    }

    /**
     * Flatten the field prompts into the shared Prompt-Editor registry shape
     * ({module:'seo'} → section → content string). Sections are keyed
     * `{use}_{mode}` (e.g. `page_title_generate`, `content_optimize`). These
     * are the verbatim shipped defaults — surfaced as the "Built-in" variant
     * in Settings → Prompts → SEO and used whenever the user has no active
     * override. Consumed by PCM_REST_Prompts::get_default_prompt().
     *
     * @return array<string, string>
     */
    public static function get_default_prompts(): array
    {
        $out = array();
        foreach (self::field_prompts() as $use => $cfg) {
            if (!empty($cfg['generate'])) {
                $out[$use . '_generate'] = (string) $cfg['generate'];
            }
            if (!empty($cfg['optimize'])) {
                $out[$use . '_optimize'] = (string) $cfg['optimize'];
            }
        }
        return $out;
    }

    /**
     * Resolve the prompt template for a Prompt-Editor section: the user's
     * ACTIVE override (Settings → Prompts → SEO) when present, else the shipped
     * default. Mirrors PCM_Writer_Service::get_system_prompt.
     *
     * @param string   $section Section key, e.g. `meta_title_optimize`.
     * @param string   $default Shipped default template (fallback).
     * @param int|null $user_id PCM user id (wp_pcm_users.id), NOT the WP user id.
     * @return string
     */
    public static function resolve_prompt(string $section, string $default, ?int $user_id = null, ?int $template_id = null): string
    {
        if ($user_id && $user_id > 0) {
            // Prompts now live as Templates (module=seo). Seed defaults once, then
            // resolve the chosen/default template for this section.
            self::seed_seo_templates();
            $tpl = self::seo_template_prompt($user_id, $section, $template_id);
            if ($tpl !== null && $tpl !== '') {
                return $tpl;
            }
            // Legacy fallback: a pre-migration Settings→Prompts→SEO override.
            global $wpdb;
            $table    = PCM_Schema::table('prompt_overrides');
            $override = $wpdb->get_var($wpdb->prepare(
                "SELECT content FROM {$table} WHERE userId = %d AND module = 'seo' AND section = %s AND isActive = 1 ORDER BY updatedAt DESC LIMIT 1",
                $user_id,
                $section
            ));
            if (!empty($override)) {
                return (string) $override;
            }
        }
        return $default;
    }

    /**
     * Seed one default prompt Template per SEO section for a user (idempotent).
     * Stored in wp_pcm_templates (module=seo, formData={type,section,prompt,isDefault})
     * so prompts are managed as Templates rather than Prompt-Editor overrides.
     */
    public static function seed_seo_templates(): void
    {
        global $wpdb;
        $table = PCM_Schema::table('templates');
        // SYSTEM set (userId=0) — one shared default per section, visible to every
        // user (the Templates list + resolver both read `userId = me OR 0`). Idempotent.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows  = $wpdb->get_col("SELECT formData FROM {$table} WHERE userId = 0 AND module = 'seo'");
        $have  = array();
        foreach ($rows as $json) {
            $fd = json_decode((string) $json, true);
            if (!empty($fd['type'])) {
                $have[$fd['type']] = true;
            }
        }
        // Stored in the SAME shape the Templates module edits: module=seo,
        // formData.type=<section>, one entry {category:'prompt', value:<prompt>}.
        foreach (self::get_default_prompts() as $section => $prompt) {
            if (isset($have[$section])) {
                continue;
            }
            $name = self::seo_section_label($section);
            $form = array(
                'type'      => $section,
                'entries'   => array(array(
                    'key'      => 'prompt_' . $section,
                    'category' => 'prompt',
                    'label'    => $name,
                    'value'    => $prompt,
                )),
                'sortOrder' => 0,
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert($table, array(
                'userId'    => 0,
                'name'      => $name,
                'module'    => 'seo',
                'formData'  => wp_json_encode($form),
                'isDefault' => 1,
            ), array('%d', '%s', '%s', '%s', '%d'));
        }
    }

    /** Resolve a section's prompt from Templates (module=seo): the chosen template,
     *  else the user's own default, else the SYSTEM default, else any. */
    private static function seo_template_prompt(int $user_id, string $section, ?int $template_id): ?string
    {
        global $wpdb;
        $table = PCM_Schema::table('templates');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT id, userId, formData, isDefault FROM {$table} WHERE (userId = %d OR userId = 0) AND module = 'seo'", $user_id), ARRAY_A);
        $chosen = null;
        $chosen_shared = false; // requested id points at a SHARED (userId=0) row
        $user_fork = null;      // the user's edited copy of this section (fork-on-edit)
        $user_default = null;
        $system_default = null;
        $any = null;
        foreach ($rows as $r) {
            $fd = json_decode((string) ($r['formData'] ?? ''), true);
            if (!is_array($fd) || ($fd['type'] ?? '') !== $section) {
                continue;
            }
            $prompt = self::seo_entry_prompt($fd);
            if ($prompt === null) {
                continue;
            }
            if ($template_id && (int) $r['id'] === $template_id) {
                $chosen = $prompt;
                $chosen_shared = ((int) $r['userId'] === 0);
            }
            if ((int) $r['userId'] === $user_id && $user_fork === null) {
                $user_fork = $prompt;
            }
            if (!empty($r['isDefault'])) {
                if ((int) $r['userId'] === $user_id && $user_default === null) {
                    $user_default = $prompt;
                } elseif ((int) $r['userId'] === 0 && $system_default === null) {
                    $system_default = $prompt;
                }
            }
            if ($any === null) {
                $any = $prompt;
            }
        }
        // A picker can pass the ORIGINAL shared template's id from a cached list even after the
        // user's edit forked it into their own copy (the shared row stays in the DB, only hidden
        // from the list). Honoring the stale shared row would silently ignore the user's edit —
        // redirect to their fork of the same section instead.
        if ($chosen !== null && $chosen_shared && $user_fork !== null) {
            $chosen = $user_fork;
        }
        return $chosen ?? $user_default ?? $system_default ?? $any;
    }

    /** Extract the prompt string from a SEO template's formData (entries[].value). */
    private static function seo_entry_prompt(array $fd): ?string
    {
        $entries = (isset($fd['entries']) && is_array($fd['entries'])) ? $fd['entries'] : array();
        foreach ($entries as $e) {
            if (($e['category'] ?? '') === 'prompt' && isset($e['value'])) {
                return (string) $e['value'];
            }
        }
        return isset($entries[0]['value']) ? (string) $entries[0]['value'] : null;
    }

    /** Human label for a section key, e.g. `meta_title_optimize` → "Meta Title — Optimize". */
    private static function seo_section_label(string $section): string
    {
        $mode = '';
        if (str_ends_with($section, '_generate')) {
            $mode = 'Generate';
            $section = substr($section, 0, -9);
        } elseif (str_ends_with($section, '_optimize')) {
            $mode = 'Optimize';
            $section = substr($section, 0, -9);
        }
        $field = ucwords(str_replace('_', ' ', $section));
        return $mode !== '' ? "{$field} — {$mode}" : $field;
    }

    /**
     * Substitute {{key}} placeholders. Keys are matched literally (the map
     * carries the exact key strings, incl. dotted/piped ones), mirroring the
     * source's replacePromptVariables.
     *
     * @param string               $template Prompt template.
     * @param array<string, string> $vars    key => value.
     * @return string
     */
    public static function substitute_vars(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }
        return $template;
    }

    /**
     * Trim AI output and strip a single matching pair of surrounding quotes
     * (faithful to opt_simple_sanitize_ai_output).
     *
     * @param string $content Raw model output.
     * @return string
     */
    public static function sanitize_ai_output(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }
        // Strip a wrapping code fence (```lang ... ```), if any.
        if (str_starts_with($content, '```')) {
            $content = trim((string) preg_replace('/^```[a-zA-Z0-9]*\s*|\s*```$/', '', $content));
        }
        // These are single-value fields. A chatty model (e.g. Claude Haiku) may wrap the
        // value in a markdown heading and follow it with commentary (character counts,
        // checklists) or lead with a "Here is…:" preamble. Pick the first line that looks
        // like the actual value: strip leading markdown markers + bold, skip empty/marker
        // lines and label/preamble lines (those ending in a colon).
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: array($content);
        $value = $content;
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $line = trim((string) preg_replace('/^\s*(#{1,6}|>|[-*+]|\d+\.)\s+/', '', $line));
            $line = trim(str_replace(array('**', '__'), '', $line));
            if ($line === '' || preg_match('/:\s*$/', $line)) {
                continue; // pure marker line, or a label/preamble line ("Meta title:")
            }
            $value = $line;
            break;
        }
        // Strip a leading "Label:" / "Label -" prefix on the value itself.
        $value = trim((string) preg_replace('/^(meta\s+title|title|meta\s+description|description|slug|keywords?|primary\s+keyword)\s*[:\-\x{2013}]\s+/iu', '', trim($value)));
        // Strip surrounding matched quotes.
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last  = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = trim(substr($value, 1, $len - 2));
            }
        }
        return trim($value);
    }

    /**
     * Build the substitution variable map for a post (+ optional brand for
     * business context; falls back to site info).
     *
     * @param int      $post_id  Post id.
     * @param int|null $brand_id Optional PC brand for {{business.*}}.
     * @return array<string, string>
     */
    public function build_field_vars(int $post_id, ?int $brand_id = null): array
    {
        $post = get_post($post_id);
        $home = home_url('/');
        $host = (string) wp_parse_url($home, PHP_URL_HOST);

        $business_name = (string) get_bloginfo('name');
        $business_tag  = (string) get_bloginfo('description');
        // GBP business context for the brand (resolved = snapshot + overrides).
        $gbp = array();
        if ($brand_id && $brand_id > 0) {
            global $wpdb;
            $brands = PCM_Schema::table('brands');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $brand = $wpdb->get_row($wpdb->prepare("SELECT name FROM {$brands} WHERE id = %d", $brand_id));
            if ($brand && !empty($brand->name)) {
                $business_name = (string) $brand->name;
            }
            if (class_exists('PCM_SEO_GBP')) {
                $gbp = PCM_SEO_GBP::get_for_brand($brand_id)['resolved'];
                if (!empty($gbp['name'])) {
                    $business_name = (string) $gbp['name'];
                }
            }
        }

        $locale = get_locale();
        return array(
            'title'                     => $post ? $post->post_title : '',
            'primary_keyword'           => self::seo_get($post_id, 'keyword'),
            'supporting_keyword'        => (string) get_post_meta($post_id, 'pcm_seo_supporting_keyword', true),
            'meta_keywords'             => self::seo_get($post_id, 'meta_keywords'),
            'meta_title'                => self::seo_get($post_id, 'title'),
            'meta_description'          => self::seo_get($post_id, 'description'),
            'post_type'                 => $post ? $post->post_type : '',
            'site.lang'                 => $locale ? substr($locale, 0, 2) : 'en',
            'website.url'               => $home,
            'today'                     => gmdate('Y-m-d'),
            'business.name'             => $business_name,
            'business.tagline'          => $business_tag,
            'business.website'          => !empty($gbp['website']) ? (string) $gbp['website'] : $home,
            'business.website|hostname' => $host,
            'business.address'          => (string) ($gbp['address'] ?? ''),
            'business.phone'            => (string) ($gbp['phone'] ?? ''),
            'business.category'         => (string) ($gbp['category'] ?? ''),
            'business.hours'            => (string) ($gbp['hours'] ?? ''),
            'business.rating'           => isset($gbp['rating']) ? (string) $gbp['rating'] : '',
            'business.lat'              => isset($gbp['lat']) ? (string) $gbp['lat'] : '',
            'business.lng'              => isset($gbp['lng']) ? (string) $gbp['lng'] : '',
            'business.types'            => !empty($gbp['types']) ? implode(', ', (array) $gbp['types']) : '',
        );
    }

    /**
     * Generate (or optimize) an SEO field value with AI. Returns the suggested
     * value WITHOUT saving — the caller stages it for accept/reject. Reuses
     * PC's PCM_LLM provider routing.
     *
     * @param int      $post_id  Post id.
     * @param string   $field    Cell field (must be in field_use_map()).
     * @param int|null $brand_id Optional brand for business context.
     * @param string|null $model Optional model override.
     * @param int|null $user_id  PCM user (for prompt overrides + provider key).
     * @param string|null $provider Optional provider override (paired with $model).
     * @return array|WP_Error { field, value }.
     */
    public function generate_field(int $post_id, string $field, ?int $brand_id = null, ?string $model = null, ?int $user_id = null, ?string $provider = null, ?int $template_id = null)
    {
        $use_map = self::field_use_map();
        if (!isset($use_map[$field])) {
            return new WP_Error('pcm_seo_not_generatable', __('This field cannot be AI-generated.', 'power-creatives'), array('status' => 400));
        }
        $use     = $use_map[$field];
        $prompts = self::field_prompts();
        if (!isset($prompts[$use])) {
            return new WP_Error('pcm_seo_no_prompt', __('No prompt configured for this field.', 'power-creatives'), array('status' => 500));
        }

        $vars    = $this->build_field_vars($post_id, $brand_id);
        // Read the current value (for optimize mode) from the right storage key.
        $get_key_map = array(
            'metaTitle'       => 'title',
            'metaDescription' => 'description',
            'metaKeywords'    => 'meta_keywords',
            'primaryKeyword'  => 'keyword',
        );
        $current = in_array($field, array('title', 'slug'), true) ? '' : self::seo_get($post_id, $get_key_map[$field] ?? 'meta_keywords');
        // 'title' and 'slug' are native post fields, not SEO meta — read them directly.
        if ($field === 'title' || $field === 'slug') {
            $p = get_post($post_id);
            $current = $p ? ($field === 'title' ? $p->post_title : $p->post_name) : '';
        }
        $vars['current_value'] = $current;

        // Optimize an existing value when present and an optimize prompt exists.
        $mode    = (!empty($current) && !empty($prompts[$use]['optimize'])) ? 'optimize' : 'generate';
        $default = $prompts[$use][$mode];
        // Honor the user's Settings → Prompts → SEO override (falls back to default).
        $tpl     = self::resolve_prompt($use . '_' . $mode, $default, $user_id, $template_id);
        $prompt  = self::substitute_vars($tpl, $vars);
        $max     = (int) ($prompts[$use]['max'] ?? 200);

        if (!class_exists('PCM_LLM')) {
            return new WP_Error('pcm_seo_no_llm', __('AI provider is unavailable.', 'power-creatives'), array('status' => 500));
        }

        try {
            $opts = array('max_tokens' => $max);
            if (!empty($model)) {
                $opts['model'] = $model;
            }
            // Data-driven provider routing — when the caller picks a model it also
            // sends its provider, so we never fall back to detect_provider().
            if (!empty($provider)) {
                $opts['provider'] = $provider;
            }
            $result = PCM_LLM::invoke(array(array('role' => 'user', 'content' => $prompt)), $opts);
            $value  = self::sanitize_ai_output((string) ($result['content'] ?? ''));
            // The slug field must be a valid URL slug — slugify whatever the model
            // returned (handles chatty output / spaces / casing reliably).
            if ($field === 'slug') {
                $value = sanitize_title($value);
            }
            if ($value === '') {
                return new WP_Error('pcm_seo_empty', __('The model returned no text — try again.', 'power-creatives'), array('status' => 502));
            }
            return array('field' => $field, 'value' => $value);
        } catch (\Throwable $e) {
            return new WP_Error('pcm_seo_generate_failed', $e->getMessage(), array('status' => 502));
        }
    }

    /**
     * Generate a SITE-wide field (robots.txt / LocalBusiness JSON-LD) from its
     * editable prompt (Settings → Templates, module=seo). Brand supplies the
     * business context for the schema; robots only needs the site URL. Returns
     * the generated text WITHOUT saving (the Site tab previews + saves).
     *
     * @param string      $field    'robots' | 'schema'.
     * @param int|null    $brand_id Optional brand for {{business.*}} context.
     * @param string|null $model    Optional model override.
     * @param int|null    $user_id  PCM user (for prompt-template override).
     * @param string|null $provider Optional provider override (paired with $model).
     * @return array|WP_Error { field, value }.
     */
    public function generate_site_field(string $field, ?int $brand_id = null, ?string $model = null, ?int $user_id = null, ?string $provider = null, array $var_overrides = array())
    {
        $use_map = array(
            'robots'       => 'robots',
            'schema'       => 'site_schema',
            'site_title'   => 'site_title',
            'site_tagline' => 'site_tagline',
        );
        if (!isset($use_map[$field])) {
            return new WP_Error('pcm_seo_not_generatable', __('This site field cannot be generated.', 'power-creatives'), array('status' => 400));
        }
        $use     = $use_map[$field];
        $prompts = self::field_prompts();
        if (empty($prompts[$use]['generate'])) {
            return new WP_Error('pcm_seo_no_prompt', __('No prompt configured for this field.', 'power-creatives'), array('status' => 500));
        }

        // Site-level vars (no post): business.* (from brand), website.url, site.lang.
        // $var_overrides lets a CONNECTED site inject its own url/name so {{website.url}}
        // (robots Sitemap, schema url) resolves to the remote site, not the hub.
        $vars    = array_merge($this->build_field_vars(0, $brand_id), $var_overrides);
        $default = $prompts[$use]['generate'];
        $tpl     = self::resolve_prompt($use . '_generate', $default, $user_id);
        $prompt  = self::substitute_vars($tpl, $vars);
        $max     = (int) ($prompts[$use]['max'] ?? 600);

        if (!class_exists('PCM_LLM')) {
            return new WP_Error('pcm_seo_no_llm', __('AI provider is unavailable.', 'power-creatives'), array('status' => 500));
        }

        try {
            $opts = array('max_tokens' => $max);
            if (!empty($model)) {
                $opts['model'] = $model;
            }
            if (!empty($provider)) {
                $opts['provider'] = $provider;
            }
            $result = PCM_LLM::invoke(array(array('role' => 'user', 'content' => $prompt)), $opts);
            $value = trim((string) ($result['content'] ?? ''));
            if ($field === 'site_title' || $field === 'site_tagline') {
                // Single-line value fields — strip markdown/commentary a chatty model adds.
                $value = self::sanitize_ai_output($value);
            } elseif (strpos($value, '```') === 0) {
                // Multi-line output (robots/schema) — strip only a wrapping ```code fence```
                // (do NOT use sanitize_ai_output, which collapses to a single line).
                $value = trim((string) preg_replace('/^```[a-zA-Z0-9]*\s*|\s*```$/', '', $value));
            }
            if ($value === '') {
                return new WP_Error('pcm_seo_empty', __('The model returned no text — try again.', 'power-creatives'), array('status' => 502));
            }
            return array('field' => $field, 'value' => $value);
        } catch (\Throwable $e) {
            return new WP_Error('pcm_seo_generate_failed', $e->getMessage(), array('status' => 502));
        }
    }

    /**
     * AI-optimize a post's full body (SEO + AEO). Returns the suggested HTML
     * WITHOUT saving (caller previews + accepts). Reuses PCM_LLM.
     *
     * @return array|WP_Error { body }.
     */
    public function optimize_body(int $post_id, ?int $brand_id = null, ?string $model = null, ?int $user_id = null)
    {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('pcm_seo_no_post', __('Content not found.', 'power-creatives'), array('status' => 404));
        }
        $prompts = self::field_prompts();
        if (empty($prompts['content']['optimize'])) {
            return new WP_Error('pcm_seo_no_prompt', __('No content prompt configured.', 'power-creatives'), array('status' => 500));
        }
        $vars = $this->build_field_vars($post_id, $brand_id);
        $vars['current_value'] = $post->post_content;
        // Honor the user's Settings → Prompts → SEO override (falls back to default).
        $tpl    = self::resolve_prompt('content_optimize', $prompts['content']['optimize'], $user_id);
        $prompt = self::substitute_vars($tpl, $vars);

        if (!class_exists('PCM_LLM')) {
            return new WP_Error('pcm_seo_no_llm', __('AI provider is unavailable.', 'power-creatives'), array('status' => 500));
        }
        try {
            $opts = array('max_tokens' => (int) ($prompts['content']['max'] ?? 4096));
            if (!empty($model)) {
                $opts['model'] = $model;
            }
            $result = PCM_LLM::invoke(array(array('role' => 'user', 'content' => $prompt)), $opts);
            $body   = trim((string) ($result['content'] ?? ''));
            // Strip accidental code fences.
            $body   = preg_replace('/^```[a-z]*\n?|\n?```$/i', '', $body);
            if ($body === '') {
                return new WP_Error('pcm_seo_empty', __('The model returned no content — try again.', 'power-creatives'), array('status' => 502));
            }
            return array('body' => $body);
        } catch (\Throwable $e) {
            return new WP_Error('pcm_seo_optimize_failed', $e->getMessage(), array('status' => 502));
        }
    }
}
