<?php
/**
 * Connector status surfacing — the fix for "silently blank" connected sites.
 *
 * massagegoteborg.nu had NO connector installed (its REST namespaces carry no
 * pcm-conn/v1 at all), so SEO meta could never populate — and nothing in the
 * UI said so. Now: the hub classifies the connector state per site
 * (active / inactive / missing / unknown), the SEO module shows an actionable
 * banner for missing/inactive, and an installed-but-inactive copy can be
 * activated in one click over the app-password channel WordPress core already
 * provides (PUT /wp/v2/plugins/{plugin}).
 *
 * The classifier is EXECUTED here with stubbed HTTP.
 *
 * Run: php tests/standalone/connector_status_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!class_exists('WP_Error')) { class WP_Error { public function get_error_message() { return 'err'; } } }
if (!function_exists('is_wp_error')) { function is_wp_error($x) { return $x instanceof WP_Error; } }
// connector_status now also reports whether the connector serves routes the hub
// needs (an ACTIVE but old build 404s /head-tags) — give it a transient store.
$GLOBALS['transients'] = array();
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }

$ROOT = dirname(__DIR__, 2);
$svc  = file_get_contents($ROOT . '/includes/modules/sites/service.php');

// Extract connector_status; host it with a scriptable remote_rest.
$i = strpos($svc, 'public static function connector_status');
$start = strrpos(substr($svc, 0, $i), "\n") + 1;
$next = strpos($svc, "\n    public static function activate_newest_connector", $i);
$fn = substr($svc, $start, $next - $start);
if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
// The capability helpers connector_status leans on, taken verbatim.
$helpers = '';
foreach (array('missing_route_key', 'mark_connector_route', 'connector_lacks_route') as $h) {
    $hi = strpos($svc, "function {$h}(");
    // NEAREST preceding declaration of either visibility — anchoring on one kind
    // walked back past a public method into the private one above it and sliced
    // both (PHP then refused the duplicate).
    $hp = strrpos(substr($svc, 0, (int)$hi), '    private static');
    $hq = strrpos(substr($svc, 0, (int)$hi), '    public static');
    $hs = max($hp === false ? -1 : $hp, $hq === false ? -1 : $hq);
    $he = strpos($svc, "\n    /**", (int)$hi);
    $hf = substr($svc, (int)$hs, (int)$he - (int)$hs);
    if (preg_match('/\n    \}\r?\n/', $hf, $hm, PREG_OFFSET_CAPTURE)) { $hf = substr($hf, 0, $hm[0][1]) . "\n    }"; }
    $helpers .= str_replace('private static', 'public static', $hf) . "\n";
}
eval('class ConnHost { public static $reply = null;' . $helpers
    . ' public static function remote_rest($site, $method, $route, $q = array(), $b = array(), $t = 30) { return self::$reply; }'
    . str_replace('self::remote_rest', 'self::remote_rest', $fn) . ' }');

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}
$site = (object) array('id' => 7, 'url' => 'https://x.se');
$conn = static fn($name, $ver, $status) => array('plugin' => 'p/p.php', 'name' => $name, 'version' => $ver, 'status' => $status);

echo "\n1. Classification — EXECUTED\n";
ConnHost::$reply = new WP_Error();
check('unreadable plugins list → unknown (NOT missing)', ConnHost::connector_status($site)['status'] === 'unknown');
ConnHost::$reply = array('status' => 403, 'body' => array());
check('403 on the list → unknown', ConnHost::connector_status($site)['status'] === 'unknown');
ConnHost::$reply = array('status' => 200, 'body' => array($conn('Some Other Plugin', '1.0', 'active')));
check('readable list, no connector → missing', ConnHost::connector_status($site)['status'] === 'missing');
ConnHost::$reply = array('status' => 200, 'body' => array($conn('Power Creatives Connector', '3.0.8', 'inactive')));
$r = ConnHost::connector_status($site);
check('installed but inactive → inactive, version reported', $r['status'] === 'inactive' && $r['version'] === '3.0.8', $r);
ConnHost::$reply = array('status' => 200, 'body' => array(
    $conn('Power Creatives Connector', '2.9.0', 'inactive'),
    $conn('Power Creatives Connector', '3.0.8', 'active'),
));
$r = ConnHost::connector_status($site);
check('an active copy wins → active with ITS version', $r['status'] === 'active' && $r['version'] === '3.0.8', $r);
check('copies counted', $r['copies'] === 2, $r);
ConnHost::$reply = array('status' => 200, 'body' => array(
    $conn('Power Creatives Connector', '2.9.0', 'inactive'),
    $conn('Power Creatives Connector', '3.0.8', 'inactive'),
));
check('multiple inactive copies → newest version reported',
    ConnHost::connector_status($site)['version'] === '3.0.8', ConnHost::connector_status($site));

echo "\n2. Routes + handlers\n";
$ctl = file_get_contents($ROOT . '/includes/modules/sites/controller.php');
check('status route registered', strpos($ctl, "'/sites/(?P<id>\\d+)/connector-status',   'connector_status'") !== false);
check('activate route registered', strpos($ctl, "'/sites/(?P<id>\\d+)/connector-activate', 'connector_activate'") !== false);
check('both handlers ownership-scope the site',
    preg_match_all('/function connector_(status|activate)\([\s\S]{0,260}?PCM_DB::get_site\(\(int\)\$request->get_param\(\'id\'\), \(int\)\$pcm_user->id\)/', $ctl) === 2, 'any site id would do');
check('activation reuses the existing self-heal', strpos($ctl, 'PCM_Sites_Service::activate_newest_connector($site)') !== false);
check('activation re-classifies so the UI trusts one response', strpos($ctl, "\$result['status'] = PCM_Sites_Service::connector_status(\$site)") !== false);
$routes = file_get_contents($ROOT . '/app/src/lib/trpc-routes.ts');
check('tRPC routes exist', strpos($routes, '"sites.connectorStatus"') !== false && strpos($routes, '"sites.connectorActivate"') !== false);

echo "\n3. The SEO module says so — and stays quiet on 'unknown'\n";
$ui = file_get_contents($ROOT . '/app/src/modules/SEO/index.tsx');
check('banner renders for missing OR inactive only',
    strpos($ui, "connectorState.status === 'missing' || connectorState.status === 'inactive'") !== false, 'no gating');
check("'unknown' never nags (capability limits are not the user's fault)",
    strpos($ui, "connectorState.status === 'unknown'") === false, 'nags on unknown');
check('the query only runs for connected sites',
    preg_match('/connectorStatus\.useQuery\(\s*\n?\s*\{ id: siteId \},\s*\n?\s*\{ enabled: !isLocal/', $ui) === 1, 'fires for local');
// The MESSAGES too, not just the gating — a banner that renders "x" passes
// every structural check while telling the user nothing.
check('the inactive message explains the consequence',
    strpos($ui, 'installed on this site but not active') !== false, 'copy gutted');
check('the missing message explains the consequence',
    strpos($ui, 'no Power Creatives Connector') !== false, 'copy gutted');
check('inactive → one-click Activate wired', strpos($ui, 'connectorActivate.mutate({ id: siteId })') !== false);
check('missing → Download connector wired', strpos($ui, 'seohub/connector-download') !== false);
check('download keeps the server-named file (Content-Disposition)',
    preg_match('/downloadConnector[\s\S]{0,900}?Content-Disposition/', $ui) === 1, 'hardcoded filename again');
check('activation refetches the status', preg_match('/connectorActivate = [\s\S]{0,700}?connectorStatusQuery\.refetch\(\)/', $ui) === 1, 'stale banner after activate');

echo "\n4. Bulk save (Accept all): no data loss, no toast wall\n";
// Owner: "bulk save… doesn't work… one title, two descriptions, three keywords".
// The old acceptAllStaged fire-and-forgot every save and CLEARED the staging map
// immediately — on a connector-less site the meta saves 422 and the generated
// content was simply gone. These pin the rewritten contract.
$aas_at = strpos($ui, 'const acceptAllStaged');
check('acceptAllStaged found', $aas_at !== false);
$aas = substr($ui, (int)$aas_at, 2600);
check('the fire-and-forget void call is gone', strpos($aas, 'void saveCell') === false, 'still fire-and-forget');
check('saves are AWAITED sequentially (remote saves are round trips)',
    strpos($aas, 'await saveCell(id, field, value)') !== false, 'concurrent/unawaited');
check('known-doomed fields are partitioned, not sent',
    strpos($aas, 'connectorBlocked && REMOTE_META_FIELDS.has(field)') !== false, 'doomed fields still fired');
check("blocking gates on missing|inactive ONLY — 'unknown' never blocks work",
    strpos($aas, "connectorState.status === 'missing' || connectorState.status === 'inactive'") !== false
    && strpos($aas, "status === 'unknown'") === false, 'unknown blocks');
check('blocked suggestions STAY STAGED (content survives)',
    strpos($aas, 'setStaged(Object.fromEntries(blocked))') !== false, 'blocked content discarded');
check('failed saves are RE-STAGED (retryable, not lost)',
    strpos($aas, '...Object.fromEntries(failed), ...s') !== false, 'failures discarded');
check('one summary toast instead of N identical errors',
    strpos($aas, 'kept pending') !== false && strpos($aas, 'parts.join') !== false, 'toast wall remains');

// PARITY: the frontend's meta-field set must mirror the hub's remote_meta_keys
// map — drift means a doomed field gets fired (toast wall returns) or a native
// field gets blocked (save silently withheld).
$svc2 = file_get_contents($ROOT . '/includes/modules/seo/service.php');
// Slice the FUNCTION (whitespace-exact anchors broke on indentation — session lesson).
$map_at = strpos($svc2, 'private static function remote_meta_keys');
$map_block = substr($svc2, (int)$map_at, 1400);
preg_match_all("/'([a-zA-Z]+)'\s*=>\s*array\('pcm_seo_/", $map_block, $mm);
$hub_fields = $mm[1];
preg_match('/REMOTE_META_FIELDS = useMemo\(\(\) => new Set\(\[\s*\n\s*(.+?)\s*\n\s*\]\)/s', $ui, $fm);
$ui_fields = isset($fm[1]) ? array_map(static fn($x) => trim($x, " '\""), array_filter(array_map('trim', explode(',', $fm[1])))) : array();
check('hub meta map parsed (sanity)', count($hub_fields) >= 5, $hub_fields);
check('frontend REMOTE_META_FIELDS mirrors the hub map exactly',
    !empty($ui_fields) && array_diff($hub_fields, $ui_fields) === array() && array_diff($ui_fields, $hub_fields) === array(),
    array('hub' => $hub_fields, 'ui' => $ui_fields));

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
