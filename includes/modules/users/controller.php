<?php
/**
 * Users REST Controller — admin-only user management.
 *
 * Routes (all require manage_options; nonce enforced by PCM_REST_Base):
 *   GET /users                      List every WP user mirrored as a PCM user,
 *                                   with live access level + assigned deliveries.
 *   PUT /users/{id}/deliveries      Replace a user's delivery assignments.
 *
 * Access level mirrors WordPress: administrators (manage_options) → 'admin',
 * everyone else → 'user'. The plugin never writes WP roles.
 *
 * @package PowerCreatives
 * @since   1.18.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Users extends PCM_REST_Base
{
    private PCM_Users_Service $service;

    public function __construct()
    {
        require_once __DIR__ . '/service.php';
        $this->service = new PCM_Users_Service();
    }

    protected function routes(): array
    {
        // 'manage_options:strict' — real WP admins only. These routes expose the
        // WP user directory (names + emails), so the shared-password shortcode
        // gate must NOT grant access.
        return array(
            array('GET', '/users',                              'list_items',       array(), 'manage_options:strict'),
            array('PUT', '/users/(?P<id>\d+)/deliveries',       'set_deliveries',   array(), 'manage_options:strict'),
        );
    }

    /** GET /users — all WP users with plugin level + assignments. */
    public function list_items(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            return $this->success($this->service->list_users());
        } catch (\Throwable $e) {
            return $this->error('Failed to list users: ' . $e->getMessage(), 500);
        }
    }

    /** PUT /users/{id}/deliveries — replace the user's assignment set. */
    public function set_deliveries(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $admin       = $this->get_current_pcm_user();
        $assignee_id = absint($request->get_param('id'));
        $params      = $request->get_json_params() ?: array();
        $raw_ids     = isset($params['deliveryIds']) && is_array($params['deliveryIds'])
            ? $params['deliveryIds']
            : null;

        if ($assignee_id <= 0 || $raw_ids === null) {
            return $this->error('deliveryIds (array) and a valid user id are required.');
        }

        $delivery_ids = array_values(array_unique(array_filter(array_map('absint', $raw_ids))));

        $result = $this->service->set_assignments($assignee_id, $delivery_ids, (int) $admin->id);
        if ($result instanceof WP_Error) {
            return $result;
        }

        return $this->success(array('assignedDeliveryIds' => $result));
    }
}
