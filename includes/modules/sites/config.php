<?php
/**
 * Module Config: Sites
 *
 * Connected WordPress sites for content publishing.
 * Handles site CRUD and article publishing via WP REST API + Application Passwords.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'id'             => 'sites',
    'name'           => 'Sites',
    'version'        => '1.0.0',
    'controller'     => 'PCM_REST_Sites',
    'rest_namespace' => 'pcm/v1/sites',
);
