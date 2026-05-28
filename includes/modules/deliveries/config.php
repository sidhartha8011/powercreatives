<?php
/**
 * Deliveries Module Configuration
 *
 * Declares the Deliveries module for auto-discovery by PCM_Module_Loader.
 * A delivery is a continual-fulfilment unit for a client — other modules
 * (Approvals, Projects) reference it via deliveryId on their own payloads.
 *
 * @package PowerCreatives
 * @since   1.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    'id' => 'deliveries',
    'name' => 'Deliveries',
    'description' => 'Continual-fulfilment client deliveries with a Kanban pipeline (Active / Paused / Completed).',
    'version' => '1.0.0',
    'controller' => 'PCM_REST_Deliveries',
    'rest_namespace' => 'pcm/v1/deliveries',
];
