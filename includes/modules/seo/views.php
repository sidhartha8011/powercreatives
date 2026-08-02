<?php
/**
 * SEO Saved Views — per-user column/filter configurations for the SEO table.
 *
 * Extracted VERBATIM from PCM_SEO_Service (2026-07-29 decomposition) so the
 * service stops being a 7k-line catch-all. This concern is fully self-contained:
 * it touches only `wp_pcm_seo_views` via $wpdb and shares no state with the rest
 * of the SEO module — the same standalone-class shape already used by
 * PCM_SEO_Site / PCM_SEO_Export / PCM_SEO_Schema in this module.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_SEO_Views
{
    /**
     * List a user's saved views, newest first.
     *
     * @param int $userId PCM user id (wp_pcm_users.id).
     * @return array[] [{ id:int, name:string, config:array }, ...].
     */
    /** Option holding pinned view ids: userId => int[]. */
    private const PINNED_OPTION = 'pcm_seo_pinned_views';

    /**
     * Ids this user has pinned to the tab strip.
     *
     * Stored in an OPTION rather than an `isPinned` column on purpose: adding a column means a
     * schema migration + DB-version bump, and pinning is a per-user display preference, not
     * data. Keeping it out of the table also means an unpinned/deleted view simply drops out
     * of the list with no migration to write.
     */
    public static function pinned_ids(int $userId): array
    {
        $map = get_option(self::PINNED_OPTION);
        $ids = (is_array($map) && isset($map[$userId]) && is_array($map[$userId])) ? $map[$userId] : array();
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Pin/unpin a view. Ownership checked, so a forged id can never pin someone else's view
     * into this user's tab strip.
     */
    public static function set_pinned(int $id, int $userId, bool $pinned): bool
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_views');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $owned = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE id = %d AND userId = %d",
            $id,
            $userId
        ));
        if ($owned === 0) {
            return false;
        }
        $map = get_option(self::PINNED_OPTION);
        if (!is_array($map)) { $map = array(); }
        $ids = self::pinned_ids($userId);
        $ids = array_values(array_diff($ids, array($id)));   // remove first — keeps it idempotent
        if ($pinned) { $ids[] = $id; }
        $map[$userId] = $ids;
        update_option(self::PINNED_OPTION, $map, false);
        return true;
    }

    public static function list_views(int $userId): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_views');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name, config, isDefault FROM {$table} WHERE userId = %d ORDER BY id DESC",
            $userId
        ));

        $pinned = self::pinned_ids($userId);
        $views = array();
        foreach ($rows as $row) {
            $config = json_decode((string) $row->config, true);
            $views[] = array(
                'id'        => (int) $row->id,
                'name'      => (string) $row->name,
                'config'    => is_array($config) ? $config : array(),
                'isDefault' => (bool) (int) $row->isDefault,
                'isPinned'  => in_array((int) $row->id, $pinned, true),
            );
        }
        return $views;
    }

    /**
     * Create a saved view for a user.
     *
     * @param int    $userId PCM user id.
     * @param string $name   Sanitized, non-empty view name.
     * @param array  $config { columns: {colKey:bool}, filters: {colKey:string} }.
     * @return array { id:int, name:string, config:array }.
     */
    public static function create_view(int $userId, string $name, array $config): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_views');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            array(
                'userId' => $userId,
                'name'   => $name,
                'config' => wp_json_encode($config),
            ),
            array('%d', '%s', '%s')
        );

        return array(
            'id'        => (int) $wpdb->insert_id,
            'name'      => $name,
            'config'    => $config,
            'isDefault' => false,
        );
    }

    /**
     * Mark a view as the user's default (or clear it), enforcing at most one
     * default per user. Only affects views owned by the given user.
     *
     * @param int  $id        View id.
     * @param int  $userId    PCM user id.
     * @param bool $isDefault True to make this the default, false to unset it.
     * @return bool True if the target view exists and belongs to the user.
     */
    /**
     * Rename a saved view. Same ownership rule as every other mutator here — a user can only
     * touch their own views, so a forged id returns false rather than renaming someone else's.
     *
     * @param int    $id     View id.
     * @param int    $userId Owner id.
     * @param string $name   New name (already sanitized by the controller).
     * @return bool False when the view doesn't exist or isn't this user's.
     */
    public static function rename_view(int $id, int $userId, string $name): bool
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_views');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $owned = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE id = %d AND userId = %d",
            $id,
            $userId
        ));
        if ($owned === 0) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->update($table, array('name' => $name), array('id' => $id, 'userId' => $userId)) !== false;
    }

    public static function set_default_view(int $id, int $userId, bool $isDefault): bool
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_views');

        // Ownership check — never touch another user's views.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $owned = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE id = %d AND userId = %d",
            $id,
            $userId
        ));
        if ($owned === 0) {
            return false;
        }

        // Clear any existing default for this user (single-default invariant).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update($table, array('isDefault' => 0), array('userId' => $userId), array('%d'), array('%d'));

        if ($isDefault) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $table,
                array('isDefault' => 1),
                array('id' => $id, 'userId' => $userId),
                array('%d'),
                array('%d', '%d')
            );
        }
        return true;
    }

    /**
     * Delete a saved view, but only if it belongs to the given user.
     *
     * @param int $id     View id.
     * @param int $userId PCM user id.
     * @return bool True if a row was deleted, false if none matched.
     */
    public static function delete_view(int $id, int $userId): bool
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_views');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted = $wpdb->delete(
            $table,
            array('id' => $id, 'userId' => $userId),
            array('%d', '%d')
        );
        return (int) $deleted > 0;
    }
}
