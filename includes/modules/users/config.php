<?php
/**
 * Users module config — admin-only user management.
 *
 * Lists every WordPress user (mirrored into wp_pcm_users) with their plugin
 * access level (admin/user, derived live from WP capabilities) and manages
 * delivery assignments that grant team members view+use access to the
 * delivery's linked brand and project.
 *
 * @package PowerCreatives
 * @since   1.18.0
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'id'             => 'users',
    'name'           => 'Users',
    'description'    => 'Admin-only user management: WP-mirrored users, access levels, delivery assignments.',
    'version'        => '1.0.0',
    'controller'     => 'PCM_REST_Users',
    'rest_namespace' => 'pcm/v1/users',
);
