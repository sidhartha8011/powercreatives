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
 * Description: Connects this site to a Power Creatives hub — exposes SEO meta in REST, manages site-wide robots.txt + JSON-LD, serves /llms.txt + /llm-info/, and shows a one-paste connection code.
 * Version: 1.3.1
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
