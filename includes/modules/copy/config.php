<?php
if (!defined('ABSPATH')) {
    exit;
}
return [
    'id' => 'copy',
    'name' => 'Copy Generation',
    'description' => 'AI-powered ad copy generation for Social Ads and Social Organic with SSE streaming.',
    'version' => '1.0.0',
    'controller' => 'PCM_REST_Copy',
    'rest_namespace' => 'pcm/v1/copy',
];
