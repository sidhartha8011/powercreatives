<?php
/**
 * Shortcode Gate Authentication
 *
 * Single source of truth for the [power_creatives] password gate:
 *  - Cookie validation (HMAC-signed, time-limited)
 *  - Cookie issuance and revocation
 *  - Resolution to the shared workspace PCM user
 *
 * Auth model: ONE shared password gates the public shortcode. Every visitor
 * who enters the correct password is treated as the SAME PCM user (a shared
 * workspace user with openId 'pcm_shortcode_shared'). This matches the
 * product intent — "one login, no accounts" — while keeping REST endpoints
 * properly scoped to a user row.
 *
 * WP admins remain on their own admin user — they do NOT share the workspace.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Gate_Auth
{
    const COOKIE_NAME = 'pcm_shortcode_auth';
    const SHARED_OPEN_ID = 'pcm_shortcode_shared';

    /**
     * Does the current request carry a valid gate cookie?
     */
    public static function is_authenticated(): bool
    {
        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return false;
        }
        $parts = explode('|', (string) $_COOKIE[self::COOKIE_NAME], 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$expires, $sig] = $parts;
        $expires = (int) $expires;
        if ($expires < time()) {
            return false;
        }
        $expected = hash_hmac('sha256', (string) $expires, wp_salt('auth'));
        return hash_equals($expected, $sig);
    }

    /**
     * Issue a fresh gate cookie. Headers must not yet be sent.
     */
    public static function set_cookie(): void
    {
        $lifetime = (int) PCM_Settings::get('shortcode_cookie_lifetime', 604800);
        $expires = time() + $lifetime;
        $sig = hash_hmac('sha256', (string) $expires, wp_salt('auth'));
        $value = $expires . '|' . $sig;

        $secure = is_ssl();
        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

        if (PHP_VERSION_ID >= 70300) {
            setcookie(self::COOKIE_NAME, $value, array(
                'expires' => $expires,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        } else {
            setcookie(self::COOKIE_NAME, $value, $expires, $path . '; samesite=Lax', $domain, $secure, true);
        }
        // Make the cookie visible to the rest of the current request as well.
        $_COOKIE[self::COOKIE_NAME] = $value;
    }

    public static function clear_cookie(): void
    {
        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        setcookie(self::COOKIE_NAME, '', time() - 3600, $path, $domain);
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    /**
     * Return (and lazily create) the PCM user row that owns the shared
     * workspace used by gate-authenticated visitors.
     */
    public static function get_shared_pcm_user(): ?object
    {
        $existing = PCM_DB::get_user_by_open_id(self::SHARED_OPEN_ID);
        if ($existing) {
            return $existing;
        }

        $id = PCM_DB::upsert_user(array(
            'openId' => self::SHARED_OPEN_ID,
            'name' => __('Shortcode User', 'power-creatives'),
            'email' => '',
            'role' => 'user',
            'avatarUrl' => '',
        ));

        if (!$id) {
            return null;
        }

        // Seed default prompts for the shared user so the dashboard works
        // out of the box for gate visitors (mirrors get_current_pcm_user()).
        if (class_exists('PCM_Prompt_Seeds')) {
            PCM_Prompt_Seeds::seed_for_user((int) $id);
        }

        return PCM_DB::get_user_by_open_id(self::SHARED_OPEN_ID);
    }
}
