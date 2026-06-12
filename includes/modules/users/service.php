<?php
/**
 * Users Service — WP-user mirroring + delivery assignments.
 *
 * @package PowerCreatives
 * @since   1.18.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Users_Service
{
    /**
     * Every WordPress user, mirrored into wp_pcm_users (rows created on
     * demand so users are assignable before their first login), with the
     * live plugin access level and current delivery assignments.
     *
     * @return array[]
     */
    public function list_users(): array
    {
        $wp_users = get_users(array('orderby' => 'display_name', 'order' => 'ASC'));

        // Mirror: make sure a pcm row exists for every WP user, and collect ids.
        $items = array();
        foreach ($wp_users as $wp_user) {
            $open_id  = 'wp_' . $wp_user->ID;
            $pcm_user = PCM_DB::get_user_by_open_id($open_id);
            if (!$pcm_user) {
                PCM_DB::upsert_user(array(
                    'openId'    => $open_id,
                    'name'      => $wp_user->display_name,
                    'email'     => $wp_user->user_email,
                    'role'      => user_can($wp_user, 'manage_options') ? 'admin' : 'user',
                    'avatarUrl' => get_avatar_url($wp_user->ID),
                ));
                $pcm_user = PCM_DB::get_user_by_open_id($open_id);
                // Seed defaults exactly like get_current_pcm_user() does for
                // self-created rows — without this, mirrored users have NO
                // automation rules (their approval sets never produce
                // notifications) and no default prompts.
                if ($pcm_user) {
                    if (class_exists('PCM_Prompt_Seeds')) {
                        PCM_Prompt_Seeds::seed_for_user((int) $pcm_user->id);
                    }
                    if (class_exists('PCM_Automation_Seeds')) {
                        PCM_Automation_Seeds::seed_for_user((int) $pcm_user->id);
                    }
                }
            }
            if (!$pcm_user) {
                continue; // degenerate: insert failed — skip rather than fatal.
            }

            $items[] = array(
                'pcmId'     => (int) $pcm_user->id,
                'wpUserId'  => (int) $wp_user->ID,
                'name'      => $wp_user->display_name,
                'email'     => $wp_user->user_email,
                'avatarUrl' => get_avatar_url($wp_user->ID),
                // LIVE level — always mirrors current WP capabilities.
                'role'      => user_can($wp_user, 'manage_options') ? 'admin' : 'user',
            );
        }

        // Attach assignments in one query.
        $by_user = $this->assignments_for_users(array_column($items, 'pcmId'));
        foreach ($items as &$item) {
            $item['assignedDeliveryIds'] = $by_user[$item['pcmId']] ?? array();
        }
        unset($item);

        return $items;
    }

    /**
     * Replace a user's delivery assignment set. Every delivery must belong to
     * the calling admin (ownership check) — you can only hand out access to
     * your own deliveries.
     *
     * @param int   $assignee_id  PCM user id receiving access.
     * @param int[] $delivery_ids Deliveries to assign (validated, deduped).
     * @param int   $admin_id     Calling admin's PCM user id.
     * @return int[]|WP_Error Final assigned ids, or error.
     */
    public function set_assignments(int $assignee_id, array $delivery_ids, int $admin_id): array|WP_Error
    {
        global $wpdb;

        // Assignee must exist.
        $users_table = PCM_Schema::table('users');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $assignee = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$users_table} WHERE id = %d",
            $assignee_id
        ));
        if (!$assignee) {
            return new WP_Error('pcm_user_not_found', __('User not found.', 'power-creatives'), array('status' => 404));
        }

        // Every requested delivery must be OWNED by the calling admin.
        $deliveries_table = PCM_Schema::table('deliveries');
        if (!empty($delivery_ids)) {
            $placeholders = implode(',', array_fill(0, count($delivery_ids), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
            $owned = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$deliveries_table} WHERE userId = %d AND id IN ({$placeholders})",
                $admin_id,
                ...$delivery_ids
            ));
            if (count($owned) !== count($delivery_ids)) {
                return new WP_Error(
                    'pcm_delivery_not_owned',
                    __('One or more deliveries do not exist or are not yours to assign.', 'power-creatives'),
                    array('status' => 403)
                );
            }
        }

        // Replace the set: remove assignments (for the admin's deliveries) not
        // in the new list, insert missing ones. Assignments made by OTHER
        // admins on their own deliveries are left untouched.
        $assignments_table = PCM_Schema::table('delivery_assignments');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $current = $wpdb->get_col($wpdb->prepare(
            "SELECT a.deliveryId
             FROM {$assignments_table} a
             INNER JOIN {$deliveries_table} d ON d.id = a.deliveryId
             WHERE a.userId = %d AND d.userId = %d",
            $assignee_id,
            $admin_id
        ));
        $current = array_map('intval', $current ?: array());

        foreach (array_diff($current, $delivery_ids) as $remove_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete(
                $assignments_table,
                array('deliveryId' => $remove_id, 'userId' => $assignee_id),
                array('%d', '%d')
            );
        }
        foreach (array_diff($delivery_ids, $current) as $add_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $assignments_table,
                array(
                    'deliveryId' => $add_id,
                    'userId'     => $assignee_id,
                    'assignedBy' => $admin_id,
                    'createdAt'  => current_time('mysql'),
                ),
                array('%d', '%d', '%d', '%s')
            );
        }

        // The assignee's visible set changed — bust their cached lists
        // (transient-backed, so they'd otherwise serve stale data until TTL)
        // and the per-request grant memo (defensive: a later read in this same
        // request would otherwise see the pre-write grant set).
        PCM_DB::invalidate('deliveries', $assignee_id);
        PCM_DB::invalidate('brands', $assignee_id);
        if (class_exists('PCM_Access')) {
            PCM_Access::reset_memo();
        }

        return $delivery_ids;
    }

    /**
     * Assigned delivery ids per user, one query.
     *
     * @param int[] $pcm_ids PCM user ids.
     * @return array<int, int[]>
     */
    private function assignments_for_users(array $pcm_ids): array
    {
        if (empty($pcm_ids)) {
            return array();
        }
        global $wpdb;
        $table = PCM_Schema::table('delivery_assignments');
        $placeholders = implode(',', array_fill(0, count($pcm_ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT userId, deliveryId FROM {$table} WHERE userId IN ({$placeholders})",
            ...array_map('intval', $pcm_ids)
        ));

        $by_user = array();
        foreach (($rows ?: array()) as $row) {
            $by_user[(int) $row->userId][] = (int) $row->deliveryId;
        }
        return $by_user;
    }
}
