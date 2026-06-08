<?php
/**
 * Copy REST Controller
 *
 * Thin router for AI-powered copy generation. Handles HTTP concerns:
 * input validation, SSE lifecycle, response formatting.
 *
 * All business logic delegated to PCM_Copy_Service.
 *
 * Endpoints:
 *   POST   /copy/generate            → generate copy
 *   POST   /copy/regenerate           → regenerate single card
 *   POST   /copy/regenerate-batch     → regenerate multiple cards
 *   POST   /copy/duplicate-audience   → duplicate audience results
 *   POST   /copy/rename-audience      → rename audience
 *   POST   /copy/update-result        → inline edit a result
 *   GET    /copy/jobs/{jobId}          → getJobResults
 *   POST   /copy/scrape-url           → scrape business info from URL
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Copy extends PCM_REST_Base
{

    /**
     * Service instance — holds all business logic.
     *
     * @var PCM_Copy_Service
     */
    private PCM_Copy_Service $service;

    /**
     * Constructor — inject service dependency.
     */
    public function __construct()
    {
        $this->service = new PCM_Copy_Service();
    }

    /**
     * Define copy generation routes.
     *
     * @return array
     */
    protected function routes(): array
    {
        return array(
            // Generation
                array('POST', '/copy/generate', 'generate'),
                array('POST', '/copy/regenerate', 'regenerate_card'),
                array('POST', '/copy/regenerate-batch', 'regenerate_batch'),
                array('POST', '/copy/suggest', 'suggest_items'),

            // Audience operations
                array('POST', '/copy/duplicate-audience', 'duplicate_audience'),
                array('POST', '/copy/rename-audience', 'rename_audience'),

            // Result operations
                array('POST', '/copy/update-result', 'update_result'),
                array('GET', '/copy/jobs/(?P<jobId>\d+)', 'get_job_results'),
                array('POST', '/copy/save-to-project', 'save_to_project'),
                array('GET', '/copy/project/(?P<projectId>\d+)', 'get_project_results'),

            // URL Scraper (for extracting business info from websites)
                array('POST', '/copy/scrape-url', 'scrape_business_info'),
        );
    }

    // ========================================
    // Suggest (Pre-Generate)
    // ========================================

    /**
     * POST /copy/suggest — Pre-generate angles or audiences via LLM.
     *
     * Standalone endpoint for the ✨ sparkle button. Uses the same
     * resolve_angles / resolve_audiences logic (editable prompts from DB)
     * but returns items without starting a full copy generation.
     *
     * Input:  { type: 'angles'|'audiences', count: int, formValues: object }
     * Output: { items: [{ id, name }] }
     */
    public function suggest_items(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        $type = sanitize_text_field($params['type'] ?? '');
        $count = min(10, max(1, (int)($params['count'] ?? 3)));
        $form_values = $params['formValues'] ?? array();
        // Module namespace for prompt resolution ('copy' or 'ads')
        $suggest_module = sanitize_text_field($params['module'] ?? 'copy');

        if (!in_array($type, array('angles', 'audiences'), true)) {
            return $this->error('Invalid type. Must be "angles" or "audiences".');
        }

        try {
            $model_id = $this->service->normalize_model_id($params['modelId'] ?? '', $user->id);

            if ($type === 'angles') {
                // Audience-aware angle generation: sparkle button sends
                // current audiences from sidebar (if any)
                $audiences = $params['audiences'] ?? array();
                $items = $this->service->resolve_angles_for_audiences(
                    array('mode' => 'auto', 'count' => $count),
                    $audiences,
                    $form_values,
                    $model_id,
                    $user->id,
                    $suggest_module
                );
                return $this->success(array('items' => $items));
            }
            else {
                // Audience generation with optional research step
                $use_research = (bool)($params['useResearch'] ?? false);
                $research_model = sanitize_text_field($params['researchModelId'] ?? '');

                $result = $this->service->resolve_audiences(
                    array('mode' => 'auto', 'count' => $count),
                    $form_values,
                    $model_id,
                    $user->id,
                    $use_research,
                    $research_model,
                    $suggest_module
                );
                return $this->success(array(
                    'items' => $result['audiences'],
                    'researched' => $result['researched'],
                    'researchStatus' => $result['researchStatus'] ?? 'not_requested',
                    'researchError' => $result['researchError'] ?? '',
                ));
            }
        }
        catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    // ========================================
    // Main Generate (SSE)
    // ========================================

    /**
     * Main copy generation endpoint.
     *
     * Blocking JSON response — resolves audiences/angles, generates all copy
     * via LLM, stores results in DB, and returns the full result set.
     *
     * The frontend (useCopyGeneration.ts) calls this via fetch() and expects
     * a standard JSON response, not an SSE stream.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function generate(WP_REST_Request $request)
    {
        // Lift PHP's max_execution_time up-front. Audience research (Gemini
        // grounding) + audience/angle generation run several blocking LLM calls
        // BEFORE the SSE stream raises the limit (PCM_SSE::start), so without this
        // a research-enabled run can exceed the default 30s and fatal mid-request.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        try {
            $model_id = $this->service->normalize_model_id($params['modelId'] ?? '', $user->id);
            $copy_types = $params['copyTypes'] ?? array();
            $form_values = $params['formValues'] ?? array();
            $scope = $params['scope'] ?? 'all';
            $scope_filter = $params['scopeFilter'] ?? array();
            // Module namespace for prompt resolution: 'copy' (default) or 'ads'.
            // When the Ads module calls /copy/generate, it passes module='ads'
            // so prompts are resolved from the ads namespace in prompt_overrides.
            $module = sanitize_text_field($params['module'] ?? 'copy');

            // Read research settings from frontend (localStorage-based settings)
            $use_research = (bool)($params['useResearch'] ?? false);
            $research_model = sanitize_text_field($params['researchModelId'] ?? '');

            // Step 1: Resolve audiences (with optional research step)
            $audience_result = $this->service->resolve_audiences(
                $params['audiences'] ?? array(),
                $form_values,
                $model_id,
                $user->id,
                $use_research,
                $research_model,
                $module
            );
            $audiences = $audience_result['audiences'];

            // Step 2: Resolve angles — audience-aware generation
            $angles = $this->service->resolve_angles_for_audiences(
                $params['angles'] ?? array(),
                $audiences,
                $form_values,
                $model_id,
                $user->id,
                $module
            );

            // Step 3: Filter copy types and build task matrix
            $types_to_generate = $this->service->filter_copy_types($copy_types, $scope, $scope_filter);
            $tasks = $this->service->build_task_matrix($types_to_generate, $audiences, $angles, $scope, $scope_filter);

            // [PCM_DIAG — remove after style inconsistency investigation]
            if (defined('WP_DEBUG') && WP_DEBUG) {
                PCM_LLM::write_diagnostic(array(
                    'direction' => 'TASK_MATRIX',
                    'timestamp' => gmdate('Y-m-d H:i:s'),
                    'totalTasks' => count($tasks),
                    'tasks' => array_map(function ($t) {
                    return array(
                    'copyType' => $t['copyType'],
                    'audience' => $t['audience']['name'],
                    'audienceId' => $t['audience']['id'],
                    'angle' => $t['angle']['name'],
                    'angleAudienceId' => $t['angle']['audienceId'] ?? '*',
                    );
                }, $tasks),
                ));
            }

            // Step 4: Create job
            $job_id = $this->service->create_job($user->id, $types_to_generate, $model_id, $params, count($tasks));

            // Step 5: Start SSE stream — progressive results as each LLM call completes.
            // This prevents the 300s PHP timeout by streaming results incrementally.
            // PCM_SSE::start() sets its own time limit (600s) and flushes output.
            PCM_SSE::start(600);

            // Send initial metadata (audiences + angles + job info)
            PCM_SSE::send('init', array(
                'jobId' => $job_id,
                'totalCount' => count($tasks),
                'audiences' => $audiences,
                'angles' => $angles,
                'researchStatus' => $audience_result['researchStatus'] ?? 'not_requested',
                'researchError' => $audience_result['researchError'] ?? '',
            ));

            // Step 6: Execute LLM calls — stream each result as it completes
            $completed = 0;
            $failed = 0;

            foreach ($tasks as $i => $task) {
                // Send progress update before each task
                PCM_SSE::send_progress($i, count($tasks), "Generating copy {$task['copyType']} for {$task['audience']['name']}...");

                try {
                    $output = $this->service->generate_single_copy(
                        $task['copyType'],
                        $task['audience'],
                        $task['angle'],
                        $form_values,
                        $model_id,
                        $user->id,
                        $module
                    );

                    // Store result via service
                    $result_id = $this->service->store_result($job_id, $user->id, $task, $output);
                    $output['id'] = $result_id;
                    $output['jobId'] = $job_id;
                    $output['error'] = null;
                    $output['modelUsed'] = $model_id;
                    $output['createdAt'] = current_time('mysql');

                    // Stream this result to the frontend immediately
                    PCM_SSE::send('result', $output);
                    $completed++;
                }
                catch (\Exception $e) {
                    $failed++;
                    // Stream error result so frontend can display it
                    PCM_SSE::send('result', array(
                        'copyType' => $task['copyType'],
                        'audienceId' => $task['audience']['id'],
                        'audienceName' => $task['audience']['name'],
                        'angleName' => $task['angle']['name'],
                        'error' => $e->getMessage(),
                    ));
                }
            }

            // Step 7: Finalize job
            $this->service->finalize_job($job_id, $completed, $failed, count($tasks));

            // Send final done event with summary
            PCM_SSE::send_done(array(
                'jobId' => $job_id,
                'completedCount' => $completed,
                'failedCount' => $failed,
                'totalCount' => count($tasks),
            ));
        // PCM_SSE::send_done() calls exit — no return needed

        }
        catch (\Exception $e) {
            error_log('[PCM_Copy_Debug] Copy generation failed: ' . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
            // If SSE already started, send error event; otherwise return JSON error
            if (PCM_SSE::is_started()) {
                PCM_SSE::send_error('Copy generation failed: ' . $e->getMessage());
            }
            return $this->error('Copy generation failed: ' . $e->getMessage(), 500);
        }
    }

    // ========================================
    // Regenerate Single Card
    // ========================================

    /**
     * Regenerate a single copy result card.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function regenerate_card(WP_REST_Request $request)
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();
        $result_id = (int)($params['resultId'] ?? 0);

        // Fetch existing result via service
        $existing = $this->service->get_result($result_id, $user->id);
        if (!$existing) {
            return $this->not_found('Copy result');
        }

        try {
            // Get the job for model info
            $job = $this->service->get_job((int)$existing->jobId, $user->id);
            $model_id = $this->service->normalize_model_id($job->modelId ?? '', $user->id);
            $form_values = json_decode($job->formSnapshot ?? '{}', true) ?: array();

            // Merge with provided form values
            if (!empty($params['formValues'])) {
                $form_values = array_merge($form_values, $params['formValues']);
            }

            $audience = array(
                'id' => $existing->audienceId ?? 'default',
                'name' => $existing->audienceName ?? 'General',
            );
            $angle = array('id' => 'regen', 'name' => 'Regeneration');

            $output = $this->service->generate_single_copy(
                $existing->copyType,
                $audience,
                $angle,
                $form_values,
                $model_id,
                $user->id
            );

            // Update result via service
            $this->service->update_result($result_id, $output);
            $output['id'] = $result_id;

            return $this->success($output);

        }
        catch (\Exception $e) {
            return $this->error('Regeneration failed: ' . $e->getMessage(), 500);
        }
    }

    // ========================================
    // Regenerate Batch
    // ========================================

    /**
     * Regenerate multiple copy result cards in a batch.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function regenerate_batch(WP_REST_Request $request)
    {
        // Allow long execution for large batches
        set_time_limit(300);

        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();
        $result_ids = $params['resultIds'] ?? array();
        $form_values = $params['formValues'] ?? array();
        $instruction = $params['instruction'] ?? null;

        if (empty($result_ids) || !is_array($result_ids)) {
            return $this->error('resultIds is required and must be a non-empty array.');
        }

        // Cap at 50 to prevent abuse
        $result_ids = array_slice($result_ids, 0, 50);

        $success_results = array();
        $failed_count = 0;

        foreach ($result_ids as $rid) {
            $rid = (int)$rid;
            $existing = $this->service->get_result($rid, $user->id);
            if (!$existing) {
                $failed_count++;
                continue;
            }

            // Get the job for model info
            try {
                $job = $this->service->get_job((int)$existing->jobId, $user->id);
                $model_id = $this->service->normalize_model_id($job->modelId ?? '', $user->id);
                $job_form_values = json_decode($job->formSnapshot ?? '{}', true) ?: array();

                // Merge provided form values with job snapshot
                $merged_form = array_merge($job_form_values, $form_values);

                $audience = array(
                    'id' => $existing->audienceId ?? 'default',
                    'name' => $existing->audienceName ?? 'General',
                );
                $angle = array('id' => 'regen', 'name' => 'Regeneration');

                $output = $this->service->generate_single_copy(
                    $existing->copyType,
                    $audience,
                    $angle,
                    $merged_form,
                    $model_id,
                    $user->id
                );

                // Update the existing result with the new output
                $this->service->update_result($rid, $output);
                $output['id'] = $rid;
                $output['jobId'] = (int)$existing->jobId;
                $output['modelUsed'] = $model_id;
                $output['createdAt'] = current_time('mysql');
                $output['error'] = null;

                $success_results[] = array(
                    'originalId' => $rid,
                    'newResult' => $output,
                );
            }
            catch (\Exception $e) {
                $failed_count++;
            }
        }

        return $this->success(array(
            'results' => $success_results,
            'failedCount' => $failed_count,
            'totalCount' => count($result_ids),
        ));
    }

    // ========================================
    // Audience Operations
    // ========================================

    /**
     * Duplicate all results for a given audience within a job.
     *
     * Creates copies of all result rows for the specified audience with a new
     * audience identity ("Original Name Copy N").
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function duplicate_audience(WP_REST_Request $request)
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        $job_id = (int)($params['jobId'] ?? 0);
        $audience_id = sanitize_text_field($params['audienceId'] ?? '');
        $audience_name = sanitize_text_field($params['audienceName'] ?? '');

        if (!$job_id || empty($audience_id)) {
            return $this->error('jobId and audienceId are required.');
        }

        // Verify job ownership
        $job = $this->service->get_job($job_id, $user->id);
        if (!$job) {
            return $this->not_found('Copy job');
        }

        // Determine the next copy number and generate new audience identity
        $next_num = $this->service->get_next_duplicate_number($job_id, $user->id, $audience_name);
        $new_audience_id = 'audience_dup_' . time();
        $new_audience_name = $audience_name . ' Copy ' . $next_num;

        $new_results = $this->service->duplicate_audience_results(
            $job_id,
            $user->id,
            $audience_id,
            $new_audience_id,
            $new_audience_name
        );

        return $this->success(array(
            'newAudienceId' => $new_audience_id,
            'newAudienceName' => $new_audience_name,
            'results' => $new_results,
        ));
    }

    /**
     * Rename an audience within a job.
     *
     * Updates the audienceName on all copy results matching the audienceId.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function rename_audience(WP_REST_Request $request)
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        $job_id = (int)($params['jobId'] ?? 0);
        $audience_id = sanitize_text_field($params['audienceId'] ?? '');
        $new_name = sanitize_text_field($params['newName'] ?? '');

        if (!$job_id || empty($audience_id) || empty($new_name)) {
            return $this->error('jobId, audienceId, and newName are required.');
        }

        // Verify job ownership
        $job = $this->service->get_job($job_id, $user->id);
        if (!$job) {
            return $this->not_found('Copy job');
        }

        $affected = $this->service->rename_audience_results($job_id, $user->id, $audience_id, $new_name);

        if ($affected === 0) {
            return $this->not_found('No copy results found for the given audience');
        }

        return $this->success(array(
            'audienceId' => $audience_id,
            'newName' => $new_name,
            'affectedRows' => $affected,
        ));
    }

    // ========================================
    // Inline Edit
    // ========================================

    /**
     * Update text fields of a single copy result (inline editing).
     *
     * Only updates the fields that are provided in the request body.
     * This does NOT trigger a new LLM generation.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_result(WP_REST_Request $request)
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        $result_id = (int)($params['resultId'] ?? 0);
        if (!$result_id) {
            return $this->error('resultId is required.');
        }

        // Extract only the editable fields
        $fields = array();
        foreach (array('headline', 'body', 'cta', 'hashtags', 'description') as $key) {
            if (array_key_exists($key, $params)) {
                $fields[$key] = $params[$key];
            }
        }

        if (empty($fields)) {
            return $this->error('No editable fields provided.');
        }

        $updated = $this->service->update_result_fields($result_id, $user->id, $fields);

        if (!$updated) {
            return $this->not_found('Copy result not found or no changes to apply');
        }

        // Return the updated result
        $result = $this->service->get_result($result_id, $user->id);

        return $this->success(array(
            'id' => (int)$result->id,
            'copyType' => $result->copyType,
            'audienceId' => $result->audienceId,
            'audienceName' => $result->audienceName,
            'headline' => $result->headline,
            'body' => $result->body,
            'cta' => $result->cta,
            'hashtags' => json_decode($result->hashtags ?? '[]', true),
            'description' => $result->description,
            'createdAt' => $result->createdAt,
        ));
    }

    // ========================================
    // Results
    // ========================================

    /**
     * Get all results for a copy job.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_job_results(WP_REST_Request $request)
    {
        $user = $this->get_current_pcm_user();
        $job_id = (int)$request->get_param('jobId');

        $job = $this->service->get_job($job_id, $user->id);
        if (!$job) {
            return $this->not_found('Copy job');
        }

        $formatted = $this->service->get_results_for_job($job_id);

        return $this->success(array(
            'job' => array(
                'id' => (int)$job->id,
                'status' => $job->status,
                'copyTypes' => json_decode($job->copyTypes, true),
                'totalCount' => (int)$job->totalCount,
                'completedCount' => (int)$job->completedCount,
                'failedCount' => (int)$job->failedCount,
                'createdAt' => $job->createdAt,
            ),
            'results' => $formatted,
        ));
    }

    // ========================================
    // URL Scraper
    // ========================================

    /**
     * Scrape a URL for business information.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function scrape_business_info(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        $url = $params['url'] ?? '';

        if (empty($url)) {
            return $this->error('URL is required.');
        }

        try {
            // Scraping requires an explicit model — either from frontend
            // (Settings.defaultTextModel) or from Settings → Module Defaults.
            // No auto-detect fallback: user must configure this consciously.
            $model = $params['model'] ?? '';
            if (empty($model)) {
                return $this->error(
                    'No Scraping Model configured. Please set one in Settings → Module Defaults → Copy Module → Scraping Model.',
                    400
                );
            }
            $info = $this->service->extract_business_info($url, $model);
            return $this->success($info);
        }
        catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * POST /copy/save-to-project — Save one or more copy results to a project.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function save_to_project(WP_REST_Request $request)
    {
        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        $result_ids = $params['resultIds'] ?? array();
        $project_id = (int)($params['projectId'] ?? 0);

        if (empty($result_ids)) {
            // Handle legacy input parameter single resultId for versatility
            $single_id = (int)($params['resultId'] ?? 0);
            if ($single_id > 0) {
                $result_ids = array($single_id);
            } else {
                return $this->error('resultIds is required.');
            }
        }

        if (!$project_id) {
            return $this->error('projectId is required.');
        }

        try {
            $affected = $this->service->save_to_project($result_ids, $project_id, $user->id);
            return $this->success(array(
                'success' => true,
                'count' => $affected,
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * GET /copy/project/{projectId} — Get all copy results associated with a project.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_project_results(WP_REST_Request $request)
    {
        $user = $this->get_current_pcm_user();
        $project_id = (int)$request->get_param('projectId');

        if (!$project_id) {
            return $this->error('projectId is required.');
        }

        try {
            $results = $this->service->get_results_for_project($project_id, $user->id);
            return $this->success(array(
                'results' => $results,
            ));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
