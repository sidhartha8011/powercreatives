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
        $url = rtrim($site->url, '/') . '/wp-json/wp/v2/users/me';

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
        $url = rtrim($site->url, '/') . '/wp-json/wp/v2/posts';

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
