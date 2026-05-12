<?php
/**
 * PHPUnit Bootstrap — initializes WP_Mock and loads minimal plugin stubs.
 *
 * This bootstrap does NOT load WordPress itself. Instead, it uses WP_Mock
 * to simulate WordPress functions and hooks, allowing fast unit tests
 * without a database or web server.
 *
 * @package PowerCreatives\Tests
 */

// Composer autoloader (loads WP_Mock, Mockery, PHPUnit)
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Initialize WP_Mock
WP_Mock::bootstrap();

// Define WordPress constants used by the plugin
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}
if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

// Plugin-level constants
if (!defined('PCM_PLUGIN_DIR')) {
    define('PCM_PLUGIN_DIR', dirname(__DIR__) . '/');
}
if (!defined('PCM_PLUGIN_URL')) {
    define('PCM_PLUGIN_URL', 'https://example.com/wp-content/plugins/power-creatives/');
}
if (!defined('PCM_VERSION')) {
    define('PCM_VERSION', '1.1.0-test');
}
