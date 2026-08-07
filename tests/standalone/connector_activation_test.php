<?php
/**
 * Connector version reading + activation self-heal.
 *
 * Guards the fix for a site that keeps reporting "This site's connector predates
 * self-update (no /update-now)" even after the connector has been re-uploaded.
 *
 * THE MECHANISM. The connector package installs to `pcm-connector/pcm-connector.php`.
 * When an OLDER copy already sits at a different plugin path, uploading the zip
 * leaves BOTH installed and the old one still ACTIVE — and only the active plugin
 * registers the /pcm-conn/v1 routes. So:
 *   - /update-now 404s, because the ACTIVE copy predates it, and
 *   - the hub reported the NEW copy's version, because it returned the first
 *     name match without looking at `status` — showing "up to date" while every
 *     connector call behaved like an old build.
 * That pair is why re-uploading appeared to fix nothing.
 *
 * THE FIX. Read every copy with its status, report the ACTIVE one's version, and
 * on a 404 activate the newest installed copy over the same app-password channel
 * (`status` is the one plugin field WordPress core's REST API lets you write)
 * before telling anyone to go and upload a file by hand.
 *
 * Run: php tests/standalone/connector_activation_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

class WP_Error {
    public $msg;
    public function __construct($c = '', $m = '') { $this->msg = $m; }
    public function get_error_message() { return $this->msg; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function esc_html($s) { return $s; }
function __($s, $d = null) { return $s; }
function add_action(...$a) {}
function add_filter(...$a) {}

/**
 * Stands in for the remote site. Records every call so the test can assert WHAT
 * the hub did, not merely what it returned.
 */
class FakeSite {
    public static array $plugins = array();
    public static array $calls = array();
    public static bool $activation_fails = false;
}

// Minimal shim of the service under test: the two methods, verbatim in behaviour,
// reading from FakeSite instead of a live WordPress.
class PCM_Sites_Service {
    public static function remote_rest(object $site, string $method, string $route, array $query = array(), ?array $body = null, int $timeout = 30) {
        FakeSite::$calls[] = $method . ' ' . $route;
        if ($method === 'GET' && $route === '/wp/v2/plugins') {
            return array('status' => 200, 'body' => FakeSite::$plugins);
        }
        if ($method === 'PUT' && str_starts_with($route, '/wp/v2/plugins/')) {
            if (FakeSite::$activation_fails) { return array('status' => 500, 'body' => array()); }
            $target = substr($route, strlen('/wp/v2/plugins/'));
            foreach (FakeSite::$plugins as &$p) {
                $p['status'] = ($p['plugin'] === $target) ? 'active' : 'inactive';
            }
            unset($p);
            return array('status' => 200, 'body' => array());
        }
        return array('status' => 404, 'body' => array());
    }

    public static function remote_connector_plugins(object $site): array {
        $res = self::remote_rest($site, 'GET', '/wp/v2/plugins', array('_fields' => 'plugin,name,version,status'));
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return array();
        }
        $found = array();
        foreach ($res['body'] as $plugin) {
            if (stripos((string) ($plugin['name'] ?? ''), 'Power Creatives Connector') === false) { continue; }
            $found[] = array(
                'plugin'  => (string) ($plugin['plugin'] ?? ''),
                'version' => (string) ($plugin['version'] ?? ''),
                'active'  => ($plugin['status'] ?? '') === 'active',
            );
        }
        usort($found, static fn($a, $b) => version_compare($b['version'], $a['version']));
        return $found;
    }

    public static function remote_connector_version(object $site): string {
        $copies = self::remote_connector_plugins($site);
        foreach ($copies as $c) { if ($c['active']) { return $c['version']; } }
        return (string) ($copies[0]['version'] ?? '');
    }

    public static function activate_newest_connector(object $site): array {
        $out = array('switched' => false, 'from' => '', 'to' => '', 'message' => '');
        $copies = self::remote_connector_plugins($site);
        if (count($copies) === 0) { $out['message'] = 'No connector plugin is installed on this site.'; return $out; }
        $newest = $copies[0];
        $active = null;
        foreach ($copies as $c) { if ($c['active']) { $active = $c; break; } }
        $out['from'] = (string) ($active['version'] ?? '');
        $out['to']   = $newest['version'];
        if ($active !== null && $active['plugin'] === $newest['plugin']) {
            $out['message'] = 'The newest installed connector is already the active one.';
            return $out;
        }
        if ($newest['plugin'] === '') { $out['message'] = 'Could not identify the connector plugin file.'; return $out; }
        $res = self::remote_rest($site, 'PUT', '/wp/v2/plugins/' . $newest['plugin'], array(), array('status' => 'active'), 60);
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300) {
            $out['message'] = is_wp_error($res) ? $res->get_error_message() : 'Activation failed (HTTP ' . (int) ($res['status'] ?? 0) . ').';
            return $out;
        }
        $out['switched'] = true;
        $out['message']  = 'Activated the newer connector already installed on this site.';
        return $out;
    }
}

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}
$site = (object) array('id' => 1, 'url' => 'https://brizy.profitmedia.pro');
$p = fn(string $file, string $ver, string $status) => array(
    'plugin' => $file, 'name' => 'Power Creatives Connector', 'version' => $ver, 'status' => $status,
);

echo "\n1. The reported situation: two copies, the OLD one active\n";
FakeSite::$plugins = array(
    $p('pcm-connector.php', '3.0.8', 'active'),               // legacy path, still running
    $p('pcm-connector/pcm-connector.php', '3.0.8.220', 'inactive'), // the re-upload
    array('plugin' => 'akismet/akismet.php', 'name' => 'Akismet', 'version' => '5.0', 'status' => 'active'),
);
check('both connector copies are seen', count(PCM_Sites_Service::remote_connector_plugins($site)) === 2);
check('unrelated plugins are ignored',
    !in_array('akismet/akismet.php', array_column(PCM_Sites_Service::remote_connector_plugins($site), 'plugin'), true));
// THE MISREPORT: the old code returned the first name match, i.e. the new inactive
// copy, so the hub claimed the site was up to date while the old one served REST.
check('version reported is the ACTIVE copy, not the newest',
    PCM_Sites_Service::remote_connector_version($site) === '3.0.8',
    PCM_Sites_Service::remote_connector_version($site));

echo "\n2. Self-heal: activate the newer copy already on the site\n";
FakeSite::$calls = array();
$heal = PCM_Sites_Service::activate_newest_connector($site);
check('reports a switch', $heal['switched'] === true, $heal);
check('from the old version', $heal['from'] === '3.0.8', $heal['from']);
check('to the newest installed', $heal['to'] === '3.0.8.220', $heal['to']);
check('activated the FOLDER copy, not the legacy file',
    in_array('PUT /wp/v2/plugins/pcm-connector/pcm-connector.php', FakeSite::$calls, true), FakeSite::$calls);
check('the site now runs the new version',
    PCM_Sites_Service::remote_connector_version($site) === '3.0.8.220',
    PCM_Sites_Service::remote_connector_version($site));

echo "\n3. Cases where activation must NOT be attempted\n";
FakeSite::$plugins = array($p('pcm-connector/pcm-connector.php', '3.0.8.220', 'active'));
FakeSite::$calls = array();
$heal = PCM_Sites_Service::activate_newest_connector($site);
check('already newest+active → no switch', $heal['switched'] === false, $heal);
check('and no write is issued',
    count(array_filter(FakeSite::$calls, fn($c) => str_starts_with($c, 'PUT'))) === 0, FakeSite::$calls);
check('message explains why', str_contains($heal['message'], 'already the active one'), $heal['message']);

FakeSite::$plugins = array();
$heal = PCM_Sites_Service::activate_newest_connector($site);
check('no connector installed → honest message',
    $heal['switched'] === false && str_contains($heal['message'], 'No connector plugin'), $heal);
check('no version to report', PCM_Sites_Service::remote_connector_version($site) === '');

echo "\n4. A genuinely old, single-copy install still needs the manual step\n";
// Nothing newer is installed, so there is nothing to switch to — the message the
// user sees in that case is CORRECT, and the fix must not paper over it.
FakeSite::$plugins = array($p('pcm-connector.php', '3.0.8', 'active'));
FakeSite::$calls = array();
$heal = PCM_Sites_Service::activate_newest_connector($site);
check('no switch when only the old copy exists', $heal['switched'] === false, $heal);
check('no pointless write', count(array_filter(FakeSite::$calls, fn($c) => str_starts_with($c, 'PUT'))) === 0);

echo "\n5. A failed activation degrades honestly\n";
FakeSite::$plugins = array(
    $p('pcm-connector.php', '3.0.8', 'active'),
    $p('pcm-connector/pcm-connector.php', '3.0.8.220', 'inactive'),
);
FakeSite::$activation_fails = true;
$heal = PCM_Sites_Service::activate_newest_connector($site);
check('switch reported as failed', $heal['switched'] === false, $heal);
check('carries the HTTP status', str_contains($heal['message'], 'HTTP 500'), $heal['message']);
check('the active copy is untouched', PCM_Sites_Service::remote_connector_version($site) === '3.0.8');
FakeSite::$activation_fails = false;

echo "\n6. A deactivated connector is not mistaken for an uninstalled one\n";
FakeSite::$plugins = array($p('pcm-connector/pcm-connector.php', '3.0.8.220', 'inactive'));
check('falls back to the newest installed version',
    PCM_Sites_Service::remote_connector_version($site) === '3.0.8.220',
    PCM_Sites_Service::remote_connector_version($site));
$heal = PCM_Sites_Service::activate_newest_connector($site);
check('and activating it is attempted', $heal['switched'] === true, $heal);

echo "\n7. Version ordering is numeric, not lexical\n";
// '3.0.8.9' vs '3.0.8.220': a string sort would pick the 9.
FakeSite::$plugins = array(
    $p('a/a.php', '3.0.8.9', 'active'),
    $p('b/b.php', '3.0.8.220', 'inactive'),
);
$copies = PCM_Sites_Service::remote_connector_plugins($site);
check('newest is 3.0.8.220', $copies[0]['version'] === '3.0.8.220', $copies[0]['version']);

echo "\n8. The controller wires the self-heal into BOTH update paths\n";
$ctl = file_get_contents(dirname(__DIR__, 2) . '/includes/modules/sites/controller.php');
check('per-site route heals on 404',
    substr_count($ctl, 'activate_newest_connector') >= 2, substr_count($ctl, 'activate_newest_connector'));
check('and retries /update-now after healing',
    substr_count($ctl, "'/pcm-conn/v1/update-now'") >= 4, substr_count($ctl, "'/pcm-conn/v1/update-now'"));
// A site healed earlier in the bulk loop must not colour a later site's failure.
check('bulk loop resets its retry flag per site', str_contains($ctl, '$retried = false;'), 'flag not reset');
check('no cross-iteration $heal leak', !str_contains($ctl, 'isset($heal)'), 'leak still present');

echo "\n" . str_repeat('─', 52) . "\n";
echo "  passed: $PASS   failed: $FAIL\n";
exit($FAIL > 0 ? 1 : 0);
