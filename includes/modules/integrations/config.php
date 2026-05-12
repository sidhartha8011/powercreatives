<?php
if (!defined('ABSPATH')) {
    exit;
}
return [
    'id' => 'integrations',
    'name' => 'Integrations',
    'description' => 'API integration management for AI providers (Google, OpenAI, Kie.ai).',
    'version' => '1.0.0',
    'controller' => 'PCM_REST_Integrations',
    'rest_namespace' => 'pcm/v1/integrations',
];
