<?php
/**
 * Integrations REST Controller
 *
 * Manages API integrations for AI providers (Google, OpenAI, Kie.ai, etc.).
 * PHP port of server/routers/integrations.ts (10 endpoints).
 *
 * Endpoints:
 *   GET    /integrations              → list
 *   GET    /integrations/providers    → providers for UI
 *   GET    /integrations/providers/details → provider details + known models
 *   POST   /integrations/validate     → validate API key
 *   POST   /integrations              → create
 *   PATCH  /integrations/<id>         → update
 *   POST   /integrations/<id>/toggle  → toggle active
 *   DELETE /integrations/provider/<provider> → delete by provider
 *   POST   /integrations/sync         → sync from localStorage
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Integrations extends PCM_REST_Base
{

    /**
     * Define all integration routes.
     *
     * @return array
     */
    protected function routes(): array
    {
        return array(
            // Read operations
                array('GET', '/integrations', 'list_items'),
                array('GET', '/integrations/providers', 'list_providers'),
                array('GET', '/integrations/providers/details', 'provider_details'),
                array('GET', '/integrations/brevo/senders', 'brevo_senders'),

            // Validation
                array('POST', '/integrations/validate', 'validate_api_key'),

            // CRUD
                array('POST', '/integrations', 'create_item'),
                array('PATCH', '/integrations/(?P<id>\\d+)', 'update_item'),
                array('POST', '/integrations/(?P<id>\\d+)/toggle', 'toggle_active'),
                array('DELETE', '/integrations/provider/(?P<provider>[\\w-]+)', 'delete_by_provider'),

            // Sync from localStorage
                array('POST', '/integrations/sync', 'sync_to_database'),
        );
    }

    /**
     * GET /integrations/brevo/senders — list the Brevo account's verified senders
     * so the user can PICK the "from" address (only verified senders deliver).
     * Returns array of { email, name, active }. Empty if no key / API error.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function brevo_senders(WP_REST_Request $request): WP_REST_Response
    {
        $pcm_user = $this->get_current_pcm_user();
        $key = $this->get_provider_api_key('brevo', (int) $pcm_user->id);
        if ($key === '') {
            return $this->success(array());
        }

        $resp = wp_remote_get('https://api.brevo.com/v3/senders', array(
            'headers' => array('api-key' => $key, 'Accept' => 'application/json'),
            'timeout' => 15,
        ));
        if (is_wp_error($resp)) {
            return $this->success(array());
        }

        $body    = json_decode((string) wp_remote_retrieve_body($resp), true);
        $senders = array();
        foreach ((array) ($body['senders'] ?? array()) as $s) {
            $email = sanitize_email((string) ($s['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $senders[] = array(
                'email'  => $email,
                'name'   => sanitize_text_field((string) ($s['name'] ?? '')),
                'active' => !empty($s['active']),
            );
        }
        return $this->success($senders);
    }

    /**
     * GET /integrations — List all integrations for the current user.
     *
     * TS original (integrations.ts:23-31) stored models as JSON text on the
     * integrations row and had a `name` column. The PHP port normalised models
     * into pcm_models and renamed `name` → `label`. This method reconstructs
     * the original response shape the frontend expects using existing DB helpers.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function list_items(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->get_current_pcm_user();
        $integrations = PCM_DB::get_user_integrations($user->id);

        // Fetch all user models once (single query) and group by provider.
        // TS original stored models as JSON on the integration row; PHP port
        // normalised them into the pcm_models table queried via get_user_models().
        $all_models = PCM_DB::get_user_models($user->id);
        $models_by_provider = array();
        foreach ($all_models as $model) {
            // Map capability flags → primary type matching TS schema:
            // z.enum(["image", "video", "text", "vision"])
            if ((int)$model->canGenerateVideo === 1) {
                $type = 'video';
            }
            elseif ((int)$model->canGenerateImage === 1) {
                $type = 'image';
            }
            elseif ((int)$model->canVision === 1) {
                $type = 'vision';
            }
            else {
                $type = 'text';
            }

            $models_by_provider[$model->provider][] = array(
                'id' => $model->modelId,
                'name' => $model->displayName ?? $model->modelId,
                'type' => $type,
            );
        }

        // Map DB columns → frontend shape (mirrors TS integrations.ts list)
        $result = array_map(function ($integration) use ($models_by_provider) {
            // TS: apiKey → first 8 chars + "..."
            $integration->apiKey = substr($integration->apiKey, 0, 8) . '...';
            // TS: name column; PHP port renamed to label
            $integration->name = $integration->label ?? $integration->provider;
            // TS: JSON.parse(i.models); PHP port normalised to pcm_models table
            $integration->models = $models_by_provider[$integration->provider] ?? array();
            return $integration;
        }, $integrations);

        return $this->success($result);
    }


    /**
     * GET /integrations/providers — Get all providers formatted for UI display.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function list_providers(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(PCM_Providers::get_for_ui());
    }

    /**
     * GET /integrations/providers/details — Get provider details with known models.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function provider_details(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(PCM_Providers::get_all_details());
    }

    /**
     * POST /integrations/validate — Validate an API key for a provider.
     * Calls the provider's API to verify the key works.
     *
     * @param WP_REST_Request $request Request with provider + apiKey.
     * @return WP_REST_Response|WP_Error
     */
    public function validate_api_key(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $provider = sanitize_text_field($request->get_param('provider'));
        $api_key = $request->get_param('apiKey'); // Don't sanitize API keys

        if (empty($provider) || empty($api_key)) {
            return $this->error('Provider and API key are required.');
        }

        $result = PCM_Providers::validate_api_key($provider, $api_key);

        return $this->success($result);
    }

    /**
     * POST /integrations — Create a new integration.
     *
     * @param WP_REST_Request $request Request with provider, name, apiKey, models.
     * @return WP_REST_Response|WP_Error
     */
    public function create_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();

        $provider = sanitize_text_field($request->get_param('provider'));
        $name = sanitize_text_field($request->get_param('name'));
        $api_key = $request->get_param('apiKey');
        $enabled_modules = $request->get_param('enabledModules') ?? array();
        $models = $request->get_param('models') ?? array();

        if (empty($provider) || empty($name) || empty($api_key)) {
            return $this->error('Provider, name, and API key are required.');
        }

        // Determine type from enabled modules
        $type = 'image';
        if (in_array('text', $enabled_modules, true)) {
            $type = 'text';
        }
        elseif (in_array('video', $enabled_modules, true)) {
            $type = 'video';
        }

        $integration_id = PCM_DB::create_integration(array(
            'userId' => $user->id,
            'provider' => $provider,
            'label' => $name,
            'apiKey' => $api_key,
            'type' => $type,
            'isActive' => 1,
        ));

        if (!$integration_id) {
            return $this->error('Failed to create integration.', 500);
        }

        return $this->success(
            array(
            'id' => $integration_id,
            'provider' => $provider,
            'name' => $name,
            'apiKey' => substr($api_key, 0, 8) . '...',
            'models' => $models,
            'enabledModules' => $enabled_modules,
            'isActive' => true,
        ),
            201
        );
    }

    /**
     * PATCH /integrations/<id> — Update an integration.
     *
     * @param WP_REST_Request $request Request with id + fields to update.
     * @return WP_REST_Response|WP_Error
     */
    public function update_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));

        // Check ownership
        $existing = PCM_DB::get_by_id('integrations', $id, $user->id);
        if (!$existing) {
            return $this->not_found('Integration');
        }

        $update = array();

        $name = $request->get_param('name');
        $api_key = $request->get_param('apiKey');
        $is_active = $request->get_param('isActive');

        if (null !== $name) {
            $update['label'] = sanitize_text_field($name);
        }
        if (null !== $api_key) {
            $update['apiKey'] = $api_key;
        }
        if (null !== $is_active) {
            $update['isActive'] = (int)rest_sanitize_boolean($is_active);
        }

        if (!empty($update)) {
            PCM_DB::update_by_id('integrations', $id, $user->id, $update);
        }

        return $this->success(array('success' => true));
    }

    /**
     * POST /integrations/<id>/toggle — Toggle active state.
     *
     * @param WP_REST_Request $request Request with id + isActive.
     * @return WP_REST_Response|WP_Error
     */
    public function toggle_active(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));
        $is_active = rest_sanitize_boolean($request->get_param('isActive'));

        $existing = PCM_DB::get_by_id('integrations', $id, $user->id);
        if (!$existing) {
            return $this->not_found('Integration');
        }

        PCM_DB::update_by_id('integrations', $id, $user->id, array(
            'isActive' => (int)$is_active,
        ));

        return $this->success(array('success' => true));
    }

    /**
     * DELETE /integrations/provider/<provider> — Delete integration by provider name.
     * Built-in providers cannot be deleted.
     *
     * @param WP_REST_Request $request Request with provider.
     * @return WP_REST_Response|WP_Error
     */
    public function delete_by_provider(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $provider = sanitize_text_field($request->get_param('provider'));

        // Block deletion of built-in providers
        if (PCM_Providers::is_built_in($provider)) {
            return $this->error('Built-in providers cannot be deleted.', 403);
        }

        // Find the user's integration for this provider
        $integrations = PCM_DB::get_user_integrations($user->id);
        $match = null;

        foreach ($integrations as $integration) {
            if ($integration->provider === $provider) {
                $match = $integration;
                break;
            }
        }

        if (!$match) {
            return $this->not_found('Integration');
        }

        PCM_DB::delete_integration($match->id, $user->id);

        return $this->success(array('success' => true));
    }

    /**
     * POST /integrations/sync — Sync an integration from localStorage to database.
     * Used for migrating existing integrations that were only saved locally.
     *
     * @param WP_REST_Request $request Request with provider, name, apiKey, models.
     * @return WP_REST_Response|WP_Error
     */
    public function sync_to_database(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();

        $provider = sanitize_text_field($request->get_param('provider'));

        // Check if integration already exists for this provider
        $integrations = PCM_DB::get_user_integrations($user->id);
        foreach ($integrations as $existing) {
            if ($existing->provider === $provider) {
                return $this->success(array('success' => true, 'action' => 'already_exists'));
            }
        }

        // Delegate to create flow
        return $this->create_item($request);
    }
}
