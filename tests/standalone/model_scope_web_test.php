<?php
/**
 * Model visibility on the SHORTCODE SPA (the "web version").
 *
 * Reported: everything works at /wp-admin/admin.php?page=power-creatives but
 * not at the public page — "the models aren't visible here".
 *
 * Cause: wp-admin resolves the caller to the PCM row openId `wp_<ID>`, which
 * owns the integrations and model rows. The public page authenticates through
 * the shortcode gate cookie, which resolves to a DIFFERENT platform user row
 * that owns nothing. PCM_DB::get_user_models() already handled that with an
 * admin-owned fallback, but the queries feeding the GENERATION dropdowns and
 * the SEO optimizer's engine list were still hard-scoped `userId = caller`, so
 * they returned zero rows on the public page only.
 *
 * This exercises the REAL PCM_Access::model_scope() — the shared lever both
 * call sites now use — and asserts the call sites actually route through it.
 *
 * Run: php tests/standalone/model_scope_web_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

class PCM_Schema { public static function table($n) { return "wp_pcm_{$n}"; } }

/**
 * Scriptable $wpdb — just enough for is_admin() (role lookup) and
 * model_scope() (does this user own any model row?).
 */
class FakeWpdb {
    /** @var array<int,string> user id => role */
    public array $roles = array();
    /** @var int[] user ids that own at least one model row */
    public array $model_owners = array();
    public array $queries = array();

    public function prepare($sql, ...$args) {
        foreach ($args as $a) {
            $sql = preg_replace('/%d/', (string) (int) $a, $sql, 1);
            $sql = preg_replace('/%s/', "'" . (string) $a . "'", $sql, 1);
        }
        return $sql;
    }
    public function get_var($sql) {
        $this->queries[] = $sql;
        if (preg_match('/SELECT role FROM \S*users WHERE id = (\d+)/i', $sql, $m)) {
            return $this->roles[(int) $m[1]] ?? null;
        }
        if (preg_match('/SELECT 1 FROM \S*models WHERE userId = (\d+)/i', $sql, $m)) {
            return in_array((int) $m[1], $this->model_owners, true) ? '1' : null;
        }
        return null;
    }
}

global $wpdb;
$wpdb = new FakeWpdb();

require_once __DIR__ . '/../../includes/core/class-pcm-access.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, $got = null): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok  {$name}\n"; }
    else { $fail++; echo "FAIL  {$name}\n      got: " . var_export($got, true) . "\n"; }
}
/** prepare() blows up unless the placeholder count matches the param count. */
function placeholders(string $sql): int { return preg_match_all('/%[ds]/', $sql); }

// ── The cast ────────────────────────────────────────────────────────────────
// Distinct ids per tier on purpose: PCM_Access memoizes per request, so reusing
// one id across tiers would test the memo instead of the logic.
$ADMIN      = 10;  // wp-admin's user — owns the keys
$GATE_ADMIN = 20;  // platform admin logging in through the shortcode gate
$GATE_PLAIN = 30;  // ordinary platform user, owns nothing
$OWNER      = 40;  // ordinary user who does own model rows

$wpdb->roles = array($ADMIN => 'admin', $GATE_ADMIN => 'admin', $GATE_PLAIN => 'user', $OWNER => 'user');
$wpdb->model_owners = array($ADMIN, $OWNER);

echo "\n1. Tier 1 — an admin sees every model row\n";
foreach (array('wp-admin admin' => $ADMIN, 'gate-login admin' => $GATE_ADMIN) as $label => $uid) {
    $s = PCM_Access::model_scope('m.userId', $uid);
    check("{$label}: clause is unrestricted", $s['sql'] === '%d = %d', $s['sql']);
    check("{$label}: does not filter on the owner column", !str_contains($s['sql'], 'm.userId'), $s['sql']);
}

echo "\n2. Tier 2 — a user who owns models sees their own\n";
$s = PCM_Access::model_scope('m.userId', $OWNER);
check('owner: scoped to themselves', $s['sql'] === 'm.userId = %d', $s['sql']);
check('owner: bound to their id', $s['params'] === array($OWNER), $s['params']);

echo "\n3. Tier 3 — THE BUG: gate user owns nothing, must still see the workspace\n";
$s = PCM_Access::model_scope('m.userId', $GATE_PLAIN);
check('gate user: NOT scoped to themselves (this returned zero rows before)',
    !str_contains($s['sql'], "m.userId = %d"), $s['sql']);
check('gate user: falls back to admin-owned rows',
    str_contains($s['sql'], 'role') && str_contains($s['sql'], 'admin')
    && str_contains($s['sql'], 'm.userId IN'), $s['sql']);

echo "\n4. Every tier stays prepare()-safe (placeholders == params)\n";
foreach (array('admin' => $ADMIN, 'owner' => $OWNER, 'gate user' => $GATE_PLAIN) as $label => $uid) {
    $s = PCM_Access::model_scope('m.userId', $uid);
    check("{$label}: placeholder/param parity", placeholders($s['sql']) === count($s['params']),
        array('sql' => $s['sql'], 'params' => $s['params']));
    check("{$label}: carries at least one placeholder", placeholders($s['sql']) >= 1, $s['sql']);
}

echo "\n5. The memo keys on the user (no cross-user bleed)\n";
$a = PCM_Access::model_scope('m.userId', $GATE_PLAIN);
$b = PCM_Access::model_scope('m.userId', $OWNER);
$c = PCM_Access::model_scope('m.userId', $GATE_PLAIN);
check('gate user still gets the fallback after an owner lookup', $a['sql'] === $c['sql'], array($a['sql'], $c['sql']));
check('owner and gate user get different clauses', $a['sql'] !== $b['sql'], $b['sql']);

echo "\n6. The call sites route through the lever (rule, not spelling)\n";
$sites = array(
    'models generation dropdowns' => array('includes/modules/models/service.php', 'get_by_capability'),
    'optimizer engine picker'     => array('includes/modules/optimizer/service.php', 'text_engines'),
);
foreach ($sites as $label => [$file, $fn]) {
    $src = file_get_contents(__DIR__ . '/../../' . $file);
    // Isolate the function body so a match elsewhere in the file can't mask it.
    $i = strpos($src, "function {$fn}(");
    check("{$label}: {$fn}() found", $i !== false, $file);
    if ($i === false) { continue; }
    $body = substr($src, $i, 2600);
    check("{$label}: uses PCM_Access::model_scope", str_contains($body, 'PCM_Access::model_scope'), $fn);
    check("{$label}: no longer hard-scopes m.userId to the caller",
        !preg_match('/WHERE\s+m\.userId\s*=\s*%d/i', $body), $fn);
    check("{$label}: still validates the owner's integration is active",
        str_contains($body, 'i.userId = m.userId') || str_contains($body, 'm.userId = i.userId'), $fn);
    check("{$label}: still requires an ACTIVE integration",
        str_contains($body, 'i.isActive = 1'), $fn);
}

echo "\n7. Provider detection is not tied to a WP login\n";
$llm = file_get_contents(__DIR__ . '/../../includes/core/llm/class-pcm-llm.php');
$i = strpos($llm, 'function detect_provider(');
check('detect_provider() found', $i !== false);
// Strip comments — the fix is DESCRIBED in a comment here, and a naive scan
// would match its own explanation and report the bug as still present.
$body = preg_replace('!//[^\n]*|/\*.*?\*/!s', '', substr($llm, $i, 1800));
check('does not scope the provider lookup by user',
    !preg_match('/modelId = %s AND userId/i', $body), 'user-scoped lookup still present');
check('does not use the WP user id as a PCM user id',
    !str_contains($body, 'get_current_user_id()'), 'get_current_user_id() still present');
check('still looks the provider up by modelId', str_contains($body, 'WHERE modelId = %s'), 'lookup gone');

echo "\n" . str_repeat('-', 56) . "\n";
echo "  passed: {$pass}   failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
