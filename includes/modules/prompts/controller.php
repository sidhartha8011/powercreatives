<?php
/**
 * PromptOverrides REST Controller
 *
 * Manages custom prompt templates and variants.
 * PHP port of server/routers/promptOverrides.ts (8 endpoints, 172 lines).
 *
 * Endpoints:
 *   GET    /prompts/<module>                          → list sections
 *   GET    /prompts/<module>/<section>/variants       → list variants
 *   GET    /prompts/<module>/<section>                → get prompt content
 *   POST   /prompts                                   → create variant
 *   POST   /prompts/<id>/duplicate                    → duplicate variant
 *   PATCH  /prompts/<id>                              → update variant
 *   DELETE /prompts/<id>                              → delete variant
 *   POST   /prompts/<id>/default                      → set as default
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Prompts extends PCM_REST_Base
{

    /**
     * Define all prompt routes.
     *
     * @return array
     */
    protected function routes(): array
    {
        return array(
            // Read
                array('GET', '/prompts/(?P<module>\\w+)', 'list_sections'),
                array(
                'GET',
                '/prompts/(?P<module>\\w+)/(?P<section>[\\w.-]+)/variants',
                'list_variants',
            ),
                array(
                'GET',
                '/prompts/(?P<module>\\w+)/(?P<section>[\\w.-]+)',
                'get_prompt',
            ),

            // CRUD
                array('POST', '/prompts', 'create_variant'),
                array('POST', '/prompts/(?P<id>\\d+)/duplicate', 'duplicate_variant'),
                array('PATCH', '/prompts/(?P<id>\\d+)', 'update_variant'),
                array('DELETE', '/prompts/(?P<id>\\d+)', 'delete_variant'),

            // Actions
                array('POST', '/prompts/(?P<id>\\d+)/default', 'set_default'),
        );
    }

    // =========================================================================
    // READ OPERATIONS
    // =========================================================================

    /**
     * GET /prompts/<module> — List prompt sections for a module.
     *
     * Retrieves all unique sections that have prompt overrides.
     * Falls back to the default prompt registry if no overrides exist.
     */
    public function list_sections(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $module = sanitize_text_field($request->get_param('module'));
        $table = PCM_Schema::table('prompt_overrides');

        // Get sections that have overrides in DB
        $db_sections = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT section FROM {$table} WHERE userId = %d AND module = %s ORDER BY section ASC",
            $user->id,
            $module
        ));

        // Only show sections that are registered in the default registry.
        // DB rows for deregistered sections (e.g. moved to Templates) are ignored.
        $defaults = $this->get_default_sections($module);
        $all = $defaults;
        sort($all);

        // Section metadata — human-readable labels + descriptions.
        // Must match what the frontend PromptEditorSection.tsx expects.
        $meta = $this->get_section_meta();

        $result = array();
        foreach ($all as $section) {
            $has_custom = in_array($section, $db_sections, true);
            $section_meta = $meta["{$module}.{$section}"] ?? null;
            $result[] = array(
                'section' => $section,
                'label' => $section_meta['label'] ?? ucfirst(str_replace('_', ' ', $section)),
                'description' => $section_meta['description'] ?? '',
                'hasCustom' => $has_custom,
                'module' => $module,
            );
        }

        return $this->success(array('sections' => $result));
    }

    /**
     * GET /prompts/<module>/<section>/variants — List all variants for a prompt section.
     *
     * If no DB variants exist, returns a virtual "Built-in Default" variant
     * with the built-in default prompt content. This ensures the Prompt Editor
     * always has content to display, even if seeds haven't run.
     */
    public function list_variants(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $module = sanitize_text_field($request->get_param('module'));
        $section = sanitize_text_field($request->get_param('section'));
        $table = PCM_Schema::table('prompt_overrides');

        $db_variants = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE userId = %d AND module = %s AND section = %s ORDER BY isActive DESC, variantName ASC",
            $user->id,
            $module,
            $section
        ));

        if (!empty($db_variants)) {
            return $this->success(array('variants' => array_map(array($this, 'format_variant'), $db_variants)));
        }

        // No DB variants — return a virtual built-in default so the editor
        // always shows the current prompt. The user can then "save" it to DB.
        $default_content = $this->get_default_prompt($module, $section);
        $meta = $this->get_section_meta();
        $section_label = $meta["{$module}.{$section}"]['label'] ?? ucfirst(str_replace('_', ' ', $section));

        return $this->success(array('variants' => array(
                    array(
                    'id' => 0,
                    'module' => $module,
                    'section' => $section,
                    'name' => $section_label . ' (Built-in)',
                    'content' => $default_content,
                    'isDefault' => true,
                    'createdAt' => current_time('mysql'),
                    'updatedAt' => current_time('mysql'),
                ),
            )));
    }

    /**
     * GET /prompts/<module>/<section> — Get the active prompt content.
     *
     * Returns the default variant if one exists, otherwise the built-in default.
     */
    public function get_prompt(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $module = sanitize_text_field($request->get_param('module'));
        $section = sanitize_text_field($request->get_param('section'));
        $table = PCM_Schema::table('prompt_overrides');

        // Try to get the default variant
        $variant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE userId = %d AND module = %s AND section = %s AND isActive = 1 LIMIT 1",
            $user->id,
            $module,
            $section
        ));

        if ($variant) {
            return $this->success(array(
                'content' => $variant->content,
                'source' => 'custom',
                'variant' => $this->format_variant($variant),
            ));
        }

        // Fall back to built-in default
        $default_content = $this->get_default_prompt($module, $section);

        return $this->success(array(
            'content' => $default_content,
            'source' => 'default',
            'variant' => null,
        ));
    }

    // =========================================================================
    // CRUD OPERATIONS
    // =========================================================================

    /**
     * POST /prompts — Create a new prompt variant.
     */
    public function create_variant(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $module = sanitize_text_field($request->get_param('module'));
        $section = sanitize_text_field($request->get_param('section'));
        $name = sanitize_text_field($request->get_param('name') ?? $request->get_param('variantName') ?? '');
        $content = $request->get_param('content'); // Don't sanitize prompt content (preserves formatting)

        if (empty($module) || empty($section) || empty($name)) {
            return $this->error('Module, section, and name are required.');
        }

        $table = PCM_Schema::table('prompt_overrides');

        $wpdb->insert($table, array(
            'userId' => $user->id,
            'module' => $module,
            'section' => $section,
            'variantName' => $name,
            'content' => $content ?? '',
            'isActive' => 0,
            'createdAt' => current_time('mysql'),
            'updatedAt' => current_time('mysql'),
        ));

        $id = $wpdb->insert_id;

        if (!$id) {
            return $this->error('Failed to create variant.', 500);
        }

        return $this->success(array('success' => true, 'id' => $id), 201);
    }

    /**
     * POST /prompts/<id>/duplicate — Duplicate an existing variant.
     */
    public function duplicate_variant(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));
        $table = PCM_Schema::table('prompt_overrides');

        $source = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND userId = %d",
            $id,
            $user->id
        ));

        if (!$source) {
            return $this->not_found('Prompt variant');
        }

        $wpdb->insert($table, array(
            'userId' => $user->id,
            'module' => $source->module,
            'section' => $source->section,
            'variantName' => $source->variantName . ' (copy)',
            'content' => $source->content,
            'isActive' => 0,
            'createdAt' => current_time('mysql'),
            'updatedAt' => current_time('mysql'),
        ));

        return $this->success(array('success' => true, 'id' => $wpdb->insert_id), 201);
    }

    /**
     * PATCH /prompts/<id> — Update a prompt variant.
     */
    public function update_variant(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));
        $table = PCM_Schema::table('prompt_overrides');

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND userId = %d",
            $id,
            $user->id
        ));

        if (!$existing) {
            return $this->not_found('Prompt variant');
        }

        $update = array('updatedAt' => current_time('mysql'));

        $name = $request->get_param('name') ?? $request->get_param('variantName');
        if (null !== $name) {
            $update['variantName'] = sanitize_text_field($name);
        }

        $content = $request->get_param('content');
        if (null !== $content) {
            $update['content'] = $content; // Preserve prompt formatting

            // Auto-activate: editing a prompt's content means the user intends
            // to USE this version. Deactivate all other variants for this
            // module+section, then mark this one as active.
            $wpdb->update(
                $table,
                array('isActive' => 0),
                array(
                'userId' => $user->id,
                'module' => $existing->module,
                'section' => $existing->section,
            )
            );
            $update['isActive'] = 1;
        }

        $wpdb->update(
            $table,
            $update,
            array('id' => $id, 'userId' => $user->id)
        );

        return $this->success(array('success' => true));
    }

    /**
     * DELETE /prompts/<id> — Delete a prompt variant.
     */
    public function delete_variant(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));
        $table = PCM_Schema::table('prompt_overrides');

        // Fetch the variant before deleting so we know if it was active
        $variant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND userId = %d",
            $id,
            $user->id
        ));

        if (!$variant) {
            return $this->not_found('Prompt variant');
        }

        $was_active = (bool)$variant->isActive;
        $module = $variant->module;
        $section = $variant->section;

        $wpdb->delete(
            $table,
            array('id' => $id, 'userId' => $user->id),
            array('%d', '%d')
        );

        // ── Auto-promote: if the deleted variant was active, promote the
        // most recent remaining variant so the section is never left without
        // an active prompt. This is critical — a section with zero active
        // variants would cause generation to fail.
        if ($was_active) {
            $next = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE userId = %d AND module = %s AND section = %s ORDER BY updatedAt DESC LIMIT 1",
                $user->id,
                $module,
                $section
            ));

            if ($next) {
                $wpdb->update(
                    $table,
                    array('isActive' => 1),
                    array('id' => (int)$next)
                );
            }
        }

        return $this->success(array('success' => true));
    }

    /**
     * POST /prompts/<id>/default — Set a variant as the default for its section.
     * Unsets any existing default for the same module + section.
     */
    public function set_default(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));
        $table = PCM_Schema::table('prompt_overrides');

        $variant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND userId = %d",
            $id,
            $user->id
        ));

        if (!$variant) {
            return $this->not_found('Prompt variant');
        }

        // Unset existing active flags for this module + section
        $wpdb->update(
            $table,
            array('isActive' => 0),
            array(
            'userId' => $user->id,
            'module' => $variant->module,
            'section' => $variant->section,
        )
        );

        // Set the new active variant
        $wpdb->update(
            $table,
            array('isActive' => 1),
            array('id' => $id, 'userId' => $user->id)
        );

        return $this->success(array('success' => true));
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Format a prompt variant DB row for JSON output.
     */
    private function format_variant(object $variant): array
    {
        return array(
            'id' => (int)$variant->id,
            'module' => $variant->module,
            'section' => $variant->section,
            'name' => $variant->variantName,
            'content' => $variant->content,
            'isDefault' => (bool)$variant->isActive,
            'createdAt' => $variant->createdAt,
            'updatedAt' => $variant->updatedAt,
        );
    }

    /**
     * Get human-readable metadata for prompt sections.
     *
     * Used by list_sections() to provide labels and descriptions
     * that the frontend PromptEditorSection.tsx renders as subtab names.
     *
     * @return array Keyed by "module.section" → { label, description }.
     */
    private function get_section_meta(): array
    {
        return array(
            // Copy module
            // Ads — system prompt (persona + global formatting rules, role:system)
            'copy.system_prompt_ads_system' => array(
                'label' => 'Ads System Prompt',
                'description' => 'Persona and global formatting rules sent as role:system before every ad generation. Controls the LLM\'s behavior and output format. Does NOT support {{placeholders}}.',
            ),
            // Ads — user prompt (business context + style + task, role:user)
            'copy.system_prompt_ads' => array(
                'label' => 'Ads User Prompt',
                'description' => 'Business context, style rules, and task instructions sent as role:user. Supports {{language}}, {{brief}}, {{campaignContext}}, {{referenceCopy}}, {{reviewsContext}}, {{toneInstruction}}, {{emojiInstruction}}, {{ctaInstruction}}, {{typeLabel}}, {{audience}}, {{angle}}.',
            ),
            'copy.system_prompt_organic' => array(
                'label' => 'Organic System Prompt',
                'description' => 'Prompt for organic social media posts. Supports {{language}}, {{brief}}, {{campaignContext}}, {{referenceCopy}}, {{reviewsContext}}, {{organicContext}}, {{toneInstruction}}, {{emojiInstruction}}, {{typeLabel}}, {{audience}}, {{angle}}.',
            ),
            'copy.angle_generation' => array(
                'label' => 'Angle Generation',
                'description' => 'Prompt for auto-generating marketing angles. Supports {{brandName}}, {{product}}, {{description}}, {{referenceAds}}, {{tone}}, {{count}}, {{campaignContext}}.',
            ),
            'copy.audience_generation' => array(
                'label' => 'Audience Generation',
                'description' => 'Prompt for auto-generating target audiences. Supports {{brandName}}, {{product}}, {{description}}, {{referenceAds}}, {{tone}}, {{count}}, {{campaignContext}}, {{researchContext}}.',
            ),
            'copy.audience_research' => array(
                'label' => 'Audience Research',
                'description' => 'How it works: Your prompt is sent to Gemini, which then performs real Google Searches to gather market data. Gemini decides the search terms itself based on your prompt — you cannot see or control the exact queries. It may run multiple searches per request. The research result is injected into the Audience Generation prompt via {{researchContext}}. Tip: Be specific about market, country, and product to get more relevant results. Cost: ~$0.035/request ($35 per 1,000 queries after 1,500 free daily). Supports {{brandName}}, {{product}}, {{description}}, {{campaignContext}}.',
            ),
            'copy.angle_generation_with_audiences' => array(
                'label' => 'Angle Generation (Audience-Aware)',
                'description' => 'Prompt for generating angles specific to audience segments. Supports {{brandName}}, {{product}}, {{description}}, {{referenceAds}}, {{tone}}, {{audiences}}, {{anglesPerAudience}}, {{totalAngles}}, {{campaignContext}}.',
            ),

            // Video module
            'video.concept_suggestions' => array(
                'label' => 'Concept Suggestions',
                'description' => 'Prompt for generating video concept ideas from a brief.',
            ),
            'video.compose' => array(
                'label' => 'Compose Prompt',
                'description' => 'Prompt for composing a final video generation prompt from components.',
            ),

            // Image module
            'image.prompt_suggestions_system' => array(
                'label' => 'Prompt Suggestions — System',
                'description' => 'System prompt (role:system) for brief-based image suggestions. Controls the AI\'s persona and behavior. Does NOT support {{placeholders}}.',
            ),
            'image.prompt_suggestions' => array(
                'label' => 'Prompt Suggestions — User',
                'description' => 'User prompt template for brief-based image suggestions. Supports {{count}}, {{brief}}, {{brandName}}, {{seasonEvent}}, {{campaignTheme}}, {{brandColors}}.',
            ),
            'image.context_suggestions_system' => array(
                'label' => 'Context Suggestions — System',
                'description' => 'System prompt (role:system) for context-based image suggestions (brand/URL). Controls the AI\'s persona and behavior. Does NOT support {{placeholders}}.',
            ),
            'image.context_suggestions' => array(
                'label' => 'Context Suggestions — User',
                'description' => 'User prompt template for context-based image suggestions. Supports {{count}}, {{brandName}}, {{brandSummary}}, {{url}}, {{seasonEvent}}, {{campaignTheme}}, {{brandColors}}.',
            ),
            'image.concept_suggestions' => array(
                'label' => 'Angles (Scenes) — System',
                'description' => 'System prompt (role:system) for generating creative angles/scenes from a product brief. Controls how the AI interprets reference images and intents. Does NOT support {{placeholders}}.',
            ),
            'image.concept_suggestions_user' => array(
                'label' => 'Angles (Scenes) — User',
                'description' => 'User prompt template for angle/scene generation. This is what the AI receives as the task. Supports {{count}}, {{brief}}, {{style}}, {{brandName}}, {{brandSummary}}.',
            ),
            'image.brief_optimization' => array(
                'label' => 'Brief Optimization — System',
                'description' => 'System prompt for rewriting a product brief into a detailed, AI-optimized image generation prompt. Does NOT support {{placeholders}}.',
            ),
            'image.brief_optimization_user' => array(
                'label' => 'Brief Optimization — User',
                'description' => 'User prompt template for brief optimization. Supports {{brief}}, {{brandName}}, {{niche}}, {{location}}, {{language}}, {{phone}}, {{url}}, {{brandColors}}.',
            ),
            'image.final_prompt' => array(
                'label' => 'Final Prompt',
                'description' => 'The actual prompt sent to the image model (DALL-E, Flux, Kling). Wraps the brief with brand context. Empty variables disappear automatically. Supports {{brief}}, {{brandName}}, {{niche}}, {{location}}, {{language}}, {{brandColors}}.',
            ),

            // Writer module
            'writer.writer_system' => array(
                'label' => 'System Prompt',
                'description' => 'Persona and behavior rules sent as role:system. Supports {{settingsContext}}, {{brandContext}}.',
            ),
            'writer.writer_user' => array(
                'label' => 'User Prompt',
                'description' => 'Task instructions and output format sent as role:user. Supports {{instructionContext}}, {{keyword}}, {{supporting}}, {{mediaInstruction}}.',
            ),
        );
    }

    /**
     * Get default prompt sections for a module.
     * Mirrors the promptRegistry from the original app.
     *
     * @param string $module Module name (copy, image, video).
     * @return array List of section names.
     */
    private function get_default_sections(string $module): array
    {
        // Section names must match what the service layer uses.
        // Copy: system prompts + generation + research steps.
        // Video: concept suggestions + compose + enhance.
        // Image: concept suggestions.
        $registry = array(
            // Copy: system prompt (role:system) + user prompt (role:user) for ads,
            // plus organic prompt and all generation + research sections.
            'copy' => array('system_prompt_ads_system', 'system_prompt_ads', 'system_prompt_organic', 'angle_generation', 'audience_generation', 'audience_research', 'angle_generation_with_audiences'),
            'image' => array('prompt_suggestions_system', 'prompt_suggestions', 'context_suggestions_system', 'context_suggestions', 'concept_suggestions', 'concept_suggestions_user', 'brief_optimization', 'brief_optimization_user', 'final_prompt'),
            'video' => array('concept_suggestions', 'compose', 'enhance'),
            'writer' => array('writer_system', 'writer_user'),
        );

        return $registry[$module] ?? array();
    }

    /**
     * Get the built-in default prompt content for a module section.
     *
     * Returns the rich template content with {{placeholders}} for copy prompts,
     * and the actual constants for video prompts.
     *
     * @param string $module  Module name.
     * @param string $section Section name.
     * @return string Default prompt content.
     */
    private function get_default_prompt(string $module, string $section): string
    {
        // Video module: reference actual prompts from the video controller
        if ($module === 'video') {
            return match ($section) {
                    'concept_suggestions' => PCM_REST_Video::DEFAULT_CONCEPTS_PROMPT,
                    'compose' => PCM_REST_Video::DEFAULT_COMPOSE_PROMPT,
                    default => '',
                };
        }

        // Copy module: delegate to the service's default templates.
        // These contain {{placeholders}} resolved at generation time.
        if ($module === 'copy') {
            $defaults = PCM_Copy_Service::get_default_prompts();
            return $defaults[$section] ?? '';
        }

        // Image module: delegate to the service's default templates.
        if ($module === 'image') {
            $defaults = PCM_Image_Service::get_default_prompts();
            return $defaults[$section] ?? '';
        }

        // Writer module: delegate to the service's default templates.
        if ($module === 'writer') {
            $defaults = PCM_Writer_Service::get_default_prompts();
            return $defaults[$section] ?? '';
        }

        return '';
    }
}
