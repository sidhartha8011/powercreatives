<?php
/**
 * SEO Table — Move tabs (2-SEO-Table-Move-tabs.pdf), pinned.
 *
 *  1. Tabs are drag-reorderable, the order SAVES, and the tab strip and the
 *     Views dropdown show the SAME order (one server-sorted list, rendered
 *     as-is in both places).
 *  2. While a saved view is applied, the Columns panel offers "Update <name>"
 *     — overwriting the existing view's config instead of forcing a new view
 *     for every filter tweak.
 *
 * The REAL PCM_SEO_Views class is require'd (it is self-contained) and
 * EXECUTED against a scripted $wpdb: ordering, ownership filtering, and the
 * config overwrite all run for real.
 *
 * Run: php tests/standalone/seo_view_tabs_test.php
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

// ── WP + schema stubs, then the REAL class ──────────────────────────────────
class PCM_Schema { public static function table($n) { return 'wp_pcm_' . $n; } }
$GLOBALS['options'] = array();
function get_option($k) { return $GLOBALS['options'][$k] ?? false; }
function update_option($k, $v, $autoload = null) { $GLOBALS['options'][$k] = $v; return true; }
function wp_json_encode($v) { return json_encode($v); }
function current_time($t) { return '2026-08-14 12:00:00'; }

class WPDB_Stub {
    public $rows = array();          // view rows for get_results
    public $owned_ids = array();     // ids "owned" for get_col / get_var
    public $updates = array();       // recorded update() calls
    public $last_prepare = '';
    public function prepare($q, ...$args) { $this->last_prepare = $q; $this->args = $args; return json_encode(array($q, $args)); }
    public function get_results($q) { return $this->rows; }
    public function get_col($q) {
        list(, $args) = json_decode($q, true);
        // args: [userId, ...ids] — return the intersection with owned_ids (SQL would).
        $ids = array_slice($args, 1);
        return array_values(array_intersect(array_map('intval', $ids), $this->owned_ids));
    }
    public function get_var($q) {
        list(, $args) = json_decode($q, true);
        return in_array((int) $args[0], $this->owned_ids, true) ? 1 : 0;
    }
    public function update($table, $data, $where, $f1 = null, $f2 = null) { $this->updates[] = array($data, $where); return 1; }
    public function insert($t, $d, $f) { $this->insert_id = 77; return 1; }
    public $insert_id = 0;
    public $args = array();
}
$GLOBALS['wpdb'] = new WPDB_Stub();
require $ROOT . '/includes/modules/seo/views.php';

$mkrow = static fn($id, $name) => (object) array('id' => $id, 'name' => $name, 'config' => '{}', 'isDefault' => 0);

echo "\n1. Reorder — EXECUTED (saved order, ownership-filtered, one list for both places)\n";
$GLOBALS['wpdb']->owned_ids = array(1, 2, 3, 4);
PCM_SEO_Views::set_view_order(9, array(3, 1, 4, 2));
check('order round-trips', PCM_SEO_Views::view_order(9) === array(3, 1, 4, 2), PCM_SEO_Views::view_order(9));
PCM_SEO_Views::set_view_order(9, array(3, 99, 1, 4, 2));   // 99 is NOT owned (forged/foreign)
check('a foreign/stale id is dropped, never stored',
    PCM_SEO_Views::view_order(9) === array(3, 1, 4, 2), PCM_SEO_Views::view_order(9));
check("another user's order is untouched", PCM_SEO_Views::view_order(8) === array());

$GLOBALS['wpdb']->rows = array($mkrow(4, 'D'), $mkrow(3, 'C'), $mkrow(2, 'B'), $mkrow(1, 'A'));
$list = PCM_SEO_Views::list_views(9);
check('list_views returns the SAVED order (this one list feeds tabs AND dropdown)',
    array_column($list, 'id') === array(3, 1, 4, 2), array_column($list, 'id'));

// A view created AFTER the order was saved (id 5) must still show up — first
// among the unordered remainder, newest-first.
$GLOBALS['wpdb']->rows = array($mkrow(5, 'E'), $mkrow(4, 'D'), $mkrow(3, 'C'), $mkrow(2, 'B'), $mkrow(1, 'A'));
$list = PCM_SEO_Views::list_views(9);
check('a NEW view (not yet in the order) still appears, after the ordered ones',
    array_column($list, 'id') === array(3, 1, 4, 2, 5), array_column($list, 'id'));

$GLOBALS['options'] = array();   // no saved order at all
$list = PCM_SEO_Views::list_views(9);
check('no saved order → newest-first exactly as before (no behaviour change)',
    array_column($list, 'id') === array(5, 4, 3, 2, 1), array_column($list, 'id'));

echo "\n2. Update-in-place — EXECUTED (ownership law intact)\n";
$GLOBALS['wpdb']->updates = array();
$ok = PCM_SEO_Views::update_view_config(3, 9, array('columns' => array('slug' => false), 'filters' => array('status' => 'publish')));
check('owned view: config overwritten', $ok === true && count($GLOBALS['wpdb']->updates) === 1);
$upd = $GLOBALS['wpdb']->updates[0] ?? array(array(), array());
check('…with the JSON config + a touched updatedAt',
    strpos((string) ($upd[0]['config'] ?? ''), '"status":"publish"') !== false && isset($upd[0]['updatedAt']), $upd);
$GLOBALS['wpdb']->updates = array();
$ok = PCM_SEO_Views::update_view_config(99, 9, array('columns' => array()));
check('foreign view id → refused, nothing written', $ok === false && $GLOBALS['wpdb']->updates === array());

echo "\n3. Routes + handlers + tRPC\n";
$ctl = file_get_contents($ROOT . '/includes/modules/seo/controller.php');
check('order route registered', strpos($ctl, "'/seo/views/order',          'views_reorder'") !== false);
check('config route registered', strpos($ctl, "'/seo/views/(?P<id>\\d+)/config',  'views_update_config'") !== false);
check('reorder handler validates the ids array',
    preg_match('/function views_reorder\([\s\S]{0,400}?is_array\(\$ids\)/', $ctl) === 1);
check('update handler validates the config object',
    preg_match('/function views_update_config\([\s\S]{0,500}?is_array\(\$config\)/', $ctl) === 1);
$routes = file_get_contents($ROOT . '/app/src/lib/trpc-routes.ts');
check('tRPC routes exist', strpos($routes, '"seo.reorderViews"') !== false && strpos($routes, '"seo.updateView"') !== false);

echo "\n4. The tabs drag, the drop persists, the dropdown mirrors\n";
$idx = file_get_contents($ROOT . '/app/src/modules/SEO/index.tsx');
check('pinned tabs are draggable', preg_match('/draggable\s*\n\s*onDragStart/', $idx) === 1);
check('dropping persists via handleTabDrop', strpos($idx, 'onDrop={(e) => { e.preventDefault(); handleTabDrop(v.id); setDragViewId(null); }}') !== false);
check('the drop reorders the pinned subsequence and sends the FULL id list',
    preg_match('/pinnedIds\.splice\(to, 0, \.\.\.pinnedIds\.splice\(from, 1\)\);[\s\S]{0,300}?reorderViews\(full\)/', $idx) === 1);
check('dragged tab gets a visual cue', strpos($idx, "dragViewId === v.id ? 'opacity-40'") !== false);
check('the tab strip renders the views list in order (no local re-sort)',
    strpos($idx, 'views.filter((v) => v.isPinned).map((v) => (') !== false);
$hook = file_get_contents($ROOT . '/app/src/modules/SEO/hooks/useViews.ts');
check('reorder is optimistic (the tab stays where dropped)',
    preg_match('/reorderViews[\s\S]{0,600}?setQueryData\(LIST_KEY/', $hook) === 1);
check('a failed reorder rolls back + explains', preg_match('/reorderMutation[\s\S]{0,700}?invalidate\(\);[\s\S]{0,200}?Failed to save the tab order/', $hook) === 1);

echo "\n5. The Columns panel updates the APPLIED view in place\n";
$tb = file_get_contents($ROOT . '/app/src/modules/SEO/ViewsToolbar.tsx');
check('Update button gated on an applied view', strpos($tb, 'appliedName && onUpdateView && (') !== false);
check('the button names the view it overwrites', strpos($tb, 'Update “{appliedName}”') !== false);
check('helper copy explains the overwrite', strpos($tb, 'Overwrites the applied view with the current columns + filters.') !== false);
check('save-as-NEW stays available beside it', strpos($tb, 'placeholder="New view name…"') !== false);
check('index wires the handler with the CURRENT columns + filters',
    preg_match('/handleUpdateView[\s\S]{0,200}?updateView\(appliedViewId, \{ columns: cols, filters: filterValues \}\)/', $idx) === 1);
check('both toolbar instances receive it', substr_count($idx, 'onUpdateView={handleUpdateView}') === 2);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
