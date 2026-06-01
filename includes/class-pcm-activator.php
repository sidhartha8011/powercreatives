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

            // v1.4.0: Backfill role='logo' on legacy brand assets that were created
            // before the role contract was introduced. Idempotent — only touches assets
            // missing the role field.
            if (version_compare($installed_version, '1.4.0', '<')) {
                PCM_Schema::migrate_brand_assets_role();
            }

            // v1.7.0: Convert existing SVG brand assets to PNG.
            // AI image generation models cannot process SVG vector files.
            // This rasterizes all SVG logos/assets to PNG using Imagick.
            if (version_compare($installed_version, '1.7.0', '<')) {
                PCM_Schema::migrate_brand_svg_to_png();
            }

            // v1.8.0: Migrate approval_sets.status to the 6-value taxonomy
            // (draft / internal / client / approved / live / archived). Remaps
            // legacy 'review' → 'client' and 'completed' → 'approved'. Idempotent.
            //
            // v1.9.0: Extend taxonomy to 7 values (adds 'launch'), rename
            // 'approved' → 'create'. Same migration method — the
            // LEGACY_STATUS_MAP now carries both v1.8.0 and v1.9.0 mappings;
            // running it on any older install converges to the latest schema.
            if (version_compare($installed_version, '1.9.0', '<')) {
                PCM_Schema::migrate_approval_set_statuses();
            }

            // v1.11.0: Collapse the 'create' + 'launch' stages into a single
            // 'launch' stage. LEGACY_STATUS_MAP gains 'create' → 'launch' and
            // re-points 'approved' at 'launch' so installs already on 1.9.0
            // or 1.10.0 (which carry rows with status='create') converge in
            // the same idempotent pass.
            if (version_compare($installed_version, '1.11.0', '<')) {
                PCM_Schema::migrate_approval_set_statuses();
            }

            // v1.12.0: Inject the {{creativeBrief}} placeholder (and any future
            // declarative placeholders) into legacy DB-stored prompt overrides
            // that pre-date the placeholder being added to the default templates.
            // Idempotent + customization-safe — see PCM_Prompt_Placeholders.
            if (version_compare($installed_version, '1.12.0', '<')) {
                PCM_Prompt_Placeholders::sync_all();
            }

            update_option('pcm_db_version', PCM_DB_VERSION);
        }
    }
}
