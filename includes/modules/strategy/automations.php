<?php
/**
 * Strategy — Automations registration
 *
 * Auto-loaded by the per-module "automations.php" glob in power-creatives.php
 * (loaded before rest_api_init, same as every other module's automations
 * file). Registers the action that reacts to the
 * EXISTING `approvals.set_fully_approved` trigger (owned by the Approvals
 * module) — plus the module's own `strategy.completed` trigger (Task E3),
 * fired from strategy/service.php when a strategy's generation finishes.
 *
 * @package PowerCreatives
 * @since   1.38.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('PCM_Automation_Actions') || !class_exists('PCM_Automation_Triggers')) {
    return;
}

// Module-owned action handler. Required here — not in power-creatives.php —
// per the established module-automations pattern (see approvals/automations.php).
require_once __DIR__ . '/class-pcm-publish-on-approval-action-handler.php';

PCM_Automation_Actions::register(array(
    'id'           => 'strategy.publish_on_approval',
    'module'       => 'strategy',
    'implemented'  => true,
    'label'        => __('Advance & publish a Strategy item when its approval set is fully approved', 'power-creatives'),
    'description'  => __('When a Strategy item was sent to Approvals for review, this advances it out of review and auto-publishes it (if Publish Mode is on) once its linked set is fully approved. Cleanly skips sets not linked to a Strategy item.', 'power-creatives'),
    'configFields' => array(),
    'inputSchema'  => array(),
));

PCM_Automation_Engine::register_action_handler(new PCM_Publish_On_Approval_Action_Handler());

// Functional trigger — fires once, when a strategy's generation finishes (all
// items completed or failed-out; see PCM_Strategy_Service::finalize_on_completion()).
// Drives the seeded "notify when a strategy finishes" in-app notification rule.
PCM_Automation_Triggers::register(array(
    'id'              => 'strategy.completed',
    'module'          => 'strategy',
    'implemented'     => true,
    'label'           => __('Strategy finishes generating', 'power-creatives'),
    'description'     => __('Fires once when every item in a strategy has finished generating (completed or failed).', 'power-creatives'),
    'contextKeys'     => array('strategyId', 'name', 'completedItems', 'totalItems'),
    'conditionFields' => array(),
));
