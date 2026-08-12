<?php
/**
 * Links tab — false 404s and phantom links (reported on brizy.profitmedia.pro).
 *
 * Owner: "It is finding false 404's — the 1 link is actually working; the 3
 * links does not even exist on the page."
 *
 * Two distinct defects, two fixes under test:
 *
 * 1. FALSE 404 (working link flagged broken): the REMOTE status checker was
 *    HEAD-only, and hosts routinely answer 404/403/405 to HEAD while serving
 *    GET fine. The LOCAL scanner already retried — but only on 405. Both now
 *    retry with GET whenever HEAD reports >=400 or a transport error, so a
 *    link is only called broken when GET agrees.
 *
 * 2. PHANTOM LINKS (links not on the page at all): the connector treated
 *    Brizy as content-based — scanning post_content (a STALE COMPILED COPY on
 *    Brizy pages) plus ALL postmeta including Brizy's compiled-HTML render
 *    cache. Caches lag their source after edits, so the scan reported links
 *    that are no longer on the live page. Brizy is now source-scoped
 *    (brizy-post/brizy meta), compiled/cache keys are skipped during the walk,
 *    and a zero-links fallback keeps visibility for odd Brizy versions.
 *
 * The remote checker is EXECUTED here with stubbed HTTP (HEAD lies, GET tells
 * the truth); the connector template is EXTRACTED from its nowdoc and
 * php -l'd — the hub file's own lint never checks that string.
 *
 * Run: php tests/standalone/seo_link_scan_test.php
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

// ── 1. The remote checker, EXECUTED: HEAD lies, GET decides ────────────────
$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');
$i = strpos($svc, 'private static function link_http_status');
$start = strrpos(substr($svc, 0, $i), "\n") + 1;
$fn = substr($svc, $start, strpos($svc, "\n    }", $i) + 6 - $start);
$fn = str_replace('private static', 'public static', $fn);

$GLOBALS['http_log'] = array();
function wp_remote_head($url, $args = array()) { $GLOBALS['http_log'][] = "HEAD $url"; return array('code' => $GLOBALS['head_status']); }
function wp_remote_get($url, $args = array())  { $GLOBALS['http_log'][] = "GET $url";  return array('code' => $GLOBALS['get_status']); }
function wp_remote_retrieve_response_code($r) { return is_array($r) ? (int) ($r['code'] ?? 0) : 0; }
function is_wp_error($x) { return false; }
eval('class LinkCheckHost { ' . $fn . ' }');

echo "\n1. A failing HEAD is never trusted on its own (the reported false 404)\n";
$GLOBALS['head_status'] = 404; $GLOBALS['get_status'] = 200; $GLOBALS['http_log'] = array();
check('HEAD says 404 but GET says 200 → the link is NOT broken',
    LinkCheckHost::link_http_status('https://example.test/works') === 200, $GLOBALS['http_log']);
check('…and the GET retry actually happened', in_array('GET https://example.test/works', $GLOBALS['http_log'], true), $GLOBALS['http_log']);
$GLOBALS['head_status'] = 403; $GLOBALS['get_status'] = 200;
check('HEAD 403 (bot-blocked) → GET decides: 200', LinkCheckHost::link_http_status('https://example.test/a') === 200);
$GLOBALS['head_status'] = 405; $GLOBALS['get_status'] = 200;
check('HEAD 405 (method not allowed) → GET decides: 200', LinkCheckHost::link_http_status('https://example.test/b') === 200);
$GLOBALS['head_status'] = 404; $GLOBALS['get_status'] = 404;
check('a REAL 404 stays a 404 (GET agrees)', LinkCheckHost::link_http_status('https://example.test/gone') === 404);
$GLOBALS['head_status'] = 200; $GLOBALS['get_status'] = 500; $GLOBALS['http_log'] = array();
check('a healthy HEAD is trusted — no wasted GET',
    LinkCheckHost::link_http_status('https://example.test/fine') === 200
    && !in_array('GET https://example.test/fine', $GLOBALS['http_log'], true), $GLOBALS['http_log']);
check('non-http(s) URLs are not probed at all', LinkCheckHost::link_http_status('mailto:x@y.z') === 0);

echo "\n2. The LOCAL scanner carries the same law (it retried only on 405)\n";
$loc = file_get_contents($ROOT . '/includes/modules/seo/local.php');
check('local retry fires on any >=400 or transport error, not just 405',
    preg_match('/if \(\$status === 0 \|\| \$status >= 400\) \{\s*\n\s*\$resp   = wp_remote_get/', $loc) === 1,
    '405-only retry survives');
check('the 405-only special case is gone', !preg_match('/if \(\$status === 405\)/', $loc), 'still 405-only');

// ── 3. The connector template (extracted — the hub lint never checks it) ───
$hub = file_get_contents($ROOT . '/includes/modules/seohub/service.php');
$a = strpos($hub, "<<<'PHP'");
$b = strpos($hub, "\nPHP;", $a);
check('connector nowdoc found', $a !== false && $b !== false, array($a, $b));
$tpl = substr($hub, $a + 9, $b - $a - 9);
$tpl_baked = str_replace(
    array('__PCM_CONN_UPDATE_URI__', '__PCM_CONN_VERSION__', '__PCM_HUB_URL__', '__PCM_CLIENT_ID__'),
    array('https://x', '0.0.0', 'https://hub', 'cid'),
    $tpl
);
$tmp = tempnam(sys_get_temp_dir(), 'pcmconn') . '.php';
file_put_contents($tmp, $tpl_baked);
exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $lint_out, $lint_code);
@unlink($tmp);
echo "\n3. The generated connector still parses (template edits are string edits)\n";
check('php -l on the EXTRACTED template', $lint_code === 0, implode("\n", array_slice($lint_out, 0, 3)));

echo "\n4. Brizy is source-scoped — the phantom factory is closed\n";
$brizy = substr($tpl, strpos($tpl, 'class PCM_Conn_B_Brizy'), 2600);
check('Brizy declares its SOURCE meta keys',
    preg_match("/function source_keys\(\) \{ return array\('brizy-post', 'brizy'\); \}/", $brizy) === 1,
    'Brizy still content-based — post_content + all-meta scan');
check('detection still covers uid/meta variants', strpos($brizy, 'brizy_post_uid') !== false, 'detection narrowed');
$cl = substr($tpl, strpos($tpl, 'private static function collect_links'), 3400);
check('compiled/cache meta keys are skipped during the walk',
    preg_match("/preg_match\('\/compiled\|_cache\\\\b\|cached\/i', \\\$ck\)/", $cl) === 1, 'render caches still scanned');
$sl = substr($tpl, strpos($tpl, 'public function scan_links('), 1800);
check('scan_links delegates to a scoped pass', strpos($sl, 'scan_links_pass($post_id, $source_keys)') !== false, 'no pass split');
check('zero-links fallback keeps visibility (never silently empty)',
    preg_match('/if \(!empty\(\$source_keys\) && empty\(\$result\)\) \{\s*\n\s*\$result = \$this->scan_links_pass\(\$post_id, array\(\)\);/', $sl) === 1,
    'a Brizy storing data elsewhere would scan to zero');

echo "\n5. The template changed, so self-update will offer it\n";
check('the build number derives from an md5 of the raw template',
    strpos($hub, 'md5(self::connector_php_simple_raw())') !== false, 'manual bump needed but missing');

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
