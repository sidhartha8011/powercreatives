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
}
