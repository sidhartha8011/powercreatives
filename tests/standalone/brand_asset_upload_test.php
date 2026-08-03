<?php
/**
 * Brand asset upload (base64) — standalone harness.
 *
 * Guards the bugfix for "uploading a logo or an image on the brand page does
 * nothing but show an error". Root cause: PCM_REST_Brands::add_asset() read ONLY
 * $_FILES['file'], while every caller in app/ (BrandLogoSection,
 * ReferenceImageSelector, ContextPanel) POSTs JSON { fileData, filename,
 * mimeType }. The request never had a $_FILES entry, so the handler returned
 * "No file uploaded." before the service was ever reached.
 *
 * What this proves about the new PCM_Brands_Service::add_asset_from_data():
 *   1. The exact browser payload lands as a real file + a real asset entry.
 *   2. The `data:<mime>;base64,` prefix is tolerated (browsers differ).
 *   3. The asset is APPENDED to the brand, not replacing existing assets.
 *   4. MIME is allowlisted — mime_to_ext()'s ".png fallback" must NOT act as an
 *      implicit allowlist that lets text/html into the uploads directory.
 *   5. A hostile filename cannot traverse out of the uploads directory, and the
 *      stored extension always follows the validated MIME, never the name.
 *   6. Undecodable base64 fails loudly instead of writing a 0-byte "image".
 *
 * Clean CLI process with plain stubs — no DB, no WordPress. Run:
 *   php tests/standalone/brand_asset_upload_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

$TMP = sys_get_temp_dir() . '/pcm-brand-asset-test-' . getmypid();
@mkdir($TMP, 0777, true);

// ── Minimal WP shims (only what the exercised code path touches) ─────────────
function wp_upload_dir() {
    return array('path' => $GLOBALS['__updir'], 'url' => 'https://example.test/uploads', 'error' => false);
}
function wp_unique_filename($dir, $name) {
    $info = pathinfo($name);
    $base = $info['filename']; $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
    $n = 1; $try = $base . $ext;
    while (file_exists($dir . '/' . $try)) { $try = $base . '-' . (++$n) . $ext; }
    return $try;
}
function sanitize_file_name($n) {
    $n = preg_replace('/[^a-zA-Z0-9 _.\-]/', '', (string) $n);
    return trim(str_replace(array('..', '/', '\\'), '', $n), '.-');
}
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_generate_uuid4() { return sprintf('%04x%04x-test', mt_rand(0, 0xffff), mt_rand(0, 0xffff)); }
function current_time($t) { return '2026-08-04T00:00:00+00:00'; }
function wp_json_encode($d) { return json_encode($d); }
function esc_html($s) { return $s; }
function __($s, $d = null) { return $s; }
function add_action(...$a) {}
function add_filter(...$a) {}

/**
 * Captures what the service writes back to the brand row.
 *
 * update_brand() deliberately does NOT touch any object the caller is holding —
 * a real DB write doesn't either. That is the whole point: code that keeps using
 * its original $brand after a write is looking at stale data, and this stub has
 * to reproduce that or it hides the bug (an earlier version mutated the caller's
 * object in place, which made the staleness test pass even with the fix removed).
 */
class PCM_DB {
    public static array $written = array();
    /** Column => value, as it would be on disk. Null disables get_brand_by_id(). */
    public static ?array $state = null;
    public static function update_brand($id, $uid, array $data) {
        self::$written[] = $data;
        if (self::$state !== null) { self::$state = array_merge(self::$state, $data); }
        return true;
    }
    public static function get_brand_by_id($id, $uid) {
        return self::$state === null ? null : (object) self::$state;
    }
    /** Fresh row object for seeding a test — never the one the stub keeps. */
    public static function seed(array $cols): object {
        self::$state = $cols;
        return (object) $cols;
    }
}

// ── HTTP stubs: serve one HTML page plus the images it references ────────────
$GLOBALS['__http'] = array();
function is_wp_error($t) { return $t instanceof WP_Error_Stub; }
class WP_Error_Stub { public function get_error_message() { return 'stub error'; } }
function wp_remote_get($url, $args = array()) {
    if (!isset($GLOBALS['__http'][$url])) { return new WP_Error_Stub(); }
    return $GLOBALS['__http'][$url];
}
function wp_remote_retrieve_body($r) { return is_array($r) ? ($r['body'] ?? '') : ''; }
function wp_remote_retrieve_header($r, $h) { return is_array($r) ? ($r['headers'][$h] ?? '') : ''; }
function wp_parse_url($u, $c = -1) { return $c === -1 ? parse_url($u) : parse_url($u, $c); }
/** Colour extraction is out of scope here — return a fixed pair. */
class PCM_Image_Utils {
    public static function is_svg($m) { return $m === 'image/svg+xml'; }
    public static function rasterize_svg_to_png($p, $d = null) { return false; }
    public static function extract_dominant_colors($p, $n) { return array('#112233', '#445566'); }
    public static function filter_new_colors($ex, $exist) { return array_values(array_diff($ex, $exist)); }
}

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/includes/modules/brands/service.php';

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

$GLOBALS['__updir'] = $TMP;
$svc = new PCM_Brands_Service();

// A real 1x1 PNG — exactly what the browser's arrayBuffer→btoa produces.
$PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

// A brand that already owns one asset, so "append vs replace" is observable.
$brand = (object) array(
    'assets' => json_encode(array(array('fileKey' => 'brand_7_existing', 'role' => 'reference'))),
    'colors' => json_encode(array('#112233')),
);

echo "\n1. The real browser payload (JSON base64, no \$_FILES)\n";
$asset = $svc->add_asset_from_data($PNG_B64, 'My Logo.png', 'image/png', 7, 3, $brand, 'logo');

check('asset role is the requested one', ($asset['role'] ?? null) === 'logo', $asset['role'] ?? null);
check('source is "upload"', ($asset['source'] ?? null) === 'upload', $asset['source'] ?? null);
check('mimeType preserved', ($asset['mimeType'] ?? null) === 'image/png', $asset['mimeType'] ?? null);
check('fileKey is brand-scoped', str_starts_with((string) ($asset['fileKey'] ?? ''), 'brand_7_'), $asset['fileKey'] ?? null);

$written_name = basename((string) ($asset['url'] ?? ''));
$written_path = $TMP . '/' . $written_name;
check('file actually written to the uploads dir', is_file($written_path), $written_path);
check('written bytes are the decoded PNG', is_file($written_path) && file_get_contents($written_path) === base64_decode($PNG_B64), $written_name);
check('user filename kept, MIME extension enforced', $written_name === 'My Logo.png', $written_name);
check('extracted colours returned, existing filtered out',
    ($asset['extractedColors'] ?? null) === array('#445566'), $asset['extractedColors'] ?? null);

echo "\n2. Appended to the brand, not replacing\n";
$saved = json_decode(end(PCM_DB::$written)['assets'], true);
check('brand row was updated once', count(PCM_DB::$written) === 1, count(PCM_DB::$written));
check('existing asset survived', ($saved[0]['fileKey'] ?? null) === 'brand_7_existing', $saved[0] ?? null);
check('new asset appended', count($saved) === 2 && ($saved[1]['role'] ?? null) === 'logo', $saved);

echo "\n3. data: URI prefix tolerated\n";
PCM_DB::$written = array();
$a2 = $svc->add_asset_from_data('data:image/png;base64,' . $PNG_B64, 'prefixed.png', 'image/png', 7, 3, $brand, 'reference');
check('prefixed payload decodes to the same bytes',
    file_get_contents($TMP . '/' . basename($a2['url'])) === base64_decode($PNG_B64), basename($a2['url']));

echo "\n4. MIME allowlist — the .png fallback is not an allowlist\n";
foreach (array('text/html', 'application/x-php', 'application/octet-stream', '') as $bad) {
    $threw = false;
    try { $svc->add_asset_from_data($PNG_B64, 'x.png', $bad, 7, 3, $brand, 'logo'); }
    catch (\RuntimeException $e) { $threw = str_contains($e->getMessage(), 'Unsupported image type'); }
    check("rejects MIME '" . $bad . "'", $threw);
}
$ok_mimes = array('image/png', 'image/jpeg', 'image/gif', 'image/webp');
foreach ($ok_mimes as $good) {
    $accepted = true;
    try { $svc->add_asset_from_data($PNG_B64, 'ok.bin', $good, 7, 3, $brand, 'reference'); }
    catch (\Throwable $e) { $accepted = $e->getMessage(); }
    check("accepts MIME '" . $good . "'", $accepted === true, $accepted);
}
$charset = $svc->add_asset_from_data($PNG_B64, 'charset.png', 'IMAGE/PNG; charset=binary', 7, 3, $brand, 'reference');
check('MIME parameters + casing normalized, not rejected', ($charset['mimeType'] ?? null) === 'image/png', $charset['mimeType'] ?? null);

echo "\n5. Hostile filename cannot escape the uploads dir\n";
$evil = $svc->add_asset_from_data($PNG_B64, '../../../wp-config.php', 'image/png', 7, 3, $brand, 'reference');
$evil_name = basename((string) $evil['url']);
check('no traversal segments in the stored name',
    !str_contains($evil_name, '..') && !str_contains($evil_name, '/'), $evil_name);
check('stored file sits inside the uploads dir', is_file($TMP . '/' . $evil_name), $evil_name);
check('extension follows the MIME, not the name', str_ends_with($evil_name, '.png'), $evil_name);
check('nothing written outside the uploads dir', !file_exists(dirname($TMP, 3) . '/wp-config.php'));

$empty_name = $svc->add_asset_from_data($PNG_B64, '...', 'image/png', 7, 3, $brand, 'reference');
check('filename that sanitizes to nothing still gets a name',
    basename((string) $empty_name['url']) !== '.png', basename((string) $empty_name['url']));

echo "\n6. Bad input fails loudly\n";
$threw = false;
try { $svc->add_asset_from_data('!!!not base64!!!', 'x.png', 'image/png', 7, 3, $brand, 'logo'); }
catch (\RuntimeException $e) { $threw = str_contains($e->getMessage(), 'decode'); }
check('undecodable base64 throws instead of writing a 0-byte image', $threw);

$threw = false;
try { $svc->add_asset_from_data($PNG_B64, 'x.png', 'image/png', 7, 3, $brand, 'not-a-role'); }
catch (\InvalidArgumentException $e) { $threw = true; }
check('role is still validated on this path', $threw);

$threw = false;
try { $svc->add_asset_from_data($PNG_B64, 'x.png', 'image/png', 7, 3, $brand, ''); }
catch (\InvalidArgumentException $e) { $threw = true; }
check('empty role rejected', $threw);

echo "\n7. Website scrape STORES what it finds (fetch_and_store_website_assets)\n";
// fetch_website_assets() used to only return URLs, so "fetch images from this page"
// found images and then threw them away — and ReferenceImageSelector, which reads
// `added`/`message`, errored on every call.
$PNG_BYTES = base64_decode($PNG_B64);
$page = 'https://example.test/';
$GLOBALS['__http'] = array(
    $page => array('body' =>
        '<html><head>'
        . '<meta property="og:image" content="https://cdn.test/og.png">'
        . '<link rel="icon" href="/favicon.png">'
        . '<link rel="apple-touch-icon" href="https://cdn.test/touch.png">'
        . '</head></html>'),
    'https://cdn.test/og.png'    => array('body' => $PNG_BYTES, 'headers' => array('content-type' => 'image/png')),
    'https://example.test/favicon.png' => array('body' => $PNG_BYTES, 'headers' => array('content-type' => 'image/png')),
    'https://cdn.test/touch.png' => array('body' => $PNG_BYTES, 'headers' => array('content-type' => 'image/png')),
);

PCM_DB::$written = array();
$scrapeBrand = PCM_DB::seed(array('assets' => '[]', 'colors' => '[]'));
$scrape = $svc->fetch_and_store_website_assets($page, 7, 3, $scrapeBrand);

check('all 3 scraped images returned', count($scrape['images']) === 3, $scrape['images']);
check('all 3 were actually STORED', count($scrape['added']) === 3, count($scrape['added']));
check('no message when images were stored', $scrape['message'] === '', $scrape['message']);
check('every stored asset has role reference',
    count(array_filter($scrape['added'], fn($a) => $a['role'] === 'reference')) === 3, $scrape['added']);

// The regression this guards: add_asset_from_url() appends to the brand object it
// is HANDED. Without re-reading between iterations each store overwrites the last
// and only one asset survives.
$final = json_decode(PCM_DB::get_brand_by_id(7, 3)->assets, true);
check('brand accumulated all 3 assets, none overwritten', count($final) === 3, count($final));
check('stored assets have distinct fileKeys',
    count(array_unique(array_column($final, 'fileKey'))) === 3, array_column($final, 'fileKey'));

echo "\n8. Scrape degrades gracefully\n";
$scrapeBrand = PCM_DB::seed(array('assets' => '[]', 'colors' => '[]'));
$GLOBALS['__http'][$page] = array('body' => '<html><head></head></html>');
$none = $svc->fetch_and_store_website_assets($page, 7, 3, $scrapeBrand);
check('no images → empty added', $none['added'] === array() && $none['images'] === array(), $none);
check('no images → explanatory message', str_contains($none['message'], 'No images found'), $none['message']);

$scrapeBrand = PCM_DB::seed(array('assets' => '[]', 'colors' => '[]'));
$GLOBALS['__http'][$page] = array('body' => '<meta property="og:image" content="https://cdn.test/gone.png">');
$dead = $svc->fetch_and_store_website_assets($page, 7, 3, $scrapeBrand);
check('unreachable image → found but not added', count($dead['images']) === 1 && $dead['added'] === array(), $dead);
check('unreachable image → explanatory message', str_contains($dead['message'], 'none could be downloaded'), $dead['message']);

$scrapeBrand = PCM_DB::seed(array('assets' => '[]', 'colors' => '[]'));
$GLOBALS['__http'][$page] = array('body' =>
    '<meta property="og:image" content="https://cdn.test/og.png">'
    . '<link rel="icon" href="https://cdn.test/gone.png">');
$partial = $svc->fetch_and_store_website_assets($page, 7, 3, $scrapeBrand);
check('one dead image does not cost the good one',
    count($partial['images']) === 2 && count($partial['added']) === 1, $partial['added']);

PCM_DB::$state = null;

// ── Cleanup ──
foreach (glob($TMP . '/*') as $f) { @unlink($f); }
@rmdir($TMP);

echo "\n" . str_repeat('─', 52) . "\n";
echo "  passed: $PASS   failed: $FAIL\n";
exit($FAIL > 0 ? 1 : 0);
