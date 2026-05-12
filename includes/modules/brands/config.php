<?php
/**
 * Brands Module Configuration
 *
 * Declares the Brands module for auto-discovery by PCM_Module_Loader.
 * Brand management includes CRUD for business profiles, asset handling
 * (logo uploads, URL fetching), color extraction, and bulk operations.
 *
 * @package PowerCreatives
 * @since   1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    'id' => 'brands',
    'name' => 'Brand Management',
    'description' => 'Business profiles with logo assets, color palettes, and brand identity data.',
    'version' => '1.0.0',
    'controller' => 'PCM_REST_Brands',
    'rest_namespace' => 'pcm/v1/brands',
];
