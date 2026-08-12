<?php
/**
 * Approvals Service
 *
 * Implements CRUD operations, frozen snapshot processing, and outbound Webhooks.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Approvals_Service
{
    /**
     * Canonical pipeline statuses for an approval set.
     *
     * Order matches the kanban left-to-right flow:
     *   draft     — being assembled by the creator.
     *   internal  — out for internal team review (Awaiting Internal Approval).
     *   client    — out for client review (Awaiting Client Approval).
     *   launch    — client signed off; team is building + preparing the
     *               campaign for launch. (v1.11.0 collapsed the previous
     *               separate 'create' stage into this one.)
     *   live      — campaign is running in market.
     *   archived  — closed or paused; retained for reference.
     *
     * @var string[]
     */
    public const STATUSES = ['draft', 'internal', 'client', 'launch', 'live', 'archived'];

    /**
     * Legacy → new status migration map. Iterated in declaration order by
     * PCM_Schema::migrate_approval_set_statuses() — later entries can
     * catch values produced by earlier ones in the same pass.
     *
     * History:
     *   v1.8.0: review→client, completed→approved.
     *   v1.9.0: approved→create (mid-workflow rename for 7-status taxonomy).
     *   v1.11.0: create→launch (collapse the create + launch stages into a
     *            single 'launch' stage; also re-points approved at the
     *            new destination so older installs converge in one pass).
     *
     * @var array<string, string>
     */
    public const LEGACY_STATUS_MAP = [
        'review'    => 'client',
        'completed' => 'approved',
        'approved'  => 'launch',
        'create'    => 'launch',
    ];

    /**
     * List all approval sets for a user.
     *
     * @param int $user_id User ID.
     * @return array Array of approval set objects.
     */
    public static function list_sets_by_user(int $user_id): array
    {
        global $wpdb;
        $table = PCM_Schema::table('approval_sets');

        // Visibility: admins see every set (team-wide oversight); others see
        // their own plus sets in their granted brand/project scope, so a
        // teammate's work on a shared engagement is visible to everyone with
        // that access (and notification jumps resolve on both sides).
        // deliveryId is selected so the board's Delivery dropdown has something to
        // filter on for legacy sets that carry it directly; for sets with a project,
        // format_set_row() overwrites it with the LIVE chain value.
        // `snapshot`/`reviewFeedback` stay OUT on purpose — both are longtext and can
        // carry embedded images; the board resolves names from the registries instead.
        $cols = "id, userId, brandId, projectId, deliveryId, name, token, status, clientEmail, createdAt, updatedAt"
              . ', ' . self::items_summary_sql();
        if (class_exists('PCM_Access') && PCM_Access::is_admin($user_id)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results("SELECT {$cols} FROM {$table} ORDER BY createdAt DESC");
        } else {
            $clauses = array('userId = %d');
            $params  = array($user_id);
            $brand_ids   = class_exists('PCM_Access') ? PCM_Access::granted_brand_ids($user_id) : array();
            $project_ids = class_exists('PCM_Access') ? PCM_Access::granted_project_ids($user_id) : array();
            if (!empty($brand_ids)) {
                $clauses[] = 'brandId IN (' . implode(',', array_fill(0, count($brand_ids), '%d')) . ')';
                $params    = array_merge($params, $brand_ids);
            }
            if (!empty($project_ids)) {
                $clauses[] = 'projectId IN (' . implode(',', array_fill(0, count($project_ids), '%d')) . ')';
                $params    = array_merge($params, $project_ids);
            }
            $where = implode(' OR ', $clauses);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- clause built from %d placeholders only.
            $rows = $wpdb->get_results(
                $wpdb->prepare("SELECT {$cols} FROM {$table} WHERE {$where} ORDER BY createdAt DESC", ...$params)
            );
        }

        return array_map(function ($row) {
            return self::format_list_row($row);
        }, $rows ?: array());
    }

    /**
     * The board's item buckets, and where each one keeps its display title.
     *
     * ONE definition — the summary SQL and the row formatter both read it, so a
     * fifth bucket is a single line here rather than an edit in two places that
     * can silently disagree.
     *
     * @var array<string, string>
     */
    private const ITEM_BUCKETS = array(
        'media'    => array('title' => 'name',     'approvalKey' => 'approvedVisualIds'),
        'copy'     => array('title' => 'headline', 'approvalKey' => 'approvedCopyIds'),
        'articles' => array('title' => 'title',    'approvalKey' => 'approvedArticleIds'),
        'custom'   => array('title' => 'title',    'approvalKey' => 'approvedCustomIds'),
    );

    /**
     * SELECT fragment giving the board a per-card item SUMMARY without shipping
     * `snapshot` itself.
     *
     * WHY THIS EXISTS. `snapshot` is longtext and can carry embedded base64
     * images, which is exactly why the column list above leaves it out. But the
     * board card has to show how many items it holds and list them when expanded,
     * and doing that from a per-card fetch would put a 1.4-2.9 s round trip
     * behind every disclosure. This extracts only `{id, type, title}` per item —
     * measured at 86 bytes against a 261-byte snapshot, and the gap widens
     * sharply once a card carries an image.
     *
     * JSON_TABLE, not `'$.custom[*].title'`: the naive path form SKIPS elements
     * that lack the key, so ids and titles silently drift out of alignment.
     * JSON_TABLE walks the array and yields NULL for a missing key instead.
     * Requires MySQL 5.7.8+/8.0 (this install: 8.0.35, verified).
     *
     * @return string
     */
    /**
     * bucket => approval-id-list, derived from the ONE bucket definition.
     *
     * This mapping was previously written out twice — in `bucket_for_asset()`
     * and again in `is_fully_approved()` — so "which approval list belongs to
     * which bucket" was asserted in two places and could drift apart without
     * anything failing loudly. Approvals landing in the wrong list is not a
     * cosmetic bug, so it gets one owner.
     *
     * @return array<string, string>
     */
    private static function approval_key_map(): array
    {
        $map = array();
        foreach (self::ITEM_BUCKETS as $bucket => $meta) {
            $map[$bucket] = $meta['approvalKey'];
        }
        return $map;
    }

    private static function items_summary_sql(): string
    {
        $parts = array();
        $counts = array();

        foreach (self::ITEM_BUCKETS as $bucket => $meta) {
            $title_key = $meta['title'];
            $counts[] = "COALESCE(JSON_LENGTH(JSON_EXTRACT(snapshot,'$.{$bucket}')),0)";
            $parts[]  = "(SELECT JSON_ARRAYAGG(JSON_OBJECT('id',jt.iid,'type','{$bucket}','title',jt.ttl))"
                . " FROM JSON_TABLE(snapshot,'$.{$bucket}[*]'"
                . " COLUMNS (iid VARCHAR(64) PATH '$.id', ttl VARCHAR(255) PATH '$.{$title_key}')) jt)"
                . " AS items_{$bucket}";
        }

        return implode(' + ', $counts) . ' AS itemCount, ' . implode(', ', $parts);
    }

    /**
     * Format a LIST row: merge the item summary into one ordered `items` array.
     *
     * Deliberately NOT `format_set_row()`. That one json_decodes `snapshot`, and
     * because the list never selects `snapshot` it produced `array()` — so every
     * board row shipped `"snapshot": []`, an empty ARRAY where the type says
     * object. Code then read `set.snapshot.brandName` off it and silently got
     * undefined forever. A list row now carries no `snapshot` key at all, which
     * is the truth: the board does not have it.
     *
     * Item order matches the client view's own merge order (media, copy,
     * articles, custom) so the board and the opened card agree.
     */
    private static function format_list_row(object $row): object
    {
        $items = array();
        foreach (array_keys(self::ITEM_BUCKETS) as $bucket) {
            $key = 'items_' . $bucket;
            $decoded = !empty($row->$key) ? json_decode($row->$key, true) : array();
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_array($item) && !empty($item['id'])) {
                        $items[] = array(
                            'id'    => (string) $item['id'],
                            'type'  => (string) ($item['type'] ?? $bucket),
                            'title' => isset($item['title']) ? (string) $item['title'] : '',
                        );
                    }
                }
            }
            unset($row->$key);
        }

        $row->items     = $items;
        $row->itemCount = isset($row->itemCount) ? (int) $row->itemCount : count($items);

        // The live Brand → Delivery → Project chain, exactly as format_set_row does.
        if (class_exists('PCM_Hierarchy') && !empty($row->projectId)) {
            $chain = PCM_Hierarchy::for_project((int) $row->projectId);
            $row->brandId    = !empty($chain['brandId']) ? (int) $chain['brandId'] : null;
            $row->deliveryId = !empty($chain['deliveryId']) ? (int) $chain['deliveryId'] : null;
        }

        return $row;
    }

    /**
     * Retrieve a single approval set by ID and User ID.
     */
    public static function get_set_by_id(int $id, int $user_id): ?object
    {
        $row = PCM_DB::get_by_id('approval_sets', $id, $user_id);
        return $row ? self::format_set_row($row) : null;
    }

    /**
     * May this user perform a DESTRUCTIVE operation on this set (delete, bulk
     * delete, lane change)? Owner, or an admin.
     *
     * The board already shows an admin EVERY set (list_sets_by_user drops the
     * userId filter for them), so without the same grant here an admin sees a
     * card and is refused the moment they touch it — "Set not found or not
     * owned by this user" (owner report 2026-08-10, hit on delete).
     *
     * Deliberately narrower than get_set_scoped(): a brand/project GRANTEE may
     * view and append, but only the owner or an admin may destroy. That keeps
     * the original restriction for everyone except the role whose entire
     * purpose is team-wide oversight — PCM_Access::is_admin's own contract
     * names approvals: "a platform admin gets the same team-wide oversight
     * (all brands/deliveries/approvals) a WP admin has".
     *
     * @param int $id      Approval-set id.
     * @param int $user_id PCM user id.
     */
    public static function can_write_set(int $id, int $user_id): bool
    {
        if ($id <= 0 || $user_id <= 0) {
            return false;
        }
        global $wpdb;
        $table = PCM_Schema::table('approval_sets');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $owner = $wpdb->get_var($wpdb->prepare("SELECT userId FROM {$table} WHERE id = %d", $id));
        if ($owner === null) {
            return false; // genuinely missing — the 404 is correct
        }
        if ((int) $owner === $user_id) {
            return true;
        }
        return class_exists('PCM_Access') && PCM_Access::is_admin($user_id);
    }

    /**
     * Retrieve a set the user may VIEW (and append to): their own, any set
     * for admins, or a set within their granted brand/project scope.
     * Destructive operations (status/delete/share/reply) use can_write_set()
     * — owner or admin, NOT brand/project grantees.
     */
    public static function get_set_scoped(int $id, int $user_id): ?object
    {
        global $wpdb;
        $table = PCM_Schema::table('approval_sets');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
        if (!$row) {
            return null;
        }
        $allowed = (int) $row->userId === $user_id
            || (class_exists('PCM_Access') && (
                PCM_Access::is_admin($user_id)
                || (!empty($row->brandId) && in_array((int) $row->brandId, PCM_Access::granted_brand_ids($user_id), true))
                || (!empty($row->projectId) && in_array((int) $row->projectId, PCM_Access::granted_project_ids($user_id), true))
            ));
        return $allowed ? self::format_set_row($row) : null;
    }

    /**
     * Merge additional snapshot buckets into an existing snapshot, deduped
     * by item id (existing items win; order preserved, new items appended).
     *
     * @param array $base Existing snapshot.
     * @param array $add  Incoming { media?, copy?, articles? } buckets.
     * @return array Merged snapshot.
     */
    /**
     * Remove ONE item from a card.
     *
     * Deliberately mirrors `append_to_set()`: the same scoped read, the same
     * refusal once the card is past client review or fully approved, the same
     * single write. Adding and removing an item are the same operation in
     * opposite directions, so they must obey the same law — a card the client
     * has already signed off cannot quietly lose an item underneath them.
     *
     * Buckets come from `ITEM_BUCKETS`, the constant the board summary already
     * reads, so there is no second hardcoded list of what a card can hold.
     *
     * @return object|string The updated set, or 'not_found' | 'locked' | 'missing'.
     */
    public static function remove_asset(int $set_id, int $user_id, string $asset_id): object|string
    {
        $set = self::get_set_scoped($set_id, $user_id);
        if (!$set) {
            return 'not_found';
        }

        $snapshot = is_array($set->snapshot) ? $set->snapshot : array();
        if (in_array($set->status, self::POST_SUBMIT_STATUSES, true)
            || self::is_fully_approved($snapshot, self::feedback_struct($set))
        ) {
            return 'locked';
        }

        // Capture WHAT was removed, and from which bucket, so the caller can put
        // it back verbatim. Undo cannot be reconstructed from the board's item
        // summary — that carries only {id, type, title} — and re-fetching the
        // whole snapshot to enable an undo would cost seconds on this host.
        $removed_item   = null;
        $removed_bucket = null;

        foreach (array_keys(self::ITEM_BUCKETS) as $bucket) {
            if (empty($snapshot[$bucket]) || !is_array($snapshot[$bucket])) {
                continue;
            }
            $kept = array();
            foreach ($snapshot[$bucket] as $item) {
                if (is_array($item) && isset($item['id']) && (string) $item['id'] === $asset_id) {
                    $removed_item   = $item;
                    $removed_bucket = $bucket;
                    continue;
                }
                $kept[] = $item;
            }
            // Re-index so the stored array stays a JSON array, never an object.
            $snapshot[$bucket] = array_values($kept);
        }

        if ($removed_item === null) {
            return 'missing';
        }

        global $wpdb;
        $table = PCM_Schema::table('approval_sets');
        // Ownership already established by the scoped read above.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            array(
                'snapshot'  => wp_json_encode($snapshot),
                'updatedAt' => current_time('mysql'),
            ),
            array('id' => $set_id),
            array('%s', '%s'),
            array('%d')
        );

        $set->snapshot = $snapshot;
        // Handed back so an undo can re-append the exact item. `merge_snapshot`
        // dedupes by id, so restoring twice cannot duplicate it.
        $set->removedAsset  = $removed_item;
        $set->removedBucket = $removed_bucket;
        return $set;
    }

    public static function merge_snapshot(array $base, array $add): array
    {
        foreach (array('media', 'copy', 'articles', 'custom') as $bucket) {
            $incoming = (!empty($add[$bucket]) && is_array($add[$bucket])) ? $add[$bucket] : array();
            if (empty($incoming)) {
                continue;
            }
            $existing = (!empty($base[$bucket]) && is_array($base[$bucket])) ? $base[$bucket] : array();
            $seen     = array();
            foreach ($existing as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $seen[(string) $item['id']] = true;
                }
            }
            foreach ($incoming as $item) {
                if (!is_array($item) || !isset($item['id']) || isset($seen[(string) $item['id']])) {
                    continue;
                }
                $seen[(string) $item['id']] = true;
                $existing[] = $item;
            }
            $base[$bucket] = $existing;
        }
        return $base;
    }

    /**
     * Append assets to an existing set. Only allowed while the set is still
     * in review — not in a post-submit lane and not fully approved.
     *
     * @param int   $set_id  Set id.
     * @param int   $user_id Caller's PCM user id (view-scoped access).
     * @param array $add     Incoming { media?, copy?, articles? } buckets.
     * @return object|string Updated set, or error code 'not_found' | 'locked'.
     */
    public static function append_to_set(int $set_id, int $user_id, array $add): object|string
    {
        $set = self::get_set_scoped($set_id, $user_id);
        if (!$set) {
            return 'not_found';
        }

        $snapshot = is_array($set->snapshot) ? $set->snapshot : array();
        if (in_array($set->status, self::POST_SUBMIT_STATUSES, true)
            || self::is_fully_approved($snapshot, self::feedback_struct($set))
        ) {
            return 'locked';
        }

        $merged = self::merge_snapshot($snapshot, $add);

        global $wpdb;
        $table = PCM_Schema::table('approval_sets');
        // Ownership already established by the scoped read above.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            array(
                'snapshot'  => wp_json_encode($merged),
                'updatedAt' => current_time('mysql'),
            ),
            array('id' => $set_id),
            array('%s', '%s'),
            array('%d')
        );

        $set->snapshot  = $merged;
        $set->updatedAt = current_time('mysql');
        return $set;
    }

    /**
     * Retrieve a public approval set by token.
     */
    public static function get_set_by_token(string $token): ?object
    {
        global $wpdb;
        $table = PCM_Schema::table('approval_sets');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE token = %s LIMIT 1",
                $token
            )
        );

        return $row ? self::format_set_row($row) : null;
    }

    /**
     * Create a new approval set.
     *
     * `status` is optional and defaults to 'draft' — the board's lane "+" passes the
     * lane it was clicked in, so the set is born there in ONE insert. Deliberately
     * NOT create-then-update_status: that would write a phantom Draft row and fire
     * `approvals.set_status_changed`, which would run user rules keyed to a lane
     * (e.g. the seeded "Notify team on Launch") for a set that was merely CREATED
     * there. A creation is not a lane change. The caller validates the value.
     *
     * @param int   $user_id User ID.
     * @param array $data    Set fields (name, brandId, projectId, snapshot, status?).
     * @return int|false New Set ID or false.
     */
    public static function create_set(int $user_id, array $data): int|false
    {
        global $wpdb;
        $table = PCM_Schema::table('approval_sets');

        // Generate unguessable high-entropy token
        $token = 'set_' . bin2hex(random_bytes(16));

        // Format snapshot
        $snapshot = is_string($data['snapshot']) ? $data['snapshot'] : wp_json_encode($data['snapshot']);

        $status = isset($data['status']) && in_array($data['status'], self::STATUSES, true)
            ? (string) $data['status']
            : 'draft';

        $row = array(
            'userId'     => $user_id,
            'brandId'    => $data['brandId'] ?? null,
            'projectId'  => $data['projectId'] ?? null,
            'deliveryId' => $data['deliveryId'] ?? null,
            'name'       => $data['name'],
            'token'      => $token,
            'status'     => $status,
            'snapshot'   => $snapshot,
        );

        // Born directly in the client lane? Stamp the same anchor update_status()
        // stamps — the pending-client reminder scanner reads clientSentAt, so
        // without this those sets would never be reminded about.
        if ($status === 'client') {
            $row['clientSentAt'] = current_time('mysql');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->insert($table, $row);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update an approval set's status (internal team operation).
     *
     * Validates the new status against the canonical taxonomy. Ownership
     * is enforced via wpdb update WHERE userId — a user cannot touch
     * another user's sets.
     *
     * @return bool True on success, false if validation/ownership fails.
     */
    public static function update_status(int $id, int $user_id, string $next_status): bool
    {
        if (!in_array($next_status, self::STATUSES, true)) {
            return false;
        }

        // Owner OR admin. get_set_by_id() is strictly owner-scoped (it goes
        // through PCM_DB::get_by_id), so an admin dragging a card between lanes
        // failed here before the UPDATE was even attempted.
        if (!self::can_write_set($id, $user_id)) {
            return false;
        }

        // Load for the previous lane (fire the trigger only on an actual change)
        // and the name/token for the payload. get_set_scoped, not get_set_by_id,
        // so an admin's load succeeds for a set they do not own.
        $set = self::get_set_scoped($id, $user_id);
        if (!$set) {
            return false;
        }
        $previous = $set->status;

        global $wpdb;
        $table = PCM_Schema::table('approval_sets');

        // Stamp clientSentAt on the transition INTO the 'client' lane (and only
        // then). This is the stable anchor the pending-client reminder scanner
        // uses to compute daysSinceSent — `updatedAt` would drift on any edit.
        $update_data    = array('status' => $next_status, 'updatedAt' => current_time('mysql'));
        $update_formats = array('%s', '%s');
        if ($previous !== 'client' && $next_status === 'client') {
            $update_data['clientSentAt']    = current_time('mysql');
            $update_formats[]               = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        // id only — can_write_set() above is the authorisation gate. Keeping
        // `userId` here as well would re-impose owner-only and match 0 rows for
        // the very admin the gate just allowed.
        $rows = $wpdb->update(
            $table,
            $update_data,
            array('id' => $id),
            $update_formats,
            array('%d')
        );

        if ($rows === false || $rows <= 0) {
            return false;
        }

        // Fire the Automations trigger when the set actually enters a new lane
        // (e.g. dragged into Launch on the kanban).
        if ($previous !== $next_status) {
            $set->status = $next_status;
            self::fire_status_trigger($set, $next_status);
        }

        return true;
    }

    /**
     * Notify the Automations engine that an approval set entered a lane.
     * Powers user-defined rules such as "lane = Launch → send webhook".
     *
     * @param object $set        Approval set row (must have id, name, token, userId, brandId).
     * @param string $new_status The lane the set just entered.
     * @return void
     */
    private static function fire_status_trigger(object $set, string $new_status): void
    {
        if (!class_exists('PCM_Automation_Engine')) {
            return;
        }
        PCM_Automation_Engine::fire_trigger(
            'approvals.set_status_changed',
            array_merge(
                // Full brand→delivery→project + set tokens (names + links) so a webhook can map
                // the set to the right delivery/client/chat. enrich_context supplies setID/setName/
                // setLink/setInternalLink/brandName/deliveryName/projectName etc.
                self::enrich_context($set),
                array(
                    // Legacy keys — kept so existing rules keep resolving.
                    'setId'     => (int) $set->id,
                    'name'      => (string) $set->name,
                    'status'    => $new_status,
                    'token'     => (string) $set->token,
                    'link'      => self::build_share_url((string) $set->token),
                    'brandId'   => !empty($set->brandId) ? (int) $set->brandId : null,
                    // Status is event-specific (the lane just entered), so it's set here, not in enrich.
                    'setStatus' => $new_status,
                )
            ),
            (int) $set->userId
        );
    }

    /**
     * Delete an approval set.
     *
     * Ownership-scoped: the WHERE clause includes userId so a user can
     * only delete their own sets. Returns true only when exactly one row
     * is affected; false on validation, ownership mismatch, or already-
     * deleted records.
     */
    public static function delete_set(int $id, int $user_id): bool
    {
        if ($id <= 0 || $user_id <= 0) {
            return false;
        }

        // Owner OR admin (can_write_set) — an admin sees every set on the board,
        // so an owner-only DELETE matched 0 rows and surfaced as "not found".
        if (!self::can_write_set($id, $user_id)) {
            return false;
        }

        global $wpdb;
        $table = PCM_Schema::table('approval_sets');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->delete($table, array('id' => $id), array('%d'));

        return $rows !== false && $rows > 0;
    }

    /**
     * Bulk-delete approval sets owned by the caller.
     *
     * Returns the number of rows actually deleted (0 if nothing matched,
     * which can happen if any ids are not owned by the user — silent
     * skip rather than partial-rollback, matching the bulk pattern used
     * by other modules like brands).
     */
    public static function bulk_delete_sets(array $ids, int $user_id): int
    {
        if ($user_id <= 0) {
            return 0;
        }

        // Normalize + filter: only positive integers survive.
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($n) => $n > 0));
        if (empty($ids)) {
            return 0;
        }

        // Same owner-or-admin rule as delete_set, applied PER id so a mixed
        // selection deletes exactly what this user may destroy and silently
        // skips the rest — the caller reports the count, never a partial lie.
        $ids = array_values(array_filter($ids, static fn(int $n): bool => self::can_write_set($n, $user_id)));
        if (empty($ids)) {
            return 0;
        }

        global $wpdb;
        $table = PCM_Schema::table('approval_sets');
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE id IN ({$placeholders})",
                $ids
            )
        );

        return (int)($deleted ?: 0);
    }

    /**
     * Submit client feedback, lock set, and dispatch outbound webhook.
     */
    public static function submit_review(string $token, string $client_name, array $feedback): bool
    {
        global $wpdb;
        $table = PCM_Schema::table('approval_sets');

        $set = self::get_set_by_token($token);
        if (!$set) {
            return false;
        }

        // IDEMPOTENCY GUARD. A submitted set is already past client review, so a
        // second submit — a refresh, a double click, a replayed request — must not
        // re-fire the outbound webhook. `approve_assets()` has always had this
        // guard; `submit_review()` did not, and the client UI was the only thing
        // standing between a reload and a duplicate dispatch. Returning true keeps
        // the caller's "it worked" contract: the set IS submitted.
        if (in_array($set->status, self::POST_SUBMIT_STATUSES, true)) {
            return true;
        }

        // Tidy feedback arrays
        $sanitized_feedback = array(
            'approvedVisualIds'  => array_map('sanitize_text_field', $feedback['approvedVisualIds'] ?? array()),
            'approvedCopyIds'    => array_map('sanitize_text_field', $feedback['approvedCopyIds'] ?? array()),
            'approvedArticleIds' => array_map('sanitize_text_field', $feedback['approvedArticleIds'] ?? array()),
            'approvedCustomIds'  => array_map('sanitize_text_field', $feedback['approvedCustomIds'] ?? array()),
            'comments'           => self::sanitize_comment_threads($feedback['comments'] ?? array(), $client_name),
        );

        // Client review submission auto-advances the set to 'launch' (next
        // workflow step — campaign build + launch prep). History:
        //   v1.7.0 = 'completed', v1.8.0 = 'approved', v1.9.0 = 'create',
        //   v1.11.0 = 'launch' (collapsed create+launch into a single stage).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $success = $wpdb->update(
            $table,
            array(
                'status'         => 'launch',
                'reviewFeedback' => wp_json_encode($sanitized_feedback),
                'updatedAt'      => current_time('mysql'),
            ),
            array('id' => (int)$set->id)
        );

        if ($success !== false) {
            // Legacy single-submit event — preserved for existing webhook
            // consumers (fires only if a global/brand webhook URL is configured).
            PCM_Automation_Engine::dispatch(
                PCM_Automation_Events::APPROVAL_COMPLETED,
                self::build_event_context($set, array(
                    'clientName' => $client_name,
                    'feedback'   => $sanitized_feedback,
                )),
                (int) $set->userId
            );
            // The set also entered the Launch lane → fire the Automations trigger
            // so user-defined "lane = Launch → …" rules run.
            if ($set->status !== 'launch') {
                $set->status = 'launch';
                self::fire_status_trigger($set, 'launch');
            }
            return true;
        }

        return false;
    }

    /**
     * Save client feedback draft (real-time autosave).
     *
     * Comments are stored as an array-of-objects per asset ID:
     *   { assetId: [ { id, author, text, createdAt, status, parentId } ] }
     * This supports multiple comments, threading, and per-comment statuses.
     */
    public static function save_review_draft(string $token, array $feedback): bool
    {
        global $wpdb;
        $table = PCM_Schema::table('approval_sets');

        $set = self::get_set_by_token($token);
        if (!$set) {
            return false;
        }

        // Only allow saving draft if the status is still 'draft'
        if ($set->status !== 'draft') {
            return false;
        }

        // Tidy feedback arrays
        $sanitized_feedback = array(
            'approvedVisualIds'  => array_map('sanitize_text_field', $feedback['approvedVisualIds'] ?? array()),
            'approvedCopyIds'    => array_map('sanitize_text_field', $feedback['approvedCopyIds'] ?? array()),
            'approvedArticleIds' => array_map('sanitize_text_field', $feedback['approvedArticleIds'] ?? array()),
            'approvedCustomIds'  => array_map('sanitize_text_field', $feedback['approvedCustomIds'] ?? array()),
            'comments'           => self::sanitize_comment_threads($feedback['comments'] ?? array(), 'Client'),
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $success = $wpdb->update(
            $table,
            array(
                'reviewFeedback' => wp_json_encode($sanitized_feedback),
                'updatedAt'      => current_time('mysql'),
            ),
            array('id' => (int)$set->id)
        );

        return $success !== false;
    }

    /**
     * Sanitize comment threads for storage.
     *
     * Handles both legacy string-per-asset format and new array-of-objects.
     * Validates comment status against whitelist to prevent arbitrary values.
     *
     * @param array  $comments       Raw comments keyed by asset ID.
     * @param string $default_author Fallback author name for legacy entries.
     * @return array Sanitized comments keyed by asset ID.
     */
    private static function sanitize_comment_threads(array $comments, string $default_author): array
    {
        /** Valid statuses for individual comment entries */
        $allowed_statuses = array('New', 'Team reply', 'Done');
        $sanitized = array();

        foreach ($comments as $item_id => $thread_array) {
            $safe_id = sanitize_text_field($item_id);

            if (is_string($thread_array)) {
                // Legacy: single string → convert to single-entry array
                $sanitized[$safe_id] = array(
                    array(
                        'id'          => wp_generate_uuid4(),
                        'author'      => $default_author,
                        'text'        => sanitize_textarea_field($thread_array),
                        'createdAt'   => current_time('c'),
                        'status'      => 'New',
                        'parentId'    => null,
                        'attachments' => array(),
                        'readBy'      => array(),
                    ),
                );
            } elseif (is_array($thread_array)) {
                $safe_thread = array();
                foreach ($thread_array as $entry) {
                    if (!is_array($entry) || empty($entry['text'])) {
                        continue;
                    }
                    // Validate status against whitelist
                    $raw_status = sanitize_text_field($entry['status'] ?? 'New');
                    $status = in_array($raw_status, $allowed_statuses, true) ? $raw_status : 'New';

                    $safe_thread[] = array(
                        'id'          => sanitize_text_field($entry['id'] ?? wp_generate_uuid4()),
                        'author'      => sanitize_text_field($entry['author'] ?? $default_author),
                        'text'        => sanitize_textarea_field($entry['text']),
                        'createdAt'   => sanitize_text_field($entry['createdAt'] ?? current_time('c')),
                        'status'      => $status,
                        'parentId'    => isset($entry['parentId']) ? sanitize_text_field($entry['parentId']) : null,
                        // Preserve attachments + read receipts (previously dropped
                        // server-side, so they only survived in localStorage).
                        'attachments' => self::sanitize_attachments($entry['attachments'] ?? array()),
                        'readBy'      => array_values(array_map(
                            'sanitize_text_field',
                            is_array($entry['readBy'] ?? null) ? $entry['readBy'] : array()
                        )),
                    );
                }
                if (!empty($safe_thread)) {
                    $sanitized[$safe_id] = $safe_thread;
                }
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize a comment's attachment list.
     *
     * Each attachment is { url, name?, type? }. Entries without a valid URL
     * are dropped.
     *
     * @param mixed $attachments Raw attachments array.
     * @return array<int, array{ url: string, name: string, type: string }>
     */
    private static function sanitize_attachments($attachments): array
    {
        if (!is_array($attachments)) {
            return array();
        }

        $clean = array();
        foreach ($attachments as $att) {
            if (!is_array($att) || empty($att['url'])) {
                continue;
            }
            $url = esc_url_raw((string) $att['url']);
            if ($url === '') {
                continue;
            }
            $clean[] = array(
                'url'  => $url,
                'name' => sanitize_text_field((string) ($att['name'] ?? '')),
                'type' => sanitize_text_field((string) ($att['type'] ?? '')),
            );
        }

        return $clean;
    }

    /**
     * Format database row JSON strings into standard array formats.
     */
    private static function format_set_row(object $row): object
    {
        $row->snapshot       = !empty($row->snapshot) ? json_decode($row->snapshot, true) : array();
        $row->reviewFeedback = !empty($row->reviewFeedback) ? json_decode($row->reviewFeedback, true) : null;
        // Brand → Delivery → Project: derive brand + delivery LIVE from the set's project so the
        // displayed scope follows the project, never the value stored at creation time.
        // When the set HAS a project the chain is the whole truth — including its NULLs, so a
        // project deliberately left with no delivery (or a delivery with no brand) reports
        // exactly that instead of falling back to a stale value stored at creation time.
        // Sets with no project keep their stored ids (legacy rows created before the
        // project-only mapping).
        if (class_exists('PCM_Hierarchy') && !empty($row->projectId)) {
            $chain = PCM_Hierarchy::for_project((int) $row->projectId);
            $row->brandId    = !empty($chain['brandId']) ? (int) $chain['brandId'] : null;
            $row->deliveryId = !empty($chain['deliveryId']) ? (int) $chain['deliveryId'] : null;
        }
        return $row;
    }

    /**
     * Statuses in which approval/submission is locked (post client sign-off).
     * Comments remain open in these statuses to support ongoing back-and-forth.
     *
     * @var string[]
     */
    public const POST_SUBMIT_STATUSES = ['launch', 'live', 'archived'];

    /**
     * Build the public client review URL for a token.
     *
     * Mirrors the frontend link: the published page containing the
     * [power_creatives] shortcode, plus ?pcm_public_token=<token>.
     *
     * @param string $token Share token.
     * @return string Absolute URL (falls back to home URL).
     */
    public static function build_share_url(string $token): string
    {
        global $wpdb;

        $base = home_url('/');
        $like = '%' . $wpdb->esc_like('[power_creatives]') . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s LIMIT 1",
            $like
        ));
        if ($page_id) {
            $base = get_permalink((int) $page_id);
        }

        return add_query_arg('pcm_public_token', $token, $base);
    }

    /**
     * Assemble the event context passed to the Automations engine.
     *
     * @param object $set   Approval set row (snapshot may be array or json).
     * @param array  $extra Extra context to merge (assetId, body, etc.).
     * @return array
     */
    private static function build_event_context(object $set, array $extra = array()): array
    {
        $snapshot = is_array($set->snapshot ?? null)
            ? $set->snapshot
            : (json_decode($set->snapshot ?? '', true) ?: array());

        $share_url = self::build_share_url((string) $set->token);

        // Client email: the set's own value first; fall back to the brand's stored
        // clientEmail so a team reply still emails the client even when the set was
        // shared as a bare link (no email captured on the set).
        $client_email = isset($set->clientEmail) ? (string) $set->clientEmail : '';
        if ($client_email === '' && !empty($set->brandId)) {
            $brand = PCM_DB::get_brand_by_id((int) $set->brandId, (int) $set->userId);
            if ($brand && !empty($brand->clientEmail)) {
                $client_email = (string) $brand->clientEmail;
            }
        }

        $context = array(
            'setId'       => (int) $set->id,
            'setName'     => (string) $set->name,
            'token'       => (string) $set->token,
            'brandId'     => !empty($set->brandId) ? (int) $set->brandId : null,
            'brandName'   => (string) ($snapshot['brandName'] ?? ''),
            'clientEmail' => $client_email,
            'shareUrl'    => $share_url,
        );

        // Allow callers to point the email/webhook at a specific asset thread.
        if (!empty($extra['assetId'])) {
            $context['assetUrl'] = $share_url . '#asset-' . rawurlencode((string) $extra['assetId']);
        }

        return array_merge($context, $extra);
    }

    /**
     * Read the current reviewFeedback structure for a set, normalised.
     *
     * @param object $set Approval set row.
     * @return array{ approvedVisualIds: array, approvedCopyIds: array, approvedArticleIds: array, comments: array }
     */
    private static function feedback_struct(object $set): array
    {
        $fb = is_array($set->reviewFeedback ?? null)
            ? $set->reviewFeedback
            : (json_decode($set->reviewFeedback ?? '', true) ?: array());

        return array(
            'approvedVisualIds'  => array_values($fb['approvedVisualIds'] ?? array()),
            'approvedCopyIds'    => array_values($fb['approvedCopyIds'] ?? array()),
            'approvedArticleIds' => array_values($fb['approvedArticleIds'] ?? array()),
            'approvedCustomIds'  => array_values($fb['approvedCustomIds'] ?? array()),
            'comments'           => is_array($fb['comments'] ?? null) ? $fb['comments'] : array(),
        );
    }

    /**
     * Persist a feedback structure back to the set.
     *
     * @param int   $set_id   Set id.
     * @param array $feedback Feedback structure.
     * @return bool
     */
    private static function save_feedback(int $set_id, array $feedback): bool
    {
        global $wpdb;
        $table = PCM_Schema::table('approval_sets');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->update(
            $table,
            array(
                'reviewFeedback' => wp_json_encode($feedback),
                'updatedAt'      => current_time('mysql'),
            ),
            array('id' => $set_id)
        );

        return $result !== false;
    }

    /**
     * Append a comment to an asset thread and dispatch the matching event.
     *
     * Shared by the public (client) and authenticated (team) comment endpoints.
     * Commenting is intentionally NOT gated by set status, so the conversation
     * can continue after the client has signed off.
     *
     * @param object $set      Approval set row.
     * @param string $asset_id Asset id the comment is attached to.
     * @param string $body     Comment text.
     * @param string $author   Author display name.
     * @param string $status   Comment status ('New' | 'Team reply').
     * @param string|null $parent_id Optional parent comment id for threading.
     * @param string $event    Automation event to dispatch.
     * @return array|false The created comment entry, or false on failure.
     */
    private static function append_comment(object $set, string $asset_id, string $body, string $author, string $status, ?string $parent_id, string $event): array|false
    {
        $asset_id = sanitize_text_field($asset_id);
        $body     = sanitize_textarea_field($body);
        if ($asset_id === '' || $body === '') {
            return false;
        }

        $feedback = self::feedback_struct($set);

        $comment = array(
            'id'        => wp_generate_uuid4(),
            'author'    => sanitize_text_field($author),
            'text'      => $body,
            'createdAt' => current_time('c'),
            'status'    => $status,
            'parentId'  => $parent_id ? sanitize_text_field($parent_id) : null,
        );

        if (!isset($feedback['comments'][$asset_id]) || !is_array($feedback['comments'][$asset_id])) {
            $feedback['comments'][$asset_id] = array();
        }
        $feedback['comments'][$asset_id][] = $comment;

        if (!self::save_feedback((int) $set->id, $feedback)) {
            return false;
        }

        PCM_Automation_Engine::dispatch(
            $event,
            self::build_event_context($set, array(
                'assetId'   => $asset_id,
                'commentId' => $comment['id'],
                'author'    => $comment['author'],
                'body'      => $comment['text'],
            )),
            (int) $set->userId
        );

        // User-editable automation trigger (covers BOTH client comments and
        // team replies — append_comment is the single funnel). Powers the
        // seeded in-app notification rule + any user rules (e.g. webhooks).
        PCM_Automation_Engine::fire_trigger(
            'approvals.comment_added',
            array_merge(
                array(
                    'setId'     => (int) $set->id,
                    'name'      => (string) $set->name,
                    'token'     => (string) $set->token,
                    'link'      => self::build_share_url((string) $set->token),
                    'assetId'   => $asset_id,
                    'commentId' => $comment['id'],
                    'author'    => $comment['author'],
                    'body'      => $comment['text'],
                    'brandId'   => !empty($set->brandId) ? (int) $set->brandId : null,
                ),
                self::enrich_context($set, $asset_id, $comment['text']) // setComment = this comment's content
            ),
            (int) $set->userId
        );

        return $comment;
    }

    /**
     * Cross-entity enrichment for approval triggers/webhooks: resolves the
     * client (brand) name, the delivery + project the set belongs to (via the
     * brand link on deliveries), who is assigned, and stable deep links.
     *
     * @param object      $set      Approval set row.
     * @param string|null $asset_id Optional asset id for the comment deep link.
     * @return array
     */
    private static function enrich_context(object $set, ?string $asset_id = null, string $comment = ''): array
    {
        global $wpdb;

        $owner_id = (int) $set->userId;

        // Brand → Delivery → Project is canonical: when the set has a project, derive its brand
        // + delivery LIVE from the project's current chain (PCM_Hierarchy) so reassigning the
        // project (or its delivery/brand) moves the set with it. Legacy sets with no project
        // chain fall back to the brand/delivery stored on the set.
        $chain        = class_exists('PCM_Hierarchy') ? PCM_Hierarchy::for_project((int) ($set->projectId ?? 0)) : array();
        $brand_id     = !empty($chain['brandId']) ? (int) $chain['brandId'] : (!empty($set->brandId) ? (int) $set->brandId : 0);
        $eff_delivery = !empty($chain['deliveryId']) ? (int) $chain['deliveryId'] : (!empty($set->deliveryId) ? (int) $set->deliveryId : 0);

        $brand_name = '';
        $brand_ext  = '';
        if ($brand_id > 0) {
            $brand      = PCM_DB::get_brand_by_id($brand_id, $owner_id);
            $brand_name = $brand ? (string) $brand->name : '';
            $brand_ext  = $brand ? (string) ($brand->externalId ?? '') : ''; // manual/automation external id
        }

        $delivery_name = '';
        $delivery_ext  = '';
        $delivery_id   = 0;
        $project_name  = '';
        $project_ext   = '';
        $project_id    = (int) ($set->projectId ?? 0); // canonical: the set's own project
        $assignees     = '';
        $delivery      = null;
        $deliveries_t  = PCM_Schema::table('deliveries');
        if ($eff_delivery > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $delivery = $wpdb->get_row($wpdb->prepare(
                "SELECT id, name, projectId, externalId FROM {$deliveries_t} WHERE id = %d",
                $eff_delivery
            ));
        }
        if (!$delivery && $brand_id > 0 && $project_id === 0) {
            // Legacy fallback (set has no project): latest delivery linked to the brand.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $delivery = $wpdb->get_row($wpdb->prepare(
                "SELECT id, name, projectId, externalId FROM {$deliveries_t}
                 WHERE userId = %d AND brandId = %d
                 ORDER BY updatedAt DESC, id DESC LIMIT 1",
                $owner_id,
                $brand_id
            ));
        }
        if ($delivery) {
            $delivery_name = (string) $delivery->name;
            $delivery_ext  = (string) ($delivery->externalId ?? '');
            $delivery_id   = (int) $delivery->id;

            // The project name is resolved from the canonical $project_id below; only borrow the
            // delivery's legacy projectId when the set itself has no project.
            if ($project_id === 0 && !empty($delivery->projectId)) {
                $project_id = (int) $delivery->projectId;
            }

            $assignments_t = PCM_Schema::table('delivery_assignments');
            $users_t       = PCM_Schema::table('users');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $names = $wpdb->get_col($wpdb->prepare(
                "SELECT u.name FROM {$assignments_t} a
                 INNER JOIN {$users_t} u ON u.id = a.userId
                 WHERE a.deliveryId = %d",
                (int) $delivery->id
            ));
            $assignees = implode(', ', array_filter(array_map('strval', $names ?: array())));
        }

        // Resolve the project name from the canonical project id (set's own project, or the
        // delivery's legacy project as a fallback).
        if ($project_id > 0) {
            $projects_t = PCM_Schema::table('projects');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $prow = $wpdb->get_row($wpdb->prepare(
                "SELECT name, externalId FROM {$projects_t} WHERE id = %d",
                $project_id
            ));
            $project_name = $prow ? (string) $prow->name : '';
            $project_ext  = $prow ? (string) ($prow->externalId ?? '') : '';
        }

        $share_url = self::build_share_url((string) $set->token);

        return array(
            // Set-level tokens — well-named for webhook/automation consumers (n8n etc.).
            'setID'           => (int) $set->id,
            'setName'         => (string) $set->name,
            'setStatus'       => (string) ($set->status ?? ''),
            'setLink'         => $share_url, // public client review link
            'setInternalLink' => admin_url('admin.php?page=power-creatives&pcm_approval_set=' . (int) $set->id), // team edit link inside the Approvals module
            'setComment'      => $comment, // the comment text that triggered the hook (empty for non-comment triggers)
            // IDs are exposed alongside names so webhook consumers (n8n etc.)
            // can key off stable identifiers, not just display strings. Empty
            // ids are emitted as '' (the webhook handler drops empty values).
            // *ExtID are the entity's own EXTERNAL id field (set manually or by an automation),
            // for mapping our brand/delivery/project to the consumer's system of record.
            'brandId'         => $brand_id > 0 ? $brand_id : '',
            'brandName'       => $brand_name,
            'brandExtID'      => $brand_ext,
            'deliveryId'      => $delivery_id > 0 ? $delivery_id : '',
            'deliveryName'    => $delivery_name,
            'deliveryExtID'   => $delivery_ext,
            'projectId'       => $project_id > 0 ? $project_id : '',
            'projectName'     => $project_name,
            'projectExtID'    => $project_ext,
            'projectAssignee' => $assignees,
            'commentUrl'      => $asset_id !== null && $asset_id !== ''
                ? $share_url . '#asset-' . rawurlencode($asset_id)
                : $share_url,
            'dashboardUrl'    => admin_url('admin.php?page=power-creatives'),
        );
    }

    /**
     * Client adds a comment (public, token-scoped).
     *
     * @param string $token     Share token.
     * @param string $asset_id  Asset id.
     * @param string $body      Comment text.
     * @param string|null $author Optional author name (defaults to 'Client').
     * @param string|null $parent_id Optional parent comment id.
     * @return array|false
     */
    public static function add_public_comment(string $token, string $asset_id, string $body, ?string $author = null, ?string $parent_id = null): array|false
    {
        $set = self::get_set_by_token($token);
        if (!$set) {
            return false;
        }

        return self::append_comment(
            $set,
            $asset_id,
            $body,
            $author ?: 'Client',
            'New',
            $parent_id,
            PCM_Automation_Events::APPROVAL_COMMENT_CREATED
        );
    }

    /**
     * Team member replies in a thread (authenticated, ownership-scoped).
     *
     * @param int    $set_id    Set id.
     * @param int    $user_id   PCM user id (owner).
     * @param string $asset_id  Asset id.
     * @param string $body      Reply text.
     * @param string $author    Author name.
     * @param string|null $parent_id Optional parent comment id.
     * @return array|false
     */
    public static function add_team_comment(int $set_id, int $user_id, string $asset_id, string $body, string $author, ?string $parent_id = null): array|false
    {
        // View-scoped, not owner-scoped: the board lists granted sets too, and
        // commenting is collaboration — anyone who can SEE the set (owner,
        // admin, granted brand/project) can reply. Destructive ops stay
        // owner-scoped.
        $set = self::get_set_scoped($set_id, $user_id);
        if (!$set) {
            return false;
        }

        return self::append_comment(
            $set,
            $asset_id,
            $body,
            $author ?: 'Team',
            'Team reply',
            $parent_id,
            PCM_Automation_Events::APPROVAL_COMMENT_TEAM_REPLY
        );
    }

    /**
     * Determine which approved-id bucket an asset belongs to by scanning the
     * snapshot. Returns 'approvedVisualIds' | 'approvedCopyIds' |
     * 'approvedArticleIds' or null when the asset is not in the snapshot.
     *
     * @param array  $snapshot Snapshot array.
     * @param string $asset_id Asset id.
     * @return string|null
     */
    private static function bucket_for_asset(array $snapshot, string $asset_id): ?string
    {
        // ONE definition — see ITEM_BUCKETS. This map used to be written out here
        // AND again in is_fully_approved(), so which approval list belonged to
        // which bucket was asserted twice and could drift apart silently.
        foreach (self::approval_key_map() as $key => $bucket) {
            if (!empty($snapshot[$key]) && is_array($snapshot[$key])) {
                foreach ($snapshot[$key] as $item) {
                    if (isset($item['id']) && (string) $item['id'] === $asset_id) {
                        return $bucket;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Whether every asset in the snapshot has been approved.
     *
     * @param array $snapshot Snapshot array.
     * @param array $feedback Feedback structure.
     * @return bool True only when the set is non-empty and fully approved.
     */
    private static function is_fully_approved(array $snapshot, array $feedback): bool
    {
        $checks = self::approval_key_map();

        $total = 0;
        foreach ($checks as $key => $bucket) {
            $items = (!empty($snapshot[$key]) && is_array($snapshot[$key])) ? $snapshot[$key] : array();
            $total += count($items);
            $approved = array_map('strval', $feedback[$bucket] ?? array());
            foreach ($items as $item) {
                if (!isset($item['id']) || !in_array((string) $item['id'], $approved, true)) {
                    return false;
                }
            }
        }

        return $total > 0;
    }

    /**
     * Approve / unapprove one asset, or approve everything. Persists immediately
     * and, when the set becomes fully approved, advances it to 'launch' and
     * dispatches the approval.all_approved event (idempotent — only on the
     * transition into a post-submit status).
     *
     * @param string $token Share token.
     * @param array  $args  { approveAll?: bool, assetId?: string, type?: string, approved?: bool }.
     * @return object|false Updated set, or false if not found.
     */
    public static function approve_assets(string $token, array $args): object|false
    {
        $set = self::get_set_by_token($token);
        if (!$set) {
            return false;
        }

        // Locked once signed off — return the set unchanged.
        if (in_array($set->status, self::POST_SUBMIT_STATUSES, true)) {
            return $set;
        }

        $snapshot = is_array($set->snapshot) ? $set->snapshot : array();
        $feedback = self::feedback_struct($set);

        // Track the approve TRANSITION (never unapprove) so the
        // approvals.asset_approved trigger fires exactly when something new
        // got approved — 'all' for the approve-all action.
        $approved_asset = null;

        if (!empty($args['approveAll'])) {
            $feedback['approvedVisualIds']  = self::collect_ids($snapshot, 'media');
            $feedback['approvedCopyIds']    = self::collect_ids($snapshot, 'copy');
            $feedback['approvedArticleIds'] = self::collect_ids($snapshot, 'articles');
            $feedback['approvedCustomIds']  = self::collect_ids($snapshot, 'custom');
            $approved_asset = 'all';
        } else {
            $asset_id = sanitize_text_field($args['assetId'] ?? '');
            if ($asset_id === '') {
                return $set;
            }
            $bucket = self::bucket_for_asset($snapshot, $asset_id);
            if ($bucket === null) {
                return $set;
            }
            $approved = array_map('strval', $feedback[$bucket]);
            $is_on    = in_array($asset_id, $approved, true);
            $want_on  = array_key_exists('approved', $args) ? (bool) $args['approved'] : !$is_on;

            if ($want_on && !$is_on) {
                $approved[] = $asset_id;
                $approved_asset = $asset_id;
            } elseif (!$want_on && $is_on) {
                $approved = array_values(array_diff($approved, array($asset_id)));
            }
            $feedback[$bucket] = $approved;
        }

        self::save_feedback((int) $set->id, $feedback);

        // User-editable automation trigger — powers the seeded in-app
        // notification rule + any user rules (e.g. enriched webhooks).
        if ($approved_asset !== null && class_exists('PCM_Automation_Engine')) {
            PCM_Automation_Engine::fire_trigger(
                'approvals.asset_approved',
                array_merge(
                    array(
                        'setId'   => (int) $set->id,
                        'name'    => (string) $set->name,
                        'token'   => (string) $set->token,
                        'link'    => self::build_share_url((string) $set->token),
                        'assetId' => $approved_asset,
                        'brandId' => !empty($set->brandId) ? (int) $set->brandId : null,
                    ),
                    self::enrich_context($set, $approved_asset === 'all' ? null : $approved_asset)
                ),
                (int) $set->userId
            );
        }

        // Auto-advance into Launch when every asset is approved. The lane move is
        // NO LONGER hardcoded — it is driven by the editable "Approval set is fully
        // approved → move to Launch" automation rule. We fire the trigger exactly
        // once on the transition into full approval; the rule's move_to_lane action
        // sets 'launch', which in turn fires approvals.set_status_changed so any
        // "lane = Launch → webhook" rule still chains.
        if ($set->status !== 'launch'
            && self::is_fully_approved($snapshot, $feedback)
            && class_exists('PCM_Automation_Engine')
        ) {
            PCM_Automation_Engine::fire_trigger(
                'approvals.set_fully_approved',
                array_merge(
                    // BUGFIX: this trigger used to pass ONLY the five legacy keys, so every
                    // rich token its contextKeys advertise ({{setID}}/{{setName}}/{{setLink}}/
                    // {{brandName}}/{{deliveryName}}/{{projectName}}/{{dashboardUrl}}…) resolved
                    // EMPTY in webhooks and emails. Merged the same enrich_context() its three
                    // sibling approvals triggers already use, so what the UI offers is what the
                    // payload carries.
                    self::enrich_context($set),
                    array(
                        // Legacy keys — kept so existing rules keep resolving.
                        'setId'   => (int) $set->id,
                        'name'    => (string) $set->name,
                        'token'   => (string) $set->token,
                        'link'    => self::build_share_url((string) $set->token),
                        'brandId' => !empty($set->brandId) ? (int) $set->brandId : null,
                    )
                ),
                (int) $set->userId
            );
        }

        return self::get_set_by_token($token);
    }

    /**
     * Collect snapshot asset ids for a bucket key.
     *
     * @param array  $snapshot Snapshot array.
     * @param string $key      'media' | 'copy' | 'articles'.
     * @return string[]
     */
    private static function collect_ids(array $snapshot, string $key): array
    {
        $ids = array();
        if (!empty($snapshot[$key]) && is_array($snapshot[$key])) {
            foreach ($snapshot[$key] as $item) {
                if (isset($item['id'])) {
                    $ids[] = (string) $item['id'];
                }
            }
        }
        return $ids;
    }

    /**
     * Share a set with one or more clients: store the recipient, dispatch the
     * invite email to EVERY recipient, and fire the "sent to client" trigger
     * ONCE. Ownership-scoped.
     *
     * Multi-recipient is one share EVENT with N emails — not N shares. The
     * trigger drives the seeded "move to Sent to Client" rule and any user
     * webhook, so firing it per recipient would run those N times; the invite
     * email is the only thing that is legitimately per-person.
     *
     * `clientEmail` on the set (and the brand's remembered address) stay
     * single-valued on purpose: they answer "who is this set with", which is the
     * first recipient. Multi-send is an action, not new state.
     *
     * @param int             $set_id  Set id.
     * @param int             $user_id PCM user id (owner).
     * @param string|string[] $email   Recipient email, or a list of them.
     * @param string          $message Optional custom invite message (already sanitized).
     * @return object|false Updated set, or false if not found / no valid email.
     */
    public static function share_set(int $set_id, int $user_id, string|array $email, string $message = ''): object|false
    {
        // Normalise to a de-duplicated list of valid addresses, order preserved.
        $recipients = array();
        foreach ((is_array($email) ? $email : array($email)) as $candidate) {
            $clean = sanitize_email((string) $candidate);
            if ($clean !== '' && is_email($clean) && !in_array($clean, $recipients, true)) {
                $recipients[] = $clean;
            }
        }
        if (empty($recipients)) {
            return false;
        }
        // The set's own record of "who is this with" — the first recipient.
        $email = $recipients[0];

        $set = self::get_set_by_id($set_id, $user_id);
        if (!$set) {
            return false;
        }

        global $wpdb;
        $table = PCM_Schema::table('approval_sets');

        // Persist the recipient email. The lane move is NO LONGER hardcoded here —
        // it is driven by the editable "Approval set is sent to client → move to
        // 'Sent to Client for Approval'" automation rule (fired below). That rule's
        // move_to_lane action calls update_status(), which stamps clientSentAt on
        // entry into the 'client' lane.
        //
        // Fire for any pre-submit lane INCLUDING 'client': the share dialogs
        // pre-move the set to 'client' at link-generation time, so restricting
        // to draft/internal would mean user rules on "set is sent to client"
        // (e.g. notify Slack) never fire from the primary UI flows. Re-firing
        // while already in 'client' is safe — move_to_lane no-ops on an
        // unchanged lane. Post-submit lanes (launch/live/archived) stay
        // excluded so a re-share can't drag a launched set backwards.
        $needs_share = !in_array($set->status, self::POST_SUBMIT_STATUSES, true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            array('clientEmail' => $email, 'updatedAt' => current_time('mysql')),
            array('id' => $set_id, 'userId' => $user_id)
        );
        $set->clientEmail = $email;

        // Remember the recipient on the brand so future shares auto-fill.
        if (!empty($set->brandId)) {
            PCM_DB::update_brand((int) $set->brandId, $user_id, array('clientEmail' => $email));
        }

        // Fire the editable "sent to client" automation trigger — only when the
        // set is actually being sent out (not already past the client lane), to
        // preserve the original no-downgrade guard.
        if ($needs_share && class_exists('PCM_Automation_Engine')) {
            PCM_Automation_Engine::fire_trigger(
                'approvals.set_shared',
                array_merge(
                    // Full brand→delivery→project + set tokens (setID/setName/setStatus/setLink/
                    // setInternalLink/brandName/deliveryName/projectName) for webhook mapping.
                    self::enrich_context($set),
                    array(
                        // Legacy keys — kept so existing rules keep resolving.
                        'setId'       => (int) $set->id,
                        'name'        => (string) $set->name,
                        'token'       => (string) $set->token,
                        'link'        => self::build_share_url((string) $set->token),
                        'clientEmail' => $email,
                        'brandId'     => !empty($set->brandId) ? (int) $set->brandId : null,
                    )
                ),
                $user_id
            );
        }

        // The invite email is the one thing that is per-person: dispatch once per
        // recipient, overriding the address the email channel reads
        // (render_email_for_event() takes it from context['clientEmail']).
        foreach ($recipients as $recipient) {
            PCM_Automation_Engine::dispatch(
                PCM_Automation_Events::APPROVAL_SET_SHARED,
                self::build_event_context($set, array(
                    'customMessage' => $message,
                    'clientEmail'   => $recipient,
                )),
                $user_id
            );
        }

        return self::get_set_by_id($set_id, $user_id);
    }

    /**
     * Sanitise a document body WITHOUT destroying its embedded images.
     *
     * `wp_kses_post()` drops any `src` whose scheme is not in
     * `wp_allowed_protocols()` — and that list has no `data:`. A card whose
     * images are inline base64 therefore came back with every image stripped of
     * its source; the editor then dropped the source-less nodes, and the next
     * save wrote the resulting EMPTY document over the real one. That is how
     * approval set 26 lost 489,939 bytes of content on 2026-08-06.
     *
     * `data:` is added for the duration of this one call and removed again, so
     * nothing else in the request gains a protocol it should not have. The
     * payload still goes through the full kses tag/attribute filter — this
     * widens exactly one scheme, on exactly one field.
     *
     * @param string $html Raw document HTML from the editor.
     * @return string Sanitised HTML with inline images intact.
     */
    private static function sanitize_document_html(string $html): string
    {
        $allow_data = static function (array $protocols): array {
            $protocols[] = 'data';
            return $protocols;
        };

        add_filter('kses_allowed_protocols', $allow_data);
        $clean = wp_kses_post($html);
        remove_filter('kses_allowed_protocols', $allow_data);

        return $clean;
    }

    /**
     * Would this write empty a document that currently has content?
     *
     * A save is an edit, never an erasure. An editor that failed to hydrate, a
     * dropped node, or a half-mounted view all produce the same thing: an empty
     * paragraph. Storing that destroys work no one asked to delete, so it is
     * refused and the stored content is kept.
     *
     * Deliberately narrow — it only blocks EMPTYING. Deleting every word by hand
     * still leaves the paragraph the editor emits, so this cannot be worked
     * around by intent; clearing a card is what removing the card is for.
     *
     * @param string $incoming Sanitised HTML about to be stored.
     * @param string $stored   HTML currently in the snapshot.
     */
    private static function would_erase_document(string $incoming, string $stored): bool
    {
        if (trim(wp_strip_all_tags($stored)) === '' && stripos($stored, '<img') === false) {
            return false; // Nothing to lose.
        }
        return trim(wp_strip_all_tags($incoming)) === '' && stripos($incoming, '<img') === false;
    }

    /**
     * Update an asset (e.g. ad copy text) inside the approval set's snapshot and propagate it.
     */
    public static function update_snapshot_asset(string $token, string $asset_id, array $updates): bool
    {
        global $wpdb;
        $table = PCM_Schema::table('approval_sets');

        $set = self::get_set_by_token($token);
        if (!$set || empty($set->snapshot)) {
            return false;
        }

        $snapshot = $set->snapshot;
        $updated = false;

        // Search in copy snapshot assets
        if (!empty($snapshot['copy']) && is_array($snapshot['copy'])) {
            foreach ($snapshot['copy'] as &$item) {
                if (isset($item['id']) && (string)$item['id'] === (string)$asset_id) {
                    if (isset($updates['body'])) {
                        $item['body'] = sanitize_textarea_field($updates['body']);
                    }
                    if (isset($updates['headline'])) {
                        $item['headline'] = sanitize_text_field($updates['headline']);
                    }
                    if (isset($updates['description'])) {
                        $item['description'] = sanitize_textarea_field($updates['description']);
                    }
                    $updated = true;
                    break;
                }
            }
        }

        // Search in media snapshot assets (just in case)
        if (!empty($snapshot['media']) && is_array($snapshot['media'])) {
            foreach ($snapshot['media'] as &$item) {
                if (isset($item['id']) && (string)$item['id'] === (string)$asset_id) {
                    if (isset($updates['name'])) {
                        $item['name'] = sanitize_text_field($updates['name']);
                    }
                    $updated = true;
                    break;
                }
            }
        }

        // Search in articles snapshot assets (Writer articles in approval sets)
        if (!$updated && !empty($snapshot['articles']) && is_array($snapshot['articles'])) {
            foreach ($snapshot['articles'] as &$item) {
                if (isset($item['id']) && (string)$item['id'] === (string)$asset_id) {
                    if (isset($updates['title'])) {
                        $item['title'] = sanitize_text_field($updates['title']);
                    }
                    if (isset($updates['content'])) {
                        $clean = self::sanitize_document_html((string) $updates['content']);
                        if (!self::would_erase_document($clean, (string) ($item['content'] ?? ''))) {
                            $item['content'] = $clean;
                        }
                    }
                    if (isset($updates['metaTitle'])) {
                        $item['metaTitle'] = sanitize_text_field($updates['metaTitle']);
                    }
                    if (isset($updates['metaDescription'])) {
                        $item['metaDescription'] = sanitize_textarea_field($updates['metaDescription']);
                    }
                    $updated = true;
                    break;
                }
            }
        }

        // Search in custom snapshot assets (Notion-style custom cards).
        if (!$updated && !empty($snapshot['custom']) && is_array($snapshot['custom'])) {
            foreach ($snapshot['custom'] as &$item) {
                if (isset($item['id']) && (string)$item['id'] === (string)$asset_id) {
                    if (isset($updates['title'])) {
                        $item['title'] = sanitize_text_field($updates['title']);
                    }
                    if (isset($updates['content'])) {
                        $clean = self::sanitize_document_html((string) $updates['content']);
                        if (!self::would_erase_document($clean, (string) ($item['content'] ?? ''))) {
                            $item['content'] = $clean;
                        }
                    }
                    if (isset($updates['images']) && is_array($updates['images'])) {
                        $item['images'] = array_map('esc_url_raw', $updates['images']);
                    }
                    // Freehand draw layer. It could only be set at CREATE time before
                    // this, so drawing on an already-shared card lost the strokes on
                    // save. Not run through esc_url_raw: WP's allowed protocols exclude
                    // `data:`, so that would blank every overlay. Validated against the
                    // exact shape the draw layer produces instead — a base64 PNG — and
                    // anything else is rejected rather than stored.
                    if (array_key_exists('overlay', $updates)) {
                        $overlay = $updates['overlay'];
                        if ($overlay === null || $overlay === '') {
                            unset($item['overlay']);
                        } elseif (is_string($overlay)
                            && preg_match('#^data:image/png;base64,[A-Za-z0-9+/]+={0,2}$#', $overlay)
                        ) {
                            $item['overlay'] = $overlay;
                        }
                    }
                    // Annotation metadata is stored opaque (Phase 2) — passed through as-is.
                    if (array_key_exists('annotation', $updates)) {
                        $item['annotation'] = $updates['annotation'];
                    }
                    $item['updatedAt'] = current_time('mysql');
                    $updated = true;
                    break;
                }
            }
            unset($item);
        }

        if (!$updated) {
            return false;
        }

        // 1. Save updated snapshot back to DB
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->update(
            $table,
            array(
                'snapshot'  => wp_json_encode($snapshot),
                'updatedAt' => current_time('mysql'),
            ),
            array('id' => (int)$set->id)
        );

        if ($result === false) {
            return false;
        }

        // 2. Propagate updates back to the original copy_results table if the ID is numeric
        if (is_numeric($asset_id)) {
            $copy_table = PCM_Schema::table('copy_results');
            $copy_updates = array();
            if (isset($updates['body'])) {
                $copy_updates['body'] = sanitize_textarea_field($updates['body']);
            }
            if (isset($updates['headline'])) {
                $copy_updates['headline'] = sanitize_text_field($updates['headline']);
            }
            if (isset($updates['description'])) {
                $copy_updates['description'] = sanitize_textarea_field($updates['description']);
            }
            if (!empty($copy_updates)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($copy_table, $copy_updates, array('id' => (int)$asset_id));
            }
        }

        return true;
    }
}
