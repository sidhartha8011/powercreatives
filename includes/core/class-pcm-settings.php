<?php
/**
 * Plugin Settings Wrapper
 *
 * Centralizes all WordPress Options API access for the plugin.
 * Replaces the standalone app's .env / process.env pattern.
 *
 * Usage:
 *   PCM_Settings::get( 'database_url' )
 *   PCM_Settings::set( 'forge_api_key', 'xxx' )
 *   PCM_Settings::get_all()
 *
 * All settings are stored in a single wp_options row as a serialized array
 * under the key 'pcm_settings'. This avoids polluting the options table.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Settings
{

    /**
     * The single options key used to store all plugin settings.
     *
     * @var string
     */
    const OPTION_KEY = 'pcm_settings';

    /**
     * Default settings with sensible defaults.
     * Maps to the original ENV vars from the standalone app.
     *
     * @var array<string, mixed>
     */
    private static array $defaults = array(
        // Original: VITE_APP_ID
        'app_id' => '',
        // Original: DATABASE_URL (unused in WP — kept for migration compat)
        'database_url' => '',
        // Original: OAUTH_SERVER_URL
        'oauth_server_url' => '',
        // Original: OWNER_OPEN_ID
        'owner_open_id' => '',
        // Original: BUILT_IN_FORGE_API_URL
        'forge_api_url' => '',
        // Original: BUILT_IN_FORGE_API_KEY
        'forge_api_key' => '',

        // ── Token budgets per LLM operation ──────────────────────
        // Controls max_completion_tokens sent to the LLM API.
        // For thinking models (Gemini 2.5), this includes reasoning + visible output.
        // Higher values = more room for thinking = no truncation.
        // Gemini 2.5 Flash max output: 65,536 tokens.
        'token_budget_audience' => 8192, // Audience generation (output ~150 tokens)
        'token_budget_angle' => 8192, // Angle generation (output ~150 tokens)
        'token_budget_copy' => 16384, // Copy generation (output ~500-2000 tokens)
        'token_budget_video' => 4096, // Video prompt enrichment (output ~200 tokens)

        // Shortcode password gate
        'shortcode_password_hash' => '',
        'shortcode_cookie_lifetime' => 604800,
        // Whether to apply rate limiting (5 attempts / 15 min / IP) on the login form
        'shortcode_rate_limit_enabled' => true,
    );

    /**
     * In-memory cache to avoid repeated DB reads within one request.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $cache = null;

    /**
     * Get a single setting value.
     *
     * @param string $key     Setting key (e.g. 'forge_api_url').
     * @param mixed  $default Optional fallback if key doesn't exist.
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $settings = self::get_all();

        if (array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        // Fall back to provided default, then to schema default
        return $default ?? (self::$defaults[$key] ?? null);
    }

    /**
     * Set a single setting value and persist to database.
     *
     * @param string $key   Setting key.
     * @param mixed  $value New value.
     * @return bool True on success, false on failure.
     */
    public static function set(string $key, mixed $value): bool
    {
        $settings = self::get_all();
        $settings[$key] = $value;

        $result = update_option(self::OPTION_KEY, $settings);

        // Invalidate cache
        self::$cache = null;

        return $result;
    }

    /**
     * Update multiple settings at once.
     *
     * @param array<string, mixed> $values Key-value pairs to update.
     * @return bool True on success.
     */
    public static function set_many(array $values): bool
    {
        $settings = self::get_all();
        $settings = array_merge($settings, $values);

        $result = update_option(self::OPTION_KEY, $settings);

        // Invalidate cache
        self::$cache = null;

        return $result;
    }

    /**
     * Get all settings merged with defaults.
     *
     * @return array<string, mixed>
     */
    public static function get_all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored = get_option(self::OPTION_KEY, array());
        self::$cache = array_merge(self::$defaults, is_array($stored) ? $stored : array());

        return self::$cache;
    }

    /**
     * Delete all plugin settings (used during uninstall).
     *
     * @return bool
     */
    public static function delete_all(): bool
    {
        self::$cache = null;
        return delete_option(self::OPTION_KEY);
    }

    /**
     * Install default settings if they don't exist yet.
     * Called during plugin activation.
     *
     * @return void
     */
    public static function install_defaults(): void
    {
        if (false === get_option(self::OPTION_KEY)) {
            add_option(self::OPTION_KEY, self::$defaults);
        }
    }
}
