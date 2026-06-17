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

        $hub_url = rest_url('pcm/v1/seohub/connector/hello');
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

    /** The single-file connector plugin source (placeholders baked at build). */
    private static function connector_php(string $hub_url, string $client_id, string $secret): string
    {
        $tpl = <<<'PHP'
<?php
/**
 * Plugin Name: Power Creatives Connector
 * Description: Connects this site to a Power Creatives SEO Hub.
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) { exit; }

define('PCM_CONN_HUB_URL', '__HUB_URL__');
define('PCM_CONN_CLIENT_ID', '__CLIENT_ID__');
define('PCM_CONN_CLIENT_SECRET', '__CLIENT_SECRET__');

// On activation: create an Application Password for the current admin and
// register with the hub via an HMAC-signed handshake.
register_activation_hook(__FILE__, function () {
    $user = wp_get_current_user();
    if (!$user || !$user->ID) { return; }
    $app = null;
    if (class_exists('WP_Application_Passwords')) {
        $created = WP_Application_Passwords::create_new_application_password($user->ID, array('name' => 'Power Creatives Hub'));
        if (!is_wp_error($created)) { $app = $created[0]; }
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
    wp_remote_post(PCM_CONN_HUB_URL, array(
        'timeout' => 20,
        'headers' => array(
            'Content-Type'     => 'application/json',
            'X-Hub-Client-Id'  => PCM_CONN_CLIENT_ID,
            'X-Hub-Timestamp'  => $ts,
            'X-Hub-Nonce'      => $nonce,
            'X-Hub-Signature'  => $sig,
        ),
        'body' => $body,
    ));
    update_option('pcm_conn_status', 'registered');
});

// Expose SEO meta over the standard REST API so the hub can read/write it.
add_action('init', function () {
    $keys = array(
        '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw',
        'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword',
        '_seopress_titles_title', '_seopress_titles_desc', '_seopress_analysis_target_kw',
        'pcm_seo_meta_title', 'pcm_seo_meta_description', 'pcm_seo_primary_keyword', 'pcm_seo_meta_keywords',
    );
    foreach (array('post', 'page') as $type) {
        foreach ($keys as $k) {
            register_post_meta($type, $k, array('show_in_rest' => true, 'single' => true, 'type' => 'string', 'auth_callback' => function () { return current_user_can('edit_posts'); }));
        }
    }
});
PHP;
        return str_replace(
            array('__HUB_URL__', '__CLIENT_ID__', '__CLIENT_SECRET__'),
            array($hub_url, $client_id, $secret),
            $tpl
        );
    }
}
