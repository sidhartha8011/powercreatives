<?php
/**
 * Models REST Controller
 *
 * Thin router for unified AI model management. Handles HTTP concerns:
 * route definitions, input validation, response formatting.
 *
 * All business logic delegated to PCM_Models_Service.
 *
 * Endpoints:
 *   GET    /models                      → getAll
 *   GET    /models/<id>                 → getById
 *   GET    /models/provider/<provider>  → getByProvider
 *   GET    /models/generation/<type>    → getForGeneration
 *   GET    /models/editing/<type>       → getForEditing
 *   POST   /models                      → create
 *   PATCH  /models/<id>                 → update
 *   POST   /models/<id>/capability      → updateCapability
 *   POST   /models/<id>/confirm         → confirmStatus
 *   POST   /models/<id>/toggle-module   → toggleModule
 *   POST   /models/sync                 → syncFromIntegrations
 *   POST   /models/resync               → resyncProvider
 *   DELETE /models/<id>                 → delete
 *   POST   /models/bulk/delete          → bulkDelete
 *   POST   /models/bulk/tier            → bulkChangeTier
 *   POST   /models/bulk/toggle-module   → bulkToggleModule
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Models extends PCM_REST_Base
{
    // Work module — usable by non-admin team members (assigned access).
    protected string $default_capability = 'edit_posts';


    /**
     * Service instance — holds all business logic.
     *
     * @var PCM_Models_Service
     */
    private PCM_Models_Service $service;

    /**
     * Constructor — inject service dependency.
     */
    public function __construct()
    {
        $this->service = new PCM_Models_Service();
    }

    /**
     * Define all model routes.
     *
     * @return array
     */
    protected function routes(): array
    {
        return array(
            // Read
                array('GET', '/models', 'get_all'),
                array('GET', '/models/(?P<id>\\d+)', 'get_by_id'),
                array('GET', '/models/provider/(?P<provider>[\\w-]+)', 'get_by_provider'),
                array('GET', '/models/generation/(?P<type>\\w+)', 'get_for_generation'),
                array('GET', '/models/editing/(?P<type>\\w+)', 'get_for_editing'),

            // CRUD
                array('POST', '/models', 'create_item'),
                array('PATCH', '/models/(?P<id>\\d+)', 'update_item'),
                array('DELETE', '/models/(?P<id>\\d+)', 'delete_item'),

            // Actions
                array('POST', '/models/(?P<id>\\d+)/capability', 'update_capability'),
                array('POST', '/models/(?P<id>\\d+)/confirm', 'confirm_status'),
                array('POST', '/models/(?P<id>\\d+)/toggle-module', 'toggle_module'),
                array('POST', '/models/sync', 'sync_from_integrations'),
                array('POST', '/models/resync', 'resync_provider'),

            // Bulk operations
                array('POST', '/models/bulk/delete', 'bulk_delete'),
                array('POST', '/models/bulk/tier', 'bulk_change_tier'),
                array('POST', '/models/bulk/toggle-module', 'bulk_toggle_module'),
                array('POST', '/models/bulk/toggle-enabled', 'bulk_toggle_enabled'),

            // AI & confirmations
                array('POST', '/models/auto-detect', 'auto_detect_capabilities'),
                array('POST', '/models/confirm-all-suggested', 'confirm_all_suggested'),
        );
    }

    // =========================================================================
    // READ OPERATIONS
    // =========================================================================

    /** GET /models — All models for current user. */
    public function get_all(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->get_current_pcm_user();
        $models = PCM_DB::get_user_models($user->id);

        return $this->success(array_map(array($this->service, 'format_model'), $models));
    }

    /** GET /models/<id> — Single model by ID. */
    public function get_by_id(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $model = PCM_DB::get_by_id('models', absint($request->get_param('id')), $user->id);

        if (!$model) {
            return $this->not_found('Model');
        }

        return $this->success($this->service->format_model($model));
    }

    /** GET /models/provider/<provider> — Models filtered by provider. */
    public function get_by_provider(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->get_current_pcm_user();
        $provider = sanitize_text_field($request->get_param('provider'));
        $models = $this->service->get_by_provider($user->id, $provider);

        return $this->success(array_map(array($this->service, 'format_model'), $models));
    }

    /** GET /models/generation/<type> — Models that can generate content. */
    public function get_for_generation(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->get_current_pcm_user();
        $type = sanitize_text_field($request->get_param('type'));
        $this->service->maybe_schedule_refresh((int) $user->id); // reading keeps the registry fresh
        $models = $this->service->get_by_capability($user->id, $type, 'generate');

        return $this->success(array_map(array($this->service, 'format_model'), $models));
    }

    /** GET /models/editing/<type> — Models that can edit content. */
    public function get_for_editing(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->get_current_pcm_user();
        $type = sanitize_text_field($request->get_param('type'));
        $this->service->maybe_schedule_refresh((int) $user->id); // reading keeps the registry fresh
        $models = $this->service->get_by_capability($user->id, $type, 'edit');

        return $this->success(array_map(array($this->service, 'format_model'), $models));
    }

    // =========================================================================
    // CRUD OPERATIONS
    // =========================================================================

    /** POST /models — Create a new model entry. */
    public function create_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();

        $data = array(
            'userId' => $user->id,
            'modelId' => sanitize_text_field($request->get_param('modelId')),
            'provider' => sanitize_text_field($request->get_param('provider')),
            'displayName' => sanitize_text_field($request->get_param('originalName') ?? $request->get_param('modelId')),
            'canGenerateImage' => (int)($request->get_param('canGenerateImage') ?? 0),
            'canEditImage' => (int)($request->get_param('canEditImage') ?? 0),
            'canGenerateVideo' => (int)($request->get_param('canGenerateVideo') ?? 0),
            'canEditVideo' => (int)($request->get_param('canEditVideo') ?? 0),
            'canGenerateText' => (int)($request->get_param('canGenerateText') ?? 0),
            'canVision' => (int)($request->get_param('canVision') ?? 0),
            'costTier' => sanitize_text_field($request->get_param('costTier') ?? 'standard'),
            'status' => 'auto',
            'isEnabled' => 1,
            'description' => sanitize_textarea_field($request->get_param('description') ?? ''),
            'tags' => wp_json_encode($request->get_param('tags') ?? array()),
            'sortOrder' => absint($request->get_param('sortOrder') ?? 0),
        );

        if (empty($data['modelId']) || empty($data['provider'])) {
            return $this->error('Model ID and provider are required.');
        }

        $model_id = PCM_DB::upsert_model($data);

        if (!$model_id) {
            return $this->error('Failed to create model.', 500);
        }

        return $this->success(array('success' => true, 'id' => $model_id), 201);
    }

    /** PATCH /models/<id> — Update a model's configuration. */
    public function update_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));

        $existing = PCM_DB::get_by_id('models', $id, $user->id);
        if (!$existing) {
            return $this->not_found('Model');
        }

        $update = array();

        // Capability fields
        $capability_fields = array(
            'canGenerateImage', 'canEditImage', 'canGenerateVideo',
            'canEditVideo', 'canGenerateText', 'canVision',
        );
        foreach ($capability_fields as $field) {
            $val = $request->get_param($field);
            if (null !== $val) {
                $update[$field] = (int)$val;
            }
        }

        // Other updatable fields
        $text_fields = array('displayName', 'costTier', 'status', 'description');
        foreach ($text_fields as $field) {
            $val = $request->get_param($field);
            if (null !== $val) {
                $update[$field] = sanitize_text_field($val);
            }
        }

        $is_enabled = $request->get_param('isEnabled');
        if (null !== $is_enabled) {
            $update['isEnabled'] = (int)rest_sanitize_boolean($is_enabled);
        }

        $sort_order = $request->get_param('sortOrder');
        if (null !== $sort_order) {
            $update['sortOrder'] = absint($sort_order);
        }

        $tags = $request->get_param('tags');
        if (null !== $tags) {
            $update['tags'] = wp_json_encode($tags);
        }

        if (!empty($update)) {
            PCM_DB::update_by_id('models', $id, $user->id, $update);
        }

        return $this->success(array('success' => true));
    }

    /** DELETE /models/<id> — Delete a single model. */
    public function delete_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $deleted = PCM_DB::delete_by_id('models', absint($request->get_param('id')), $user->id);

        if (!$deleted) {
            return $this->not_found('Model');
        }

        return $this->success(array('success' => true));
    }

    // =========================================================================
    // ACTION ENDPOINTS
    // =========================================================================

    /** POST /models/<id>/capability — Toggle a single capability. */
    public function update_capability(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));
        $capability = sanitize_text_field($request->get_param('capability'));
        $enabled = rest_sanitize_boolean($request->get_param('enabled'));

        $existing = PCM_DB::get_by_id('models', $id, $user->id);
        if (!$existing) {
            return $this->not_found('Model');
        }

        // Map capability string to DB column via service
        $column = $this->service->type_to_capability_column(
            str_replace(['can_generate_', 'can_edit_', 'can_'], '', $capability),
            str_contains($capability, 'edit') ? 'edit' : 'generate'
        );

        // Direct column mapping for exact matches
        $column_map = array(
            'can_generate_image' => 'canGenerateImage',
            'can_edit_image' => 'canEditImage',
            'can_generate_video' => 'canGenerateVideo',
            'can_edit_video' => 'canEditVideo',
            'can_generate_text' => 'canGenerateText',
            'can_vision' => 'canVision',
        );

        $column = $column_map[$capability] ?? null;
        if (!$column) {
            return $this->error('Invalid capability: ' . $capability);
        }

        PCM_DB::update_by_id('models', $id, $user->id, array($column => (int)$enabled));

        return $this->success(array('success' => true));
    }

    /** POST /models/<id>/confirm — Confirm a model's status. */
    public function confirm_status(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));

        $existing = PCM_DB::get_by_id('models', $id, $user->id);
        if (!$existing) {
            return $this->not_found('Model');
        }

        PCM_DB::update_by_id('models', $id, $user->id, array('status' => 'confirmed'));

        return $this->success(array('success' => true));
    }

    /** POST /models/<id>/toggle-module — Toggle module enablement for a model. */
    public function toggle_module(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));
        $module = sanitize_text_field($request->get_param('module'));
        $enabled = rest_sanitize_boolean($request->get_param('enabled'));

        $existing = PCM_DB::get_by_id('models', $id, $user->id);
        if (!$existing) {
            return $this->not_found('Model');
        }

        // Delegate module toggle logic to service
        $modules = $this->service->apply_module_toggle($existing, $module, $enabled);

        // Write to the enabledModules column (not providerMetadata)
        // Consistent with bulk_toggle_module() which already writes correctly.
        PCM_DB::update_by_id('models', $id, $user->id, array(
            'enabledModules' => wp_json_encode($modules),
        ));

        return $this->success(array('success' => true));
    }

    /** POST /models/sync — Sync models from a provider integration. */
    public function sync_from_integrations(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $provider = sanitize_text_field($request->get_param('provider'));
        $api_key = $request->get_param('apiKey');

        if (empty($provider) || empty($api_key)) {
            return $this->error('Provider and API key are required.');
        }

        $validation = PCM_Providers::validate_api_key($provider, $api_key);
        if (!$validation['valid']) {
            return $this->error('API key is invalid: ' . ($validation['error'] ?? 'Unknown error'));
        }

        // Delegate sync to service (consolidated logic)
        $results = $this->service->sync_models($user->id, $provider, $validation['models']);

        return $this->success(array(
            'success' => true,
            'created' => $results['created'],
            'total' => $results['total'],
        ));
    }

    /** POST /models/resync — Re-sync using stored API key. */
    public function resync_provider(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $provider = sanitize_text_field($request->get_param('provider'));

        if (empty($provider)) {
            return $this->error('Provider is required.');
        }

        // Look up stored API key
        $integrations = PCM_DB::get_user_integrations($user->id);
        $integration = null;
        foreach ($integrations as $int) {
            if ($int->provider === $provider) {
                $integration = $int;
                break;
            }
        }

        if (!$integration || empty($integration->apiKey)) {
            return $this->error('No integration found for provider: ' . $provider);
        }

        $validation = PCM_Providers::validate_api_key($provider, $integration->apiKey);
        if (!$validation['valid']) {
            return $this->error('API key validation failed: ' . ($validation['error'] ?? 'Unknown error'));
        }

        // Delegate sync to service (same consolidated logic, with mark_available=true)
        $results = $this->service->sync_models($user->id, $provider, $validation['models'], true);

        return $this->success(array(
            'success' => true,
            'resynced' => $results['created'],
            'total' => $results['total'],
            'provider' => $provider,
        ));
    }

    // =========================================================================
    // BULK OPERATIONS
    // =========================================================================

    /** POST /models/bulk/delete — Delete multiple models. */
    public function bulk_delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $ids = $request->get_param('ids');

        if (!is_array($ids) || empty($ids)) {
            return $this->error('IDs array is required.');
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if (PCM_DB::delete_by_id('models', absint($id), $user->id)) {
                $deleted++;
            }
        }

        return $this->success(array('deleted' => $deleted));
    }

    /** POST /models/bulk/tier — Change cost tier for multiple models. */
    public function bulk_change_tier(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $ids = $request->get_param('ids');
        $tier = sanitize_text_field($request->get_param('tier'));

        if (!in_array($tier, array('budget', 'standard', 'premium'), true)) {
            return $this->error('Invalid tier. Must be budget, standard, or premium.');
        }

        $updated = 0;
        foreach ($ids as $id) {
            if (PCM_DB::update_by_id('models', absint($id), $user->id, array('costTier' => $tier))) {
                $updated++;
            }
        }

        return $this->success(array('updated' => $updated));
    }

    /** POST /models/bulk/toggle-module — Toggle module for multiple models. */
    public function bulk_toggle_module(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $ids = $request->get_param('ids');
        $module = sanitize_text_field($request->get_param('module'));
        $enabled = rest_sanitize_boolean($request->get_param('enabled'));

        $updated = 0;
        foreach ($ids as $id) {
            $existing = PCM_DB::get_by_id('models', absint($id), $user->id);
            if (!$existing) {
                continue;
            }

            // Delegate toggle logic to service
            $modules = $this->service->apply_module_toggle($existing, $module, $enabled);

            PCM_DB::update_by_id('models', absint($id), $user->id, array(
                'enabledModules' => wp_json_encode($modules),
            ));
            $updated++;
        }

        return $this->success(array('updated' => $updated));
    }

    /** POST /models/bulk/toggle-enabled — Enable or disable multiple models. */
    public function bulk_toggle_enabled(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $ids = $request->get_param('ids');
        $enabled = rest_sanitize_boolean($request->get_param('enabled'));

        if (!is_array($ids) || empty($ids)) {
            return $this->error('IDs array is required.');
        }

        $updated = 0;
        foreach ($ids as $id) {
            if (PCM_DB::update_by_id('models', absint($id), $user->id, array('isEnabled' => (int)$enabled))) {
                $updated++;
            }
        }

        return $this->success(array('success' => true, 'count' => $updated));
    }

    /** POST /models/auto-detect — Re-detect capabilities for all models using models.json registry. */
    public function auto_detect_capabilities(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $models = PCM_DB::get_user_models($user->id);
        $analyzed = 0;

        foreach ($models as $model) {
            // Determine primary type from current capabilities
            if ($model->canGenerateVideo) {
                $type = 'video';
            }
            elseif ($model->canGenerateImage) {
                $type = 'image';
            }
            else {
                $type = 'text';
            }

            $capabilities = $this->service->detect_capabilities($model->modelId, $type);

            PCM_DB::update_by_id('models', $model->id, $user->id, array(
                'canGenerateImage' => (int)$capabilities['canGenerateImage'],
                'canEditImage' => (int)$capabilities['canEditImage'],
                'canGenerateVideo' => (int)$capabilities['canGenerateVideo'],
                'canEditVideo' => (int)$capabilities['canEditVideo'],
                'canGenerateText' => (int)$capabilities['canGenerateText'],
                'canVision' => (int)$capabilities['canVision'],
                'status' => 'ai_suggested',
            ));
            $analyzed++;
        }

        return $this->success(array('success' => true, 'analyzed' => $analyzed));
    }

    /** POST /models/confirm-all-suggested — Confirm all AI-suggested model statuses. */
    public function confirm_all_suggested(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $models = PCM_DB::get_user_models($user->id);
        $confirmed = 0;

        foreach ($models as $model) {
            if ($model->status === 'ai_suggested') {
                PCM_DB::update_by_id('models', $model->id, $user->id, array(
                    'status' => 'confirmed',
                ));
                $confirmed++;
            }
        }

        return $this->success(array('success' => true, 'count' => $confirmed));
    }
}
