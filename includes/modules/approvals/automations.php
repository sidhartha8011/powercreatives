<?php
/**
 * Approvals — Automations registration
 *
 * Auto-loaded by PCM_Module_Loader::discover(). This is where the Approvals
 * module declares the triggers/actions it contributes to the cross-module
 * Automations engine. Today: the functional "set changes lane" trigger (emitted
 * from approvals/service.php on every status change).
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

// Functional trigger — fires whenever an approval set enters a lane.
PCM_Automation_Triggers::register(array(
    'id'          => 'approvals.set_status_changed',
    'module'      => 'approvals',
    'implemented' => true,
    'label'       => __('Approval set changes lane', 'power-creatives'),
    'description' => __('Fires when an approval set moves into a lane (e.g. Launch).', 'power-creatives'),
    'contextKeys' => array('setId', 'name', 'status', 'token', 'link', 'brandId'),
    'conditionFields' => array(
        array(
            'key'     => 'status',
            'label'   => __('Lane', 'power-creatives'),
            'type'    => 'select',
            'default' => 'launch',
            'options' => array(
                array('value' => 'draft',    'label' => __('Draft', 'power-creatives')),
                array('value' => 'internal', 'label' => __('Internal review', 'power-creatives')),
                array('value' => 'client',   'label' => __('Awaiting client approval', 'power-creatives')),
                array('value' => 'launch',   'label' => __('Launch', 'power-creatives')),
                array('value' => 'live',     'label' => __('Live', 'power-creatives')),
                array('value' => 'archived', 'label' => __('Archived', 'power-creatives')),
            ),
        ),
    ),
));
