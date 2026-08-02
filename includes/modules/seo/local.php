<?php
/**
 * SEO local content editing — the hub's OWN posts & pages.
 *
 * Extracted VERBATIM from PCM_SEO_Service (2026-07-29 decomposition, phase 3).
 *
 * Owns the cross-plugin SEO meta registry (Yoast / Rank Math / SEOPress +
 * native `pcm_seo_*` backup keys), the content-row list, link scanning and
 * rewriting, heading + content-node parsing/editing, and the inline cell save.
 * Everything here operates on THIS WordPress install — the connected-site
 * equivalents live on PCM_SEO_Service (remote_*) and speak over the connector.
 *
 * Depends outward only on PCM_SEO_AI (prompt resolution) and PCM_SEO_Schema;
 * the VALID_TYPES / VALID_STATUSES / PER_TYPE constants deliberately stay on
 * PCM_SEO_Service, which is where controller.php already reads them from.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_SEO_Local
{
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
    public static function list_content(array $types): array
    {
        $types = array_values(array_intersect($types, PCM_SEO_Service::VALID_TYPES));
        if (empty($types)) {
            $types = PCM_SEO_Service::VALID_TYPES;
        }

        $rows = array();
        foreach ($types as $type) {
            $query = new WP_Query(array(
                'post_type'      => $type,
                'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
                'posts_per_page' => PCM_SEO_Service::PER_TYPE,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            ));
            foreach ($query->posts as $post) {
                $rows[] = self::build_row($post);
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
    public static function build_row(WP_Post $post): array
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
            // The raw id too: save_cell maps `author` → `post_author` and therefore expects an
            // ID, while the display name above is only for reading. Matching the name back to a
            // user would break the moment two users share a display_name.
            'authorId'           => (int) $post->post_author,
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
    public static function scan_links(int $post_id, bool $check_status = true): array
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
    public static function get_post_links(int $post_id): array
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
    public static function nth_link_pos(string $content, array $links, int $index): ?int
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
    public static function update_post_link(int $post_id, int $index, ?string $anchor, ?string $href)
    {
        $links = self::get_post_links($post_id);
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

        self::scan_links($post_id, false); // fast refresh — skip per-link HTTP checks (avoid timeout)
        return self::get_post_links($post_id);
    }

    /** Remove a link from a local post's content (unwrap the <a>, keep its text), save, re-scan. */
    public static function remove_post_link(int $post_id, int $index)
    {
        $links = self::get_post_links($post_id);
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
        self::scan_links($post_id, false); // fast refresh — skip per-link HTTP checks (avoid timeout)
        return self::get_post_links($post_id);
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
    public static function rebuild_heading_html(string $old_html, int $old_level, int $new_level, ?string $new_text): string
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
    public static function get_post_headings(int $post_id): array
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
    public static function get_post_content_nodes(int $post_id): array
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
    public static function update_post_heading(int $post_id, int $index, ?string $text, ?int $level)
    {
        $headings = self::get_post_headings($post_id);
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
            return self::get_post_headings($post_id); // no-op
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

        return self::get_post_headings($post_id);
    }

    /** Shared AI runner for a prompt section (resolve override → substitute → invoke → sanitize).
     *  $single_line: true = pick-the-value-line sanitize (titles/headings/keywords);
     *  false = keep the whole text as ONE flowing block (paragraphs: strip fences,
     *  collapse whitespace, strip matched surrounding quotes — never drop sentences). */
    public static function run_prompt_section(string $section, string $mode, array $vars, int $max, ?string $model, ?int $user_id, ?string $provider, ?int $template_id, bool $single_line = true)
    {
        $prompts = PCM_SEO_AI::field_prompts();
        if (empty($prompts[$section][$mode])) {
            return new WP_Error('pcm_seo_no_prompt', __('No prompt configured for this field.', 'power-creatives'), array('status' => 500));
        }
        $default = (string) $prompts[$section][$mode];
        $tpl     = PCM_SEO_AI::resolve_prompt($section . '_' . $mode, $default, $user_id, $template_id);
        // A user's free-form INSTRUCTION ('topic') must reach the model even when
        // the resolved template predates the {{topic}} placeholder (already-seeded
        // or user-edited templates are never rewritten) — append it honestly.
        if (!empty($vars['topic']) && strpos($tpl, '{{topic}}') === false) {
            $tpl .= "\n\nExtra instruction (follow it): {{topic}}";
        }
        // Same append law for the PAGE TYPE and the linked brand's BUSINESS
        // context (owner order 2026-07-13): they must reach the model even on
        // templates that predate the placeholders.
        if (!empty($vars['page.type']) && strpos($tpl, '{{page.type}}') === false) {
            $tpl .= "\n\nPage type: {{page.type}} — match the content to this intent (a local page targets local searches).";
        }
        if ((!empty($vars['business.phone']) || !empty($vars['business.address'])) && strpos($tpl, '{{business.') === false) {
            $tpl .= "\n\nBusiness context: {{business.name}} — phone {{business.phone}}, address {{business.address}}, category {{business.category}}, hours {{business.hours}}. About: {{business.description}}. Use the real details where relevant; never invent contact data.";
        }
        $prompt  = PCM_SEO_AI::substitute_vars($tpl, $vars);
        if (!class_exists('PCM_LLM')) {
            return new WP_Error('pcm_seo_no_llm', __('AI provider is unavailable.', 'power-creatives'), array('status' => 500));
        }
        try {
            $opts = array('max_tokens' => $max);
            if (!empty($model))    { $opts['model'] = $model; }
            if (!empty($provider)) { $opts['provider'] = $provider; }
            // The caller's user owns the API keys — without this, key lookup
            // silently leaned on the WP session user (absent in cron/CLI).
            if (!empty($user_id))  { $opts['user_id'] = $user_id; }
            $result = PCM_LLM::invoke(array(array('role' => 'user', 'content' => $prompt)), $opts);
            $raw    = (string) ($result['content'] ?? '');
            // What ACTUALLY answered (the API's own report, not the request) —
            // callers surface it so the UI never claims one model and runs another.
            $meta = array(
                'model'    => (string) ($result['model'] ?? ($model ?? '')),
                'provider' => (string) ($result['provider'] ?? ($provider ?? '')),
            );
            if ($single_line) {
                $value = PCM_SEO_AI::sanitize_ai_output($raw);
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
            return array('value' => $value, 'model' => $meta['model'], 'provider' => $meta['provider']);
        } catch (\Throwable $e) {
            return new WP_Error('pcm_seo_generate_failed', $e->getMessage(), array('status' => 502));
        }
    }

    /** AI-optimize a single heading's text (NOT saved). Returns { value }. */
    public static function optimize_heading(int $post_id, string $text, ?int $brand_id = null, ?string $model = null, ?int $user_id = null, ?string $provider = null, ?int $template_id = null)
    {
        $vars = PCM_SEO_AI::build_field_vars($post_id, $brand_id);
        $vars['current_value'] = $text;
        $mode = ($text !== '') ? 'optimize' : 'generate';
        $max  = (int) (PCM_SEO_AI::field_prompts()['heading']['max'] ?? 80);
        // run_prompt_section returns {value, model, provider} — pass it through.
        return self::run_prompt_section('heading', $mode, $vars, $max, $model, $user_id, $provider, $template_id);
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
    public static function duplicate(int $post_id)
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
    public static function save_cell(int $post_id, string $field, $value)
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
                if (!in_array($clean, PCM_SEO_Service::VALID_STATUSES, true)) {
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
}
