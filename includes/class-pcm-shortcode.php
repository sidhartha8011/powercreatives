<?php
/**
 * Shortcode Handler — [power_creatives]
 *
 * Allows the React SPA to be rendered on any WordPress page or post
 * via the [power_creatives] shortcode. Enqueues the same Vite-built
 * assets as PCM_Admin but on the public frontend.
 *
 * Security: Only users with 'manage_options' capability can view the app.
 * Non-authorized visitors see nothing (empty string).
 *
 * Usage:
 *   1. Create a WordPress page
 *   2. Add [power_creatives] to the page content
 *   3. Publish — the full React SPA renders on that page
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Shortcode
{

    /**
     * Whether the shortcode has been encountered on the current page.
     * Prevents double-enqueuing assets if shortcode appears multiple times.
     *
     * @var bool
     */
    private bool $enqueued = false;

    /**
     * Constructor — register the shortcode.
     */
    public function __construct()
    {
        add_shortcode('power_creatives', array($this, 'render'));
    }

    /**
     * Render the [power_creatives] shortcode.
     *
     * Outputs the React mount point and enqueues JS/CSS assets.
     * Only visible to users with 'manage_options' capability.
     *
     * @param array|string $atts    Shortcode attributes (unused for now).
     * @param string|null  $content Shortcode inner content (unused).
     * @return string HTML output (React mount point or access denied message).
     */
    public function render($atts = array(), ?string $content = null): string
    {
        // Security: require login + manage_options capability
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return '<p class="pcm-access-denied">'
                . esc_html__('You do not have permission to access Power Creatives.', 'power-creatives')
                . '</p>';
        }

        // Enqueue assets only once per page load
        if (!$this->enqueued) {
            $this->enqueue_assets();
            $this->enqueued = true;
        }

        // Return the React mount point — main.tsx looks for #pcm-root
        return '<div id="pcm-root"></div>';
    }

    /**
     * Enqueue the Vite-built React SPA assets for the frontend.
     *
     * Mirrors PCM_Admin::enqueue_assets() but uses wp_enqueue_scripts
     * hook timing (frontend) instead of admin_enqueue_scripts.
     *
     * @return void
     */
    private function enqueue_assets(): void
    {
        $app_dir = PCM_PLUGIN_DIR . 'app/dist/';
        $app_url = PCM_PLUGIN_URL . 'app/dist/';

        // Bail if assets not built
        if (!file_exists($app_dir . 'index.js')) {
            return;
        }

        // Inter font from Google Fonts (design system dependency)
        wp_enqueue_style(
            'pcm-google-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
            array(),
            null
        );

        // Main React app CSS
        if (file_exists($app_dir . 'index.css')) {
            wp_enqueue_style(
                'pcm-app',
                $app_url . 'index.css',
                array('pcm-google-fonts'),
                filemtime($app_dir . 'index.css') // Cache-bust on every build
            );
        }

        // Main React app JS
        wp_enqueue_script(
            'pcm-app',
            $app_url . 'index.js',
            array(),
            filemtime($app_dir . 'index.js'), // Cache-bust on every build
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
    }

    /**
     * Build the configuration object passed to the React app.
     *
     * Same structure as PCM_Admin::get_js_config() — the React app
     * consumes pcmConfig identically regardless of admin or frontend context.
     *
     * @return array<string, mixed>
     */
    private function get_js_config(): array
    {
        $current_user = wp_get_current_user();

        return array(
            'restUrl' => esc_url_raw(rest_url('pcm/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'pluginUrl' => esc_url(PCM_PLUGIN_URL),
            'user' => array(
                'id' => $current_user->ID,
                'name' => $current_user->display_name,
                'email' => $current_user->user_email,
                'role' => current_user_can('manage_options') ? 'admin' : 'user',
                'avatarUrl' => get_avatar_url($current_user->ID),
            ),
            'version' => PCM_VERSION,
        );
    }
}
