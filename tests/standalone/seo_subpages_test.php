<?php
/**
 * "The table should and needs to read subpages. By read, I mean it needs to
 * pull in and show the subpages." (Filip, 2026-08-15)
 *
 * Probed live first: massagegoteborg.nu has NINE pages, all parent=0 — its
 * "subpages" are the `services` / `cmsms_doctor` custom-type URLs, already
 * covered by the content-type discovery (seo_content_types_test.php). What
 * remained for the GENERAL case, both fixed and pinned here:
 *
 *   PULL IN — the remote list fetched ONE 100-row request per type and
 *   silently dropped the rest; on a page-heavy site the dropped rows are
 *   precisely the subpages. Now paginated (5 × 100), and the local WP_Query
 *   bound is raised to match (PER_TYPE 100 → 500).
 *
 *   SHOW — a subpage row displayed only its leaf slug ("lymphatic-drainage"),
 *   indistinguishable from a top-level page. The Slug cell now shows the
 *   permalink's path prefix (/services/) as a faint display-only hint; the
 *   editable value stays the leaf slug (what WordPress actually lets you edit).
 *
 * The pagination loop is EXECUTED here with a scripted proxy.
 *
 * Run: php tests/standalone/seo_subpages_test.php
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

if (!class_exists('WP_Error')) { class WP_Error { public function get_error_message() { return 'err'; } } }
function is_wp_error($x) { return $x instanceof WP_Error; }

$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');

// ── Host the pagination loop, EXECUTED against a scripted proxy ────────────
// Slice just the per-type fetch loop out of remote_list_content and run it with
// stub collaborators — the loop's stop conditions are the substance under test.
$a = strpos($svc, 'foreach ($routes as $type => $route) {');
$b = strpos($svc, '// Featured images the embed', (int) $a);
check('pagination loop located', $a !== false && $b !== false);
$loop = substr($svc, (int) $a, (int) $b - (int) $a);
$loop = str_replace(
    array('PCM_Sites_Service::remote_rest', 'self::remote_row($item, $type, $site)', 'self::PER_TYPE_PAGES'),
    array('PageHost::remote_rest', 'PageHost::row($item, $type)', 'PageHost::PAGES'),
    $loop
);
eval('class PageHost {
    const PAGES = 5;
    public static $responses = array();   // "type|page" => response
    public static $calls = array();
    public static function remote_rest($site, $method, $route, $q = array()) {
        $key = $route . "|" . (int) ($q["page"] ?? 1);
        self::$calls[] = $key;
        return self::$responses[$key] ?? array("status" => 400, "body" => array("code" => "rest_post_invalid_page_number"));
    }
    public static function row($item, $type) { return array("id" => (int) $item["id"], "type" => $type); }
    public static function run($routes) {
        $site = (object) array("id" => 1);
        $rows = array();
        $fields = "";
        ' . $loop . '
        return $rows;
    }
}');
$mk = static fn(int $from, int $n) => array('status' => 200, 'body' => array_map(
    static fn($i) => array('id' => $i), range($from, $from + $n - 1)
));

echo "\n1. PULL IN — pagination, EXECUTED\n";
PageHost::$responses = array(
    '/wp/v2/pages|1' => $mk(1, 100),
    '/wp/v2/pages|2' => $mk(101, 100),
    '/wp/v2/pages|3' => $mk(201, 34),
);
PageHost::$calls = array();
$rows = PageHost::run(array('page' => '/wp/v2/pages'));
check('234 pages → ALL 234 rows (the old list stopped at 100)', count($rows) === 234, count($rows));
check('three requests — the short page ends the walk without provoking a 400',
    PageHost::$calls === array('/wp/v2/pages|1', '/wp/v2/pages|2', '/wp/v2/pages|3'), PageHost::$calls);

PageHost::$responses = array('/wp/v2/pages|1' => $mk(1, 100), '/wp/v2/pages|2' => $mk(101, 100));
PageHost::$calls = array();
$rows = PageHost::run(array('page' => '/wp/v2/pages'));
check('an exact-multiple list ends on the 400 (WordPress\'s end-of-list answer)',
    count($rows) === 200 && count(PageHost::$calls) === 3, array(count($rows), PageHost::$calls));

PageHost::$responses = array('/wp/v2/pages|1' => $mk(1, 40));
PageHost::$calls = array();
$rows = PageHost::run(array('page' => '/wp/v2/pages'));
check('a small site costs exactly ONE request per type (unchanged)', count($rows) === 40 && count(PageHost::$calls) === 1, PageHost::$calls);

PageHost::$responses = array();
for ($p = 1; $p <= 9; $p++) { PageHost::$responses['/wp/v2/pages|' . $p] = $mk(($p - 1) * 100 + 1, 100); }
PageHost::$calls = array();
$rows = PageHost::run(array('page' => '/wp/v2/pages'));
check('a huge site is BOUNDED at 5 pages (500 rows), matching the local cap',
    count($rows) === 500 && count(PageHost::$calls) === 5, array(count($rows), count(PageHost::$calls)));

PageHost::$responses = array(
    '/wp/v2/services|1' => $mk(1, 3),
);
PageHost::$calls = array();
// The erroring type comes FIRST — with it last, "skip this type" and "abort the
// whole list" would produce identical rows and the law would be unfalsifiable.
$rows = PageHost::run(array('ghost' => '/wp/v2/ghost', 'services' => '/wp/v2/services'));
check('a type erroring on page 1 is skipped; LATER types still list (best-effort law)',
    count($rows) === 3 && $rows[0]['type'] === 'services', $rows);

echo "\n2. The caps agree everywhere\n";
check('PER_TYPE raised to 500', strpos($svc, 'public const PER_TYPE = 500;') !== false);
check('PER_TYPE_PAGES × 100 equals PER_TYPE', strpos($svc, 'public const PER_TYPE_PAGES = 5;') !== false);
$loc = file_get_contents($ROOT . '/includes/modules/seo/local.php');
check('the local WP_Query uses the same bound', strpos($loc, "'posts_per_page' => PCM_SEO_Service::PER_TYPE") !== false);
check('the remote loop honours the page bound', strpos($svc, '$page <= self::PER_TYPE_PAGES') !== false);

echo "\n3. SHOW — a subpage is recognizable in the Slug cell\n";
$ui = file_get_contents($ROOT . '/app/src/modules/SEO/index.tsx');
check('the path prefix is derived from the permalink the row already carries',
    preg_match('/const segs = new URL\(row\.permalink\)\.pathname\.split\(.\/.\)\.filter\(Boolean\);/', $ui) === 1, 'no derivation');
check('only rows with a REAL parent path get a prefix (leaf-only rows do not)',
    strpos($ui, "if (segs.length > 1) pathPrefix = '/' + segs.slice(0, -1).join('/') + '/';") !== false, 'prefix on everything');
check('the prefix is display-only; the EDITABLE value stays the leaf slug',
    preg_match('/\{pathPrefix\}[\s\S]{0,400}?<EditableCell\s[\s\S]{0,120}?value=\{row\.slug\}/', $ui) === 1, 'slug editing changed');
check('the hint explains itself', strpos($ui, 'This is a subpage — it lives under') !== false);
check('a malformed permalink (draft preview link) never crashes the cell',
    preg_match('/try \{\s*\n\s*if \(row\.permalink\) \{[\s\S]{0,300}?\} catch \{/', $ui) === 1, 'no guard');

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
