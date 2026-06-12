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

// Module-owned action handler (move an approval set to a lane). Required here —
// not in power-creatives.php — per the module-automations pattern; this file is
// loaded by the entry-point glob before any trigger can fire.
require_once __DIR__ . '/class-pcm-move-lane-action-handler.php';
require_once __DIR__ . '/class-pcm-notification-action-handler.php';

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
                array('value' => 'client',   'label' => __('Sent to Client for Approval', 'power-creatives')),
                array('value' => 'launch',   'label' => __('Launch', 'power-creatives')),
                array('value' => 'live',     'label' => __('Live', 'power-creatives')),
                array('value' => 'archived', 'label' => __('Archived', 'power-creatives')),
            ),
        ),
    ),
));

// Functional trigger — fires when a set is sent to the client (shared).
// Drives the editable "sent → move to 'Sent to Client for Approval' lane" rule.
PCM_Automation_Triggers::register(array(
    'id'              => 'approvals.set_shared',
    'module'          => 'approvals',
    'implemented'     => true,
    'label'           => __('Approval set is sent to client', 'power-creatives'),
    'description'     => __('Fires when an approval set is shared with the client.', 'power-creatives'),
    'contextKeys'     => array('setId', 'name', 'token', 'link', 'clientEmail', 'brandId'),
    'conditionFields' => array(),
));

// Functional trigger — fires when every asset in a set is approved.
// Drives the editable "fully approved → move to Launch" rule.
PCM_Automation_Triggers::register(array(
    'id'              => 'approvals.set_fully_approved',
    'module'          => 'approvals',
    'implemented'     => true,
    'label'           => __('Approval set is fully approved', 'power-creatives'),
    'description'     => __('Fires when every asset in an approval set has been approved.', 'power-creatives'),
    'contextKeys'     => array('setId', 'name', 'token', 'link', 'brandId'),
    'conditionFields' => array(),
));

// Functional action — move an approval set into a configurable lane. The target
// lane is user-editable on the rule. Registered with its handler so the engine
// can run it. See class-pcm-move-lane-action-handler.php.
PCM_Automation_Engine::register_action_handler(new PCM_Move_Lane_Action_Handler());
PCM_Automation_Engine::register_action_handler(new PCM_Notification_Action_Handler());

// Functional trigger — fires when anyone comments on an approval set (client
// comment or team reply). Context includes the enrichment block (brandName,
// deliveryName, projectName, projectAssignee, commentUrl, dashboardUrl).
PCM_Automation_Triggers::register(array(
    'id'              => 'approvals.comment_added',
    'module'          => 'approvals',
    'implemented'     => true,
    'label'           => __('Approval set receives a comment', 'power-creatives'),
    'description'     => __('Fires when a client or team member comments on an asset in an approval set.', 'power-creatives'),
    'contextKeys'     => array('setId', 'name', 'token', 'link', 'assetId', 'commentId', 'author', 'body', 'brandId', 'brandName', 'deliveryId', 'deliveryName', 'projectId', 'projectName', 'projectAssignee', 'commentUrl', 'dashboardUrl'),
    'conditionFields' => array(),
));

// Functional trigger — fires when an asset is approved (assetId='all' for the
// approve-all action). Only fires on the approve transition, never unapprove.
PCM_Automation_Triggers::register(array(
    'id'              => 'approvals.asset_approved',
    'module'          => 'approvals',
    'implemented'     => true,
    'label'           => __('Asset is approved in a set', 'power-creatives'),
    'description'     => __('Fires when the client approves an asset (or everything) in an approval set.', 'power-creatives'),
    'contextKeys'     => array('setId', 'name', 'token', 'link', 'assetId', 'brandId', 'brandName', 'deliveryId', 'deliveryName', 'projectId', 'projectName', 'projectAssignee', 'dashboardUrl'),
    'conditionFields' => array(),
));

// Functional action — write an in-app notification (badge + panel). Editable
// rule: disable it in Automations to mute a notification type.
PCM_Automation_Actions::register(array(
    'id'           => 'notifications.create',
    'module'       => 'approvals',
    'implemented'  => true,
    'label'        => __('Create an in-app notification', 'power-creatives'),
    'description'  => __('Adds an entry to the notifications panel (red badge on Approvals).', 'power-creatives'),
    'configFields' => array(),
    'inputSchema'  => array(),
));
PCM_Automation_Actions::register(array(
    'id'           => 'approvals.move_to_lane',
    'module'       => 'approvals',
    'implemented'  => true,
    'label'        => __('Move approval set to a lane', 'power-creatives'),
    'description'  => __('Move the approval set from the trigger into the chosen lane.', 'power-creatives'),
    'configFields' => array(
        array(
            'key'     => 'lane',
            'label'   => __('Move to lane', 'power-creatives'),
            'type'    => 'select',
            'default' => 'client',
            'options' => array(
                array('value' => 'draft',    'label' => __('Draft', 'power-creatives')),
                array('value' => 'internal', 'label' => __('Internal review', 'power-creatives')),
                array('value' => 'client',   'label' => __('Sent to Client for Approval', 'power-creatives')),
                array('value' => 'launch',   'label' => __('Launch', 'power-creatives')),
                array('value' => 'live',     'label' => __('Live', 'power-creatives')),
                array('value' => 'archived', 'label' => __('Archived', 'power-creatives')),
            ),
        ),
    ),
    'inputSchema'  => array(),
));

// Time-based trigger — fires while a set is *still* in the "Sent to Client for
// Approval" lane after `minDays` have passed since it entered. Re-fires every
// `minDays` after that (3, 6, 9, …) until the set leaves the lane. Emitted by the
// daily cron scanner registered in includes/modules/automations/service.php; the
// scanner reads `minDays` directly (the engine's equality matcher isn't used for
// this trigger), and dedupes via the engine's automation_logs.dedupeKey.
PCM_Automation_Triggers::register(array(
    'id'          => 'approvals.set_pending_in_client',
    'module'      => 'approvals',
    'implemented' => true,
    'label'       => __('Approval set is still awaiting client approval', 'power-creatives'),
    'description' => __('Fires while a set is in the "Sent to Client for Approval" lane after X days, and repeats every X days until it leaves the lane.', 'power-creatives'),
    'contextKeys' => array('setId', 'name', 'status', 'token', 'link', 'daysSinceSent', 'clientEmail', 'brandId'),
    'conditionFields' => array(
        array(
            'key'         => 'minDays',
            'label'       => __('Days in lane before reminding', 'power-creatives'),
            'type'        => 'number',
            'default'     => '3',
            'placeholder' => '3',
        ),
    ),
));
