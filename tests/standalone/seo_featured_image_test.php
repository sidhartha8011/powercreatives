<?php
/**
 * SEO table — "Not pulling in the featured image".
 *
 * Owner: "when a page has a featured image, the table does not show it. it
 * seemingly can only Set a image. not discover if there is one."
 *
 * DISCOVERY depended on the WP REST `_embed` payload arriving. A post always
 * carries `featured_media` (an id); `_embedded` is optional and routinely
 * absent — older cores drop it when `_fields` is used, and security plugins /
 * CDNs strip it. When it went missing the row got id-but-no-URL and the cell
 * rendered the empty "set an image" box, i.e. it could set but never discover.
 *
 * Now: remote rows carry featuredImageId, and any row with an id but no URL is
 * resolved from a batched /wp/v2/media call. Both the row builder and the
 * resolver are EXECUTED here against realistic payload shapes with scripted
 * HTTP; the local builder's field set is pinned too.
 *
 * Run: php tests/standalone/seo_featured_image_test.php
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
if (!class_exists('WP_Error')) { class WP_Error { public function get_error_message() { return 'err'; } } }
function is_wp_error($x) { return $x instanceof WP_Error; }
function wp_trim_words($t, $n = 20, $e = '…') { return $t; }
function wp_strip_all_tags($t) { return strip_tags((string) $t); }

$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');

// Host the resolver with a scriptable remote_rest (records every call).
$i = strpos($svc, 'private static function fill_remote_featured_images');
check('fill_remote_featured_images found', $i !== false);
$start = strrpos(substr($svc, 0, (int)$i), "\n") + 1;
$next = strpos($svc, "\n    /**", (int)$i);
$fn = substr($svc, $start, (int)$next - $start);
$fn = str_replace('private static', 'public static', $fn);
$fn = str_replace('PCM_Sites_Service::remote_rest', 'self::remote_rest', $fn);
eval('class MediaHost {
    public static $reply = null;      // array|WP_Error, or a callable(chunk)
    public static $calls = array();
    public static function remote_rest($site, $method, $route, $q = array(), $b = array(), $t = 30) {
        self::$calls[] = array($route, $q);
        return is_callable(self::$reply) ? (self::$reply)($q) : self::$reply;
    }
    ' . $fn . ' }');

$site = (object) array('id' => 1, 'url' => 'https://x.se');
$media_ok = static fn($q) => array('status' => 200, 'body' => array_map(
    static fn($id) => array(
        'id' => (int) $id,
        'source_url' => "https://x.se/full-{$id}.jpg",
        'media_details' => array('sizes' => array('thumbnail' => array('source_url' => "https://x.se/thumb-{$id}.jpg"))),
    ),
    explode(',', (string) $q['include'])
));

echo "\n1. The reported bug — an image that EXISTS is discovered without the embed\n";
MediaHost::$reply = $media_ok; MediaHost::$calls = array();
$rows = array(
    array('id' => 11, 'featuredImage' => '', 'featuredImageId' => 501),   // embed missing (the bug)
    array('id' => 12, 'featuredImage' => '', 'featuredImageId' => 0),     // genuinely no image
);
MediaHost::fill_remote_featured_images($site, $rows);
check('a page with a featured image now shows a thumbnail',
    $rows[0]['featuredImage'] === 'https://x.se/thumb-501.jpg', $rows[0]);
check('a page with NO image stays empty (no invented URL)', $rows[1]['featuredImage'] === '', $rows[1]);
check('one batched media call, by id', count(MediaHost::$calls) === 1 && MediaHost::$calls[0][0] === '/wp/v2/media', MediaHost::$calls);
check('…asking only for what it needs', MediaHost::$calls[0][1]['include'] === '501', MediaHost::$calls[0][1]);

echo "\n2. Costs nothing when the embed already worked\n";
MediaHost::$reply = $media_ok; MediaHost::$calls = array();
$rows = array(array('id' => 21, 'featuredImage' => 'https://x.se/thumb-9.jpg', 'featuredImageId' => 9));
MediaHost::fill_remote_featured_images($site, $rows);
check('no request at all when every URL is present', MediaHost::$calls === array(), MediaHost::$calls);
check('the embed URL is left untouched', $rows[0]['featuredImage'] === 'https://x.se/thumb-9.jpg', $rows[0]);

echo "\n3. Shapes + failure modes (honest, never fabricated)\n";
MediaHost::$reply = static fn($q) => array('status' => 200, 'body' => array(
    array('id' => 601, 'source_url' => 'https://x.se/logo.svg'),                 // no sizes (SVG/PDF)
    array('id' => 602, 'source_url' => 'https://x.se/f.jpg', 'media_details' => array('sizes' => array('medium' => array('source_url' => 'https://x.se/m.jpg')))),
));
$rows = array(
    array('id' => 31, 'featuredImage' => '', 'featuredImageId' => 601),
    array('id' => 32, 'featuredImage' => '', 'featuredImageId' => 602),
);
MediaHost::fill_remote_featured_images($site, $rows);
check('no thumbnail size → falls back to the full URL', $rows[0]['featuredImage'] === 'https://x.se/logo.svg', $rows[0]);
check('sizes without a thumbnail → falls back to the full URL', $rows[1]['featuredImage'] === 'https://x.se/f.jpg', $rows[1]);

MediaHost::$reply = new WP_Error();
$rows = array(array('id' => 41, 'featuredImage' => '', 'featuredImageId' => 701));
MediaHost::fill_remote_featured_images($site, $rows);
check('media route unreachable → cell stays empty, no crash', $rows[0]['featuredImage'] === '', $rows[0]);
MediaHost::$reply = array('status' => 401, 'body' => array());
$rows = array(array('id' => 42, 'featuredImage' => '', 'featuredImageId' => 702));
MediaHost::fill_remote_featured_images($site, $rows);
check('media route forbidden → cell stays empty, no crash', $rows[0]['featuredImage'] === '', $rows[0]);

echo "\n4. Scale: batched, deduped, bounded\n";
MediaHost::$reply = $media_ok; MediaHost::$calls = array();
$rows = array();
for ($n = 0; $n < 150; $n++) { $rows[] = array('id' => 1000 + $n, 'featuredImage' => '', 'featuredImageId' => 2000 + $n); }
MediaHost::fill_remote_featured_images($site, $rows);
check('150 images → 2 chunked calls (100 max per request)', count(MediaHost::$calls) === 2, count(MediaHost::$calls));
check('every row filled', $rows[0]['featuredImage'] === 'https://x.se/thumb-2000.jpg' && $rows[149]['featuredImage'] === 'https://x.se/thumb-2149.jpg');
MediaHost::$reply = $media_ok; MediaHost::$calls = array();
$rows = array(
    array('id' => 51, 'featuredImage' => '', 'featuredImageId' => 900),   // three pages sharing
    array('id' => 52, 'featuredImage' => '', 'featuredImageId' => 900),   // ONE image
    array('id' => 53, 'featuredImage' => '', 'featuredImageId' => 900),
);
MediaHost::fill_remote_featured_images($site, $rows);
check('a shared image is requested once…', MediaHost::$calls[0][1]['include'] === '900', MediaHost::$calls[0][1]);
check('…and fills every row that uses it',
    $rows[0]['featuredImage'] === $rows[1]['featuredImage'] && $rows[1]['featuredImage'] === 'https://x.se/thumb-900.jpg', $rows);
MediaHost::$reply = $media_ok; MediaHost::$calls = array();
$rows = array();
for ($n = 0; $n < 400; $n++) { $rows[] = array('id' => $n, 'featuredImage' => '', 'featuredImageId' => 5000 + $n); }
MediaHost::fill_remote_featured_images($site, $rows);
check('a huge table is capped (first load stays bounded)', count(MediaHost::$calls) === 2, count(MediaHost::$calls));

echo "\n5. The row builders carry the id (that is what makes discovery possible)\n";
check('remote_row returns featuredImageId from featured_media',
    strpos($svc, "'featuredImageId'   => (int) (\$item['featured_media'] ?? 0),") !== false, 'remote rows have no id');
check('remote_row still prefers the embed when present',
    strpos($svc, "\$item['_embedded']['wp:featuredmedia'][0]['media_details']['sizes']['thumbnail']['source_url']") !== false);
check('the list requests featured_media', strpos($svc, 'featured_media,excerpt,meta') !== false);
check('the resolver runs on every remote list', preg_match('/function remote_list_content\([\s\S]{0,4000}?self::fill_remote_featured_images\(\$site, \$rows\);/', $svc) === 1, 'never called');
$loc = file_get_contents($ROOT . '/includes/modules/seo/local.php');
check('local rows carry url + id (unchanged parity)',
    strpos($loc, "'featuredImage'      => (string) get_the_post_thumbnail_url(\$id, 'thumbnail')") !== false
    && strpos($loc, "'featuredImageId'    => (int) get_post_thumbnail_id(\$id)") !== false);

echo "\n6. The cell tells the truth about all three states\n";
$ui = file_get_contents($ROOT . '/app/src/modules/SEO/index.tsx');
check('an unresolvable-but-SET image is not shown as "none"',
    strpos($ui, 'const hasImageId = (row.featuredImageId ?? 0) > 0;') !== false
    && strpos($ui, 'A featured image is set on this page, but its URL could not be read') !== false, 'silently reads as empty');
check('the three states are visually distinct (solid vs dashed vs image)',
    preg_match('/row\.featuredImage \? \([\s\S]{0,260}?<img[\s\S]{0,200}?\) : hasImageId \? \([\s\S]{0,260}?border-border bg-muted[\s\S]{0,200}?\) : \([\s\S]{0,200}?border-dashed/', $ui) === 1, 'states collapsed');
check('sorting agrees with what the cell shows',
    strpos($ui, "featuredImage: (r) => (r.featuredImage || (r.featuredImageId ?? 0) > 0 ? 1 : 0)") !== false, 'sort disagrees with display');
check('the client keeps featuredImageId on remote rows',
    strpos(file_get_contents($ROOT . '/app/src/modules/SEO/hooks/useRemoteSeoContent.ts'), 'featuredImageId: Number(r.featuredImageId ?? 0)') !== false);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
