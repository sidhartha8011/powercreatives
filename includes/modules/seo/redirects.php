<?php
/**
 * SEO Slug-change Redirects — hub-owned store, connector-served copy.
 *
 * Extracted VERBATIM from PCM_SEO_Service (2026-07-29 decomposition).
 *
 * Slug-change redirects (connector 3.0.5): hub-owned store, connector-
 * served copy. Same laws as rules: capability BEFORE any write, the
 * connector receives the COMPLETE set, push-fail rolls the hub row back.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_SEO_Redirects
{
    /** Whether the site's connector answers /redirects (3.0.5+) — the capability handle. */
    public static function connector_supports_redirects(object $site): bool
    {
        static $memo = array();
        $key = (int) $site->id;
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        PCM_SEO_Service::ensure_sites_service();
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
        PCM_SEO_Service::ensure_sites_service();
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
    public static function list_redirects(int $user_id, object $site): array
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
    public static function save_redirect(int $user_id, object $site, array $input)
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
    public static function delete_redirect(int $user_id, object $site, int $redirect_id)
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
        PCM_SEO_Service::ensure_sites_service();
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
}
