<?php
if (!defined('ABSPATH')) {
    exit;
}
return [
    'id' => 'scraper',
    'name' => 'Asset Scraper',
    'description' => 'URL scraping for images, AI Vision analysis, and smart image selection for ad creatives.',
    'version' => '1.0.0',
    'controller' => 'PCM_REST_Scraper',
    'rest_namespace' => 'pcm/v1/scraper',
];
