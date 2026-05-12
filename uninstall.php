<?php
/**
 * Uninstall Handler
 *
 * Runs when the user deletes the plugin from WordPress Admin.
 * This is separate from deactivation — deactivation preserves data.
 *
 * Removes:
 * - All custom database tables (wp_pcm_*)
 * - All plugin settings from wp_options
 * - DB version option
 *
 * @package PowerCreatives
 */

// Security: Only allow WordPress to run this
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Load required classes
require_once plugin_dir_path(__FILE__) . 'includes/core/class-pcm-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/core/db/class-pcm-schema.php';

// Drop all custom tables
PCM_Schema::drop_tables();

// Remove plugin settings
PCM_Settings::delete_all();

// Remove DB version tracking
delete_option('pcm_db_version');
