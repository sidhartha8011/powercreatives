<?php
/**
 * Copy Service — Business Logic Layer
 *
 * Contains all the business logic for copy generation, previously
 * embedded in the controller. This separation enables:
 * - Unit testing without WordPress/REST context
 * - Reusability (service can be called from CLI, cron, etc.)
 * - Thin controller that only handles HTTP concerns
 *
 * @package PowerCreatives
 * @since   1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Copy_Service
{

    // ========================================
    // Constants
    // ========================================

    /**
     * Emoji regex for sanitizing labels.
     *
     * @var string
     */
    const EMOJI_PATTERN = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';

    // ========================================
    // Audience & Angle Resolution
    // ========================================

    /**
     * Resolve audiences — manual list or auto-generate via LLM.
     *
     * In auto mode, optionally runs a research step first (Gemini grounding)
     * to gather real market data, then generates audiences using the enriched context.
     *
     * @param array  $audience_config   Config: { mode: "manual"|"auto", items?, count? }
     * @param array  $form_values       Form field values.
     * @param string $model_id          Model to use for audience generation.
     * @param int    $user_id           PCM user ID (for prompt override lookup).
     * @param bool   $use_research      Whether to run the research step first.
     * @param string $research_model_id Model to use for research step (empty = same as $model_id).
     *
     * @return array { audiences: array, researched: bool }
     * @throws \RuntimeException If manual mode has no items or LLM fails.
     */
    public function resolve_audiences(
        array $audience_config,
        array $form_values,
        string $model_id,
        int $user_id = 0,
        bool $use_research = false,
        string $research_model_id = '',
        string $module = 'copy'
        ): array
    {
        $mode = $audience_config['mode'] ?? 'manual';

        if ($mode === 'manual') {
            $items = $audience_config['items'] ?? array();
            if (empty($items)) {
                throw new \RuntimeException('No audiences provided in manual mode.');
            }
            return array('audiences' => $items, 'researched' => false);
        }

        // Auto mode — generate via editable prompt
        $count = min(10, max(1, (int)($audience_config['count'] ?? 3)));

        // Build context variables for placeholder resolution
        $prompt_vars = $this->build_generation_context($form_values, $count);

        // ── Validate required business context ──────────────────────
        // Fail-fast with actionable field names so the user knows exactly
        // which sidebar fields to fill before generating audiences.
        $missing = array();
        if (empty(trim($prompt_vars['brandName'])) || $prompt_vars['brandName'] === 'the business') {
            $missing[] = 'Business Name';
        }
        if (!empty($missing)) {
            throw new \RuntimeException(
                'Cannot generate audiences — the following fields are required: '
                . implode(', ', $missing) . '. '
                . 'Please fill them in the sidebar before generating.'
                );
        }

        // ── Research Step (optional) ──────────────────────────────
        // Run a separate LLM call with grounding to gather market data.
        // The research result enriches the audience generation prompt.
        //
        // Returns a detailed researchStatus so the frontend can show
        // exactly why research was skipped (not just a boolean).
        $research_context = '';
        $researched = false;
        // Detailed status: 'not_requested' | 'success' | 'skipped_not_google' | 'skipped_no_key' | 'error'
        $research_status = 'not_requested';
        $research_error = '';

        if ($use_research) {
            $r_model = !empty($research_model_id) ? $research_model_id : $model_id;

            // Check if the research model's provider supports grounding
            $r_provider = PCM_LLM::detect_provider_for($r_model);

            if ($r_provider !== 'google') {
                // Grounding via google_search requires Google provider — report clearly
                $research_status = 'skipped_not_google';
                $research_error = "Research requires a Google Gemini model. Current model provider: {$r_provider}.";
            }
            elseif (!PCM_LLM::has_api_key($r_provider, $user_id)) {
                // Google provider but no API key configured
                $research_status = 'skipped_no_key';
                $research_error = 'No Google API key configured for this user.';
            }
            else {
                // All prerequisites met — run grounded research
                try {
                    $research_prompt_template = $this->get_system_prompt('audience_research', $user_id, $module);
                    $research_prompt = $this->resolve_prompt_placeholders($research_prompt_template, $prompt_vars);

                    $research_messages = array(
                            array('role' => 'user', 'content' => $research_prompt),
                    );

                    // Use native Gemini endpoint for grounding (OpenAI compat doesn't support google_search)
                    $research_result = PCM_LLM::invoke_with_grounding($research_messages, array(
                        'model' => $r_model,
                        'max_tokens' => (int)PCM_Settings::get('token_budget_audience', 8192),
                    ));

                    $research_context = $research_result['content'] ?? '';
                    $researched = !empty($research_context);
                    $research_status = $researched ? 'success' : 'error';
                    if (!$researched) {
                        $research_error = 'Research call succeeded but returned empty content.';
                    }
                }
                catch (\Exception $e) {
                    // Research failed — proceed without it (non-blocking)
                    $research_status = 'error';
                    $research_error = $e->getMessage();
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[PCM_Copy] Research step failed: ' . $e->getMessage());
                    }
                }
            }
        }

        // Inject research context into prompt vars if available.
        // The raw research text is passed as-is — any wrapper/label text
        // lives in the editable audience_generation template where the user controls it.
        $prompt_vars['researchContext'] = $research_context;

        // Get the editable prompt template (DB override or built-in default)
        $prompt_template = $this->get_system_prompt('audience_generation', $user_id, $module);
        $resolved_prompt = $this->resolve_prompt_placeholders($prompt_template, $prompt_vars);

        $messages = array(
                array('role' => 'user', 'content' => $resolved_prompt),
        );

        // Request structured JSON output with strict schema.
        // Using json_schema + strict: true guarantees the LLM returns
        // the exact structure we need — no wrapper objects, no surprises.
        $result = PCM_LLM::invoke($messages, array(
            'model' => $model_id,
            'max_tokens' => (int)PCM_Settings::get('token_budget_audience', 8192),
            'response_format' => self::audience_response_schema(),
        ));

        // Extract array from the schema-enforced response
        $raw_content = $result['content'] ?? '';
        $parsed = $this->extract_json_array($raw_content);

        if (empty($parsed)) {
            // Log the raw response for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[PCM_Copy] Audience generation: extract_json_array returned empty. Raw LLM content (first 500 chars): ' . substr($raw_content, 0, 500));
            }
            throw new \RuntimeException(
                'AI generated a response but it could not be parsed. '
                . 'Please try again — this is usually a temporary issue.'
                );
        }

        $audiences = array_map(function ($a) {
            return array(
            'id' => $this->sanitize_label($a['id'] ?? $a['name'] ?? ''),
            'name' => $this->sanitize_label($a['name'] ?? ''),
            );
        }, $parsed);

        // Strictly enforce user settings count to prevent LLM non-determinism from returning too many items
        $audiences = array_slice($audiences, 0, $count);

        return array(
            'audiences' => $audiences,
            'researched' => $researched,
            'researchStatus' => $research_status,
            'researchError' => $research_error,
        );
    }

    /**
     * Resolve angles — manual list or auto-generate via LLM.
     *
     * In auto mode, reads the angle_generation prompt from the prompt_overrides
     * DB (editable in Settings → Prompt Editor) and resolves {{placeholders}}
     * before sending to the LLM.
     *
     * @param array  $angle_config  Config: { mode: "manual"|"auto", items?, count? }
     * @param array  $form_values   Form field values.
     * @param int    $audience_count Number of audiences (unused, kept for API compat).
     * @param string $model_id      Model to use for auto generation.
     * @param int    $user_id       PCM user ID (for prompt override lookup).
     *
     * @return array Array of { id, name } angle objects.
     */
    public function resolve_angles(array $angle_config, array $form_values, int $audience_count, string $model_id, int $user_id = 0, string $module = 'copy'): array
    {
        $mode = $angle_config['mode'] ?? 'manual';

        if ($mode === 'manual') {
            $items = $angle_config['items'] ?? array();
            if (empty($items)) {
                // Default single angle
                return array(array('id' => 'default', 'name' => 'Value Proposition'));
            }
            return $items;
        }

        // Auto mode — generate via editable prompt
        $count = min(10, max(1, (int)($angle_config['count'] ?? 3)));

        // Build context variables for placeholder resolution
        $prompt_vars = $this->build_generation_context($form_values, $count);

        // Get the editable prompt template (DB override or built-in default)
        $prompt_template = $this->get_system_prompt('angle_generation', $user_id, $module);
        $resolved_prompt = $this->resolve_prompt_placeholders($prompt_template, $prompt_vars);

        $messages = array(
                array('role' => 'user', 'content' => $resolved_prompt),
        );

        // Request structured JSON output with strict schema.
        // Same id/name schema as audiences — prevents format drift.
        $result = PCM_LLM::invoke($messages, array(
            'model' => $model_id,
            'max_tokens' => (int)PCM_Settings::get('token_budget_angle', 8192),
            'response_format' => self::audience_response_schema(),
        ));

        // Extract array from schema-enforced response
        $parsed = $this->extract_json_array($result['content'] ?? '');

        if (empty($parsed)) {
            return array(array('id' => 'default', 'name' => 'Value Proposition'));
        }

        $angles = array_map(function ($a) {
            return array(
                'id' => $this->sanitize_label($a['id'] ?? $a['name'] ?? ''),
                'name' => $this->sanitize_label($a['name'] ?? ''),
            );
        }, $parsed);

        // Strictly enforce user settings count to prevent LLM non-determinism from returning too many items
        return array_slice($angles, 0, $count);
    }

    /**
     * Resolve angles FOR specific audiences — audience-aware generation.
     *
     * Generates N angles PER audience in a single batch LLM call.
     * Each returned angle is tagged with its audienceId so that
     * build_task_matrix() can match them correctly (not cartesian).
     *
     * Falls back to generic resolve_angles() if audiences are empty.
     *
     * @param array  $angle_config Config: { mode: "manual"|"auto", items?, count? }
     * @param array  $audiences    Resolved audience objects [{ id, name }].
     * @param array  $form_values  Form field values.
     * @param string $model_id     Model to use for auto generation.
     * @param int    $user_id      PCM user ID.
     *
     * @return array Array of { id, name, audienceId } angle objects.
     */
    public function resolve_angles_for_audiences(
        array $angle_config,
        array $audiences,
        array $form_values,
        string $model_id,
        int $user_id = 0,
        string $module = 'copy'
        ): array
    {
        $mode = $angle_config['mode'] ?? 'manual';

        // Manual mode: preserve audienceId from frontend if present,
        // default to wildcard '*' (global) for backwards compatibility.
        if ($mode === 'manual') {
            $items = $angle_config['items'] ?? array();
            if (empty($items)) {
                return array(array('id' => 'default', 'name' => 'Value Proposition', 'audienceId' => '*'));
            }
            // Preserve audience pairing if set, otherwise mark as global
            return array_map(function ($a) {
                if (empty($a['audienceId'])) {
                    $a['audienceId'] = '*';
                }
                return $a;
            }, $items);
        }

        // No audiences? Fall back to generic angle generation
        if (empty($audiences)) {
            $generic = $this->resolve_angles($angle_config, $form_values, 0, $model_id, $user_id, $module);
            return array_map(function ($a) {
                $a['audienceId'] = '*';
                return $a;
            }, $generic);
        }

        // Auto mode — generate audience-specific angles in one batch call
        $count = min(10, max(1, (int)($angle_config['count'] ?? 3)));

        // Build audience list string for the prompt
        $audience_list = '';
        foreach ($audiences as $i => $aud) {
            $n = $i + 1;
            $audience_list .= "{$n}. {$aud['name']} (id: {$aud['id']})\n";
        }

        // Build context variables for placeholder resolution
        $prompt_vars = $this->build_generation_context($form_values, $count);
        $prompt_vars['audiences'] = $audience_list;
        $prompt_vars['anglesPerAudience'] = (string)$count;
        $prompt_vars['totalAngles'] = (string)($count * count($audiences));

        // Get the editable prompt template (DB override or built-in default)
        $prompt_template = $this->get_system_prompt('angle_generation_with_audiences', $user_id, $module);
        $resolved_prompt = $this->resolve_prompt_placeholders($prompt_template, $prompt_vars);

        $messages = array(
                array('role' => 'user', 'content' => $resolved_prompt),
        );

        // Strict schema with audienceId — each angle is tied to its audience.
        $result = PCM_LLM::invoke($messages, array(
            'model' => $model_id,
            'max_tokens' => (int)PCM_Settings::get('token_budget_angle', 8192),
            'response_format' => self::audience_angle_response_schema(),
        ));

        $parsed = $this->extract_json_array($result['content'] ?? '');

        if (empty($parsed)) {
            // Fallback: generate generic angles with wildcard audienceId
            $generic = $this->resolve_angles($angle_config, $form_values, 0, $model_id, $user_id, $module);
            return array_map(function ($a) {
                $a['audienceId'] = '*';
                return $a;
            }, $generic);
        }

        // Sanitize and ensure audienceId is present on every item
        $audience_ids = array_column($audiences, 'id');
        $sanitized_angles = array_map(function ($a) use ($audience_ids) {
            $aud_id = $this->sanitize_label($a['audienceId'] ?? '');
            // Validate the audienceId — if unknown, mark as wildcard
            if (!in_array($aud_id, $audience_ids, true)) {
                $aud_id = '*';
            }
            return array(
                'id' => $this->sanitize_label($a['id'] ?? $a['name'] ?? ''),
                'name' => $this->sanitize_label($a['name'] ?? ''),
                'audienceId' => $aud_id,
            );
        }, $parsed);

        // Group by audienceId to strictly enforce the user-requested count per audience
        $grouped_angles = array();
        foreach ($sanitized_angles as $angle) {
            $aud_id = $angle['audienceId'];
            $grouped_angles[$aud_id][] = $angle;
        }

        $enforced_angles = array();
        foreach ($grouped_angles as $aud_id => $angle_list) {
            // Strictly limit angles per audience to the user-requested count
            $enforced_angles = array_merge($enforced_angles, array_slice($angle_list, 0, $count));
        }

        return $enforced_angles;
    }

    /**
     * Build shared context variables for angle/audience generation prompts.
     *
     * Provides rich business context (brand, product, description, reference ads,
     * tonality, campaign/season) so the LLM can generate more relevant angles
     * and audiences. These variables are resolved as {{placeholders}} in the
     * editable prompt templates.
     *
     * @param array $form_values Form field values from the sidebar.
     * @param int   $count       Number of items to generate.
     *
     * @return array Associative array of placeholder → value.
     */
    private function build_generation_context(array $form_values, int $count): array
    {
        $brand_name = $form_values['brandName'] ?? $form_values['business_name'] ?? 'the business';
        // Frontend copyConfig uses 'offer_name', legacy code uses 'product'/'service'
        $product = $form_values['product'] ?? $form_values['offer_name'] ?? $form_values['service'] ?? '';
        // Frontend copyConfig uses 'business_summary', legacy code uses 'description'
        $description = $form_values['description'] ?? $form_values['business_summary'] ?? '';
        // Niche/industry — frontend field 'niche'
        $niche = $form_values['niche'] ?? '';

        // Reference ads — gives the LLM tone/style context.
        // Frontend stores these as indexed keys (reference_ad_0, reference_ad_1, …)
        // via the dynamic_list inputType. Collect them into a single string.
        $reference_ads = $this->collect_dynamic_list($form_values, 'reference_ad');

        // Tonality — so angles/audiences align with the chosen voice
        $tone_key = $form_values['tone_override'] ?? $form_values['tone'] ?? $form_values['toneOfVoice'] ?? '';

        // Campaign/season context
        $campaign_context = $this->build_season_context($form_values);

        return array(
            'count' => (string)$count,
            'brandName' => $brand_name,
            'product' => $product,
            'description' => $description,
            'niche' => $niche,
            'referenceAds' => $reference_ads,
            'tone' => $tone_key ?: 'auto',
            'campaignContext' => $campaign_context,
            'creativeBrief' => trim($form_values['creativeBrief'] ?? ''),
        );
    }

    /**
     * Extract a JSON array from an LLM response string.
     *
     * With strict json_schema, the LLM returns a well-formed
     * {"items": [...]} wrapper. This method:
     *   1. Strips markdown fences (rare but possible)
     *   2. Decodes JSON
     *   3. Unwraps wrapper objects
     *
     * Kept simple — strict schema eliminates most edge cases.
     *
     * @param string $raw Raw LLM response content.
     * @return array Numerically indexed array of parsed items (may be empty).
     */
    private function extract_json_array(string $raw): array
    {
        $cleaned = trim($raw);
        if (empty($cleaned)) {
            return array();
        }

        // Strip markdown code fences if LLM wraps output (safety net)
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        // Remove invisible control characters (except \n, \r, \t)
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $cleaned);

        $decoded = json_decode($cleaned, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[PCM_Copy] json_decode failed: ' . json_last_error_msg()
                    . ' | raw_len=' . strlen($cleaned)
                    . ' | first_100=' . substr($cleaned, 0, 100));
            }
            return array();
        }

        // Case 1: Already a numerically indexed array → return as-is
        if (is_array($decoded) && array_is_list($decoded)) {
            return $decoded;
        }

        // Case 2: Wrapper object like {"items": [...]} (strict schema)
        // or legacy {"angles": [...]} / {"audiences": [...]}
        if (is_array($decoded)) {
            foreach ($decoded as $value) {
                if (is_array($value) && !empty($value) && array_is_list($value)) {
                    return $value;
                }
            }

            // Case 3: Single object like {"id":"...", "name":"..."} → wrap in array
            if (isset($decoded['id']) || isset($decoded['name'])) {
                return array($decoded);
            }
        }

        return array();
    }

    // ========================================
    // JSON Schema definitions for LLM responses
    // ========================================

    /**
     * Strict JSON schema for audience and angle generation.
     *
     * Forces the LLM to return exactly:
     *   {"items": [{"id": "...", "name": "..."}, ...]}
     *
     * Eliminates formatting failures caused by non-deterministic
     * JSON shapes (wrapper key names, bare arrays, single objects).
     *
     * @return array Response format for PCM_LLM::invoke().
     */
    private static function audience_response_schema(): array
    {
        return array(
            'type' => 'json_schema',
            'json_schema' => array(
                'name' => 'audience_list',
                'strict' => true,
                'schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'items' => array(
                            'type' => 'array',
                            'items' => array(
                                'type' => 'object',
                                'properties' => array(
                                    'id' => array('type' => 'string', 'description' => 'Kebab-case slug identifier.'),
                                    'name' => array('type' => 'string', 'description' => 'Human-readable name, 2-5 words.'),
                                ),
                                'required' => array('id', 'name'),
                                'additionalProperties' => false,
                            ),
                        ),
                    ),
                    'required' => array('items'),
                    'additionalProperties' => false,
                ),
            ),
        );
    }

    /**
     * Strict JSON schema for audience-aware angle generation.
     *
     * Each angle is tagged with its audienceId:
     *   {"items": [{"id": "...", "name": "...", "audienceId": "..."}, ...]}
     *
     * @return array Response format for PCM_LLM::invoke().
     */
    private static function audience_angle_response_schema(): array
    {
        return array(
            'type' => 'json_schema',
            'json_schema' => array(
                'name' => 'audience_angle_list',
                'strict' => true,
                'schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'items' => array(
                            'type' => 'array',
                            'items' => array(
                                'type' => 'object',
                                'properties' => array(
                                    'id' => array('type' => 'string', 'description' => 'Kebab-case slug identifier.'),
                                    'name' => array('type' => 'string', 'description' => 'Human-readable angle name, 2-5 words.'),
                                    'audienceId' => array('type' => 'string', 'description' => 'The audience id this angle belongs to.'),
                                ),
                                'required' => array('id', 'name', 'audienceId'),
                                'additionalProperties' => false,
                            ),
                        ),
                    ),
                    'required' => array('items'),
                    'additionalProperties' => false,
                ),
            ),
        );
    }

    // ========================================
    // Copy Generation (LLM)
    // ========================================

    /**
     * Generate a single copy piece via LLM.
     *
     * Uses type-aware prompts and JSON schema descriptions so the LLM
     * generates appropriate content per copy type (ads vs organic).
     *
     * @param string $copy_type   Type: 'social_ads' or 'social_organic'.
     * @param array  $audience    Audience object { id, name }.
     * @param array  $angle       Angle object { id, name }.
     * @param array  $form_values Form field values.
     * @param string $model_id    Model to use.
     * @param int    $user_id     PCM user ID.
     *
     * @return array Copy output { headline, body, cta, hashtags, description, copyType, audienceId, audienceName, angleName }.
     * @throws \RuntimeException If LLM returns invalid JSON.
     */
    public function generate_single_copy(
        string $copy_type,
        array $audience,
        array $angle,
        array $form_values,
        string $model_id,
        int $user_id,
        string $module = 'copy'
        ): array
    {
        // Build the fully resolved user prompt — business context + style + task.
        $resolved_prompt = $this->build_copy_system_prompt($form_values, $copy_type, $user_id, $audience, $angle, $module);

        // Fetch the system prompt for this copy type.
        // Ads use a dedicated system section; organic falls back to the combined prompt.
        // The system message provides persona + global rules; the user message provides
        // the specific context and task for this generation.
        $system_section = ($copy_type === 'social_ads') ? 'system_prompt_ads_system' : null;
        $messages = array();

        if ($system_section) {
            $system_content = $this->get_system_prompt($system_section, $user_id, $module);
            $messages[] = array('role' => 'system', 'content' => $system_content);
        }

        $messages[] = array('role' => 'user', 'content' => $resolved_prompt);

        // Type-aware JSON schema — field descriptions guide the LLM to
        // produce empty hashtags for ads and empty headline/cta/description for organic.
        // Mirrors the SOURCE COPY_RESULT_SCHEMA from copyPrompts.ts.
        $response_format = array(
            'type' => 'json_schema',
            'json_schema' => array(
                'name' => 'copy_result',
                'strict' => true,
                'schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'headline' => array(
                            'type' => 'string',
                            'description' => 'The attention-grabbing headline. Leave empty string for organic posts.',
                        ),
                        'body' => array(
                            'type' => 'string',
                            'description' => 'The main body copy. Use \n for line breaks. Include formatting like numbered lists where appropriate.',
                        ),
                        'cta' => array(
                            'type' => 'string',
                            'description' => 'Call-to-action text. Leave empty string for organic posts.',
                        ),
                        'hashtags' => array(
                            'type' => 'array',
                            'items' => array('type' => 'string'),
                            'description' => 'Relevant hashtags (3-6). Include # prefix. Empty array for ads.',
                        ),
                        'description' => array(
                            'type' => 'string',
                            'description' => 'Short link description displayed below the headline (1-2 sentences, like Facebook ad link descriptions). For organic posts, set to empty string.',
                        ),
                    ),
                    'required' => array('headline', 'body', 'cta', 'hashtags', 'description'),
                ),
            ),
        );

        // Retry logic: Gemini 2.5 Flash occasionally returns invalid JSON
        // despite strict json_schema. A retry typically succeeds on attempt 2.
        $max_attempts = 3;
        $last_error = null;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $result = PCM_LLM::invoke($messages, array(
                'model' => $model_id,
                'user_id' => $user_id,
                'max_tokens' => (int)PCM_Settings::get('token_budget_copy', 16384),
                'response_format' => $response_format,
            ));

            $content = $result['content'] ?? '';
            $clean_json = PCM_LLM::extract_json($content);
            $output = json_decode($clean_json, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($output)) {
                // Normalise body newlines: LLMs sometimes return literal \n (double-escaped)
                // instead of real newline characters. Convert any remaining literal \n
                // sequences to actual newlines so the frontend renders paragraphs correctly.
                if (isset($output['body']) && is_string($output['body'])) {
                    $output['body'] = str_replace('\\n', "\n", $output['body']);
                }

                // Add metadata and return
                $output['copyType'] = $copy_type;
                $output['audienceId'] = $audience['id'];
                $output['audienceName'] = $audience['name'];
                $output['angleName'] = $angle['name'];

                return $output;
            }

            // Invalid JSON — log and retry
            $last_error = json_last_error_msg();
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[PCM_Copy] Invalid JSON on attempt {$attempt}/{$max_attempts}: {$last_error}");
            }
        }

        // All attempts failed
        throw new \RuntimeException("LLM returned invalid JSON after {$max_attempts} attempts: {$last_error}");
    }

    // ========================================
    // Job Management (DB)
    // ========================================

    /**
     * Create a copy job record in the database.
     *
     * @param int    $user_id           PCM user ID.
     * @param array  $types_to_generate Copy types to generate.
     * @param string $model_id          Model ID.
     * @param array  $params            Request params (brandId, templateId, formValues).
     * @param int    $task_count        Total number of generation tasks.
     *
     * @return int Job ID.
     * @throws \RuntimeException If insert fails.
     */
    public function create_job(int $user_id, array $types_to_generate, string $model_id, array $params, int $task_count): int
    {
        global $wpdb;

        $job_table = PCM_Schema::table('copy_jobs');
        $now = current_time('mysql');

        $wpdb->insert($job_table, array(
            'userId' => $user_id,
            'copyTypes' => wp_json_encode($types_to_generate),
            'modelId' => $model_id,
            'brandId' => $params['brandId'] ?? null,
            'templateId' => $params['templateId'] ?? null,
            'formSnapshot' => wp_json_encode($params['formValues'] ?? $params),
            'status' => 'running',
            'totalCount' => $task_count,
            'completedCount' => 0,
            'failedCount' => 0,
            'createdAt' => $now,
            'updatedAt' => $now,
        ));

        $job_id = $wpdb->insert_id;

        if (!$job_id) {
            throw new \RuntimeException('Failed to create copy job.');
        }

        return $job_id;
    }

    /**
     * Store a single copy result in the database.
     *
     * @param int    $job_id   Job ID.
     * @param int    $user_id  PCM user ID.
     * @param array  $task     Task definition { copyType, audience }.
     * @param array  $output   LLM output { headline, body, cta, hashtags, description }.
     *
     * @return int Result row ID.
     */
    public function store_result(int $job_id, int $user_id, array $task, array $output): int
    {
        global $wpdb;

        $result_table = PCM_Schema::table('copy_results');

        $wpdb->insert($result_table, array(
            'jobId' => $job_id,
            'userId' => $user_id,
            'copyType' => $task['copyType'],
            'audienceId' => $task['audience']['id'],
            'audienceName' => $task['audience']['name'],
            'headline' => $output['headline'] ?? '',
            'body' => $output['body'] ?? '',
            'cta' => $output['cta'] ?? '',
            'hashtags' => wp_json_encode($output['hashtags'] ?? array()),
            'description' => $output['description'] ?? '',
            'rawResponse' => wp_json_encode($output),
            'createdAt' => current_time('mysql'),
        ));

        return $wpdb->insert_id;
    }

    /**
     * Finalize a job — update status and counts.
     *
     * @param int $job_id    Job ID.
     * @param int $completed Number of completed tasks.
     * @param int $failed    Number of failed tasks.
     * @param int $total     Total tasks.
     *
     * @return void
     */
    public function finalize_job(int $job_id, int $completed, int $failed, int $total): void
    {
        global $wpdb;

        $job_table = PCM_Schema::table('copy_jobs');

        $wpdb->update($job_table, array(
            'status' => $failed === $total ? 'failed' : 'completed',
            'completedCount' => $completed,
            'failedCount' => $failed,
            'updatedAt' => current_time('mysql'),
        ), array('id' => $job_id));
    }

    /**
     * Get a copy job with ownership check.
     *
     * @param int $job_id  Job ID.
     * @param int $user_id PCM user ID.
     *
     * @return object|null Job row or null.
     */
    public function get_job(int $job_id, int $user_id): ?object
    {
        global $wpdb;

        $job_table = PCM_Schema::table('copy_jobs');

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $job_table WHERE id = %d AND userId = %d",
            $job_id,
            $user_id
        ));
    }

    /**
     * Get all results for a job, formatted for API response.
     *
     * @param int $job_id Job ID.
     *
     * @return array Formatted result objects.
     */
    public function get_results_for_job(int $job_id): array
    {
        global $wpdb;

        $result_table = PCM_Schema::table('copy_results');

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $result_table WHERE jobId = %d ORDER BY id ASC",
            $job_id
        ));

        return array_map(function ($row) {
            $raw = !empty($row->rawResponse) ? json_decode($row->rawResponse, true) : array();
            $angle_name = $raw['angleName'] ?? '';
            $model_used = $raw['modelUsed'] ?? '';
            return array(
                'id' => (int)$row->id,
                'projectId' => isset($row->projectId) && $row->projectId ? (int)$row->projectId : null,
                'copyType' => $row->copyType,
                'audienceId' => $row->audienceId,
                'audienceName' => $row->audienceName,
                'angleName' => $angle_name,
                'headline' => $row->headline,
                'body' => $row->body,
                'cta' => $row->cta,
                'hashtags' => json_decode($row->hashtags ?? '[]', true),
                'description' => $row->description,
                'modelUsed' => $model_used ?: 'Gemini Flash',
                'createdAt' => $row->createdAt,
            );
        }, $results ?: array());
    }

    /**
     * Associate selected copy results with a project.
     *
     * @param array $result_ids Array of copy result IDs.
     * @param int   $project_id Project ID.
     * @param int   $user_id    PCM user ID.
     *
     * @return int Number of affected rows.
     */
    public function save_to_project(array $result_ids, int $project_id, int $user_id): int
    {
        global $wpdb;

        if (empty($result_ids)) {
            return 0;
        }

        $result_table = PCM_Schema::table('copy_results');
        $project_table = PCM_Schema::table('projects');

        // Verify project ownership
        $project_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $project_table WHERE id = %d AND userId = %d",
            $project_id,
            $user_id
        ));

        if (!$project_exists) {
            throw new \InvalidArgumentException('Project not found or not owned by user.');
        }

        // Clean and prepare IDs
        $clean_ids = array_map('intval', $result_ids);
        $placeholders = implode(',', array_fill(0, count($clean_ids), '%d'));

        // Perform bulk update of projectId
        $sql = "UPDATE $result_table SET projectId = %d WHERE id IN ($placeholders) AND userId = %d";
        $params = array_merge(array($project_id), $clean_ids, array($user_id));

        $affected = $wpdb->query($wpdb->prepare($sql, ...$params));

        return $affected !== false ? $affected : 0;
    }

    /**
     * Get all copy results for a specific project.
     *
     * @param int $project_id Project ID.
     * @param int $user_id    PCM user ID.
     *
     * @return array List of formatted copy results.
     */
    public function get_results_for_project(int $project_id, int $user_id): array
    {
        global $wpdb;

        $result_table = PCM_Schema::table('copy_results');

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $result_table WHERE projectId = %d AND userId = %d ORDER BY id DESC",
            $project_id,
            $user_id
        ));

        return array_map(function ($row) {
            $raw = !empty($row->rawResponse) ? json_decode($row->rawResponse, true) : array();
            $angle_name = $raw['angleName'] ?? '';
            $model_used = $raw['modelUsed'] ?? '';
            return array(
                'id' => (int)$row->id,
                'projectId' => (int)$row->projectId,
                'copyType' => $row->copyType,
                'audienceId' => $row->audienceId,
                'audienceName' => $row->audienceName,
                'angleName' => $angle_name,
                'headline' => $row->headline,
                'body' => $row->body,
                'cta' => $row->cta,
                'hashtags' => json_decode($row->hashtags ?? '[]', true),
                'description' => $row->description,
                'modelUsed' => $model_used ?: 'Gemini Flash',
                'createdAt' => $row->createdAt,
            );
        }, $results ?: array());
    }

    /**
     * Get an existing copy result with ownership check.
     *
     * @param int $result_id Result ID.
     * @param int $user_id   PCM user ID.
     *
     * @return object|null Result row or null.
     */
    public function get_result(int $result_id, int $user_id): ?object
    {
        global $wpdb;

        $result_table = PCM_Schema::table('copy_results');

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $result_table WHERE id = %d AND userId = %d",
            $result_id,
            $user_id
        ));
    }

    /**
     * Update a copy result row with new LLM output (full replacement).
     *
     * Used after regeneration when the entire result is replaced by a new LLM response.
     *
     * @param int   $result_id Result ID.
     * @param array $output    LLM output { headline, body, cta, hashtags, description }.
     *
     * @return void
     */
    public function update_result(int $result_id, array $output): void
    {
        global $wpdb;

        $result_table = PCM_Schema::table('copy_results');

        $wpdb->update($result_table, array(
            'headline' => $output['headline'] ?? '',
            'body' => $output['body'] ?? '',
            'cta' => $output['cta'] ?? '',
            'hashtags' => wp_json_encode($output['hashtags'] ?? array()),
            'description' => $output['description'] ?? '',
            'rawResponse' => wp_json_encode($output),
        ), array('id' => $result_id));
    }

    /**
     * Update specific fields on a copy result (partial update for inline editing).
     *
     * Only updates the fields that are provided in $fields. This is different from
     * update_result() which replaces all fields with LLM output.
     *
     * @param int   $result_id Result ID.
     * @param int   $user_id   PCM user ID (ownership check).
     * @param array $fields    Partial fields { headline?, body?, cta?, hashtags?, description? }.
     *
     * @return bool True if rows were affected, false otherwise.
     */
    public function update_result_fields(int $result_id, int $user_id, array $fields): bool
    {
        global $wpdb;

        $result_table = PCM_Schema::table('copy_results');

        // Only allow whitelisted fields to prevent injection
        $allowed = array('headline', 'body', 'cta', 'hashtags', 'description');
        $data = array();

        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                // Hashtags: convert comma-separated string to JSON array
                if ($key === 'hashtags') {
                    $tags = array_filter(array_map('trim', explode(',', $fields[$key] ?? '')));
                    $data[$key] = wp_json_encode($tags);
                }
                else {
                    $data[$key] = $fields[$key] ?? '';
                }
            }
        }

        if (empty($data)) {
            return false;
        }

        $affected = $wpdb->update(
            $result_table,
            $data,
            array('id' => $result_id, 'userId' => $user_id)
        );

        return $affected !== false && $affected > 0;
    }

    // ========================================
    // Audience Operations (DB)
    // ========================================

    /**
     * Duplicate all copy results for a given audience within a job.
     *
     * Clones every result row matching the source audienceId, inserting new rows
     * with the provided new audienceId and audienceName.
     *
     * @param int    $job_id           Job ID.
     * @param int    $user_id          PCM user ID.
     * @param string $source_audience_id  Original audience ID to duplicate from.
     * @param string $new_audience_id     New audience ID for the duplicated rows.
     * @param string $new_audience_name   New audience name for the duplicated rows.
     *
     * @return array Array of formatted result objects (same shape as get_results_for_job).
     */
    public function duplicate_audience_results(
        int $job_id,
        int $user_id,
        string $source_audience_id,
        string $new_audience_id,
        string $new_audience_name
        ): array
    {
        global $wpdb;

        $result_table = PCM_Schema::table('copy_results');
        $now = current_time('mysql');

        // Fetch all results for the source audience within this job
        $source_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $result_table WHERE jobId = %d AND userId = %d AND audienceId = %s ORDER BY id ASC",
            $job_id,
            $user_id,
            $source_audience_id
        ));

        $new_results = array();

        foreach ($source_rows ?: array() as $row) {
            // Insert a clone with the new audience identity
            $wpdb->insert($result_table, array(
                'jobId' => $job_id,
                'userId' => $user_id,
                'copyType' => $row->copyType,
                'audienceId' => $new_audience_id,
                'audienceName' => $new_audience_name,
                'headline' => $row->headline,
                'body' => $row->body,
                'cta' => $row->cta,
                'hashtags' => $row->hashtags,
                'description' => $row->description,
                'rawResponse' => $row->rawResponse,
                'createdAt' => $now,
            ));

            $new_results[] = array(
                'id' => $wpdb->insert_id,
                'jobId' => $job_id,
                'copyType' => $row->copyType,
                'audienceId' => $new_audience_id,
                'audienceName' => $new_audience_name,
                'headline' => $row->headline,
                'body' => $row->body,
                'cta' => $row->cta,
                'hashtags' => json_decode($row->hashtags ?? '[]', true),
                'description' => $row->description,
                'createdAt' => $now,
                'error' => null,
                'modelUsed' => '',
            );
        }

        return $new_results;
    }

    /**
     * Rename an audience across all results in a job.
     *
     * Updates the audienceName for every result row matching the given audienceId
     * within the specified job. Subsequent regenerations will use the new name.
     *
     * @param int    $job_id      Job ID.
     * @param int    $user_id     PCM user ID.
     * @param string $audience_id Audience ID to rename.
     * @param string $new_name    New audience name.
     *
     * @return int Number of affected rows.
     */
    public function rename_audience_results(int $job_id, int $user_id, string $audience_id, string $new_name): int
    {
        global $wpdb;

        $result_table = PCM_Schema::table('copy_results');

        $affected = $wpdb->update(
            $result_table,
            array('audienceName' => $new_name),
            array(
            'jobId' => $job_id,
            'userId' => $user_id,
            'audienceId' => $audience_id,
        )
        );

        return $affected ?: 0;
    }

    /**
     * Get the next duplicate number for an audience name.
     *
     * Looks at existing audienceNames in the job that start with "$name Copy"
     * and returns the next sequential number.
     *
     * @param int    $job_id        Job ID.
     * @param int    $user_id       PCM user ID.
     * @param string $audience_name Base audience name.
     *
     * @return int Next duplicate number (starts at 1).
     */
    public function get_next_duplicate_number(int $job_id, int $user_id, string $audience_name): int
    {
        global $wpdb;

        $result_table = PCM_Schema::table('copy_results');

        // Find the highest existing "Copy N" suffix for this audience name
        $existing = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT audienceName FROM $result_table WHERE jobId = %d AND userId = %d AND audienceName LIKE %s",
            $job_id,
            $user_id,
            $wpdb->esc_like($audience_name . ' Copy') . '%'
        ));

        $max = 0;
        foreach ($existing as $name) {
            // Extract the number from "Original Name Copy N"
            if (preg_match('/Copy\s+(\d+)$/', $name, $matches)) {
                $max = max($max, (int)$matches[1]);
            }
        }

        return $max + 1;
    }

    // ========================================
    // URL Scraping (Business Info)
    // ========================================

    /**
     * Fetch and extract structured business info from a URL via LLM.
     *
     * Returns data using snake_case keys that match the frontend's
     * ScrapedBusinessData interface: business_name, niche, location, phone,
     * business_summary, website, language, brand_colors.
     *
     * @param string $url URL to scrape.
     *
     * @return array Parsed business info matching ScrapedBusinessData shape.
     * @throws \RuntimeException On fetch failure or parse error.
     */
    public function extract_business_info(string $url, string $model): array
    {
        // Fetch the page content
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'user-agent' => 'Mozilla/5.0 (compatible; PCM-Bot/1.0)',
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException('Failed to fetch URL: ' . $response->get_error_message());
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            throw new \RuntimeException('Empty response from URL.');
        }

        // Extract text content (strip HTML tags, limit to ~5000 chars)
        $text = wp_strip_all_tags($html);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = substr($text, 0, 5000);

        // Extract page title
        preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $title_match);
        $page_title = $title_match[1] ?? '';

        // Extract meta description
        preg_match('/<meta\s+name=["\']description["\']\s+content=["\'](.+?)["\']/is', $html, $desc_match);
        $meta_description = $desc_match[1] ?? '';

        // Use LLM to extract structured business info.
        // Field names use snake_case to match the frontend ScrapedBusinessData interface.
        $messages = array(
                array(
                'role' => 'system',
                'content' => 'You are a business analyst. Extract structured business information from the given webpage text. '
                . 'Return a JSON object with these exact fields (use snake_case keys): '
                . 'business_name (string, the company/brand name), '
                . 'niche (string, industry or market niche e.g. "Dental Clinic", "E-commerce", "SaaS"), '
                . 'business_summary (string, 1-3 sentence description of what the business does and its value proposition), '
                . 'location (string, physical address or city/country if mentioned, empty string if not found), '
                . 'phone (string, phone number if found on the page, empty string if not found), '
                . 'website (string, the main website URL), '
                . 'language (string, ISO 639-1 code of the primary language of the page content, e.g. "en", "sv", "de"), '
                . 'brand_colors (string[], up to 5 hex color codes visible on the site, e.g. ["#1a73e8", "#ffffff"]), '
                . 'products (string[], up to 5 key products or services), '
                . 'target_audience (string, who they primarily serve), '
                . 'unique_selling_points (string[], up to 3 USPs), '
                . 'tone_of_voice (string, e.g. "professional", "playful", "authoritative"). '
                . 'Return ONLY valid JSON, no markdown.',
            ),
                array(
                'role' => 'user',
                'content' => "URL: {$url}\nPage title: {$page_title}\nMeta description: {$meta_description}\n\nPage text:\n{$text}",
            ),
        );

        $result = PCM_LLM::invoke($messages, array('model' => $model, 'max_tokens' => (int)PCM_Settings::get('token_budget_copy', 16384)));
        // Defensive: some providers (Claude, Gemini) sometimes wrap JSON in
        // ```json ... ``` markdown despite the "no markdown" system instruction.
        // extract_json() is a no-op on already-clean JSON.
        $clean_json = PCM_LLM::extract_json($result['content'] ?? '');
        $parsed = json_decode($clean_json !== '' ? $clean_json : '{}', true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to parse business info from URL.');
        }

        // Normalize response to guarantee frontend-expected keys are present.
        // Handles both snake_case (expected) and camelCase (legacy LLM output) gracefully.
        $normalized = array(
            'business_name' => $parsed['business_name'] ?? $parsed['businessName'] ?? '',
            'niche' => $parsed['niche'] ?? $parsed['industry'] ?? '',
            'business_summary' => $parsed['business_summary'] ?? $parsed['description'] ?? '',
            'location' => $parsed['location'] ?? '',
            'phone' => $parsed['phone'] ?? '',
            'website' => $parsed['website'] ?? $url,
            'language' => $parsed['language'] ?? '',
            'brand_colors' => $parsed['brand_colors'] ?? $parsed['brandColors'] ?? array(),
            'products' => $parsed['products'] ?? array(),
            'target_audience' => $parsed['target_audience'] ?? $parsed['targetAudience'] ?? '',
            'unique_selling_points' => $parsed['unique_selling_points'] ?? $parsed['uniqueSellingPoints'] ?? array(),
            'tone_of_voice' => $parsed['tone_of_voice'] ?? $parsed['toneOfVoice'] ?? '',
            'source_url' => $url,
            'page_title' => $page_title,
        );

        return $normalized;
    }

    // ========================================
    // Task Matrix
    // ========================================

    /**
     * Build the generation task matrix: copyType × audience × angle.
     *
     * Supports audience-aware angles: if an angle has audienceId set,
     * it only pairs with its matching audience. Angles with audienceId = '*'
     * (manual/generic) pair with ALL audiences (backward-compatible cartesian).
     *
     * @param array  $types_to_generate Copy types to generate.
     * @param array  $audiences         Audience objects.
     * @param array  $angles            Angle objects (may include audienceId).
     * @param string $scope             Scope filter: 'all', 'current_type', 'current_audience'.
     * @param array  $scope_filter      Scope filter params { type?, audienceId? }.
     *
     * @return array Array of tasks { copyType, audience, angle }.
     */
    public function build_task_matrix(
        array $types_to_generate,
        array $audiences,
        array $angles,
        string $scope = 'all',
        array $scope_filter = array()
        ): array
    {
        $tasks = array();

        foreach ($types_to_generate as $copy_type) {
            $target_audiences = $audiences;
            if ($scope === 'current_audience' && !empty($scope_filter['audienceId'])) {
                $target_audiences = array_filter($audiences, function ($a) use ($scope_filter) {
                    return $a['id'] === $scope_filter['audienceId'];
                });
                $target_audiences = array_values($target_audiences);
            }

            foreach ($target_audiences as $audience) {
                foreach ($angles as $angle) {
                    $angle_audience = $angle['audienceId'] ?? '*';

                    // Matched pairing: angle belongs to this audience OR is global (*)
                    if ($angle_audience === '*' || $angle_audience === $audience['id']) {
                        $tasks[] = array(
                            'copyType' => $copy_type,
                            'audience' => $audience,
                            'angle' => $angle,
                        );
                    }
                }
            }
        }

        return $tasks;
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * Get the raw system prompt template for a copy type.
     *
     * Returns the template string (potentially with {{placeholders}}) from
     * prompt_overrides DB table, or a comprehensive default template.
     *
     * Supports sections: system_prompt_ads, system_prompt_organic,
     * angle_generation, audience_generation, angle_generation_with_audiences,
     * audience_research.
     *
     * @param string $section_or_type Section name or legacy copy_type (social_ads, social_organic).
     * @param int    $user_id         PCM user ID.
     * @param string $module          Module namespace to read from ('copy' or 'ads').
     *                                Defaults to 'copy' for backward compatibility.
     *                                When 'ads', checks for ads-specific overrides first,
     *                                then falls back to 'copy' prompts.
     *
     * @return string System prompt template (may contain {{placeholders}}).
     */
    public function get_system_prompt(string $section_or_type, int $user_id, string $module = 'copy'): string
    {
        global $wpdb;

        // Map legacy copy_type names to section names for backwards compat
        $section_map = array(
            'social_ads' => 'system_prompt_ads',
            'social_organic' => 'system_prompt_organic',
        );
        $section = $section_map[$section_or_type] ?? $section_or_type;

        $table = PCM_Schema::table('prompt_overrides');

        // Check for user override (DB = single source of truth)
        if ($user_id > 0) {
            // When module is 'ads', check ads namespace first, then fall back to copy.
            // This allows Ads users to customize prompts independently, while new users
            // who haven't customized yet get the Copy module's active prompt.
            $modules_to_check = ($module === 'ads')
                ? array('ads', 'copy')
                : array($module);

            foreach ($modules_to_check as $mod) {
                $override = $wpdb->get_var($wpdb->prepare(
                    "SELECT content FROM $table WHERE userId = %d AND module = %s AND section = %s AND isActive = 1 ORDER BY updatedAt DESC LIMIT 1",
                    $user_id,
                    $mod,
                    $section
                ));

                if (!empty($override)) {
                    return $override;
                }
            }
        }

        // No active prompt found — this should never happen because:
        //   - Seeds set isActive=1 on creation
        //   - update_variant auto-activates the edited variant
        //   - delete_variant auto-promotes the next variant
        // If we get here, something went wrong with the data.
        throw new \RuntimeException(
            "No active prompt found for section '{$section}' (userId={$user_id}). "
            . "Check the Prompt Editor or reactivate the plugin to re-seed."
            );
    }

    /**
     * Get all default prompt templates for the copy module.
     *
     * Centralised registry of built-in prompts. Each template supports
     * {{placeholders}} that are resolved at generation time.
     *
     * @return array Section name → default prompt template.
     */
    public static function get_default_prompts(): array
    {
        return array(
            // ── Copy Generation — System Prompt (Ads) ─────────────
            // Sent as role:system — persona + output format instruction.
            // Editable in Settings → Prompt Editor → "Ads System Prompt".
            // Does NOT support {{placeholders}}.
            'system_prompt_ads_system' => "You are an expert copywriter specializing in paid social media advertising.

IMPORTANT FOR OUTPUT:
The main body copy must use \\n for line breaks between paragraphs. Include formatting like numbered lists where appropriate. Never return a wall of text without paragraph breaks.",

            // ── Copy Generation — User Prompt (Ads) ───────────────
            // Sent as role:user — language, business context, style rules, and task.
            // Editable in Settings → Prompt Editor → "Ads User Prompt".
            'system_prompt_ads' => "Your task: Write a single high-converting social media ad in {{language}}.

BUSINESS CONTEXT:
{{brief}}
{{campaignContext}}
{{referenceCopy}}
{{reviewsContext}}

OUTPUT FORMAT:
Return a JSON object with: headline, body, cta, hashtags (EMPTY array for ads), description.

RULES FOR ADS:
- Headline: attention-grabbing, concise (max 40 chars)
- Body: persuasive, direct, focused on benefits. Use \\n for line breaks.
- CTA: clear call-to-action button text
- Hashtags: ALWAYS return an empty array [] — paid ads do NOT use hashtags
- Description: short link description (1-2 sentences, like Facebook ad link descriptions)

STYLE RULES:
- {{toneInstruction}}
- {{emojiInstruction}}
{{ctaInstruction}}

TASK:
Write a {{typeLabel}} for this specific audience and angle:
TARGET AUDIENCE: {{audience}}
MARKETING ANGLE: {{angle}}
Tailor the copy specifically to resonate with '{{audience}}' using the '{{angle}}' approach. Make it feel personal and relevant to this exact audience segment.",

            // ── Copy Generation (Organic) ──────────────────────────
            'system_prompt_organic' => "You are an expert social media content creator.

Your task: Write a single engaging organic social media post in {{language}}.

BUSINESS CONTEXT:
{{brief}}
{{campaignContext}}
{{referenceCopy}}
{{reviewsContext}}
{{organicContext}}

OUTPUT FORMAT:
Return a JSON object with: headline (empty string for organic), body, cta (empty string for organic), hashtags (3-6 relevant hashtags with # prefix), description (empty string for organic).

RULES FOR ORGANIC POSTS:
- Headline: leave as empty string
- Body: engaging, shareable content. Use \\n for line breaks. Include formatting like numbered lists where appropriate.
- CTA: leave as empty string
- Hashtags: 3-6 relevant hashtags with # prefix
- Description: leave as empty string

STYLE RULES:
- {{toneInstruction}}
- {{emojiInstruction}}

TASK:
Write a {{typeLabel}} for this specific audience and angle:
TARGET AUDIENCE: {{audience}}
MARKETING ANGLE: {{angle}}
Tailor the copy specifically to resonate with '{{audience}}' using the '{{angle}}' approach. Make it feel personal and relevant to this exact audience segment.",

            // ── Angle Generation ───────────────────────────────────
            'angle_generation' => "You generate creative marketing angles/hooks for advertising copy.

BUSINESS CONTEXT:
Brand: {{brandName}}
Product/Service: {{product}}
Description: {{description}}
Tonality: {{tone}}
{{campaignContext}}

REFERENCE ADS (study these for style and angle inspiration):
{{referenceAds}}

INSTRUCTIONS:
- Generate {{count}} distinct marketing angles, each representing a unique persuasive approach.
- Each angle should be clearly different from the others (e.g. value/savings, pain relief, urgency, social proof, emotional, etc.).
- Consider the brand's tonality and any seasonal/campaign context.
- Return a JSON array of {{count}} objects with 'id' (kebab-case slug) and 'name' (2-5 words describing the angle).
- NO emojis. Return ONLY valid JSON array, no markdown.",

            // ── Audience Generation (enhanced with STP framework) ──
            'audience_generation' => "You are a senior media buyer at a performance marketing agency.
Your task: identify the most commercially viable target audience segments.

Apply the STP framework (Segmentation → Targeting → Positioning):
1. SEGMENT the market by demographics, psychographics, behavior, and needs.
2. TARGET segments with highest purchase intent and reachability.
3. POSITION each segment with its primary psychological driver
   (Cialdini: reciprocity, scarcity, authority, consistency, liking, consensus).

BUSINESS CONTEXT:
Brand: {{brandName}}
Product/Service: {{product}}
Description: {{description}}
Tonality: {{tone}}
{{campaignContext}}

REFERENCE ADS (study these to understand existing targeting):
{{referenceAds}}

INSTRUCTIONS:
- Generate {{count}} distinct, commercially viable target audience segments.
- Each segment must represent a DIFFERENT buyer persona (not just demographic variations).
- Consider real market demand — who actually searches for and buys this type of product/service?

MARKET RESEARCH (use these real-world insights to inform your audience selection):
{{researchContext}}

- Return a JSON array of {{count}} objects with 'id' (kebab-case slug) and 'name' (human-readable, 2-5 words).
- NO emojis. Return ONLY valid JSON, no markdown.",

            // ── Audience-Aware Angle Generation ───────────────────
            'angle_generation_with_audiences' => "You are a creative director at a top-tier ad agency.
Your task: generate persuasive marketing angles TAILORED to each specific audience.

BUSINESS CONTEXT:
Brand: {{brandName}}
Product/Service: {{product}}
Description: {{description}}
Tonality: {{tone}}
{{campaignContext}}

REFERENCE ADS:
{{referenceAds}}

TARGET AUDIENCES:
{{audiences}}

FRAMEWORK — For each audience, apply these copywriting principles to choose the best angles:
- AIDA (Attention → Interest → Desire → Action)
- PAS (Problem → Agitate → Solution)
- Cialdini's 6 principles (reciprocity, scarcity, authority, consistency, liking, consensus)
Choose the approach that resonates MOST with each specific audience's psychology.

INSTRUCTIONS:
- Generate exactly {{anglesPerAudience}} angles PER audience.
- Each angle must be SPECIFICALLY tailored to its audience's psychology and buying motivation.
- Angles for different audiences SHOULD differ — 'Budget Parents' need different angles than 'Tech Professionals'.
- Return a JSON array of objects, each with:
  - 'id' (kebab-case slug)
  - 'name' (2-5 words describing the angle)
  - 'audienceId' (the audience's id this angle belongs to — MUST match an audience id from the list above)
- NO emojis. Return ONLY valid JSON, no markdown.",

            // ── Audience Research (grounding step) ───────────────
            'audience_research' => "You are a market research analyst.
Your task: research the market for this business and identify who the real buyers are.

BUSINESS CONTEXT:
Brand: {{brandName}}
Product/Service: {{product}}
Description: {{description}}
{{campaignContext}}

RESEARCH OBJECTIVES:
- Who searches for this type of product/service?
- What are the primary pain points that drive purchase decisions?
- What demographics and psychographics characterize the buyers?
- What competing alternatives do buyers consider?
- What language and terminology do buyers use when searching?

Provide a concise market research summary (max 300 words) that can inform audience segmentation.",
        );
    }

    /**
     * Build the full system prompt from a template + form values.
     *
     * Resolves all {{placeholder}} variables in the template string.
     * Ported from SOURCE copyPrompts.ts buildCopySystemPrompt().
     *
     * @param array  $form_values Form field values.
     * @param string $copy_type   Copy type (social_ads, social_organic).
     * @param int    $user_id     PCM user ID.
     * @param array  $audience    Current audience { id, name } for {{audience}} placeholder.
     * @param array  $angle       Current angle { id, name } for {{angle}} placeholder.
     *
     * @return string Fully resolved system prompt ready for LLM.
     */
    public function build_copy_system_prompt(array $form_values, string $copy_type, int $user_id, array $audience = array(), array $angle = array(), string $module = 'copy'): string
    {
        $template = $this->get_system_prompt($copy_type, $user_id, $module);

        $locale_code = substr(get_locale(), 0, 2);
        $language = $this->get_language_label($form_values['language'] ?? $locale_code);
        $brief = $this->extract_brief($form_values);

        // Tone resolution
        $tone_map = array(
            'professional' => 'Use a professional, authoritative tone.',
            'casual' => 'Use a casual, friendly, conversational tone.',
            'humorous' => 'Use humor and wit. Be playful but not silly.',
            'urgent' => 'Create urgency and FOMO. Use time-sensitive language.',
            'luxurious' => 'Use premium, aspirational language. Evoke exclusivity.',
            'empathetic' => 'Be warm, caring, and understanding. Connect emotionally.',
            'bold' => 'Be bold, provocative, and attention-grabbing. Challenge assumptions.',
        );
        $tone_key = $form_values['tone_override'] ?? $form_values['tone'] ?? $form_values['toneOfVoice'] ?? '';
        $tone_instruction = $tone_map[$tone_key] ?? 'Choose the most appropriate tone for the audience and angle.';

        // Emoji resolution
        $emoji_map = array(
            'auto' => 'Use emojis naturally and organically — choose the most fitting emoji usage for the audience and context.',
            'none' => 'Do NOT use any emojis.',
            'few' => 'Use 1-2 emojis sparingly for emphasis.',
            'some' => 'Use 3-5 emojis to add personality.',
            'many' => 'Use 6+ emojis generously throughout the copy.',
        );
        $emoji_instruction = $emoji_map[$form_values['emoji_level'] ?? 'auto'] ?? $emoji_map['auto'];

        // CTA style (ads only)
        $cta_instruction = '';
        if ($copy_type === 'social_ads') {
            $cta_map = array(
                'direct' => 'Use a direct CTA like "Buy Now", "Sign Up", "Get Started".',
                'soft' => 'Use a soft CTA like "Learn More", "See How", "Discover".',
                'urgency' => 'Use an urgency CTA like "Limited Time", "Act Now", "Don\'t Miss Out".',
                'question' => 'Use a question CTA like "Ready to…?", "Want to…?".',
            );
            $cta_key = $form_values['cta_style'] ?? '';
            $cta_val = $cta_map[$cta_key] ?? 'Choose the most effective CTA style for the context.';
            $cta_instruction = '- ' . $cta_val;
        }

        // Customer reviews context
        $reviews_context = '';
        $reviews = trim($form_values['reviews'] ?? '');
        if (!empty($reviews)) {
            $reviews_context = "\n\nCUSTOMER REVIEWS (use these real customer experiences to write more authentic, emotionally resonant copy):\n" . $reviews;
        }

        // Reference copy (if provided).
        // Frontend stores reference ads as indexed keys (reference_ad_0, reference_ad_1, …)
        // via the dynamic_list inputType. Collect them into a single string.
        $reference_copy = '';
        $ref_ads = $this->collect_dynamic_list($form_values, 'reference_ad');
        if (!empty($ref_ads)) {
            $reference_copy = "\n\nREFERENCE ADS (match the style and tone of these examples):\n" . $ref_ads;
        }

        // Organic-specific context
        $organic_context = '';
        if ($copy_type === 'social_organic') {
            if (!empty($form_values['content_pillar'])) {
                $organic_context .= "\nContent pillar: " . $form_values['content_pillar'];
            }
            $post_format = $form_values['post_format'] ?? 'auto';
            if ($post_format !== 'auto') {
                $format_map = array(
                    'short' => 'Write a short post (1-3 lines).',
                    'story' => 'Write in a story/narrative format.',
                    'listicle' => 'Write as a numbered list of tips or points.',
                    'question' => 'Frame the post as a question or poll.',
                    'carousel' => 'Write carousel-style captions (one key point per slide).',
                    'thread' => 'Write as a multi-part thread.',
                );
                $organic_context .= "\n" . ($format_map[$post_format] ?? '');
            }
        }

        // Campaign/season context
        $campaign_context = $this->build_season_context($form_values);
        if (!empty($campaign_context)) {
            $campaign_context = "\n" . $campaign_context;
        }

        // Resolve all {{placeholders}} in the template
        $vars = array(
            'language' => $language,
            'brief' => $brief,
            'referenceCopy' => $reference_copy,
            'reviewsContext' => $reviews_context,
            'organicContext' => $organic_context,
            'campaignContext' => $campaign_context,
            'toneInstruction' => $tone_instruction,
            'emojiInstruction' => $emoji_instruction,
            'ctaInstruction' => $cta_instruction,
            // Type label — "paid social ad" or "organic social media post"
            'typeLabel' => $copy_type === 'social_ads' ? 'paid social ad' : 'organic social media post',
            // Angle + audience — resolved per-task so the prompt
            // can reference the current marketing angle and audience segment.
            'angle' => $angle['name'] ?? '',
            'audience' => $audience['name'] ?? '',
        );

        return $this->resolve_prompt_placeholders($template, $vars);
    }

    /**
     * Normalize model IDs for Copy module LLM calls.
     *
     * Resolution order:
     *   1. Explicit model ID from frontend (non-empty, not 'built-in')
     *   2. Settings → Module Defaults → Menu Intelligence (PCM_Settings)
     *   3. Fail-fast — no silent fallback, require explicit configuration
     *
     * @param string $model_id Model ID from frontend.
     * @param int    $user_id  PCM user ID for registry lookup.
     *
     * @return string Normalized model ID.
     * @throws \RuntimeException If no model is configured.
     */
    public function normalize_model_id(string $model_id, int $user_id = 0): string
    {
        // 1. Explicit model from request
        if (!empty($model_id) && $model_id !== 'built-in') {
            return $model_id;
        }

        // 2. Settings → Module Defaults → Menu Intelligence (backend-persisted)
        $settings_model = PCM_Settings::get('copy_menu_intelligence', '');
        if (!empty($settings_model)) {
            return $settings_model;
        }

        // 3. No model configured — fail-fast with actionable message
        throw new \RuntimeException(
            'No Menu Intelligence model configured for the Copy module. '
            . 'Please go to Settings → Module Defaults → Copy → Menu Intelligence '
            . 'and select a text model (e.g. Gemini 2.5 Flash).'
            );
    }

    /**
     * Strip emojis and truncate a label.
     *
     * @param string $name    Raw label.
     * @param int    $max_len Maximum length.
     *
     * @return string Sanitized label.
     */
    public function sanitize_label(string $name, int $max_len = 60): string
    {
        $clean = preg_replace(self::EMOJI_PATTERN, '', $name);
        $clean = trim($clean);
        if (strlen($clean) > $max_len) {
            $clean = substr($clean, 0, $max_len);
        }
        return $clean;
    }

    // ========================================
    // Dynamic list helpers
    // ========================================

    /**
     * Collect indexed dynamic-list entries into a single formatted string.
     *
     * Frontend's `dynamic_list` inputType stores entries as separate keys:
     *   prefix_0, prefix_1, prefix_2, …
     * This helper collects all non-empty entries and joins them with
     * separator lines for clear LLM readability.
     *
     * Generic by design — works for any dynamic_list field, not just
     * reference ads. Pass the `dynamicListPrefix` value from copyConfig.ts.
     *
     * @param array  $form_values  Form field values from the sidebar.
     * @param string $prefix       The dynamic list prefix (e.g. 'reference_ad').
     * @param int    $max_entries  Safety cap to avoid infinite iteration.
     *
     * @return string Collected entries joined by separator, or empty string.
     */
    private function collect_dynamic_list(array $form_values, string $prefix, int $max_entries = 50): string
    {
        $entries = array();

        for ($i = 0; $i < $max_entries; $i++) {
            $key = "{$prefix}_{$i}";
            $val = trim($form_values[$key] ?? '');
            if ($val !== '') {
                $entries[] = $val;
            }
        }

        if (empty($entries)) {
            return '';
        }

        return implode("\n---\n", $entries);
    }



    /**
     * Extract a structured brief from form values.
     *
     * Ported from SOURCE copyPrompts.ts extractBrief().
     * Builds a human-readable context block from all available form fields.
     *
     * @param array $form_values Form field values.
     *
     * @return string Structured brief text.
     */
    private function extract_brief(array $form_values): string
    {
        $parts = array();

        // Core business info
        $business_name = $form_values['business_name'] ?? $form_values['brandName'] ?? '';
        if (!empty($business_name))
            $parts[] = "Business: {$business_name}";

        $niche = $form_values['niche'] ?? '';
        if (!empty($niche))
            $parts[] = "Industry: {$niche}";

        $summary = $form_values['business_summary'] ?? $form_values['description'] ?? '';
        if (!empty($summary))
            $parts[] = "About: {$summary}";

        // Offer details
        $offer = $form_values['offer_name'] ?? $form_values['product'] ?? $form_values['service'] ?? '';
        if (!empty($offer))
            $parts[] = "Offer: {$offer}";

        $std_price = $form_values['standard_price'] ?? '';
        if (!empty($std_price))
            $parts[] = "Regular price: {$std_price}";

        $offer_price = $form_values['offer_price'] ?? '';
        if (!empty($offer_price))
            $parts[] = "Offer price: {$offer_price}";

        $discount = $form_values['total_discount'] ?? '';
        if (!empty($discount))
            $parts[] = "Discount: {$discount}";

        $bonuses = $form_values['extra_bonuses'] ?? '';
        if (!empty($bonuses))
            $parts[] = "Bonuses: {$bonuses}";

        // Contact info
        $location = $form_values['location'] ?? '';
        if (!empty($location))
            $parts[] = "Location: {$location}";

        $website = $form_values['website'] ?? '';
        if (!empty($website))
            $parts[] = "Website: {$website}";

        $landing = $form_values['landing_page_url'] ?? '';
        if (!empty($landing))
            $parts[] = "Landing page: {$landing}";

        $shortlink = $form_values['shortlink'] ?? '';
        if (!empty($shortlink))
            $parts[] = "Shortlink: {$shortlink}";

        $phone = $form_values['phone'] ?? '';
        if (!empty($phone))
            $parts[] = "Phone: {$phone}";

        // Creative Brief — free-form user directions
        $creative_brief = $form_values['creativeBrief'] ?? '';
        if (!empty($creative_brief))
            $parts[] = "Creative Brief: {$creative_brief}";

        $result = implode("\n", $parts);

        // [PCM_DIAG] Temporary diagnostic — log what extract_brief() produces
        // to verify creative brief reaches the LLM prompt. Remove after investigation.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[PCM_DIAG extract_brief] creativeBrief key present: ' . (isset($form_values['creativeBrief']) ? 'YES' : 'NO'));
            error_log('[PCM_DIAG extract_brief] creativeBrief value: ' . substr($creative_brief, 0, 200));
            error_log('[PCM_DIAG extract_brief] full brief output: ' . substr($result, 0, 500));
        }

        return $result;
    }

    /**
     * Build campaign/seasonal context string.
     *
     * Ported from SOURCE copyPrompts.ts buildSeasonContext().
     *
     * @param array $form_values Form field values.
     *
     * @return string Season/campaign context or empty string.
     */
    private function build_season_context(array $form_values): string
    {
        $parts = array();

        $season = $form_values['season_event'] ?? '';
        if (!empty($season)) {
            $parts[] = "Seasonal context: {$season}";
        }

        $theme = $form_values['campaign_theme'] ?? '';
        if (!empty($theme)) {
            $parts[] = "Campaign theme: {$theme}";
        }

        return implode("\n", $parts);
    }

    /**
     * Replace {{placeholders}} in a prompt template with resolved values.
     *
     * Ported from SOURCE copyPrompts.ts resolvePromptPlaceholders().
     *
     * @param string $template Template string with {{key}} placeholders.
     * @param array  $vars     Key-value map of placeholder replacements.
     *
     * @return string Resolved prompt string.
     */
    private function resolve_prompt_placeholders(string $template, array $vars): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($matches) use ($vars) {
            return $vars[$matches[1]] ?? '';
        }, $template);
    }

    /**
     * Map language code to human label.
     *
     * @param string $code ISO 639-1 code or full language name.
     *
     * @return string Human-readable language name.
     */
    private function get_language_label(string $code): string
    {
        $lower_code = strtolower(trim($code));

        $map = array(
            'en' => 'English', 'sv' => 'Swedish', 'no' => 'Norwegian', 'da' => 'Danish',
            'fi' => 'Finnish', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish',
            'pt' => 'Portuguese', 'it' => 'Italian', 'nl' => 'Dutch', 'ar' => 'Arabic',
        );

        if (isset($map[$lower_code])) {
            return $map[$lower_code];
        }

        if (strlen($lower_code) > 2) {
            return ucfirst($lower_code);
        }

        return 'English';
    }

    /**
     * Filter copy types based on scope settings.
     *
     * @param array  $copy_types   All copy types.
     * @param string $scope        Scope: 'all', 'current_type'.
     * @param array  $scope_filter { type? }.
     *
     * @return array Filtered copy types.
     */
    public function filter_copy_types(array $copy_types, string $scope, array $scope_filter): array
    {
        if ($scope === 'current_type' && !empty($scope_filter['type'])) {
            return array($scope_filter['type']);
        }
        return $copy_types;
    }
}
