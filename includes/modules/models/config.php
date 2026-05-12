<?php
if (!defined('ABSPATH')) {
    exit;
}
return [
    'id' => 'models',
    'name' => 'Models',
    'description' => 'Unified AI model management — CRUD, capability detection, sync, and bulk operations.',
    'version' => '1.0.0',
    'controller' => 'PCM_REST_Models',
    'rest_namespace' => 'pcm/v1/models',
];
