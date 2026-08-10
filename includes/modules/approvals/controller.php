<?php
/**
 * Approvals REST Controller
 *
 * Handles creator management, token-secured public sharing, and client reviews.
 *
 * Endpoints:
 *   GET    /approvals/sets                           List creator sets
 *   POST   /approvals/sets                           Create an approval set
 *   GET    /approvals/sets/(?P<token>[a-zA-Z0-9_-]+)  Get public set by token (unauthenticated)
 *   POST   /approvals/sets/(?P<token>[a-zA-Z0-9_-]+)/review  Submit client feedback (unauthenticated)
 *   POST   /approvals/sets/(?P<token>[a-zA-Z0-9_-]+)/assets/(?P<asset_id>[a-zA-Z0-9_-]+)  Update snapshot asset (authenticated)
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Approvals extends PCM_REST_Base
{
    /**
     * Override base register() to support public unauthenticated routes.
     */
    public function register(): void
    {
        foreach ($this->routes() as $route_def) {
            $method     = $route_def[0];
            $path       = $route_def[1];
            $callback   = $route_def[2];
            $args       = $route_def[3] ?? array();
            $permission = $route_def[4] ?? 'manage_options';

            if ($permission === 'public') {
                register_rest_route(
                    $this->namespace,
                    $path,
                    array(
                        'methods'             => $method,
                        'callback'            => array($this, $callback),
                        'permission_callback' => '__return_true', // Public bypass
                        'args'                => $args,
                    )
                );
            } else {
                register_rest_route(
                    $this->namespace,
                    $path,
                    array(
                        'methods'             => $method,
                        'callback'            => array($this, $callback),
                        'permission_callback' => $this->make_permission_callback($permission),
                        'args'                => $args,
                    )
                );
            }
        }
    }

    protected function routes(): array
    {
        return array(
            array('GET',    '/approvals/sets',                          'list_sets',           array(), 'read'),
            array('POST',   '/approvals/sets',                          'create_set',          array(), 'edit_posts'),
            array('PATCH',  '/approvals/sets/(?P<id>\d+)/status',       'update_set_status',   array(), 'edit_posts'),
            array('DELETE', '/approvals/sets/(?P<id>\d+)',              'delete_set',          array(), 'edit_posts'),
            array('POST',   '/approvals/sets/bulk/delete',              'bulk_delete_sets',    array(), 'edit_posts'),
            array('GET',   '/approvals/sets/(?P<token>[a-zA-Z0-9_-]+)', 'get_public_set',      array(), 'public'),
            array('POST',  '/approvals/sets/(?P<token>[a-zA-Z0-9_-]+)/review', 'submit_public_review', array(), 'public'),
            array('POST',  '/approvals/sets/(?P<token>[a-zA-Z0-9_-]+)/draft', 'save_public_draft', array(), 'public'),
            // Public client actions (token-scoped, rate-limited inside the handler).
            array('POST',  '/approvals/sets/(?P<token>[a-zA-Z0-9_-]+)/comment', 'add_public_comment', array(), 'public'),
            array('POST',  '/approvals/sets/(?P<token>[a-zA-Z0-9_-]+)/approve', 'approve_public', array(), 'public'),
            // Authenticated team actions (ownership-scoped).
            array('POST',  '/approvals/sets/(?P<id>\d+)/reply', 'add_team_reply', array(), 'edit_posts'),
            array('POST',  '/approvals/sets/(?P<id>\d+)/share', 'share_set', array(), 'edit_posts'),
            // Append assets to an in-review set (id is numeric, so it can't
            // collide with the token-based public asset route below).
            array('POST',  '/approvals/sets/(?P<id>\d+)/assets', 'append_assets', array(), 'edit_posts'),
            // Remove ONE item from a card. Numeric id, so it cannot collide with
            // the token-based public asset route below — the same distinction the
            // append route above relies on.
            array('DELETE', '/approvals/sets/(?P<id>\d+)/assets/(?P<asset_id>[A-Za-z0-9_-]+)', 'remove_asset', array(), 'edit_posts'),
            array('POST',  '/approvals/sets/(?P<token>[a-zA-Z0-9_-]+)/assets/(?P<asset_id>[a-zA-Z0-9_-]+)', 'update_snapshot_asset', array(), 'public'),
        );
    }

    /**
     * List all approval sets owned by the logged-in user.
     */
    public function list_sets(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        require_once __DIR__ . '/service.php';

        try {
            $sets = PCM_Approvals_Service::list_sets_by_user((int)$pcm_user->id);
            return $this->success($sets);
        } catch (\Throwable $e) {
            return $this->error('Failed to list approval sets: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new approval set from selected working assets.
     */
    public function create_set(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $params   = $request->get_json_params();

        if (empty($params['name'])) {
            return $this->error('Approval set name is required.');
        }
        if (empty($params['snapshot'])) {
            return $this->error('Snapshot data cannot be empty.');
        }

        require_once __DIR__ . '/service.php';

        // Optional delivery linkage — must be a delivery the caller owns or is
        // assigned (get_delivery_by_id is owned-OR-granted).
        $delivery_id = !empty($params['deliveryId']) ? absint($params['deliveryId']) : null;
        if ($delivery_id !== null && !PCM_DB::get_delivery_by_id($delivery_id, (int) $pcm_user->id)) {
            return $this->not_found('Delivery');
        }

        // Optional target lane (the board's lane "+" creates straight into it).
        // Absent = 'draft', exactly as before. An unrecognised value is a named
        // error, never a silent demotion to draft.
        $status = isset($params['status']) ? sanitize_text_field((string) $params['status']) : '';
        if ($status !== '' && !in_array($status, PCM_Approvals_Service::STATUSES, true)) {
            return $this->error('Invalid status: ' . $status);
        }

        try {
            $set_id = PCM_Approvals_Service::create_set((int)$pcm_user->id, array(
                'name'       => sanitize_text_field($params['name']),
                'status'     => $status !== '' ? $status : null,
                'brandId'    => !empty($params['brandId']) ? (int)$params['brandId'] : null,
                'projectId'  => !empty($params['projectId']) ? (int)$params['projectId'] : null,
                'deliveryId' => $delivery_id,
                // Decode ASCII-escaped emoji (escapeAstralDeep on the client) so they survive a
                // WAF that strips 4-byte UTF-8 from the request body. Sanitized in the service layer.
                'snapshot'   => self::decode_snapshot_emojis($params['snapshot']),
            ));

            if (!$set_id) {
                return $this->error('Failed to save approval set.', 500);
            }

            // If the caller supplied a client email, share immediately — this both
            // moves the set to the client lane AND emails the invite (built-in
            // dispatch → client_invite template). Sending server-side here means
            // "create + notify the client" is one reliable request, not dependent on
            // a follow-up share call from the browser.
            $client_email = isset($params['clientEmail']) ? sanitize_email((string) $params['clientEmail']) : '';
            if ($client_email !== '' && is_email($client_email)) {
                $client_message = isset($params['clientMessage']) ? sanitize_textarea_field((string) $params['clientMessage']) : '';
                PCM_Approvals_Service::share_set((int) $set_id, (int) $pcm_user->id, $client_email, $client_message);
            }

            $set = PCM_Approvals_Service::get_set_by_id((int)$set_id, (int)$pcm_user->id);
            return $this->success($set, 201);
        } catch (\Throwable $e) {
            return $this->error('Failed to create approval set: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Append assets to an existing approval set (cross-module workflow).
     *
     * POST /approvals/sets/{id}/assets  body: { snapshot: {media?, copy?, articles?} }
     * Allowed only while the set is still in review — not in a post-submit
     * lane and not fully approved (409 pcm_set_locked otherwise).
     */
    public function append_assets(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $id       = absint($request->get_param('id'));
        $params   = $request->get_json_params() ?: array();

        $snapshot = isset($params['snapshot']) && is_array($params['snapshot']) ? $params['snapshot'] : array();
        if (empty($snapshot['media']) && empty($snapshot['copy']) && empty($snapshot['articles']) && empty($snapshot['custom'])) {
            return $this->error('Nothing to append — snapshot data cannot be empty.');
        }
        // Decode ASCII-escaped emoji (escapeAstralDeep on the client) so they survive a WAF
        // that strips 4-byte UTF-8 from the request body, before the snapshot is frozen.
        $snapshot = self::decode_snapshot_emojis($snapshot);

        require_once __DIR__ . '/service.php';

        try {
            $result = PCM_Approvals_Service::append_to_set($id, (int) $pcm_user->id, $snapshot);
            if ($result === 'not_found') {
                return $this->not_found('Approval set');
            }
            if ($result === 'locked') {
                return $this->error(
                    __('This set is fully approved — create a new set instead.', 'power-creatives'),
                    409,
                    'pcm_set_locked'
                );
            }
            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error('Failed to append to approval set: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove one item from an approval card.
     *
     * DELETE /approvals/sets/{id}/assets/{assetId}
     *
     * Ownership-scoped and refused once the card is past client review or fully
     * approved — the same law `append_assets` obeys, because adding and removing
     * an item are the same operation in opposite directions. A card the client
     * has signed off must not lose an item underneath them.
     */
    public function remove_asset(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $id       = absint($request->get_param('id'));
        $asset_id = sanitize_text_field((string) $request->get_param('asset_id'));

        if ($id <= 0 || $asset_id === '') {
            return $this->error('A set id and an asset id are required.');
        }

        require_once __DIR__ . '/service.php';

        try {
            $result = PCM_Approvals_Service::remove_asset($id, (int) $pcm_user->id, $asset_id);
            if ($result === 'not_found') {
                return $this->not_found('Approval set');
            }
            if ($result === 'locked') {
                return $this->error(
                    __('This set is past client review — items can no longer be removed.', 'power-creatives'),
                    409,
                    'pcm_set_locked'
                );
            }
            if ($result === 'missing') {
                return $this->not_found('Asset');
            }
            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error('Failed to remove the asset: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update an approval set's status (internal team operation).
     *
     * PATCH /approvals/sets/{id}/status  body: { status: <one of STATUSES> }
     */
    public function update_set_status(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $id       = (int)$request->get_param('id');
        $params   = $request->get_json_params();
        $next     = isset($params['status']) ? sanitize_text_field($params['status']) : '';

        if ($id <= 0 || $next === '') {
            return $this->error('Set id and status are required.');
        }

        require_once __DIR__ . '/service.php';

        if (!in_array($next, PCM_Approvals_Service::STATUSES, true)) {
            return $this->error('Invalid status: ' . $next);
        }

        try {
            $ok = PCM_Approvals_Service::update_status($id, (int)$pcm_user->id, $next);
            if (!$ok) {
                return $this->error('Set not found or not owned by this user.', 404);
            }
            $set = PCM_Approvals_Service::get_set_by_id($id, (int)$pcm_user->id);
            return $this->success($set);
        } catch (\Throwable $e) {
            return $this->error('Failed to update set status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a single approval set.
     *
     * DELETE /approvals/sets/{id}
     */
    public function delete_set(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $id       = (int)$request->get_param('id');

        if ($id <= 0) {
            return $this->error('Set id is required.');
        }

        require_once __DIR__ . '/service.php';

        try {
            $ok = PCM_Approvals_Service::delete_set($id, (int)$pcm_user->id);
            if (!$ok) {
                return $this->error('Set not found or not owned by this user.', 404);
            }
            return $this->success(array('id' => $id, 'deleted' => true));
        } catch (\Throwable $e) {
            return $this->error('Failed to delete set: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Bulk-delete approval sets owned by the caller.
     *
     * POST /approvals/sets/bulk/delete  body: { ids: number[] }
     */
    public function bulk_delete_sets(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $params   = $request->get_json_params();
        $ids      = isset($params['ids']) && is_array($params['ids']) ? $params['ids'] : array();

        if (empty($ids)) {
            return $this->error('ids array is required.');
        }

        require_once __DIR__ . '/service.php';

        try {
            $deleted = PCM_Approvals_Service::bulk_delete_sets($ids, (int)$pcm_user->id);
            return $this->success(array('deleted' => $deleted));
        } catch (\Throwable $e) {
            return $this->error('Failed to bulk-delete sets: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Retrieve a public approval set by token (unauthenticated).
     */
    public function get_public_set(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $token = sanitize_key($request->get_param('token'));
        require_once __DIR__ . '/service.php';

        try {
            $set = PCM_Approvals_Service::get_set_by_token($token);
            if (!$set) {
                return $this->not_found('Approval Set');
            }
            return $this->success($set);
        } catch (\Throwable $e) {
            return $this->error('Failed to retrieve approval set: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Submit client feedback (unauthenticated) and dispatch webhook.
     */
    public function submit_public_review(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $token  = sanitize_key($request->get_param('token'));
        $params = $request->get_json_params();

        if (!isset($params['feedback'])) {
            return $this->error('Feedback data is required.');
        }

        require_once __DIR__ . '/service.php';

        try {
            $success = PCM_Approvals_Service::submit_review(
                $token,
                sanitize_text_field($params['clientName'] ?? 'External Client'),
                $params['feedback']
            );

            if (!$success) {
                return $this->not_found('Approval Set');
            }

            return $this->success(array('success' => true));
        } catch (\Throwable $e) {
            return $this->error('Failed to submit client review: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Save client feedback draft in real-time (unauthenticated).
     */
    public function save_public_draft(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $token  = sanitize_key($request->get_param('token'));
        $params = $request->get_json_params();

        if (!isset($params['feedback'])) {
            return $this->error('Feedback data is required.');
        }

        require_once __DIR__ . '/service.php';

        try {
            $success = PCM_Approvals_Service::save_review_draft(
                $token,
                $params['feedback']
            );

            if (!$success) {
                return $this->not_found('Approval Set');
            }

            return $this->success(array('success' => true));
        } catch (\Throwable $e) {
            return $this->error('Failed to save client review draft: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update an individual asset inside the approval set snapshot (authenticated for team members).
     */
    public function update_snapshot_asset(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        // Require logged-in team member authorization
        if (!is_user_logged_in() || (!current_user_can('edit_posts') && !current_user_can('manage_options'))) {
            return $this->error('Unauthorized: only team members can edit assets.', 403);
        }

        $token    = sanitize_key($request->get_param('token'));
        $asset_id = sanitize_key($request->get_param('asset_id'));
        $params   = $request->get_json_params();

        // ── Emoji preservation safeguard ──
        // Some shared hosts run a security layer (mod_security / an input-sanitizing
        // plugin) that strips 4-byte UTF-8 (emojis) from the PARSED REST params while
        // leaving the RAW request body intact. For the editable text fields, prefer the
        // raw-body value when it carries emojis the parsed value lost — so emojis survive
        // an edit. (Storage itself is already charset-safe: the snapshot is written via
        // wp_json_encode, i.e. \uXXXX ASCII.)
        $raw_body = json_decode((string) $request->get_body(), true);
        if (is_array($raw_body)) {
            foreach (array('body', 'headline', 'description') as $field) {
                if (isset($raw_body[$field], $params[$field])
                    && self::emoji_count($raw_body[$field]) > self::emoji_count($params[$field])
                ) {
                    $params[$field] = $raw_body[$field];
                }
            }
        }

        // ── Decode ASCII-escaped emoji ──
        // The client escapes astral-plane characters (emoji, > U+FFFF) to ASCII
        // numeric HTML entities (&#128640;) BEFORE sending, so a request-stripping
        // security/WAF layer can't drop the 4-byte UTF-8 in transit (the raw-body
        // safeguard above can only help when the parsed params lost bytes the raw
        // body kept — it can't recover bytes that never reached PHP at all).
        // Decode them back to UTF-8 here so the value stored matches what the user
        // typed. Numeric references only — named entities / literal text untouched.
        foreach (array('body', 'headline', 'description') as $field) {
            if (isset($params[$field]) && is_string($params[$field])) {
                $params[$field] = self::decode_numeric_entities($params[$field]);
            }
        }

        // Opt-in probe: enable WP_DEBUG to log where an emoji is lost on this host
        // (raw → parsed → after-sanitize). raw=1 parsed=0 ⇒ a request-level layer
        // stripped it; raw=0 ⇒ the browser never sent it (stale bundle / cache).
        if (defined('WP_DEBUG') && WP_DEBUG && isset($params['body'])) {
            error_log(sprintf(
                '[PCM emoji probe] asset=%s raw=%d parsed=%d sanitized=%d',
                $asset_id,
                self::emoji_count(is_array($raw_body) ? ($raw_body['body'] ?? '') : ''),
                self::emoji_count($params['body']),
                self::emoji_count(sanitize_textarea_field((string) $params['body']))
            ));
        }

        require_once __DIR__ . '/service.php';

        try {
            $success = PCM_Approvals_Service::update_snapshot_asset($token, $asset_id, $params);
            if (!$success) {
                return $this->error('Failed to update asset or asset not found in snapshot.', 400);
            }
            return $this->success(array('success' => true));
        } catch (\Throwable $e) {
            return $this->error('Failed to update snapshot asset: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Count emoji / astral-plane symbols in a value — used by the emoji-preservation
     * safeguard in update_snapshot_asset(). Returns 0 for non-strings or when the
     * host's PCRE lacks UTF-8 support (the safeguard then simply no-ops).
     */
    private static function emoji_count($value): int
    {
        if (!is_string($value) || $value === '') {
            return 0;
        }
        $n = @preg_match_all(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{FE00}-\x{FE0F}\x{1F1E6}-\x{1F1FF}]/u',
            $value
        );
        return is_int($n) ? $n : 0;
    }

    /**
     * Decode ASCII numeric HTML character references (decimal &#128640; and hex
     * &#x1F680;) back to their UTF-8 characters. Pairs with the frontend's
     * astral-plane escaping (escapeAstral) so emojis survive a request-stripping
     * WAF/security layer. Only numeric references are touched — named entities
     * and literal text pass through unchanged. No-op when mbstring is missing
     * (the entity is left as-is rather than crashing).
     */
    private static function decode_numeric_entities(string $value): string
    {
        if (strpos($value, '&#') === false || !function_exists('mb_chr')) {
            return $value;
        }
        $decode = static function (int $cp, string $original): string {
            return ($cp > 0 && $cp <= 0x10FFFF) ? mb_chr($cp, 'UTF-8') : $original;
        };
        $value = preg_replace_callback('/&#(\d+);/', static function ($m) use ($decode) {
            return $decode((int) $m[1], $m[0]);
        }, $value);
        $value = preg_replace_callback('/&#x([0-9a-fA-F]+);/', static function ($m) use ($decode) {
            return $decode((int) hexdec($m[1]), $m[0]);
        }, $value);
        return $value;
    }

    /**
     * Recursively decode ASCII-escaped emoji in every string of an approval snapshot
     * (copy / custom / media item fields). Server-side pair to the client's
     * escapeAstralDeep() so emoji survive a WAF that strips 4-byte UTF-8 from the
     * create/append request body. No-op for strings without a numeric reference.
     *
     * @param mixed $value Snapshot value (array tree or scalar).
     * @return mixed
     */
    private static function decode_snapshot_emojis($value)
    {
        if (is_string($value)) {
            return self::decode_numeric_entities($value);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::decode_snapshot_emojis($v);
            }
        }
        return $value;
    }

    /**
     * Client adds a comment to an asset thread (public, token-scoped).
     *
     * POST /approvals/sets/{token}/comment  body: { assetId, body, parentId?, author? }
     */
    public function add_public_comment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($this->is_rate_limited('comment')) {
            return $this->error('Too many requests. Please slow down.', 429, 'pcm_rate_limited');
        }

        $token  = sanitize_key($request->get_param('token'));
        $params = $request->get_json_params() ?: array();

        $asset_id = sanitize_text_field($params['assetId'] ?? '');
        $body     = sanitize_textarea_field($params['body'] ?? '');

        if ($asset_id === '' || $body === '') {
            return $this->error('assetId and body are required.');
        }

        require_once __DIR__ . '/service.php';

        try {
            $comment = PCM_Approvals_Service::add_public_comment(
                $token,
                $asset_id,
                $body,
                isset($params['author']) ? sanitize_text_field($params['author']) : null,
                isset($params['parentId']) ? sanitize_text_field($params['parentId']) : null
            );

            if ($comment === false) {
                return $this->not_found('Approval Set');
            }
            return $this->success($comment, 201);
        } catch (\Throwable $e) {
            return $this->error('Failed to add comment: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Client approves/unapproves an asset or approves all (public, token-scoped).
     *
     * POST /approvals/sets/{token}/approve  body: { approveAll? } | { assetId, type?, approved? }
     */
    public function approve_public(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($this->is_rate_limited('approve')) {
            return $this->error('Too many requests. Please slow down.', 429, 'pcm_rate_limited');
        }

        $token  = sanitize_key($request->get_param('token'));
        $params = $request->get_json_params() ?: array();

        require_once __DIR__ . '/service.php';

        $args = array();
        if (!empty($params['approveAll'])) {
            $args['approveAll'] = true;
        } else {
            $args['assetId'] = sanitize_text_field($params['assetId'] ?? '');
            if ($args['assetId'] === '') {
                return $this->error('assetId (or approveAll) is required.');
            }
            if (array_key_exists('approved', $params)) {
                $args['approved'] = (bool) $params['approved'];
            }
        }

        try {
            $set = PCM_Approvals_Service::approve_assets($token, $args);
            if ($set === false) {
                return $this->not_found('Approval Set');
            }
            return $this->success($set);
        } catch (\Throwable $e) {
            return $this->error('Failed to update approval: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Team member replies in a comment thread (authenticated, ownership-scoped).
     *
     * POST /approvals/sets/{id}/reply  body: { assetId, body, parentId? }
     */
    public function add_team_reply(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $id       = (int) $request->get_param('id');
        $params   = $request->get_json_params() ?: array();

        $asset_id = sanitize_text_field($params['assetId'] ?? '');
        $body     = sanitize_textarea_field($params['body'] ?? '');

        if ($id <= 0 || $asset_id === '' || $body === '') {
            return $this->error('Set id, assetId and body are required.');
        }

        require_once __DIR__ . '/service.php';

        try {
            $comment = PCM_Approvals_Service::add_team_comment(
                $id,
                (int) $pcm_user->id,
                $asset_id,
                $body,
                $pcm_user->name ?: 'Team',
                isset($params['parentId']) ? sanitize_text_field($params['parentId']) : null
            );

            if ($comment === false) {
                return $this->not_found('Approval Set');
            }
            return $this->success($comment, 201);
        } catch (\Throwable $e) {
            return $this->error('Failed to add reply: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Share a set with a client by email (authenticated, ownership-scoped).
     *
     * POST /approvals/sets/{id}/share  body: { email, message? }
     */
    public function share_set(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $id       = (int) $request->get_param('id');
        $params   = $request->get_json_params() ?: array();
        // Optional custom invite message (editable in the share dialog). Multi-line
        // plain text — sanitized, then escaped + nl2br'd in the email template.
        $message  = isset($params['message']) ? sanitize_textarea_field((string) $params['message']) : '';

        // One or many recipients: `emails` is the list the share step sends;
        // `email` is the original single-recipient shape, still accepted.
        $raw = array();
        if (isset($params['emails']) && is_array($params['emails'])) {
            $raw = $params['emails'];
        } elseif (isset($params['email'])) {
            $raw = array($params['email']);
        }

        $emails = array();
        foreach ($raw as $candidate) {
            $clean = sanitize_email((string) $candidate);
            if ($clean !== '' && is_email($clean) && !in_array($clean, $emails, true)) {
                $emails[] = $clean;
            }
        }

        // Never a silent no-send: nothing valid in the list is a named error.
        if ($id <= 0 || empty($emails)) {
            return $this->error('A valid email is required.');
        }

        require_once __DIR__ . '/service.php';

        try {
            $set = PCM_Approvals_Service::share_set($id, (int) $pcm_user->id, $emails, $message);
            if ($set === false) {
                return $this->not_found('Approval Set');
            }
            return $this->success($set);
        } catch (\Throwable $e) {
            return $this->error('Failed to share set: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Lightweight per-IP rate limit for the public client endpoints, mirroring
     * the shortcode gate's transient pattern. Returns true when the caller has
     * exceeded the window and should be rejected.
     *
     * @param string $bucket Logical bucket name (e.g. 'comment', 'approve').
     * @return bool
     */
    private function is_rate_limited(string $bucket): bool
    {
        $max    = 40;   // requests
        $window = 300;  // seconds (5 min)

        $ip  = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $key = 'pcm_ap_rl_' . $bucket . '_' . md5($ip);

        $count = (int) get_transient($key);
        if ($count >= $max) {
            return true;
        }

        set_transient($key, $count + 1, $window);
        return false;
    }
}
