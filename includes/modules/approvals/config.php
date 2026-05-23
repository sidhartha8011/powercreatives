<?php
/**
 * Module Config: Approvals
 *
 * Handles client sharing, approval sets, granular reviews, and webhooks.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'id'             => 'approvals',
    'name'           => 'Approvals',
    'version'        => '1.0.0',
    'controller'     => 'PCM_REST_Approvals',
    'rest_namespace' => 'pcm/v1/approvals',
);
