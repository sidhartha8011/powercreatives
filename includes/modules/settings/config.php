<?php
/**
 * Module Configuration: Settings
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'id' => 'settings',
    'name' => 'Settings',
    'version' => '1.0.0',
    'controller' => 'PCM_Settings_Controller',
    'rest_namespace' => 'pcm/v1',
);
