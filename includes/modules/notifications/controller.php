<?php
/**
 * Notifications REST Controller.
 *
 * Routes (nonce enforced by PCM_REST_Base; edit_posts so every team member
 * can read their own scoped feed — scoping itself is server-side):
 *   GET  /notifications        → { items: [...latest 50 visible], unseen: int }
 *   POST /notifications/seen   → mark everything seen for the current user.
 *   POST /notifications/clear  → hide all past notifications for the current user.
 *
 * @package PowerCreatives
 * @since   1.19.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Notifications extends PCM_REST_Base
{
    // Work module — usable by non-admin team members (scoped feed).
    protected string $default_capability = 'edit_posts';

    private PCM_Notifications_Service $service;

    public function __construct()
    {
        require_once __DIR__ . '/service.php';
        $this->service = new PCM_Notifications_Service();
    }

    protected function routes(): array
    {
        return array(
            array('GET',  '/notifications',       'list_items'),
            array('POST', '/notifications/seen',  'mark_seen'),
            array('POST', '/notifications/clear', 'clear_all'),
        );
    }

    /** GET /notifications — visible items + unseen count for the caller. */
    public function list_items(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $user = $this->get_current_pcm_user();
            return $this->success($this->service->list_for_user($user));
        } catch (\Throwable $e) {
            return $this->error('Failed to list notifications: ' . $e->getMessage(), 500);
        }
    }

    /** POST /notifications/seen — reset the caller's unseen badge. */
    public function mark_seen(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $this->service->mark_seen((int) $user->id);
        return $this->success(array('success' => true));
    }

    /** POST /notifications/clear — hide all past notifications for the caller. */
    public function clear_all(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $this->service->clear_all((int) $user->id);
        return $this->success(array('success' => true));
    }
}
