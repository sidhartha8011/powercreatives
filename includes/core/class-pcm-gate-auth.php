<?php
/**
 * Shortcode Gate Authentication — per-user login.
 *
 * The public [power_creatives] shortcode is gated by a PLATFORM user's
 * username + password (created by an admin in the Users module — no WordPress
 * account needed). A successful login issues an HMAC-signed cookie that carries
 * the specific PCM user id, so each visitor operates as THEIR OWN workspace user
 * and REST endpoints scope to them. WP admins keep their own admin user and
 * bypass the gate entirely.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Gate_Auth
{
    const COOKIE_NAME = 'pcm_shortcode_auth';

    /** Cookie secret: derived from wp_salt('auth') so it can't be forged client-side. */
    private static function secret(): string
    {
        return wp_salt('auth') . '|pcm_gate_user';
    }

    /**
     * The PCM user id carried by a VALID gate cookie, or 0.
     * Cookie format: "<userId>|<expires>|<hmac(userId|expires)>".
     */
    public static function authed_user_id(): int
    {
        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return 0;
        }
        $parts = explode('|', (string) $_COOKIE[self::COOKIE_NAME], 3);
        if (count($parts) !== 3) {
            return 0;
        }
        [$uid, $expires, $sig] = $parts;
        $uid     = (int) $uid;
        $expires = (int) $expires;
        if ($uid <= 0 || $expires < time()) {
            return 0;
        }
        $expected = hash_hmac('sha256', $uid . '|' . $expires, self::secret());
        return hash_equals($expected, (string) $sig) ? $uid : 0;
    }

    /** Does the current request carry a valid gate cookie? */
    public static function is_authenticated(): bool
    {
        return self::authed_user_id() > 0;
    }

    /**
     * Verify a login. Returns the PCM user row on success, null otherwise.
     * Only platform users (with a passwordHash) can log in via the gate.
     */
    public static function authenticate(string $username, string $password): ?object
    {
        if ($username === '' || $password === '') {
            return null;
        }
        // Cap length before password_verify() — bcrypt is CPU-bound and its cost
        // scales with input length, so an oversized value is a cheap DoS vector.
        if (strlen($password) > 256 || strlen($username) > 191) {
            return null;
        }
        $user = PCM_DB::get_user_by_username($username);
        if (!$user || empty($user->passwordHash)) {
            return null;
        }
        if (!password_verify($password, (string) $user->passwordHash)) {
            return null;
        }
        return $user;
    }

    /** Issue a fresh gate cookie for a specific PCM user. Headers must not be sent yet. */
    public static function set_cookie(int $user_id): void
    {
        if ($user_id <= 0) {
            return;
        }
        $lifetime = (int) PCM_Settings::get('shortcode_cookie_lifetime', 604800);
        $expires  = time() + $lifetime;
        $sig      = hash_hmac('sha256', $user_id . '|' . $expires, self::secret());
        $value    = $user_id . '|' . $expires . '|' . $sig;

        $secure = is_ssl();
        $path   = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

        if (PHP_VERSION_ID >= 70300) {
            setcookie(self::COOKIE_NAME, $value, array(
                'expires'  => $expires,
                'path'     => $path,
                'domain'   => $domain,
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        } else {
            setcookie(self::COOKIE_NAME, $value, $expires, $path . '; samesite=Lax', $domain, $secure, true);
        }
        $_COOKIE[self::COOKIE_NAME] = $value;
    }

    public static function clear_cookie(): void
    {
        $path   = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        setcookie(self::COOKIE_NAME, '', time() - 3600, $path, $domain);
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    /**
     * The PCM user the current gate cookie identifies (or null). Rejects a cookie
     * whose user was deleted or is no longer a platform (password) user.
     */
    public static function get_gate_user(): ?object
    {
        $uid = self::authed_user_id();
        if ($uid <= 0) {
            return null;
        }
        $user = PCM_DB::get_user_by_id($uid);
        if (!$user || empty($user->passwordHash)) {
            return null;
        }
        return $user;
    }
}
