<?php
/**
 * Card 14 — Strategies / Updates. Four items, each EXECUTED where the code allows:
 *
 *  1. Templates: "I clicked Duplicate on a Writer template, and it deleted all others."
 *     The list hid every SHARED (seeded) row whose formData.type matched a row the
 *     user owns — right for SEO/Optimizer (type = the overridden section), wrong for
 *     Writer/Copy/Image/Video (type = a category shared by many). Duplicating a seeded
 *     writer template created a user-owned 'article' row → all seeded writer templates
 *     vanished. The section rule is now scoped to seo/optimizer.
 *  2. Strategies: an RSS strategy "2 per day continually" posted its first two and set
 *     itself 'completed' — and the watcher queries skip completed rows, so it silently
 *     stopped watching. recompute_counters() keeps a still-watching source strategy at
 *     'in_progress' (UI: "Watching feed"); run_rss_scan() revives ones parked earlier.
 *  3. Cadence pill + dialog with "reschedule the unpublished posts" before Save
 *     (PATCH `reschedulePending`), and the row plans ahead ("N queued · next slot").
 *  4. Web-enabled generation: PCM_LLM `web` option → each provider's own web tool;
 *     strategy article generation turns it on (per-strategy opt-out, global kill-switch).
 *
 * Run: php tests/standalone/strategies_updates_card14_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$ROOT = dirname(__DIR__, 2);
$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}
$slice_of = static function (string $src, string $needle): string {
    $i = strpos($src, $needle); $start = strrpos(substr($src, 0, (int)$i), "\n") + 1;
    $j = strpos($src, "\n    /**", (int)$i); if ($j === false) { $j = strpos($src, "\n    public static function", (int)$i + 10); }
    $fn = substr($src, $start, (int)$j - $start);
    if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
    return str_replace('private static', 'public static', $fn);
};

// ═══ 1. Templates list — Duplicate must not hide the seeded writer templates ═══
echo "\n1. Templates list: the section-hiding rule is scoped to seo/optimizer\n";
$tctl = file_get_contents($ROOT . '/includes/modules/templates/controller.php');
$ls = strpos($tctl, '$results = $results ?: array();'); $le = strpos($tctl, '// Parse JSON formData and format for frontend', $ls);
$filter_src = substr($tctl, $ls, $le - $ls);
$GLOBALS['user'] = (object) array('id' => 7);
function run_list_filter(array $results): array {
    global $filter_src; $user = $GLOBALS['user'];
    eval($filter_src);
    return array_values(array_map(static fn($r) => $r->name, $results));
}
$rows = array(
    (object) array('userId' => 0, 'module' => 'writer', 'name' => 'RSS Reposting',        'formData' => json_encode(array('type' => 'article'))),
    (object) array('userId' => 0, 'module' => 'writer', 'name' => 'SEO Pillar Article',   'formData' => json_encode(array('type' => 'article'))),
    (object) array('userId' => 0, 'module' => 'writer', 'name' => 'Social Media Reposting','formData' => json_encode(array('type' => 'article'))),
    (object) array('userId' => 7, 'module' => 'writer', 'name' => 'RSS Reposting (copy)', 'formData' => json_encode(array('type' => 'article'))), // the Duplicate
    (object) array('userId' => 0, 'module' => 'seo',    'name' => 'Meta Title — Generate', 'formData' => json_encode(array('type' => 'meta_title'))),
    (object) array('userId' => 7, 'module' => 'seo',    'name' => 'My meta title prompt',  'formData' => json_encode(array('type' => 'meta_title'))), // a fork
    (object) array('userId' => 0, 'module' => 'seo',    'name' => 'Meta Desc — Generate',  'formData' => json_encode(array('type' => 'meta_description'))),
    (object) array('userId' => 0, 'module' => 'copy',   'name' => 'AIDA',                  'formData' => json_encode(array('type' => 'social_ads'))),
    (object) array('userId' => 7, 'module' => 'copy',   'name' => 'AIDA (copy)',           'formData' => json_encode(array('type' => 'social_ads'))),
);
$shown = run_list_filter($rows);
check('after Duplicate, ALL seeded writer templates stay visible beside the copy', in_array('RSS Reposting', $shown, true) && in_array('SEO Pillar Article', $shown, true) && in_array('Social Media Reposting', $shown, true) && in_array('RSS Reposting (copy)', $shown, true), $shown);
check('SEO keeps its section rule: the user’s meta_title fork HIDES the shared meta_title default…', !in_array('Meta Title — Generate', $shown, true) && in_array('My meta title prompt', $shown, true), $shown);
check('…but not another section’s default', in_array('Meta Desc — Generate', $shown, true), $shown);
check('copy: type is a category too — the seeded AIDA survives its own copy', in_array('AIDA', $shown, true) && in_array('AIDA (copy)', $shown, true), $shown);
$rows2 = array(
    (object) array('userId' => 0, 'module' => 'writer', 'name' => 'RSS Reposting', 'formData' => json_encode(array('type' => 'article'))),
    (object) array('userId' => 7, 'module' => 'writer', 'name' => 'RSS Reposting', 'formData' => json_encode(array('type' => 'article'))), // an EDIT of the seed (same name)
);
check('an owned row with the SAME NAME still hides the shared original (fork semantics kept)', run_list_filter($rows2) === array('RSS Reposting') && count(run_list_filter($rows2)) === 1, run_list_filter($rows2));

// ═══ 2. A watched source is never "completed" while it still watches ═══
echo "\n2. recompute_counters(): a live RSS/Social strategy stays in_progress; revive parks\n";
$svc = file_get_contents($ROOT . '/includes/modules/strategy/service.php');
if (!function_exists('current_time')) { function current_time($t) { return '2026-08-18 12:00:00'; } }
class PCM_Schema { public static function table($n) { return 'wp_pcm_' . $n; } }
class PCM_DB {
    public static $items = array(); public static $strategy = null; public static $updates = array(); public static $count = 0;
    public static function get_strategy_items($id) { return self::$items; }
    public static function get_strategy($id, $uid) { return self::$strategy; }
    public static function update_strategy($id, $uid, $data) { self::$updates[] = array($id, $data); if (self::$strategy) { foreach ($data as $k => $v) { self::$strategy->$k = $v; } } return true; }
    public static function count_strategy_items($id, $since = null) { return self::$count; }
}
class WPDB_S { public $rows = array(); public function prepare($q, ...$a) { return $q; } public function esc_like($s) { return $s; } public function get_results($q) { return $this->rows; } }
$GLOBALS['wpdb'] = new WPDB_S();
eval('class StratHost { private static function finalize_on_completion($a, $b) { $GLOBALS["finalized"][] = $a; } '
    . $slice_of($svc, 'public static function recompute_counters(') . "\n"
    . $slice_of($svc, 'public static function still_watching(') . "\n"
    . $slice_of($svc, 'public static function rss_duration_blocked(') . "\n"
    . $slice_of($svc, 'public static function revive_completed_watchers(') . ' }');
$GLOBALS['finalized'] = array();
$rss = static fn(array $extra = array()) => (object) (array('id' => 5, 'userId' => 7, 'status' => 'in_progress', 'totalItems' => 2,
    'config' => json_encode($extra + array('sourceMode' => 'rss', 'rssFeeds' => array('https://x/feed'), 'rssCadence' => array('perWeek' => 2, 'unit' => 'day'), 'duration' => array('mode' => 'ongoing'))))); // $extra wins
PCM_DB::$items = array((object) array('status' => 'completed'), (object) array('status' => 'completed'));
PCM_DB::$strategy = $rss(); PCM_DB::$count = 2; PCM_DB::$updates = array();
StratHost::recompute_counters(5, 7, 2);
check('RSS "2 per day, continually": both items published → status stays in_progress (watching), NOT completed', PCM_DB::$updates[0][1]['status'] === 'in_progress' && PCM_DB::$updates[0][1]['completedItems'] === 2, PCM_DB::$updates);
check('…and completion side effects (interlinks) do NOT fire', $GLOBALS['finalized'] === array());
PCM_DB::$strategy = $rss(array('duration' => array('mode' => 'limit', 'maxArticles' => 2))); PCM_DB::$updates = array();
StratHost::recompute_counters(5, 7, 2);
check('a "limit 2" RSS strategy that reached 2 published items IS completed (duration ended it)', PCM_DB::$updates[0][1]['status'] === 'completed', PCM_DB::$updates);
PCM_DB::$strategy = $rss(array('duration' => array('mode' => 'until', 'endDate' => '2026-08-01'))); PCM_DB::$updates = array();
StratHost::recompute_counters(5, 7, 2);
check('an "until 2026-08-01" RSS strategy (date passed) IS completed', PCM_DB::$updates[0][1]['status'] === 'completed', PCM_DB::$updates);
PCM_DB::$strategy = (object) array('id' => 6, 'userId' => 7, 'status' => 'in_progress', 'totalItems' => 2, 'config' => json_encode(array('sourceMode' => 'keywords'))); PCM_DB::$updates = array(); $GLOBALS['finalized'] = array();
StratHost::recompute_counters(6, 7, 2);
check('a KEYWORD strategy with every item published completes exactly as before (+ finalize fires once)', PCM_DB::$updates[0][1]['status'] === 'completed' && $GLOBALS['finalized'] === array(6), PCM_DB::$updates);
// revive
$parked = $rss(); $parked->status = 'completed';
$ended  = $rss(array('duration' => array('mode' => 'limit', 'maxArticles' => 2))); $ended->status = 'completed'; $ended->id = 9;
$GLOBALS['wpdb']->rows = array($parked, $ended); PCM_DB::$updates = array(); PCM_DB::$count = 2;
$n = StratHost::revive_completed_watchers();
check('revive: the wrongly-parked continual RSS strategy goes back to in_progress; the genuinely-ended one stays completed', $n === 1 && PCM_DB::$updates === array(array(5, array('status' => 'in_progress'))), PCM_DB::$updates);
check('revive runs at the top of every RSS scan pass', preg_match('/public static function run_rss_scan\(\): void[\s\S]{0,1200}?self::revive_completed_watchers\(\);/', $svc) === 1);
check('the watcher queries still skip completed rows (so the status law is what keeps a feed alive)', strpos($svc, "status NOT IN ('paused','completed')") !== false);

// ═══ 3. Cadence pill / dialog / reschedule flag / planning ahead ═══
echo "\n3. Cadence pill + dialog + reschedulePending + planning ahead\n";
$ctl = file_get_contents($ROOT . '/includes/modules/strategy/controller.php');
check('PATCH honours reschedulePending: absent → legacy always-reschedule; false → leave the planned dates alone',
    preg_match("/\\\$reschedule_pending = !array_key_exists\('reschedulePending', \\\$params\) \|\| !empty\(\\\$params\['reschedulePending'\]\);[\s\S]{0,260}?if \(\\\$schedule_changed && \\\$reschedule_pending\) \{/", $ctl) === 1, 'flag not honoured');
// Round 3 (owner: "this feature is not implemented … a flickering thing appears and disappears"):
check('the PATCH reports what it did — rescheduled (schedule mode) and replanned (as-content-arrives) counts', strpos($ctl, '$strategy->rescheduled = $rescheduled;') !== false && strpos($ctl, '$strategy->replanned   = $replanned;') !== false && strpos($ctl, '$rescheduled = PCM_Strategy_Service::reschedule_pending_items(') !== false, 'no counts in the response');
check('as-content-arrives + toggle → the queue is re-planned under the new limit right away (rssCadence change, non-schedule mode)', strpos($ctl, "\$cadence_changed = is_array(\$params['config'] ?? null) && array_key_exists('rssCadence', (array) \$params['config']);") !== false && strpos($ctl, 'if ($cadence_changed && $reschedule_pending) {') !== false && strpos($ctl, '$replanned = PCM_Strategy_Service::replan_queue_now($strategy_id, (int)$pcm_user->id);') !== false, 'no arrive-mode replan');
check('replan_queue_now() = one ordinary watcher pass for THIS strategy, social never forced (no paid Apify call)', preg_match('/public static function replan_queue_now\(int \$strategy_id, int \$user_id\): int[\s\S]{0,500}?return \(int\) self::scan_rss_strategy\(\$strategy, false\);/', $svc) === 1, 'replan missing / forced');
$ui = file_get_contents($ROOT . '/app/src/modules/Strategies/index.tsx');
// Execute describeCadence + nextArrivalSlot (TS → JS via node)
$js = "const src = require('fs').readFileSync(process.argv[2], 'utf8');\n"
    . "const start = src.indexOf('export function describeCadence'); const end = src.indexOf('// ── Status indicator');\n"
    . "let block = src.slice(start, end).replace(/export function/g, 'function');\n"
    . "block = block.replace(/\\(publishingMode: string \\| undefined, config: any\\): string/, '(publishingMode, config)')\n"
    . "  .replace(/\\(config: any\\): number/, '(config)')\n"
    . "  .replace(/\\(config: any, items: Array<\\{ createdAt\\?: string; scheduledDate\\?: string; status\\?: string \\}>, now: Date = new Date\\(\\)\\): Date \\| null/, '(config, items, now = new Date())');\n"
    . "function summarizeRecurrence(r){ return 'REC:' + JSON.stringify(r); } function recurrenceFromConfig(c){ return c; }\n"
    . "eval(block);\n"
    . "const out = {};\n"
    . "out.arrive = describeCadence('publish', { sourceMode: 'rss', rssCadence: { perWeek: 2, unit: 'day' } });\n"
    . "out.arriveDefault = describeCadence('draft', { sourceMode: 'social' });\n"
    . "out.ondemand = describeCadence('draft', { sourceMode: 'keywords' });\n"
    . "out.sched = describeCadence('schedule', { sourceMode: 'rss', scheduleConfig: { unit: 'week' } });\n"
    . "const now = new Date('2026-08-18T12:00:00Z');\n"
    . "const cfg = { sourceMode: 'rss', rssCadence: { perWeek: 2, unit: 'day' } };\n"
    . "out.slotOpen = nextArrivalSlot(cfg, [{ createdAt: '2026-08-18 09:00:00' }], now);\n"
    . "const full = nextArrivalSlot(cfg, [{ createdAt: '2026-08-18 09:00:00' }, { createdAt: '2026-08-18 10:30:00' }], now);\n"
    . "out.slotFull = full ? full.toISOString() : null;\n"
    . "out.slotAged = nextArrivalSlot(cfg, [{ createdAt: '2026-08-17 09:00:00' }, { createdAt: '2026-08-17 10:30:00' }], now);\n"
    . "console.log(JSON.stringify(out));\n";
$tmpjs = tempnam(sys_get_temp_dir(), 'cad') . '.cjs'; file_put_contents($tmpjs, $js);
$out = json_decode((string) shell_exec('node ' . escapeshellarg($tmpjs) . ' ' . escapeshellarg($ROOT . '/app/src/modules/Strategies/index.tsx') . ' 2>&1'), true); @unlink($tmpjs);
check('describeCadence: RSS as-posts-arrive → "2 per day"', ($out['arrive'] ?? null) === '2 per day', $out);
check('describeCadence: source without a stored cadence → the engine default "3 per week"', ($out['arriveDefault'] ?? null) === '3 per week', $out);
check('describeCadence: keyword strategy not scheduled → "On demand"', ($out['ondemand'] ?? null) === 'On demand', $out);
check('describeCadence: schedule mode → the recurrence summary', strpos((string) ($out['sched'] ?? ''), 'REC:') === 0, $out);
check('nextArrivalSlot: 1 of 2 slots used → a slot is open now (null)', array_key_exists('slotOpen', $out) && $out['slotOpen'] === null, $out);
check('nextArrivalSlot: both slots used → opens when the OLDEST in-window item ages out (09:00 + 1 day, in that TZ)', isset($out['slotFull']) && $out['slotFull'] !== null && strpos($out['slotFull'], '2026-08-19') === 0, $out);
check('nextArrivalSlot: yesterday’s items aged out → open now', array_key_exists('slotAged', $out) && $out['slotAged'] === null, $out);
check('the row shows the cadence pill (describeCadence) where "Set schedule" used to be', strpos($ui, "title={`Posting cadence: \${describeCadence(strategy.publishingMode, config)} — click to change it`}") !== false && strpos($ui, "'Set schedule'") === false);
check('the row plans ahead: "N queued · N planned · next slot/post: …" for source strategies', strpos($ui, "{' '}· {queued} queued") !== false && strpos($ui, "{' '}· {planned} planned") !== false && strpos($ui, "· next{planned > 0 ? ' post' : ' slot'}: {nextSlot ? nextSlot.toLocaleString(") !== false);
check('the dialog offers the modes per strategy shape and the reschedule checkbox before Save', strpos($ui, "[{ value: 'arrive', label: 'As posts arrive' }, { value: 'schedule', label: 'On a schedule' }]") !== false && strpos($ui, "[{ value: 'ondemand', label: 'On demand' }, { value: 'schedule', label: 'On a schedule' }]") !== false && strpos($ui, 'Also reschedule the unpublished posts') !== false);
check('Save sends reschedulePending = the toggle for BOTH schedule and as-posts-arrive modes; false for on-demand', substr_count($ui, 'reschedulePending: d.reschedule,') === 2 && substr_count($ui, 'reschedulePending: false,') === 1);
check('the toggle renders in every mode that has something to re-plan (not schedule-only), with mode-specific copy', strpos($ui, "{d.mode !== 'ondemand' && (") !== false && strpos($ui, "{d.mode === 'schedule' ? 'Also reschedule the unpublished posts' : 'Also apply the new limit to the queued posts now'}") !== false, 'toggle still schedule-only');
check('Save waits for the server: own mutation (no generic toast), Saving… state, dialog closes only on success', strpos($ui, "const cadenceMutation = trpc.strategy.update.useMutation({") !== false && strpos($ui, "const res: any = await cadenceMutation.mutateAsync(payload);") !== false && strpos($ui, "{cadenceMutation.isPending ? 'Saving…' : 'Save'}") !== false && preg_match("/toast\.success\(what \+ tail, \{ duration: 6000 \}\);\s*\r?\n\s*setCadenceDialog\(null\);/", $ui) === 1 && strpos($ui, "toast.error(err?.message ?? 'Could not save the cadence.');") !== false, 'save still fire-and-forget');
check('…and the one toast SAYS what happened to the unpublished posts (counts from the server)', strpos($ui, "moved onto the new dates.") !== false && strpos($ui, "released under the new limit.") !== false && strpos($ui, "const rescheduled = Number(res?.rescheduled ?? 0) || 0;") !== false && strpos($ui, "const replanned = Number(res?.replanned ?? 0) || 0;") !== false);
// Round 2 (owner: "these issues are not fixed"): the badge takes the DURATION-AWARE client mirror of
// still_watching(), and a still-watching feed reads "Watching feed" even while the DB row is a stale
// 'completed' from an older build.
check('the status badge reads "Watching feed" for a live source strategy', strpos($ui, "watching:     { icon: <Rss className=\"w-3 h-3\" />, label: 'Watching feed'") !== false && strpos($ui, '<StatusBadge status={strategy.status} watching={isSource && stillWatching(config, Number(strategy.totalItems) || 0)} />') !== false);
check('…including a stale-completed row (older build parked it)', preg_match("/\(watching && \(status === 'in_progress' \|\| status === 'pending' \|\| isDone\) \? 'watching' : status\)/", $ui) === 1);
check('stillWatching(): the exact until/limit rules are present (not just the words)', strpos($ui, "if (end && new Date(end + 'T23:59:59').getTime() < Date.now()) return false;") !== false && strpos($ui, "if (max > 0 && Number(totalItems || 0) >= max) return false;") !== false);
check('stillWatching() mirrors still_watching(): ongoing → true, until past → false, limit reached → false, keyword → false', preg_match("/export function stillWatching\(config: any, totalItems: number\): boolean \{[\s\S]*?if \(!config \|\| !\['rss', 'social'\]\.includes[\s\S]*?mode === 'until'[\s\S]*?mode === 'limit'[\s\S]*?return true;/", $ui) === 1);
$pill_row = substr($ui, strpos($ui, "{isRss ? 'RSS' : isSocial ? 'Social' : 'Keywords'}"), 2600);
check('the cadence PILL sits in the always-visible identity row (collapsed rows too), not only in the expanded controls',
    strpos($pill_row, 'THE CADENCE PILL') !== false && strpos($pill_row, '<button') !== false
    && strpos($pill_row, 'Posting cadence: ${describeCadence(strategy.publishingMode, config)}') !== false
    && strpos($pill_row, '<CalendarClock className="w-3 h-3" />') !== false
    && strpos($pill_row, '{describeCadence(strategy.publishingMode, config)}') !== false, 'no identity-row pill');
$ctl = file_get_contents($ROOT . '/includes/modules/strategy/controller.php');
check('parked feed strategies are revived on the LIST fetch (before the cached read) and on Scan now — not only by cron', preg_match('/PCM_Strategy_Service::revive_completed_watchers\(\);\s*?
\s*\$strategies = PCM_DB::get_user_strategies/', $ctl) === 1 && preg_match('/PCM_Strategy_Service::revive_completed_watchers\(\);\s*?
\s*\$result = PCM_Strategy_Service::scan_strategy_now/', $ctl) === 1, 'revive missing on list/scan-now');
$llm2 = file_get_contents($ROOT . '/includes/core/llm/class-pcm-llm.php');
check('PCM_LLM::web_default() = the shared kill-switch read + the Settings switch', preg_match("/public static function web_default\(\): bool\s*\{\s*if \(function_exists\('get_option'\) && \(string\) get_option\('pcm_llm_web_search', '1'\) === '0'\) \{\s*return false;[\s\S]*?PCM_Settings::get\('llm_web_search', true\)/", $llm2) === 1);
foreach (array(
    'includes/modules/seo/ai.php'                          => "'web' => PCM_LLM::web_default()",
    'includes/modules/seo/service.php'                     => "array('max_tokens' => 1400, 'web' => PCM_LLM::web_default())",
    'includes/modules/writer/service.php'                  => "\$invoke_args['web'] = PCM_LLM::web_default();",
    'includes/modules/writer/class-pcm-article-review.php' => "'web' => PCM_LLM::web_default()",
) as $f => $needle) {
    check("web-enabled by default at the long-form site $f", strpos(file_get_contents($ROOT . '/' . $f), $needle) !== false, 'no web at ' . $f);
}
$loc = file_get_contents($ROOT . '/includes/modules/seo/local.php');
check('SEO section rewrites are web-enabled, one-line HEADING rewrites are not', strpos($loc, "'web' => !\$single_line && PCM_LLM::web_default()") !== false);
check('short scalar SEO fields (title/description bulk) stay off the web tools', preg_match("/function invoke_scalar_field[\s\S]{0,1600}?PCM_LLM::invoke\(/", file_get_contents($ROOT . '/includes/modules/seo/ai.php')) === 1 && !preg_match("/function invoke_scalar_field[\s\S]{0,1600}?'web'/", file_get_contents($ROOT . '/includes/modules/seo/ai.php')));

// ═══ 4. Web-enabled generation ═══
echo "\n4. Web-enabled generation — provider-native web tools, on by default for articles\n";
$llm = file_get_contents($ROOT . '/includes/core/llm/class-pcm-llm.php');
check('invoke(): web → OpenAI Responses API path', preg_match("/if \(\\\$web && \\\$provider === 'openai'\) \{\s*\r?\n\s*return self::invoke_openai_responses\(\\\$payload, \\\$api_key, \\\$options\);/", $llm) === 1);
check('invoke(): web → Google native grounding path (+ finish/refusal keys)', preg_match("/if \(\\\$web && \\\$provider === 'google'\) \{[\s\S]*?invoke_with_grounding\(\\\$messages[\s\S]*?\\+ array\('finish_reason' => '', 'refusal' => ''\)/", $llm) === 1);
check('invoke(): web → Anthropic server tool web_search_20250305 (bounded)', strpos($llm, "array('type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5)") !== false);
check('streaming callers keep the plain path', preg_match("/\\\$web = !empty\(\\\$options\['web'\]\) && !is_callable\(\\\$on_chunk\);/", $llm) === 1);
// Execute the Responses adapter against a scripted HTTP reply.
$GLOBALS['http'] = array();
function wp_remote_post($url, $args) { $GLOBALS['http'][] = array($url, json_decode($args['body'], true)); return $GLOBALS['reply']; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function is_wp_error($x) { return false; }
function wp_json_encode($v) { return json_encode($v); }
eval('class LlmHost { const DEFAULT_MAX_TOKENS = 16384; ' . $slice_of($llm, 'private static function invoke_openai_responses(') . "\n" . $slice_of($llm, 'private static function parse_response(') . ' }');
$GLOBALS['reply'] = array('code' => 200, 'body' => json_encode(array('model' => 'gpt-4.1', 'status' => 'completed', 'usage' => array('total_tokens' => 10), 'output' => array(
    array('type' => 'web_search_call', 'status' => 'completed'),
    array('type' => 'message', 'content' => array(array('type' => 'output_text', 'text' => '{"title":"A"'), array('type' => 'output_text', 'text' => ',"content":"<p>B</p>"}'))),
))));
$payload = array('model' => 'gpt-4.1', 'messages' => array(array('role' => 'system', 'content' => 'SYS'), array('role' => 'user', 'content' => 'Write it')), 'max_completion_tokens' => 12288,
    'response_format' => array('type' => 'json_schema', 'json_schema' => array('name' => 'article', 'strict' => true, 'schema' => array('type' => 'object', 'properties' => array('title' => array('type' => 'string'))))));
$r = LlmHost::invoke_openai_responses($payload, 'sk-test', array('timeout' => 60));
$sent = $GLOBALS['http'][0][1];
check('Responses request: web_search_preview tool, system → instructions, user → input, max_output_tokens', $GLOBALS['http'][0][0] === 'https://api.openai.com/v1/responses' && $sent['tools'] === array(array('type' => 'web_search_preview')) && $sent['instructions'] === 'SYS' && $sent['input'] === array(array('role' => 'user', 'content' => 'Write it')) && $sent['max_output_tokens'] === 12288, $sent);
check('response_format json_schema → text.format (name/schema/strict)', ($sent['text']['format']['type'] ?? '') === 'json_schema' && ($sent['text']['format']['name'] ?? '') === 'article' && ($sent['text']['format']['strict'] ?? null) === true, $sent['text'] ?? null);
check('the reply’s message text parts are JOINED (tool-call items skipped) — the parse_response shape', $r['content'] === '{"title":"A","content":"<p>B</p>"}' && $r['finish_reason'] === 'completed' && $r['refusal'] === '' && $r['model'] === 'gpt-4.1', $r);
$GLOBALS['reply'] = array('code' => 200, 'body' => json_encode(array('status' => 'incomplete', 'incomplete_details' => array('reason' => 'max_output_tokens'), 'output' => array(array('type' => 'message', 'content' => array(array('type' => 'output_text', 'text' => 'cut')))))));
$r = LlmHost::invoke_openai_responses($payload, 'sk-test');
check('truncation → finish_reason "length" (invoke_json retries with more budget)', $r['finish_reason'] === 'length' && $r['content'] === 'cut', $r);
$GLOBALS['reply'] = array('code' => 400, 'body' => json_encode(array('error' => array('message' => "Invalid parameter: 'text.format' is not supported with this model."))));
try { LlmHost::invoke_openai_responses($payload, 'sk-test'); $threw = ''; } catch (\RuntimeException $e) { $threw = $e->getMessage(); }
check('a rejected text.format is re-worded so invoke_json’s classifier falls through to the next tier', stripos($threw, 'response_format not supported') !== false && stripos($threw, 'LLM API error 400') !== false, $threw);
$p = LlmHost::parse_response(array('content' => array(array('type' => 'server_tool_use', 'id' => 'x'), array('type' => 'text', 'text' => 'Part 1. '), array('type' => 'web_search_tool_result', 'content' => array()), array('type' => 'text', 'text' => 'Part 2.')), 'stop_reason' => 'end_turn'));
check('Anthropic web reply: ALL text blocks joined, tool blocks skipped (content[0]-only would have dropped the article)', $p['content'] === 'Part 1. Part 2.', $p);
check('strategy article generation passes web = web_enabled($strategy) at BOTH call sites', substr_count($svc, "\$llm_options['web'] = self::web_enabled(\$strategy);") === 2);
eval('class WebHost { ' . $slice_of($svc, 'public static function web_enabled(') . ' }');
if (!function_exists('get_option')) { function get_option($k, $d = '') { return $GLOBALS['opts'][$k] ?? $d; } }
$GLOBALS['opts'] = array();
check('web_enabled: default ON', WebHost::web_enabled((object) array('config' => json_encode(array('sourceMode' => 'rss')))) === true);
check('web_enabled: per-strategy opt-out (config.webEnabled=false)', WebHost::web_enabled((object) array('config' => json_encode(array('webEnabled' => false)))) === false);
$GLOBALS['opts']['pcm_llm_web_search'] = '0';
check('web_enabled: global kill-switch option', WebHost::web_enabled((object) array('config' => '')) === false);
check('the config sanitizer accepts webEnabled (so a toggle can be saved)', strpos($ctl, "\$config['webEnabled'] = (bool)\$fields['webEnabled'];") !== false);

echo "
5. Web-enabled generation is FINDABLE: Settings switch + per-strategy controls (owner: 'where did you make this? I can't find it')
";
// PCM_LLM::web_default() EXECUTED against a PCM_Settings stub — the Settings switch is the source of truth.
class PCM_LLM_Settings_Stub { public static $v = true; public static function get($k, $d = null) { return $k === 'llm_web_search' ? self::$v : $d; } }
$wd = $slice_of($llm, 'public static function web_default(');
$wd = str_replace('PCM_Settings::get(', 'PCM_LLM_Settings_Stub::get(', str_replace("class_exists('PCM_Settings')", 'true', $wd));
eval('class WebDefaultHost { ' . $wd . ' }');
$GLOBALS['opts'] = array();
check('web_default(): Settings switch on (default) → web on', WebDefaultHost::web_default() === true);
PCM_LLM_Settings_Stub::$v = false;
check('web_default(): Settings switch OFF → web off', WebDefaultHost::web_default() === false);
PCM_LLM_Settings_Stub::$v = '0';
check('web_default(): a stringly "0" also reads as off', WebDefaultHost::web_default() === false);
PCM_LLM_Settings_Stub::$v = true; $GLOBALS['opts']['pcm_llm_web_search'] = '0';
check('web_default(): the operator kill-switch option still wins', WebDefaultHost::web_default() === false);
$GLOBALS['opts'] = array();
check('the Settings default declares llm_web_search = true (absent = on)', preg_match("/'llm_web_search' => true,/", file_get_contents($ROOT . '/includes/core/class-pcm-settings.php')) === 1);
check('strategy web_enabled() defers to the Settings switch (off everywhere when off)', preg_match("/if \(class_exists\('PCM_LLM'\) && !PCM_LLM::web_default\(\)\) \{\s*?
\s*return false;/", $svc) === 1);
check('POST /settings stores llm_web_search as a real boolean', strpos(file_get_contents($ROOT . '/includes/modules/settings/controller.php'), "\$params['llm_web_search'] = filter_var(\$params['llm_web_search'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;") !== false);
$wss = file_get_contents($ROOT . '/app/src/modules/Settings/WebSearchSection.tsx');
$sidx = file_get_contents($ROOT . '/app/src/modules/Settings/index.tsx');
check('Settings → Model Registry renders the "Web-enabled generation" switch (saves llm_web_search via settings.update)', strpos($sidx, '<WebSearchSection />') !== false && strpos($wss, 'Web-enabled generation') !== false && strpos($wss, 'trpc.settings.update.useMutation') !== false && strpos($wss, 'saveMutation.mutate({ llm_web_search: !!on })') !== false);
check('…reads absent-as-on', strpos($wss, "const enabled = settings.llm_web_search !== false && settings.llm_web_search !== 0 && settings.llm_web_search !== '0';") !== false);
$dlg = file_get_contents($ROOT . '/app/src/modules/Keywords/CreateStrategyDialog.tsx');
check('strategy dialog: "Web access" switch, default ON, loaded from config.webEnabled, sent as webEnabled', strpos($dlg, 'id="web-enabled"') !== false && strpos($dlg, 'const [webEnabled, setWebEnabled] = useState(true);') !== false && strpos($dlg, 'if (cfg.webEnabled !== undefined) setWebEnabled(cfg.webEnabled !== false);') !== false && preg_match('/
\s+webEnabled,?
\s+inContentMedia,/', $dlg) === 1);
check('strategy row: a "Web" tick (checked unless webEnabled === false) with a partial-config mutation', strpos($ui, 'checked={config.webEnabled !== false}') !== false && strpos($ui, "updateStrategyMutation.mutate({ id: strategyId, config: { webEnabled: enabled } });") !== false);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
