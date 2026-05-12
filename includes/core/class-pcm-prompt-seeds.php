<?php
/**
 * Prompt Seeds — Default system prompt seeder
 *
 * Seeds default prompt variants for each module into the prompt_overrides
 * table. Runs on plugin activation. Idempotent — skips if a variant already
 * exists for the same user + module + section combination.
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Prompt_Seeds
{
    /**
     * Seed default prompts for all users who have PCM user records.
     *
     * Called from PCM_Activator::activate(). Safe to call multiple times.
     */
    public static function seed(): void
    {
        global $wpdb;

        $users_table = PCM_Schema::table('users');
        $prompt_table = PCM_Schema::table('prompt_overrides');

        // Get all PCM users
        $user_ids = $wpdb->get_col("SELECT id FROM {$users_table}");

        if (empty($user_ids)) {
            error_log('[PCM_Prompt_Seeds] No PCM users found — skipping seed.');
            return;
        }

        $prompts = self::get_seed_prompts();
        error_log('[PCM_Prompt_Seeds] seed() called. Users: ' . count($user_ids) . ', Prompts: ' . count($prompts));

        foreach ($user_ids as $user_id) {
            foreach ($prompts as $prompt) {
                self::seed_single($prompt_table, (int)$user_id, $prompt);
            }
        }
    }

    /**
     * Seed prompts for a single user ID.
     *
     * Can be called independently when a new user is created.
     *
     * @param int $user_id PCM user ID.
     */
    public static function seed_for_user(int $user_id): void
    {
        $prompt_table = PCM_Schema::table('prompt_overrides');
        $prompts = self::get_seed_prompts();

        foreach ($prompts as $prompt) {
            self::seed_single($prompt_table, $user_id, $prompt);
        }
    }

    /**
     * Insert a single prompt if it doesn't already exist for the user.
     *
     * @param string $table   Full table name.
     * @param int    $user_id PCM user ID.
     * @param array  $prompt  Prompt definition { module, section, variantName, content }.
     */
    private static function seed_single(string $table, int $user_id, array $prompt): void
    {
        global $wpdb;

        // Check if a variant already exists for this user + module + section
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE userId = %d AND module = %s AND section = %s LIMIT 1",
            $user_id,
            $prompt['module'],
            $prompt['section']
        ));

        if ($exists) {
            // Row exists — ensure at least one variant is active for this section.
            // Repairs scenarios where all variants ended up with isActive=0
            // (e.g. active variant was deleted before auto-promote was added).
            $has_active = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE userId = %d AND module = %s AND section = %s AND isActive = 1 LIMIT 1",
                $user_id,
                $prompt['module'],
                $prompt['section']
            ));

            if (!$has_active) {
                $most_recent_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE userId = %d AND module = %s AND section = %s ORDER BY updatedAt DESC LIMIT 1",
                    $user_id,
                    $prompt['module'],
                    $prompt['section']
                ));
                if ($most_recent_id) {
                    $wpdb->update($table, array('isActive' => 1), array('id' => (int)$most_recent_id));
                    error_log("[PCM_Prompt_Seeds] Repaired: user={$user_id} module={$prompt['module']} section={$prompt['section']} activated id={$most_recent_id}");
                }
            }
            return;
        }

        $wpdb->insert($table, array(
            'userId' => $user_id,
            'module' => $prompt['module'],
            'section' => $prompt['section'],
            'variantName' => $prompt['variantName'],
            'content' => $prompt['content'],
            'isActive' => 1, // First variant is active by default
            'createdAt' => current_time('mysql'),
            'updatedAt' => current_time('mysql'),
        ));

        error_log("[PCM_Prompt_Seeds] Seeded: user={$user_id} module={$prompt['module']} section={$prompt['section']} result=" . ($wpdb->insert_id ? 'OK id=' . $wpdb->insert_id : 'FAILED: ' . $wpdb->last_error));
    }

    /**
     * Get the list of default prompt definitions to seed.
     *
     * Copy prompts come from the service defaults (rich templates with {{placeholders}}).
     * Video prompts come from the video controller constants.
     *
     * @return array List of prompt definitions.
     */
    private static function get_seed_prompts(): array
    {
        // Get copy defaults from the centralised registry
        $copy_defaults = PCM_Copy_Service::get_default_prompts();

        $prompts = array(
            // ── Copy Module (7 sections — system + user prompt split for ads) ──

            // System prompt: persona + global rules, sent as role:system
                array(
                'module' => 'copy',
                'section' => 'system_prompt_ads_system',
                'variantName' => 'Default Ads System Prompt',
                'content' => $copy_defaults['system_prompt_ads_system'],
            ),

            // User prompt: business context + style + task, sent as role:user
                array(
                'module' => 'copy',
                'section' => 'system_prompt_ads',
                'variantName' => 'Default Ads User Prompt',
                'content' => $copy_defaults['system_prompt_ads'],
            ),
                array(
                'module' => 'copy',
                'section' => 'system_prompt_organic',
                'variantName' => 'Default Organic Prompt',
                'content' => $copy_defaults['system_prompt_organic'],
            ),
                array(
                'module' => 'copy',
                'section' => 'angle_generation',
                'variantName' => 'Default Angle Generation',
                'content' => $copy_defaults['angle_generation'],
            ),
                array(
                'module' => 'copy',
                'section' => 'audience_generation',
                'variantName' => 'Default Audience Generation',
                'content' => $copy_defaults['audience_generation'],
            ),
                array(
                'module' => 'copy',
                'section' => 'angle_generation_with_audiences',
                'variantName' => 'Default Audience-Aware Angles',
                'content' => $copy_defaults['angle_generation_with_audiences'],
            ),
                array(
                'module' => 'copy',
                'section' => 'audience_research',
                'variantName' => 'Default Audience Research',
                'content' => $copy_defaults['audience_research'],
            ),
        );

        // ── Video Module ──
        // Only add if the video controller class exists and has the constants
        if (class_exists('PCM_REST_Video')) {
            if (defined('PCM_REST_Video::DEFAULT_CONCEPTS_PROMPT')) {
                $prompts[] = array(
                    'module' => 'video',
                    'section' => 'concept_suggestions',
                    'variantName' => 'Default Concepts Prompt',
                    'content' => PCM_REST_Video::DEFAULT_CONCEPTS_PROMPT,
                );
            }
            if (defined('PCM_REST_Video::DEFAULT_COMPOSE_PROMPT')) {
                $prompts[] = array(
                    'module' => 'video',
                    'section' => 'compose',
                    'variantName' => 'Default Compose Prompt',
                    'content' => PCM_REST_Video::DEFAULT_COMPOSE_PROMPT,
                );
            }
            if (defined('PCM_REST_Video::DEFAULT_ENHANCE_PROMPT')) {
                $prompts[] = array(
                    'module' => 'video',
                    'section' => 'enhance',
                    'variantName' => 'Default Enhance Prompt',
                    'content' => PCM_REST_Video::DEFAULT_ENHANCE_PROMPT,
                );
            }
        }

        // ── Image Module ──
        $prompts[] = array(
            'module' => 'image',
            'section' => 'concept_suggestions',
            'variantName' => 'Default Image Concepts Prompt',
            'content' => 'You are a creative director specializing in visual advertising. '
            . 'Suggest unique, compelling image concepts based on the brief. '
            . 'Each concept should have a short creative name (2-4 words) and a detailed description '
            . 'suitable as a prompt for AI image generation (1-3 sentences). '
            . 'Focus on concepts that would work well for social media advertising.',
        );

        // ── Writer Module ──
        // Writer prompts use {{placeholders}} resolved at generation time.
        if (class_exists('PCM_Writer_Service')) {
            $writer_defaults = PCM_Writer_Service::get_default_prompts();
            $prompts[] = array(
                'module' => 'writer',
                'section' => 'writer_system',
                'variantName' => 'Default Writer System Prompt',
                'content' => $writer_defaults['writer_system'],
            );
            $prompts[] = array(
                'module' => 'writer',
                'section' => 'writer_user',
                'variantName' => 'Default Writer User Prompt',
                'content' => $writer_defaults['writer_user'],
            );
        }

        return $prompts;
    }

    /**
     * v1.2.0 Migration: Append TASK section to existing ads/organic prompts.
     *
     * Before v1.2.0, the audience/angle targeting instruction was a hardcoded
     * user-message invisible to the user. Now it's part of the editable template.
     * This migration adds it to existing prompts that don't have it yet.
     *
     * Idempotent: skips prompts that already contain {{typeLabel}}.
     */
    public static function migrate_v1_2_0(): void
    {
        global $wpdb;
        $table = PCM_Schema::table('prompt_overrides');

        // The TASK section to append to existing prompts
        $task_section = "\n\nTASK:\nWrite a {{typeLabel}} for this specific audience and angle:\nTARGET AUDIENCE: {{audience}}\nMARKETING ANGLE: {{angle}}\nTailor the copy specifically to resonate with '{{audience}}' using the '{{angle}}' approach. Make it feel personal and relevant to this exact audience segment.";

        // Find all ads/organic prompts that lack the TASK section
        $sections = array('system_prompt_ads', 'system_prompt_organic');

        foreach ($sections as $section) {
            // Get all variants for this section (all users)
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, content FROM {$table} WHERE module = 'copy' AND section = %s",
                $section
            ));

            if (empty($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                // Idempotent check: skip if already contains {{typeLabel}}
                if (strpos($row->content, '{{typeLabel}}') !== false) {
                    continue;
                }

                // Append TASK section
                $wpdb->update(
                    $table,
                    array(
                    'content' => $row->content . $task_section,
                    'updatedAt' => current_time('mysql'),
                ),
                    array('id' => (int)$row->id)
                );
            }
        }
    }
}
