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
    private const SCOPE     = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE  = 'https://www.googleapis.com/webmasters/v3';

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
     *  connection (refresh-token grant). Cached ~55 min in a transient either way. */
    public static function access_token(string $json): string|WP_Error
    {
        $creds = self::parse_credentials($json);
        if (is_wp_error($creds)) {
            return $creds;
        }
        $is_oauth  = (($creds['type'] ?? '') === 'oauth');
        $cache_key = 'pcm_gsc_tok_' . md5($is_oauth ? $creds['refresh_token'] : $creds['email']);
        $cached    = get_transient($cache_key);
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
        }
        $resp = wp_remote_request(self::API_BASE . $path, $args);
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
        $host = strtolower((string) (wp_parse_url($site_url, PHP_URL_HOST) ?: $site_url));
        $bare = preg_replace('/^www\./', '', $host);
        foreach ($properties as $p) { // domain property first — the broadest match
            if (stripos($p, 'sc-domain:') === 0 && strtolower(substr($p, 10)) === $bare) {
                return $p;
            }
        }
        foreach ($properties as $p) {
            $ph = strtolower((string) (wp_parse_url($p, PHP_URL_HOST) ?: ''));
            if ($ph !== '' && preg_replace('/^www\./', '', $ph) === $bare) {
                return $p;
            }
        }
        return '';
    }

    /** Normalize a page URL for map lookups: lowercase host, no trailing slash, no protocol. */
    public static function norm_url(string $url): string
    {
        $host = strtolower((string) (wp_parse_url($url, PHP_URL_HOST) ?: ''));
        $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?? '/');
        return preg_replace('/^www\./', '', $host) . rtrim($path, '/');
    }

    /**
     * Per-page search stats for the last $days days:
     * [norm_url => ['clicks'=>int,'impressions'=>int,'ctr'=>float,'position'=>float,'keywords'=>string[]]]
     * Two API calls: dimensions=[page] for the metrics, dimensions=[page,query] for top queries.
     */
    public static function page_stats(string $json, string $property, int $days = 28): array|WP_Error
    {
        $end   = gmdate('Y-m-d', time() - 2 * DAY_IN_SECONDS); // GSC data lags ~2 days
        $start = gmdate('Y-m-d', time() - (2 + max(1, $days)) * DAY_IN_SECONDS);
        $path  = '/sites/' . rawurlencode($property) . '/searchAnalytics/query';

        $pages = self::api($json, 'POST', $path, array(
            'startDate'  => $start,
            'endDate'    => $end,
            'dimensions' => array('page'),
            'rowLimit'   => 5000,
        ));
        if (is_wp_error($pages)) {
            return $pages;
        }
        $out = array();
        foreach ((array) ($pages['rows'] ?? array()) as $r) {
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
        $queries = self::api($json, 'POST', $path, array(
            'startDate'  => $start,
            'endDate'    => $end,
            'dimensions' => array('page', 'query'),
            'rowLimit'   => 5000,
        ));
        if (!is_wp_error($queries)) { // metrics still useful if the query call fails
            foreach ((array) ($queries['rows'] ?? array()) as $r) {
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
}
