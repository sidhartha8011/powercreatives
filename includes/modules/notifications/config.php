<?php
/**
 * Notifications module config — approval-flow activity feed.
 *
 * Read model for the notification rows written by the automations action
 * `notifications.create` (badge + side panel in the SPA). Visibility is
 * role-scoped at read time: admins see everything, others see events for
 * sets they own or brands granted via delivery assignments.
 *
 * @package PowerCreatives
 * @since   1.19.0
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'id'             => 'notifications',
    'name'           => 'Notifications',
    'description'    => 'Approval-flow notifications: unseen badge count + panel feed, role-scoped.',
    'version'        => '1.0.0',
    'controller'     => 'PCM_REST_Notifications',
    'rest_namespace' => 'pcm/v1/notifications',
);
