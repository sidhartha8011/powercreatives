<?php
/**
 * Sites Service — Publishing & Connection Business Logic
 *
 * Handles:
 *   - App password encryption/decryption (openssl + wp_salt)
 *   - Connection testing (GET /wp-json/wp/v2/users/me)
 *   - Article publishing via WP REST API with Application Passwords
 *
 * This replaces the backup's `wpService.ts` (500+ lines) with ~130 lines
 * by using wp_remote_post() and WP's built-in Application Passwords.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Sites_Service
{

    /**
     * Encryption method — AES-256-CBC provides strong symmetric encryption.
     *
     * @var string
     */
    private const CIPHER = 'aes-256-cbc';

    /**
     * Option holding every per-site recurring content schedule, keyed by
     * (string) site id. Stored as a WP option rather than a column because
     * the sites table has no config JSON field and this plan's only schema
     * change is elsewhere (F3) — an option is equally additive and the
     * daily scan reads all rules in one get_option() anyway.
     *
     * Rule shape: {enabled: bool, frequency: 'daily'|'weekly'|'monthly',
     * count: int, templateId: int, publishingMode: 'draft'|'publish'|'schedule',
     * niche: string, userId: int, lastRunAt: 'Y-m-d H:i:s'|null}.
     *
     * @var string
     */
    public const SCHEDULES_OPTION = 'pcm_site_schedules';

    /**
     * Read one site's recurring-schedule rule, or null when none is set.
     *
     * @param int $site_id Site ID (ownership is the caller's job — the
     *                     controller resolves the site through
     *                     PCM_DB::get_site() before calling this).
     * @return array|null
     */
    public static function get_site_schedule(int $site_id): ?array
    {
        $all  = get_option(self::SCHEDULES_OPTION, array());
        $rule = is_array($all) ? ($all[(string) $site_id] ?? null) : null;
        return is_array($rule) ? $rule : null;
    }

    /**
     * Save (or replace) one site's recurring-schedule rule. The caller passes
     * already-sanitized fields; this preserves the existing lastRunAt stamp so
     * editing a rule doesn't make it immediately due again.
     *
     * @param int   $site_id Site ID.
     * @param array $rule    Sanitized rule fields (without lastRunAt).
     * @return array The stored rule.
     */
    public static function set_site_schedule(int $site_id, array $rule): array
    {
        $all = get_option(self::SCHEDULES_OPTION, array());
        if (!is_array($all)) {
            $all = array();
        }
        $existing            = is_array($all[(string) $site_id] ?? null) ? $all[(string) $site_id] : array();
        $rule['lastRunAt']   = $existing['lastRunAt'] ?? null;
        $all[(string) $site_id] = $rule;
        update_option(self::SCHEDULES_OPTION, $all, false);
        return $rule;
    }

    /**
     * Encrypt an Application Password for secure storage.
     *
     * Uses the WordPress AUTH_KEY salt as the encryption key, ensuring
     * passwords are tied to the specific WP installation.
     *
     * @param string $password Plain-text Application Password.
     * @return string base64-encoded encrypted string (iv:ciphertext).
     */
    public static function encrypt_password(string $password): string
    {
        $key = hash('sha256', wp_salt('auth'), true);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::CIPHER));
        $encrypted = openssl_encrypt($password, self::CIPHER, $key, 0, $iv);

        // Store as iv:ciphertext (both base64)
        return base64_encode($iv) . ':' . $encrypted;
    }

    /**
     * Decrypt a stored Application Password.
     *
     * @param string $stored Encrypted password from DB.
     * @return string Decrypted plain-text password.
     * @throws \RuntimeException If decryption fails.
     */
    public static function decrypt_password(string $stored): string
    {
        $parts = explode(':', $stored, 2);
        if (count($parts) !== 2) {
            throw new \RuntimeException('Invalid encrypted password format.');
        }

        $key = hash('sha256', wp_salt('auth'), true);
        $iv = base64_decode($parts[0]);
        $decrypted = openssl_decrypt($parts[1], self::CIPHER, $key, 0, $iv);

        if ($decrypted === false) {
            throw new \RuntimeException('Failed to decrypt Application Password.');
        }

        return $decrypted;
    }

    /**
     * Authenticated REST call to a connected remote site. Uses the `?rest_route=`
     * form (permalink-agnostic) + Basic auth via the stored Application Password.
     *
     * @param object     $site   wp_pcm_sites row (url, username, appPassword).
     * @param string     $method 'GET' | 'POST'.
     * @param string     $route  REST route, e.g. '/wp/v2/posts'.
     * @param array      $query  Extra query args (per_page, _fields, …).
     * @param array|null $body   JSON body for write requests.
     * @return array{status:int,body:mixed}|\WP_Error
     */
    public static function remote_rest(object $site, string $method, string $route, array $query = array(), ?array $body = null, int $timeout = 30)
    {
        $password = self::decrypt_password((string) $site->appPassword);
        $qs  = array_merge(array('rest_route' => $route), $query);
        $url = rtrim((string) $site->url, '/') . '/?' . http_build_query($qs);
        $headers = array(
            'Authorization' => 'Basic ' . base64_encode($site->username . ':' . $password),
            'Content-Type'  => 'application/json',
        );
        // DELETE tunnels as POST + WP-core's native method override: web
        // servers in front of client sites can refuse the DELETE method
        // outright (proven live 2026-07-14 — bare 405 with an EMPTY body,
        // the request never reached WordPress), and the override wins on
        // servers that allow DELETE too. One transport fix for every
        // remote-DELETE consumer, present and future.
        if (strtoupper($method) === 'DELETE') {
            $headers['X-HTTP-Method-Override'] = 'DELETE';
            $method = 'POST';
        }
        $args = array(
            'method'    => $method,
            'headers'   => $headers,
            'timeout'   => max(1, $timeout),
            'sslverify' => true,
        );
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            // THE HEARTBEAT (gap fad81ea P1): a transport failure IS a health
            // signal — recorded for free, no probe spent.
            self::record_heartbeat((int) $site->id, false, $response->get_error_message());
            return $response;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        // Any HTTP ANSWER proves the site alive (a 404/401 is an answer);
        // only 5xx = the site is up but broken — recorded as unhealthy.
        self::record_heartbeat((int) $site->id, $status < 500, $status >= 500 ? sprintf('HTTP %d', $status) : null);
        return array(
            'status' => $status,
            'body'   => json_decode(wp_remote_retrieve_body($response), true),
        );
    }

    /**
     * Per-site health record (gap fad81ea): every real site interaction
     * writes it (source 'heartbeat'); the cron probe writes the same shape
     * (source 'probe'). ONE map, autoload off — the dashboard's dots read
     * THIS, never live tests.
     *
     * @param int         $site_id Site id.
     * @param bool        $ok      Healthy?
     * @param string|null $error   The failure, when not.
     * @param string      $source  'heartbeat' | 'probe'.
     * @return void
     */
    public static function record_heartbeat(int $site_id, bool $ok, ?string $error = null, string $source = 'heartbeat'): void
    {
        if ($site_id <= 0) {
            return;
        }
        $map = get_option('pcm_site_health', array());
        if (!is_array($map)) {
            $map = array();
        }
        // THE FLAP GUARD (gap 23955b9 — the random red dot): ONE transport
        // blip (this machine's proven intermittent AV SSL-timeouts, a slow
        // site's cURL 28) must never paint a healthy site red. The stored
        // verdict flips to unhealthy only after failThreshold CONSECUTIVE
        // failures (hub data, merge-seeded); ONE success heals instantly
        // and resets the counter. The dots read the same map as always —
        // the entry's `fails` counter is additive.
        $cfg = get_option('pcm_sites_health_check');
        $cfg = is_array($cfg) ? $cfg : array();
        if (!isset($cfg['failThreshold'])) {
            $cfg['failThreshold'] = 2;
            update_option('pcm_sites_health_check', $cfg, false);
        }
        $threshold = max(1, (int) $cfg['failThreshold']);
        $prev      = is_array($map[$site_id] ?? null) ? $map[$site_id] : array();
        $fails     = $ok ? 0 : ((int) ($prev['fails'] ?? 0)) + 1;
        $verdict   = $ok ? true : ($fails >= $threshold ? false : (bool) ($prev['ok'] ?? true));
        $map[$site_id] = array(
            'ok'     => $verdict,
            'error'  => $verdict ? null : (string) ($error ?? __('The site did not answer.', 'power-creatives')),
            'at'     => time(),
            'source' => $source,
            'fails'  => $fails,
        );
        update_option('pcm_site_health', $map, false);
    }

    /**
     * The stored health map — millisecond reads at ANY fleet size.
     *
     * @return array<int, array{ok:bool,error:?string,at:int,source:string}>
     */
    public static function health_map(): array
    {
        $map = get_option('pcm_site_health', array());
        return is_array($map) ? $map : array();
    }

    /**
     * THE PROBE QUEUE (gap fad81ea P3): actively test ONLY the sites whose
     * heartbeat is stale — real traffic keeps busy sites verified for free;
     * the probe visits the silent ones. Cadence/staleness/batch are hub
     * DATA. Runs on WP-cron; each run is bounded (maxPerRun × timeout).
     *
     * @return void
     */
    public static function probe_stale_sites(): void
    {
        $cfg = get_option('pcm_sites_health_check');
        if (!is_array($cfg)) {
            $cfg = array();
        }
        $cfg += array('timeoutS' => 5, 'staleS' => 900, 'maxPerRun' => 3, 'intervalS' => 300);
        update_option('pcm_sites_health_check', $cfg, false);

        global $wpdb;
        $table = PCM_Schema::table('sites');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $sites = $wpdb->get_results("SELECT * FROM {$table}");
        $map   = self::health_map();
        $now   = time();
        $stale = array_values(array_filter((array) $sites, static function ($s) use ($map, $now, $cfg) {
            $rec = $map[(int) $s->id] ?? null;
            return $rec === null || ($now - (int) ($rec['at'] ?? 0)) > (int) $cfg['staleS'];
        }));
        // Oldest heartbeat first — nobody starves.
        usort($stale, static fn($a, $b): int => ((int) ($map[(int) $a->id]['at'] ?? 0)) <=> ((int) ($map[(int) $b->id]['at'] ?? 0)));
        foreach (array_slice($stale, 0, max(1, (int) $cfg['maxPerRun'])) as $site) {
            try {
                self::test_connection($site, max(1, (int) $cfg['timeoutS']));
                self::record_heartbeat((int) $site->id, true, null, 'probe');
            } catch (\Throwable $e) {
                self::record_heartbeat((int) $site->id, false, $e->getMessage(), 'probe');
            }
        }
    }

    /**
     * Upload an image (by URL) into a connected site's media library via /wp/v2/media,
     * returning the new attachment id + source URL. Raw-binary POST (the JSON remote_rest
     * can't do file uploads), Basic-authed with the stored app password.
     *
     * @return array{id:int,url:string}|\WP_Error
     */
    public static function remote_upload_media(object $site, string $image_url)
    {
        $img = wp_remote_get($image_url, array('timeout' => 30));
        if (is_wp_error($img)) {
            return new WP_Error('pcm_media_fetch', $img->get_error_message(), array('status' => 502));
        }
        $bytes = wp_remote_retrieve_body($img);
        if ($bytes === '') {
            return new WP_Error('pcm_media_empty', __('Could not read the source image.', 'power-creatives'), array('status' => 502));
        }
        $mime = wp_remote_retrieve_header($img, 'content-type');
        $mime = (is_string($mime) && str_starts_with($mime, 'image/')) ? $mime : 'image/jpeg';
        $name = basename((string) wp_parse_url($image_url, PHP_URL_PATH));
        if ($name === '' || strpos($name, '.') === false) {
            $ext  = str_contains($mime, 'png') ? 'png' : (str_contains($mime, 'webp') ? 'webp' : (str_contains($mime, 'gif') ? 'gif' : 'jpg'));
            $name = 'featured-' . time() . '.' . $ext;
        }
        $password = self::decrypt_password((string) $site->appPassword);
        $url      = rtrim((string) $site->url, '/') . '/?' . http_build_query(array('rest_route' => '/wp/v2/media'));
        $res = wp_remote_post($url, array(
            'headers' => array(
                'Authorization'       => 'Basic ' . base64_encode($site->username . ':' . $password),
                'Content-Type'        => $mime,
                'Content-Disposition' => 'attachment; filename="' . sanitize_file_name($name) . '"',
            ),
            'body'      => $bytes,
            'timeout'   => 60,
            'sslverify' => true,
        ));
        if (is_wp_error($res)) {
            return new WP_Error('pcm_media_upload', $res->get_error_message(), array('status' => 502));
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 300 || empty($body['id'])) {
            $msg = (is_array($body) && !empty($body['message'])) ? (string) $body['message'] : ('HTTP ' . $code);
            return new WP_Error('pcm_media_upload', $msg, array('status' => 502));
        }
        return array('id' => (int) $body['id'], 'url' => (string) ($body['source_url'] ?? ''));
    }

    /**
     * Installed version of the Power Creatives Connector on a connected site
     * (read live via its /wp/v2/plugins), or '' when it can't be read (no
     * connector installed, or the plugin list isn't readable by the app-password
     * user). THE single reader — the SEO module delegates here; never duplicate.
     */
    /**
     * Every installed copy of the connector on a site, newest first.
     *
     * A site can genuinely hold MORE THAN ONE: re-uploading the zip when an older
     * copy sits at a different plugin path leaves both installed, and only the
     * ACTIVE one registers the /pcm-conn/v1 routes. Reading just the first match
     * (as this used to) could report the shiny new inactive copy's version while
     * the old active one kept serving REST — the hub then showed "up to date"
     * while every connector call behaved like a pre-self-update build.
     *
     * @param object $site Site DB row.
     * @return array<int,array{plugin:string,version:string,active:bool}> Newest first.
     */
    public static function remote_connector_plugins(object $site): array
    {
        $res = self::remote_rest($site, 'GET', '/wp/v2/plugins', array('_fields' => 'plugin,name,version,status'));
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return array();
        }
        $found = array();
        foreach ($res['body'] as $plugin) {
            if (stripos((string) ($plugin['name'] ?? ''), 'Power Creatives Connector') === false) {
                continue;
            }
            $found[] = array(
                'plugin'  => (string) ($plugin['plugin'] ?? ''),
                'version' => (string) ($plugin['version'] ?? ''),
                'active'  => ($plugin['status'] ?? '') === 'active',
            );
        }
        usort($found, static fn($a, $b) => version_compare($b['version'], $a['version']));
        return $found;
    }

    /**
     * The connector version the site is actually RUNNING.
     *
     * Prefers the active copy — that is the one serving the routes. Falls back to
     * the newest installed copy so a deactivated connector still reports something
     * rather than looking uninstalled.
     *
     * @param object $site Site DB row.
     * @return string Version, or '' when no connector is installed/readable.
     */
    public static function remote_connector_version(object $site): string
    {
        $copies = self::remote_connector_plugins($site);
        foreach ($copies as $c) {
            if ($c['active']) {
                return $c['version'];
            }
        }
        return (string) ($copies[0]['version'] ?? '');
    }

    /**
     * Activate the newest installed connector copy when the running one is older.
     *
     * The self-heal for "this connector predates self-update": if a newer copy is
     * already sitting on the site but inactive, the hub can switch to it over the
     * SAME app-password channel — `status` is the one plugin field WordPress core's
     * REST API lets you write — and the new copy brings /update-now with it. This
     * turns the dead end into a one-call fix with no manual upload.
     *
     * @param object $site Site DB row.
     * @return array{switched:bool,from:string,to:string,message:string}
     */
    /**
     * Connector state on a connected site, honestly classified:
     *   active   — a connector copy is running (routes should answer)
     *   inactive — installed but no copy active (one PUT away from working)
     *   missing  — the plugins list is readable and holds NO connector
     *   unknown  — the plugins list is unreadable (app-password user lacks
     *              activate_plugins, or the site errored) — NOT the same as
     *              missing, and the UI must not nag on it.
     *
     * Born from massagegoteborg.nu: the SEO table sat silently blank because
     * the site had no connector at all, and nothing anywhere said so.
     *
     * @return array{status:string,version:string,copies:int}
     */
    /** Transient recording that a site's connector answered 404 for a route we need. */
    private static function missing_route_key(int $site_id, string $route): string
    {
        return 'pcm_conn_no_' . preg_replace('/[^a-z0-9]/', '', strtolower($route)) . '_' . $site_id;
    }

    /**
     * Record whether this site's connector serves a route the hub depends on.
     *
     * An OLD connector is not "missing" and not broken — it answers everything it
     * knows and 404s the rest — so nothing in the UI could ever say why a feature
     * was degraded. brizy.profitmedia.pro proved the cost: its connector predates
     * /head-tags, so the SEO table fell back to the slow public-permalink reader
     * and the meta columns looked broken, with no hint that a one-click connector
     * update was the actual fix.
     *
     * @param bool $served True clears the flag, false records the 404 (12h).
     */
    public static function mark_connector_route(int $site_id, string $route, bool $served): void
    {
        $key = self::missing_route_key($site_id, $route);
        if ($served) {
            delete_transient($key);
            return;
        }
        set_transient($key, 1, 12 * HOUR_IN_SECONDS);
    }

    /** True when this site's connector is known NOT to serve $route. */
    public static function connector_lacks_route(int $site_id, string $route): bool
    {
        return (bool) get_transient(self::missing_route_key($site_id, $route));
    }

    public static function connector_status(object $site): array
    {
        // An active-but-OLD connector degrades features silently; surface it so the
        // banner can offer the self-update that already exists.
        // Any hub-relied route the connector was seen NOT to serve = an older build.
        // head-tags (meta reader) and media (thumbnail bytes for bot-walled sites) today.
        $outdated = self::connector_lacks_route((int) ($site->id ?? 0), 'head-tags')
            || self::connector_lacks_route((int) ($site->id ?? 0), 'media');
        $res = self::remote_rest($site, 'GET', '/wp/v2/plugins', array('_fields' => 'plugin,name,version,status'));
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return array('status' => 'unknown', 'version' => '', 'copies' => 0, 'outdated' => $outdated);
        }
        $copies = array();
        foreach ($res['body'] as $plugin) {
            if (stripos((string) ($plugin['name'] ?? ''), 'Power Creatives Connector') === false) {
                continue;
            }
            $copies[] = array(
                'version' => (string) ($plugin['version'] ?? ''),
                'active'  => ($plugin['status'] ?? '') === 'active',
            );
        }
        usort($copies, static fn($a, $b) => version_compare($b['version'], $a['version']));
        foreach ($copies as $c) {
            if ($c['active']) {
                return array('status' => 'active', 'version' => $c['version'], 'copies' => count($copies), 'outdated' => $outdated);
            }
        }
        return array(
            'status'   => count($copies) > 0 ? 'inactive' : 'missing',
            'version'  => (string) ($copies[0]['version'] ?? ''),
            'copies'   => count($copies),
            'outdated' => $outdated,
        );
    }

    public static function activate_newest_connector(object $site): array
    {
        $out = array('switched' => false, 'from' => '', 'to' => '', 'message' => '');
        $copies = self::remote_connector_plugins($site);
        if (count($copies) === 0) {
            $out['message'] = 'No connector plugin is installed on this site.';
            return $out;
        }

        $newest = $copies[0];
        $active = null;
        foreach ($copies as $c) {
            if ($c['active']) { $active = $c; break; }
        }
        $out['from'] = (string) ($active['version'] ?? '');
        $out['to']   = $newest['version'];

        if ($active !== null && $active['plugin'] === $newest['plugin']) {
            $out['message'] = 'The newest installed connector is already the active one.';
            return $out;
        }
        if ($newest['plugin'] === '') {
            $out['message'] = 'Could not identify the connector plugin file.';
            return $out;
        }

        // Core exposes plugin activation as PUT /wp/v2/plugins/<plugin> {status}.
        $res = self::remote_rest(
            $site,
            'PUT',
            '/wp/v2/plugins/' . $newest['plugin'],
            array(),
            array('status' => 'active'),
            60
        );
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300) {
            $out['message'] = is_wp_error($res)
                ? $res->get_error_message()
                : 'Activation failed (HTTP ' . (int) ($res['status'] ?? 0) . ').';
            return $out;
        }

        $out['switched'] = true;
        $out['message']  = 'Activated the newer connector already installed on this site.';
        return $out;
    }

    /**
     * The latest connector version this hub ships (what a site self-updates to).
     * Read from the SEO-Hub's cached connector artifact; '' when unavailable.
     */
    public static function latest_connector_version(): string
    {
        if (!class_exists('PCM_SEOHub_Service')) {
            $seohub = dirname(__DIR__) . '/seohub/service.php';
            if (file_exists($seohub)) {
                require_once $seohub;
            }
        }
        if (!class_exists('PCM_SEOHub_Service') || !method_exists('PCM_SEOHub_Service', 'connector_artifact')) {
            return '';
        }
        $artifact = PCM_SEOHub_Service::connector_artifact();
        return isset($artifact['error']) ? '' : (string) ($artifact['version'] ?? '');
    }

    /**
     * Test connection to a WordPress site.
     *
     * Calls GET /wp-json/wp/v2/users/me to verify credentials.
     *
     * @param object $site Site DB row.
     * @return array Connection result with site info.
     */
    public static function test_connection(object $site, int $timeout = 15): array
    {
        $password = self::decrypt_password($site->appPassword);
        // Use the `?rest_route=` form, NOT pretty `/wp-json/...`: the latter 404s
        // on remotes with plain permalinks (common on LiteSpeed / shared hosting).
        // The query-var form always resolves regardless of permalink settings.
        $url = rtrim($site->url, '/') . '/?rest_route=/wp/v2/users/me';

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($site->username . ':' . $password),
            ),
            'timeout'   => max(1, $timeout),
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException('Connection failed: ' . $response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            throw new \RuntimeException("Authentication failed (HTTP {$status}). Check username and Application Password.");
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return array(
            'success'  => true,
            'siteName' => $body['name'] ?? $site->name,
            'siteUrl'  => $site->url,
            'userId'   => $body['id'] ?? null,
            'roles'    => $body['roles'] ?? array(),
        );
    }

    /**
     * D3 — pull a single remote post's current status (and permalink) from a
     * connected site, for syncing our stored article record with reality (e.g.
     * a post that was deleted or unpublished directly on WordPress).
     *
     * GET `/wp/v2/posts/{id}` (not `?context=edit`, which 401s for anyone but the
     * post's own author) — the endpoint already includes `status`/`link` for the
     * authenticated app-password user regardless of author. Wholly failure-
     * isolated: every unexpected outcome (network error, non-200/404 status,
     * unparseable body) degrades to `null` ("unknown — do nothing"), never a
     * thrown exception.
     *
     * @param object $site           Site DB row.
     * @param int    $remote_post_id The remote post ID to check.
     * @return array{status:string,link?:string}|null `['status' => 'deleted']` on a
     *   404, `['status' => ..., 'link' => ...]` on 200, or null on any other
     *   failure/ambiguity.
     */
    public static function fetch_remote_post_status(object $site, int $remote_post_id): ?array
    {
        try {
            $password = self::decrypt_password((string) $site->appPassword);
            $auth = 'Basic ' . base64_encode($site->username . ':' . $password);
            $url = rtrim((string) $site->url, '/') . '/?' . http_build_query(array(
                'rest_route' => '/wp/v2/posts/' . $remote_post_id,
            ));

            $response = wp_remote_get($url, array(
                'headers'   => array('Authorization' => $auth),
                'timeout'   => 15,
                'sslverify' => true,
            ));

            if (is_wp_error($response)) {
                return null;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status === 404) {
                return array('status' => 'deleted');
            }
            if ($status === 200) {
                $body = json_decode((string) wp_remote_retrieve_body($response), true);
                if (!is_array($body)) {
                    return null;
                }
                return array(
                    'status' => (string) ($body['status'] ?? ''),
                    'link'   => (string) ($body['link'] ?? ''),
                );
            }
            return null; // any other status — unknown, do nothing
        } catch (\Throwable $e) {
            error_log('PCM fetch_remote_post_status failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Publish an article to a remote WordPress site.
     *
     * Uses POST /wp-json/wp/v2/posts with Basic Auth (Application Passwords).
     *
     * Publish-time enrichments (each failure-ISOLATED — the post still publishes if
     * any of them fails, they only error_log):
     *   - schema:  embeds a JSON-LD @graph into the OUTGOING content (never the stored
     *              hub article) when PCM_Article_Schema is loadable and the content does
     *              not already carry an `application/ld+json` block.
     *   - terms:   when $options['tags'] / $options['category'] are given, resolves them
     *              on the remote site (find-or-create) and attaches the term ids.
     *   - image:   when $article->featuredImage is set, sideloads it into the remote
     *              media library and sets it as the new post's featured image.
     *
     * @param object $site    Site DB row.
     * @param object $article Article DB row.
     * @param int    $user_id PCM user ID (for updating article record).
     * @param array  $options Optional publish options: `tags` (string[]), `category` (string),
     *   `schedule_date` (MySQL datetime string — a FUTURE date sends the post as a
     *   native WP `status:'future'` scheduled post instead of publishing immediately;
     *   a past/absent date publishes as before).
     *
     * @return array Publish result with post URL and ID.
     * @throws \RuntimeException On API failure.
     */
    public static function publish_to_site(object $site, object $article, int $user_id, array $options = array()): array
    {
        $password = self::decrypt_password($site->appPassword);
        $auth = 'Basic ' . base64_encode($site->username . ':' . $password);
        // `?rest_route=` form — works regardless of the remote's permalink settings
        // (pretty `/wp-json/...` 404s on plain-permalink hosts). See test_connection().
        $url = rtrim($site->url, '/') . '/?rest_route=/wp/v2/posts';

        // A4-wire: embed JSON-LD schema into the content being SENT (not the stored
        // hub article). Failure-isolated — returns the content unchanged on any error.
        $content = self::maybe_embed_schema($site, $article, (string) $article->content);

        // A6: sideload any in-content <figure.pcm-in-content-media> images (AI
        // images AND quickchart.io charts) into the remote media library and
        // rewrite their src to the remote URL, so the client site serves them
        // locally. Failure-isolated — a broken/unreachable asset keeps its
        // original src; publish never fails because of media.
        $content = self::sideload_in_content_media($site, $content);

        // D4: a FUTURE `schedule_date` posts as native WP 'future' status (AutoPress
        // parity for manually publishing a not-yet-due scheduled item) instead of
        // publishing immediately. A past/missing date keeps the historical behavior.
        $schedule_date = !empty($options['schedule_date']) ? (string) $options['schedule_date'] : '';
        $schedule_ts   = $schedule_date !== '' ? strtotime($schedule_date) : false;
        $is_future     = $schedule_ts !== false && $schedule_ts > strtotime(current_time('mysql'));

        // Build the WP REST API post payload
        $post_data = array(
            'title'   => $article->title,
            'content' => $content,
            'status'  => $is_future ? 'future' : 'publish',
            'slug'    => $article->slug,
        );
        if ($is_future) {
            // WP's REST API accepts ISO 8601 for `date`. The article row still
            // records publishedUrl/publishedPostId as usual below — WP returns the
            // permalink even for a future post.
            $post_data['date'] = date('c', $schedule_ts);
        }

        // Add meta if available
        if (!empty($article->metaTitle) || !empty($article->metaDescription)) {
            $post_data['meta'] = array();
            if (!empty($article->metaTitle)) {
                $post_data['meta']['_yoast_wpseo_title'] = $article->metaTitle;
            }
            if (!empty($article->metaDescription)) {
                $post_data['meta']['_yoast_wpseo_metadesc'] = $article->metaDescription;
            }
        }

        // A5: resolve remote tags/category (find-or-create) and attach their ids.
        // Failure-isolated — a term that can't be resolved is simply omitted.
        self::apply_remote_terms($site, $options, $post_data);

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => $auth,
                'Content-Type'  => 'application/json',
            ),
            'body'      => wp_json_encode($post_data),
            'timeout'   => 30,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException('Publishing failed: ' . $response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300) {
            $code    = is_array($body) ? (string) ($body['code'] ?? '') : '';
            $message = is_array($body) ? (string) ($body['message'] ?? '') : '';
            if ($message === '') {
                $message = "HTTP {$status}";
            }

            // The connected Application-Password user's ROLE lacks the capability
            // to create or publish this post. WordPress signals it with one of
            // three REST codes (verified against wp-includes REST posts
            // controller): `rest_cannot_create` (no `create_posts`/`edit_posts`
            // — e.g. a Subscriber; message "…create posts as this user"),
            // `rest_cannot_publish` (can draft but not publish — e.g. a
            // Contributor; message "…publish posts in this post type"), and
            // `rest_cannot_edit_others` (author-mismatch — unreachable here since
            // we never send an `author`, kept defensively). The reported Swedish
            // "…skapa inlägg som om du vore denna användare" is rest_cannot_create.
            // Key on the UNtranslated `code` (the message is localized) and
            // surface an actionable remedy instead of the raw WordPress text.
            if (in_array($code, array('rest_cannot_create', 'rest_cannot_publish', 'rest_cannot_edit_others'), true)
                || $status === 403
            ) {
                // 403 is included on purpose: the code above only fires for WP's own
                // three codes, but a security plugin (or a hardened REST setup) can
                // return 403 with its own code, and the message is LOCALIZED — the
                // reported failure arrived in Swedish and fell through to the raw text
                // below. A 403 on this request means "not permitted" whatever emitted it.
                throw new \RuntimeException(sprintf(
                    /* translators: %s = the Application Password username on the connected site. */
                    __(
                        'The connected site won\'t let the user "%s" create or publish posts — that user\'s role lacks the required permission (this is a WordPress user-role issue, not a connector one). Reconnect the site (Sites → this site → credentials) with an Application Password from an Editor or Administrator account.',
                        'power-creatives'
                    ),
                    (string) $site->username
                ));
            }

            // 401 = the credentials themselves were rejected (Application Password
            // revoked, regenerated, or the username changed) — a different remedy
            // from a role problem, so say so rather than lumping them together.
            if ($status === 401) {
                throw new \RuntimeException(sprintf(
                    /* translators: %s = the Application Password username on the connected site. */
                    __(
                        'The connected site rejected the credentials for "%s" — the Application Password is wrong, revoked or regenerated. Create a new one on the site (Users → Profile → Application Passwords) and reconnect it under Sites.',
                        'power-creatives'
                    ),
                    (string) $site->username
                ));
            }

            throw new \RuntimeException("WordPress API error: {$message}");
        }

        $post_url = $body['link'] ?? '';
        $post_id = $body['id'] ?? 0;

        // A2/A3: sideload the featured image onto the just-CREATED post. Fully
        // failure-isolated (error_log only) — a broken image must never fail publish.
        if (!empty($article->featuredImage) && (int) $post_id > 0) {
            self::push_featured_image(
                $site,
                $auth,
                (string) $article->featuredImage,
                (string) $article->slug,
                (int) $post_id,
                (string) $article->title
            );
        }

        // Update article record with publish info
        PCM_DB::update_article((int)$article->id, $user_id, array(
            'status'          => 'published',
            'publishedUrl'    => $post_url,
            'publishedPostId' => $post_id,
            'siteId'          => (int)$site->id,
            'publishedAt'     => current_time('mysql'),
        ));

        return array(
            'success' => true,
            'postId'  => $post_id,
            'postUrl' => $post_url,
            'siteId'  => (int)$site->id,
        );
    }

    /**
     * A4-wire — best-effort JSON-LD schema embed for the OUTGOING post content only.
     *
     * Skips silently (returns $content unchanged) when the content already carries an
     * `application/ld+json` block, when PCM_Article_Schema can't be loaded, or on any
     * throwable. Never mutates the stored hub article.
     *
     * @param object $site    Site row (url/name).
     * @param object $article Article row (title/metaDescription/slug/featuredImage).
     * @param string $content The content about to be sent.
     * @return string Content with the schema appended, or the original on skip/failure.
     */
    private static function maybe_embed_schema(object $site, object $article, string $content): string
    {
        try {
            if (stripos($content, 'application/ld+json') !== false) {
                return $content; // already carries a schema block — don't double-embed
            }
            if (!class_exists('PCM_Article_Schema')) {
                $f = dirname(__DIR__) . '/strategy/class-pcm-article-schema.php';
                if (file_exists($f)) {
                    require_once $f;
                }
            }
            if (!class_exists('PCM_Article_Schema')) {
                return $content; // step A4 file not present yet — skip silently
            }

            $site_url  = rtrim((string) $site->url, '/');
            $slug      = (string) ($article->slug ?? '');
            $page_url  = $slug !== '' ? $site_url . '/' . ltrim($slug, '/') : $site_url;
            $site_name = (string) ($site->name ?? '');

            $args = array(
                'title'         => (string) ($article->title ?? ''),
                'description'   => (string) ($article->metaDescription ?? ''),
                'url'           => $page_url,
                'siteUrl'       => $site_url,
                'siteName'      => $site_name,
                'orgName'       => $site_name,
                'keywords'      => array(),
                'datePublished' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s'),
            );
            if (!empty($article->featuredImage)) {
                $args['imageUrl'] = (string) $article->featuredImage;
            }

            $block = PCM_Article_Schema::render($args);
            if (is_string($block) && $block !== '') {
                return $content . "\n" . $block;
            }
        } catch (\Throwable $e) {
            error_log('PCM publish schema embed failed: ' . $e->getMessage());
        }
        return $content;
    }

    /**
     * A5 — resolve the requested tags/category on the remote site and attach their
     * ids to the post payload. Each term is resolved independently; an unresolvable
     * term is simply omitted. Wholly failure-isolated (error_log only).
     *
     * @param object $site      Site row.
     * @param array  $options   `tags` (string[]) and/or `category` (string).
     * @param array  $post_data Payload (by reference) to receive `tags`/`categories`.
     */
    private static function apply_remote_terms(object $site, array $options, array &$post_data): void
    {
        try {
            $tag_ids = array();
            if (!empty($options['tags']) && is_array($options['tags'])) {
                foreach ($options['tags'] as $name) {
                    $name = trim((string) $name);
                    if ($name === '') {
                        continue;
                    }
                    $id = self::resolve_remote_term($site, 'tags', $name);
                    if ($id !== null) {
                        $tag_ids[] = $id;
                    }
                }
            }
            if (!empty($tag_ids)) {
                $post_data['tags'] = array_values(array_unique($tag_ids));
            }

            if (!empty($options['category'])) {
                $cat = trim((string) $options['category']);
                if ($cat !== '') {
                    $cat_id = self::resolve_remote_term($site, 'categories', $cat);
                    if ($cat_id !== null) {
                        $post_data['categories'] = array($cat_id);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('PCM publish term resolution failed: ' . $e->getMessage());
        }
    }

    /**
     * A5 — find-or-create a term on the remote site's taxonomy, returning its id.
     *
     * GETs `/wp/v2/{route}?search=<name>&per_page=1` and reuses an exact (case-
     * insensitive) name match; otherwise POSTs `/wp/v2/{route}` `{name}` and uses the
     * new id (or the `term_exists` error's term_id on a race). Returns null on any failure.
     *
     * @param object $site           Site row (url/username/appPassword).
     * @param string $taxonomy_route 'tags' | 'categories'.
     * @param string $name           Term name to resolve.
     * @return int|null Term id, or null when it can't be resolved.
     */
    private static function resolve_remote_term(object $site, string $taxonomy_route, string $name): ?int
    {
        try {
            $password = self::decrypt_password((string) $site->appPassword);
            $auth = 'Basic ' . base64_encode($site->username . ':' . $password);
            $base = rtrim((string) $site->url, '/');

            // 1. Search for an existing exact-name match.
            $get_url = $base . '/?' . http_build_query(array(
                'rest_route' => '/wp/v2/' . $taxonomy_route,
                'search'     => $name,
                'per_page'   => 1,
            ));
            $res = wp_remote_get($get_url, array(
                'headers'   => array('Authorization' => $auth),
                'timeout'   => 15,
                'sslverify' => true,
            ));
            if (!is_wp_error($res)) {
                $code = (int) wp_remote_retrieve_response_code($res);
                $rows = json_decode((string) wp_remote_retrieve_body($res), true);
                if ($code >= 200 && $code < 300 && is_array($rows)) {
                    foreach ($rows as $row) {
                        if (is_array($row) && isset($row['id'], $row['name'])
                            && strcasecmp((string) $row['name'], $name) === 0
                        ) {
                            return (int) $row['id'];
                        }
                    }
                }
            }

            // 2. No match → create it.
            $post_url = $base . '/?' . http_build_query(array('rest_route' => '/wp/v2/' . $taxonomy_route));
            $create = wp_remote_post($post_url, array(
                'headers'   => array('Authorization' => $auth, 'Content-Type' => 'application/json'),
                'body'      => wp_json_encode(array('name' => $name)),
                'timeout'   => 15,
                'sslverify' => true,
            ));
            if (!is_wp_error($create)) {
                $code = (int) wp_remote_retrieve_response_code($create);
                $body = json_decode((string) wp_remote_retrieve_body($create), true);
                if ($code >= 200 && $code < 300 && is_array($body) && !empty($body['id'])) {
                    return (int) $body['id'];
                }
                // A concurrent create loses with `term_exists` (HTTP 400) carrying the id.
                if (is_array($body) && isset($body['data']['term_id']) && (int) $body['data']['term_id'] > 0) {
                    return (int) $body['data']['term_id'];
                }
            }
        } catch (\Throwable $e) {
            error_log('PCM resolve_remote_term failed: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * A2/A3 — sideload $image_url into the remote media library and set it as the
     * post's featured image. Deterministic alt_text/title (the article title — no LLM
     * call; a simplification over per-image generated copy). Wholly failure-isolated:
     * every branch returns quietly (error_log only) so a bad image never fails publish.
     *
     * @param object $site           Site row.
     * @param string $auth           Prebuilt `Basic …` Authorization header value.
     * @param string $image_url      Source image URL to sideload.
     * @param string $slug           Post slug (used for the uploaded filename).
     * @param int    $remote_post_id The just-created remote post id.
     * @param string $title          Article title (media alt_text + title).
     */
    private static function push_featured_image(object $site, string $auth, string $image_url, string $slug, int $remote_post_id, string $title): void
    {
        try {
            // 1. Fetch the source image.
            $img = wp_remote_get($image_url, array('timeout' => 30));
            if (is_wp_error($img) || (int) wp_remote_retrieve_response_code($img) !== 200) {
                return;
            }
            $bytes = wp_remote_retrieve_body($img);
            if ($bytes === '' || $bytes === null) {
                return;
            }
            $mime = (string) wp_remote_retrieve_header($img, 'content-type');
            if ($mime === '') {
                $mime = 'image/jpeg';
            }
            $ext      = self::ext_from_mime($mime);
            $filename = ($slug !== '' ? $slug : ('featured-' . time())) . '.' . $ext;
            $base     = rtrim((string) $site->url, '/');

            // 2. Upload the raw bytes to the remote media library.
            $media_url = $base . '/?' . http_build_query(array('rest_route' => '/wp/v2/media'));
            $up = wp_remote_post($media_url, array(
                'headers' => array(
                    'Authorization'       => $auth,
                    'Content-Type'        => $mime,
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ),
                'body'      => $bytes,
                'timeout'   => 60,
                'sslverify' => true,
            ));
            if (is_wp_error($up) || (int) wp_remote_retrieve_response_code($up) !== 201) {
                return;
            }
            $media    = json_decode((string) wp_remote_retrieve_body($up), true);
            $media_id = (is_array($media) && !empty($media['id'])) ? (int) $media['id'] : 0;
            if ($media_id <= 0) {
                return;
            }

            // 3. A3 — deterministic alt_text/title on the uploaded media.
            wp_remote_post(
                $base . '/?' . http_build_query(array('rest_route' => '/wp/v2/media/' . $media_id)),
                array(
                    'headers'   => array('Authorization' => $auth, 'Content-Type' => 'application/json'),
                    'body'      => wp_json_encode(array('alt_text' => $title, 'title' => $title)),
                    'timeout'   => 30,
                    'sslverify' => true,
                )
            );

            // 4. Attach the media as the post's featured image.
            wp_remote_post(
                $base . '/?' . http_build_query(array('rest_route' => '/wp/v2/posts/' . $remote_post_id)),
                array(
                    'headers'   => array('Authorization' => $auth, 'Content-Type' => 'application/json'),
                    'body'      => wp_json_encode(array('featured_media' => $media_id)),
                    'timeout'   => 30,
                    'sslverify' => true,
                )
            );
        } catch (\Throwable $e) {
            error_log('PCM push_featured_image failed: ' . $e->getMessage());
        }
    }

    /**
     * A6 — sideload in-content media referenced by `<figure class="pcm-in-content-media">`
     * blocks in the OUTGOING post content. Each such figure's `<img src>` that is
     * NOT already on the target site (including quickchart.io chart URLs, which
     * become static images on the client) is uploaded into the remote media
     * library (reusing remote_upload_media()'s mechanics) and its src rewritten to
     * the remote URL. Wholly failure-isolated: a fetch/upload failure leaves the
     * original src untouched, and no branch throws — publish must never fatal
     * because of media. No-ops instantly when the content has no such figure.
     *
     * @param object $site    Site row.
     * @param string $content Outgoing post content.
     * @return string Content with in-content media rewritten to remote URLs where possible.
     */
    private static function sideload_in_content_media(object $site, string $content): string
    {
        try {
            if (strpos($content, 'pcm-in-content-media') === false) {
                return $content; // nothing to sideload
            }
            $site_host = strtolower((string) wp_parse_url(rtrim((string) $site->url, '/'), PHP_URL_HOST));

            return (string) preg_replace_callback(
                '#(<figure\b[^>]*\bclass="[^"]*\bpcm-in-content-media\b[^"]*"[^>]*>.*?<img\b[^>]*\bsrc=")([^"]+)(")#is',
                static function (array $m) use ($site, $site_host): string {
                    // The src was esc_url()'d into the content — decode entities
                    // (e.g. quickchart's &#038; separators) before fetching.
                    $raw_src = html_entity_decode($m[2], ENT_QUOTES);
                    $host    = strtolower((string) wp_parse_url($raw_src, PHP_URL_HOST));
                    if ($host === '') {
                        return $m[0]; // relative/opaque src — nothing to sideload
                    }
                    if ($site_host !== '' && $host === $site_host) {
                        return $m[0]; // already on the target site — skip
                    }
                    $uploaded = self::remote_upload_media($site, $raw_src);
                    if (is_wp_error($uploaded) || empty($uploaded['url'])) {
                        return $m[0]; // sideload failed — keep the original src
                    }
                    return $m[1] . esc_url((string) $uploaded['url']) . $m[3];
                },
                $content
            );
        } catch (\Throwable $e) {
            error_log('PCM sideload_in_content_media failed: ' . $e->getMessage());
            return $content;
        }
    }

    /**
     * Map a content-type to a file extension (default 'jpg').
     *
     * @param string $mime Source content-type header.
     * @return string Extension without a leading dot.
     */
    private static function ext_from_mime(string $mime): string
    {
        $mime = strtolower($mime);
        if (str_contains($mime, 'png')) {
            return 'png';
        }
        if (str_contains($mime, 'webp')) {
            return 'webp';
        }
        if (str_contains($mime, 'gif')) {
            return 'gif';
        }
        return 'jpg';
    }

    /**
     * Auto-provision the site in Google Search Console (mirrors the client's n8n flow):
     *   1. add the property (PUT sites/{url} — lands "unverified")
     *   2. mint a META verification token (Site Verification API)
     *   3. push the token to the site's connector (POST /pcm-conn/v1/site {gscToken} →
     *      rendered as <meta name="google-site-verification"> in wp_head, connector 2.3.0+)
     *   4. ask Google to verify (it fetches the page and checks the tag)
     * Best-effort: every failure returns a report with the failed step + a human reason,
     * never an exception — adding a site must succeed even when GSC can't be provisioned.
     *
     * @param object $site    Connected-site row (url + credentials).
     * @param int    $user_id PCM user owning the gsc integration.
     * @return array{attempted:bool, added:bool, tokenPushed:bool, verified:bool, step?:string, error?:string}
     */
    public static function gsc_provision(object $site, int $user_id, string $target_url = ''): array
    {
        $report = array('attempted' => false, 'added' => false, 'tokenPushed' => false, 'verified' => false);
        // Domain variant to register/verify. Defaults to the site URL, but the "Verify in GSC" dialog
        // lets the user pick the actually-indexed variant (www vs non-www) so we don't create a second,
        // empty property next to the one Google already has data for.
        $url = trim($target_url) !== '' ? trim($target_url) : (string) $site->url;
        // Did a HUMAN pick this variant in the dialog, or is it just the stored site URL?
        // The two must behave differently — see the reuse guard and the pin below.
        $chosen = trim($target_url) !== '';

        // The user's active GSC integration (OAuth connection or service-account JSON).
        $key = '';
        foreach (PCM_DB::get_user_integrations($user_id) as $row) {
            if (($row->provider ?? '') === 'gsc' && (int) ($row->isActive ?? 0) === 1) {
                $key = (string) $row->apiKey;
                break;
            }
        }
        if ($key === '') {
            $report['step']  = 'integration';
            $report['error'] = __('No active Google Search Console connection — connect one on the Integrations page, then use "Verify in GSC".', 'power-creatives');
            return $report;
        }
        $report['attempted'] = true;

        // 0. Is the site ALREADY a property this GSC account can see (any www/non-www/sc-domain
        //    variant)? If so, REUSE it — adding the other variant would create an empty duplicate
        //    that steals the SEO row and reports "no data". A listed property is already verified
        //    (list_properties drops siteUnverifiedUser), so provisioning is done.
        $existing = PCM_GSC::list_properties($key);
        if (!is_wp_error($existing)) {
            $matches = PCM_GSC::match_properties($existing, $url);
            // When the user PICKED a variant, reuse only a property that actually serves
            // THAT variant (an sc-domain property serves both, so it still counts).
            // match_properties is variant-insensitive by design, so without this filter the
            // www property was returned for a non-www pick — the choice became a no-op and
            // the user got connected to an empty property (owner report 2026-08-03).
            if ($chosen) {
                $matches = array_values(array_filter(
                    $matches,
                    static fn(string $p): bool => PCM_GSC::covers_exact_variant($p, $url)
                ));
            }
            if (!empty($matches)) {
                $report['added']         = true;
                $report['alreadyExists'] = true;
                $report['property']      = $matches[0];
                $report['verified']      = true;
                $report['hasData']       = self::gsc_has_data($key, $matches[0]);
                // Make the pick STICK. Later stats pulls re-run match_properties against the
                // STORED site url — which may still be the other variant — so without pinning,
                // the choice silently reverts on the next pull ("it insists on using the www").
                self::gsc_pin($site, $matches[0], $chosen);
                return $report;
            }
        }

        // 1. Add the property (the chosen variant).
        $added = PCM_GSC::add_property($key, $url);
        if (is_wp_error($added)) {
            $report['step']  = 'add';
            $report['error'] = $added->get_error_message();
            return $report;
        }
        $report['added'] = true;

        // 2. Mint the META token.
        $token = PCM_GSC::verification_token($key, $url);
        if (is_wp_error($token)) {
            $report['step']  = 'token';
            $report['error'] = $token->get_error_message();
            return $report;
        }

        // 3. Push it to the connector, and confirm the connector actually stored it — an older
        //    connector (<2.3.0) ignores gscToken silently, which would make step 4 fail cryptically.
        $push = self::remote_rest($site, 'POST', '/pcm-conn/v1/site', array(), array('gscToken' => $token));
        if (is_wp_error($push) || (int) ($push['status'] ?? 0) >= 300) {
            $report['step']  = 'push';
            $report['error'] = is_wp_error($push)
                ? sprintf(__('Could not reach the site’s connector (%s).', 'power-creatives'), $push->get_error_message())
                : sprintf(__('The site’s connector rejected the verification token (HTTP %d).', 'power-creatives'), (int) ($push['status'] ?? 0));
            return $report;
        }
        // The connector stores the token via sanitize_text_field and echoes back the STORED value,
        // so compare against the same-sanitized, trimmed token — not the raw one. Otherwise a fresh
        // connector that stored the token perfectly well could be mislabeled "too old" over a stray
        // space. An EMPTY/missing echo still means the connector genuinely can't store it (predates
        // v2.3.0) → the reinstall guidance below is the correct remedy.
        $echoed = trim((string) ($push['body']['gscToken'] ?? ''));
        if ($echoed === '' || $echoed !== trim((string) sanitize_text_field($token))) {
            $report['step']  = 'push';
            $report['error'] = __('The site’s connector is older than v2.3.0 and can’t store the verification token — reinstall the connector on the site (Sites → Download connector), then use "Verify in GSC".', 'power-creatives');
            return $report;
        }
        $report['tokenPushed'] = true;

        // 4. Verify. Google fetches the homepage NOW — a stale page cache can hide the fresh
        //    meta tag; the error below tells the user to clear caches and retry in that case.
        $verified = PCM_GSC::verify_property($key, $url);
        if (is_wp_error($verified)) {
            $report['step']  = 'verify';
            $report['error'] = sprintf(
                /* translators: %s: Google's error */
                __('Google could not verify the site yet (%s). If the site caches pages, clear its cache so the new meta tag is visible, then retry "Verify in GSC".', 'power-creatives'),
                $verified->get_error_message()
            );
            return $report;
        }
        $report['verified'] = true;
        // Tell the UI whether the property actually HAS Search Analytics data yet — a freshly
        // registered property is verified but empty for a few days, and without this flag the
        // user sees "Verified ✓" then an empty SEO table and assumes something broke.
        $property          = rtrim($url, '/') . '/';
        $report['hasData'] = self::gsc_has_data($key, $property);
        // Same pin as the reuse branch: the variant the user just registered is the one their
        // stats must come from, even though the stored site url may be the other variant.
        self::gsc_pin($site, $property, $chosen);
        return $report;
    }

    /**
     * Pin a site to the GSC property its stats must come from — but ONLY when a human chose
     * the variant in the "Verify in GSC" dialog. Auto-provisioning stays unpinned so
     * match_properties() keeps its heuristic (PCM_GSC::property_override's stated contract:
     * "a site nobody has touched behaves exactly as before").
     *
     * @param object $site     Site row (needs ->url).
     * @param string $property Resolved GSC property.
     * @param bool   $chosen   True when the property came from an explicit user pick.
     */
    private static function gsc_pin(object $site, string $property, bool $chosen): void
    {
        if (!$chosen || $property === '' || !class_exists('PCM_GSC')
            || !method_exists('PCM_GSC', 'set_property_override')) {
            return;
        }
        PCM_GSC::set_property_override((string) $site->url, $property);
    }

    /**
     * Does this GSC property have ANY Search Analytics data in the last 28 days? Best-effort
     * probe for user messaging only: null when the check itself fails (quota, transient error)
     * so callers can stay silent instead of guessing.
     *
     * @param string $key      GSC credential JSON.
     * @param string $property Property id (url-prefix with trailing slash, or sc-domain:host).
     * @return bool|null True/false, or null when undeterminable.
     */
    private static function gsc_has_data(string $key, string $property): ?bool
    {
        try {
            $s = PCM_GSC::page_stats($key, $property, 28);
        } catch (\Throwable $e) {
            return null;
        }
        if (is_wp_error($s)) {
            return null;
        }
        return !empty($s['pages']);
    }

    /**
     * Best-effort automated detection of the "indexed" domain variant: follow the homepage's
     * redirects and return the FINAL scheme://host it settles on. Most sites 301 one of www /
     * non-www to the other — the destination is the canonical host Google indexes, which replaces
     * the user's manual "Google the domain and hover the result" step. Returns '' if it can't tell.
     *
     * @param string $url The site URL to probe.
     * @return string Canonical "scheme://host" (lowercased host), or '' on failure.
     */
    public static function detect_canonical(string $url): string
    {
        $current = trim($url);
        if ($current === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $current)) {
            $current = 'https://' . ltrim($current, '/');
        }
        for ($hop = 0; $hop < 5; $hop++) {
            $args = array('redirection' => 0, 'timeout' => 10, 'sslverify' => true);
            $r    = wp_remote_head($current, $args);
            if (is_wp_error($r)) {
                // Some servers reject HEAD — try one GET before giving up.
                $r = wp_remote_get($current, $args);
                if (is_wp_error($r)) {
                    return '';
                }
            }
            $code = (int) wp_remote_retrieve_response_code($r);
            if ($code >= 300 && $code < 400) {
                $loc = trim((string) wp_remote_retrieve_header($r, 'location'));
                if ($loc === '') {
                    break;
                }
                if (!preg_match('#^https?://#i', $loc)) {
                    // Resolve a relative/absolute-path Location against the current URL's host.
                    $base   = wp_parse_url($current);
                    $scheme = $base['scheme'] ?? 'https';
                    $hostp  = ($base['host'] ?? '') . (isset($base['port']) ? ':' . $base['port'] : '');
                    $loc    = $scheme . '://' . $hostp . '/' . ltrim($loc, '/');
                }
                $current = $loc;
                continue;
            }
            break; // 2xx / other → settled on the final URL.
        }
        $p = wp_parse_url($current);
        if (empty($p['host'])) {
            return '';
        }
        return ($p['scheme'] ?? 'https') . '://' . strtolower((string) $p['host']);
    }

    /**
     * Data for the "Verify in GSC" dialog: which existing GSC properties already cover this site
     * (so we reuse instead of duplicating), plus the auto-detected canonical domain to pre-fill the
     * input. Best-effort — every field degrades to empty so the dialog still opens.
     *
     * @param object $site    Site row.
     * @param int    $user_id Owner id.
     * @return array{siteUrl:string,existing:array,suggested:string,canonical:string,accountEmail:string,error:string}
     */
    public static function gsc_preview(object $site, int $user_id): array
    {
        $out = array(
            'siteUrl'      => (string) $site->url,
            'existing'     => array(),
            'suggested'    => (string) $site->url,
            'canonical'    => '',
            'accountEmail' => '',
            'error'        => '',
        );
        $key = '';
        foreach (PCM_DB::get_user_integrations($user_id) as $row) {
            if (($row->provider ?? '') === 'gsc' && (int) ($row->isActive ?? 0) === 1) {
                $key = (string) $row->apiKey;
                break;
            }
        }
        if ($key === '') {
            $out['error'] = __('No active Google Search Console connection — connect one on the Integrations page first.', 'power-creatives');
            return $out;
        }

        // 1. Auto-detect the canonical (indexed) variant by following the homepage redirect.
        $canonical         = self::detect_canonical((string) $site->url);
        $out['canonical']  = $canonical;

        // 2. Pull every property this account can see and keep the ones covering this site.
        $props = PCM_GSC::list_properties($key);
        if (is_wp_error($props)) {
            $out['error'] = $props->get_error_message();
        } else {
            $out['existing'] = PCM_GSC::match_properties($props, (string) $site->url);
            $creds = PCM_GSC::parse_credentials($key);
            if (!is_wp_error($creds)) {
                $out['accountEmail'] = (string) ($creds['email'] ?? '');
            }
        }

        // Suggest, best-first: an existing url-prefix property to REUSE, else the detected canonical,
        // else the site URL as stored. (An existing sc-domain match still triggers reuse in
        // gsc_provision regardless of the input, so we prefer a real URL for the editable field.)
        $url_prefix = '';
        foreach ($out['existing'] as $e) {
            if (stripos((string) $e, 'sc-domain:') !== 0) {
                $url_prefix = rtrim((string) $e, '/');
                break;
            }
        }
        if ($url_prefix !== '') {
            $out['suggested'] = $url_prefix;
        } elseif ($canonical !== '') {
            $out['suggested'] = $canonical;
        }
        return $out;
    }
}
