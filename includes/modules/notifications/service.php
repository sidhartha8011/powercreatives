<?php
/**
 * Notifications Service — role-scoped feed + seen anchor.
 *
 * One notification row per approval-flow event (written by the automations
 * action `notifications.create`); visibility computed at read time:
 *   - admin → every row;
 *   - user  → rows they own (ownerId) OR whose brandId is granted to them via
 *     a delivery assignment (PCM_Access::granted_brand_ids).
 * Unseen count = visible rows newer than users.notificationsSeenAt.
 *
 * @package PowerCreatives
 * @since   1.19.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Notifications_Service
{
    /**
     * Build the visibility WHERE fragment for a user. Public static so the
     * SQL shape is unit-testable without a DB.
     *
     * @param bool  $is_admin          Admin sees everything.
     * @param int   $user_id           PCM user id.
     * @param int[] $granted_brand_ids Brand ids granted via assignments.
     * @return array{sql: string, params: array}
     */
    public static function visibility_clause(bool $is_admin, int $user_id, array $granted_brand_ids): array
    {
        if ($is_admin) {
            return array('sql' => '1=1', 'params' => array());
        }
        if (empty($granted_brand_ids)) {
            return array('sql' => 'ownerId = %d', 'params' => array($user_id));
        }
        $placeholders = implode(',', array_fill(0, count($granted_brand_ids), '%d'));
        return array(
            'sql'    => "(ownerId = %d OR brandId IN ({$placeholders}))",
            'params' => array_merge(array($user_id), array_map('intval', $granted_brand_ids)),
        );
    }

    /**
     * Latest visible notifications + unseen count for a user.
     *
     * @param object $user PCM user row (id, role, notificationsSeenAt).
     * @return array{items: array[], unseen: int}
     */
    public function list_for_user(object $user): array
    {
        global $wpdb;

        $table    = PCM_Schema::table('notifications');
        $is_admin = ($user->role ?? '') === 'admin';
        $scope    = self::visibility_clause($is_admin, (int) $user->id, PCM_Access::granted_brand_ids((int) $user->id));
        $clause   = $scope['sql'];
        $params   = $scope['params'];

        // Per-user "Clear all" anchor — hide events at/older than it. Leaves the
        // shared notification rows intact for other recipients.
        $cleared = (string) ($user->notificationsClearedAt ?? '');
        if ($cleared !== '') {
            $clause  .= ' AND createdAt > %s';
            $params[] = $cleared;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- clause built from %d/%s placeholders only.
        $sql  = "SELECT * FROM {$table} WHERE {$clause} ORDER BY createdAt DESC, id DESC LIMIT 50";
        $rows = empty($params)
            ? $wpdb->get_results($sql) // admin with nothing cleared: no placeholders to prepare.
            : $wpdb->get_results($wpdb->prepare($sql, ...$params));

        $seen_at = (string) ($user->notificationsSeenAt ?? '');
        $unseen  = 0;
        $items   = array();
        foreach (($rows ?: array()) as $row) {
            $is_new = ($seen_at === '' || $row->createdAt > $seen_at);
            if ($is_new) {
                $unseen++;
            }
            $items[] = array(
                'id'        => (int) $row->id,
                'setId'     => (int) $row->setId,
                'brandId'   => $row->brandId !== null ? (int) $row->brandId : null,
                'type'      => (string) $row->type,
                'title'     => (string) $row->title,
                'excerpt'   => (string) ($row->excerpt ?? ''),
                'link'      => (string) ($row->link ?? ''),
                'createdAt' => (string) $row->createdAt,
                'isNew'     => $is_new,
            );
        }

        return array('items' => $items, 'unseen' => $unseen);
    }

    /**
     * Mark everything seen for the user (sets the per-user anchor).
     *
     * @param int $user_id PCM user id.
     * @return bool
     */
    public function mark_seen(int $user_id): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->update(
            PCM_Schema::table('users'),
            array('notificationsSeenAt' => current_time('mysql')),
            array('id' => $user_id),
            array('%s'),
            array('%d')
        );
        return $ok !== false;
    }

    /**
     * Clear the feed for the user — sets the per-user cleared anchor so the
     * list hides everything at/older than now. Non-destructive: the shared
     * notification rows remain visible to other recipients.
     *
     * @param int $user_id PCM user id.
     * @return bool
     */
    public function clear_all(int $user_id): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->update(
            PCM_Schema::table('users'),
            array('notificationsClearedAt' => current_time('mysql')),
            array('id' => $user_id),
            array('%s'),
            array('%d')
        );
        return $ok !== false;
    }
}
