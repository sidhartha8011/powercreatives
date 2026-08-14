<?php
/**
 * SEO table — "it is not reading the meta tags / the meta tags is not shown in
 * the table" (Filip, on brizy.profitmedia.pro: 63 rows, meta columns blank).
 *
 * The effective-meta fallback existed, but it could NEVER finish a table bigger
 * than its own cap — two independent reasons, both fixed and pinned here:
 *
 *   1. fill_effective_meta counted CACHE HITS against its fetch cap. A row whose
 *      page was already read still consumed one of the 10/20 slots, so every load
 *      re-spent the whole budget on the same first N rows and rows N+1.. stayed
 *      blank forever.
 *   2. The connector path asked for "the first 20 ids that need meta" on EVERY
 *      load and cached nothing hub-side, so ids 21+ were never requested at all.
 *
 * fill_effective_meta is EXECUTED here across REPEATED loads (the thing the bug
 * was about) with a scripted fetcher that counts network calls.
 *
 * Run: php tests/standalone/seo_meta_fill_convergence_test.php
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

// WP functions the REAL parser calls.
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function wp_specialchars_decode($s, $q = null) { return html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function html_entity_decode_wp($s) { return html_entity_decode((string) $s); }

$loc = file_get_contents($ROOT . '/includes/modules/seo/local.php');
$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');

// ── Host the REAL fill_effective_meta + parse_head_tags ─────────────────────
$slice = static function (string $src, string $name): string {
    $i = strpos($src, "function {$name}(");
    $start = strrpos(substr($src, 0, (int)$i), 'public static');
    $j = strpos($src, "\n    /**", (int)$i);
    $fn = substr($src, (int)$start, (int)$j - (int)$start);
    if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
    return $fn;
};
// parse_head_tags leans on a private helper — take the whole trio verbatim.
$priv = static function (string $src, string $name): string {
    $i = strpos($src, "function {$name}(");
    $start = strrpos(substr($src, 0, (int)$i), 'private static');
    $j = strpos($src, "\n    /**", (int)$i);
    $fn = substr($src, (int)$start, (int)$j - (int)$start);
    if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
    return str_replace('private static', 'public static', $fn);
};
eval('class FillHost { ' . $slice($loc, 'fill_effective_meta') . "\n" . $slice($loc, 'parse_head_tags') . "\n"
    . $slice($loc, 'is_challenge_page') . "\n" . $priv($loc, 'head_meta_content') . ' }');
check('fill_effective_meta + parser hosted', method_exists('FillHost', 'fill_effective_meta') && method_exists('FillHost', 'parse_head_tags'));

// 63 rows, like the reported site. All published, none with stored meta.
$make_rows = static function (int $n = 63): array {
    $rows = array();
    for ($i = 1; $i <= $n; $i++) {
        $rows[] = array('id' => $i, 'status' => 'publish', 'permalink' => "https://brizy.test/p{$i}/",
                        'modified' => '2026-08-01 10:00:00', 'metaTitle' => '', 'metaDescription' => '');
    }
    return $rows;
};
$page = static fn(int $i) => "<html><head><title>Post {$i} — Brizy</title>"
    . "<meta name=\"description\" content=\"Description for post {$i}.\"></head><body>x</body></html>";

// A site-wide store standing in for the transient cache; $calls counts real fetches.
$store = array();
$calls = 0;
$fetch = static function (array $row) use (&$store, &$calls, $page) {
    $key = 'h' . $row['id'];
    if (isset($store[$key])) { return $store[$key]; }   // the fetcher's own cache branch
    $calls++;
    $store[$key] = $page((int) $row['id']);
    return $store[$key];
};
$cache_only = static function (array $row) use (&$store) {
    return $store['h' . $row['id']] ?? null;            // free lookup, no network
};
$filled = static fn(array $rows) => count(array_filter($rows, static fn($r) => $r['metaTitle'] !== ''));

echo "\n1. The bug: repeated loads used to make NO progress\n";
// Reproduce the old behaviour exactly: no cache-only pass, so cache hits are billed.
$store = array(); $calls = 0;
$rows = $make_rows();
$rows = FillHost::fill_effective_meta($rows, $fetch, 10, 999.0);           // load 1
$after1 = $filled($rows);
$rows = FillHost::fill_effective_meta($make_rows(), $fetch, 10, 999.0);    // load 2
$after2 = $filled($rows);
check('OLD shape: load 1 fills exactly the cap', $after1 === 10, $after1);
check('OLD shape: load 2 fills the SAME rows — no progress (the reported bug)',
    $after2 === 10 && $rows[10]['metaTitle'] === '', array($after2, $rows[10]));

echo "\n2. The fix: each load fills what is known FREE and learns more\n";
$store = array(); $calls = 0;
$rows = FillHost::fill_effective_meta($make_rows(), $fetch, 10, 999.0, $cache_only);
check('load 1 fills 10 (the fetch cap) and costs 10 fetches', $filled($rows) === 10 && $calls === 10, array($filled($rows), $calls));
$rows = FillHost::fill_effective_meta($make_rows(), $fetch, 10, 999.0, $cache_only);
check('load 2 fills 20 — the first 10 free, 10 newly learned', $filled($rows) === 20, $filled($rows));
check('…and only 10 more fetches were spent', $calls === 20, $calls);
for ($n = 0; $n < 5; $n++) { $rows = FillHost::fill_effective_meta($make_rows(), $fetch, 10, 999.0, $cache_only); }
check('the table CONVERGES — all 63 rows filled', $filled($rows) === 63, $filled($rows));
check('a warm table costs ZERO fetches', (static function () use (&$calls, $fetch, $cache_only, $make_rows, $filled) {
    $before = $calls;
    $rows = FillHost::fill_effective_meta($make_rows(), $fetch, 10, 999.0, $cache_only);
    return $calls === $before && $filled($rows) === 63;
})(), $calls);
check('every row got the RIGHT page (no cross-wiring)',
    $rows[0]['metaTitle'] === 'Post 1 — Brizy' && $rows[62]['metaTitle'] === 'Post 63 — Brizy'
    && $rows[62]['metaDescription'] === 'Description for post 63.', array($rows[0], $rows[62]));

echo "\n3. The budget still bounds the WORK, not the filling\n";
$store = array(); $calls = 0;
$rows = FillHost::fill_effective_meta($make_rows(), $fetch, 5, 999.0, $cache_only);
check('a tight cap still limits fetches per load', $calls === 5, $calls);
// An exhausted time budget must not stop free cache fills — including a cached row
// that sits BELOW an uncached one. (With every cached row first, `break` and
// `continue` behave identically and the difference is invisible; row 10 is what
// makes this falsifiable.)
$store['h10'] = $page(10);
$warm  = $store;                      // ids 1-5 and 10 already known
$calls = 0;
$never = static function (array $row) use (&$calls) { $calls++; return null; };
$rows  = FillHost::fill_effective_meta($make_rows(), $never, 50, -1.0, $cache_only);
check('an exhausted time budget still fills every CACHED row — even below an uncached one',
    $filled($rows) === count($warm) && $rows[9]['metaTitle'] === 'Post 10 — Brizy',
    array('filled' => $filled($rows), 'expected' => count($warm), 'row10' => $rows[9]['metaTitle']));
check('…and spends nothing on the network', $calls === 0, $calls);

echo "\n4. Rows that legitimately have nothing stay empty (honest)\n";
$store = array(); $calls = 0;
$blank = static function (array $row) use (&$store, &$calls) {
    $calls++; $store['h' . $row['id']] = '<html><head><title></title></head></html>'; return $store['h' . $row['id']];
};
$rows = FillHost::fill_effective_meta($make_rows(3), $blank, 10, 999.0, $cache_only);
check('a page with no tags leaves the cell empty — never invented', $filled($rows) === 0, $rows[0]);
// Stored meta always wins. Fresh store — the blank pages above are cached for
// these same ids, and reusing them would prove nothing about the fill.
$store = array(); $calls = 0;
$rows = $make_rows(2);
$rows[0]['metaTitle'] = 'Author wrote this';
$rows = FillHost::fill_effective_meta($rows, $fetch, 10, 999.0, $cache_only);
check('a STORED title is never overwritten by the rendered one', $rows[0]['metaTitle'] === 'Author wrote this', $rows[0]);
check('…while its empty description is still filled', $rows[0]['metaDescription'] !== '', $rows[0]);

echo "\n5. Both callers pass the free cache pass\n";
check('local list_content passes cached_page_html_only',
    preg_match('/fill_effective_meta\([\s\S]{0,700}?cached_page_html_only\(/', $loc) === 1, 'local still bills cache hits');
check('a cache-ONLY helper exists and never fetches',
    preg_match('/function cached_page_html_only[\s\S]{0,600}?return \(is_string\(\$cached\) && \$cached !== \'\'\) \? \$cached : null;/', $loc) === 1
    && strpos($slice($loc, 'cached_page_html_only'), 'wp_remote_get') === false, 'it can hit the network');
check('the remote connector-less fallback passes one too',
    strpos($svc, "get_transient('pcm_seo_rhead_' . md5((string) \$row['permalink']") !== false, 'remote still bills cache hits');

echo "\n6. The connector path ROTATES instead of re-asking for the same 20\n";
check('learned tags are cached hub-side per site+post+modified',
    strpos($svc, "'pcm_seo_rtags_' . (int) (\$site->id ?? 0) . '_' . \$post_id . '_'") !== false, 'nothing cached');
check('only ids never resolved are requested', preg_match('/\$unseen\[\] = \$pid;[\s\S]{0,600}?array_slice\(\$unseen, 0, 20\)/', $svc) === 1, 'still slices $need');
check('known ids are served from cache with no request',
    preg_match('/\$hit = get_transient\(\$tag_key\(\$pid\)\);\s*\n\s*if \(is_array\(\$hit\)\) \{/', $svc) === 1);
check('an EMPTY answer is cached too (no re-fetch storm), on a short TTL',
    preg_match('/\$has \?  ?WEEK_IN_SECONDS : HOUR_IN_SECONDS/', $svc) === 1, 'empties re-fetched forever');
check('the connector batch size still matches what it will answer (20)',
    strpos($svc, 'array_slice($unseen, 0, 20)') !== false
    && strpos(file_get_contents($ROOT . '/includes/modules/seohub/service.php'), "explode(',', (string) \$req->get_param('post_ids')))), 0, 20)") !== false);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
