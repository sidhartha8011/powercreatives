<?php
if (!defined('ABSPATH')) {
    exit;
}
return [
    'id' => 'image',
    'name' => 'Image Generation',
    'description' => 'AI image generation — concepts, single/batch generation, editing, and upscaling.',
    'version' => '1.0.0',
    'controller' => 'PCM_REST_Image',
    'rest_namespace' => 'pcm/v1/image',
];
