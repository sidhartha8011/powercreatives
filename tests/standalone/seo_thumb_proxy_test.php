<?php
/**
 * "The feature image is set but it is not displayed — even after set the image we see
 * the icon" (massagegoteborg.nu).
 *
 * PROVEN LIVE first (2026-08-18): the site's featured-image URL answers HTTP 403
 * (Cloudflare challenge) to a browser user-agent, while BOTH REST forms the hub uses
 * (/?rest_route=/pcm-conn/v1/… and /wp-json/pcm-conn/v1/…) answer a plain WP 401 —
 * the wall exempts REST. So the image cannot load in the browser or via an HTTP proxy,
 * but a connector route that reads the file FROM DISK and hands the bytes to the hub
 * sidesteps the wall entirely.
 *
 * Under test, EXECUTED:
 *  1. Connector GET /pcm-conn/v1/media — disk read, image-only, smallest fitting size,
 *     2 MB cap, base64 in the JSON contract.
 *  2. Hub PCM_SEO_Service::remote_thumbnail — connector call, re-validation of the
 *     bytes, disk cache (hit skips the connector), old-connector flag (12h) and the
 *     honest reasons.
 *  3. Hub GET /seo/sites/{id}/thumb — ownership-scoped, streams bytes (test mode: no exit).
 *  4. The Image cell: direct URL → hub proxy → placeholder; proxy only for connected sites;
 *     nonce rides in the query; the connector-status "outdated" covers /media.
 *
 * Run: php tests/standalone/seo_thumb_proxy_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
define('PCM_TESTING_NO_EXIT', true);
$ROOT = dirname(__DIR__, 2);
$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}
// A REAL 1×1 PNG (68 bytes) — what getimagesizefromstring must recognise.
$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$TMP = rtrim(sys_get_temp_dir(), '/\\') . '/pcm_thumb_test_' . getmypid() . '/';
@mkdir($TMP . 'uploads/2026/08', 0777, true);
file_put_contents($TMP . 'uploads/2026/08/pic-150x150.png', $PNG);
// The ORIGINAL is a DIFFERENT image (2x1) so "serves the thumbnail, not the original" is falsifiable.
$PNG_ORIG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAADklEQVR42mNk+M/AwMAAAAQIAgHF9RJ+AAAAAElFTkSuQmCC');
file_put_contents($TMP . 'uploads/2026/08/pic.png', $PNG_ORIG);
file_put_contents($TMP . 'uploads/2026/08/huge.png', str_repeat('x', 2 * 1024 * 1024 + 1));

// ── Shared WP stubs ─────────────────────────────────────────────────────────
class WP_Error { public $c; public $m; public $d; public function __construct($c = '', $m = '', $d = null) { $this->c = $c; $this->m = $m; $this->d = $d; } public function get_error_message() { return $this->m; } public function get_error_code() { return $this->c; } }
function is_wp_error($x) { return $x instanceof WP_Error; }
class WP_REST_Response { public $data; public $status; public function __construct($d = null, $s = 200) { $this->data = $d; $this->status = $s; } }
class WP_REST_Request { private $p; public function __construct($p) { $this->p = $p; } public function get_param($k) { return $this->p[$k] ?? null; } }
function absint($v) { return abs((int) $v); }
function __($s, $d = null) { return $s; }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function esc_url_raw($u) { return (string) $u; }
function trailingslashit($s) { return rtrim((string) $s, '/\\') . '/'; }
function wp_get_upload_dir() { global $TMP; return array('basedir' => $TMP . 'uploads', 'error' => false); }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
// Connector-side media stubs
$GLOBALS['att'] = array(501 => array('mime' => 'image/png', 'file' => 'uploads/2026/08/pic.png', 'thumb' => '2026/08/pic-150x150.png'),
                        502 => array('mime' => 'image/png', 'file' => 'uploads/2026/08/huge.png', 'thumb' => ''),
                        503 => array('mime' => 'application/pdf', 'file' => 'uploads/2026/08/doc.pdf', 'thumb' => ''));
function get_post_type($id) { return isset($GLOBALS['att'][$id]) ? 'attachment' : ($id === 9 ? 'post' : false); }
function get_post_mime_type($id) { return $GLOBALS['att'][$id]['mime'] ?? ''; }
function attachment_url_to_postid($url) { return strpos($url, '/pic.png') !== false ? 501 : 0; }
function image_get_intermediate_size($id, $size) { $t = $GLOBALS['att'][$id]['thumb'] ?? ''; return ($size === 'thumbnail' && $t !== '') ? array('path' => $t) : false; }
function get_attached_file($id) { global $TMP; return isset($GLOBALS['att'][$id]) ? $TMP . $GLOBALS['att'][$id]['file'] : ''; }

// ── 1. The connector route, executed ────────────────────────────────────────
$hub = file_get_contents($ROOT . '/includes/modules/seohub/service.php');
$a = strpos($hub, "<<<'PHP'"); $b = strpos($hub, "\nPHP;", (int)$a);
$tpl = substr($hub, (int)$a + 9, (int)$b - (int)$a - 9);
$baked = str_replace(array('__PCM_CONN_UPDATE_URI__', '__PCM_CONN_VERSION__', '__PCM_HUB_URL__', '__PCM_CLIENT_ID__'), array('https://x', '0.0.0', 'https://hub', 'cid'), $tpl);
$tmpf = tempnam(sys_get_temp_dir(), 'pcmconn') . '.php'; file_put_contents($tmpf, $baked);
exec('php -l ' . escapeshellarg($tmpf) . ' 2>&1', $lint_out, $lint_code); @unlink($tmpf);
echo "\n1. Connector /media — bytes from disk, image-only, bounded\n";
check('the edited connector template still parses', $lint_code === 0, implode("\n", array_slice($lint_out, 0, 3)));
$rs = strpos($tpl, "register_rest_route('pcm-conn/v1', '/media'");
$re = strpos($tpl, "register_rest_route(", (int)$rs + 10);
$route_src = substr($tpl, (int)$rs, (int)$re - (int)$rs);
$route_src = preg_replace('/\/\/[^\n]*\n\s*$/', '', $route_src); // drop the trailing comment of the NEXT route
$GLOBALS['routes'] = array();
function register_rest_route($ns, $route, $args) { $GLOBALS['routes'][$route] = $args; }
$perm = static function () { return true; };
eval($route_src);
check('the route is registered under the connector namespace with the shared permission', isset($GLOBALS['routes']['/media']) && $GLOBALS['routes']['/media']['methods'] === 'GET' && $GLOBALS['routes']['/media']['permission_callback'] === $perm);
$cb = $GLOBALS['routes']['/media']['callback'];
$r = $cb(new WP_REST_Request(array('id' => 501)));
check('by attachment id → 200 with mime + base64 of the THUMBNAIL file (not the original)', $r->status === 200 && $r->data['mime'] === 'image/png' && base64_decode($r->data['data']) === $PNG && $r->data['w'] === 1, $r->data ?? $r);
$r = $cb(new WP_REST_Request(array('url' => 'https://site.test/wp-content/uploads/2026/08/pic.png')));
check('by URL (attachment_url_to_postid) → same bytes', $r->status === 200 && base64_decode($r->data['data']) === $PNG);
$r = $cb(new WP_REST_Request(array('id' => 503)));
check('a non-image attachment (pdf) is refused 415', $r->status === 415, $r);
$r = $cb(new WP_REST_Request(array('id' => 502)));
check('an oversized original with no thumbnail is refused 413 (2 MB cap)', $r->status === 413, $r);
$r = $cb(new WP_REST_Request(array('id' => 9)));
check('a non-attachment id → 404 with the connector’s own error key', $r->status === 404 && $r->data['error'] === 'not_found', $r);
$r = $cb(new WP_REST_Request(array()));
check('nothing asked → 404', $r->status === 404);

// ── 2. The hub proxy service, executed ──────────────────────────────────────
echo "\n2. Hub remote_thumbnail — validate, cache, flag old connectors, honest reasons\n";
$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');
$slice = static function (string $needle) use ($svc): string {
    $i = strpos($svc, $needle); $start = strrpos(substr($svc, 0, (int)$i), "\n") + 1;
    $j = strpos($svc, "\n    /**", (int)$i); if ($j === false) { $j = strpos($svc, "\n    public static function", (int)$i + 10); }
    return substr($svc, $start, (int)$j - $start);
};
$m1 = $slice('public static function remote_thumbnail(');
$m2 = $slice('private const THUMB_EXT');
$m3 = $slice('private static function thumb_cache_dir(');
class PCM_Sites_Service {
    public static $reply = null; public static $calls = array(); public static $flags = array();
    public static function remote_rest($site, $m, $route, $q = array(), $b = null, $t = 30) { self::$calls[] = array($route, $q); return self::$reply; }
    public static function connector_lacks_route($id, $route) { return !empty(self::$flags[$route]); }
    public static function mark_connector_route($id, $route, $served) { if ($served) { unset(self::$flags[$route]); } else { self::$flags[$route] = true; } }
}
eval('class SvcHost { private static function ensure_sites_service() {} ' . $m1 . "\n" . $m2 . "\n" . $m3 . ' }');
$site = (object) array('id' => 7, 'url' => 'https://massagegoteborg.nu');
PCM_Sites_Service::$reply = array('status' => 200, 'body' => array('id' => 501, 'mime' => 'image/png', 'data' => base64_encode($PNG)));
$r = SvcHost::remote_thumbnail($site, 501, '', 'thumbnail');
check('bytes come back with the RE-VALIDATED mime (not the connector’s word)', is_array($r) && $r['mime'] === 'image/png' && $r['bytes'] === $PNG && $r['cached'] === false, $r);
check('the connector was asked once, by id + size', count(PCM_Sites_Service::$calls) === 1 && PCM_Sites_Service::$calls[0][0] === '/pcm-conn/v1/media' && PCM_Sites_Service::$calls[0][1] === array('size' => 'thumbnail', 'id' => 501), PCM_Sites_Service::$calls);
$cached = glob($TMP . 'uploads/pcm-cache/thumbs/*.png');
check('…and cached on disk under uploads/pcm-cache/thumbs (with an index.html)', count($cached) === 1 && is_file($TMP . 'uploads/pcm-cache/thumbs/index.html'), $cached);
PCM_Sites_Service::$calls = array(); PCM_Sites_Service::$reply = new WP_Error('x', 'must not be called');
$r = SvcHost::remote_thumbnail($site, 501, '', 'thumbnail');
check('second ask is served from the cache — the connector is NOT called', is_array($r) && $r['cached'] === true && $r['bytes'] === $PNG && PCM_Sites_Service::$calls === array(), $r);
PCM_Sites_Service::$reply = array('status' => 200, 'body' => array('data' => base64_encode('<html>Just a moment...</html>')));
$r = SvcHost::remote_thumbnail($site, 777, '', 'thumbnail');
check('non-image bytes from the connector are REFUSED (never stored, never served)', $r instanceof WP_Error && $r->get_error_code() === 'pcm_seo_thumb_invalid' && count(glob($TMP . 'uploads/pcm-cache/thumbs/*')) === 2, $r);
PCM_Sites_Service::$reply = array('status' => 404, 'body' => array('code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.'));
$r = SvcHost::remote_thumbnail($site, 778, '', 'thumbnail');
check('a bare rest_no_route (OLD connector) → honest "update the connector" error…', $r instanceof WP_Error && $r->get_error_code() === 'pcm_seo_thumb_no_route' && strpos($r->get_error_message(), 'Update connector') !== false, $r);
check('…and the media capability flag is set', !empty(PCM_Sites_Service::$flags['media']));
PCM_Sites_Service::$calls = array();
$r = SvcHost::remote_thumbnail($site, 779, '', 'thumbnail');
check('while flagged, the connector is not asked again (12h)', $r instanceof WP_Error && PCM_Sites_Service::$calls === array());
PCM_Sites_Service::$flags = array();
PCM_Sites_Service::$reply = array('status' => 404, 'body' => array('error' => 'not_found'));
$r = SvcHost::remote_thumbnail($site, 780, '', 'thumbnail');
check("the connector's OWN 404 (attachment gone) is NOT mistaken for an old connector", $r instanceof WP_Error && $r->get_error_code() === 'pcm_seo_thumb_unavailable' && empty(PCM_Sites_Service::$flags['media']), $r);
$r = SvcHost::remote_thumbnail($site, 0, '', 'thumbnail');
check('nothing to ask for → 400', $r instanceof WP_Error && $r->get_error_code() === 'pcm_seo_thumb_missing');
PCM_Sites_Service::$reply = array('status' => 200, 'body' => array('data' => base64_encode($PNG)));
$r = SvcHost::remote_thumbnail($site, 0, 'https://massagegoteborg.nu/wp-content/uploads/2026/08/pic.png', 'medium');
check('URL-only rows work too (no attachment id known), size honoured', is_array($r) && PCM_Sites_Service::$calls[count(PCM_Sites_Service::$calls) - 1][1] === array('size' => 'medium', 'url' => 'https://massagegoteborg.nu/wp-content/uploads/2026/08/pic.png'));

// ── 3. The hub controller, executed ─────────────────────────────────────────
echo "\n3. Hub GET /seo/sites/{id}/thumb — scoped, streams\n";
$ctl = file_get_contents($ROOT . '/includes/modules/seo/controller.php');
check('route registered (GET, manage_options)', preg_match("#array\('GET',\s+'/seo/sites/\(\?P<id>\\\\d\+\)/thumb', 'remote_thumb', array\(\), 'manage_options'\)#", $ctl) === 1, 'route missing');
$cs = static function (string $needle) use ($ctl): string {
    $i = strpos($ctl, $needle); $start = strrpos(substr($ctl, 0, (int)$i), "\n") + 1;
    $j = strpos($ctl, "\n    /**", (int)$i); return substr($ctl, $start, (int)$j - $start);
};
$h1 = $cs('public function remote_thumb('); $h2 = $cs('protected function stream_bytes(');
class PCM_DB { public static $seen = array(); public static function get_site($id, $uid) { self::$seen[] = array($id, $uid); return $id === 7 ? (object) array('id' => 7, 'url' => 'https://massagegoteborg.nu') : null; } }
class PCM_SEO_Service { public static $reply; public static $args; public static function remote_thumbnail($site, $id, $url, $size) { self::$args = array($id, $url, $size); return self::$reply; } }
eval('class CtlHost { public function get_current_pcm_user() { return (object) array("id" => 3); } public function not_found($w) { return new WP_Error("nf", $w); } ' . $h1 . "\n" . $h2 . ' }');
$c = new CtlHost();
PCM_SEO_Service::$reply = array('mime' => 'image/png', 'bytes' => $PNG, 'cached' => true);
$r = $c->remote_thumb(new WP_REST_Request(array('id' => 7, 'attachmentId' => 501, 'url' => 'https://x/y.png', 'size' => 'thumbnail')));
check('a site the user owns → the bytes stream (test mode reports mime + length)', $r instanceof WP_REST_Response && $r->data === array('mime' => 'image/png', 'length' => strlen($PNG)), $r);
check('the lookup is ownership-scoped (site id + user id)', PCM_DB::$seen[0] === array(7, 3), PCM_DB::$seen);
check('params reach the service (attachmentId, url, size)', PCM_SEO_Service::$args === array(501, 'https://x/y.png', 'thumbnail'), PCM_SEO_Service::$args);
$r = $c->remote_thumb(new WP_REST_Request(array('id' => 8, 'attachmentId' => 501)));
check('another user’s site → not found, nothing streamed', $r instanceof WP_Error, $r);
PCM_SEO_Service::$reply = new WP_Error('pcm_seo_thumb_no_route', 'update', array('status' => 404));
$r = $c->remote_thumb(new WP_REST_Request(array('id' => 7, 'attachmentId' => 501)));
check('service errors pass through as WP_Error (the <img> gets a real 4xx, not a 200 of JSON)', $r instanceof WP_Error && $r->get_error_code() === 'pcm_seo_thumb_no_route');
check('the real stream path sends image headers + bytes then exits', preg_match("/header\('Content-Type: ' \. \\\$mime\);[\s\S]*?header\('X-Content-Type-Options: nosniff'\);[\s\S]*?echo \\\$bytes;[\s\S]*?exit;/", $h2) === 1, 'no raw stream');

// ── 4. The cell ──────────────────────────────────────────────────────────────
echo "\n4. The Image cell: direct → hub proxy → placeholder\n";
$ui = file_get_contents($ROOT . '/app/src/modules/SEO/index.tsx');
check('remoteThumbUrl builds the hub route with attachmentId/url and the REST nonce in the query',
    preg_match('/function remoteThumbUrl\(siteId: number, attachmentId: number, imageUrl: string\): string \{[\s\S]*?seo\/sites\/\$\{siteId\}\/thumb[\s\S]*?q\.set\(\'attachmentId\'[\s\S]*?q\.set\(\'url\', imageUrl\)[\s\S]*?q\.set\(\'_wpnonce\', cfg\.nonce\)/', $ui) === 1, 'no proxy url builder');
check('…and joins with & when restUrl already carries ?rest_route= (plain permalinks)', strpos($ui, "base.includes('?') ? '&' : '?'") !== false);
check('FeaturedThumb tries the direct URL first, then the proxy, then gives up',
    preg_match("/const \[stage, setStage\] = useState<'direct' \| 'proxy' \| 'failed'>\('direct'\)/", $ui) === 1
    && preg_match("/onError=\{\(\) => setStage\(\(s\) => \(s === 'direct' && proxySrc \? 'proxy' : 'failed'\)\)\}/", $ui) === 1, 'no staged fallback');
check('a new src / proxy resets the attempt', preg_match("/useEffect\(\(\) => \{ setStage\('direct'\); \}, \[src, proxySrc\]\);/", $ui) === 1);
check('the cell passes the proxy for CONNECTED sites only', preg_match("/proxySrc=\{isLocal \? '' : remoteThumbUrl\(siteId as number, row\.featuredImageId \?\? 0, row\.featuredImage \|\| ''\)\}/", $ui) === 1, 'proxy not wired / wired for local');
check('when even the proxy fails, the placeholder names the fix (update the connector)', strpos($ui, 'update its connector (SEO → Update connector) so images load through it') !== false);
$sites = file_get_contents($ROOT . '/includes/modules/sites/service.php');
check('connector_status "outdated" now covers the media route (banner offers the update)', preg_match("/connector_lacks_route\(\(int\) \(\\\$site->id \?\? 0\), 'head-tags'\)\s*\|\| self::connector_lacks_route\(\(int\) \(\\\$site->id \?\? 0\), 'media'\)/", $sites) === 1);
$sctl = file_get_contents($ROOT . '/includes/modules/sites/controller.php');
check('a successful connector update clears the media flag too', strpos($sctl, "mark_connector_route((int) \$site->id, 'media', true);") !== false);

// cleanup
foreach (glob($TMP . 'uploads/pcm-cache/thumbs/*') as $f) { @unlink($f); }
@rmdir($TMP . 'uploads/pcm-cache/thumbs'); @rmdir($TMP . 'uploads/pcm-cache');
foreach (glob($TMP . 'uploads/2026/08/*') as $f) { @unlink($f); }
@rmdir($TMP . 'uploads/2026/08'); @rmdir($TMP . 'uploads/2026'); @rmdir($TMP . 'uploads'); @rmdir($TMP);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
