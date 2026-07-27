<?php
/**
 * Template seed DELIVERY — standalone harness.
 *
 * Guards the fix for "template still not update": default templates used to be
 * seeded ONLY inside maybe_upgrade()'s `pcm_db_version < PCM_DB_VERSION` gate.
 * Adding a template is not a schema change, so PCM_DB_VERSION is correctly NOT
 * bumped for one — which meant a newly shipped default template could never
 * reach an already-installed site (observed live: the reposting templates were
 * absent after a plugin update, with only the manual "Reseed defaults" button
 * able to deliver them).
 *
 * PCM_Template_Seeds::maybe_seed() now re-seeds whenever the seeds FILE changes
 * (mtime+size signature), independently of the DB version.
 *
 * Clean CLI process with plain stubs (same isolation model as run.php). Run:
 *   php tests/standalone/template_seed_delivery_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

$GLOBALS['__opts'] = array();
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['__opts']) ? $GLOBALS['__opts'][$k] : $d; }
function update_option($k, $v, $a = false) { $GLOBALS['__opts'][$k] = $v; return true; }
function current_time($t = 'mysql') { return date('Y-m-d H:i:s'); }
function wp_json_encode($v) { return json_encode($v); }

/** Fake $wpdb: records inserts, answers the name+module existence probe. */
class PCM_Test_Wpdb
{
    public $rows = array();          // [ "module|name" => true ]
    public $inserts = array();
    public function prepare($sql, ...$args) { return array('sql' => $sql, 'args' => $args); }
    public function get_var($q)
    {
        // insert_if_missing() probes COUNT(*) ... WHERE name=%s AND module=%s
        $name = $q['args'][0] ?? '';
        $mod  = $q['args'][1] ?? '';
        return isset($this->rows[$mod . '|' . $name]) ? 1 : 0;
    }
    public function insert($table, $data)
    {
        $this->rows[$data['module'] . '|' . $data['name']] = true;
        $this->inserts[] = $data;
        return 1;
    }
}
$GLOBALS['wpdb'] = new PCM_Test_Wpdb();

class PCM_Schema { public static function table($n) { return 'wp_pcm_' . $n; } }

require_once dirname(__DIR__, 2) . '/includes/core/class-pcm-template-seeds.php';

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

echo "-- template seed delivery --\n";

// 1. Fresh install: no signature → seeds run, the reposting templates land.
PCM_Template_Seeds::maybe_seed();
$names = array_column($GLOBALS['wpdb']->inserts, 'name');
check('first run seeds the defaults', count($names) > 0, count($names));
check('ships "Social Media Reposting"', in_array('Social Media Reposting', $names, true));
check('ships "RSS Reposting"', in_array('RSS Reposting', $names, true));
check('signature recorded', (string) get_option('pcm_template_seeds_sig', '') !== '');

// 2. Unchanged definitions → no repeat work (the every-page-load cost is one
//    stat + one option read, never a re-seed).
$countAfterFirst = count($GLOBALS['wpdb']->inserts);
PCM_Template_Seeds::maybe_seed();
PCM_Template_Seeds::maybe_seed();
check('repeat page loads do NOT re-seed', count($GLOBALS['wpdb']->inserts) === $countAfterFirst, count($GLOBALS['wpdb']->inserts));

// 3. Definitions changed (new file signature) → seeds re-run, but existing
//    templates are NOT duplicated (insert_if_missing skips by name+module).
update_option('pcm_template_seeds_sig', 'stale-signature-from-an-older-build');
$before = count($GLOBALS['wpdb']->inserts);
PCM_Template_Seeds::maybe_seed();
check('a changed seeds file re-runs the seeder', (string) get_option('pcm_template_seeds_sig', '') !== 'stale-signature-from-an-older-build');
check('…without duplicating existing templates', count($GLOBALS['wpdb']->inserts) === $before, count($GLOBALS['wpdb']->inserts) - $before);

// 4. The delivery must NOT depend on the DB version (the original bug): even
//    with pcm_db_version already at the current value, seeds still land.
$GLOBALS['wpdb'] = new PCM_Test_Wpdb();       // empty install
$GLOBALS['__opts'] = array('pcm_db_version' => '1.45.0'); // == PCM_DB_VERSION on prod
PCM_Template_Seeds::maybe_seed();
$names2 = array_column($GLOBALS['wpdb']->inserts, 'name');
check('seeds land even when pcm_db_version is already current', in_array('RSS Reposting', $names2, true));

echo "\n" . ($FAIL === 0 ? "ALL GREEN" : "FAILURES: $FAIL") . " — $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
