<?php
/**
 * Link optimization — editable HTML column ("every column the user should be
 * able to edit manually and save. it should save it on that page").
 *
 * The popup's HTML cell is now click-to-edit: saving replaces the ENTIRE <a>
 * element in the page content, sanitized server-side so pasted markup can
 * never smuggle scripts/handlers into post content. Local + remote routes.
 *
 * sanitize_link_html and update_post_link_html are EXECUTED here with stubbed
 * WP functions (real kses is approximated by the stub only for tag stripping —
 * the structural single-<a> contract is what these pin).
 *
 * Run: php tests/standalone/seo_link_html_edit_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$ROOT = dirname(__DIR__, 2);

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

// ── WP stubs ─────────────────────────────────────────────────────────────────
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message; public $data;
        public function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->message = $m; $this->data = $d; }
        public function get_error_message() { return $this->message; }
    }
}
function __($s, $d = null) { return $s; }
// kses stub: strip disallowed tags + event-handler/js attrs (coarse, but structural).
function wp_kses($html, $allowed) {
    $html = preg_replace('/\son[a-z]+=["\'][^"\']*["\']/i', '', $html);      // onClick=…
    $html = preg_replace('/href=["\']\s*javascript:[^"\']*["\']/i', 'href=""', $html);
    return strip_tags($html, '<' . implode('><', array_keys($allowed)) . '>');
}
function esc_url_raw($u) { return preg_match('#^https?://#i', $u) ? $u : ''; }

$GLOBALS['posts'] = array();
$GLOBALS['updated'] = array();
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function wp_update_post($arr) { $GLOBALS['updated'][] = $arr; $GLOBALS['posts'][$arr['ID']]->post_content = $arr['post_content']; return $arr['ID']; }

// ── Host the real methods ────────────────────────────────────────────────────
$src = file_get_contents($ROOT . '/includes/modules/seo/local.php');
function slice_method(string $src, string $name): string {
    $i = strpos($src, "function {$name}(");
    $start = strrpos(substr($src, 0, (int)$i), 'public static');
    $j = strpos($src, "\n    public", (int)$i);  // next method at class indentation
    $fn = substr($src, (int)$start, (int)$j - (int)$start);
    if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
    return 'public static ' . preg_replace('/^public static\s+/', '', $fn);
}
$san  = slice_method($src, 'sanitize_link_html');
$upd  = slice_method($src, 'update_post_link_html');
check('both methods sliced', strlen($san) > 200 && strlen($upd) > 400, array(strlen($san), strlen($upd)));

// Host with scriptable collaborators; self:: calls resolve inside the host class.
eval('class LinkHost {
    public static $links = array();
    public static $meta_replaced = array();
    public static $purged = 0;
    public static $scanned = 0;
    public static function get_post_links($id) { return self::$links; }
    public static function nth_link_pos($content, $links, $index) {
        if (!isset($links[$index]["html"])) return null;
        $p = strpos($content, (string)$links[$index]["html"]);
        return $p === false ? null : $p;
    }
    public static function replace_url_in_meta($id, $old, $new) { self::$meta_replaced[] = array($old, $new); return 1; }
    public static function purge_post_caches($id) { self::$purged++; }
    public static function scan_links($id, $checks = true) { self::$scanned++; return array(); }
    ' . $san . "\n" . $upd . ' }');

echo "\n1. sanitize_link_html — EXECUTED (the security gate)\n";
$ok = LinkHost::sanitize_link_html('<a href="https://x.se/page">hamburgers</a>');
check('a clean anchor passes through', $ok === '<a href="https://x.se/page">hamburgers</a>', $ok);
$ok = LinkHost::sanitize_link_html('  <a href="https://x.se" rel="nofollow" target="_blank">text <strong>bold</strong></a>  ');
check('rel/target + inline formatting survive', is_string($ok) && strpos($ok, 'rel="nofollow"') !== false && strpos($ok, '<strong>') !== false, $ok);
// kses strips the <script> TAGS but leaves their text ("alert(1)") — the validator then
// rejects the whole edit because text surrounds the anchor. Rejection > silent truncation.
$r = LinkHost::sanitize_link_html('<script>alert(1)</script><a href="https://x.se">t</a>');
check('script around the anchor → rejected outright (never reaches content)', $r instanceof WP_Error, $r);
$r = LinkHost::sanitize_link_html('<a href="https://x.se" onclick="steal()">t</a>');
check('event handlers are stripped', is_string($r) && stripos($r, 'onclick') === false, $r);
$r = LinkHost::sanitize_link_html('plain text, no anchor');
check('non-anchor input → WP_Error', $r instanceof WP_Error, $r);
$r = LinkHost::sanitize_link_html('<a href="https://a.se">1</a><a href="https://b.se">2</a>');
check('TWO anchors → WP_Error (must be a single element)', $r instanceof WP_Error, $r);
$r = LinkHost::sanitize_link_html('before <a href="https://x.se">t</a> after');
check('text around the anchor → WP_Error', $r instanceof WP_Error, $r);
$r = LinkHost::sanitize_link_html('<a>no href</a>');
check('missing href → WP_Error', $r instanceof WP_Error, $r);
$r = LinkHost::sanitize_link_html('<div><a href="https://x.se">t</a></div>');
check('wrapping div is stripped by kses, anchor kept', $r === '<a href="https://x.se">t</a>', $r);

echo "\n2. update_post_link_html — EXECUTED (save it on that page)\n";
$OLD = '<a href="https://hamburgers.se/hamburgersaregreat">hamburgers</a>';
$NEW = '<a href="https://hamburgers.se/menu" rel="nofollow">great burgers</a>';
$reset = function () use ($OLD) {
    $GLOBALS['posts'][7] = (object) array('ID' => 7, 'post_content' => "<p>intro</p>\n{$OLD}\n<p>outro</p>");
    $GLOBALS['updated'] = array();
    LinkHost::$links = array(array('id' => 0, 'anchor' => 'hamburgers', 'from' => 'https://domain.com', 'to' => 'https://hamburgers.se/hamburgersaregreat', 'html' => $OLD, 'status' => 404, 'kind' => 'external', 'broken' => true, 'editable' => true));
    LinkHost::$meta_replaced = array();
    LinkHost::$purged = 0; LinkHost::$scanned = 0;
};
$reset();
$res = LinkHost::update_post_link_html(7, 0, $NEW);
check('edit succeeds (returns refreshed list, not WP_Error)', !($res instanceof WP_Error), $res);
check('the OLD element is gone from the page', strpos($GLOBALS['posts'][7]->post_content, $OLD) === false, $GLOBALS['posts'][7]->post_content);
check('the NEW element is on the page, in place', strpos($GLOBALS['posts'][7]->post_content, "<p>intro</p>\n{$NEW}\n<p>outro</p>") === 0, $GLOBALS['posts'][7]->post_content);
check('href change propagated to builder/custom-field meta', LinkHost::$meta_replaced === array(array('https://hamburgers.se/hamburgersaregreat', 'https://hamburgers.se/menu')), LinkHost::$meta_replaced);
check('caches purged + fast re-scan ran', LinkHost::$purged === 1 && LinkHost::$scanned === 1, array(LinkHost::$purged, LinkHost::$scanned));

$reset();
$res = LinkHost::update_post_link_html(7, 0, '<a href="https://hamburgers.se/hamburgersaregreat">renamed anchor</a>');
check('anchor-only html edit: page updated, NO meta url replace', !($res instanceof WP_Error) && LinkHost::$meta_replaced === array() && strpos($GLOBALS['posts'][7]->post_content, 'renamed anchor') !== false, LinkHost::$meta_replaced);

$reset();
$res = LinkHost::update_post_link_html(7, 0, 'garbage');
check('invalid html → WP_Error, page untouched', $res instanceof WP_Error && $GLOBALS['updated'] === array(), $res);
$reset();
$res = LinkHost::update_post_link_html(7, 5, $NEW);
check('unknown index → WP_Error', $res instanceof WP_Error, $res);
$reset();
$res = LinkHost::update_post_link_html(7, 0, $OLD);
check('unchanged html → no-op (no save)', !($res instanceof WP_Error) && $GLOBALS['updated'] === array(), $GLOBALS['updated']);
$reset();
$GLOBALS['posts'][7]->post_content = '<p>the page changed since the scan</p>';
$res = LinkHost::update_post_link_html(7, 0, $NEW);
check('stale scan (element no longer on page) → 409-style WP_Error, no save', $res instanceof WP_Error && $GLOBALS['updated'] === array(), $res);

echo "\n3. Routes + handlers (local and remote, ownership-scoped)\n";
$ctl = file_get_contents($ROOT . '/includes/modules/seo/controller.php');
check('local route registered', strpos($ctl, "'/seo/content/(?P<id>\\d+)/links/(?P<idx>\\d+)/html', 'update_link_html'") !== false);
check('remote route registered (manage_options)', strpos($ctl, "'/seo/sites/(?P<id>\\d+)/content/(?P<post>\\d+)/links/(?P<idx>\\d+)/html', 'remote_update_link_html', array(), 'manage_options'") !== false);
check('local handler gates on edit_post', preg_match('/function update_link_html\([\s\S]{0,400}?current_user_can\(\'edit_post\', \$id\)/', $ctl) === 1, 'cap check missing');
check('remote handler ownership-scopes the site', preg_match('/function remote_update_link_html\([\s\S]{0,300}?PCM_DB::get_site\(absint\(\$request->get_param\(\'id\'\)\), \(int\) \$user->id\)/', $ctl) === 1, 'any site id would do');

$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');
check('remote path sanitizes through the SAME validator', preg_match('/function remote_update_link_html\([\s\S]{0,300}?PCM_SEO_Local::sanitize_link_html/', $svc) === 1, 'separate/no sanitizer');
check('remote path reuses the verified rewrite machinery', preg_match('/function remote_update_link_html\([\s\S]{0,900}?remote_rewrite_link_content/', $svc) === 1, 'bespoke write path');

$routes = file_get_contents($ROOT . '/app/src/lib/trpc-routes.ts');
check('tRPC routes exist', strpos($routes, '"seo.updateLinkHtml"') !== false && strpos($routes, '"seo.remoteUpdateLinkHtml"') !== false);

echo "\n4. The popup's HTML cell is editable (body links), read-only for builder links\n";
$ui = file_get_contents($ROOT . '/app/src/modules/SEO/LinksPopup.tsx');
check('HTML cell renders EditableTextCell for editable body links',
    preg_match('/editable && !l\.elId \? \([\s\S]{0,300}?EditableTextCell value=\{l\.html\} onSave=\{\(v\) => saveHtml\(l, v\)\}/', $ui) === 1, 'html cell not editable');
check('builder links stay read-only with an explanation',
    strpos($ui, 'edit its Anchor or To instead') !== false, 'no builder explanation');
check('client pre-validates the single-<a> shape', strpos($ui, "/^<a\\s/i.test(val.trim())") !== false, 'no client validation');
check('edited link stays visible across the re-scan (justEditedTo)',
    preg_match('/const saveHtml[\s\S]{0,1200}?setJustEditedTo\(/', $ui) === 1, 'edit vanishes from filtered view');
check('cache expectation set on success', preg_match('/Link HTML saved[\s\S]{0,120}?CDN cache/', $ui) === 1, 'no cache note');

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
