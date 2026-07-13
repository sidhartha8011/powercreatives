<?php
/**
 * Strategy Service — Business Logic Layer
 *
 * Orchestrates the content generation pipeline:
 *   1. Creates strategies from keyword selections
 *   2. Generates articles by combining template + brand context + LLM
 *   3. Manages item status transitions
 *
 * This replaces the backup's `strategyService.ts` (916 lines) with ~200 lines
 * by leveraging existing PC infrastructure (PCM_LLM, PCM_DB, templates).
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Strategy_Service
{

    /**
     * Merge a PARTIAL config update onto a strategy's existing stored config.
     *
     * `PATCH /strategies/{id}` lets a caller change just one config key (e.g.
     * the inline "Target Site" selector on the Strategies page sending only
     * `{siteId}`) — a naive wholesale-replace of the whole `config` JSON would
     * silently wipe every other key (`approvalMode`, `scheduleConfig`,
     * `interlinksConfig`, ...). Incoming keys win; everything else in the
     * existing config is preserved untouched. Pure/deterministic — no DB
     * access, directly unit-testable.
     *
     * @param string|null $existing_json Existing strategy.config JSON (nullable; malformed JSON is treated as empty).
     * @param array       $incoming      Already-sanitized incoming config fields (only the keys to change).
     * @return array Merged config, ready to wp_json_encode() for storage.
     */
    public static function merge_strategy_config(?string $existing_json, array $incoming): array
    {
        $existing = array();
        if (!empty($existing_json)) {
            $decoded = json_decode($existing_json, true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }
        return array_merge($existing, $incoming);
    }

    /**
     * Create a strategy and its items from a keyword list.
     *
     * Equivalent to backup's `createNewStrategy()` (18 params) but simplified
     * to use PC's DB-backed persistence and template system.
     *
     * @param int         $user_id     PCM user ID.
     * @param string      $name        Strategy name.
     * @param int         $template_id Template ID from pcm_templates.
     * @param int|null    $brand_id    Optional brand ID for context injection.
     * @param array       $keywords    Array of keyword strings.
     * @param array       $options     Optional config (hierarchyMode, publishingMode, config,
     *                                 and keywordMeta — a display-only per-keyword
     *                                 volume/difficulty map keyed by keyword string, Task F3).
     *
     * @return array Created strategy with items array.
     * @throws \RuntimeException On validation or DB failure.
     */
    public static function create_from_keywords(
        int $user_id,
        string $name,
        int $template_id,
        ?int $brand_id,
        array $keywords,
        array $options = array()
    ): array {
        // ── Create the strategy record ──
        $strategy_data = array(
            'userId'         => $user_id,
            'name'           => $name,
            'templateId'     => $template_id,
            'brandId'        => $brand_id,
            'status'         => 'pending',
            'hierarchyMode'  => $options['hierarchyMode'] ?? 'standalone',
            'publishingMode' => $options['publishingMode'] ?? 'draft',
            'config'         => !empty($options['config']) ? wp_json_encode($options['config']) : null,
            'totalItems'     => count($keywords),
            'completedItems' => 0,
            'failedItems'    => 0,
        );

        $strategy_id = PCM_DB::create_strategy($strategy_data);
        if (!$strategy_id) {
            throw new \RuntimeException('Failed to create strategy in database.');
        }

        // ── Create items for each keyword ── (F3: pass the optional per-keyword
        // display-only volume/difficulty map straight through — already sanitized
        // + keyed by keyword string by the controller; absent → empty, no metrics)
        $items_created = PCM_DB::create_strategy_items(
            $strategy_id,
            $user_id,
            $keywords,
            is_array($options['keywordMeta'] ?? null) ? $options['keywordMeta'] : array()
        );

        // ── Compute per-item due dates when scheduled (Decision 1: daily cron
        //     granularity — these are DUE DATES the scanner checks once a day,
        //     not precise times) — port of AutoPress's calculateSchedule(). ──
        if (($options['publishingMode'] ?? '') === 'schedule') {
            $schedule_cfg = (is_array($options['config'] ?? null) && is_array($options['config']['scheduleConfig'] ?? null))
                ? $options['config']['scheduleConfig']
                : array();
            $frequency  = !empty($schedule_cfg['frequency']) ? (string)$schedule_cfg['frequency'] : 'weekly';
            // The create dialog has no date-picker yet (frontend gap — always sends
            // startDate:''), so this default-to-now path is the one every scheduled
            // strategy currently takes; still correct once a picker ships.
            $start_date = !empty($schedule_cfg['startDate']) ? (string)$schedule_cfg['startDate'] : current_time('mysql');

            $items = PCM_DB::get_strategy_items($strategy_id); // position ASC — matches keyword order
            $dates = self::calculate_schedule_dates(count($items), $frequency, $start_date);
            foreach ($items as $i => $item) {
                PCM_DB::update_strategy_item((int)$item->id, array('scheduledDate' => $dates[$i] ?? end($dates)));
            }
        }

        // ── Auto-start background generation (Task E2/AutoPress parity) ──
        // Scheduled strategies start via run_scheduled_scan() on their own due
        // dates (arming the queue here would blow through a whole week's worth
        // of "daily" items immediately — see maybe_schedule_queue_continuation()'s
        // own due-date guard). Every other mode begins generating right away,
        // in the background, with no Generate click required. The method
        // dedupes itself (wp_next_scheduled()) and no-ops when nothing is
        // pending, so calling it here is always safe.
        if (($options['publishingMode'] ?? 'draft') !== 'schedule') {
            self::maybe_schedule_queue_continuation($strategy_id, $user_id);
        }

        // ── Return the complete strategy with items ──
        $strategy = PCM_DB::get_strategy($strategy_id, $user_id);
        $strategy->items = PCM_DB::get_strategy_items($strategy_id);

        return (array)$strategy;
    }

    /**
     * Duplicate an existing strategy (Task E1): create a fresh copy carrying over
     * the source's template/brand/hierarchy/publishing mode and its config JSON
     * verbatim, with a brand-new set of PENDING items recreated from the source
     * items' keywords. Counters reset (completedItems/failedItems = 0, status
     * 'pending'); totalItems mirrors the source's item count.
     *
     * DELIBERATE deviation from create_from_keywords() (Task E2's auto-start): the
     * copy does NOT arm background generation — the user reviews the duplicated
     * queue (keywords, target site, schedule) and starts it manually. Duplicating
     * a strategy shouldn't silently spend LLM budget regenerating a whole batch.
     *
     * @param object $strategy Source strategy DB row (already ownership-verified by the caller).
     * @param int    $user_id  Owner ID (the copy is owned by this user).
     * @return array Created copy with its items array (same shape create_from_keywords returns).
     * @throws \RuntimeException On DB failure.
     */
    public static function duplicate_strategy(object $strategy, int $user_id): array
    {
        // Collect the source items' keywords (position order) — the copy's items
        // are recreated fresh (all pending), NOT cloned with their statuses.
        $keywords = array();
        foreach (PCM_DB::get_strategy_items((int)$strategy->id) as $it) {
            $keywords[] = (string)$it->keyword;
        }

        $publishing_mode = (string)($strategy->publishingMode ?? 'draft');
        // Copy the config JSON verbatim (already-stored, already-sanitized string);
        // null stays null.
        $config_json = !empty($strategy->config) ? (string)$strategy->config : null;

        $new_id = PCM_DB::create_strategy(array(
            'userId'         => $user_id,
            'name'           => (string)$strategy->name . ' (copy)',
            'templateId'     => (int)$strategy->templateId,
            'brandId'        => !empty($strategy->brandId) ? (int)$strategy->brandId : null,
            'status'         => 'pending',
            'hierarchyMode'  => (string)($strategy->hierarchyMode ?? 'standalone'),
            'publishingMode' => $publishing_mode,
            'config'         => $config_json,
            'totalItems'     => count($keywords),
            'completedItems' => 0,
            'failedItems'    => 0,
        ));
        if (!$new_id) {
            throw new \RuntimeException('Failed to create duplicate strategy in database.');
        }

        PCM_DB::create_strategy_items($new_id, $user_id, $keywords);

        // Schedule mode: recompute the copy's due dates starting NOW (the source's
        // start date is history) via the existing reschedule/calculate path.
        if ($publishing_mode === 'schedule') {
            $cfg = !empty($config_json) ? json_decode($config_json, true) : null;
            $schedule_cfg = (is_array($cfg) && is_array($cfg['scheduleConfig'] ?? null))
                ? $cfg['scheduleConfig']
                : array();
            $frequency = !empty($schedule_cfg['frequency']) ? (string)$schedule_cfg['frequency'] : 'weekly';
            self::reschedule_pending_items($new_id, $frequency, ''); // '' → now
        }

        // NOTE: no maybe_schedule_queue_continuation() here — see the deviation
        // documented in this method's docblock (the copy waits for a manual start).

        $new_strategy = PCM_DB::get_strategy($new_id, $user_id);
        $new_strategy->items = PCM_DB::get_strategy_items($new_id);

        return (array)$new_strategy;
    }

    /**
     * Compute per-item due dates for a scheduled strategy — a faithful port of
     * AutoPress's `calculateSchedule()`: the first item gets $start_date; each
     * subsequent item's date advances by the frequency interval, in position
     * order. `all_once` gives every item the SAME date. Pure/deterministic —
     * the caller resolves "now" (via `current_time()`) before calling, so this
     * has no hidden time dependency and is directly unit-testable.
     *
     * @param int    $count      Number of items to schedule (in position order).
     * @param string $frequency  'all_once'|'daily'|'every_other_day'|'weekly'|'biweekly'|'monthly'.
     * @param string $start_date Any strtotime()-parseable date (the first item's due date).
     * @return string[] $count MySQL DATETIME strings ('Y-m-d H:i:s'), one per item, in order.
     */
    public static function calculate_schedule_dates(int $count, string $frequency, string $start_date): array
    {
        $dates     = array();
        $timestamp = strtotime($start_date) ?: time();

        for ($i = 0; $i < $count; $i++) {
            $dates[] = date('Y-m-d H:i:s', $timestamp);

            if ($frequency === 'all_once') {
                continue; // every item shares the same date
            }

            switch ($frequency) {
                case 'daily':
                    $timestamp = strtotime('+1 day', $timestamp);
                    break;
                case 'every_other_day':
                    $timestamp = strtotime('+2 days', $timestamp);
                    break;
                case 'weekly':
                    $timestamp = strtotime('+7 days', $timestamp);
                    break;
                case 'biweekly':
                    // Matches AutoPress's own strategyService.ts verbatim: named
                    // "biweekly" but spaced +3 days ("approx twice a week" per its
                    // own comment) — ported faithfully, not a typo on our part.
                    $timestamp = strtotime('+3 days', $timestamp);
                    break;
                case 'monthly':
                    $timestamp = strtotime('+1 month', $timestamp);
                    break;
                default:
                    $timestamp = strtotime('+7 days', $timestamp); // sane fallback
                    break;
            }
        }

        return $dates;
    }

    /**
     * Resolve a strategy's content-per-keyword structure from its stored config.
     * 'individual' (the default) preserves the exact pre-Step-8 behavior — one
     * article per item.
     *
     * @param object $strategy Strategy DB row.
     * @return string 'individual'|'consolidated'.
     */
    private static function structure_mode(object $strategy): string
    {
        if (empty($strategy->config)) {
            return 'individual';
        }
        $cfg = json_decode((string)$strategy->config, true);
        $mode = is_array($cfg) ? (string)($cfg['structure'] ?? 'individual') : 'individual';
        return $mode === 'consolidated' ? 'consolidated' : 'individual';
    }

    /**
     * Generate ONE article covering every keyword in the strategy (Step 8),
     * instead of one article per item. Every item shares the same articleId and
     * moves through generation/approval/publish together, since there's only
     * one piece of content for the whole batch.
     *
     * Deliberately re-applies the approval-gate branch from generate_next_item()
     * (Step 6) rather than skipping it — a strategy can combine "Consolidated"
     * structure with "Internal/Client" approvals in the create dialog, and
     * silently bypassing the approval gate for that combination would be a real
     * regression, not just an omission.
     *
     * @param object $strategy Strategy DB row.
     * @param int    $user_id  PCM user ID.
     * @return array|null Generated article data, or null if already generated
     *   (or nothing to generate).
     * @throws \RuntimeException On generation failure.
     */
    private static function generate_consolidated_batch(object $strategy, int $user_id): ?array
    {
        $items = PCM_DB::get_strategy_items((int)$strategy->id);
        $pending_ids = array();
        $keywords    = array();
        foreach ($items as $it) {
            $keywords[] = (string)$it->keyword;
            if ($it->status === 'pending' || $it->status === 'error') {
                $pending_ids[] = (int)$it->id;
            }
        }

        if (empty($pending_ids)) {
            // Already generated (every item shares the one article) — or there
            // was nothing to generate in the first place.
            self::recompute_counters((int)$strategy->id, $user_id, (int)$strategy->totalItems);
            return null;
        }

        foreach ($pending_ids as $id) {
            PCM_DB::update_strategy_item($id, array('status' => 'generating'));
        }

        try {
            $template = self::load_template((int)$strategy->templateId, $user_id);
            $brand = !empty($strategy->brandId) ? PCM_DB::get_brand_by_id((int)$strategy->brandId, $user_id) : null;

            $research = self::maybe_research_context($strategy, $keywords, $user_id);
            $messages = self::build_prompt($keywords, $template, $brand, $research, self::in_content_media_enabled($strategy));
            list($model, $provider) = self::resolve_model($strategy);
            $llm_options = array(
                'model'      => $model,
                'max_tokens' => 8192,
                // Owner-scoped key lookup (PCM user id), not get_current_user_id():
                // under wp-cron there is no current user (id 0), which is why the
                // consolidated batch failed with "No active API key" even though
                // the key was present and active. See generate_next_item() twin.
                'user_id'    => $user_id,
            );
            if ($provider !== '') {
                $llm_options['provider'] = $provider;
            }
            $result = PCM_LLM::invoke_json($messages, self::article_schema(), $llm_options);

            // A6: in-content images & charts — replace [IMAGE_N] tokens with
            // <figure> blocks (or strip them when disabled/absent), before persist.
            $result = self::maybe_generate_in_content_media($result, $strategy, $user_id);

            $title = $result['title'] ?? ucfirst($keywords[0] ?? '');
            $slug  = sanitize_title($title);

            $article_id = PCM_DB::create_article(array(
                'userId'          => $user_id,
                'strategyId'      => (int)$strategy->id,
                'strategyItemId'  => $pending_ids[0], // the batch's own "primary" item, for traceability only
                'brandId'         => !empty($strategy->brandId) ? (int)$strategy->brandId : null,
                'title'           => $title,
                'slug'            => $slug,
                'content'         => $result['content'] ?? '',
                'metaTitle'       => $result['metaTitle'] ?? $title,
                'metaDescription' => $result['metaDescription'] ?? '',
                'schemaType'      => 'Article',
                'status'          => 'draft',
                'featuredImage'   => self::maybe_generate_featured_image($strategy, $title, (string)($keywords[0] ?? ''), $user_id),
            ));

            if (!$article_id) {
                throw new \RuntimeException('Failed to save generated article.');
            }

            $approval_mode = self::approval_mode($strategy);
            $publish = null;

            if ($approval_mode !== 'none') {
                $set_id = self::create_approval_set_for_item(
                    $strategy,
                    (object)array('keyword' => 'Consolidated (' . count($keywords) . ' keywords)'),
                    $article_id,
                    $user_id,
                    $approval_mode
                );
                foreach ($pending_ids as $id) {
                    PCM_DB::update_strategy_item($id, array(
                        'status'       => 'in_review',
                        'title'        => $title,
                        'slug'         => $slug,
                        'articleId'    => $article_id,
                        'setId'        => $set_id,
                        'errorMessage' => '',
                    ));
                }
            } else {
                foreach ($pending_ids as $id) {
                    PCM_DB::update_strategy_item($id, array(
                        'status'       => 'completed',
                        'title'        => $title,
                        'slug'         => $slug,
                        'articleId'    => $article_id,
                        'errorMessage' => '',
                    ));
                }
                $publish = self::maybe_auto_publish($strategy, PCM_DB::get_article($article_id, $user_id), $user_id);
            }

            self::recompute_counters((int)$strategy->id, $user_id, (int)$strategy->totalItems);

            $response = array(
                'item'    => PCM_DB::get_strategy_items((int)$strategy->id),
                'article' => PCM_DB::get_article($article_id, $user_id),
            );
            if ($publish !== null) {
                $response['publish'] = $publish;
            }
            return $response;
        } catch (\Throwable $e) {
            foreach ($pending_ids as $id) {
                PCM_DB::update_strategy_item($id, array(
                    'status'       => 'error',
                    'errorMessage' => substr($e->getMessage(), 0, 1000),
                ));
            }
            self::recompute_counters((int)$strategy->id, $user_id, (int)$strategy->totalItems);
            throw $e;
        }
    }

    /**
     * Resolve a strategy's hierarchy config (parentTargetUrl/parentKeyword,
     * Step 9) from its stored config JSON.
     *
     * @param object $strategy Strategy DB row.
     * @return array Decoded config (empty array if none stored).
     */
    private static function hierarchy_config(object $strategy): array
    {
        if (empty($strategy->config)) {
            return array();
        }
        $cfg = json_decode((string)$strategy->config, true);
        return is_array($cfg) ? $cfg : array();
    }

    /**
     * Item selection for hierarchyMode='parent_and_children' (Step 9): the
     * designated parent (matched by keyword — this codebase stores
     * `parentKeyword` as a string, not an item id, per the create dialog) must
     * generate FIRST regardless of its position, so children can link to its
     * resulting article. Once the parent has an articleId, children are
     * selected normally (any other pending item); until then, children wait —
     * mirrors the AutoPress reference this was ported from ("waiting for
     * parent"), simplified to "parent has generated an article" rather than
     * "parent is published" (draft-mode strategies may never publish at all).
     *
     * Falls back to plain lowest-position-pending selection if the configured
     * parentKeyword doesn't match any item (bad/missing config) — degrades to
     * standalone behavior rather than stalling the whole strategy.
     *
     * @param object $strategy Strategy DB row.
     * @return object|null Next item to generate, or null (nothing left, or
     *   children are waiting on the parent).
     */
    private static function select_next_item_for_parent_and_children(object $strategy): ?object
    {
        $items = PCM_DB::get_strategy_items((int)$strategy->id);
        $parent_keyword = (string)(self::hierarchy_config($strategy)['parentKeyword'] ?? '');

        $parent = null;
        if ($parent_keyword !== '') {
            foreach ($items as $it) {
                if ((string)$it->keyword === $parent_keyword) {
                    $parent = $it;
                    break;
                }
            }
        }

        if (!$parent) {
            // No parent configured/found — degrade to plain selection.
            return PCM_DB::get_next_pending_item((int)$strategy->id);
        }
        if ($parent->status === 'pending') {
            return $parent; // parent goes first, regardless of position
        }
        if (empty($parent->articleId)) {
            return null; // parent is generating/errored — children wait
        }
        foreach ($items as $it) {
            if ($it->status === 'pending' && (int)$it->id !== (int)$parent->id) {
                return $it;
            }
        }
        return null;
    }

    /**
     * Resolve the parent link {url, topic} to inject into a child item's HTML
     * (Step 9). Two sources, matching the two hierarchy modes that need one:
     *   - 'children_only': the stored external parentTargetUrl — every item in
     *     the strategy is a "child" linking to the same pre-existing page.
     *   - 'parent_and_children': the strategy's own designated parent item,
     *     generated first via select_next_item_for_parent_and_children() above.
     * Returns null for 'standalone'/'parent_only' (nothing to inject), or when
     * the parent_and_children parent hasn't generated its article yet.
     *
     * URL construction for an unpublished parent article mirrors the AutoPress
     * reference this was ported from: prefer the article's real publishedUrl
     * once it exists, otherwise construct one from the strategy's configured
     * site + the article's slug — a best-effort guess, not a guarantee that it
     * matches the article's actual URL once published, exactly as ported.
     *
     * @param object $strategy Strategy DB row.
     * @param array  $items    All of the strategy's items (avoids a re-query).
     * @param int    $user_id  PCM user ID.
     * @return array{url:string,topic:string}|null
     */
    private static function resolve_parent_link(object $strategy, array $items, int $user_id): ?array
    {
        $mode = (string)($strategy->hierarchyMode ?? 'standalone');
        $cfg  = self::hierarchy_config($strategy);

        if ($mode === 'children_only') {
            $url = !empty($cfg['parentTargetUrl']) ? (string)$cfg['parentTargetUrl'] : '';
            return $url !== '' ? array('url' => $url, 'topic' => 'our parent guide') : null;
        }

        if ($mode === 'parent_and_children') {
            $parent_keyword = (string)($cfg['parentKeyword'] ?? '');
            if ($parent_keyword === '') {
                return null;
            }
            $parent = null;
            foreach ($items as $it) {
                if ((string)$it->keyword === $parent_keyword) {
                    $parent = $it;
                    break;
                }
            }
            if (!$parent || empty($parent->articleId)) {
                return null;
            }
            $article = PCM_DB::get_article((int)$parent->articleId, $user_id);
            if (!$article) {
                return null;
            }
            if (!empty($article->publishedUrl)) {
                return array('url' => (string)$article->publishedUrl, 'topic' => (string)$parent->keyword);
            }
            $site_id = !empty($cfg['siteId']) ? (int)$cfg['siteId'] : 0;
            $site = $site_id > 0 ? PCM_DB::get_site($site_id, $user_id) : null;
            $url = $site
                ? rtrim((string)$site->url, '/') . '/' . ltrim((string)($article->slug ?? ''), '/')
                : '/' . ltrim((string)($article->slug ?? ''), '/');
            return array('url' => $url, 'topic' => (string)$parent->keyword);
        }

        return null;
    }

    /**
     * Append a parent-link paragraph to generated HTML (Step 9, post-process —
     * deterministic and guaranteed-present, unlike the AutoPress reference's
     * prompt-instruction approach, which asks the LLM to place the link and
     * cannot guarantee it complies; the plan's own verification standard —
     * "child HTML contains the parent link" — requires the deterministic form).
     *
     * @param string $content  Generated article HTML.
     * @param array  $link     {url, topic} from resolve_parent_link().
     * @param object $strategy  Strategy DB row (for the optional anchor override).
     * @return string Content with the parent-link paragraph appended.
     */
    private static function inject_parent_link(string $content, array $link, object $strategy): string
    {
        // G1: an explicit config.parentAnchorKeyword overrides the parent's topic
        // as the anchor text. The appended paragraph is already DETERMINISTIC (a
        // fixed, exact-text sentence — never an LLM-placed variation), so the
        // allowAnchorVariations=false contract ("exact text") holds automatically:
        // the override is used verbatim. allowAnchorVariations only governs the
        // (LLM-driven) variation path, which this deterministic form doesn't take.
        $cfg      = self::hierarchy_config($strategy);
        $override = isset($cfg['parentAnchorKeyword']) ? trim((string)$cfg['parentAnchorKeyword']) : '';
        $anchor   = $override !== '' ? $override : (string)$link['topic'];

        return $content . sprintf(
            '<p>Learn more in %s: <a href="%s">%s</a>.</p>',
            esc_html($anchor),
            esc_url($link['url']),
            esc_html($anchor)
        );
    }

    /**
     * Generate an article for the next pending item in a strategy.
     *
     * Pipeline:
     *   1. Find next pending item
     *   2. Load template (prompt entries)
     *   3. Load brand context (if set)
     *   4. Build LLM prompt with variable injection
     *   5. Invoke PCM_LLM
     *   6. Create article in pcm_articles
     *   7. Link article to strategy item
     *   8. Update strategy counters
     *
     * @param object $strategy Strategy DB row.
     * @param int    $user_id  PCM user ID.
     *
     * @return array|null Generated article data, or null if all items done.
     * @throws \RuntimeException On generation failure.
     */
    public static function generate_next_item(object $strategy, int $user_id, ?int $item_id = null): ?array
    {
        // Step 8: a consolidated strategy has ONE shared article across every
        // item — a completely different generation shape (all keywords in one
        // prompt, every item completes together) — so it's routed to its own
        // dedicated path rather than threaded through the per-item pipeline
        // below. Applies to both the "next pending" flow and a targeted retry
        // (there's only one article to regenerate either way).
        if (self::structure_mode($strategy) === 'consolidated') {
            return self::generate_consolidated_batch($strategy, $user_id);
        }

        // ── 1. Pick the item to generate ──
        if ($item_id) {
            // Targeted (re)generation — retry a failed item or regenerate a specific
            // one. Must belong to this strategy; any current status is allowed.
            $item = null;
            foreach (PCM_DB::get_strategy_items((int)$strategy->id) as $candidate) {
                if ((int)$candidate->id === $item_id) {
                    $item = $candidate;
                    break;
                }
            }
            if (!$item) {
                throw new \RuntimeException('Item not found in this strategy.');
            }
        } else {
            // A generator process killed mid-run (gateway/PHP timeout) strands its
            // item in 'generating' forever — reclaim stale ones first so the
            // strategy can't wedge at "In Progress" with a phantom worker.
            PCM_DB::reclaim_stale_generating((int)$strategy->id);

            // Step 9: 'parent_and_children' must generate the designated parent
            // item FIRST regardless of position, then wait for it to have an
            // article before any child proceeds (a child's content links to the
            // parent's URL — see resolve_parent_link()). Every other hierarchy
            // mode (including the default 'standalone') keeps the plain
            // lowest-position-pending selection unchanged.
            //
            // The pick→claim loop is the duplicate-generation guard: the browser's
            // Generate-All loop and a wp-cron queue tick can race for the same
            // pending item, so the 'generating' write is an atomic compare-and-set
            // (claim_strategy_item, gated on status='pending'); a loser re-picks
            // the NEXT pending item instead of double-generating this one.
            $item = null;
            for ($pick_attempt = 0; $pick_attempt < 3; $pick_attempt++) {
                $candidate = (string)($strategy->hierarchyMode ?? 'standalone') === 'parent_and_children'
                    ? self::select_next_item_for_parent_and_children($strategy)
                    : PCM_DB::get_next_pending_item((int)$strategy->id);
                if (!$candidate) {
                    break;
                }
                if (PCM_DB::claim_strategy_item((int)$candidate->id)) {
                    $item = $candidate;
                    break;
                }
                // Lost the race — another generator claimed it between pick and
                // claim; loop re-picks whatever is pending now.
            }
            if (!$item) {
                // Nothing left to generate — resolve status from the real item states
                // (marks 'completed' only when every item succeeded; stays 'in_progress'
                // when a failed item remains, so the strategy isn't falsely "Completed").
                self::recompute_counters((int)$strategy->id, $user_id, (int)$strategy->totalItems);
                return null;
            }
        }

        // Targeted retry: mark as generating unconditionally (any-status
        // regeneration is by design). The next-pending flow above already set
        // it atomically via claim_strategy_item().
        if ($item_id) {
            PCM_DB::update_strategy_item((int)$item->id, array('status' => 'generating'));
        }

        try {
            // Task F1: per-item overrides (templateId/publishingMode/approvalMode).
            // ONLY resolved here, in the per-item path — a consolidated (Step 8)
            // strategy's one shared article can't honor conflicting per-item
            // overrides, so generate_consolidated_batch() never calls item_config().
            $item_cfg = self::item_config($item);

            // ── 2. Load template — item override wins over the strategy's own ──
            $template_id = !empty($item_cfg['templateId']) ? (int)$item_cfg['templateId'] : (int)$strategy->templateId;
            $template = self::load_template($template_id, $user_id);

            // ── 3. Load brand context ──
            $brand = null;
            if (!empty($strategy->brandId)) {
                $brand = PCM_DB::get_brand_by_id((int)$strategy->brandId, $user_id);
            }

            // ── 4. Build prompt with variable injection (+ optional live research) ──
            $research = self::maybe_research_context($strategy, array($item->keyword), $user_id);
            $messages = self::build_prompt(array($item->keyword), $template, $brand, $research, self::in_content_media_enabled($strategy));

            // ── 5. Invoke LLM — user-selected model/provider (data-driven), with a
            //       fallback for strategies created before model selection existed. ──
            list($model, $provider) = self::resolve_model($strategy);
            $llm_options = array(
                'model'      => $model,
                'max_tokens' => 8192,
                // The API key is scoped to the strategy OWNER (the PCM user id
                // threaded through this whole flow — the same id the integrations
                // table is keyed by and that maybe_generate_featured_image() uses).
                // NOT get_current_user_id(): the queue continuation runs under
                // wp-cron with no logged-in user (id 0), so that lookup found no
                // key and every cron-driven item errored with "No active API key".
                'user_id'    => $user_id,
            );
            if ($provider !== '') {
                $llm_options['provider'] = $provider;
            }
            $result = PCM_LLM::invoke_json($messages, self::article_schema(), $llm_options);

            // ── 6. Create article ──
            $title = $result['title'] ?? ucfirst($item->keyword);
            $slug = sanitize_title($title);

            // A6: in-content images & charts — replace [IMAGE_N] tokens with
            // <figure> blocks (or strip them when disabled/absent). Runs BEFORE
            // the content is persisted, and before the parent-link append below.
            $result  = self::maybe_generate_in_content_media($result, $strategy, $user_id);
            $content = $result['content'] ?? '';

            // Step 9: hierarchy-aware parent-link injection (post-process, before
            // the article is saved, so the link is baked into stored content).
            $parent_link = self::resolve_parent_link($strategy, PCM_DB::get_strategy_items((int)$strategy->id), $user_id);
            if ($parent_link !== null) {
                $content = self::inject_parent_link($content, $parent_link, $strategy);
            }

            $article_id = PCM_DB::create_article(array(
                'userId'          => $user_id,
                'strategyId'      => (int)$strategy->id,
                'strategyItemId'  => (int)$item->id,
                'brandId'         => !empty($strategy->brandId) ? (int)$strategy->brandId : null,
                'title'           => $title,
                'slug'            => $slug,
                'content'         => $content,
                'metaTitle'       => $result['metaTitle'] ?? $title,
                'metaDescription' => $result['metaDescription'] ?? '',
                'schemaType'      => 'Article',
                'status'          => 'draft',
                'featuredImage'   => self::maybe_generate_featured_image($strategy, $title, (string)$item->keyword, $user_id),
            ));

            if (!$article_id) {
                throw new \RuntimeException('Failed to save generated article.');
            }

            // ── 7. Link article to strategy item, then either hand off to the
            //       Approvals module (Decision 2) or complete + auto-publish
            //       immediately, exactly as before this step. ──
            // Task F1: this ITEM's effective approval mode — an item-level
            // override (including an explicit 'none') wins over the strategy's
            // own approval_mode(); array_key_exists (not empty()) so an explicit
            // 'none' override is honored, not treated as "absent".
            $approval_mode = array_key_exists('approvalMode', $item_cfg)
                && in_array($item_cfg['approvalMode'], array('none', 'internal', 'client'), true)
                ? (string)$item_cfg['approvalMode']
                : self::approval_mode($strategy);

            if ($approval_mode !== 'none') {
                // Approval gate (Step 6/Decision 2): park the item in review and hand
                // the article to the EXISTING Approvals module as a new set. Publish
                // must NOT happen yet — it only runs once the set is fully approved
                // (see strategy/automations.php's action handler, Step 7). This is a
                // hand-off, not a reimplementation: no approval UI lives here.
                $set_id = self::create_approval_set_for_item($strategy, $item, $article_id, $user_id, $approval_mode);

                PCM_DB::update_strategy_item((int)$item->id, array(
                    'status'       => 'in_review',
                    'title'        => $title,
                    'slug'         => $slug,
                    'articleId'    => $article_id,
                    'setId'        => $set_id,
                    'errorMessage' => '',
                ));

                // ── 8. Recompute strategy counters (retry-safe). 'in_review' counts
                //       as neither completed nor failed — matches recompute_counters'
                //       existing completed/error-only tally. ──
                self::recompute_counters((int)$strategy->id, $user_id, (int)$strategy->totalItems);

                $response = array(
                    'item'    => PCM_DB::get_strategy_items((int)$strategy->id),
                    'article' => PCM_DB::get_article($article_id, $user_id),
                );
            } else {
                PCM_DB::update_strategy_item((int)$item->id, array(
                    'status'       => 'completed',
                    'title'        => $title,
                    'slug'         => $slug,
                    'articleId'    => $article_id,
                    'errorMessage' => '',
                ));

                // ── 8. Recompute strategy counters from item states (retry-safe) ──
                self::recompute_counters((int)$strategy->id, $user_id, (int)$strategy->totalItems);

                // ── 9. Auto-publish (publishingMode 'publish' or 'schedule' + a configured
                //       site) — 'publish' publishes immediately, right here on generation;
                //       'schedule' reaches this same call once the cron scanner triggers
                //       generation on the item's due date (run_scheduled_scan(), above).
                //       Failure here must NOT affect the item/strategy state above — the
                //       draft already saved successfully; a publish error is reported
                //       separately so the caller can toast it without masking success.
                //       Re-fetch the article afterwards: on success, publish_to_site()
                //       updates its status/publishedUrl in the DB, and the response
                //       must reflect that instead of the pre-publish draft snapshot.
                //       Task F1: an item-level publishingMode override is applied by
                //       cloning the strategy (maybe_auto_publish()'s signature is NOT
                //       changed) — the clone is what both the publish decision above
                //       reads and what gets passed below. ──
                $publish_strategy = $strategy;
                if (!empty($item_cfg['publishingMode']) && in_array($item_cfg['publishingMode'], array('draft', 'publish', 'schedule'), true)) {
                    $publish_strategy = clone $strategy;
                    $publish_strategy->publishingMode = (string)$item_cfg['publishingMode'];
                }
                $publish = self::maybe_auto_publish($publish_strategy, PCM_DB::get_article($article_id, $user_id), $user_id);

                $response = array(
                    'item'    => PCM_DB::get_strategy_items((int)$strategy->id),
                    'article' => PCM_DB::get_article($article_id, $user_id),
                );
                if ($publish !== null) {
                    $response['publish'] = $publish;
                }
            }
            // Background continuation: if items remain, self-schedule a wp-cron
            // tick so the queue keeps advancing even if the caller (a browser tab
            // running Generate/Generate All) closes before it finishes. Scoped to
            // the "next pending" flow ONLY ($item_id === null) — a TARGETED retry
            // of one specific item must stay scoped to that one item; it shouldn't
            // surprise the user by silently kicking off unrelated pending items in
            // the background just because they clicked Retry on a single row.
            if ($item_id === null) {
                self::maybe_schedule_queue_continuation((int)$strategy->id, $user_id);
            }
            return $response;

        } catch (\Throwable $e) {
            // Mark item as failed — don't crash the entire strategy
            PCM_DB::update_strategy_item((int)$item->id, array(
                'status'       => 'error',
                'errorMessage' => substr($e->getMessage(), 0, 1000),
            ));
            self::recompute_counters((int)$strategy->id, $user_id, (int)$strategy->totalItems);
            // Skip-and-continue (Decision 4): a failed item must not stop the queue —
            // still check for more pending work and schedule the continuation before
            // re-throwing (the throw only tells THIS caller their one item failed).
            // Same $item_id===null scoping as the success path above.
            if ($item_id === null) {
                self::maybe_schedule_queue_continuation((int)$strategy->id, $user_id);
            }

            throw $e;
        }
    }

    /**
     * Background queue: if a strategy still has a pending item after a generate
     * attempt (success or failure), self-schedule a one-off wp-cron continuation
     * so the batch keeps advancing without the browser tab that started it. Reuses
     * generate_next_item() itself as the tick — no separate "process all" endpoint
     * needed; every call to /generate (single or from the client's Generate-All
     * loop) transparently arms this safety net. Dedup'd by wp_next_scheduled() on
     * the exact (strategyId,userId) args, so repeated calls don't pile up duplicate
     * cron events for the same strategy.
     *
     * @param int $strategy_id Strategy ID.
     * @param int $user_id     Owner ID (the continuation runs as this user).
     */
    private static function maybe_schedule_queue_continuation(int $strategy_id, int $user_id): void
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }
        // D2: a paused strategy must not advance — never arm a continuation for it.
        // The ownerless read available here (strategy_id + user_id) is exactly what
        // PCM_DB::get_strategy() needs; a resume (status → 'in_progress') re-opens
        // the queue via the next generate/scan trigger.
        $paused_check = PCM_DB::get_strategy($strategy_id, $user_id);
        if ($paused_check && (string)($paused_check->status ?? '') === 'paused') {
            return;
        }
        $next = PCM_DB::get_next_pending_item($strategy_id);
        if (!$next) {
            return; // nothing left — no continuation needed
        }
        // Scheduled strategies (Step 4/Decision 1): don't chain straight through a
        // not-yet-due item — that would blow through a whole week's worth of
        // "daily" items in one background sweep the moment the first one runs.
        // A due item's continuation still chains normally; a not-due item is left
        // for run_scheduled_scan() to pick back up on its own due date. Items with
        // no scheduledDate (draft/publish mode) are unaffected — they keep chaining
        // immediately exactly as before.
        $due_date = $next->scheduledDate ?? null;
        if (!empty($due_date) && strtotime($due_date) > strtotime(current_time('mysql'))) {
            return;
        }
        $args = array($strategy_id, $user_id);
        if (!wp_next_scheduled('pcm_strategy_process_queue', $args)) {
            wp_schedule_single_event(time(), 'pcm_strategy_process_queue', $args);
        }
    }

    /**
     * wp-cron callback for the background queue continuation above. Generates
     * the next pending item and — via generate_next_item()'s own call to
     * maybe_schedule_queue_continuation() — re-arms itself while pending items
     * remain, so the whole batch drains even with no browser tab open.
     * Registered via add_action('pcm_strategy_process_queue', ...) at file load.
     *
     * Skip-and-continue (Decision 4): a thrown generation failure is caught and
     * logged here — generate_next_item() has ALREADY recorded it on the item
     * (status='error' + errorMessage) and scheduled the next continuation before
     * re-throwing, so swallowing the exception at this outermost boundary is
     * exactly "don't halt the batch," not "hide a failure" (nothing is hidden;
     * it's already persisted).
     *
     * @param int $strategy_id Strategy ID.
     * @param int $user_id     Owner ID.
     */
    public static function run_queue_tick(int $strategy_id, int $user_id): void
    {
        $strategy = PCM_DB::get_strategy($strategy_id, $user_id);
        if (!$strategy) {
            return; // deleted since this tick was scheduled
        }
        // D2: paused since this tick was scheduled — no-op (belt-and-braces with
        // maybe_schedule_queue_continuation()'s own paused guard, which normally
        // prevents the tick from ever being armed; this covers a pause that lands
        // after the event was already queued).
        if ((string)($strategy->status ?? '') === 'paused') {
            return;
        }
        try {
            self::generate_next_item($strategy, $user_id);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[PCM_Strategy_Service] Background queue tick failed for strategy #%d: %s',
                $strategy_id,
                $e->getMessage()
            ));
        }
    }

    /**
     * Daily wp-cron scan (Step 4/Decision 1: daily cron granularity — due DATES,
     * not precise times) for scheduled strategies. Finds every strategy that has
     * at least one pending item whose scheduledDate is now due, and kicks off the
     * SAME background-queue mechanism Step 1 already built
     * (maybe_schedule_queue_continuation() → run_queue_tick() → generate_next_item()
     * → maybe_auto_publish()) — no new generation/publish path, just a new trigger
     * for the existing one. The due-date gate added to
     * maybe_schedule_queue_continuation() above stops the chain from running past
     * the currently-due item(s); this scan is what re-arms it on each item's own
     * due date. Mirrors run_pending_client_scan()'s shape (global, owner-scoped,
     * dedup'd by wp_next_scheduled()) — registered on its own daily event so it
     * doesn't have to share cadence with the Automations module's scanner.
     */
    public static function run_scheduled_scan(): void
    {
        $now = current_time('mysql');
        foreach (PCM_DB::get_due_scheduled_strategies($now) as $row) {
            self::maybe_schedule_queue_continuation((int)$row->strategyId, (int)$row->userId);
        }
        self::run_site_schedules($now);
    }

    /**
     * Per-site recurring content schedules (Task I2, port of AutoPress's
     * SiteSchedule): each rule — configured on the Sites page and stored in
     * the PCM_Sites_Service::SCHEDULES_OPTION option — says "every
     * day/week/month, suggest N topics for this site and spin them into a
     * strategy". Runs inside the same daily scan as scheduled strategies.
     *
     * Cadence/dedupe: a rule is due when it has never run, or when
     * lastRunAt + its interval has passed. lastRunAt is stamped ONLY after a
     * strategy was actually created — an empty suggestion round (missing API
     * key, LLM hiccup; the suggester never throws) leaves the stamp alone so
     * the rule simply retries on the next daily scan instead of silently
     * skipping a whole period.
     *
     * Failure isolation: each rule runs in its own try/catch — one site's
     * broken rule must not stop the others (same contract as the queue tick).
     *
     * @param string $now Current time 'Y-m-d H:i:s' (site-local, from the caller).
     * @return array<int, array{siteId: int, strategyId: int|null, reason: string}>
     *               Per-rule outcome — consumed by tests; cron ignores it.
     */
    public static function run_site_schedules(string $now): array
    {
        $outcomes = array();
        // Option name matches PCM_Sites_Service::SCHEDULES_OPTION — referenced
        // as a literal so the cron path never needs the Sites service class
        // loaded (cron runs outside any REST request, where controllers lazily
        // require their services).
        $all = get_option('pcm_site_schedules', array());
        if (!is_array($all) || $all === array()) {
            return $outcomes;
        }

        $dirty = false;
        foreach ($all as $site_key => $rule) {
            $site_id = (int) $site_key;
            try {
                if (!is_array($rule) || empty($rule['enabled'])) {
                    $outcomes[] = array('siteId' => $site_id, 'strategyId' => null, 'reason' => 'disabled');
                    continue;
                }
                $user_id     = (int) ($rule['userId'] ?? 0);
                $template_id = (int) ($rule['templateId'] ?? 0);
                if (!$user_id || !$template_id) {
                    $outcomes[] = array('siteId' => $site_id, 'strategyId' => null, 'reason' => 'incomplete rule');
                    continue;
                }
                if (!self::site_schedule_due($rule, $now)) {
                    $outcomes[] = array('siteId' => $site_id, 'strategyId' => null, 'reason' => 'not due');
                    continue;
                }
                // Ownership re-check at run time — a deleted (or re-owned) site
                // leaves its rule inert rather than creating orphan strategies.
                $site = PCM_DB::get_site($site_id, $user_id);
                if (!$site) {
                    $outcomes[] = array('siteId' => $site_id, 'strategyId' => null, 'reason' => 'site gone');
                    continue;
                }

                if (!class_exists('PCM_Topic_Suggester')) {
                    require_once __DIR__ . '/class-pcm-topic-suggester.php';
                }
                $count  = min(10, max(1, (int) ($rule['count'] ?? 5)));
                $topics = PCM_Topic_Suggester::suggest($user_id, array(
                    'niche'    => (string) ($rule['niche'] ?? ''),
                    'siteName' => (string) ($site->name ?? ''),
                    'siteUrl'  => (string) ($site->url ?? ''),
                ), $count);
                $keywords = array_values(array_filter(array_map(
                    static fn($t) => trim((string) ($t['keyword'] ?? '')),
                    $topics
                )));
                if ($keywords === array()) {
                    // No stamp — retry on the next daily scan (see docblock).
                    $outcomes[] = array('siteId' => $site_id, 'strategyId' => null, 'reason' => 'no suggestions');
                    continue;
                }

                $mode   = (string) ($rule['publishingMode'] ?? 'draft');
                $config = array('siteId' => $site_id);
                if ($mode === 'schedule') {
                    // Spread the batch across the rule's own cadence.
                    $config['scheduleConfig'] = array('frequency' => (string) ($rule['frequency'] ?? 'weekly'));
                }
                $created = self::create_from_keywords(
                    $user_id,
                    sprintf('Auto: %s — %s', (string) $site->name, substr($now, 0, 10)),
                    $template_id,
                    null,
                    $keywords,
                    array('publishingMode' => $mode, 'config' => $config)
                );

                $rule['lastRunAt']    = $now;
                $all[$site_key]       = $rule;
                $dirty                = true;
                $outcomes[] = array(
                    'siteId'     => $site_id,
                    'strategyId' => (int) ($created['id'] ?? 0),
                    'reason'     => 'created',
                );
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[PCM_Strategy_Service] Site schedule for site #%d failed: %s',
                    $site_id,
                    $e->getMessage()
                ));
                $outcomes[] = array('siteId' => $site_id, 'strategyId' => null, 'reason' => 'error');
            }
        }

        if ($dirty) {
            update_option('pcm_site_schedules', $all, false);
        }
        return $outcomes;
    }

    /**
     * Whether a site-schedule rule's next period has arrived: never-run rules
     * are due immediately; otherwise due once lastRunAt + interval ≤ now.
     * Intervals are calendar-based via strtotime so "monthly" tracks month
     * lengths instead of a fixed 30 days.
     *
     * @param array  $rule Rule array (frequency + lastRunAt).
     * @param string $now  Current time 'Y-m-d H:i:s'.
     * @return bool
     */
    private static function site_schedule_due(array $rule, string $now): bool
    {
        $last = (string) ($rule['lastRunAt'] ?? '');
        if ($last === '') {
            return true;
        }
        $interval = match ((string) ($rule['frequency'] ?? 'weekly')) {
            'daily'   => '+1 day',
            'monthly' => '+1 month',
            default   => '+1 week',
        };
        $next = strtotime($interval, (int) strtotime($last));
        return $next !== false && strtotime($now) >= $next;
    }

    /**
     * Resolve the LLM model + provider for a strategy from its stored config.
     * Falls back to the historical default (`gemini-2.5-flash`, no explicit
     * provider) for strategies created before model selection existed.
     *
     * @param object $strategy Strategy DB row.
     * @return array{0:string,1:string} [model, provider] — provider '' if unset.
     */
    private static function resolve_model(object $strategy): array
    {
        $model    = 'gemini-2.5-flash';
        $provider = '';
        if (!empty($strategy->config)) {
            $cfg = json_decode((string)$strategy->config, true);
            if (is_array($cfg)) {
                if (!empty($cfg['model']))    { $model    = (string)$cfg['model']; }
                if (!empty($cfg['provider'])) { $provider = (string)$cfg['provider']; }
            }
        }
        return array($model, $provider);
    }

    /**
     * Auto-publish a just-generated article to the strategy's configured site, when
     * `publishingMode` is `'publish'` OR `'schedule'` and a site is set — 'publish'
     * fires this immediately on generation; 'schedule' reaches this same path once
     * the cron scanner (run_scheduled_scan()) triggers generation on the item's due
     * date, so a scheduled article is no longer stranded as a draft forever (a
     * confirmed product gap — schedule mode previously generated but never
     * published). Reuses the same PCM_Sites_Service::publish_to_site() path the
     * Sites module's own /publish route calls — no new WP REST logic.
     * Ownership-scoped (a siteId from another user's site never resolves here,
     * since PCM_DB::get_site() is scoped to $user_id).
     *
     * @param object      $strategy Strategy DB row (publishingMode + config JSON).
     * @param object|null $article  The just-created article row. Nullable defensively —
     *   the caller re-fetches by the id it just inserted, so this should never actually
     *   be null, but a lookup miss must degrade to a reported failure, NOT a TypeError
     *   that would escape into generate_next_item()'s outer catch and wrongly flip a
     *   successfully-generated item to 'error' (that catch exists for GENERATION
     *   failures, not publish ones — see the isolation guarantee this method exists for).
     * @param int    $user_id  Owner ID.
     * @return array{success:bool,message:string}|null Null when publish doesn't apply
     *   (draft mode, or no site configured) — distinct from a failed attempt.
     */
    private static function maybe_auto_publish(object $strategy, ?object $article, int $user_id): ?array
    {
        if (!in_array((string)($strategy->publishingMode ?? ''), array('publish', 'schedule'), true)) {
            return null;
        }
        $site_id = 0;
        if (!empty($strategy->config)) {
            $cfg = json_decode((string)$strategy->config, true);
            if (is_array($cfg) && !empty($cfg['siteId'])) {
                $site_id = (int)$cfg['siteId'];
            }
        }
        if ($site_id <= 0) {
            return null;
        }
        if (!$article) {
            return array('success' => false, 'message' => 'Article not found for publish.');
        }

        $site = PCM_DB::get_site($site_id, $user_id);
        if (!$site) {
            return array('success' => false, 'message' => 'The configured site is no longer connected.');
        }

        try {
            if (!class_exists('PCM_Sites_Service')) {
                require_once dirname(__DIR__) . '/sites/service.php';
            }
            PCM_Sites_Service::publish_to_site($site, $article, $user_id);
            return array('success' => true, 'message' => 'Published to ' . (string)($site->name ?: $site->url) . '.');
        } catch (\Throwable $e) {
            return array('success' => false, 'message' => 'Publish failed: ' . $e->getMessage());
        }
    }

    /**
     * Resolve a strategy's approval mode from its stored config. 'none' (the
     * default) preserves the exact pre-Step-6 behavior — generate → completed →
     * maybe-auto-publish, no Approvals involvement at all.
     *
     * @param object $strategy Strategy DB row.
     * @return string 'none'|'internal'|'client'.
     */
    private static function approval_mode(object $strategy): string
    {
        if (empty($strategy->config)) {
            return 'none';
        }
        $cfg  = json_decode((string)$strategy->config, true);
        $mode = is_array($cfg) ? (string)($cfg['approvalMode'] ?? 'none') : 'none';
        return in_array($mode, array('internal', 'client'), true) ? $mode : 'none';
    }

    /**
     * Resolve a strategy ITEM's per-item override config (Task F1: per-item
     * overrides for templateId/publishingMode/approvalMode). Same json_decode
     * guard pattern as approval_mode()/hierarchy_config() above — malformed or
     * absent JSON degrades to an empty array (no overrides), never a fatal.
     *
     * Only consumed by the PER-ITEM generation path in generate_next_item() —
     * a consolidated (Step 8) strategy shares ONE article across every item, so
     * a single shared article can't honor conflicting per-item overrides; see
     * generate_consolidated_batch()'s docblock, which deliberately does not
     * call this.
     *
     * @param object $item Strategy item DB row.
     * @return array Decoded override config (empty array if none stored/invalid).
     */
    private static function item_config(object $item): array
    {
        if (empty($item->config)) {
            return array();
        }
        $cfg = json_decode((string)$item->config, true);
        return is_array($cfg) ? $cfg : array();
    }

    /**
     * AutoPress-parity featured image: generate one for a just-titled article,
     * ONLY when the strategy opted in (`config.featuredImages` truthy). Returns
     * null otherwise — and null on any generation failure too, since the whole
     * image path is failure-isolated (PCM_Strategy_Image::generate() never
     * throws; it error_log()s and returns null), so a missing/failed image can
     * never fail or delay the article that's being created around it.
     *
     * Provider/model come from the strategy's optional `config.imageProvider` /
     * `config.imageModel` (empty → the class's own defaults, openai/dall-e-3).
     *
     * @param object $strategy Strategy DB row (its config JSON opts image gen in).
     * @param string $title    The generated article title (drives the prompt).
     * @param string $keyword  The item's target keyword (drives the prompt).
     * @param int    $user_id  Owner ID — whose integration API key is used.
     * @return string|null Image URL, or null (opted out, or any failure).
     */
    private static function maybe_generate_featured_image(object $strategy, string $title, string $keyword, int $user_id): ?string
    {
        $cfg = !empty($strategy->config) ? json_decode((string)$strategy->config, true) : null;
        if (!is_array($cfg) || empty($cfg['featuredImages'])) {
            return null; // strategy didn't opt in
        }

        $prompt = sprintf(
            'Professional blog featured image for an article titled "%s" about %s — clean, modern, editorial photography, no text overlays.',
            $title,
            $keyword
        );

        if (!class_exists('PCM_Strategy_Image')) {
            require_once __DIR__ . '/class-pcm-strategy-image.php';
        }

        return PCM_Strategy_Image::generate(
            $prompt,
            $user_id,
            (string)($cfg['imageProvider'] ?? ''),
            (string)($cfg['imageModel'] ?? '')
        );
    }

    /**
     * Whether in-content images & charts (A6) are enabled for a strategy. Unlike
     * the featuredImages opt-in (default OFF — absent means off), this feature is
     * default ON: a missing/absent `config.inContentMedia` key — or no config at
     * all — is treated as ENABLED. Same json_decode guard pattern as
     * approval_mode()/maybe_generate_featured_image(); only an explicit falsey
     * value turns it off.
     *
     * @param object $strategy Strategy DB row.
     * @return bool True unless config explicitly disables it.
     */
    private static function in_content_media_enabled(object $strategy): bool
    {
        if (empty($strategy->config)) {
            return true; // no config → default ON
        }
        $cfg = json_decode((string)$strategy->config, true);
        if (!is_array($cfg) || !array_key_exists('inContentMedia', $cfg)) {
            return true; // key absent → default ON
        }
        return (bool)$cfg['inContentMedia'];
    }

    /**
     * A6 — turn the model's `media_assets` into in-content <figure> blocks
     * (AutoPress [IMAGE_N] parity). For each returned asset, its matching
     * [IMAGE_N] token in the article HTML is replaced with a
     * `<figure class="pcm-in-content-media">` wrapping either an AI-generated
     * image (type=image → PCM_Strategy_Image::generate(), reusing the strategy's
     * imageProvider/imageModel exactly like maybe_generate_featured_image()) or a
     * QuickChart-rendered chart (type=chart → a quickchart.io URL built from the
     * asset's chart_config; no API key). Capped at 3 assets.
     *
     * Fully guarded so no raw placeholder ever survives to the published article:
     *   - Disabled (config.inContentMedia falsey) or no assets → the article is
     *     returned unchanged EXCEPT that any [IMAGE_N] tokens are stripped.
     *   - An asset whose image generation returns null, or whose chart_config is
     *     empty/invalid, is dropped (its token falls through to the strip below).
     *   - Any leftover/unmatched [IMAGE_N] token (bare or in its own <p> wrapper)
     *     is stripped at the end.
     * Failure-isolated in spirit like the featured-image path: an image that
     * can't be generated simply doesn't appear.
     *
     * @param array  $article  The decoded article JSON (uses/updates 'content').
     * @param object $strategy Strategy DB row (opt-in flag + imageProvider/imageModel).
     * @param int    $user_id  Owner ID — whose integration API key is used.
     * @return array The article array with 'content' post-processed.
     */
    private static function maybe_generate_in_content_media(array $article, object $strategy, int $user_id): array
    {
        $content = (string)($article['content'] ?? '');

        $assets = (isset($article['media_assets']) && is_array($article['media_assets']))
            ? array_values($article['media_assets'])
            : array();

        // Skip path: disabled or nothing to place — strip any stray tokens so a
        // raw [IMAGE_N] can never reach the published article, then return.
        if (!self::in_content_media_enabled($strategy) || empty($assets)) {
            $article['content'] = self::strip_media_placeholders($content);
            return $article;
        }

        $cfg          = !empty($strategy->config) ? json_decode((string)$strategy->config, true) : null;
        $img_provider = is_array($cfg) ? (string)($cfg['imageProvider'] ?? '') : '';
        $img_model    = is_array($cfg) ? (string)($cfg['imageModel'] ?? '') : '';

        // Cap at 3 assets per article.
        foreach (array_slice($assets, 0, 3) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $placeholder = sanitize_text_field((string)($asset['placeholder'] ?? ''));
            if ($placeholder === '') {
                continue;
            }
            // Accept "IMAGE_1" or "[IMAGE_1]"; normalize to the token form.
            $token = '[' . trim($placeholder, '[]') . ']';
            $type  = (($asset['type'] ?? 'image') === 'chart') ? 'chart' : 'image';
            $desc  = (string)($asset['prompt'] ?? '');

            $src = '';
            if ($type === 'chart') {
                $chart_config = $asset['chart_config'] ?? null;
                // Strict-mode schemas deliver the config as a JSON STRING (free-form
                // objects aren't allowed there); fallback tiers / Gemini may still
                // hand back a real object — accept both.
                if (is_string($chart_config)) {
                    $chart_config = json_decode($chart_config, true);
                }
                if (!is_array($chart_config) || empty($chart_config)) {
                    continue; // invalid/empty chart — leave the token to be stripped
                }
                // QuickChart renders the Chart.js config to a static image URL; no
                // API key required (approved plan decision). Publish-time sideload
                // (sites/service.php) turns this into a local image on the client site.
                $src = 'https://quickchart.io/chart?w=800&h=450&c=' . rawurlencode(wp_json_encode($chart_config));
            } else {
                if (!class_exists('PCM_Strategy_Image')) {
                    require_once __DIR__ . '/class-pcm-strategy-image.php';
                }
                $generated = PCM_Strategy_Image::generate($desc, $user_id, $img_provider, $img_model);
                if ($generated === null || $generated === '') {
                    continue; // null return → drop this asset (token stripped below)
                }
                $src = (string)$generated;
            }

            // alt/caption: a short, sanitized slice of the asset's prompt.
            $caption = sanitize_text_field(substr($desc, 0, 120));
            $figure  = sprintf(
                '<figure class="pcm-in-content-media"><img src="%s" alt="%s" /><figcaption>%s</figcaption></figure>',
                esc_url($src),
                esc_attr($caption),
                esc_html($caption)
            );
            $content = self::replace_media_token($content, $token, $figure);
        }

        // Strip any leftover/unmatched placeholders so none reach the article.
        $article['content'] = self::strip_media_placeholders($content);
        return $article;
    }

    /**
     * Replace a single [IMAGE_N] placeholder token with its rendered figure. The
     * token may appear bare or wrapped in its own paragraph (`<p>[IMAGE_1]</p>`) —
     * when a `<p>` wraps ONLY the token (± whitespace) the whole wrapper is
     * replaced (a block <figure> inside a <p> is invalid HTML). Otherwise the bare
     * token is replaced in place. Only the FIRST occurrence is replaced.
     *
     * @param string $content     Article HTML.
     * @param string $token       The placeholder token, e.g. "[IMAGE_1]".
     * @param string $replacement The figure HTML to substitute in.
     * @return string
     */
    private static function replace_media_token(string $content, string $token, string $replacement): string
    {
        $wrapped = '#<p>\s*' . preg_quote($token, '#') . '\s*</p>#i';
        if (preg_match($wrapped, $content)) {
            return (string)preg_replace($wrapped, $replacement, $content, 1);
        }
        $pos = strpos($content, $token);
        if ($pos !== false) {
            return substr_replace($content, $replacement, $pos, strlen($token));
        }
        return $content;
    }

    /**
     * Strip every remaining [IMAGE_N] placeholder token from article HTML,
     * including a lone `<p>[IMAGE_N]</p>` wrapper (removed whole). Used both on
     * the skip path and to clear leftovers after replacement, so a raw token can
     * never survive to the published article.
     *
     * @param string $content Article HTML.
     * @return string
     */
    private static function strip_media_placeholders(string $content): string
    {
        $content = (string)preg_replace('#<p>\s*\[IMAGE_\d+\]\s*</p>#i', '', $content);
        $content = (string)preg_replace('#\[IMAGE_\d+\]#i', '', $content);
        return $content;
    }

    /**
     * Hand a freshly generated article off to the Approvals module (Decision 2 —
     * approval is handled BY the custom Approvals module, not reimplemented here).
     * Creates one set per item (not batched per strategy), in the same
     * `{media, copy, articles}` snapshot shape the Writer module already uses to
     * send an article to approval, so it renders on the existing approval-card UI
     * with no special-casing.
     *
     * Known limitation, same honesty as Step 3's frontend gap: 'client' mode does
     * not automatically email/share the set (PCM_Approvals_Service::share_set()
     * needs a client email address, and the create-strategy dialog captures none)
     * — it creates the same unshared set as 'internal' mode, distinguished only by
     * its name, and a human still shares it from the Approvals module UI. This is
     * consistent with Decision 2 (sharing IS an Approvals-module action) rather
     * than a silently-dropped requirement.
     *
     * @param object $strategy      Strategy DB row.
     * @param object $item          Strategy item DB row (for its keyword, in the name).
     * @param int    $article_id    Newly created article ID.
     * @param int    $user_id       Owner ID.
     * @param string $approval_mode 'internal'|'client'.
     * @return int|null New approval set ID, or null if the article/creation failed.
     */
    private static function create_approval_set_for_item(object $strategy, object $item, int $article_id, int $user_id, string $approval_mode): ?int
    {
        $article = PCM_DB::get_article($article_id, $user_id);
        if (!$article) {
            return null;
        }
        if (!class_exists('PCM_Approvals_Service')) {
            require_once dirname(__DIR__) . '/approvals/service.php';
        }

        $label = $approval_mode === 'client' ? 'Client Review' : 'Internal Review';
        // Client-facing sets get a client-safe name (just the article title) --
        // the internal strategy name + target SEO keyword must not be exposed to
        // a client who opens the (unauthenticated, token-scoped) review link.
        // Internal-only sets keep the more useful strategy+keyword identifier for
        // the team's own approval-queue dashboard.
        $name = $approval_mode === 'client'
            ? sprintf('%s (%s)', (string)$article->title, $label)
            : sprintf('%s — %s (%s)', (string)$strategy->name, (string)$item->keyword, $label);
        $set_id = PCM_Approvals_Service::create_set($user_id, array(
            'name'     => $name,
            'brandId'  => !empty($strategy->brandId) ? (int)$strategy->brandId : null,
            'snapshot' => array(
                'media'    => array(),
                'copy'     => array(),
                'articles' => array(array(
                    'id'              => (int)$article->id,
                    'title'           => $article->title,
                    'slug'            => $article->slug,
                    'content'         => $article->content,
                    'metaTitle'       => $article->metaTitle,
                    'metaDescription' => $article->metaDescription,
                    'schemaType'      => $article->schemaType,
                    'status'          => $article->status,
                    'featuredImage'   => $article->featuredImage ?? null,
                )),
            ),
        ));

        return $set_id ?: null;
    }

    /**
     * Advance a strategy item out of review once its linked approval set is fully
     * approved (Step 7 — reacts to the Approvals module's `approvals.set_fully_
     * approved` trigger via the action handler in strategy/automations.php).
     * Auto-publishes if the strategy's publishingMode is 'publish' — this is the
     * FIRST point a Step-6-gated article is allowed to publish; maybe_auto_publish()
     * is deliberately never called during generation once an approval gate is set.
     *
     * Returns null (a clean no-op, not an error) when the set isn't linked to a
     * strategy item at all — e.g. a Copy/Image/Writer approval set unrelated to
     * this module — or when the linked item isn't currently 'in_review' (already
     * advanced by an earlier delivery of the same trigger; re-entry must not
     * re-publish).
     *
     * @param int $set_id  Approval set ID from the trigger context.
     * @param int $user_id Set owner (== strategy/item owner).
     * @return array|null {item, publish} on success, null on no-op.
     */
    public static function advance_item_on_approval(int $set_id, int $user_id): ?array
    {
        $item = PCM_DB::get_strategy_item_by_set_id($set_id, $user_id);
        if (!$item || (string)$item->status !== 'in_review') {
            return null;
        }

        // Atomically claim the item BEFORE publishing (security-review finding,
        // P2): the Approvals module can deliver `set_fully_approved` more than
        // once for the same set under concurrent requests (e.g. a client
        // double-clicking "approve all"). The claim is a single UPDATE gated on
        // `status = 'in_review'` — only one concurrent caller can win it. A
        // losing caller must skip publish entirely, not just skip the status
        // write, or the race still produces a duplicate live post.
        if (!PCM_DB::advance_strategy_item_from_in_review((int)$item->id)) {
            return null;
        }

        $strategy = PCM_DB::get_strategy((int)$item->strategyId, $user_id);
        if (!$strategy) {
            return null;
        }

        $article = !empty($item->articleId) ? PCM_DB::get_article((int)$item->articleId, $user_id) : null;
        $publish = self::maybe_auto_publish($strategy, $article, $user_id);

        self::recompute_counters((int)$strategy->id, $user_id, (int)$strategy->totalItems);

        return array('item' => $item, 'publish' => $publish);
    }

    /**
     * Recompute a strategy's completed/failed counters and status from the actual
     * item states. Robust to retries — a re-generated item moving error → completed
     * is reflected correctly instead of double-counting an incrementing counter.
     *
     * @param int $strategy_id Strategy ID.
     * @param int $user_id     Owner ID.
     * @param int $total       Total item count (from the strategy row).
     */
    public static function recompute_counters(int $strategy_id, int $user_id, int $total): void
    {
        $completed = 0;
        $failed    = 0;
        foreach (PCM_DB::get_strategy_items($strategy_id) as $it) {
            if ($it->status === 'completed')  { $completed++; }
            elseif ($it->status === 'error')  { $failed++; }
        }
        $status = ($total > 0 && $completed >= $total)
            ? 'completed'
            : (($completed + $failed) > 0 ? 'in_progress' : 'pending');

        // Step 10: this is the single choke point every completion path already
        // runs through (the per-item flow, the consolidated batch, and Step 7's
        // advance_item_on_approval()) — the natural place to detect the ONE-TIME
        // transition into 'completed' and fire interlink injection, without a new
        // DB column to track "already injected." Read the PRE-update status so
        // a strategy that's already completed isn't re-injected on every
        // subsequent recompute call (e.g. a later manual reset-and-regenerate of
        // one item still calls this method).
        $previous = PCM_DB::get_strategy($strategy_id, $user_id);
        $was_completed = $previous && (string)($previous->status ?? '') === 'completed';

        // D2: a paused strategy stays paused across counter recomputes — an item
        // completing/erroring under a pause must not silently resume the strategy
        // by flipping it back to 'in_progress'/'pending'. Only a FULLY-completed
        // batch escapes the pause (→ 'completed'); everything else keeps 'paused'.
        if ($previous && (string)($previous->status ?? '') === 'paused'
            && in_array($status, array('in_progress', 'pending'), true)) {
            $status = 'paused';
        }

        PCM_DB::update_strategy($strategy_id, $user_id, array(
            'completedItems' => $completed,
            'failedItems'    => $failed,
            'status'         => $status,
        ));

        if (!$was_completed && $status === 'completed') {
            self::finalize_on_completion($strategy_id, $user_id);
        }
    }

    /**
     * Everything that should happen exactly once, the moment a strategy's
     * status transitions into 'completed' (architect-review note: keeps
     * recompute_counters()'s own contract honest — "recompute two integers and
     * a status enum" — by naming this side effect its own seam, rather than
     * burying it as an anonymous inline call). Currently just interlink
     * injection (Step 10); a natural place to add future completion-only work.
     *
     * @param int $strategy_id Strategy ID.
     * @param int $user_id     Owner ID.
     */
    private static function finalize_on_completion(int $strategy_id, int $user_id): void
    {
        $result = self::maybe_inject_interlinks($strategy_id, $user_id);
        unset($result); // only ['injected'] matters here, and even that is unused -- see Step 10 above

        // Task E3: notify once a strategy finishes generating. Guarded twice —
        // class_exists() AND try/catch — because the unit-test environment
        // (StrategyLifecycleTest, StrategyAutoPublishTest) loads this service
        // standalone with no PCM_Automation_Engine at all; a fatal here must
        // never be able to break the completion path itself.
        if (class_exists('PCM_Automation_Engine')) {
            try {
                $strategy = PCM_DB::get_strategy($strategy_id, $user_id);
                PCM_Automation_Engine::fire_trigger(
                    'strategy.completed',
                    array(
                        'strategyId'     => $strategy_id,
                        'name'           => $strategy ? (string)$strategy->name : null,
                        'completedItems' => $strategy ? (int)$strategy->completedItems : null,
                        'totalItems'     => $strategy ? (int)$strategy->totalItems : null,
                    ),
                    $user_id
                );
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[PCM_Strategy_Service] strategy.completed trigger failed for strategy #%d: %s',
                    $strategy_id,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Resolve a strategy's interlinks config (mode/quantity, Step 10) from its
     * stored config JSON.
     *
     * @param object $strategy Strategy DB row.
     * @return array {mode, quantity} — empty/zero when not configured.
     */
    private static function interlinks_config(object $strategy): array
    {
        if (empty($strategy->config)) {
            return array();
        }
        $cfg = json_decode((string)$strategy->config, true);
        return (is_array($cfg) && is_array($cfg['interlinksConfig'] ?? null)) ? $cfg['interlinksConfig'] : array();
    }

    /**
     * Best-effort URL for a generated article — prefer its real publishedUrl,
     * otherwise construct one from the strategy's configured site + slug.
     * Shared by Step 9 (parent link) and Step 10 (interlinks); a guess, not a
     * guarantee, exactly like the AutoPress reference this was ported from.
     *
     * @param object      $article Article DB row.
     * @param object|null $site    Configured site (nullable).
     * @return string
     */
    private static function resolve_article_url(object $article, ?object $site): string
    {
        if (!empty($article->publishedUrl)) {
            return (string)$article->publishedUrl;
        }
        $slug = ltrim((string)($article->slug ?? ''), '/');
        return $site ? rtrim((string)$site->url, '/') . '/' . $slug : '/' . $slug;
    }

    /**
     * Whether a byte offset in an HTML string is an unsafe place to inject a
     * new `<a>` — used by maybe_inject_interlinks() to skip a keyword match
     * there and try a later occurrence instead. Two unsafe cases:
     *   - Inside a tag's own markup (between an unmatched `<` and its `>`) —
     *     e.g. a tag name or an attribute value.
     *   - Inside an EXISTING anchor's rendered text (more open `<a ...>` tags
     *     than `</a>` closes before this offset) — wrapping there would nest
     *     one anchor inside another, which is invalid HTML. This is the
     *     realistic case in practice: Step 9's own parent-link paragraph's
     *     anchor text is frequently a keyword that Step 10 would otherwise
     *     re-wrap.
     *
     * @param string $content Full HTML string.
     * @param int    $offset  Byte offset to check.
     * @return bool
     */
    private static function is_inside_html_tag(string $content, int $offset): bool
    {
        $before = substr($content, 0, $offset);

        $last_open  = strrpos($before, '<');
        $last_close = strrpos($before, '>');
        if ($last_open !== false && ($last_close === false || $last_open > $last_close)) {
            return true;
        }

        $open_anchors   = preg_match_all('/<a\b[^>]*>/i', $before);
        $closed_anchors = preg_match_all('#</a>#i', $before);
        return $open_anchors > $closed_anchors;
    }

    /**
     * Inject up to `quantity` phrase-matched internal links between this
     * strategy's own generated articles (Step 10/Decision 3), once the whole
     * batch finishes (called from recompute_counters() on the one-time
     * transition into 'completed' — see the caller for why there).
     *
     * No existing "SEO link-rewrite" utility was found elsewhere in this
     * codebase to reuse (searched; nothing under includes/ or app/src/ besides
     * this module's own new code) — this is a small, purpose-built,
     * dependency-free implementation, not the AI-assisted/DOM-aware surgical
     * injector in the AutoPress reference (which needs an extra LLM call per
     * anchor and an HTML-position-indexing utility this plan didn't call for).
     * "Phrase-match" here means: literally search each OTHER completed item's
     * own keyword text within this article's raw content and wrap the FIRST
     * occurrence in an `<a>` tag — deterministic and directly testable, per
     * Decision 3 ("no new deps").
     *
     * Known limitation, documented rather than silently accepted: this is a
     * plain string search on raw HTML, not a full HTML parser. Two guards keep
     * it safe in practice rather than skipping them: (1) a candidate is skipped
     * once its target URL is already present anywhere in the content (re-run
     * idempotency — compared against the SAME escaped form actually injected,
     * not the raw URL, so a URL with special characters can't defeat this), and
     * (2) is_inside_html_tag() above skips any keyword occurrence that falls
     * inside an existing tag/attribute (e.g. Step 9's own parent-link anchor
     * text), trying subsequent occurrences instead of blindly wrapping the
     * first one found.
     *
     * $options (all optional):
     *   - quantity            int   Per-source insert cap. Falls back to the
     *                                strategy's stored interlinksConfig quantity
     *                                (0/absent = feature not configured -> no-op).
     *                                Callers that want a hard default when the
     *                                strategy has no config (the manual "run now"
     *                                button) set this explicitly -- see
     *                                run_interlinks() below.
     *   - maxLinksPerArticle  int   Cap on INBOUND links a single target URL may
     *                                receive across this whole run (default 2).
     *   - manualRules         array  {keyword, url, matchType: phrase|exact}[].
     *                                When non-empty, REPLACES the auto candidate
     *                                list (every OTHER completed item's own
     *                                keyword) with this fixed rule set for every
     *                                source article.
     *   - aiAnchors           bool  When truthy AND a target keyword has no safe
     *                                verbatim occurrence, ask the LLM (one call
     *                                per source/target pair) for an existing
     *                                short phrase already in the article to wrap
     *                                instead -- re-validated through the SAME
     *                                deterministic rails. Any LLM failure or
     *                                unsafe answer degrades to the plain
     *                                'no safe occurrence' skip (never fails the
     *                                run). Absent -> false.
     *
     * @param int   $strategy_id Strategy ID.
     * @param int   $user_id     Owner ID.
     * @param array $options     See above.
     * @return array{injected: int, results: array<int, array{source: string, target: string, status: string, reason: string}>}
     */
    private static function maybe_inject_interlinks(int $strategy_id, int $user_id, array $options = array()): array
    {
        $empty_result = array('injected' => 0, 'results' => array());

        $strategy = PCM_DB::get_strategy($strategy_id, $user_id);
        if (!$strategy) {
            return $empty_result;
        }
        $cfg = self::interlinks_config($strategy);
        $quantity = array_key_exists('quantity', $options) && $options['quantity'] !== null
            ? (int)$options['quantity']
            : (!empty($cfg['quantity']) ? (int)$cfg['quantity'] : 0);
        if ($quantity <= 0) {
            return $empty_result; // not configured, or explicitly set to 0 links
        }

        // When truthy, a target keyword with no safe verbatim occurrence falls
        // back to an LLM-picked existing phrase (see pick_ai_anchor() below),
        // re-validated through the same rails. Absent -> false.
        $ai_anchors = !empty($options['aiAnchors']);

        $max_links_per_article = array_key_exists('maxLinksPerArticle', $options) && $options['maxLinksPerArticle'] !== null
            ? (int)$options['maxLinksPerArticle']
            : 2;

        $manual_rules = !empty($options['manualRules']) && is_array($options['manualRules']) ? $options['manualRules'] : array();

        $items = array_values(array_filter(
            PCM_DB::get_strategy_items($strategy_id),
            static fn($it) => $it->status === 'completed' && !empty($it->articleId)
        ));
        if (count($items) < 2) {
            return $empty_result; // need at least 2 articles to link between
        }

        $site_id = !empty($cfg['siteId']) ? (int)$cfg['siteId'] : 0;
        if (empty($site_id) && !empty($strategy->config)) {
            $strategy_cfg = json_decode((string)$strategy->config, true);
            $site_id = is_array($strategy_cfg) && !empty($strategy_cfg['siteId']) ? (int)$strategy_cfg['siteId'] : 0;
        }
        $site = $site_id > 0 ? PCM_DB::get_site($site_id, $user_id) : null;

        // Pre-resolve every candidate's article + URL once.
        $candidates = array();
        foreach ($items as $it) {
            $article = PCM_DB::get_article((int)$it->articleId, $user_id);
            if (!$article) {
                continue;
            }
            $candidates[] = array(
                'itemId'  => (int)$it->id,
                'keyword' => (string)$it->keyword,
                'article' => $article,
                'url'     => self::resolve_article_url($article, $site),
            );
        }

        // Manual rules, when present, are a FIXED target list shared by every
        // source article -- they replace the "other items' own keywords" auto
        // candidates entirely. itemId 0 is a sentinel that can never equal a
        // real source itemId, so the self-link guard below still applies.
        $manual_targets = array();
        foreach ($manual_rules as $rule) {
            $keyword = (string)($rule['keyword'] ?? '');
            $url     = (string)($rule['url'] ?? '');
            if ($keyword === '' || $url === '') {
                continue;
            }
            $manual_targets[] = array(
                'itemId'    => 0,
                'keyword'   => $keyword,
                'url'       => $url,
                'matchType' => ($rule['matchType'] ?? 'phrase') === 'exact' ? 'exact' : 'phrase',
            );
        }
        $auto_targets = array_map(static fn($c) => array(
            'itemId'    => $c['itemId'],
            'keyword'   => $c['keyword'],
            'url'       => $c['url'],
            'matchType' => 'phrase',
        ), $candidates);
        $targets = !empty($manual_targets) ? $manual_targets : $auto_targets;

        $total_injected = 0;
        $results        = array();
        $target_counts  = array(); // target URL => inbound links injected so far this run

        foreach ($candidates as $source) {
            $content       = (string)($source['article']->content ?? '');
            $injected      = 0;
            $source_label  = $source['keyword'] !== '' ? $source['keyword'] : (string)($source['article']->title ?? '');
            $result_marker = count($results);

            foreach ($targets as $target) {
                if ($injected >= $quantity) {
                    break;
                }
                $target_label = $target['keyword'];

                if ($target['itemId'] === $source['itemId'] || $target['url'] === $source['url']) {
                    $results[] = array('source' => $source_label, 'target' => $target_label, 'status' => 'skipped', 'reason' => 'self');
                    continue; // never self-link
                }

                // Compare against the SAME escaped form actually injected below —
                // comparing against the raw URL here would let a target URL with
                // special characters (e.g. "&" in a query string, which esc_url()
                // entity-encodes) defeat this guard and re-inject on every re-run.
                $escaped_url = esc_url($target['url']);
                if ($target['keyword'] === '' || strpos($content, $escaped_url) !== false) {
                    $results[] = array('source' => $source_label, 'target' => $target_label, 'status' => 'skipped', 'reason' => 'already linked');
                    continue; // already linked to this target -- skip (idempotent re-run)
                }

                if (($target_counts[$target['url']] ?? 0) >= $max_links_per_article) {
                    $results[] = array('source' => $source_label, 'target' => $target_label, 'status' => 'skipped', 'reason' => 'target at cap');
                    continue;
                }

                // Find the first occurrence that is NOT inside an existing tag or
                // attribute (e.g. the anchor text of Step 9's own parent-link
                // paragraph, or a URL) — a plain preg_replace(limit=1) would happily
                // wrap a match anywhere, including inside markup, producing invalid
                // nested/broken anchors once persisted. 'exact' matchType is
                // case-SENSITIVE (no /i flag); 'phrase' (default/auto) stays
                // case-insensitive, matching the pre-existing behavior.
                $flags  = $target['matchType'] === 'exact' ? '' : 'i';
                $safe   = self::find_safe_occurrence($content, $target['keyword'], $flags);
                $reason = 'ok';

                // AI-assisted anchor fallback (AutoPress injectLinkSurgically
                // allowAi parity): the keyword itself has no safe occurrence, so
                // ask the LLM (exactly one call per source/target pair) for an
                // existing short phrase already in the article to wrap instead,
                // then re-validate that phrase through the SAME deterministic
                // rails (case-SENSITIVE verbatim scan + is_inside_html_tag). Any
                // failure or unsafe answer falls through to the skip below -- the
                // LLM is never retried, and a throw never fails the run.
                if ($safe === null && $ai_anchors) {
                    $ai_anchor = self::pick_ai_anchor($content, $target['keyword'], $user_id);
                    if ($ai_anchor !== null) {
                        $ai_safe = self::find_safe_occurrence($content, $ai_anchor, '');
                        if ($ai_safe !== null) {
                            $safe   = $ai_safe;
                            $reason = 'ai anchor';
                        }
                    }
                }

                if ($safe === null) {
                    $results[] = array('source' => $source_label, 'target' => $target_label, 'status' => 'skipped', 'reason' => 'no safe occurrence');
                    continue; // no keyword occurrence (or all inside markup), and no safe AI anchor
                }

                list($safe_text, $safe_offset) = $safe;
                $anchor  = '<a href="' . $escaped_url . '">' . $safe_text . '</a>';
                $content = substr_replace($content, $anchor, $safe_offset, strlen($safe_text));
                $injected++;
                $target_counts[$target['url']] = ($target_counts[$target['url']] ?? 0) + 1;
                $results[] = array('source' => $source_label, 'target' => $target_label, 'status' => 'injected', 'reason' => $reason);
            }

            if ($injected > 0) {
                $wrote = PCM_DB::update_article((int)$source['article']->id, $user_id, array('content' => $content));
                if ($wrote) {
                    $total_injected += $injected;
                } else {
                    // Write failed -- downgrade this source's 'injected' rows to
                    // 'failed' rather than reporting links that were never persisted.
                    for ($i = $result_marker, $n = count($results); $i < $n; $i++) {
                        if ($results[$i]['status'] === 'injected') {
                            $results[$i]['status'] = 'failed';
                            $results[$i]['reason'] = 'write error';
                        }
                    }
                }
            }
        }

        return array('injected' => $total_injected, 'results' => $results);
    }

    /**
     * Scan $content for the FIRST occurrence of $needle that is not inside
     * existing HTML markup (per is_inside_html_tag()) — the shared deterministic
     * rail used both for exact keyword matches and for re-validating an
     * AI-suggested anchor. Case-sensitivity follows $flags ('' = sensitive,
     * 'i' = insensitive), exactly like the original inline scan.
     *
     * @param string $content Full HTML string to search.
     * @param string $needle  Literal phrase to locate (matched via preg_quote).
     * @param string $flags   Regex modifier flags ('' or 'i').
     * @return array{0: string, 1: int}|null [matched text, byte offset] or null
     *                                         when there is no safe occurrence.
     */
    private static function find_safe_occurrence(string $content, string $needle, string $flags): ?array
    {
        if ($needle === '') {
            return null;
        }
        $pattern = '/' . preg_quote($needle, '/') . '/' . $flags;
        if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        foreach ($matches[0] as $match) {
            if (!self::is_inside_html_tag($content, $match[1])) {
                return array($match[0], $match[1]);
            }
        }
        return null; // every occurrence sat inside existing markup
    }

    /**
     * AI-assisted anchor fallback (AutoPress injectLinkSurgically allowAi
     * parity): when a target keyword has no safe verbatim occurrence, ask the
     * LLM for the single best SHORT existing phrase already present in the
     * article to hyperlink for the given topic. Returns the trimmed phrase, or
     * null on any throwable or empty answer — the caller re-validates it through
     * find_safe_occurrence() and keeps the plain 'no safe occurrence' skip if it
     * can't be safely located. NEVER throws: an LLM failure must not fail the
     * interlink run. Exactly one LLM call per invocation (one per source/target
     * pair, never retried).
     *
     * @param string $content        Source article's raw HTML content.
     * @param string $target_keyword Topic the internal link should point at.
     * @param int    $user_id        Owner ID, forwarded for LLM accounting.
     * @return string|null Trimmed anchor phrase, or null.
     */
    private static function pick_ai_anchor(string $content, string $target_keyword, int $user_id): ?string
    {
        try {
            $text     = substr(strip_tags($content), 0, 6000);
            $messages = array(
                array('role' => 'system', 'content' => 'You select anchor text for internal links.'),
                array('role' => 'user', 'content' => 'From the ARTICLE TEXT below, return the single best short existing phrase (2-6 words, verbatim from the text, outside of HTML tags) to hyperlink for the topic "' . $target_keyword . '". ARTICLE TEXT: ' . $text),
            );
            // Full json_schema wrapper ({name, schema}) — invoke_json() forwards
            // this verbatim as response_format.json_schema, and OpenAI 400s
            // without the name (a bare schema silently disabled AI anchors on
            // OpenAI-format keys; caught by the real-API smoke).
            $schema = array(
                'name'   => 'anchor_pick',
                'schema' => array(
                    'type'       => 'object',
                    'properties' => array('anchor' => array('type' => 'string')),
                    'required'   => array('anchor'),
                ),
            );
            $result = PCM_LLM::invoke_json($messages, $schema, array('max_tokens' => 256, 'user_id' => $user_id));
            $anchor = is_array($result) ? trim((string)($result['anchor'] ?? '')) : '';
            return $anchor !== '' ? $anchor : null;
        } catch (\Throwable $e) {
            return null; // any LLM failure -> no AI anchor, run continues
        }
    }

    /**
     * Manually run interlink injection for a strategy (the Strategies page's
     * "Interlinks" button). Unlike the automatic on-completion run, this is an
     * explicit user request — so when the strategy has no interlinksConfig (or
     * a zero quantity), a sensible default cap is used instead of silently
     * doing nothing (3, matching the create dialog's default).
     *
     * @param int   $strategy_id Strategy ID (ownership verified by the caller/controller).
     * @param int   $user_id     Owner ID.
     * @param array $options     Optional overrides -- see maybe_inject_interlinks()'s
     *                            docblock for the full set of keys (quantity,
     *                            maxLinksPerArticle, manualRules, aiAnchors). A
     *                            caller-supplied 'quantity' wins; otherwise this
     *                            falls back to the strategy's configured quantity,
     *                            or 3 if unconfigured (unlike the automatic
     *                            on-completion run, this is an explicit user
     *                            request, so it shouldn't silently no-op).
     * @return array{injected: int, results: array<int, array{source: string, target: string, status: string, reason: string}>}
     */
    public static function run_interlinks(int $strategy_id, int $user_id, array $options = array()): array
    {
        $strategy = PCM_DB::get_strategy($strategy_id, $user_id);
        if (!$strategy) {
            return array('injected' => 0, 'results' => array());
        }
        if (!array_key_exists('quantity', $options) || $options['quantity'] === null) {
            $cfg = self::interlinks_config($strategy);
            $options['quantity'] = !empty($cfg['quantity']) ? (int)$cfg['quantity'] : 3;
        }
        return self::maybe_inject_interlinks($strategy_id, $user_id, $options);
    }

    /**
     * Publish ONE completed item's article to the strategy's configured site —
     * the retro-publish path for articles generated in draft mode (or before a
     * Target Site was set). Reuses the exact publish pipeline auto-publish uses
     * (PCM_Sites_Service::publish_to_site), so publishedUrl/status land on the
     * article identically and the "View on site" link lights up via the items
     * JOIN. The item MUST belong to the given strategy — never trust a bare
     * itemId (mirrors reset_item_to_pending()'s ownership pattern).
     *
     * @param object $strategy Strategy DB row (already ownership-verified by the controller).
     * @param int    $item_id  Item whose article to publish.
     * @param int    $user_id  Owner ID.
     * @return array{success:bool,message:string,postUrl?:string}
     * @throws \RuntimeException If the item isn't in this strategy, isn't
     *   completed, has no article, or no Target Site is configured.
     */
    public static function publish_item(object $strategy, int $item_id, int $user_id): array
    {
        $item = null;
        foreach (PCM_DB::get_strategy_items((int)$strategy->id) as $candidate) {
            if ((int)$candidate->id === $item_id) {
                $item = $candidate;
                break;
            }
        }
        if (!$item) {
            throw new \RuntimeException('Item not found in this strategy.');
        }
        if ((string)$item->status !== 'completed' || empty($item->articleId)) {
            throw new \RuntimeException('Only a completed item with a generated article can be published.');
        }

        $site_id = 0;
        if (!empty($strategy->config)) {
            $cfg = json_decode((string)$strategy->config, true);
            if (is_array($cfg) && !empty($cfg['siteId'])) {
                $site_id = (int)$cfg['siteId'];
            }
        }
        if ($site_id <= 0) {
            throw new \RuntimeException('No Target Site is set on this strategy — select one first.');
        }

        $site = PCM_DB::get_site($site_id, $user_id);
        if (!$site) {
            throw new \RuntimeException('The configured site is no longer connected.');
        }
        $article = PCM_DB::get_article((int)$item->articleId, $user_id);
        if (!$article) {
            throw new \RuntimeException('The generated article could not be loaded.');
        }

        // Idempotency guard (done-gate finding): the UI hides the Publish button
        // once published, but the ENDPOINT must enforce it too — a direct API
        // call (or two racing requests) would otherwise create duplicate live
        // posts on the connected site, since publish_to_site() always creates a
        // fresh post. Already published → report success with the existing URL.
        if (!empty($article->publishedUrl)) {
            return array(
                'success' => true,
                'message' => 'Already published.',
                'postUrl' => (string)$article->publishedUrl,
            );
        }

        if (!class_exists('PCM_Sites_Service')) {
            require_once dirname(__DIR__) . '/sites/service.php';
        }

        $publish_options = array('tags' => array((string)$item->keyword));
        // D4: manually publishing a scheduled item that ISN'T DUE YET (its
        // scheduledDate is still in the future) creates a native WP 'future'
        // post instead of publishing immediately — AutoPress parity. A due/past
        // date (or any non-'schedule' publishingMode) publishes normally, as before.
        if ((string)($strategy->publishingMode ?? '') === 'schedule' && !empty($item->scheduledDate)) {
            $due_ts = strtotime((string)$item->scheduledDate);
            if ($due_ts !== false && $due_ts > strtotime(current_time('mysql'))) {
                $publish_options['schedule_date'] = (string)$item->scheduledDate;
            }
        }

        $result = PCM_Sites_Service::publish_to_site($site, $article, $user_id, $publish_options);

        return array(
            'success' => true,
            'message' => 'Published to ' . (string)($site->name ?: $site->url) . '.',
            'postUrl' => (string)($result['postUrl'] ?? ''),
        );
    }

    /**
     * D3 — pull each item's linked article status from the remote WordPress site
     * and reconcile our stored record with reality (a post deleted directly on
     * WordPress must stop showing as "published" here). Only items with an
     * articleId whose article actually carries a `publishedPostId` + `siteId`
     * are checked — nothing to sync otherwise.
     *
     * Per item:
     *   - remote 'deleted' → clear the article's publishedUrl/publishedPostId
     *     (PCM_DB::update_article) so the item shows unpublished again.
     *   - remote 'future'  → left as-is (still scheduled on WordPress' side).
     *   - remote 'publish' → refresh publishedUrl from the remote's `link` when
     *     it changed.
     *   - fetch failure (null) → skipped entirely (unknown — do nothing).
     *
     * Ownership-scoped throughout: the site is loaded via PCM_DB::get_site()
     * ($user_id-scoped), so a foreign siteId on a stale article never resolves.
     *
     * @param object $strategy Strategy DB row (already ownership-verified by the controller).
     * @param int    $user_id  Owner ID.
     * @return array{checked:int,updated:int,deleted:int}
     */
    public static function sync_items_from_wp(object $strategy, int $user_id): array
    {
        $checked = 0;
        $updated = 0;
        $deleted = 0;

        if (!class_exists('PCM_Sites_Service')) {
            require_once dirname(__DIR__) . '/sites/service.php';
        }

        foreach (PCM_DB::get_strategy_items((int)$strategy->id) as $item) {
            if (empty($item->articleId)) {
                continue;
            }
            $article = PCM_DB::get_article((int)$item->articleId, $user_id);
            if (!$article || empty($article->publishedPostId) || empty($article->siteId)) {
                continue;
            }
            $site = PCM_DB::get_site((int)$article->siteId, $user_id);
            if (!$site) {
                continue;
            }

            $checked++;
            try {
                $remote = PCM_Sites_Service::fetch_remote_post_status($site, (int)$article->publishedPostId);
            } catch (\Throwable $e) {
                $remote = null;
            }
            if ($remote === null) {
                continue; // unknown — do nothing
            }

            $remote_status = (string)($remote['status'] ?? '');
            if ($remote_status === 'deleted') {
                PCM_DB::update_article((int)$article->id, $user_id, array(
                    'publishedUrl'    => '',
                    'publishedPostId' => null,
                ));
                $deleted++;
                $updated++;
                continue;
            }
            if ($remote_status === 'future') {
                continue; // still scheduled on WordPress' side — leave as-is
            }
            if ($remote_status === 'publish') {
                $link = (string)($remote['link'] ?? '');
                if ($link !== '' && $link !== (string)($article->publishedUrl ?? '')) {
                    PCM_DB::update_article((int)$article->id, $user_id, array('publishedUrl' => $link));
                    $updated++;
                }
            }
        }

        return array('checked' => $checked, 'updated' => $updated, 'deleted' => $deleted);
    }

    /**
     * Recompute due dates for a strategy's PENDING items from a (changed)
     * frequency/start date — the Strategies row's inline Frequency select
     * (AutoPress parity: editing the schedule after creation). Completed/
     * errored/generating items keep their history; only not-yet-generated
     * items are redistributed, in position order.
     *
     * @param int    $strategy_id Strategy ID (ownership verified by the caller).
     * @param string $frequency   calculate_schedule_dates() frequency key.
     * @param string $start_date  strtotime()-parseable start ('' → now).
     * @return int Items rescheduled.
     */
    public static function reschedule_pending_items(int $strategy_id, string $frequency, string $start_date = ''): int
    {
        $pending = array_values(array_filter(
            PCM_DB::get_strategy_items($strategy_id),
            static fn($it) => $it->status === 'pending'
        ));
        if (empty($pending)) {
            return 0;
        }
        $start = $start_date !== '' ? $start_date : current_time('mysql');
        $dates = self::calculate_schedule_dates(count($pending), $frequency, $start);
        foreach ($pending as $i => $item) {
            PCM_DB::update_strategy_item((int)$item->id, array('scheduledDate' => $dates[$i] ?? end($dates)));
        }
        return count($pending);
    }

    /**
     * Set one PENDING item's due date (the item-row inline date picker —
     * AutoPress parity). Restricted to pending items: a completed/errored item
     * has already run, and a generating one is live work.
     *
     * @param int    $strategy_id Strategy ID (ownership verified by the caller).
     * @param int    $item_id     Item to (re)schedule.
     * @param int    $user_id     Owner ID (unused for writes, kept for symmetry/logging).
     * @param string $date        strtotime()-parseable date.
     * @return string The normalized stored date (Y-m-d H:i:s).
     * @throws \RuntimeException On foreign item, non-pending status, or unparseable date.
     */
    public static function set_item_scheduled_date(int $strategy_id, int $item_id, int $user_id, string $date): string
    {
        $item = null;
        foreach (PCM_DB::get_strategy_items($strategy_id) as $candidate) {
            if ((int)$candidate->id === $item_id) {
                $item = $candidate;
                break;
            }
        }
        if (!$item) {
            throw new \RuntimeException('Item not found in this strategy.');
        }
        if ((string)$item->status !== 'pending') {
            throw new \RuntimeException("Only a 'pending' item can be rescheduled.");
        }
        $ts = strtotime($date);
        if ($ts === false) {
            throw new \RuntimeException('Unrecognized date.');
        }
        $normalized = date('Y-m-d H:i:s', $ts);
        PCM_DB::update_strategy_item($item_id, array('scheduledDate' => $normalized));
        return $normalized;
    }

    /**
     * Set (REPLACE, not merge) one item's per-item override config (Task F1/F2:
     * templateId/publishingMode/approvalMode overrides — see item_config() and
     * generate_next_item()'s per-item resolution above). REPLACE semantics —
     * deliberately NOT a partial merge like the strategy-level
     * merge_strategy_config() — because the frontend's inline overrides row
     * (Task F2) always sends the FULL desired override set for these 3 keys
     * (only non-"Inherit" keys present); 'Inherit' is expressed by simply
     * omitting a key, and REPLACE naturally drops it from storage the moment
     * the user switches a Select back to Inherit. A merge would need an
     * explicit "clear" sentinel to ever remove a previously-set override —
     * unneeded complexity for 3 keys.
     *
     * @param int   $strategy_id Strategy ID (ownership verified by the caller).
     * @param int   $item_id     Item to configure — MUST belong to $strategy_id.
     * @param int   $user_id     Owner ID (unused for writes; kept for symmetry with sibling methods).
     * @param array $config      Already-sanitized override fields (only the keys to keep — see the controller's whitelist).
     * @return array Refreshed strategy items.
     * @throws \RuntimeException If the item isn't in this strategy.
     */
    public static function set_item_config(int $strategy_id, int $item_id, int $user_id, array $config): array
    {
        unset($user_id); // symmetry with set_item_scheduled_date() above — kept for future use/logging
        $item = null;
        foreach (PCM_DB::get_strategy_items($strategy_id) as $candidate) {
            if ((int)$candidate->id === $item_id) {
                $item = $candidate;
                break;
            }
        }
        if (!$item) {
            throw new \RuntimeException('Item not found in this strategy.');
        }

        PCM_DB::update_strategy_item($item_id, array(
            'config' => !empty($config) ? wp_json_encode($config) : null,
        ));

        return PCM_DB::get_strategy_items($strategy_id);
    }

    /**
     * Delete one item from a strategy (AutoPress parity: per-item delete).
     * The generated article, if any, is left untouched in Writer — deleting
     * the work-queue row must not destroy content. A 'generating' item is
     * refused (live work — deleting it under a running generator would orphan
     * the claim). Decrements totalItems and recomputes counters/status.
     *
     * @param int $strategy_id Strategy ID (ownership verified by the caller).
     * @param int $item_id     Item to delete.
     * @param int $user_id     Owner ID (for the counters recompute).
     * @param int $total       Strategy's CURRENT totalItems.
     * @return array Refreshed strategy items.
     * @throws \RuntimeException On foreign item or a generating item.
     */
    public static function delete_item(int $strategy_id, int $item_id, int $user_id, int $total): array
    {
        $item = null;
        foreach (PCM_DB::get_strategy_items($strategy_id) as $candidate) {
            if ((int)$candidate->id === $item_id) {
                $item = $candidate;
                break;
            }
        }
        if (!$item) {
            throw new \RuntimeException('Item not found in this strategy.');
        }
        if ((string)$item->status === 'generating') {
            throw new \RuntimeException('This item is generating right now — wait for it to finish (or stall out) first.');
        }

        PCM_DB::delete_strategy_item($item_id);

        $new_total = max(0, $total - 1);
        PCM_DB::update_strategy($strategy_id, $user_id, array('totalItems' => $new_total));
        self::recompute_counters($strategy_id, $user_id, $new_total);

        return PCM_DB::get_strategy_items($strategy_id);
    }

    /**
     * Reset a `completed`/`error` item back to `pending` — detaches its article
     * link (the article itself is untouched, left as-is in Writer) and clears any
     * error message, so a later Generate/Generate All/Retry regenerates it fresh.
     * The item MUST belong to the given strategy — never trust a bare itemId.
     *
     * @param int $strategy_id Strategy ID (already ownership-verified by the caller).
     * @param int $item_id     Item to reset.
     * @param int $user_id     Owner ID (for the counters recompute).
     * @param int $total       Strategy's totalItems (for the counters recompute).
     * @return array Refreshed strategy items.
     * @throws \RuntimeException If the item isn't in this strategy, or isn't
     *   currently `completed`/`error`.
     */
    public static function reset_item_to_pending(int $strategy_id, int $item_id, int $user_id, int $total): array
    {
        $item = null;
        foreach (PCM_DB::get_strategy_items($strategy_id) as $candidate) {
            if ((int)$candidate->id === $item_id) {
                $item = $candidate;
                break;
            }
        }
        if (!$item) {
            throw new \RuntimeException('Item not found in this strategy.');
        }
        if (!in_array($item->status, array('completed', 'error'), true)) {
            throw new \RuntimeException("Only a 'completed' or 'error' item can be reset.");
        }

        PCM_DB::update_strategy_item($item_id, array(
            'status'       => 'pending',
            'articleId'    => null,
            'errorMessage' => '',
        ));
        self::recompute_counters($strategy_id, $user_id, $total);

        return PCM_DB::get_strategy_items($strategy_id);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Load and parse a template by ID.
     *
     * @param int $template_id Template ID.
     * @param int $user_id     User ID.
     * @return array Template entries and metadata.
     */
    private static function load_template(int $template_id, int $user_id): array
    {
        global $wpdb;
        $table = PCM_Schema::table('templates');

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND (userId = %d OR userId = 0)",
            $template_id,
            $user_id
        ));

        if (!$row) {
            throw new \RuntimeException("Template #{$template_id} not found.");
        }

        $form_data = json_decode($row->formData, true) ?: array();
        return array(
            'name'    => $row->name,
            'entries' => $form_data['entries'] ?? array(),
            'type'    => $form_data['type'] ?? null,
        );
    }

    /**
     * Build LLM messages by injecting keyword(s) + brand into template prompt.
     *
     * Template entries with category "prompt" are used as the system prompt.
     * Brand context is injected as additional system context. The keyword(s)
     * become the user message — a single-element array for the normal per-item
     * case, or every item's keyword for Step 8's consolidated mode (one article
     * covering all of them).
     *
     * @param string[]    $keywords Target keyword(s).
     * @param array       $template Template data with entries.
     * @param object|null $brand    Brand DB row or null.
     *
     * @return array OpenAI-compatible messages array.
     */
    /**
     * SERP-style research enrichment (B1/B2): when the strategy opted in
     * (`config.research` truthy — same json_decode gate as approval_mode()),
     * ask Gemini — via the existing google_search-grounded
     * PCM_LLM::invoke_with_grounding() — to summarize the current search
     * landscape for the target keyword(s), so build_prompt() can fold it into
     * the generation prompt. Maps AutoPress's SERP-research concept onto PC's
     * own grounding infra (no DataForSEO).
     *
     * FULLY failure-isolated, exactly like maybe_generate_featured_image():
     * ANY failure — including the common "user has no Google API key" case,
     * which invoke_with_grounding() throws on — is caught, error_log()'d, and
     * degrades to '' (an un-enriched prompt). Research must NEVER fail or block
     * article generation.
     *
     * @param object $strategy Strategy DB row (its config JSON opts research in).
     * @param array  $keywords Keyword(s) driving the research query — one for the
     *   per-item path, all of them for the consolidated batch.
     * @param int    $user_id  Strategy owner (PCM user id) — the grounding API
     *   key is resolved for THIS user, matching the generation call's user_id
     *   option and the integrations table's keying. (Previously used
     *   get_current_user_id(), which is 0 under wp-cron, so research silently
     *   never ran for cron-driven items.)
     * @return string Research summary (trimmed, capped ~4000 chars), or '' when
     *   opted out or on any failure.
     */
    private static function maybe_research_context(object $strategy, array $keywords, int $user_id): string
    {
        $cfg = !empty($strategy->config) ? json_decode((string)$strategy->config, true) : null;
        if (!is_array($cfg) || empty($cfg['research'])) {
            return ''; // strategy didn't opt in
        }

        $query    = implode('", "', $keywords);
        $messages = array(array(
            'role'    => 'user',
            'content' => 'Research the current top-ranking content, dominant themes, common questions, and content gaps for the search query: "' . $query . '". Summarize concisely: key themes to cover, questions to answer, angles competitors miss.',
        ));

        try {
            $result  = PCM_LLM::invoke_with_grounding($messages, array(
                'model'      => 'gemini-2.5-flash',
                'max_tokens' => 2048,
                'user_id'    => $user_id,
            ));
            $content = trim((string)($result['content'] ?? ''));
            if (strlen($content) > 4000) {
                $content = substr($content, 0, 4000);
            }
            return $content;
        } catch (\Throwable $e) {
            error_log('[PCM_Strategy_Service] Research enrichment failed: ' . $e->getMessage());
            return '';
        }
    }

    private static function build_prompt(array $keywords, array $template, ?object $brand, string $research_context = '', bool $in_content_media = false): array
    {
        $messages = array();

        // ── System prompt from template entries ──
        $system_parts = array();

        // Collect prompt entries from template
        foreach ($template['entries'] as $entry) {
            if (($entry['category'] ?? '') === 'prompt') {
                $system_parts[] = $entry['value'] ?? '';
            }
        }

        // If no prompt entries exist, use a sensible default
        if (empty($system_parts)) {
            $system_parts[] = 'You are an expert SEO content writer. Write a comprehensive, well-structured article optimized for search engines.';
        }

        // ── Inject brand context ──
        if ($brand) {
            $brand_context = "BRAND CONTEXT:\n";
            if (!empty($brand->name)) {
                $brand_context .= "- Company: {$brand->name}\n";
            }
            if (!empty($brand->niche)) {
                $brand_context .= "- Industry: {$brand->niche}\n";
            }
            if (!empty($brand->tonOfVoice)) {
                $brand_context .= "- Tone of Voice: {$brand->tonOfVoice}\n";
            }
            if (!empty($brand->targetAudience)) {
                $brand_context .= "- Target Audience: {$brand->targetAudience}\n";
            }
            if (!empty($brand->uniqueSellingPoints)) {
                $brand_context .= "- Unique Selling Points: {$brand->uniqueSellingPoints}\n";
            }
            if (!empty($brand->language)) {
                $brand_context .= "- Content Language: {$brand->language}\n";
            }
            $system_parts[] = $brand_context;
        }

        // ── Inject live-research landscape (B1/B2), when the strategy opted in
        //     and research succeeded. Empty string (opted out, or a failed/
        //     degraded research call) leaves the prompt un-enriched — research
        //     must never change generation's shape, only augment it. ──
        if ($research_context !== '') {
            $system_parts[] = "CURRENT SEARCH LANDSCAPE (from live research — use to inform coverage, do not cite):\n" . $research_context;
        }

        $messages[] = array(
            'role'    => 'system',
            'content' => implode("\n\n", $system_parts),
        );

        // ── User message: keyword(s) as the generation target. A single-element
        //     array is the normal per-item case; Step 8's consolidated mode passes
        //     every keyword in the strategy so ONE article covers all of them. ──
        $user_content = count($keywords) > 1
            ? "Write a SINGLE comprehensive article that covers ALL of the following keywords together, "
              . "as distinct sections or subtopics within one cohesive piece: \"" . implode('", "', $keywords) . "\"\n\n"
              . "Return a JSON object with the following fields: title, content (HTML), metaTitle, metaDescription."
            : "Write a comprehensive article targeting the keyword: \"" . ($keywords[0] ?? '') . "\"\n\n"
              . "Return a JSON object with the following fields: title, content (HTML), metaTitle, metaDescription.";

        // A6: in-content images & charts. When enabled, ask the model to drop
        // [IMAGE_N] placeholder tokens into the HTML body and return a matching
        // media_assets entry for each — the post-process step
        // (maybe_generate_in_content_media()) turns those into <figure> blocks.
        if ($in_content_media) {
            $user_content .= "\n\n"
                . "IN-CONTENT MEDIA: You may add up to 3 supporting visuals. Insert placeholder tokens "
                . "[IMAGE_1], [IMAGE_2], [IMAGE_3] — each on its OWN line, wrapped in its own <p></p>, at "
                . "natural points in the HTML body. For EVERY placeholder you insert, add one matching entry "
                . "to a \"media_assets\" array, where each entry is {placeholder: \"IMAGE_1\", type: \"image\"|\"chart\", "
                . "prompt: string, chart_config: string|null}. For type \"image\", `prompt` is a detailed image-generation "
                . "prompt and chart_config is null. For type \"chart\", put a VALID Chart.js config — serialized as a JSON "
                . "string — in `chart_config` and a short caption in `prompt`. Only insert a placeholder if you also "
                . "return its media_assets entry, and never exceed 3.";
        }

        $messages[] = array(
            'role'    => 'user',
            'content' => $user_content,
        );

        return $messages;
    }

    /**
     * JSON schema for structured article output from LLM.
     *
     * Used by PCM_LLM::invoke_json() for guaranteed structured output.
     *
     * @return array JSON schema definition.
     */
    private static function article_schema(): array
    {
        return array(
            'name'   => 'article_output',
            'strict' => true,
            'schema' => array(
                'type'       => 'object',
                // Strict mode requires EVERY property listed here; media_assets
                // expresses optionality via its ['array','null'] type instead.
                'required'   => array('title', 'content', 'metaTitle', 'metaDescription', 'media_assets'),
                'properties' => array(
                    'title' => array(
                        'type'        => 'string',
                        'description' => 'SEO-optimized article title (H1)',
                    ),
                    'content' => array(
                        'type'        => 'string',
                        'description' => 'Full article content in clean HTML (h2, h3, p, ul, ol, strong, em). No wrapper div.',
                    ),
                    'metaTitle' => array(
                        'type'        => 'string',
                        'description' => 'SEO meta title tag, max 60 characters',
                    ),
                    'metaDescription' => array(
                        'type'        => 'string',
                        'description' => 'SEO meta description, max 160 characters',
                    ),
                    // A6: optional in-content images/charts (AutoPress [IMAGE_N] /
                    // media_assets convention). The writer prompt (build_prompt())
                    // — only when in-content media is enabled — asks the model to
                    // drop [IMAGE_1]..[IMAGE_3] tokens into `content` and return a
                    // matching entry here per token. Empty when the model adds no
                    // visuals; capped to 3 in post-process.
                    //
                    // STRICT-MODE SHAPE (caught by a real-API smoke, not theory):
                    // this schema carries strict=true, and OpenAI enforces that
                    // (a) every property at every level appears in `required` —
                    // optionality is expressed as a ["…","null"] type union, and
                    // (b) no free-form objects — so chart_config is a JSON STRING
                    // the post-process json_decodes, not an object. A bare
                    // optional property here is a hard 400 that propagates (it
                    // does not match the response_format fallback matcher).
                    'media_assets' => array(
                        'type'        => array('array', 'null'),
                        'description' => 'Up to 3 in-content visuals, one per [IMAGE_N] placeholder inserted into content. Null or empty when none.',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'placeholder'  => array(
                                    'type'        => 'string',
                                    'description' => 'The matching placeholder token name, e.g. "IMAGE_1".',
                                ),
                                'type'         => array(
                                    'type'        => 'string',
                                    'enum'        => array('image', 'chart'),
                                    'description' => 'image = AI-generated picture; chart = Chart.js chart rendered to an image.',
                                ),
                                'prompt'       => array(
                                    'type'        => 'string',
                                    'description' => 'For type=image, a detailed image-generation prompt. For type=chart, a short caption.',
                                ),
                                'chart_config' => array(
                                    'type'        => array('string', 'null'),
                                    'description' => 'For type=chart only: a valid Chart.js config object serialized as a JSON string. Null for images.',
                                ),
                            ),
                            'required'             => array('placeholder', 'type', 'prompt', 'chart_config'),
                            'additionalProperties' => false,
                        ),
                    ),
                ),
                'additionalProperties' => false,
            ),
        );
    }
}

// wp-cron callback for the background queue continuation (see
// maybe_schedule_queue_continuation()/run_queue_tick() above). Registered at
// file load so the hook exists when wp-cron fires the event — mirrors the
// automations module's async-action registration pattern exactly.
if (function_exists('add_action')) {
    add_action('pcm_strategy_process_queue', array('PCM_Strategy_Service', 'run_queue_tick'), 10, 2);
    // Daily scan for scheduled strategies (Step 4). The recurring event itself is
    // scheduled once in power-creatives.php; this just wires the hook so it exists
    // whenever wp-cron dispatches it.
    add_action('pcm_strategy_scheduled_scan', array('PCM_Strategy_Service', 'run_scheduled_scan'));
}
