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
     *   create    — client signed off; team is building the campaign assets.
     *   launch    — campaign is being prepared for launch.
     *   live      — campaign is running in market.
     *   archived  — closed or paused; retained for reference.
     *
     * @var string[]
     */
    public const STATUSES = ['draft', 'internal', 'client', 'create', 'launch', 'live', 'archived'];

    /**
     * Legacy → new status migration map. Iterated in declaration order by
     * PCM_Schema::migrate_approval_set_statuses() — later entries can
     * catch values produced by earlier ones in the same pass.
     *
     * History:
     *   v1.8.0: review→client, completed→approved.
     *   v1.9.0: approved→create (mid-workflow rename for 7-status taxonomy).
     *
     * @var array<string, string>
     */
    public const LEGACY_STATUS_MAP = [
        'review'    => 'client',
        'completed' => 'approved',
        'approved'  => 'create',
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, userId, brandId, projectId, name, token, status, createdAt, updatedAt FROM {$table} WHERE userId = %d ORDER BY createdAt DESC",
                $user_id
            )
        );

        return array_map(function ($row) {
            return self::format_set_row($row);
        }, $rows ?: array());
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
     * @param int   $user_id User ID.
     * @param array $data    Set fields (name, brandId, projectId, snapshot).
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->insert(
            $table,
            array(
                'userId'    => $user_id,
                'brandId'   => $data['brandId'] ?? null,
                'projectId' => $data['projectId'] ?? null,
                'name'      => $data['name'],
                'token'     => $token,
                'status'    => 'draft',
                'snapshot'  => $snapshot,
            )
        );

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

        global $wpdb;
        $table = PCM_Schema::table('approval_sets');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->update(
            $table,
            array(
                'status'    => $next_status,
                'updatedAt' => current_time('mysql'),
            ),
            array('id' => $id, 'userId' => $user_id),
            array('%s', '%s'),
            array('%d', '%d')
        );

        return $rows !== false && $rows > 0;
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

        global $wpdb;
        $table = PCM_Schema::table('approval_sets');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->delete(
            $table,
            array('id' => $id, 'userId' => $user_id),
            array('%d', '%d')
        );

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

        global $wpdb;
        $table = PCM_Schema::table('approval_sets');
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE userId = %d AND id IN ({$placeholders})",
                array_merge(array($user_id), $ids)
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

        // Tidy feedback arrays
        $sanitized_feedback = array(
            'approvedVisualIds' => array_map('sanitize_text_field', $feedback['approvedVisualIds'] ?? array()),
            'approvedCopyIds'   => array_map('sanitize_text_field', $feedback['approvedCopyIds'] ?? array()),
            'comments'          => self::sanitize_comment_threads($feedback['comments'] ?? array(), $client_name),
        );

        // Client review submission auto-advances the set to 'create' (next
        // workflow step — campaign creation). History:
        //   v1.7.0 = 'completed', v1.8.0 = 'approved', v1.9.0 = 'create'.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $success = $wpdb->update(
            $table,
            array(
                'status'         => 'create',
                'reviewFeedback' => wp_json_encode($sanitized_feedback),
                'updatedAt'      => current_time('mysql'),
            ),
            array('id' => (int)$set->id)
        );

        if ($success !== false) {
            self::dispatch_webhook($set, $client_name, $sanitized_feedback);
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
            'approvedVisualIds' => array_map('sanitize_text_field', $feedback['approvedVisualIds'] ?? array()),
            'approvedCopyIds'   => array_map('sanitize_text_field', $feedback['approvedCopyIds'] ?? array()),
            'comments'          => self::sanitize_comment_threads($feedback['comments'] ?? array(), 'Client'),
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
                        'id'        => wp_generate_uuid4(),
                        'author'    => $default_author,
                        'text'      => sanitize_textarea_field($thread_array),
                        'createdAt' => current_time('c'),
                        'status'    => 'New',
                        'parentId'  => null,
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
                        'id'        => sanitize_text_field($entry['id'] ?? wp_generate_uuid4()),
                        'author'    => sanitize_text_field($entry['author'] ?? $default_author),
                        'text'      => sanitize_textarea_field($entry['text']),
                        'createdAt' => sanitize_text_field($entry['createdAt'] ?? current_time('c')),
                        'status'    => $status,
                        'parentId'  => isset($entry['parentId']) ? sanitize_text_field($entry['parentId']) : null,
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
     * Format database row JSON strings into standard array formats.
     */
    private static function format_set_row(object $row): object
    {
        $row->snapshot       = !empty($row->snapshot) ? json_decode($row->snapshot, true) : array();
        $row->reviewFeedback = !empty($row->reviewFeedback) ? json_decode($row->reviewFeedback, true) : null;
        return $row;
    }

    /**
     * Dispatch client review webhook (non-blocking).
     */
    private static function dispatch_webhook(object $set, string $client_name, array $feedback): void
    {
        $webhook_url = '';

        // 1. Check Brand Settings override
        if (!empty($set->brandId)) {
            $brand = PCM_DB::get_brand_by_id((int)$set->brandId, (int)$set->userId);
            if ($brand && !empty($brand->additionalContext)) {
                $context = json_decode($brand->additionalContext, true);
                if (is_array($context) && !empty($context['webhookUrl'])) {
                    $webhook_url = esc_url_raw($context['webhookUrl']);
                }
            }
        }

        // 2. Fallback to Global Settings
        if (empty($webhook_url)) {
            $webhook_url = PCM_Settings::get('global_webhook_url', '');
        }

        if (empty($webhook_url)) {
            return; // Webhook URL not configured
        }

        $payload = array(
            'event'      => 'approval.completed',
            'setId'      => (int)$set->id,
            'setName'    => $set->name,
            'token'      => $set->token,
            'clientName' => $client_name,
            'feedback'   => $feedback,
            'timestamp'  => current_time('c'),
        );

        // Perform non-blocking outbound HTTP POST
        wp_remote_post($webhook_url, array(
            'headers'     => array('Content-Type' => 'application/json'),
            'body'        => wp_json_encode($payload),
            'timeout'     => 15,
            'redirection' => 5,
            'blocking'    => false, // Non-blocking
        ));
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
