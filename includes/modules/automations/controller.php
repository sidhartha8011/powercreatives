<?php
/**
 * Automations REST Controller
 *
 * CRUD for user-defined automation rules (wp_pcm_automations) in the
 * trigger → condition → action model, plus a catalog endpoint (available
 * triggers + actions for the UI) and a test-send endpoint.
 *
 * All routes require `manage_options` — automations send outbound traffic on
 * the user's behalf, so they are admin-only.
 *
 * Endpoints (namespace pcm/v1):
 *   GET    /automations            List rules
 *   POST   /automations            Create a rule { triggerId, conditions, actionId, config, name?, isActive? }
 *   PATCH  /automations/{id}        Update a rule
 *   DELETE /automations/{id}        Delete a rule
 *   GET    /automations/catalog     Available triggers + actions (for the builder UI)
 *   POST   /automations/test        Send a test webhook/email
 *
 * @package PowerCreatives
 * @since   1.15.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Automations extends PCM_REST_Base
{
    protected function routes(): array
    {
        return array(
            array('GET',    '/automations',             'list_items',  array(), 'manage_options'),
            array('POST',   '/automations',             'create_item', array(), 'manage_options'),
            array('PATCH',  '/automations/(?P<id>\d+)',  'update_item', array(), 'manage_options'),
            array('DELETE', '/automations/(?P<id>\d+)',  'delete_item', array(), 'manage_options'),
            array('GET',    '/automations/catalog',      'catalog',     array(), 'manage_options'),
            array('POST',   '/automations/test',         'test_send',   array(), 'manage_options'),
        );
    }

    /** GET /automations — list the caller's automation rules. */
    public function list_items(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        try {
            return $this->success(PCM_Automation_Engine::list_rules((int) $user->id));
        } catch (\Throwable $e) {
            return $this->error('Failed to list automations: ' . $e->getMessage(), 500);
        }
    }

    /** GET /automations/catalog — triggers + actions for the builder UI. */
    public function catalog(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->success(array(
            'triggers' => PCM_Automation_Triggers::all(),
            'actions'  => PCM_Automation_Actions::all(),
        ));
    }

    /** POST /automations — create a rule. */
    public function create_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user   = $this->get_current_pcm_user();
        $params = $request->get_json_params() ?: array();

        $trigger = sanitize_text_field($params['triggerId'] ?? '');
        $action  = sanitize_text_field($params['actionId'] ?? '');

        if (!PCM_Automation_Triggers::is_valid($trigger)) {
            return $this->error('Invalid or unknown trigger: ' . $trigger);
        }
        if (!PCM_Automation_Actions::is_valid($action)) {
            return $this->error('Invalid or unknown action: ' . $action);
        }
        // Block "coming soon" (not-yet-implemented) catalog entries.
        $invalid = $this->assert_implemented($trigger, $action);
        if ($invalid instanceof WP_Error) {
            return $invalid;
        }

        try {
            $id = PCM_Automation_Engine::create_rule((int) $user->id, array(
                'name'         => isset($params['name']) ? sanitize_text_field($params['name']) : null,
                'triggerId'    => $trigger,
                'conditions'   => $this->sanitize_conditions($params['conditions'] ?? array()),
                'actionId'     => $action,
                'config'       => $this->sanitize_config($action, $params['config'] ?? array()),
                'inputMapping' => $this->sanitize_conditions($params['inputMapping'] ?? array()),
                'brandId'      => !empty($params['brandId']) ? (int) $params['brandId'] : null,
                'isActive'     => $params['isActive'] ?? true,
            ));

            if (!$id) {
                return $this->error('Failed to create automation.', 500);
            }
            return $this->success(array('id' => $id), 201);
        } catch (\Throwable $e) {
            return $this->error('Failed to create automation: ' . $e->getMessage(), 500);
        }
    }

    /** PATCH /automations/{id} — update a rule. */
    public function update_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user   = $this->get_current_pcm_user();
        $id     = (int) $request->get_param('id');
        $params = $request->get_json_params() ?: array();

        $update = array();

        if (isset($params['name'])) {
            $update['name'] = sanitize_text_field($params['name']);
        }
        if (isset($params['triggerId'])) {
            $trigger = sanitize_text_field($params['triggerId']);
            if (!PCM_Automation_Triggers::is_valid($trigger)) {
                return $this->error('Invalid trigger: ' . $trigger);
            }
            $update['triggerId'] = $trigger;
        }
        $action_for_config = null;
        if (isset($params['actionId'])) {
            $action_for_config = sanitize_text_field($params['actionId']);
            if (!PCM_Automation_Actions::is_valid($action_for_config)) {
                return $this->error('Invalid action: ' . $action_for_config);
            }
            $update['actionId'] = $action_for_config;
        }
        if (array_key_exists('conditions', $params)) {
            $update['conditions'] = $this->sanitize_conditions($params['conditions'] ?? array());
        }
        if (array_key_exists('config', $params)) {
            $update['config'] = $this->sanitize_config($action_for_config ?? 'webhook', $params['config'] ?? array());
        }
        if (array_key_exists('inputMapping', $params)) {
            $update['inputMapping'] = $this->sanitize_conditions($params['inputMapping'] ?? array());
        }
        if (array_key_exists('brandId', $params)) {
            $update['brandId'] = $params['brandId'];
        }
        if (isset($params['isActive'])) {
            $update['isActive'] = (bool) $params['isActive'];
        }

        try {
            $ok = PCM_Automation_Engine::update_rule($id, (int) $user->id, $update);
            if (!$ok) {
                return $this->not_found('Automation');
            }
            return $this->success(array('success' => true));
        } catch (\Throwable $e) {
            return $this->error('Failed to update automation: ' . $e->getMessage(), 500);
        }
    }

    /** DELETE /automations/{id} — delete a rule. */
    public function delete_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id   = (int) $request->get_param('id');

        try {
            $ok = PCM_Automation_Engine::delete_rule($id, (int) $user->id);
            if (!$ok) {
                return $this->not_found('Automation');
            }
            return $this->success(array('id' => $id, 'deleted' => true));
        } catch (\Throwable $e) {
            return $this->error('Failed to delete automation: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /automations/test — send a one-off test through an action channel.
     *
     * Body: { channel: 'webhook'|'email', config, to? }. Webhook blocks so the
     * HTTP status is returned; email sends a sample message to `to` via Brevo.
     */
    public function test_send(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user    = $this->get_current_pcm_user();
        $params  = $request->get_json_params() ?: array();
        $channel = sanitize_text_field($params['channel'] ?? 'webhook');

        if (!in_array($channel, array('webhook', 'email'), true)) {
            return $this->error('Invalid channel: ' . $channel);
        }

        $config  = $this->sanitize_config($channel === 'email' ? 'email' : 'webhook', $params['config'] ?? array());
        $context = array(
            'event'  => 'automations.test',
            'name'   => __('Test automation', 'power-creatives'),
            'link'   => home_url('/'),
            'status' => 'launch',
            'setId'  => 0,
        );

        try {
            if ($channel === 'webhook') {
                $config['blocking'] = true; // We want the HTTP result for a test.
                $result = (new PCM_Webhook_Channel())->send($config, $context, (int) $user->id);
            } else {
                $to = sanitize_email($params['to'] ?? '');
                if ($to === '' || !is_email($to)) {
                    return $this->error('A valid "to" email is required to test the email channel.');
                }
                $context['email'] = array(
                    'to'      => $to,
                    'subject' => __('Power Creatives — test email', 'power-creatives'),
                    'html'    => '<p>' . esc_html__('This is a test email from Power Creatives Automations. If you received this, your Brevo integration works.', 'power-creatives') . '</p>',
                );
                $result = (new PCM_Brevo_Email_Channel())->send($config, $context, (int) $user->id);
            }

            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error('Test dispatch failed: ' . $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Reject rules that target a not-yet-implemented ("coming soon") trigger or
     * action (registered for catalog visibility but with no emitter/handler).
     *
     * @param string $trigger Trigger id.
     * @param string $action  Action id.
     * @return WP_Error|true
     */
    private function assert_implemented(string $trigger, string $action)
    {
        $t = PCM_Automation_Triggers::get($trigger);
        if (is_array($t) && array_key_exists('implemented', $t) && !$t['implemented']) {
            return $this->error('This trigger is coming soon and cannot be used yet: ' . $trigger);
        }
        $a = PCM_Automation_Actions::get($action);
        if (is_array($a) && array_key_exists('implemented', $a) && !$a['implemented']) {
            return $this->error('This action is coming soon and cannot be used yet: ' . $action);
        }
        return true;
    }

    /**
     * Sanitize a rule's conditions to a flat string→string map.
     *
     * @param mixed $conditions Raw conditions.
     * @return array<string, string>
     */
    private function sanitize_conditions($conditions): array
    {
        if (!is_array($conditions)) {
            return array();
        }
        $clean = array();
        foreach ($conditions as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $clean[sanitize_text_field((string) $key)] = sanitize_text_field((string) $value);
        }
        return $clean;
    }

    /**
     * Sanitize an action's config, keeping only known keys per action.
     *
     * @param string $action Action id.
     * @param mixed  $config Raw config.
     * @return array
     */
    private function sanitize_config(string $action, $config): array
    {
        if (!is_array($config)) {
            return array();
        }

        if ($action === 'webhook') {
            $clean = array();
            if (isset($config['url'])) {
                $clean['url'] = esc_url_raw((string) $config['url']);
            }
            if (isset($config['secret'])) {
                $clean['secret'] = sanitize_text_field((string) $config['secret']);
            }
            return $clean;
        }

        // email (test channel)
        $clean = array();
        if (isset($config['fromEmail'])) {
            $clean['fromEmail'] = sanitize_email((string) $config['fromEmail']);
        }
        if (isset($config['fromName'])) {
            $clean['fromName'] = sanitize_text_field((string) $config['fromName']);
        }
        return $clean;
    }
}
