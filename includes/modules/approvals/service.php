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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, userId, brandId, projectId, name, token, status, clientEmail, createdAt, updatedAt FROM {$table} WHERE userId = %d ORDER BY createdAt DESC",
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

        // Load first so we know the previous lane (to fire the trigger only on
        // an actual change) and have the name/token for the payload.
        $set = self::get_set_by_id($id, $user_id);
        if (!$set) {
            return false;
        }
        $previous = $set->status;

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
            array(
                'setId'   => (int) $set->id,
                'name'    => (string) $set->name,
                'status'  => $new_status,
                'token'   => (string) $set->token,
                'link'    => self::build_share_url((string) $set->token),
                'brandId' => !empty($set->brandId) ? (int) $set->brandId : null,
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
            'approvedVisualIds'  => array_map('sanitize_text_field', $feedback['approvedVisualIds'] ?? array()),
            'approvedCopyIds'    => array_map('sanitize_text_field', $feedback['approvedCopyIds'] ?? array()),
            'approvedArticleIds' => array_map('sanitize_text_field', $feedback['approvedArticleIds'] ?? array()),
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

        $context = array(
            'setId'       => (int) $set->id,
            'setName'     => (string) $set->name,
            'token'       => (string) $set->token,
            'brandId'     => !empty($set->brandId) ? (int) $set->brandId : null,
            'brandName'   => (string) ($snapshot['brandName'] ?? ''),
            'clientEmail' => isset($set->clientEmail) ? (string) $set->clientEmail : '',
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

        return $comment;
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
        $set = self::get_set_by_id($set_id, $user_id);
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
        $map = array(
            'media'    => 'approvedVisualIds',
            'copy'     => 'approvedCopyIds',
            'articles' => 'approvedArticleIds',
        );
        foreach ($map as $key => $bucket) {
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
        $checks = array(
            'media'    => 'approvedVisualIds',
            'copy'     => 'approvedCopyIds',
            'articles' => 'approvedArticleIds',
        );

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

        if (!empty($args['approveAll'])) {
            $feedback['approvedVisualIds']  = self::collect_ids($snapshot, 'media');
            $feedback['approvedCopyIds']    = self::collect_ids($snapshot, 'copy');
            $feedback['approvedArticleIds'] = self::collect_ids($snapshot, 'articles');
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
            } elseif (!$want_on && $is_on) {
                $approved = array_values(array_diff($approved, array($asset_id)));
            }
            $feedback[$bucket] = $approved;
        }

        self::save_feedback((int) $set->id, $feedback);

        // Auto-advance into Launch when every asset is approved, and fire the
        // Automations "set entered lane" trigger (same path as a kanban drag),
        // so a "lane = Launch → webhook" rule runs exactly once on the transition.
        if ($set->status !== 'launch' && self::is_fully_approved($snapshot, $feedback)) {
            self::update_status_unscoped((int) $set->id, 'launch');
            $set->status = 'launch';
            self::fire_status_trigger($set, 'launch');
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
     * Update status without an ownership check (internal, after we've already
     * validated the set via token). Distinct from update_status() which is
     * user-scoped for the team kanban.
     *
     * @param int    $set_id Set id.
     * @param string $status New status (assumed valid).
     * @return void
     */
    private static function update_status_unscoped(int $set_id, string $status): void
    {
        global $wpdb;
        $table = PCM_Schema::table('approval_sets');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            array('status' => $status, 'updatedAt' => current_time('mysql')),
            array('id' => $set_id)
        );
    }

    /**
     * Share a set with a client: store the recipient email and dispatch the
     * invite event (Brevo email). Ownership-scoped.
     *
     * @param int    $set_id  Set id.
     * @param int    $user_id PCM user id (owner).
     * @param string $email   Recipient email.
     * @return object|false Updated set, or false if not found / invalid email.
     */
    public static function share_set(int $set_id, int $user_id, string $email): object|false
    {
        $email = sanitize_email($email);
        if ($email === '' || !is_email($email)) {
            return false;
        }

        $set = self::get_set_by_id($set_id, $user_id);
        if (!$set) {
            return false;
        }

        global $wpdb;
        $table = PCM_Schema::table('approval_sets');

        $update = array('clientEmail' => $email, 'updatedAt' => current_time('mysql'));
        // Sharing sends the set out for client review → move it into the
        // "Awaiting Client Approval" lane, unless it has already advanced past it.
        if (in_array($set->status, array('draft', 'internal'), true)) {
            $update['status'] = 'client';
            $set->status = 'client';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            $update,
            array('id' => $set_id, 'userId' => $user_id)
        );
        $set->clientEmail = $email;

        // Remember the recipient on the brand so future shares auto-fill.
        if (!empty($set->brandId)) {
            PCM_DB::update_brand((int) $set->brandId, $user_id, array('clientEmail' => $email));
        }

        PCM_Automation_Engine::dispatch(
            PCM_Automation_Events::APPROVAL_SET_SHARED,
            self::build_event_context($set),
            $user_id
        );

        return self::get_set_by_id($set_id, $user_id);
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
                        $item['content'] = wp_kses_post($updates['content']);
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
