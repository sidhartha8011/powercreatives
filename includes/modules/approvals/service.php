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
     *   internal  — out for internal team review.
     *   client    — out for client review (public link sent).
     *   approved  — client signed off; round complete.
     *   live      — assets in production / running.
     *   archived  — closed; retained for reference.
     *
     * @var string[]
     */
    public const STATUSES = ['draft', 'internal', 'client', 'approved', 'live', 'archived'];

    /**
     * Legacy → new status migration map. Consumed by
     * PCM_Schema::migrate_approval_set_statuses() (v1.8.0).
     *
     * @var array<string, string>
     */
    public const LEGACY_STATUS_MAP = [
        'review'    => 'client',
        'completed' => 'approved',
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
            'comments'          => array(),
        );

        if (!empty($feedback['comments']) && is_array($feedback['comments'])) {
            foreach ($feedback['comments'] as $itemId => $commentText) {
                $sanitized_feedback['comments'][sanitize_text_field($itemId)] = sanitize_textarea_field($commentText);
            }
        }

        // Client review submission transitions the set to 'approved'.
        // (Old taxonomy used 'completed'; v1.8.0 migration remaps legacy data.)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $success = $wpdb->update(
            $table,
            array(
                'status'         => 'approved',
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
