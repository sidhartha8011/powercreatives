<?php
if (!defined('ABSPATH')) {
    exit;
}
return [
    'id' => 'templates',
    'name' => 'Templates',
    'description' => 'Reusable templates that pre-fill form fields across Copy, Image, and Video modules.',
    'version' => '1.0.0',
    'controller' => 'PCM_REST_Templates',
    'rest_namespace' => 'pcm/v1/templates',
];
