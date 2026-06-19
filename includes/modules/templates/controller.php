<?php
/**
 * Templates REST Controller
 *
 * CRUD operations for reusable templates that pre-fill form fields
 * across modules (Copy, Image, Video).
 *
 * Endpoints:
 *   GET    /templates              → list (optional ?module= filter)
 *   GET    /templates/{id}         → getById
 *   POST   /templates              → create
 *   PATCH  /templates/{id}         → update (partial)
 *   DELETE /templates/{id}         → delete
 *   POST   /templates/bulk/delete  → bulkDelete
 *   POST   /templates/bulk/duplicate → bulkDuplicate
 *   POST   /templates/{id}/default → setDefault
 *
 * DB table: pcm_templates
 * Columns: id, userId, name, module, formData (JSON), description, isDefault,
 *          createdAt, updatedAt
 *
 * Note: The source TypeScript schema uses `entries` (TemplateEntry[]) which
 * maps to the `formData` JSON column. Additional fields like `type`, `niche`,
 * `groupName`, `sortOrder` are stored inside formData as a JSON object.
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Templates extends PCM_REST_Base
{

    /**
     * Define all template routes.
     *
     * @return array
     */
    protected function routes(): array
    {
        return array(
            // Read
                array('GET', '/templates', 'list_items'),
                array('GET', '/templates/(?P<id>\d+)', 'get_by_id'),

            // CRUD
                array('POST', '/templates', 'create_item'),
                array('PATCH', '/templates/(?P<id>\d+)', 'update_item'),
                array('DELETE', '/templates/(?P<id>\d+)', 'delete_item'),

            // Bulk operations
                array('POST', '/templates/bulk/delete', 'bulk_delete'),
                array('POST', '/templates/bulk/duplicate', 'bulk_duplicate'),

            // Actions
                array('POST', '/templates/(?P<id>\d+)/default', 'set_default'),
                array('POST', '/templates/reseed', 'reseed_defaults'),
        );
    }

    // ========================================
    // Read
    // ========================================

    /**
     * List all templates for the current user.
     * Optional filters: ?module=copy|image|video, ?type=scene|recipe|...
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function list_items(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $table = PCM_Schema::table('templates');
        $module = $request->get_param('module');
        $type_filter = $request->get_param('type');

        // Ensure the shared (system) SEO prompt templates exist before listing them,
        // so they appear in the Templates UI + SEO header picker without a prior generate.
        if ($module === 'seo' && class_exists('PCM_SEO_Service')) {
            PCM_SEO_Service::seed_seo_templates();
        }

        // Build query with optional module filter
        // Include system-level templates (userId=0) alongside user-owned templates
        if ($module) {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE (userId = %d OR userId = 0) AND module = %s ORDER BY name ASC",
                $user->id,
                $module
            ));
        }
        else {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE (userId = %d OR userId = 0) ORDER BY name ASC",
                $user->id
            ));
        }

        // Parse JSON formData and format for frontend
        $templates = array_map(array($this, 'format_template'), $results ?: array());

        // Optional type filter (type lives in formData JSON, so filter in PHP)
        if ($type_filter) {
            $templates = array_values(array_filter($templates, function ($tpl) use ($type_filter) {
                return ($tpl['type'] ?? null) === $type_filter;
            }));
        }

        return $this->success($templates);
    }

    /**
     * Get a single template by ID.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_by_id(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $table = PCM_Schema::table('templates');
        $id = (int)$request->get_param('id');

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND (userId = %d OR userId = 0)",
            $id,
            $user->id
        ));

        if (!$row) {
            return $this->not_found('Template');
        }

        return $this->success($this->format_template($row));
    }

    // ========================================
    // Create
    // ========================================

    /**
     * Create a new template.
     *
     * Expected JSON body:
     *   name: string (required)
     *   module: "copy"|"image"|"video" (required)
     *   entries: TemplateEntry[] (required) — stored as formData JSON
     *   type: string (optional)
     *   niche: string (optional)
     *   groupName: string (optional)
     *   isDefault: bool (optional, default false)
     *   sortOrder: int (optional, default 0)
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function create_item(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $table = PCM_Schema::table('templates');
        $params = $request->get_json_params();

        // Validate required fields
        if (empty($params['name'])) {
            return $this->error('Template name is required.');
        }
        if (empty($params['module']) || !in_array($params['module'], array('copy', 'image', 'video', 'writer', 'seo'), true)) {
            return $this->error('Module must be one of: copy, image, video, writer.');
        }
        if (empty($params['entries']) || !is_array($params['entries'])) {
            return $this->error('Template must have at least one entry.');
        }

        // Build formData JSON — includes entries + extra fields
        $form_data = array(
            'entries' => $params['entries'],
            'type' => $params['type'] ?? null,
            'niche' => $params['niche'] ?? null,
            'groupName' => $params['groupName'] ?? null,
            'sortOrder' => (int)($params['sortOrder'] ?? 0),
        );

        $is_default = !empty($params['isDefault']) ? 1 : 0;
        $now = current_time('mysql');

        $wpdb->insert($table, array(
            'userId' => $user->id,
            'name' => sanitize_text_field($params['name']),
            'module' => $params['module'],
            'formData' => wp_json_encode($form_data),
            'description' => sanitize_textarea_field($params['description'] ?? ''),
            'isDefault' => $is_default,
            'createdAt' => $now,
            'updatedAt' => $now,
        ));

        $template_id = $wpdb->insert_id;

        if (!$template_id) {
            return $this->error('Failed to create template.', 500);
        }

        // If set as default, clear other defaults for same module+type
        if ($is_default) {
            $this->clear_other_defaults($template_id, $user->id, $params['module'], $form_data['type']);
        }

        // Return the created template
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $template_id
        ));

        return $this->success($this->format_template($row), 201);
    }

    // ========================================
    // Update
    // ========================================

    /**
     * Update an existing template (partial update).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function update_item(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $table = PCM_Schema::table('templates');
        $id = (int)$request->get_param('id');
        $params = $request->get_json_params();

        // Verify ownership
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND userId = %d",
            $id,
            $user->id
        ));

        if (!$existing) {
            return $this->not_found('Template');
        }

        // Build update data — only update provided fields
        $update = array('updatedAt' => current_time('mysql'));

        if (isset($params['name'])) {
            $update['name'] = sanitize_text_field($params['name']);
        }
        if (isset($params['module'])) {
            $update['module'] = $params['module'];
        }
        if (isset($params['description'])) {
            $update['description'] = sanitize_textarea_field($params['description']);
        }

        // Handle formData fields — merge with existing
        $existing_form = json_decode($existing->formData, true) ?: array();

        if (isset($params['entries'])) {
            $existing_form['entries'] = $params['entries'];
        }
        if (array_key_exists('type', $params)) {
            $existing_form['type'] = $params['type'];
        }
        if (array_key_exists('niche', $params)) {
            $existing_form['niche'] = $params['niche'];
        }
        if (array_key_exists('groupName', $params)) {
            $existing_form['groupName'] = $params['groupName'];
        }
        if (isset($params['sortOrder'])) {
            $existing_form['sortOrder'] = (int)$params['sortOrder'];
        }

        $update['formData'] = wp_json_encode($existing_form);

        $wpdb->update(
            $table,
            $update,
            array('id' => $id, 'userId' => $user->id)
        );

        // Return updated template
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ));

        return $this->success($this->format_template($row));
    }

    // ========================================
    // Delete
    // ========================================

    /**
     * Delete a single template.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function delete_item(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $table = PCM_Schema::table('templates');
        $id = (int)$request->get_param('id');

        // Allow deleting own templates and system templates (userId=0)
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE id = %d AND (userId = %d OR userId = 0)",
            $id,
            $user->id
        ));

        if (!$deleted) {
            return $this->not_found('Template');
        }

        return $this->success(array('success' => true));
    }

    /**
     * Bulk delete multiple templates.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function bulk_delete(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $table = PCM_Schema::table('templates');
        $params = $request->get_json_params();
        $ids = $params['ids'] ?? array();

        if (empty($ids) || !is_array($ids)) {
            return $this->error('ids array is required.');
        }

        // Sanitize IDs and batch delete
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $query_args = array_merge(array($user->id), array_map('intval', $ids));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE (userId = %d OR userId = 0) AND id IN ($placeholders)",
            ...$query_args
        ));

        return $this->success(array('deleted' => (int)$deleted));
    }

    /**
     * Re-seed default system templates.
     *
     * Calls PCM_Template_Seeds::seed() which is idempotent —
     * only inserts templates that don't already exist (by name+module).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function reseed_defaults(WP_REST_Request $request)
    {
        PCM_Template_Seeds::seed();
        return $this->success(array('success' => true, 'message' => 'Default templates restored'));
    }

    /**
     * Bulk duplicate multiple templates.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function bulk_duplicate(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $table = PCM_Schema::table('templates');
        $params = $request->get_json_params();
        $ids = $params['ids'] ?? array();

        if (empty($ids) || !is_array($ids)) {
            return $this->error('ids array is required.');
        }

        // Fetch originals
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $query_args = array_merge(array($user->id), array_map('intval', $ids));

        $originals = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE (userId = %d OR userId = 0) AND id IN ($placeholders)",
            ...$query_args
        ));

        $now = current_time('mysql');
        $created = 0;

        foreach ($originals ?: array() as $orig) {
            $wpdb->insert($table, array(
                'userId' => $user->id,
                'name' => $orig->name . ' (Copy)',
                'module' => $orig->module,
                'formData' => $orig->formData,
                'description' => $orig->description,
                'isDefault' => 0, // Copies are never default
                'createdAt' => $now,
                'updatedAt' => $now,
            ));
            if ($wpdb->insert_id) {
                $created++;
            }
        }

        return $this->success(array('created' => $created));
    }

    // ========================================
    // Actions
    // ========================================

    /**
     * Toggle the default state of a template.
     * Only one template can be default per module+type combination.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function set_default(WP_REST_Request $request)
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $table = PCM_Schema::table('templates');
        $id = (int)$request->get_param('id');
        $params = $request->get_json_params();

        $is_default = !empty($params['isDefault']) ? 1 : 0;

        // Verify ownership
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND userId = %d",
            $id,
            $user->id
        ));

        if (!$existing) {
            return $this->not_found('Template');
        }

        // If setting as default, clear other defaults for same module+type
        if ($is_default) {
            $form_data = json_decode($existing->formData, true) ?: array();
            $this->clear_other_defaults($id, $user->id, $existing->module, $form_data['type'] ?? null);
        }

        $wpdb->update(
            $table,
            array('isDefault' => $is_default, 'updatedAt' => current_time('mysql')),
            array('id' => $id, 'userId' => $user->id)
        );

        return $this->success(array('success' => true));
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * Clear default flag for other templates with the same module+type.
     *
     * @param int         $except_id Template ID to exclude.
     * @param int         $user_id   User ID.
     * @param string      $module    Module name.
     * @param string|null $type      Template type (from formData).
     */
    private function clear_other_defaults(int $except_id, int $user_id, string $module, ?string $type): void
    {
        global $wpdb;

        $table = PCM_Schema::table('templates');

        // Get all default templates for this module (excluding the current one)
        $defaults = $wpdb->get_results($wpdb->prepare(
            "SELECT id, formData FROM $table WHERE userId = %d AND module = %s AND isDefault = 1 AND id != %d",
            $user_id,
            $module,
            $except_id
        ));

        foreach ($defaults ?: array() as $tmpl) {
            $form = json_decode($tmpl->formData, true) ?: array();
            // Only clear if same type (or type is null/matches)
            if (($form['type'] ?? null) === $type) {
                $wpdb->update(
                    $table,
                    array('isDefault' => 0),
                    array('id' => $tmpl->id)
                );
            }
        }
    }

    /**
     * Format a raw DB row into the frontend-compatible template object.
     *
     * @param object $row Database row.
     *
     * @return array Formatted template.
     */
    private function format_template(object $row): array
    {
        $form_data = json_decode($row->formData, true) ?: array();

        return array(
            'id' => (int)$row->id,
            'name' => $row->name,
            'module' => $row->module,
            'type' => $form_data['type'] ?? null,
            'entries' => $form_data['entries'] ?? array(),
            'niche' => $form_data['niche'] ?? null,
            // SEO prompt templates carry section + prompt in formData.
            'section' => $form_data['section'] ?? null,
            'prompt' => $form_data['prompt'] ?? null,
            'sectionIsDefault' => !empty($form_data['isDefault']),
            'groupName' => $form_data['groupName'] ?? null,
            'sortOrder' => $form_data['sortOrder'] ?? 0,
            'isDefault' => (bool)$row->isDefault,
            'description' => $row->description,
            'createdAt' => $row->createdAt,
            'updatedAt' => $row->updatedAt,
        );
    }
}
