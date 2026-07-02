<?php
/**
 * Shortcode Settings Page
 *
 * Adds a submenu under "Power Creatives" for the [power_creatives] shortcode gate.
 * The gate is now per-user: each visitor signs in with a username + password that
 * an admin creates in the Users module — there is no global shortcode password.
 * This page only tunes gate behaviour (session length, rate limiting, lockouts).
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Shortcode_Admin
{
    const MENU_SLUG = 'power-creatives-shortcode';
    const NONCE_ACTION = 'pcm_shortcode_settings_save';

    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_menu'), 20);
        add_action('admin_post_pcm_shortcode_save', array($this, 'handle_save'));
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'power-creatives',
            __('Shortcode', 'power-creatives'),
            __('Shortcode', 'power-creatives'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_page')
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'power-creatives'));
        }

        $lifetime_days = (int) round(((int) PCM_Settings::get('shortcode_cookie_lifetime', 604800)) / DAY_IN_SECONDS);
        $rate_limit_enabled = (bool) PCM_Settings::get('shortcode_rate_limit_enabled', true);

        $notice = isset($_GET['pcm_msg']) ? sanitize_key((string) $_GET['pcm_msg']) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Power Creatives — Shortcode', 'power-creatives'); ?></h1>

            <?php if ($notice === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'power-creatives'); ?></p></div>
            <?php elseif ($notice === 'cleared_lockouts'): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('All active IP lockouts have been cleared.', 'power-creatives'); ?></p></div>
            <?php endif; ?>

            <p>
                <?php esc_html_e('The [power_creatives] shortcode is gated per-user: each visitor signs in with the username and password you create for them in the Users module. Admins (manage_options) bypass the gate automatically. This page tunes how the gate behaves after login.', 'power-creatives'); ?>
            </p>

            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=power-creatives#/users')); ?>" class="button button-primary"><?php esc_html_e('Manage platform users', 'power-creatives'); ?></a>
            </p>

            <hr />

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off">
                <input type="hidden" name="action" value="pcm_shortcode_save" />
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="pcm_lifetime_days"><?php esc_html_e('Session length (days)', 'power-creatives'); ?></label></th>
                        <td>
                            <input type="number" id="pcm_lifetime_days" name="pcm_lifetime_days" value="<?php echo esc_attr((string) $lifetime_days); ?>" min="1" max="365" class="small-text" />
                            <p class="description"><?php esc_html_e('How long the auth cookie remains valid after login.', 'power-creatives'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Rate limiting', 'power-creatives'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="pcm_rate_limit_enabled" value="1" <?php checked($rate_limit_enabled); ?> />
                                <?php esc_html_e('Lock out an IP after 5 failed attempts for 15 minutes', 'power-creatives'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Turn this off on demo sites with many viewers. Recommended on for production.', 'power-creatives'); ?></p>
                            <?php if ($rate_limit_enabled): ?>
                                <p>
                                    <button type="submit" name="pcm_clear_lockouts" value="1" class="button"><?php esc_html_e('Clear active lockouts', 'power-creatives'); ?></button>
                                    <span class="description" style="margin-left:8px;"><?php esc_html_e('Resets all current IP lockouts immediately.', 'power-creatives'); ?></span>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save', 'power-creatives')); ?>
            </form>

            <hr />

            <h2><?php esc_html_e('Usage', 'power-creatives'); ?></h2>
            <p><?php esc_html_e('Add this shortcode to any WordPress page or post:', 'power-creatives'); ?></p>
            <p><code>[power_creatives]</code> — <?php esc_html_e('fullscreen (app takes over the viewport)', 'power-creatives'); ?></p>
            <p><code>[power_creatives mode="inline"]</code> — <?php esc_html_e('inline (sits inside the theme content area)', 'power-creatives'); ?></p>
        </div>
        <?php
    }

    public function handle_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission.', 'power-creatives'));
        }
        check_admin_referer(self::NONCE_ACTION);

        $back = admin_url('admin.php?page=' . self::MENU_SLUG);

        $days = isset($_POST['pcm_lifetime_days']) ? max(1, min(365, (int) $_POST['pcm_lifetime_days'])) : 7;
        $rate_limit_enabled = !empty($_POST['pcm_rate_limit_enabled']);

        // Update the session length + rate limit toggle
        PCM_Settings::set('shortcode_cookie_lifetime', $days * DAY_IN_SECONDS);
        PCM_Settings::set('shortcode_rate_limit_enabled', $rate_limit_enabled);

        // "Clear active lockouts" button: wipe all rate-limit transients
        if (!empty($_POST['pcm_clear_lockouts'])) {
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pcm_sc_rl\\_%' ESCAPE '\\\\' OR option_name LIKE '\\_transient\\_timeout\\_pcm_sc_rl\\_%' ESCAPE '\\\\'");
            wp_safe_redirect(add_query_arg('pcm_msg', 'cleared_lockouts', $back));
            exit;
        }

        wp_safe_redirect(add_query_arg('pcm_msg', 'saved', $back));
        exit;
    }
}
