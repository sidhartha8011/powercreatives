<?php
/**
 * Plugin Activation / Deactivation Handler
 *
 * Runs on plugin activate/deactivate hooks.
 * Creates database tables, sets default options, stores DB version.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Activator
{

    /**
     * Run on plugin activation.
     *
     * - Creates all custom database tables.
     * - Installs default settings.
     * - Stores the current DB version for future migrations.
     *
     * @return void
     */
    public static function activate(): void
    {
        // Create or update database tables
        PCM_Schema::create_tables();

        // Install default settings
        PCM_Settings::install_defaults();

        // Seed pre-programmed templates (idempotent — skips existing)
        PCM_Template_Seeds::seed();

        // Seed default system prompts (idempotent — skips existing)
        PCM_Prompt_Seeds::seed();

        // Seed Fal.ai models into wp_pcm_models (idempotent — upserts)
        PCM_Fal_Seed::seed();

        // Store DB version for future migration checks
        update_option('pcm_db_version', PCM_DB_VERSION);

        // Flush rewrite rules (in case we add custom post types later)
        flush_rewrite_rules();
    }

    /**
     * Run on plugin deactivation.
     *
     * Note: We do NOT drop tables here — user data should persist
     * if the plugin is temporarily disabled.
     *
     * @return void
     */
    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    /**
     * Check if DB needs migration (called on plugins_loaded).
     *
     * Compares stored DB version with current plugin DB version.
     * If different, re-runs dbDelta() to apply schema changes.
     *
     * @return void
     */
    public static function maybe_upgrade(): void
    {
        $installed_version = get_option('pcm_db_version', '0.0.0');

        if (version_compare($installed_version, PCM_DB_VERSION, '<')) {
            PCM_Schema::create_tables();
            PCM_Template_Seeds::seed();
            PCM_Prompt_Seeds::seed();
            PCM_Fal_Seed::seed();

            // v1.2.0: Append TASK section to existing ads/organic prompts
            if (version_compare($installed_version, '1.2.0', '<')) {
                PCM_Prompt_Seeds::migrate_v1_2_0();
            }

            // v1.3.0: Seed new system_prompt_ads_system section (role:system prompt for ads).
            // PCM_Prompt_Seeds::seed() above is idempotent — it inserts only missing sections
            // and skips any that already exist, so re-running it safely adds the new section.

            update_option('pcm_db_version', PCM_DB_VERSION);
        }
    }
}
