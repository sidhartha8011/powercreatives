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
        // ONE template for both flows (3.0.0): the per-tenant build is the REAL
        // connector with the handshake credentials baked (the old separate v1.0.1
        // tenant template shipped a connector with none of the current features).
        $php = self::connector_php_simple($hub_url, (string) $tenant->clientId, (string) $tenant->clientSecret);

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
        // wp_tempnam() lives in wp-admin/includes/file.php, which is NOT loaded in a REST
        // request — and the manifest/package routes connected sites poll ARE REST. Without this
        // require, a poll that misses the package cache (i.e. right after every connector version
        // bump) fatals with a 500, so WordPress never sees the update and the site can't
        // auto-update. Load the file on demand so the build works in any context.
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
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

    /** The connector plugin source with self-update placeholders baked to this hub's
     *  manifest URL + host + header version. Tenant args bake the HMAC handshake
     *  (per-site download); the generic pairing-code build bakes '' (handshake inert). */
    private static function connector_php_simple(string $hub_url = '', string $client_id = '', string $secret = ''): string
    {
        $php = self::connector_php_simple_raw();
        $manifest = rest_url('pcm/v1/seohub/connector-manifest');
        $scheme   = (string) (wp_parse_url($manifest, PHP_URL_SCHEME) ?: 'https');
        $host     = (string) (wp_parse_url($manifest, PHP_URL_HOST) ?: wp_parse_url(home_url('/'), PHP_URL_HOST));
        $version  = preg_match('/^\s*\*\s*Version:\s*([0-9][0-9.]*)/m', $php, $m) ? $m[1] : '0';
        return strtr($php, array(
            '__PCM_CONN_MANIFEST_URL__'  => $manifest,
            '__PCM_CONN_UPDATE_URI__'    => $scheme . '://' . $host . '/pcm-connector',
            '__PCM_CONN_UPDATE_HOST__'   => $host,
            '__PCM_CONN_VERSION__'       => $version,
            '__PCM_CONN_HUB_URL__'       => $hub_url,
            '__PCM_CONN_CLIENT_ID__'     => $client_id,
            '__PCM_CONN_CLIENT_SECRET__' => $secret,
        ));
    }

    /** Raw connector source with self-update placeholders (baked by connector_php_simple()). */
    private static function connector_php_simple_raw(): string
    {
        return <<<'PHP'
<?php
/**
 * Plugin Name: Power Creatives Connector
 * Description: Connects this site to a Power Creatives hub — four dumb jobs: page snapshot (the hub does ALL parsing), builder-aware storage writers (post content + Elementor/Bricks/Divi/WPBakery/Oxygen/Breakdance/Brizy + any custom field, incl. base64-encoded builder data, PLUS Elementor Theme Builder templates + Gutenberg reusable blocks, with cache regeneration + verification), guarded render-time apply of hub-precomputed instructions (refuse-if-unsure), and hub-pushed config. Also: SEO meta in REST, fallback meta tags, robots.txt + JSON-LD, /llms.txt + /llm-info/, cache flush on edit, self-update, one-paste connection code.
 * Version: 3.0.7
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
// Per-site downloads bake hub URL + HMAC credentials for the automatic handshake;
// the generic pairing-code build bakes '' and the handshake block below is inert.
// (ONE template for both flows — the old separate v1.0.1 tenant template is gone.)
if (!defined('PCM_CONN_HUB_URL'))       { define('PCM_CONN_HUB_URL', '__PCM_CONN_HUB_URL__'); }
if (!defined('PCM_CONN_CLIENT_ID'))     { define('PCM_CONN_CLIENT_ID', '__PCM_CONN_CLIENT_ID__'); }
if (!defined('PCM_CONN_CLIENT_SECRET')) { define('PCM_CONN_CLIENT_SECRET', '__PCM_CONN_CLIENT_SECRET__'); }

// Register with the hub via an HMAC-signed handshake (creates an Application Password for
// the current admin + pings the hub). Runs on activation AND, as a self-heal, on each admin
// load until registered. Capped to avoid endless app passwords if the hub is unreachable.
// A self-update replaces baked credentials with '' — harmless: registration is one-time and
// pcm_conn_status/app-password persist in the DB.
function pcm_conn_register() {
    if (PCM_CONN_CLIENT_ID === '' || get_option('pcm_conn_status') === 'registered') { return; }
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

// ── Hub-pushed config (config schema v1) ─────────────────────────────────────────────────────
// Every tunable is hub-controlled data; the literals below are only the DEFAULTS a connector
// uses until the hub pushes values (a connector that never received config behaves exactly
// like the shipped code). Read via pcm_conn_cfg(key); stored in the pcm_conn_config option.
function pcm_conn_cfg($key) {
    static $defaults = array(
        'snapshotCacheTtl' => 600,  // sec — snapshot transient TTL
        'loopbackTimeout'  => 8,    // sec — self-request budget
        'loopbackLockTtl'  => 15,   // sec — single-flight lock cover
        'chromeRegions'    => array('header', 'nav', 'footer', 'aside'),
        'statsThrottle'    => 300,  // sec — min gap between counter writes
        'overridesCap'     => 200,  // legacy overrides runaway backstop
    );
    $cfg = get_option('pcm_conn_config', array());
    if (is_array($cfg) && array_key_exists($key, $cfg) && $cfg[$key] !== null && $cfg[$key] !== '') {
        return $cfg[$key];
    }
    return isset($defaults[$key]) ? $defaults[$key] : null;
}
add_action('rest_api_init', function () {
    $perm = function () { return current_user_can('manage_options'); };
    $read = function () {
        $effective = array();
        foreach (array('snapshotCacheTtl', 'loopbackTimeout', 'loopbackLockTtl', 'chromeRegions', 'statsThrottle', 'overridesCap') as $k) {
            $effective[$k] = pcm_conn_cfg($k);
        }
        return array(
            'config'    => $effective,
            // The legacy override list is EXPOSED here for the hub's one-time
            // override→instruction migration (nothing else could read it).
            'overrides' => function_exists('pcm_conn_heading_overrides') ? pcm_conn_heading_overrides() : array(),
            'version'   => PCM_CONN_VERSION,
        );
    };
    register_rest_route('pcm-conn/v1', '/config', array(
        array('methods' => 'GET', 'permission_callback' => $perm, 'callback' => $read),
        array('methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ($req) use ($read) {
            $p = $req->get_json_params();
            if (is_array($p)) {
                $cfg = get_option('pcm_conn_config', array());
                if (!is_array($cfg)) { $cfg = array(); }
                foreach (array('snapshotCacheTtl', 'loopbackTimeout', 'loopbackLockTtl', 'statsThrottle', 'overridesCap') as $k) {
                    if (array_key_exists($k, $p)) { $cfg[$k] = max(1, absint($p[$k])); }
                }
                if (array_key_exists('chromeRegions', $p) && is_array($p['chromeRegions'])) {
                    $cfg['chromeRegions'] = array_values(array_filter(array_map('sanitize_key', $p['chromeRegions'])));
                }
                update_option('pcm_conn_config', $cfg, true);
                // Executed ONLY by the hub's migration after per-site verification: an
                // empty override list makes the legacy buffer a no-op (its early-return).
                if (!empty($p['clearOverrides'])) { delete_option('pcm_conn_heading_overrides'); }
            }
            return call_user_func($read);
        }),
    ));
});

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
    // Featherweight page-state (3.0.7, gap e48b1ff): version+fingerprint ONLY —
    // no page render, no rule application. version 0 / '' = never pushed under
    // versioning (the true baseline, never an invention).
    register_rest_route('pcm-conn/v1', '/page-state', array(
        'methods' => 'GET', 'permission_callback' => $perm,
        'callback' => function ($req) {
            $pid = absint($req->get_param('post_id'));
            $s   = get_option('pcm_conn_page_state_' . $pid, null);
            return new WP_REST_Response(array(
                'version'     => (is_array($s) && isset($s['version'])) ? (int) $s['version'] : 0,
                'fingerprint' => (is_array($s) && isset($s['fingerprint'])) ? (string) $s['fingerprint'] : '',
            ), 200);
        },
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
            $cap = max(1, (int) pcm_conn_cfg('overridesCap'));
            if (count($list) > $cap) { $list = array_slice($list, -$cap); } // runaway-list backstop
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

// ═══ Dynamic content rules (rule schema v1) — render-time paragraph optimization ═══
// Pushed by the hub, stored locally per post, applied to the final HTML on every
// front-end render. Builder-agnostic by construction (operates AFTER the builder).
// Contracts: docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md (hub repo). SYNC CONTRACT:
// pcm_conn_normalize_text / the boundary matcher below MUST stay behavior-identical
// to the hub's fixture-tested PCM_Text_Matcher.

/**
 * Site-wide SINGLE-FLIGHT loopback lock (2.7.1): at most ONE self-request runs at a
 * time, no matter who asks (two hub tabs, two users, heading + content scan at once).
 * Rationale: on worker-limited hosts (LocalWP, small shared hosting) concurrent
 * loopbacks starve the PHP workers and time EVERYTHING out. Transients aren't CAS —
 * a rare race admits a second loopback, which is exactly today's behavior (no worse).
 */
function pcm_conn_loopback_acquire() {
    if (get_transient('pcm_conn_loopback_lock')) { return false; }
    set_transient('pcm_conn_loopback_lock', 1, (int) pcm_conn_cfg('loopbackLockTtl')); // covers the timeout + margin
    return true;
}
function pcm_conn_loopback_release() { delete_transient('pcm_conn_loopback_lock'); }
/**
 * Fetch this site's own page (loopback) under the single-flight lock, budgeted
 * (2.7.1: fail fast and honestly — callers MUST have a non-loopback fallback
 * path). Timeout is hub-pushed config. Returns the HTML body, or '' when
 * locked/failed.
 */
function pcm_conn_loopback_fetch($pid, $bust_arg, $agent_tag) {
    if (!pcm_conn_loopback_acquire()) { return ''; }
    $resp = wp_remote_get(add_query_arg($bust_arg, (string) time(), get_permalink($pid)), array(
        'timeout'     => (int) pcm_conn_cfg('loopbackTimeout'),
        'redirection' => 3,
        'sslverify'   => apply_filters('https_local_ssl_verify', false),
        'user-agent'  => 'Mozilla/5.0 (compatible; PowerCreativesConnector/2.7; +' . $agent_tag . ')',
        'headers'     => array('Accept' => 'text/html', 'Cache-Control' => 'no-cache'),
    ));
    pcm_conn_loopback_release();
    if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) { return ''; }
    return (string) wp_remote_retrieve_body($resp);
}

/** Normalization spec v1 — mirror of PCM_Text_Matcher::normalize(). */
function pcm_conn_normalize_text($text) {
    $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = (string) preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}
/** Visible text of an HTML fragment — mirror of PCM_Text_Matcher::visible_text(). */
function pcm_conn_visible_text($html) {
    $html = (string) preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', (string) $html);
    return strip_tags($html);
}
/** Image-src identity (v2.3) — mirror of PCM_Text_Matcher::normalize_src():
 *  entity-decode + trim ONLY. NO case fold (URL paths are case-sensitive),
 *  query string KEPT (it is identity). */
function pcm_conn_normalize_src($src) {
    return trim(html_entity_decode((string) $src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}
// --- Section engine (3.0.0: the ONLY apply implementation — the hub's serving
// mirror is gone; these functions are exercised directly by the committed
// extraction harness in tests/standalone/, which also pins the parsing
// primitives behavior-identical to the hub's PCM_Text_Matcher reference. ---
/** Section fingerprint v2: normalized paragraph texts joined with "\n". */
function pcm_conn_section_fingerprint($texts) {
    $out = array();
    foreach ((array) $texts as $t) { $out[] = pcm_conn_normalize_text((string) $t); }
    return implode("\n", $out);
}
/** Top-level <h1-6>/<p> blocks with offsets (level 0 = <p>). */
function pcm_conn_parse_blocks($html) {
    $out = array();
    if (!preg_match_all('#<(h[1-6]|p)(\s[^>]*)?>(.*?)</\1>#is', (string) $html, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) { return $out; }
    foreach ($mm as $m) {
        $tag   = strtolower($m[1][0]);
        $out[] = array(
            'tag'   => $tag,
            'level' => ($tag === 'p') ? 0 : (int) substr($tag, 1),
            'attrs' => isset($m[2][0]) ? (string) $m[2][0] : '',
            'inner' => (string) $m[3][0],
            'text'  => pcm_conn_visible_text((string) $m[3][0]),
            'html'  => (string) $m[0][0],
            'start' => (int) $m[0][1],
            'len'   => strlen((string) $m[0][0]),
        );
    }
    return $out;
}
/** Chrome spans (pre-<body> + the config-driven region list; defaults =
 *  header/nav/footer/aside) — behavior-identical to PCM_Text_Matcher::chrome_spans
 *  under default config (2.8.1 scan-parity fix; harness-pinned). */
function pcm_conn_chrome_spans($html) {
    $spans = array();
    $body  = stripos((string) $html, '<body');
    if ($body !== false && $body > 0) { $spans[] = array(0, $body); }
    $regions = function_exists('pcm_conn_cfg') ? (array) pcm_conn_cfg('chromeRegions') : array('header', 'nav', 'footer', 'aside');
    foreach ($regions as $tag) {
        if (preg_match_all('#<' . $tag . '(\s[^>]*)?>.*?</' . $tag . '>#is', (string) $html, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as $m) { $spans[] = array((int) $m[1], (int) $m[1] + strlen((string) $m[0])); }
        }
    }
    return $spans;
}
/** parse_blocks filtered to CONTENT blocks — the set the scan fingerprinted. */
function pcm_conn_content_blocks($html) {
    $spans = pcm_conn_chrome_spans($html);
    $blocks = pcm_conn_parse_blocks($html);
    if (empty($spans)) { return $blocks; }
    $out = array();
    foreach ($blocks as $b) {
        $inside = false;
        foreach ($spans as $s) {
            if ($b['start'] >= $s[0] && $b['start'] < $s[1]) { $inside = true; break; }
        }
        if (!$inside) { $out[] = $b; }
    }
    return array_values($out);
}
/** A replacement's ordered units: h/p blocks + raw chunks (lists etc.) between them. */
function pcm_conn_parse_replacement_units($html) {
    $units = array(); $pos = 0; $html = (string) $html;
    if (preg_match_all('#<(h[1-6]|p)(\s[^>]*)?>(.*?)</\1>#is', $html, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($mm as $m) {
            $start = (int) $m[0][1];
            $gap   = substr($html, $pos, $start - $pos);
            if (trim($gap) !== '') { $units[] = array('tag' => '', 'inner' => '', 'html' => trim($gap)); }
            $units[] = array('tag' => strtolower($m[1][0]), 'inner' => (string) $m[3][0], 'html' => (string) $m[0][0]);
            $pos = $start + strlen((string) $m[0][0]);
        }
    }
    $tail = substr($html, $pos);
    if (trim($tail) !== '') { $units[] = array('tag' => '', 'inner' => '', 'html' => trim($tail)); }
    return $units;
}
/** The section body owned by the heading block at $i: following <p> block indices. */
function pcm_conn_section_body($blocks, $i) {
    $body = array();
    for ($j = $i + 1, $n = count($blocks); $j < $n; $j++) {
        if ($blocks[$j]['tag'] !== 'p') { break; }
        $body[] = $j;
    }
    return $body;
}
/**
 * Apply one `section` replace rule — all-or-nothing, wrapper-safe (contracts v2):
 * candidates verify their body FINGERPRINT (occurrence is only a hint among
 * verified twins, so chrome-duplicate headings can never cause a wrong swap);
 * replacement units map 1:1 onto original blocks (same tag keeps the ORIGINAL
 * attributes), surplus new units ride as siblings, surplus originals are removed
 * whole. Returns new HTML or null (miss → caller serves original + stale count).
 */
function pcm_conn_apply_section_rule($html, $match_text, $level, $fingerprint, $occurrence, $replacement) {
    if ((string) $match_text === '') { return null; }
    $blocks = pcm_conn_content_blocks($html); // chrome-excluded (scan parity, 2.8.1)
    $verified = array();
    foreach ($blocks as $i => $b) {
        if ($b['tag'] === 'p' || ($level >= 1 && $b['level'] !== $level)) { continue; }
        if (pcm_conn_normalize_text($b['text']) !== $match_text) { continue; }
        $body  = pcm_conn_section_body($blocks, $i);
        $texts = array();
        foreach ($body as $j) { $texts[] = $blocks[$j]['text']; }
        if (pcm_conn_section_fingerprint($texts) === (string) $fingerprint) {
            $verified[] = array('heading' => $i, 'body' => $body);
        }
    }
    if (empty($verified)) { return null; }
    $hit      = $verified[min(max(0, (int) $occurrence), count($verified) - 1)];
    $orig_idx = array_merge(array($hit['heading']), $hit['body']);
    $units    = pcm_conn_parse_replacement_units($replacement);
    if (empty($units)) { return null; }
    $edits  = array();
    $shared = min(count($orig_idx), count($units));
    for ($k = 0; $k < $shared; $k++) {
        $o = $blocks[$orig_idx[$k]];
        $n = $units[$k];
        $new_html = ($n['tag'] !== '' && $o['tag'] === $n['tag'])
            ? '<' . $o['tag'] . $o['attrs'] . '>' . $n['inner'] . '</' . $o['tag'] . '>'
            : $n['html'];
        $edits[] = array('start' => $o['start'], 'len' => $o['len'], 'html' => $new_html);
    }
    if (count($units) > $shared) {
        $last  = $blocks[$orig_idx[$shared - 1]];
        $extra = '';
        for ($k = $shared; $k < count($units); $k++) { $extra .= $units[$k]['html']; }
        $edits[] = array('start' => $last['start'] + $last['len'], 'len' => 0, 'html' => $extra);
    }
    for ($k = $shared; $k < count($orig_idx); $k++) {
        $o       = $blocks[$orig_idx[$k]];
        $edits[] = array('start' => $o['start'], 'len' => $o['len'], 'html' => '');
    }
    usort($edits, function ($a, $b) { return $b['start'] - $a['start']; });
    foreach ($edits as $e) { $html = substr_replace($html, $e['html'], $e['start'], $e['len']); }
    return $html;
}
// --- Content-region primitive (engine v2.4.1): WordPress's OWN the_content
// pipeline defines where page content begins and ends — the engine OBSERVES
// it instead of guessing. The recorder is a pure observer at the last filter
// position; the locator is an exact substring match on the output buffer.
// Not recorded / not found → null → callers keep legacy behavior (never worse).
if (!defined('PCM_CONN_CE')) { define('PCM_CONN_CE', '<!--pcm-ce-7f3a-->'); }
/** Remember a post's FINAL the_content output (first non-empty win per request). */
function pcm_conn_content_remember($pid, $html) {
    if (!isset($GLOBALS['pcm_conn_content_rec'][(int) $pid]) && trim((string) $html) !== '') {
        $GLOBALS['pcm_conn_content_rec'][(int) $pid] = (string) $html;
    }
    return (string) $html;
}
add_filter('the_content', function ($html) {
    if (is_singular() && in_the_loop() && is_main_query()) {
        pcm_conn_content_remember((int) get_queried_object_id(), (string) $html);
    }
    return $html;
}, PHP_INT_MAX);
/** Exact [start, end) span of the recorded content inside a buffer, or null. */
function pcm_conn_content_span($buffer, $pid) {
    $rec = isset($GLOBALS['pcm_conn_content_rec'][(int) $pid]) ? (string) $GLOBALS['pcm_conn_content_rec'][(int) $pid] : '';
    if ($rec === '') { return null; }
    $at = strpos((string) $buffer, $rec);
    return ($at === false) ? null : array($at, $at + strlen($rec));
}
/**
 * Apply one `sectionInsert` rule: new section before the anchor heading, or
 * after the anchor section ('after' = before the NEXT heading block when one
 * exists — top-level, outside builder wrappers; only the page's LAST section
 * falls back to after-its-last-block). Anchor missing → null (nothing inserted).
 *
 * v2.4.1 PLACEMENT LAW: when the buffer carries the content-end sentinel
 * (injected by the serving callback) and the anchor lies INSIDE the content
 * region, the insertion point may NEVER exceed the region's end — an insert
 * after the last section lands at the true end of the content, never past
 * trailing theme furniture (comment forms etc.). Anchors outside the region
 * (comment-area headings) keep legacy semantics.
 */
function pcm_conn_apply_section_insert($html, $match_text, $level, $position, $occurrence, $replacement) {
    if ((string) $match_text === '' || trim((string) $replacement) === '') { return null; }
    $blocks = pcm_conn_content_blocks($html); // chrome-excluded (scan parity, 2.8.1)
    $candidates = array();
    foreach ($blocks as $i => $b) {
        if ($b['tag'] === 'p' || ($level >= 1 && $b['level'] !== $level)) { continue; }
        if (pcm_conn_normalize_text($b['text']) === $match_text) { $candidates[] = $i; }
    }
    if (empty($candidates)) { return null; }
    $i = $candidates[min(max(0, (int) $occurrence), count($candidates) - 1)];
    if ($position === 'before') {
        $at = $blocks[$i]['start'];
    } else {
        $body = pcm_conn_section_body($blocks, $i);
        $next = empty($body) ? $i + 1 : $body[count($body) - 1] + 1;
        if (isset($blocks[$next]) && $blocks[$next]['tag'] !== 'p') {
            $at = $blocks[$next]['start'];
        } else {
            $last = empty($body) ? $blocks[$i] : $blocks[$body[count($body) - 1]];
            $at   = $last['start'] + $last['len'];
        }
    }
    $ce = strpos($html, PCM_CONN_CE);
    if ($ce !== false && $blocks[$i]['start'] < $ce && $at > $ce) { $at = $ce; }
    return substr_replace($html, $replacement, $at, 0);
}
/**
 * Apply one `sectionRemove` rule (engine v2.4): locate EXACTLY like a replace
 * (fingerprint-verified candidates; occurrence = hint among verified twins —
 * a changed section can never cause a wrong removal), then remove the
 * section's blocks (heading + body) whole. Between-content (images, forms,
 * builder wrappers) stays — image visibility is its own rule (`hidden`).
 * Returns new HTML or null (miss → original serves + stale count).
 */
function pcm_conn_apply_section_remove($html, $match_text, $level, $fingerprint, $occurrence) {
    if ((string) $match_text === '') { return null; }
    $blocks = pcm_conn_content_blocks($html); // chrome-excluded (scan parity, 2.8.1)
    $verified = array();
    foreach ($blocks as $i => $b) {
        if ($b['tag'] === 'p' || ($level >= 1 && $b['level'] !== $level)) { continue; }
        if (pcm_conn_normalize_text($b['text']) !== $match_text) { continue; }
        $body  = pcm_conn_section_body($blocks, $i);
        $texts = array();
        foreach ($body as $j) { $texts[] = $blocks[$j]['text']; }
        if (pcm_conn_section_fingerprint($texts) === (string) $fingerprint) {
            $verified[] = array('heading' => $i, 'body' => $body);
        }
    }
    if (empty($verified)) { return null; }
    $hit   = $verified[min(max(0, (int) $occurrence), count($verified) - 1)];
    $edits = array();
    foreach (array_merge(array($hit['heading']), $hit['body']) as $k) {
        $edits[] = array('start' => $blocks[$k]['start'], 'len' => $blocks[$k]['len']);
    }
    usort($edits, function ($a, $b) { return $b['start'] - $a['start']; });
    foreach ($edits as $e) { $html = substr_replace($html, '', $e['start'], $e['len']); }
    return $html;
}
// Snapshot cache: busted whenever the post changes — a stale snapshot must
// never outlive an edit (rules POST also busts it, see the /rules route).
// The served view is version-stamped; bumping the version retires it too.
add_action('save_post', function ($pid) {
    delete_transient('pcm_conn_snap_' . (int) $pid);
    update_option('pcm_conn_view_ver', (int) get_option('pcm_conn_view_ver', 0) + 1, false);
});
/** The post's stored rule set (rule schema v1 rows), [] when none. */
function pcm_conn_rules_for($pid) {
    $r = get_option('pcm_conn_rules_' . (int) $pid, array());
    return is_array($r) ? array_values(array_filter($r, 'is_array')) : array();
}
/** SITE-SCOPE rules (instruction stream v2.1: heading instructions — the
 *  compiled legacy overrides). Served on EVERY front-end render. */
function pcm_conn_rules_site() {
    $r = get_option('pcm_conn_rules_site', array());
    return is_array($r) ? array_values(array_filter($r, 'is_array')) : array();
}
/** Replace a post's rule set + maintain the index of posts that have rules. */
function pcm_conn_rules_save($pid, $rules) {
    $pid = (int) $pid;
    $idx = get_option('pcm_conn_rules_index', array());
    if (!is_array($idx)) { $idx = array(); }
    if (empty($rules)) {
        delete_option('pcm_conn_rules_' . $pid);
        delete_option('pcm_conn_rules_stats_' . $pid);
        $idx = array_values(array_diff(array_map('intval', $idx), array($pid)));
    } else {
        update_option('pcm_conn_rules_' . $pid, array_values($rules), false); // autoload OFF
        if (!in_array($pid, array_map('intval', $idx), true)) { $idx[] = $pid; }
    }
    update_option('pcm_conn_rules_index', $idx, false);
}
/** Echo the hub-declared page state {version, fingerprint} on a snapshot reply
 *  (page-versioning contract, 3.0.6). Stored beside the rules on push; when the
 *  hub never declared one the reply carries NO pageState key — a fabricated
 *  value would read as agreement on the hub's compare. */
function pcm_conn_page_state_echo($reply, $pid) {
    $s = get_option('pcm_conn_page_state_' . (int) $pid, null);
    if (is_array($s) && isset($s['version'], $s['fingerprint'])) {
        $reply['pageState'] = array('version' => (int) $s['version'], 'fingerprint' => (string) $s['fingerprint']);
    }
    return $reply;
}
/** Per-post serve/miss counters (throttled writes — min gap is hub-pushed config). */
function pcm_conn_rules_bump_stats($pid, $applied, $missed) {
    $key = 'pcm_conn_rules_stats_' . (int) $pid;
    $s = get_option($key, array());
    if (!is_array($s)) { $s = array(); }
    $now = time();
    if (isset($s['lastAt']) && ($now - (int) $s['lastAt']) < (int) pcm_conn_cfg('statsThrottle') && $missed <= (int) ($s['lastMissed'] ?? -1)) { return; }
    $s['applied']    = (int) ($s['applied'] ?? 0) + (int) $applied;
    $s['missed']     = (int) ($s['missed'] ?? 0) + (int) $missed;
    $s['lastMissed'] = (int) $missed;
    $s['lastAt']     = $now;
    update_option($key, $s, false);
}
/**
 * Apply active rules to a full HTML response. Boundary matching on NON-NESTABLE
 * tags (<p>/<hN> cannot legally nest — same proven pattern as the heading
 * overrides above). Miss → block left untouched (the original serves) + counted.
 * v1 serves target 'paragraph'; the engine is target-agnostic by design.
 */
function pcm_conn_apply_rules($html, $rules, $pid) {
    $applied = 0; $missed = 0;
    // Pass 0 (v2.2): HEADING rules — attributes preserved, text esc_html'd,
    // normalized visible-text compare (spec v1). Runs FIRST because section/
    // paragraph identities are computed by the hub on the DISPLAY state
    // (headings as instructed) — the 2.8.2 ordering law's successor.
    // `section.allOccurrences` = compiled-override semantics (EVERY match,
    // whole buffer — chrome included); without it the rule targets exactly the
    // occurrence-th matching CONTENT heading (chrome-excluded — the same
    // identity space the hub's inventory computes).
    foreach ($rules as $r) {
        if (empty($r['active']) || (string) ($r['target'] ?? '') !== 'heading') { continue; }
        $want = (string) ($r['match']['text'] ?? '');
        if ($want === '') { $missed++; continue; }
        $sec = (isset($r['section']) && is_array($r['section'])) ? $r['section'] : array();
        $ol  = max(1, min(6, (int) ($sec['level'] ?? 0)));
        $nl  = max(1, min(6, (int) ($sec['newLevel'] ?? $ol)));
        $nt  = (string) ($r['replacement'] ?? '');
        if (!empty($sec['allOccurrences'])) {
            // frameOnly (site/frame edits): apply ONLY inside chrome spans —
            // identical text in page CONTENT is never touched. Rules without
            // the flag (migrated legacy overrides) keep whole-page semantics.
            $frame_only = !empty($sec['frameOnly']);
            $spans = $frame_only ? pcm_conn_chrome_spans($html) : array();
            $edits = array();
            if (preg_match_all('#<h' . $ol . '(\s[^>]*)?>(.*?)</h' . $ol . '>#is', $html, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($mm as $m) {
                    $start = (int) $m[0][1];
                    if ($frame_only) {
                        $inside = false;
                        foreach ($spans as $s) {
                            if ($start >= $s[0] && $start < $s[1]) { $inside = true; break; }
                        }
                        if (!$inside) { continue; }
                    }
                    if (pcm_conn_normalize_text(pcm_conn_visible_text((string) $m[2][0])) !== $want) { continue; }
                    $edits[] = array('start' => $start, 'len' => strlen((string) $m[0][0]), 'html' => '<h' . $nl . (isset($m[1][0]) ? $m[1][0] : '') . '>' . esc_html($nt) . '</h' . $nl . '>');
                }
            }
            if (!empty($edits)) {
                usort($edits, function ($a, $b) { return $b['start'] - $a['start']; });
                foreach ($edits as $e) { $html = substr_replace($html, $e['html'], $e['start'], $e['len']); }
                $applied++;
            } else { $missed++; }
            continue;
        }
        $occ  = max(0, (int) ($r['match']['occurrence'] ?? 0));
        $seen = 0;
        $done = false;
        foreach (pcm_conn_content_blocks($html) as $b) {
            if ($b['tag'] === 'p' || (int) $b['level'] !== $ol) { continue; }
            if (pcm_conn_normalize_text($b['text']) !== $want) { continue; }
            if ($seen++ !== $occ) { continue; }
            $html = substr_replace($html, '<h' . $nl . $b['attrs'] . '>' . esc_html($nt) . '</h' . $nl . '>', $b['start'], $b['len']);
            $done = true;
            break;
        }
        if ($done) { $applied++; } else { $missed++; }
    }
    // Pass 1 (v2/v2.4): section replaces → REMOVES → inserts — each rule
    // re-parses the current buffer (offsets shift between rules; a few rules
    // per post, cheap). Removes run before inserts so an insert anchored on a
    // surviving neighbor still lands; one anchored on a removed heading goes
    // honestly inert + stale.
    foreach (array('section', 'sectionRemove') as $phase) {
        foreach ($rules as $r) {
            if (empty($r['active']) || (string) ($r['target'] ?? '') !== $phase) { continue; }
            $want = (string) ($r['match']['text'] ?? '');
            $occ  = (int) ($r['match']['occurrence'] ?? 0);
            $sec  = (isset($r['section']) && is_array($r['section'])) ? $r['section'] : array();
            $lvl  = (int) ($sec['level'] ?? 0);
            $out  = ($phase === 'section')
                ? pcm_conn_apply_section_rule($html, $want, $lvl, (string) ($sec['fingerprint'] ?? ''), $occ, (string) ($r['replacement'] ?? ''))
                : pcm_conn_apply_section_remove($html, $want, $lvl, (string) ($sec['fingerprint'] ?? ''), $occ);
            if (is_string($out)) { $html = $out; $applied++; } else { $missed++; }
        }
    }
    // Inserts (v2.4.1 ORDERING LAW): 'before' rules apply in rule order (each
    // lands at the anchor's start, above the previous — creation flow already);
    // 'after' rules apply in REVERSE rule order — each earlier rule then lands
    // above the later ones, so the page reads in creation order (fixture-pinned;
    // forward order provably served THREE,TWO,ONE for created ONE,TWO,THREE).
    $ins_before = array();
    $ins_after  = array();
    foreach ($rules as $r) {
        if (empty($r['active']) || (string) ($r['target'] ?? '') !== 'sectionInsert') { continue; }
        $sec = (isset($r['section']) && is_array($r['section'])) ? $r['section'] : array();
        if (((string) ($sec['position'] ?? 'after')) === 'before') { $ins_before[] = $r; } else { $ins_after[] = $r; }
    }
    foreach (array_merge($ins_before, array_reverse($ins_after)) as $r) {
        $sec = (isset($r['section']) && is_array($r['section'])) ? $r['section'] : array();
        $out = pcm_conn_apply_section_insert(
            $html,
            (string) ($r['match']['text'] ?? ''),
            (int) ($sec['level'] ?? 0),
            (string) ($sec['position'] ?? 'after'),
            (int) ($r['match']['occurrence'] ?? 0),
            (string) ($r['replacement'] ?? '')
        );
        if (is_string($out)) { $html = $out; $applied++; } else { $missed++; }
    }
    // Pass 2 (v1, byte-identical behavior): paragraph rules.
    foreach ($rules as $r) {
        if (empty($r['active'])) { continue; }
        $target = (string) ($r['target'] ?? '');
        $tag = ($target === 'paragraph') ? 'p' : '';
        if ($tag === '') { continue; } // heading/anchorText/href reserved — never guessed at
        $want = (string) ($r['match']['text'] ?? '');
        $occ  = (int) ($r['match']['occurrence'] ?? 0);
        $replacement = (string) ($r['replacement'] ?? '');
        if ($want === '') { $missed++; continue; }
        $seen = 0; $done = false;
        $out = preg_replace_callback('#<' . $tag . '(\s[^>]*)?>(.*?)</' . $tag . '>#is', function ($m) use (&$seen, &$done, $want, $occ, $replacement, $tag) {
            if ($done) { return $m[0]; }
            if (pcm_conn_normalize_text(pcm_conn_visible_text($m[2])) !== $want) { return $m[0]; }
            if ($seen++ !== $occ) { return $m[0]; }
            $done = true;
            return '<' . $tag . (isset($m[1]) ? $m[1] : '') . '>' . $replacement . '</' . $tag . '>';
        }, $html);
        if (is_string($out) && $done) { $html = $out; $applied++; } else { $missed++; }
    }
    // Pass 3 (v2.3/v2.4): IMAGE rules — ONE buffer scan so indexes stay
    // stable across multiple rules and removals. Every content-region <img>
    // gets an occurrence per normalized src; a matching rule either HIDES it
    // (`hidden`: the tag is removed at render — media/storage untouched) or
    // rewrites ONLY alt/title. Chrome images are neither counted nor touched
    // (the frame law). A rule matching no image = inert, original serves,
    // counted. Runs LAST so it also reaches images inside rule output.
    $img_rules = array();
    foreach ($rules as $r) {
        if (empty($r['active']) || (string) ($r['target'] ?? '') !== 'image') { continue; }
        $want  = pcm_conn_normalize_src((string) ($r['match']['text'] ?? ''));
        $attrs = json_decode((string) ($r['replacement'] ?? ''), true);
        if ($want === '' || !is_array($attrs)) { $missed++; continue; }
        $img_rules[] = array('src' => $want, 'occ' => max(0, (int) ($r['match']['occurrence'] ?? 0)), 'attrs' => $attrs, 'hit' => false);
    }
    if (!empty($img_rules)) {
        $spans = pcm_conn_chrome_spans($html);
        $seen  = array();
        $edits = array();
        if (preg_match_all('#<img\b[^>]*>#i', $html, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as $m) {
                $start  = (int) $m[1];
                $chrome = false;
                foreach ($spans as $s) {
                    if ($start >= $s[0] && $start < $s[1]) { $chrome = true; break; }
                }
                if ($chrome) { continue; }
                $tag = (string) $m[0];
                if (!preg_match('#(?<![\w-])src\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $tag, $sm)) { continue; }
                $src = pcm_conn_normalize_src($sm[2] !== '' ? $sm[2] : (isset($sm[3]) ? $sm[3] : ''));
                if ($src === '') { continue; }
                $occ        = isset($seen[$src]) ? $seen[$src] : 0;
                $seen[$src] = $occ + 1;
                foreach ($img_rules as $ri => $ir) {
                    if ($ir['hit'] || $ir['src'] !== $src || $ir['occ'] !== $occ) { continue; }
                    $img_rules[$ri]['hit'] = true;
                    if (!empty($ir['attrs']['hidden'])) {
                        $edits[] = array('start' => $start, 'len' => strlen($tag), 'html' => '');
                        break;
                    }
                    $new = $tag;
                    foreach (array('alt', 'title') as $a) {
                        if (!array_key_exists($a, $ir['attrs'])) { continue; }
                        $val = esc_attr((string) $ir['attrs'][$a]);
                        if (preg_match('#(?<![\w-])' . $a . '\s*=\s*("[^"]*"|\'[^\']*\')#i', $new)) {
                            // Callback: the value is literal, never backref-processed.
                            $new = (string) preg_replace_callback(
                                '#(?<![\w-])' . $a . '\s*=\s*("[^"]*"|\'[^\']*\')#i',
                                function () use ($a, $val) { return $a . '="' . $val . '"'; },
                                $new,
                                1
                            );
                        } else {
                            $new = (string) preg_replace_callback(
                                '#^<img\b#i',
                                function () use ($a, $val) { return '<img ' . $a . '="' . $val . '"'; },
                                $new,
                                1
                            );
                        }
                    }
                    if ($new !== $tag) { $edits[] = array('start' => $start, 'len' => strlen($tag), 'html' => $new); }
                    break;
                }
            }
        }
        if (!empty($edits)) {
            usort($edits, function ($a, $b) { return $b['start'] - $a['start']; });
            foreach ($edits as $e) { $html = substr_replace($html, $e['html'], $e['start'], $e['len']); }
        }
        foreach ($img_rules as $ir) {
            if ($ir['hit']) { $applied++; } else { $missed++; }
        }
    }
    if ($applied > 0 || $missed > 0) { pcm_conn_rules_bump_stats($pid, $applied, $missed); }
    return $html;
}
// Serving: front-end singular renders only. The ENTIRE callback is fail-safe —
// any throwable serves the ORIGINAL buffer (a rule can never break a page).
// pcm_cscan requests (the hub's content inventory) are EXCLUDED on purpose: the
// inventory must show the ORIGINAL rendered text, because that is exactly what
// rules match against (matching post-rule text would chain rules on themselves).
// PRIORITY 0 (2.8.2, PROVEN fix): this buffer must be the OUTER one so its
// callback runs AFTER the heading-override layer (prio 1) — rules then match
// the OVERRIDE-TRANSFORMED page, which is exactly what the scan inventoried
// and what the user sees. At prio 2 (inner) rules saw the RAW page: a section
// whose heading had a render-time override could NEVER match (identity built
// on the displayed text, raw text still the old one — permanent honest miss).
add_action('template_redirect', function () {
    if (is_admin() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) { return; }
    // The snapshot loopback is excluded from rule serving (snapshot = the page
    // as RULES-INPUT — the exact identity rules match; serving rules into it
    // would chain rules onto their own output). The legacy scan params stay
    // excluded for the transition window.
    if (isset($_GET['pcm_snap']) || isset($_GET['pcm_cscan']) || isset($_GET['pcm_hscan'])) { return; }
    if (get_option('pcm_conn_rules_off') === '1') { return; } // site kill switch (hub-managed)
    // SITE-scope rules (heading instructions) serve on EVERY front-end render —
    // the legacy override layer's reach; post rules stay singular-only.
    $site_rules = pcm_conn_rules_site();
    $pid        = is_singular() ? (int) get_queried_object_id() : 0;
    $post_rules = $pid ? pcm_conn_rules_for($pid) : array();
    if (empty($site_rules) && empty($post_rules)) { return; }
    ob_start(function ($html) use ($site_rules, $post_rules, $pid) {
        $original = $html; // fail-to-original must return the PRISTINE buffer
        try {
            // Content-region sentinel (v2.4.1): mark the true end of the
            // content BEFORE the passes run — earlier passes shift offsets,
            // a sentinel survives every mutation. Stripped before output.
            $span = pcm_conn_content_span($html, $pid);
            if ($span !== null) { $html = substr_replace($html, PCM_CONN_CE, $span[1], 0); }
            $html = pcm_conn_apply_rules($html, array_merge($site_rules, $post_rules), $pid);
            return str_replace(PCM_CONN_CE, '', $html);
        } catch (\Throwable $e) {
            return $original; // fail-to-original, always
        }
    });
}, 0);
// ── Slug-change redirects (3.0.5): hub-managed EXACT-PATH redirect store. ──
// The hub pushes the COMPLETE set (same replace-the-set law as rules). The
// handler answers ONLY requests WordPress would otherwise 404 — a working
// page can never be hijacked, normal views cost one is_404() check, and
// deleting a redirect honestly falls back to core's own old-slug behavior
// where core covers it. Query strings pass through to the target untouched.
// Both decision functions are pure (harness-pinned).
function pcm_conn_redirect_norm_path($path) {
    $p = (string) $path;
    if ($p === '') { return '/'; }
    if (preg_match('#^([a-z][a-z0-9+.-]*:)?//#i', $p)) {
        $p = (string) (wp_parse_url($p, PHP_URL_PATH) ?: '/');
    } else {
        $cut = strcspn($p, '?#');
        $p   = substr($p, 0, $cut);
    }
    $p = rawurldecode($p);
    if ($p === '' || $p[0] !== '/') { $p = '/' . $p; }
    $p = rtrim($p, '/');
    return $p === '' ? '/' : $p;
}
function pcm_conn_redirect_match($request_uri, $redirects) {
    if (!is_array($redirects) || empty($redirects)) { return null; }
    $uri   = (string) $request_uri;
    $qpos  = strpos($uri, '?');
    $query = $qpos !== false ? substr($uri, $qpos + 1) : '';
    $path  = pcm_conn_redirect_norm_path($uri);
    foreach ($redirects as $r) {
        if (!is_array($r) || (string) ($r['from'] ?? '') !== $path) { continue; }
        $to = (string) ($r['to'] ?? '');
        if ($to === '') { continue; }
        if ($query !== '') { $to .= (strpos($to, '?') === false ? '?' : '&') . $query; }
        $code = (int) ($r['code'] ?? 301);
        return array('to' => $to, 'code' => in_array($code, array(301, 302, 307, 308), true) ? $code : 301);
    }
    return null;
}
function pcm_conn_redirect_sanitize($rows) {
    $clean = array();
    $seen  = array();
    foreach ((array) $rows as $r) {
        if (!is_array($r)) { continue; }
        $from = pcm_conn_redirect_norm_path((string) ($r['from'] ?? ''));
        $to   = esc_url_raw((string) ($r['to'] ?? ''));
        $code = (int) ($r['code'] ?? 301);
        // Never the front page, never empty/unsafe targets, one rule per path.
        if ($from === '/' || $to === '' || isset($seen[$from])) { continue; }
        $seen[$from] = true;
        $clean[] = array('from' => $from, 'to' => $to, 'code' => in_array($code, array(301, 302, 307, 308), true) ? $code : 301);
    }
    return $clean;
}
add_action('template_redirect', function () {
    if (!is_404()) { return; } // only URLs WordPress can no longer answer
    if (get_option('pcm_conn_rules_off') === '1') { return; } // same kill switch as rules
    $store = get_option('pcm_conn_redirects', array());
    if (!is_array($store) || empty($store)) { return; }
    $hit = pcm_conn_redirect_match((string) ($_SERVER['REQUEST_URI'] ?? ''), $store);
    if ($hit === null) { return; }
    wp_redirect($hit['to'], $hit['code'], 'pcm-connector');
    exit;
}, 0);
add_action('rest_api_init', function () {
    $perm = function () { return current_user_can('manage_options'); };
    // GET's existence = the hub's capability handle (honest 404 pre-3.0.5).
    register_rest_route('pcm-conn/v1', '/redirects', array(
        array('methods' => 'GET', 'permission_callback' => $perm, 'callback' => function () {
            $store = get_option('pcm_conn_redirects', array());
            return array('supported' => true, 'redirects' => is_array($store) ? array_values($store) : array());
        }),
        array('methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ($req) {
            $p     = $req->get_json_params();
            $clean = pcm_conn_redirect_sanitize(is_array($p) ? ($p['redirects'] ?? array()) : array());
            if (empty($clean)) { delete_option('pcm_conn_redirects'); }
            else { update_option('pcm_conn_redirects', $clean, true); } // autoloaded: read on 404s
            return array('stored' => count($clean));
        }),
    ));
    // Site-wide "who links to this URL" (powers the hub's update-N-internal-links
    // offer). Plain-text LIKE over content + custom fields — the same surface the
    // universal replace pass edits; base64-stored builder data (Brizy) is invisible
    // to SEARCH but still handled by replace when its post is found another way.
    register_rest_route('pcm-conn/v1', '/url-usage', array(
        'methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ($req) {
            global $wpdb;
            $url = trim((string) $req->get_param('url'));
            if ($url === '') { return new WP_REST_Response(array('error' => 'bad_params'), 400); }
            $like = '%' . $wpdb->esc_like($url) . '%';
            $ids  = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','attachment','nav_menu_item') AND post_content LIKE %s LIMIT 50",
                $like
            )));
            $meta_ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_status = 'publish' AND p.post_type NOT IN ('revision','attachment','nav_menu_item') AND pm.meta_value LIKE %s LIMIT 50",
                $like
            )));
            $posts = array();
            foreach (array_unique(array_merge($ids, $meta_ids)) as $pid) {
                $post = get_post($pid);
                if (!$post) { continue; }
                $posts[] = array(
                    'id'    => $pid,
                    'title' => (string) $post->post_title,
                    'count' => max(1, substr_count((string) $post->post_content, $url)),
                );
            }
            return array('url' => $url, 'posts' => $posts, 'total' => array_sum(array_column($posts, 'count')));
        },
    ));
});

// Rules API: GET = capability answer + a post's rules & counters; POST = replace
// a post's (or the SITE's, postId 0) rule set — schema-validated, rejected honestly.
add_action('rest_api_init', function () {
    $perm = function () { return current_user_can('manage_options'); };
    register_rest_route('pcm-conn/v1', '/rules', array(
        array('methods' => 'GET', 'permission_callback' => $perm, 'callback' => function ($req) {
            $pid = absint($req->get_param('post_id'));
            if (!$pid) {
                $idx = get_option('pcm_conn_rules_index', array());
                return array(
                    'supported'     => true,
                    'schemaVersion' => 5,
                    'posts'         => is_array($idx) ? array_map('intval', $idx) : array(),
                    'siteRules'     => pcm_conn_rules_site(),
                    'killSwitch'    => get_option('pcm_conn_rules_off') === '1',
                );
            }
            return array(
                'supported'     => true,
                'schemaVersion' => 5,
                'rules'         => pcm_conn_rules_for($pid),
                'stats'         => (array) get_option('pcm_conn_rules_stats_' . $pid, array()),
            );
        }),
        array('methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ($req) {
            $p = $req->get_json_params();
            $schema = is_array($p) ? (int) ($p['schemaVersion'] ?? 0) : 0;
            if ($schema < 1 || $schema > 5) {
                return new WP_REST_Response(array('error' => 'unsupported_schema', 'accepts' => 5), 400);
            }
            $pid = absint($p['postId'] ?? 0);
            $site_scope = ($pid === 0 && $schema >= 3 && array_key_exists('postId', (array) $p));
            if (!$site_scope && (!$pid || !get_post($pid))) { return new WP_REST_Response(array('error' => 'not_found'), 404); }
            // v2 adds the section targets; v3 adds SERVED heading rules + site
            // scope; v4 adds the image target (attr rewrite only); v5 adds
            // sectionRemove + the image hidden flag. A v1..v4 payload keeps
            // exactly its old shape. Site scope accepts ONLY heading targets
            // (a site-wide paragraph/section rule is undefined).
            if ($schema >= 2) {
                $targets = array('paragraph', 'heading', 'anchorText', 'href', 'section', 'sectionInsert');
            } else {
                $targets = array('paragraph', 'heading', 'anchorText', 'href');
            }
            if ($schema >= 4) { $targets[] = 'image'; }
            if ($schema >= 5) { $targets[] = 'sectionRemove'; }
            if ($site_scope) { $targets = array('heading'); }
            $clean = array();
            foreach ((array) ($p['rules'] ?? array()) as $r) {
                if (!is_array($r)) { continue; }
                $target = (string) ($r['target'] ?? '');
                if (!in_array($target, $targets, true)) { continue; }
                $row = array(
                    'id'             => (int) ($r['id'] ?? 0),
                    'target'         => $target,
                    'match'          => array(
                        // Image identity is a URL (case/query significant) —
                        // src normalization, never the text fold.
                        'text'       => $target === 'image'
                            ? pcm_conn_normalize_src((string) ($r['match']['text'] ?? ''))
                            : pcm_conn_normalize_text((string) ($r['match']['text'] ?? '')),
                        'occurrence' => (int) ($r['match']['occurrence'] ?? 0),
                    ),
                    'replacement'    => wp_kses_post((string) ($r['replacement'] ?? '')),
                    'active'         => !empty($r['active']),
                    'changesetId'    => isset($r['changesetId']) ? (int) $r['changesetId'] : null,
                    'sourceChangeId' => isset($r['sourceChangeId']) ? (int) $r['sourceChangeId'] : null,
                    'anchor'         => (isset($r['anchor']) && is_array($r['anchor'])) ? $r['anchor'] : null,
                );
                if ($target === 'image') {
                    // Whitelist + re-encode: replacement is EXACTLY {alt?,title?,hidden?}.
                    // `hidden` exists only in schema 5 (a 3.0.2 hub payload never carries it).
                    $set = json_decode((string) ($r['replacement'] ?? ''), true);
                    $set = is_array($set) ? $set : array();
                    $img = array();
                    foreach (array('alt', 'title') as $a) {
                        if (array_key_exists($a, $set)) { $img[$a] = sanitize_text_field((string) $set[$a]); }
                    }
                    if ($schema >= 5 && !empty($set['hidden'])) { $img = array('hidden' => true); }
                    if (empty($img)) { continue; } // nothing rewritable — not a rule
                    $row['replacement'] = (string) wp_json_encode($img);
                }
                if ($target === 'section' || $target === 'sectionInsert' || $target === 'sectionRemove' || ($target === 'heading' && $schema >= 3)) {
                    $sec = (isset($r['section']) && is_array($r['section'])) ? $r['section'] : array();
                    $row['section'] = array('level' => max(0, min(6, (int) ($sec['level'] ?? 0))));
                    if ($target === 'section' || $target === 'sectionRemove') {
                        // Fingerprint is stored as-is: the hub computed it via the SAME
                        // normalization (harness-pinned) — re-normalizing per line here
                        // would be redundant, and the compare side normalizes live text.
                        $row['section']['fingerprint'] = (string) ($sec['fingerprint'] ?? '');
                        if ($target === 'sectionRemove') { $row['replacement'] = ''; } // removal carries no content
                    } elseif ($target === 'sectionInsert') {
                        $row['section']['position'] = ((string) ($sec['position'] ?? 'after')) === 'before' ? 'before' : 'after';
                    } else {
                        // Heading instruction (v2.2): allOccurrences keeps the
                        // compiled-override semantics; else occurrence-targeted.
                        $row['section']['newLevel']       = max(1, min(6, (int) ($sec['newLevel'] ?? ($sec['level'] ?? 2))));
                        $row['section']['allOccurrences'] = !empty($sec['allOccurrences']);
                        $row['section']['frameOnly']      = !empty($sec['frameOnly']);
                        $row['replacement'] = sanitize_text_field((string) ($r['replacement'] ?? ''));
                    }
                }
                $clean[] = $row;
            }
            update_option('pcm_conn_view_ver', (int) get_option('pcm_conn_view_ver', 0) + 1, false); // served views are stale
            if ($site_scope) {
                if (empty($clean)) { delete_option('pcm_conn_rules_site'); }
                else { update_option('pcm_conn_rules_site', array_values($clean), true); }
                return array('stored' => count($clean), 'schemaVersion' => 3, 'scope' => 'site');
            }
            // Page state (3.0.6, page-versioning contract): the hub's {version, fingerprint}
            // for the rule set just stored — kept beside the rules, echoed on the snapshot
            // reply. Both values are hub-computed; the connector never derives either. A push
            // WITHOUT one clears the stored state: it described a rule set this push replaced.
            $state = (isset($p['pageState']) && is_array($p['pageState']) && isset($p['pageState']['version'], $p['pageState']['fingerprint']))
                ? array('version' => (int) $p['pageState']['version'], 'fingerprint' => (string) $p['pageState']['fingerprint'])
                : null;
            if ($state !== null) { update_option('pcm_conn_page_state_' . $pid, $state, false); } // autoload OFF — same as the rules
            else { delete_option('pcm_conn_page_state_' . $pid); }
            pcm_conn_rules_save($pid, $clean);
            delete_transient('pcm_conn_snap_' . $pid); // rules changed → snapshot cache is stale
            pcm_conn_purge_caches($pid);
            return array('stored' => count($clean), 'schemaVersion' => 1);
        }),
    ));
    // Page snapshot v1 (3.0.0 — replaces BOTH scanners' parsing): the connector
    // does NOT parse — the hub does all of it from this one document. TWO views
    // (v2.2): mode=input (default) = the page as RULES-INPUT (?pcm_snap is
    // serving-excluded — exactly what rules match against); mode=served = what
    // a visitor sees (?pcm_view is NOT excluded, rules apply). Tiers preserved
    // verbatim from scan-content (WAF/host survival): rendered loopback →
    // in-process the_content → raw storage + wpautop → honest loopback_blocked.
    // Served-mode tier-2/3 fallbacks apply the stored rules to the fallback
    // render so WAF-blocked sites stay honest.
    register_rest_route('pcm-conn/v1', '/snapshot', array(
        'methods' => 'GET',
        'permission_callback' => $perm,
        'callback' => function ($req) {
            $pid = absint($req->get_param('post_id'));
            if (!$pid || !get_post($pid)) { return new WP_REST_Response(array('error' => 'not_found'), 404); }
            $served = ((string) $req->get_param('mode')) === 'served';
            // Cache first: repeat outline-opens must not cost a loopback.
            // Input view busts on save_post + the post's rules push. The SERVED
            // view is version-stamped instead: ANY rules push (site rules touch
            // every page) bumps pcm_conn_view_ver, old entries expire by TTL.
            $cache_key = $served
                ? 'pcm_conn_snap_served_' . (int) get_option('pcm_conn_view_ver', 0) . '_' . $pid
                : 'pcm_conn_snap_' . $pid;
            // pageState (3.0.6) is attached AFTER the cache on every reply — never
            // baked into the transient, so a cached document can't echo stale state.
            $cached = get_transient($cache_key);
            if (is_array($cached)) { return pcm_conn_page_state_echo($cached, $pid); }
            $result = null;
            // Tier 1 — rendered page via the LOCKED loopback (TRUE serving order,
            // incl. builder output).
            $live = $served
                ? pcm_conn_loopback_fetch($pid, 'pcm_view', 'snapshot-served')
                : pcm_conn_loopback_fetch($pid, 'pcm_snap', 'snapshot');
            if ($live !== '') {
                $result = array('html' => $live, 'tier' => 'rendered');
            } else {
                // Tier 2 — in-process render via the_content (Divi/WPBakery/shortcode
                // builders hook it; no HTTP, no workers consumed). Fail-safe to tier 3.
                $html = '';
                try {
                    $html = (string) apply_filters('the_content', (string) get_post($pid)->post_content);
                } catch (\Throwable $e) {
                    $html = '';
                }
                if (trim($html) !== '') {
                    $result = array('html' => $html, 'tier' => 'content-rendered');
                } else {
                    // Tier 3 — raw storage (wpautop for classic content): plain WP/Gutenberg.
                    // NOTE (documented limitation): tier-2/3 document order can differ from
                    // rendered order in edge cases — the serving stale-flag is the safety
                    // net; never a wrong swap, at worst a flagged miss.
                    $raw = (string) get_post($pid)->post_content;
                    if (stripos($raw, '<p') === false && trim($raw) !== '') { $raw = wpautop($raw); }
                    $result = array('html' => $raw, 'tier' => 'content');
                    if (trim($raw) === '') {
                        // Loopback failed AND storage has nothing — say exactly that.
                        $result['error'] = 'loopback_blocked';
                    }
                }
                if ($served && trim((string) $result['html']) !== '' && get_option('pcm_conn_rules_off') !== '1') {
                    try {
                        $result['html'] = pcm_conn_apply_rules((string) $result['html'], array_merge(pcm_conn_rules_site(), pcm_conn_rules_for($pid)), $pid);
                    } catch (\Throwable $e) {
                        // Fail-to-fallback-render — a display view must never error.
                    }
                }
            }
            // Explicit view marker: a pre-3.0.1 connector ignores ?mode and would
            // answer with the INPUT view — the hub requires this marker before
            // trusting a response as served.
            $result['view'] = $served ? 'served' : 'input';
            set_transient($cache_key, $result, max(1, (int) pcm_conn_cfg('snapshotCacheTtl')));
            return pcm_conn_page_state_echo($result, $pid);
        },
    ));
});
PHP;
    }
}
