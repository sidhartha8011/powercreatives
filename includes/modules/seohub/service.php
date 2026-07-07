<?php
/**
 * SEO Hub Service — tenants, HMAC handshake, connector generation, proxy.
 *
 * Security model (ported from the source plugin's hub):
 *  - Each tenant has a clientId (uuid) + clientSecret (32 random bytes).
 *  - The connector signs its registration: HMAC-SHA256 over
 *    "{timestamp}.{nonce}.{body}" with the clientSecret. The hub verifies the
 *    signature, a ±300s timestamp window, and nonce uniqueness (replay guard).
 *  - After registration the connector hands the hub a WP Application Password,
 *    which the hub uses for Basic-auth REST calls back to the remote site.
 *
 * @package PowerCreatives
 * @since   1.23.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_SEOHub_Service
{
    public const HMAC_WINDOW = 300; // seconds

    // ── HMAC ──

    public static function hmac_sign(string $timestamp, string $nonce, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $secret);
    }

    /**
     * Verify a connector signature. Returns the tenant row on success, or a
     * WP_Error with a specific code on failure.
     *
     * @return object|WP_Error
     */
    public static function hmac_verify(string $client_id, string $timestamp, string $nonce, string $body, string $signature)
    {
        $tenant = self::get_by_client_id($client_id);
        if (!$tenant) {
            return new WP_Error('hmac_unknown_client', 'Unknown client', array('status' => 401));
        }
        if (abs(time() - (int) $timestamp) > self::HMAC_WINDOW) {
            return new WP_Error('hmac_timestamp_expired', 'Timestamp outside window', array('status' => 401));
        }
        if (self::nonce_seen($nonce)) {
            return new WP_Error('hmac_nonce_reused', 'Nonce reused', array('status' => 401));
        }
        $expected = self::hmac_sign($timestamp, $nonce, $body, (string) $tenant->clientSecret);
        if (!hash_equals($expected, $signature)) {
            return new WP_Error('hmac_signature_invalid', 'Bad signature', array('status' => 401));
        }
        self::store_nonce($nonce);
        return $tenant;
    }

    private static function nonce_seen(string $nonce): bool
    {
        global $wpdb;
        $t = PCM_Schema::table('seo_hmac_nonces');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE nonce = %s", $nonce));
    }

    private static function store_nonce(string $nonce): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(PCM_Schema::table('seo_hmac_nonces'), array('nonce' => $nonce));
        // Opportunistic cleanup of nonces older than a day (portable cutoff).
        $cutoff = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare("DELETE FROM " . PCM_Schema::table('seo_hmac_nonces') . " WHERE createdAt < %s", $cutoff));
    }

    // ── Tenant CRUD ──

    public static function format_tenant(object $row, bool $redact = true): array
    {
        $out = array(
            'id'         => (int) $row->id,
            'clientId'   => $row->clientId,
            'name'       => $row->name,
            'domain'     => $row->domain,
            'siteUrl'    => $row->siteUrl,
            'status'     => $row->status,
            'wpVersion'  => $row->wpVersion,
            'phpVersion' => $row->phpVersion,
            'adminEmail' => $row->adminEmail,
            'lastPingAt' => $row->lastPingAt,
            'createdAt'  => $row->createdAt,
        );
        if (!$redact) {
            $out['clientSecret'] = $row->clientSecret;
            $out['appUser']      = $row->appUser;
            $out['appPassword']  = $row->appPassword;
        }
        return $out;
    }

    public static function list_tenants(): array
    {
        global $wpdb;
        $t = PCM_Schema::table('seo_tenants');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results("SELECT * FROM {$t} ORDER BY createdAt DESC");
        return array_map(static fn($r) => self::format_tenant($r, true), $rows ?: array());
    }

    public static function get_by_client_id(string $client_id): ?object
    {
        global $wpdb;
        $t = PCM_Schema::table('seo_tenants');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE clientId = %s", $client_id)) ?: null;
    }

    public static function get_by_id(int $id): ?object
    {
        global $wpdb;
        $t = PCM_Schema::table('seo_tenants');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id)) ?: null;
    }

    public static function create_tenant(string $name, int $created_by = 0): array
    {
        global $wpdb;
        $wpdb->insert(PCM_Schema::table('seo_tenants'), array(
            'createdBy'    => $created_by,
            'clientId'     => wp_generate_uuid4(),
            'clientSecret' => bin2hex(random_bytes(32)),
            'name'         => sanitize_text_field($name),
            'status'       => 'pending',
        ));
        return self::format_tenant(self::get_by_id((int) $wpdb->insert_id), true);
    }

    public static function set_status(int $id, string $status): bool
    {
        if (!in_array($status, array('pending', 'active', 'revoked'), true)) {
            return false;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = (bool) $wpdb->update(PCM_Schema::table('seo_tenants'), array('status' => $status, 'updatedAt' => current_time('mysql')), array('id' => $id));
        // Keep the mirrored publishing site in lockstep (revoke disables it).
        if ($ok && $status === 'revoked') {
            self::cascade_mirrored_site($id, 'revoked');
        }
        return $ok;
    }

    public static function delete_tenant(int $id): bool
    {
        // Remove the mirrored site BEFORE the tenant row (cascade needs its data).
        self::cascade_mirrored_site($id, 'delete');
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->delete(PCM_Schema::table('seo_tenants'), array('id' => $id));
    }

    /** Connector handshake → mark active + store its metadata + app password. */
    public static function register_ping(object $tenant, array $meta): void
    {
        $site_url = esc_url_raw((string) ($meta['site_url'] ?? ''));
        $app_user = sanitize_text_field((string) ($meta['app_user'] ?? ''));
        $app_pass = sanitize_text_field((string) ($meta['app_password'] ?? ''));
        $name     = $tenant->name ?: sanitize_text_field((string) ($meta['site_name'] ?? ''));
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(PCM_Schema::table('seo_tenants'), array(
            'status'      => 'active',
            'siteUrl'     => $site_url,
            'domain'      => (string) wp_parse_url($site_url, PHP_URL_HOST),
            'name'        => $name,
            'adminEmail'  => sanitize_email((string) ($meta['admin_email'] ?? '')),
            'wpVersion'   => sanitize_text_field((string) ($meta['wp_version'] ?? '')),
            'phpVersion'  => sanitize_text_field((string) ($meta['php_version'] ?? '')),
            'appUser'     => $app_user,
            'appPassword' => $app_pass,
            'lastPingAt'  => current_time('mysql'),
            'updatedAt'   => current_time('mysql'),
        ), array('id' => (int) $tenant->id));

        // Mirror the connection into wp_pcm_sites so it's a first-class,
        // publishable site (single source of truth = sites). The connector
        // hands over a WP Application Password; store it ENCRYPTED like a
        // manually-added site (publishing decrypts it).
        self::mirror_to_sites((int) ($tenant->createdBy ?? 0), $name, $site_url, $app_user, $app_pass);
    }

    /**
     * Upsert a connector tenant into wp_pcm_sites (owner-scoped, encrypted).
     * Idempotent: keyed on (owner, url) so a re-ping updates the same row.
     * Skips when we can't form a usable site (no owner / url / credentials).
     */
    private static function mirror_to_sites(int $owner, string $name, string $site_url, string $app_user, string $app_pass): void
    {
        if ($owner <= 0 || $site_url === '' || $app_user === '' || $app_pass === '') {
            return;
        }
        if (!class_exists('PCM_Sites_Service')) {
            require_once dirname(__DIR__) . '/sites/service.php';
        }
        $row = self::mirror_row($owner, $name, $site_url, $app_user, PCM_Sites_Service::encrypt_password($app_pass));
        if ($row === null) {
            return;
        }
        $existing = self::find_mirrored_site($owner, $row['url']);
        if ($existing) {
            $update = $row;
            unset($update['userId']); // userId is the WHERE clause, not a column to set
            PCM_DB::update_site((int) $existing->id, $owner, $update);
        } else {
            PCM_DB::create_site($row);
        }
    }

    /**
     * Pure: the wp_pcm_sites row a connector tenant maps to, or null when it
     * can't be a publishable site (no owner / url / username / password).
     * The actual upsert is DB-bound (see mirror_to_sites) and verified live.
     *
     * @return array<string,mixed>|null
     */
    public static function mirror_row(int $owner, string $name, string $site_url, string $app_user, string $encrypted_pass): ?array
    {
        if ($owner <= 0 || $site_url === '' || $app_user === '' || $encrypted_pass === '') {
            return null;
        }
        $url = rtrim($site_url, '/');
        return array(
            'userId'        => $owner,
            'name'          => $name !== '' ? $name : $url,
            'url'           => $url,
            'username'      => $app_user,
            'appPassword'   => $encrypted_pass,
            'status'        => 'active',
            'connectMethod' => 'connector',
        );
    }

    /** The mirrored sites row for a connector (owner + url), or null. */
    private static function find_mirrored_site(int $owner, string $url): ?object
    {
        if ($owner <= 0 || $url === '') {
            return null;
        }
        global $wpdb;
        $t = PCM_Schema::table('sites');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$t} WHERE userId = %d AND url = %s AND connectMethod = 'connector' LIMIT 1",
            $owner,
            $url
        )) ?: null;
    }

    /** Revoke ('revoked') or delete ('delete') the site mirrored from a tenant. */
    private static function cascade_mirrored_site(int $tenant_id, string $op): void
    {
        $tenant = self::get_by_id($tenant_id);
        if (!$tenant) {
            return;
        }
        $owner = (int) ($tenant->createdBy ?? 0);
        $site  = self::find_mirrored_site($owner, rtrim((string) $tenant->siteUrl, '/'));
        if (!$site) {
            return;
        }
        if ($op === 'delete') {
            PCM_DB::delete_site((int) $site->id, $owner);
        } else {
            PCM_DB::update_site((int) $site->id, $owner, array('status' => $op));
        }
    }

    // ── Remote proxy (Basic auth via Application Password) ──

    public static function remote_get(object $tenant, string $endpoint)
    {
        return self::remote_request('GET', $tenant, $endpoint, null);
    }

    public static function remote_post(object $tenant, string $endpoint, array $body)
    {
        return self::remote_request('POST', $tenant, $endpoint, $body);
    }

    private static function remote_request(string $method, object $tenant, string $endpoint, ?array $body)
    {
        if (empty($tenant->siteUrl) || empty($tenant->appUser) || empty($tenant->appPassword)) {
            return new WP_Error('seohub_not_connected', 'Site not connected');
        }
        $url  = rtrim((string) $tenant->siteUrl, '/') . '/?rest_route=' . rawurlencode($endpoint);
        $args = array(
            'method'  => $method,
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($tenant->appUser . ':' . $tenant->appPassword),
                'Content-Type'  => 'application/json',
            ),
        );
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        return wp_remote_request($url, $args);
    }

    // ── Connector plugin generation (self-contained, secrets baked in) ──

    /**
     * Build the connector plugin ZIP for a tenant and return its absolute path.
     * Regenerated on demand; lives in uploads/pcm-connectors/.
     */
    public static function build_connector_zip(object $tenant): array
    {
        if (!class_exists('ZipArchive')) {
            return array('error' => 'ZipArchive PHP extension is required to build connectors.');
        }
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'pcm-connectors';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        $zip_path = $dir . '/pcm-connector-' . $tenant->clientId . '.zip';

        // The connector pings this URL FROM the remote site, so it must be PUBLICLY
        // reachable. Use the permalink-agnostic `?rest_route=` form: rest_url() returns
        // the pretty `/wp-json/...` path whenever permalinks are "pretty", but that 404s
        // on hosts where the rewrite isn't honored (LiteSpeed/shared) — so the remote's
        // ping never lands and the tenant stays "pending" forever (lastPingAt=null). The
        // query-var form always resolves. Override for a tunnel/reverse-proxy hub via the
        // PCM_SEOHUB_HUB_URL constant or the 'pcm_seohub_connector_hub_url' filter.
        $hub_base = rtrim((defined('PCM_SEOHUB_HUB_URL') && PCM_SEOHUB_HUB_URL) ? (string) PCM_SEOHUB_HUB_URL : home_url('/'), '/');
        $hub_url  = $hub_base . '/?rest_route=/pcm/v1/seohub/connector/hello';
        /** @param string $hub_url Baked connector→hub endpoint. @param object $tenant */
        $hub_url = (string) apply_filters('pcm_seohub_connector_hub_url', $hub_url, $tenant);
        $php = self::connector_php($hub_url, (string) $tenant->clientId, (string) $tenant->clientSecret);

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return array('error' => 'Could not create connector archive.');
        }
        $zip->addFromString('pcm-connector/pcm-connector.php', $php);
        $zip->close();

        return array(
            'path' => $zip_path,
            'url'  => trailingslashit($uploads['baseurl']) . 'pcm-connectors/pcm-connector-' . $tenant->clientId . '.zip',
        );
    }

    /**
     * Build a GENERIC (tenant-free) connector ZIP for the pairing-code flow: it only
     * exposes SEO meta + shows a connection code (no remote->hub handshake at all), so
     * it works on any host and never leaves a "pending" tenant behind.
     */
    public static function build_connector_zip_generic(): array
    {
        if (!class_exists('ZipArchive')) {
            return array('error' => 'ZipArchive PHP extension is required to build connectors.');
        }
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'pcm-connectors';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        $zip_path = $dir . '/pcm-connector.zip';
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return array('error' => 'Could not create connector archive.');
        }
        $zip->addFromString('pcm-connector/pcm-connector.php', self::connector_php_simple());
        $zip->close();
        return array('path' => $zip_path);
    }

    /**
     * The connector version + package for the self-update system. Cached in an option keyed by a
     * hash of the connector SOURCE, so the manifest's sha256 ALWAYS matches the served package
     * byte-for-byte (a mismatch would make every auto-update fail the hash check). Both the
     * manifest and package endpoints read this one artifact.
     *
     * @return array{version:string, sha256:string, zip:string}|array{error:string}
     */
    public static function connector_artifact(): array
    {
        $php = self::connector_php_simple(); // fully baked (hub URL + version)
        $ver = preg_match('/^\s*\*\s*Version:\s*([0-9][0-9.]*)/m', $php, $m) ? $m[1] : '0';
        $sig = md5($php);
        $cache = get_option('pcm_seohub_conn_pkg', array());
        if (is_array($cache) && ($cache['sig'] ?? '') === $sig && !empty($cache['zip_b64'])) {
            return array('version' => $ver, 'sha256' => (string) $cache['sha256'], 'zip' => (string) base64_decode((string) $cache['zip_b64']));
        }
        if (!class_exists('ZipArchive')) {
            return array('error' => 'ZipArchive PHP extension is required to build the connector package.');
        }
        $tmp = wp_tempnam('pcm-conn-pkg');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return array('error' => 'Could not create the connector package.');
        }
        $zip->addFromString('pcm-connector/pcm-connector.php', $php);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        $sha = hash('sha256', $bytes);
        update_option('pcm_seohub_conn_pkg', array('sig' => $sig, 'sha256' => $sha, 'zip_b64' => base64_encode($bytes)), false);
        return array('version' => $ver, 'sha256' => $sha, 'zip' => $bytes);
    }

    /** The generic connector plugin source (pairing-code only; no handshake), with the self-update
     *  placeholders baked to this hub's manifest URL + host + the header version. */
    private static function connector_php_simple(): string
    {
        $php = self::connector_php_simple_raw();
        $manifest = rest_url('pcm/v1/seohub/connector-manifest');
        $scheme   = (string) (wp_parse_url($manifest, PHP_URL_SCHEME) ?: 'https');
        $host     = (string) (wp_parse_url($manifest, PHP_URL_HOST) ?: wp_parse_url(home_url('/'), PHP_URL_HOST));
        $version  = preg_match('/^\s*\*\s*Version:\s*([0-9][0-9.]*)/m', $php, $m) ? $m[1] : '0';
        return strtr($php, array(
            '__PCM_CONN_MANIFEST_URL__' => $manifest,
            '__PCM_CONN_UPDATE_URI__'   => $scheme . '://' . $host . '/pcm-connector',
            '__PCM_CONN_UPDATE_HOST__'  => $host,
            '__PCM_CONN_VERSION__'      => $version,
        ));
    }

    /** Raw connector source with self-update placeholders (baked by connector_php_simple()). */
    private static function connector_php_simple_raw(): string
    {
        return <<<'PHP'
<?php
/**
 * Plugin Name: Power Creatives Connector
 * Description: Connects this site to a Power Creatives hub — exposes SEO meta in REST, renders fallback SEO meta tags when no SEO plugin is active, manages site-wide robots.txt + JSON-LD, serves /llms.txt + /llm-info/, performs builder-aware link + heading replacement (post content + Elementor/Bricks/Divi/WPBakery/Oxygen/Breakdance/Brizy + any custom field, incl. base64-encoded builder data, PLUS Elementor Theme Builder templates + Gutenberg reusable blocks, with cache regeneration + verification), flushes page caches on edit, self-updates from the hub, and shows a one-paste connection code.
 * Version: 2.6.2
 * Update URI: __PCM_CONN_UPDATE_URI__
 */
if (!defined('ABSPATH')) { exit; }

// ── Self-update (WordPress-native) ───────────────────────────────────────────────────────────
// The HUB is the update server: it serves a manifest + the connector zip. WordPress polls the
// manifest twice daily — and on demand via /update-now below — verifies the package sha256, then
// auto-installs. So a connector fix on the hub reaches every connected site with NO manual
// reinstall. (Bootstrap: a site must run a build that already contains THIS block once; from then
// on it updates itself.) Baked at generation time: version, manifest URL, and the hub host.
if (!defined('PCM_CONN_VERSION'))  { define('PCM_CONN_VERSION', '__PCM_CONN_VERSION__'); }
if (!defined('PCM_CONN_MANIFEST')) { define('PCM_CONN_MANIFEST', '__PCM_CONN_MANIFEST_URL__'); }
if (!defined('PCM_CONN_HOST'))     { define('PCM_CONN_HOST', '__PCM_CONN_UPDATE_HOST__'); }
if (!defined('PCM_CONN_FILE'))     { define('PCM_CONN_FILE', plugin_basename(__FILE__)); }

// 1. Point WordPress at the hub's manifest. The filter name is update_plugins_<Update-URI host>.
add_filter('update_plugins___PCM_CONN_UPDATE_HOST__', function ($update, $plugin_data, $plugin_file) {
    if ($plugin_file !== PCM_CONN_FILE) { return $update; }
    $res = wp_remote_get(PCM_CONN_MANIFEST, array('timeout' => 10));
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) { return $update; }
    $info = json_decode((string) wp_remote_retrieve_body($res), true);
    if (!is_array($info) || empty($info['version']) || empty($info['package'])) { return $update; }
    if (version_compare((string) $info['version'], (string) ($plugin_data['Version'] ?? '0'), '<=')) { return $update; }
    update_option('pcm_conn_expected_sha256', isset($info['sha256']) ? (string) $info['sha256'] : '', false);
    return array(
        'slug'         => 'pcm-connector',
        'plugin'       => PCM_CONN_FILE,
        'version'      => (string) $info['version'],
        'url'          => isset($info['url']) ? (string) $info['url'] : '',
        'package'      => (string) $info['package'],
        'requires'     => isset($info['requires']) ? (string) $info['requires'] : '',
        'requires_php' => isset($info['requires_php']) ? (string) $info['requires_php'] : '',
    );
}, 10, 3);

// 2. Verify the downloaded zip's sha256 before install (supply-chain protection). Never skipped.
add_filter('upgrader_pre_download', function ($reply, $package, $upgrader) {
    if (strpos((string) $package, PCM_CONN_HOST) === false) { return $reply; } // not our package
    if (!function_exists('download_url')) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
    $file = download_url((string) $package, 300);
    if (is_wp_error($file)) { return $file; }
    $expected = (string) get_option('pcm_conn_expected_sha256', '');
    if ($expected !== '' && !hash_equals($expected, (string) hash_file('sha256', $file))) {
        @unlink($file);
        return new WP_Error('pcm_conn_bad_hash', 'Connector update package hash mismatch — refusing to install.');
    }
    return $file; // WordPress installs from this verified local file
}, 10, 3);

// 3. Auto-update THIS plugin without human clicks.
add_filter('auto_update_plugin', function ($update, $item) {
    if (isset($item->plugin) && $item->plugin === PCM_CONN_FILE) { return true; }
    return $update;
}, 10, 2);

// Let the hub authenticate FRONT-END page loads via the Application Password, so its
// authenticated page preview renders the WP admin bar. WordPress normally limits
// app-password auth to REST/XML-RPC; this only affects requests that carry a
// Basic-auth header, so normal visitors are unaffected.
add_filter('application_password_is_api_request', '__return_true');

// Expose SEO meta over the standard REST API so the hub can read/write it.
add_action('init', function () {
    $keys = array(
        '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw',
        'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword',
        '_seopress_titles_title', '_seopress_titles_desc', '_seopress_analysis_target_kw',
        'pcm_seo_meta_title', 'pcm_seo_meta_description', 'pcm_seo_primary_keyword', 'pcm_seo_meta_keywords',
        'pcm_seo_supporting_keyword', 'pcm_seo_cluster_label', 'pcm_seo_schema',
    );
    foreach (array('post', 'page') as $type) {
        foreach ($keys as $k) {
            register_post_meta($type, $k, array('show_in_rest' => true, 'single' => true, 'type' => 'string', 'auth_callback' => function () { return current_user_can('edit_posts'); }));
        }
    }
});

// Flush known page + page-builder caches for a post so a change shows on the live page. Many
// cache plugins purge on an editor save but SKIP programmatic/REST saves, so the hub's edit
// persists in the DB (and the SEO scan shows it) while the cached HTML keeps serving the old
// version. This forces the purge + triggers Cloudflare-bridge plugins (Super Page Cache for
// Cloudflare, LiteSpeed, etc.). A bare Cloudflare proxy with NO WordPress integration can't be
// purged from here — install the official Cloudflare plugin or purge the Cloudflare cache manually.
function pcm_conn_purge_caches($post_id) {
    $post_id = (int) $post_id;
    if (function_exists('clean_post_cache'))            { clean_post_cache($post_id); }
    if (function_exists('rocket_clean_post'))           { rocket_clean_post($post_id); }            // WP Rocket
    if (function_exists('w3tc_flush_post'))             { w3tc_flush_post($post_id); }              // W3 Total Cache
    if (function_exists('wp_cache_post_change'))        { wp_cache_post_change($post_id); }         // WP Super Cache
    if (function_exists('wpfc_clear_post_cache_by_id')) { wpfc_clear_post_cache_by_id($post_id); }  // WP Fastest Cache
    do_action('litespeed_purge_post', $post_id);
    do_action('cache_enabler_clear_page_cache_by_post', $post_id);
    do_action('rt_nginx_helper_purge_post', $post_id);
    do_action('breeze_clear_all_cache');
    do_action('siteground_optimizer_flush_cache');
    do_action('swcfpc_purge_cache');           // Super Page Cache for Cloudflare → purges CF edge
    do_action('autoptimize_flush_pagecache');
    // Page builders cache their rendered CSS/HTML — clear it so edited builder data re-renders.
    do_action('elementor/core/files/clear_cache');
    if (class_exists('\\Elementor\\Plugin')) {
        try { \Elementor\Plugin::$instance->files_manager->clear_cache(); } catch (\Throwable $e) {}
    }
    if (class_exists('FLBuilderModel') && method_exists('FLBuilderModel', 'delete_asset_cache')) {
        try { FLBuilderModel::delete_asset_cache($post_id); } catch (\Throwable $e) {}
    }
    // The official Cloudflare plugin auto-purges on post transitions; nothing to call here.
}
// Recursively replace strings inside a meta value (which may be a JSON string, a PHP-serialized
// array, or nested objects — page builders use all three). Counts replacements via $count.
function pcm_conn_replace_in($val, $search, $replace, &$count) {
    if (is_string($val)) {
        $c = 0; $out = str_replace($search, $replace, $val, $c); $count += $c;
        // Builders like Brizy store their page (editor_data) and compiled HTML BASE64-encoded, so a
        // plain string pass can't see the URLs. If nothing matched and this is a base64 blob whose
        // DECODED form carries a search term, replace inside and re-encode. Guarded on a real hit +
        // a valid-UTF-8 decode, so ordinary base64-ish strings (and binary blobs) are never altered.
        if ($c === 0) { $out = pcm_conn_replace_b64($out, $search, $replace, $count); }
        return $out;
    }
    if (is_array($val))  { foreach ($val as $k => $v) { $val[$k] = pcm_conn_replace_in($v, $search, $replace, $count); } return $val; }
    if (is_object($val)) { foreach (get_object_vars($val) as $k => $v) { $val->$k = pcm_conn_replace_in($v, $search, $replace, $count); } return $val; }
    return $val;
}
/** Decode a base64 blob, replace inside, re-encode — only when it cleanly decodes to UTF-8 text
 *  that actually contains a search term. Returns the input unchanged otherwise (never corrupts). */
function pcm_conn_replace_b64($val, $search, $replace, &$count) {
    if (strlen($val) < 24 || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $val)) { return $val; }
    $dec = base64_decode($val, true);
    if ($dec === false || $dec === '' || !preg_match('//u', $dec)) { return $val; }
    $hit = false;
    foreach ($search as $s) { if ($s !== '' && strpos($dec, $s) !== false) { $hit = true; break; } }
    if (!$hit) { return $val; }
    $c = 0; $ndec = str_replace($search, $replace, $dec, $c);
    if ($c === 0) { return $val; }
    $count += $c;
    return base64_encode($ndec);
}
/** True if a search term appears in $raw directly, OR inside a base64-encoded blob within it — so
 *  the replace pass isn't skipped for builders (Brizy) that store their data base64-encoded. */
function pcm_conn_meta_may_contain($raw, $search) {
    foreach ($search as $s) { if ($s !== '' && strpos($raw, $s) !== false) { return true; } }
    if (preg_match_all('/[A-Za-z0-9+\/]{32,}={0,2}/', $raw, $m)) {
        foreach ($m[0] as $blob) {
            $dec = base64_decode($blob, true);
            if ($dec === false || $dec === '') { continue; }
            foreach ($search as $s) { if ($s !== '' && strpos($dec, $s) !== false) { return true; } }
        }
    }
    return false;
}

// ── Universal builder-aware link replacement ────────────────────────────────────────────────
// Pluggable: each handler DETECTS whether its builder owns a post and REGENERATES that builder's
// cache/CSS after an edit. The URL replacement itself is universal (post_content + every custom
// field, serialization-safe via pcm_conn_replace_in) so links update no matter how a builder
// stores them; handlers add accurate detection + cache regeneration for clean, immediate rendering.
interface PCM_Conn_Builder_Handler {
    public function source_keys();        // string[] — meta keys holding this builder's SOURCE data
                                          // (empty = the builder renders from post_content)
    public function key();
    public function label();
    public function detect($post_id);     // bool — does this builder own the post?
    public function regenerate($post_id); // clear/rebuild this builder's cache + CSS
}
abstract class PCM_Conn_Builder_Base implements PCM_Conn_Builder_Handler {
    public function regenerate($post_id) {}
    public function source_keys() { return array(); } // default: builder renders from post_content
    protected function meta_has($post_id, $key) {
        $v = get_post_meta($post_id, $key, true);
        return $v !== '' && $v !== false && $v !== null && $v !== array();
    }
}
class PCM_Conn_B_Elementor extends PCM_Conn_Builder_Base {
    public function key() { return 'elementor'; }
    public function label() { return 'Elementor'; }
    public function source_keys() { return array('_elementor_data'); }
    public function detect($post_id) { return get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder' || $this->meta_has($post_id, '_elementor_data'); }
    public function regenerate($post_id) {
        do_action('elementor/core/files/clear_cache');
        if (class_exists('\\Elementor\\Plugin')) { try { \Elementor\Plugin::$instance->files_manager->clear_cache(); } catch (\Throwable $e) {} }
    }
}
class PCM_Conn_B_Bricks extends PCM_Conn_Builder_Base {
    public function key() { return 'bricks'; }
    public function label() { return 'Bricks'; }
    public function source_keys() { return array('_bricks_page_content_2'); }
    public function detect($post_id) { return get_post_meta($post_id, '_bricks_editor_mode', true) === 'bricks' || $this->meta_has($post_id, '_bricks_page_content_2'); }
    public function regenerate($post_id) {
        delete_post_meta($post_id, '_bricks_inline_css'); // rebuilt on next render
        if (class_exists('\\Bricks\\Assets') && method_exists('\\Bricks\\Assets', 'clear_cache_in_db')) { try { \Bricks\Assets::clear_cache_in_db(); } catch (\Throwable $e) {} }
    }
}
class PCM_Conn_B_Divi extends PCM_Conn_Builder_Base {
    public function key() { return 'divi'; }
    public function label() { return 'Divi'; }
    public function detect($post_id) { return get_post_meta($post_id, '_et_pb_use_builder', true) === 'on' || strpos((string) get_post_field('post_content', $post_id), '[et_pb_') !== false; }
    public function regenerate($post_id) {
        delete_post_meta($post_id, '_et_pb_static_css_file');
        delete_post_meta($post_id, '_et_dynamic_cached_shortcodes');
        delete_post_meta($post_id, '_et_dynamic_cached_attributes');
    }
}
class PCM_Conn_B_WPBakery extends PCM_Conn_Builder_Base {
    public function key() { return 'wpbakery'; }
    public function label() { return 'WPBakery'; }
    public function detect($post_id) { return get_post_meta($post_id, '_wpb_vc_js_status', true) === 'true' || strpos((string) get_post_field('post_content', $post_id), '[vc_row') !== false; }
}
class PCM_Conn_B_Oxygen extends PCM_Conn_Builder_Base {
    public function key() { return 'oxygen'; }
    public function label() { return 'Oxygen'; }
    public function source_keys() { return array('ct_builder_shortcodes'); }
    public function detect($post_id) { return $this->meta_has($post_id, 'ct_builder_shortcodes'); }
    public function regenerate($post_id) {
        delete_post_meta($post_id, 'ct_page_css_cache');
        if (function_exists('oxygen_vsb_cache_universal_css')) { try { oxygen_vsb_cache_universal_css(); } catch (\Throwable $e) {} }
    }
}
class PCM_Conn_B_Breakdance extends PCM_Conn_Builder_Base {
    public function key() { return 'breakdance'; }
    public function label() { return 'Breakdance'; }
    public function source_keys() { return array('_breakdance_data'); }
    public function detect($post_id) { return $this->meta_has($post_id, '_breakdance_data'); }
    public function regenerate($post_id) {
        if (function_exists('__breakdance_clearCachedCssForPost')) { try { __breakdance_clearCachedCssForPost($post_id); } catch (\Throwable $e) {} }
    }
}
class PCM_Conn_B_Brizy extends PCM_Conn_Builder_Base {
    public function key() { return 'brizy'; }
    public function label() { return 'Brizy'; }
    // No source_keys → treated as CONTENT-based, so scan_links walks post_content + ALL meta and the
    // base64-aware collect_links surfaces links from Brizy's base64 compiled HTML / editor JSON.
    public function detect($post_id) {
        return (class_exists('Brizy_Editor_Post') || defined('BRIZY_VERSION'))
            && (get_post_meta($post_id, 'brizy_post_uid', true) !== ''
                || $this->meta_has($post_id, 'brizy-post') || $this->meta_has($post_id, 'brizy'));
    }
    public function regenerate($post_id) {
        // The heading/URL swap itself is generic (editor_data + compiled HTML are base64, decoded/
        // replaced/re-encoded by pcm_conn_replace_in). Brizy still serves a CACHED "compiled HTML"
        // copy, so it must be told to rebuild it from the (updated) editor data — otherwise the live
        // page keeps showing the old text even though the DB is correct.
        // (1) Try Brizy's PHP API.
        if (class_exists('Brizy_Editor_Post')) {
            try {
                $post = get_post($post_id);
                if ($post) {
                    $bp = Brizy_Editor_Post::get($post);
                    if (is_object($bp) && method_exists($bp, 'set_needs_compile')) { $bp->set_needs_compile(true); }
                    if (is_object($bp) && method_exists($bp, 'save')) { $bp->save(); }
                }
            } catch (\Throwable $e) {}
        }
        // (2) Reliable fallback — the API above varies by Brizy version and can silently no-op, so
        // ALSO flip Brizy's own `needs_compile` flag straight in its meta. Brizy then rebuilds the
        // served HTML from the updated editor data on the next view. We ONLY set the recompile flag
        // (never touch editor_data or blank the compiled cache — that could blank the page), so it's
        // safe even if the structure differs.
        foreach (array('brizy-post', 'brizy') as $mk) {
            $val = get_post_meta($post_id, $mk, true);
            if (!is_array($val) && !is_object($val)) { continue; }
            $changed = false;
            $val = self::brizy_set_needs_compile($val, $changed);
            if ($changed) { update_post_meta($post_id, $mk, $val); }
        }
    }
    /** Recursively set any `needs_compile`-style flag in Brizy's stored data to true, so the frontend
     *  rebuilds the served HTML from the (updated) editor data. Touches nothing else. */
    private static function brizy_set_needs_compile($node, &$changed) {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                if (is_string($k) && preg_match('/needs_?compile/i', $k)) {
                    if ($v !== true && $v !== 1 && $v !== '1') { $node[$k] = true; $changed = true; }
                } elseif (is_array($v) || is_object($v)) {
                    $node[$k] = self::brizy_set_needs_compile($v, $changed);
                }
            }
            return $node;
        }
        if (is_object($node)) {
            foreach (get_object_vars($node) as $k => $v) {
                if (is_string($k) && preg_match('/needs_?compile/i', $k)) {
                    if ($v !== true && $v !== 1 && $v !== '1') { $node->$k = true; $changed = true; }
                } elseif (is_array($v) || is_object($v)) {
                    $node->$k = self::brizy_set_needs_compile($v, $changed);
                }
            }
            return $node;
        }
        return $node;
    }
}
class PCM_Conn_Builder_Manager {
    private $handlers = array();
    public function register($h) { $this->handlers[] = $h; return $this; }
    /** Builders that own this post (for reporting + cache regeneration). */
    public function detect($post_id) {
        $out = array();
        foreach ($this->handlers as $h) { try { if ($h->detect($post_id)) { $out[] = $h; } } catch (\Throwable $e) {} }
        return $out;
    }
    /** Replace every old=>new URL across post_content + ALL custom fields (serialization-safe),
     *  regenerate detected builders' caches, purge caches, then VERIFY. Returns a step report. */
    public function replace_links($post_id, $replacements) {
        $report = array('replaced' => 0, 'where' => array(), 'builders' => array(), 'steps' => array(), 'verified' => false, 'remaining' => array());
        $search = array(); $replace = array(); $olds = array();
        foreach ($replacements as $old => $new) {
            $old = (string) $old; $new = (string) $new;
            if ($old === '' || $new === '' || $old === $new) { continue; }
            $olds[] = $old; $search[] = $old; $replace[] = $new;
            $oe = str_replace('/', '\\/', $old);
            if ($oe !== $old) { $search[] = $oe; $replace[] = str_replace('/', '\\/', $new); }
            // Fully JSON-string-escaped variant (escapes " and / and unicode) — needed when the
            // needle is HTML with attributes ('<h2 class="x">…</h2>') living inside a JSON blob,
            // e.g. a heading in Brizy's base64 editor data. For a bare URL this equals $oe, so it's
            // skipped (links unaffected); it only adds a variant when quotes are present.
            $oj = trim((string) json_encode($old), '"'); $nj = trim((string) json_encode($new), '"');
            if ($oj !== $old && $oj !== $oe) { $search[] = $oj; $replace[] = $nj; }
        }
        if (empty($search)) { return $report; }
        global $wpdb;
        // 1. Post content.
        $c = 0; $npc = str_replace($search, $replace, (string) get_post_field('post_content', $post_id), $c);
        if ($c > 0) { wp_update_post(array('ID' => $post_id, 'post_content' => $npc)); $report['replaced'] += $c; $report['where'][] = 'post_content'; $report['steps'][] = 'post_content updated'; }
        // 2. EVERY custom field — serialization-safe (JSON strings, PHP-serialized arrays, objects).
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id));
        foreach ((array) $rows as $row) {
            $raw = (string) $row->meta_value;
            // Skip metas that can't hold the URL — directly OR base64-encoded (Brizy editor_data /
            // compiled HTML). Without the encoded check, Brizy's metas were skipped and never edited.
            if (!pcm_conn_meta_may_contain($raw, $search)) { continue; }
            $cnt = 0; $newVal = pcm_conn_replace_in(maybe_unserialize($raw), $search, $replace, $cnt);
            if ($cnt > 0) {
                // Write the exact value via $wpdb — update_metadata_by_mid()'s slashing differs by WP
                // version (some unslash, some don't), and wp_slash() here double-escaped JSON/serialized
                // builder data, corrupting it. $wpdb stores the value as-is, then we bust the meta cache.
                $store = is_scalar($newVal) ? (string) $newVal : maybe_serialize($newVal);
                $wpdb->update($wpdb->postmeta, array('meta_value' => $store), array('meta_id' => (int) $row->meta_id));
                wp_cache_delete($post_id, 'post_meta');
                $report['replaced'] += $cnt; $report['where'][] = (string) $row->meta_key;
            }
        }
        // 3. Regenerate detected builders' caches/CSS so the edit renders cleanly.
        foreach ($this->detect($post_id) as $h) {
            try { $h->regenerate($post_id); $report['builders'][] = $h->label(); $report['steps'][] = $h->label() . ' regenerated'; }
            catch (\Throwable $e) { $report['steps'][] = $h->label() . ' regenerate failed'; }
        }
        // 4. Purge page caches.
        pcm_conn_purge_caches($post_id); $report['steps'][] = 'caches purged';
        // 5. Verify — no OLD url should remain in content or any meta (read fresh from the DB).
        $blob = (string) get_post_field('post_content', $post_id) . "\n" . implode("\n", (array) $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id)));
        foreach ($olds as $old) {
            $oe = str_replace('/', '\\/', $old);
            if (strpos($blob, $old) !== false || ($oe !== $old && strpos($blob, $oe) !== false)) { $report['remaining'][] = $old; }
        }
        $report['verified'] = empty($report['remaining']);
        if ($report['verified']) { $report['steps'][] = 'verified'; }
        $report['where'] = array_values(array_unique($report['where']));
        return $report;
    }
    /** Element-scoped replace: rewrite $old→$new ONLY inside the builder element $el_id, so editing
     *  one button/link doesn't touch other links that share the URL. Decodes the meta, replaces within
     *  the element subtree, re-encodes, then regenerates builders + purges caches. */
    public function replace_link_in_element($post_id, $el_id, $old, $new) {
        $report = array('replaced' => 0, 'where' => array(), 'builders' => array(), 'steps' => array(), 'verified' => false, 'remaining' => array());
        $el_id = (string) $el_id; $old = (string) $old; $new = (string) $new;
        if ($el_id === '' || $old === '' || $new === '' || $old === $new) { return $report; }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id));
        foreach ((array) $rows as $row) {
            $raw = (string) $row->meta_value;
            if (strpos($raw, $el_id) === false) { continue; }                       // element not in this meta
            // NB: do NOT pre-filter on $old being present in the RAW meta. Builder data JSON-escapes the
            // stored form (quotes as \", slashes as \/, non-ASCII as \uXXXX), so a heading like
            // <h2 class="x">Så här…</h2> never strpos-matches the raw string and the edit would be silently
            // skipped. The el_id already narrows to the right meta; the real match runs on the DECODED
            // structure in replace_in_element (where quotes/slashes/unicode are back to their literal form).
            $val = maybe_unserialize($raw); $is_json = false;
            if (is_string($val) && $val !== '' && ($val[0] === '[' || $val[0] === '{')) {
                $j = json_decode($val, true);
                if (is_array($j)) { $val = $j; $is_json = true; }
            }
            if (!is_array($val) && !is_object($val)) { continue; }                   // can't target inside a plain string safely
            $cnt = 0; $newVal = self::replace_in_element($val, $el_id, $old, $new, false, $cnt);
            if ($cnt > 0) {
                $store = $is_json ? wp_json_encode($newVal) : (is_scalar($newVal) ? (string) $newVal : maybe_serialize($newVal));
                $wpdb->update($wpdb->postmeta, array('meta_value' => $store), array('meta_id' => (int) $row->meta_id));
                wp_cache_delete($post_id, 'post_meta');
                $report['replaced'] += $cnt; $report['where'][] = (string) $row->meta_key;
            }
        }
        foreach ($this->detect($post_id) as $h) {
            try { $h->regenerate($post_id); $report['builders'][] = $h->label(); $report['steps'][] = $h->label() . ' regenerated'; }
            catch (\Throwable $e) { $report['steps'][] = $h->label() . ' regenerate failed'; }
        }
        pcm_conn_purge_caches($post_id); $report['steps'][] = 'caches purged';
        $report['verified'] = $report['replaced'] > 0;
        $report['where'] = array_values(array_unique($report['where']));
        return $report;
    }
    /** Element-scoped ANCHOR (link text) update — the mirror of replace_link_in_element for URLs.
     *  Sets the label of the link inside element $el_id from $old_anchor → $new_anchor: a widget
     *  text/title field whose current text equals $old_anchor, or the inner text of an inline
     *  <a href="$url">…</a>. Builder anchors live in meta, not post_content, so this is how the hub
     *  edits them. */
    public function replace_anchor_in_element($post_id, $el_id, $url, $old_anchor, $new_anchor) {
        $report = array('replaced' => 0, 'where' => array(), 'builders' => array(), 'steps' => array(), 'verified' => false);
        $el_id = (string) $el_id; $url = (string) $url; $old_anchor = (string) $old_anchor;
        $new_anchor = sanitize_text_field((string) $new_anchor); // link text is plain text
        if ($el_id === '' || $old_anchor === '' || $new_anchor === $old_anchor) { return $report; }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id));
        foreach ((array) $rows as $row) {
            $raw = (string) $row->meta_value;
            // Guard on the element id only. Do NOT pre-filter on $old_anchor: the raw JSON escapes
            // non-ASCII (\uXXXX) + slashes (\/), so a decoded anchor like "Learn More →" or "24/7"
            // would never strpos-match the raw string and the edit would falsely report "not found".
            // The decoded label match in set_anchor_in_element does the real matching.
            if (strpos($raw, $el_id) === false) { continue; }
            $val = maybe_unserialize($raw); $is_json = false;
            if (is_string($val) && $val !== '' && ($val[0] === '[' || $val[0] === '{')) {
                $j = json_decode($val, true);
                if (is_array($j)) { $val = $j; $is_json = true; }
            }
            if (!is_array($val) && !is_object($val)) { continue; }
            $cnt = 0; $newVal = self::set_anchor_in_element($val, $el_id, $url, $old_anchor, $new_anchor, false, $cnt);
            if ($cnt > 0) {
                $store = $is_json ? wp_json_encode($newVal) : (is_scalar($newVal) ? (string) $newVal : maybe_serialize($newVal));
                $wpdb->update($wpdb->postmeta, array('meta_value' => $store), array('meta_id' => (int) $row->meta_id));
                wp_cache_delete($post_id, 'post_meta');
                $report['replaced'] += $cnt; $report['where'][] = (string) $row->meta_key;
            }
        }
        foreach ($this->detect($post_id) as $h) {
            try { $h->regenerate($post_id); $report['builders'][] = $h->label(); $report['steps'][] = $h->label() . ' regenerated'; }
            catch (\Throwable $e) { $report['steps'][] = $h->label() . ' regenerate failed'; }
        }
        pcm_conn_purge_caches($post_id); $report['steps'][] = 'caches purged';
        $report['verified'] = $report['replaced'] > 0;
        $report['where'] = array_values(array_unique($report['where']));
        return $report;
    }
    /** Recursively set the anchor text inside the target element: a label field (text/title/…)
     *  whose current text equals $old_anchor, or the inner text of an inline <a href="$url">. */
    private static function set_anchor_in_element($node, $target, $url, $old_anchor, $new_anchor, $inside, &$count) {
        // Any bespoke string field can hold a widget's label (addon widgets use custom keys, e.g.
        // cmsmasters-featured-box), so match by VALUE (=== the old anchor) on non-technical keys —
        // guarded by the element id + the link's URL — rather than an exact key list.
        $skip_keys = array('url', 'id', 'elType', 'widgetType', 'html_tag', 'css_classes');
        if (is_array($node)) {
            $here = $inside || (isset($node['id']) && (string) $node['id'] === (string) $target);
            foreach ($node as $k => $v) {
                if ($here && is_string($v)) {
                    // Only rename a label whose OWN item carries this link's URL — so two same-text
                    // links in one widget (e.g. an icon-list with matching labels, different URLs)
                    // don't both get relabeled. When $url is empty (unknown), fall back to text-only.
                    if (!in_array($k, $skip_keys, true) && strpos((string) $k, '_') !== 0 && trim(wp_strip_all_tags($v)) === $old_anchor && ($url === '' || self::node_has_url($node, $url))) {
                        $node[$k] = $new_anchor; $count++; continue;
                    }
                    if (strpos($v, '<a ') !== false && strpos($v, $old_anchor) !== false) {
                        $rep = self::replace_inline_anchor_text($v, $url, $old_anchor, $new_anchor, $count);
                        if ($rep !== $v) { $node[$k] = $rep; continue; }
                    }
                } elseif (is_array($v) || is_object($v)) {
                    $node[$k] = self::set_anchor_in_element($v, $target, $url, $old_anchor, $new_anchor, $here, $count);
                }
            }
            return $node;
        }
        if (is_object($node)) {
            $here = $inside || (isset($node->id) && (string) $node->id === (string) $target);
            foreach (get_object_vars($node) as $k => $v) {
                if ($here && is_string($v)) {
                    if (!in_array($k, $skip_keys, true) && strpos((string) $k, '_') !== 0 && trim(wp_strip_all_tags($v)) === $old_anchor && ($url === '' || self::node_has_url($node, $url))) { $node->$k = $new_anchor; $count++; continue; }
                    if (strpos($v, '<a ') !== false && strpos($v, $old_anchor) !== false) { $rep = self::replace_inline_anchor_text($v, $url, $old_anchor, $new_anchor, $count); if ($rep !== $v) { $node->$k = $rep; continue; } }
                } elseif (is_array($v) || is_object($v)) { $node->$k = self::set_anchor_in_element($v, $target, $url, $old_anchor, $new_anchor, $here, $count); }
            }
            return $node;
        }
        return $node;
    }
    /** Swap the inner text of <a href="$url">$old</a> → <a …>$new</a> within an HTML string. */
    private static function replace_inline_anchor_text($html, $url, $old_anchor, $new_anchor, &$count) {
        $href = $url !== '' ? preg_quote($url, '#') : '[^"\']*';
        $pattern = '#(<a\b[^>]*href=(["\'])' . $href . '\2[^>]*>)' . preg_quote($old_anchor, '#') . '(</a>)#is';
        $out = preg_replace_callback($pattern, function ($m) use ($new_anchor, &$count) { $count++; return $m[1] . $new_anchor . $m[3]; }, $html);
        return ($out === null) ? $html : $out;
    }
    /** True if $node's subtree carries a link 'url' equal to $url — used to tie a label to its own
     *  link when disambiguating same-text siblings (icon-list rows etc.). */
    private static function node_has_url($node, $url) {
        if ($url === '') { return true; }
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                if ($k === 'url' && is_string($v) && $v === $url) { return true; }
                if ((is_array($v) || is_object($v)) && self::node_has_url($v, $url)) { return true; }
            }
            return false;
        }
        if (is_object($node)) {
            foreach (get_object_vars($node) as $k => $v) {
                if ($k === 'url' && is_string($v) && $v === $url) { return true; }
                if ((is_array($v) || is_object($v)) && self::node_has_url($v, $url)) { return true; }
            }
            return false;
        }
        return false;
    }
    /** Find every link on a post for the hub to show: <a href> in post_content + URLs stored in
     *  builder data (Elementor/Divi/etc. custom fields) that post_content scanning misses. De-duped
     *  by URL (replace-url rewrites every occurrence). Returns [{anchor,to,html,source}]. */
    public function scan_links($post_id) {
        $out = array();
        // When a META-BASED builder owns this post (Elementor/Bricks/Oxygen/Breakdance), the page
        // renders from the builder's SOURCE meta — post_content is dead weight and other metas are
        // render caches. Scanning those produced phantom rows (invisible post_content links, stale
        // cache copies of edited links), so scan ONLY the source keys. Content-based builders
        // (Divi/WPBakery shortcodes) and classic posts keep the full post_content + all-meta scan.
        $source_keys = array();
        foreach ($this->detect($post_id) as $h) {
            foreach ((array) $h->source_keys() as $k) { $source_keys[] = (string) $k; }
        }
        $meta_based = !empty($source_keys);

        if (!$meta_based) {
            $content = (string) get_post_field('post_content', $post_id);
            if (preg_match_all('#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $content, $m, PREG_SET_ORDER)) {
                foreach ($m as $mm) { $out[] = array('anchor' => trim(wp_strip_all_tags($mm[3])), 'to' => $mm[2], 'html' => $mm[0], 'source' => 'content', 'elId' => ''); }
            }
        }
        global $wpdb;
        if ($meta_based) {
            $ph   = implode(',', array_fill(0, count($source_keys), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($ph)", (int) $post_id, ...$source_keys));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", (int) $post_id));
        }
        foreach ((array) $rows as $row) {
            $raw = (string) $row->meta_value;
            $val = maybe_unserialize($raw);
            // Elementor/Bricks store their layout as a JSON STRING (not PHP-serialized) — decode it
            // so link URLs in widget settings are reachable, not buried in an opaque string.
            if (is_string($val) && $val !== '' && ($val[0] === '[' || $val[0] === '{')) {
                $j = json_decode($val, true);
                if (is_array($j)) { $val = $j; }
            }
            self::collect_links($val, $out);
        }
        // Keep EVERY builder link — each is a distinct element the user sees on the page (e.g. three
        // "View Details" buttons that share a URL are three real links, not one). Only drop a
        // post_content link that merely duplicates a builder link: Elementor renders its builder
        // links into the body too, and we don't want both copies of the same one.
        $builder_keys = array();
        foreach ($out as $l) {
            if (($l['source'] ?? '') === 'builder' && (string) $l['to'] !== '') {
                $builder_keys[$l['to'] . '|' . (string) ($l['anchor'] ?? '')] = 1;
            }
        }
        $result = array();
        foreach ($out as $l) {
            if ((string) $l['to'] === '') { continue; }
            if (($l['source'] ?? '') === 'content'
                && isset($builder_keys[$l['to'] . '|' . (string) ($l['anchor'] ?? '')])) {
                continue;
            }
            $result[] = $l;
        }
        return $result;
    }
    /** Recursively pull link URLs out of decoded builder data (arrays / objects / inline HTML). */
    private static function collect_links($val, &$out, $label = '', $el_id = '') {
        if (is_array($val)) {
            // Track the nearest builder ELEMENT id (Elementor/Bricks/etc. elements carry id + elType)
            // so each captured link can be edited in isolation — rewriting just that one element, not
            // every link that happens to share the URL.
            if (isset($val['id'], $val['elType']) && is_string($val['id'])) { $el_id = $val['id']; }
            // A widget's text/title labels its link, which is a nested {url:...} field. Pass the label
            // DOWN so the link inherits it — and capture each link ONCE (no empty-anchor duplicate).
            $lbl = $label;
            foreach (array('text', 'title', 'button_text', 'heading_title', 'label') as $lk) {
                if (!empty($val[$lk]) && is_string($val[$lk])) { $lbl = trim(wp_strip_all_tags($val[$lk])); break; }
            }
            if ($lbl === $label) {
                // Addon widgets (e.g. cmsmasters-featured-box) store their button text under
                // bespoke keys the exact list can't know. Generic fallback: any label-ish key
                // (contains text/title/label/name, minus style-ish keys), preferring button-ish
                // keys, then the SHORTEST value — button labels are short, headings/descriptions long.
                $generic = self::label_from_node($val);
                if ($generic !== '') { $lbl = $generic; }
            }
            if (isset($val['url']) && is_string($val['url']) && preg_match('#^https?://#i', $val['url'])) {
                $out[] = array('anchor' => $lbl, 'to' => $val['url'], 'html' => '', 'source' => 'builder', 'elId' => $el_id);
            }
            foreach ($val as $k => $v) {
                if ($k === 'url') { continue; }
                self::collect_links($v, $out, $lbl, $el_id);
            }
            return;
        }
        if (is_object($val)) { foreach (get_object_vars($val) as $v) { self::collect_links($v, $out, $label, $el_id); } return; }
        if (is_string($val) && stripos($val, '<a ') !== false && preg_match_all('#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $val, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) { if (preg_match('#^https?://#i', $mm[2])) { $out[] = array('anchor' => trim(wp_strip_all_tags($mm[3])), 'to' => $mm[2], 'html' => $mm[0], 'source' => 'builder', 'elId' => $el_id); } }
            return;
        }
        // Builders like Brizy keep their page as a BASE64-encoded blob (compiled HTML + editor JSON),
        // so links are invisible to the plain scan. Decode and recurse: HTML → inline <a> extraction
        // above; JSON → normal array walk (url fields). Guarded on a clean UTF-8 decode.
        if (is_string($val) && strlen($val) >= 24 && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $val)) {
            $dec = base64_decode($val, true);
            if ($dec !== false && $dec !== '' && preg_match('//u', $dec)) {
                $t = ltrim($dec);
                if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
                    $j = json_decode($dec, true);
                    if (is_array($j)) { self::collect_links($j, $out, $label, $el_id); return; }
                }
                if (stripos($dec, '<a ') !== false) { self::collect_links($dec, $out, $label, $el_id); }
            }
        }
    }
    /** Best label among a node's bespoke string fields: keys containing text/title/label/name
     *  (minus style-ish keys), button-ish keys first, then the shortest value. '' when none. */
    private static function label_from_node($val) {
        if (!is_array($val)) { return ''; }
        $button = array(); $plain = array();
        foreach ($val as $k => $v) {
            if (!is_string($v) || !is_string($k)) { continue; }
            $t = trim(wp_strip_all_tags($v));
            if ($t === '' || strlen($t) > 100 || strpos($t, '://') !== false) { continue; }
            $lk = strtolower($k);
            if (!preg_match('/(text|title|label|name|button|btn)/', $lk)) { continue; }
            if (preg_match('/(align|color|size|tag|typography|style|position|transform|decoration|shadow|spacing|gap|weight|family|icon|css|class|hover|animation)/', $lk)) { continue; }
            if (preg_match('/(button|btn)/', $lk)) { $button[] = $t; } else { $plain[] = $t; }
        }
        $pool = !empty($button) ? $button : $plain;
        if (empty($pool)) { return ''; }
        usort($pool, static function ($a, $b) { return strlen($a) <=> strlen($b); });
        return $pool[0];
    }
    /** Replace $old→$new only inside the builder element whose id is $target (and its descendants),
     *  leaving identical links in OTHER elements untouched. Returns the decoded structure + count. */
    private static function replace_in_element($node, $target, $old, $new, $inside, &$count) {
        if (is_array($node)) {
            $here = $inside || (isset($node['id']) && (string) $node['id'] === (string) $target);
            $r = array();
            foreach ($node as $k => $v) {
                if ($here && is_string($v) && strpos($v, $old) !== false) {
                    $c = 0; $v = str_replace($old, $new, $v, $c); $count += $c;
                } elseif (is_array($v) || is_object($v)) {
                    $v = self::replace_in_element($v, $target, $old, $new, $here, $count);
                }
                $r[$k] = $v;
            }
            return $r;
        }
        if (is_object($node)) {
            $here = $inside || (isset($node->id) && (string) $node->id === (string) $target);
            foreach (get_object_vars($node) as $k => $v) {
                if ($here && is_string($v) && strpos($v, $old) !== false) { $c = 0; $node->$k = str_replace($old, $new, $v, $c); $count += $c; }
                elseif (is_array($v) || is_object($v)) { $node->$k = self::replace_in_element($v, $target, $old, $new, $here, $count); }
            }
            return $node;
        }
        return $node;
    }

    // ── Headings (H1–H6) — the SEO table's expandable heading editor ──────────────────────────
    // Meta tag keys page builders use to store a heading's level (Elementor 'header_size',
    // Bricks 'tag', generic 'html_tag'/'tag'/'size'). Used to read + rewrite builder-field headings.
    private static $heading_tag_keys = array('header_size', 'html_tag', 'tag', 'size', 'heading_tag', 'title_tag');
    // Keys that carry a heading's TEXT inside a builder heading widget.
    private static $heading_text_keys = array('title', 'heading', 'heading_title', 'text', 'title_text');

    /** List every heading (H1–H6) on a post for the hub: <hN> in post_content + inline <hN> inside
     *  builder data, PLUS builder heading widgets whose text+level live in separate meta fields
     *  (Elementor/Bricks). Returns [{index,level,text,html,source,elId}] in document order. */
    public function scan_headings($post_id) {
        $out = array();
        $source_keys = array();
        foreach ($this->detect($post_id) as $h) {
            foreach ((array) $h->source_keys() as $k) { $source_keys[] = (string) $k; }
        }
        $meta_based = !empty($source_keys);

        if (!$meta_based) {
            self::collect_headings_html((string) get_post_field('post_content', $post_id), $out, 'content', '');
        }
        global $wpdb;
        if ($meta_based) {
            $ph   = implode(',', array_fill(0, count($source_keys), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($ph)", (int) $post_id, ...$source_keys));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", (int) $post_id));
        }
        foreach ((array) $rows as $row) {
            $raw = (string) $row->meta_value;
            $val = maybe_unserialize($raw);
            if (is_string($val) && $val !== '' && ($val[0] === '[' || $val[0] === '{')) {
                $j = json_decode($val, true);
                if (is_array($j)) { $val = $j; }
            }
            self::collect_headings($val, $out);
        }
        // De-dupe: a builder renders each heading into post_content AND, for base64 builders (Brizy),
        // into BOTH its editor-JSON and compiled-HTML metas — so the same heading is captured several
        // times. Drop (a) a post_content heading that duplicates a builder heading, and (b) repeat
        // builder copies that carry no element id (Brizy's compiled/source pair). Keep every elId'd
        // heading distinct — those are separate on-page elements (e.g. two identical Elementor widgets).
        $builder_keys = array();
        foreach ($out as $h) {
            if (($h['source'] ?? '') !== 'content' && (string) $h['text'] !== '') {
                $builder_keys[$h['level'] . '|' . $h['text']] = 1;
            }
        }
        $result = array();
        $seen_builder = array();
        foreach ($out as $h) {
            if ((string) $h['text'] === '') { continue; }
            $key = $h['level'] . '|' . $h['text'];
            if (($h['source'] ?? '') === 'content' && isset($builder_keys[$key])) { continue; }
            if (($h['source'] ?? '') !== 'content' && (string) ($h['elId'] ?? '') === '') {
                if (isset($seen_builder[$key])) { continue; }
                $seen_builder[$key] = 1;
            }
            $h['index'] = count($result);
            $result[] = $h;
        }
        return $result;
    }
    /** Headings that live in SHARED sources rendered on many pages but NOT stored in the page
     *  itself — Elementor Theme Builder templates (header/footer/single/archive… = elementor_library
     *  posts) and Gutenberg reusable blocks (wp_block). Each is tagged with the OWNING post id
     *  (`sourcePostId`) so the hub routes the edit to that template/block, plus a human `sourceLabel`
     *  + `source` ('template'|'block') so the UI can warn it changes every page using that source.
     *  These are DB-backed and editable; headings truly hardcoded in theme PHP are not returned
     *  (they never reach here → stay read-only on the hub). */
    public function scan_template_headings() {
        $out = array();
        // Only site-wide Elementor LOCATION templates (header/footer/single/archive/…), keyed by TYPE.
        // Anything not in this allow-list — saved sections/pages/popups/global kit, OR an empty/unknown
        // type — is skipped (those don't render site-wide as their own heading source).
        $labels = array(
            'header' => 'Header template', 'footer' => 'Footer template',
            'single' => 'Single template', 'single-post' => 'Single Post template',
            'single-page' => 'Single Page template', 'archive' => 'Archive template',
            'loop-item' => 'Loop Item template', 'error-404' => '404 template',
            'search-results' => 'Search Results template',
        );
        if (post_type_exists('elementor_library')) {
            $tpls = get_posts(array('post_type' => 'elementor_library', 'post_status' => 'publish', 'numberposts' => 100, 'fields' => 'ids', 'suppress_filters' => true));
            foreach ((array) $tpls as $tid) {
                $ttype = (string) get_post_meta($tid, '_elementor_template_type', true);
                if ($ttype === '') { // older/imported saves store the type only in the taxonomy term
                    $terms = function_exists('get_the_terms') ? get_the_terms($tid, 'elementor_library_type') : false;
                    if (is_array($terms) && !empty($terms)) { $ttype = (string) $terms[0]->slug; }
                }
                if (!isset($labels[$ttype])) { continue; } // not a site-wide location template
                $data = get_post_meta($tid, '_elementor_data', true);
                $val  = is_string($data) ? json_decode($data, true) : $data;
                if (!is_array($val)) { continue; }
                $before = count($out);
                self::collect_headings($val, $out);
                $label = $labels[$ttype] . ': ' . get_the_title($tid);
                for ($i = $before, $n = count($out); $i < $n; $i++) {
                    $out[$i]['source']       = 'template';
                    $out[$i]['sourcePostId'] = (int) $tid;
                    $out[$i]['sourceType']   = $ttype;
                    $out[$i]['sourceLabel']  = $label;
                }
            }
        }
        // Gutenberg reusable blocks (wp_block) — one block can appear on many pages.
        if (post_type_exists('wp_block')) {
            $blocks = get_posts(array('post_type' => 'wp_block', 'post_status' => 'publish', 'numberposts' => 200, 'fields' => 'ids', 'suppress_filters' => true));
            foreach ((array) $blocks as $bid) {
                $before = count($out);
                self::collect_headings_html((string) get_post_field('post_content', $bid), $out, 'block', '');
                $label = 'Reusable block: ' . get_the_title($bid);
                for ($i = $before, $n = count($out); $i < $n; $i++) {
                    $out[$i]['source']       = 'block';
                    $out[$i]['sourcePostId'] = (int) $bid;
                    $out[$i]['sourceType']   = 'wp_block';
                    $out[$i]['sourceLabel']  = $label;
                }
            }
        }
        $res = array();
        foreach ($out as $h) { if ((string) ($h['text'] ?? '') !== '') { $res[] = $h; } }
        return $res;
    }
    /** Parse literal <h1>..<h6> tags out of an HTML string into the heading list. */
    private static function collect_headings_html($html, &$out, $source, $el_id) {
        if (!is_string($html) || stripos($html, '<h') === false) { return; }
        if (preg_match_all('#<h([1-6])(\s[^>]*)?>(.*?)</h\1>#is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $text = trim(wp_strip_all_tags($mm[3]));
                if ($text === '') { continue; }
                $out[] = array('level' => (int) $mm[1], 'text' => $text, 'html' => $mm[0], 'source' => $source, 'elId' => $el_id, 'field' => '', 'tagKey' => '', 'textKey' => '');
            }
        }
    }
    /** Recursively pull headings out of decoded builder data: heading WIDGETS (a text field paired
     *  with an h1–h6 tag field) and inline <hN> HTML inside string values. */
    private static function collect_headings($val, &$out, $el_id = '') {
        if (is_array($val)) {
            if (isset($val['id'], $val['elType']) && is_string($val['id'])) { $el_id = $val['id']; }
            // Builder heading WIDGET: a text key + a tag key holding h1–h6.
            $tag = ''; $tag_key = '';
            foreach (self::$heading_tag_keys as $tk) {
                if (isset($val[$tk]) && is_string($val[$tk]) && preg_match('/^h([1-6])$/i', trim($val[$tk]))) { $tag = strtolower(trim($val[$tk])); $tag_key = $tk; break; }
            }
            if ($tag !== '') {
                foreach (self::$heading_text_keys as $xk) {
                    if (!empty($val[$xk]) && is_string($val[$xk])) {
                        $text = trim(wp_strip_all_tags($val[$xk]));
                        if ($text !== '') {
                            $out[] = array('level' => (int) substr($tag, 1), 'text' => $text, 'html' => '', 'source' => 'builder', 'elId' => $el_id, 'field' => 'widget', 'tagKey' => $tag_key, 'textKey' => $xk);
                        }
                        break;
                    }
                }
            }
            foreach ($val as $v) { self::collect_headings($v, $out, $el_id); }
            return;
        }
        if (is_object($val)) { foreach (get_object_vars($val) as $v) { self::collect_headings($v, $out, $el_id); } return; }
        if (is_string($val)) {
            if (stripos($val, '<h') !== false) { self::collect_headings_html($val, $out, 'builder', $el_id); return; }
            // Builders like Brizy keep their page (editor JSON + compiled HTML) as a BASE64 blob, so
            // headings are invisible to the plain scan. Decode and recurse — mirrors collect_links():
            // JSON → array walk; HTML → inline <hN> extraction. Guarded on a clean UTF-8 decode so
            // ordinary base64-ish / binary strings are never misread.
            if (strlen($val) >= 24 && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $val)) {
                $dec = base64_decode($val, true);
                if ($dec !== false && $dec !== '' && preg_match('//u', $dec)) {
                    $t = ltrim($dec);
                    if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
                        $j = json_decode($dec, true);
                        if (is_array($j)) { self::collect_headings($j, $out, $el_id); return; }
                    }
                    if (stripos($dec, '<h') !== false) { self::collect_headings_html($dec, $out, 'builder', $el_id); }
                }
            }
        }
    }
    /** Rewrite a builder-FIELD heading widget (Elementor/Bricks): inside element $el_id, set the text
     *  field (matching $old_text) to $new_text and the tag field to h$new_level. Returns a report. */
    public function replace_heading_field($post_id, $el_id, $old_text, $new_text, $new_level, $text_key, $tag_key) {
        $report = array('replaced' => 0, 'where' => array(), 'builders' => array(), 'steps' => array(), 'verified' => false);
        $el_id = (string) $el_id; $old_text = (string) $old_text;
        $new_text = sanitize_text_field((string) $new_text);
        $new_level = max(1, min(6, (int) $new_level));
        if ($el_id === '' || $old_text === '') { return $report; }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id));
        foreach ((array) $rows as $row) {
            $raw = (string) $row->meta_value;
            if (strpos($raw, $el_id) === false) { continue; }
            $val = maybe_unserialize($raw); $is_json = false;
            if (is_string($val) && $val !== '' && ($val[0] === '[' || $val[0] === '{')) {
                $j = json_decode($val, true);
                if (is_array($j)) { $val = $j; $is_json = true; }
            }
            if (!is_array($val) && !is_object($val)) { continue; }
            $cnt = 0; $newVal = self::set_heading_in_element($val, $el_id, $old_text, $new_text, 'h' . $new_level, (string) $text_key, (string) $tag_key, false, $cnt);
            if ($cnt > 0) {
                $store = $is_json ? wp_json_encode($newVal) : (is_scalar($newVal) ? (string) $newVal : maybe_serialize($newVal));
                $wpdb->update($wpdb->postmeta, array('meta_value' => $store), array('meta_id' => (int) $row->meta_id));
                wp_cache_delete($post_id, 'post_meta');
                $report['replaced'] += $cnt; $report['where'][] = (string) $row->meta_key;
            }
        }
        foreach ($this->detect($post_id) as $h) {
            try { $h->regenerate($post_id); $report['builders'][] = $h->label(); }
            catch (\Throwable $e) {}
        }
        pcm_conn_purge_caches($post_id);
        $report['verified'] = $report['replaced'] > 0;
        $report['where'] = array_values(array_unique($report['where']));
        return $report;
    }
    /** Recursively set a heading widget's text + tag inside the target element. Matches the text field
     *  by value (=== $old_text) among the known text keys, and updates the tag field to $new_tag. */
    private static function set_heading_in_element($node, $target, $old_text, $new_text, $new_tag, $text_key, $tag_key, $inside, &$count) {
        if (is_array($node)) {
            $here = $inside || (isset($node['id']) && (string) $node['id'] === (string) $target);
            if ($here) {
                $keys = ($text_key !== '') ? array($text_key) : self::$heading_text_keys;
                foreach ($keys as $xk) {
                    if (isset($node[$xk]) && is_string($node[$xk]) && trim(wp_strip_all_tags($node[$xk])) === $old_text) {
                        $node[$xk] = $new_text;
                        $tkeys = ($tag_key !== '') ? array($tag_key) : self::$heading_tag_keys;
                        foreach ($tkeys as $tk) {
                            if (isset($node[$tk]) && is_string($node[$tk]) && preg_match('/^h[1-6]$/i', trim($node[$tk]))) { $node[$tk] = $new_tag; break; }
                        }
                        $count++;
                        return $node;
                    }
                }
            }
            foreach ($node as $k => $v) {
                if (is_array($v) || is_object($v)) { $node[$k] = self::set_heading_in_element($v, $target, $old_text, $new_text, $new_tag, $text_key, $tag_key, $here, $count); }
            }
            return $node;
        }
        if (is_object($node)) {
            $here = $inside || (isset($node->id) && (string) $node->id === (string) $target);
            if ($here) {
                $keys = ($text_key !== '') ? array($text_key) : self::$heading_text_keys;
                foreach ($keys as $xk) {
                    if (isset($node->$xk) && is_string($node->$xk) && trim(wp_strip_all_tags($node->$xk)) === $old_text) {
                        $node->$xk = $new_text;
                        $tkeys = ($tag_key !== '') ? array($tag_key) : self::$heading_tag_keys;
                        foreach ($tkeys as $tk) {
                            if (isset($node->$tk) && is_string($node->$tk) && preg_match('/^h[1-6]$/i', trim($node->$tk))) { $node->$tk = $new_tag; break; }
                        }
                        $count++;
                        return $node;
                    }
                }
            }
            foreach (get_object_vars($node) as $k => $v) {
                if (is_array($v) || is_object($v)) { $node->$k = self::set_heading_in_element($v, $target, $old_text, $new_text, $new_tag, $text_key, $tag_key, $here, $count); }
            }
            return $node;
        }
        return $node;
    }
}
function pcm_conn_builder_manager() {
    static $mgr = null;
    if ($mgr === null) {
        $mgr = new PCM_Conn_Builder_Manager();
        $mgr->register(new PCM_Conn_B_Elementor())->register(new PCM_Conn_B_Bricks())->register(new PCM_Conn_B_Divi())
            ->register(new PCM_Conn_B_WPBakery())->register(new PCM_Conn_B_Oxygen())->register(new PCM_Conn_B_Breakdance())
            ->register(new PCM_Conn_B_Brizy());
    }
    return $mgr;
}
add_action('wp_after_insert_post', function ($post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) { return; }
    $post = get_post($post_id);
    if (!$post || !in_array($post->post_type, array('post', 'page'), true)) { return; }
    pcm_conn_purge_caches($post_id);
}, 99);

// Ensure one Application Password exists for the hub + store it for the connection code.
function pcm_conn_ensure() {
    if (get_option('pcm_conn_app_password')) { return; }
    $u = wp_get_current_user();
    if (!$u || !$u->ID || !current_user_can('manage_options') || !class_exists('WP_Application_Passwords')) { return; }
    $c = WP_Application_Passwords::create_new_application_password($u->ID, array('name' => 'Power Creatives Hub'));
    if (!is_wp_error($c)) {
        update_option('pcm_conn_app_password', str_replace(' ', '', $c[0]));
        update_option('pcm_conn_app_user', $u->user_login);
    }
}
register_activation_hook(__FILE__, 'pcm_conn_ensure');
add_action('admin_init', 'pcm_conn_ensure');

// Admin page: shows the single connection code to paste into the hub.
add_action('admin_menu', function () {
    add_menu_page('Power Creatives', 'Power Creatives', 'manage_options', 'pcm-connector', 'pcm_conn_page', 'dashicons-rest-api', 80);
});
function pcm_conn_page() {
    if (!current_user_can('manage_options')) { return; }
    if (isset($_POST['pcm_conn_gen']) && check_admin_referer('pcm_conn_gen')) { delete_option('pcm_conn_app_password'); pcm_conn_ensure(); }
    $pass = (string) get_option('pcm_conn_app_password', '');
    $user = (string) get_option('pcm_conn_app_user', '');
    $code = ($pass && $user) ? base64_encode(wp_json_encode(array('url' => home_url('/'), 'user' => $user, 'pass' => $pass))) : '';
    echo '<div class="wrap"><h1>Power Creatives &mdash; Connection</h1>';
    echo '<p>Copy this code and paste it into your Power Creatives hub at <strong>Sites &rarr; Add Site</strong>.</p>';
    if ($code !== '') {
        echo '<textarea id="pcmcode" readonly rows="4" style="width:100%;max-width:640px;font-family:monospace" onclick="this.select()">' . esc_textarea($code) . '</textarea>';
        echo '<p><button class="button" type="button" onclick="var t=document.getElementById(\'pcmcode\');t.select();document.execCommand(\'copy\');this.textContent=\'Copied!\'">Copy code</button></p>';
    } else {
        echo '<p><em>No code yet &mdash; click below to generate one.</em></p>';
    }
    echo '<form method="post" style="margin-top:1em">';
    wp_nonce_field('pcm_conn_gen');
    echo '<button class="button button-primary" type="submit" name="pcm_conn_gen" value="1">' . ($code !== '' ? 'Regenerate code' : 'Generate code') . '</button>';
    echo '</form></div>';
}

// --- Hub-managed Site SEO: custom robots.txt rules + a site-wide JSON-LD block. ---
add_action('rest_api_init', function () {
    $perm = function () { return current_user_can('manage_options'); };
    $read = function () {
        return array(
            'robots'   => (string) get_option('pcm_conn_robots', ''),
            'jsonld'   => (string) get_option('pcm_conn_jsonld', ''),
            'gscToken' => (string) get_option('pcm_conn_gsc_token', ''),
        );
    };
    register_rest_route('pcm-conn/v1', '/site', array(
        array('methods' => 'GET', 'permission_callback' => $perm, 'callback' => $read),
        array('methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ($req) use ($read) {
            $p = $req->get_json_params();
            if (is_array($p) && array_key_exists('robots', $p)) { update_option('pcm_conn_robots', (string) $p['robots']); }
            if (is_array($p) && array_key_exists('jsonld', $p)) { update_option('pcm_conn_jsonld', (string) $p['jsonld']); }
            // Google Search Console META verification token (content value only) — rendered in
            // wp_head so the hub's add-site auto-verify flow can complete against Google.
            if (is_array($p) && array_key_exists('gscToken', $p)) { update_option('pcm_conn_gsc_token', sanitize_text_field((string) $p['gscToken'])); }
            return call_user_func($read);
        }),
    ));
    // Instant self-update: the hub POSTs here to force an update NOW (bypassing WP's twice-daily
    // poll). Same admin/app-password auth as every other route — the hub already holds that key,
    // so no separate per-site secret is needed. Runs a fresh update check, then upgrades if newer.
    register_rest_route('pcm-conn/v1', '/update-now', array(
        'methods' => 'POST',
        'permission_callback' => $perm,
        'callback' => function () {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/misc.php';
            delete_site_transient('update_plugins'); // bust the 12h cache
            wp_update_plugins();                      // re-check (runs the manifest filter above)
            $u = get_site_transient('update_plugins');
            if (empty($u->response[PCM_CONN_FILE])) {
                return array('status' => 'up-to-date', 'version' => PCM_CONN_VERSION);
            }
            $to = (string) ($u->response[PCM_CONN_FILE]->new_version ?? '');
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            $result   = $upgrader->upgrade(PCM_CONN_FILE);
            if (is_wp_error($result)) { return new WP_Error('pcm_conn_update', $result->get_error_message(), array('status' => 500)); }
            if ($result !== true)     { return new WP_Error('pcm_conn_update', 'Upgrade did not complete.', array('status' => 500)); }
            // The OLD code answers this request; the new version is on disk now.
            return array('status' => 'updated', 'from' => PCM_CONN_VERSION, 'to' => $to);
        },
    ));
    // Builder-aware URL replacement: replace a link's target across post_content AND any page
    // builder's data (Elementor/Bricks/Divi/WPBakery/Oxygen/Breakdance/…), regenerate that
    // builder's cache, purge caches, and verify. Accepts {old,new} and/or a {replacements} map.
    register_rest_route('pcm-conn/v1', '/replace-url', array(
        'methods' => 'POST',
        'permission_callback' => $perm,
        'callback' => function ($req) {
            $p   = $req->get_json_params();
            $pid = is_array($p) && isset($p['post_id']) ? absint($p['post_id']) : 0;
            $replacements = array();
            if (is_array($p)) {
                if (isset($p['old'], $p['new'])) { $replacements[(string) $p['old']] = (string) $p['new']; }
                if (isset($p['replacements']) && is_array($p['replacements'])) {
                    foreach ($p['replacements'] as $o => $n) { $replacements[(string) $o] = (string) $n; }
                }
            }
            if (!$pid || empty($replacements)) { return new WP_REST_Response(array('replaced' => 0, 'error' => 'bad_params'), 400); }
            if (!get_post($pid)) { return new WP_REST_Response(array('error' => 'not_found'), 404); }
            if (!current_user_can('edit_post', $pid)) { return new WP_REST_Response(array('error' => 'forbidden'), 403); }
            // When the hub passes an element id, rewrite ONLY that element so other links sharing the
            // URL stay put. Falls back to the global (every-occurrence) replace when no element id.
            $el_id = (is_array($p) && isset($p['elId'])) ? (string) $p['elId'] : '';
            if ($el_id !== '' && count($replacements) === 1) {
                $old = (string) array_key_first($replacements); $new = (string) $replacements[$old];
                return new WP_REST_Response(pcm_conn_builder_manager()->replace_link_in_element($pid, $el_id, $old, $new), 200);
            }
            return new WP_REST_Response(pcm_conn_builder_manager()->replace_links($pid, $replacements), 200);
        },
    ));
    // Builder-aware ANCHOR (link text) edit — post_content can't reach a builder link's label
    // (it lives in meta), so the hub edits it here by element id.
    register_rest_route('pcm-conn/v1', '/replace-anchor', array(
        'methods' => 'POST',
        'permission_callback' => $perm,
        'callback' => function ($req) {
            $p     = $req->get_json_params();
            $pid   = is_array($p) && isset($p['post_id']) ? absint($p['post_id']) : 0;
            $el_id = (is_array($p) && isset($p['elId'])) ? (string) $p['elId'] : '';
            $url   = (is_array($p) && isset($p['url'])) ? (string) $p['url'] : '';
            $old_a = (is_array($p) && isset($p['oldAnchor'])) ? (string) $p['oldAnchor'] : '';
            $new_a = (is_array($p) && isset($p['newAnchor'])) ? (string) $p['newAnchor'] : '';
            if (!$pid || $el_id === '' || $old_a === '' || $new_a === $old_a) { return new WP_REST_Response(array('replaced' => 0, 'error' => 'bad_params'), 400); }
            if (!get_post($pid)) { return new WP_REST_Response(array('error' => 'not_found'), 404); }
            if (!current_user_can('edit_post', $pid)) { return new WP_REST_Response(array('error' => 'forbidden'), 403); }
            return new WP_REST_Response(pcm_conn_builder_manager()->replace_anchor_in_element($pid, $el_id, $url, $old_a, $new_a), 200);
        },
    ));
    register_rest_route('pcm-conn/v1', '/scan-links', array(
        'methods' => 'GET',
        'permission_callback' => function () { return current_user_can('edit_posts'); },
        'callback' => function ($req) {
            $pid = absint($req->get_param('post_id'));
            if (!$pid || !get_post($pid)) { return new WP_REST_Response(array('error' => 'not_found'), 404); }
            return new WP_REST_Response(array('links' => pcm_conn_builder_manager()->scan_links($pid)), 200);
        },
    ));
    // Builder-aware heading scan: every H1–H6 on the post (post_content + inline <hN> in builder
    // data + builder heading widgets whose text+level live in separate meta fields).
    register_rest_route('pcm-conn/v1', '/scan-headings', array(
        'methods' => 'GET',
        'permission_callback' => function () { return current_user_can('edit_posts'); },
        'callback' => function ($req) {
            $pid = absint($req->get_param('post_id'));
            if (!$pid || !get_post($pid)) { return new WP_REST_Response(array('error' => 'not_found'), 404); }
            $mgr = pcm_conn_builder_manager();
            // Page's own headings first, then shared template/block headings (each tagged with its own
            // sourcePostId so the hub can route edits there). A reusable block inlined into the page is
            // captured by BOTH scans — drop the shared copy when the page already has that heading
            // (keep the page copy so its edit stays local); key by level|text. Re-index the result.
            $page   = $mgr->scan_headings($pid);
            $shared = $mgr->scan_template_headings();
            $page_keys = array();
            foreach ($page as $ph) { $page_keys[$ph['level'] . '|' . $ph['text']] = 1; }
            $headings = $page;
            foreach ($shared as $sh) {
                if (isset($page_keys[$sh['level'] . '|' . $sh['text']])) { continue; }
                $headings[] = $sh;
            }
            $ovr = function_exists('pcm_conn_heading_overrides') ? pcm_conn_heading_overrides() : array();
            // Apply active render-time overrides to the SCANNED (builder/content) headings. A heading
            // edited via the override layer (e.g. a Brizy heading whose stored form the string-replace
            // couldn't reach) still shows its ORIGINAL text in the builder data — collapse it to the
            // overridden value here so it appears ONCE with the current text (tagged 'override', still
            // editable), instead of the stale stored text here + the overridden text from the rendered
            // scan below. Match on the override's ORIGINAL (level|text); re-index-safe.
            if (!empty($ovr)) {
                foreach ($headings as $i => $hh) {
                    foreach ($ovr as $o) {
                        if ((int) ($o['oldLevel'] ?? 0) === (int) $hh['level'] && (string) ($o['oldText'] ?? '') === (string) $hh['text']) {
                            $headings[$i]['text']        = (string) $o['newText'];
                            $headings[$i]['level']       = (int) $o['newLevel'];
                            $headings[$i]['html']        = '';   // stored markup no longer matches; edits route via override
                            $headings[$i]['field']       = '';
                            $headings[$i]['source']      = 'override';
                            $headings[$i]['sourceType']  = 'override';
                            $headings[$i]['sourceLabel'] = 'Site-wide override (render-time)';
                            break;
                        }
                    }
                }
            }
            // Headings that exist ONLY in the RENDERED page (theme PHP, menus, widget titles — no DB
            // source anywhere) become editable through the render-time override layer (see
            // /override-heading below). Loopback-fetch the live page; overrides already apply on that
            // request, so an overridden heading shows its CURRENT text and stays re-editable. If the
            // host blocks loopback requests this scan is skipped and behaviour is unchanged.
            $seen_all = array();
            foreach ($headings as $hh) { $seen_all[$hh['level'] . '|' . $hh['text']] = 1; }
            // Hardened loopback: a real browser UA (security plugins/WAFs serve a near-empty
            // challenge page to unknown agents → the scan would see "only a few"), a cache-busting
            // query arg (skip a stale full-page cache that predates recent edits), redirect follow,
            // and a longer timeout for heavy builder pages. Add ?pcm_hscan so page caches treat it
            // as a distinct URL; strip nothing else. `blocking` GET so we actually read the body.
            $scan_url = add_query_arg('pcm_hscan', (string) time(), get_permalink($pid));
            $resp = wp_remote_get($scan_url, array(
                'timeout'     => 20,
                'redirection' => 3,
                'sslverify'   => apply_filters('https_local_ssl_verify', false),
                'user-agent'  => 'Mozilla/5.0 (compatible; PowerCreativesConnector/2.6; +heading-scan)',
                'headers'     => array('Accept' => 'text/html', 'Cache-Control' => 'no-cache'),
            ));
            if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
                $live = (string) wp_remote_retrieve_body($resp);
                // Only scan the <body> (drop <head>: <title>, OG/twitter meta, JSON-LD headline
                // strings never contain <hN>, but a defensive trim keeps the match set page-visible).
                if (($bpos = stripos($live, '<body')) !== false) { $live = substr($live, $bpos); }
                if (preg_match_all('#<h([1-6])(\s[^>]*)?>(.*?)</h\1>#is', $live, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $hm) {
                        $lvl = (int) $hm[1];
                        $txt = trim(wp_strip_all_tags($hm[3]));
                        $key = $lvl . '|' . $txt;
                        if ($txt === '' || isset($seen_all[$key])) { continue; }
                        $seen_all[$key] = 1;
                        $is_ovr = false;
                        foreach ($ovr as $o) {
                            if ((int) ($o['newLevel'] ?? 0) === $lvl && (string) ($o['newText'] ?? '') === $txt) { $is_ovr = true; break; }
                        }
                        $headings[] = array(
                            'level' => $lvl, 'text' => $txt, 'html' => $hm[0],
                            'source' => $is_ovr ? 'override' : 'rendered',
                            'elId' => '', 'field' => '', 'tagKey' => '', 'textKey' => '',
                            'sourcePostId' => 0,
                            'sourceType'   => $is_ovr ? 'override' : 'rendered',
                            'sourceLabel'  => $is_ovr ? 'Site-wide override (render-time)' : 'Theme / hardcoded (render-time override)',
                        );
                    }
                }
            }
            foreach ($headings as $i => $unused) { $headings[$i]['index'] = $i; }
            return new WP_REST_Response(array('headings' => $headings), 200);
        },
    ));
    // Builder-aware heading edit for builder-FIELD headings (Elementor/Bricks heading widgets store
    // text + level in separate meta fields — post_content replace can't reach them). Content-stored
    // and inline-HTML headings are edited by the hub via /replace-url (oldHtml → newHtml) instead.
    register_rest_route('pcm-conn/v1', '/replace-heading', array(
        'methods' => 'POST',
        'permission_callback' => $perm,
        'callback' => function ($req) {
            $p       = $req->get_json_params();
            $pid     = is_array($p) && isset($p['post_id']) ? absint($p['post_id']) : 0;
            $el_id   = (is_array($p) && isset($p['elId'])) ? (string) $p['elId'] : '';
            $old_t   = (is_array($p) && isset($p['oldText'])) ? (string) $p['oldText'] : '';
            $new_t   = (is_array($p) && isset($p['newText'])) ? (string) $p['newText'] : '';
            $level   = (is_array($p) && isset($p['newLevel'])) ? absint($p['newLevel']) : 0;
            $text_key = (is_array($p) && isset($p['textKey'])) ? (string) $p['textKey'] : '';
            $tag_key  = (is_array($p) && isset($p['tagKey'])) ? (string) $p['tagKey'] : '';
            if (!$pid || $el_id === '' || $old_t === '' || $level < 1 || $level > 6) { return new WP_REST_Response(array('replaced' => 0, 'error' => 'bad_params'), 400); }
            if (!get_post($pid)) { return new WP_REST_Response(array('error' => 'not_found'), 404); }
            if (!current_user_can('edit_post', $pid)) { return new WP_REST_Response(array('error' => 'forbidden'), 403); }
            return new WP_REST_Response(pcm_conn_builder_manager()->replace_heading_field($pid, $el_id, $old_t, ($new_t !== '' ? $new_t : $old_t), $level, $text_key, $tag_key), 200);
        },
    ));
});
add_filter('robots_txt', function ($output) {
    $extra = trim((string) get_option('pcm_conn_robots', ''));
    return $extra !== '' ? rtrim($output) . "\n" . $extra . "\n" : $output;
}, 20);
add_action('wp_head', function () {
    $jsonld = trim((string) get_option('pcm_conn_jsonld', ''));
    if ($jsonld === '') { return; }
    $decoded = json_decode($jsonld, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) { return; }
    echo "\n" . '<script type="application/ld+json">' . wp_json_encode($decoded) . '</script>' . "\n";
}, 20);
// Google Search Console META site-verification tag (token pushed by the hub via POST /site).
add_action('wp_head', function () {
    $tok = trim((string) get_option('pcm_conn_gsc_token', ''));
    if ($tok === '') { return; }
    echo '<meta name="google-site-verification" content="' . esc_attr($tok) . '" />' . "\n";
}, 1);

// --- Render-time heading overrides: edit headings that have NO database source (hardcoded in
// theme PHP, nav menus, widget titles). The hub stores {original → new} text+level pairs and every
// frontend render rewrites matching <hN> elements in the output buffer. Site-wide BY DESIGN — a
// theme heading renders identically on every page, so the edit follows it everywhere (the hub UI
// badge warns about the scope). Entries are keyed by the ORIGINAL text so repeated edits update in
// place, and an edit back to the original value deletes its entry (clean revert, no dead rules).
function pcm_conn_heading_overrides() {
    $o = get_option('pcm_conn_heading_overrides', array());
    return is_array($o) ? array_values(array_filter($o, 'is_array')) : array();
}
add_action('rest_api_init', function () {
    register_rest_route('pcm-conn/v1', '/override-heading', array(
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function ($req) {
            $p     = $req->get_json_params();
            $old_t = is_array($p) ? trim((string) ($p['oldText'] ?? '')) : '';
            $new_t = is_array($p) ? trim((string) ($p['newText'] ?? '')) : '';
            $old_l = is_array($p) ? absint($p['oldLevel'] ?? 0) : 0;
            $new_l = is_array($p) ? absint($p['newLevel'] ?? 0) : 0;
            if ($old_t === '' || $old_l < 1 || $old_l > 6 || $new_l < 1 || $new_l > 6) {
                return new WP_REST_Response(array('replaced' => 0, 'error' => 'bad_params'), 400);
            }
            if ($new_t === '') { $new_t = $old_t; }
            $list = pcm_conn_heading_overrides();
            $done = false;
            foreach ($list as $i => $o) {
                // Re-edit of an already-overridden heading: the incoming "old" is that entry's
                // CURRENT value (what the user sees). Keep the original match, update the target.
                if ((string) ($o['newText'] ?? '') === $old_t && (int) ($o['newLevel'] ?? 0) === $old_l) {
                    $list[$i]['newText'] = $new_t; $list[$i]['newLevel'] = $new_l; $done = true; break;
                }
                // Same ORIGINAL edited again (stale scan / cached page raced the last edit).
                if ((string) ($o['oldText'] ?? '') === $old_t && (int) ($o['oldLevel'] ?? 0) === $old_l) {
                    $list[$i]['newText'] = $new_t; $list[$i]['newLevel'] = $new_l; $done = true; break;
                }
            }
            if (!$done) {
                $list[] = array('oldText' => $old_t, 'oldLevel' => $old_l, 'newText' => $new_t, 'newLevel' => $new_l);
            }
            // An override whose target equals its original is a no-op → drop (revert support).
            $list = array_values(array_filter($list, function ($o) {
                return (string) ($o['oldText'] ?? '') !== (string) ($o['newText'] ?? '')
                    || (int) ($o['oldLevel'] ?? 0) !== (int) ($o['newLevel'] ?? 0);
            }));
            if (count($list) > 200) { $list = array_slice($list, -200); } // runaway-list backstop
            update_option('pcm_conn_heading_overrides', $list, true);
            $pid = is_array($p) ? absint($p['post_id'] ?? 0) : 0;
            if ($pid) { pcm_conn_purge_caches($pid); }
            return new WP_REST_Response(array('replaced' => 1, 'via' => 'override', 'count' => count($list)), 200);
        },
    ));
});
add_action('template_redirect', function () {
    if (is_admin() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) { return; }
    $list = pcm_conn_heading_overrides();
    if (empty($list)) { return; }
    ob_start(function ($html) use ($list) {
        foreach ($list as $o) {
            $ol = max(1, min(6, (int) ($o['oldLevel'] ?? 0)));
            $nl = max(1, min(6, (int) ($o['newLevel'] ?? 0)));
            $ot = (string) ($o['oldText'] ?? '');
            $nt = (string) ($o['newText'] ?? '');
            if ($ot === '') { continue; }
            $out = preg_replace_callback(
                '#<h' . $ol . '(\s[^>]*)?>(.*?)</h' . $ol . '>#is',
                function ($m) use ($nl, $ot, $nt) {
                    // Match on the VISIBLE text (inner markup stripped) so attributes/spans don't block it.
                    if (trim(wp_strip_all_tags($m[2])) !== $ot) { return $m[0]; }
                    return '<h' . $nl . (isset($m[1]) ? $m[1] : '') . '>' . esc_html($nt) . '</h' . $nl . '>';
                },
                $html
            );
            if (is_string($out)) { $html = $out; } // PCRE failure → leave the page untouched
        }
        return $html;
    });
}, 1);

// --- Hub-managed AI Readiness: store + serve a virtual /llms.txt index. ---
add_action('rest_api_init', function () {
    $perm = function () { return current_user_can('manage_options'); };
    $read = function () {
        return array(
            'llms'    => (string) get_option('pcm_conn_llms', ''),
            'enabled' => get_option('pcm_conn_llms_enabled', '0') === '1',
            'url'     => home_url('/llms.txt'),
        );
    };
    register_rest_route('pcm-conn/v1', '/ai', array(
        array('methods' => 'GET', 'permission_callback' => $perm, 'callback' => $read),
        array('methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ($req) use ($read) {
            $p = $req->get_json_params();
            if (is_array($p) && array_key_exists('llms', $p)) { update_option('pcm_conn_llms', (string) $p['llms']); }
            if (is_array($p) && array_key_exists('enabled', $p)) { update_option('pcm_conn_llms_enabled', !empty($p['enabled']) ? '1' : '0'); }
            return call_user_func($read);
        }),
    ));
});
add_action('template_redirect', function () {
    $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if ($path !== 'llms.txt' || get_option('pcm_conn_llms_enabled', '0') !== '1') { return; }
    $c = (string) get_option('pcm_conn_llms', '');
    if ($c === '') { return; }
    header('Content-Type: text/plain; charset=utf-8');
    echo $c;
    exit;
});

// Serve a per-page Markdown rendition at /{slug}.md when AI Readiness is enabled.
function pcm_conn_html_to_md($html) {
    $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', (string) $html);
    for ($i = 1; $i <= 6; $i++) {
        $html = preg_replace('#<h' . $i . '[^>]*>(.*?)</h' . $i . '>#is', "\n" . str_repeat('#', $i) . ' $1' . "\n", $html);
    }
    $html = preg_replace('#<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', '[$2]($1)', $html);
    $html = preg_replace('#<(strong|b)[^>]*>(.*?)</\1>#is', '**$2**', $html);
    $html = preg_replace('#<(em|i)[^>]*>(.*?)</\1>#is', '*$2*', $html);
    $html = preg_replace('#<li[^>]*>(.*?)</li>#is', "- $1\n", $html);
    $html = preg_replace('#</p>#i', "\n\n", $html);
    $html = preg_replace('#<br\s*/?>#i', "\n", $html);
    $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", $text));
}
add_action('template_redirect', function () {
    $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if (substr($path, -3) !== '.md' || get_option('pcm_conn_llms_enabled', '0') !== '1') { return; }
    $page = get_page_by_path(substr($path, 0, -3), OBJECT, array('post', 'page'));
    if (!$page || $page->post_status !== 'publish') { status_header(404); return; }
    header('Content-Type: text/markdown; charset=utf-8');
    echo '# ' . $page->post_title . "\n\n" . pcm_conn_html_to_md(strip_shortcodes($page->post_content));
    exit;
});

// --- Hub-managed /llm-info/ — an AI-search-optimized business overview (HTML), generated by the hub. ---
add_action('rest_api_init', function () {
    $perm = function () { return current_user_can('manage_options'); };
    $read = function () {
        return array(
            'content' => (string) get_option('pcm_conn_llminfo', ''),
            'enabled' => get_option('pcm_conn_llminfo_enabled', '0') === '1',
            'url'     => home_url('/llm-info/'),
        );
    };
    register_rest_route('pcm-conn/v1', '/llm-info', array(
        array('methods' => 'GET', 'permission_callback' => $perm, 'callback' => $read),
        array('methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ($req) use ($read) {
            $p = $req->get_json_params();
            if (is_array($p) && array_key_exists('content', $p)) { update_option('pcm_conn_llminfo', wp_kses_post((string) $p['content'])); }
            if (is_array($p) && array_key_exists('enabled', $p)) { update_option('pcm_conn_llminfo_enabled', !empty($p['enabled']) ? '1' : '0'); }
            return call_user_func($read);
        }),
    ));
});
add_action('template_redirect', function () {
    $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if ($path !== 'llm-info' || get_option('pcm_conn_llminfo_enabled', '0') !== '1') { return; }
    $c = (string) get_option('pcm_conn_llminfo', '');
    if ($c === '') { return; }
    $name = get_bloginfo('name');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html($name) . ' &mdash; Overview</title><meta name="robots" content="index,follow"></head><body><main style="max-width:760px;margin:2rem auto;padding:0 1rem;font-family:system-ui,-apple-system,sans-serif;line-height:1.6">' . $c . '</main></body></html>';
    exit;
});

// --- Fallback SEO meta: render <title> + meta description/keywords from the pcm_seo_*
// meta on singular pages, ONLY when no SEO plugin (Yoast / Rank Math / SEOPress) is
// handling the page (so we never double-output). ---
function pcm_conn_seo_plugin_active() {
    return defined('WPSEO_VERSION') || class_exists('WPSEO_Options')
        || class_exists('RankMath') || defined('RANK_MATH_VERSION')
        || function_exists('seopress_init') || defined('SEOPRESS_VERSION');
}
add_filter('pre_get_document_title', function ($title) {
    if (pcm_conn_seo_plugin_active() || !is_singular()) { return $title; }
    $t = trim((string) get_post_meta(get_queried_object_id(), 'pcm_seo_meta_title', true));
    return $t !== '' ? $t : $title;
}, 99);
add_action('wp_head', function () {
    if (pcm_conn_seo_plugin_active() || !is_singular()) { return; }
    $id   = get_queried_object_id();
    $desc = trim((string) get_post_meta($id, 'pcm_seo_meta_description', true));
    $kw   = trim((string) get_post_meta($id, 'pcm_seo_meta_keywords', true));
    if ($desc !== '') { echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n"; }
    if ($kw !== '')   { echo '<meta name="keywords" content="' . esc_attr($kw) . '">' . "\n"; }
}, 1);
PHP;
    }

    /** The single-file connector plugin source (placeholders baked at build). */
    private static function connector_php(string $hub_url, string $client_id, string $secret): string
    {
        $tpl = <<<'PHP'
<?php
/**
 * Plugin Name: Power Creatives Connector
 * Description: Connects this site to a Power Creatives SEO Hub.
 * Version: 1.0.1
 */
if (!defined('ABSPATH')) { exit; }

// Let the hub authenticate FRONT-END page loads via the Application Password, so its
// authenticated page preview renders the WP admin bar. WordPress normally limits
// app-password auth to REST/XML-RPC; this only affects requests that carry a
// Basic-auth header, so normal visitors are unaffected.
add_filter('application_password_is_api_request', '__return_true');

define('PCM_CONN_HUB_URL', '__HUB_URL__');
define('PCM_CONN_CLIENT_ID', '__CLIENT_ID__');
define('PCM_CONN_CLIENT_SECRET', '__CLIENT_SECRET__');

// Register with the hub via an HMAC-signed handshake (creates an Application Password for
// the current admin + pings the hub). Runs on activation AND, as a self-heal, on each admin
// load until registered — so a plugin UPDATE/replace (which does NOT fire the activation
// hook) or a transient network blip still completes the handshake. Capped to avoid creating
// endless app passwords if the hub is permanently unreachable; outcome -> pcm_conn_status.
function pcm_conn_register() {
    if (get_option('pcm_conn_status') === 'registered') { return; }
    $user = wp_get_current_user();
    if (!$user || !$user->ID || !current_user_can('manage_options')) { return; }
    $attempts = (int) get_option('pcm_conn_attempts', 0);
    if ($attempts >= 6) { return; }
    update_option('pcm_conn_attempts', $attempts + 1);
    $app = null;
    if (class_exists('WP_Application_Passwords')) {
        $created = WP_Application_Passwords::create_new_application_password($user->ID, array('name' => 'Power Creatives Hub'));
        if (!is_wp_error($created)) { $app = $created[0]; }
    }
    if ($app) {
        update_option('pcm_conn_app_password', str_replace(' ', '', $app));
        update_option('pcm_conn_app_user', $user->user_login);
    }
    $body = wp_json_encode(array(
        'site_url'    => home_url('/'),
        'site_name'   => get_bloginfo('name'),
        'admin_email' => get_option('admin_email'),
        'wp_version'  => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'app_user'    => $user->user_login,
        'app_password'=> $app ? str_replace(' ', '', $app) : '',
    ));
    $ts = (string) time();
    $nonce = wp_generate_uuid4();
    $sig = hash_hmac('sha256', $ts . '.' . $nonce . '.' . $body, PCM_CONN_CLIENT_SECRET);
    $args = array(
        'timeout' => 20,
        'headers' => array(
            'Content-Type'     => 'application/json',
            'X-Hub-Client-Id'  => PCM_CONN_CLIENT_ID,
            'X-Hub-Timestamp'  => $ts,
            'X-Hub-Nonce'      => $nonce,
            'X-Hub-Signature'  => $sig,
        ),
        'body' => $body,
    );
    // Try the baked URL, then BOTH permalink forms, so the handshake lands whether or not
    // the hub serves pretty /wp-json/ (LiteSpeed/shared hosting often only serves the
    // ?rest_route= form). Record the outcome in pcm_conn_status for debugging.
    $candidates = array(PCM_CONN_HUB_URL);
    $p = wp_parse_url(PCM_CONN_HUB_URL);
    if ($p && !empty($p['scheme']) && !empty($p['host'])) {
        $origin = $p['scheme'] . '://' . $p['host'] . (empty($p['port']) ? '' : ':' . $p['port']);
        foreach (array($origin . '/?rest_route=/pcm/v1/seohub/connector/hello', $origin . '/wp-json/pcm/v1/seohub/connector/hello') as $alt) {
            if (!in_array($alt, $candidates, true)) { $candidates[] = $alt; }
        }
    }
    $status = 'failed';
    foreach ($candidates as $u) {
        $resp = wp_remote_post($u, $args);
        $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
        if ($code >= 200 && $code < 300) { $status = 'registered'; break; }
        $status = 'failed:' . $code;
    }
    update_option('pcm_conn_status', $status);
}
register_activation_hook(__FILE__, 'pcm_conn_register');
add_action('admin_init', 'pcm_conn_register');

// Expose SEO meta over the standard REST API so the hub can read/write it.
add_action('init', function () {
    $keys = array(
        '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw',
        'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword',
        '_seopress_titles_title', '_seopress_titles_desc', '_seopress_analysis_target_kw',
        'pcm_seo_meta_title', 'pcm_seo_meta_description', 'pcm_seo_primary_keyword', 'pcm_seo_meta_keywords',
        'pcm_seo_supporting_keyword', 'pcm_seo_cluster_label', 'pcm_seo_schema',
    );
    foreach (array('post', 'page') as $type) {
        foreach ($keys as $k) {
            register_post_meta($type, $k, array('show_in_rest' => true, 'single' => true, 'type' => 'string', 'auth_callback' => function () { return current_user_can('edit_posts'); }));
        }
    }
});

// Admin page: shows a single "connection code" (this site URL + a WP username + an
// Application Password). Paste it into the Power Creatives hub and it connects OUTBOUND
// with those credentials — reliable on any host, no remote->hub handshake required.
add_action('admin_menu', function () {
    add_menu_page('Power Creatives', 'Power Creatives', 'manage_options', 'pcm-connector', 'pcm_conn_page', 'dashicons-rest-api', 80);
});
function pcm_conn_page() {
    if (!current_user_can('manage_options')) { return; }
    if (isset($_POST['pcm_conn_gen']) && check_admin_referer('pcm_conn_gen')) {
        $u = wp_get_current_user();
        if (class_exists('WP_Application_Passwords')) {
            $c = WP_Application_Passwords::create_new_application_password($u->ID, array('name' => 'Power Creatives Hub'));
            if (!is_wp_error($c)) {
                update_option('pcm_conn_app_password', str_replace(' ', '', $c[0]));
                update_option('pcm_conn_app_user', $u->user_login);
            }
        }
    }
    $pass = (string) get_option('pcm_conn_app_password', '');
    $user = (string) get_option('pcm_conn_app_user', '');
    $code = ($pass && $user) ? base64_encode(wp_json_encode(array('url' => home_url('/'), 'user' => $user, 'pass' => $pass))) : '';
    echo '<div class="wrap"><h1>Power Creatives &mdash; Connection</h1>';
    echo '<p>Copy this code and paste it into your Power Creatives hub at <strong>Sites &rarr; Add Site &rarr; Paste connection code</strong>.</p>';
    if ($code !== '') {
        echo '<textarea id="pcmcode" readonly rows="4" style="width:100%;max-width:640px;font-family:monospace" onclick="this.select()">' . esc_textarea($code) . '</textarea>';
        echo '<p><button class="button" type="button" onclick="var t=document.getElementById(\'pcmcode\');t.select();document.execCommand(\'copy\');this.textContent=\'Copied!\'">Copy code</button></p>';
    } else {
        echo '<p><em>No code yet &mdash; click below to generate one.</em></p>';
    }
    echo '<form method="post" style="margin-top:1em">';
    wp_nonce_field('pcm_conn_gen');
    echo '<button class="button button-primary" type="submit" name="pcm_conn_gen" value="1">' . ($code !== '' ? 'Regenerate code' : 'Generate code') . '</button>';
    echo '</form></div>';
}
PHP;
        return str_replace(
            array('__HUB_URL__', '__CLIENT_ID__', '__CLIENT_SECRET__'),
            array($hub_url, $client_id, $secret),
            $tpl
        );
    }
}
