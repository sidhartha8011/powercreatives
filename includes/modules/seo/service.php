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
 * DECOMPOSITION NOTE (2026-07-31, phase 5 — REMOTE HEADINGS → PCM_SEO_Remote_Headings):
 * these six were `private static` and are now `public static` ONLY because the
 * extracted heading methods still call back into them —
 *   served_inventory (PAGE INVENTORY), rekey_section_rules (SECTION RULES),
 *   update_owned_heading_unit + update_section_owned_heading (SAVE TRANSACTION),
 *   remote_row + remote_field_vars (Remote-site SEO).
 * Nothing else should treat them as public API.
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
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/business.php';
require_once __DIR__ . '/redirects.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/local.php';
require_once __DIR__ . '/page-state.php';
require_once __DIR__ . '/remote-headings.php';
require_once __DIR__ . '/page-inventory.php';
require_once __DIR__ . '/editing.php';

class PCM_SEO_Service
{
    /** Content types this module operates on. */
    public const VALID_TYPES = ['post', 'page'];

    /**
     * Post types that are never public CONTENT: WordPress internals, plus page-builder
     * template libraries (an Elementor/Divi/Brizy template is not a URL anyone visits).
     * Everything else a site registers publicly — services, doctors, products, portfolio —
     * IS content the SEO table must show.
     */
    public const NON_CONTENT_TYPES = [
        'attachment', 'nav_menu_item', 'revision', 'custom_css', 'customize_changeset',
        'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part',
        'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face',
        'elementor_library', 'e-floating-buttons', 'et_pb_layout', 'brizy_template',
        'fl-builder-template', 'fl-theme-layout', 'ct_template', 'bricks_template',
        'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon',
        'scheduled-action', 'acf-field', 'acf-field-group', 'wpcf7_contact_form',
    ];

    /** Editable WP post statuses (dropdown source + save validation). */
    public const VALID_STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

    /** Max rows fetched per content type. */
    // 100 → 500: the cap was silently DROPPING everything past 100 per type, and on a
    // page-heavy site the dropped rows are precisely the subpages. 500 bounds the
    // local WP_Query; the remote list paginates to the same total (5 × 100).
    public const PER_TYPE = 500;

    /** Remote pagination: how many 100-row pages to fetch per content type. */
    public const PER_TYPE_PAGES = 5;


    // ── Remote-site SEO (connected sites via the connector proxy; Phase 1: read + edit) ──

    /** Ensure the Sites service (remote proxy + credential decrypt) is loaded. */
    public static function ensure_sites_service(): void
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
    public static function remote_row(array $item, string $type, object $site): array
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
            'modified'          => (string) ($item['modified'] ?? ''),
            'authorId'          => (int) ($item['author'] ?? 0),
            'author'            => (string) ($item['_embedded']['author'][0]['name'] ?? ''),
            'permalink'         => (string) ($item['link'] ?? ''),
            'editUrl'           => rtrim((string) $site->url, '/') . '/wp-admin/post.php?post=' . $id . '&action=edit',
            // Featured image. The embed is the free path, but it is NOT reliable: `_embed` is
            // dropped by older cores when `_fields` is used, and security plugins / CDNs strip
            // it outright — leaving the table unable to DISCOVER an image that is really set.
            // So keep the id too; remote_list_content resolves any missing URL from it.
            'featuredImage'     => (string) (
                $item['_embedded']['wp:featuredmedia'][0]['media_details']['sizes']['thumbnail']['source_url']
                    ?? $item['_embedded']['wp:featuredmedia'][0]['source_url']
                    ?? ''
            ),
            'featuredImageId'   => (int) ($item['featured_media'] ?? 0),
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
     * REST post types that hold real, indexable CONTENT on a connected site — keyed
     * slug => rest_base.
     *
     * Owner: "SEO table - not pulling pages in a subfolder or what we call it
     * /sub-subpage". Proven on massagegoteborg.nu: /services/lymphatic-drainage/ (its
     * best-performing URL in Search Console) is not a page at all — the site registers a
     * `services` custom post type, plus `cmsms_doctor`. A post+page-only list can never
     * show them, and from the outside they look exactly like pages in a subfolder.
     *
     * Discovered from CORE's /wp/v2/types, so it works with no connector at all. System
     * and page-builder template types are excluded — they are never public URLs.
     * Cached per site (12h); post/page are always present, so a failed lookup degrades
     * to exactly the old behaviour rather than an empty table.
     *
     * @return array<string,string> slug => rest_base.
     */
    public static function remote_content_types(object $site): array
    {
        $builtin = array('post' => 'posts', 'page' => 'pages');
        $key     = 'pcm_seo_types_' . (int) ($site->id ?? 0);
        $cached  = get_transient($key);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }
        self::ensure_sites_service();
        // context=edit exposes `viewable`; fall back to the public view context when the
        // app-password user can't use it.
        $res = PCM_Sites_Service::remote_rest($site, 'GET', '/wp/v2/types', array('context' => 'edit'));
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            $res = PCM_Sites_Service::remote_rest($site, 'GET', '/wp/v2/types');
        }
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return $builtin;   // unreadable → behave exactly as before, never an empty table
        }
        $types = $builtin;
        foreach ($res['body'] as $slug => $def) {
            $slug = sanitize_key((string) $slug);
            if ($slug === '' || isset($types[$slug]) || !is_array($def)) {
                continue;
            }
            if (in_array($slug, self::NON_CONTENT_TYPES, true)) {
                continue;
            }
            // When the site tells us whether a type is publicly viewable, believe it.
            if (array_key_exists('viewable', $def) && !$def['viewable']) {
                continue;
            }
            $base = (string) ($def['rest_base'] ?? '');
            // Empty base = not exposed; a base with a regex placeholder (wp_font_face) is
            // a nested route we can neither list nor edit as content.
            if ($base === '' || strpos($base, '(') !== false) {
                continue;
            }
            $types[$slug] = $base;
        }
        set_transient($key, $types, 12 * HOUR_IN_SECONDS);
        return $types;
    }

    /** Forget a site's cached post-type map (call after connecting/updating a site). */
    public static function flush_remote_content_types(int $site_id): void
    {
        delete_transient('pcm_seo_types_' . $site_id);
    }

    /**
     * REST route for a content type on a connected site — the ONE place a type becomes a
     * URL. Unknown types fall back to the built-in post/page mapping, so a stale row can
     * never produce a nonsense route.
     *
     * @param int $post_id Append this id when non-zero (single-item route).
     */
    public static function remote_route(object $site, string $type, int $post_id = 0): string
    {
        $types = self::remote_content_types($site);
        $base  = $types[$type] ?? ($type === 'page' ? 'pages' : 'posts');
        return '/wp/v2/' . $base . ($post_id > 0 ? '/' . $post_id : '');
    }

    /**
     * Fill in featuredImage URLs for rows that carry a featured-media ID but no URL (the
     * embed was missing or unusable). Batched by id via /wp/v2/media, so the whole table
     * costs at most a couple of extra requests.
     *
     * Best-effort by contract: a failed/blocked media call leaves those cells empty exactly
     * as before — never an error, never a fabricated URL. Rows are passed by reference.
     *
     * @param object  $site
     * @param array[] $rows  Rows from remote_row(), modified in place.
     * @param int     $cap   Max ids to resolve per list (bounds a huge table's first load).
     * @return void
     */
    private static function fill_remote_featured_images(object $site, array &$rows, int $cap = 200): void
    {
        $need = array();               // media id => [row indexes]
        foreach ($rows as $i => $r) {
            $mid = (int) ($r['featuredImageId'] ?? 0);
            if ($mid > 0 && (string) ($r['featuredImage'] ?? '') === '') {
                $need[$mid][] = $i;
            }
        }
        if (empty($need)) {
            return;                    // every image already arrived via the embed
        }
        $ids = array_slice(array_keys($need), 0, $cap);
        foreach (array_chunk($ids, 100) as $chunk) {
            $res = PCM_Sites_Service::remote_rest($site, 'GET', '/wp/v2/media', array(
                'include'  => implode(',', $chunk),
                'per_page' => count($chunk),
                '_fields'  => 'id,source_url,media_details',
            ));
            if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
                continue;              // blocked/erroring media route → leave those cells empty
            }
            foreach ($res['body'] as $media) {
                if (!is_array($media)) {
                    continue;
                }
                $mid = (int) ($media['id'] ?? 0);
                $url = (string) (
                    $media['media_details']['sizes']['thumbnail']['source_url']
                        ?? $media['source_url']
                        ?? ''
                );
                if ($mid === 0 || $url === '' || empty($need[$mid])) {
                    continue;
                }
                foreach ($need[$mid] as $i) {
                    $rows[$i]['featuredImage'] = $url;
                }
            }
        }
    }

    /**
     * List a connected site's posts + pages as SeoRows, via the connector proxy.
     * Best-effort: skips a post type on a proxy error rather than failing the whole list.
     */
    public static function remote_list_content(object $site): array
    {
        self::ensure_sites_service();
        $rows   = array();
        $fields = 'id,title,slug,status,date,modified,link,author,featured_media,excerpt,meta,_embedded.author,_embedded.wp:featuredmedia';
        // EVERY public content type, not just post+page — a site's `services` /
        // `cmsms_doctor` items are real URLs and were invisible here (see
        // remote_content_types). Discovery degrades to post+page when unreadable.
        $routes = array();
        foreach (self::remote_content_types($site) as $type => $base) {
            $routes[$type] = '/wp/v2/' . $base;
        }
        foreach ($routes as $type => $route) {
            // PAGINATED: one 100-row request per page, up to PER_TYPE_PAGES. The old
            // single request silently DROPPED everything past 100 — on a site with
            // many pages the missing rows are precisely the subpages ("the table…
            // needs to pull in and show the subpages"). WordPress answers a page
            // past the end with 400 rest_post_invalid_page_number — that is the
            // normal end-of-list signal here, not a failure.
            for ($page = 1; $page <= self::PER_TYPE_PAGES; $page++) {
                $res = PCM_Sites_Service::remote_rest($site, 'GET', $route, array(
                    'per_page' => 100,
                    'page'     => $page,
                    'status'   => 'publish,future,draft,pending,private',
                    '_embed'   => '1',
                    '_fields'  => $fields,
                    'orderby'  => 'modified',
                ));
                if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
                    break;   // error on page 1 = type unavailable; later = end of list
                }
                $got = 0;
                foreach ($res['body'] as $item) {
                    if (is_array($item)) {
                        $rows[] = self::remote_row($item, $type, $site);
                        $got++;
                    }
                    // (effective-meta fallback applied once, after all types — below)
                }
                if ($got < 100) {
                    break;   // short page = last page; no need to provoke the 400
                }
            }
        }
        // Featured images the embed didn't deliver: resolve them from the ids we DO have.
        // Owner: "when a page has a featured image, the table does not show it… it can only
        // Set an image, not discover if there is one." A post carries featured_media (an id)
        // unconditionally, while `_embedded` is optional and routinely absent (older cores
        // drop it when `_fields` is set; security plugins and CDNs strip it). One batched
        // /wp/v2/media call turns those ids into thumbnails, so discovery no longer depends
        // on the embed arriving.
        self::fill_remote_featured_images($site, $rows);

        // Empty meta cells fall back to the tags each page ACTUALLY renders,
        // served batch-wise by the connector's /head-tags route (loopback fetch
        // + head-only parse, cached on the site). SEO plugins generate most
        // titles/descriptions from templates and store NOTHING per post — the
        // reported massagegoteborg.nu columns were empty while every live page
        // had perfect tags. Old connector without the route → skip silently.
        // The connector answers at most 20 ids per request. Asking for "the first 20
        // that need meta" EVERY time meant rows 21+ were never requested at all — on
        // brizy.profitmedia.pro (63 rows) most of the table could never fill, no matter
        // how often it was reloaded ("the meta tags is not shown in the table"). So:
        // remember what we learn per (site, post, modified) and ask only for ids we do
        // NOT know yet. Each load fills every known row for free and learns up to 20
        // more, so successive loads converge and a warm table costs zero requests.
        $need = array();
        foreach ($rows as $i => $r) {
            if ((($r['metaTitle'] ?? '') === '' || ($r['metaDescription'] ?? '') === '') && !empty($r['id'])) {
                $need[(int) $r['id']] = $i;
            }
        }
        if (!empty($need)) {
            $tag_key = static function (int $post_id) use ($site, $rows, $need) {
                $row = $rows[$need[$post_id]] ?? array();
                return 'pcm_seo_rtags_' . (int) ($site->id ?? 0) . '_' . $post_id . '_'
                    . md5((string) ($row['modified'] ?? $row['date'] ?? ''));
            };
            // 1. Free pass: everything already learned.
            $tags   = array();
            $unseen = array();
            foreach (array_keys($need) as $pid) {
                $hit = get_transient($tag_key($pid));
                if (is_array($hit)) {
                    $tags[(string) $pid] = $hit;
                } else {
                    $unseen[] = $pid;
                }
            }
            // 2. Learn up to one connector batch of the ids we have never resolved.
            if (!empty($unseen)) {
                $res = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/head-tags', array(
                    'post_ids' => implode(',', array_slice($unseen, 0, 20)),
                ));
                $served = !is_wp_error($res) && (int) ($res['status'] ?? 0) < 300;
                // A 404 here means the connector predates /head-tags — it is ACTIVE and
                // healthy, just old. Record that so the SEO banner can offer the
                // one-click self-update instead of leaving the columns mysteriously
                // slow to fill (proven on brizy.profitmedia.pro, whose connector has no
                // such route). 5xx/transport errors are NOT a capability verdict.
                if ($served || (int) ($res['status'] ?? 0) === 404) {
                    PCM_Sites_Service::mark_connector_route((int) ($site->id ?? 0), 'head-tags', $served);
                }
                $fresh = ($served && is_array($res['body']['tags'] ?? null))
                    ? $res['body']['tags'] : array();
                foreach ($fresh as $pid => $t) {
                    if (!is_array($t)) {
                        continue;
                    }
                    $tags[(string) $pid] = $t;
                    // Cache the ANSWER, including an empty one: a page that genuinely
                    // renders no meta must not be re-fetched on every load. Short TTL
                    // for empties so a fixed page recovers on its own.
                    $has = (!empty($t['title']) || !empty($t['description']));
                    set_transient($tag_key((int) $pid), $t, $has ? WEEK_IN_SECONDS : HOUR_IN_SECONDS);
                }
            }
            if (!empty($tags)) {
                foreach ($rows as $i => $r) {
                    $t = $tags[(string) ($r['id'] ?? '')] ?? null;
                    if (!is_array($t)) {
                        continue;
                    }
                    if (($r['metaTitle'] ?? '') === '' && !empty($t['title'])) {
                        $rows[$i]['metaTitle'] = (string) $t['title'];
                    }
                    if (($r['metaDescription'] ?? '') === '' && !empty($t['description'])) {
                        $rows[$i]['metaDescription'] = (string) $t['description'];
                    }
                }
            }
            // CONNECTOR-LESS FALLBACK. massagegoteborg.nu turned out to run no
            // connector AT ALL (its REST namespaces have no pcm-conn/v1 — even
            // page-state 404s), so the route above can never answer there. The
            // published permalinks are public pages, so the HUB fetches them
            // directly: publish-only, small cap, cached on the row's modified
            // time, and the parser's challenge guard refuses a Cloudflare
            // interstitial rather than caching "Just a moment..." as a title.
            // A bot-walled site stays visibly empty — honest, not wrong.
            $rows = PCM_SEO_Local::fill_effective_meta(
                $rows,
                static function (array $row) {
                    if (($row['status'] ?? '') !== 'publish' || empty($row['permalink'])) {
                        return null;
                    }
                    $key    = 'pcm_seo_rhead_' . md5((string) $row['permalink'] . '|' . (string) ($row['modified'] ?? $row['date'] ?? ''));
                    $cached = get_transient($key);
                    if (is_string($cached)) {
                        return $cached === '' ? null : $cached;
                    }
                    $resp = wp_remote_get((string) $row['permalink'], array(
                        'timeout'    => 5,
                        'sslverify'  => false,
                        'user-agent' => 'Mozilla/5.0 (compatible; PowerCreatives meta reader)',
                    ));
                    $html = (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) < 300)
                        ? (string) wp_remote_retrieve_body($resp)
                        : '';
                    if ($html !== '' && PCM_SEO_Local::is_challenge_page($html)) {
                        $html = ''; // challenged — treat as unavailable, retry after the short TTL
                    }
                    $end  = stripos($html, '</head>');
                    $html = $end !== false ? substr($html, 0, $end + 7) : substr($html, 0, 200000);
                    set_transient($key, $html, $html === '' ? HOUR_IN_SECONDS : WEEK_IN_SECONDS);
                    return $html === '' ? null : $html;
                },
                // Cap raised 10 → 25: on a site whose connector predates /head-tags this
                // reader is the ONLY way meta ever appears, and 10-per-load made a 63-row
                // table take seven visits. Each page is cached for a week, and the 6s
                // budget still bounds a single load's wall clock.
                25,
                6.0,
                // Free cache-only pass (same transient the fetcher writes), so pages
                // already read don't burn the budget and later rows still get a turn.
                static function (array $row) {
                    if (($row['status'] ?? '') !== 'publish' || empty($row['permalink'])) {
                        return null;
                    }
                    $cached = get_transient('pcm_seo_rhead_' . md5((string) $row['permalink'] . '|' . (string) ($row['modified'] ?? $row['date'] ?? '')));
                    return (is_string($cached) && $cached !== '') ? $cached : null;
                }
            );
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
        $route   = self::remote_route($site, $type, (int) $post_id);
        $payload = array();
        if ($field === 'title') {
            $payload['title'] = $value;
        } elseif ($field === 'slug') {
            $payload['slug'] = sanitize_title($value);
        } elseif ($field === 'status') {
            $payload['status'] = $value; // native post field (publish/draft/…) — core REST validates
        } elseif ($field === 'author') {
            // Native post field. The id MUST be a user on the REMOTE site — the
            // Author dropdown is fed by remote_authors() for exactly this reason;
            // sending a hub user id here would reassign the post to whoever holds
            // that id on the client's site.
            $payload['author'] = absint($value);
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
        if (!in_array($field, array('title', 'slug', 'status', 'author'), true)) {
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
        // AUTHOR verification — from the POST's own response body, no extra round trip.
        // A cap failure (user can't edit_others_posts) 403s and is surfaced above, but
        // core REST silently IGNORES the author param when the post type doesn't expose
        // author support — 200, author unchanged. The table then looked like the pick
        // simply "didn't select" (owner, knallenstandvard.se). The updated-post body
        // carries the stored author: absent = the field isn't in this type's schema;
        // different = the write was refused. Either way, say so instead of faking success.
        if ($field === 'author' && is_array($res['body'] ?? null)) {
            $stored_author = array_key_exists('author', $res['body']) ? (int) $res['body']['author'] : null;
            if ($stored_author === null || $stored_author !== absint($value)) {
                return new WP_Error(
                    'pcm_seo_author_not_saved',
                    __('The remote site accepted the request but kept the previous author. Usually the connected user lacks the "edit others\' posts" capability on that site (its app-password user needs an Editor/Administrator role), or this content type does not support authors.', 'power-creatives'),
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
     * The authors on a CONNECTED site, for that site's rows in the content table.
     *
     * Local rows use get_users() on the hub. Those two id spaces are unrelated, so a
     * remote row must never be offered hub users — assigning id 5 from the hub would
     * hand the client's post to whoever is id 5 over there. This is the remote half.
     *
     * `who=authors` is core WP REST's own "users who can write posts" filter, which is
     * exactly the local `capability => edit_posts` query it mirrors.
     *
     * @param object $site Connected site row.
     * @return array<int, array{id:int,name:string}>|\WP_Error
     */
    public static function remote_authors(object $site)
    {
        self::ensure_sites_service();
        $res = PCM_Sites_Service::remote_rest($site, 'GET', '/wp/v2/users', array(
            'who'      => 'authors',
            'per_page' => 100,
            '_fields'  => 'id,name',
        ));
        if (is_wp_error($res)) {
            return new WP_Error('pcm_seo_remote_authors', $res->get_error_message(), array('status' => 502));
        }
        if ((int) ($res['status'] ?? 0) >= 300) {
            $msg = (is_array($res['body'] ?? null) && !empty($res['body']['message']))
                ? (string) $res['body']['message']
                : ('HTTP ' . (int) ($res['status'] ?? 0));
            return new WP_Error('pcm_seo_remote_authors', $msg, array('status' => 502));
        }
        $out = array();
        foreach ((array) ($res['body'] ?? array()) as $u) {
            $u = (array) $u;
            if (!empty($u['id'])) {
                $out[] = array('id' => (int) $u['id'], 'name' => (string) ($u['name'] ?? ('#' . (int) $u['id'])));
            }
        }
        return $out;
    }

    /**
     * Create an author ON A CONNECTED SITE and return {id,name}.
     *
     * Mirrors the hub-side create_author()'s hard rules, because this is the same
     * privilege-escalation surface pointed at someone else's install:
     *   - the role is HARDCODED to 'author' and never read from input;
     *   - the password is GENERATED, never accepted;
     *   - a username is derived and de-duplicated remotely by retrying on conflict.
     * Whether the caller may create users at all is enforced by the connection's own
     * credentials — the remote refuses with 403 if the application password's user
     * lacks create_users, and that message is surfaced verbatim.
     *
     * @return array{id:int,name:string}|\WP_Error
     */
    public static function remote_create_author(object $site, string $name, string $email)
    {
        self::ensure_sites_service();
        if ($name === '') {
            return new WP_Error('pcm_seo_author_name', __('A name is required.', 'power-creatives'), array('status' => 400));
        }
        if ($email === '' || !is_email($email)) {
            return new WP_Error('pcm_seo_author_email', __('A valid email address is required.', 'power-creatives'), array('status' => 400));
        }

        // sanitize_user() can empty a non-latin name outright (the hub-side path hit
        // this too), so fall back to the email's local part before giving up.
        $base = sanitize_user(sanitize_title($name), true);
        if ($base === '') {
            $base = sanitize_user((string) strstr($email, '@', true), true);
        }
        if ($base === '') {
            return new WP_Error('pcm_seo_author_login', __('Could not derive a username from that name — try a different one.', 'power-creatives'), array('status' => 400));
        }

        // The remote owns its own username namespace, so collisions can only be found
        // by asking it. Retry with a suffix rather than pre-checking (no list_users needed).
        $last = null;
        for ($i = 0; $i < 5; $i++) {
            $login = $i === 0 ? $base : $base . ($i + 1);
            $res = PCM_Sites_Service::remote_rest($site, 'POST', '/wp/v2/users', array(), array(
                'username' => $login,
                'email'    => $email,
                'name'     => $name,
                'password' => wp_generate_password(24, true, true), // generated, never from input
                'roles'    => array('author'),                     // hardcoded, never from input
            ));
            if (is_wp_error($res)) {
                return new WP_Error('pcm_seo_remote_author', $res->get_error_message(), array('status' => 502));
            }
            $code = (int) ($res['status'] ?? 0);
            $body = is_array($res['body'] ?? null) ? $res['body'] : array();
            if ($code < 300 && !empty($body['id'])) {
                return array('id' => (int) $body['id'], 'name' => (string) ($body['name'] ?? $name));
            }
            $last = !empty($body['message']) ? (string) $body['message'] : ('HTTP ' . $code);
            // Only a USERNAME clash is worth retrying; an existing email is terminal.
            if ((string) ($body['code'] ?? '') !== 'existing_user_login') {
                return new WP_Error('pcm_seo_remote_author', $last, array('status' => $code >= 400 && $code < 500 ? $code : 502));
            }
        }
        return new WP_Error('pcm_seo_remote_author', $last ?: __('Could not find a free username on that site.', 'power-creatives'), array('status' => 409));
    }

    /**
     * Create a draft post/page on a connected site (via the proxy). Returns {id,type}.
     *
     * @return array{id:int,type:string}|\WP_Error
     */
    public static function remote_create_content(object $site, string $type)
    {
        self::ensure_sites_service();
        $route = self::remote_route($site, $type);
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
        $base = self::remote_route($site, $type);

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
        $route = self::remote_route($site, $type, (int) $post_id);
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
        $route = self::remote_route($site, $type, (int) $post_id);
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
    /**
     * HTTP status of a URL (0 on transport error).
     *
     * HEAD first (cheap), but a failing HEAD is NEVER trusted on its own:
     * plenty of ordinary hosts answer 404/403/405 to HEAD while serving the
     * page perfectly over GET — which is exactly how a WORKING link got
     * reported as a 404 in the Links tab (the local scanner already retried on
     * 405; this remote path retried on nothing). The GET retry only fires for
     * links about to be flagged broken, so the extra request is bounded by the
     * broken count, not the link count. Bot-protected hosts (Cloudflare
     * challenge etc.) can still 403 both verbs — a known residual
     * false-positive we surface rather than guess away.
     */
    private static function link_http_status(string $url): int
    {
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return 0;
        }
        $args = array(
            'timeout'     => 8,
            'redirection' => 3,
            'sslverify'   => false,
            'user-agent'  => 'Mozilla/5.0 (compatible; PowerCreatives link check)',
        );
        $resp   = wp_remote_head($url, $args);
        $status = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
        if ($status === 0 || $status >= 400) {
            $resp   = wp_remote_get($url, $args);
            $status = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
        }
        return $status;
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
                self::remote_route($site, $type, (int) $post_id),
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
        $route = self::remote_route($site, $type, (int) $post_id);
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
        $links    = PCM_SEO_Local::scan_link_details($content, $from, (string) $site->url, $check_status);
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
    /**
     * Find a link in a scanned list by IDENTITY (exact html, else to+anchor, else a
     * unique to). The popup's row indexes come from the CONNECTOR's builder-aware
     * scan; this method's list comes from content.raw — two DIFFERENT lists, so an
     * index from one is meaningless in the other. On bokatandlakartid.se that
     * mismatch made "Remove all dead links" fail on every row while the UI toasted
     * success. Null = the link is not in the editable content at all.
     */
    private static function locate_link_index(array $links, array $locate): ?int
    {
        $html   = (string) ($locate['html'] ?? '');
        $to     = (string) ($locate['to'] ?? '');
        $anchor = (string) ($locate['anchor'] ?? '');
        if ($html !== '') {
            foreach ($links as $i => $l) {
                if ((string) ($l['html'] ?? '') === $html) {
                    return (int) $i;
                }
            }
        }
        if ($to !== '') {
            $by_pair = array();
            $by_to   = array();
            foreach ($links as $i => $l) {
                if ((string) ($l['to'] ?? '') !== $to) {
                    continue;
                }
                $by_to[] = (int) $i;
                if ((string) ($l['anchor'] ?? '') === $anchor) {
                    $by_pair[] = (int) $i;
                }
            }
            if (count($by_pair) >= 1) {
                return $by_pair[0];
            }
            if (count($by_to) === 1) {
                return $by_to[0];
            }
        }
        return null;
    }

    private static function remote_rewrite_link_content(object $site, int $post_id, string $type, int $index, callable $build, ?string $rendered_needle = null, ?array $locate = null)
    {
        self::ensure_sites_service();
        $route = self::remote_route($site, $type, (int) $post_id);
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
        $links = PCM_SEO_Local::scan_link_details($raw, $from, (string) $site->url, false); // no HTTP checks
        // IDENTITY beats index: the caller's index numbers the CONNECTOR's scan, which
        // includes builder/meta links this content.raw list doesn't — using it here
        // edited the wrong link or missed. When the caller sends the link's identity,
        // resolve against THIS list; not found = it lives outside the editable content.
        if ($locate !== null) {
            $resolved = self::locate_link_index($links, $locate);
            if ($resolved === null) {
                return new WP_Error(
                    'pcm_seo_link_outside',
                    __('This link isn’t part of the editable post content — it lives in plugin or builder data (e.g. an image/gallery plugin’s stored markup), so it can’t be changed from here. Fix or regenerate it in that plugin on the site.', 'power-creatives'),
                    array('status' => 422)
                );
            }
            $index = $resolved;
        }
        if (!isset($links[$index])) {
            return new WP_Error('pcm_seo_link_not_found', __('Link not found — re-scan and try again.', 'power-creatives'), array('status' => 404));
        }
        $old_html = (string) $links[$index]['html'];
        $new_html = (string) $build($old_html, $links[$index]);
        $pos = PCM_SEO_Local::nth_link_pos($raw, $links, $index);
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

    /**
     * Replace a connected post's link with user-edited raw HTML (the popup's editable HTML
     * column). Body links only — a builder-stored link's HTML is synthesized from meta, so
     * raw-markup replacement can't reach it (the UI keeps those cells read-only; edit their
     * Anchor/To instead). Sanitized by the same validator as the local path.
     */
    public static function remote_update_link_html(object $site, int $post_id, string $type, int $index, string $html)
    {
        $new_html = PCM_SEO_Local::sanitize_link_html($html);
        if ($new_html instanceof WP_Error) {
            return $new_html;
        }
        preg_match('/href=[\'"]([^\'"]+)[\'"]/i', $new_html, $hm);
        $needle = esc_url_raw((string) ($hm[1] ?? ''));
        return self::remote_rewrite_link_content($site, $post_id, $type, $index,
            static fn() => $new_html, $needle !== '' ? $needle : null);
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
    public static function remote_remove_link(object $site, int $post_id, string $type, int $index, ?array $locate = null)
    {
        return self::remote_rewrite_link_content($site, $post_id, $type, $index, static function ($old_html) {
            return preg_replace('/^<a\s[^>]*>(.*)<\/a>$/is', '$1', $old_html);
        }, null, $locate);
    }

    /** Toggle rel="nofollow" on a connected post's link — same rewrite seam as remove. */
    public static function remote_set_link_rel(object $site, int $post_id, string $type, int $index, bool $nofollow, ?array $locate = null)
    {
        return self::remote_rewrite_link_content($site, $post_id, $type, $index, static function ($old_html) use ($nofollow) {
            return PCM_SEO_Local::toggle_nofollow_html((string) $old_html, $nofollow);
        }, null, $locate);
    }

    /**
     * DELETE a connected post's link — the entire element — recording it in the
     * hub's seo_deleted_links ledger first (siteId set), same reversibility
     * contract as the local delete. The ledger context is the raw content
     * before the element, captured by the $build callback via reference.
     */
    public static function remote_delete_link(object $site, int $post_id, string $type, int $index, int $user_id, ?array $locate = null)
    {
        global $wpdb;
        $captured = array('html' => '', 'anchor' => '', 'to' => '');
        $result = self::remote_rewrite_link_content(
            $site,
            $post_id,
            $type,
            $index,
            static function ($old_html, $link = null) use (&$captured) {
                $captured['html']   = (string) $old_html;
                $captured['anchor'] = is_array($link) ? (string) ($link['anchor'] ?? '') : '';
                $captured['to']     = is_array($link) ? (string) ($link['to'] ?? '') : '';
                return ''; // cut the whole element
            },
            null,
            $locate
        );
        if ($result instanceof WP_Error) {
            return $result;
        }
        if ($captured['html'] !== '') {
            // Ledger after the remote write succeeded (the reverse order of the
            // local path, because the remote write is the step that can fail
            // long after validation — a ledger row for an uncut link would
            // offer a bogus restore).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(PCM_Schema::table('seo_deleted_links'), array(
                'userId'   => $user_id,
                'siteId'   => (int) $site->id,
                'postId'   => $post_id,
                'postType' => $type === 'page' ? 'page' : 'post',
                'anchor'   => $captured['anchor'],
                'toUrl'    => $captured['to'],
                'html'     => $captured['html'],
                'context'  => '', // remote raw is read transiently; restore appends
            ), array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s'));
        }
        return $result;
    }

    /**
     * Restore a link deleted on a CONNECTED site: append its stored html to the
     * remote post's raw content (position context is not kept for remote cuts)
     * and drop the ledger row on success.
     */
    public static function remote_restore_deleted_link(object $site, int $ledger_id, int $user_id)
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_deleted_links');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND userId = %d AND siteId = %d",
            $ledger_id, $user_id, (int) $site->id
        ));
        if (!$row) {
            return new WP_Error('pcm_seo_ledger_missing', __('Deleted-link record not found for this site.', 'power-creatives'), array('status' => 404));
        }
        self::ensure_sites_service();
        $type  = (string) $row->postType === 'page' ? 'page' : 'post';
        $route = self::remote_route($site, $type, (int) $row->postId);
        $res   = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('context' => 'edit', '_fields' => 'content'));
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return new WP_Error('pcm_seo_remote_fetch', __('Could not read the remote post.', 'power-creatives'), array('status' => 502));
        }
        $raw = (string) ($res['body']['content']['raw'] ?? '');
        $put = PCM_Sites_Service::remote_rest($site, 'POST', $route, array(), array(
            'content' => $raw . "\n" . (string) $row->html,
        ));
        if (is_wp_error($put) || (int) ($put['status'] ?? 0) >= 300) {
            return new WP_Error('pcm_seo_remote_save', __('The site rejected the restore.', 'power-creatives'), array('status' => 502));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete($table, array('id' => $ledger_id), array('%d'));
        return array('restored' => true, 'postId' => (int) $row->postId);
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
        if (PCM_SEO_Page_Inventory::connector_rules_schema_version($site) < 3) {
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
        $name    = ($site->name ?? '') !== '' ? $site->name : (string) $site->url;
        $default = (string) (PCM_SEO_AI::field_prompts()['site_ai_description']['generate'] ?? '');
        $tpl     = PCM_SEO_AI::resolve_prompt('site_ai_description_generate', $default, $user_id);
        // The site's own page titles below are the language authority; the brand's
        // scraped language is the hint. See PCM_SEO_AI::language_law().
        $tpl    .= PCM_SEO_AI::language_law(array(
            'site.lang' => (string) (PCM_SEO_Business::business_record_for_site((int) ($site->id ?? 0))['fields']['language'] ?? ''),
        ));
        $prompt  = PCM_SEO_AI::substitute_vars($tpl, array(
            'site_name' => $name,
            'key_pages' => implode("\n", $titles),
        ));
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
        $route   = self::remote_route($site, $type, (int) $post_id);
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
        // The business facts assembled below carry the site's own wording, which
        // is the language authority. See PCM_SEO_AI::language_law().
        $prompt = self::llm_info_prompt($ctx, $user_id)
            . PCM_SEO_AI::language_law(array('site.lang' => (string) ($ctx['language'] ?? '')));
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
    private static function llm_info_prompt(array $ctx, ?int $user_id = null): string
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

        // No hidden prompt: this template is a Templates (module=seo) row a
        // user can view/edit — resolve_prompt() returns the shipped default
        // (llm_info_page.generate) verbatim when no override exists. Every
        // conditional clause below becomes an empty-when-absent fragment var,
        // same convention as the strategy module's {{output_format}} etc.
        $default = (string) (PCM_SEO_AI::field_prompts()['llm_info_page']['generate'] ?? '');
        $tpl     = PCM_SEO_AI::resolve_prompt('llm_info_page_generate', $default, $user_id);
        return PCM_SEO_AI::substitute_vars($tpl, array(
            'facts'              => $facts,
            'corpus'             => $corpus,
            'name'               => $name,
            'corpus_note'        => $corpus !== '' ? "- Base the summary on the SITE CONTENT above — reflect the real services, topics and expertise found across the pages; if the FACTS and the content conflict, prefer the content.\n" : '',
            'area_serves_clause' => $area !== '' ? ", and that it serves {$area}" : '',
            'keywords_bullet'    => $keywords !== '' ? "- Naturally weave in the target keywords (no keyword stuffing).\n" : '',
            'years_bullet'       => $years !== '' ? "- Frame the years in business as a proven, trusted track record.\n" : '',
            'area_bullet'        => $area !== '' ? "- Emphasise how local and dedicated the business is to {$area}.\n" : '',
            'strengths_bullet'   => $strengths !== '' ? "- Present the strengths/recommendations positively — but only the facts provided.\n" : '',
            'area_section'       => $area !== '' ? ", \"Areas served\"" : '',
        ));
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

    /** Page types the AI can optimize FOR ('' = general). The list is the
     *  contract between the editor dropdown and the prompt context. */
    public const PAGE_TYPES = array('', 'local', 'blog', 'product', 'service', 'landing');

    /** The per-post page-type store (option map per user+site — tiny data). */
    private static function page_type_option(int $user_id, int $site_id): string
    {
        return 'pcm_seo_page_types_' . $user_id . '_' . $site_id;
    }

    /** @return string the post's page type ('' = general/unset). */
    public static function get_page_type(int $user_id, int $site_id, int $post_id): string
    {
        $map = get_option(self::page_type_option($user_id, $site_id), array());
        return is_array($map) ? (string) ($map[$post_id] ?? '') : '';
    }

    /** Persist a post's page type (whitelisted; '' clears). */
    public static function save_page_type(int $user_id, int $site_id, int $post_id, string $type)
    {
        if (!in_array($type, self::PAGE_TYPES, true)) {
            return new WP_Error('pcm_seo_bad_page_type', __('Unknown page type.', 'power-creatives'), array('status' => 400));
        }
        $key = self::page_type_option($user_id, $site_id);
        $map = get_option($key, array());
        $map = is_array($map) ? $map : array();
        if ($type === '') {
            unset($map[$post_id]);
        } else {
            $map[$post_id] = $type;
        }
        update_option($key, $map, false);
        return array('postId' => $post_id, 'pageType' => $type);
    }

    /** Prompt vars for a remote post. Business context (owner order
     *  2026-07-13): when the site is LINKED to a brand (sites.brandId), the
     *  brand's business details — name, phone, address, category, hours —
     *  fill the {{business.*}} placeholders the prompts already carry (they
     *  were blank for remote pages before, so local-intent content had
     *  nothing real to use). Falls back to the site's own name/url exactly
     *  as before when no brand is linked. `page.type` rides along so the AI
     *  knows WHAT it is optimizing (local / blog / product / …). */
    public static function remote_field_vars(object $site, array $row, ?int $user_id = null, int $post_id = 0): array
    {
        $url    = (string) $site->url;
        $host   = (string) wp_parse_url($url, PHP_URL_HOST);
        $locale = get_locale();
        // THE SITE RESOLVER (gap 616870f): the full ladder — site SEO
        // overrides > unit > brand basics > site basics — reaches EVERY
        // remote generation; unit pinning + the owner's per-site
        // corrections included. Fallbacks preserved: no brand = site
        // name/url exactly as before.
        $gbp  = (array) (PCM_SEO_Business::business_record_for_site((int) ($site->id ?? 0))['fields'] ?? array());
        $name = !empty($gbp['name']) ? (string) $gbp['name'] : (!empty($site->name) ? (string) $site->name : $host);
        // The CLIENT site's language, not the hub's. get_locale() describes the
        // WordPress this plugin runs on — for a remote connected site that is the
        // agency's dashboard, not the site being edited, so a Swedish client site
        // managed from an English hub reported 'en'. The linked brand's language
        // is scraped from that site's own <html lang> (brands/service.php), so it
        // describes the right site. Passed through VERBATIM — it may be a code
        // ('sv') or a name ('Swedish'), and substr() would have mangled the
        // latter into 'Sw'. Hub locale remains the last-resort fallback.
        $brand_lang = trim((string) ($gbp['language'] ?? ''));
        $site_lang  = $brand_lang !== ''
            ? $brand_lang
            : ($locale ? substr($locale, 0, 2) : 'en');
        return array(
            'title'                     => (string) ($row['title'] ?? ''),
            'primary_keyword'           => (string) ($row['primaryKeyword'] ?? ''),
            'supporting_keyword'        => (string) ($row['supportingKeyword'] ?? ''),
            'meta_title'                => (string) ($row['metaTitle'] ?? ''),
            'meta_description'          => (string) ($row['metaDescription'] ?? ''),
            'post_type'                 => (string) ($row['type'] ?? ''),
            'page.type'                 => ($user_id && $post_id) ? self::get_page_type($user_id, (int) $site->id, $post_id) : '',
            'site.lang'                 => $site_lang,
            'website.url'               => $url,
            'today'                     => gmdate('Y-m-d'),
            'business.name'             => $name,
            'business.tagline'          => '',
            'business.website'          => !empty($gbp['website']) ? (string) $gbp['website'] : $url,
            'business.website|hostname' => $host,
            'business.address'          => (string) ($gbp['address'] ?? ''),
            'business.phone'            => (string) ($gbp['phone'] ?? ''),
            'business.category'         => (string) ($gbp['category'] ?? ''),
            'business.hours'            => (string) ($gbp['hours'] ?? ''),
            'business.description'      => (string) ($gbp['description'] ?? ''),
            'business.rating'           => isset($gbp['rating']) ? (string) $gbp['rating'] : '',
            'business.lat'              => isset($gbp['lat']) ? (string) $gbp['lat'] : '',
            'business.lng'              => isset($gbp['lng']) ? (string) $gbp['lng'] : '',
            'business.types'            => !empty($gbp['types']) ? implode(', ', (array) $gbp['types']) : '',
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
        $use_map = PCM_SEO_AI::field_use_map();
        if (!isset($use_map[$field])) {
            return new WP_Error('pcm_seo_not_generatable', __('This field cannot be AI-generated.', 'power-creatives'), array('status' => 400));
        }
        $use     = $use_map[$field];
        $prompts = PCM_SEO_AI::field_prompts();
        if (!isset($prompts[$use])) {
            return new WP_Error('pcm_seo_no_prompt', __('No prompt configured for this field.', 'power-creatives'), array('status' => 500));
        }
        self::ensure_sites_service();

        // Fetch the remote post so the prompt has its title + current SEO meta.
        $route = self::remote_route($site, $type, (int) $post_id);
        $res   = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('_fields' => 'id,title,slug,link,author,meta'));
        if (is_wp_error($res)) {
            return new WP_Error('pcm_seo_remote_fetch', $res->get_error_message(), array('status' => 502));
        }
        if ((int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return new WP_Error('pcm_seo_remote_fetch', __('Could not read the remote post.', 'power-creatives'), array('status' => 502));
        }
        $row  = self::remote_row($res['body'], $type, $site);
        $vars = self::remote_field_vars($site, $row, $user_id, $post_id);

        // Current value of THIS field (drives optimize vs generate).
        $current_map = array(
            'title' => 'title', 'slug' => 'slug', 'metaTitle' => 'metaTitle',
            'metaDescription' => 'metaDescription', 'primaryKeyword' => 'primaryKeyword',
            'metaKeywords' => 'metaKeywords',
        );
        $current = (string) ($row[$current_map[$field] ?? ''] ?? '');
        $vars['current_value'] = $current;

        $mode    = (!empty($current) && !empty($prompts[$use]['optimize'])) ? 'optimize' : 'generate';
        // An explicitly picked template decides the mode — same rule as the local
        // path (PCM_SEO_AI::generate_field). Without it, picking a Generate
        // template for a filled remote cell resolved the OPTIMIZE section and
        // silently ignored the pick.
        $mode    = PCM_SEO_AI::apply_template_mode($mode, (int) $user_id, $template_id, $use, (string) $current, $prompts[$use]);
        $default = $prompts[$use][$mode];
        $tpl     = PCM_SEO_AI::resolve_prompt($use . '_' . $mode, $default, $user_id, $template_id);
        $prompt  = PCM_SEO_AI::substitute_vars($tpl . PCM_SEO_AI::language_law($vars), $vars);
        $max     = (int) ($prompts[$use]['max'] ?? 200);

        if (!class_exists('PCM_LLM')) {
            return new WP_Error('pcm_seo_no_llm', __('AI provider is unavailable.', 'power-creatives'), array('status' => 500));
        }
        $opts = array('max_tokens' => $max);
        if (!empty($model)) {
            $opts['model'] = $model;
        }
        if (!empty($provider)) {
            $opts['provider'] = $provider;
        }
        // Shared invoke (same as the local path): sanitizes, slugifies, and
        // SELF-HEALS an envelope answer by retrying once with the shipped default
        // when the prompt was customized.
        return PCM_SEO_AI::invoke_scalar_field($prompt, $default, $vars, $field, $opts, $tpl !== $default);
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
        $inv = PCM_SEO_Page_Inventory::served_inventory($site, $post_id, null);
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


}
