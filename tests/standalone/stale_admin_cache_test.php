<?php
/**
 * Dropdown edits on the WEB version: "Strategy updated" then snaps back.
 *
 * Reported: every per-strategy dropdown (status/site/approval/template/model)
 * saves with a success toast on the shortcode SPA but the shown value never
 * changes; the same edit works in wp-admin.
 *
 * Cause: list getters are cached per CALLER for 300s, but the write path
 * (admin owner-resolution, previous round) invalidates the row OWNER's key.
 * In wp-admin caller == owner (`wp_<ID>` owns the rows) so the cleared key is
 * the one being read. On the web version the gate-login admin is a DIFFERENT
 * PCM user: the write lands in the DB, the caller's own cached list survives,
 * and the post-save refetch serves the stale transient.
 *
 * Fix under test: PCM_DB::invalidate() clears the owner's key AND every
 * admin's key — admins read team-wide lists, so any write stales all of them.
 *
 * NOTE this harness's transients STORE (array-backed). The sibling
 * admin_workspace_scope_test.php stubs get_transient to always miss, which is
 * precisely why it could never catch a staleness bug.
 *
 * Run: php tests/standalone/stale_admin_cache_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

foreach (array('add_action', 'add_filter') as $fn) {
    if (!function_exists($fn)) { eval("function {$fn}() { return true; }"); }
}
if (!function_exists('__')) { function __($s, $d = null) { return $s; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d) { return json_encode($d); } }
if (!function_exists('sanitize_key')) { function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('current_time')) { function current_time($t) { return '2026-08-07 00:00:00'; } }

// ── STORING transients — the whole point of this harness ──────────────────
// serialize/unserialize on the way in and out: WP transients round-trip
// through the options table, so a cached list is a SNAPSHOT. Storing live
// object references instead would let a later row mutation "update" the
// cache in place and silently hide exactly the staleness this test exists
// to catch (which is how the first version of this harness missed it).
$GLOBALS['transients'] = array();
function get_transient($k) { return isset($GLOBALS['transients'][$k]) ? unserialize($GLOBALS['transients'][$k]) : false; }
function set_transient($k, $v, $e = 0) { $GLOBALS['transients'][$k] = serialize($v); return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function wp_cache_get($k, $g = '') { return false; }
function wp_cache_set($k, $v, $g = '', $e = 0) { return true; }
function wp_cache_delete($k, $g = '') { return true; }

/** Admin membership, scriptable. */
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

/** Row-store $wpdb: selects filter on userId/id/role, update() MUTATES rows. */
class FakeWpdb {
    public array $rows_by_table = array();
    public function prepare($sql, ...$args) {
        foreach ($args as $a) {
            $sql = preg_replace('/%d/', (string) (int) $a, $sql, 1);
            $sql = preg_replace('/%s/', "'" . (string) $a . "'", $sql, 1);
        }
        return $sql;
    }
    private function select($sql) {
        if (!preg_match('/FROM (\S+)/', $sql, $m)) { return array(); }
        $out = array();
        foreach ($this->rows_by_table[$m[1]] ?? array() as $row) {
            if (preg_match('/WHERE .*\buserId = (\d+)/', $sql, $u) && (int) $row->userId !== (int) $u[1]) { continue; }
            if (preg_match('/WHERE id = (\d+)/', $sql, $i) && (int) $row->id !== (int) $i[1]) { continue; }
            if (preg_match("/role = '([^']+)'/", $sql, $r) && (string) ($row->role ?? '') !== $r[1]) { continue; }
            $out[] = $row;
        }
        return $out;
    }
    private function col_of($sql, $row) {
        $arr = (array) $row;
        if (preg_match('/SELECT\s+([a-zA-Z_]+)\s+FROM/i', $sql, $c) && array_key_exists($c[1], $arr)) {
            return $arr[$c[1]];
        }
        return reset($arr);
    }
    public function get_results($sql) { return $this->select($sql); }
    public function get_row($sql) { $r = $this->select($sql); return $r[0] ?? null; }
    public function get_var($sql) { $r = $this->select($sql); return $r ? (string) $this->col_of($sql, $r[0]) : null; }
    public function get_col($sql) {
        return array_map(fn($row) => $this->col_of($sql, $row), $this->select($sql));
    }
    public function update($table, $data, $where) {
        $n = 0;
        foreach ($this->rows_by_table[$table] ?? array() as $row) {
            $hit = true;
            foreach ($where as $k => $v) { if ((string) $row->$k !== (string) $v) { $hit = false; break; } }
            if ($hit) { foreach ($data as $k => $v) { $row->$k = $v; } $n++; }
        }
        return $n; // 0 on no match — never false
    }
    public function delete($table, $where, $formats = null) {
        $keep = array(); $n = 0;
        foreach ($this->rows_by_table[$table] ?? array() as $row) {
            $hit = true;
            foreach ($where as $k => $v) { if ((string) $row->$k !== (string) $v) { $hit = false; break; } }
            if ($hit) { $n++; } else { $keep[] = $row; }
        }
        $this->rows_by_table[$table] = $keep;
        return $n;
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
$OWNER = 1;   // wp-admin's PCM row (wp_<ID>) — owns the strategies
$GATE  = 7;   // gate-login admin on the shortcode SPA — owns NOTHING
$THIRD = 8;   // another admin, elsewhere
$USER  = 9;   // plain platform user
PCM_Access::$admins = array($OWNER, $GATE, $THIRD);

// The users table backs admin_user_ids() — the fix's one new query.
foreach (array($OWNER => 'admin', $GATE => 'admin', $THIRD => 'admin', $USER => 'user') as $id => $role) {
    $db->rows_by_table['wp_pcm_users'][] = (object) array('id' => $id, 'role' => $role);
}
$db->rows_by_table['wp_pcm_strategies'][] = (object) array(
    'id' => 1, 'userId' => $OWNER, 'name' => 'Massage RSS', 'status' => 'draft',
    'publishingMode' => 'draft', 'config' => '{"imageModel":""}',
    'updatedAt' => '2026-08-07', 'createdAt' => '2026-08-07',
);

echo "\n1. THE BUG — gate admin edits, own refetch must show it\n";
$before = PCM_DB::get_user_strategies($GATE);                       // caches under u7
check('warm-up: gate admin sees the strategy', count($before) === 1, count($before));
check('warm-up: cache is actually populated (harness sanity)',
    get_transient('pcm_strategies_u' . $GATE) !== false,
    array_keys($GLOBALS['transients']));
$saved = PCM_DB::update_strategy(1, $GATE, array('config' => '{"imageModel":"gpt-image-1.5-i2i"}'));
check('the PATCH reports success', $saved === true, $saved);
$row = $db->rows_by_table['wp_pcm_strategies'][0];
check('…and the write actually landed in the DB', str_contains((string) $row->config, 'gpt-image-1.5-i2i'), $row->config);
$after = PCM_DB::get_user_strategies($GATE);                        // the refetch
check('REFETCH SHOWS THE NEW VALUE (this is what snapped back)',
    isset($after[0]) && str_contains((string) $after[0]->config, 'gpt-image-1.5-i2i'),
    $after[0]->config ?? null);

echo "\n2. wp-admin unchanged — owner edits own row, own list fresh\n";
PCM_DB::get_user_strategies($OWNER);
PCM_DB::update_strategy(1, $OWNER, array('publishingMode' => 'publish'));
$own = PCM_DB::get_user_strategies($OWNER);
check('owner refetch shows the new value', ($own[0]->publishingMode ?? '') === 'publish', $own[0]->publishingMode ?? null);

echo "\n3. Every OTHER admin's team-wide list is busted too\n";
PCM_DB::get_user_strategies($THIRD);                                // third admin caches
PCM_DB::update_strategy(1, $OWNER, array('publishingMode' => 'schedule'));
$third = PCM_DB::get_user_strategies($THIRD);
check('third admin sees the change without waiting out the TTL',
    ($third[0]->publishingMode ?? '') === 'schedule', $third[0]->publishingMode ?? null);

echo "\n4. A plain user's cache neither breaks nor bleeds\n";
$plain = PCM_DB::get_user_strategies($USER);                        // warm u9 FIRST
check('plain user still sees only their own (nothing)', count($plain) === 0, count($plain));
PCM_DB::update_strategy(1, $OWNER, array('status' => 'active'));    // …then churn
check('plain user cache key survives the admin churn (only admins are busted)',
    get_transient('pcm_strategies_u' . $USER) !== false, 'evicted');

echo "\n5. The same lever covers the other cached lists (sites spot-check)\n";
$db->rows_by_table['wp_pcm_sites'][] = (object) array(
    'id' => 1, 'userId' => $OWNER, 'name' => 'brizy', 'status' => 'active',
    'updatedAt' => '2026-08-07', 'createdAt' => '2026-08-07',
);
PCM_DB::get_user_sites($GATE);                                      // gate admin caches sites
PCM_DB::update_site(1, $GATE, array('name' => 'brizy-renamed'));
$sites = PCM_DB::get_user_sites($GATE);
check('sites: gate-admin refetch shows the rename', ($sites[0]->name ?? '') === 'brizy-renamed', $sites[0]->name ?? null);

echo "\n" . str_repeat('-', 56) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
