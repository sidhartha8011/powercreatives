<?php
/**
 * SEO — Site-wide settings (port of the source's site module head hooks).
 *
 *  - Custom robots.txt (robots_txt filter)
 *  - Site-wide LocalBusiness JSON-LD in <head>
 *  - <meta name="keywords"> on singular pages (from the cross-plugin abstraction)
 *  - Language / timezone apply with restorable backups
 *
 * Required by service.php (loaded every request → hooks register).
 *
 * @package PowerCreatives
 * @since   1.23.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_SEO_Site
{
    public const OPT_SCHEMA_ON   = 'pcm_seo_site_schema_enabled';
    public const OPT_SCHEMA_JSON = 'pcm_seo_site_schema_json';
    public const OPT_ROBOTS_ON   = 'pcm_seo_robots_enabled';
    public const OPT_ROBOTS_TXT  = 'pcm_seo_robots_text';
    public const BK_WPLANG       = 'pcm_seo_backup_wplang';
    public const BK_TZ           = 'pcm_seo_backup_timezone_string';

    /** Current site settings for the UI. */
    public static function get_settings(): array
    {
        return array(
            'schemaEnabled' => (bool) get_option(self::OPT_SCHEMA_ON, false),
            'schemaJson'    => (string) get_option(self::OPT_SCHEMA_JSON, ''),
            'robotsEnabled' => (bool) get_option(self::OPT_ROBOTS_ON, false),
            'robotsText'    => (string) get_option(self::OPT_ROBOTS_TXT, self::default_robots()),
            'siteTitle'     => (string) get_option('blogname', ''),
            'tagline'       => (string) get_option('blogdescription', ''),
            'language'      => (string) get_option('WPLANG', ''),
            'timezone'      => (string) get_option('timezone_string', ''),
            'hasLangBackup' => get_option(self::BK_WPLANG, null) !== null,
            'hasTzBackup'   => get_option(self::BK_TZ, null) !== null,
        );
    }

    /** Default robots.txt body. */
    public static function default_robots(): string
    {
        return "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nSitemap: " . home_url('/sitemap.xml');
    }

    /**
     * Persist settings. Each key is optional; language/timezone changes stamp
     * a one-time backup so they can be restored.
     *
     * @param array $in Sanitized-ish request params.
     * @return array Updated settings.
     */
    public static function save(array $in): array
    {
        if (array_key_exists('schemaEnabled', $in)) {
            update_option(self::OPT_SCHEMA_ON, (bool) $in['schemaEnabled']);
        }
        if (array_key_exists('schemaJson', $in)) {
            // Stored as-is (echoed through wp_kses_post on output).
            update_option(self::OPT_SCHEMA_JSON, (string) $in['schemaJson'], false);
        }
        if (array_key_exists('robotsEnabled', $in)) {
            update_option(self::OPT_ROBOTS_ON, (bool) $in['robotsEnabled']);
        }
        if (array_key_exists('robotsText', $in)) {
            update_option(self::OPT_ROBOTS_TXT, sanitize_textarea_field((string) $in['robotsText']), false);
        }
        // WP Site Title (blogname) — only overwrite when a non-empty value is given.
        if (array_key_exists('siteTitle', $in) && is_string($in['siteTitle']) && trim($in['siteTitle']) !== '') {
            update_option('blogname', sanitize_text_field((string) $in['siteTitle']));
        }
        // WP Tagline (blogdescription) — may be blanked.
        if (array_key_exists('tagline', $in) && is_string($in['tagline'])) {
            update_option('blogdescription', sanitize_text_field((string) $in['tagline']));
        }
        if (!empty($in['language']) && is_string($in['language'])) {
            if (get_option(self::BK_WPLANG, null) === null) {
                update_option(self::BK_WPLANG, (string) get_option('WPLANG', ''));
            }
            update_option('WPLANG', sanitize_text_field($in['language']));
        }
        if (!empty($in['timezone']) && is_string($in['timezone'])) {
            if (get_option(self::BK_TZ, null) === null) {
                update_option(self::BK_TZ, (string) get_option('timezone_string', ''));
            }
            update_option('timezone_string', sanitize_text_field($in['timezone']));
        }
        return self::get_settings();
    }

    /** Restore language/timezone from backup. $what = 'language' | 'timezone' | 'all'. */
    public static function restore(string $what): array
    {
        if (($what === 'language' || $what === 'all') && get_option(self::BK_WPLANG, null) !== null) {
            update_option('WPLANG', (string) get_option(self::BK_WPLANG, ''));
            delete_option(self::BK_WPLANG);
        }
        if (($what === 'timezone' || $what === 'all') && get_option(self::BK_TZ, null) !== null) {
            update_option('timezone_string', (string) get_option(self::BK_TZ, ''));
            delete_option(self::BK_TZ);
        }
        return self::get_settings();
    }

    // ── Frontend head/robots hooks ──

    public static function render_site_schema(): void
    {
        if (!get_option(self::OPT_SCHEMA_ON, false)) {
            return;
        }
        $json = (string) get_option(self::OPT_SCHEMA_JSON, '');
        if ($json === '') {
            return;
        }
        echo "\n<script type=\"application/ld+json\">" . wp_kses_post($json) . "</script>\n";
    }

    public static function render_meta_keywords(): void
    {
        if (!is_singular() || !class_exists('PCM_SEO_Service')) {
            return;
        }
        $kw = PCM_SEO_Service::seo_get((int) get_the_ID(), 'meta_keywords');
        if ($kw !== '') {
            echo '<meta name="keywords" content="' . esc_attr($kw) . '">' . "\n";
        }
    }

    public static function filter_robots($output, $public): string
    {
        if (!get_option(self::OPT_ROBOTS_ON, false)) {
            return $output;
        }
        $txt = (string) get_option(self::OPT_ROBOTS_TXT, '');
        return $txt !== '' ? $txt : $output;
    }
}

add_action('wp_head', array('PCM_SEO_Site', 'render_site_schema'), 99);
add_action('wp_head', array('PCM_SEO_Site', 'render_meta_keywords'), 5);
add_filter('robots_txt', array('PCM_SEO_Site', 'filter_robots'), 9999, 2);
