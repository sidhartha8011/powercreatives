<?php
/**
 * Approvals REST Controller
 *
 * Handles creator management, token-secured public sharing, and client reviews.
 *
 * Endpoints:
 *   GET    /approvals/sets                           List creator sets
 *   POST   /approvals/sets                           Create an approval set
 *   GET    /approvals/sets/(?P<token>[a-zA-Z0-9_-]+)  Get public set by token (unauthenticated)
 *   POST   /approvals/sets/(?P<token>[a-zA-Z0-9_-]+)/review  Submit client feedback (unauthenticated)
 *   POST   /approvals/sets/(?P<token>[a-zA-Z0-9_-]+)/assets/(?P<asset_id>[a-zA-Z0-9_-]+)  Update snapshot asset (authenticated)
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Approvals extends PCM_REST_Base
{
    /**
     * Override base register() to support public unauthenticated routes.
     */
    public function register(): void
    {
        foreach ($this->routes() as $route_def) {
            $method     = $route_def[0];
            $path       = $route_def[1];
            $callback   = $route_def[2];
            $args       = $route_def[3] ?? array();
            $permission = $route_def[4] ?? 'manage_options';

            if ($permission === 'public') {
                register_rest_route(
                    $this->namespace,
                    $path,
                    array(
                        'methods'             => $method,
                        'callback'            => array($this, $callback),
                        'permission_callback' => '__return_true', // Public bypass
                        'args'                => $args,
                    )
                );
            } else {
                register_rest_route(
                    $this->namespace,
                    $path,
                    array(
                        'methods'             => $method,
                        'callback'            => array($this, $callback),
                        'permission_callback' => $this->make_permission_callback($permission),
                        'args'                => $args,
                    )
                );
            }
        }
    }

    protected function routes(): array
    {
        return array(
            array('GET',    '/approvals/sets',                          'list_sets',           array(), 'read'),
            array('POST',   '/approvals/sets',                          'create_set',          array(), 'edit_posts'),
            array('PATCH',  '/approvals/sets/(?P<id>\d+)/status',       'update_set_status',   array(), 'edit_posts'),
            array('DELETE', '/approvals/sets/(?P<id>\d+)',              'delete_set',          array(), 'edit_posts'),
            array('POST',   '/approvals/sets/bulk/delete',              'bulk_delete_sets',    array(), 'edit_posts'),
            array('GET',   '/approvals/sets/(?P<token>[a-zA-Z0-9_-]+)', 'get_public_set',      array(), 'public'),
            array('POST',  '/approvals/sets/(?P<token>[a-zA-Z0-9_-]+)/review', 'submit_public_review', array(), 'public'),
            array('POST',  '/approvals/sets/(?P<token>[a-zA-Z0-9_-]+)/draft', 'save_public_draft', array(), 'public'),
            array('POST',  '/approvals/sets/(?P<token>[a-zA-Z0-9_-]+)/assets/(?P<asset_id>[a-zA-Z0-9_-]+)', 'update_snapshot_asset', array(), 'public'),
        );
    }

    /**
     * List all approval sets owned by the logged-in user.
     */
    public function list_sets(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        require_once __DIR__ . '/service.php';

        try {
            $sets = PCM_Approvals_Service::list_sets_by_user((int)$pcm_user->id);
            return $this->success($sets);
        } catch (\Throwable $e) {
            return $this->error('Failed to list approval sets: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new approval set from selected working assets.
     */
    public function create_set(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $params   = $request->get_json_params();

        if (empty($params['name'])) {
            return $this->error('Approval set name is required.');
        }
        if (empty($params['snapshot'])) {
            return $this->error('Snapshot data cannot be empty.');
        }

        require_once __DIR__ . '/service.php';

        try {
            $set_id = PCM_Approvals_Service::create_set((int)$pcm_user->id, array(
                'name'      => sanitize_text_field($params['name']),
                'brandId'   => !empty($params['brandId']) ? (int)$params['brandId'] : null,
                'projectId' => !empty($params['projectId']) ? (int)$params['projectId'] : null,
                'snapshot'  => $params['snapshot'], // Sanitized in service layer
            ));

            if (!$set_id) {
                return $this->error('Failed to save approval set.', 500);
            }

            $set = PCM_Approvals_Service::get_set_by_id((int)$set_id, (int)$pcm_user->id);
            return $this->success($set, 201);
        } catch (\Throwable $e) {
            return $this->error('Failed to create approval set: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update an approval set's status (internal team operation).
     *
     * PATCH /approvals/sets/{id}/status  body: { status: <one of STATUSES> }
     */
    public function update_set_status(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $id       = (int)$request->get_param('id');
        $params   = $request->get_json_params();
        $next     = isset($params['status']) ? sanitize_text_field($params['status']) : '';

        if ($id <= 0 || $next === '') {
            return $this->error('Set id and status are required.');
        }

        require_once __DIR__ . '/service.php';

        if (!in_array($next, PCM_Approvals_Service::STATUSES, true)) {
            return $this->error('Invalid status: ' . $next);
        }

        try {
            $ok = PCM_Approvals_Service::update_status($id, (int)$pcm_user->id, $next);
            if (!$ok) {
                return $this->error('Set not found or not owned by this user.', 404);
            }
            $set = PCM_Approvals_Service::get_set_by_id($id, (int)$pcm_user->id);
            return $this->success($set);
        } catch (\Throwable $e) {
            return $this->error('Failed to update set status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a single approval set.
     *
     * DELETE /approvals/sets/{id}
     */
    public function delete_set(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $id       = (int)$request->get_param('id');

        if ($id <= 0) {
            return $this->error('Set id is required.');
        }

        require_once __DIR__ . '/service.php';

        try {
            $ok = PCM_Approvals_Service::delete_set($id, (int)$pcm_user->id);
            if (!$ok) {
                return $this->error('Set not found or not owned by this user.', 404);
            }
            return $this->success(array('id' => $id, 'deleted' => true));
        } catch (\Throwable $e) {
            return $this->error('Failed to delete set: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Bulk-delete approval sets owned by the caller.
     *
     * POST /approvals/sets/bulk/delete  body: { ids: number[] }
     */
    public function bulk_delete_sets(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $params   = $request->get_json_params();
        $ids      = isset($params['ids']) && is_array($params['ids']) ? $params['ids'] : array();

        if (empty($ids)) {
            return $this->error('ids array is required.');
        }

        require_once __DIR__ . '/service.php';

        try {
            $deleted = PCM_Approvals_Service::bulk_delete_sets($ids, (int)$pcm_user->id);
            return $this->success(array('deleted' => $deleted));
        } catch (\Throwable $e) {
            return $this->error('Failed to bulk-delete sets: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Retrieve a public approval set by token (unauthenticated).
     */
    public function get_public_set(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $token = sanitize_key($request->get_param('token'));
        require_once __DIR__ . '/service.php';

        try {
            $set = PCM_Approvals_Service::get_set_by_token($token);
            if (!$set) {
                return $this->not_found('Approval Set');
            }
            return $this->success($set);
        } catch (\Throwable $e) {
            return $this->error('Failed to retrieve approval set: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Submit client feedback (unauthenticated) and dispatch webhook.
     */
    public function submit_public_review(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $token  = sanitize_key($request->get_param('token'));
        $params = $request->get_json_params();

        if (!isset($params['feedback'])) {
            return $this->error('Feedback data is required.');
        }

        require_once __DIR__ . '/service.php';

        try {
            $success = PCM_Approvals_Service::submit_review(
                $token,
                sanitize_text_field($params['clientName'] ?? 'External Client'),
                $params['feedback']
            );

            if (!$success) {
                return $this->not_found('Approval Set');
            }

            return $this->success(array('success' => true));
        } catch (\Throwable $e) {
            return $this->error('Failed to submit client review: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Save client feedback draft in real-time (unauthenticated).
     */
    public function save_public_draft(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $token  = sanitize_key($request->get_param('token'));
        $params = $request->get_json_params();

        if (!isset($params['feedback'])) {
            return $this->error('Feedback data is required.');
        }

        require_once __DIR__ . '/service.php';

        try {
            $success = PCM_Approvals_Service::save_review_draft(
                $token,
                $params['feedback']
            );

            if (!$success) {
                return $this->not_found('Approval Set');
            }

            return $this->success(array('success' => true));
        } catch (\Throwable $e) {
            return $this->error('Failed to save client review draft: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update an individual asset inside the approval set snapshot (authenticated for team members).
     */
    public function update_snapshot_asset(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Require logged-in team member authorization
        if (!is_user_logged_in() || (!current_user_can('edit_posts') && !current_user_can('manage_options'))) {
            return $this->error('Unauthorized: only team members can edit assets.', 403);
        }

        $token    = sanitize_key($request->get_param('token'));
        $asset_id = sanitize_key($request->get_param('asset_id'));
        $params   = $request->get_json_params();

        require_once __DIR__ . '/service.php';

        try {
            $success = PCM_Approvals_Service::update_snapshot_asset($token, $asset_id, $params);
            if (!$success) {
                return $this->error('Failed to update asset or asset not found in snapshot.', 400);
            }
            return $this->success(array('success' => true));
        } catch (\Throwable $e) {
            return $this->error('Failed to update snapshot asset: ' . $e->getMessage(), 500);
        }
    }
}
