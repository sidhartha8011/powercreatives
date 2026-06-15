<?php
/**
 * SEO Hub Module Configuration — manage remote WordPress sites.
 *
 * Each managed site installs a generated, self-contained "connector" plugin
 * that registers with this hub via an HMAC-signed handshake and exposes its
 * SEO meta over the standard WP REST API (Application Password auth), so the
 * hub can read/write a client's SEO across many sites.
 *
 * Auto-discovered by PCM_Module_Loader.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    'id'             => 'seohub',
    'name'           => 'SEO Hub',
    'description'    => 'Manage SEO across multiple remote WordPress sites via generated connectors.',
    'version'        => '1.0.0',
    'controller'     => 'PCM_REST_SEOHub',
    'rest_namespace' => 'pcm/v1/seohub',
];
