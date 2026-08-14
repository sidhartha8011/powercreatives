<?php
/**
 * SEO table meta columns blank on brizy.profitmedia.pro — the REAL cause,
 * established by probing the live site rather than reasoning about the code:
 *
 *   GET /wp-json/                    → pcm-conn/v1 IS present (connector installed)
 *   GET /wp-json/pcm-conn/v1         → 15 routes, and NO /head-tags among them
 *                                      (that route shipped 2026-08-13)
 *   GET /wp/v2/posts?_fields=meta    → _yoast_wpseo_title "", rank_math_title "",
 *                                      pcm_seo_meta_title "" — the stored meta is
 *                                      genuinely EMPTY (Rank Math renders from
 *                                      templates, storing nothing per post)
 *   GET a post permalink             → <title>The Role, Influence and Impact of the
 *                                      FIFA President - Dental Template</title>, no
 *                                      meta description
 *
 * So the tags exist only in the RENDERED page, the connector is too old to serve
 * them, and the hub's own public-permalink reader is the only path — silently, a
 * few rows per load, with nothing telling the owner that a one-click connector
 * update was the actual fix.
 *
 * Run: php tests/standalone/seo_meta_old_connector_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
$ROOT = dirname(__DIR__, 2);

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

// ── Stubs ───────────────────────────────────────────────────────────────────
if (!class_exists('WP_Error')) { class WP_Error { public function get_error_message() { return 'err'; } } }
function is_wp_error($x) { return $x instanceof WP_Error; }
$GLOBALS['transients'] = array();
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }

$sites = file_get_contents($ROOT . '/includes/modules/sites/service.php');
$svc   = file_get_contents($ROOT . '/includes/modules/seo/service.php');

// Host the capability flag + classifier with a scriptable plugins list.
$slice = static function (string $src, string $name, string $vis = 'public static'): string {
    $i = strpos($src, "function {$name}(");
    $start = strrpos(substr($src, 0, (int)$i), $vis);
    $j = strpos($src, "\n    /**", (int)$i);
    $fn = substr($src, (int)$start, (int)$j - (int)$start);
    if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
    return str_replace('private static', 'public static', $fn);
};
eval('class ConnHost {
    public static $reply = null;
    public static function remote_rest($site, $method, $route, $q = array(), $b = null, $t = 30) { return self::$reply; }
    ' . $slice($sites, 'missing_route_key', 'private static') . "\n"
      . $slice($sites, 'mark_connector_route') . "\n"
      . $slice($sites, 'connector_lacks_route') . "\n"
      . str_replace('self::remote_rest', 'self::remote_rest', $slice($sites, 'connector_status')) . ' }');

$site = (object) array('id' => 4, 'url' => 'https://brizy.profitmedia.pro');
$active_conn = array(array('plugin' => 'p/p.php', 'name' => 'Power Creatives Connector', 'version' => '2.1.4', 'status' => 'active'));

echo "\n1. The capability flag — EXECUTED\n";
$GLOBALS['transients'] = array();
check('a site starts with no verdict', ConnHost::connector_lacks_route(4, 'head-tags') === false);
ConnHost::mark_connector_route(4, 'head-tags', false);          // the observed 404
check('a 404 is recorded', ConnHost::connector_lacks_route(4, 'head-tags') === true);
check('it is scoped per SITE', ConnHost::connector_lacks_route(9, 'head-tags') === false);
check('…and per ROUTE', ConnHost::connector_lacks_route(4, 'scan-links') === false);
ConnHost::mark_connector_route(4, 'head-tags', true);           // after the update
check('a success CLEARS it — an updated connector stops nagging immediately',
    ConnHost::connector_lacks_route(4, 'head-tags') === false, $GLOBALS['transients']);

echo "\n2. connector_status reports it (the site is ACTIVE, just old)\n";
ConnHost::$reply = array('status' => 200, 'body' => $active_conn);
ConnHost::mark_connector_route(4, 'head-tags', false);
$st = ConnHost::connector_status($site);
check('status stays ACTIVE — an old connector is not broken', $st['status'] === 'active', $st);
check('…and is flagged outdated', ($st['outdated'] ?? null) === true, $st);
check('the version is reported so the banner can name it', $st['version'] === '2.1.4', $st);
ConnHost::mark_connector_route(4, 'head-tags', true);
$st = ConnHost::connector_status($site);
check('an up-to-date connector is NOT flagged', ($st['outdated'] ?? null) === false, $st);
// Every branch must carry the field, or the UI reads undefined.
ConnHost::mark_connector_route(4, 'head-tags', false);
ConnHost::$reply = new WP_Error();
check('unknown branch carries the flag too', array_key_exists('outdated', ConnHost::connector_status($site)));
ConnHost::$reply = array('status' => 200, 'body' => array());
$st = ConnHost::connector_status($site);
check('missing branch carries it too', array_key_exists('outdated', $st) && $st['status'] === 'missing', $st);

echo "\n3. The hub records the verdict from the real call\n";
check('a 404 from /head-tags marks the route unserved',
    preg_match('/\$served = !is_wp_error\(\$res\) && \(int\) \(\$res\[.status.\] \?\? 0\) < 300;[\s\S]{0,700}?mark_connector_route\(\(int\) \(\$site->id \?\? 0\), .head-tags., \$served\)/', $svc) === 1,
    'no capability recorded');
check('only a 2xx or a 404 is a verdict — a 5xx/timeout is NOT',
    preg_match('/if \(\$served \|\| \(int\) \(\$res\[.status.\] \?\? 0\) === 404\) \{/', $svc) === 1,
    'a transient outage would be misreported as an old connector');
check('tags are only read from a SERVED response',
    strpos($svc, '$fresh = ($served && is_array($res[\'body\'][\'tags\'] ?? null))') !== false, 'reads tags from an error body');

echo "\n4. The only working path on such a site is made usable\n";
// \s* between the args — this file is CRLF, and a literal \n would never match.
check('the public-permalink reader cap is raised to 25 (was 10)',
    preg_match('/\s25,\s*6\.0,/', $svc) === 1, 'still 10 per load');
check('…the time budget still bounds one load', strpos($svc, '6.0,') !== false);
check('pages stay cached for a week, so the cost is paid once',
    strpos($svc, "set_transient(\$key, \$html, \$html === '' ? HOUR_IN_SECONDS : WEEK_IN_SECONDS)") !== false);
check('and it still refuses challenge pages rather than caching them as titles',
    strpos($svc, 'PCM_SEO_Local::is_challenge_page($html)') !== false);

echo "\n5. The owner is TOLD, with the one-click fix\n";
$ui = file_get_contents($ROOT . '/app/src/modules/SEO/index.tsx');
check('a banner exists for active-but-outdated',
    strpos($ui, "connectorState.status === 'active' && connectorState.outdated") !== false, 'still silent');
check('it explains the SYMPTOM the owner reported (slow/blank meta columns)',
    strpos($ui, 'Meta Title / Description fill in slowly') !== false, 'no explanation');
check('it offers the self-update the connector already supports',
    strpos($ui, 'connectorUpdate.mutate({ id: siteId })') !== false && strpos($ui, 'Update connector') !== false);
check('the update route is the per-site one', strpos(file_get_contents($ROOT . '/app/src/lib/trpc-routes.ts'), 'sites/${input.id}/update-connector') !== false);
check('a refused self-update says to reinstall instead of claiming success',
    strpos($ui, 'reinstall it once (Download connector)') !== false, 'fake success on refusal');
check('the missing/inactive banner is untouched',
    strpos($ui, "(connectorState.status === 'missing' || connectorState.status === 'inactive')") !== false);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
