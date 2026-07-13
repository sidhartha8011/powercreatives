<?php
/**
 * Image REST Controller
 *
 * Thin HTTP layer for the Image module. Handles route definitions,
 * input validation and response formatting. All business logic lives
 * in PCM_Image_Service.
 *
 * Endpoints:
 *   POST /image/concepts               → suggest_concepts()
 *   POST /image/generate               → generate_single()
 *   POST /image/generate-batch         → generate_batch()  (SSE)
 *   POST /image/edit                   → edit_image()
 *   POST /image/upscale                → upscale()         (stub)
 *   POST /image/suggestions            → generate_suggestions()
 *   POST /image/context-suggestions    → generate_context_suggestions()
 *   POST /image/optimize-brief         → optimize_brief()
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Image extends PCM_REST_Base
{
    // Work module — usable by non-admin team members (assigned access).
    protected string $default_capability = 'edit_posts';
    // Per-delivery module grant ids (see PCM_REST_Base::$module_grant_keys).
    protected array $module_grant_keys = array('image', 'ads');


    /**
     * Service instance — holds all business logic.
     *
     * @var PCM_Image_Service
     */
    private PCM_Image_Service $service;

    /**
     * Constructor — inject service dependency.
     */
    public function __construct()
    {
        $this->service = new PCM_Image_Service();
    }

    /**
     * Define all image routes.
     *
     * @return array
     */
    protected function routes(): array
    {
        return array(
            // Core generation endpoints
                array('POST', '/image/concepts', 'suggest_concepts'),
                array('POST', '/image/generate', 'generate_single'),
                // Async pair for Kie.ai models — shared hosting kills the
                // blocking generate poll loop, so: create task, poll cheaply.
                array('POST', '/image/generate-task', 'create_generation_task'),
                array('POST', '/image/task-result', 'get_generation_result'),
                array('POST', '/image/generate-batch', 'generate_batch'),
                array('POST', '/image/edit', 'edit_image'),
                array('POST', '/image/edit-task', 'create_edit_task'),
                array('POST', '/image/upscale', 'upscale'),

            // Suggestion / optimization endpoints (used by ImageModule frontend)
                array('POST', '/image/suggestions', 'generate_suggestions'),
                array('POST', '/image/context-suggestions', 'generate_context_suggestions'),
                array('POST', '/image/optimize-brief', 'optimize_brief'),
        );
    }

    // =========================================================================
    // CONCEPT SUGGESTIONS
    // =========================================================================

    /**
     * POST /image/concepts — Suggest creative concepts for image generation.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function suggest_concepts(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params() ?? array();

        $prompt = sanitize_text_field($params['prompt'] ?? '');
        $count = absint($params['count'] ?? 3);
        $style = sanitize_text_field($params['style'] ?? '');
        $brand_context = $params['brandContext'] ?? null;
        $ref_images = $params['referenceImages'] ?? array();
        $module = sanitize_text_field($params['module'] ?? 'image');
        $model_id = sanitize_text_field($params['modelId'] ?? '');

        if (empty($prompt)) {
            return $this->error('Prompt is required.', 400, 'pcm_missing_prompt');
        }

        // Look up prompt override from prompt_overrides table
        $system_prompt = $this->get_prompt_override($user->id, 'image', 'concept_suggestions');
        $user_template = $this->get_prompt_override($user->id, 'image', 'concept_suggestions_user');

        try {
            $concepts = $this->service->suggest_concepts(
                $user->id,
                $prompt,
                $count,
                $style,
                $brand_context,
                $ref_images,
                $system_prompt,
                $model_id,
                $user_template
            );

            return $this->success(array('concepts' => $concepts));
        }
        catch (\Exception $e) {
            return $this->error($e->getMessage(), 500, 'pcm_service_error');
        }
    }

    // =========================================================================
    // IMAGE GENERATION
    // =========================================================================

    /**
     * POST /image/generate — Generate a single image.
     *
     * Provider is passed explicitly from frontend (selected model's provider
     * from the unified Model Registry). No heuristic fallback.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function generate_single(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Verified: php_max_execution_time=1200, polling timeout=800s.
        // Ensure PHP doesn't kill the process during long marketplace polls.
        set_time_limit(900);

        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params() ?? array();

        $prompt = sanitize_text_field($params['prompt'] ?? '');
        // Fallback mirrors PCM_Strategy_Image: dall-e-3 is retired on newer API
        // accounts ("model does not exist", confirmed live on this install).
        $model_id = sanitize_text_field($params['model'] ?? $params['modelId'] ?? 'gpt-image-1-mini');
        $provider = sanitize_text_field($params['provider'] ?? '');

        if (empty($prompt)) {
            return $this->error('Prompt is required.', 400, 'pcm_missing_prompt');
        }

        if (empty($provider)) {
            return $this->error('Provider is required. Pass provider alongside model ID.', 400, 'pcm_missing_provider');
        }

        // ── Resolve final prompt template ──────────────────────────
        // When brandContext is provided, wrap the raw prompt with brand
        // variables via the editable "Final Prompt" template. If no
        // brandContext is sent, the prompt passes through unchanged
        // (backward-compatible).
        $prompt = $this->resolve_final_brand_prompt($user, $prompt, $params);

        // Override the prompt in params so save_asset() stores the resolved version
        $params['prompt'] = $prompt;

        try {
            $api_key = $this->get_provider_api_key($provider, $user->id);
            $image_url = $this->service->generate_image($model_id, $provider, $api_key, $params);
            $wp_url = $this->service->store_to_media_library($image_url, $user->id);
            $asset = $this->service->save_asset($user->id, $prompt, $model_id, $provider, $wp_url, $params);

            return $this->success($asset, 201);
        }
        catch (\Exception $e) {
            return $this->error($e->getMessage(), 500, 'pcm_generation_error');
        }
    }

    /**
     * Resolve the brand "Final Prompt" template around a raw brief. Shared by
     * the sync (generate_single) and async (create_generation_task) paths so
     * both store/send the identical resolved prompt.
     *
     * @param object $user   PCM user.
     * @param string $prompt Raw (sanitized) brief.
     * @param array  $params Request params (reads brandContext).
     * @return string Resolved prompt (unchanged when no brandContext).
     */
    private function resolve_final_brand_prompt(object $user, string $prompt, array $params): string
    {
        $brand_context = $params['brandContext'] ?? null;
        if (empty($brand_context)) {
            return $prompt;
        }
        $final_template = $this->get_prompt_override($user->id, 'image', 'final_prompt');
        if (!$final_template) {
            $defaults = PCM_Image_Service::get_default_prompts();
            $final_template = $defaults['final_prompt'];
        }
        $vars = $this->service->build_final_prompt_context($brand_context);
        $vars['brief'] = $prompt;
        return $this->service->resolve_final_prompt($final_template, $vars);
    }

    /**
     * POST /image/generate-task — create an async Kie.ai generation task.
     *
     * Returns { taskId, prompt } immediately (the resolved prompt is echoed
     * so the frontend can send it back to /image/task-result for storage).
     * Exists because the blocking /image/generate poll loop gets killed by
     * shared hosts' request timeouts on slow models (observed: GPT Image
     * variations failing with opaque 500s / slots stuck "Generating…").
     */
    public function create_generation_task(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user   = $this->get_current_pcm_user();
        $params = $request->get_json_params() ?? array();

        $prompt   = sanitize_text_field($params['prompt'] ?? '');
        $model_id = sanitize_text_field($params['model'] ?? $params['modelId'] ?? '');
        $provider = sanitize_text_field($params['provider'] ?? '');

        if (empty($prompt)) {
            return $this->error('Prompt is required.', 400, 'pcm_missing_prompt');
        }
        if ($provider !== 'kieai') {
            return $this->error('Async generation is only available for Kie.ai models.', 400, 'pcm_async_unsupported');
        }

        $prompt = $this->resolve_final_brand_prompt($user, $prompt, $params);
        $params['prompt'] = $prompt;

        try {
            $api_key  = $this->get_provider_api_key($provider, $user->id);
            $instance = PCM_Provider_Registry::get($provider, $api_key);
            $task     = $instance->create_image_task($model_id, $params);

            return $this->success(array(
                'taskId' => (string) $task['taskId'],
                'prompt' => $prompt,
            ), 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500, 'pcm_generation_error');
        }
    }

    /**
     * POST /image/edit-task — create an async Kie.ai image-EDIT task.
     * Same contract as create_generation_task; poll /image/task-result with
     * storageContext: 'image-edit'.
     */
    public function create_edit_task(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user   = $this->get_current_pcm_user();
        $params = $request->get_json_params() ?? array();

        $image_url = esc_url_raw($params['imageUrl'] ?? '');
        $prompt    = sanitize_text_field($params['prompt'] ?? '');
        $model_id  = sanitize_text_field($params['model'] ?? $params['modelId'] ?? '');
        $provider  = sanitize_text_field($params['provider'] ?? '');
        if (!empty($params['referenceImageUrl'])) {
            $params['referenceImageUrl'] = esc_url_raw($params['referenceImageUrl']);
        }

        if (empty($image_url) || empty($prompt)) {
            return $this->error('imageUrl and prompt are required.', 400, 'pcm_missing_params');
        }
        if ($provider !== 'kieai') {
            return $this->error('Async editing is only available for Kie.ai models.', 400, 'pcm_async_unsupported');
        }

        $params['imageUrl'] = $image_url;
        $params['prompt']   = $prompt;

        try {
            $api_key  = $this->get_provider_api_key($provider, $user->id);
            $instance = PCM_Provider_Registry::get($provider, $api_key);
            $task     = $instance->create_edit_task($model_id, $params);

            return $this->success(array(
                'taskId' => (string) $task['taskId'],
                'prompt' => $prompt,
            ), 201);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500, 'pcm_edit_error');
        }
    }

    /**
     * POST /image/task-result — poll an async Kie.ai task.
     *
     * processing → { status: 'processing', progress? }
     * failed     → { status: 'failed', error }
     * completed  → stores the image (media library + wp_pcm_image_assets,
     *              same tail as generate_single) and returns
     *              { status: 'completed', asset }.
     */
    public function get_generation_result(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user   = $this->get_current_pcm_user();
        $params = $request->get_json_params() ?? array();

        $task_id  = sanitize_text_field($params['taskId'] ?? '');
        $model_id = sanitize_text_field($params['model'] ?? $params['modelId'] ?? '');
        $provider = sanitize_text_field($params['provider'] ?? 'kieai');
        $prompt   = sanitize_text_field($params['prompt'] ?? '');

        if ($task_id === '') {
            return $this->error('taskId is required.', 400, 'pcm_missing_task');
        }

        try {
            $api_key = $this->get_provider_api_key($provider, $user->id);
            $status  = PCM_Kie_Api::get_task_status($api_key, $task_id, $model_id);

            if (($status['status'] ?? '') === 'completed') {
                $image_url = $status['url'] ?? ($status['urls'][0] ?? '');
                if (empty($image_url)) {
                    // Upstream "success" without an image (e.g. silent safety
                    // block) — surface as a failure instead of a blank card.
                    return $this->success(array(
                        'status' => 'failed',
                        'error'  => __('The model completed without returning an image.', 'power-creatives'),
                    ));
                }
                $params['prompt'] = $prompt;
                // Optional storage context (e.g. 'image-edit' from the async
                // edit flow) — mirrors the sync handlers' filename prefixes.
                $storage_context = sanitize_key($params['storageContext'] ?? '');
                $wp_url = $storage_context !== ''
                    ? $this->service->store_to_media_library($image_url, $user->id, $storage_context)
                    : $this->service->store_to_media_library($image_url, $user->id);
                $asset  = $this->service->save_asset($user->id, $prompt, $model_id, $provider, $wp_url, $params);
                return $this->success(array('status' => 'completed', 'asset' => $asset));
            }

            if (($status['status'] ?? '') === 'failed') {
                return $this->success(array(
                    'status' => 'failed',
                    'error'  => (string) ($status['error'] ?? __('Generation failed.', 'power-creatives')),
                ));
            }

            return $this->success(array(
                'status'   => 'processing',
                'progress' => $status['progress'] ?? null,
            ));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500, 'pcm_generation_error');
        }
    }

    /**
     * POST /image/generate-batch — Generate images for multiple prompts via SSE stream.
     *
     * Accepts: { prompts: [{ name, prompt }], model, provider, ... }
     *
     * @param WP_REST_Request $request
     * @return void (streams SSE)
     */
    public function generate_batch(WP_REST_Request $request): void
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params() ?? array();

        $prompts = $params['prompts'] ?? array();
        // Fallback mirrors PCM_Strategy_Image: dall-e-3 is retired on newer API
        // accounts ("model does not exist", confirmed live on this install).
        $model_id = sanitize_text_field($params['model'] ?? $params['modelId'] ?? 'gpt-image-1-mini');
        $provider = sanitize_text_field($params['provider'] ?? '');

        if (empty($prompts) || !is_array($prompts)) {
            PCM_SSE::start(5);
            PCM_SSE::send_error('No prompts provided.');
            PCM_SSE::send_done();
            return;
        }

        if (empty($provider)) {
            PCM_SSE::start(5);
            PCM_SSE::send_error('Provider is required.');
            PCM_SSE::send_done();
            return;
        }

        try {
            $api_key = $this->get_provider_api_key($provider, $user->id);
        }
        catch (\Exception $e) {
            PCM_SSE::start(5);
            PCM_SSE::send_error($e->getMessage());
            PCM_SSE::send_done();
            return;
        }

        $total = count($prompts);
        PCM_SSE::start(300);

        foreach ($prompts as $index => $item) {
            $item_prompt = sanitize_text_field($item['prompt'] ?? $item['description'] ?? '');
            $item_name = sanitize_text_field($item['name'] ?? "Image " . ($index + 1));

            if (empty($item_prompt)) {
                PCM_SSE::send('image_error', array(
                    'index' => $index,
                    'name' => $item_name,
                    'message' => 'Empty prompt.',
                ));
                continue;
            }

            PCM_SSE::send_progress(array(
                'current' => $index + 1,
                'total' => $total,
                'name' => $item_name,
            ));

            try {
                $merged = array_merge($params, array('prompt' => $item_prompt));
                $img_url = $this->service->generate_image($model_id, $provider, $api_key, $merged);
                $wp_url = $this->service->store_to_media_library($img_url, $user->id);
                $asset = $this->service->save_asset($user->id, $item_prompt, $model_id, $provider, $wp_url, $merged);

                PCM_SSE::send('image_result', array(
                    'index' => $index,
                    'name' => $item_name,
                    'asset' => $asset,
                ));
            }
            catch (\Exception $e) {
                // Per-item failure — continue with remaining prompts
                PCM_SSE::send('image_error', array(
                    'index' => $index,
                    'name' => $item_name,
                    'message' => $e->getMessage(),
                ));
            }
        }

        PCM_SSE::send_done();
    }

    // =========================================================================
    // IMAGE EDITING
    // =========================================================================

    /**
     * POST /image/edit — Edit an existing image using an inpaint/edit model.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function edit_image(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params() ?? array();

        $image_url = esc_url_raw($params['imageUrl'] ?? '');
        $prompt = sanitize_text_field($params['prompt'] ?? '');
        $model_id = sanitize_text_field($params['model'] ?? $params['modelId'] ?? 'dall-e-2');
        $provider = sanitize_text_field($params['provider'] ?? '');
        
        if (!empty($params['referenceImageUrl'])) {
            $params['referenceImageUrl'] = esc_url_raw($params['referenceImageUrl']);
        }

        if (empty($image_url) || empty($prompt)) {
            return $this->error('imageUrl and prompt are required.', 400, 'pcm_missing_params');
        }

        if (empty($provider)) {
            return $this->error('Provider is required.', 400, 'pcm_missing_provider');
        }

        try {
            $api_key = $this->get_provider_api_key($provider, $user->id);
            $result = $this->service->edit_image($model_id, $provider, $api_key, $params);
            $wp_url = $this->service->store_to_media_library($result, $user->id, 'image-edit');
            $asset = $this->service->save_asset($user->id, $prompt, $model_id, $provider, $wp_url, $params);

            return $this->success($asset, 201);
        }
        catch (\Exception $e) {
            return $this->error($e->getMessage(), 500, 'pcm_edit_error');
        }
    }

    /**
     * POST /image/upscale — Upscale an existing image.
     *
     * Stub implementation — returns original URL until provider support is added.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function upscale(WP_REST_Request $request): WP_REST_Response
    {
        return $this->error(
            'Upscaling will be available in a future update.',
            501,
            'pcm_not_implemented'
        );
    }

    // =========================================================================
    // PROMPT SUGGESTIONS
    // =========================================================================

    /**
     * POST /image/suggestions — Generate AI prompt suggestions from a product brief.
     *
     * Input: { brief, count?, detailLevel?, brandName?, brandSummary?,
     *           seasonEvent?, campaignTheme?, url?, referenceImages? }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function generate_suggestions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params() ?? array();

        $brief = sanitize_text_field($params['brief'] ?? $params['input'] ?? '');
        $count = absint($params['count'] ?? 3);
        $model_id = sanitize_text_field($params['modelId'] ?? '');

        if (empty($brief)) {
            return $this->error('Brief is required.', 400, 'pcm_missing_brief');
        }

        // Load prompt overrides — system (role:system) + user template (role:user)
        $system_prompt = $this->get_prompt_override($user->id, 'image', 'prompt_suggestions_system');
        $user_template = $this->get_prompt_override($user->id, 'image', 'prompt_suggestions');

        try {
            $suggestions = $this->service->suggest_prompts(
                $user->id,
                $brief,
                $count,
                $params,
                $system_prompt,
                $model_id,
                $user_template
            );

            return $this->success(array('suggestions' => $suggestions));
        }
        catch (\Exception $e) {
            return $this->error($e->getMessage(), 500, 'pcm_suggestions_error');
        }
    }

    /**
     * POST /image/context-suggestions — Generate prompt suggestions from brand/URL context.
     *
     * Input: { brandId?, brandName?, brandSummary?, url?, seasonEvent?,
     *           campaignTheme?, count? }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function generate_context_suggestions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params() ?? array();

        $count = absint($params['count'] ?? 4);
        $model_id = sanitize_text_field($params['modelId'] ?? '');

        // At least one context signal must be present
        $has_context = !empty($params['brandId'])
            || !empty($params['brandName'])
            || !empty($params['url'])
            || !empty($params['seasonEvent'])
            || !empty($params['campaignTheme']);

        if (!$has_context) {
            return $this->error(
                'At least one context signal is required (brand, url, seasonEvent or campaignTheme).',
                400,
                'pcm_missing_context'
            );
        }

        // Load prompt overrides — system (role:system) + user template (role:user)
        $system_prompt = $this->get_prompt_override($user->id, 'image', 'context_suggestions_system');
        $user_template = $this->get_prompt_override($user->id, 'image', 'context_suggestions');

        try {
            $suggestions = $this->service->suggest_context_prompts(
                $user->id,
                $count,
                $params,
                $system_prompt,
                $model_id,
                $user_template
            );

            return $this->success(array('suggestions' => $suggestions));
        }
        catch (\Exception $e) {
            return $this->error($e->getMessage(), 500, 'pcm_context_suggestions_error');
        }
    }

    /**
     * POST /image/optimize-brief — Rewrite a product brief for optimal image generation.
     *
     * Input: { brief, brandContext? }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function optimize_brief(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params() ?? array();

        $brief = sanitize_text_field($params['brief'] ?? '');
        $model_id = sanitize_text_field($params['modelId'] ?? '');

        if (empty($brief)) {
            return $this->error('Brief is required.', 400, 'pcm_missing_brief');
        }

        $system_prompt = $this->get_prompt_override($user->id, 'image', 'brief_optimization');
        $user_template = $this->get_prompt_override($user->id, 'image', 'brief_optimization_user');

        try {
            $optimized = $this->service->optimize_brief(
                $user->id,
                $brief,
                $params['brandContext'] ?? null,
                $system_prompt,
                $model_id,
                $user_template
            );

            return $this->success(array('optimizedBrief' => $optimized));
        }
        catch (\Exception $e) {
            return $this->error($e->getMessage(), 500, 'pcm_optimize_error');
        }
    }
}
