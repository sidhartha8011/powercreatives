<?php
/**
 * Module Config: Writer
 *
 * Article editor and document management.
 * Consumes content from the Strategy module's generation pipeline.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'id'             => 'writer',
    'name'           => 'Content Writer',
    'version'        => '1.0.0',
    'controller'     => 'PCM_REST_Writer',
    'rest_namespace' => 'pcm/v1/articles',
);
