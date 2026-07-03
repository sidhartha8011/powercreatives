<?php
/**
 * Users REST Controller — admin-only user management.
 *
 * Routes (all require manage_options; nonce enforced by PCM_REST_Base):
 *   GET  /users                     List every WP user mirrored as a PCM user
 *                                   plus platform (username+password) users,
 *                                   with live access level + assigned deliveries.
 *   POST /users                     Create a platform login user (username+password).
 *   PUT  /users/{id}/password       Reset a platform user's password.
 *   DELETE /users/{id}              Delete a platform user.
 *   PUT  /users/{id}/deliveries     Replace a user's delivery assignments.
 *
 * Access level mirrors WordPress: administrators (manage_options) → 'admin',
 * everyone else → 'user'. The plugin never writes WP roles. Platform users are
 * the accounts a visitor uses at the [power_creatives] shortcode gate.
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
        // 'manage_options:coadmin' — a real WP admin OR a platform admin (a
        // gate-login user with role='admin'). Lets multiple admins manage platform
        // users + assign deliveries; a normal platform user is still refused, and
        // these routes only ever touch platform users (never WP accounts).
        return array(
            array('GET',    '/users',                           'list_items',       array(), 'manage_options:coadmin'),
            array('POST',   '/users',                           'create_item',      array(), 'manage_options:coadmin'),
            array('PUT',    '/users/(?P<id>\d+)/password',      'set_password',     array(), 'manage_options:coadmin'),
            array('PUT',    '/users/(?P<id>\d+)/role',          'set_role',         array(), 'manage_options:coadmin'),
            array('DELETE', '/users/(?P<id>\d+)',               'delete_item',      array(), 'manage_options:coadmin'),
            array('PUT',    '/users/(?P<id>\d+)/deliveries',    'set_deliveries',   array(), 'manage_options:coadmin'),
        );
    }

    /** POST /users — create a platform login user (username + password). */
    public function create_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_json_params() ?: array();
        $result = $this->service->create_user(array(
            'username' => (string) ($params['username'] ?? ''),
            'password' => (string) ($params['password'] ?? ''),
            'name'     => (string) ($params['name'] ?? ''),
            'email'    => (string) ($params['email'] ?? ''),
            'role'     => (string) ($params['role'] ?? 'user'),
        ));
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** PUT /users/{id}/password — reset a platform user's password. */
    public function set_password(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_id = absint($request->get_param('id'));
        $params = $request->get_json_params() ?: array();
        $result = $this->service->set_password($pcm_id, (string) ($params['password'] ?? ''));
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success(array('updated' => true));
    }

    /** PUT /users/{id}/role — change a platform user's access level (admin|user). */
    public function set_role(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_id = absint($request->get_param('id'));
        $params = $request->get_json_params() ?: array();
        $result = $this->service->set_role($pcm_id, (string) ($params['role'] ?? ''));
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success(array('updated' => true, 'role' => $result));
    }

    /** DELETE /users/{id} — delete a platform user. */
    public function delete_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_id = absint($request->get_param('id'));
        $result = $this->service->delete_user($pcm_id);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success(array('deleted' => true));
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
