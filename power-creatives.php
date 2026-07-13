<?php
/**
 * Plugin Name:       Power Creatives
 * Plugin URI:        https://powercreatives.io
 * Description:       AI-powered creative generation platform — copy, images, video, brand management, and more.
 * Version:           1.7.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Power Creatives
 * Author URI:        https://powercreatives.io
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       power-creatives
 * Domain Path:       /languages
 *
 * @package PowerCreatives
 */

// ── Security: Prevent direct file access ──
if (!defined('ABSPATH')) {
    exit;
}

// ── Plugin Constants ──
define('PCM_VERSION', '1.7.0');
define('PCM_DB_VERSION', '1.40.0');
define('PCM_PLUGIN_FILE', __FILE__);
define('PCM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PCM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PCM_PLUGIN_BASENAME', plugin_basename(__FILE__));

// ── Core: Shared infrastructure (owned by no single module) ──
require_once PCM_PLUGIN_DIR . 'includes/core/class-pcm-settings.php';
require_once PCM_PLUGIN_DIR . 'includes/core/db/class-pcm-schema.php';
require_once PCM_PLUGIN_DIR . 'includes/core/db/class-pcm-db.php';
require_once PCM_PLUGIN_DIR . 'includes/core/class-pcm-access.php';
require_once PCM_PLUGIN_DIR . 'includes/core/class-pcm-hierarchy.php';
require_once PCM_PLUGIN_DIR . 'includes/class-pcm-admin.php';
require_once PCM_PLUGIN_DIR . 'includes/class-pcm-shortcode.php';
require_once PCM_PLUGIN_DIR . 'includes/class-pcm-shortcode-admin.php';
require_once PCM_PLUGIN_DIR . 'includes/class-pcm-activator.php';
require_once PCM_PLUGIN_DIR . 'includes/core/class-pcm-template-seeds.php';
require_once PCM_PLUGIN_DIR . 'includes/core/class-pcm-prompt-seeds.php';
require_once PCM_PLUGIN_DIR . 'includes/core/class-pcm-prompt-placeholders.php';

// ── Core: Provider metadata & image utilities ──
require_once PCM_PLUGIN_DIR . 'includes/core/class-pcm-providers.php';
require_once PCM_PLUGIN_DIR . 'includes/core/class-pcm-gsc.php';
require_once PCM_PLUGIN_DIR . 'includes/core/class-pcm-image-utils.php';
require_once PCM_PLUGIN_DIR . 'includes/core/class-pcm-website-scraper.php';

// ── Core: Shortcode gate authentication ──
require_once PCM_PLUGIN_DIR . 'includes/core/class-pcm-gate-auth.php';

// ── Core: Base controller (abstract REST class) ──
require_once PCM_PLUGIN_DIR . 'includes/core/base-controller.php';

// ── Core: Automations engine + channels (shared dispatch layer) ──
// Loaded before modules so Approvals (and future modules) can call
// PCM_Automation_Engine::dispatch() during their own request handling.
require_once PCM_PLUGIN_DIR . 'includes/modules/automations/class-pcm-automation-events.php';
require_once PCM_PLUGIN_DIR . 'includes/modules/automations/class-pcm-automation-triggers.php';
require_once PCM_PLUGIN_DIR . 'includes/modules/automations/class-pcm-automation-actions.php';
require_once PCM_PLUGIN_DIR . 'includes/modules/automations/templates/class-pcm-automation-templates.php';
require_once PCM_PLUGIN_DIR . 'includes/modules/automations/channels/interface-pcm-automation-channel.php';
require_once PCM_PLUGIN_DIR . 'includes/modules/automations/channels/class-pcm-webhook-channel.php';
require_once PCM_PLUGIN_DIR . 'includes/modules/automations/channels/class-pcm-brevo-email-channel.php';
// Cross-module action handlers + input mapping (loaded before modules so each
// module's automations.php can register triggers/actions/handlers at discover()).
require_once PCM_PLUGIN_DIR . 'includes/modules/automations/class-pcm-automation-mapping.php';
require_once PCM_PLUGIN_DIR . 'includes/modules/automations/handlers/interface-pcm-automation-action-handler.php';
require_once PCM_PLUGIN_DIR . 'includes/modules/automations/handlers/class-pcm-webhook-action-handler.php';
require_once PCM_PLUGIN_DIR . 'includes/modules/automations/handlers/class-pcm-email-action-handler.php';
require_once PCM_PLUGIN_DIR . 'includes/modules/automations/service.php';
// Default-rule seeder (per-user, idempotent). Loaded after the engine so it can
// call PCM_Automation_Engine::create_rule().
require_once PCM_PLUGIN_DIR . 'includes/modules/automations/class-pcm-automation-seeds.php';

// ── Module-owned Automations registration ──
// Each module declares its triggers/actions/handlers in its own
// includes/modules/{id}/automations.php. Load them here (the module-loader does
// not auto-require this file), so the trigger/action registries are populated
// before rest_api_init and before any fire_trigger() during request handling.
foreach (glob(PCM_PLUGIN_DIR . 'includes/modules/*/automations.php') as $pcm_automations_file) {
    require_once $pcm_automations_file;
}

// ── Core: Infrastructure services ──
require_once PCM_PLUGIN_DIR . 'includes/core/storage/class-pcm-storage.php';
require_once PCM_PLUGIN_DIR . 'includes/core/llm/class-pcm-llm.php';
require_once PCM_PLUGIN_DIR . 'includes/core/sse/class-pcm-sse.php';

// ── Core: Kie.ai integration layer ──
require_once PCM_PLUGIN_DIR . 'includes/core/kie/class-pcm-kie-input-mapper.php';
require_once PCM_PLUGIN_DIR . 'includes/core/kie/class-pcm-kie-marketplace.php';
require_once PCM_PLUGIN_DIR . 'includes/core/class-pcm-input-resolver.php';
require_once PCM_PLUGIN_DIR . 'includes/core/kie/class-pcm-kie-upload.php';
require_once PCM_PLUGIN_DIR . 'includes/core/kie/class-pcm-kie-api.php';

// ── Core: Google integration layer ──
require_once PCM_PLUGIN_DIR . 'includes/core/google/class-pcm-google-veo-api.php';

// ── Core: Fal.ai integration layer ──
require_once PCM_PLUGIN_DIR . 'includes/core/fal/class-pcm-fal-input-mapper.php';
require_once PCM_PLUGIN_DIR . 'includes/core/fal/class-pcm-fal-api.php';
require_once PCM_PLUGIN_DIR . 'includes/core/fal/class-pcm-fal-seed.php';

// ── Core: Provider interface layer ──
require_once PCM_PLUGIN_DIR . 'includes/core/providers/class-pcm-provider-interface.php';
require_once PCM_PLUGIN_DIR . 'includes/core/providers/class-pcm-provider-openai.php';
require_once PCM_PLUGIN_DIR . 'includes/core/providers/class-pcm-provider-google.php';
require_once PCM_PLUGIN_DIR . 'includes/core/providers/class-pcm-provider-kieai.php';
require_once PCM_PLUGIN_DIR . 'includes/core/providers/class-pcm-provider-fal.php';
require_once PCM_PLUGIN_DIR . 'includes/core/providers/class-pcm-provider-registry.php';

// ── Module Loader: Auto-discovers modules in includes/modules/{name}/config.php ──
require_once PCM_PLUGIN_DIR . 'includes/module-loader.php';
PCM_Module_Loader::discover();

// ── Activation / Deactivation Hooks ──
register_activation_hook(__FILE__, array('PCM_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('PCM_Activator', 'deactivate'));

/**
 * Initialize the plugin after WordPress has loaded.
 *
 * Hooks into 'plugins_loaded' to ensure all WordPress APIs are available.
 *
 * @return void
 */
function pcm_init(): void
{
    // Check for DB schema upgrades on every load
    PCM_Activator::maybe_upgrade();

    // Initialize admin UI (only in wp-admin)
    if (is_admin()) {
        new PCM_Admin();
        new PCM_Shortcode_Admin();
    }

    // Register [power_creatives] shortcode for frontend rendering
    new PCM_Shortcode();

    // Register REST API endpoints via module-loader
    add_action('rest_api_init', 'pcm_register_rest_routes');

    // Schedule the daily reminder scanner once. The hook itself is registered
    // at file-load in includes/modules/automations/service.php.
    if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')
        && !wp_next_scheduled('pcm_automation_check_pending_approvals')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'pcm_automation_check_pending_approvals');
    }
}
add_action('plugins_loaded', 'pcm_init');

/**
 * Register all REST API routes for the plugin.
 *
 * All modules are auto-discovered by the Module Loader.
 * Each module's controller is instantiated and its register()
 * method is called to wire up every route.
 *
 * @return void
 */
function pcm_register_rest_routes(): void
{
    PCM_Module_Loader::register_routes();
}
