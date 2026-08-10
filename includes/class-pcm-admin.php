<?php
/**
 * Admin Page Handler
 *
 * Registers the WordPress admin menu page and enqueues the React SPA.
 * The React app is mounted into a container div on the admin page.
 *
 * This class replaces the standalone Express server's Vite dev middleware
 * and static file serving.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Admin
{

    /**
     * Constructor — register WordPress hooks.
     */
    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Register the top-level admin menu page.
     *
     * Creates a "Power Creatives" entry in the WordPress admin sidebar.
     * The page callback renders the React app's mount point.
     *
     * @return void
     */
    public function register_menu(): void
    {
        add_menu_page(
            __('Power Creatives', 'power-creatives'), // Page title
            __('Power Creatives', 'power-creatives'), // Menu title
            'manage_options', // Capability required
            'power-creatives', // Menu slug
            array($this, 'render_page'), // Render callback
            'dashicons-art', // Icon
            30 // Position
        );
    }

    /**
     * Render the admin page containing the React SPA mount point.
     *
     * This outputs a minimal HTML wrapper. The React app hydrates into
     * the #pcm-root div. WordPress admin chrome (sidebar, topbar) remains.
     *
     * @return void
     */
    public function render_page(): void
    {
?>
        <div class="wrap" id="pcm-wrap">
            <!-- WordPress notices render above this -->
            <div id="pcm-root"></div>
        </div>
        <?php
    }

    /**
     * Enqueue React SPA scripts and styles.
     *
     * Only loads on the Power Creatives admin page (not globally).
     * Passes server-side data to the React app via wp_localize_script.
     *
     * @param string $hook_suffix The current admin page hook suffix.
     * @return void
     */
    public function enqueue_assets(string $hook_suffix): void
    {
        // Only load on our admin page
        if ('toplevel_page_power-creatives' !== $hook_suffix) {
            return;
        }

        // Enable WordPress Media Library integration for the React app
        wp_enqueue_media();

        $app_dir = PCM_PLUGIN_DIR . 'app/dist/';
        $app_url = PCM_PLUGIN_URL . 'app/dist/';

        // Check if built assets exist
        if (!file_exists($app_dir . 'index-writer.js') && !file_exists($app_dir . 'index.js')) {
            // Dev mode or assets not built yet — show notice
            add_action('admin_notices', function () {
                echo '<div class="notice notice-warning"><p>';
                esc_html_e(
                    'Power Creatives: React app not built. Run "npm run build" in the plugin/app directory.',
                    'power-creatives'
                );
                echo '</p></div>';
            });
            return;
        }

        // Inter font from Google Fonts (design system requires it)
        wp_enqueue_style(
            'pcm-google-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
            array(),
            null // No version — CDN handles caching
        );

        // Enqueue the main React app CSS
        if (file_exists($app_dir . 'index.css')) {
            wp_enqueue_style(
                'pcm-app',
                $app_url . 'index.css',
                array('pcm-google-fonts'),
                filemtime($app_dir . 'index.css') // Cache-bust on every build
            );
        }

        // WordPress' heartbeat. Core hooks wp_refresh_heartbeat_nonces() onto
        // `heartbeat_received`, so every tick returns a FRESH `rest_nonce`. The SPA
        // listens for it (lib/trpc.ts) and swaps the nonce in place. Without this the
        // nonce minted at page load is the only one the tab ever has, and once it ages
        // out every write fails with WordPress' "Cookie check failed"
        // (rest_cookie_invalid_nonce) — owner report 2026-08-10, hit on Create Strategy.
        wp_enqueue_script('heartbeat');

        // Enqueue the main React app JS
        // Using index-writer.js to bypass stubborn Nginx server caches
        wp_enqueue_script(
            'pcm-app',
            $app_url . 'index-writer.js',
            array('heartbeat'), // heartbeat must be present before the app binds its tick listener
            time(), // Cache-bust on every page load for dev testing
            true // Load in footer
        );

        // Vite outputs ES modules — add type="module" attribute
        add_filter('script_loader_tag', function ($tag, $handle) {
            if ('pcm-app' === $handle) {
                return str_replace(' src', ' type="module" src', $tag);
            }
            return $tag;
        }, 10, 2);

        // Pass server-side configuration to the React app
        wp_localize_script('pcm-app', 'pcmConfig', $this->get_js_config());

        // Polyfill crypto.randomUUID() for non-secure contexts (http:// local dev).
        // The Web Crypto API's randomUUID() is only available in Secure Contexts
        // (HTTPS or localhost). Local dev domains like http://powercreatives.local
        // are NOT considered secure, causing TypeError crashes in the React app.
        // This polyfill uses crypto.getRandomValues() which IS available everywhere.
        wp_add_inline_script('pcm-app', '
            if (typeof crypto !== "undefined" && typeof crypto.randomUUID !== "function") {
                crypto.randomUUID = function() {
                    var a = new Uint8Array(16);
                    crypto.getRandomValues(a);
                    a[6] = (a[6] & 0x0f) | 0x40;
                    a[8] = (a[8] & 0x3f) | 0x80;
                    var h = Array.from(a, function(b) { return b.toString(16).padStart(2, "0"); }).join("");
                    return h.slice(0,8) + "-" + h.slice(8,12) + "-" + h.slice(12,16) + "-" + h.slice(16,20) + "-" + h.slice(20);
                };
            }
        ', 'before');
    }

    /**
     * Build the configuration object passed to the React app.
     *
     * Replaces the standalone app's .env variables and tRPC context.
     * Available in React via `window.pcmConfig`.
     *
     * @return array<string, mixed>
     */
    private function get_js_config(): array
    {
        $current_user = wp_get_current_user();

        // Resolve (creating if missing) the published page that hosts the
        // [power_creatives] shortcode — client share links point at it. Without a
        // page the link would fall back to home_url and render the theme's
        // "nothing found" page.
        $shortcode_page_url = home_url('/'); // Safe default fallback
        $page_id = $this->ensure_public_page();
        if ($page_id) {
            $shortcode_page_url = get_permalink((int) $page_id);
        }

        return array(
            // REST API base URL for fetch calls
            'restUrl' => esc_url_raw(rest_url('pcm/v1/')),
            // Security nonce for REST API authentication
            'nonce' => wp_create_nonce('wp_rest'),
            // admin-ajax endpoint used ONLY to re-mint an expired REST nonce.
            // WordPress core registers `wp_ajax_rest-nonce`, which returns a fresh
            // wp_rest nonce and authenticates on the login COOKIE alone — no nonce
            // required, which is what breaks the chicken-and-egg when the one we
            // hold has expired. The SPA calls it once, then retries the request.
            'ajaxUrl' => esc_url_raw(admin_url('admin-ajax.php')),
            // Plugin URL for asset references
            'pluginUrl' => esc_url(PCM_PLUGIN_URL),
            // Shortcode page URL (for client shareable link resolution)
            'shortcodePageUrl' => esc_url_raw($shortcode_page_url),
            // Current WordPress user info
            'user' => array(
                'id' => $current_user->ID,
                'name' => $current_user->display_name,
                'email' => $current_user->user_email,
                'role' => current_user_can('manage_options') ? 'admin' : 'user',
                'avatarUrl' => get_avatar_url($current_user->ID),
                'isLoggedIn' => true,
                // Per-delivery module grants (null = unrestricted/admin). The
                // sidebar uses this for UX; the REST layer enforces it anyway.
                'allowedModules' => self::allowed_modules_for_current_user(),
                // Per-module brand grants (null = unrestricted/admin). UX
                // filter for brand pickers; the REST layer enforces it anyway.
                'brandsByModule' => self::brands_by_module_for_current_user(),
            ),
            // Central delivery-type → module presets (deliveries dialog).
            'deliveryTypePresets' => class_exists('PCM_Deliveries_Service')
                ? PCM_Deliveries_Service::type_presets()
                : array(),
            // Plugin version
            'version' => PCM_VERSION,
        );
    }

    /**
     * Resolve the published page that hosts the [power_creatives] shortcode,
     * creating it once if none exists. Client approval share links point here;
     * without it the link falls back to home_url and shows the theme's
     * "nothing found" page. Idempotent — only creates when truly missing.
     *
     * @return int|null Page ID, or null if it could not be created.
     */
    private function ensure_public_page(): ?int
    {
        global $wpdb;
        $like_sc = '%' . $wpdb->esc_like('[power_creatives]') . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND post_content LIKE %s LIMIT 1",
            $like_sc
        ));
        if ($page_id) {
            return (int) $page_id;
        }

        // None found — create a published page that renders the app/review board.
        $new_id = wp_insert_post(array(
            'post_title'   => 'Power Creatives',
            'post_content' => '[power_creatives]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => get_current_user_id(),
        ), true);

        return is_wp_error($new_id) ? null : (int) $new_id;
    }

    /**
     * Module ids granted to the current WP user via assigned deliveries.
     * Null = unrestricted (admins, or grant infra unavailable). Computed at
     * page load — grant changes apply on the next reload.
     *
     * @return string[]|null
     */
    public static function allowed_modules_for_current_user(): ?array
    {
        if (current_user_can('manage_options')
            || !class_exists('PCM_Access')
            || !class_exists('PCM_DB')
        ) {
            return null;
        }
        $pcm_user = PCM_DB::get_user_by_open_id('wp_' . get_current_user_id());
        return $pcm_user ? PCM_Access::granted_module_ids((int) $pcm_user->id) : array();
    }

    /**
     * Map of granted module id → usable brand ids for the current WP user.
     * Null = unrestricted (admins, or grant infra unavailable). Same
     * page-load semantics as allowed_modules_for_current_user().
     *
     * @return array<string, int[]>|null
     */
    public static function brands_by_module_for_current_user(): ?array
    {
        if (current_user_can('manage_options')
            || !class_exists('PCM_Access')
            || !class_exists('PCM_DB')
        ) {
            return null;
        }
        $pcm_user = PCM_DB::get_user_by_open_id('wp_' . get_current_user_id());
        return $pcm_user ? PCM_Access::brands_by_module((int) $pcm_user->id) : array();
    }
}
