<?php
/**
 * Bugfix card 8 — "Plugin update is not working… even if the version is the
 * version with a self-updating package."
 *
 * The screenshot is MY 08-15 banner: "Update connector" → toast "The
 * connector could not self-update — reinstall it once". Traced both ends:
 *
 *  1. THE TOAST WAS WRONG ON SUCCESS. The hub answers
 *     { status: 'updated'|'up-to-date', from, to, version } — the connector's
 *     own contract. The frontend tested `res.updated || res.ok`, keys that do
 *     NOT exist, so a SUCCESSFUL update (and an already-current connector)
 *     both fell into the failure toast. Real failures never even reach that
 *     branch — the hub 409/502s and onError handles them.
 *  2. A LINGERING "OUTDATED" FLAG. The route-capability flag cleared only on
 *     the next successful meta fetch (up to 12h) — after an update the banner
 *     could keep nagging. The hub now clears it on any /update-now success.
 *  3. "UP-TO-DATE" CAN BE A LIE. The connector's verdict comes from a WP
 *     manifest poll on the CLIENT site; if that poll can't reach the hub, the
 *     connector honestly reports current while the hub serves a newer build.
 *     The hub now compares installed vs served and reports `stale` with the
 *     exact remedy instead of relaying a verdict it can see is wrong.
 *
 * update_connector() is EXECUTED here against scripted connector replies.
 *
 * Run: php tests/standalone/connector_update_flow_test.php
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

// ── Stubs ───────────────────────────────────────────────────────────────────
if (!class_exists('WP_Error')) { class WP_Error { public $m; public function __construct($c = '', $m = '') { $this->m = $m; } public function get_error_message() { return $this->m; } } }
function is_wp_error($x) { return $x instanceof WP_Error; }
function absint($v) { return abs((int) $v); }
function __($s, $d = null) { return $s; }
function home_url($p = '/') { return 'https://hub.test/'; }
class WP_REST_Request { private $p; public function __construct($p) { $this->p = $p; } public function get_param($k) { return $this->p[$k] ?? null; } }
class WP_REST_Response { public $data; public $status; public function __construct($d, $s = 200) { $this->data = $d; $this->status = $s; } }
class PCM_DB { public static function get_site($id, $uid) { return (object) array('id' => $id, 'url' => 'https://client.test'); } }
class PCM_SEOHub_Service { public static $served = '3.0.9.220'; public static function connector_effective_version() { return self::$served; } }
class PCM_Sites_Service {
    public static $reply = null; public static $installed = '3.0.9.220'; public static $marks = array(); public static $heal = array('switched' => false, 'message' => '');
    public static function remote_rest($site, $m, $route, $q = array(), $b = null, $t = 30) { return self::$reply; }
    public static function remote_connector_version($site) { return self::$installed; }
    public static function mark_connector_route($id, $route, $served) { self::$marks[] = array($id, $route, $served); }
    public static function activate_newest_connector($site) { return self::$heal; }
}

// Host the REAL handler.
$ctl = file_get_contents($ROOT . '/includes/modules/sites/controller.php');
$i = strpos($ctl, 'public function update_connector(');
$start = strrpos(substr($ctl, 0, (int)$i), "\n") + 1;
$j = strpos($ctl, "\n    /**", (int)$i);
$fn = substr($ctl, (int)$start, (int)$j - (int)$start);
if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
$fn = str_replace("require_once __DIR__ . '/service.php';", '', $fn);
eval('class CtlHost {
    public function get_current_pcm_user() { return (object) array("id" => 1); }
    public function not_found($w) { return new WP_Error("nf", $w); }
    public function error($msg, $code = 400, $slug = "") { return new WP_Error($slug, $msg); }
    public function success($data, $code = 200) { return new WP_REST_Response($data, $code); }
    ' . $fn . ' }');
$host = new CtlHost();
$req  = new WP_REST_Request(array('id' => 4));

echo "\n1. A REAL update — the connector answers 'updated'\n";
PCM_Sites_Service::$reply = array('status' => 200, 'body' => array('status' => 'updated', 'from' => '3.0.8', 'to' => '3.0.9.220'));
PCM_Sites_Service::$installed = '3.0.9.220'; PCM_Sites_Service::$marks = array();
$r = $host->update_connector($req);
check('handler succeeds', $r instanceof WP_REST_Response, $r);
check('status = updated, from/to relayed', $r->data['status'] === 'updated' && $r->data['from'] === '3.0.8' && $r->data['to'] === '3.0.9.220', $r->data);
check('the re-read installed version is included', $r->data['version'] === '3.0.9.220');
check('the head-tags + media capability flags are CLEARED (banner stops nagging)', PCM_Sites_Service::$marks === array(array(4, 'head-tags', true), array(4, 'media', true)), PCM_Sites_Service::$marks);

echo "\n2. Already current — the connector answers 'up-to-date' and REALLY is\n";
PCM_Sites_Service::$reply = array('status' => 200, 'body' => array('status' => 'up-to-date', 'version' => '3.0.9.220'));
PCM_Sites_Service::$installed = '3.0.9.220'; PCM_SEOHub_Service::$served = '3.0.9.220'; PCM_Sites_Service::$marks = array();
$r = $host->update_connector($req);
check('status = up-to-date (relayed, not turned into a failure)', $r->data['status'] === 'up-to-date', $r->data);
check('no stale message', $r->data['message'] === '');
check('flags cleared here too', PCM_Sites_Service::$marks === array(array(4, 'head-tags', true), array(4, 'media', true)));

echo "\n3. THE LIE — 'up-to-date' while the hub serves a NEWER build\n";
PCM_Sites_Service::$installed = '3.0.8.212'; PCM_SEOHub_Service::$served = '3.0.9.220';
$r = $host->update_connector($req);
check('status = stale (the hub can SEE the verdict is wrong)', $r->data['status'] === 'stale', $r->data);
check('the message names both versions', strpos($r->data['message'], 'v3.0.8.212') !== false && strpos($r->data['message'], 'v3.0.9.220') !== false, $r->data['message']);
check('…and the exact remedy (reachability of the hub URL / reinstall)', strpos($r->data['message'], 'https://hub.test/') !== false && strpos($r->data['message'], 'reinstall') !== false, $r->data['message']);
check('served version is in the payload', $r->data['served'] === '3.0.9.220');
check('a NEWER installed than served is NOT stale (dev builds)', (static function () use ($host, $req) {
    PCM_Sites_Service::$installed = '3.1.0.300'; PCM_SEOHub_Service::$served = '3.0.9.220';
    return $host->update_connector($req)->data['status'] === 'up-to-date';
})());

echo "\n4. Real failures still fail (never faked)\n";
PCM_Sites_Service::$reply = new WP_Error('x', 'cURL error 28: timed out');
$r = $host->update_connector($req);
check('transport error → WP_Error with the reason', $r instanceof WP_Error && strpos($r->get_error_message(), 'timed out') !== false, $r);
PCM_Sites_Service::$reply = array('status' => 500, 'body' => array('message' => 'Upgrade did not complete.'));
$r = $host->update_connector($req);
check("connector 500 → the connector's own message", $r instanceof WP_Error && $r->get_error_message() === 'Upgrade did not complete.', $r);
PCM_Sites_Service::$reply = array('status' => 404, 'body' => array()); PCM_Sites_Service::$heal = array('switched' => false, 'message' => '');
$r = $host->update_connector($req);
check('404 + no newer copy → too-old error naming the reinstall', $r instanceof WP_Error && strpos($r->get_error_message(), 'Reinstall the connector once') !== false, $r);
PCM_Sites_Service::$marks = array();
check('a failure never clears the capability flag', PCM_Sites_Service::$marks === array());

echo "\n5. The FRONTEND reads the real contract (the screenshot's bug)\n";
$ui = file_get_contents($ROOT . '/app/src/modules/SEO/index.tsx');
$h  = substr($ui, strpos($ui, 'const connectorUpdate = trpc.sites.updateConnector.useMutation('), 2600);
check("the phantom keys are gone (`res.updated || res.ok` never existed in the payload)", strpos($h, 'res?.updated || res?.ok') === false, 'still testing keys that do not exist');
check("'updated' → success toast with from → to", preg_match("/status === 'updated'[\s\S]{0,300}?toast\.success\(`Connector updated/", $h) === 1, 'success not recognised');
check("'up-to-date' → success toast (NOT the reinstall failure)", preg_match("/status === 'up-to-date'[\s\S]{0,300}?toast\.success\(`The connector is already the newest build/", $h) === 1, 'up-to-date treated as failure');
check("'stale' → the hub's remedy message, as a warning", preg_match("/status === 'stale'[\s\S]{0,300}?toast\.warning\(res\?\.message/", $h) === 1, 'stale not surfaced');
check('no success path shows the reinstall FAILURE text', strpos($h, "toast.error(res?.message || 'The connector could not self-update") === false, 'onSuccess still toasts failure');
check('real failures still go through onError', strpos($h, "onError: (e: any) => toast.error(e.message ?? 'Could not update the connector.')") !== false);
check('status is refetched after any answer (banner re-evaluates)', strpos($h, 'void connectorStatusQuery.refetch();') !== false);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
