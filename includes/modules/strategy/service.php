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
     * Enforce the one hierarchy invariant a 'consolidated' structure (ONE
     * article for every keyword in the strategy — see structure_mode()) still
     * breaks: 'parent_and_children'. That mode splits the strategy's OWN
     * articles into a parent and its children, which is impossible when there
     * is only one article. The other modes are fine — 'children_only' means the
     * single consolidated article links to an EXTERNAL parent URL
     * (config.parentTargetUrl), 'parent_only' means the single consolidated
     * article IS the pillar/parent and carries no outbound parent link, and
     * 'standalone' carries no link at all — so all pass through untouched.
     *
     * When consolidated + 'parent_and_children': downgrade the mode to
     * 'parent_only' — the "parent" intent is now expressible for a single
     * consolidated article: that one article becomes the pillar with nothing
     * above it (no injected link, via resolve_parent_link()'s default-null
     * path). Drop only 'parentKeyword' — the item-designation key that names
     * which of the strategy's own items is the parent, meaningless with a
     * single article. 'parentAnchorKeyword' (the anchor-text override) and
     * 'parentTargetUrl' survive untouched.
     *
     * The create dialog's UI already prevents an impossible combination, but
     * the server must not trust client input — this is the single place both
     * create_from_keywords() (covering every creation path: the REST create
     * endpoint, duplicate_strategy(), and the per-site schedule scanner) and
     * PCM_REST_Strategy::update_strategy() (the only PATCH door that can change
     * hierarchyMode and/or config.structure) funnel through before persisting.
     *
     * Pure/deterministic — no DB access, directly unit-testable. Callers must
     * pass the EFFECTIVE values (i.e., already merged with any existing
     * stored config for a partial PATCH), not just the fields present in a
     * given request.
     *
     * @param string $hierarchy_mode Effective hierarchyMode.
     * @param array  $config         Effective config (must reflect the
     *                                effective 'structure' key).
     * @return array{hierarchyMode:string,config:array} Corrected pair —
     *                                returned unchanged unless structure is
     *                                'consolidated' AND mode is
     *                                'parent_and_children' (→ 'parent_only').
     */
    public static function apply_structure_hierarchy_guard(string $hierarchy_mode, array $config): array
    {
        if (($config['structure'] ?? 'individual') === 'consolidated'
            && $hierarchy_mode === 'parent_and_children'
        ) {
            $hierarchy_mode = 'parent_only';
            unset($config['parentKeyword']);
        }
        return array('hierarchyMode' => $hierarchy_mode, 'config' => $config);
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
        // A consolidated structure (single article for every keyword) can never
        // carry a hierarchy between the strategy's own articles — enforced here
        // regardless of what the caller sent, since this is the one funnel every
        // creation path (REST create, duplicate_strategy(), site schedules) uses.
        $guarded = self::apply_structure_hierarchy_guard(
            (string)($options['hierarchyMode'] ?? 'standalone'),
            is_array($options['config'] ?? null) ? $options['config'] : array()
        );

        // ── Create the strategy record ──
        $strategy_data = array(
            'userId'         => $user_id,
            'name'           => $name,
            'templateId'     => $template_id,
            'brandId'        => $brand_id,
            'status'         => 'pending',
            'hierarchyMode'  => $guarded['hierarchyMode'],
            'publishingMode' => $options['publishingMode'] ?? 'draft',
            'config'         => !empty($guarded['config']) ? wp_json_encode($guarded['config']) : null,
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
            // The create dialog DOES ship a start-date picker (CreateStrategyDialog
            // sends scheduleConfig.startDate; the controller whitelists it), so this
            // default-to-now branch is now only the fallback for an empty picker.
            $start_date = !empty($schedule_cfg['startDate']) ? (string)$schedule_cfg['startDate'] : current_time('mysql');

            $items = PCM_DB::get_strategy_items($strategy_id); // position ASC — matches keyword order
            // Pass the WHOLE scheduleConfig to the custom-recurrence engine — it
            // takes the byte-identical legacy path for a bare {frequency}, and the
            // custom keys (interval/unit/byDays/ends) once a picker sends them.
            $dates = self::calculate_recurrence_dates(count($items), $schedule_cfg, $start_date);
            foreach ($items as $i => $item) {
                $slot = $dates[$i] ?? null;
                // A null slot = past a custom-recurrence `ends` cap: leave the
                // freshly-created item's scheduledDate NULL (it stays pending and is
                // never picked up by the due-date scan) rather than writing a date.
                if ($slot === null) {
                    continue;
                }
                PCM_DB::update_strategy_item((int)$item->id, array('scheduledDate' => $slot));
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

        // ── RSS instant first pull: a Source=RSS strategy must generate from
        // the feed's newest EXISTING item right away instead of waiting for
        // the hourly watcher's next pass. One-off event, same shape (and
        // wp_next_scheduled() dedupe) as maybe_schedule_queue_continuation();
        // it fires run_rss_first_scan() → scan_rss_strategy() in the
        // background. Living here — the one funnel every creation door uses
        // (REST create, site-schedule rules) — it covers them all. ──
        if (($guarded['config']['sourceMode'] ?? '') === 'rss'
            && is_array($guarded['config']['rssFeeds'] ?? null)
            && $guarded['config']['rssFeeds'] !== array()
            && function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')
        ) {
            $args = array($strategy_id, $user_id);
            if (!wp_next_scheduled('pcm_strategy_rss_first_scan', $args)) {
                wp_schedule_single_event(time(), 'pcm_strategy_rss_first_scan', $args);
            }
        }

        // ── Social create-time split (Source=Social strategies): classify
        // every pasted link once — post links become pending items NOW (one
        // article each, generated from the post's own fetched context), free-
        // platform account links (YouTube/Bluesky/Reddit) convert to native
        // feeds merged into rssFeeds (the existing RSS watcher takes over,
        // including the instant first pull above), and Apify-platform account
        // links land on config.socialAccounts for the watcher's ≥4h Apify
        // branch. Same funnel rationale as the RSS kick above — every
        // creation door passes through here. ──
        if (($guarded['config']['sourceMode'] ?? '') === 'social'
            && is_array($guarded['config']['socialLinks'] ?? null)
            && $guarded['config']['socialLinks'] !== array()
        ) {
            self::split_social_links($strategy_id, $user_id, $guarded['config']);
        }

        // ── Return the complete strategy with items ──
        $strategy = PCM_DB::get_strategy($strategy_id, $user_id);
        $strategy->items = PCM_DB::get_strategy_items($strategy_id);

        return (array)$strategy;
    }

    /**
     * Create-time split for a Source=Social strategy's pasted links (called by
     * create_from_keywords() right after the strategy row + config persist).
     * Each stored socialLink is classified exactly once:
     *
     *   - kind 'post' (any platform, incl unknown hosts) → ONE pending item
     *     immediately, keyword = the post's best-effort context title
     *     (PCM_Social_Source::post_context() NEVER throws — a fetch failure
     *     still yields the URL-label fallback, so create always succeeds);
     *   - kind 'account' on an Apify platform (apify_request() non-null:
     *     instagram/tiktok/x/facebook) → {url, platform} collected onto
     *     config.socialAccounts for the watcher's ≥4h Apify branch;
     *   - kind 'account' on a free platform with a native feed
     *     (account_feed_url() non-null: YouTube/Bluesky/Reddit) → the feed URL
     *     merged (deduped) into config.rssFeeds; the EXISTING RSS instant
     *     first pull is armed so the newest post generates right away. Items
     *     created from these feeds are plain RSS items (standard rss rider) —
     *     deliberate: the feed entries carry no post text, so the richer
     *     social rider has nothing extra to say;
     *   - kind 'account' on a free platform whose feed conversion failed
     *     (e.g. a YouTube handle whose channelId resolve failed) → skipped
     *     with an error_log — an account link must never become a one-off
     *     "post" article.
     *
     * The derived watcher config persists exactly the way scan_rss_strategy()
     * persists its merged state — ONE update_strategy() write of the whole
     * config — and totalItems is bumped the same way the watcher does after
     * inserting items. Post-item inserts arm the existing background queue
     * once (same placement rationale as the RSS kick).
     *
     * @param int   $strategy_id Freshly created strategy ID.
     * @param int   $user_id     Owner ID.
     * @param array $config      The stored (guarded) create config.
     */
    private static function split_social_links(int $strategy_id, int $user_id, array $config): void
    {
        if (!class_exists('PCM_Social_Source', false)) {
            require_once __DIR__ . '/class-pcm-social-source.php';
        }

        $feeds    = array();
        $accounts = array();
        $posts    = array();
        $inserted = 0;

        // Wall-clock budget for ALL network fetches in this create-path run
        // (post_context's oEmbed/meta fetches AND YouTube channelId resolves).
        // This runs synchronously inside the REST create — a many-link paste
        // must not blow past host request caps (60s FPM kills are common).
        // Once ~20s is spent, remaining lookups skip the network: post links
        // fall back to their URL-derived label; a YouTube handle that can't
        // resolve without network is skipped with an error_log.
        $context_deadline = microtime(true) + 20.0;
        @set_time_limit(120);

        // ── PHASE 1 (fast, no slow network): classify every link; collect
        //    Apify accounts + free-platform feeds. Only the YouTube handle
        //    resolve fetches, and it is under the deadline. ──
        foreach ((array)$config['socialLinks'] as $url) {
            $url = trim((string)$url);
            if ($url === '') {
                continue;
            }
            $classified = PCM_Social_Source::classify($url);
            $platform   = (string)($classified['platform'] ?? 'unknown');

            if (($classified['kind'] ?? '') === 'account') {
                if (PCM_Social_Source::apify_request($platform, $url, 1) !== null) {
                    $accounts[] = array('url' => $url, 'platform' => $platform);
                    continue;
                }
                $feed = microtime(true) < $context_deadline
                    ? PCM_Social_Source::account_feed_url($url, $classified)
                    : null;
                if ($feed !== null && $feed !== '') {
                    $feeds[] = $feed;
                    continue;
                }
                error_log(sprintf(
                    '[PCM_Strategy_Service] Social create #%d: could not derive a feed for account link %s (%s) — skipped.',
                    $strategy_id,
                    $url,
                    $platform
                ));
                continue;
            }

            $posts[] = $url; // slow context fetches deferred to phase 3
        }

        // ── PHASE 2: persist the watcher config + arm the instant first pull
        //    BEFORE any slow post-context work. If the host kills this request
        //    mid-phase-3, the accounts/feeds are already durable and the
        //    watcher takes over — no zombie strategy (adversarial-review P1). ──
        $config_dirty = false;
        if ($feeds !== array()) {
            $existing_feeds = is_array($config['rssFeeds'] ?? null) ? $config['rssFeeds'] : array();
            $merged_feeds   = array_values(array_unique(array_merge($existing_feeds, $feeds)));
            if ($merged_feeds !== $existing_feeds) {
                $config['rssFeeds'] = $merged_feeds;
                $config_dirty = true;
            }
        }
        if ($accounts !== array()) {
            $config['socialAccounts'] = $accounts;
            $config_dirty = true;
        }
        if ($config_dirty) {
            PCM_DB::update_strategy($strategy_id, $user_id, array('config' => wp_json_encode($config)));
        }
        if (($feeds !== array() || $accounts !== array())
            && function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')
        ) {
            $args = array($strategy_id, $user_id);
            if (!wp_next_scheduled('pcm_strategy_rss_first_scan', $args)) {
                wp_schedule_single_event(time(), 'pcm_strategy_rss_first_scan', $args);
            }
        }

        // ── PHASE 3 (slow): one pending item per post link, carrying the
        //    source context the social prompt rider reads back. ──
        foreach ($posts as $url) {
            $context = PCM_Social_Source::post_context($url, microtime(true) < $context_deadline);
            $title   = trim((string)($context['title'] ?? ''));
            if ($title === '') {
                $title = $url; // post_context() guarantees a title, but stay defensive
            }
            $item_cfg = array(
                'sourceLink'  => $url,
                'sourceTitle' => $title,
                'sourceText'  => (string)($context['text'] ?? ''),
                'social'      => true,
            );
            // The post's own preview image (og:image when fetchable) is reused as
            // the generated article's featured image — stored when captured.
            $source_image = trim((string)($context['image'] ?? ''));
            if ($source_image !== '') {
                $item_cfg['sourceImage'] = $source_image;
            }
            $item_id = PCM_DB::create_rss_strategy_item($strategy_id, $user_id, $title, $item_cfg);
            if ($item_id) {
                $inserted++;
            }
        }

        if ($inserted > 0) {
            PCM_DB::update_strategy($strategy_id, $user_id, array(
                'totalItems' => PCM_DB::count_strategy_items($strategy_id),
            ));
            // Kick the existing background queue exactly once (it dedupes
            // itself), mirroring the rss watcher.
            self::maybe_schedule_queue_continuation($strategy_id, $user_id);
        }
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
        // null stays null. One exception: run the structure↔hierarchy guard over
        // the copied pair — the live write doors already guarantee clean rows,
        // but a legacy/imported consolidated+hierarchy row must not propagate
        // its contradiction into the copy.
        $config_arr = !empty($strategy->config) ? json_decode((string)$strategy->config, true) : array();
        $guarded = self::apply_structure_hierarchy_guard(
            (string)($strategy->hierarchyMode ?? 'standalone'),
            is_array($config_arr) ? $config_arr : array()
        );
        $config_json = $guarded['config'] !== array() ? wp_json_encode($guarded['config']) : null;

        $new_id = PCM_DB::create_strategy(array(
            'userId'         => $user_id,
            'name'           => (string)$strategy->name . ' (copy)',
            'templateId'     => (int)$strategy->templateId,
            'brandId'        => !empty($strategy->brandId) ? (int)$strategy->brandId : null,
            'status'         => 'pending',
            'hierarchyMode'  => $guarded['hierarchyMode'],
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
            // Pass the whole stored scheduleConfig (custom-recurrence keys and all)
            // straight through; an empty/frequency-only config still yields weekly.
            self::reschedule_pending_items($new_id, $schedule_cfg, ''); // '' → now
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
     * Thin adapter over calculate_recurrence_dates() (the custom-recurrence
     * engine): a bare frequency is just the legacy shape of a schedule config,
     * so this wraps it as `{frequency}` and delegates. Signature and behavior
     * are unchanged for existing callers (and the direct unit tests) — the
     * engine's legacy branch reproduces the original switch byte-for-byte.
     *
     * @param int    $count      Number of items to schedule (in position order).
     * @param string $frequency  'all_once'|'daily'|'every_other_day'|'weekly'|'biweekly'|'monthly'.
     * @param string $start_date Any strtotime()-parseable date (the first item's due date).
     * @return string[] $count MySQL DATETIME strings ('Y-m-d H:i:s'), one per item, in order.
     */
    public static function calculate_schedule_dates(int $count, string $frequency, string $start_date): array
    {
        return self::calculate_recurrence_dates($count, array('frequency' => $frequency), $start_date);
    }

    /**
     * Custom-recurrence date engine (behind calculate_schedule_dates()). Spreads
     * $count items across per-item due dates from a schedule config, in position
     * order. Pure/deterministic — the caller resolves "now" before calling, so
     * this has no hidden time dependency and is directly unit-testable.
     *
     * Config keys (all optional; sane defaults):
     *   - `interval` int 1–12 (default 1) — steps of `unit` between items.
     *   - `unit` 'day'|'week'|'month' (default 'week').
     *   - `byDays` ISO weekday ints 1(Mon)–7(Sun); honored ONLY when unit='week'.
     *     Items land on the selected weekdays in order: the first run starts on
     *     the first selected weekday >= the start date's weekday (same week), or
     *     the first selected day of the NEXT block if none remain that week; after
     *     the last selected day in a block, advance `interval` weeks to the block
     *     holding the FIRST selected day.
     *   - `byMonthDay` day-of-month 1–31; honored ONLY when unit='month'. A day past
     *     the month's length is CLAMPED to that month's last day, so 31 means "last
     *     day" for 28/29/30-day months. Absent → the start date's own day-of-month.
     *     Monthly slots are computed from the start anchor (never chained), so they
     *     cannot drift the way strtotime('+1 month') does from a 29th–31st start.
     *   - `ends` {type:'never'} (default) | {type:'on','date'=>'Y-m-d'} |
     *     {type:'after','count'=>N}. Slots past the cap are NULL entries (the
     *     caller leaves scheduledDate NULL → the item stays pending, never
     *     scanned). `on`: no date strictly after that day's 23:59:59. `after`:
     *     only the first N slots get dates.
     *
     * LEGACY: a bare `frequency` (with NONE of interval/unit/byDays/ends present)
     * reproduces calculate_schedule_dates()'s original switch byte-for-byte,
     * including the deliberately-ported 'biweekly' = +3 days quirk.
     *
     * The start date's time-of-day is preserved on every slot (day/week/month
     * and byDays alike).
     *
     * @param int    $count        Number of items to schedule (position order).
     * @param array  $schedule_cfg Schedule config (keys above; empty = weekly).
     * @param string $start_date   Any strtotime()-parseable date (first slot anchor).
     * @return array<int,string|null> $count entries: 'Y-m-d H:i:s' strings, or
     *   null for slots past an `ends` cap.
     */
    public static function calculate_recurrence_dates(int $count, array $schedule_cfg, string $start_date): array
    {
        // LEGACY: a bare frequency with none of the custom-recurrence keys → the
        // exact original calculate_schedule_dates() switch (byte-identical).
        $has_custom = isset($schedule_cfg['interval']) || isset($schedule_cfg['unit'])
            || isset($schedule_cfg['byDays']) || isset($schedule_cfg['byMonthDay'])
            || isset($schedule_cfg['ends']);
        if (!$has_custom && isset($schedule_cfg['frequency'])) {
            return self::legacy_schedule_dates($count, (string)$schedule_cfg['frequency'], $start_date);
        }

        if ($count < 1) {
            return array();
        }

        // ── Parse config with sane defaults ──
        $interval = isset($schedule_cfg['interval']) ? (int)$schedule_cfg['interval'] : 1;
        $interval = max(1, min(12, $interval));
        $unit = isset($schedule_cfg['unit']) ? (string)$schedule_cfg['unit'] : 'week';
        if (!in_array($unit, array('day', 'week', 'month'), true)) {
            $unit = 'week';
        }
        // byDays is only meaningful weekly — normalize to unique ISO ints 1–7, ascending.
        $by_days = array();
        if ($unit === 'week' && isset($schedule_cfg['byDays']) && is_array($schedule_cfg['byDays'])) {
            foreach ($schedule_cfg['byDays'] as $d) {
                $d = (int)$d;
                if ($d >= 1 && $d <= 7) {
                    $by_days[$d] = $d; // key by value → dedupe
                }
            }
            $by_days = array_values($by_days);
            sort($by_days);
        }
        // byMonthDay is only meaningful MONTHLY — the day-of-month to post on (1–31).
        // 31 (or any day past a month's length) is clamped to that month's LAST day.
        // 0/absent → keep the start date's own day-of-month.
        $by_month_day = 0;
        if ($unit === 'month' && isset($schedule_cfg['byMonthDay'])) {
            $d = (int)$schedule_cfg['byMonthDay'];
            if ($d >= 1 && $d <= 31) {
                $by_month_day = $d;
            }
        }

        $start_ts = strtotime($start_date) ?: time();
        $time_str = date('H:i:s', $start_ts);

        // ── Build the raw (uncapped) sequence of MySQL DATETIME strings ──
        $raw = array();
        if ($by_days !== array()) {
            // Weekday-set scheduling: walk the selected weekdays within each block,
            // advancing `interval` weeks after the block's last selected day.
            $start_wd = (int)date('N', $start_ts); // ISO 1(Mon)–7(Sun)
            // Monday of the start week (whole-day shift keeps the time-of-day).
            $block_monday = strtotime(sprintf('%+d days', -($start_wd - 1)), $start_ts);

            // First slot: the first selected weekday >= the start weekday; if none
            // remain this week, the first selected day of the next block.
            $idx = null;
            foreach ($by_days as $k => $wd) {
                if ($wd >= $start_wd) {
                    $idx = $k;
                    break;
                }
            }
            if ($idx === null) {
                $idx = 0;
                $block_monday = strtotime('+' . ($interval * 7) . ' days', $block_monday);
            }

            $day_count = count($by_days);
            for ($i = 0; $i < $count; $i++) {
                $ts = strtotime('+' . ($by_days[$idx] - 1) . ' days', $block_monday);
                // Rebuild with the anchored time-of-day so a DST-crossing day shift
                // can't drift the stored time by an hour.
                $raw[] = date('Y-m-d ', $ts) . $time_str;
                $idx++;
                if ($idx >= $day_count) {
                    $idx = 0;
                    $block_monday = strtotime('+' . ($interval * 7) . ' days', $block_monday);
                }
            }
        } elseif ($unit === 'month') {
            // Calendar-month scheduling on a fixed day-of-month. Each slot is computed
            // from the START anchor, never chained — because strtotime('+1 month')
            // OVERFLOWS: from Jan 31 it yields Mar 3, and a chain then drifts forever.
            // The target day is clamped to the month's real length, so day 31 lands on
            // the LAST day of shorter months (28/29/30) — the documented rule — and
            // Feb/Apr/Jun/Sep/Nov can never spill into the following month.
            $anchor_day = $by_month_day > 0 ? $by_month_day : (int)date('j', $start_ts);
            $year   = (int)date('Y', $start_ts);
            $month  = (int)date('n', $start_ts);
            // If the chosen day has already passed in the start month, begin at the
            // next period instead of emitting a slot in the past.
            $dim_start = (int)date('t', $start_ts);
            $offset    = (min($anchor_day, $dim_start) < (int)date('j', $start_ts)) ? $interval : 0;
            for ($i = 0; $i < $count; $i++) {
                $m = $month + $offset + ($i * $interval);
                $y = $year + intdiv($m - 1, 12);
                $m = (($m - 1) % 12) + 1;
                $dim = (int)date('t', mktime(0, 0, 0, $m, 1, $y)); // days in THAT month
                $raw[] = sprintf('%04d-%02d-%02d ', $y, $m, min($anchor_day, $dim)) . $time_str;
            }
        } else {
            // Fixed-interval scheduling by day/week. Advancing from the previous slot's
            // timestamp mirrors the legacy switch's own chained-strtotime shape.
            $step = $unit === 'day'
                ? '+' . $interval . ' day'
                : '+' . ($interval * 7) . ' days';
            $ts = $start_ts;
            for ($i = 0; $i < $count; $i++) {
                $raw[] = date('Y-m-d H:i:s', $ts);
                $ts = strtotime($step, $ts);
            }
        }

        // ── Apply the `ends` cap: slots past it become null ──
        $ends      = (isset($schedule_cfg['ends']) && is_array($schedule_cfg['ends'])) ? $schedule_cfg['ends'] : array();
        $ends_type = (string)($ends['type'] ?? 'never');
        $cap_ts    = null;  // 'on': last inclusive second of the cap day
        $after_n   = null;  // 'after': how many leading slots keep a date
        if ($ends_type === 'on' && !empty($ends['date'])) {
            $cap_ts = strtotime((string)$ends['date'] . ' 23:59:59');
            if ($cap_ts === false) {
                $cap_ts = null; // unparseable → treat as never
            }
        } elseif ($ends_type === 'after') {
            $after_n = max(0, (int)($ends['count'] ?? 0));
        }

        $out = array();
        for ($i = 0; $i < $count; $i++) {
            $slot = $raw[$i];
            if ($after_n !== null && $i >= $after_n) {
                $slot = null;
            } elseif ($cap_ts !== null && strtotime($raw[$i]) > $cap_ts) {
                $slot = null;
            }
            $out[] = $slot;
        }
        return $out;
    }

    /**
     * The original calculateSchedule() switch, preserved verbatim behind the
     * custom-recurrence engine's legacy branch (calculate_recurrence_dates()).
     * `all_once` gives every item the SAME date; every other key advances the
     * timestamp by its interval in position order.
     *
     * @param int    $count      Number of items (position order).
     * @param string $frequency  'all_once'|'daily'|'every_other_day'|'weekly'|'biweekly'|'monthly'.
     * @param string $start_date strtotime()-parseable first due date.
     * @return string[] $count MySQL DATETIME strings ('Y-m-d H:i:s').
     */
    private static function legacy_schedule_dates(int $count, string $frequency, string $start_date): array
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
            // 'pending' only — matches generate_next_item()'s get_next_pending_item()
            // (status='pending'). Selecting 'error' here re-runs a permanently-failing
            // consolidated batch on every Generate click forever: the catch below sets
            // failed items back to 'error', which this branch would immediately re-pick.
            // An explicit Retry (generate_next_item with $item_id) still targets any
            // status, so a failed consolidated item remains retryable on demand.
            if ($it->status === 'pending') {
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
            $messages = self::build_prompt($keywords, $template, $brand, $research, self::in_content_media_enabled($strategy), self::media_count($strategy), self::media_type($strategy), self::media_guidance($strategy));
            list($model, $provider) = self::resolve_model($strategy);
            $llm_options = array(
                'model'      => $model,
                'max_tokens' => 12288, // headroom for long listicle HTML; the
                                       // LLM layer auto-retries at a doubled cap
                                       // if a longer article still truncates.
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
            $content = $result['content'] ?? '';

            // Step 9: hierarchy-aware parent-link injection — mirrors the per-item
            // path in generate_next_item(), applied here to the single shared
            // article. For a consolidated strategy the only hierarchy left after
            // the guard is 'children_only', where resolve_parent_link() returns
            // the EXTERNAL parentTargetUrl without any item lookup — so the
            // $items array is passed only for signature parity. Runs after the
            // media step and before persist, so the link is baked into stored
            // content. Failure-isolated: neither helper throws for missing config.
            $parent_link = self::resolve_parent_link($strategy, $items, $user_id);
            if ($parent_link !== null) {
                $content = self::inject_parent_link($content, $parent_link, $strategy);
            }

            $title      = $result['title'] ?? ucfirst($keywords[0] ?? '');
            $meta_title = $result['metaTitle'] ?? $title;
            $meta_desc  = $result['metaDescription'] ?? '';

            // Consolidated + Source=Social: one article covers MANY posts, so
            // this path never reads a single $item_cfg — which meant the
            // no-emoji rule silently didn't apply here. It does now, keyed off
            // any contributing item being social.
            //
            // The post-image reuse is deliberately NOT applied: a shared
            // article covering N posts has no unambiguous "the post's image",
            // and picking one arbitrarily is a product decision, not a fix. The
            // AI featured image (opt-in gated) stays the behavior here.
            $batch_is_social = false;
            foreach ($items as $it) {
                if (!empty(self::item_config($it)['social'])) {
                    $batch_is_social = true;
                    break;
                }
            }
            if ($batch_is_social) {
                $title      = self::strip_emoji($title);
                $content    = self::strip_emoji($content);
                $meta_title = self::strip_emoji($meta_title);
                $meta_desc  = self::strip_emoji($meta_desc);
            }
            $slug = sanitize_title($title);

            $article_id = PCM_DB::create_article(array(
                'userId'          => $user_id,
                'strategyId'      => (int)$strategy->id,
                'strategyItemId'  => $pending_ids[0], // the batch's own "primary" item, for traceability only
                'brandId'         => !empty($strategy->brandId) ? (int)$strategy->brandId : null,
                // Record the Target Site up front so a DRAFT is publishable from the
                // Writer; previously siteId was only written at publish time.
                'siteId'          => self::strategy_site_id($strategy),
                'title'           => $title,
                'slug'            => $slug,
                'content'         => $content,
                'metaTitle'       => $meta_title,
                'metaDescription' => $meta_desc,
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
                foreach ($pending_ids as $i => $id) {
                    // Claim guard, as in the completed branch below — a batch
                    // that lost its items to a reclaim must not overwrite the
                    // state the new owner is producing.
                    if (!PCM_DB::complete_strategy_item_if_generating($id, array(
                        'status'       => 'in_review',
                        'title'        => $title,
                        'slug'         => $slug,
                        'articleId'    => $article_id,
                        'setId'        => $set_id,
                        'errorMessage' => '',
                    )) && $i === 0) {
                        error_log(sprintf(
                            '[PCM_Strategy_Service] Consolidated batch on strategy #%d lost its claim to a reclaim — approval set #%d is left unlinked.',
                            (int)$strategy->id,
                            (int)$set_id
                        ));
                        break;
                    }
                }
            } else {
                // Same claim guard as the per-item path: the stale-generating
                // reclaim can hand these items to a new batch run without
                // cancelling this one, and a blind write would then publish a
                // SECOND copy of the shared article. Ownership is decided by
                // the FIRST item — a consolidated batch owns its items as a
                // set, so a partial write is never correct.
                $owned = false;
                foreach ($pending_ids as $i => $id) {
                    $wrote = PCM_DB::complete_strategy_item_if_generating($id, array(
                        'status'       => 'written',
                        'title'        => $title,
                        'slug'         => $slug,
                        'articleId'    => $article_id,
                        'errorMessage' => '',
                    ));
                    if ($i === 0) {
                        $owned = $wrote;
                        if (!$owned) {
                            error_log(sprintf(
                                '[PCM_Strategy_Service] Consolidated batch on strategy #%d lost its claim to a reclaim — discarding this run (article #%d stays an unlinked draft) and NOT publishing.',
                                (int)$strategy->id,
                                (int)$article_id
                            ));
                            break;
                        }
                    }
                }
                if ($owned) {
                    $publish = self::maybe_auto_publish($strategy, PCM_DB::get_article($article_id, $user_id), $user_id);
                    // Promote the whole batch 'written' → 'completed' only on a successful
                    // publish (the consolidated batch shares one article; all-or-nothing).
                    if ($publish !== null && !empty($publish['success'])) {
                        foreach ($pending_ids as $id) {
                            PCM_DB::update_strategy_item($id, array('status' => 'completed'));
                        }
                    }
                }
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
     * Remove any previously injected parent-link paragraph(s) from article HTML.
     * Safe because inject_parent_link() emits a DETERMINISTIC, distinctive shape
     * ("<p>Learn more in …: <a …>…</a>.</p>") that user content and the
     * interlinker never produce — so a literal pattern match is surgical.
     *
     * @param string $content Article HTML.
     * @return string Content without parent-link paragraphs.
     */
    private static function strip_parent_link(string $content): string
    {
        return (string)preg_replace(
            '#\s*<p>Learn more in [^<]*: <a href="[^"]*">[^<]*</a>\.</p>#',
            '',
            $content
        );
    }

    /**
     * Re-apply the parent link across a strategy's ALREADY-GENERATED articles —
     * the "change the parent after generation" action. Generation bakes the
     * parent-link paragraph into each child's content, so editing the parent
     * settings later (new target URL, a different item promoted to parent, a
     * new anchor keyword, or switching hierarchy mode) never touched existing
     * articles until this.
     *
     * Per article: strip any old parent-link paragraph, then append the freshly
     * resolved one — EXCEPT on the current parent's own article, which (like at
     * generation time) carries no link to itself. Resolving to no link at all
     * (standalone mode, missing config, parent not generated yet) degrades to a
     * pure strip, so switching a strategy back to standalone cleans its
     * children. Idempotent: re-running with unchanged settings rewrites nothing.
     *
     * Local content only — articles already pushed to a client site need a
     * re-publish/sync to update the remote copy (existing flows).
     *
     * @param int $strategy_id Strategy ID (ownership verified by the caller).
     * @param int $user_id     Owner ID.
     * @return array{updated: int, cleared: int, skipped: int}
     *   updated = articles whose link was replaced/added; cleared = articles
     *   whose old link was removed with nothing re-added; skipped = articles
     *   already correct (or items with no article yet).
     */
    public static function reapply_parent_links(int $strategy_id, int $user_id): array
    {
        $out = array('updated' => 0, 'cleared' => 0, 'skipped' => 0);
        $strategy = PCM_DB::get_strategy($strategy_id, $user_id);
        if (!$strategy) {
            return $out;
        }
        $items = PCM_DB::get_strategy_items($strategy_id);
        $link  = self::resolve_parent_link($strategy, $items, $user_id);

        $mode           = (string)($strategy->hierarchyMode ?? 'standalone');
        $parent_keyword = $mode === 'parent_and_children'
            ? (string)(self::hierarchy_config($strategy)['parentKeyword'] ?? '')
            : '';

        // A consolidated strategy has N items all pointing at the SAME
        // articleId; process each DISTINCT article once. Without this the loop
        // would strip+append the same article N times — the final content is
        // still correct (strip-then-append), but the counts inflate and the
        // writes are wasteful. A duplicate item isn't a real "skip" candidate,
        // so it bumps no counter. (Individual/parent_and_children strategies
        // have one article per item, so this never coalesces them.)
        $seen_articles = array();
        foreach ($items as $item) {
            if (empty($item->articleId)) {
                $out['skipped']++;
                continue;
            }
            $article_key = (int)$item->articleId;
            if (isset($seen_articles[$article_key])) {
                continue;
            }
            $seen_articles[$article_key] = true;
            $article = PCM_DB::get_article((int)$item->articleId, $user_id);
            if (!$article) {
                $out['skipped']++;
                continue;
            }
            $original = (string)$article->content;
            $stripped = self::strip_parent_link($original);

            // The parent's own article never links to itself (matches the
            // generation-time behavior, where the parent generates first).
            $is_parent = $parent_keyword !== '' && (string)$item->keyword === $parent_keyword;
            $next = (!$is_parent && $link !== null)
                ? self::inject_parent_link($stripped, $link, $strategy)
                : $stripped;

            if ($next === $original) {
                $out['skipped']++;
                continue;
            }
            PCM_DB::update_article((int)$item->articleId, $user_id, array('content' => $next));
            if ($next === $stripped) {
                $out['cleared']++;
            } else {
                $out['updated']++;
            }
        }
        return $out;
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
            // strategy can't wedge at "In Progress" with a phantom worker. The W3
            // per-item attempt cap is enforced by the global 90-min sweep
            // (reclaim_wedged_items); this 10-min fast path does a plain bulk
            // reclaim (the common case is a transiently-lost tick, not a
            // permanently-failing item, and a caught failure is already marked
            // 'error' by the time a second tick could reclaim it).
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
        // it atomically via claim_strategy_item(). A manual Retry also resets
        // the reclaim attempt counter (W3) so an owner re-trying a permanently-
        // failed item gets a fresh attempt budget.
        if ($item_id) {
            $retry_cfg = array_merge(self::item_config($item), array('attempts' => 0));
            PCM_DB::update_strategy_item((int)$item->id, array(
                'status' => 'generating',
                'config' => wp_json_encode($retry_cfg),
            ));
        }

        // Shutdown safety net (W1): a generator process KILLED mid-run — PHP
        // max_execution_time, FPM request_terminate_timeout, OOM, or a fatal —
        // never reaches the \Throwable catch below, so its item would stay
        // 'generating' until the 90-min global sweep. This shutdown handler
        // flips a still-'generating' item to 'error' on a terminating fatal,
        // bounded by $wedge_clean so a normal completion (return OR the catch,
        // which are both reached without a fatal) does NOT fire it. Gated on
        // status='generating' via complete_strategy_item_if_generating() so a
        // reclaim that already moved the item to 'pending' is never clobbered.
        $wedge_item_id = (int) $item->id;
        $wedge_clean   = false;
        register_shutdown_function(static function () use ($wedge_item_id, &$wedge_clean): void {
            if ($wedge_clean || !class_exists('PCM_DB')) {
                return;
            }
            $err = error_get_last();
            // Only act on a terminating fatal/OOM/parse — a clean exit (the
            // catch handled a Throwable, or the function returned normally)
            // has $wedge_clean === true and returns above.
            $fatal = $err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true);
            if (!$fatal) {
                return;
            }
            PCM_DB::complete_strategy_item_if_generating($wedge_item_id, array(
                'status'       => 'error',
                'errorMessage' => 'generation interrupted (fatal/timeout)',
            ));
            if (function_exists('error_log')) {
                error_log(sprintf(
                    '[PCM_Strategy_Service] Shutdown net marked item #%d error (fatal/timeout): %s',
                    $wedge_item_id,
                    $err['message'] ?? '(unknown)'
                ));
            }
        });

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

            // A pasted social POST link has no caption yet — Instagram/X/Facebook
            // killed public oEmbed, so create-time post_context() stored only the
            // bare URL. Here, in the BACKGROUND path where a ~30s Apify run-sync
            // is safe, pull the real post via the owner's Apify key so the article
            // is written ABOUT the post, not a naked link. No-ops for every item
            // that already has context (RSS, free platforms, watcher-fed posts).
            $item_cfg = self::maybe_enrich_social_post($item, $item_cfg, $user_id);

            // ── 4. Build prompt with variable injection (+ optional live research) ──
            // A template that references {{ post_content }} / {{ post_title }} /
            // {{ post_link }} OWNS how the source post is used, so the hardcoded
            // rider is suppressed — appending it would duplicate the instruction
            // and can contradict the author's wording.
            //
            // Keyed off the template TEXT **and** the resolved VALUES: a social
            // strategy on a variable-free template still gets the rider (exactly
            // as before), and so does one whose referenced variables are all
            // empty — otherwise an item with no captured caption would lose both
            // its context and the rider's mandatory "link back to the post"
            // instruction, leaving the model nothing to work from.
            $template_drives_source = false;
            foreach ($template['entries'] as $entry) {
                if (($entry['category'] ?? '') === 'prompt'
                    && self::template_carries_source((string)($entry['value'] ?? ''), $item_cfg)
                ) {
                    $template_drives_source = true;
                    break;
                }
            }
            $rss_source = $template_drives_source
                ? ''
                : self::rss_source_instruction($item_cfg, self::rss_angle($strategy));

            $research = self::maybe_research_context($strategy, array($item->keyword), $user_id);
            $messages = self::build_prompt(array($item->keyword), $template, $brand, $research, self::in_content_media_enabled($strategy), self::media_count($strategy), self::media_type($strategy), self::media_guidance($strategy), $rss_source, $item_cfg);

            // ── 5. Invoke LLM — user-selected model/provider (data-driven), with a
            //       fallback for strategies created before model selection existed. ──
            list($model, $provider) = self::resolve_model($strategy);
            $llm_options = array(
                'model'      => $model,
                'max_tokens' => 12288, // headroom for long listicle HTML; the
                                       // LLM layer auto-retries at a doubled cap
                                       // if a longer article still truncates.
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
            // No emojis in articles written from a social post: the source post's
            // emojis bleed into the model's output. Strip them from every ARTICLE
            // text field — the stored sourceText/sourceTitle on the item are
            // captured data and stay byte-identical.
            $is_social = !empty($item_cfg['social']);
            if ($is_social) {
                $title = self::strip_emoji($title);
            }
            $slug = sanitize_title($title);

            // A6: in-content images & charts — replace [IMAGE_N] tokens with
            // <figure> blocks (or strip them when disabled/absent). Runs BEFORE
            // the content is persisted, and before the parent-link append below.
            $result  = self::maybe_generate_in_content_media($result, $strategy, $user_id);
            $content = $result['content'] ?? '';
            // metaTitle/metaDescription are stripped too — sites/service.php
            // pushes them to _yoast_wpseo_title / _yoast_wpseo_metadesc on the
            // client's site, so an unstripped one puts the post's emojis
            // straight into the SERP snippet.
            $meta_title = $result['metaTitle'] ?? $title;
            $meta_desc  = $result['metaDescription'] ?? '';
            if ($is_social) {
                $content    = self::strip_emoji($content);
                $meta_title = self::strip_emoji($meta_title);
                $meta_desc  = self::strip_emoji($meta_desc);
            }

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
                // See the consolidated path above — the draft must carry its Target Site.
                'siteId'          => self::strategy_site_id($strategy),
                'title'           => $title,
                'slug'            => $slug,
                'content'         => $content,
                'metaTitle'       => $meta_title,
                'metaDescription' => $meta_desc,
                'schemaType'      => 'Article',
                'status'          => 'draft',
                // The featured-image OPT-IN gates both sources. It lives inside
                // maybe_generate_featured_image(), so putting the social image
                // on the left of `??` short-circuited past it and published an
                // image on strategies that had the feature switched off.
                'featuredImage'   => self::featured_images_enabled($strategy)
                    ? (self::social_source_image($item_cfg)
                        ?? self::maybe_generate_featured_image($strategy, $title, (string)$item->keyword, $user_id))
                    : null,
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
                && in_array($item_cfg['approvalMode'], array('none', 'internal', 'client', 'both'), true)
                ? (string)$item_cfg['approvalMode']
                : self::approval_mode($strategy);

            if ($approval_mode !== 'none') {
                // Approval gate (Step 6/Decision 2): park the item in review and hand
                // the article to the EXISTING Approvals module as a new set. Publish
                // must NOT happen yet — it only runs once the set is fully approved
                // (see strategy/automations.php's action handler, Step 7). This is a
                // hand-off, not a reimplementation: no approval UI lives here.
                $set_id = self::create_approval_set_for_item($strategy, $item, $article_id, $user_id, $approval_mode);

                // Same compare-and-set as the completed branch below — a run
                // that lost its claim to a reclaim must not overwrite the item
                // state the new owner is producing. The approval set is already
                // created at this point, so a lost claim leaves that set
                // unlinked; logged rather than silently swallowed.
                if (!PCM_DB::complete_strategy_item_if_generating((int)$item->id, array(
                    'status'       => 'in_review',
                    'title'        => $title,
                    'slug'         => $slug,
                    'articleId'    => $article_id,
                    'setId'        => $set_id,
                    'errorMessage' => '',
                ))) {
                    error_log(sprintf(
                        '[PCM_Strategy_Service] Item #%d was reclaimed while it was still generating — discarding this run\'s result; approval set #%d is left unlinked.',
                        (int)$item->id,
                        (int)$set_id
                    ));
                }

                // ── 8. Recompute strategy counters (retry-safe). 'in_review' counts
                //       as neither completed nor failed — matches recompute_counters'
                //       existing completed/error-only tally. ──
                self::recompute_counters((int)$strategy->id, $user_id, (int)$strategy->totalItems);

                $response = array(
                    'item'    => PCM_DB::get_strategy_items((int)$strategy->id),
                    'article' => PCM_DB::get_article($article_id, $user_id),
                );
            } else {
                // Compare-and-set, not a blind write: if a stale-generating
                // reclaim handed this item to another generator while we were
                // still working (see PCM_DB::complete_strategy_item_if_generating),
                // we no longer own it — completing anyway would double-count the
                // item and, below, publish a SECOND post to the client's site.
                // Status semantics (2026-07-28): "completed" now means PUBLISHED, not
                // merely generated. A generated-but-unpublished item lands in 'written'
                // (blue) first; the auto-publish call below promotes it to 'completed'
                // (green) only when publish actually succeeds. Draft/no-site strategies
                // thus leave items 'written' forever — honest about "not yet published".
                $owned = PCM_DB::complete_strategy_item_if_generating((int)$item->id, array(
                    'status'       => 'written',
                    'title'        => $title,
                    'slug'         => $slug,
                    'articleId'    => $article_id,
                    'errorMessage' => '',
                ));
                if (!$owned) {
                    error_log(sprintf(
                        '[PCM_Strategy_Service] Item #%d was reclaimed while it was still generating — discarding this run\'s result (article #%d stays an unlinked draft) and NOT publishing.',
                        (int)$item->id,
                        (int)$article_id
                    ));
                    self::recompute_counters((int)$strategy->id, $user_id, (int)$strategy->totalItems);
                    // Still re-arm: siblings may be pending, and the winner
                    // could itself die. Without this a lost claim would leave
                    // the rest of the batch waiting a full sweep interval.
                    if ($item_id === null) {
                        self::maybe_schedule_queue_continuation((int)$strategy->id, $user_id);
                    }
                    return array(
                        'item'      => PCM_DB::get_strategy_items((int)$strategy->id),
                        'article'   => PCM_DB::get_article($article_id, $user_id),
                        'discarded' => true,
                    );
                }

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
                $publish = self::maybe_auto_publish($publish_strategy, PCM_DB::get_article($article_id, $user_id), $user_id, $item);

                // Promote 'written' → 'completed' ONLY when publish actually succeeded
                // (null = publish not applicable e.g. draft/no-site; success=false = tried
                // and failed). Both non-success cases leave the item 'written' so the UI
                // honestly shows "generated, not yet published" instead of a green check.
                if ($publish !== null && !empty($publish['success'])) {
                    PCM_DB::update_strategy_item((int)$item->id, array('status' => 'completed'));
                    self::recompute_counters((int)$strategy->id, $user_id, (int)$strategy->totalItems);
                }

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
            $wedge_clean = true;
            return $response;

        } catch (\Throwable $e) {
            // The catch handled the failure cleanly — don't let the shutdown net
            // double-mark an item this block already wrote 'error' to.
            $wedge_clean = true;
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
                // Suggest topics in the site's BRAND language, not English. Best-effort:
                // an unlinked site (or a brand with no language) yields '' → unchanged.
                $sched_language = '';
                if (!empty($site->brandId)) {
                    $sched_brand = PCM_DB::get_brand_by_id((int) $site->brandId, $user_id);
                    if ($sched_brand) {
                        $sched_language = trim((string) ($sched_brand->language ?? ''));
                    }
                }
                $topics = PCM_Topic_Suggester::suggest($user_id, array(
                    'niche'    => (string) ($rule['niche'] ?? ''),
                    'siteName' => (string) ($site->name ?? ''),
                    'siteUrl'  => (string) ($site->url ?? ''),
                    'language' => $sched_language,
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

    // =========================================================================
    // RSS WATCHER (Filip's Source=RSS strategies)
    // =========================================================================
    //
    // Hourly wp-cron scan (hook 'pcm_strategy_rss_scan', wired + armed at the
    // bottom of this file) that turns NEW feed items into strategy items, which
    // then ride the EXISTING generation pipeline unchanged
    // (maybe_schedule_queue_continuation() → run_queue_tick() →
    // generate_next_item() → maybe_auto_publish()). All watcher state lives in
    // the strategy's config JSON per the frozen contract — rssSeen (GUID hashes,
    // cap 200) and rssQueue ({guid,title,link,ts}, freshest-first, cap 10) — so
    // the DB schema stays untouched. The impure edges (feed fetch, DB writes)
    // are kept thin; the decision logic below is pure static seams, directly
    // unit-tested (StrategyRssWatcherTest).

    /**
     * Hourly wp-cron callback: scan every RSS-sourced strategy for new feed
     * items. Per-strategy failure isolation (same contract as
     * run_site_schedules()): one strategy's dead feed or DB hiccup must not
     * stop the others.
     */
    public static function run_rss_scan(): void
    {
        // Health stamp read by the cron-info route and the UI — "when did
        // scanning actually last run", regardless of what fired it (wp-cron,
        // the keep-alive chain, or a manual trigger).
        if (function_exists('update_option') && function_exists('current_time')) {
            update_option('pcm_rss_last_scan', current_time('mysql'), false);
        }

        // Third re-arm lane: any wp-cron execution of this scan resurrects a
        // dead keep-alive chain. Stale-gated, so a scan running INSIDE a live
        // link (which beat seconds ago) never double-spawns.
        if (function_exists('get_option')
            && (time() - (int) get_option('pcm_keepalive_beat', 0)) > 120) {
            self::spawn_keepalive();
        }

        // Social strategies ride the SAME per-strategy watcher pass — their
        // converted rssFeeds are fetched every pass, and their Apify-watched
        // accounts on the ≥4h cost-controlled branch inside scan_rss_strategy().
        foreach (array_merge(PCM_DB::get_rss_strategies(), self::get_social_strategies()) as $strategy) {
            try {
                self::scan_rss_strategy($strategy);
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[PCM_Strategy_Service] RSS scan for strategy #%d failed: %s',
                    (int)($strategy->id ?? 0),
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * All non-paused Source=Social strategies — the social twin of
     * PCM_DB::get_rss_strategies() (same LIKE pre-filter shape on the config
     * JSON; scan_rss_strategy() re-verifies the decoded sourceMode, so a LIKE
     * false positive is harmless). Lives here rather than in PCM_DB to keep
     * this round's diff inside the strategy module; degrades to an empty list
     * when the full $wpdb surface is absent (unit-test harness).
     *
     * @return object[] Strategy rows.
     */
    private static function get_social_strategies(): array
    {
        global $wpdb;
        if (!is_object($wpdb ?? null)
            || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'esc_like')
            || !class_exists('PCM_Schema')
        ) {
            return array();
        }
        $table = PCM_Schema::table('strategies');
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE config LIKE %s AND status NOT IN ('paused','completed')",
                '%' . $wpdb->esc_like('"sourceMode":"social"') . '%'
            )
        );
        return is_array($rows) ? $rows : array();
    }

    /**
     * One-off create-time first scan for a single RSS strategy (wp-cron
     * callback for 'pcm_strategy_rss_first_scan', armed by
     * create_from_keywords()). Runs the exact same per-strategy watcher pass
     * as the hourly run_rss_scan() — a brand-new strategy's rssSeen is empty,
     * so the pass ingests the feed's current items freshest-first and
     * generates from the newest EXISTING post immediately, instead of waiting
     * for the next hourly tick. Deliberately does NOT stamp
     * pcm_rss_last_scan: that health option means "the GLOBAL scan ran", and
     * this is a single-strategy pass.
     *
     * @param int $strategy_id Strategy ID (from the scheduled event args).
     * @param int $user_id     Owner ID (ownership-checked via get_strategy()).
     */
    public static function run_rss_first_scan(int $strategy_id, int $user_id): void
    {
        $strategy = PCM_DB::get_strategy($strategy_id, $user_id);
        if (!$strategy) {
            return; // deleted (or never owned by this user) since the event was armed
        }
        // Re-verify the source config — same guards scan_rss_strategy() applies
        // (the config may have been edited between arming and firing). Social
        // strategies arm this same event when their account links converted to
        // native feeds, so 'social' passes too — with either feeds OR watched
        // Apify accounts as the thing to scan.
        $config = !empty($strategy->config) ? json_decode((string)$strategy->config, true) : null;
        if (!is_array($config) || !in_array(($config['sourceMode'] ?? ''), array('rss', 'social'), true)) {
            return;
        }
        $feeds    = $config['rssFeeds'] ?? null;
        $accounts = $config['socialAccounts'] ?? null;
        if ((!is_array($feeds) || $feeds === array())
            && (!is_array($accounts) || $accounts === array())
        ) {
            return;
        }
        try {
            self::scan_rss_strategy($strategy);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[PCM_Strategy_Service] RSS first scan for strategy #%d failed: %s',
                $strategy_id,
                $e->getMessage()
            ));
        }
    }

    /**
     * One strategy's full watcher pass: duration gate → fetch feeds → ingest
     * new items into the config queue → backpressure-limited pop → insert
     * pending strategy items → arm the existing background queue → persist the
     * updated watcher state (rssSeen/rssQueue) back onto the config JSON.
     *
     * @param object $strategy Strategy DB row (from get_rss_strategies()).
     * @param bool   $force    When true, bypass the social 4h cadence gate so a
     *                         manual "Scan now" pulls immediately. The duration
     *                         and backpressure gates always still apply — force
     *                         overrides only the every-4h Apify cadence, never
     *                         the user's volume/limit rules.
     * @return int Number of new pending strategy items created this pass.
     */
    private static function scan_rss_strategy(object $strategy, bool $force = false): int
    {
        $config = !empty($strategy->config) ? json_decode((string)$strategy->config, true) : null;
        if (!is_array($config)
            || !in_array(($config['sourceMode'] ?? ''), array('rss', 'social'), true)
        ) {
            return 0; // LIKE pre-filter false positive — not a watched source
        }
        $feeds    = is_array($config['rssFeeds'] ?? null) ? $config['rssFeeds'] : array();
        $accounts = is_array($config['socialAccounts'] ?? null) ? $config['socialAccounts'] : array();
        if ($feeds === array() && $accounts === array()) {
            return 0; // nothing to watch
        }

        $strategy_id = (int)$strategy->id;
        $user_id     = (int)$strategy->userId;

        // ── Duration gate: an expired 'until' date or a reached 'limit' cap
        //    stops the watcher outright (no fetch, no new items) — a manual
        //    scan never overrides this business rule. ──
        $item_count = PCM_DB::count_strategy_items($strategy_id);
        if (self::rss_duration_blocked($config, $item_count)) {
            return 0;
        }

        // ── Fetch + ingest: every new feed item lands in rssQueue (and its
        //    guid in rssSeen) regardless of backpressure — slots only gate how
        //    many become strategy items THIS pass. ──
        $config = self::ingest_feed_items(self::fetch_rss_feed_items($feeds), $config);

        // ── Social branch: Apify-watched accounts are scanned at most every
        //    4h (Apify bills per result — hourly would burn credits for
        //    nothing), 10 items per account per scan. The mapped items feed
        //    the SAME ingest path the rss items use, so seen-dedupe, the
        //    queue cap and backpressure all apply identically; their queue
        //    entries additionally carry the post text + a social flag the pop
        //    below writes into the item config for the social prompt rider.
        //    PCM_Apify degrades to [] on ANY failure, so a dead actor/token
        //    can never break the pass. lastSocialScan stamps site-local
        //    current_time like the rss health stamp, and persists with the
        //    same config write below. ──
        if ($accounts !== array()
            && ($force || self::social_scan_due((string)($config['lastSocialScan'] ?? ''), (int)strtotime(current_time('mysql'))))
        ) {
            if (!class_exists('PCM_Social_Source', false)) {
                require_once __DIR__ . '/class-pcm-social-source.php';
            }
            if (!class_exists('PCM_Apify', false)) {
                require_once __DIR__ . '/class-pcm-apify.php';
            }
            // Stamp + persist BEFORE fetching: a host wall-clock kill mid-call
            // must not leave the branch "still due" — that would re-run the
            // paid Apify actors every pass in a billing loop while never
            // completing (adversarial-review P1). At-most-once per 4h window
            // even under repeated kills; a lost window self-heals next pass.
            $config['lastSocialScan'] = current_time('mysql');
            PCM_DB::update_strategy((int)$strategy->id, $user_id, array('config' => wp_json_encode($config)));

            $social_raw = array();
            foreach ($accounts as $account) {
                if (!is_array($account)) {
                    continue;
                }
                $account_url      = trim((string)($account['url'] ?? ''));
                $account_platform = trim((string)($account['platform'] ?? ''));
                if ($account_url === '' || $account_platform === '') {
                    continue;
                }
                $request = PCM_Social_Source::apify_request($account_platform, $account_url, 10);
                if ($request === null) {
                    continue; // not an Apify platform — nothing to fetch
                }
                @set_time_limit(120); // headroom per account against CPU caps
                $items = PCM_Apify::fetch_account_items($request, $user_id); // [] on any failure
                foreach (PCM_Social_Source::apify_map_items($account_platform, $items) as $mapped) {
                    $mapped['social'] = true; // rides ingest → pop → item config
                    $social_raw[] = $mapped;
                }
            }
            if ($social_raw !== array()) {
                $config = self::ingest_feed_items($social_raw, $config);
            }
        }

        // ── Drip-publish detection: a Source=RSS/Social strategy set to publish
        //    "On a schedule" holds each generated article and releases it on the
        //    recurrence. The recurrence — NOT perWeek — is the cadence, so we pop
        //    the whole queue and stamp each new item a future scheduledDate; the
        //    daily scheduled scan then generates+publishes it on its slot. ──
        $schedule_cfg = (($strategy->publishingMode ?? '') === 'schedule'
            && is_array($config['scheduleConfig'] ?? null))
            ? $config['scheduleConfig'] : null;

        $duration = is_array($config['duration'] ?? null) ? $config['duration'] : array();
        if ($schedule_cfg !== null) {
            // Schedule paces publishing → bypass the perWeek backpressure; still
            // honor a 'limit' duration so we never schedule past maxArticles.
            $slots = is_array($config['rssQueue'] ?? null) ? count($config['rssQueue']) : 0;
            if ((string)($duration['mode'] ?? '') === 'limit' && (int)($duration['maxArticles'] ?? 0) > 0) {
                $slots = min($slots, max(0, (int)$duration['maxArticles'] - $item_count));
            }
        } else {
            // ── Backpressure: the cadence cap minus items created in the trailing
            //    window, whose length now follows the cadence UNIT (day/week/month)
            //    instead of always being 7 days; a 'limit' duration additionally caps
            //    this pass so the watcher can never insert PAST maxArticles. ──
            $window_days = self::rss_cadence_window_days($config);
            $window_ago  = date('Y-m-d H:i:s', (int)strtotime(current_time('mysql')) - $window_days * 86400);
            $slots = self::rss_free_slots(PCM_DB::count_strategy_items($strategy_id, $window_ago), $config);
            if ((string)($duration['mode'] ?? '') === 'limit' && (int)($duration['maxArticles'] ?? 0) > 0) {
                $slots = min($slots, max(0, (int)$duration['maxArticles'] - $item_count));
            }
        }

        // ── Pop freshest-first into pending strategy items. keyword = the feed
        //    item's title; the item config carries the source context the
        //    generation prompt's RSS rider reads back (rss_source_instruction()). ──
        $pop      = self::rss_pop_due_items($config, $slots);
        $config   = $pop['config'];
        $inserted = 0;

        // Drip-publish: precompute the recurrence slots for the items about to be
        // created. Dates are computed from the ORIGINAL start over (alreadyScheduled
        // + newBatch) so each new item deterministically lands in the NEXT open slot;
        // a slot past an `ends` cap comes back null → that item keeps a null
        // scheduledDate and stays pending (never published), which stops the drip.
        $sched_dates = array();
        $sched_i     = 0;
        if ($schedule_cfg !== null && $pop['popped'] !== array()) {
            $already = PCM_DB::count_scheduled_strategy_items($strategy_id);
            // The anchor MUST be a FIXED point, not now: the tail is sliced at
            // [already..] and `already` grows every scan, so re-anchoring at
            // current_time() each pass would push every new item `already`
            // intervals into the future and the drip would drift/stall. An empty
            // startDate falls back to the strategy's createdAt (stable), NOT now.
            $start_date = !empty($schedule_cfg['startDate'])
                ? (string)$schedule_cfg['startDate']
                : (string)($strategy->createdAt ?? current_time('mysql'));
            $all_dates   = self::calculate_recurrence_dates($already + count($pop['popped']), $schedule_cfg, $start_date);
            $sched_dates = array_slice($all_dates, $already); // the tail = this batch's slots
        }

        foreach ($pop['popped'] as $entry) {
            $keyword = trim((string)($entry['title'] ?? ''));
            if ($keyword === '') {
                $keyword = trim((string)($entry['link'] ?? ''));
            }
            if ($keyword === '') {
                continue; // nothing usable as a keyword — drop (guid already seen)
            }
            $item_cfg = array(
                'sourceLink'  => (string)($entry['link'] ?? ''),
                'sourceTitle' => (string)($entry['title'] ?? ''),
            );
            // Queue entries carry the post text — written into the item config
            // for the social prompt rider, {{ post_content }}, and (social only)
            // featured-image reuse. RSS entries populate `text` from the feed
            // entry's description; only `social`/`image` remain social-only.
            if (isset($entry['text']) && (string)$entry['text'] !== '') {
                $item_cfg['sourceText'] = (string)$entry['text'];
            }
            if (!empty($entry['social'])) {
                $item_cfg['social'] = true;
            }
            $source_image = trim((string)($entry['image'] ?? ''));
            if ($source_image !== '') {
                $item_cfg['sourceImage'] = $source_image;
            }
            $item_id = PCM_DB::create_rss_strategy_item($strategy_id, $user_id, $keyword, $item_cfg);
            if ($item_id) {
                $inserted++;
                // Drip-publish: stamp this item's release slot. A null slot (past
                // the recurrence's `ends` cap) leaves scheduledDate null → the item
                // stays pending and is never published, exactly like the create-path.
                if ($schedule_cfg !== null) {
                    $slot = $sched_dates[$sched_i] ?? null;
                    if ($slot !== null) {
                        PCM_DB::update_strategy_item((int)$item_id, array('scheduledDate' => $slot));
                    }
                    $sched_i++;
                }
            }
        }

        // ── Persist the updated watcher state (rssSeen/rssQueue) — the WHOLE
        //    merged config, so every other key survives byte-for-byte. Skipped
        //    when nothing changed, so idle strategies aren't rewritten hourly.
        //    totalItems tracks the real item count so recompute_counters()'s
        //    completed>=total logic stays honest as the watcher appends. ──
        $update      = array();
        $config_json = wp_json_encode($config);
        if ($config_json !== (string)($strategy->config ?? '')) {
            $update['config'] = $config_json;
        }
        if ($inserted > 0) {
            $update['totalItems'] = PCM_DB::count_strategy_items($strategy_id);
        }
        if ($update !== array()) {
            PCM_DB::update_strategy($strategy_id, $user_id, $update);
        }

        // ── Kick the EXISTING background queue exactly once — it dedupes
        //    itself and generates the new pending items one tick at a time.
        //    SKIPPED for drip-publish (schedule mode): those items carry a future
        //    scheduledDate and are generated+published by the daily scheduled scan
        //    on their due date, not immediately (mirrors create_from_keywords). ──
        if ($inserted > 0 && $schedule_cfg === null) {
            self::maybe_schedule_queue_continuation($strategy_id, $user_id);
        }

        return $inserted;
    }

    /**
     * Force an immediate watcher pass for ONE strategy — the "Scan now" button.
     *
     * Bypasses only the social 4h cadence gate (force=true); the duration and
     * weekly-backpressure gates still apply, so a manual scan can pull new
     * posts on demand but never past the user's volume or article-limit rules.
     * Runs the fetch synchronously (an Instagram account scan can take ~40-60s
     * via Apify), so it raises the time limit and is only ever reached from an
     * authenticated admin click — never a background/public path.
     *
     * @param int $strategy_id Strategy ID (ownership already checked by caller).
     * @param int $user_id     Owner PCM user ID.
     * @return array{created:int} Count of new pending items created this pass.
     */
    public static function scan_strategy_now(int $strategy_id, int $user_id): array
    {
        $strategy = PCM_DB::get_strategy($strategy_id, $user_id);
        if (!$strategy) {
            return array('created' => 0);
        }
        @set_time_limit(180); // a social (Apify) pass can hold ~40-60s per account

        $config = !empty($strategy->config) ? json_decode((string)$strategy->config, true) : array();
        $config = is_array($config) ? $config : array();
        $feeds    = is_array($config['rssFeeds'] ?? null) ? $config['rssFeeds'] : array();
        $accounts = is_array($config['socialAccounts'] ?? null) ? $config['socialAccounts'] : array();
        $links    = is_array($config['socialLinks'] ?? null) ? $config['socialLinks'] : array();

        // ── Self-heal a "zombie" social strategy: sourceMode=social with pasted
        //    links but NEITHER watched accounts NOR converted feeds persisted.
        //    That is the create-timeout failure shape (the old build could die
        //    mid-create after saving socialLinks but before split_social_links
        //    persisted the derived accounts/feeds) — the watcher then scans
        //    nothing forever. Re-running the split here classifies the links
        //    again, persists accounts/feeds, creates post items, and arms the
        //    first pull — one click repairs the strategy. Idempotent: with
        //    accounts or feeds already present this branch never runs.
        $healed = 0;
        if (($config['sourceMode'] ?? '') === 'social'
            && $accounts === array() && $feeds === array() && $links !== array()
        ) {
            $pre_heal = PCM_DB::count_strategy_items($strategy_id);
            self::split_social_links($strategy_id, $user_id, $config);
            // Post links become items directly inside the split (not via the
            // scan below) — count them into this scan's reported total.
            $healed   = max(0, PCM_DB::count_strategy_items($strategy_id) - $pre_heal);
            // The split armed a background first-scan, but we scan synchronously
            // right below — cancel it so the keep-alive chain can't run the SAME
            // Apify fetch concurrently (a duplicate paid run, and a second
            // sync request the plan may reject).
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook('pcm_strategy_rss_first_scan', array($strategy_id, $user_id));
            }
            $strategy = PCM_DB::get_strategy($strategy_id, $user_id); // reload the repaired config
            if (!$strategy) {
                return array('created' => $healed);
            }
            $config   = !empty($strategy->config) ? json_decode((string)$strategy->config, true) : array();
            $config   = is_array($config) ? $config : array();
            $feeds    = is_array($config['rssFeeds'] ?? null) ? $config['rssFeeds'] : array();
            $accounts = is_array($config['socialAccounts'] ?? null) ? $config['socialAccounts'] : array();
        }

        if (!class_exists('PCM_Apify', false)) {
            require_once __DIR__ . '/class-pcm-apify.php';
        }
        PCM_Apify::$last_error = null;

        $created = self::scan_rss_strategy($strategy, true) + $healed;
        if ($created > 0) {
            return array('created' => $created);
        }

        // ── Zero items: say WHY, so a prod misconfiguration is visible in the
        //    toast instead of hiding behind "no new posts". Checked in order of
        //    how definitively each explains the zero. ──
        if ($feeds === array() && $accounts === array()) {
            return array('created' => 0, 'reason' => 'no_sources');
        }
        if (self::rss_duration_blocked($config, PCM_DB::count_strategy_items($strategy_id))) {
            return array('created' => 0, 'reason' => 'duration_complete');
        }
        if ($accounts !== array() && !PCM_Apify::has_key($user_id)) {
            return array('created' => 0, 'reason' => 'no_apify_key');
        }
        if (PCM_Apify::$last_error !== null) {
            return array('created' => 0, 'reason' => 'fetch_failed', 'detail' => PCM_Apify::$last_error);
        }
        // Posts were found (or already known) but the weekly volume cap left no
        // slot this pass — they wait in the queue for the next free slot.
        $fresh = PCM_DB::get_strategy($strategy_id, $user_id);
        $fresh_cfg = ($fresh && !empty($fresh->config)) ? json_decode((string)$fresh->config, true) : array();
        if (is_array($fresh_cfg) && count($fresh_cfg['rssQueue'] ?? array()) > 0) {
            return array('created' => 0, 'reason' => 'volume_capped');
        }
        return array('created' => 0, 'reason' => 'no_new_posts');
    }

    /**
     * Fetch raw items from every configured feed URL via WP-core SimplePie
     * (fetch_feed()), capped at ~20 items per feed, with the feed cache
     * shortened to ~15 minutes for the duration of the calls (the watcher runs
     * hourly; core's 12-hour default would make it near-blind). A dead feed
     * (WP_Error) skips THAT feed only — the other feeds still contribute.
     *
     * Impure edge — deliberately returns plain scalar arrays so everything
     * downstream (ingest_feed_items()) is pure and unit-testable without
     * SimplePie.
     *
     * @param string[] $feeds Feed URLs (already esc_url_raw'd at config write).
     * @return array<int,array{permalink:string,id:string,title:string,date:int}>
     */
    private static function fetch_rss_feed_items(array $feeds): array
    {
        if (!function_exists('fetch_feed')) {
            if (!defined('ABSPATH') || !defined('WPINC') || !file_exists(ABSPATH . WPINC . '/feed.php')) {
                return array();
            }
            include_once ABSPATH . WPINC . '/feed.php';
        }
        if (!function_exists('fetch_feed')) {
            return array();
        }

        $shorten = static function () {
            return 900; // ~15 min — fresh enough for an hourly watcher
        };
        add_filter('wp_feed_cache_transient_lifetime', $shorten);
        $raw = array();
        try {
            foreach ($feeds as $url) {
                $url = trim((string)$url);
                if ($url === '') {
                    continue;
                }
                $feed = fetch_feed($url);
                if (is_wp_error($feed) || !is_object($feed)) {
                    continue; // dead feed — skip this feed only
                }
                $quantity = $feed->get_item_quantity(20);
                $items    = $quantity > 0 ? $feed->get_items(0, $quantity) : array();
                foreach ((array)$items as $item) {
                    $raw[] = array(
                        'permalink' => (string)$item->get_permalink(),
                        'id'        => (string)$item->get_id(),
                        'title'     => (string)$item->get_title(),
                        'date'      => (int)$item->get_date('U'),
                        // The feed entry's own summary — this is what makes
                        // {{ post_content }} real for an RSS strategy. It rides
                        // the SAME `text` key the social branch already uses, so
                        // ingest → pop → sourceText needs no further change
                        // (ingest sanitizes + caps it at 1000 chars, which also
                        // strips the HTML feeds put in a description).
                        'text'      => (string)$item->get_description(),
                    );
                }
            }
        } finally {
            remove_filter('wp_feed_cache_transient_lifetime', $shorten);
        }
        return $raw;
    }

    /**
     * Pure ingest seam: fold freshly-fetched raw feed items into the config's
     * watcher state per the frozen contract.
     *
     *   - guid = md5(permalink ?: id ?: title) — skipped when already in
     *     rssSeen or already queued.
     *   - queue entry {guid, title (sanitized, cap 200), link (esc_url_raw),
     *     ts (item date unix, or "now" when the feed omits one)}. Social
     *     (Apify) raw items may additionally carry `text` (cap 1000) and a
     *     `social` flag — both ride the queue entry ONLY when present, so
     *     plain RSS entries keep their exact historical shape (absent keys,
     *     never empty strings).
     *   - every NEWLY ingested guid is marked seen immediately — queue
     *     membership dedupes in-flight items, rssSeen dedupes history, and
     *     popping never has to write back to the seen list. Queue overflow
     *     (cap 10) is thereby "marked seen anyway": the dropped entries'
     *     guids stay in rssSeen so they are never re-ingested.
     *   - rssQueue normalized freshest-first (ts DESC), deduped by guid, cap 10.
     *   - rssSeen capped at the 200 NEWEST guids (append order).
     *
     * @param array $raw_items Items shaped like fetch_rss_feed_items() output.
     * @param array $config    Decoded strategy config.
     * @return array The config with rssQueue/rssSeen updated (all other keys untouched).
     */
    public static function ingest_feed_items(array $raw_items, array $config): array
    {
        $seen = array();
        foreach ((array)($config['rssSeen'] ?? array()) as $guid) {
            if (is_string($guid) && $guid !== '') {
                $seen[] = $guid;
            }
        }
        $queue = array();
        $queued_guids = array();
        foreach ((array)($config['rssQueue'] ?? array()) as $entry) {
            if (is_array($entry) && !empty($entry['guid'])) {
                $guid = (string)$entry['guid'];
                $normalized = array(
                    'guid'  => $guid,
                    'title' => (string)($entry['title'] ?? ''),
                    'link'  => (string)($entry['link'] ?? ''),
                    'ts'    => (int)($entry['ts'] ?? 0),
                );
                // Carry-through: text/social/image survive re-normalization ONLY
                // when present. `text` is now populated for rss entries too (the
                // feed description); social/image stay social-only.
                if (isset($entry['text']) && (string)$entry['text'] !== '') {
                    $normalized['text'] = (string)$entry['text'];
                }
                if (!empty($entry['social'])) {
                    $normalized['social'] = true;
                }
                if (isset($entry['image']) && (string)$entry['image'] !== '') {
                    $normalized['image'] = (string)$entry['image'];
                }
                $queue[] = $normalized;
                $queued_guids[$guid] = true;
            }
        }

        $now_ts = (int)strtotime(current_time('mysql'));
        foreach ($raw_items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $basis = trim((string)($item['permalink'] ?? ''));
            if ($basis === '') {
                $basis = trim((string)($item['id'] ?? ''));
            }
            if ($basis === '') {
                $basis = trim((string)($item['title'] ?? ''));
            }
            if ($basis === '') {
                continue; // nothing identifiable — unusable item
            }
            $guid = md5($basis);
            if (in_array($guid, $seen, true) || isset($queued_guids[$guid])) {
                continue; // already ingested on a previous scan (or earlier this one)
            }
            $ts = (int)($item['date'] ?? 0);
            $new_entry = array(
                'guid'  => $guid,
                'title' => mb_substr(sanitize_text_field((string)($item['title'] ?? '')), 0, 200),
                'link'  => esc_url_raw((string)($item['permalink'] ?? '')),
                'ts'    => $ts > 0 ? $ts : $now_ts,
            );
            // The post text rides through to the pop for BOTH sources (social
            // caption / rss description); the social flag and image are
            // social-only, so a plain rss entry keeps its historical shape
            // apart from `text`.
            $text = trim((string)($item['text'] ?? ''));
            if ($text !== '') {
                $new_entry['text'] = mb_substr(sanitize_text_field($text), 0, 1000);
            }
            if (!empty($item['social'])) {
                $new_entry['social'] = true;
            }
            $src_image = trim((string)($item['image'] ?? ''));
            if ($src_image !== '') {
                $new_entry['image'] = esc_url_raw($src_image);
            }
            $queue[] = $new_entry;
            $queued_guids[$guid] = true;
            $seen[] = $guid;
        }

        // Freshest-first, dedupe by guid (first = freshest wins), cap 10 —
        // overflow entries drop from the queue but their guids remain seen.
        usort($queue, static fn(array $a, array $b): int => (int)$b['ts'] <=> (int)$a['ts']);
        $deduped = array();
        $have = array();
        foreach ($queue as $entry) {
            if (isset($have[$entry['guid']])) {
                continue;
            }
            $have[$entry['guid']] = true;
            $deduped[] = $entry;
        }

        $config['rssQueue'] = array_slice($deduped, 0, 10);
        $config['rssSeen']  = array_values(array_slice(array_values(array_unique($seen)), -200));
        return $config;
    }

    /**
     * Pure backpressure seam: how many NEW strategy items this pass may create
     * — the strategy's perWeek cadence (config.rssCadence.perWeek, default 3,
     * clamped 1–21 to mirror the controller's sanitize clamp) minus how many
     * items were already created in the trailing 7-day window. Never negative.
     *
     * @param int   $recent_count Items created on this strategy in the last 7 days.
     * @param array $config       Decoded strategy config.
     * @return int Free slots (>= 0).
     */
    public static function rss_free_slots(int $recent_count, array $config): int
    {
        $per_week = 3;
        $cadence  = $config['rssCadence'] ?? null;
        if (is_array($cadence) && isset($cadence['perWeek'])) {
            $per_week = max(1, min(21, (int)$cadence['perWeek']));
        }
        return max(0, $per_week - max(0, $recent_count));
    }

    /**
     * How many DAYS the cadence cap is measured over — the trailing window the
     * "already created" count is taken from. Driven by `config.rssCadence.unit`
     * ('day'|'week'|'month'); anything missing or unrecognized falls back to 7,
     * which is the behaviour every config had before the unit existed.
     *
     * @param array $config Decoded strategy config.
     * @return int Window length in days (1 | 7 | 30).
     */
    public static function rss_cadence_window_days(array $config): int
    {
        $cadence = $config['rssCadence'] ?? null;
        $unit    = is_array($cadence) ? (string)($cadence['unit'] ?? 'week') : 'week';
        switch ($unit) {
            case 'day':   return 1;
            case 'month': return 30;
            default:      return 7;
        }
    }

    /**
     * Pure pop seam: take up to $slots entries off the FRONT of the queue
     * (freshest-first — the whole queue is re-sorted ts DESC defensively, so a
     * hand-edited/legacy config still pops newest work first) and return both
     * the popped entries and the config with the shrunken queue. Popped guids
     * need no rssSeen write-back — ingest_feed_items() already marked every
     * queued guid seen.
     *
     * @param array $config Decoded strategy config.
     * @param int   $slots  Max entries to pop (<= 0 pops nothing).
     * @return array{popped: array<int,array>, config: array}
     */
    public static function rss_pop_due_items(array $config, int $slots): array
    {
        $queue = array();
        foreach ((array)($config['rssQueue'] ?? array()) as $entry) {
            if (is_array($entry) && !empty($entry['guid'])) {
                $queue[] = $entry;
            }
        }
        usort($queue, static fn(array $a, array $b): int => (int)($b['ts'] ?? 0) <=> (int)($a['ts'] ?? 0));
        $slots = max(0, $slots);
        $config['rssQueue'] = array_values(array_slice($queue, $slots));
        return array(
            'popped' => array_slice($queue, 0, $slots),
            'config' => $config,
        );
    }

    /**
     * Pure duration seam (config.duration, frozen contract): whether the
     * watcher must stop creating items for this strategy.
     *
     *   - mode 'until':  blocked once the endDate has fully passed (the end
     *     date itself still counts — comparison is against endDate 23:59:59).
     *   - mode 'limit':  blocked once the strategy's item count has reached
     *     maxArticles.
     *   - mode 'ongoing' (or absent/unknown): never blocked.
     *
     * "Now" comes from current_time('mysql') — resolved here (not injected)
     * to match the other config readers; unit tests drive it via the shared
     * current_time() fake.
     *
     * @param array $config     Decoded strategy config.
     * @param int   $item_count The strategy's current TOTAL item count.
     * @return bool True when the watcher must skip this strategy.
     */
    public static function rss_duration_blocked(array $config, int $item_count): bool
    {
        $duration = is_array($config['duration'] ?? null) ? $config['duration'] : array();
        $mode = (string)($duration['mode'] ?? 'ongoing');

        if ($mode === 'until') {
            $end = (string)($duration['endDate'] ?? '');
            $end_ts = $end !== '' ? strtotime($end . ' 23:59:59') : false;
            if ($end_ts !== false && (int)strtotime(current_time('mysql')) > (int)$end_ts) {
                return true;
            }
        }

        if ($mode === 'limit') {
            $max = (int)($duration['maxArticles'] ?? 0);
            if ($max > 0 && $item_count >= $max) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pure social-scan staleness seam (mirrors keepalive_rss_due()): whether
     * the watcher's Apify branch may scan this strategy's watched accounts —
     * true when lastSocialScan is absent/unparseable or ≥4 hours old. Apify
     * bills per result, so social accounts scan every ≥4h, NOT hourly like
     * the RSS feeds (cost control — see the plan's verified facts).
     *
     * @param string $last config.lastSocialScan ('' when never scanned).
     * @param int    $now  Site-local "now" as a unix timestamp.
     * @return bool True when the Apify branch is due.
     */
    public static function social_scan_due(string $last, int $now): bool
    {
        if ($last === '') {
            return true;
        }
        $ts = strtotime($last);
        return $ts === false || ($now - $ts) >= 4 * 3600;
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
     * Resolve the RESEARCH model + provider for a strategy. Research (the grounded /
     * deep passes) is a separate job from article writing, so it gets its own model:
     * `config.researchModel` / `config.researchProvider`. Falls back to the historical
     * hardcoded default (`gemini-2.5-flash`, no explicit provider) so strategies saved
     * before research-model selection existed behave EXACTLY as before.
     *
     * @param object $strategy Strategy DB row.
     * @return array{0:string,1:string} [model, provider] — provider '' if unset.
     */
    private static function resolve_research_model(object $strategy): array
    {
        $model    = 'gemini-2.5-flash';
        $provider = '';
        if (!empty($strategy->config)) {
            $cfg = json_decode((string)$strategy->config, true);
            if (is_array($cfg)) {
                if (!empty($cfg['researchModel']))    { $model    = (string)$cfg['researchModel']; }
                if (!empty($cfg['researchProvider'])) { $provider = (string)$cfg['researchProvider']; }
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
     * Draft-gate (Filip's Publishing split): a strategy whose `config.publishing`
     * is `'draft'` NEVER auto-publishes — this returns null right after the
     * publishingMode check, even when publishingMode is `'schedule'`/`'publish'`.
     * Absent/any-other value = the legacy behavior exactly, so pre-split
     * strategies are unaffected (zero back-compat break).
     *
     * @param object      $strategy Strategy DB row (publishingMode + config JSON).
     * @param object|null $article  The just-created article row. Nullable defensively —
     *   the caller re-fetches by the id it just inserted, so this should never actually
     *   be null, but a lookup miss must degrade to a reported failure, NOT a TypeError
     *   that would escape into generate_next_item()'s outer catch and wrongly flip a
     *   successfully-generated item to 'error' (that catch exists for GENERATION
     *   failures, not publish ones — see the isolation guarantee this method exists for).
     * @param int          $user_id Owner ID.
     * @param object|null  $item    The strategy item being published (optional). When
     *   present, its `scheduledDate` (if future) is forwarded as `schedule_date` and
     *   its `keyword` as a tag — parity with the manual publish_item() path. Callers
     *   that lack a single item (consolidated batch, approval completion) omit it and
     *   get the legacy no-options behavior, byte-identical.
     * @return array{success:bool,message:string}|null Null when publish doesn't apply
     *   (draft mode, or no site configured) — distinct from a failed attempt.
     */
    private static function maybe_auto_publish(object $strategy, ?object $article, int $user_id, ?object $item = null): ?array
    {
        if (!in_array((string)($strategy->publishingMode ?? ''), array('publish', 'schedule'), true)) {
            return null;
        }
        // Draft-gate (Filip's Publishing split): when the strategy's config opts
        // into 'draft' publishing, NEVER auto-publish — even in 'schedule'/'publish'
        // mode. Any absent/other value leaves the legacy behavior exactly intact
        // (this gate is a no-op), so pre-split strategies are unaffected.
        if (!empty($strategy->config)) {
            $publishing_gate = json_decode((string)$strategy->config, true);
            if (is_array($publishing_gate) && ($publishing_gate['publishing'] ?? '') === 'draft') {
                return null;
            }
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
            // Forward the item's scheduledDate + keyword when available — parity with
            // the manual publish_item() path (service.php ~4375-4385). A future
            // scheduledDate becomes a native WP 'future' post instead of publishing
            // immediately; the keyword becomes a tag. Callers with no $item (consolidated
            // batch, approval completion) pass nothing and get the legacy no-options call.
            $publish_options = array();
            if ($item) {
                $publish_options['tags'] = array((string)$item->keyword);
                if ((string)($strategy->publishingMode ?? '') === 'schedule' && !empty($item->scheduledDate)) {
                    $due_ts = strtotime((string)$item->scheduledDate);
                    if ($due_ts !== false && $due_ts > strtotime(current_time('mysql'))) {
                        $publish_options['schedule_date'] = (string)$item->scheduledDate;
                    }
                }
            }
            PCM_Sites_Service::publish_to_site($site, $article, $user_id, $publish_options);
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
     * @return string 'none'|'internal'|'client'|'both'.
     */
    private static function approval_mode(object $strategy): string
    {
        if (empty($strategy->config)) {
            return 'none';
        }
        $cfg  = json_decode((string)$strategy->config, true);
        $mode = is_array($cfg) ? (string)($cfg['approvalMode'] ?? 'none') : 'none';
        return in_array($mode, array('internal', 'client', 'both'), true) ? $mode : 'none';
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
    /**
     * The featured-image opt-in (config.featuredImages — default OFF, absent
     * means off). Extracted so the SOCIAL source-image path can honor the same
     * switch: that path bypasses maybe_generate_featured_image() entirely, so
     * the gate could no longer live only inside it.
     *
     * @param object $strategy Strategy DB row.
     * @return bool
     */
    private static function featured_images_enabled(object $strategy): bool
    {
        $cfg = !empty($strategy->config) ? json_decode((string)$strategy->config, true) : null;
        return is_array($cfg) && !empty($cfg['featuredImages']);
    }

    /**
     * The featured-image prompt. Uses the strategy's selected IMAGE TEMPLATE
     * (`config.imageTemplateId`, module 'image') when one is set, so the wording —
     * and therefore the style and language — is owned by the template author rather
     * than hardcoded here. Its prompt entries are concatenated and rendered with
     * {{ title }} / {{ keyword }} / {{ brand_language }}.
     *
     * Falls back to the ORIGINAL built-in sentence, byte-identical, when no template
     * is set, the id is stale/not readable, or the rendered text comes out empty —
     * an image prompt must never end up blank just because a template was deleted.
     *
     * @param object $strategy Strategy row.
     * @param string $title    Article title.
     * @param string $keyword  Target keyword.
     * @param int    $user_id  Owner (scopes the template read).
     * @return string Non-empty prompt.
     */
    /**
     * The strategy's Target Site id, or null when none is configured.
     *
     * Stamped onto every article the strategy generates so the article itself records
     * where it is meant to go. Without it a strategy-generated DRAFT reached the Writer
     * with `articles.siteId` NULL, so the Writer's Publish button stayed disabled
     * ("Select a Target Site in Settings") even though the strategy plainly had one —
     * the site was only ever written at publish time, which is too late to publish FROM.
     *
     * @param object $strategy Strategy row.
     * @return int|null Site id, or null when unset.
     */
    private static function strategy_site_id(object $strategy): ?int
    {
        if (empty($strategy->config)) {
            return null;
        }
        $cfg = json_decode((string)$strategy->config, true);
        $id  = is_array($cfg) && !empty($cfg['siteId']) ? (int)$cfg['siteId'] : 0;
        return $id > 0 ? $id : null;
    }

    private static function build_image_prompt(object $strategy, string $title, string $keyword, int $user_id): string
    {
        $default = sprintf(
            'Professional blog featured image for an article titled "%s" about %s — clean, modern, editorial photography, no text overlays.',
            $title,
            $keyword
        );

        $cfg = !empty($strategy->config) ? json_decode((string)$strategy->config, true) : null;
        $template_id = is_array($cfg) ? (int)($cfg['imageTemplateId'] ?? 0) : 0;
        if ($template_id <= 0) {
            return $default;
        }

        try {
            $template = self::load_template($template_id, $user_id);
        } catch (\Throwable $e) {
            // Deleted or foreign template — never break image generation over it.
            error_log('[PCM_Strategy_Service] Image template #' . $template_id . ' unavailable: ' . $e->getMessage());
            return $default;
        }

        $brand_language = '';
        if (!empty($strategy->brandId)) {
            $brand = PCM_DB::get_brand_by_id((int)$strategy->brandId, $user_id);
            if ($brand) {
                $brand_language = trim((string)($brand->language ?? ''));
            }
        }
        $vars = array(
            'title'          => $title,
            'keyword'        => $keyword,
            'brand_language' => $brand_language,
        );

        $parts = array();
        foreach ((array)($template['entries'] ?? array()) as $entry) {
            if (($entry['category'] ?? '') !== 'prompt') {
                continue;
            }
            $rendered = trim(self::render_template_vars((string)($entry['value'] ?? ''), $vars));
            if ($rendered !== '') {
                $parts[] = $rendered;
            }
        }

        $prompt = trim(implode("\n\n", $parts));
        return $prompt !== '' ? $prompt : $default;
    }

    private static function maybe_generate_featured_image(object $strategy, string $title, string $keyword, int $user_id): ?string
    {
        $cfg = !empty($strategy->config) ? json_decode((string)$strategy->config, true) : null;
        if (!self::featured_images_enabled($strategy)) {
            return null; // strategy didn't opt in
        }

        $prompt = self::build_image_prompt($strategy, $title, $keyword, $user_id);

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
     * The captured image of a social-source post (item config `sourceImage`) —
     * reused as the generated article's featured image INSTEAD of an AI one, so a
     * Source=Social strategy republishes the post's own image on the blog. Returns
     * null for non-social items or when no usable image was captured, so the caller
     * falls back to maybe_generate_featured_image() unchanged (byte-identical to
     * the pre-social-image behavior for every non-social / image-less item).
     *
     * Pure/deterministic — directly unit-testable.
     *
     * @param array $item_cfg Decoded ITEM config (item_config()'s output).
     * @return string|null Sanitized image URL, or null.
     */
    private static function social_source_image(array $item_cfg): ?string
    {
        if (empty($item_cfg['social'])) {
            return null;
        }
        $url = trim((string)($item_cfg['sourceImage'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }
        return function_exists('esc_url_raw') ? esc_url_raw($url) : $url;
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
     * Max in-content media assets per article (A6, `config.mediaCount`) — replaces
     * the old hard cap of 3. Default 3, clamped to 1–8 (mirrors the controller's
     * own sanitize clamp so a legacy/out-of-range stored value is still bounded).
     *
     * @param object $strategy Strategy DB row.
     * @return int 1–8.
     */
    private static function media_count(object $strategy): int
    {
        if (empty($strategy->config)) {
            return 3;
        }
        $cfg = json_decode((string)$strategy->config, true);
        if (!is_array($cfg) || !isset($cfg['mediaCount'])) {
            return 3;
        }
        return max(1, min(8, (int)$cfg['mediaCount']));
    }

    /**
     * Which in-content asset type(s) are allowed (A6, `config.mediaType`):
     * 'images' | 'charts' | 'both'. Default 'both'; any unrecognized/absent value
     * falls back to 'both'. Governs BOTH the prompt (instruct only the allowed
     * type) and the post-process gate (drop assets of a disallowed type).
     *
     * @param object $strategy Strategy DB row.
     * @return string 'images'|'charts'|'both'.
     */
    private static function media_type(object $strategy): string
    {
        if (empty($strategy->config)) {
            return 'both';
        }
        $cfg  = json_decode((string)$strategy->config, true);
        $type = is_array($cfg) ? (string)($cfg['mediaType'] ?? 'both') : 'both';
        return in_array($type, array('images', 'charts', 'both'), true) ? $type : 'both';
    }

    /**
     * Optional free-text creative direction for in-content media
     * (config.mediaGuidance): what kind of images to use and/or what data the
     * charts should show. Sanitized + capped at the controller; '' when unset.
     *
     * @param object $strategy Strategy DB row.
     * @return string
     */
    private static function media_guidance(object $strategy): string
    {
        if (empty($strategy->config)) {
            return '';
        }
        $cfg = json_decode((string)$strategy->config, true);
        return is_array($cfg) ? trim((string)($cfg['mediaGuidance'] ?? '')) : '';
    }

    /**
     * Optional angle/primary keyword every RSS rewrite is tailored to
     * (config.rssAngle, frozen contract) — same config-reader pattern as
     * media_guidance() above. Sanitized + capped at the controller; '' when
     * unset or the strategy isn't RSS-sourced.
     *
     * @param object $strategy Strategy DB row.
     * @return string
     */
    private static function rss_angle(object $strategy): string
    {
        if (empty($strategy->config)) {
            return '';
        }
        $cfg = json_decode((string)$strategy->config, true);
        return is_array($cfg) ? trim((string)($cfg['rssAngle'] ?? '')) : '';
    }

    /**
     * The RSS-source prompt rider: when the ITEM being generated was created
     * by the RSS watcher (its item config carries the {sourceLink, sourceTitle}
     * context written at insert time), steer the article to respond to — and
     * outdo — that specific feed item, optionally tailored to the strategy's
     * rssAngle. Returns '' for every non-RSS item, leaving the prompt
     * byte-identical to before (same optionality contract as media_guidance()).
     * Items whose config carries social=true (Source=Social post items, and
     * watcher items from Apify-watched accounts) get the social variant
     * instead — write ABOUT the post (quoting its sourceText when captured)
     * and visibly link back to it.
     *
     * Pure/deterministic — directly unit-testable (StrategyRssWatcherTest).
     *
     * @param array  $item_cfg  Decoded ITEM config (item_config()'s output).
     * @param string $rss_angle The strategy's rssAngle ('' when unset).
     * @return string Extra user-prompt instruction, or ''.
     */
    /**
     * Fetch a pasted social POST link's real caption via Apify — at generation
     * time only.
     *
     * WHY THIS EXISTS: Instagram, X, and Facebook killed public oEmbed years
     * ago, so post_context() (create-time) can't read a pasted post's caption
     * and stores only the bare URL. The article then gets written around a
     * naked link — the "post is not fetched" symptom. Apify CAN read these
     * posts (its run-sync actor returns the full caption for a single post
     * URL), but that call blocks ~30s, so it is unsafe in the user-facing
     * create path and MUST run here, in the BACKGROUND generation path.
     *
     * SCOPE — only URL-targeted Apify platforms:
     *   - instagram (directUrls), x (startUrls), facebook (startUrls) accept
     *     the pasted post URL directly, so the fetched post IS the pasted one.
     *   - tiktok's actor is PROFILE-based (input is a handle, not a post URL),
     *     so a single-post fetch would return the account's LATEST video, not
     *     the pasted one — we deliberately skip it and keep the bare link
     *     rather than write about the wrong video.
     *   - free platforms (youtube/bluesky/reddit) never reach here — their
     *     oEmbed still works, so sourceText is already populated.
     *
     * Cheap-exit + idempotent: fires only for social items whose sourceText is
     * still empty AND whose link is a URL-targeted Apify platform AND when the
     * owner has an active Apify key. The fetched caption is persisted back onto
     * the item, so a re-run (or a later publish) never re-bills Apify.
     * Degrades safely: any failure returns the config unchanged and generation
     * proceeds around the bare link exactly as before.
     *
     * @param object $item     The strategy item row (needs ->id).
     * @param array  $item_cfg Decoded item config (item_config()'s output).
     * @param int    $user_id  Owner PCM user id whose Apify token is used.
     * @return array The item config, enriched with sourceText/sourceTitle when the fetch succeeds.
     */
    private static function maybe_enrich_social_post(object $item, array $item_cfg, int $user_id): array
    {
        if (empty($item_cfg['social'])) {
            return $item_cfg;
        }
        $link = trim((string)($item_cfg['sourceLink'] ?? ''));
        if ($link === '') {
            return $item_cfg;
        }
        // Caption already captured, or Apify has already ANSWERED for this post
        // — nothing to fetch.
        //
        // The re-bill guard is `socialEnriched` (set below once Apify actually
        // replies), NOT the presence of sourceImage. Keying it off sourceImage
        // permanently lost the caption: create-time post_context() stores an
        // og:image with an EMPTY sourceText whenever the page ships an image
        // card without a description, which is routine — the item then looked
        // "already enriched", Apify was never called, sourceTitle kept its URL
        // placeholder, and the article got written from a bare link.
        if (trim((string)($item_cfg['sourceText'] ?? '')) !== ''
            || !empty($item_cfg['socialEnriched'])
        ) {
            return $item_cfg;
        }

        if (!class_exists('PCM_Social_Source', false)) {
            require_once __DIR__ . '/class-pcm-social-source.php';
        }
        if (!class_exists('PCM_Apify', false)) {
            require_once __DIR__ . '/class-pcm-apify.php';
        }

        $classified = PCM_Social_Source::classify($link);
        $platform   = (string)($classified['platform'] ?? 'unknown');

        // Only platforms whose Apify input targets the exact post URL — see the
        // SCOPE note above (tiktok's profile-based actor is intentionally out).
        if (!in_array($platform, array('instagram', 'x', 'facebook'), true)) {
            return $item_cfg;
        }
        $request = PCM_Social_Source::apify_request($platform, $link, 1);
        if ($request === null) {
            return $item_cfg;
        }
        if (!PCM_Apify::has_key($user_id)) {
            error_log(sprintf(
                '[PCM_Strategy_Service] Social post item #%d (%s) needs Apify to read its caption, but no active Apify key is set for user %d — generating around the bare link.',
                (int)$item->id,
                $platform,
                $user_id
            ));
            return $item_cfg;
        }

        @set_time_limit(120);
        $raw    = PCM_Apify::fetch_account_items($request, $user_id); // [] on any failure
        $mapped = PCM_Social_Source::apify_map_items($platform, $raw);
        if ($mapped === array()) {
            return $item_cfg; // fetch failed — unchanged behavior (bare link)
        }

        // Apify answered for this post — mark it so a re-run never re-bills,
        // even when the post genuinely carries no caption. This, not the
        // presence of an image, is the idempotency key.
        $item_cfg['socialEnriched'] = true;

        $text  = trim((string)($mapped[0]['text'] ?? ''));
        $image = trim((string)($mapped[0]['image'] ?? ''));
        if ($text !== '') {
            $item_cfg['sourceText'] = $text;
        }
        // The post's own media URL is reused as the generated article's featured
        // image — captured here for the Apify platforms whose og:image is blocked.
        // Never overwrite an image the create-time og:image scrape already found.
        if ($image !== '' && trim((string)($item_cfg['sourceImage'] ?? '')) === '') {
            $item_cfg['sourceImage'] = $image;
        }

        // Upgrade a URL-placeholder title (post_context stores the link as the
        // title when it can't read the post) to the caption's lead.
        $cur_title = trim((string)($item_cfg['sourceTitle'] ?? ''));
        if ($cur_title === '' || $cur_title === $link) {
            $new_title = trim((string)($mapped[0]['title'] ?? ''));
            if ($new_title !== '') {
                $item_cfg['sourceTitle'] = $new_title;
            }
        }

        // Persist so the published article's stored context shows the caption
        // and a re-run never re-bills Apify for the same post. Always written
        // once Apify has answered — the socialEnriched marker is the thing that
        // must survive, even when the reply carried nothing usable.
        PCM_DB::update_strategy_item((int)$item->id, array('config' => wp_json_encode($item_cfg)));

        return $item_cfg;
    }

    /**
     * The template variables a Writer template may reference to place the source
     * post's own fields anywhere in its prompt — the "generation lives in the
     * template" contract.
     *
     * Before this, the source post reached the model ONLY through the hardcoded
     * English sentence in rss_source_instruction() below, so the author could
     * not change how (or where) the post was used. These three tokens give the
     * template that control. Both sources populate the same item-config keys —
     * social posts via the Apify/oEmbed capture, RSS feed items via the feed
     * entry's own description (fetch_rss_feed_items() reads it precisely so
     * {{ post_content }} is real for an RSS strategy). A feed that ships no
     * description still yields '', which is why suppression checks the resolved
     * VALUE and not just the token — see template_carries_source().
     *
     * Lowercase, whitespace-tolerant (`{{post_title}}` == `{{ post_title }}`).
     * A keyword-sourced item (or the consolidated batch, which has no single
     * item) resolves every one to '' rather than leaking a raw token.
     *
     * Pure/deterministic — directly unit-testable.
     *
     * @param array $item_cfg Decoded ITEM config (item_config()'s output).
     * @return array<string,string> Variable name => value.
     */
    public static function source_vars(array $item_cfg): array
    {
        return array(
            'post_content' => trim((string)($item_cfg['sourceText'] ?? '')),
            'post_title'   => trim((string)($item_cfg['sourceTitle'] ?? '')),
            'post_link'    => trim((string)($item_cfg['sourceLink'] ?? '')),
        );
    }

    /**
     * Whether a template's prompt references ANY source variable. Drives the
     * rider suppression in generate_next_item(): a template that places the post
     * itself must not also get the hardcoded sentence appended, or the two
     * instructions duplicate and can contradict each other.
     *
     * Keyed off the TEMPLATE TEXT, never off source mode — a social strategy on
     * a variable-free template must keep its rider, or it loses the post context
     * entirely.
     *
     * Pure/deterministic — directly unit-testable.
     *
     * @param string $text Template prompt text.
     * @return bool
     */
    public static function uses_source_vars(string $text): bool
    {
        return self::referenced_source_vars($text) !== array();
    }

    /**
     * WHICH source variables a template references. Suppression keys off this
     * plus the resolved VALUES, not the mere presence of a token: a template can
     * reference {{ post_content }} while the item has no caption at all (an RSS
     * feed with no description, or a social post whose Apify enrichment has not
     * landed), and dropping the rider then would leave the model with NO source
     * item and no attribution link — strictly worse than before the feature.
     *
     * Pure/deterministic — directly unit-testable.
     *
     * @param string $text Template prompt text.
     * @return string[] Referenced variable names, in declaration order.
     */
    public static function referenced_source_vars(string $text): array
    {
        return self::referenced_vars($text, array_keys(self::source_vars(array())));
    }

    /**
     * Does this template both reference a source variable AND have a real value
     * for at least one of the ones it references? Only then does the template
     * genuinely carry the post, and only then is it safe to drop the built-in
     * rider.
     *
     * @param string $text     Template prompt text.
     * @param array  $item_cfg Decoded ITEM config (item_config()'s output).
     * @return bool
     */
    public static function template_carries_source(string $text, array $item_cfg): bool
    {
        $vars = self::source_vars($item_cfg);
        foreach (self::referenced_source_vars($text) as $name) {
            if (($vars[$name] ?? '') !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Substitute the source variables into a template prompt.
     *
     * UNKNOWN `{{ … }}` tokens are deliberately left untouched: a typo like
     * `{{ post_body }}` stays visible to the author instead of being silently
     * swallowed, and real templates legitimately contain `{{#if x}}`,
     * `{{{ triple }}}` and JSON braces that a generic stripper would eat.
     *
     * ONE pass, via preg_replace_callback. Substituting each variable in its own
     * pass re-scanned the text already substituted, so a caption that itself
     * contained `{{ post_title }}` had that expanded too — user content being
     * interpreted as template syntax. A single pass also removes the ordering
     * dependency and the need to escape `$`/`\` backreferences in the value.
     *
     * Delegates to render_template_vars() with ONLY the post_* map and NO
     * collapsible names, so the blank-line collapse that the prompt fragments
     * get never applies here: these tokens shipped before that behaviour
     * existed and must keep producing byte-identical output.
     *
     * Pure/deterministic — directly unit-testable.
     *
     * @param string $text     Template prompt text.
     * @param array  $item_cfg Decoded ITEM config (item_config()'s output).
     * @return string The prompt with the known variables resolved.
     */
    public static function render_source_vars(string $text, array $item_cfg): string
    {
        return self::render_template_vars($text, self::source_vars($item_cfg));
    }

    /**
     * Core of the template-variable substitution: resolve an arbitrary
     * {{ name }} map into the text in ONE pass. render_source_vars() delegates
     * here with the post_* map; build_prompt() delegates with the full map
     * (post_* + the prompt-building fragments), so a single scan resolves every
     * variable a template may carry.
     *
     * Same guarantees as render_source_vars(): ONLY names in $vars resolve;
     * every other {{ token }} (a typo, {{#if}}, JSON braces) is left untouched
     * so a typo stays visible. Scope the no-eat guarantee to UNKNOWN names: the
     * map is small but includes short, collision-prone keys like `keyword` and
     * `research`, so a triple-braced KNOWN name (`{{{ keyword }}}`) DOES have
     * its inner token resolved — the surrounding brace is left behind — while a
     * triple-braced UNKNOWN name (`{{{ post_body }}}` when post_body is not
     * mapped) survives whole. ONE pass means a value that itself contains
     * {{ x }} — a brand name, a research summary, a caption — is never
     * re-expanded as template syntax.
     *
     * Pure/deterministic — directly unit-testable via its public wrappers.
     *
     * @param string                    $text Template prompt text.
     * @param array<string,string|null> $vars Variable name => value.
     * @return string The prompt with the known variables resolved.
     */
    private static function render_template_vars(string $text, array $vars, array $collapsible = array()): string
    {
        if ($text === '' || $vars === array()) {
            return $text;
        }
        $pattern = '/\{\{\s*(' . implode('|', array_map(
            static fn(string $n): string => preg_quote($n, '/'),
            array_keys($vars)
        )) . ')\s*\}\}/';

        $emptied  = false;
        $rendered = preg_replace_callback(
            $pattern,
            static function (array $m) use ($vars, $collapsible, &$emptied): string {
                $value = (string)($vars[$m[1]] ?? '');
                if ($value === '' && in_array($m[1], $collapsible, true)) {
                    $emptied = true;
                }
                return $value;
            },
            $text
        );

        // A token that resolved to nothing (no brand, research off, media off)
        // leaves the blank lines the author put AROUND it, stacking up to six
        // newlines before the next heading. Collapse only then, and only for
        // the fragment variables ($collapsible): the {{ post_* }} tokens
        // shipped BEFORE this collapse existed, so applying it to them would
        // silently reformat prompts that are supposed to be byte-identical to
        // what they produced then. A template whose variables all resolved —
        // and any template with no variables, which never reaches here — keeps
        // its spacing byte for byte.
        if ($emptied && $rendered !== null) {
            $collapsed = preg_replace("/\n{3,}/", "\n\n", $rendered);
            if ($collapsed !== null) {
                $rendered = $collapsed;
            }
        }

        // preg_replace_callback returns null only on a PCRE engine failure
        // (e.g. an exhausted backtrack limit). Keep the un-substituted prompt
        // rather than blanking it — a visible token beats an empty brief. This
        // is the one path where a KNOWN token can still reach the model raw.
        return $rendered === null ? $text : $rendered;
    }

    /**
     * Core of referenced_source_vars(): WHICH names from a variable map the
     * text references. build_prompt() uses this to decide, per fragment,
     * whether the template placed it (→ suppress the auto-append) or left it
     * to the built-in injection.
     *
     * Pure/deterministic.
     *
     * @param string   $text      Template prompt text.
     * @param string[] $var_names Candidate variable names.
     * @return string[] Referenced names, in the order given.
     */
    private static function referenced_vars(string $text, array $var_names): array
    {
        $found = array();
        foreach ($var_names as $name) {
            if (preg_match('/\{\{\s*' . preg_quote($name, '/') . '\s*\}\}/', $text)) {
                $found[] = $name;
            }
        }
        return $found;
    }

    private static function rss_source_instruction(array $item_cfg, string $rss_angle): string
    {
        $title = trim((string)($item_cfg['sourceTitle'] ?? ''));
        $link  = trim((string)($item_cfg['sourceLink'] ?? ''));
        if ($title === '' && $link === '') {
            return '';
        }

        // Social variant: the item config carries social=true when the item
        // was created from a pasted social post link (create-time split) or by
        // the watcher's Apify branch — the article is ABOUT the post (with its
        // text when captured, sourceText) and must link back to it visibly.
        // Non-social items fall through to the byte-identical rss wording.
        if (!empty($item_cfg['social'])) {
            $instruction = "Write an article about this social media post: '" . $title . "' (" . $link . ").";
            $text = trim((string)($item_cfg['sourceText'] ?? ''));
            if ($text !== '') {
                $instruction .= ' The post says: "' . $text . '".';
            }
            if ($rss_angle !== '') {
                $instruction .= " Tailor it to the angle/primary keyword: '" . $rss_angle . "'.";
            }
            return $instruction . ' INCLUDE a visible link to the original post in the article HTML.';
        }

        $instruction = "This article responds to a new industry item: '" . $title . "' (" . $link . "). "
            . 'Write a better, more complete take on that topic';
        if ($rss_angle !== '') {
            $instruction .= ", tailored to the angle/primary keyword: '" . $rss_angle . "'";
        }
        return $instruction . '. Do not copy the source; outdo it.';
    }

    /**
     * Server-side chart-quality gate (A6, the owner's "no junk charts" ask). A
     * decoded Chart.js config passes ONLY when it presents real, meaningful data:
     *   - data.labels is an array with ≥3 entries;
     *   - data.datasets[0].data is an array of ≥3 numeric values;
     *   - data.datasets[0].label is a non-empty string (named series);
     *   - the values are NOT all identical (a placeholder/filler smell); and
     *   - a descriptive title is present — either options.plugins.title.text OR a
     *     top-level `title` string (accept either, don't over-require).
     * A failing config ⇒ the asset is dropped (its token stripped), exactly like a
     * failed image.
     *
     * @param array $chart_config Decoded Chart.js config.
     * @return bool True when the chart carries real, meaningful data.
     */
    private static function is_quality_chart(array $chart_config): bool
    {
        $data = $chart_config['data'] ?? null;
        if (!is_array($data)) {
            return false;
        }

        $labels = $data['labels'] ?? null;
        if (!is_array($labels) || count($labels) < 3) {
            return false;
        }

        $datasets = $data['datasets'] ?? null;
        if (!is_array($datasets) || empty($datasets)) {
            return false;
        }
        $first = $datasets[0] ?? null;
        if (!is_array($first)) {
            return false;
        }

        $label = $first['label'] ?? null;
        if (!is_string($label) || trim($label) === '') {
            return false;
        }

        $values = $first['data'] ?? null;
        if (!is_array($values) || count($values) < 3) {
            return false;
        }
        $numeric = array();
        foreach ($values as $v) {
            if (is_bool($v) || !is_numeric($v)) {
                return false; // non-numeric data point ⇒ not a real chart
            }
            $numeric[] = (float)$v;
        }
        // All-identical values are the canonical placeholder smell (e.g. [1,1,1]).
        if (count(array_unique($numeric)) < 2) {
            return false;
        }

        // A descriptive title in EITHER accepted location.
        $top_title    = $chart_config['title'] ?? null;
        $plugin_title = $chart_config['options']['plugins']['title']['text'] ?? null;
        $has_title = (is_string($top_title) && trim($top_title) !== '')
            || (is_string($plugin_title) && trim($plugin_title) !== '');

        return $has_title;
    }

    /**
     * A6 — turn the model's `media_assets` into in-content <figure> blocks
     * (AutoPress [IMAGE_N] parity). For each returned asset, its matching
     * [IMAGE_N] token in the article HTML is replaced with a
     * `<figure class="pcm-in-content-media">` wrapping either an AI-generated
     * image (type=image → PCM_Strategy_Image::generate(), reusing the strategy's
     * imageProvider/imageModel exactly like maybe_generate_featured_image()) or a
     * QuickChart-rendered chart (type=chart → a quickchart.io URL built from the
     * asset's chart_config; no API key). Capped at `config.mediaCount` (1–8,
     * default 3) assets, and filtered to the allowed `config.mediaType`
     * (images|charts|both); charts additionally pass a server-side quality gate.
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

        // mediaCount caps how many assets we place; mediaType restricts which
        // asset type(s) survive (a disallowed type is dropped, its token stripped).
        $max          = self::media_count($strategy);
        $allowed_type = self::media_type($strategy);

        foreach (array_slice($assets, 0, $max) as $asset) {
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

            // mediaType gate: drop an asset of a disallowed type regardless of what
            // the model returned — the token falls through to the strip below.
            if (($allowed_type === 'images' && $type !== 'image')
                || ($allowed_type === 'charts' && $type !== 'chart')
            ) {
                continue;
            }

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
                // Chart-quality gate (owner's "no junk charts" ask): a config that
                // doesn't present real, meaningful data is dropped like a failed image.
                if (!self::is_quality_chart($chart_config)) {
                    continue;
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

            // ALT TEXT ONLY — never a visible caption. `$desc` is the IMAGE GENERATION
            // PROMPT ("A cinematic, high-resolution image showing…"), an instruction to
            // the image model, not reader-facing copy. It was previously also emitted as
            // a <figcaption>, so every RSS/strategy article rendered its own prompt as
            // visible text under the image (truncated mid-word at 120 chars). It stays
            // in `alt`, where it genuinely helps accessibility and SEO, and is trimmed on
            // a word boundary so screen readers don't hear a severed word.
            $alt = sanitize_text_field($desc);
            if (mb_strlen($alt) > 120) {
                $cut  = mb_substr($alt, 0, 120);
                $stop = mb_strrpos($cut, ' ');
                $alt  = rtrim($stop !== false && $stop > 60 ? mb_substr($cut, 0, $stop) : $cut, " ,.;:-");
            }
            $figure = sprintf(
                '<figure class="pcm-in-content-media"><img src="%s" alt="%s" /></figure>',
                esc_url($src),
                esc_attr($alt)
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
     * Remove emoji from a string — used to keep ARTICLE text (title + body) plain
     * when an article is written from an emoji-heavy social post whose emojis
     * bleed into the model's output. Scoped to the pictographic/symbol Unicode
     * blocks + the joiners/selectors that compose them, so CJK, Latin, digits and
     * ordinary punctuation pass through untouched (no \p{Emoji} guesswork, which
     * PCRE does not expose reliably). The stored social sourceText/sourceTitle on
     * the item are captured data and are NEVER passed through this — only the
     * article the LLM writes.
     *
     * Pure/deterministic — directly unit-testable.
     *
     * @param string $text UTF-8 text.
     * @return string Text with emoji code points removed.
     */
    public static function strip_emoji(string $text): string
    {
        // WHY this looks heavier than a flat character class: the requirements
        // are in tension. (a) WIDEN — the "early emoji" live in the low symbol
        // blocks (arrows 2190-21FF, clocks 231A-23FA, enclosed 24C2/3297/3299,
        // shapes 25AA-25FE, misc + dingbats 2600-27BF) plus the single points
        // ‼(203C) ⁉(2049) ℹ(2139) 〰(3030); the old class let ⌚⌛⏰⏳▶◀ℹ‼↔Ⓜ㊙▪〰
        // through. (b) CARVE OUT — those same 26xx/27xx blocks also carry
        // ordinary article typography: ✓ ✗, the card suits ♠♥♦♣, music notes
        // ♪♫, and ✂✈✉✏. A "✓ included / ✗ not included" comparison table is a
        // common SEO device and must keep its markers. Resolution: swap those
        // 12 for private-use sentinels (U+E000–E00B, raw UTF-8 bytes — outside
        // every range below, so they survive the preg_replace) then restore, so
        // the broad ranges can't reach them — even with an emoji VS-16 appended
        // (✓️ → ✓, never erased). ‼ and ⁉ are DISTINCT code points; the prior
        // \x{2049}-\x{204A} also swept in U+204A (⁊, the Tironian Gaelic LETTER
        // — not an emoji), so 204A is now excluded.
        static $keep = array('✓', '✗', '♠', '♥', '♦', '♣', '♪', '♫', '✂', '✈', '✉', '✏');
        static $map = null;
        static $rev = null;
        if ($map === null) {
            $map = array();
            $rev = array();
            foreach ($keep as $i => $ch) {
                $sentinel = "\xEE\x80" . chr(0x80 + $i); // U+E000+i as 3 UTF-8 bytes (no mbstring dep)
                $map[$ch] = $sentinel;
                $rev[$sentinel] = $ch;
            }
        }
        $protected = strtr($text, $map);

        // NOT cast to string here — the null return below is the whole point.
        $cleaned = preg_replace(
            '/[\x{1F000}-\x{1FAFF}'  // pictographs, enclosed supplements, flags
            . '\x{2B00}-\x{2BFF}'    // stars/arrows block (⭐ ⭕ ⬅ ⬆)
            . '\x{2190}-\x{21FF}'    // arrow-block emoji (↔ ↕ ↖… the ‼/⁉ are separate below)
            . '\x{231A}-\x{23FA}'    // watch/hourglass/clocks ⌚⌛⏰⏳⏩…⏺
            . '\x{24C2}'             // Ⓜ (NOT the ①② text enclosed numerics around it)
            . '\x{25AA}-\x{25FE}'    // geometric shapes ▪▫▶◀◻◼◽◾
            . '\x{2600}-\x{27BF}'    // misc symbols + dingbats (carve-out applies)
            . '\x{203C}\x{2049}'     // ‼ ⁉ (distinct; 204A ⁊ is a letter, excluded)
            . '\x{2139}'             // ℹ
            . '\x{3030}'             // 〰 wavy dash
            . '\x{3297}\x{3299}'     // ㊗ ㊙ enclosed ideographs
            . '\x{FE0F}'             // variation selector-16 (emoji presentation)
            . '\x{200D}'             // zero-width joiner
            . '\x{20E3}'             // combining enclosing keycap (keycap emoji)
            . ']/u',
            '',
            $protected
        );
        // preg_replace with /u returns NULL on malformed UTF-8, and (string)null
        // is '' — which silently saved an EMPTY article (title, body and slug all
        // blank) with no exception and no log. Emoji removal is cosmetic; losing
        // the article is not. Hand the text back untouched instead.
        if ($cleaned === null) {
            return $text;
        }
        $restored = strtr($cleaned, $rev);
        // Only collapse spaces (and trim) when an emoji was ACTUALLY removed —
        // otherwise the global [ \t]{2,} collapse destroys <pre><code> indentation
        // in an emoji-free article (it ran unconditionally before).
        if ($restored === $text) {
            return $text;
        }
        $collapsed = preg_replace('/[ \t]{2,}/', ' ', $restored);
        return trim($collapsed === null ? $restored : $collapsed);
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
     * 'both' (internal + client must BOTH approve) is not a new pipeline: it
     * reuses the exact 'internal' starting lane below — 'both' starts internal;
     * moving the set to the Client lane is the internal sign-off; the client's
     * full approval then triggers publish — both parties gate the post.
     *
     * @param object $strategy      Strategy DB row.
     * @param object $item          Strategy item DB row (for its keyword, in the name).
     * @param int    $article_id    Newly created article ID.
     * @param int    $user_id       Owner ID.
     * @param string $approval_mode 'internal'|'client'|'both'.
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

        // Explicit mode -> starting lane/status map, instead of an implicit
        // client-or-not ternary, now that a third mode exists: 'internal' and
        // 'client' each start in their own lane; 'both' starts internal (see
        // docblock above for why).
        $status_map = array(
            'internal' => 'internal',
            'client'   => 'client',
            'both'     => 'internal',
        );
        $starting_status = $status_map[$approval_mode] ?? 'internal';

        $label = $approval_mode === 'client' ? 'Client Review' : 'Internal Review';
        // Client-facing sets get a client-safe name (just the article title) --
        // the internal strategy name + target SEO keyword must not be exposed to
        // a client who opens the (unauthenticated, token-scoped) review link.
        // Internal-only sets keep the more useful strategy+keyword identifier for
        // the team's own approval-queue dashboard. 'both' starts internal, so it
        // takes the internal-only naming, same as 'internal'.
        $name = $approval_mode === 'client'
            ? sprintf('%s (%s)', (string)$article->title, $label)
            : sprintf('%s — %s (%s)', (string)$strategy->name, (string)$item->keyword, $label);
        // NOTE: create_set() hardcodes status 'draft' and ignores this key (a
        // pre-existing approvals-module contract) — it stays in the payload as
        // documentation/forward-compat; the REAL lane placement happens via the
        // explicit update_status() call below, the same public API the approvals
        // Kanban uses. Before that call existed, every strategy-made set
        // silently started in Draft regardless of approvalMode.
        $set_id = PCM_Approvals_Service::create_set($user_id, array(
            'name'     => $name,
            'brandId'  => !empty($strategy->brandId) ? (int)$strategy->brandId : null,
            'status'   => $starting_status,
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

        // Move the fresh set out of the hardcoded 'draft' into its mode's
        // starting lane ('internal'/'client'; 'both' → internal). Best-effort:
        // a failed move leaves the set findable in Draft rather than failing
        // the generation that created it.
        if ($set_id && method_exists('PCM_Approvals_Service', 'update_status')) {
            PCM_Approvals_Service::update_status((int)$set_id, $user_id, $starting_status);
        }

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

        // Approval no longer means "done" — it means "ready to publish". The item
        // advanced from in_review to 'written' above; promote to 'completed' only on
        // an actual successful publish (mirrors the generation paths). An approval-mode
        // strategy with no site configured leaves the item 'written' (correct: approved
        // but not published), instead of a misleading green check.
        if ($publish !== null && !empty($publish['success'])) {
            PCM_DB::update_strategy_item((int)$item->id, array('status' => 'completed'));
        }

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
        $written   = 0;
        foreach (PCM_DB::get_strategy_items($strategy_id) as $it) {
            if ($it->status === 'completed')  { $completed++; }
            elseif ($it->status === 'error')  { $failed++; }
            elseif ($it->status === 'written') { $written++; }
        }
        // 'completed' now means PUBLISHED; 'written' (generated, not published) does NOT
        // count toward completion, but DOES count toward in_progress so a strategy of all-
        // written items shows "In Progress" (blue) instead of getting stuck at "Pending"
        // or falsely flipping green. Only when every item is genuinely published does the
        // strategy reach 'completed'.
        $status = ($total > 0 && $completed >= $total)
            ? 'completed'
            : (($completed + $failed + $written) > 0 ? 'in_progress' : 'pending');

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
     * Resolve the effective anchor mode for an interlink run — 'keyword'
     * (exact scan only, no LLM ever), 'synonym' (verbatim synonym fallback), or
     * 'ai' (single-phrase fallback ≡ legacy aiAnchors). An explicit valid
     * anchorMode in $options wins; else the stored interlinksConfig's anchorMode;
     * else back-compat with the legacy aiAnchors flag (truthy -> 'ai', absent ->
     * 'keyword'). Mirrors how quantity/aiAnchors resolve ($options over $cfg).
     *
     * @param array $options Per-run overrides.
     * @param array $cfg     Stored interlinksConfig (fallback).
     * @return string 'keyword'|'synonym'|'ai'
     */
    private static function resolve_anchor_mode(array $options, array $cfg): string
    {
        $valid = array('keyword', 'synonym', 'ai');
        if (isset($options['anchorMode']) && in_array($options['anchorMode'], $valid, true)) {
            return (string)$options['anchorMode'];
        }
        if (isset($cfg['anchorMode']) && in_array($cfg['anchorMode'], $valid, true)) {
            return (string)$cfg['anchorMode'];
        }
        return !empty($options['aiAnchors']) ? 'ai' : 'keyword';
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
     *   - aiAnchors           bool  Legacy flag. When truthy AND no anchorMode is
     *                                given, resolves to anchorMode='ai' (below).
     *   - anchorMode          string 'keyword'|'synonym'|'ai'. Governs the anchor
     *                                fallback when a target keyword has no safe
     *                                verbatim occurrence. 'keyword': never consult
     *                                the LLM (exact scan only). 'ai': ask the LLM
     *                                (one call per source/target pair) for one
     *                                existing short phrase already in the article
     *                                to wrap instead (≡ legacy aiAnchors). 'synonym':
     *                                ask (one call) for 3-5 short verbatim synonyms
     *                                of the keyword and take the first that
     *                                re-validates. All fallbacks re-validate through
     *                                the SAME deterministic rails; any LLM failure or
     *                                unsafe answer degrades to the plain
     *                                'no safe occurrence' skip (never fails the run).
     *                                Resolution: explicit valid anchorMode (option
     *                                then stored config) wins; else legacy aiAnchors
     *                                truthy -> 'ai'; else 'keyword'.
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

        // Anchor mode governs what happens when a target keyword has no safe
        // verbatim occurrence: 'keyword' never consults the LLM; 'ai' asks for a
        // single existing phrase (legacy aiAnchors semantics); 'synonym' asks for
        // 3-5 short verbatim synonyms. Resolved from $options with the stored
        // interlinksConfig as fallback, then back-compat with legacy aiAnchors.
        $anchor_mode = self::resolve_anchor_mode($options, $cfg);

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

                // Anchor fallback when the keyword itself has no safe occurrence.
                // 'ai' (AutoPress injectLinkSurgically allowAi parity / legacy
                // aiAnchors): ask the LLM (exactly one call per source/target
                // pair) for one existing short phrase already in the article to
                // wrap instead, then re-validate that phrase through the SAME
                // deterministic rails. 'synonym': ask (one call) for 3-5 short
                // verbatim synonyms of the target keyword and take the first that
                // re-validates. 'keyword': never consult the LLM. Any failure or
                // unsafe answer falls through to the skip below -- the LLM is
                // never retried, and a throw never fails the run.
                if ($safe === null && $anchor_mode === 'ai') {
                    $ai_anchor = self::pick_ai_anchor($content, $target['keyword'], $user_id);
                    if ($ai_anchor !== null) {
                        $ai_safe = self::find_safe_occurrence($content, $ai_anchor, '');
                        if ($ai_safe !== null) {
                            $safe   = $ai_safe;
                            $reason = 'ai anchor';
                        }
                    }
                } elseif ($safe === null && $anchor_mode === 'synonym') {
                    foreach (self::pick_synonym_anchors($content, $target['keyword'], $user_id) as $synonym) {
                        $synonym = trim((string)$synonym);
                        if ($synonym === '') {
                            continue;
                        }
                        // Case-insensitive re-validation pass, same as the AI path.
                        $syn_safe = self::find_safe_occurrence($content, $synonym, 'i');
                        if ($syn_safe !== null) {
                            $safe   = $syn_safe;
                            $reason = 'synonym anchor';
                            break; // first safely-locatable candidate wins
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
     * Synonym-anchor fallback (the 'synonym' anchor mode): when a target keyword
     * has no safe verbatim occurrence, ask the LLM (exactly one call) for 3-5
     * short synonyms / close variants of the TARGET KEYWORD that literally appear
     * VERBATIM in the article text. Returns the raw candidate list (each
     * re-validated by the caller through find_safe_occurrence); returns [] on any
     * throwable or malformed answer — same isolation contract as pick_ai_anchor:
     * an LLM failure must never fail the interlink run, and it is never retried.
     *
     * @param string $content        Source article's raw HTML content.
     * @param string $target_keyword Topic the internal link should point at.
     * @param int    $user_id        Owner ID, forwarded for LLM accounting.
     * @return array<int,string> Candidate synonym phrases (possibly empty).
     */
    private static function pick_synonym_anchors(string $content, string $target_keyword, int $user_id): array
    {
        try {
            $text     = substr(strip_tags($content), 0, 6000);
            $messages = array(
                array('role' => 'system', 'content' => 'You select synonym anchor text for internal links.'),
                array('role' => 'user', 'content' => 'From the ARTICLE TEXT below, return 3-5 short synonyms or close variants (2-6 words each, verbatim from the text, outside of HTML tags) of the keyword "' . $target_keyword . '" that literally appear in the text. ARTICLE TEXT: ' . $text),
            );
            // Full json_schema wrapper ({name, schema}) — see pick_ai_anchor()'s
            // note on why the name is required (OpenAI strict-mode 400s without it).
            $schema = array(
                'name'   => 'synonym_anchors',
                'schema' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'synonyms' => array('type' => 'array', 'items' => array('type' => 'string')),
                    ),
                    'required'   => array('synonyms'),
                ),
            );
            $result   = PCM_LLM::invoke_json($messages, $schema, array('max_tokens' => 256, 'user_id' => $user_id));
            $synonyms = (is_array($result) && isset($result['synonyms']) && is_array($result['synonyms'])) ? $result['synonyms'] : array();
            return array_values($synonyms);
        } catch (\Throwable $e) {
            return array(); // any LLM failure -> no synonyms, run continues
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
    /**
     * Change the LIVE status of an item's already-published post on the connected site:
     * 'draft' (unpublish, keeps the post), 'publish' (re-publish a drafted one), or
     * 'trash' (delete — recoverable from the site's Trash, never a permanent delete).
     *
     * Only valid once the item actually HAS a post on a site — publishedPostId + siteId
     * on the linked article. The local article row is kept in step so the Strategies UI
     * and Writer agree without a refetch.
     *
     * @param object $strategy Strategy row (ownership already verified by the controller).
     * @param int    $item_id  Strategy item id — must belong to $strategy.
     * @param int    $user_id  Owner id (scopes every DB read).
     * @param string $status   'draft' | 'publish' | 'trash'.
     * @return array{status:string,postId:int,trashed:bool}
     * @throws \RuntimeException On any invalid state or remote failure.
     */
    /**
     * Duplicate one strategy item back into the same strategy.
     *
     * The copy is a FRESH pending item: same keyword / title / per-item config, but
     * NO articleId, setId, scheduledDate or error — duplicating is "queue this topic
     * again", not "clone the finished article" (which would double-publish the same
     * content). It lands directly after the original so the order reads sensibly.
     *
     * @param object $strategy Strategy row (ownership verified by the controller).
     * @param int    $item_id  Item to copy — must belong to $strategy.
     * @param int    $user_id  Owner id.
     * @return array{id:int,keyword:string}
     * @throws \RuntimeException When the item isn't in this strategy or the insert fails.
     */
    public static function duplicate_item(object $strategy, int $item_id, int $user_id): array
    {
        $source = null;
        foreach (PCM_DB::get_strategy_items((int)$strategy->id) as $candidate) {
            if ((int)$candidate->id === $item_id) {
                $source = $candidate;
                break;
            }
        }
        if (!$source) {
            throw new \RuntimeException('Item not found in this strategy.');
        }

        global $wpdb;
        $table = PCM_Schema::table('strategy_items');
        $row = array(
            'strategyId' => (int)$strategy->id,
            'userId'     => $user_id,
            'keyword'    => (string)$source->keyword,
            'title'      => isset($source->title) ? (string)$source->title : null,
            'status'     => 'pending',
            // Sit right after the original; later items keep their own positions, so
            // ties are broken by id — good enough for a display ordering.
            'position'   => (int)$source->position + 1,
        );
        if (!empty($source->config)) {
            $row['config'] = (string)$source->config;
        }
        if (isset($source->volume) && $source->volume !== null) {
            $row['volume'] = (int)$source->volume;
        }
        if (isset($source->difficulty) && $source->difficulty !== null) {
            $row['difficulty'] = (int)$source->difficulty;
        }

        if ($wpdb->insert($table, $row) === false) {
            throw new \RuntimeException('Could not duplicate the item.');
        }
        return array('id' => (int)$wpdb->insert_id, 'keyword' => (string)$source->keyword);
    }

    /**
     * Every status a WordPress post or page can be moved to from the UI.
     * 'auto-draft' and 'inherit' are internal to WordPress and deliberately absent.
     * 'trash' is a DELETE, not a status write — see set_item_post_status().
     *
     * One list, used by the controller's validation AND the service, so the two
     * can never disagree about what is allowed.
     *
     * @var string[]
     */
    public const POST_STATUSES = array('publish', 'future', 'draft', 'pending', 'private', 'trash');

    public static function set_item_post_status(object $strategy, int $item_id, int $user_id, string $status): array
    {
        if (!in_array($status, self::POST_STATUSES, true)) {
            throw new \RuntimeException('Unsupported post status.');
        }

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
        if (empty($item->articleId)) {
            throw new \RuntimeException('This item has no generated article yet.');
        }

        $article = PCM_DB::get_article((int)$item->articleId, $user_id);
        if (!$article) {
            throw new \RuntimeException('The generated article could not be loaded.');
        }
        $post_id = (int)($article->publishedPostId ?? 0);
        $site_id = (int)($article->siteId ?? 0);
        // The control is only offered once the post is live — mirror that server-side
        // so a stale UI can never act on an unpublished item.
        if ($post_id <= 0 || $site_id <= 0) {
            throw new \RuntimeException('This article is not published to a site yet.');
        }
        $site = PCM_DB::get_site($site_id, $user_id);
        if (!$site) {
            throw new \RuntimeException('The site this article was published to is no longer connected.');
        }

        if (!class_exists('PCM_Sites_Service')) {
            require_once dirname(__DIR__) . '/sites/service.php';
        }
        $route = '/wp/v2/posts/' . $post_id;
        if ($status === 'trash') {
            // force=false → Trash, not a permanent delete: a mistaken click on a client
            // site stays recoverable from the site's own Trash.
            $res = PCM_Sites_Service::remote_rest($site, 'DELETE', $route, array('force' => 'false'));
        } else {
            $body = array('status' => $status);
            if ($status === 'future') {
                // 'future' without a FUTURE date is not a scheduled post — WordPress
                // publishes it immediately (wp_insert_post downgrades future→publish
                // when the date has passed). Send the item's scheduled date, and
                // refuse rather than silently publishing when there isn't a usable one.
                $when = trim((string)($item->scheduledDate ?? ''));
                if ($when === '' || strtotime($when) === false) {
                    throw new \RuntimeException('Set a schedule date on this item before choosing Scheduled.');
                }
                if (strtotime($when) <= time()) {
                    throw new \RuntimeException('That item\'s schedule date is in the past — pick a future date before choosing Scheduled.');
                }
                // WP REST wants ISO-8601 in SITE time; `date` is exactly that field.
                $body['date'] = gmdate('Y-m-d\TH:i:s', strtotime($when));
            }
            $res = PCM_Sites_Service::remote_rest($site, 'POST', $route, array(), $body);
        }
        if (is_wp_error($res)) {
            throw new \RuntimeException('Could not reach the site: ' . $res->get_error_message());
        }
        if ((int)($res['status'] ?? 0) >= 300) {
            $msg = (is_array($res['body'] ?? null) && !empty($res['body']['message']))
                ? (string)$res['body']['message']
                : ('HTTP ' . (int)($res['status'] ?? 0));
            throw new \RuntimeException('The site rejected the change: ' . $msg);
        }

        // Keep the local row coherent. Trashing clears the publish pointers so the item
        // falls back to its "not on the site" affordances (and can be published again).
        if ($status === 'trash') {
            PCM_DB::update_article((int)$article->id, $user_id, array(
                'status'           => 'draft',
                'publishedPostId'  => null,
                'publishedUrl'     => null,
                'publishedStatus'  => null, // no longer on the site
            ));
        } else {
            PCM_DB::update_article((int)$article->id, $user_id, array(
                // LOCAL Writer workflow state. Only a real 'publish' counts as
                // published — pending/private/future/draft are all "not live yet"
                // to Writer. Unchanged on purpose: widening this vocabulary would
                // break Writer's draft|review|ready|published states.
                'status'          => $status === 'publish' ? 'published' : 'draft',
                // REMOTE status, which is what the row's dropdown reads back. Without
                // this the control would show Draft for a Pending or Private post and
                // appear to "not save".
                'publishedStatus' => $status,
            ));
        }

        return array(
            'status'  => $status,
            'postId'  => $post_id,
            'trashed' => $status === 'trash',
        );
    }

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
        if (!in_array((string)$item->status, array('written', 'completed'), true) || empty($item->articleId)) {
            throw new \RuntimeException('Only a written (generated, not yet published) item with an article can be published.');
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

        // A successful manual publish is the canonical 'written' → 'completed'
        // transition (publish = the thing that earns the green check). Idempotent for
        // an item that was somehow already 'completed'.
        if ((string)$item->status !== 'completed') {
            PCM_DB::update_strategy_item((int)$item->id, array('status' => 'completed'));
            self::recompute_counters((int)$strategy->id, $user_id, (int)$strategy->totalItems);
        }

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
                    'publishedStatus' => null,
                ));
                $deleted++;
                $updated++;
                continue;
            }
            // Record the REMOTE status whatever it is, so a status changed directly
            // in wp-admin (or a schedule that has since fired) reconciles here and
            // the row's dropdown stops disagreeing with the site.
            if ($remote_status !== '' && $remote_status !== (string)($article->publishedStatus ?? '')) {
                PCM_DB::update_article((int)$article->id, $user_id, array('publishedStatus' => $remote_status));
                $updated++;
            }
            if ($remote_status === 'future') {
                continue; // still scheduled on WordPress' side — leave the rest as-is
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
     * @param int          $strategy_id Strategy ID (ownership verified by the caller).
     * @param array|string $schedule    Full scheduleConfig array (custom-recurrence:
     *   interval/unit/byDays/ends), OR a bare frequency string — every current UI
     *   surface now sends the full array; the string form is kept as a defensive
     *   back-compat rail (normalized to `{frequency}`, byte-identical legacy path).
     * @param string       $start_date  strtotime()-parseable start ('' → now).
     * @return int Items rescheduled (slots past an `ends` cap are skipped, not counted).
     */
    public static function reschedule_pending_items(int $strategy_id, $schedule, string $start_date = ''): int
    {
        $schedule_cfg = is_array($schedule) ? $schedule : array('frequency' => (string)$schedule);

        $pending = array_values(array_filter(
            PCM_DB::get_strategy_items($strategy_id),
            static fn($it) => $it->status === 'pending'
        ));
        if (empty($pending)) {
            return 0;
        }
        $start = $start_date !== '' ? $start_date : current_time('mysql');
        $dates = self::calculate_recurrence_dates(count($pending), $schedule_cfg, $start);
        $rescheduled = 0;
        foreach ($pending as $i => $item) {
            $slot = $dates[$i] ?? null;
            // A null slot = past a custom-recurrence `ends` cap. update_strategy_item()
            // rides $wpdb->update(), which drops NULL values (it can't write SQL NULL),
            // so SKIP the write and leave scheduledDate as-is rather than no-op a NULL
            // that would never land. (Legacy frequency paths never yield nulls.)
            if ($slot === null) {
                continue;
            }
            PCM_DB::update_strategy_item((int)$item->id, array('scheduledDate' => $slot));
            $rescheduled++;
        }
        return $rescheduled;
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

        // Preserve non-override keys, replace the override set: RSS/social
        // items carry sourceLink/sourceTitle/sourceText/social in this same
        // column, and the controller's sanitize passes ONLY the override keys
        // — a blind replace silently wiped an item's source context the
        // moment any per-item override was set (adversarial-review finding).
        // The UI clears an override by OMITTING its key, so the override keys
        // are authoritative-by-absence (absent = back to inherit) while every
        // other stored key survives untouched.
        $override_keys = array('templateId', 'publishingMode', 'approvalMode');
        $existing = array();
        if (!empty($item->config)) {
            $decoded = json_decode((string)$item->config, true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }
        $preserved = array_diff_key($existing, array_flip($override_keys));
        $merged    = array_merge($preserved, $config);

        PCM_DB::update_strategy_item($item_id, array(
            'config' => !empty($merged) ? wp_json_encode($merged) : null,
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
        if (!in_array($item->status, array('completed', 'written', 'error'), true)) {
            throw new \RuntimeException("Only a 'completed', 'written', or 'error' item can be reset.");
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
     * Which passes actually run is resolved by research_passes() (valid
     * `config.researchPasses` wins; else derived from research_mode()). A SINGLE
     * selected pass keeps grounded parity — its raw summary, capped ~4000 chars,
     * with NO section label. MULTIPLE passes use the labeled-section merge
     * (SEARCH LANDSCAPE / QUESTIONS & DATA / CONTENT GAPS), capped ~6000 chars.
     * The three prompt strings are byte-identical to the original grounded/deep
     * implementations, so legacy strategies behave exactly as before.
     *
     * @return string Research summary (trimmed, capped ~4000 chars for a single
     *   pass / ~6000 chars for multiple), or '' when no pass is selected or on failure.
     */
    private static function maybe_research_context(object $strategy, array $keywords, int $user_id): string
    {
        $passes = self::research_passes($strategy);
        if (empty($passes)) {
            return '';
        }

        $query = implode('", "', $keywords);
        // Research runs on its OWN configured model (falls back to the historical default).
        list($research_model, $research_provider) = self::resolve_research_model($strategy);

        // Each pass -> its VERBATIM prompt (byte-identical to the original
        // grounded_research_context / deep_research_context prompt strings) and
        // its section label (used only in the multi-pass merge).
        $prompts = array(
            'landscape' => 'Research the current top-ranking content, dominant themes, common questions, and content gaps for the search query: "' . $query . '". Summarize concisely: key themes to cover, questions to answer, angles competitors miss.',
            'questions' => 'For the search query: "' . $query . '", identify the most common questions real users ask AND concrete, citable statistics or data points (with sources) relevant to this topic — include specific numbers that could be used in a chart or infographic.',
            'gaps'      => 'For the search query: "' . $query . '", identify angles and subtopics that competitors\' top-ranking pages commonly miss or under-cover.',
        );
        $labels = array(
            'landscape' => 'SEARCH LANDSCAPE',
            'questions' => 'QUESTIONS & DATA',
            'gaps'      => 'CONTENT GAPS',
        );

        // A single selected pass reproduces grounded mode byte-for-byte: raw
        // content, 4000-char cap, no label. Multiple passes reproduce deep mode:
        // labeled sections joined by a blank line, 6000-char cap.
        if (count($passes) === 1) {
            $content = self::run_grounding_call($prompts[$passes[0]], $user_id, $research_model, $research_provider);
            if ($content === '') {
                return '';
            }
            return strlen($content) > 4000 ? substr($content, 0, 4000) : $content;
        }

        $sections = array();
        foreach ($passes as $pass) {
            $content = self::run_grounding_call($prompts[$pass], $user_id, $research_model, $research_provider);
            if ($content !== '') {
                $sections[] = $labels[$pass] . ":\n" . $content;
            }
        }

        $result = implode("\n\n", $sections);
        if (strlen($result) > 6000) {
            $result = substr($result, 0, 6000);
        }
        return $result;
    }

    /**
     * Resolves the effective research mode for a strategy: 'off' | 'grounded' | 'deep'.
     *
     * Precedence: an explicit, valid `config.researchMode` always wins. Otherwise
     * fall back to the legacy `config.research` boolean for back-compat — truthy
     * maps to 'grounded' (today's single-call behaviour), and falsy/absent maps
     * to 'off' (the pre-existing default: no `research` key means no research).
     *
     * @param object $strategy Strategy DB row.
     * @return string 'off'|'grounded'|'deep'.
     */
    private static function research_mode(object $strategy): string
    {
        $cfg = !empty($strategy->config) ? json_decode((string)$strategy->config, true) : null;
        if (!is_array($cfg)) {
            return 'off';
        }

        if (isset($cfg['researchMode']) && in_array($cfg['researchMode'], array('off', 'grounded', 'deep'), true)) {
            return $cfg['researchMode'];
        }

        return !empty($cfg['research']) ? 'grounded' : 'off';
    }

    /**
     * Resolve the ORDERED set of research passes a strategy runs, drawn from
     * the whitelist ['landscape','questions','gaps'] (Filip's research checklist).
     *
     * Precedence:
     *   1. A valid `config.researchPasses` array WINS — intersected against the
     *      whitelist in CANONICAL order (dedupes + drops unknowns; an explicit
     *      empty array is a legitimate "research off" signal → []).
     *   2. Otherwise derive from research_mode() for full back-compat:
     *      off → [], grounded → ['landscape'], deep → ['landscape','questions','gaps'].
     *
     * Canonical order matters: it keeps deep-mode's three-pass output
     * byte-identical (landscape, then questions, then gaps) to the pre-refactor
     * deep_research_context().
     *
     * @param object $strategy Strategy DB row.
     * @return string[] Ordered subset of ['landscape','questions','gaps'] (possibly empty).
     */
    private static function research_passes(object $strategy): array
    {
        $whitelist = array('landscape', 'questions', 'gaps');

        $cfg = !empty($strategy->config) ? json_decode((string)$strategy->config, true) : null;
        if (is_array($cfg) && array_key_exists('researchPasses', $cfg) && is_array($cfg['researchPasses'])) {
            return array_values(array_filter(
                $whitelist,
                static fn($p) => in_array($p, $cfg['researchPasses'], true)
            ));
        }

        switch (self::research_mode($strategy)) {
            case 'deep':
                return array('landscape', 'questions', 'gaps');
            case 'grounded':
                return array('landscape');
            default:
                return array();
        }
    }

    /**
     * Runs a single invoke_with_grounding() call, fully failure-isolated: any
     * throwable is caught, error_log()'d, and degrades to '' — this section is
     * simply omitted from the (single- or multi-pass) research context, per-call,
     * without affecting sibling passes in maybe_research_context().
     *
     * @param string $prompt  User-role prompt content for this grounding call.
     * @param int    $user_id Strategy owner (PCM user id).
     * @return string Trimmed content, or '' on failure.
     */
    private static function run_grounding_call(string $prompt, int $user_id, string $model = 'gemini-2.5-flash', string $provider = ''): string
    {
        try {
            $options = array(
                'model'      => ($model !== '' ? $model : 'gemini-2.5-flash'),
                'max_tokens' => 2048,
                'user_id'    => $user_id,
            );
            // Only send an explicit provider when one is configured — omitting it
            // preserves the pre-existing resolution path byte-for-byte.
            if ($provider !== '') {
                $options['provider'] = $provider;
            }
            $result = PCM_LLM::invoke_with_grounding(array(array(
                'role'    => 'user',
                'content' => $prompt,
            )), $options);
            return trim((string)($result['content'] ?? ''));
        } catch (\Throwable $e) {
            error_log('[PCM_Strategy_Service] Research enrichment failed: ' . $e->getMessage());
            return '';
        }
    }

    private static function build_prompt(array $keywords, array $template, ?object $brand, string $research_context = '', bool $in_content_media = false, int $media_count = 3, string $media_type = 'both', string $media_guidance = '', string $rss_source = '', array $item_cfg = array()): array
    {
        $messages = array();

        // ── Build the variable values from this call's arguments. Each is also
        //     auto-appended UNLESS the template references its variable, so an
        //     author who places {{ brand_context }} / {{ research }} /
        //     {{ media_instructions }} / {{ keyword }} / {{ output_format }}
        //     owns that piece of the prompt instead of receiving a hidden,
        //     duplicate injection. A template that references NONE of them is
        //     byte-identical to the prompt this method built before the
        //     variables existed (the load-bearing back-compat contract). ──
        // For the consolidated batch this MUST carry the "one article covering
        // all of them" framing, not a bare comma list: a template that places
        // {{ keyword }} suppresses the auto-appended sentence, and without the
        // framing the model is told to write a single pillar for a comma list.
        $keyword_text = count($keywords) > 1
            ? '"' . implode('", "', $keywords) . '" — cover ALL of them together,'
              . ' as distinct sections or subtopics within ONE cohesive article'
            : (string)($keywords[0] ?? '');

        $brand_block = '';
        if ($brand) {
            $brand_block = "BRAND CONTEXT:\n";
            if (!empty($brand->name)) {
                $brand_block .= "- Company: {$brand->name}\n";
            }
            if (!empty($brand->niche)) {
                $brand_block .= "- Industry: {$brand->niche}\n";
            }
            if (!empty($brand->tonOfVoice)) {
                $brand_block .= "- Tone of Voice: {$brand->tonOfVoice}\n";
            }
            if (!empty($brand->targetAudience)) {
                $brand_block .= "- Target Audience: {$brand->targetAudience}\n";
            }
            if (!empty($brand->uniqueSellingPoints)) {
                $brand_block .= "- Unique Selling Points: {$brand->uniqueSellingPoints}\n";
            }
            if (!empty($brand->language)) {
                $brand_block .= "- Content Language: {$brand->language}\n";
            }
        }

        // Empty when research is off or failed — research must never change
        // generation's shape, only augment it.
        $research_block = $research_context !== ''
            ? "CURRENT SEARCH LANDSCAPE (from live research — use to inform coverage, do not cite):\n" . $research_context
            : '';

        // The brand's declared content language, exposed to templates as
        // {{ brand_language }} and used to language-lock the JSON fields below.
        $brand_language = $brand ? trim((string)($brand->language ?? '')) : '';

        $output_format = 'Return a JSON object with the following fields: title, content (HTML), metaTitle, metaDescription.';
        // WHY: the brand block already declares "Content Language", which the model
        // applied to the article BODY — but nothing told it what language the JSON
        // fields should use, so title/metaTitle/metaDescription came back in ENGLISH
        // even for a Swedish brand. Name them explicitly. Guarded on a non-empty
        // language so brands without one keep the byte-identical original string.
        if ($brand_language !== '') {
            $output_format .= ' Write every field — including title, metaTitle and metaDescription — in ' . $brand_language . '.';
        }

        // A6: in-content images & charts. Empty when the feature is off. Built
        // here (not inline on the user message) so {{ media_instructions }} can
        // surface the same block wherever the author places it.
        $media_block = '';
        if ($in_content_media) {
            // mediaCount (1–8) drives how many placeholders we advertise; mediaType
            // restricts which asset type(s) the model may return.
            $count  = max(1, min(8, $media_count));
            $tokens = array();
            for ($n = 1; $n <= $count; $n++) {
                $tokens[] = '[IMAGE_' . $n . ']';
            }

            if ($media_type === 'images') {
                $type_field = "\"image\"";
                $type_rule  = "Every media_assets entry's type must be \"image\" (do NOT return any charts). ";
            } elseif ($media_type === 'charts') {
                $type_field = "\"chart\"";
                $type_rule  = "Every media_assets entry's type must be \"chart\" (do NOT return any images). ";
            } else {
                $type_field = "\"image\"|\"chart\"";
                $type_rule  = "Each media_assets entry's type must be either \"image\" or \"chart\". ";
            }

            $media_block = "IN-CONTENT MEDIA: You may add up to " . $count . " supporting visuals. Insert placeholder tokens "
                . implode(', ', $tokens) . " — each on its OWN line, wrapped in its own <p></p>, at "
                . "natural points in the HTML body. For EVERY placeholder you insert, add one matching entry "
                . "to a \"media_assets\" array, where each entry is {placeholder: \"IMAGE_1\", type: " . $type_field . ", "
                . "prompt: string, chart_config: string|null}. " . $type_rule
                . "For type \"image\", `prompt` is a detailed image-generation prompt and chart_config is null. "
                . "For type \"chart\", put a VALID Chart.js config — serialized as a JSON string — in `chart_config`, "
                . "presenting REAL, meaningful data: at least 3 data points, a named dataset/series (a non-empty "
                . "`label`), and a descriptive chart title; put a short caption naming the data source in `prompt`. "
                . "Never use placeholder, filler, or all-identical values.";

            // Chart-quality contract, research-grounded (the owner's core ask):
            // when live research findings are present, base chart data on them.
            if ($research_context !== '') {
                $media_block .= " Base chart data on the RESEARCH FINDINGS above (real statistics, real "
                    . "comparisons); cite the source in the chart caption (`prompt` field).";
            }

            // Owner-supplied creative direction (config.mediaGuidance): what
            // the images should depict / what data the charts should show.
            if ($media_guidance !== '') {
                $media_block .= " CREATIVE DIRECTION for the visuals: \"" . $media_guidance . "\" — follow it "
                    . "for image subjects/style and for what data the charts present.";
            }

            $media_block .= " Only insert a placeholder if you also return its media_assets entry, and never "
                . "exceed " . $count . ".";
        }

        // Full variable map: the source post (post_*) plus the prompt-building
        // fragments. ONE pass via render_template_vars(), so a fragment value
        // that itself contains {{ x }} is never re-expanded.
        // Fragment VALUES are trimmed: the author controls the blank lines
        // around a token, and brand_block's trailing newline would otherwise
        // run the next heading straight into the block. The AUTO-APPENDED
        // copies below deliberately keep their original untrimmed form, which
        // is what preserves byte identity for variable-free templates.
        $vars = self::source_vars($item_cfg) + array(
            'keyword'            => $keyword_text,
            'brand_context'      => trim($brand_block),
            'research'           => trim($research_block),
            'output_format'      => $output_format,
            'media_instructions' => trim($media_block),
            // Plain value var (NOT a fragment): substituted wherever a template
            // writes {{ brand_language }}, never auto-appended and never triggers
            // the blank-line collapse. Empty string when the brand sets no language,
            // so a template referencing it degrades to nothing rather than breaking.
            'brand_language'     => $brand_language,
        )
        // The SHARED site + business vocabulary — the same tokens SEO templates
        // resolve ({{business.name}}, {{business.phone}}, {{site.lang}}, {{today}}…).
        // Writer templates simply did not have them before, which is the card:
        // "we're lacking variables that we currently have in SEO, but we don't have
        // it in writer". All plain values (never fragments), so an unreferenced one
        // is never auto-appended and a template using none is byte-identical.
        // `null` lang = the brand's own content language, hub locale as fallback.
        + (class_exists('PCM_Content_Vars')
            ? PCM_Content_Vars::site_business($brand ? (int) ($brand->id ?? 0) : null, null)
            : array());

        // ── System prompt from the template's prompt entries. Concatenate every
        //     prompt entry so a variable referenced in ANY of them suppresses
        //     the matching auto-append (the template is the unit, not one
        //     entry); only 'prompt' entries ever become the system message. ──
        // Suppression is decided PER ENTRY and unioned — concatenating the
        // entries first meant a token split across two of them ('… {{' + 
        // 'brand_context }} …') matched the join, suppressed the append, and
        // rendered in neither entry: the fragment vanished entirely.
        $system_parts   = array();
        $referenced     = array();
        $var_names      = array_keys($vars);
        // Only these five may trigger the blank-line collapse — see render_template_vars().
        $fragment_vars  = array('keyword', 'brand_context', 'research', 'output_format', 'media_instructions');
        foreach ($template['entries'] as $entry) {
            if (($entry['category'] ?? '') === 'prompt') {
                $value          = (string)($entry['value'] ?? '');
                $referenced     = array_merge($referenced, self::referenced_vars($value, $var_names));
                $system_parts[] = self::render_template_vars($value, $vars, $fragment_vars);
            }
        }
        $referenced = array_unique($referenced);

        // Deliberate exception to the "author places every fragment" contract:
        // a template with zero prompt entries has no text to place a token in,
        // so this hardcoded line carries no variable — there is nowhere an
        // author could take over, and no fragment to surface or suppress.
        if (empty($system_parts)) {
            $system_parts[] = 'You are an expert SEO content writer. Write a comprehensive, well-structured article optimized for search engines.';
        }

        // Append the context fragments the template did NOT place itself.
        if ($brand_block !== '' && !in_array('brand_context', $referenced, true)) {
            $system_parts[] = $brand_block;
        }
        if ($research_block !== '' && !in_array('research', $referenced, true)) {
            $system_parts[] = $research_block;
        }

        $messages[] = array(
            'role'    => 'system',
            'content' => implode("\n\n", $system_parts),
        );

        // ── User message. keyword / output_format / media_instructions each
        //     suppress their auto-append when referenced, so the author controls
        //     where they land. The RSS/social rider keeps its own
        //     template_carries_source() suppression at the call site (it is not
        //     a variable). A variable-free template leaves every segment in
        //     place, so the user turn stays non-empty — required, because the
        //     Anthropic adapter lifts `system` to a top-level field and rejects
        //     an empty messages[] ──
        $user_segments = array();
        if (!in_array('keyword', $referenced, true)) {
            // A single-element array is the normal per-item case; the
            // consolidated batch passes every keyword so ONE article covers all.
            $user_segments[] = count($keywords) > 1
                ? "Write a SINGLE comprehensive article that covers ALL of the following keywords together, "
                  . "as distinct sections or subtopics within one cohesive piece: \"" . implode('", "', $keywords) . "\""
                : "Write a comprehensive article targeting the keyword: \"" . ($keywords[0] ?? '') . "\"";
        }
        if (!in_array('output_format', $referenced, true)) {
            $user_segments[] = $output_format;
        }
        if ($media_block !== '' && !in_array('media_instructions', $referenced, true)) {
            $user_segments[] = $media_block;
        }
        if ($rss_source !== '') {
            $user_segments[] = $rss_source;
        }

        // Every user-side fragment was placed in the template, so there is
        // nothing left to append and an EMPTY user turn would follow — which
        // Anthropic rejects outright (it lifts `system` to a top-level field and
        // the empty content block in messages[] fails the request).
        //
        // The nudge is the BARE generation target, never $keyword_text: for a
        // consolidated batch that variable carries the whole "cover ALL of them
        // in ONE article" framing, so reusing it here printed that instruction
        // in BOTH turns — the same duplicate-fragment bug this feature exists to
        // remove, just relocated onto `keyword`.
        if ($user_segments === array()) {
            $user_segments[] = $keywords !== array()
                ? implode(', ', $keywords)
                : 'Write the article now.';
        }

        $messages[] = array(
            'role'    => 'user',
            'content' => implode("\n\n", $user_segments),
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

    // ========================================
    // Internal keep-alive chain (always-on)
    // ========================================
    //
    // PHP has no persistent process — nothing inside WordPress can wake itself
    // at a future time, which is why WP-cron depends on traffic. This chain is
    // the ONE internal workaround: a background request that sleeps in short
    // slices (beating a heartbeat option), does the due scan work, then
    // re-spawns itself with a non-blocking loopback request. It runs BY
    // DEFAULT (owner ruling: automatic, no setup, no toggle) — links are kept
    // SHORT (~45s) so hosts with strict wall-clock request kills
    // (request_terminate_timeout counts sleep!) never get to kill one, and a
    // stale heartbeat + any visit → the init hook below respawns it anyway.
    // Emergency brake (no UI, wp-cli only): `option update pcm_keepalive_enabled 0`.

    /**
     * One link of the chain. Takes ownership (a newer link always wins — an
     * older one sees the owner change on its next slice and exits, so at most
     * two overlap for a single slice), sleeps 3 short slices with a
     * heartbeat, runs due work, then spawns the next link.
     */
    public static function run_keepalive_chain(): void
    {
        if (!function_exists('get_option') || (string) get_option('pcm_keepalive_enabled', '') === '0') {
            return; // '0' is the hidden emergency brake; absent/anything else = on
        }

        $me = function_exists('wp_generate_password')
            ? wp_generate_password(12, false, false)
            : (string) getmypid() . '-' . (string) mt_rand(1000, 9999);
        update_option('pcm_keepalive_owner', $me, false);
        update_option('pcm_keepalive_beat', time(), false);

        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        // Release the spawner's socket where the SAPI supports it (the spawner
        // is non-blocking and never reads the response anyway).
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        // Slice length is option-tunable (bounded 3–30s; default 15) so tests/
        // smokes can compress the loop. 3 slices ≈ 45s per link — deliberately
        // SHORT: FPM request_terminate_timeout counts wall-clock sleep, and
        // 60s is a common cap; a link that hands off in 45s survives strict
        // hosts where a 5-minute link would be killed mid-sleep every time.
        $slice = max(3, min(30, (int) get_option('pcm_keepalive_slice', 15)));
        // Due work FIRST — before any sleep. Some SAPIs (php -S; FPM without
        // fastcgi_finish_request; aggressive proxies) kill the handler when
        // the non-blocking spawner disconnects, which can be seconds in. A
        // work-first link means even a 100%-mortality host still runs the due
        // scans on every spawn (each visit's self-heal spawn ⇒ scans run);
        // the sleep+respawn tail is pure upside where the SAPI lets it live.
        try {
            $now = function_exists('current_time') ? (int) current_time('timestamp') : time();
            if (self::keepalive_rss_due((string) get_option('pcm_rss_last_scan', ''), $now)) {
                self::run_rss_scan();
            }
            self::run_scheduled_scan();
            // Un-wedge items stranded in 'generating' by a killed generator.
            // Runs BEFORE the event drain so a reclaimed item's re-armed
            // continuation can be picked up by this same link.
            self::reclaim_wedged_items();
            // Drain due PCM cron events DIRECTLY. On hosts with
            // DISABLE_WP_CRON (no system cron) or broken cron loopbacks,
            // single events — the instant first pull AND every generation
            // queue continuation — would otherwise NEVER fire: strategies sit
            // at 0/0 and items stay Pending forever (observed live). The
            // chain is the one execution context guaranteed to exist, so it
            // is also the cron-of-last-resort for this plugin's own events.
            self::process_due_pcm_events();
        } catch (\Throwable $e) {
            error_log('[PCM_Strategy_Service] keep-alive work failed: ' . $e->getMessage());
        }

        for ($i = 0; $i < 3; $i++) {
            @set_time_limit($slice + 40);
            sleep($slice);
            $state = array(
                'enabled' => (string) get_option('pcm_keepalive_enabled', ''),
                'owner'   => (string) get_option('pcm_keepalive_owner', ''),
            );
            if (self::keepalive_tick_decision($state, $me) === 'stop') {
                return;
            }
            update_option('pcm_keepalive_beat', time(), false);
        }

        self::spawn_keepalive();
    }

    /**
     * Un-wedge strategy items stranded in 'generating', globally.
     *
     * WHY: generate_next_item() claims an item ('generating') and only ever
     * writes 'error' from its catch block — so a generator process KILLED
     * mid-run (host wall-clock, FPM request_terminate_timeout, OOM) leaves the
     * row 'generating' with nothing to resolve it. That state cannot self-heal:
     *
     *   - process_due_pcm_events() unschedules an event BEFORE firing it, so a
     *     kill during the fire loses the queue continuation outright;
     *   - maybe_schedule_queue_continuation() only re-arms when an item is
     *     PENDING, and a wedged item is 'generating';
     *   - PCM_DB::reclaim_stale_generating() only runs from inside
     *     generate_next_item()'s next-pending path — which needs the tick that
     *     was just lost.
     *
     * Net effect before this sweep: one killed generation wedged that item
     * permanently (the "stuck at generating" report). Long articles are the
     * common victims — a single item can legitimately outlive the keep-alive
     * link that started it (LLM blocking_request default 300s + truncation
     * retry + featured image + in-content media, vs a link sized for 60s FPM
     * caps), so this is an expected condition, not an exotic one.
     *
     * The sweep itself adds no new recovery logic — it reuses the existing
     * reclaim ('generating' → 'pending') and the existing re-arm, which is all
     * that was missing. It runs on a 90-minute cutoff rather than the
     * per-strategy path's 10 (see the cutoff comment in the body); stealing an
     * item from a still-live generation is made SAFE — not merely unlikely — by
     * PCM_DB::complete_strategy_item_if_generating(), which turns the losing
     * run's completion into a no-op so it can neither double-count the item nor
     * publish a second post. Paused strategies are excluded by the query, and
     * maybe_schedule_queue_continuation() re-checks pause state anyway.
     * Failure-isolated per strategy so one bad row can't stop the rest.
     *
     * KNOWN LIMIT (RESOLVED): an item that can NEVER finish was previously
     * retried on every sweep rather than being failed after N attempts, re-
     * burning LLM/image spend every 90 min forever. The sweep now caps reclaim
     * retries per item via the attempts counter in strategy_items.config
     * (inline increment / wedge_max_attempts()): once an item has been
     * reclaimed MAX times it is marked 'error' instead of being requeued, so a
     * deterministically-failing item stops spending and clears its red row. A
     * manual Retry from the UI resets the counter (generate_next_item()'s
     * targeted-retry path). An orphan article row can still be left behind when
     * a kill lands between create_article() and the item write; each reclaim is
     * error_log()'d so the loop is visible.
     */
    public static function reclaim_wedged_items(): void
    {
        if (!function_exists('current_time')) {
            return;
        }
        // 90 minutes, NOT the per-strategy reclaim's 10. This sweep is global
        // and unattended, and a reclaim does not cancel the process it steals
        // the item from — so a cutoff under the pipeline's real worst case
        // means two live runs on one item. Correctness no longer RESTS on this
        // number (complete_strategy_item_if_generating() makes the loser's
        // completion a no-op), but a too-short cutoff still wastes a whole
        // generation, so it is set above the measured worst case rather than
        // near it: ~39 min for the default providers — 300s LLM + the
        // truncation retry's second 300s (PCM_LLM::blocking_request), up to 3
        // grounding passes at 180s, the Apify post-enrich at 120s, then the
        // featured image plus up to 8 in-content media at 120s each — and more
        // on a marketplace image provider, whose per-image wait is 800s.
        // Nothing heartbeats updatedAt while any of that runs. Recovery latency
        // is not the point: the per-strategy 10-minute reclaim still runs on
        // every generate tick and remains the fast path; this is only the last
        // resort for an item no tick will ever reach.
        $minutes = 90;
        $cutoff  = date('Y-m-d H:i:s', (int) strtotime(current_time('mysql')) - ($minutes * 60));
        $items   = PCM_DB::get_stale_generating_items($cutoff);
        $result  = self::reclaim_items_capped($items, $minutes);
        // Recompute counters for EVERY strategy that had an item touched — both
        // reclaimed-to-pending AND capped-to-failed — so a permanently-failed
        // item is reflected in failedItems/status immediately (a strategy whose
        // remaining items are all done must not falsely read 'completed' while a
        // failed item sits uncounted). Only reclaimed-to-pending strategies get
        // a queue continuation; a failed item must not re-arm.
        $touched = array();
        foreach ($result['reclaimed'] as $r) {
            $touched[(int) $r['strategyId']] = (int) $r['userId'];
        }
        foreach ($result['failed'] as $r) {
            $touched[(int) $r['strategyId']] = (int) $r['userId'];
        }
        foreach ($touched as $strategy_id => $user_id) {
            $strategy = PCM_DB::get_strategy($strategy_id, $user_id);
            $total = $strategy ? (int) ($strategy->totalItems ?? 0) : 0;
            self::recompute_counters($strategy_id, $user_id, $total);
        }
        // Re-arm the queue ONLY for strategies that had an item reclaimed to pending.
        foreach ($result['reclaimed'] as $r) {
            self::maybe_schedule_queue_continuation((int) $r['strategyId'], (int) $r['userId']);
        }
    }

    /**
     * Shared W3 reclaim engine: for each stale 'generating' item, read its
     * config.attempts and either fail it permanently (>= MAX) or flip it to
     * 'pending' and increment the counter. Failure-isolated per item. Returns
     * both buckets so the caller can recompute counters for every touched
     * strategy but re-arm the queue only for reclaimed-to-pending ones.
     *
     * @param object[] $items   Stale item rows (id, strategyId, userId, config).
     * @param int      $minutes Cutoff used (for log clarity only).
     * @return array{reclaimed:array<int,array{strategyId:int,userId:int}>, failed:array<int,array{strategyId:int,userId:int}>}
     */
    private static function reclaim_items_capped(array $items, int $minutes): array
    {
        $max       = self::wedge_max_attempts();
        $reclaimed = array();
        $failed    = array();
        foreach ($items as $row) {
            $item_id     = (int) $row->id;
            $strategy_id = (int) $row->strategyId;
            $user_id     = (int) $row->userId;
            try {
                $cfg      = empty($row->config) ? array() : (json_decode((string)$row->config, true) ?: array());
                $attempts = (int) ($cfg['attempts'] ?? 0);
                if ($attempts >= $max) {
                    // Give up: mark the item failed so it stops re-burning spend.
                    PCM_DB::update_strategy_item($item_id, array(
                        'status'       => 'error',
                        'errorMessage' => sprintf('generation failed after %d attempts', $max),
                    ));
                    $failed[] = array('strategyId' => $strategy_id, 'userId' => $user_id);
                    error_log(sprintf(
                        '[PCM_Strategy_Service] Item #%d on strategy #%d failed permanently after %d reclaim attempts — marked error.',
                        $item_id,
                        $strategy_id,
                        $max
                    ));
                } else {
                    // Reclaim: flip to 'pending' and bump the counter so the
                    // next reclaim sees one more attempt.
                    PCM_DB::update_strategy_item($item_id, array(
                        'status' => 'pending',
                        'config' => wp_json_encode(array_merge($cfg, array('attempts' => $attempts + 1))),
                    ));
                    error_log(sprintf(
                        '[PCM_Strategy_Service] Reclaimed item #%d stuck in generating on strategy #%d (attempt %d/%d, claimed >%d min ago) — usually a generator killed mid-run; re-arming the queue.',
                        $item_id,
                        $strategy_id,
                        $attempts + 1,
                        $max,
                        $minutes
                    ));
                    $reclaimed[] = array('strategyId' => $strategy_id, 'userId' => $user_id);
                }
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[PCM_Strategy_Service] Wedged-item reclaim failed for item #%d (strategy #%d): %s',
                    $item_id,
                    $strategy_id,
                    $e->getMessage()
                ));
            }
        }
        return array('reclaimed' => $reclaimed, 'failed' => $failed);
    }

    /**
     * The per-item reclaim retry cap (default 3). Option-tunable via
     * 'pcm_strategy_max_attempts' so an owner can raise it for a flaky provider
     * or lower it to fail faster. A manual UI Retry always resets the counter.
     *
     * @return int Maximum reclaim attempts before an item is failed.
     */
    public static function wedge_max_attempts(): int
    {
        $val = function_exists('get_option') ? (int) get_option('pcm_strategy_max_attempts', 3) : 3;
        return max(1, $val);
    }

    /**
     * Cron-of-last-resort: run this plugin's own DUE single events directly.
     *
     * WHY: pcm_strategy_rss_first_scan (the instant first pull) and
     * pcm_strategy_process_queue (every article generation) are wp-cron
     * single events. On hosts with DISABLE_WP_CRON and no working system
     * cron — or with broken cron-spawn loopbacks — those events pile up
     * forever and the whole pipeline visibly stalls (strategies 0/0, items
     * Pending). The keep-alive chain runs regardless of wp-cron, so it
     * drains them itself.
     *
     * Scope-limited to an allowlist of OUR hooks (never touches other
     * plugins' events), unschedules-then-dispatches (the same order wp-cron
     * uses, so a crash mid-handler can't loop the same event), re-entrancy
     * guarded, and time-budgeted (~4 min — generation runs ~1-2 min each; a
     * long backlog drains across successive links rather than one marathon).
     */
    public static function process_due_pcm_events(): void
    {
        static $running = false;
        if ($running
            || !function_exists('_get_cron_array')
            || !function_exists('wp_unschedule_event')
            || !function_exists('do_action')
        ) {
            return;
        }
        $running = true;

        $allowed  = array('pcm_strategy_rss_first_scan', 'pcm_strategy_process_queue', 'pcm_strategy_scheduled_scan');
        $deadline = microtime(true) + 240.0;

        try {
            $cron = _get_cron_array();
            if (!is_array($cron)) {
                return;
            }
            ksort($cron);
            foreach ($cron as $timestamp => $hooks) {
                if ($timestamp > time() || microtime(true) > $deadline) {
                    break;
                }
                foreach ((array) $hooks as $hook => $events) {
                    if (!in_array($hook, $allowed, true)) {
                        continue;
                    }
                    foreach ((array) $events as $event) {
                        if (microtime(true) > $deadline) {
                            break 3;
                        }
                        $args = is_array($event['args'] ?? null) ? $event['args'] : array();
                        wp_unschedule_event($timestamp, $hook, $args);
                        @set_time_limit(300);
                        try {
                            do_action_ref_array($hook, $args);
                        } catch (\Throwable $e) {
                            error_log(sprintf(
                                '[PCM_Strategy_Service] due-event %s failed: %s',
                                $hook,
                                $e->getMessage()
                            ));
                        }
                    }
                }
            }
        } finally {
            $running = false;
        }
    }

    /**
     * Pure slice decision: keep looping only while the feature is enabled AND
     * this link is still the owner (a newer link taking over = stop).
     *
     * @param array{enabled?:string,owner?:string} $state Current option values.
     * @param string $me This link's owner id.
     * @return string 'continue'|'stop'
     */
    public static function keepalive_tick_decision(array $state, string $me): string
    {
        // Always-on semantics: only the hidden emergency brake ('0') stops the
        // chain — absent/empty/anything else means enabled.
        if (($state['enabled'] ?? '') === '0') {
            return 'stop';
        }
        if (($state['owner'] ?? '') !== $me) {
            return 'stop';
        }
        return 'continue';
    }

    /**
     * Pure staleness check: is the hourly-grade RSS scan due? Empty/garbage
     * stamps count as due (never scanned, or an unparseable stamp is useless).
     *
     * @param string $last_scan 'Y-m-d H:i:s' site-local stamp (pcm_rss_last_scan).
     * @param int    $now       Site-local timestamp to compare against.
     * @return bool
     */
    public static function keepalive_rss_due(string $last_scan, int $now): bool
    {
        if ($last_scan === '') {
            return true;
        }
        $ts = strtotime($last_scan);
        return $ts === false || ($now - $ts) >= 55 * 60;
    }

    /**
     * Fire-and-forget loopback that starts the next chain link. No-op only
     * when the hidden emergency brake is set or the secret token is missing
     * (the public /strategies/keepalive route authenticates with that token).
     * Uses a non-blocking self-request — the same primitive WordPress core
     * uses to spawn its own cron (works on FPM; a dev `php -S` server cannot
     * service non-blocking loopbacks, but the work runs anyway, see below).
     */
    /**
     * Run one pass of the keep-alive chain's DUE WORK in the current process,
     * with no sleep and no respawn — the fallback for hosts where the loopback
     * self-request never lands (blocked/faked by the host, a security plugin, a
     * firewall, or a single-threaded dev server), which otherwise leaves
     * background scanning silently dead forever.
     *
     * Armed on `shutdown` by the init self-heal ONLY when the heartbeat has been
     * missing >15 min (i.e. repeated spawns demonstrably are not landing), and
     * throttled to once per 5 minutes. Running on shutdown means the HTTP
     * response has already been sent, so the visitor never waits for it.
     *
     * Deliberately the SAME calls as run_keepalive_chain()'s work-first block —
     * one behaviour, one place. It does NOT write pcm_keepalive_beat: the beat
     * means "a real chain link is alive", and faking it here would suppress the
     * spawn attempts that let a host recover the moment loopbacks work again.
     */
    public static function run_due_work_inline(): void
    {
        if (!function_exists('get_option') || (string) get_option('pcm_keepalive_enabled', '') === '0') {
            return; // same hidden emergency brake as the chain
        }
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        // Response is already flushed on FPM; make sure of it where supported so
        // this never holds the connection open.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        @set_time_limit(180);

        try {
            $now = function_exists('current_time') ? (int) current_time('timestamp') : time();
            if (self::keepalive_rss_due((string) get_option('pcm_rss_last_scan', ''), $now)) {
                self::run_rss_scan();
            }
            self::run_scheduled_scan();
            self::reclaim_wedged_items();
            self::process_due_pcm_events();
        } catch (\Throwable $e) {
            error_log('[PCM_Strategy_Service] inline due-work fallback failed: ' . $e->getMessage());
        }
    }

    public static function spawn_keepalive(): void
    {
        if (!function_exists('get_option') || (string) get_option('pcm_keepalive_enabled', '') === '0') {
            return; // hidden emergency brake only — default is on
        }
        $token = (string) get_option('pcm_cron_token', '');
        if ($token === '' || !function_exists('rest_url') || !function_exists('wp_remote_post')) {
            return;
        }
        // timeout 2 (not 0.1-0.5): fire-and-forget requests with sub-second
        // timeouts frequently disconnect BEFORE the connection is even
        // established — the spawn silently never lands (observed live). Two
        // seconds guarantees the handshake while still not blocking anything
        // user-facing for long (this runs from init self-heal / chain tails).
        wp_remote_post(rest_url('pcm/v1/strategies/keepalive'), array(
            'timeout'   => 2,
            'blocking'  => false,
            // Local self-request may sit behind a self-signed cert — same
            // exemption as PCM_Input_Resolver's loopback fetches.
            'sslverify' => false,
            'body'      => array('token' => $token),
        ));
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
    // Hourly RSS watcher (Filip's Source=RSS strategies). Hook wired at file
    // load like the two above. Unlike pcm_strategy_scheduled_scan (whose
    // recurring event power-creatives.php arms), the RSS event is armed LAZILY
    // right here on init — deliberately, so the entire RSS-watcher surface
    // stays inside this module and no shared bootstrap file changes. The
    // wp_next_scheduled() guard makes re-arming a no-op on every subsequent
    // load, exactly like pcm_init()'s own once-only scheduling.
    add_action('pcm_strategy_rss_scan', array('PCM_Strategy_Service', 'run_rss_scan'));
    // Create-time first scan for a single RSS strategy (instant first pull —
    // generates from the feed's newest EXISTING item instead of waiting for
    // the hourly watcher). One-off event armed by create_from_keywords().
    add_action('pcm_strategy_rss_first_scan', array('PCM_Strategy_Service', 'run_rss_first_scan'), 10, 2);
    // Wedge reclaim: un-strand items whose generator process was KILLED mid-run
    // (PHP max_execution_time / FPM request_terminate_timeout / OOM never reach
    // generate_next_item()'s catch, so the row stays 'generating'). reclaim_wedged_items()
    // is also called from the keep-alive chain, but that chain is traffic-driven — on a
    // DISABLE_WP_CRON host with no loopback, or simply a quiet site, a killed item would
    // wedge permanently. This recurring cron is the traffic-independent fallback, the same
    // role the daily pcm_strategy_scheduled_scan plays for due items. Reuses the existing
    // idempotent sweep; the hook is wired at file load like the others above.
    add_action('pcm_strategy_wedge_reclaim', array('PCM_Strategy_Service', 'reclaim_wedged_items'));
    // Dedicated 15-min cron interval for the wedge-reclaim fallback. Registered
    // via the cron_schedules filter (same pattern as pcm_sites_health_interval
    // in power-creatives.php), so wp_schedule_event(...,'pcm_wedge_interval',...)
    // above resolves. 15 min is comfortably under the sweep's 90-min cutoff.
    add_filter('cron_schedules', static function (array $schedules): array {
        $schedules['pcm_wedge_interval'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __('Power Creatives wedge reclaim', 'power-creatives'),
        );
        return $schedules;
    });
    add_action('init', static function (): void {
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')
            && !wp_next_scheduled('pcm_strategy_rss_scan')) {
            wp_schedule_event(time(), 'hourly', 'pcm_strategy_rss_scan');
        }
        // Arm the wedge-reclaim cron with a dedicated 15-min interval (well under the
        // sweep's 90-min cutoff, so a killed item is recovered promptly regardless of
        // site traffic). The interval is registered below in the same init handler.
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')
            && !wp_next_scheduled('pcm_strategy_wedge_reclaim')) {
            wp_schedule_event(time(), 'pcm_wedge_interval', 'pcm_strategy_wedge_reclaim');
        }
        // The keep-alive loopback authenticates with this secret (spawn_keepalive
        // sends it; keepalive_link's hash_equals checks it), so it must exist
        // before the first spawn — generate it once, lazily, the same way the
        // event above is armed. Never rotated automatically.
        if (function_exists('get_option') && function_exists('update_option')
            && function_exists('wp_generate_password')
            && (string) get_option('pcm_cron_token', '') === '') {
            update_option('pcm_cron_token', wp_generate_password(40, false, false), false);
        }
        // Keep-alive bootstrap + self-heal (ALWAYS-ON): background scanning is
        // the default — no setup, no toggle. A fresh install has no heartbeat
        // (stale by definition), so the very first visit starts the chain; if
        // a host ever kills a link, the next visit — ANY visit — resurrects
        // it. Transient-guarded so a burst of requests spawns once. Hidden
        // emergency brake: option pcm_keepalive_enabled = '0' (wp-cli only).
        if (function_exists('get_option') && function_exists('get_transient')
            && (string) get_option('pcm_keepalive_enabled', '') !== '0'
            && (time() - (int) get_option('pcm_keepalive_beat', 0)) > 120
            && !get_transient('pcm_keepalive_respawn_lock')) {
            set_transient('pcm_keepalive_respawn_lock', 1, 90);
            PCM_Strategy_Service::spawn_keepalive();
        }

        // ── LOOPBACK-DEAD FALLBACK (the "auto-scan just doesn't run" fix) ──
        // spawn_keepalive() is fire-and-forget: it never reads the response, so
        // a host that BLOCKS or fakes loopback self-requests (managed WP, a
        // security plugin, a firewall, or a single-threaded local server) makes
        // every spawn look successful while the chain never executes. Combined
        // with DISABLE_WP_CRON that left background scanning silently dead
        // forever — the heartbeat simply never appears.
        //
        // So: when the heartbeat has been missing far longer than a working
        // chain could ever go quiet, stop trusting the loopback and run the due
        // work IN THIS PROCESS. Hooked to `shutdown` so it runs AFTER the
        // response is sent — the visitor's page is never slowed. Throttled by
        // its own transient, so a busy site runs it at most once per window.
        // Where loopbacks DO work this branch never fires (the beat stays
        // fresh); where they don't, scanning degrades to per-visit progress
        // instead of not happening at all.
        if (function_exists('get_option') && function_exists('get_transient')
            && function_exists('add_action')
            && (string) get_option('pcm_keepalive_enabled', '') !== '0'
            && (time() - (int) get_option('pcm_keepalive_beat', 0)) > 900 // 15 min ⇒ spawns are not landing
            && !get_transient('pcm_keepalive_inline_lock')) {
            set_transient('pcm_keepalive_inline_lock', 1, 300); // at most once per 5 min
            add_action('shutdown', array('PCM_Strategy_Service', 'run_due_work_inline'), 99);
        }
    });
}
