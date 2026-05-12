<?php
/**
 * Keyword Explorer Module Configuration
 *
 * Auto-discovered by PCM_Module_Loader.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    'id'             => 'keywords',
    'name'           => 'Keyword Explorer',
    'description'    => 'Google Autocomplete keyword research with Ahrefs enrichment.',
    'version'        => '1.0.0',
    'controller'     => 'PCM_REST_Keywords',
    'rest_namespace' => 'pcm/v1/keywords',
];
