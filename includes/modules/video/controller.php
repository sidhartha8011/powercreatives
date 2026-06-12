<?php
/**
 * Video REST Controller
 *
 * Handles video generation operations:
 *   - Concept suggestions via LLM (with brand logo context)
 *   - Video generation dispatch to provider API (Kie.ai, etc.)
 *   - Status checking for async video generation
 *   - Capabilities listing for available models
 *
 * Endpoints:
 *   POST   /video/concepts       → suggestConcepts
 *   POST   /video/generate       → generate
 *   POST   /video/status         → checkStatus
 *   GET    /video/capabilities    → getCapabilities
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Video extends PCM_REST_Base
{
    // Work module — usable by non-admin team members (assigned access).
    protected string $default_capability = 'edit_posts';
    // Per-delivery module grant ids (see PCM_REST_Base::$module_grant_keys).
    protected array $module_grant_keys = array('video');


    /**
     * Service instance — holds video generation business logic.
     *
     * @var PCM_Video_Service
     */
    private PCM_Video_Service $service;

    /**
     * Constructor — inject service dependency.
     */
    public function __construct()
    {
        $this->service = new PCM_Video_Service();
    }

    /**
     * Default video concept suggestion prompt.
     *
     * @var string
     */
    const DEFAULT_CONCEPTS_PROMPT = <<<'PROMPT'
You are a senior creative director at a performance marketing agency.
Generate unique video ad scene variations based on the user's brief.
Each concept must have:
- name: Short creative name (2-4 words)
- description: short description of the angle
- prompt: Create a variation of the users brief while preserving the format and structure, preserve all the important details from the user's brief but adapt the angle of the video to a different angle. (camera angles, lighting, wardrobe, setting, constraints, negative prompts) and adapt them to this specific creative angle. This prompt will be sent DIRECTLY to the video AI model.
Each concept must represent a distinctly different creative angle:
different POV, emotion, location, or narrative hook.
Return a JSON object with a "concepts" array.
PROMPT;

    /**
     * Default prompt enhancement system prompt.
     * Rewrites a simple user brief into a detailed, structured video ad prompt.
     *
     * @var string
     */
    const DEFAULT_ENHANCE_PROMPT = <<<'PROMPT'
You are a senior creative director at a performance marketing agency.
The user will give you a short brief. Rewrite it into a structured, detailed prompt
optimized for AI video generation models (Kling, Sora, Hailuo, Runway).

Your output MUST use this exact format:

dialogue: [1-3 short sentences. Sounds like a real person talking to camera. No corporate speak.]
action: [What the person/object does throughout the video. 1 clear, visible action.]
camera: [iPhone selfie / handheld / fixed camera + framing + movement. Always specify.]
emotion: [genuine excitement / calm confidence / playful / relieved / curious]
setting: [Real location: bathroom, kitchen, car, office, gym, bedroom...]
wardrobe/props: [Clothes, product, props — realistic everyday items, not styled]
lighting: [natural window light / soft bathroom light / golden hour / ring light]
constraints: [product must stay visible; maintain eye contact; UGC/TikTok aesthetic]
negative: [no subtitles, no logos except product, no extra hands, no weird facial distortions, no CGI, no 3D rendering, no polished studio look]

Make it feel like a real UGC/TikTok/Instagram ad — authentic, not corporate.
Return ONLY the structured prompt, nothing else.
PROMPT;

    /**
     * System prompt for the compose_prompt endpoint.
     *
     * Instructs the LLM to fill in every [...] bracket placeholder
     * in a user-provided scene framework, using the product brief
     * and content recipe as context.
     *
     * @var string
     */
    const DEFAULT_COMPOSE_PROMPT = <<<'PROMPT'
You are a video prompt composer for AI video generation (Kling, Sora, Hailuo, Runway).

The user provides:
1. A product/service brief
2. A scene framework template with [...] bracketed placeholders
3. Optionally, a content recipe (style/pattern guide)

Your ONLY task: fill in every [...] placeholder in the framework.

Rules:
- Return ONLY the filled-in framework — no explanations, headers, markdown, or commentary
- Preserve the exact structure, field names, and formatting of the original framework
- Fill brackets with specific, vivid, production-ready content informed by the brief and recipe
- Keep dialogue natural, short, authentic (UGC/TikTok style — sounds like a real person, not corporate)
- All visual descriptions must be concrete and filmable (no abstract concepts)
- Respect all constraints and negative prompts already in the framework
- If the framework has multiple scenes, fill in ALL scenes
PROMPT;

    /**
     * Define video module routes.
     *
     * @return array
     */
    protected function routes(): array
    {
        return array(
                array('POST', '/video/concepts', 'suggest_concepts'),
                array('POST', '/video/enhance-prompt', 'enhance_prompt'),
                array('POST', '/video/compose-prompt', 'compose_prompt'),
                array('POST', '/video/generate', 'generate'),
                // Async pair for Kie.ai models — shared hosting kills the
                // blocking generate poll loop (up to 600s), so: create the
                // task, poll cheaply, persist on completion.
                array('POST', '/video/generate-task', 'create_generation_task'),
                array('POST', '/video/task-result', 'get_generation_result'),
                array('POST', '/video/status', 'check_status'),
                array('GET', '/video/capabilities', 'get_capabilities'),
        );
    }

    // ========================================
    // Concept Suggestions
    // ========================================

    /**
     * Suggest creative video concepts via LLM.
     *
     * Body: {
     *   prompt: string,
     *   count?: int (1-10, default 5),
     *   brandId?: int,
     *   modelId?: string,
     *   style?: string,
     *   sceneTemplate?: string,
     *   recipeTemplate?: string
     * }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function suggest_concepts(WP_REST_Request $request)
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        // Accept both backend names (prompt, count) and frontend aliases (productBrief, conceptCount)
        $prompt = $params['prompt'] ?? $params['productBrief'] ?? '';
        $count = min(10, max(1, (int)($params['count'] ?? $params['conceptCount'] ?? 5)));
        $brand_id = $params['brandId'] ?? null;
        $model_id = $params['modelId'] ?? null;
        $style = $params['style'] ?? '';
        $scene_template = $params['sceneTemplate'] ?? '';
        $recipe_template = $params['recipeTemplate'] ?? '';

        if (empty($prompt)) {
            return $this->error('Prompt is required.');
        }

        // Build system prompt — DB is single source of truth, no hardcoded fallback
        $system_prompt = $this->get_prompt_override($user->id, 'video', 'concept_suggestions');
        if (empty($system_prompt)) {
            return $this->error(
                'No active prompt found for video concept suggestions. '
                . 'Please deactivate and reactivate the plugin to seed defaults, '
                . 'or create one in Settings → Prompt Editor.',
                500
            );
        }
        $system_prompt .= "\n\nGenerate exactly {$count} concepts.";

        // Build user message — optionally include brand logo
        $user_content = array();
        $user_text = "Brief: {$prompt}";
        if ($style) {
            $user_text .= "\nStyle: {$style}";
        }

        // Inject scene template (fishbone structure) if selected
        if ($scene_template) {
            $user_text .= "\n\n--- SCENE FRAMEWORK (follow this structure) ---\n{$scene_template}";
        }

        // Inject recipe template (content type) if selected
        if ($recipe_template) {
            $user_text .= "\n\n--- CONTENT RECIPE (use this style/pattern) ---\n{$recipe_template}";
        }

        // Get brand logo if brand is selected
        if ($brand_id) {
            $logo_url = $this->get_brand_logo_url($brand_id);
            if ($logo_url) {
                $user_text .= "\n\nA brand logo is attached for reference.";
                $user_content[] = array('type' => 'text', 'text' => $user_text);
                $user_content[] = array(
                    'type' => 'image_url',
                    'image_url' => array('url' => $logo_url, 'detail' => 'auto'),
                );
            }
            else {
                $user_content = $user_text;
            }
        }
        else {
            $user_content = $user_text;
        }

        try {
            $messages = array(
                    array('role' => 'system', 'content' => $system_prompt),
                    array('role' => 'user', 'content' => $user_content),
            );

            $options = array(
                'max_tokens' => (int)PCM_Settings::get('token_budget_video', 4096),
                'user_id' => $user->id,
                'response_format' => array(
                    'type' => 'json_schema',
                    'json_schema' => array(
                        'name' => 'video_concepts',
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
                                            'prompt' => array('type' => 'string'),
                                        ),
                                        'required' => array('name', 'description', 'prompt'),
                                    ),
                                ),
                            ),
                            'required' => array('concepts'),
                        ),
                    ),
                ),
            );

            if ($model_id) {
                $options['model'] = $model_id;
            }

            $result = PCM_LLM::invoke($messages, $options);
            // Defensive: some providers (Claude, Gemini) wrap JSON in markdown
            // despite response_format instructions. extract_json() is a no-op on clean JSON.
            $clean_json = PCM_LLM::extract_json($result['content'] ?? '{}');
            $parsed = json_decode($clean_json !== '' ? $clean_json : '{}', true);

            // Frontend expects a flat array: response.map(c => ...)
            return $this->success($parsed['concepts'] ?? array());

        }
        catch (\Exception $e) {
            return $this->error('Concept generation failed: ' . $e->getMessage(), 500);
        }
    }

    // ========================================
    // Prompt Enhancement
    // ========================================

    /**
     * Enhance a user's video prompt via LLM.
     *
     * Takes a simple brief and rewrites it into a detailed,
     * model-optimized video generation prompt.
     *
     * The system prompt is determined by priority:
     *   1. enhanceTemplate param (selected Enhancement template from dropdown)
     *   2. DEFAULT_ENHANCE_PROMPT fallback
     *
     * Body: {
     *   prompt: string,
     *   enhanceTemplate?: string,  ← Enhancement template content from dropdown
     *   style?: string,
     *   sceneTemplate?: string,
     *   recipeTemplate?: string
     * }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function enhance_prompt(WP_REST_Request $request)
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        $prompt = $params['prompt'] ?? '';
        $model_id = $params['modelId'] ?? null;
        $style = $params['style'] ?? '';
        $scene_template = $params['sceneTemplate'] ?? '';
        $recipe_template = $params['recipeTemplate'] ?? '';
        $enhance_template = $params['enhanceTemplate'] ?? '';

        if (empty($prompt)) {
            return $this->error('Prompt is required.');
        }

        // Determine system prompt — DB is single source of truth
        if (!empty($enhance_template)) {
            // Enhancement template from dropdown (user-selected style)
            $system_prompt = $enhance_template;
        }
        else {
            // Check for DB override
            $system_prompt = $this->get_prompt_override($user->id, 'video', 'enhance');
            if (empty($system_prompt)) {
                return $this->error(
                    'No active prompt found for video enhance. '
                    . 'Please deactivate and reactivate the plugin to seed defaults, '
                    . 'or create one in Settings → Prompt Editor.',
                    500
                );
            }
        }

        // Build user message with optional style and template context
        $user_text = "Brief: {$prompt}";
        if ($style) {
            $user_text .= "\nStyle: {$style}";
        }

        // Inject scene template (fishbone structure) if selected
        if ($scene_template) {
            $user_text .= "\n\n--- SCENE FRAMEWORK (follow this structure) ---\n{$scene_template}";
        }

        // Inject recipe template (content type) if selected
        if ($recipe_template) {
            $user_text .= "\n\n--- CONTENT RECIPE (use this style/pattern) ---\n{$recipe_template}";
        }

        try {
            $messages = array(
                    array('role' => 'system', 'content' => $system_prompt),
                    array('role' => 'user', 'content' => $user_text),
            );

            $options = array(
                'max_tokens' => (int)PCM_Settings::get('token_budget_video', 4096),
                'user_id' => $user->id,
            );

            // Pass through the Menu Intelligence model selected in Settings
            if ($model_id) {
                $options['model'] = $model_id;
            }

            $result = PCM_LLM::invoke($messages, $options);

            $enhanced = trim($result['content'] ?? '');

            return $this->success(array(
                'enhanced_prompt' => $enhanced,
            ));

        }
        catch (\Exception $e) {
            return $this->error('Prompt enhancement failed: ' . $e->getMessage(), 500);
        }
    }

    // ========================================
    // Prompt Composition (bracket-filling)
    // ========================================

    /**
     * Compose a video prompt by filling in framework bracket placeholders.
     *
     * Takes a scene framework template with [...] placeholders, a product brief,
     * and optionally a content recipe. Uses LLM to fill in every bracket.
     * Returns ONLY the filled-in framework(s) — ready for video AI consumption.
     *
     * Body: {
     *   brief: string (required),
     *   sceneFramework: string (required — the template with [...] brackets),
     *   recipeTemplate?: string (content recipe / style guide),
     *   count?: int (1-10, default 1 — number of unique angle variations),
     *   brandId?: int,
     *   modelId?: string (LLM model for composition, NOT the video model)
     * }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function compose_prompt(WP_REST_Request $request)
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        // Accept both naming conventions (brief / productBrief)
        $brief = $params['brief'] ?? $params['productBrief'] ?? '';
        $scene_framework = $params['sceneFramework'] ?? '';
        $recipe_template = $params['recipeTemplate'] ?? '';
        $count = min(10, max(1, (int)($params['count'] ?? 1)));
        $brand_id = $params['brandId'] ?? null;
        $model_id = $params['modelId'] ?? null;

        if (empty($brief)) {
            return $this->error('Product brief is required.');
        }
        if (empty($scene_framework)) {
            return $this->error('Scene framework template is required.');
        }

        // Build system prompt — DB is single source of truth, no hardcoded fallback
        $system_prompt = $this->get_prompt_override($user->id, 'video', 'compose');
        if (empty($system_prompt)) {
            return $this->error(
                'No active prompt found for video compose. '
                . 'Please deactivate and reactivate the plugin to seed defaults, '
                . 'or create one in Settings → Prompt Editor.',
                500
            );
        }

        // For multiple angles, instruct LLM to generate N unique variants
        if ($count > 1) {
            $system_prompt .= "\n\nGenerate exactly {$count} unique creative variations of the filled-in framework.";
            $system_prompt .= "\nEach variation must have a different creative angle (different hook, emotion, scenario, or narrative).";
            $system_prompt .= "\nReturn a JSON object with a \"prompts\" array where each element is the complete filled-in framework as a string.";
        }
        else {
            $system_prompt .= "\n\nReturn the filled-in framework as plain text. Nothing else.";
        }

        // Build user message
        $user_content = array();
        $user_text = "PRODUCT BRIEF:\n{$brief}";
        $user_text .= "\n\nSCENE FRAMEWORK (fill in every [...] below):\n{$scene_framework}";

        if ($recipe_template) {
            $user_text .= "\n\nCONTENT RECIPE (use this as style/pattern guide):\n{$recipe_template}";
        }

        // Optionally attach brand logo
        if ($brand_id) {
            $logo_url = $this->get_brand_logo_url($brand_id);
            if ($logo_url) {
                $user_text .= "\n\nA brand logo is attached for reference.";
                $user_content[] = array('type' => 'text', 'text' => $user_text);
                $user_content[] = array(
                    'type' => 'image_url',
                    'image_url' => array('url' => $logo_url, 'detail' => 'auto'),
                );
            }
            else {
                $user_content = $user_text;
            }
        }
        else {
            $user_content = $user_text;
        }

        try {
            $messages = array(
                    array('role' => 'system', 'content' => $system_prompt),
                    array('role' => 'user', 'content' => $user_content),
            );

            $options = array(
                'max_tokens' => (int)PCM_Settings::get('token_budget_video', 4096),
                'user_id' => $user->id,
            );

            if ($model_id) {
                $options['model'] = $model_id;
            }

            // For multiple angles, use JSON structured output
            if ($count > 1) {
                $options['response_format'] = array(
                    'type' => 'json_schema',
                    'json_schema' => array(
                        'name' => 'composed_prompts',
                        'strict' => true,
                        'schema' => array(
                            'type' => 'object',
                            'properties' => array(
                                'prompts' => array(
                                    'type' => 'array',
                                    'items' => array('type' => 'string'),
                                ),
                            ),
                            'required' => array('prompts'),
                        ),
                    ),
                );
            }

            $result = PCM_LLM::invoke($messages, $options);
            $content = trim($result['content'] ?? '');

            if ($count > 1) {
                // Parse JSON response for multi-angle
                // Defensive: some providers wrap JSON in markdown code blocks.
                $clean_json = PCM_LLM::extract_json($content);
                $parsed = json_decode($clean_json !== '' ? $clean_json : '{}', true);
                $prompts = $parsed['prompts'] ?? array($content);
            }
            else {
                // Single angle: the response IS the filled-in framework
                $prompts = array($content);
            }

            return $this->success(array('prompts' => $prompts));

        }
        catch (\Exception $e) {
            return $this->error('Prompt composition failed: ' . $e->getMessage(), 500);
        }
    }

    // ========================================
    // Video Generation
    // ========================================

    /**
     * Generate a video (async — returns a job ID for status polling).
     *
     * Body: {
     *   prompt: string,
     *   modelId: string,
     *   provider: string,
     *   duration?: string,
     *   format?: string,
     *   brandId?: int,
     *   projectId?: int
     * }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    /**
     * POST /video/generate-task — create an async Kie.ai video task.
     * Same validation/normalization as generate(); returns { taskId, prompt }
     * immediately so shared-hosting request timeouts can't kill the run.
     */
    public function create_generation_task(WP_REST_Request $request)
    {
        $user   = $this->get_current_pcm_user();
        $params = $request->get_json_params() ?? array();

        $prompt   = $params['prompt'] ?? '';
        $model_id = $params['modelId'] ?? $params['model'] ?? null;
        $provider = $params['provider'] ?? null;
        $duration = $params['duration'] ?? null;
        $format   = $params['format'] ?? '16:9';
        $input_urls = $params['inputUrls'] ?? [];
        if (empty($input_urls) && !empty($params['inputUrl'])) {
            $input_urls = [$params['inputUrl']];
        }

        if (empty($prompt)) {
            return $this->error('Prompt is required.');
        }
        if (empty($model_id)) {
            return $this->error('Model is required. Send "model" or "modelId" in the request body.');
        }
        if ($provider !== 'kieai') {
            return $this->error('Async generation is only available for Kie.ai models.', 400, 'pcm_async_unsupported');
        }

        $format_map   = array('landscape' => '16:9', 'portrait' => '9:16', 'square' => '1:1');
        $aspect_ratio = $format_map[strtolower((string) $format)] ?? $format;

        try {
            $api_key  = $this->get_provider_api_key($provider, $user->id);
            $instance = PCM_Provider_Registry::get($provider, $api_key);
            $task     = $instance->create_video_task((string) $model_id, array(
                'prompt'      => $prompt,
                'aspectRatio' => $aspect_ratio,
                'format'      => $format,
                'duration'    => $duration,
                'inputUrls'   => $input_urls,
            ));

            return $this->success(array(
                'taskId' => (string) $task['taskId'],
                'prompt' => $prompt,
            ), 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500, 'pcm_generation_error');
        }
    }

    /**
     * POST /video/task-result — poll an async Kie.ai video task.
     * On completion runs the SAME fail-soft persistence tail as generate():
     * media-library store + pcm asset row, response always carries a playable
     * URL. processing/failed mirror the image module's contract.
     */
    public function get_generation_result(WP_REST_Request $request)
    {
        $user   = $this->get_current_pcm_user();
        $params = $request->get_json_params() ?? array();

        $task_id  = sanitize_text_field($params['taskId'] ?? '');
        $model_id = sanitize_text_field($params['modelId'] ?? $params['model'] ?? '');
        $provider = sanitize_text_field($params['provider'] ?? 'kieai');
        $prompt   = $params['prompt'] ?? '';
        $duration = $params['duration'] ?? null;
        $format   = $params['format'] ?? '16:9';

        if ($task_id === '') {
            return $this->error('taskId is required.', 400, 'pcm_missing_task');
        }

        $format_map   = array('landscape' => '16:9', 'portrait' => '9:16', 'square' => '1:1');
        $aspect_ratio = $format_map[strtolower((string) $format)] ?? $format;

        try {
            $api_key = $this->get_provider_api_key($provider, $user->id);
            $status  = PCM_Kie_Api::get_task_status($api_key, $task_id, $model_id ?: null);

            if (($status['status'] ?? '') === 'failed') {
                return $this->success(array(
                    'status' => 'failed',
                    'error'  => (string) ($status['error'] ?? __('Generation failed.', 'power-creatives')),
                ));
            }

            if (($status['status'] ?? '') !== 'completed') {
                return $this->success(array(
                    'status'   => 'processing',
                    'progress' => $status['progress'] ?? null,
                ));
            }

            $video_url = $status['url'] ?? ($status['urls'][0] ?? '');
            if (empty($video_url)) {
                return $this->success(array(
                    'status' => 'failed',
                    'error'  => __('The model completed without returning a video.', 'power-creatives'),
                ));
            }

            // Fail-soft persistence — mirrors generate(): storage problems
            // never block the playable external URL from reaching the user.
            $attachment_id = null;
            try {
                $saved = $this->service->persist_video($video_url, $model_id, $provider, $prompt, array(
                    'duration'     => $duration,
                    'format'       => $format,
                    'aspect_ratio' => $aspect_ratio,
                ));
                $video_url     = $saved['url'];
                $attachment_id = $saved['id'];
            } catch (\Exception $storage_error) {
                error_log('PCM Video Storage (async): failed to persist — ' . $storage_error->getMessage());
            }

            $asset_id = null;
            try {
                $asset = $this->service->save_asset($user->id, $prompt, $model_id, $provider, $video_url, array(
                    'duration'     => $duration,
                    'format'       => $format,
                    'aspect_ratio' => $aspect_ratio,
                ));
                $asset_id = $asset['id'];
            } catch (\Exception $asset_error) {
                error_log('PCM Video Asset (async): failed to save to pcm_assets — ' . $asset_error->getMessage());
            }

            return $this->success(array(
                'status'       => 'completed',
                'success'      => true,
                'url'          => $video_url,
                'attachmentId' => $attachment_id,
                'assetId'      => $asset_id,
            ));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500, 'pcm_generation_error');
        }
    }

    public function generate(WP_REST_Request $request)
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();



        $prompt = $params['prompt'] ?? '';
        $model_id = $params['modelId'] ?? $params['model'] ?? null;
        // Provider: required — sent by the frontend from model.provider (DB).
        // See: docs/architecture-vision.md → "Provider Routing — Data-Driven"
        $provider = $params['provider'] ?? null;
        // Duration null = "smart" mode → input mapper resolves from model's defaultDuration.
        // An explicit value (e.g. "5", "10") is passed through and validated by the input mapper
        // against the model's validDurations list.
        $duration = $params['duration'] ?? null;
        $format = $params['format'] ?? '16:9';
        // Frontend sends 'inputUrl' (singular string) or 'inputUrls' (array).
        $input_urls = $params['inputUrls'] ?? [];
        if (empty($input_urls) && !empty($params['inputUrl'])) {
            $input_urls = [$params['inputUrl']];
        }

        // ── Fail-fast validation ────────────────────────────────────
        // Every required param is checked here at the API boundary.
        // No silent defaults — if something is wrong, we tell the caller
        // exactly what's missing so they can fix it.
        if (empty($prompt)) {
            return $this->error('Prompt is required.');
        }
        if (empty($model_id)) {
            return $this->error('Model is required. Send "model" or "modelId" in the request body.');
        }
        // Provider is required — the frontend reads model.provider from the model
        // registry (DB) and sends it with every request. No guessing, no heuristics.
        // See: docs/architecture-vision.md → "Provider Routing — Data-Driven"
        if (empty($provider)) {
            return $this->error('Provider is required. The frontend must send model.provider from the model registry.');
        }

        // Normalize human-readable format to API aspect ratio.
        // Frontend sends 'landscape'/'portrait', but Kie.ai expects '16:9'/'9:16'.
        $format_map = array(
            'landscape' => '16:9',
            'portrait' => '9:16',
            'square' => '1:1',
        );
        $aspect_ratio = $format_map[strtolower($format)] ?? $format;

        try {
            // Route through service → provider registry → provider API.
            // The provider handles task creation AND wait-for-completion
            // (polls up to 600s for video generation).
            $result = $this->service->generate_video(
                $provider,
                $model_id,
                $prompt,
            [
                'aspectRatio' => $aspect_ratio,
                'format' => $format,
                'duration' => $duration,
                'inputUrls' => $input_urls,
            ],
                $user->id
            );

            // Attempt to persist video to WP Media Library.
            // This is wrapped in its own try/catch so storage failure
            // never blocks the video from being shown to the user.
            $video_url = $result['url'];
            $attachment_id = null;

            try {
                $saved = $this->service->persist_video(
                    $result['url'],
                    $model_id,
                    $provider,
                    $prompt,
                [
                    'duration' => $duration,
                    'format' => $format,
                    'aspect_ratio' => $aspect_ratio,
                ]
                );
                $video_url = $saved['url'];
                $attachment_id = $saved['id'];
            }
            catch (\Exception $storage_error) {
                // Log but don't fail — the video is still accessible via external URL
                error_log('PCM Video Storage: failed to persist — ' . $storage_error->getMessage());
            }

            // Save to pcm_assets table so we have a real DB ID for save-to-project.
            // This mirrors the pattern used by Image module (PCM_Image_Service::save_asset).
            // If this fails, we log but don't block — the video is still playable.
            $asset_id = null;
            try {
                $asset = $this->service->save_asset(
                    $user->id,
                    $prompt,
                    $model_id,
                    $provider,
                    $video_url,
                    [
                        'duration'     => $duration,
                        'format'       => $format,
                        'aspect_ratio' => $aspect_ratio,
                    ]
                );
                $asset_id = $asset['id'];
            }
            catch (\Exception $asset_error) {
                error_log('PCM Video Asset: failed to save to pcm_assets — ' . $asset_error->getMessage());
            }

            // Response always includes a working video URL.
            // If storage succeeded: url = WP Media Library (permanent).
            // If storage failed: url = external provider URL (temporary, but video still plays).
            return $this->success(array(
                'success' => true,
                'url' => $video_url,
                'externalUrl' => $result['url'],
                'attachmentId' => $attachment_id,
                'assetId' => $asset_id,
                'model' => $model_id,
            ));

        }
        catch (\Exception $e) {
            error_log('[PCM Video] Generation failed — model=' . $model_id . ' provider=' . $provider . ' error=' . $e->getMessage());
            return $this->error('Video generation failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Check the status of a video generation job.
     *
     * Body: { jobId: string }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function check_status(WP_REST_Request $request)
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        $job_id = $params['jobId'] ?? '';
        // Provider is required — same principle as generate().
        // Note: This endpoint currently only supports Kie.ai (uses PCM_Kie_Api),
        // since Google Veo handles polling synchronously within generate_video().
        $provider = $params['provider'] ?? null;
        $model_id = $params['modelId'] ?? null;

        if (empty($job_id)) {
            return $this->error('jobId is required.');
        }
        if (empty($provider)) {
            return $this->error('Provider is required. The frontend must send model.provider from the model registry.');
        }

        try {
            $api_key = $this->get_provider_api_key($provider, $user->id);

            // Poll task status via the appropriate API layer.
            // Currently only Kie.ai uses async polling; Google Veo polls
            // synchronously in generate_video() and never reaches this endpoint.
            $status = PCM_Kie_Api::get_task_status($api_key, $job_id, $model_id);

            return $this->success(array(
                'jobId' => $job_id,
                'status' => $status['status'],
                'progress' => $status['progress'] ?? 0,
                'url' => $status['url'] ?? null,
                'urls' => $status['urls'] ?? null,
                'error' => $status['error'] ?? null,
            ));

        }
        catch (\Exception $e) {
            return $this->error('Status check failed: ' . $e->getMessage(), 500);
        }
    }

    // ========================================
    // Capabilities
    // ========================================

    /**
     * Get video generation capabilities for available models.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_capabilities(WP_REST_Request $request)
    {
        // Static capabilities map from the provider registry
        $capabilities = array(
            'kie-video-gen' => array(
                'provider' => 'kieai',
                'supportedFormats' => array('16:9', '9:16', '1:1'),
                'validDurations' => array('5', '10'),
                'defaultDuration' => '5',
            ),
        );

        // Filter to models the user actually has access to
        $user = $this->get_current_pcm_user();
        global $wpdb;

        $models_table = PCM_Schema::table('models');
        $video_models = $wpdb->get_results($wpdb->prepare(
            "SELECT modelId, provider, displayName FROM $models_table WHERE userId = %d AND canGenerateVideo = 1 AND isEnabled = 1",
            $user->id
        ));

        $result = array();
        foreach ($video_models ?: array() as $model) {
            $caps = $capabilities[$model->modelId] ?? array(
                'provider' => $model->provider,
                'supportedFormats' => array(),
                'validDurations' => array(),
                'defaultDuration' => null,
            );

            $result[$model->modelId] = array_merge($caps, array(
                'displayName' => $model->displayName,
            ));
        }

        return $this->success($result);
    }

}
