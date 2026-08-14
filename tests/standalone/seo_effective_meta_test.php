<?php
/**
 * SEO table — empty meta columns fall back to the page's RENDERED tags.
 *
 * Reported (massagegoteborg.nu): Meta Title / Meta Description columns blank
 * while every live page has perfect tags. Cause: the table reads the SEO
 * plugin's STORED per-post meta, but Yoast/RankMath/SEOPress only store a value
 * when someone typed an override — generated-from-template tags live nowhere in
 * the DB. Fix: rows with EMPTY meta cells are filled from the page's actual
 * <head> (local: cached loopback; remote: the connector's /head-tags route).
 * Stored meta always wins; the fallback only fills blanks.
 *
 * The parser and the filler are EXECUTED here (real methods, extracted).
 *
 * Run: php tests/standalone/seo_effective_meta_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }

$ROOT = dirname(__DIR__, 2);
$loc  = file_get_contents($ROOT . '/includes/modules/seo/local.php');

/** Extract a method by slicing to the NEXT method at indentation (braces appear
 *  inside strings/regexes in this file — counting them fails; session lesson). */
$grab = function (string $name) use ($loc): string {
    $i = strpos($loc, 'static function ' . $name . '(');
    if ($i === false) { fwrite(STDERR, "method {$name} not found\n"); exit(1); }
    $start = strrpos(substr($loc, 0, $i), "\n") + 1;
    $next = strlen($loc);
    foreach (array("\n    public static function ", "\n    private static function ") as $m) {
        $n = strpos($loc, $m, $i + 1);
        if ($n !== false && $n < $next) { $next = $n; }
    }
    $chunk = substr($loc, $start, $next - $start);
    if (preg_match('/\n    \}\r?\n/', $chunk, $mm, PREG_OFFSET_CAPTURE)) { $chunk = substr($chunk, 0, $mm[0][1]) . "\n    }"; }
    return str_replace('private static', 'public static', $chunk);
};
eval('class MetaHost { ' . $grab('parse_head_tags') . $grab('head_meta_content') . $grab('fill_effective_meta') . $grab('is_challenge_page') . ' }');

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

echo "\n1. The parser reads what the page actually renders\n";
$page = '<html><head><title>The Highest Compliment — When Customers Feel Better</title>'
      . '<meta name="description" content="Discover why a customer feeling better is the ultimate compliment.">'
      . '<meta property="og:title" content="OG Title"></head>'
      . '<body><svg><title>icon label</title></svg>'
      . '<p>body text with description-like words</p></body></html>';
$t = MetaHost::parse_head_tags($page);
check('title parsed from <title>', $t['title'] === 'The Highest Compliment — When Customers Feel Better', $t['title']);
check('description parsed from meta[name=description]', str_starts_with($t['description'], 'Discover why a customer'), $t['description']);
check('a <title> in the BODY (svg icon label) never wins',
    MetaHost::parse_head_tags('<head><meta name="x" content="y"></head><body><title>Body Title</title></body>')['title'] === '', 'body title leaked');
$rev = '<head><meta content="Reversed attr order works" name="description"><title>T</title></head>';
check('attribute order reversed still parses', MetaHost::parse_head_tags($rev)['description'] === 'Reversed attr order works');
$sq = "<head><title>Single</title><meta name='description' content='Single quotes too'></head>";
check('single-quoted attributes parse', MetaHost::parse_head_tags($sq)['description'] === 'Single quotes too');
$og = '<head><meta property="og:title" content="Only OG Title"><meta property="og:description" content="Only OG Desc"></head>';
$r = MetaHost::parse_head_tags($og);
check('og:title fallback when <title> is missing', $r['title'] === 'Only OG Title', $r['title']);
check('og:description fallback when description is missing', $r['description'] === 'Only OG Desc', $r['description']);
check('entities are decoded (&amp; &#8211;)',
    MetaHost::parse_head_tags('<head><title>Massage &amp; Spa &#8211; G&#246;teborg</title></head>')['title'] === 'Massage & Spa – Göteborg',
    MetaHost::parse_head_tags('<head><title>Massage &amp; Spa &#8211; G&#246;teborg</title></head>')['title']);
check('whitespace collapses to single spaces',
    MetaHost::parse_head_tags("<head><title>Two\n   Lines</title></head>")['title'] === 'Two Lines');
check('no head, no tags → empty strings, no notices',
    MetaHost::parse_head_tags('just text') === array('title' => '', 'description' => ''));

echo "\n2. The filler: stored plugin meta ALWAYS wins, blanks get filled\n";
$html = '<head><title>Rendered T</title><meta name="description" content="Rendered D"></head>';
$rows = array(
    array('id' => 1, 'permalink' => 'https://x/a', 'metaTitle' => '',       'metaDescription' => ''),
    array('id' => 2, 'permalink' => 'https://x/b', 'metaTitle' => 'Stored', 'metaDescription' => ''),
    array('id' => 3, 'permalink' => 'https://x/c', 'metaTitle' => 'Full',   'metaDescription' => 'Full D'),
    array('id' => 4, 'permalink' => '',            'metaTitle' => '',       'metaDescription' => ''),
);
$calls = array();
$out = MetaHost::fill_effective_meta($rows, function ($row) use (&$calls, $html) { $calls[] = $row['id']; return $html; });
check('empty row fully filled from the page', $out[0]['metaTitle'] === 'Rendered T' && $out[0]['metaDescription'] === 'Rendered D', $out[0]);
check('stored title untouched, only the blank description filled',
    $out[1]['metaTitle'] === 'Stored' && $out[1]['metaDescription'] === 'Rendered D', $out[1]);
check('a fully-stored row is never fetched at all', !in_array(3, $calls, true), $calls);
check('no permalink → skipped, not fetched', !in_array(4, $calls, true), $calls);
check('fetch count matches the rows that needed it', $calls === array(1, 2), $calls);
$many = array();
for ($i = 1; $i <= 30; $i++) { $many[] = array('id' => $i, 'permalink' => 'https://x/' . $i, 'metaTitle' => '', 'metaDescription' => ''); }
$n = 0;
MetaHost::fill_effective_meta($many, function () use (&$n, $html) { $n++; return $html; });
check('the cap bounds a big list (20 of 30 fetched)', $n === 20, $n);
$out2 = MetaHost::fill_effective_meta(array(array('id' => 9, 'permalink' => 'https://x/9', 'metaTitle' => '', 'metaDescription' => '')), function () { return null; });
check('a failed fetch leaves the row empty (no fatal, no fake value)', $out2[0]['metaTitle'] === '', $out2[0]);

echo "\n2b. Bot-challenge pages are refused, never reported as tags\n";
// The REAL failure observed live: fetching massagegoteborg.nu returned a 5.8KB
// Cloudflare interstitial whose <title> is "Just a moment..." — without the
// guard that string becomes the post's Meta Title and gets CACHED for a week.
$cf = '<html><head><title>Just a moment...</title></head><body><script src="https://challenges.cloudflare.com/x.js"></script></body></html>';
check('cloudflare interstitial detected', MetaHost::is_challenge_page($cf) === true);
check('…and parses to EMPTY, not "Just a moment..."', MetaHost::parse_head_tags($cf) === array('title' => '', 'description' => ''));
check('marker-based detection works without the English title',
    MetaHost::is_challenge_page('<html><head><title>Ett ogonblick</title></head><script>window.__cf_chl_opt={}</script>') === true);
check('a REAL page with a similar-ish title is NOT refused',
    MetaHost::is_challenge_page('<head><title>Just a moment of calm — massage in Goteborg</title></head>') === false);
check('normal pages pass the guard', MetaHost::is_challenge_page('<head><title>Massage</title></head>') === false);

echo "\n3. Both list paths are wired\n";
check('local list_content applies the fallback',
    preg_match('/return self::fill_effective_meta\(\s*\n\s*\$rows,/', $loc) === 1, 'local not wired');
check('…and passes the FREE cache pass, so a big site converges',
    strpos($loc, 'self::cached_page_html_only((int) ($row[\'id\'] ?? 0))') !== false, 'cache hits billed again');
check('local fetcher is the cached loopback', strpos($loc, 'self::cached_page_html((int) ($row[\'id\'] ?? 0))') !== false, 'wrong fetcher');
check('local cache keys on post_modified (edits invalidate naturally)',
    strpos($loc, "md5((string) \$post->post_modified_gmt)") !== false, 'stale cache on edit');
check('drafts are skipped locally (no public page to read)', strpos($loc, "post_status !== 'publish'") !== false, 'drafts fetched');
$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');
// Window widened: remote_list_content grew (featured-image resolution, post-type
// discovery, hub-side tag caching). A too-small window would make these pass or
// fail on truncation rather than on the code.
$rlc = substr($svc, strpos($svc, 'function remote_list_content'), 9000);
check('remote list calls the connector /head-tags route', strpos($rlc, "'/pcm-conn/v1/head-tags'") !== false, 'remote not wired');
check('remote fill touches EMPTY fields only',
    preg_match('/\(\$r\[.metaTitle.\] \?\? .{2}\) === .{2} && !empty\(\$t\[.title.\]\)/', $rlc) === 1, 'stored meta shadowed');
check('remote batch is still capped at 20 ids (what the connector answers)',
    strpos($rlc, 'array_slice($unseen, 0, 20)') !== false, 'unbounded batch');
check('…and it asks for ids NOT yet learned, so the table converges',
    strpos($rlc, '$unseen[] = $pid;') !== false, 'same first 20 re-requested every load');
check('an old connector (no route) fails silently', strpos($rlc, 'is_wp_error($res)') !== false, 'hard failure on old connector');
// The connector-LESS fallback (massagegoteborg.nu has NO pcm-conn namespace at
// all): the hub fetches public permalinks itself — publish-only, small cap,
// challenge-guarded, cached on the row's modified time.
$rlc2 = substr($svc, strpos($svc, 'function remote_list_content'), 12000);
check('hub-side public fallback exists for connector-less sites',
    strpos($rlc2, 'PCM_SEO_Local::fill_effective_meta(') !== false, 'no connector-less fallback');
check('it only fetches PUBLISHED rows',
    strpos($rlc2, "(\$row['status'] ?? '') !== 'publish'") !== false, 'drafts fetched cross-internet');
check('it refuses challenge pages instead of caching them',
    strpos($rlc2, 'PCM_SEO_Local::is_challenge_page($html)') !== false, 'interstitial cached as tags');
// Raised 10 -> 25 (2026-08-15): on a site whose connector predates /head-tags this
// reader is the ONLY way meta appears. The point is that it stays BOUNDED — a cap
// AND a wall-clock budget — not the exact number.
check('the cross-internet reader stays bounded (cap + time budget)',
    preg_match('/\s25,\s*6\.0,/', $rlc2) === 1, 'no cap');
check('cache key includes the modified time', strpos($rlc2, "\$row['modified']") !== false, 'stale cache on edit');
check('wp/v2 fields request modified', strpos($svc, "'id,title,slug,status,date,modified,link,author") !== false, 'modified not fetched');
check('remote_row passes modified through', strpos($svc, "(string) (\$item['modified'] ?? '')") !== false, 'row lacks modified');
$hub2 = file_get_contents($ROOT . '/includes/modules/seohub/service.php');
check('the CONNECTOR route carries the challenge guard too',
    strpos($hub2, 'cf-browser-verification') !== false, 'connector caches interstitials');

echo "\n4. The connector route ships (extracted template — hub lint never checks it)\n";
$hub = file_get_contents($ROOT . '/includes/modules/seohub/service.php');
$a = strpos($hub, "<<<'PHP'"); $b = strpos($hub, "\nPHP;", $a);
$tpl = substr($hub, $a + 9, $b - $a - 9);
check('/head-tags route registered IN THE TEMPLATE', strpos($tpl, "register_rest_route('pcm-conn/v1', '/head-tags'") !== false, 'route not in shipped connector');
$route = substr($tpl, strpos($tpl, "'/head-tags'"), 3200);
check('published posts only', strpos($route, "post_status !== 'publish'") !== false, 'drafts leak');
check('ids capped at 20 per call', strpos($route, ', 0, 20)') !== false, 'unbounded');
check('time budget bounds the call', strpos($route, '> 8.0) { break; }') !== false, 'unbounded time');
check('cache keys on post_modified', strpos($route, 'md5((string) $post->post_modified_gmt)') !== false, 'stale cache');
check('failures cache briefly, successes long', strpos($route, "HOUR_IN_SECONDS : WEEK_IN_SECONDS") !== false, 'no negative cache');
$tmp = tempnam(sys_get_temp_dir(), 'pcmconn') . '.php';
file_put_contents($tmp, str_replace(array('__PCM_CONN_UPDATE_URI__', '__PCM_CONN_VERSION__', '__PCM_HUB_URL__', '__PCM_CLIENT_ID__'), array('https://x', '0.0.0', 'https://hub', 'cid'), $tpl));
exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $lo, $lc);
@unlink($tmp);
check('the extracted connector template parses', $lc === 0, implode("\n", array_slice($lo, 0, 3)));

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
