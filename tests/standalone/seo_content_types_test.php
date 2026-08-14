<?php
/**
 * SEO table — "not pulling pages in a subfolder / sub-subpage".
 *
 * ROOT CAUSE, proven live against massagegoteborg.nu's public REST API:
 * /services/lymphatic-drainage/ — the site's best-performing URL in Search
 * Console — is NOT a page. The site registers a `services` custom post type
 * (plus `cmsms_doctor`), and the table only ever asked for post + page, so
 * those rows could never appear. From outside they look exactly like pages in
 * a subfolder, which is how the card describes them.
 *
 * Now: both sides list EVERY public content type. remote_content_types() and
 * remote_route() are EXECUTED here against the REAL /wp/v2/types payload shape
 * captured from that site (including its Elementor/system types, which must
 * stay out), with scripted HTTP.
 *
 * Run: php tests/standalone/seo_content_types_test.php
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
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
$GLOBALS['transients'] = array();
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }

$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');

// Host the three real methods + the real NON_CONTENT_TYPES constant.
$slice = static function (string $src, string $name): string {
    $i = strpos($src, "function {$name}(");
    $start = strrpos(substr($src, 0, (int)$i), 'public static');
    $j = strpos($src, "\n    /**", (int)$i);
    $fn = substr($src, (int)$start, (int)$j - (int)$start);
    if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
    return $fn;
};
preg_match('/public const NON_CONTENT_TYPES = \[[\s\S]*?\];/', $svc, $cm);
check('NON_CONTENT_TYPES constant found', !empty($cm[0]));
$body = $cm[0] . "\n"
    . $slice($svc, 'remote_content_types') . "\n"
    . $slice($svc, 'flush_remote_content_types') . "\n"
    . $slice($svc, 'remote_route');
$body = str_replace(array('self::ensure_sites_service();', 'PCM_Sites_Service::remote_rest'), array('', 'self::remote_rest'), $body);
$body = str_replace('self::NON_CONTENT_TYPES', 'self::NON_CONTENT_TYPES', $body);
eval('class TypesHost {
    public static $reply = null; public static $calls = array();
    public static function remote_rest($site, $method, $route, $q = array(), $b = null, $t = 30) {
        self::$calls[] = array($route, $q);
        return is_callable(self::$reply) ? (self::$reply)($q) : self::$reply;
    }
    ' . $body . ' }');

$site = (object) array('id' => 7, 'url' => 'https://massagegoteborg.nu');

// The REAL shape returned by massagegoteborg.nu/wp-json/wp/v2/types (probed live).
$REAL_TYPES = array(
    'post'              => array('slug' => 'post', 'rest_base' => 'posts', 'hierarchical' => false),
    'page'              => array('slug' => 'page', 'rest_base' => 'pages', 'hierarchical' => true),
    'attachment'        => array('slug' => 'attachment', 'rest_base' => 'media'),
    'nav_menu_item'     => array('slug' => 'nav_menu_item', 'rest_base' => 'menu-items'),
    'wp_block'          => array('slug' => 'wp_block', 'rest_base' => 'blocks'),
    'wp_template'       => array('slug' => 'wp_template', 'rest_base' => 'templates'),
    'wp_template_part'  => array('slug' => 'wp_template_part', 'rest_base' => 'template-parts'),
    'wp_global_styles'  => array('slug' => 'wp_global_styles', 'rest_base' => 'global-styles'),
    'wp_navigation'     => array('slug' => 'wp_navigation', 'rest_base' => 'navigation'),
    'wp_font_family'    => array('slug' => 'wp_font_family', 'rest_base' => 'font-families'),
    'wp_font_face'      => array('slug' => 'wp_font_face', 'rest_base' => 'font-families/(?P<font_family_id>[\d]+)/font-faces'),
    'e-floating-buttons' => array('slug' => 'e-floating-buttons', 'rest_base' => 'e-floating-buttons'),
    'elementor_library' => array('slug' => 'elementor_library', 'rest_base' => 'elementor_library'),
    'services'          => array('slug' => 'services', 'rest_base' => 'services'),
    'cmsms_doctor'      => array('slug' => 'cmsms_doctor', 'rest_base' => 'cmsms_doctor'),
    // A NESTED route that is not on the denylist — only the regex guard can exclude it.
    // (wp_font_face above is denylisted too, so it cannot prove that guard on its own.)
    'book_chapter'      => array('slug' => 'book_chapter', 'rest_base' => 'books/(?P<book_id>[\d]+)/chapters'),
);
$reset = static function () { $GLOBALS['transients'] = array(); TypesHost::$calls = array(); };

echo "\n1. The reported bug — the site's REAL types (probed live) are discovered\n";
$reset();
TypesHost::$reply = array('status' => 200, 'body' => $REAL_TYPES);
$types = TypesHost::remote_content_types($site);
check('`services` is listed — /services/lymphatic-drainage/ can finally appear',
    ($types['services'] ?? null) === 'services', $types);
check('`cmsms_doctor` (Our Doctors) is listed too', ($types['cmsms_doctor'] ?? null) === 'cmsms_doctor', $types);
check('post + page still listed', ($types['post'] ?? '') === 'posts' && ($types['page'] ?? '') === 'pages', $types);

echo "\n2. …and the noise stays out\n";
foreach (array('attachment', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part',
               'wp_global_styles', 'wp_navigation', 'wp_font_family', 'elementor_library',
               'e-floating-buttons') as $junk) {
    check("$junk excluded", !isset($types[$junk]), $types);
}
check('a regex rest_base (wp_font_face) is refused — it is not a listable route',
    !isset($types['wp_font_face']), $types);
check('a NON-denylisted nested route is refused too (the regex guard, on its own)',
    !isset($types['book_chapter']), $types);

echo "\n3. Route resolution — the ONE place a type becomes a URL\n";
check('custom type → its own REST base', TypesHost::remote_route($site, 'services') === '/wp/v2/services', TypesHost::remote_route($site, 'services'));
check('custom type + id (edits hit the right endpoint)',
    TypesHost::remote_route($site, 'services', 28837) === '/wp/v2/services/28837', TypesHost::remote_route($site, 'services', 28837));
check('page → /wp/v2/pages (unchanged)', TypesHost::remote_route($site, 'page', 12) === '/wp/v2/pages/12');
check('post → /wp/v2/posts (unchanged)', TypesHost::remote_route($site, 'post', 12) === '/wp/v2/posts/12');
check('an UNKNOWN type falls back to posts — never a nonsense route',
    TypesHost::remote_route($site, 'ghost_type', 5) === '/wp/v2/posts/5', TypesHost::remote_route($site, 'ghost_type', 5));

echo "\n4. Caching + degradation (never an empty table)\n";
$reset();
TypesHost::$reply = array('status' => 200, 'body' => $REAL_TYPES);
TypesHost::remote_content_types($site);
$after_first = count(TypesHost::$calls);
TypesHost::remote_content_types($site);
TypesHost::remote_content_types($site);
check('discovery is cached per site (one lookup, not one per call)', count(TypesHost::$calls) === $after_first, TypesHost::$calls);
TypesHost::flush_remote_content_types(7);
TypesHost::remote_content_types($site);
check('flush forces a fresh lookup', count(TypesHost::$calls) > $after_first);

$reset();
TypesHost::$reply = new WP_Error();
$types = TypesHost::remote_content_types($site);
check('unreachable /types → degrades to post+page (old behaviour, not empty)',
    $types === array('post' => 'posts', 'page' => 'pages'), $types);
check('a failed lookup is NOT cached (it retries next time)', $GLOBALS['transients'] === array(), $GLOBALS['transients']);
$reset();
TypesHost::$reply = array('status' => 401, 'body' => array());
check('403/401 on /types → same safe degradation',
    TypesHost::remote_content_types($site) === array('post' => 'posts', 'page' => 'pages'));

$reset();
TypesHost::$reply = static function ($q) {
    // context=edit refused; the public view context answers — and exposes `viewable`.
    if (($q['context'] ?? '') === 'edit') { return array('status' => 403, 'body' => array()); }
    return array('status' => 200, 'body' => array(
        'post'     => array('rest_base' => 'posts'),
        'services' => array('rest_base' => 'services', 'viewable' => true),
        'hidden'   => array('rest_base' => 'hidden', 'viewable' => false),
    ));
};
$types = TypesHost::remote_content_types($site);
check('falls back to the view context when edit is refused', isset($types['services']), $types);
check('a NON-viewable type is refused when the site says so', !isset($types['hidden']), $types);

echo "\n5. The lists actually use it (both sides)\n";
check('remote list iterates the discovered types, not a hardcoded pair',
    preg_match('/foreach \(self::remote_content_types\(\$site\) as \$type => \$base\)/', $svc) === 1, 'still hardcoded');
check('no hardcoded page/post route ternaries remain',
    strpos($svc, "\$type === 'page' ? '/wp/v2/pages/'") === false, 'ternaries survive');
check('every remote op resolves through remote_route',
    substr_count($svc, 'self::remote_route($site') >= 11, substr_count($svc, 'self::remote_route($site'));
$loc = file_get_contents($ROOT . '/includes/modules/seo/local.php');
check('local side has the same discovery', strpos($loc, "get_post_types(array('public' => true), 'names')") !== false);
check('local excludes the same non-content types', strpos($loc, 'PCM_SEO_Service::NON_CONTENT_TYPES') !== false);
check('local list allows every discovered type', strpos($loc, '$allowed = self::content_types();') !== false);
$ctl = file_get_contents($ROOT . '/includes/modules/seo/controller.php');
check('the content route defaults to all types', strpos($ctl, ': PCM_SEO_Local::content_types();') !== false);
$edit = file_get_contents($ROOT . '/includes/modules/seo/editing.php');
check('the options payload advertises them (Type filter)', strpos($edit, "'types'      => PCM_SEO_Local::content_types(),") !== false);

echo "\n6. The client passes the REAL type through (so edits hit the right route)\n";
$ui = file_get_contents($ROOT . '/app/src/modules/SEO/index.tsx');
check('links popup gets the row type', strpos($ui, "const linkType: string = row.type || 'post';") !== false, 'still coerced');
check('headings panel gets the row type', strpos($ui, 'type={row.type || \'post\'}') !== false);
check('page editor gets the row type', strpos($ui, "type={pageEditRow.type || 'post'}") !== false);
check('no post/page coercion left in the table', strpos($ui, "row.type === 'page' ? 'page' : 'post'") === false, 'coercion survives');
foreach (array('LinksPopup', 'HeadingsPanel', 'SectionModal') as $comp) {
    $c = file_get_contents($ROOT . '/app/src/modules/SEO/' . $comp . '.tsx');
    // \r?$ — these files are CRLF, and a bare $ would sit before the CR and never match.
    check("$comp accepts any content type", preg_match('/^[ \t]*type: string;[ \t]*\r?$/m', $c) === 1, $comp);
}
$flt = file_get_contents($ROOT . '/app/src/modules/SEO/seoFilters.ts');
check('the Type filter offers types found in the rows (remote custom types)',
    strpos($flt, 'rowTypes: string[] = []') !== false && strpos($flt, '...rowTypes') !== false);
check('index feeds the row types in', strpos($ui, 'buildFilterDefs(options, rowTypes)') !== false);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
