<?php
/**
 * "Template #56 not found." on a strategy item — why, and the fix.
 *
 * WHY: a strategy keeps its templateId after that template is DELETED (Templates →
 * delete had no "in use" guard — very likely during the Duplicate clean-up) or when
 * the row belongs to another user (load_template is scoped to the caller + shared
 * rows). Every item then failed with that one cryptic line and the row's Generation
 * template select went blank.
 *
 * FIX, both ends — EXECUTED here against a wpdb stub:
 *  A. load_template() self-heals: id gone + strategy given → the source's seed
 *     ("RSS Reposting" / "Social Media Reposting" / "SEO Pillar Article"; user's own
 *     copy over the shared one), else the user's default writer template, else any
 *     writer template — persisted onto the strategy, logged. Nothing at all → an
 *     actionable error (create one under Templates → Writer, pick it, retry).
 *  B. Templates delete / bulk delete refuse a template that strategies still use
 *     (writer template, image prompt, or a per-item override), naming them.
 *
 * Run: php tests/standalone/strategy_missing_template_test.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$ROOT = dirname(__DIR__, 2);
$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void { global $PASS, $FAIL; if ($ok) { $PASS++; echo "  ok  $name\n"; } else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; } }
$svc = file_get_contents($ROOT . '/includes/modules/strategy/service.php');
$slice = static function (string $needle) use ($svc): string {
    $i = strpos($svc, $needle); $start = strrpos(substr($svc, 0, (int)$i), "\n") + 1;
    $j = strpos($svc, "\n    /**", (int)$i); if ($j === false) { $j = strpos($svc, "\n    private static function", (int)$i + 10); }
    $fn = substr($svc, $start, (int)$j - $start);
    if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
    return str_replace('private static', 'public static', $fn);
};

// ── stubs ──
class PCM_Schema { public static function table($n) { return 'wp_pcm_' . $n; } }
// error_log() is a builtin — route it to a temp file and read that back.
$LOGF = tempnam(sys_get_temp_dir(), 'pcmlog'); ini_set('error_log', $LOGF); ini_set('log_errors', '1');
function read_log(): array { global $LOGF; $t = @file_get_contents($LOGF); return $t === false || trim($t) === '' ? array() : preg_split('/\R/', trim($t)); }
function clear_log(): void { global $LOGF; file_put_contents($LOGF, ''); }
class WPDB { public $rows = array(); public $sql = array();
    public function prepare($q, ...$a) { $this->sql[] = $q; foreach ($a as $v) { $q = preg_replace('/%[ds]/', is_int($v) ? (string) $v : "'" . $v . "'", $q, 1); } return $q; }
    public function get_row($q) {
        // by id (scoped)
        if (preg_match("/WHERE id = (\d+) AND \(userId = (\d+) OR userId = 0\)/", $q, $m)) {
            foreach ($this->rows as $r) { if ((int)$r->id === (int)$m[1] && ((int)$r->userId === (int)$m[2] || (int)$r->userId === 0)) { return $r; } }
            return null;
        }
        // seed by name (user's own wins)
        if (preg_match("/module = 'writer' AND name = '([^']+)' AND \(userId = (\d+) OR userId = 0\)(?: ORDER BY userId (ASC|DESC))?/", $q, $m)) {
            $c = array_values(array_filter($this->rows, static fn($r) => $r->module === 'writer' && $r->name === $m[1] && ((int)$r->userId === (int)$m[2] || (int)$r->userId === 0)));
            $desc = (($m[3] ?? 'ASC') === 'DESC'); // honour the SQL's ORDER BY — the stub must not decide the winner
            usort($c, static fn($a, $b) => $desc ? ((int)$b->userId <=> (int)$a->userId) : ((int)$a->userId <=> (int)$b->userId));
            return $c[0] ?? null;
        }
        // any writer template: default first, user's first
        if (preg_match("/module = 'writer' AND \(userId = (\d+) OR userId = 0\) ORDER BY isDefault DESC, userId DESC, id ASC/", $q, $m)) {
            $c = array_values(array_filter($this->rows, static fn($r) => $r->module === 'writer' && ((int)$r->userId === (int)$m[1] || (int)$r->userId === 0)));
            usort($c, static fn($a, $b) => [(int)$b->isDefault, (int)$b->userId, (int)$a->id] <=> [(int)$a->isDefault, (int)$a->userId, (int)$b->id]);
            return $c[0] ?? null;
        }
        return null;
    }
}
$GLOBALS['wpdb'] = new WPDB();
class PCM_DB { public static $updates = array(); public static function update_strategy($id, $uid, $data) { self::$updates[] = array($id, $uid, $data); return true; } }
function tpl(int $id, string $name, int $userId, int $isDefault = 0, string $module = 'writer'): object {
    return (object) array('id' => $id, 'name' => $name, 'module' => $module, 'userId' => $userId, 'isDefault' => $isDefault, 'formData' => json_encode(array('type' => 'article', 'entries' => array(array('key' => 'p', 'category' => 'prompt', 'label' => $name, 'value' => "Prompt of $name")))));
}
eval('class Host { ' . $slice('private static function fallback_writer_template(') . "\n" . $slice('private static function load_template(') . ' }');
$rss = (object) array('id' => 9, 'templateId' => 56, 'config' => json_encode(array('sourceMode' => 'rss')));
$kw  = (object) array('id' => 10, 'templateId' => 56, 'config' => json_encode(array('sourceMode' => 'keywords')));

echo "\n1. The failure, reproduced: #56 is gone (or another user's)\n";
$GLOBALS['wpdb']->rows = array(tpl(56, 'Filip blog', 2 /* another user */), tpl(3, 'RSS Reposting', 0), tpl(4, 'SEO Pillar Article', 0), tpl(7, 'Social Media Reposting', 0));
try { Host::load_template(56, 1); check('without a strategy, a foreign/deleted id still throws (no silent guess)', false); }
catch (\RuntimeException $e) { check('without a strategy, a foreign/deleted id still throws — now with the remedy', strpos($e->getMessage(), 'no longer exists') !== false && strpos($e->getMessage(), 'Templates → Writer') !== false, $e->getMessage()); }

echo "\n2. Self-heal, source-aware\n";
PCM_DB::$updates = array(); clear_log();
$t = Host::load_template(56, 1, $rss);
check('an RSS strategy falls back to the "RSS Reposting" seed', $t['name'] === 'RSS Reposting', $t['name']);
check('…the choice is persisted onto the strategy (row select shows it, next run is direct)', PCM_DB::$updates === array(array(9, 1, array('templateId' => 3))) && $rss->templateId === 3, PCM_DB::$updates);
$lg = read_log();
check('…and logged with the reason', count($lg) === 1 && strpos($lg[0], 'no longer exists') !== false && strpos($lg[0], '"RSS Reposting" (#3)') !== false, $lg);
$t = Host::load_template(56, 1, $kw);
check('a KEYWORD strategy falls back to "SEO Pillar Article" — never a reposting template (empty {{ post_* }})', $t['name'] === 'SEO Pillar Article', $t['name']);
$soc = (object) array('id' => 11, 'templateId' => 56, 'config' => json_encode(array('sourceMode' => 'social')));
check('a SOCIAL strategy → "Social Media Reposting"', Host::load_template(56, 1, $soc)['name'] === 'Social Media Reposting');
// user's own copy of the seed wins over the shared one
$GLOBALS['wpdb']->rows[] = tpl(20, 'RSS Reposting', 1);
$rss2 = (object) array('id' => 12, 'templateId' => 56, 'config' => json_encode(array('sourceMode' => 'rss')));
$t = Host::load_template(56, 1, $rss2);
check("the user's OWN copy of the seed wins over the shared one", $rss2->templateId === 20, $rss2->templateId);
// no seed → default writer template → any
$GLOBALS['wpdb']->rows = array(tpl(30, 'My default', 1, 1), tpl(31, 'Other', 1, 0));
$rss3 = (object) array('id' => 13, 'templateId' => 56, 'config' => json_encode(array('sourceMode' => 'rss')));
check('no seed at all → the user’s DEFAULT writer template', Host::load_template(56, 1, $rss3)['name'] === 'My default');
$GLOBALS['wpdb']->rows = array(tpl(31, 'Other', 1, 0), tpl(40, 'Copy tpl', 1, 1, 'copy'));
$rss4 = (object) array('id' => 14, 'templateId' => 56, 'config' => json_encode(array('sourceMode' => 'rss')));
check('no default → any WRITER template (never a copy/image template)', Host::load_template(56, 1, $rss4)['name'] === 'Other');
$GLOBALS['wpdb']->rows = array(tpl(40, 'Copy tpl', 1, 1, 'copy'));
$rss5 = (object) array('id' => 15, 'templateId' => 56, 'config' => json_encode(array('sourceMode' => 'rss')));
try { Host::load_template(56, 1, $rss5); check('no writer template anywhere → throws', false); }
catch (\RuntimeException $e) { check('no writer template anywhere → throws the actionable message (never a copy template)', strpos($e->getMessage(), 'no Writer template is available') !== false, $e->getMessage()); }
// a FOUND id is untouched
$GLOBALS['wpdb']->rows = array(tpl(56, 'Filip blog', 1), tpl(3, 'RSS Reposting', 0)); PCM_DB::$updates = array();
$rss6 = (object) array('id' => 16, 'templateId' => 56, 'config' => json_encode(array('sourceMode' => 'rss')));
check('an id that EXISTS for this user loads as before — no fallback, no write', Host::load_template(56, 1, $rss6)['name'] === 'Filip blog' && PCM_DB::$updates === array());
check('both article-generation call sites pass the strategy (image prompt keeps its own default fallback)', substr_count($svc, 'self::load_template($template_id, $user_id, $strategy);') === 1 && substr_count($svc, 'self::load_template((int)$strategy->templateId, $user_id, $strategy);') === 1);

echo "\n3. Templates delete refuses an in-use template (the cause, closed)\n";
$ctl = file_get_contents($ROOT . '/includes/modules/templates/controller.php');
check('single delete: 409 pcm_template_in_use naming the strategies', preg_match("/\\\$users = \\\$this->strategies_using_templates\(array\(\\\$id\), \(int\)\\\$user->id\);\s*\r?\n\s*if \(!empty\(\\\$users\[\\\$id\]\)\) \{[\s\S]{0,600}?409, 'pcm_template_in_use'\);/", $ctl) === 1, 'no guard');
check('…checked BEFORE the DELETE runs', strpos($ctl, 'strategies_using_templates(array($id)') < strpos($ctl, "DELETE FROM \$table WHERE id = %d AND (userId = %d OR userId = 0)"));
check('bulk delete: in-use ids are SKIPPED and reported (deleted / skipped / skippedReason)', strpos($ctl, "\$ids     = array_values(array_filter(array_map('intval', \$ids), static fn(\$i) => \$i > 0 && empty(\$in_use[\$i])));") !== false && strpos($ctl, "return \$this->success(array('deleted' => \$deleted, 'skipped' => count(\$skipped), 'skippedReason' => \$skipped));") !== false, 'bulk still deletes in-use');
check('the resolver covers writer template, image prompt AND per-item overrides, with exact-id matching after the LIKE prefilter',
    strpos($ctl, "WHERE userId = %d AND (templateId = %d OR config LIKE %s)") !== false && strpos($ctl, "if ((int) (\$r->templateId ?? 0) === \$id || \$img === \$id) {") !== false && strpos($ctl, "if (is_array(\$icfg) && (int) (\$icfg['templateId'] ?? 0) === \$id) {") !== false);
$ui = file_get_contents($ROOT . '/app/src/modules/Templates/index.tsx');
check('the bulk-delete toast says what was kept and why', strpos($ui, 'kept because ${skipped === 1 ? "a strategy still uses it" : "strategies still use them"}') !== false);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
