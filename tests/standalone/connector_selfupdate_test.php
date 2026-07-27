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

echo "\n" . ($FAIL === 0 ? "ALL GREEN" : "FAILURES: $FAIL") . " — $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
