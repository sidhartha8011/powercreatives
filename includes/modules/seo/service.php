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

class PCM_SEO_Service
{
    /** Content types this module operates on. */
    public const VALID_TYPES = ['post', 'page'];

    /** Editable WP post statuses (dropdown source + save validation). */
    public const VALID_STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

    /** Max rows fetched per content type. */
    public const PER_TYPE = 100;


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
        $links = PCM_SEO_Local::scan_link_details($raw, $from, (string) $site->url, false); // find by index; no HTTP checks
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
        $name    = ($site->name ?? '') !== '' ? $site->name : (string) $site->url;
        $default = (string) (PCM_SEO_AI::field_prompts()['site_ai_description']['generate'] ?? '');
        $tpl     = PCM_SEO_AI::resolve_prompt('site_ai_description_generate', $default, $user_id);
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
        $prompt = self::llm_info_prompt($ctx, $user_id);
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
        return array(
            'title'                     => (string) ($row['title'] ?? ''),
            'primary_keyword'           => (string) ($row['primaryKeyword'] ?? ''),
            'supporting_keyword'        => (string) ($row['supportingKeyword'] ?? ''),
            'meta_title'                => (string) ($row['metaTitle'] ?? ''),
            'meta_description'          => (string) ($row['metaDescription'] ?? ''),
            'post_type'                 => (string) ($row['type'] ?? ''),
            'page.type'                 => ($user_id && $post_id) ? self::get_page_type($user_id, (int) $site->id, $post_id) : '',
            'site.lang'                 => $locale ? substr($locale, 0, 2) : 'en',
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
        $route = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
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
        $default = $prompts[$use][$mode];
        $tpl     = PCM_SEO_AI::resolve_prompt($use . '_' . $mode, $default, $user_id, $template_id);
        $prompt  = PCM_SEO_AI::substitute_vars($tpl, $vars);
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
            $value  = PCM_SEO_AI::sanitize_ai_output((string) ($result['content'] ?? ''));
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
            // The connector's page-state ECHO (frozen contract: the value the
            // last accepted push carried, stored — never recomputed). NULL on
            // pre-versioning connectors: honest ignorance, not a state.
            'pageState' => (isset($res['body']['pageState']) && is_array($res['body']['pageState'])) ? array(
                'version'     => (int) ($res['body']['pageState']['version'] ?? 0),
                'fingerprint' => (string) ($res['body']['pageState']['fingerprint'] ?? ''),
            ) : null,
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
    public static function served_inventory(object $site, int $post_id, ?int $user_id): ?array
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
                'pageState'   => $served['pageState'],
            );
        }
        $in = self::remote_fetch_snapshot($site, $post_id, 'input');
        if ($in !== null && $in['html'] !== '') {
            $rules  = $user_id ? self::heading_instructions((int) $user_id, (int) $site->id, $post_id) : array();
            $parsed = self::parse_page_snapshot($in['html'], $rules);
            return array('view' => 'input', 'tier' => $in['tier'], 'headings' => $parsed['headings'], 'nodes' => $parsed['nodes'], 'error' => '', 'pageState' => $in['pageState']);
        }
        return array('view' => 'served', 'tier' => (string) $served['tier'], 'headings' => array(), 'nodes' => array(), 'error' => (string) ($served['error'] !== '' ? $served['error'] : 'loopback_blocked'), 'pageState' => $served['pageState']);
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
                    // Section ORIGIN (frames, 2026-07-13): the attribution this
                    // walk already computed, carried on the section's heading so
                    // the editor can tell owned/inserted/original apart. Emit-
                    // only metadata: save_page_edits strips it on entry.
                    $origin = ((string) ($att['target'] ?? '')) === 'sectionInsert' ? 'insert' : 'owned';
                    $out[]  = (string) preg_replace('#<h([1-6])(\b[^>]*)>#i', '<h$1$2 data-pcm-origin="' . $origin . '">', trim($slice), 1);
                    foreach ($imgs as $img) {
                        $out[] = $lock($img);
                    }
                    $in_slice = true;
                    continue;
                }
                $in_slice = false;
                list($inner, $imgs) = self::extract_imgs((string) $b['inner']);
                $out[] = '<h' . (int) $b['level'] . ' data-pcm-origin="original">' . $inner . '</h' . (int) $b['level'] . '>';
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
                // The editor header's context controls (owner order 2026-07-13).
                'pageType'  => $user_id ? self::get_page_type($user_id, (int) $site->id, $post_id) : '',
                'brandId'   => (int) ($site->brandId ?? 0),
                // Page versioning (frozen contract, 2026-07-16): hub record +
                // drift verdict from the connector's echo in this snapshot.
                'pageState' => PCM_SEO_Page_State::page_state_reply((int) $site->id, $post_id, $inv['pageState']),
            );
            if (isset($inv['contentHtml'])) {
                $out['contentHtml'] = (string) $inv['contentHtml'];
            }
            if ($inv['error'] !== '') {
                $out['error'] = $inv['error'];
            }
            return $out;
        }
        $headings = PCM_SEO_Remote_Headings::remote_get_headings($site, $post_id, $type, $user_id);
        $meta     = self::remote_get_content_nodes($site, $post_id);
        $out      = array(
            'supported' => (bool) $meta['supported'],
            'source'    => 'scan',
            'view'      => 'input',
            'headings'  => $headings,
            'nodes'     => (array) $meta['nodes'],
            // Pre-3.0 fleet: no snapshot, no echo — the record with an honest
            // no-echo verdict (drifted:false).
            'pageState' => PCM_SEO_Page_State::page_state_reply((int) $site->id, $post_id, null),
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
     * @param array{version:int,fingerprint:string} $page_state The state this
     *        push establishes (frozen contract keys) — the connector stores it
     *        beside the set and echoes it in the snapshot reply; pre-versioning
     *        connectors ignore the key.
     * @return array{stored:int}|\WP_Error
     */
    public static function push_rules(object $site, int $post_id, array $rules, array $page_state)
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
            'pageState'     => array(
                'version'     => (int) ($page_state['version'] ?? 0),
                'fingerprint' => (string) ($page_state['fingerprint'] ?? ''),
            ),
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

    // ── THE SAVE TRANSACTION (gap ATOMIC-SAVE 2026-07-17): one user intent =
    //    ONE push = ONE version. save_page_edits sets the flag (try/finally,
    //    leak-proof); the TWO chokepoints below — every routed save path flows
    //    through them — then defer instead of acting, and the transaction ends
    //    with a single real push + a flush of the queued version rows.
    //    Outside the flag nothing changes: single-section saves keep their
    //    existing 1-save-1-push behavior. ──
    /** @var bool Page-save transaction open: pushes stub, version rows queue. */
    private static $push_deferred = false;
    /** @var array<int,array{0:int,1:int,2:int,3:string,4:string,5:int,6:string}> record_version args queued until the commit push succeeds. */
    private static $deferred_versions = array();

    /** Push a post's CURRENT hub rule set; on failure restore $snapshot and return the error. */
    private static function push_current_rules_or_rollback(int $user_id, object $site, int $post_id, array $snapshot)
    {
        if (self::$push_deferred) {
            // Inside the save transaction the rows ARE the pending truth —
            // the ONE commit push at the end transmits them (every push sends
            // the full set, so intermediate pushes are redundant by
            // construction — the gap's core fact).
            return array('deferred' => true);
        }
        $rows   = self::post_rule_rows($user_id, (int) $site->id, $post_id);
        $schema = self::rules_to_schema($rows);
        // Page state rides EVERY push (frozen contract): the payload carries
        // the NEXT record, the hub records it only after the connector
        // accepted — hub record and connector echo can never disagree about
        // an accepted push. A rejected push rolls the rows back and leaves
        // the record untouched (the connector kept the previous set).
        $next = array(
            'version'     => PCM_SEO_Page_State::page_state((int) $site->id, $post_id)['version'] + 1,
            'fingerprint' => PCM_SEO_Page_State::page_fingerprint($schema),
        );
        $push = self::push_rules($site, $post_id, $schema, $next);
        if ($push instanceof WP_Error) {
            self::restore_rule_rows($user_id, (int) $site->id, $post_id, $snapshot);
            return $push;
        }
        PCM_SEO_Page_State::write_page_state((int) $site->id, $post_id, $next['version'], $next['fingerprint']);
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
        if (self::$push_deferred) {
            // Save transaction: history rows must only exist for states the
            // connector ACCEPTED — queued here, flushed after the commit push.
            self::$deferred_versions[] = array($user_id, $site_id, $post_id, $target, $match_text, $occurrence, $replacement);
            return;
        }
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
    public function list_page_versions(int $user_id, object $site, int $post_id, bool $rows_only = false): array
    {
        // ROWS-ONLY (gap 02d3cb7 D1): the editor's OPEN needs the saved rows
        // alone — a pure DB read, milliseconds. The Original (a REMOTE
        // snapshot round-trip) is fetched lazily when the versions dropdown
        // opens — its only consumer. Gating the open on it was the 30s white.
        $rows = array('versions' => self::list_versions($user_id, (int) $site->id, $post_id, 'page', '', 0));
        if ($rows_only) {
            return $rows;
        }
        $original = '';
        $in       = self::remote_fetch_snapshot($site, $post_id, 'input');
        if ($in !== null && $in['html'] !== '') {
            // No rules passed → no attribution → every section assembles as
            // original content, which is exactly what "Original" means.
            $parsed   = self::parse_page_snapshot($in['html']);
            $original = self::assemble_content_html($in['html'], $parsed['headings']);
        }
        return $rows + array('originalHtml' => $original);
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
    public static function update_section_owned_heading(int $user_id, object $site, int $post_id, array $h, ?string $new_text, int $new_level)
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
    public static function update_owned_heading_unit(int $user_id, object $site, int $rule_id, int $unit_index, string $current_text, ?string $new_text, int $new_level)
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
     * @return array{saved:int,inserted:int,skipped:int,removed:int,restored:int,
     *               hidden:int,unhidden:int,flattened:int,notes:array<int,string>}|\WP_Error
     */
    public function save_page_edits(int $user_id, object $site, int $post_id, string $html)
    {
        // Editor-only heading metadata (origin lanes + review identity
        // anchors): content identity, rules and version snapshots must never
        // carry either — the review clears its anchors client-side, this is
        // the belt.
        $html = (string) preg_replace('#\s*data-pcm-(?:origin|review-id)="[^"]*"#i', '', $html);
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

        // ── W2 REPLACE-NOT-APPEND (page versioning, 2026-07-16): the save must
        //    leave the page's rows as the NET set. section/sectionInsert rows
        //    the served view attributes to NOTHING are superseded identities —
        //    they serve nothing and can only stack (the live-probed 63-rule
        //    pile). They die BEFORE routing so an identity upsert below can
        //    never resurrect a dead row; the routed saves and their version
        //    rows then run exactly as before. sectionRemove rows stay (net by
        //    law while the doc omits their baseline section; restores above
        //    already deleted theirs). Same atomicity law as every rule write:
        //    the deletion pushes or rolls back. ──
        $live_ids = array();
        foreach ($inv['headings'] as $h) {
            if (isset($h['rule']['id']) && in_array((string) ($h['rule']['target'] ?? ''), array('section', 'sectionInsert'), true)) {
                $live_ids[] = (int) $h['rule']['id'];
            }
        }
        $page_rows = self::post_rule_rows($user_id, (int) $site->id, $post_id);
        $dead_ids  = PCM_SEO_Page_State::superseded_rule_ids($page_rows, $live_ids);
        $flattened = 0;
        // ── THE SAVE TRANSACTION OPENS (gap ATOMIC-SAVE 2026-07-17):
        //    $page_rows (pre-mutation) is the WHOLE save's rollback point.
        //    From here every routed push is a deferred stub and every version
        //    row queues; EVERY exit path below either aborts ($fail: restore
        //    + clear) or commits (the ONE real push at the tail). The static
        //    is request-scoped — PHP request isolation is the leak backstop,
        //    and the commit/abort paths clear it explicitly. ──
        self::$push_deferred     = true;
        self::$deferred_versions = array();
        if (!empty($dead_ids)) {
            // Superseded-row deletion rides the transaction — the commit push
            // at the tail transmits the flattened set (W2 law unchanged).
            foreach ($dead_ids as $dead_id) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete($rules_table, array('id' => $dead_id, 'userId' => $user_id, 'siteId' => (int) $site->id), array('%d', '%d', '%d'));
            }
            $flattened = count($dead_ids);
        }

        // ── Route every pair through the existing save paths, document order. ──
        $saved    = 0;
        $inserted = 0;
        $skipped  = 0;
        $fail     = static function (string $label, WP_Error $err) use ($user_id, $site, $post_id, $page_rows): WP_Error {
            // TOTAL ABORT (gap ATOMIC-SAVE — supersedes the partial-progress
            // law): restore the pre-save rule set, drop the queued history.
            // Nothing was pushed (every push in the transaction is a stub),
            // so hub rows return to exactly what the site still serves.
            self::$push_deferred     = false;
            self::$deferred_versions = array();
            self::restore_rule_rows($user_id, (int) $site->id, $post_id, $page_rows);
            return new WP_Error(
                $err->get_error_code(),
                sprintf(
                    /* translators: 1: section heading, 2: reason */
                    __('Saving “%1$s” failed: %2$s Nothing was saved — the site still serves its previous state. Fix the reason and save again.', 'power-creatives'),
                    $label,
                    rtrim($err->get_error_message()) . (str_ends_with(rtrim($err->get_error_message()), '.') ? '' : '.')
                ),
                $err->get_error_data()
            );
        };
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
                return $fail($b['text'], $res);
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
                ));
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
                return $fail($e['label'], $res);
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
                return $fail($b['text'], $res);
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
                return $fail((string) $rrow['matchText'], $res);
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
                return $fail($hh['src'], $res);
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
                return $fail($uh['src'], $res);
            }
            $unhidden++;
        }

        // ── THE SAVE TRANSACTION COMMITS (gap ATOMIC-SAVE): ONE real push
        //    carries the final net set with ONE version bump; a failed push
        //    restores the pre-save set entirely — the site serves ALL of this
        //    save or NONE of it. History (queued section rows + the page row)
        //    is written only after the connector ACCEPTED. A no-op save
        //    pushes nothing and leaves the recorded state untouched. ──
        $changed = $saved + $inserted + $removed_count + $restored + $hidden_count + $unhidden;
        self::$push_deferred = false;
        $pushed = false;
        if ($changed > 0 || $flattened > 0) {
            $push = self::push_current_rules_or_rollback($user_id, $site, $post_id, $page_rows);
            if ($push instanceof WP_Error) {
                self::$deferred_versions = array();
                return new WP_Error(
                    $push->get_error_code(),
                    sprintf(
                        /* translators: %s: reason */
                        __('Publishing to the site failed: %s Nothing was saved — the site still serves its previous state. Retry.', 'power-creatives'),
                        rtrim($push->get_error_message()) . (str_ends_with(rtrim($push->get_error_message()), '.') ? '' : '.')
                    ),
                    $push->get_error_data()
                );
            }
            $pushed = true;
        }
        foreach (self::$deferred_versions as $v) {
            self::record_version($v[0], $v[1], $v[2], $v[3], $v[4], $v[5], $v[6]);
        }
        self::$deferred_versions = array();
        // Page version: ONE row per changing save — the document as submitted
        // (duplicate-skip + cap ride record_version). No-op saves record nothing.
        if ($changed > 0) {
            self::record_version($user_id, (int) $site->id, $post_id, 'page', '', 0, $html);
        }
        return array(
            'saved'     => $saved,
            'inserted'  => $inserted,
            'skipped'   => $skipped,
            'removed'   => $removed_count,
            'restored'  => $restored,
            'hidden'    => $hidden_count,
            'unhidden'  => $unhidden,
            // Superseded rows the net-set law deleted (W2) — reported, never
            // silent; they change no served output, so they don't count as a
            // page-version change above.
            'flattened' => $flattened,
            'notes'     => array_values(array_unique($notes)),
            // THE CORNER'S TRUTH (gap ATOMIC-SAVE): pushed = the connector
            // accepted the commit push this request; pageState = the record
            // the connector echoed. A no-op reply (pushed=false) must never
            // overwrite the frontend's verdict.
            'pushed'    => $pushed,
            'pageState' => PCM_SEO_Page_State::page_state((int) $site->id, $post_id),
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
    public static function rekey_section_rules(int $user_id, object $site, int $post_id, string $old_norm, int $old_occ, string $new_norm, int $new_occ, int $new_level): void
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

    /** AI-rewrite a whole section / draft a NEW one (NOT saved — staged). Returns { value } = block HTML.
     *  With $draft (a revise): THE HUMAN-EDITOR CONTRACT + retention check ride
     *  the run — a targeted note may never silently rewrite the whole draft
     *  (gap e8fcae5 D3). */
    public static function remote_optimize_section(object $site, int $post_id, string $type, string $html, string $topic = '', ?string $model = null, ?int $user_id = null, ?string $provider = null, ?int $template_id = null, string $draft = '', bool $report_changes = false, array $purposes = array())
    {
        self::ensure_sites_service();
        $route = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
        $res   = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('_fields' => 'id,title,slug,link,author,meta'));
        $row   = (!is_wp_error($res) && is_array($res['body'] ?? null)) ? self::remote_row($res['body'], $type, $site) : array();
        $vars  = self::remote_field_vars($site, $row, $user_id, $post_id);
        $vars['current_value'] = $html;
        $vars['topic']         = $topic;
        // No hidden prompt: previously baked directly into $vars['topic'] as a
        // literal string, invisible to and unremovable by any template — now
        // its own Templates (module=seo) row ('revise_contract').
        $default_contract = 'You are a careful human editor revising an existing draft. Apply the user\'s request below EXACTLY '
            . 'and ONLY. Every sentence the request does not cover must be reproduced VERBATIM — word for word, '
            . 'unchanged, in full. Only if the request explicitly asks for a broad rewrite (tone, style, length, full '
            . 'rework) may you change text beyond it. Never invent facts.';
        $contract = PCM_SEO_AI::resolve_prompt('revise_contract_generate', $default_contract, $user_id);
        if ($draft !== '') {
            $vars['topic'] = $contract . "\n\nUSER REQUEST: " . $topic
                . "\n\nTHE CURRENT DRAFT (revise THIS text):\n" . $draft;
        }
        // THE CHANGE-CARD REVIEW (gap 0a0a3c3): the append-envelope contract —
        // the exact output contract lives IN the prompt (Anthropic JSON law),
        // appended server-side around the template like the revise contract
        // above, so user-customized templates need no migration. The reply is
        // PARSED AND VERIFIED by parse_section_reply(); an unparseable reply
        // falls back to raw — byte-identical legacy behavior, the floor.
        // Also no hidden prompt: 'revise_envelope' is its own Templates row.
        $envelope = '';
        if ($report_changes) {
            $why = !empty($purposes)
                ? 'one of these purpose ids: ' . implode(', ', array_map('sanitize_key', $purposes)) . ' (or an empty string when none fits)'
                : 'an empty string';
            $default_envelope = "\n\nOUTPUT FORMAT (mandatory): respond with ONLY this JSON, no markdown fences, no text around it: "
                . '{"html":"<the COMPLETE revised section HTML>","changes":[{"what":"one plain sentence describing ONE change you actually made","why":"<{{why}}>","quote":"5-12 words copied VERBATIM from your revised html"}]}'
                . ' List every real change you made; NEVER list a change you did not make.';
            $envelope_tpl = PCM_SEO_AI::resolve_prompt('revise_envelope_generate', $default_envelope, $user_id);
            $envelope     = PCM_SEO_AI::substitute_vars($envelope_tpl, array('why' => $why));
            $vars['topic'] .= $envelope;
        }
        $mode  = ($html !== '') ? 'optimize' : 'generate';
        $max   = (int) (PCM_SEO_AI::field_prompts()['section']['max'] ?? 1200);
        $val   = PCM_SEO_Local::run_prompt_section('section', $mode, $vars, $max, $model, $user_id, $provider, $template_id, false);
        if ($val instanceof WP_Error) {
            return $val;
        }
        // Parse BEFORE any retention math — with the envelope the raw reply
        // is JSON and retention must measure the extracted html, never the
        // JSON wrapper (gap 0a0a3c3).
        $parsed = self::parse_section_reply((string) $val['value'], $purposes, $report_changes);
        if ($draft !== '') {
            $min_retention = (float) ((PCM_Optimizer_Service::research_tunables()['revise']['minRetention'] ?? 0.6));
            $retention     = self::sentence_retention($draft, $parsed['value']);
            if ($retention < $min_retention && !self::note_wants_broad_rewrite($topic, $model, $user_id, $provider)) {
                // ONE retry with the contract restated — then honesty, never a
                // silent 80% text loss.
                $vars['topic'] = $contract . ' THIS IS A RETRY: the previous attempt rewrote text the request did not '
                    . 'cover. Copy the draft exactly and change ONLY what the request demands.'
                    . "\n\nUSER REQUEST: " . $topic . "\n\nTHE CURRENT DRAFT (revise THIS text):\n" . $draft
                    . $envelope;
                $retry = PCM_SEO_Local::run_prompt_section('section', $mode, $vars, $max, $model, $user_id, $provider, $template_id, false);
                if (!($retry instanceof WP_Error)) {
                    $retry_parsed = self::parse_section_reply((string) $retry['value'], $purposes, $report_changes);
                    if (self::sentence_retention($draft, $retry_parsed['value']) > $retention) {
                        $val       = $retry;
                        $parsed    = $retry_parsed;
                        $retention = self::sentence_retention($draft, $parsed['value']);
                    }
                }
                if ($retention < $min_retention) {
                    return new WP_Error('pcm_seo_revise_overwrote', __('The AI changed much more of the text than the note asked for — try again, or rephrase the note (say explicitly if you WANT a full rewrite).', 'power-creatives'), array('status' => 502));
                }
            }
        }
        // REWRITTEN verdict (gap 0a0a3c3): measured server-side against the
        // ORIGINAL section with the hub-data threshold — the review presents
        // such sections as calm Before/After blocks instead of word confetti.
        $review_cfg = (array) (PCM_Optimizer_Service::research_tunables()['review'] ?? array());
        $rewritten  = $html !== ''
            && self::sentence_retention($html, $parsed['value']) < (float) ($review_cfg['rewriteRetention'] ?? 0.35);
        // The UI shows what ACTUALLY generated (the API's own report).
        return array(
            'value'     => wp_kses_post($parsed['value']),
            'model'     => (string) $val['model'],
            'provider'  => (string) $val['provider'],
            // Verified per-section changes (what/why/quote) — empty when the
            // model answered without the envelope contract (the honest floor).
            'changes'   => $parsed['changes'],
            'rewritten' => $rewritten,
        );
    }

    /**
     * Parse + VERIFY the section reply under the change-card envelope
     * (gap 0a0a3c3). Reply contract: {"html": "...", "changes":
     * [{what, why, quote}]}. Every change survives ONLY if its quote is
     * found VERBATIM (whitespace/case-normalized) in the html's text and
     * its why is one of the run's purposes ('' otherwise) — the model's
     * confession is checked, never believed. Any parse failure returns the
     * RAW reply with no changes: byte-identical legacy behavior, the floor.
     *
     * @param string   $raw      The model's raw reply.
     * @param string[] $purposes Allowed why ids for this run.
     * @param bool     $expected Whether the envelope was requested at all.
     * @return array{value:string,changes:array<int,array{what:string,why:string,quote:string}>}
     */
    public static function parse_section_reply(string $raw, array $purposes = array(), bool $expected = true): array
    {
        $fallback = array('value' => $raw, 'changes' => array());
        if (!$expected) {
            return $fallback;
        }
        $body = trim($raw);
        // Tolerate fenced replies (```json ... ```) — the contract forbids
        // them but a recoverable reply beats a discarded one.
        if (preg_match('/^```[a-z]*\s*(.*?)\s*```$/s', $body, $m)) {
            $body = trim($m[1]);
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['html']) || !is_string($decoded['html']) || trim($decoded['html']) === '') {
            return $fallback;
        }
        $value      = trim($decoded['html']);
        $norm       = static fn(string $s): string => strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
        $value_text = $norm(wp_strip_all_tags($value));
        $allowed    = array_map('sanitize_key', $purposes);
        // class_exists = standalone-harness compatibility (bare PHP loads the
        // seo service alone); the default mirrors the seeded tunable.
        $max = (int) (class_exists('PCM_Optimizer_Service')
            ? (PCM_Optimizer_Service::research_tunables()['review']['maxChanges'] ?? 12)
            : 12);
        $changes    = array();
        foreach ((array) ($decoded['changes'] ?? array()) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $what  = sanitize_text_field((string) ($c['what'] ?? ''));
            $quote = sanitize_text_field((string) ($c['quote'] ?? ''));
            if ($what === '' || $quote === '' || strpos($value_text, $norm($quote)) === false) {
                continue; // unverifiable claim — never shown as a card
            }
            $why       = sanitize_key((string) ($c['why'] ?? ''));
            $changes[] = array(
                'what'  => $what,
                'why'   => in_array($why, $allowed, true) ? $why : '',
                'quote' => $quote,
            );
            if (count($changes) >= max(1, $max)) {
                break;
            }
        }
        return array('value' => $value, 'changes' => $changes);
    }


    /**
     * How much of the draft survived, sentence-wise: the share of the
     * draft's substantial sentences (≥40 chars) present verbatim
     * (whitespace/case-normalized) in the result. No measurable sentences
     * → 1.0 (never block a tiny draft).
     *
     * @param string $draft  The draft sent for revision.
     * @param string $result The model's output.
     * @return float 0..1
     */
    private static function sentence_retention(string $draft, string $result): float
    {
        $norm        = static fn(string $s): string => strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
        $result_norm = $norm(wp_strip_all_tags($result));
        $sentences   = preg_split('/(?<=[.!?])\s+/u', wp_strip_all_tags($draft)) ?: array();
        $len         = static fn(string $s): int => function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
        $substantial = array_values(array_filter(array_map($norm, $sentences), static fn(string $s): bool => $len($s) >= 40));
        if (empty($substantial)) {
            return 1.0;
        }
        $kept = 0;
        foreach ($substantial as $s) {
            if (strpos($result_norm, $s) !== false) {
                $kept++;
            }
        }
        return $kept / count($substantial);
    }

    /**
     * Dynamic scope judgment (never keyword-hardcoded): does the note ask
     * for a BROAD rewrite? Unjudgeable (LLM error) → false — the strict
     * path protects the user's text.
     *
     * @param string      $note     The revise note.
     * @param string|null $model    Model override.
     * @param int|null    $user_id  Key owner.
     * @param string|null $provider Provider override.
     * @return bool
     */
    private static function note_wants_broad_rewrite(string $note, ?string $model, ?int $user_id, ?string $provider): bool
    {
        try {
            // No hidden prompt: 'revise_scope_classifier' is its own Templates
            // (module=seo) row a user can view/edit.
            $default_system = 'Judge ONE thing about the user\'s revision request: does it ask for a BROAD rewrite of the whole text '
                . '(tone, style, length, full rework) or a TARGETED change (specific facts, words, numbers, links)? '
                . 'Respond with ONLY this JSON, no markdown: {"broad":true} or {"broad":false}.';
            $system = PCM_SEO_AI::resolve_prompt('revise_scope_classifier_generate', $default_system, $user_id);
            $parsed = PCM_LLM::invoke_json(
                array(
                    array('role' => 'system', 'content' => $system),
                    array('role' => 'user', 'content' => $note),
                ),
                array('name' => 'revise_scope', 'schema' => array('type' => 'object', 'properties' => array('broad' => array('type' => 'boolean')), 'required' => array('broad'))),
                array('model' => $model ?: null, 'provider' => $provider ?: null, 'user_id' => (int) $user_id)
            );
            return !empty($parsed['broad']);
        } catch (\Throwable $e) {
            return false;
        }
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
            'seoPlugin'  => PCM_SEO_Local::detect_seo_plugin(),
        );
    }

}
