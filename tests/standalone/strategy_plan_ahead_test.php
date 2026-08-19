<?php
/**
 * Owner (card 14, re-sent 2026-08-19): "I set this one to read and post two per day. It
 * posted two immediately and set itself to completed … it should plan the two next
 * posts … when the posts are completed, it should plan the next post."
 *
 * The status half was closed on 08-18/19 (still_watching / revive / "Watching feed").
 * THIS closes the planning half: an as-content-arrives strategy now materialises the
 * NEXT window's queued feed items as PENDING rows dated at the moment each slot frees
 * — visible in the list ("Aug 20 · Pending"), generated on that date by the scheduled
 * scan (a future scheduledDate is never chained early), never doubling capacity
 * (backpressure counts by planned slot). When they complete, the next pass plans the
 * next window again — the watcher is never "done".
 *
 * EXECUTED against stubs: plan_ahead_arrivals() (cap, window, occupants, limit, queue
 * pop, dates), the DB helpers' SQL, the watcher wiring, the client mirror
 * (nextArrivalSlot: planned posts win; COALESCE occupancy).
 *
 * Run: php tests/standalone/strategy_plan_ahead_test.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$ROOT = dirname(__DIR__, 2);
$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void { global $PASS, $FAIL; if ($ok) { $PASS++; echo "  ok  $name\n"; } else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; } }
$svc = file_get_contents($ROOT . '/includes/modules/strategy/service.php');
$slice = static function (string $needle) use ($svc): string {
    $i = strpos($svc, $needle); $start = strrpos(substr($svc, 0, (int)$i), "\n") + 1;
    $j = strpos($svc, "\n    /**", (int)$i); if ($j === false) { $j = strpos($svc, "\n    public static function", (int)$i + 10); }
    $fn = substr($svc, $start, (int)$j - $start);
    if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
    return str_replace('private static', 'public static', $fn);
};
// Fixed "now" for the engine — 2026-08-19 10:30:00 site time.
function current_time($t) { return $t === 'mysql' ? '2026-08-19 10:30:00' : strtotime('2026-08-19 10:30:00'); }
class PCM_DB {
    public static $planned = 0; public static $total = 2; public static $occupants = array(); public static $created = array(); public static $updates = array(); public static $next_id = 100;
    public static $calls = array();
    public static function count_planned_strategy_items($id, $now) { self::$calls[] = array('planned', $id, $now); return self::$planned; }
    public static function count_strategy_items($id, $since = null) { self::$calls[] = array('count', $id, $since); return self::$total; }
    public static function get_window_occupant_times($id, $since) { self::$calls[] = array('occupants', $id, $since); return self::$occupants; }
    public static function create_rss_strategy_item($sid, $uid, $kw, $cfg) { self::$created[] = array('kw' => $kw, 'cfg' => $cfg); return self::$next_id++; }
    public static function update_strategy_item($id, $data) { self::$updates[] = array($id, $data); return true; }
}
eval('class Host { '
    . $slice('public static function plan_ahead_arrivals(') . "\n"
    . $slice('public static function rss_free_slots(') . "\n"
    . $slice('public static function rss_cadence_window_days(') . "\n"
    . $slice('public static function rss_pop_due_items(') . ' }');
function q(array $items): array { return array_map(static fn($t) => array('guid' => md5($t), 'title' => $t, 'link' => 'https://f.test/' . md5($t), 'text' => 'body of ' . $t), $items); }
function reset_db(int $planned, int $total, array $occ): void { PCM_DB::$planned = $planned; PCM_DB::$total = $total; PCM_DB::$occupants = $occ; PCM_DB::$created = array(); PCM_DB::$updates = array(); PCM_DB::$calls = array(); PCM_DB::$next_id = 100; }

echo "\n1. The owner's case: 2 per day, 2 just posted, 5 queued → the next 2 are PLANNED for tomorrow\n";
$cfg = array('sourceMode' => 'rss', 'rssCadence' => array('perWeek' => 2, 'unit' => 'day'), 'rssQueue' => q(array('A', 'B', 'C', 'D', 'E')));
reset_db(0, 2, array('2026-08-19 10:00:00', '2026-08-19 10:01:00'));
$n = Host::plan_ahead_arrivals(9, 1, $cfg, array('mode' => 'ongoing'));
check('two planned items created (cap 2, nothing planned yet)', $n === 2 && count(PCM_DB::$created) === 2, array($n, PCM_DB::$created));
check('they are the two FRESHEST queue entries (the pop’s own order), with source context', PCM_DB::$created[0]['cfg']['sourceTitle'] !== '' && PCM_DB::$created[0]['cfg']['sourceText'] !== '' && count($cfg['rssQueue']) === 3, array_map(static fn($c) => $c['kw'], PCM_DB::$created));
$dates = array_map(static fn($u) => $u[1]['scheduledDate'], PCM_DB::$updates);
check('dated at the moment each slot FREES: oldest occupant + 1 day → 2026-08-20 10:00 and 10:01', $dates === array('2026-08-20 10:00:00', '2026-08-20 10:01:00'), $dates);
check('the window read used the cadence unit (since = now − 1 day)', in_array(array('occupants', 9, '2026-08-18 10:30:00'), PCM_DB::$calls, true), PCM_DB::$calls);

echo "\n2. Steady state + guards\n";
$cfg2 = $cfg; reset_db(2, 4, array('2026-08-19 10:00:00', '2026-08-19 10:01:00', '2026-08-20 10:00:00', '2026-08-20 10:01:00'));
check('next window already planned (2 pending future) → nothing more is planned', Host::plan_ahead_arrivals(9, 1, $cfg2, array('mode' => 'ongoing')) === 0 && count($cfg2['rssQueue']) === 3);
$cfg3 = $cfg; reset_db(1, 3, array('2026-08-19 10:00:00', '2026-08-19 10:01:00', '2026-08-20 10:00:00'));
check('one already planned → exactly ONE more is planned (cap − planned)', Host::plan_ahead_arrivals(9, 1, $cfg3, array('mode' => 'ongoing')) === 1);
$cfg4 = $cfg; reset_db(0, 4, array('2026-08-19 10:00:00', '2026-08-19 10:01:00'));
check('a "limit 5" duration caps the plan to what is left (5 − 4 = 1)', Host::plan_ahead_arrivals(9, 1, $cfg4, array('mode' => 'limit', 'maxArticles' => 5)) === 1);
$cfg5 = array('sourceMode' => 'rss', 'rssCadence' => array('perWeek' => 2, 'unit' => 'day'), 'rssQueue' => q(array('A', 'B', 'C', 'D', 'E'))); reset_db(0, 5, array());
check('limit already reached → nothing planned, queue untouched', Host::plan_ahead_arrivals(9, 1, $cfg5, array('mode' => 'limit', 'maxArticles' => 5)) === 0 && count($cfg5['rssQueue']) === 5);
$cfg6 = array('sourceMode' => 'rss', 'rssCadence' => array('perWeek' => 2, 'unit' => 'day'), 'rssQueue' => array());
reset_db(0, 2, array('2026-08-19 10:00:00'));
check('empty queue → nothing to plan, no DB writes', Host::plan_ahead_arrivals(9, 1, $cfg6, array('mode' => 'ongoing')) === 0 && PCM_DB::$created === array());
$cfg7 = $cfg; reset_db(0, 1, array('2026-08-19 10:00:00'));
Host::plan_ahead_arrivals(9, 1, $cfg7, array('mode' => 'ongoing'));
$d7 = array_map(static fn($u) => $u[1]['scheduledDate'], PCM_DB::$updates);
check('fewer occupants than wanted: the free slot dates NOW, the occupied one when it frees', $d7 === array('2026-08-20 10:00:00', '2026-08-19 10:30:00'), $d7);
$cfgw = array('sourceMode' => 'rss', 'rssCadence' => array('perWeek' => 3, 'unit' => 'week'), 'rssQueue' => q(array('A', 'B', 'C', 'D')));
reset_db(0, 3, array('2026-08-13 09:00:00', '2026-08-15 09:00:00', '2026-08-18 09:00:00'));
Host::plan_ahead_arrivals(9, 1, $cfgw, array('mode' => 'ongoing'));
$dw = array_map(static fn($u) => $u[1]['scheduledDate'], PCM_DB::$updates);
check('weekly cadence: each slot frees 7 days after its occupant (3 per week → 08-20, 08-22, 08-25)', $dw === array('2026-08-20 09:00:00', '2026-08-22 09:00:00', '2026-08-25 09:00:00'), $dw);

echo "\n3. Wiring: the watcher plans after the immediate pops, only in as-content-arrives mode; counts follow planned slots\n";
check('scan_rss_strategy runs the plan-ahead step only when no drip schedule (schedule mode keeps its own dates)', preg_match('/\$planned = 0;\s*\r?\n\s*if \(\$schedule_cfg === null\) \{\s*\r?\n\s*\$planned = self::plan_ahead_arrivals\(\$strategy_id, \$user_id, \$config, \$duration\);/', $svc) === 1, 'not wired');
check('…and totalItems is refreshed when planned rows were added (recompute_counters stays honest: completed < total → never "completed")', strpos($svc, 'if ($inserted > 0 || $planned > 0) {') !== false);
check('…and the immediate background kick is still gated on the IMMEDIATE inserts only (planned rows must not be chained early)', strpos($svc, 'if ($inserted > 0 && $schedule_cfg === null) {') !== false);
$db = file_get_contents($ROOT . '/includes/core/db/class-pcm-db.php');
check('backpressure window count = COALESCE(scheduledDate, createdAt) (a post planned for tomorrow occupies tomorrow)', strpos($db, 'SELECT COUNT(*) FROM {$table} WHERE strategyId = %d AND COALESCE(scheduledDate, createdAt) >= %s') !== false, 'window count still by createdAt');
check('planned count = pending AND scheduledDate > now; occupants = COALESCE times oldest first', strpos($db, "AND status = 'pending' AND scheduledDate IS NOT NULL AND scheduledDate > %s") !== false && strpos($db, 'SELECT COALESCE(scheduledDate, createdAt) AS t FROM {$table} WHERE strategyId = %d AND COALESCE(scheduledDate, createdAt) >= %s ORDER BY t ASC') !== false);
check('a planned (future) pending item is never chained early (the continuation stops on a not-yet-due item)', preg_match('/\$due_date = \$next->scheduledDate \?\? null;\s*\r?\n\s*if \(!empty\(\$due_date\) && strtotime\(\$due_date\) > strtotime\(current_time\(\'mysql\'\)\)\) \{\s*\r?\n\s*return;/', $svc) === 1);
check('…and the scheduled scan picks due items of ANY non-paused strategy (not schedule-mode only)', strpos($db, "WHERE si.status = 'pending' AND si.scheduledDate IS NOT NULL") !== false && strpos($db, "AND si.scheduledDate <= %s AND s.status != 'paused'") !== false);

echo "\n4. The list: planned rows + 'N planned · next post: …' (client mirror EXECUTED)\n";
$ui = file_get_contents($ROOT . '/app/src/modules/Strategies/index.tsx');
$js = "const src = require('fs').readFileSync(process.argv[2], 'utf8');\n"
    . "const start = src.indexOf('export function describeCadence'); const end = src.indexOf('// ── Status indicator');\n"
    . "let block = src.slice(start, end).replace(/export function/g, 'function');\n"
    . "block = block.replace(/\\(publishingMode: string \\| undefined, config: any\\): string/, '(publishingMode, config)')\n"
    . "  .replace(/\\(config: any\\): number/, '(config)')\n"
    . "  .replace(/\\(config: any, items: Array<\\{ createdAt\\?: string; scheduledDate\\?: string; status\\?: string \\}>, now: Date = new Date\\(\\)\\): Date \\| null/, '(config, items, now = new Date())');\n"
    . "function summarizeRecurrence(r){ return 'REC'; } function recurrenceFromConfig(c){ return c; }\n"
    . "eval(block);\n"
    . "const out = {}; const now = new Date('2026-08-19T12:00:00Z');\n"
    . "const cfg = { sourceMode: 'rss', rssCadence: { perWeek: 2, unit: 'day' } };\n"
    . "const a = nextArrivalSlot(cfg, [{ createdAt: '2026-08-19 10:00:00' }, { createdAt: '2026-08-19 10:01:00' }, { status: 'pending', scheduledDate: '2026-08-20 10:00:00' }, { status: 'pending', scheduledDate: '2026-08-20 10:01:00' }], now);\n"
    . "out.plannedWins = a ? a.toISOString() : null;\n"
    . "const b = nextArrivalSlot(cfg, [{ createdAt: '2026-08-19 10:00:00' }, { createdAt: '2026-08-19 10:01:00' }, { status: 'completed', scheduledDate: '2026-08-21 10:00:00' }], now);\n"
    . "out.completedPlanIgnored = b ? b.toISOString() : null;\n"
    . "const c = nextArrivalSlot(cfg, [{ createdAt: '2026-08-18 09:00:00', scheduledDate: '2026-08-19 10:00:00' }, { createdAt: '2026-08-18 09:01:00', scheduledDate: '2026-08-19 10:01:00' }], now);\n"
    . "out.coalesce = c ? c.toISOString() : null;\n"
    . "console.log(JSON.stringify(out));\n";
$tmpjs = tempnam(sys_get_temp_dir(), 'plan') . '.cjs'; file_put_contents($tmpjs, $js);
$out = json_decode((string) shell_exec('node ' . escapeshellarg($tmpjs) . ' ' . escapeshellarg($ROOT . '/app/src/modules/Strategies/index.tsx') . ' 2>&1'), true); @unlink($tmpjs);
check('a planned pending post defines the next slot (the earliest one)', isset($out['plannedWins']) && strpos($out['plannedWins'], '2026-08-20') === 0, $out);
check('a COMPLETED item’s planned date (08-21) is ignored; occupancy then decides (oldest + 1 day → 08-20)', isset($out['completedPlanIgnored']) && strpos($out['completedPlanIgnored'], '2026-08-20') === 0, $out);
check('occupancy goes by the planned slot when there is one (COALESCE mirror): created 08-18 but slotted 08-19 → frees 08-20', isset($out['coalesce']) && strpos($out['coalesce'], '2026-08-20') === 0, $out);
check('the meta line shows "N planned" and says "next post" when a plan exists', strpos($ui, "{' '}· {planned} planned") !== false && strpos($ui, "· next{planned > 0 ? ' post' : ' slot'}:") !== false);
check('planned rows render their date tag like any scheduled item (itemDueTag reads scheduledDate for pending rows)', preg_match("/const due = String\(item\.scheduledDate \?\? ''\)\.trim\(\);\s*\r?\n\s*if \(due !== '' && parseDue\(due\)\) return \{ raw: due, published: false \};/", $ui) === 1);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
