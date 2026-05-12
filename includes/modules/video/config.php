<?php
if (!defined('ABSPATH')) {
    exit;
}
return [
    'id' => 'video',
    'name' => 'Video Generation',
    'description' => 'AI video generation — concepts, async generation, status polling, and capabilities.',
    'version' => '1.0.0',
    'controller' => 'PCM_REST_Video',
    'rest_namespace' => 'pcm/v1/video',
];
