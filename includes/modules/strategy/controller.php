<?php
/**
 * Strategy REST Controller
 *
 * Full CRUD for content strategies + generation trigger.
 * Strategies group keywords with a brand and template to orchestrate
 * the content pipeline: Keywords → Strategy → Writer → Sites.
 *
 * Endpoints:
 *   GET    /strategies                      List all strategies
 *   GET    /strategies/schedule             Cross-strategy flat feed of scheduled/published items
 *   POST   /strategies                      Create strategy + items
 *   GET    /strategies/(?P<id>\d+)          Get strategy with items
 *   PATCH  /strategies/(?P<id>\d+)          Update strategy
 *   DELETE /strategies/(?P<id>\d+)          Delete strategy + items
 *   POST   /strategies/(?P<id>\d+)/generate Generate next pending item
 *   POST   /strategies/(?P<id>\d+)/duplicate Duplicate a strategy (fresh pending items, no auto-start)
 *   PATCH  /strategies/(?P<id>\d+)/items/(?P<itemId>\d+) Manually change one item's status / due date
 *   DELETE /strategies/(?P<id>\d+)/items/(?P<itemId>\d+) Delete one item (article stays in Writer)
 *   POST   /strategies/(?P<id>\d+)/items/(?P<itemId>\d+)/publish Publish one completed item to the target site
 *   POST   /strategies/(?P<id>\d+)/interlinks Run interlink injection now
 *   POST   /strategies/(?P<id>\d+)/sync-status Pull published items' status from WordPress
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Strategy extends PCM_REST_Base
{
    // Work module — usable by non-admin team members (assigned access).
    protected string $default_capability = 'edit_posts';
    // Per-delivery module grant ids (see PCM_REST_Base::$module_grant_keys).
    protected array $module_grant_keys = array('strategies');

    protected function routes(): array
    {
        return array(
            array('GET',    '/strategies',                      'list_strategies'),
            // Registered BEFORE the `(?P<id>\d+)` routes below. WP registers each
            // route as its own rewrite rule (not a single first-match dispatcher),
            // and `\d+` cannot match the literal 'schedule' anyway, so the id route
            // never swallows this one — order is belt-and-braces, not required.
            array('GET',    '/strategies/schedule',             'schedule_feed'),
            array('POST',   '/strategies',                      'create_strategy'),
            array('GET',    '/strategies/(?P<id>\d+)',           'get_strategy'),
            array('PATCH',  '/strategies/(?P<id>\d+)',           'update_strategy'),
            array('DELETE', '/strategies/(?P<id>\d+)',           'delete_strategy'),
            array('POST',   '/strategies/(?P<id>\d+)/generate',  'generate_item'),
            array('POST',   '/strategies/(?P<id>\d+)/duplicate', 'duplicate_strategy'),
            array('PATCH',  '/strategies/(?P<id>\d+)/items/(?P<itemId>\d+)', 'update_item_status'),
            array('DELETE', '/strategies/(?P<id>\d+)/items/(?P<itemId>\d+)', 'delete_item'),
            array('POST',   '/strategies/(?P<id>\d+)/items/(?P<itemId>\d+)/publish', 'publish_item'),
            array('POST',   '/strategies/(?P<id>\d+)/interlinks', 'run_interlinks'),
            array('POST',   '/strategies/(?P<id>\d+)/sync-status', 'sync_status'),
            array('POST',   '/strategies/(?P<id>\d+)/reapply-parent', 'reapply_parent'),
        );
    }

    /**
     * Re-apply the parent link across a strategy's already-generated articles
     * (the Parent Settings modal calls this right after saving new settings, so
     * "changing the parent after generation" actually updates existing content:
     * old parent-link paragraphs are stripped and the fresh one appended, with
     * the current parent's own article kept link-free).
     */
    public function reapply_parent(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategy_id = (int)$request->get_param('id');

        $strategy = PCM_DB::get_strategy($strategy_id, (int)$pcm_user->id);
        if (!$strategy) {
            return $this->not_found('Strategy');
        }

        $result = PCM_Strategy_Service::reapply_parent_links($strategy_id, (int)$pcm_user->id);
        return $this->success($result);
    }

    /**
     * List all strategies for the current user.
     * Returns strategies with item counts (not individual items).
     */
    public function list_strategies(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategies = PCM_DB::get_user_strategies((int)$pcm_user->id);

        // Attach each strategy's items so the Strategies page can render them
        // inline when a row is expanded. The list is the ONLY strategies fetch
        // that page makes — expanding a row never triggered a per-strategy
        // fetch — so without this an expanded row shows an empty body. Fetched
        // fresh here (outside get_user_strategies' transient cache) so item
        // state is always current right after a generate/retry.
        foreach ($strategies as $strategy) {
            $strategy->items = PCM_DB::get_strategy_items((int)$strategy->id);
        }

        return $this->success($strategies);
    }

    /**
     * Cross-strategy master Content Schedule feed.
     *
     * Flattens every scheduled-or-published item across the current user's
     * strategies into a single table-ready row set. Composed from the same
     * ownership-scoped PCM_DB reads used by list_strategies() (no new SQL):
     * get_user_strategies() (userId-scoped) → get_strategy_items() per strategy.
     * Only items that carry a due date OR a published URL are surfaced — the
     * rest aren't "on the schedule" yet. Sorted by scheduledDate ascending,
     * null dates last (published-but-unscheduled items sink to the bottom).
     */
    public function schedule_feed(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $user_id  = (int)$pcm_user->id;

        $rows = array();
        foreach (PCM_DB::get_user_strategies($user_id) as $strategy) {
            foreach (PCM_DB::get_strategy_items((int)$strategy->id) as $item) {
                $scheduled = !empty($item->scheduledDate) ? (string)$item->scheduledDate : null;
                $published = !empty($item->articlePublishedUrl) ? (string)$item->articlePublishedUrl : null;
                if ($scheduled === null && $published === null) {
                    continue; // not on the schedule and not yet live — skip
                }

                $rows[] = array(
                    'itemId'              => (int)$item->id,
                    'strategyId'          => (int)$strategy->id,
                    'strategyName'        => (string)$strategy->name,
                    'keyword'             => (string)$item->keyword,
                    'title'               => isset($item->title) ? (string)$item->title : '',
                    'status'              => (string)$item->status,
                    'scheduledDate'       => $scheduled,
                    'articlePublishedUrl' => $published,
                    'articleId'           => !empty($item->articleId) ? (int)$item->articleId : null,
                    'publishingMode'      => (string)$strategy->publishingMode,
                );
            }
        }

        // scheduledDate is a 'Y-m-d H:i:s' datetime — lexical compare is
        // chronological. Nulls (published, never scheduled) sort last.
        usort($rows, static function (array $a, array $b): int {
            $da = $a['scheduledDate'];
            $db = $b['scheduledDate'];
            if ($da === $db) {
                return 0;
            }
            if ($da === null) {
                return 1;
            }
            if ($db === null) {
                return -1;
            }
            return strcmp($da, $db);
        });

        return $this->success($rows);
    }

    /**
     * Create a strategy from a keyword selection.
     *
     * Expected body:
     * {
     *   name: string,
     *   templateId: number,
     *   brandId?: number,
     *   keywords: string[],
     *   hierarchyMode?: string,
     *   publishingMode?: string,
     *   config?: object
     * }
     */
    public function create_strategy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_json_params();
        $pcm_user = $this->get_current_pcm_user();
        $user_id = (int)$pcm_user->id;

        // ── Validate required fields ──
        if (empty($params['name'])) {
            return $this->error('Strategy name is required.');
        }
        if (empty($params['templateId'])) {
            return $this->error('Template ID is required.');
        }
        if (empty($params['keywords']) || !is_array($params['keywords'])) {
            return $this->error('Keywords array is required.');
        }

        try {
            // Persisted generation config. `model`/`provider` drive which LLM each
            // item is generated with (empty → the service falls back to a default);
            // the remaining fields are captured from the create dialog so they're no
            // longer silently dropped (structure/interlink/schedule/approval are
            // stored for the features that consume them — see docs/modules/strategy).
            // sanitize_config_fields() whitelists + coerces every nested config
            // object — never persist raw user input. This is the FIRST config ever
            // stored for the strategy, so missing fields get real defaults (unlike
            // update_strategy's partial-merge below, which must NOT default absent
            // keys — that would silently reset them on an unrelated field update).
            $config = array_merge(
                array(
                    'model' => '', 'provider' => '', 'structure' => 'individual',
                    'approvalMode' => 'none', 'parentTargetUrl' => '', 'parentKeyword' => '',
                    'interlinksConfig' => null, 'scheduleConfig' => null,
                    // Site to auto-publish each generated article to when publishingMode
                    // is 'publish'. Ownership is verified at GENERATE time (not here) —
                    // ownership-scoped PCM_DB::get_site() is the actual enforcement point,
                    // consistent with how brandId is handled in this same method.
                    'siteId' => 0,
                ),
                $this->sanitize_config_fields($params)
            );

            // Delegate to the service layer for orchestration
            require_once __DIR__ . '/service.php';
            $strategy = PCM_Strategy_Service::create_from_keywords(
                $user_id,
                sanitize_text_field($params['name']),
                (int)$params['templateId'],
                !empty($params['brandId']) ? (int)$params['brandId'] : null,
                array_map('sanitize_text_field', $params['keywords']),
                array(
                    'hierarchyMode'  => sanitize_text_field($params['hierarchyMode'] ?? 'standalone'),
                    'publishingMode' => sanitize_text_field($params['publishingMode'] ?? 'draft'),
                    'config'         => $config,
                    // Task F3: display-only per-keyword search volume + difficulty
                    // carried from the Keyword Explorer. `keywords` (above) is the
                    // unchanged backward-compat payload; keywordMeta is additive and
                    // sanitized here into a keyword-keyed map for the service/DB layer.
                    'keywordMeta'    => $this->sanitize_keyword_meta($params['keywordMeta'] ?? null),
                )
            );

            return $this->success($strategy, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Get a single strategy with its items.
     */
    public function get_strategy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategy_id = (int)$request->get_param('id');

        $strategy = PCM_DB::get_strategy($strategy_id, (int)$pcm_user->id);
        if (!$strategy) {
            return $this->not_found('Strategy');
        }

        // Attach items to response
        $strategy->items = PCM_DB::get_strategy_items($strategy_id);

        return $this->success($strategy);
    }

    /**
     * Update strategy metadata (name, status, config).
     *
     * `config` is a PARTIAL update, MERGED onto the existing stored config (via
     * PCM_Strategy_Service::merge_strategy_config()) — e.g. the Strategies page's
     * inline "Target Site" selector sends only `{siteId}`. A wholesale-replace
     * (the previous behavior) would have silently wiped every other config key
     * (approvalMode, scheduleConfig, interlinksConfig, ...) on any such partial
     * update. Requires an extra ownership-scoped read of the CURRENT strategy
     * before merging — only when `config` is actually present in the request.
     */
    public function update_strategy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategy_id = (int)$request->get_param('id');
        $params = $request->get_json_params();

        // Whitelist updateable fields. 'config' is handled separately below
        // (sanitized + merged, not wholesale-replaced). D2: 'paused'/'in_progress'
        // pass through this same 'status' slot unchanged — they're sanitize_text_
        // field()'d string values in the existing varchar column, so pause/resume
        // needs no new field here.
        $allowed = array('name', 'status', 'hierarchyMode', 'publishingMode');
        $update = array();
        foreach ($allowed as $field) {
            if (isset($params[$field])) {
                $update[$field] = sanitize_text_field($params[$field]);
            }
        }
        // The row's inline template select (AutoPress parity). Int-coerced, not
        // text-sanitized like the fields above.
        if (isset($params['templateId'])) {
            $update['templateId'] = absint($params['templateId']);
        }

        $existing_strategy = null;
        $schedule_changed = false;
        if (is_array($params['config'] ?? null)) {
            $existing_strategy = PCM_DB::get_strategy($strategy_id, (int)$pcm_user->id);
            if (!$existing_strategy) {
                return $this->not_found('Strategy');
            }
            require_once __DIR__ . '/service.php';
            $incoming = $this->sanitize_config_fields($params['config']);
            $merged = PCM_Strategy_Service::merge_strategy_config($existing_strategy->config ?? null, $incoming);
            $update['config'] = wp_json_encode($merged);
            $schedule_changed = array_key_exists('scheduleConfig', $incoming) && is_array($incoming['scheduleConfig']);
        }

        // Invariant: a consolidated strategy (single article for every keyword)
        // can never carry a hierarchy between its own articles — enforced here
        // regardless of which side of this PATCH set it (hierarchyMode,
        // config.structure, or both at once; either can flip the combination
        // into the contradictory state on its own). Only relevant when this
        // request actually touches one of those two fields; only writes back
        // when the guard actually changes something, so an unrelated field
        // update (e.g. just 'name') never gains a spurious hierarchyMode write.
        if (array_key_exists('hierarchyMode', $update) || array_key_exists('config', $update)) {
            if ($existing_strategy === null) {
                $existing_strategy = PCM_DB::get_strategy($strategy_id, (int)$pcm_user->id);
                if (!$existing_strategy) {
                    return $this->not_found('Strategy');
                }
            }
            require_once __DIR__ . '/service.php';
            $effective_config = array_key_exists('config', $update)
                ? (json_decode((string)$update['config'], true) ?: array())
                : ((array)(json_decode((string)($existing_strategy->config ?? ''), true) ?: array()));
            $effective_hierarchy = $update['hierarchyMode'] ?? (string)($existing_strategy->hierarchyMode ?? 'standalone');

            $guarded = PCM_Strategy_Service::apply_structure_hierarchy_guard($effective_hierarchy, $effective_config);
            if ($guarded['hierarchyMode'] !== $effective_hierarchy) {
                $update['hierarchyMode'] = $guarded['hierarchyMode'];
            }
            if (array_key_exists('config', $update) && $guarded['config'] !== $effective_config) {
                $update['config'] = wp_json_encode($guarded['config']);
            }
        }

        if (empty($update)) {
            return $this->error('No valid fields to update.');
        }

        $success = PCM_DB::update_strategy($strategy_id, (int)$pcm_user->id, $update);
        if (!$success) {
            return $this->not_found('Strategy');
        }

        // A changed scheduleConfig redistributes the PENDING items' due dates
        // (completed/errored items keep their history). Only meaningful while
        // the strategy is actually in schedule mode — the mode may be changing
        // in this same request, so prefer the incoming value.
        if ($schedule_changed) {
            $mode = $update['publishingMode'] ?? (string)($existing_strategy->publishingMode ?? '');
            if ($mode === 'schedule') {
                $sc = json_decode((string)$update['config'], true)['scheduleConfig'] ?? array();
                // Pass the FULL sanitized config, not just the frequency label —
                // the custom-recurrence keys (interval/unit/byDays/ends) must
                // reach the engine; a bare string would silently fall back to
                // the legacy fixed-frequency path.
                PCM_Strategy_Service::reschedule_pending_items(
                    $strategy_id,
                    is_array($sc) && $sc !== array() ? $sc : 'weekly',
                    !empty($sc['startDate']) ? (string)$sc['startDate'] : ''
                );
            }
        }

        // Return updated strategy
        $strategy = PCM_DB::get_strategy($strategy_id, (int)$pcm_user->id);
        $strategy->items = PCM_DB::get_strategy_items($strategy_id);
        return $this->success($strategy);
    }

    /**
     * Sanitize+coerce the strategy config fields present in $fields, returning
     * ONLY the keys actually supplied (shared by create_strategy, which merges
     * the result over full defaults, and update_strategy, which merges it over
     * the strategy's EXISTING stored config via
     * PCM_Strategy_Service::merge_strategy_config() — using array_key_exists()
     * rather than a truthiness check so an update can explicitly clear a key by
     * sending it as null/empty, distinct from simply not mentioning it at all).
     *
     * @param array $fields Raw input — either the create request's top-level
     *   params, or update_strategy's nested `config` object; same key names either way.
     * @return array Sanitized subset containing only the keys present in $fields.
     */
    private function sanitize_config_fields(array $fields): array
    {
        $config = array();

        if (array_key_exists('model', $fields)) {
            $config['model'] = sanitize_text_field($fields['model'] ?? '');
        }
        if (array_key_exists('provider', $fields)) {
            $config['provider'] = sanitize_text_field($fields['provider'] ?? '');
        }
        if (array_key_exists('structure', $fields)) {
            $config['structure'] = sanitize_text_field($fields['structure'] ?? 'individual');
        }
        if (array_key_exists('approvalMode', $fields)) {
            $config['approvalMode'] = sanitize_text_field($fields['approvalMode'] ?? 'none');
        }
        if (array_key_exists('parentTargetUrl', $fields)) {
            $config['parentTargetUrl'] = !empty($fields['parentTargetUrl']) ? esc_url_raw($fields['parentTargetUrl']) : '';
        }
        if (array_key_exists('parentKeyword', $fields)) {
            $config['parentKeyword'] = sanitize_text_field($fields['parentKeyword'] ?? '');
        }
        // G1: explicit anchor text for the parent link (empty → fall back to the
        // parent's topic), plus whether the anchor may be varied (deterministic
        // injection uses the exact text either way — see inject_parent_link()).
        if (array_key_exists('parentAnchorKeyword', $fields)) {
            $config['parentAnchorKeyword'] = sanitize_text_field($fields['parentAnchorKeyword'] ?? '');
        }
        if (array_key_exists('allowAnchorVariations', $fields)) {
            $config['allowAnchorVariations'] = (bool)$fields['allowAnchorVariations'];
        }
        if (array_key_exists('interlinksConfig', $fields)) {
            if (is_array($fields['interlinksConfig'] ?? null)) {
                $interlinks = array(
                    'mode'     => sanitize_text_field($fields['interlinksConfig']['mode'] ?? ''),
                    'quantity' => absint($fields['interlinksConfig']['quantity'] ?? 0),
                );
                // anchorMode governs the anchor fallback (keyword|synonym|ai);
                // persist only a whitelisted value, drop anything else.
                if (in_array($fields['interlinksConfig']['anchorMode'] ?? '', array('keyword', 'synonym', 'ai'), true)) {
                    $interlinks['anchorMode'] = $fields['interlinksConfig']['anchorMode'];
                }
                $config['interlinksConfig'] = $interlinks;
            } else {
                $config['interlinksConfig'] = null;
            }
        }
        if (array_key_exists('scheduleConfig', $fields)) {
            if (is_array($fields['scheduleConfig'] ?? null)) {
                $schedule_config = $fields['scheduleConfig'];
                $schedule = array(
                    'frequency' => sanitize_text_field($schedule_config['frequency'] ?? ''),
                    'startDate' => sanitize_text_field($schedule_config['startDate'] ?? ''),
                );
                // Custom recurrence (optional): the strategy service consumes
                // these to build a non-standard cadence on top of frequency.
                // Unknown/invalid values are dropped rather than defaulted, so
                // the service can distinguish "not set" from an explicit value.
                if (isset($schedule_config['interval'])) {
                    $schedule['interval'] = min(12, max(1, absint($schedule_config['interval'])));
                }
                if (in_array($schedule_config['unit'] ?? null, array('day', 'week', 'month'), true)) {
                    $schedule['unit'] = $schedule_config['unit'];
                }
                if (is_array($schedule_config['byDays'] ?? null)) {
                    $by_days = array_values(array_unique(array_filter(
                        array_map('absint', $schedule_config['byDays']),
                        fn($day) => $day >= 1 && $day <= 7
                    )));
                    sort($by_days);
                    if (!empty($by_days)) {
                        $schedule['byDays'] = $by_days;
                    }
                }
                if (is_array($schedule_config['ends'] ?? null)) {
                    $ends_type = $schedule_config['ends']['type'] ?? '';
                    if (in_array($ends_type, array('never', 'on', 'after'), true)) {
                        $ends = array('type' => $ends_type);
                        if ($ends_type === 'on') {
                            $ends_date = sanitize_text_field($schedule_config['ends']['date'] ?? '');
                            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends_date)) {
                                $ends['date'] = $ends_date;
                                $schedule['ends'] = $ends;
                            }
                        } elseif ($ends_type === 'after') {
                            $ends_count = min(365, absint($schedule_config['ends']['count'] ?? 0));
                            if ($ends_count > 0) {
                                $ends['count'] = $ends_count;
                                $schedule['ends'] = $ends;
                            }
                        } else {
                            $schedule['ends'] = $ends;
                        }
                    }
                }
                $config['scheduleConfig'] = $schedule;
            } else {
                $config['scheduleConfig'] = null;
            }
        }
        if (array_key_exists('siteId', $fields)) {
            $config['siteId'] = !empty($fields['siteId']) ? absint($fields['siteId']) : 0;
        }
        if (array_key_exists('featuredImages', $fields)) {
            $config['featuredImages'] = (bool)$fields['featuredImages'];
        }
        // A6: in-content images & charts. Default ON is enforced at READ time in
        // the service (a missing key means enabled) — here we only persist the
        // explicit boolean when the client sends it, mirroring featuredImages.
        if (array_key_exists('inContentMedia', $fields)) {
            $config['inContentMedia'] = (bool)$fields['inContentMedia'];
        }
        // A6: max in-content assets per article (1–8, default 3 enforced in the
        // service at READ time). Only persist when the client sends it; clamp.
        if (array_key_exists('mediaCount', $fields)) {
            $config['mediaCount'] = min(8, max(1, absint($fields['mediaCount'])));
        }
        // A6: which in-content asset type(s) are allowed. Whitelist only — an
        // unrecognized value is dropped (never persisted), so the service default
        // ('both') stands rather than storing junk.
        if (array_key_exists('mediaType', $fields)
            && in_array($fields['mediaType'], array('images', 'charts', 'both'), true)
        ) {
            $config['mediaType'] = $fields['mediaType'];
        }
        if (array_key_exists('mediaGuidance', $fields)) {
            // Free-text creative direction for in-content media (what the
            // images should depict / what data the charts should show) —
            // plain text, capped at 500 chars.
            $config['mediaGuidance'] = mb_substr(sanitize_text_field((string)$fields['mediaGuidance']), 0, 500);
        }
        if (array_key_exists('research', $fields)) {
            $config['research'] = (bool)$fields['research'];
        }
        // Selectable research modes ('off'|'grounded'|'deep'), layered on top of
        // the legacy `research` boolean above (kept untouched for back-compat —
        // see PCM_Strategy_Service::research_mode()). Whitelist only; an
        // unrecognized value is dropped rather than persisted, mirroring mediaType.
        if (array_key_exists('researchMode', $fields)
            && in_array($fields['researchMode'], array('off', 'grounded', 'deep'), true)
        ) {
            $config['researchMode'] = $fields['researchMode'];
        }
        if (array_key_exists('imageProvider', $fields)) {
            $config['imageProvider'] = sanitize_text_field($fields['imageProvider'] ?? '');
        }
        if (array_key_exists('imageModel', $fields)) {
            $config['imageModel'] = sanitize_text_field($fields['imageModel'] ?? '');
        }

        return $config;
    }

    /**
     * Sanitize the optional keywordMeta payload (Task F3). The Keyword Explorer
     * sends display-only search-volume + difficulty per selected keyword as a
     * list of {keyword, volume?, difficulty?} objects. Returns a map keyed by the
     * sanitized keyword string → array{volume?:int,difficulty?:int} — the exact
     * shape PCM_DB::create_strategy_items()'s $meta param expects (keyed by
     * keyword string). absint() coerces both ints; sanitize_text_field() the
     * keyword; malformed entries (non-array, or a missing/blank keyword) are
     * DROPPED, never persisted. Both ints are optional — an entry may carry only
     * one — so an entry with neither present is dropped too (nothing to store).
     *
     * @param mixed $raw Raw keywordMeta from the request body (expected: array of objects).
     * @return array<string,array{volume?:int,difficulty?:int}> Keyword-keyed metric map.
     */
    private function sanitize_keyword_meta($raw): array
    {
        if (!is_array($raw)) {
            return array();
        }
        $meta = array();
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue; // drop malformed
            }
            $keyword = sanitize_text_field($entry['keyword'] ?? '');
            if ($keyword === '') {
                continue; // a metric with no keyword can't bind to any item
            }
            $clean = array();
            if (isset($entry['volume']) && $entry['volume'] !== '' && $entry['volume'] !== null) {
                $clean['volume'] = absint($entry['volume']);
            }
            if (isset($entry['difficulty']) && $entry['difficulty'] !== '' && $entry['difficulty'] !== null) {
                $clean['difficulty'] = absint($entry['difficulty']);
            }
            if (!empty($clean)) {
                $meta[$keyword] = $clean;
            }
        }
        return $meta;
    }

    /**
     * Sanitize+whitelist a strategy ITEM's per-item override config (Task F1/F2).
     * Only 3 keys exist for items (unlike the strategy-level config's much larger
     * surface in sanitize_config_fields() above): templateId (absint'd, dropped
     * if empty/zero — 0 is not a valid template id), publishingMode and
     * approvalMode (both dropped unless they match their exact whitelist — an
     * unrecognized value is silently dropped, never persisted, per the plan's
     * "drop unknowns" instruction). approvalMode's whitelist includes 'both'
     * (internal + client must BOTH approve — see create_approval_set_for_item()
     * in service.php for how that starts in the internal lane).
     *
     * @param array $fields Raw `config` object from the request body.
     * @return array Sanitized subset containing only recognized, valid keys.
     */
    private function sanitize_item_config_fields(array $fields): array
    {
        $config = array();

        if (!empty($fields['templateId'])) {
            $config['templateId'] = absint($fields['templateId']);
        }
        if (in_array($fields['publishingMode'] ?? null, array('draft', 'publish', 'schedule'), true)) {
            $config['publishingMode'] = sanitize_text_field($fields['publishingMode']);
        }
        if (in_array($fields['approvalMode'] ?? null, array('none', 'internal', 'client', 'both'), true)) {
            $config['approvalMode'] = sanitize_text_field($fields['approvalMode']);
        }

        return $config;
    }

    /**
     * Delete a strategy and all its items.
     */
    public function delete_strategy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategy_id = (int)$request->get_param('id');

        $success = PCM_DB::delete_strategy($strategy_id, (int)$pcm_user->id);
        if (!$success) {
            return $this->not_found('Strategy');
        }

        return $this->success(array('deleted' => true));
    }

    /**
     * Generate the next pending item in a strategy.
     * Uses the strategy's template + brand context to invoke PCM_LLM.
     * Creates an article in the articles table on success.
     */
    public function generate_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategy_id = (int)$request->get_param('id');

        // Verify strategy ownership
        $strategy = PCM_DB::get_strategy($strategy_id, (int)$pcm_user->id);
        if (!$strategy) {
            return $this->not_found('Strategy');
        }

        // Optional: (re)generate a SPECIFIC item (retry a failed one / regenerate).
        // Omitted → generate the next pending item.
        $params  = $request->get_json_params();
        $item_id = (is_array($params) && !empty($params['itemId'])) ? (int)$params['itemId'] : null;

        try {
            require_once __DIR__ . '/service.php';
            $result = PCM_Strategy_Service::generate_next_item($strategy, (int)$pcm_user->id, $item_id);

            if (!$result) {
                return $this->success(array(
                    'complete' => true,
                    'message'  => 'All items have been generated.',
                ));
            }

            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error('Generation failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Duplicate a strategy (Task E1) — ownership-scoped: the copy gets a fresh
     * set of pending items and does NOT auto-start generation (the user reviews,
     * then generates manually). Mirrors generate_item()'s ownership pattern:
     * get_strategy() first, then delegate to the service.
     */
    public function duplicate_strategy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategy_id = (int)$request->get_param('id');

        $strategy = PCM_DB::get_strategy($strategy_id, (int)$pcm_user->id);
        if (!$strategy) {
            return $this->not_found('Strategy');
        }

        try {
            require_once __DIR__ . '/service.php';
            $copy = PCM_Strategy_Service::duplicate_strategy($strategy, (int)$pcm_user->id);
            return $this->success($copy, 201);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Manually change one item — either reset its status back to `pending`
     * (regenerate fresh via a later Generate/Generate All, as opposed to Retry
     * which regenerates in place), set a pending item's scheduled due date
     * (the item-row inline date picker), or REPLACE its per-item override
     * config (Task F1/F2 — templateId/publishingMode/approvalMode overrides;
     * see PCM_Strategy_Service::set_item_config()'s docblock for why REPLACE,
     * not merge).
     *
     * Expected body: { status: 'pending' } OR { scheduledDate: 'YYYY-MM-DD' }
     *   OR { config: { templateId?, publishingMode?, approvalMode? } }
     */
    public function update_item_status(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategy_id = (int)$request->get_param('id');
        $item_id = (int)$request->get_param('itemId');

        // Verify strategy ownership — the service call below trusts this and is
        // NOT itself ownership-scoped (mirrors generate_item()'s itemId targeting).
        $strategy = PCM_DB::get_strategy($strategy_id, (int)$pcm_user->id);
        if (!$strategy) {
            return $this->not_found('Strategy');
        }

        $params = is_array($request->get_json_params()) ? $request->get_json_params() : array();

        try {
            require_once __DIR__ . '/service.php';

            if (is_array($params['config'] ?? null)) {
                $config = $this->sanitize_item_config_fields($params['config']);
                $items = PCM_Strategy_Service::set_item_config($strategy_id, $item_id, (int)$pcm_user->id, $config);
                return $this->success(array('items' => $items));
            }

            if (!empty($params['scheduledDate'])) {
                $date = PCM_Strategy_Service::set_item_scheduled_date(
                    $strategy_id,
                    $item_id,
                    (int)$pcm_user->id,
                    sanitize_text_field($params['scheduledDate'])
                );
                return $this->success(array('scheduledDate' => $date));
            }

            $new_status = sanitize_text_field($params['status'] ?? '');
            if ($new_status !== 'pending') {
                return $this->error("Only resetting to 'pending' (or setting scheduledDate) is supported right now.", 400);
            }

            $items = PCM_Strategy_Service::reset_item_to_pending(
                $strategy_id,
                $item_id,
                (int)$pcm_user->id,
                (int)$strategy->totalItems
            );
            return $this->success(array('items' => $items));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    /**
     * Delete one item from a strategy (the generated article, if any, stays in
     * Writer). Refused while the item is generating.
     */
    public function delete_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategy_id = (int)$request->get_param('id');
        $item_id = (int)$request->get_param('itemId');

        $strategy = PCM_DB::get_strategy($strategy_id, (int)$pcm_user->id);
        if (!$strategy) {
            return $this->not_found('Strategy');
        }

        try {
            require_once __DIR__ . '/service.php';
            $items = PCM_Strategy_Service::delete_item(
                $strategy_id,
                $item_id,
                (int)$pcm_user->id,
                (int)$strategy->totalItems
            );
            return $this->success(array('items' => $items));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    /**
     * Publish one completed item's article to the strategy's Target Site —
     * the retro-publish path for draft-mode articles (or ones generated before
     * a site was set).
     */
    public function publish_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategy_id = (int)$request->get_param('id');
        $item_id = (int)$request->get_param('itemId');

        // Verify strategy ownership — item-in-strategy scoping happens inside
        // the service (mirrors update_item_status/generate_item above).
        $strategy = PCM_DB::get_strategy($strategy_id, (int)$pcm_user->id);
        if (!$strategy) {
            return $this->not_found('Strategy');
        }

        try {
            require_once __DIR__ . '/service.php';
            $result = PCM_Strategy_Service::publish_item($strategy, $item_id, (int)$pcm_user->id);
            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    /**
     * Run interlink injection for a strategy's completed articles on demand
     * (auto-runs on completion; this covers strategies completed before the
     * feature existed, or re-runs after new items complete).
     */
    public function run_interlinks(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategy_id = (int)$request->get_param('id');

        $strategy = PCM_DB::get_strategy($strategy_id, (int)$pcm_user->id);
        if (!$strategy) {
            return $this->not_found('Strategy');
        }

        $params  = is_array($request->get_json_params()) ? $request->get_json_params() : array();
        $options = array();
        if (isset($params['maxLinks'])) {
            $options['quantity'] = absint($params['maxLinks']);
        }
        if (isset($params['maxLinksPerArticle'])) {
            $options['maxLinksPerArticle'] = absint($params['maxLinksPerArticle']);
        }
        if (!empty($params['manualRules']) && is_array($params['manualRules'])) {
            $rules = array();
            foreach ($params['manualRules'] as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $keyword = sanitize_text_field($rule['keyword'] ?? '');
                $url     = esc_url_raw($rule['url'] ?? '');
                if ($keyword === '' || $url === '') {
                    continue; // drop rules missing keyword or url
                }
                $match_type  = in_array($rule['matchType'] ?? '', array('phrase', 'exact'), true) ? $rule['matchType'] : 'phrase';
                $rules[]     = array('keyword' => $keyword, 'url' => $url, 'matchType' => $match_type);
            }
            $options['manualRules'] = $rules;
        }
        if (isset($params['aiAnchors'])) {
            $options['aiAnchors'] = (bool)$params['aiAnchors'];
        }
        if (isset($params['anchorMode']) && in_array($params['anchorMode'], array('keyword', 'synonym', 'ai'), true)) {
            $options['anchorMode'] = $params['anchorMode'];
        }

        try {
            require_once __DIR__ . '/service.php';
            $result = PCM_Strategy_Service::run_interlinks($strategy_id, (int)$pcm_user->id, $options);
            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Pull each published item's current status from the remote WordPress site
     * (Task D3) — reconciles our stored record when a post was deleted or
     * unpublished directly on WordPress, outside this plugin.
     */
    public function sync_status(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        $strategy_id = (int)$request->get_param('id');

        $strategy = PCM_DB::get_strategy($strategy_id, (int)$pcm_user->id);
        if (!$strategy) {
            return $this->not_found('Strategy');
        }

        try {
            require_once __DIR__ . '/service.php';
            $result = PCM_Strategy_Service::sync_items_from_wp($strategy, (int)$pcm_user->id);
            return $this->success($result);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}
