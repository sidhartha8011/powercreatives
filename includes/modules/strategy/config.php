<?php
/**
 * Module Config: Strategy
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'id'             => 'strategy',
    'name'           => 'Content Strategies',
    'version'        => '1.0.0',
    'controller'     => 'PCM_REST_Strategy',
    'rest_namespace' => 'pcm/v1/strategies',
);
