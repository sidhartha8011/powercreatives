<?php
/**
 * "Remove all dead links said it removed them — the table is exactly the same"
 * (Filip, bokatandlakartid.se, 27 dead webp-image links).
 *
 * Two defects, both pinned here:
 *
 *  1. INDEX-SPACE MISMATCH: the popup numbers its rows from the CONNECTOR's
 *     builder-aware scan (content + all meta), but remove/rel/delete resolved
 *     that index against a DIFFERENT list (content.raw only). With extra
 *     meta-harvested links in the popup, every index pointed at the wrong raw
 *     link — or past the end — so removals failed (or hit the wrong link).
 *     The three ops now carry the link's IDENTITY (html / to / anchor) and the
 *     server resolves it within ITS OWN scan; a link absent from the editable
 *     content gets an honest "lives in plugin/builder data" 422.
 *
 *  2. THE UI LIED: the bulk loop swallowed every failure and toasted success
 *     regardless (structural checks below; the honest summary is pinned).
 *
 * locate_link_index + remote_rewrite_link_content are EXECUTED with scripted
 * HTTP; the fixture reproduces the popup-vs-raw index mismatch.
 *
 * Run: php tests/standalone/seo_link_remove_identity_test.php
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

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message; public $data;
        public function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->message = $m; $this->data = $d; }
        public function get_error_message() { return $this->message; }
    }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function __($s, $d = null) { return $s; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function esc_url_raw($u) { return $u; }
function home_url($p = '/') { return 'https://hub.test' . $p; }
function esc_url($u) { return $u; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }

$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');
$loc = file_get_contents($ROOT . '/includes/modules/seo/local.php');

$slice = static function (string $src, string $name, string $vis = 'public static'): string {
    $i = strpos($src, "function {$name}(");
    $p1 = strrpos(substr($src, 0, (int)$i), 'private static');
    $p2 = strrpos(substr($src, 0, (int)$i), 'public static');
    $start = max($p1 === false ? -1 : $p1, $p2 === false ? -1 : $p2);
    $j = strpos($src, "\n    /**", (int)$i);
    $fn = substr($src, (int)$start, (int)$j - (int)$start);
    if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
    return str_replace('private static', 'public static', $fn);
};

// PCM_SEO_Local pieces the rewrite leans on — the REAL scanner + position finder.
eval('class PCM_SEO_Local { '
    . $slice($loc, 'scan_link_details') . "\n"
    . $slice($loc, 'nth_link_pos') . "\n"
    . ' public static function toggle_nofollow_html($h, $n) { return $h; } }');

// Host the rewrite + locator with scripted HTTP. remote_replace/connector calls are
// out of scope here (no URL change → $rendered_needle stays null on remove).
$body = $slice($svc, 'locate_link_index') . "\n" . $slice($svc, 'remote_rewrite_link_content') . "\n" . $slice($svc, 'remote_remove_link');
$body = str_replace(
    array('self::ensure_sites_service();', 'PCM_Sites_Service::remote_rest', 'self::remote_connector_version($site)'),
    array('', 'self::remote_rest', "''"),
    $body
);
// The route resolver is content-type discovery (tested elsewhere) — stub it flat.
$body = str_replace('self::remote_route($site, $type, (int) $post_id)', "'/wp/v2/posts/' . (int) \$post_id", $body);
$body = str_replace('self::remote_get_links($site, $post_id, $type, false)', 'array()', $body);
eval('class RewriteHost {
    public static $script = array();
    public static $calls = array();
    public static function remote_rest($site, $method, $route, $q = array(), $b = null, $t = 30) {
        self::$calls[] = array($method, $route, $q, $b);
        return array_shift(self::$script);
    }
    ' . $body . ' }');

$site = (object) array('id' => 4, 'url' => 'https://bokatandlakartid.se');
// The page's RAW content: TWO real links. The popup, however, listed FIVE (the
// connector also harvested three plugin-meta image links) — so popup index 3
// points nowhere useful in this list.
$RAW = '<p>Hej <a href="https://bokatandlakartid.se/boka/">Boka tid</a> och '
     . '<a href="https://old.example/img7.webp">gammal bild</a> slut.</p>';
$read_ok = static fn() => array('status' => 200, 'body' => array('content' => array('raw' => $GLOBALS['RAW_NOW']), 'link' => 'https://bokatandlakartid.se/sida/'));
$GLOBALS['RAW_NOW'] = $RAW;

echo "\n1. locate_link_index — EXECUTED\n";
$links = PCM_SEO_Local::scan_link_details($RAW, 'https://bokatandlakartid.se/sida/', 'https://bokatandlakartid.se', false);
check('the raw scan sees exactly the two real links', count($links) === 2, $links);
check('exact html wins', RewriteHost::locate_link_index($links, array('html' => (string) $links[1]['html'])) === 1);
check('to+anchor works when html differs (connector-synthesized markup)',
    RewriteHost::locate_link_index($links, array('html' => '<a href="https://old.example/img7.webp">gammal bild</a>x', 'to' => 'https://old.example/img7.webp', 'anchor' => 'gammal bild')) === 1);
check('a unique to alone is enough', RewriteHost::locate_link_index($links, array('to' => 'https://bokatandlakartid.se/boka/')) === 0);
check('a plugin-meta link (not in the editable content) resolves to NULL',
    RewriteHost::locate_link_index($links, array('html' => '<a href="https://x/wp-content/plugins/webp/a1.webp">(no text)</a>', 'to' => 'https://x/wp-content/plugins/webp/a1.webp', 'anchor' => '')) === null);
// AMBIGUITY REFUSAL: two links share a `to`; a to-only locate must return NULL —
// guessing "the first one" would edit a link the user never touched.
$dup = PCM_SEO_Local::scan_link_details(
    '<p><a href="https://same.example/x">First</a> <a href="https://same.example/x">Second</a></p>',
    'https://bokatandlakartid.se/sida/', 'https://bokatandlakartid.se', false
);
check('a to-only match over DUPLICATE targets refuses to guess',
    RewriteHost::locate_link_index($dup, array('to' => 'https://same.example/x', 'anchor' => 'Third')) === null,
    RewriteHost::locate_link_index($dup, array('to' => 'https://same.example/x', 'anchor' => 'Third')));
check('…but the anchor disambiguates the same pair',
    RewriteHost::locate_link_index($dup, array('to' => 'https://same.example/x', 'anchor' => 'Second')) === 1);

echo "\n2. The reported bug — popup index 3, raw list of 2\n";
RewriteHost::$script = array($read_ok());
$r = RewriteHost::remote_remove_link($site, 9, 'post', 3);   // OLD shape: index only
check('without identity the op still 404s (never silently succeeds)',
    $r instanceof WP_Error, $r);

RewriteHost::$script = array($read_ok());
$r = RewriteHost::remote_remove_link($site, 9, 'post', 3, array(
    'html' => '<a href="https://x/wp-content/plugins/webp/a1.webp">(no text)</a>',
    'to' => 'https://x/wp-content/plugins/webp/a1.webp', 'anchor' => '',
));
check('WITH identity, an outside-content link gets the honest 422',
    $r instanceof WP_Error && $r->code === 'pcm_seo_link_outside', $r);
check('…whose message says where it actually lives',
    $r instanceof WP_Error && strpos($r->message, 'plugin or builder data') !== false, $r->message ?? '');

echo "\n3. WITH identity, the RIGHT link is removed despite the wrong index\n";
$GLOBALS['RAW_NOW'] = $RAW;
$after = str_replace('<a href="https://old.example/img7.webp">gammal bild</a>', 'gammal bild', $RAW);
RewriteHost::$script = array(
    $read_ok(),                                                        // read raw
    array('status' => 200, 'body' => array('id' => 9)),                // save
    array('status' => 200, 'body' => array('content' => array('raw' => $after))), // verify
    array('status' => 200, 'body' => array('links' => array())),       // connector re-scan (refresh list)
    array('status' => 200, 'body' => array('link' => 'x')),            // permalink for refresh
);
$r = RewriteHost::remote_remove_link($site, 9, 'post', 0 /* WRONG index */, array(
    'html' => '<a href="https://old.example/img7.webp">gammal bild</a>',
    'to' => 'https://old.example/img7.webp', 'anchor' => 'gammal bild',
));
check('the op succeeds', !($r instanceof WP_Error), $r);
$saved = null;
foreach (RewriteHost::$calls as $c) {
    if ($c[0] === 'POST' && is_array($c[3]) && isset($c[3]['content'])) { $saved = (string) $c[3]['content']; }
}
check('the DEAD link was unwrapped — not the healthy one the index pointed at',
    is_string($saved) && strpos($saved, 'old.example') === false && strpos($saved, 'Boka tid</a>') !== false, $saved);

echo "\n4. The UI stopped lying (structural)\n";
$ui = file_get_contents($ROOT . '/app/src/modules/SEO/LinksPopup.tsx');
check('bulk removal counts outcomes', preg_match('/const removeLinks = async[\s\S]{0,400}?let ok = 0;\s*\r?\n\s*let failed = 0;/', $ui) === 1, 'failures still swallowed');
check('failures keep the server reason', strpos($ui, 'reason = err?.message || reason;') !== false);
check('the unconditional success toasts are GONE',
    strpos($ui, "toast.success('Removed all dead links')") === false && strpos($ui, "toast.success('Removed selected links')") === false, 'still lying');
check('the summary reports what actually happened',
    strpos($ui, 'could not be removed.') !== false && strpos($ui, 'None of the') !== false);
check('every remote link op sends the identity',
    substr_count($ui, 'html: l.html, to: l.to, anchor: l.anchor') >= 3
    && strpos($ui, 'html: target.html, to: target.to, anchor: target.anchor') !== false, 'index-only ops remain');
check('edits mark the popup dirty', preg_match('/const applyResult = \(res: any\) => \{\s*\r?\n\s*changedRef\.current = true;/', $ui) === 1);
check('closing after edits notifies the parent', strpos($ui, 'if (changedRef.current) { changedRef.current = false; onChanged?.(); }') !== false);
$idx = file_get_contents($ROOT . '/app/src/modules/SEO/index.tsx');
check('the parent rescans the row so the TABLE counts update',
    strpos($idx, 'onChanged={() => { void scanLinks(linksPopup.id); }}') !== false, 'table counts stay stale');
$ctl = file_get_contents($ROOT . '/includes/modules/seo/controller.php');
check('the controller forwards the identity on all three ops',
    substr_count($ctl, '$this->link_locate_from($params)') === 3, substr_count($ctl, '$this->link_locate_from($params)'));

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
