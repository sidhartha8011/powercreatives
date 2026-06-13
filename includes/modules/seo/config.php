<?php
/**
 * SEO Module Configuration
 *
 * Content-SEO suite ported from "Optimizer Simple" — a WP posts/pages SEO
 * workbench: inline meta editing with cross-plugin (Yoast/RankMath/SEOPress)
 * read-write, schema, AI optimization, and AI-readiness (llms.txt). Phase 1
 * ships the content list + cross-plugin SEO meta read/write core.
 *
 * Auto-discovered by PCM_Module_Loader.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    'id'             => 'seo',
    'name'           => 'SEO',
    'description'    => 'Content SEO workbench: cross-plugin meta editing, schema, and AI optimization for the site\'s posts and pages.',
    'version'        => '1.0.0',
    'controller'     => 'PCM_REST_SEO',
    'rest_namespace' => 'pcm/v1/seo',
];
