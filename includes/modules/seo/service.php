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

    /** Every H1–H6 on a connected post — builder-aware via the connector's /scan-headings
     *  (v2.1.7+), falling back to a post-body-only parse of content.raw on older connectors. */
    public static function remote_get_headings(object $site, int $post_id, string $type): array
    {
        self::ensure_sites_service();
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
        $headings = self::remote_get_headings($site, $post_id, $type);
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
        $result   = self::remote_update_heading_apply($site, $post_id, $type, $index, $text, $level, $headings, $via);
        // Re-key ONLY on a true SOURCE write. An override-layer edit leaves the raw
        // HTML untouched (the override rewrites at render, AFTER rules run), so the
        // section rule must keep matching the OLD heading text to keep serving.
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
    private static function remote_update_heading_apply(object $site, int $post_id, string $type, int $index, ?string $text, ?int $level, array $headings, string &$via = '')
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
        self::ensure_sites_service();
        $res = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/rules');
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || empty($res['body']['supported'])) {
            return 0;
        }
        return max(1, (int) ($res['body']['schemaVersion'] ?? 1));
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
        foreach ($rules as $r) {
            if (in_array((string) ($r['target'] ?? ''), array('section', 'sectionInsert'), true)) {
                $needs_v2 = true;
                break;
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
        if ($needs_v2 && $accepts < 2) {
            return new WP_Error(
                'pcm_seo_connector_no_sections',
                __('This site’s connector doesn’t support section rules yet (needs v2.8.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        $res = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/rules', array(), array(
            'schemaVersion' => $needs_v2 ? 2 : 1,
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
                'section'     => in_array((string) $r['target'], array('section', 'sectionInsert'), true) ? $ctx : null,
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
            if (in_array($target, array('section', 'sectionInsert'), true)) {
                // v2: anchorContext IS the section identity {level, fingerprint|position}.
                $out['section'] = array(
                    'level' => (int) ($ctx['level'] ?? 0),
                );
                if ($target === 'section') {
                    $out['section']['fingerprint'] = (string) ($ctx['fingerprint'] ?? '');
                } else {
                    $out['section']['position'] = (string) ($ctx['position'] ?? 'after');
                }
                $out['anchor'] = null;
            } else {
                $out['anchor'] = $ctx;
            }
            return $out;
        }, $rows);
    }

    /**
     * Save a paragraph rule for a connected post and push the post's complete
     * rule set to its connector — ATOMICALLY from the caller's perspective:
     * capability is checked BEFORE any write, and a failed push ROLLS BACK the
     * DB change, so hub state and connector state never diverge silently.
     *
     * UPSERT semantics (by siteId+postId+matchText+occurrence): editing the
     * same paragraph again updates its one rule — rules never pile up.
     * CLEAN REVERT: a replacement whose visible text normalizes back to the
     * original deletes the rule entirely (mirrors the shipped override
     * behavior — no dead rules).
     *
     * @param array{text:string,occurrence:int,replacement:string,anchor?:array} $input
     * @return array{reverted?:bool,rule?:array,stored:int}|\WP_Error
     */
    public function save_paragraph_rule(int $user_id, object $site, int $post_id, array $input)
    {
        global $wpdb;
        $table       = PCM_Schema::table('seo_dynamic_rules');
        $site_id     = (int) $site->id;
        $match_text  = PCM_Text_Matcher::normalize((string) ($input['text'] ?? ''));
        $occurrence  = max(0, (int) ($input['occurrence'] ?? 0));
        $replacement = wp_kses_post((string) ($input['replacement'] ?? ''));
        $anchor      = (isset($input['anchor']) && is_array($input['anchor'])) ? $input['anchor'] : null;
        if ($match_text === '') {
            return new WP_Error('pcm_seo_rule_no_match', __('The paragraph has no matchable text.', 'power-creatives'), array('status' => 400));
        }
        if (trim(PCM_Text_Matcher::visible_text($replacement)) === '') {
            return new WP_Error('pcm_seo_rule_empty', __('The replacement text is empty.', 'power-creatives'), array('status' => 400));
        }
        // Capability BEFORE any write — an old connector fails honestly, nothing half-done.
        if (!self::connector_supports_rules($site)) {
            return new WP_Error(
                'pcm_seo_connector_no_rules',
                __('This site’s connector doesn’t support dynamic rules yet (needs v2.7.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }

        // The paragraph's ONE existing rule, if any (upsert identity).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $prev = $wpdb->get_row($wpdb->prepare(
            "SELECT id, replacement, active FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'paragraph' AND matchText = %s AND occurrence = %d",
            $user_id,
            $site_id,
            $post_id,
            $match_text,
            $occurrence
        ), ARRAY_A);

        // CLEAN REVERT: replacement normalizes back to the original → delete the rule.
        $reverted = PCM_Text_Matcher::normalize(PCM_Text_Matcher::visible_text($replacement)) === $match_text;
        if ($reverted) {
            if ($prev) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete($table, array('id' => (int) $prev['id']), array('%d'));
            }
        } elseif ($prev) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $table,
                array('replacement' => $replacement, 'anchorContext' => $anchor ? wp_json_encode($anchor) : null, 'active' => 1),
                array('id' => (int) $prev['id']),
                array('%s', '%s', '%d'),
                array('%d')
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert($table, array(
                'userId'        => $user_id,
                'siteId'        => $site_id,
                'postId'        => $post_id,
                'target'        => 'paragraph',
                'matchText'     => $match_text,
                'occurrence'    => $occurrence,
                'replacement'   => $replacement,
                'anchorContext' => $anchor ? wp_json_encode($anchor) : null,
                'active'        => 1,
            ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d'));
        }

        // Push the post's COMPLETE current set (schema v1 replaces the set).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, target, matchText, occurrence, replacement, anchorContext, active FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d",
            $user_id,
            $site_id,
            $post_id
        ), ARRAY_A);
        $push = self::push_rules($site, $post_id, self::rules_to_schema((array) $rows));
        if ($push instanceof WP_Error) {
            // ROLLBACK — hub DB must mirror what the connector actually serves.
            if ($reverted && $prev) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert($table, array(
                    'userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id,
                    'target' => 'paragraph', 'matchText' => $match_text, 'occurrence' => $occurrence,
                    'replacement' => (string) $prev['replacement'],
                    'anchorContext' => $anchor ? wp_json_encode($anchor) : null,
                    'active' => (int) $prev['active'],
                ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d'));
            } elseif (!$reverted && $prev) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($table, array('replacement' => (string) $prev['replacement'], 'active' => (int) $prev['active']), array('id' => (int) $prev['id']), array('%s', '%d'), array('%d'));
            } elseif (!$reverted && !$prev) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete($table, array(
                    'userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id,
                    'target' => 'paragraph', 'matchText' => $match_text, 'occurrence' => $occurrence,
                ), array('%d', '%d', '%d', '%s', '%s', '%d'));
            }
            return $push;
        }
        $out = array('stored' => (int) ($push['stored'] ?? 0));
        if ($reverted) {
            $out['reverted'] = true;
        } else {
            $out['rule'] = array('matchText' => $match_text, 'occurrence' => $occurrence, 'replacement' => $replacement, 'active' => true);
        }
        return $out;
    }

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
     * save_paragraph_rule: capability BEFORE any write, push-fail ROLLS BACK.
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
            // ABSORB: covered paragraph rules die with this push (their served text
            // was folded into the section's initial state by the caller/UI first).
            foreach ($paragraphs as $p) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete($table, array(
                    'userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id,
                    'target' => 'paragraph', 'matchText' => $p['text'], 'occurrence' => $p['occurrence'],
                ), array('%d', '%d', '%d', '%s', '%s', '%d'));
            }
        }

        $push = self::push_current_rules_or_rollback($user_id, $site, $post_id, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        $out = array('stored' => (int) ($push['stored'] ?? 0));
        if ($reverted) {
            $out['reverted'] = true;
        } else {
            $out['rule'] = array('matchText' => $match_text, 'occurrence' => $occurrence, 'level' => $level, 'fingerprint' => $fingerprint, 'active' => true);
        }
        return $out;
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

    /** AI-optimize a connected post's paragraph text (NOT saved — staged). Returns { value }. */
    public static function remote_optimize_paragraph(object $site, int $post_id, string $type, string $text, ?string $model = null, ?int $user_id = null, ?string $provider = null, ?int $template_id = null)
    {
        self::ensure_sites_service();
        $route = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
        $res   = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('_fields' => 'id,title,slug,link,author,meta'));
        $row   = (!is_wp_error($res) && is_array($res['body'] ?? null)) ? self::remote_row($res['body'], $type, $site) : array();
        $vars  = self::remote_field_vars($site, $row);
        $vars['current_value'] = $text;
        $mode  = ($text !== '') ? 'optimize' : 'generate';
        $max   = (int) (self::field_prompts()['paragraph']['max'] ?? 400);
        $val   = self::run_prompt_section('paragraph', $mode, $vars, $max, $model, $user_id, $provider, $template_id, false);
        return ($val instanceof WP_Error) ? $val : array('value' => $val);
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
