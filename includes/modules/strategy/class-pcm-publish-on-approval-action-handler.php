<?php
/**
 * Publish-On-Approval Action Handler (strategy.publish_on_approval)
 *
 * The "THEN" side of Step 7's approval hand-off: when a Strategy item's article
 * was sent to the Approvals module for review (Step 6, approvalMode != 'none'),
 * this handler is what advances the item back out of review and auto-publishes
 * it once the linked approval set is fully approved. It runs on the EXISTING
 * `approvals.set_fully_approved` trigger — the strategy module never re-fires or
 * reimplements approval logic, it only reacts to it (Decision 2).
 *
 * A fully-approved set that has nothing to do with a strategy (Copy/Image/Writer
 * content sent to approval directly) is a clean, expected no-op here — most
 * approval sets are NOT strategy-linked, and this rule fires for every one of
 * them alongside the existing "move to Launch" rule.
 *
 * Mode: sync (a single ownership-scoped lookup + update).
 *
 * @package PowerCreatives
 * @since   1.38.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Publish_On_Approval_Action_Handler implements PCM_Automation_Action_Handler
{
    public function id(): string
    {
        return 'strategy.publish_on_approval';
    }

    public function mode(): string
    {
        return 'sync';
    }

    public function run(array $config, array $inputs, array $context, int $user_id): array
    {
        $set_id = isset($context['setId']) ? (int) $context['setId'] : 0;

        $base = array('ok' => false, 'code' => 0, 'target' => 'strategy_item', 'error' => null, 'skipped' => false);

        if ($set_id <= 0 || $user_id <= 0 || !class_exists('PCM_Strategy_Service')) {
            $base['skipped'] = true;
            $base['error']   = __('Missing set/user, or the strategy module is unavailable.', 'power-creatives');
            return $base;
        }

        $advanced = PCM_Strategy_Service::advance_item_on_approval($set_id, $user_id);

        if ($advanced === null) {
            // Not every approval set belongs to a strategy — this is the normal,
            // expected case for Copy/Image/Writer sets. Not an error.
            $base['skipped'] = true;
            $base['error']   = __('This approval set is not linked to a Strategy item awaiting review.', 'power-creatives');
            return $base;
        }

        $base['ok'] = true;
        return $base;
    }
}
