<?php
/**
 * Image Service — Business logic for image generation, suggestions, and asset persistence.
 *
 * Note: get_provider_api_key() and get_prompt_override() are inherited from
 * PCM_REST_Base and NOT duplicated here.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Image_Service
{

    /** JSON schema for concept suggestion LLM structured output. @var array */
    public const CONCEPTS_SCHEMA = array(
        'name' => 'concept_suggestions',
        'strict' => true,
        'schema' => array(
            'type' => 'object',
            'properties' => array(
                'concepts' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'name' => array('type' => 'string'),
                            'description' => array('type' => 'string'),
                        ),
                        'required' => array('name', 'description'),
                        'additionalProperties' => false,
                    ),
                ),
            ),
            'required' => array('concepts'),
            'additionalProperties' => false,
        ),
    );

    /** JSON schema for prompt suggestion LLM structured output. @var array */
    public const SUGGESTIONS_SCHEMA = array(
        'name' => 'prompt_suggestions',
        'strict' => true,
        'schema' => array(
            'type' => 'object',
            'properties' => array(
                'suggestions' => array(
                    'type' => 'array',
                    'items' => array('type' => 'string'),
                ),
            ),
            'required' => array('suggestions'),
            'additionalProperties' => false,
        ),
    );

    // =========================================================================
    // CONCEPT SUGGESTIONS
    // =========================================================================

    /**
     * Suggest creative concepts for image generation using an LLM.
     *
     * Supports multimodal input (text + reference images).
     * Returns an array of { name, description } concept objects.
     *
     * @param int         $user_id       PCM user ID.
     * @param string      $prompt        Product brief / input prompt.
     * @param int         $count         Number of concepts to generate.
     * @param string      $style         Optional visual style hint.
     * @param array|null  $brand_context Optional brand context (name, summary, colors).
     * @param array       $ref_images    Reference images [{ url, intent }].
     * @param string|null $system_prompt Optional prompt override from prompt_overrides table.
     * @param string      $model_id      Optional model ID override.
     *
     * @return array Array of concept objects.
     * @throws \RuntimeException If LLM call fails.
     */
    public function suggest_concepts(
        int $user_id,
        string $prompt,
        int $count = 3,
        string $style = '',
        ?array $brand_context = null,
        array $ref_images = array(),
        ?string $system_prompt = null,
        string $model_id = '',
        ?string $user_template = null
        ): array
    {
        // Gracefully fall back to a built-in system prompt when no override is configured.
        // Same pattern as suggest_prompts() and optimize_brief().
        if (!$system_prompt) {
            $defaults = self::get_default_prompts();
            $system_prompt = $defaults['concept_suggestions'];
        }

        // Resolve {{placeholders}} in the system prompt (e.g. {{referenceImageIntent}}).
        // Uses the same placeholder system as suggest_prompts() and suggest_context_prompts().
        $vars = $this->build_image_context(
            array_merge($brand_context ?? array(), array('referenceImages' => $ref_images)),
            $count
        );
        $system_prompt = $this->resolve_prompt_placeholders($system_prompt, $vars);

        // Resolve user prompt template — DB override or built-in default.
        // Same pattern as suggest_prompts() — the user prompt is now fully
        // editable via Settings → Prompt Editor → Image → Angles (Scenes) User.
        if (!$user_template) {
            $defaults = $defaults ?? self::get_default_prompts();
            $user_template = $defaults['concept_suggestions_user'];
        }

        // Add concept-specific variables for the user template
        $vars['brief'] = $prompt;
        if ($style) {
            $vars['style'] = 'Style: ' . $style;
        }
        $user_text = $this->resolve_prompt_placeholders($user_template, $vars);

        // Build the user message — multimodal if reference images are provided
        $user_content = $this->build_multimodal_user_message($user_text, $ref_images);

        $messages = array(
                array('role' => 'system', 'content' => $system_prompt),
                array('role' => 'user', 'content' => $user_content),
        );

        $result = PCM_LLM::invoke_json($messages, self::CONCEPTS_SCHEMA, array(
            'user_id' => $user_id,
            'model' => $model_id ?: null,
        ));

        return $result['concepts'] ?? array();
    }

    // =========================================================================
    // PROMPT SUGGESTIONS (brief-based)
    // =========================================================================

    /**
     * Suggest visual prompt ideas from a product brief.
     *
     * Used by the "Get AI Suggestions" button in the Image module sidebar.
     *
     * @param int         $user_id       PCM user ID.
     * @param string      $brief         Product brief text.
     * @param int         $count         Number of suggestions to generate.
     * @param array       $context       Additional context (brandName, url, seasonEvent, etc.).
     * @param string|null $system_prompt Prompt override from prompt_overrides table.
     * @param string      $model_id      Optional model ID override.
     *
     * @return string[] Array of prompt suggestion strings.
     * @throws \RuntimeException If LLM call fails or no prompt configured.
     */
    public function suggest_prompts(
        int $user_id,
        string $brief,
        int $count = 3,
        array $context = array(),
        ?string $system_prompt = null,
        string $model_id = '',
        ?string $user_template = null
        ): array
    {
        // System prompt — editable via Settings → Prompt Editor → Image
        if (!$system_prompt) {
            $defaults = self::get_default_prompts();
            $system_prompt = $defaults['prompt_suggestions_system'];
        }

        // Build placeholder variables from context
        $vars = $this->build_image_context($context, $count);
        $vars['brief'] = $brief;

        // Resolve user prompt template — DB override or built-in default
        if (!$user_template) {
            $defaults = $defaults ?? self::get_default_prompts();
            $user_template = $defaults['prompt_suggestions'];
        }
        $user_text = $this->resolve_prompt_placeholders($user_template, $vars);

        // Build the user message — multimodal if reference images are provided
        $ref_images = $context['referenceImages'] ?? array();
        $user_content = $this->build_multimodal_user_message($user_text, $ref_images);

        $messages = array(
                array('role' => 'system', 'content' => $system_prompt),
                array('role' => 'user', 'content' => $user_content),
        );

        $result = PCM_LLM::invoke_json($messages, self::SUGGESTIONS_SCHEMA, array(
            'user_id' => $user_id,
            'model' => $model_id ?: null,
        ));

        return $result['suggestions'] ?? array();
    }

    // =========================================================================
    // CONTEXT-BASED SUGGESTIONS
    // =========================================================================

    /**
     * Suggest visual prompt ideas from brand/URL context (no specific brief needed).
     *
     * Used by the "Generate Context Suggestions" button when brand or URL is selected.
     *
     * @param int         $user_id       PCM user ID.
     * @param int         $count         Number of suggestions.
     * @param array       $context       Context signals (brandName, brandSummary, url, seasonEvent, campaignTheme).
     * @param string|null $system_prompt Prompt override.
     * @param string      $model_id      Optional model ID override.
     *
     * @return string[] Array of prompt suggestion strings.
     * @throws \RuntimeException If LLM call fails.
     */
    public function suggest_context_prompts(
        int $user_id,
        int $count = 4,
        array $context = array(),
        ?string $system_prompt = null,
        string $model_id = '',
        ?string $user_template = null
        ): array
    {
        // System prompt — editable via Settings → Prompt Editor → Image
        if (!$system_prompt) {
            $defaults = self::get_default_prompts();
            $system_prompt = $defaults['context_suggestions_system'];
        }

        // Build placeholder variables from context
        $vars = $this->build_image_context($context, $count);

        // Resolve user prompt template — DB override or built-in default
        if (!$user_template) {
            $defaults = $defaults ?? self::get_default_prompts();
            $user_template = $defaults['context_suggestions'];
        }
        $user_text = $this->resolve_prompt_placeholders($user_template, $vars);

        // Build the user message — multimodal if reference images are provided
        $ref_images = $context['referenceImages'] ?? array();
        $user_content = $this->build_multimodal_user_message($user_text, $ref_images);

        $messages = array(
                array('role' => 'system', 'content' => $system_prompt),
                array('role' => 'user', 'content' => $user_content),
        );

        $result = PCM_LLM::invoke_json($messages, self::SUGGESTIONS_SCHEMA, array(
            'user_id' => $user_id,
            'model' => $model_id ?: null,
        ));

        return $result['suggestions'] ?? array();
    }

    /**
     * Build a multimodal user message with text + optional reference image(s).
     *
     * If no reference images are provided, returns the text string as-is.
     * If images are provided, returns an array of content parts in OpenAI
     * multimodal format: [{type:'text'}, {type:'image_url'}, ...]
     *
     * Uses 'detail: low' (85 tokens/image) — sufficient for creative direction
     * analysis without excessive token consumption.
     *
     * @param string $text   The text portion of the message.
     * @param array  $images Reference images: [{url: string, intent: string}, ...].
     *
     * @return string|array Text string or multimodal content array.
     */
    private function build_multimodal_user_message(string $text, array $images): string|array
    {
        if (empty($images)) {
            return $text;
        }

        // Intent-specific prompt fragments (mirrors referenceImageIntents.ts)
        $intent_fragments = array(
            'auto' => '',
            'subject_person' => 'featuring the person shown in this reference image',
            'subject_product' => 'featuring the product shown in this reference image',
            'style_transfer' => 'in the visual style of this reference image',
            'environment' => 'set in the environment and location shown in this reference image',
            'variation' => 'create a variation of this reference image while maintaining its core composition',
        );

        // Build per-image intent descriptions with specific instructions
        $intent_descriptions = array();
        foreach ($images as $i => $img) {
            $intent = sanitize_text_field($img['intent'] ?? 'auto');
            $fragment = $intent_fragments[$intent] ?? '';
            if ($fragment) {
                $intent_descriptions[] = sprintf('Image %d (%s): %s', $i + 1, $intent, $fragment);
            }
            else {
                $intent_descriptions[] = sprintf('Image %d: let the AI interpret naturally', $i + 1);
            }
        }

        $text .= "\n\nReference images are attached below. "
            . "Analyze their visual style, composition, colors, mood, and subject matter. "
            . "Use these visual cues to inform the generated prompts.\n"
            . implode("\n", $intent_descriptions);

        // Build multi-part content array
        $parts = array(
                array('type' => 'text', 'text' => $text),
        );

        foreach ($images as $img) {
            $url = esc_url_raw($img['url'] ?? '');
            if (empty($url)) {
                continue;
            }

            $parts[] = array(
                'type' => 'image_url',
                'image_url' => array(
                    'url' => $url,
                    'detail' => 'low',
                ),
            );
        }

        return $parts;
    }

    /**
     * Format brand colors as a human-readable string.
     *
     * Follows the same convention as buildColorContext() in the frontend:
     * first color = primary, second = secondary, rest listed as-is.
     *
     * @param array $colors Array of hex color strings (e.g. ['#3A8D9A', '#FFFFFF']).
     *
     * @return string Formatted color string, or empty if no colors.
     */
    private function format_brand_colors(array $colors): string
    {
        if (empty($colors)) {
            return '';
        }

        $parts = array();
        foreach ($colors as $i => $hex) {
            $hex = sanitize_text_field($hex);
            if (empty($hex)) {
                continue;
            }
            if ($i === 0) {
                $parts[] = "primary {$hex}";
            }
            elseif ($i === 1) {
                $parts[] = "secondary {$hex}";
            }
            else {
                $parts[] = $hex;
            }
        }

        return implode(', ', $parts);
    }

    // =========================================================================
    // BRIEF OPTIMIZATION
    // =========================================================================

    /**
     * Optimize a product brief for AI image generation.
     *
     * Rewrites the user's brief into a detailed, structured image generation
     * prompt that will produce better results across AI image models.
     *
     * @param int         $user_id       PCM user ID.
     * @param string      $brief         Original product brief.
     * @param array|null  $brand_context Optional brand context.
     * @param string|null $system_prompt Prompt override.
     * @param string      $model_id      Optional model ID override.
     *
     * @return string Optimized brief string.
     * @throws \RuntimeException If LLM call fails.
     */
    public function optimize_brief(
        int $user_id,
        string $brief,
        ?array $brand_context = null,
        ?string $system_prompt = null,
        string $model_id = ''
        ): string
    {
        if (!$system_prompt) {
            $system_prompt = 'You are an expert AI image prompt engineer. '
                . 'Rewrite the given product brief into a detailed, effective image generation prompt. '
                . 'Focus on visual elements: composition, lighting, style, mood, and subject details. '
                . 'Return only the optimized prompt text, nothing else.';
        }

        $user_text = "Optimize this product brief for AI image generation:\n\n{$brief}";

        if (!empty($brand_context['name'])) {
            $brand_name = sanitize_text_field($brand_context['name'] ?? '');
            $user_text .= "\n\nBrand: {$brand_name}";
        }

        $messages = array(
                array('role' => 'system', 'content' => $system_prompt),
                array('role' => 'user', 'content' => $user_text),
        );

        $result = PCM_LLM::invoke($messages, array(
            'user_id' => $user_id,
            'model' => $model_id ?: null,
        ));

        // LLM::invoke returns { content: string, ... }
        return trim($result['content'] ?? $brief);
    }

    // =========================================================================
    // IMAGE GENERATION / EDITING
    // =========================================================================

    /**
     * Generate an image via a provider.
     *
     * @param string $model_id  Model ID (e.g. 'dall-e-3', 'flux2-pro').
     * @param string $provider  Provider ID (e.g. 'openai', 'kieai').
     * @param string $api_key   API key for the provider.
     * @param array  $params    Full request params (prompt, width, height, style, etc.).
     *
     * @return string Generated image URL.
     * @throws \RuntimeException If generation fails or provider returns no URL.
     */
    public function generate_image(
        string $model_id,
        string $provider,
        string $api_key,
        array $params
        ): string
    {
        // Normalize reference images → provider-standard 'inputUrls' field.
        // Frontend sends 'referenceImageUrls', all providers expect 'inputUrls'.
        // Placed here (service layer) so both generate_single() and generate_batch()
        // benefit from a single mapping point.
        if (!empty($params['referenceImageUrls']) && empty($params['inputUrls'])) {
            $params['inputUrls'] = array_map('esc_url_raw', $params['referenceImageUrls']);
        }

        $instance = PCM_Provider_Registry::get($provider, $api_key);

        // Pass model_id as explicit argument (interface-enforced).
        // Params only carry generation options (prompt, format, etc.)
        $result = $instance->generate_image($model_id, $params);
        $url = $result['url'] ?? '';

        if (empty($url)) {
            throw new \RuntimeException(
                sprintf('Provider "%s" returned no image URL for model "%s".', $provider, $model_id)
                );
        }
        return $url;
    }

    /**
     * Edit an existing image via a provider's edit endpoint.
     *
     * @param string $model_id Model ID.
     * @param string $provider Provider ID.
     * @param string $api_key  API key.
     * @param array  $params   Edit params (imageUrl, prompt, mask, etc.).
     *
     * @return string Result image URL.
     * @throws \RuntimeException If editing fails or provider returns no URL.
     */
    public function edit_image(
        string $model_id,
        string $provider,
        string $api_key,
        array $params
        ): string
    {
        $instance = PCM_Provider_Registry::get($provider, $api_key);

        // Pass model_id as explicit argument (interface-enforced).
        // Params only carry edit options (prompt, mask, imageUrl, etc.)
        $result = $instance->edit_image($model_id, $params);
        $url = $result['url'] ?? '';

        if (empty($url)) {
            throw new \RuntimeException(
                sprintf('Provider "%s" returned no URL for image edit with model "%s".', $provider, $model_id)
                );
        }

        return $url;
    }

    // =========================================================================
    // PERSISTENCE
    // =========================================================================

    /**
     * Download an external image URL and add it to the WordPress Media Library.
     *
     * @param string $url     External image URL to download.
     * @param int    $user_id PCM user ID (for logging/attribution).
     * @param string $context Purpose label (e.g. 'image-generation', 'image-edit').
     *
     * @return string WordPress attachment URL (permanent WP URL).
     * @throws \RuntimeException If download or sideload fails.
     */
    public function store_to_media_library(
        string $url,
        int $user_id,
        string $context = 'image-generation'
        ): string
    {
        // PCM_Storage::download_external() returns array{id: int, url: string}
        $result = PCM_Storage::download_external($url, $context, "pcm-gen-" . wp_generate_uuid4() . ".png");
        return $result['url'] ?? '';
    }

    /**
     * Persist a generated image as a PCM asset in the pcm_assets table.
     *
     * @param int    $user_id  PCM user ID.
     * @param string $prompt   The prompt used for generation.
     * @param string $model_id Model ID that produced the image.
     * @param string $provider Provider that produced the image.
     * @param string $url      WordPress Media Library URL for the image.
     * @param array  $params   Full request params (for metadata storage).
     *
     * @return array Asset data returned to the client: { id, url, prompt, provider, modelId, type }.
     * @throws \RuntimeException If DB insert fails.
     */
    public function save_asset(
        int $user_id,
        string $prompt,
        string $model_id,
        string $provider,
        string $url,
        array $params = array()
        ): array
    {
        global $wpdb;

        $meta = array(
            'width' => $params['width'] ?? null,
            'height' => $params['height'] ?? null,
            'style' => $params['style'] ?? null,
            'quality' => $params['quality'] ?? null,
            'versionId' => $params['versionId'] ?? null,
            'assetIndex' => $params['assetIndex'] ?? null,
        );

        $table = PCM_Schema::table('assets');

        $inserted = $wpdb->insert($table, array(
            'userId' => $user_id,
            'projectId' => $params['projectId'] ?? null,
            'type' => 'image',
            'url' => $url,
            'prompt' => $prompt,
            'provider' => $provider,
            'modelId' => $model_id,
            'metadata' => wp_json_encode($meta),
            'createdAt' => current_time('mysql'),
        ));

        if (!$inserted) {
            throw new \RuntimeException('Failed to save asset to database.');
        }

        return array(
            'id' => $wpdb->insert_id,
            'url' => $url,
            'prompt' => $prompt,
            'provider' => $provider,
            'modelId' => $model_id,
            'type' => 'image',
        );
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================



    /**
     * Determine the dominant intent from an array of reference image intents.
     *
     * Returns the most common intent, defaulting to 'auto' if no clear winner.
     * 'auto' is treated as "let the AI decide" and does not inject extra instructions.
     *
     * @param string[] $intents Array of intent strings (variation, style, product, etc.)
     * @return string The dominant intent.
     */
    private function get_dominant_intent(array $intents): string
    {
        if (empty($intents)) {
            return 'auto';
        }

        // Count occurrences of each intent
        $counts = array_count_values($intents);

        // 'auto' doesn't count towards dominance — it means "let AI decide"
        unset($counts['auto']);

        if (empty($counts)) {
            return 'auto';
        }

        // Return the intent with the highest count
        arsort($counts);
        return array_key_first($counts);
    }

    // =========================================================================
    // PROMPT TEMPLATES (Copy-module pattern)
    // =========================================================================

    /**
     * Get all default prompt templates for the Image module.
     *
     * Each template uses {{placeholders}} that are resolved at runtime via
     * build_image_context() + resolve_prompt_placeholders().
     *
     * Mirrors PCM_Copy_Service::get_default_prompts() — same architectural
     * pattern so the Prompt Editor (Settings) can render and manage them.
     *
     * @return array Keyed by section name → template string.
     */
    public static function get_default_prompts(): array
    {
        return array(
            // ── Prompt Suggestions — System Prompt ────────────────
            'prompt_suggestions_system' => 'You are an expert creative director specializing in ad image generation prompts. '
            . 'Generate specific, photorealistic, production-ready image prompts for advertising.',

            // ── Prompt Suggestions — User Prompt ─────────────────
            'prompt_suggestions' => "Generate {{count}} creative image prompts for the following product/service:

Brief: {{brief}}
{{brandName}}
{{brandSummary}}
{{niche}}
{{location}}
{{language}}
{{phone}}
{{url}}
{{seasonEvent}}
{{campaignTheme}}
{{brandColors}}

Return exactly {{count}} distinct, detailed prompts optimized for AI image generation.",

            // ── Context Suggestions — System Prompt ──────────────
            'context_suggestions_system' => 'You are an expert creative director specializing in brand-aligned ad image generation. '
            . 'Generate prompts that capture brand identity and campaign goals.',

            // ── Context Suggestions — User Prompt ────────────────
            'context_suggestions' => "Generate {{count}} creative image prompts based on this brand/campaign context:

{{brandName}}
{{brandSummary}}
{{niche}}
{{location}}
{{language}}
{{phone}}
{{url}}
{{seasonEvent}}
{{campaignTheme}}
{{brandColors}}

Return exactly {{count}} distinct, production-ready image generation prompts.",

            // ── Angles (Scenes) — System Prompt ──────────────
            // {{referenceImageIntent}} resolves to the dominant intent tag
            // (variation, style, product, person, environment) or "" if no reference image.
            'concept_suggestions' => 'You are a creative director specialising in advertising and commercial photography. '
            . 'Given a product brief, generate distinct creative concept angles for image generation. '
            . 'Each concept must have a unique visual approach (scene, mood, style, composition). '
            . "Return only structured JSON matching the provided schema.\n\n"
            . "Reference Image Intent: {{referenceImageIntent}}\n\n"
            . "If a reference image is provided (intent above is not empty):\n"
            . "- Read the user's brief carefully and follow their stated intent.\n"
            . "- If the user asks for variations — preserve the reference image's composition, subject, style, and colors. Do NOT invent entirely new scenes.\n"
            . "- If the user asks for something creative — use the reference as inspiration but create fresh concepts.\n"
            . "- If the user does not specify — default to creating variations that stay close to the reference image.\n\n"
            . "If no reference image (intent above is empty):\n"
            . '- Generate distinct creative concept angles freely with unique visual approaches.',

            // ── Angles (Scenes) — User Prompt ──────────────
            // Sent as role:user alongside the system prompt above.
            // Previously hardcoded in build_concept_user_content().
            'concept_suggestions_user' => "Generate {{count}} creative image concepts for: {{brief}}
{{style}}
{{brandName}}
{{brandSummary}}
{{niche}}
{{location}}
{{language}}
{{phone}}
{{url}}
{{brandColors}}

            // ── Brief Optimization — System Prompt ───────────────
            'brief_optimization' => 'You are an expert AI image prompt engineer. '
            . 'Rewrite the given product brief into a detailed, effective image generation prompt. '
            . 'Focus on visual elements: composition, lighting, style, mood, and subject details. '
            . 'Return only the optimized prompt text, nothing else.',
        );
    }

    /**
     * Build placeholder variables from the Image module context.
     *
     * Mirrors Copy's build_generation_context() — collects all relevant context
     * values from the frontend request into a flat key→value map for
     * {{placeholder}} resolution.
     *
     * @param array $context Context from the frontend (brandName, brandSummary, etc.).
     * @param int   $count   Number of suggestions to generate.
     *
     * @return array Associative array of placeholder → resolved value.
     */
    private function build_image_context(array $context, int $count = 4): array
    {
        $vars = array(
            'count' => (string)$count,
        );

        // Brand/business context
        if (!empty($context['brandName'])) {
            $vars['brandName'] = 'Brand: ' . sanitize_text_field($context['brandName']);
        }
        if (!empty($context['brandSummary'])) {
            $vars['brandSummary'] = 'Brand Summary: ' . sanitize_textarea_field($context['brandSummary']);
        }
        if (!empty($context['url']) || !empty($context['website'])) {
            $url = $context['website'] ?? $context['url'];
            $vars['url'] = 'Website: ' . esc_url_raw($url);
        }
        if (!empty($context['niche'])) {
            $vars['niche'] = 'Niche/Industry: ' . sanitize_text_field($context['niche']);
        }
        if (!empty($context['location'])) {
            $vars['location'] = 'Location: ' . sanitize_text_field($context['location']);
        }
        if (!empty($context['phone'])) {
            $vars['phone'] = 'Phone: ' . sanitize_text_field($context['phone']);
        }
        if (!empty($context['language'])) {
            $vars['language'] = 'Language: ' . sanitize_text_field($context['language']);
        }

        // Campaign context
        if (!empty($context['seasonEvent'])) {
            $vars['seasonEvent'] = 'Season/Event: ' . sanitize_text_field($context['seasonEvent']);
        }
        if (!empty($context['campaignTheme'])) {
            $vars['campaignTheme'] = 'Campaign Theme: ' . sanitize_text_field($context['campaignTheme']);
        }

        // Brand colors — formatted as "primary #3A8D9A, secondary #FFFFFF, ..."
        $brand_colors_text = $this->format_brand_colors($context['brandColors'] ?? array());
        if ($brand_colors_text) {
            $vars['brandColors'] = 'Brand Colors: ' . $brand_colors_text;
        }

        // Reference image intent — resolves to the dominant intent tag or "" if no images.
        // Used by the Angles (Scenes) prompt: {{referenceImageIntent}}
        $ref_images = $context['referenceImages'] ?? array();
        if (!empty($ref_images)) {
            $intents = array_column($ref_images, 'intent');
            $vars['referenceImageIntent'] = $this->get_dominant_intent($intents);
        }

        return $vars;
    }

    /**
     * Replace {{placeholders}} in a prompt template with resolved values.
     *
     * Ported from PCM_Copy_Service::resolve_prompt_placeholders().
     * Unresolved placeholders are replaced with empty strings to avoid
     * leaking template syntax into LLM prompts.
     *
     * @param string $template Template string with {{key}} placeholders.
     * @param array  $vars     Key-value map of placeholder replacements.
     *
     * @return string Resolved prompt string (trimmed, blank lines collapsed).
     */
    private function resolve_prompt_placeholders(string $template, array $vars): string
    {
        $resolved = preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($vars) {
            return $vars[$matches[1]] ?? '';
        }, $template);

        // Collapse multiple consecutive blank lines left by empty placeholders
        return trim(preg_replace('/\n{3,}/', "\n\n", $resolved));
    }
}
