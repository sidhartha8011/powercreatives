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

    /** The generic connector plugin source (pairing-code only; no handshake). */
    private static function connector_php_simple(): string
    {
        return <<<'PHP'
<?php
/**
 * Plugin Name: Power Creatives Connector
 * Description: Connects this site to a Power Creatives hub — exposes SEO meta in REST, renders fallback SEO meta tags when no SEO plugin is active, manages site-wide robots.txt + JSON-LD, serves /llms.txt + /llm-info/, performs builder-aware link replacement (post content + Elementor/Bricks/Divi/WPBakery/Oxygen/Breakdance + any custom field, with cache regeneration + verification), flushes page caches on edit, and shows a one-paste connection code.
 * Version: 2.1.6
 */
if (!defined('ABSPATH')) { exit; }

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
    if (is_string($val)) { $c = 0; $out = str_replace($search, $replace, $val, $c); $count += $c; return $out; }
    if (is_array($val))  { foreach ($val as $k => $v) { $val[$k] = pcm_conn_replace_in($v, $search, $replace, $count); } return $val; }
    if (is_object($val)) { foreach (get_object_vars($val) as $k => $v) { $val->$k = pcm_conn_replace_in($v, $search, $replace, $count); } return $val; }
    return $val;
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
        }
        if (empty($search)) { return $report; }
        global $wpdb;
        // 1. Post content.
        $c = 0; $npc = str_replace($search, $replace, (string) get_post_field('post_content', $post_id), $c);
        if ($c > 0) { wp_update_post(array('ID' => $post_id, 'post_content' => $npc)); $report['replaced'] += $c; $report['where'][] = 'post_content'; $report['steps'][] = 'post_content updated'; }
        // 2. EVERY custom field — serialization-safe (JSON strings, PHP-serialized arrays, objects).
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id));
        foreach ((array) $rows as $row) {
            $raw = (string) $row->meta_value; $hit = false;
            foreach ($search as $s) { if ($s !== '' && strpos($raw, $s) !== false) { $hit = true; break; } }
            if (!$hit) { continue; }
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
        $oe = str_replace('/', '\\/', $old); // escaped-slash variant, in case a meta is a raw JSON string
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $post_id));
        foreach ((array) $rows as $row) {
            $raw = (string) $row->meta_value;
            if (strpos($raw, $el_id) === false) { continue; }                       // element not in this meta
            if (strpos($raw, $old) === false && strpos($raw, $oe) === false) { continue; }
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
}
function pcm_conn_builder_manager() {
    static $mgr = null;
    if ($mgr === null) {
        $mgr = new PCM_Conn_Builder_Manager();
        $mgr->register(new PCM_Conn_B_Elementor())->register(new PCM_Conn_B_Bricks())->register(new PCM_Conn_B_Divi())
            ->register(new PCM_Conn_B_WPBakery())->register(new PCM_Conn_B_Oxygen())->register(new PCM_Conn_B_Breakdance());
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
            'robots' => (string) get_option('pcm_conn_robots', ''),
            'jsonld' => (string) get_option('pcm_conn_jsonld', ''),
        );
    };
    register_rest_route('pcm-conn/v1', '/site', array(
        array('methods' => 'GET', 'permission_callback' => $perm, 'callback' => $read),
        array('methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ($req) use ($read) {
            $p = $req->get_json_params();
            if (is_array($p) && array_key_exists('robots', $p)) { update_option('pcm_conn_robots', (string) $p['robots']); }
            if (is_array($p) && array_key_exists('jsonld', $p)) { update_option('pcm_conn_jsonld', (string) $p['jsonld']); }
            return call_user_func($read);
        }),
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
