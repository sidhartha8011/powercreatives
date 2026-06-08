<?php
/**
 * Brands — Automations registration
 *
 * Loaded by the glob in power-creatives.php. Declares the Brands module's
 * triggers for the cross-module Automations engine. Today: a functional
 * "brand created" trigger (emitted from brands/controller.php on a new insert).
 *
 * @package PowerCreatives
 * @since   1.16.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('PCM_Automation_Triggers')) {
    return;
}

// Functional trigger — fires when a brand is genuinely created (not on update).
PCM_Automation_Triggers::register(array(
    'id'          => 'brands.brand_created',
    'module'      => 'brands',
    'implemented' => true,
    'label'       => __('Brand created', 'power-creatives'),
    'description' => __('Fires when a new brand is created.', 'power-creatives'),
    'contextKeys' => array('brandId', 'name', 'website', 'niche'),
    'conditionFields' => array(),
));
