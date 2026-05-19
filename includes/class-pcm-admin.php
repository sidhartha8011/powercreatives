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

        // Enqueue the main React app JS
        // Using index-writer.js to bypass stubborn Nginx server caches
        wp_enqueue_script(
            'pcm-app',
            $app_url . 'index-writer.js',
            array(), // Dependencies managed by Vite build
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

        return array(
            // REST API base URL for fetch calls
            'restUrl' => esc_url_raw(rest_url('pcm/v1/')),
            // Security nonce for REST API authentication
            'nonce' => wp_create_nonce('wp_rest'),
            // Plugin URL for asset references
            'pluginUrl' => esc_url(PCM_PLUGIN_URL),
            // Current WordPress user info
            'user' => array(
                'id' => $current_user->ID,
                'name' => $current_user->display_name,
                'email' => $current_user->user_email,
                'role' => current_user_can('manage_options') ? 'admin' : 'user',
                'avatarUrl' => get_avatar_url($current_user->ID),
            ),
            // Plugin version
            'version' => PCM_VERSION,
        );
    }
}
