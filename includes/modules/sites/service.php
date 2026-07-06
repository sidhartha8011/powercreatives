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
        $args = array(
            'method'    => $method,
            'headers'   => array(
                'Authorization' => 'Basic ' . base64_encode($site->username . ':' . $password),
                'Content-Type'  => 'application/json',
            ),
            'timeout'   => max(1, $timeout),
            'sslverify' => true,
        );
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }
        return array(
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body'   => json_decode(wp_remote_retrieve_body($response), true),
        );
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
     * Test connection to a WordPress site.
     *
     * Calls GET /wp-json/wp/v2/users/me to verify credentials.
     *
     * @param object $site Site DB row.
     * @return array Connection result with site info.
     */
    public static function test_connection(object $site): array
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
            'timeout'   => 15,
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
     * Publish an article to a remote WordPress site.
     *
     * Uses POST /wp-json/wp/v2/posts with Basic Auth (Application Passwords).
     *
     * @param object $site    Site DB row.
     * @param object $article Article DB row.
     * @param int    $user_id PCM user ID (for updating article record).
     *
     * @return array Publish result with post URL and ID.
     * @throws \RuntimeException On API failure.
     */
    public static function publish_to_site(object $site, object $article, int $user_id): array
    {
        $password = self::decrypt_password($site->appPassword);
        // `?rest_route=` form — works regardless of the remote's permalink settings
        // (pretty `/wp-json/...` 404s on plain-permalink hosts). See test_connection().
        $url = rtrim($site->url, '/') . '/?rest_route=/wp/v2/posts';

        // Build the WP REST API post payload
        $post_data = array(
            'title'   => $article->title,
            'content' => $article->content,
            'status'  => 'publish',
            'slug'    => $article->slug,
        );

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

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($site->username . ':' . $password),
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
            $message = $body['message'] ?? "HTTP {$status}";
            throw new \RuntimeException("WordPress API error: {$message}");
        }

        $post_url = $body['link'] ?? '';
        $post_id = $body['id'] ?? 0;

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
            if (!empty($matches)) {
                $report['added']         = true;
                $report['alreadyExists'] = true;
                $report['property']      = $matches[0];
                $report['verified']      = true;
                $report['hasData']       = self::gsc_has_data($key, $matches[0]);
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
        $report['hasData'] = self::gsc_has_data($key, rtrim($url, '/') . '/');
        return $report;
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
