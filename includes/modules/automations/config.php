<?php
/**
 * Automations Module Configuration
 *
 * Declares the Automations module for auto-discovery by PCM_Module_Loader.
 * The Automations module is the shared dispatch layer that turns domain events
 * (currently from Approvals) into outbound webhooks and Brevo emails. This
 * config exposes REST CRUD for automation rules plus a test-send endpoint.
 *
 * The engine + channels themselves are loaded eagerly in power-creatives.php
 * (before modules) so other modules can dispatch during their own requests.
 *
 * @package PowerCreatives
 * @since   1.14.0
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    'id' => 'automations',
    'name' => 'Automations',
    'description' => 'Event-driven webhooks and transactional email (Brevo) triggered by Approvals.',
    'version' => '1.0.0',
    'controller' => 'PCM_REST_Automations',
    'rest_namespace' => 'pcm/v1/automations',
];
