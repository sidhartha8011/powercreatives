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

        // Mirror every existing WP user into wp_pcm_users and seed their
        // default prompts + automation rules NOW — so the Automations module
        // is fully populated right after activation instead of lazily on each
        // user's first plugin request. Idempotent (seedKey markers / existing
        // rows are skipped), so re-activation is safe.
        self::seed_all_wp_users();

        // Store DB version for future migration checks
        update_option('pcm_db_version', PCM_DB_VERSION);

        // Flush rewrite rules (in case we add custom post types later)
        flush_rewrite_rules();
    }

    /**
     * Mirror all WP users into wp_pcm_users and run the per-user seeders
     * (default prompts + default automation rules). Same mirror shape as
     * PCM_Users_Service::list_users() and the lazy path in
     * PCM_REST_Base::get_current_pcm_user(). All steps are idempotent.
     *
     * @return void
     */
    public static function seed_all_wp_users(): void
    {
        if (!function_exists('get_users')) {
            return;
        }
        // Sane cap — agency installs are small; avoids pathological loops on
        // sites with thousands of subscribers.
        $wp_users = get_users(array('number' => 500, 'fields' => 'all'));
        foreach ($wp_users as $wp_user) {
            $open_id  = 'wp_' . $wp_user->ID;
            $pcm_user = PCM_DB::get_user_by_open_id($open_id);
            if (!$pcm_user) {
                PCM_DB::upsert_user(array(
                    'openId'    => $open_id,
                    'name'      => $wp_user->display_name,
                    'email'     => $wp_user->user_email,
                    'role'      => user_can($wp_user, 'manage_options') ? 'admin' : 'user',
                    'avatarUrl' => get_avatar_url($wp_user->ID),
                ));
                $pcm_user = PCM_DB::get_user_by_open_id($open_id);
            }
            if (!$pcm_user) {
                continue; // degenerate: insert failed — skip rather than fatal.
            }
            if (class_exists('PCM_Prompt_Seeds')) {
                PCM_Prompt_Seeds::seed_for_user((int) $pcm_user->id);
            }
            if (class_exists('PCM_Automation_Seeds')) {
                PCM_Automation_Seeds::seed_for_user((int) $pcm_user->id);
            }
        }
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

            // v1.13.0: Inject the {{copyFramework}} placeholder into existing
            // copy/ads system prompts (registry gained the copyFramework_system
            // entry). Same idempotent, customization-safe sync — already-present
            // placeholders are skipped, so re-running is a no-op.
            if (version_compare($installed_version, '1.13.0', '<')) {
                PCM_Prompt_Placeholders::sync_all();
            }

            // v1.14.0: Approvals flow + Automations module. Adds two tables
            // (wp_pcm_automations, wp_pcm_automation_logs) and two nullable
            // columns (brands.clientEmail, approval_sets.clientEmail). All
            // changes are additive, so the create_tables() call above (which
            // runs on every upgrade) applies them via dbDelta — no bespoke
            // migration method is required.

            // v1.15.0: Automations rule model. Adds name/triggerId/conditions/
            // actionId columns to wp_pcm_automations (additive via dbDelta) and
            // relaxes the legacy event/channel columns to nullable
            // (PCM_Schema::migrate_automations_columns, invoked from
            // create_tables() above). No separate gate needed here.

            // v1.16.0: Cross-module automations. Adds the inputMapping column to
            // wp_pcm_automations (additive via dbDelta) for trigger-context →
            // action-input mapping. No separate gate needed.

            // Back-fill default automation rules for EXISTING users on any
            // upgrade that added new defaults (v1.17 reminders, v1.18 flow rules,
            // v1.19 notification rules). seed_for_user is idempotent — already-
            // seeded keys are skipped via the __seedKey marker — so re-running it
            // only adds the missing rules. Gated at the latest seed-bearing
            // version so installs already at an intermediate version still get
            // the newer rules. Per-user seeding for NEW users happens in
            // PCM_REST_Base::get_current_pcm_user().
            if (version_compare($installed_version, '1.19.0', '<')
                && class_exists('PCM_Automation_Seeds')) {
                global $wpdb;
                $users_table = PCM_Schema::table('users');
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $user_ids = $wpdb->get_col("SELECT id FROM {$users_table}");
                foreach (($user_ids ?: array()) as $uid) {
                    PCM_Automation_Seeds::seed_for_user((int) $uid);
                }
            }

            // v1.22.1 — heal users mirrored by the Users module without seeds.
            // PCM_Users_Service::list_users() created pcm rows via upsert_user,
            // bypassing the per-user seeding in get_current_pcm_user(), so those
            // users had NO automation rules (notifications never fired for their
            // approval sets) and no default prompts. Both seeders are idempotent.
            if (version_compare($installed_version, '1.22.1', '<')) {
                global $wpdb;
                $users_table = PCM_Schema::table('users');
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $user_ids = $wpdb->get_col("SELECT id FROM {$users_table}");
                foreach (($user_ids ?: array()) as $uid) {
                    if (class_exists('PCM_Automation_Seeds')) {
                        PCM_Automation_Seeds::seed_for_user((int) $uid);
                    }
                    if (class_exists('PCM_Prompt_Seeds')) {
                        PCM_Prompt_Seeds::seed_for_user((int) $uid);
                    }
                }
            }

            update_option('pcm_db_version', PCM_DB_VERSION);
        }
    }
}
