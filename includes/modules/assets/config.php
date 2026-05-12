<?php
if (!defined('ABSPATH')) {
    exit;
}
return [
    'id' => 'assets',
    'name' => 'Assets',
    'description' => 'CRUD for generated images/videos, AI refine, variations, export, and project management.',
    'version' => '1.0.0',
    'controller' => 'PCM_REST_Assets',
    'rest_namespace' => 'pcm/v1/assets',
];
