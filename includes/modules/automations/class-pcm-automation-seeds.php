<?php
/**
 * Automation Seeds — preconfigured default rules
 *
 * Seeds the platform-default automations per user (idempotent, default ON):
 *   1. Set is sent to client                   → move to "Sent to Client" lane.
 *   2. Set is fully approved                    → move to Launch lane.
 *   3. Set receives a comment                   → in-app notification.
 *   4. Asset approved in a set                  → in-app notification.
 *   5. Approval set enters Launch lane          → webhook (user fills URL).
 *   6. Pending in "Sent to Client" >X days      → email the client.
 *   7. Pending in "Sent to Client" >X days      → webhook the team (user fills URL).
 *
 * Idempotency: each seeded rule carries a `__seedKey` in its `config` JSON; the
 * seeder uses that marker to recognise already-seeded rules and skip them (the
 * controller's sanitize_config drops unknown keys from request input, so this
 * marker can only come from the seeder — safe).
 *
 * Called from:
 *  - PCM_REST_Base::get_current_pcm_user() right after PCM_Prompt_Seeds — covers
 *    every newly auto-created PCM user.
 *  - PCM_Activator::maybe_upgrade() (1.17.0 gate) — covers existing users on upgrade.
 *
 * Users can edit any field, toggle off, or delete — there is no auto-restore.
 *
 * @package PowerCreatives
 * @since   1.17.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Automation_Seeds
{
    /** Identifying marker stored inside each seeded rule's config JSON. */
    private const SEED_KEY_FIELD = '__seedKey';

    /**
     * Seed all default rules for a user. Idempotent.
     *
     * @param int $user_id PCM user id.
     * @return int Number of rules actually inserted (0 when already seeded).
     */
    public static function seed_for_user(int $user_id): int
    {
        if ($user_id <= 0 || !class_exists('PCM_Automation_Engine')) {
            return 0;
        }

        $inserted = 0;
        foreach (self::definitions() as $seed_key => $rule) {
            if (self::is_seeded($user_id, $seed_key)) {
                continue;
            }
            $config = is_array($rule['config'] ?? null) ? $rule['config'] : array();
            $config[self::SEED_KEY_FIELD] = $seed_key;

            $id = PCM_Automation_Engine::create_rule($user_id, array(
                'name'         => $rule['name'],
                'triggerId'    => $rule['triggerId'],
                'conditions'   => $rule['conditions'] ?? array(),
                'actionId'     => $rule['actionId'],
                'config'       => $config,
                'inputMapping' => $rule['inputMapping'] ?? array(),
                'isActive'     => true,
            ));
            if ($id) {
                $inserted++;
            }
        }
        return $inserted;
    }

    /**
     * Whether a rule with the given seed key already exists for the user.
     *
     * @param int    $user_id  PCM user id.
     * @param string $seed_key Seed identifier.
     * @return bool
     */
    private static function is_seeded(int $user_id, string $seed_key): bool
    {
        global $wpdb;
        $table = PCM_Schema::table('automations');
        // The seed key is embedded inside the config JSON. A LIKE on the JSON
        // blob is cheap (the user has few rules) and avoids JSON-function deps
        // across MySQL/SQLite.
        $needle = '%"' . self::SEED_KEY_FIELD . '":"' . $seed_key . '"%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE userId = %d AND config LIKE %s LIMIT 1",
            $user_id,
            $needle
        ));
        return !empty($found);
    }

    /**
     * The default rule set. Keyed by stable seed id (stored in config to detect
     * already-seeded rules). All rules are created with `isActive => true`.
     * No hardcoded business detail — URLs / minDays / messages are user-editable.
     *
     * @return array<string, array>
     */
    private static function definitions(): array
    {
        return array(
            // Core approval-flow transitions, made editable (were hardcoded in
            // approvals/service.php). Default ON; users can change the lane,
            // toggle off, or delete.
            'approvals.flow.sent_to_client' => array(
                'name'         => __('Move to "Sent to Client for Approval" when shared', 'power-creatives'),
                'triggerId'    => 'approvals.set_shared',
                'conditions'   => array(),
                'actionId'     => 'approvals.move_to_lane',
                'config'       => array('lane' => 'client'),
                'inputMapping' => array(),
            ),
            'approvals.notify.comment' => array(
                'name'         => __('Notify on new approval-set comment', 'power-creatives'),
                'triggerId'    => 'approvals.comment_added',
                'conditions'   => array(),
                'actionId'     => 'notifications.create',
                'config'       => array(),
                'inputMapping' => array(),
            ),
            'approvals.notify.approval' => array(
                'name'         => __('Notify on asset approval', 'power-creatives'),
                'triggerId'    => 'approvals.asset_approved',
                'conditions'   => array(),
                'actionId'     => 'notifications.create',
                'config'       => array(),
                'inputMapping' => array(),
            ),
            'approvals.flow.fully_approved_launch' => array(
                'name'         => __('Move to Launch when fully approved', 'power-creatives'),
                'triggerId'    => 'approvals.set_fully_approved',
                'conditions'   => array(),
                'actionId'     => 'approvals.move_to_lane',
                'config'       => array('lane' => 'launch'),
                'inputMapping' => array(),
            ),
            'approvals.launch.webhook' => array(
                'name'         => __('Notify team on Launch (webhook)', 'power-creatives'),
                'triggerId'    => 'approvals.set_status_changed',
                'conditions'   => array('status' => 'launch'),
                'actionId'     => 'webhook',
                // Empty URL → channel reports `skipped` until the user fills it in.
                'config'       => array('url' => '', 'secret' => ''),
                'inputMapping' => array(),
            ),
            'approvals.pending_client.email' => array(
                'name'         => __('Remind client when waiting on approval (email)', 'power-creatives'),
                'triggerId'    => 'approvals.set_pending_in_client',
                'conditions'   => array('minDays' => '3'),
                'actionId'     => 'email.send',
                'config'       => array(),
                'inputMapping' => array(
                    'to'      => '{{clientEmail}}',
                    'subject' => 'Reminder: please review {{name}}',
                    'message' => "Hi!\n\nThis is a friendly reminder that {{name}} is waiting for your review.\n\nOpen the board: {{link}}\n\nThanks!",
                ),
            ),
            'approvals.pending_client.webhook' => array(
                'name'         => __('Remind team when waiting on client (webhook)', 'power-creatives'),
                'triggerId'    => 'approvals.set_pending_in_client',
                'conditions'   => array('minDays' => '3'),
                'actionId'     => 'webhook',
                'config'       => array('url' => '', 'secret' => ''),
                'inputMapping' => array(),
            ),
        );
    }
}
