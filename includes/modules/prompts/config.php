<?php
if (!defined('ABSPATH')) {
    exit;
}
return [
    'id' => 'prompts',
    'name' => 'Prompts',
    'description' => 'User-editable system prompt overrides per module and section.',
    'version' => '1.0.0',
    'controller' => 'PCM_REST_Prompts',
    'rest_namespace' => 'pcm/v1/prompts',
];
