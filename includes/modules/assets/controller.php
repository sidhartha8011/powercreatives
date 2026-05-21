<?php
/**
 * Assets REST Controller
 *
 * Generic asset operations for images and videos:
 *   - CRUD on user assets (generated images/videos)
 *   - AI refine (generate refined prompt via LLM)
 *   - Create variations
 *   - Export in different formats
 *   - Save to / manage projects
 *
 * Endpoints:
 *   GET    /assets              → getAll (filters: projectId, type)
 *   GET    /assets/{id}         → getById
 *   POST   /assets/refine       → refine (AI prompt refinement)
 *   POST   /assets/variations   → createVariations
 *   POST   /assets/export       → export (format conversion)
 *   POST   /assets/save-to-project → saveToProject
 *   GET    /assets/projects     → getProjects
 *   POST   /assets/projects     → createProject
 *
 * DB tables: pcm_assets, pcm_projects
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Assets extends PCM_REST_Base
{

    /**
     * Define all asset routes.
     *
     * @return array
     */
    protected function routes(): array
    {
        return array(
            // Asset CRUD
                array('GET', '/assets', 'get_all'),
                array('GET', '/assets/(?P<id>\d+)', 'get_by_id'),

            // AI operations
                array('POST', '/assets/refine', 'refine'),
                array('POST', '/assets/variations', 'create_variations'),

            // Export / project
                array('POST', '/assets/export', 'export_asset'),
                array('POST', '/assets/save-to-project', 'save_to_project'),

            // Projects
                array('GET', '/assets/projects', 'get_projects'),
                array('POST', '/assets/projects', 'create_project'),
                array('DELETE', '/assets/projects/(?P<id>\d+)', 'delete_project'),
                array('PATCH', '/assets/projects/(?P<id>\d+)', 'rename_project'),
                array('POST', '/assets/projects/(?P<id>\d+)/duplicate', 'duplicate_project'),
        );
    }

    // ========================================
    // Asset Read
    // ========================================

    /**
     * Get all assets for the current user.
     * Optional filters: ?projectId=X&type=image|video
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_all(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $table = PCM_Schema::table('assets');
        $projectId = $request->get_param('projectId');
        $type = $request->get_param('type');

        // Build dynamic WHERE clause
        $where = array('userId = %d');
        $values = array($user->id);

        if ($projectId) {
            $where[] = 'projectId = %d';
            $values[] = (int)$projectId;
        }
        if ($type) {
            $where[] = 'type = %s';
            $values[] = $type;
        }

        $where_sql = implode(' AND ', $where);

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE $where_sql ORDER BY createdAt DESC LIMIT 200",
            ...$values
        ));

        $assets = array_map(array($this, 'format_asset'), $results ?: array());

        return $this->success($assets);
    }

    /**
     * Get a single asset by ID.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_by_id(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $id = (int)$request->get_param('id');
        $asset = $this->get_asset_with_auth($id, $user->id);

        if (is_wp_error($asset)) {
            return $asset;
        }

        return $this->success($this->format_asset($asset));
    }

    // ========================================
    // AI Operations
    // ========================================

    /**
     * Refine an existing asset using AI.
     *
     * Takes the original prompt and a refinement instruction,
     * generates a refined prompt via LLM.
     *
     * Body: { assetId: int, instruction: string, modelId?: string }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function refine(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        $asset_id = (int)($params['assetId'] ?? 0);
        $instruction = $params['instruction'] ?? '';
        $model_id = $params['modelId'] ?? null;

        if (!$asset_id || empty($instruction)) {
            return $this->error('assetId and instruction are required.');
        }

        $asset = $this->get_asset_with_auth($asset_id, $user->id);
        if (is_wp_error($asset)) {
            return $asset;
        }

        // Generate refined prompt via LLM
        $original_prompt = $asset->prompt ?? '';

        try {
            $refined_prompt = $this->generate_refined_prompt(
                $original_prompt,
                $instruction,
                $model_id
            );
        }
        catch (\Exception $e) {
            return $this->error('Prompt refinement failed: ' . $e->getMessage(), 500);
        }

        return $this->success(array(
            'refinedPrompt' => $refined_prompt,
            'originalPrompt' => $original_prompt,
            'assetId' => $asset_id,
            'imageUrl' => null, // Actual generation handled by frontend
        ));
    }

    /**
     * Create variation prompts for an existing asset.
     *
     * Body: { assetId: int, count?: int (1-10, default 3) }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function create_variations(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        $asset_id = (int)($params['assetId'] ?? 0);
        $count = min(10, max(1, (int)($params['count'] ?? 3)));

        if (!$asset_id) {
            return $this->error('assetId is required.');
        }

        $asset = $this->get_asset_with_auth($asset_id, $user->id);
        if (is_wp_error($asset)) {
            return $asset;
        }

        $metadata = json_decode($asset->metadata ?? '{}', true) ?: array();

        return $this->success(array(
            'originalPrompt' => $asset->prompt ?? '',
            'provider' => $asset->provider,
            'modelId' => $asset->modelId,
            'count' => $count,
            'settings' => $metadata,
        ));
    }

    // ========================================
    // Export & Project
    // ========================================

    /**
     * Export an asset in a different format.
     *
     * Body: { assetId: int, format: "png"|"jpeg"|"webp"|"mp4" }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function export_asset(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        $asset_id = (int)($params['assetId'] ?? 0);
        $format = $params['format'] ?? 'png';

        $allowed_formats = array('png', 'jpeg', 'webp', 'mp4');
        if (!in_array($format, $allowed_formats, true)) {
            return $this->error('Format must be one of: ' . implode(', ', $allowed_formats));
        }

        $asset = $this->get_asset_with_auth($asset_id, $user->id);
        if (is_wp_error($asset)) {
            return $asset;
        }

        // Return the current URL and desired format
        // Actual conversion handled by the frontend or a future background job
        return $this->success(array(
            'url' => $asset->url,
            'format' => $format,
            'filename' => sprintf('creative-%d.%s', $asset->id, $format),
        ));
    }

    /**
     * Save an asset to a project.
     *
     * Body: { assetId: int, projectId: int }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function save_to_project(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $params = $request->get_json_params();

        $project_id = (int)($params['projectId'] ?? 0);

        if (!$project_id) {
            return $this->error('projectId is required.');
        }

        // Verify project ownership
        $project_table = PCM_Schema::table('projects');
        $project = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $project_table WHERE id = %d AND userId = %d",
            $project_id,
            $user->id
        ));

        if (!$project) {
            return $this->not_found('Project');
        }

        // Parse either a single assetId or a batch of assetIds
        $asset_ids = $params['assetIds'] ?? [];
        if (empty($asset_ids) && !empty($params['assetId'])) {
            $asset_ids = [$params['assetId']];
        }

        if (empty($asset_ids)) {
            return $this->error('assetId or assetIds is required.');
        }

        // Clean & filter IDs
        $clean_ids = array_map('intval', $asset_ids);
        $clean_ids = array_filter($clean_ids, function($id) { return $id > 0; });

        if (empty($clean_ids)) {
            return $this->error('No valid asset IDs provided.');
        }

        $asset_table = PCM_Schema::table('assets');

        // Verify ownership for all targeted assets to prevent unauthorized manipulation
        $placeholders = implode(',', array_fill(0, count($clean_ids), '%d'));
        $db_assets = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $asset_table WHERE id IN ($placeholders) AND userId = %d",
            ...array_merge($clean_ids, array($user->id))
        ));

        $valid_ids = array_map('intval', $db_assets ?: array());
        if (empty($valid_ids)) {
            return $this->error('No authorized assets found.');
        }

        // Update the assets' projectId in bulk
        $update_placeholders = implode(',', array_fill(0, count($valid_ids), '%d'));
        $query = "UPDATE $asset_table SET projectId = %d WHERE id IN ($update_placeholders) AND userId = %d";
        $wpdb->query($wpdb->prepare($query, $project_id, ...array_merge($valid_ids, array($user->id))));

        return $this->success(array(
            'success' => true,
            'assetIds' => $valid_ids,
            'projectId' => $project_id,
            'projectName' => $project->name,
        ));
    }

    // ========================================
    // Project Management
    // ========================================

    /**
     * Get all projects for the current user (for dropdown).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_projects(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $table = PCM_Schema::table('projects');
        $asset_table = PCM_Schema::table('assets');
        
        $include_thumbnails = filter_var($request->get_param('includeThumbnails'), FILTER_VALIDATE_BOOLEAN);

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, description, status, settings, createdAt FROM $table WHERE userId = %d ORDER BY name ASC",
            $user->id
        ));

        if (empty($results)) {
            return $this->success(array());
        }

        // --- BATCH LOADING ---
        $project_ids = array_map(function($row) { return (int)$row->id; }, $results);
        $placeholders = implode(',', array_fill(0, count($project_ids), '%d'));
        
        // Use a safe session increase for group_concat to avoid clipping long URLs
        $wpdb->query("SET SESSION group_concat_max_len = 100000");

        // Single query to get counts and URLs
        $query = "SELECT projectId, COUNT(id) as assetCount, GROUP_CONCAT(url ORDER BY createdAt DESC SEPARATOR '|') as urls FROM $asset_table WHERE projectId IN ($placeholders) GROUP BY projectId";
        $asset_data = $wpdb->get_results($wpdb->prepare($query, ...$project_ids));
        
        // Map data by projectId
        $asset_map = array();
        foreach ($asset_data as $data) {
            $asset_map[(int)$data->projectId] = array(
                'count' => (int)$data->assetCount,
                'urls' => $data->urls ? explode('|', $data->urls) : array(),
            );
        }

        $projects = array_map(function ($row) use ($asset_map, $include_thumbnails) {
            $mapped_data = $asset_map[(int)$row->id] ?? array('count' => 0, 'urls' => array());
            $settings = json_decode($row->settings ?? '{}', true) ?: array();
            
            // Limit to 4 images if requested
            $images = array();
            if ($include_thumbnails) {
                // Filter out empty URLs just in case
                $valid_urls = array_filter($mapped_data['urls']);
                $images = array_slice($valid_urls, 0, 4);
            }

            return array(
            'id' => (int)$row->id,
            'name' => $row->name,
            'description' => $row->description,
            'status' => $row->status,
            'type' => $settings['type'] ?? 'general',
            'assetCount' => $mapped_data['count'],
            'images' => $images,
            'createdAt' => $row->createdAt,
            );
        }, $results);

        return $this->success($projects);
    }

    /**
     * Create a new project.
     *
     * Body: { name: string, description?: string, type?: string }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function create_project(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $table = PCM_Schema::table('projects');
        $params = $request->get_json_params();

        if (empty($params['name'])) {
            return $this->error('Project name is required.');
        }

        $now = current_time('mysql');

        $wpdb->insert($table, array(
            'userId' => $user->id,
            'name' => sanitize_text_field($params['name']),
            'description' => sanitize_textarea_field($params['description'] ?? ''),
            'status' => 'active',
            'settings' => wp_json_encode(array('type' => $params['type'] ?? 'general')),
            'createdAt' => $now,
            'updatedAt' => $now,
        ));

        $id = $wpdb->insert_id;

        if (!$id) {
            return $this->error('Failed to create project.', 500);
        }

        return $this->success(array(
            'id' => $id,
            'name' => $params['name'],
            'type' => $params['type'] ?? 'general',
        ), 201);
    }

    /**
     * Rename/Update a project.
     *
     * Body: { name: string }
     */
    public function rename_project(WP_REST_Request $request)
    {
        global $wpdb;
        $user = $this->get_current_pcm_user();
        $id = (int)$request->get_param('id');
        $params = $request->get_json_params();

        if (empty($params['name'])) {
            return $this->error('Name is required.');
        }

        $table = PCM_Schema::table('projects');
        $project = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE id = %d AND userId = %d", $id, $user->id));
        if (!$project) return $this->not_found('Project');

        $wpdb->update(
            $table,
            array('name' => sanitize_text_field($params['name']), 'updatedAt' => current_time('mysql')),
            array('id' => $id)
        );

        return $this->success(array('success' => true));
    }

    /**
     * Delete a project. Ensures asset orphans are protected (projectId = 0).
     */
    public function delete_project(WP_REST_Request $request)
    {
        global $wpdb;
        $user = $this->get_current_pcm_user();
        $id = (int)$request->get_param('id');

        $table = PCM_Schema::table('projects');
        $asset_table = PCM_Schema::table('assets');
        
        $project = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE id = %d AND userId = %d", $id, $user->id));
        if (!$project) return $this->not_found('Project');

        $wpdb->query('START TRANSACTION');
        try {
            // Protect assets by re-assigning them to the root
            $wpdb->update($asset_table, array('projectId' => 0), array('projectId' => $id, 'userId' => $user->id));
            
            // Delete the project
            $wpdb->delete($table, array('id' => $id));
            
            $wpdb->query('COMMIT');
            return $this->success(array('success' => true));
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return $this->error('Failed to delete project', 500);
        }
    }

    /**
     * Duplicate a project.
     */
    public function duplicate_project(WP_REST_Request $request)
    {
        global $wpdb;
        $user = $this->get_current_pcm_user();
        $id = (int)$request->get_param('id');

        $table = PCM_Schema::table('projects');
        $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND userId = %d", $id, $user->id));
        if (!$project) return $this->not_found('Project');

        $now = current_time('mysql');
        
        $wpdb->insert($table, array(
            'userId' => $user->id,
            'name' => $project->name . ' (Copy)',
            'description' => $project->description,
            'status' => $project->status,
            'settings' => $project->settings,
            'createdAt' => $now,
            'updatedAt' => $now,
        ));

        return $this->success(array('success' => true, 'id' => $wpdb->insert_id));
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * Fetch an asset and verify ownership.
     *
     * @param int $asset_id Asset ID.
     * @param int $user_id  PCM user ID.
     *
     * @return object|WP_Error The asset row or error.
     */
    private function get_asset_with_auth(int $asset_id, int $user_id)
    {
        global $wpdb;

        $table = PCM_Schema::table('assets');
        $asset = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND userId = %d",
            $asset_id,
            $user_id
        ));

        if (!$asset) {
            return $this->not_found('Asset');
        }

        return $asset;
    }

    /**
     * Generate a refined prompt via LLM.
     *
     * @param string      $original_prompt      The original image prompt.
     * @param string      $instruction          User's refinement instruction.
     * @param string|null $model_id             Optional specific model to use.
     *
     * @return string The refined prompt.
     */
    private function generate_refined_prompt(string $original_prompt, string $instruction, ?string $model_id = null): string
    {
        $messages = array(
                array(
                'role' => 'system',
                'content' => 'You are an expert prompt engineer for AI image generation. '
                . 'Given an original image prompt and a refinement instruction, '
                . 'produce an improved prompt that incorporates the refinement '
                . 'while preserving the original intent. '
                . 'Return ONLY the refined prompt text, nothing else.',
            ),
                array(
                'role' => 'user',
                'content' => "Original prompt:\n{$original_prompt}\n\nRefinement instruction:\n{$instruction}\n\nGenerate the refined prompt:",
            ),
        );

        $options = array('max_tokens' => (int)PCM_Settings::get('token_budget_copy', 16384));
        if ($model_id) {
            $options['model'] = $model_id;
        }

        $result = PCM_LLM::invoke($messages, $options);

        return trim($result['content'] ?? '');
    }

    /**
     * Format a raw DB row into the frontend-compatible asset object.
     *
     * @param object $row Database row.
     *
     * @return array Formatted asset.
     */
    private function format_asset(object $row): array
    {
        return array(
            'id' => (int)$row->id,
            'projectId' => (int)$row->projectId,
            'type' => $row->type,
            'url' => $row->url,
            'prompt' => $row->prompt,
            'provider' => $row->provider,
            'modelId' => $row->modelId,
            'metadata' => json_decode($row->metadata ?? '{}', true),
            'versionName' => $row->versionName,
            'createdAt' => $row->createdAt,
        );
    }
}
