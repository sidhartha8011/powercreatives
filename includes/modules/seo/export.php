<?php
/**
 * SEO — Export / Import (Phase 9).
 *
 * Bundles the agency-reusable SEO configuration (site schema/robots,
 * AI-readiness settings, GBP provider+webhook) as portable JSON so a setup
 * can be replicated across client sites. Per-post content/meta is NOT
 * exported — this is site-level config only.
 *
 * Required by service.php.
 *
 * @package PowerCreatives
 * @since   1.23.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_SEO_Export
{
    public const PLUGIN_KEY = 'power-creatives-seo';

    /** Standalone wp_options included in the bundle (whitelist). */
    private const OPTIONS = array(
        'pcm_seo_site_schema_enabled',
        'pcm_seo_site_schema_json',
        'pcm_seo_robots_enabled',
        'pcm_seo_robots_text',
        'pcm_seo_air_settings',
    );

    /** PCM_Settings keys included in the bundle. */
    private const SETTINGS = array('seo_gbp_provider', 'seo_gbp_webhook');

    /** Build the export bundle. */
    public static function export(): array
    {
        $data = array();
        foreach (self::OPTIONS as $opt) {
            $val = get_option($opt, null);
            if ($val !== null) {
                $data['options'][$opt] = $val;
            }
        }
        foreach (self::SETTINGS as $key) {
            $val = class_exists('PCM_Settings') ? PCM_Settings::get($key, '') : '';
            if ($val !== '' && $val !== null) {
                $data['settings'][$key] = $val;
            }
        }
        return array(
            'plugin'   => self::PLUGIN_KEY,
            'version'  => defined('PCM_VERSION') ? PCM_VERSION : '',
            'exported' => gmdate('c'),
            'data'     => $data,
        );
    }

    /**
     * Apply an import bundle. Only whitelisted keys are written.
     *
     * @param array  $config   Decoded bundle.
     * @return array { applied: int } | WP_Error-ish array { error }.
     */
    public static function import(array $config): array
    {
        if (($config['plugin'] ?? '') !== self::PLUGIN_KEY) {
            return array('error' => __('Not a Power Creatives SEO export file.', 'power-creatives'));
        }
        $data    = is_array($config['data'] ?? null) ? $config['data'] : array();
        $applied = 0;

        foreach (($data['options'] ?? array()) as $opt => $val) {
            if (in_array($opt, self::OPTIONS, true)) {
                update_option($opt, $val, false);
                $applied++;
            }
        }
        if (!empty($data['settings']) && is_array($data['settings']) && class_exists('PCM_Settings')) {
            $clean = array();
            foreach ($data['settings'] as $key => $val) {
                if (in_array($key, self::SETTINGS, true)) {
                    $clean[$key] = is_string($val) ? sanitize_text_field($val) : $val;
                }
            }
            if (!empty($clean)) {
                PCM_Settings::set_many($clean);
                $applied += count($clean);
            }
        }
        return array('applied' => $applied);
    }
}
