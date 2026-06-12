<?php
/**
 * Move-Lane Action Handler (approvals.move_to_lane)
 *
 * The "THEN" side of the two core approval-flow rules, made editable:
 *   - IF a set is shared with the client  → move it to "Sent to Client for Approval".
 *   - IF a set is fully approved          → move it to "Launch".
 *
 * These transitions used to be hardcoded in approvals/service.php. They now run
 * as user-editable automation rules: the target lane is configurable, the rule
 * can be edited / toggled / deleted in the Automations module.
 *
 * The handler delegates to PCM_Approvals_Service::update_status(), which is
 * ownership-scoped, stamps clientSentAt on entry into the 'client' lane, and
 * fires `approvals.set_status_changed` so downstream rules (e.g. Launch → webhook)
 * chain naturally. update_status() only writes/fires when the lane actually
 * changes — so re-entry is a safe no-op and there is no trigger loop
 * (set_shared / set_fully_approved are distinct from set_status_changed).
 *
 * Mode: sync (a single ownership-scoped UPDATE).
 *
 * @package PowerCreatives
 * @since   1.18.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Move_Lane_Action_Handler implements PCM_Automation_Action_Handler
{
    public function id(): string
    {
        return 'approvals.move_to_lane';
    }

    public function mode(): string
    {
        return 'sync';
    }

    public function run(array $config, array $inputs, array $context, int $user_id): array
    {
        $lane   = isset($config['lane']) ? (string) $config['lane'] : '';
        $set_id = isset($context['setId']) ? (int) $context['setId'] : 0;

        $base = array('ok' => false, 'code' => 0, 'target' => $lane, 'error' => null, 'skipped' => false);

        // Incomplete config / context → skip (not an error), so the rule reports
        // cleanly until the user configures a valid lane.
        if (!class_exists('PCM_Approvals_Service')
            || $set_id <= 0
            || $user_id <= 0
            || !in_array($lane, PCM_Approvals_Service::STATUSES, true)
        ) {
            $base['skipped'] = true;
            $base['error']   = __('Missing or invalid target lane / set.', 'power-creatives');
            return $base;
        }

        // update_status() is ownership-scoped and returns false when the set is
        // not owned by the user OR when the lane is unchanged (no-op). Treat the
        // no-op case as a clean skip rather than a failure.
        $moved = PCM_Approvals_Service::update_status($set_id, $user_id, $lane);

        if ($moved) {
            $base['ok'] = true;
            return $base;
        }

        $base['skipped'] = true;
        $base['error']   = __('Set already in lane, not owned, or update failed.', 'power-creatives');
        return $base;
    }
}
