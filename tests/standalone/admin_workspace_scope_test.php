<?php
/**
 * Admin workspace scope — a second admin sees and can edit the whole workspace.
 *
 * Reported: logged in as ANOTHER admin, the platform looks empty. Data is scoped
 * per PCM user, and the workspace law ("admins see every X — team-wide
 * oversight") was applied to brands, deliveries and approvals but NOT to sites,
 * strategies, articles, templates or automation rules — so admin #2, owning no
 * rows, saw empty modules.
 *
 * Also guards the write half: update_strategy/article/site used
 * WHERE id AND userId with the CALLER's id, and $wpdb->update() matching zero
 * rows returns 0 — reported as success while saving nothing (the brands
 * add_asset lesson). An admin edit now resolves the row's real owner first.
 *
 * Run: php tests/standalone/admin_workspace_scope_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

foreach (array('add_action', 'add_filter') as $fn) {
    if (!function_exists($fn)) { eval("function {$fn}() { return true; }"); }
}
if (!function_exists('__')) { function __($s, $d = null) { return $s; } }
if (!function_exists('esc_html')) { function esc_html($s) { return $s; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d) { return json_encode($d); } }
// Cache layer: pass-through (each call hits the fake DB, which the tests need).
if (!function_exists('get_transient')) { function get_transient($k) { return false; } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $e = 0) { return true; } }
if (!function_exists('delete_transient')) { function delete_transient($k) { return true; } }
if (!function_exists('wp_cache_get')) { function wp_cache_get($k, $g = '') { return false; } }
if (!function_exists('wp_cache_set')) { function wp_cache_set($k, $v, $g = '', $e = 0) { return true; } }
if (!function_exists('wp_cache_delete')) { function wp_cache_delete($k, $g = '') { return true; } }
if (!function_exists('sanitize_key')) { function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('current_time')) { function current_time($t) { return '2026-08-07 00:00:00'; } }

/** Admin membership, scriptable per check. */
class PCM_Access {
    public static array $admins = array();
    public static function is_admin(int $id): bool { return in_array($id, self::$admins, true); }
    public static function granted_brand_ids(int $id): array { return array(); }
    public static function granted_delivery_ids(int $id): array { return array(); }
    public static function scope_clause(string $col, string $idc, int $uid, array $g): array {
        return array('sql' => "{$col} = %d", 'params' => array($uid));
    }
}
class PCM_Schema { public static function table($n) { return "wp_pcm_{$n}"; } }

/**
 * Scriptable $wpdb: records queries, serves canned rows, and mimics the ONE
 * behaviour this bug hinged on — update() returning 0 (not false) when the
 * WHERE matches nothing.
 */
class FakeWpdb {
    public array $rows_by_table = array();   // table => row objects
    public array $queries = array();
    public $last_update_where = null;
    public function prepare($sql, ...$args) {
        foreach ($args as $a) {
            $sql = preg_replace('/%d/', (string) (int) (is_array($a) ? 0 : $a), $sql, 1);
            $sql = preg_replace('/%s/', "'" . (string) (is_array($a) ? '' : $a) . "'", $sql, 1);
        }
        return $sql;
    }
    public function get_results($sql) {
        $this->queries[] = $sql;
        return $this->select($sql);
    }
    public function get_row($sql) {
        $this->queries[] = $sql;
        $r = $this->select($sql);
        return $r[0] ?? null;
    }
    public function get_var($sql) {
        $this->queries[] = $sql;
        $r = $this->select($sql);
        if (!$r) { return null; }
        $row = (array) $r[0];
        // Honour the SELECTed column — "SELECT userId FROM ..." must return
        // userId, not whichever property happens to be first on the object.
        if (preg_match('/SELECT\s+([a-zA-Z_]+)\s+FROM/i', $sql, $c) && array_key_exists($c[1], $row)) {
            return (string) $row[$c[1]];
        }
        return (string) reset($row);
    }
    private function select($sql) {
        if (!preg_match('/FROM (\S+)/', $sql, $m)) { return array(); }
        $rows = $this->rows_by_table[$m[1]] ?? array();
        $out = array();
        foreach ($rows as $row) {
            if (preg_match('/WHERE .*userId = (\d+)/', $sql, $u) && (int) $row->userId !== (int) $u[1]) { continue; }
            if (preg_match('/WHERE id = (\d+)/', $sql, $i) && (int) $row->id !== (int) $i[1]) { continue; }
            if (preg_match("/module = '([^']+)'/", $sql, $mm) && (string) $row->module !== $mm[1]) { continue; }
            if (preg_match("/status = '([^']+)'/", $sql, $st) && (string) $row->status !== $st[1]) { continue; }
            if (preg_match('/id = (\d+) AND userId = (\d+)/', $sql, $b)
                && ((int) $row->id !== (int) $b[1] || (int) $row->userId !== (int) $b[2])) { continue; }
            $out[] = $row;
        }
        return $out;
    }
    public function get_col($sql) {
        $this->queries[] = $sql;
        $out = array();
        foreach ($this->select($sql) as $row) {
            $arr = (array) $row;
            if (preg_match('/SELECT\s+([a-zA-Z_]+)\s+FROM/i', $sql, $c) && array_key_exists($c[1], $arr)) {
                $out[] = $arr[$c[1]];
            } else {
                $out[] = reset($arr);
            }
        }
        return $out;
    }
    public function delete($table, $where, $formats = null) {
        $n = 0;
        $keep = array();
        foreach ($this->rows_by_table[$table] ?? array() as $row) {
            $hit = true;
            foreach ($where as $k => $v) { if ((string) $row->$k !== (string) $v) { $hit = false; break; } }
            if ($hit) { $n++; } else { $keep[] = $row; }
        }
        $this->rows_by_table[$table] = $keep;
        return $n; // rows removed; 0 on no match
    }
    public function update($table, $data, $where) {
        $this->last_update_where = $where;
        $n = 0;
        foreach ($this->rows_by_table[$table] ?? array() as $row) {
            $hit = true;
            foreach ($where as $k => $v) { if ((string) $row->$k !== (string) $v) { $hit = false; break; } }
            if ($hit) { foreach ($data as $k => $v) { $row->$k = $v; } $n++; }
        }
        return $n; // 0 on no match — NOT false. The whole bug lives here.
    }
}

$GLOBALS['wpdb'] = new FakeWpdb();

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/includes/core/db/class-pcm-db.php';

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

$db = $GLOBALS['wpdb'];
$OWNER = 1;   // the original admin, owns everything
$ADMIN2 = 7;  // the second admin — owns NOTHING (the reported login)
$USER = 9;    // a plain user
PCM_Access::$admins = array($OWNER, $ADMIN2);

$mk = function (string $table, int $id, int $uid, array $extra = array()) use ($db) {
    $db->rows_by_table["wp_pcm_{$table}"][] = (object) array_merge(
        array('id' => $id, 'userId' => $uid, 'name' => "row{$id}", 'status' => 'draft',
              'module' => 'writer', 'updatedAt' => '2026-08-07', 'createdAt' => '2026-08-07'),
        $extra
    );
};
foreach (array('sites', 'strategies', 'articles', 'templates') as $t) {
    $mk($t, 1, $OWNER);
    $mk($t, 2, $OWNER);
    $mk($t, 3, $USER);
}

echo "\n1. A second admin sees the whole workspace\n";
check('sites: admin2 sees all 3', count(PCM_DB::get_user_sites($ADMIN2)) === 3, count(PCM_DB::get_user_sites($ADMIN2)));
check('strategies: admin2 sees all 3', count(PCM_DB::get_user_strategies($ADMIN2)) === 3);
check('articles: admin2 sees all 3', count(PCM_DB::get_user_articles($ADMIN2)) === 3);
check('articles filtered by status too', count(PCM_DB::get_user_articles($ADMIN2, 'draft')) === 3);
check('templates: admin2 sees all 3', count(PCM_DB::get_user_templates($ADMIN2)) === 3);
check('templates module filter still applies', count(PCM_DB::get_user_templates($ADMIN2, 'writer')) === 3
    && count(PCM_DB::get_user_templates($ADMIN2, 'video')) === 0);

echo "\n2. A plain user still sees only their own\n";
check('sites: user sees 1', count(PCM_DB::get_user_sites($USER)) === 1);
check('strategies: user sees 1', count(PCM_DB::get_user_strategies($USER)) === 1);
check('articles: user sees 1', count(PCM_DB::get_user_articles($USER)) === 1);
check('templates: user sees 1', count(PCM_DB::get_user_templates($USER)) === 1);

echo "\n3. Detail views open for the admin (list row must never 404)\n";
check('get_site: teammate row readable', PCM_DB::get_site(1, $ADMIN2) !== null);
check('get_strategy: teammate row readable', PCM_DB::get_strategy(1, $ADMIN2) !== null);
check('get_article: teammate row readable', PCM_DB::get_article(1, $ADMIN2) !== null);
check('plain user still refused a foreign row', PCM_DB::get_site(1, $USER) === null);
check('a missing id is still null for an admin', PCM_DB::get_site(999, $ADMIN2) === null);

echo "\n4. Admin edits LAND instead of faking success\n";
$ok = PCM_DB::update_strategy(1, $ADMIN2, array('name' => 'renamed-by-admin2'));
$row = PCM_DB::get_strategy(1, $ADMIN2);
check('update_strategy returns true', $ok === true, $ok);
check('...and the row actually changed', ($row->name ?? '') === 'renamed-by-admin2', $row->name ?? null);
check('...written by id, not by the caller', !isset($db->last_update_where['userId']), $db->last_update_where);

$ok = PCM_DB::update_site(3, $ADMIN2, array('name' => 'site-touched'));
check('update_site on a USER-owned row lands too', $ok === true && PCM_DB::get_site(3, $ADMIN2)->name === 'site-touched');

$ok = PCM_DB::update_article(999, $ADMIN2, array('name' => 'x'));
check('a missing id fails honestly for an admin', $ok === false, $ok);

// The trap itself, proven still present for NON-admins so the fix is not a
// blanket unscope: a plain user "editing" a foreign row keeps the old
// zero-rows-is-true shape (their WHERE simply misses).
$before = PCM_DB::get_strategy(2, $OWNER)->name;
PCM_DB::update_strategy(2, $USER, array('name' => 'stolen'));
check('a plain user cannot write a foreign row', PCM_DB::get_strategy(2, $OWNER)->name === $before);

echo "\n5. Admin deletes LAND — including the strategy-items cascade\n";
// The screenshot toast "Deleted 0, 4 failed": admin2 passed the (now admin-aware)
// ownership check, the child items were cascade-deleted, then the parent delete
// ran WHERE userId = CALLER and missed — items orphaned, delete reported failed.
$db->rows_by_table['wp_pcm_strategy_items'] = array(
    (object) array('id' => 11, 'strategyId' => 1, 'userId' => $OWNER),
);
$ok = PCM_DB::delete_strategy(1, $ADMIN2);
check('admin delete of a teammate strategy succeeds', $ok === true, $ok);
check('the strategy row is gone', PCM_DB::get_strategy(1, $ADMIN2) === null);
check('its items went with it (no orphans)', count($db->rows_by_table['wp_pcm_strategy_items']) === 0);

$ok = PCM_DB::delete_site(3, $ADMIN2);
check('admin delete of a USER-owned site lands', $ok === true, $ok);
$ok = PCM_DB::delete_article(2, $ADMIN2);
check('admin delete of a teammate article lands', $ok === true, $ok);
check('a plain user still cannot delete a foreign row', PCM_DB::delete_article(1, $USER) === false);

echo "\n5b. Integrations / models: an admin with their OWN rows still sees the workspace\n";
// The old fallback fired only when the caller owned NOTHING — an admin with one
// key of their own lost sight of everything added by teammates.
$mk('integrations', 1, $OWNER); $mk('integrations', 2, $ADMIN2); $mk('integrations', 3, $USER);
$mk('models', 1, $OWNER); $mk('models', 2, $ADMIN2);
check('integrations: admin2 sees ALL despite owning one',
    count(PCM_DB::get_user_integrations($ADMIN2)) === 3, count(PCM_DB::get_user_integrations($ADMIN2)));
check('models: admin2 sees ALL despite owning one', count(PCM_DB::get_user_models($ADMIN2)) === 2);
check('integrations: plain user still sees only their own', count(PCM_DB::get_user_integrations($USER)) === 1);

echo "\n5c. scope_clause() is the central lever\n";
$src2 = file_get_contents($ROOT . '/includes/core/class-pcm-access.php');
check('admin short-circuit exists in scope_clause',
    strpos($src2, "'%d = %d'") !== false, 'no short-circuit');
check('projects list rides scope_clause (assets controller)',
    strpos(file_get_contents($ROOT . '/includes/modules/assets/controller.php'), 'scope_clause') !== false,
    'projects not covered');

echo "\n6. The automations list is EXEMPT from the law (superseded 2026-08-12)\n";
// This section used to require the admin branch on list_rules — that was the
// 08-07 sweep over-reaching. Automation rules are per-user EXECUTION CONFIG and
// every user carries an identical seeded set, so the team-wide list rendered one
// copy per platform user ("why is everything four of everything?"). The list is
// now scoped to what actually FIRES for the caller: own rules + foreign ADMINS'
// CUSTOM rules (mirroring run_rules()'s __seedKey gate). Deep coverage lives in
// automation_field_variables_test.php §5; this guards the exemption itself.
$auto = file_get_contents($ROOT . '/includes/modules/automations/service.php');
$lr_at = strpos($auto, 'function list_rules(');
check('list_rules found', $lr_at !== false, 'missing');
$lr = substr($auto, (int) $lr_at, 2400);
check('list_rules does NOT have the team-wide admin branch',
    !str_contains($lr, 'FROM {$table} ORDER BY createdAt DESC"'), 'the 4x bug is back');
check('foreign admin CUSTOM rules stay visible (they fire for everyone)',
    str_contains($lr, "role = 'admin'") && str_contains($lr, 'NOT LIKE'), 'over-reverted');

echo "\n" . str_repeat('─', 52) . "\n";
echo "  passed: $PASS   failed: $FAIL\n";
exit($FAIL > 0 ? 1 : 0);
