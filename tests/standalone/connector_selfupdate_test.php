<?php
/**
 * Connector SELF-UPDATE version scheme — standalone harness.
 *
 * Guards the fix for "the update-connector feature isn't working — I have to
 * manually upload it a lot of times." WordPress only self-updates a plugin when
 * the update manifest offers a version STRICTLY GREATER than the installed one.
 * That version used to be the hand-maintained connector `Version:` header, so a
 * connector edit WITHOUT a header bump left every connected site stuck ("up to
 * date") and forced a manual per-site re-upload.
 *
 * The fix (seohub/service.php): connector_effective_version() = header base +
 * a monotonic BUILD counter derived from the connector source, baked into BOTH
 * the manifest AND the connector's plugin header so it advances on any code
 * change and never re-updates in a loop.
 *
 * Runs in a clean CLI process with plain WP-function stubs (NO Brain Monkey /
 * WP_Mock) — the same isolation model as run.php — so it never perturbs the
 * PHPUnit suite. Run: `php tests/standalone/connector_selfupdate_test.php`.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

$GLOBALS['__opts'] = array();
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['__opts']) ? $GLOBALS['__opts'][$k] : $d; }
function update_option($k, $v, $a = false) { $GLOBALS['__opts'][$k] = $v; return true; }
function rest_url($p = '') { return 'https://hub.example/wp-json/' . $p; }
function home_url($p = '/') { return 'https://hub.example' . $p; }
function trailingslashit($s) { return rtrim((string) $s, '/') . '/'; }
function wp_parse_url($u, $c = -1) { return $c === -1 ? parse_url((string) $u) : parse_url((string) $u, $c); }
function wp_tempnam($p = '') { return tempnam(sys_get_temp_dir(), 'pcm'); }
function esc_html($s) { return $s; }

require_once dirname(__DIR__, 2) . '/includes/modules/seohub/service.php';

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

/** Read the plugin-header Version + the PCM_CONN_VERSION define from a freshly baked connector. */
function baked_versions(): array
{
    $m = new ReflectionMethod('PCM_SEOHub_Service', 'connector_php_simple');
    $php = (string) $m->invoke(null);
    preg_match('/^\s*\*\s*Version:\s*([0-9][0-9.]*)/m', $php, $h);
    preg_match("/define\\('PCM_CONN_VERSION',\\s*'([0-9][0-9.]*)'\\)/", $php, $d);
    return array('header' => $h[1] ?? '', 'define' => $d[1] ?? '');
}

echo "-- connector self-update version scheme --\n";

// 1. Effective version = base + monotonic build suffix, stable for an unchanged source.
$ev1 = PCM_SEOHub_Service::connector_effective_version();
check('effective version has a build suffix', (bool) preg_match('/^\d+(\.\d+)+\.\d+$/', $ev1), $ev1);
check('effective version is stable across calls', $ev1 === PCM_SEOHub_Service::connector_effective_version(), $ev1);

// 2. Anti-loop invariant: baked plugin header + define + manifest artifact ALL equal the effective
//    version. WordPress reads the installed version from the header — a mismatch re-offers the
//    update on every poll.
$b   = baked_versions();
$art = PCM_SEOHub_Service::connector_artifact();
check('baked plugin header == effective version', $b['header'] === $ev1, $b);
check('baked PCM_CONN_VERSION define == effective version', $b['define'] === $ev1, $b);
check('manifest artifact version == effective version', (string) ($art['version'] ?? '') === $ev1, $art['version'] ?? null);
check('artifact carries a 64-char sha256', isset($art['sha256']) && strlen((string) $art['sha256']) === 64, $art['sha256'] ?? null);

// 3. Existing bare-version sites (e.g. "3.0.8") see the first build as an upgrade.
$base = PCM_SEOHub_Service::connector_template_version();
check('bare-version sites are offered the first build', version_compare($ev1, $base, '>'), array($ev1, $base));

// 4. A genuine connector SOURCE change bumps the build monotonically so self-update fires —
//    and the freshly baked header tracks it (no post-update loop).
$GLOBALS['__opts']['pcm_seohub_conn_build']['sig'] = 'a-different-source-hash';
$ev2 = PCM_SEOHub_Service::connector_effective_version();
check('a source change moves the effective version', $ev2 !== $ev1, array($ev1, $ev2));
check('the bump is monotonic (WordPress will offer it)', version_compare($ev2, $ev1, '>'), array($ev1, $ev2));
check('baked header tracks the bump (no re-update loop)', baked_versions()['header'] === $ev2, baked_versions());

// 5. DURABILITY: after TOTAL loss of the counter option (hub reinstall / DB
//    restore), the build floors to the monotonic day-clock — a large number —
//    so it can NEVER reset to 1 and regress a deployed fleet back into the
//    stall. (The 1767225600 epoch mirrors connector_build_number().)
unset($GLOBALS['__opts']['pcm_seohub_conn_build']);
$floor     = (int) floor((time() - 1767225600) / 86400);
$evLoss    = PCM_SEOHub_Service::connector_effective_version();
$buildLoss = (int) substr($evLoss, strrpos($evLoss, '.') + 1);
check('after option loss the build floors to the day-clock (never resets to 1)', $buildLoss === $floor && $floor > 30, array('build' => $buildLoss, 'floor' => $floor));

// ── The update OBJECT WordPress actually consumes ────────────────────────────
// Everything above proves the VERSION advances. This proves core can act on it:
// wp_update_plugins() stores whatever the update_plugins_<host> filter returns
// straight into $updates->response[$plugin_file], and then the Plugins screen,
// WP_Automatic_Updater::should_update() and Plugin_Upgrader all read ->new_version.
// Returning only 'version' left new_version UNSET — core held an update object it
// could not act on, so nothing auto-updated and /update-now reported an empty 'to'.
echo "\n-- the update object handed to WordPress --\n";

$baked = (function () {
    $m = new ReflectionMethod('PCM_SEOHub_Service', 'connector_php_simple');
    $m->setAccessible(true);
    return (string) $m->invoke(null, '', '', '');
})();

// Pull the registered filter out of the BAKED connector and run it for real.
$GLOBALS['__filters'] = array();
if (!function_exists('add_filter')) {
    function add_filter($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['__filters'][$hook] = $cb; return true; }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error_Stub; } }
if (!class_exists('WP_Error_Stub')) { class WP_Error_Stub {} }
if (!function_exists('wp_remote_get')) { function wp_remote_get($u, $a = array()) { return $GLOBALS['__manifest_response']; } }
if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($r) { return $r['code'] ?? 200; } }
if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; } }

preg_match('/^\s*\*\s*Update URI:\s*(\S+)/m', $baked, $u);
$host = (string) parse_url($u[1] ?? '', PHP_URL_HOST);
check('baked Update URI yields a host', $host !== '', $u[1] ?? '(missing)');

if (!defined('PCM_CONN_FILE'))     { define('PCM_CONN_FILE', 'pcm-connector/pcm-connector.php'); }
if (!defined('PCM_CONN_MANIFEST')) { define('PCM_CONN_MANIFEST', 'https://hub.example/manifest'); }
if (!defined('PCM_CONN_HOST'))     { define('PCM_CONN_HOST', $host); }

// Eval ONLY the add_filter(...) statement for the manifest hook, verbatim.
if (preg_match("/add_filter\('update_plugins_[^']+',\s*function.*?\n\}, 10, 3\);/s", $baked, $blk)) {
    eval($blk[0]);
}
$hook = 'update_plugins_' . $host;
check('the filter registers under the header host', isset($GLOBALS['__filters'][$hook]), array_keys($GLOBALS['__filters']));

$GLOBALS['__manifest_response'] = array('code' => 200, 'body' => json_encode(array(
    'version' => '3.0.8.999', 'package' => 'https://hub.example/pkg.zip',
    'sha256' => str_repeat('a', 64), 'url' => 'https://hub.example/', 'tested' => '6.9',
)));

$cb  = $GLOBALS['__filters'][$hook] ?? null;
$out = $cb ? $cb(false, array('Version' => '3.0.8.1'), PCM_CONN_FILE) : null;

check('an offer is returned when the manifest is newer', is_array($out), $out);
// THE regression: without this key core has nothing to compare or install.
check('carries new_version (the key core reads)', ($out['new_version'] ?? null) === '3.0.8.999', $out['new_version'] ?? null);
check('new_version and version agree', ($out['new_version'] ?? 1) === ($out['version'] ?? 2), $out);
check('carries the package URL', ($out['package'] ?? '') === 'https://hub.example/pkg.zip', $out['package'] ?? null);
check('names the plugin file core is updating', ($out['plugin'] ?? '') === PCM_CONN_FILE, $out['plugin'] ?? null);
check('carries an id', !empty($out['id']), $out['id'] ?? null);
check('sha256 stored for the pre-download check',
    ($GLOBALS['__opts']['pcm_conn_expected_sha256'] ?? '') === str_repeat('a', 64),
    $GLOBALS['__opts']['pcm_conn_expected_sha256'] ?? null);

// Same version installed -> no offer, or WordPress would reinstall forever.
$out2 = $cb ? $cb(false, array('Version' => '3.0.8.999'), PCM_CONN_FILE) : null;
check('no offer when already up to date', $out2 === false, $out2);
// A newer local build than the hub must not be downgraded.
$out3 = $cb ? $cb(false, array('Version' => '3.0.9.0'), PCM_CONN_FILE) : null;
check('never offers a downgrade', $out3 === false, $out3);
// Another plugin's update must pass straight through untouched.
$out4 = $cb ? $cb(false, array('Version' => '1.0'), 'other/other.php') : null;
check('ignores other plugins', $out4 === false, $out4);

// /update-now reads ->new_version, which is why the empty 'to' was the tell.
check('/update-now reads the same key', str_contains($baked, '->new_version'), 'not read');
check('auto_update_plugin opts this plugin in', str_contains($baked, "add_filter('auto_update_plugin'"), 'no opt-in');

echo "\n" . ($FAIL === 0 ? "ALL GREEN" : "FAILURES: $FAIL") . " — $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
