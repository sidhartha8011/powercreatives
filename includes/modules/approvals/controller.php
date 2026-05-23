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
            array('GET',  '/approvals/sets',                          'list_sets',           array(), 'read'),
            array('POST', '/approvals/sets',                          'create_set',          array(), 'edit_posts'),
            array('GET',  '/approvals/sets/(?P<token>[a-zA-Z0-9_-]+)', 'get_public_set',      array(), 'public'),
            array('POST', '/approvals/sets/(?P<token>[a-zA-Z0-9_-]+)/review', 'submit_public_review', array(), 'public'),
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
}
