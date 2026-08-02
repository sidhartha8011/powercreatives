<?php
/**
 * PCM_GSC — minimal Google Search Console client (service-account auth).
 *
 * Zero dependencies: the service-account JSON (pasted into the GSC integration card)
 * is exchanged for an access token via a self-signed RS256 JWT (openssl), then the
 * Search Analytics API is queried per PAGE for clicks / impressions / CTR / position
 * and top queries. The service-account email must be added as a (restricted) user on
 * the GSC property — validate_gsc_key() and the /integrations/gsc/* routes surface
 * clear errors when it isn't.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_GSC
{
    // Full webmasters (read + add properties) AND site verification — required for the
    // add-site auto-verify flow (PUT sites/{url} + Site Verification API). Connections made
    // before this scope widening hold readonly-only tokens; write calls then 403 and the
    // methods below surface a clear "reconnect with Google" error.
    private const SCOPE     = 'https://www.googleapis.com/auth/webmasters https://www.googleapis.com/auth/siteverification';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE  = 'https://www.googleapis.com/webmasters/v3';
    private const VERIFY_API = 'https://www.googleapis.com/siteVerification/v1';

    /** Parse + sanity-check the pasted service-account JSON. */
    public static function parse_credentials(string $json): array|WP_Error
    {
        $trimmed = trim($json);
        // Common mistake: pasting a Google API key (AIza…). The Search Console API does NOT accept
        // API keys — it needs a service account (or OAuth). Say so precisely instead of "bad JSON".
        if (preg_match('/^AIza[0-9A-Za-z_\-]{35}$/', $trimmed)) {
            return new WP_Error('pcm_gsc_api_key', __('That looks like a Google API key, but Search Console can’t be accessed with an API key — it needs a SERVICE ACCOUNT. In Google Cloud → IAM → Service Accounts, create a service account, add a JSON key (Keys → Add key → JSON), and paste that whole JSON file here. Then add the account’s email as a user on your Search Console property.', 'power-creatives'));
        }
        $data = json_decode($trimmed, true);
        if (!is_array($data)) {
            return new WP_Error('pcm_gsc_bad_json', __('That is not valid JSON — paste the FULL service-account key file (starts with {"type":"service_account"…).', 'power-creatives'));
        }
        // OAuth credential (stored by the "Connect with Google" flow): one agency Google login
        // grants every property that account can already see — no per-property service-account setup.
        if (($data['type'] ?? '') === 'oauth' || isset($data['refresh_token'])) {
            $cid = (string) ($data['client_id'] ?? '');
            $sec = (string) ($data['client_secret'] ?? '');
            $rt  = (string) ($data['refresh_token'] ?? '');
            if ($cid === '' || $sec === '' || $rt === '') {
                return new WP_Error('pcm_gsc_bad_json', __('The Google connection is incomplete (missing client_id/client_secret/refresh_token) — reconnect via "Connect with Google" on the Integrations page.', 'power-creatives'));
            }
            return array('type' => 'oauth', 'email' => __('your connected Google account', 'power-creatives'), 'client_id' => $cid, 'client_secret' => $sec, 'refresh_token' => $rt);
        }
        $email = (string) ($data['client_email'] ?? '');
        $key   = (string) ($data['private_key'] ?? '');
        if (($data['type'] ?? '') !== 'service_account' || $email === '' || $key === '') {
            return new WP_Error('pcm_gsc_bad_json', __('The JSON is missing service-account fields (type/client_email/private_key). Download the key from Google Cloud → IAM → Service Accounts → Keys.', 'power-creatives'));
        }
        return array('type' => 'service_account', 'email' => $email, 'private_key' => $key);
    }

    /** Base64url per RFC 7515. */
    private static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** Build + sign the RS256 JWT assertion for the token exchange. */
    public static function build_jwt(string $client_email, string $private_key, int $now): string|WP_Error
    {
        $header  = self::b64url((string) wp_json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
        $payload = self::b64url((string) wp_json_encode(array(
            'iss'   => $client_email,
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        )));
        $input = $header . '.' . $payload;
        $sig   = '';
        $pkey  = openssl_pkey_get_private($private_key);
        if ($pkey === false || !openssl_sign($input, $sig, $pkey, OPENSSL_ALGO_SHA256)) {
            return new WP_Error('pcm_gsc_bad_key', __('The private_key in the JSON could not be used to sign — re-download the key file from Google Cloud.', 'power-creatives'));
        }
        return $input . '.' . self::b64url($sig);
    }

    /** Access token for the stored credential — service account (JWT grant) or OAuth
     *  connection (refresh-token grant). Cached ~55 min in a transient either way.
     *  $fresh discards the cached token and mints a new one (401-recovery path). */
    public static function access_token(string $json, bool $fresh = false): string|WP_Error
    {
        $creds = self::parse_credentials($json);
        if (is_wp_error($creds)) {
            return $creds;
        }
        $is_oauth  = (($creds['type'] ?? '') === 'oauth');
        $cache_key = 'pcm_gsc_tok_' . md5($is_oauth ? $creds['refresh_token'] : $creds['email']);
        if ($fresh) {
            delete_transient($cache_key);
        }
        $cached = $fresh ? false : get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        if ($is_oauth) {
            $post = array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $creds['refresh_token'],
                'client_id'     => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
            );
        } else {
            $jwt = self::build_jwt($creds['email'], $creds['private_key'], time());
            if (is_wp_error($jwt)) {
                return $jwt;
            }
            $post = array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            );
        }
        $resp = wp_remote_post(self::TOKEN_URL, array('timeout' => 20, 'body' => $post));
        if (is_wp_error($resp)) {
            return new WP_Error('pcm_gsc_token', sprintf(__('Could not reach Google to authenticate (%s).', 'power-creatives'), $resp->get_error_message()));
        }
        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        $tok  = (string) ($body['access_token'] ?? '');
        if ($tok === '') {
            $why = (string) ($body['error_description'] ?? $body['error'] ?? 'unknown error');
            // The one failure that MUST be loud and specific: a dead refresh token (invalid_grant).
            // Most common cause: the OAuth consent screen was left in "Testing" mode — Google then
            // expires refresh tokens every 7 days. Also: access revoked, or the OAuth client deleted.
            if ($is_oauth && stripos((string) ($body['error'] ?? ''), 'invalid_grant') !== false) {
                return new WP_Error('pcm_gsc_invalid_grant', __('Google revoked this connection (invalid_grant). Most common cause: the OAuth consent screen is still in "Testing" mode, which kills the connection every 7 days — in Google Cloud → OAuth consent screen, click "Publish app" (no verification needed), then reconnect via "Connect with Google" on the Integrations page.', 'power-creatives'));
            }
            $msg = $is_oauth
                ? sprintf(__('Google rejected the connection (%s). Reconnect via "Connect with Google" on the Integrations page.', 'power-creatives'), $why)
                : sprintf(__('Google rejected the service-account key (%s). Re-download the JSON key and paste it again.', 'power-creatives'), $why);
            return new WP_Error('pcm_gsc_token', $msg);
        }
        set_transient($cache_key, $tok, 55 * MINUTE_IN_SECONDS);
        return $tok;
    }

    // ── "Connect with Google" (OAuth authorization-code flow) ──────────────────────────────

    /** The redirect URI to register on the Google OAuth client (Web application type). */
    public static function redirect_uri(): string
    {
        return rest_url('pcm/v1/integrations/gsc/oauth-callback');
    }

    /** Google consent-screen URL. `access_type=offline&prompt=consent` forces a refresh token. */
    public static function authorize_url(string $client_id, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(array(
            'client_id'     => $client_id,
            'redirect_uri'  => self::redirect_uri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ));
    }

    /** Exchange the callback `code` for tokens. Returns ['refresh_token'=>, 'access_token'=>]. */
    public static function exchange_code(string $client_id, string $client_secret, string $code): array|WP_Error
    {
        $resp = wp_remote_post(self::TOKEN_URL, array(
            'timeout' => 20,
            'body'    => array(
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => self::redirect_uri(),
            ),
        ));
        if (is_wp_error($resp)) {
            return new WP_Error('pcm_gsc_oauth', sprintf(__('Could not reach Google to finish the connection (%s).', 'power-creatives'), $resp->get_error_message()));
        }
        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        $rt   = (string) ($body['refresh_token'] ?? '');
        if ($rt === '') {
            $why = (string) ($body['error_description'] ?? $body['error'] ?? 'no refresh token returned');
            return new WP_Error('pcm_gsc_oauth', sprintf(__('Google did not complete the connection (%s).', 'power-creatives'), $why));
        }
        return array('refresh_token' => $rt, 'access_token' => (string) ($body['access_token'] ?? ''));
    }

    /** Authenticated GET/POST against the Search Console API. */
    private static function api(string $json, string $method, string $path, ?array $body = null): array|WP_Error
    {
        $tok = self::access_token($json);
        if (is_wp_error($tok)) {
            return $tok;
        }
        $args = array(
            'method'  => $method,
            'timeout' => 30,
            'headers' => array('Authorization' => 'Bearer ' . $tok, 'Content-Type' => 'application/json'),
        );
        if ($body !== null) {
            $args['body'] = (string) wp_json_encode($body);
        } elseif ($method !== 'GET') {
            // Bodyless PUT/POST (e.g. add_property) MUST still send Content-Length: 0 —
            // Google answers "HTTP 411 Length Required" otherwise. An empty body alone is
            // NOT enough: WP's curl transport skips the header for empty payloads, so set
            // it explicitly.
            $args['body'] = '';
            $args['headers']['Content-Length'] = '0';
        }
        // $path is normally relative to the Search Console API; the Site Verification API
        // lives on another base, so absolute https:// paths pass through untouched.
        $url = str_starts_with($path, 'https://') ? $path : self::API_BASE . $path;
        $resp = wp_remote_request($url, $args);
        // Quotas: ~1,200 queries/min per project + per-site limits. One short retry on
        // 429/quota/5xx smooths multi-property pulls without hanging the web request.
        $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
        if (is_wp_error($resp) || $code === 429 || $code >= 500) {
            usleep(700000); // 0.7s
            $resp = wp_remote_request($url, $args);
            $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
        }
        // 401 on a just-minted token happens (first use of a fresh credential) and a cached
        // token can be revoked server-side before our 55-min transient lapses. Both self-heal
        // the same way: force-refresh the token ONCE and retry — this is why a pull could fail
        // on the first click and succeed on the second.
        if ($code === 401) {
            $tok = self::access_token($json, true);
            if (!is_wp_error($tok)) {
                $args['headers']['Authorization'] = 'Bearer ' . $tok;
                $resp = wp_remote_request($url, $args);
            }
        }
        if (is_wp_error($resp)) {
            return new WP_Error('pcm_gsc_http', $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code >= 300) {
            $msg = (string) ($data['error']['message'] ?? ('HTTP ' . $code));
            return new WP_Error('pcm_gsc_api', $msg, array('status' => $code));
        }
        return is_array($data) ? $data : array();
    }

    /** Properties (sites) the service account can read. Returns ['sc-domain:x.com', 'https://x.com/', …]. */
    public static function list_properties(string $json): array|WP_Error
    {
        $data = self::api($json, 'GET', '/sites');
        if (is_wp_error($data)) {
            return $data;
        }
        $out = array();
        foreach ((array) ($data['siteEntry'] ?? array()) as $s) {
            if (($s['permissionLevel'] ?? '') !== 'siteUnverifiedUser' && !empty($s['siteUrl'])) {
                $out[] = (string) $s['siteUrl'];
            }
        }
        return $out;
    }

    /**
     * Pick the GSC property that covers $site_url. Prefers the domain property
     * (sc-domain:host — covers every protocol/subdomain), else a url-prefix property
     * whose prefix matches the site's host (www-insensitive).
     */
    public static function match_property(array $properties, string $site_url): string
    {
        $all = self::match_properties($properties, $site_url);
        return $all[0] ?? '';
    }

    /**
     * ALL GSC properties covering $site_url, ranked best-first:
     *   1. sc-domain:host  (covers every protocol/subdomain — one property, all data)
     *   2. url-prefix whose host EXACTLY matches the site's host
     *   3. url-prefix whose host matches www-insensitively (the other www/non-www variant)
     * The caller tries them in order and uses the first that actually returns data — so a
     * freshly-added but EMPTY property (e.g. a www prefix created by the add-site auto-verify,
     * while the traffic lives under the non-www / sc-domain property) no longer wins and yields
     * "no data". Returns [] when nothing matches.
     */
    public static function match_properties(array $properties, string $site_url): array
    {
        $host = strtolower((string) (wp_parse_url($site_url, PHP_URL_HOST) ?: $site_url));
        $bare = preg_replace('/^www\./', '', $host);
        $domain = array();
        $exact  = array();
        $other  = array();
        $parent = array();
        foreach ($properties as $p) {
            if (stripos($p, 'sc-domain:') === 0) {
                $d = strtolower(substr($p, 10));
                if ($d === $bare) {
                    $domain[] = $p;
                } elseif ($d !== '' && substr($bare, -(strlen($d) + 1)) === '.' . $d) {
                    // A GSC DOMAIN property covers every subdomain — that is the whole point of
                    // one. `sc-domain:example.com` therefore serves blog.example.com too, and an
                    // exact-equality check meant a subdomain site reported "no access" even
                    // though the account could read it (hit live: brizy.profitmedia.pro vs
                    // sc-domain:profitmedia.pro). Anchored on the leading dot so
                    // `notexample.com` can never match `example.com`.
                    $parent[] = $p;
                }
                continue;
            }
            $ph = strtolower((string) (wp_parse_url($p, PHP_URL_HOST) ?: ''));
            if ($ph === '' || preg_replace('/^www\./', '', $ph) !== $bare) { continue; }
            if ($ph === $host) { $exact[] = $p; } else { $other[] = $p; }
        }
        // Order = most specific first: the site's OWN domain property, then its exact URL
        // property, then www/non-www variants, and only then a parent-domain property that
        // merely covers it (its data spans every sibling subdomain, so it is the last resort).
        return array_values(array_unique(array_merge($domain, $exact, $other, $parent)));
    }

    /**
     * Normalize a page URL for map lookups so the SEO table's row permalinks and GSC's returned
     * page URLs collapse to the same key regardless of: protocol, www, trailing slash, letter case,
     * AND percent-encoding. The last one matters for non-ASCII slugs (Swedish å/ä/ö etc.): GSC
     * returns `/tandv%C3%A5rd`, a permalink may be raw `/tandvård`, with mixed hex case — all must
     * match. Host lowercased; path percent-decoded then Unicode-lowercased; trailing slash dropped.
     */
    public static function norm_url(string $url): string
    {
        $host = preg_replace('/^www\./', '', strtolower((string) (wp_parse_url($url, PHP_URL_HOST) ?: '')));
        $path = rtrim((string) (wp_parse_url($url, PHP_URL_PATH) ?? '/'), '/');
        $key  = rawurldecode($host . $path);
        return function_exists('mb_strtolower') ? mb_strtolower($key, 'UTF-8') : strtolower($key);
    }

    /**
     * Paginated Search Analytics query: rowLimit 25000 + startRow until a short batch
     * (Google's documented pagination), capped at $max_pages to bound an interactive
     * request. Returns all rows, or WP_Error from the FIRST call (later pages degrade
     * gracefully to what was already fetched).
     */
    private static function sa_rows(string $json, string $path, array $body, int $max_pages = 4): array|WP_Error
    {
        $limit = 25000;
        $rows  = array();
        for ($page = 0; $page < $max_pages; $page++) {
            $resp = self::api($json, 'POST', $path, array_merge($body, array(
                'rowLimit' => $limit,
                'startRow' => $page * $limit,
            )));
            if (is_wp_error($resp)) {
                return $page === 0 ? $resp : $rows; // partial > nothing on later pages
            }
            $batch = (array) ($resp['rows'] ?? array());
            $rows  = array_merge($rows, $batch);
            if (count($batch) < $limit) {
                break;
            }
        }
        return $rows;
    }

    /**
     * Per-QUERY search stats — one page (or the whole property when
     * $page_url is ''): dimensions=[query] + a page filter, same window law
     * as page_stats (2-day lag + dataState=all, the interactive view).
     * Rows: [{query, clicks, impressions, position}] in API order (clicks
     * desc) — consumers sort and filter themselves.
     *
     * @param string $json        Service-account credentials JSON.
     * @param string $property    The matched GSC property.
     * @param int    $days        Look-back window.
     * @param string $page_url    Exact page URL to filter on ('' = property-wide).
     * @param int    $offset_days Shift the whole window back by N days —
     *                            `$offset_days = $days` is the PREVIOUS
     *                            period of the same length (the compare).
     * @return array|WP_Error
     */
    /** Option holding the per-site GSC property overrides: norm_url(site) => property. */
    private const PROPERTY_MAP_OPTION = 'pcm_gsc_property_map';

    /**
     * The property a site is EXPLICITLY mapped to, or '' when it has never been overridden.
     *
     * Auto-matching (match_properties()) stays the default: a site nobody has touched behaves
     * exactly as before. This only records a human saying "no, pull stats from THAT property"
     * — which matters because a site can own several (www / non-www / sc-domain) and the
     * auto-pick is a heuristic, so a wrong guess otherwise silently re-asserts itself on the
     * next pull.
     *
     * @param string $site_url Site URL (any form — normalised internally).
     * @return string Property string, or '' when unmapped.
     */
    public static function property_override(string $site_url): string
    {
        $map = get_option(self::PROPERTY_MAP_OPTION);
        if (!is_array($map)) {
            return '';
        }
        $key = self::norm_url($site_url);
        return isset($map[$key]) ? (string) $map[$key] : '';
    }

    /**
     * Pin a site to a GSC property, or clear the pin with an empty $property (back to auto).
     *
     * @param string $site_url Site URL (any form — normalised internally).
     * @param string $property GSC property, or '' to clear.
     * @return void
     */
    public static function set_property_override(string $site_url, string $property): void
    {
        $map = get_option(self::PROPERTY_MAP_OPTION);
        if (!is_array($map)) {
            $map = array();
        }
        $key = self::norm_url($site_url);
        if ($key === '') {
            return;
        }
        $property = trim($property);
        if ($property === '') {
            unset($map[$key]);          // cleared → fall back to auto-matching
        } else {
            $map[$key] = $property;
        }
        update_option(self::PROPERTY_MAP_OPTION, $map, false);
    }

    public static function query_stats(string $json, string $property, int $days = 30, string $page_url = '', int $offset_days = 0): array|WP_Error
    {
        $offset_days = max(0, $offset_days);
        $end   = gmdate('Y-m-d', time() - (2 + $offset_days) * DAY_IN_SECONDS);
        $start = gmdate('Y-m-d', time() - (2 + $offset_days + max(1, $days)) * DAY_IN_SECONDS);
        $path  = '/sites/' . rawurlencode($property) . '/searchAnalytics/query';
        $body  = array(
            'startDate'  => $start,
            'endDate'    => $end,
            'dataState'  => 'all',
            'dimensions' => array('query'),
        );
        if ($page_url !== '') {
            $body['dimensionFilterGroups'] = array(array(
                'filters' => array(array('dimension' => 'page', 'operator' => 'equals', 'expression' => $page_url)),
            ));
        }
        $rows = self::sa_rows($json, $path, $body);
        if (is_wp_error($rows)) {
            return $rows;
        }
        $out = array();
        foreach ($rows as $r) {
            $query = (string) ($r['keys'][0] ?? '');
            if ($query === '') {
                continue;
            }
            $out[] = array(
                'query'       => $query,
                'clicks'      => (int) ($r['clicks'] ?? 0),
                'impressions' => (int) ($r['impressions'] ?? 0),
                'position'    => round((float) ($r['position'] ?? 0), 1),
            );
        }
        return $out;
    }

    /**
     * Per-page search stats for the last $days days:
     * [norm_url => ['clicks'=>int,'impressions'=>int,'ctr'=>float,'position'=>float,'keywords'=>string[]]]
     * Two paginated queries: dimensions=[page] for the metrics, dimensions=[page,query] for top
     * queries. 2-DAY lag + dataState=all: this is an INTERACTIVE view (not a warehouse), so it
     * mirrors what the GSC UI shows — including the freshest ~2 days — rather than waiting for
     * finalized data, which can leave a low-traffic recent window looking empty.
     */
    public static function page_stats(string $json, string $property, int $days = 28): array|WP_Error
    {
        $end   = gmdate('Y-m-d', time() - 2 * DAY_IN_SECONDS);
        $start = gmdate('Y-m-d', time() - (2 + max(1, $days)) * DAY_IN_SECONDS);
        $path  = '/sites/' . rawurlencode($property) . '/searchAnalytics/query';
        $base  = array('startDate' => $start, 'endDate' => $end, 'dataState' => 'all');

        $page_rows = self::sa_rows($json, $path, $base + array('dimensions' => array('page')));
        if (is_wp_error($page_rows)) {
            return $page_rows;
        }
        $out = array();
        foreach ($page_rows as $r) {
            $u = self::norm_url((string) ($r['keys'][0] ?? ''));
            if ($u === '') {
                continue;
            }
            $out[$u] = array(
                'clicks'      => (int) ($r['clicks'] ?? 0),
                'impressions' => (int) ($r['impressions'] ?? 0),
                'ctr'         => round((float) ($r['ctr'] ?? 0) * 100, 1),   // %
                'position'    => round((float) ($r['position'] ?? 0), 1),
                'keywords'    => array(),
            );
        }

        // Top queries per page (rows arrive ordered by clicks desc; keep the first 5 per page).
        $query_rows = self::sa_rows($json, $path, $base + array('dimensions' => array('page', 'query')));
        if (!is_wp_error($query_rows)) { // metrics still useful if the query call fails
            foreach ($query_rows as $r) {
                $u = self::norm_url((string) ($r['keys'][0] ?? ''));
                $q = (string) ($r['keys'][1] ?? '');
                if ($u === '' || $q === '' || !isset($out[$u]) || count($out[$u]['keywords']) >= 5) {
                    continue;
                }
                $out[$u]['keywords'][] = $q;
            }
        }
        return array('range' => array('start' => $start, 'end' => $end), 'pages' => $out);
    }

    // ── Add-site auto-verification (mirrors the n8n flow: add_site → token → verify) ────────

    /** Rewrap a 403 as a clear "the connection lacks the new write scopes" instruction. */
    private static function scope_error(WP_Error $e): WP_Error
    {
        $status = is_array($e->get_error_data()) ? (int) ($e->get_error_data()['status'] ?? 0) : 0;
        if ($status === 403) {
            return new WP_Error('pcm_gsc_scope', __('Google refused (insufficient permissions). The Google connection predates site-verification support — reconnect via "Connect with Google" on the Integrations page to grant the new permissions, then retry. (Also make sure the "Site Verification API" is enabled in the Google Cloud project.)', 'power-creatives'));
        }
        return $e;
    }

    /** URL-prefix property identifier: the site URL with a trailing slash. */
    private static function property_id(string $site_url): string
    {
        return rtrim($site_url, '/') . '/';
    }

    /** Add the site as a Search Console property (idempotent; lands "unverified"). */
    public static function add_property(string $json, string $site_url): bool|WP_Error
    {
        $r = self::api($json, 'PUT', '/sites/' . rawurlencode(self::property_id($site_url)));
        if (is_wp_error($r)) {
            return self::scope_error($r);
        }
        return true;
    }

    /** Mint a META verification token for the site (rendered by the connector in wp_head). */
    public static function verification_token(string $json, string $site_url): string|WP_Error
    {
        $r = self::api($json, 'POST', self::VERIFY_API . '/token', array(
            'site'               => array('identifier' => self::property_id($site_url), 'type' => 'SITE'),
            'verificationMethod' => 'META',
        ));
        if (is_wp_error($r)) {
            return self::scope_error($r);
        }
        $token = (string) ($r['token'] ?? '');
        if ($token === '') {
            return new WP_Error('pcm_gsc_verify', __('Google did not return a verification token.', 'power-creatives'));
        }
        // For META the API returns the whole `<meta … content="XYZ" />` tag — keep only the
        // content value so the connector can render the tag itself with proper escaping.
        if (stripos($token, '<meta') !== false && preg_match('/content=["\']([^"\']+)["\']/i', $token, $m)) {
            $token = $m[1];
        }
        return $token;
    }

    /** Ask Google to verify the site (it fetches the page and checks the META token). */
    public static function verify_property(string $json, string $site_url): bool|WP_Error
    {
        $r = self::api($json, 'POST', self::VERIFY_API . '/webResource?verificationMethod=META', array(
            'site' => array('identifier' => self::property_id($site_url), 'type' => 'SITE'),
        ));
        if (is_wp_error($r)) {
            return self::scope_error($r);
        }
        return true;
    }
}
