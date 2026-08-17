<?php
/**
 * Bugfix card 10 — "SEO table bugfixes" (bokatandlakartid.se, homepage, 27 dead links):
 *   "links to old images … we fixed it on the site … tried to rescan, but the scan
 *    still says the old thing. The scan is stale."
 *   "Remove all dead links … said it removed all … the table is exactly the same,
 *    still 27."
 *
 * PROVEN LIVE before touching code: the 27 targets are
 * https://bokatandlakartid.se/dental00/wp-content/plugins/elementor/assets/…
 * (Elementor's placeholder asset under an OLD install path), anchor "(no text)",
 * and the RENDERED homepage (REST content.rendered, 208 KB) contains ZERO
 * occurrences of "dental00", "placeholder.png" or "plugins/elementor". They are
 * image-control DEFAULTS sitting in _elementor_data — not links, not on the page.
 * The scan was not stale; it was reporting things nobody can clear from the page.
 *
 * Under test — the REAL connector scanner, extracted from its nowdoc and EXECUTED:
 *  A. MEDIA controls ({url,id,…}) are not links; Elementor's placeholder asset never is.
 *  B. Builder links must occur in the builder's LIVE render (Elementor render hooked in);
 *     no render → keep everything (visibility floor).
 *  C. Root-relative builder links ("/tandlakare" — the homepage's REAL links, invisible
 *     before while 60+ image refs were counted) are reported, absolutised to this site.
 *  D. Element-scoped URL edits accept the relative stored form the hub sends back absolute.
 *  E. The popup no longer tries to "unwrap" builder ELEMENTS (nothing to unwrap) and says so.
 *
 * Run: php tests/standalone/seo_link_scan_phantoms_test.php
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

// ── Extract + lint the connector template ───────────────────────────────────
$hub = file_get_contents($ROOT . '/includes/modules/seohub/service.php');
$a = strpos($hub, "<<<'PHP'"); $b = strpos($hub, "\nPHP;", (int)$a);
$tpl = substr($hub, (int)$a + 9, (int)$b - (int)$a - 9);
$baked = str_replace(array('__PCM_CONN_UPDATE_URI__', '__PCM_CONN_VERSION__', '__PCM_HUB_URL__', '__PCM_CLIENT_ID__'), array('https://x', '0.0.0', 'https://hub', 'cid'), $tpl);
$tmp = tempnam(sys_get_temp_dir(), 'pcmconn') . '.php'; file_put_contents($tmp, $baked);
exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $lint_out, $lint_code); @unlink($tmp);
echo "\n0. The edited connector template still parses\n";
check('php -l on the EXTRACTED template', $lint_code === 0, implode("\n", array_slice($lint_out, 0, 3)));

// ── Host the helpers + builder classes with WP stubs ────────────────────────
$s1 = strpos($tpl, 'function pcm_conn_json_variants'); $e1 = strpos($tpl, '// ── Universal builder-aware link replacement');
$s2 = strpos($tpl, 'interface PCM_Conn_Builder_Handler'); $e2 = strpos($tpl, 'function pcm_conn_builder_manager');
$helpers = substr($tpl, (int)$s1, (int)$e1 - (int)$s1); $classes = substr($tpl, (int)$s2, (int)$e2 - (int)$s2);
function maybe_unserialize($v) { if (is_string($v) && preg_match('/^[aOs]:\d/', $v)) { $u = @unserialize($v); if ($u !== false || $v === 'b:0;') { return $u; } } return $v; }
function maybe_serialize($v) { return (is_array($v) || is_object($v)) ? serialize($v) : $v; }
function wp_json_encode($v) { return json_encode($v); }
function wp_cache_delete($id, $group = '') { return true; }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function pcm_conn_purge_caches($id) {}
function do_action(...$args) {}
function delete_post_meta($id, $k) {}
function get_post_field($f, $id) { return $GLOBALS['post_content'] ?? ''; }
function wp_update_post($arr) { $GLOBALS['post_content'] = $arr['post_content'] ?? ''; return $arr['ID'] ?? 0; }
function get_post_meta($id, $key = '', $single = false) { return $GLOBALS['meta_raw'][$key] ?? ''; }
function home_url($p = '') { return 'https://bokatandlakartid.se' . $p; }
class WPDB_Stub {
    public $postmeta = 'wp_postmeta'; public $rows = array();
    public function prepare($q, ...$args) { return $q; }
    public function get_results($q) { return array_values(array_map(static fn($r) => clone $r, $this->rows)); }
    public function get_col($q) { $o = array(); foreach ($this->rows as $r) { $o[] = $r->meta_value; } return $o; }
    public function update($t, $data, $where) { $this->rows[$where['meta_id']]->meta_value = $data['meta_value']; return 1; }
}
$GLOBALS['wpdb'] = new WPDB_Stub();
// A stand-in Elementor: its frontend "renders" whatever the test says the page shows.
eval('namespace Elementor { class Plugin { public static $instance; public $frontend; public $files_manager; } class FilesStub { public function clear_cache() {} } class FrontendStub { public function get_builder_content_for_display($pid, $css = false) { return (string) ($GLOBALS["rendered"] ?? ""); } } }');
\Elementor\Plugin::$instance = new \Elementor\Plugin(); \Elementor\Plugin::$instance->frontend = new \Elementor\FrontendStub(); \Elementor\Plugin::$instance->files_manager = new \Elementor\FilesStub();
eval($helpers . "\n" . $classes);

// ── The fixture: the card's homepage in miniature ───────────────────────────
const PLACEHOLDER = 'https://bokatandlakartid.se/dental00/wp-content/plugins/elementor/assets/images/placeholder.png';
function elementor_data(): array {
    return array(array('id' => 'sec1', 'elType' => 'section', 'settings' => array(
        'background_image' => array('url' => PLACEHOLDER, 'id' => ''), // section bg DEFAULT — never rendered
    ), 'elements' => array(
        array('id' => 'img1', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array(
            'image' => array('url' => 'https://bokatandlakartid.se/wp-content/uploads/2025/03/dentist-768x512.jpg', 'id' => 501, 'size' => ''), // a real image — media, not a link
        )),
        array('id' => 'img2', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array(
            'image' => array('url' => PLACEHOLDER, 'id' => ''), // one of "the 27"
            '__dynamic__' => array('image' => '[elementor-tag id="x" name="post-featured-image"]'),
        )),
        array('id' => 'btn1', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array(
            'text' => 'Hitta tandläkare', 'link' => array('url' => '/tandlakare', 'is_external' => '', 'nofollow' => ''), // ROOT-RELATIVE real link
        )),
        array('id' => 'btn2', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array(
            'text' => 'Boka', 'link' => array('url' => 'https://bokatandlakartid.se/boka', 'is_external' => ''), // absolute real link
        )),
        array('id' => 'btn3', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array(
            'text' => 'Old promo', 'link' => array('url' => 'https://bokatandlakartid.se/dental00/kampanj', 'is_external' => ''), // link control that does NOT render (display condition)
        )),
        array('id' => 'txt1', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array(
            'editor' => '<p>Läs <a href="https://bokatandlakartid.se/om-oss">om oss</a> här.</p>',
        )),
        array('id' => 'ib1', 'elType' => 'widget', 'widgetType' => 'icon-box', 'settings' => array(
            'hover_image' => array('url' => PLACEHOLDER), // an id-LESS placeholder ref (odd/old shape) — still Elementor's own asset, never a link
        )),
        array('id' => 'vid1', 'elType' => 'widget', 'widgetType' => 'video', 'settings' => array(
            'hosted_url' => array('url' => 'https://bokatandlakartid.se/wp-content/uploads/clip.mp4', 'id' => 77), // media
        )),
    )));
}
function seed(): void {
    $GLOBALS['wpdb']->rows = array(3 => (object) array('meta_id' => 3, 'meta_key' => '_elementor_data', 'meta_value' => (string) json_encode(elementor_data())));
    $GLOBALS['meta_raw']   = array('_elementor_edit_mode' => 'builder', '_elementor_data' => (string) json_encode(elementor_data()));
    $GLOBALS['post_content'] = '<p>compiled copy</p>';
}
$RENDER = '<div class="elementor"><section><img src="https://bokatandlakartid.se/wp-content/uploads/2025/03/dentist-768x512.jpg">'
        . '<a class="elementor-button" href="/tandlakare">Hitta tandl&auml;kare</a>'
        . '<a class="elementor-button" href="https://bokatandlakartid.se/boka">Boka</a>'
        . '<p>Läs <a href="https://bokatandlakartid.se/om-oss">om oss</a> här.</p></section></div>';
$mgr = new PCM_Conn_Builder_Manager();
$mgr->register(new PCM_Conn_B_Elementor());
$tos = static fn(array $links) => array_values(array_map(static fn($l) => $l['to'], $links));

echo "\n1. Media controls are NOT links (the 27 were image-control defaults)\n";
seed(); $GLOBALS['rendered'] = ''; // no render available → only the shape rules apply
$links = $mgr->scan_links(822);
$t = $tos($links);
check('the placeholder-under-old-path refs are gone', !in_array(PLACEHOLDER, $t, true), $t);
$ph_rows = array_values(array_filter($links, static fn($l) => strpos((string) $l['to'], '/plugins/elementor/assets/') !== false));
check('…including an id-LESS one (Elementor’s own asset is never a link, whatever the shape)', $ph_rows === array(), $ph_rows);
check('a real image (uploads jpg with an attachment id) is not reported as a link', !in_array('https://bokatandlakartid.se/wp-content/uploads/2025/03/dentist-768x512.jpg', $t, true), $t);
check('a hosted video (media, id) is not a link', !in_array('https://bokatandlakartid.se/wp-content/uploads/clip.mp4', $t, true), $t);
check('the absolute button link IS reported', in_array('https://bokatandlakartid.se/boka', $t, true), $t);
check('the inline <a> in a text widget IS reported', in_array('https://bokatandlakartid.se/om-oss', $t, true), $t);
check('the ROOT-RELATIVE button link is reported, absolutised to this site', in_array('https://bokatandlakartid.se/tandlakare', $t, true), $t);
$rel = null; foreach ($links as $l) { if ($l['to'] === 'https://bokatandlakartid.se/tandlakare') { $rel = $l; } }
check('…carrying its label + element id + the stored form', $rel && $rel['anchor'] === 'Hitta tandläkare' && $rel['elId'] === 'btn1' && ($rel['stored'] ?? '') === '/tandlakare', $rel);
check('with NO render, the non-rendering link control is still listed (visibility floor)', in_array('https://bokatandlakartid.se/dental00/kampanj', $t, true), $t);
check('nothing else leaked in', count($links) === 4, $t);

echo "\n2. On-the-page guard: builder links must occur in the LIVE render\n";
seed(); $GLOBALS['rendered'] = $RENDER;
$links = $mgr->scan_links(822); $t = $tos($links);
check('a link control the page does not render is dropped', !in_array('https://bokatandlakartid.se/dental00/kampanj', $t, true), $t);
check('the relative link matches through its path (render has href="/tandlakare")', in_array('https://bokatandlakartid.se/tandlakare', $t, true), $t);
check('absolute + inline links stay', in_array('https://bokatandlakartid.se/boka', $t, true) && in_array('https://bokatandlakartid.se/om-oss', $t, true), $t);
check('exactly the three on-page links remain', count($links) === 3, $t);
check('render is entity/percent-decoded before matching (ä in href)', PCM_Conn_Builder_Manager::url_on_page('https://x.se/tandvård', rawurldecode(html_entity_decode('<a href="https://x.se/tandv&aring;rd">'))) === true);
check('a homepage link (path "/") is judged by host, never dropped for lack of a path', PCM_Conn_Builder_Manager::url_on_page('https://bokatandlakartid.se/', 'href="/tandlakare"') === false && PCM_Conn_Builder_Manager::url_on_page('https://bokatandlakartid.se/', 'href="https://bokatandlakartid.se/x"') === true);
check('empty render → everything counts as on the page', PCM_Conn_Builder_Manager::url_on_page('https://x.se/gone', '') === true);

echo "\n3. The Elementor render hook is guarded (throw → \"\" → keep everything)\n";
eval('namespace Elementor { class ThrowingFrontend { public function get_builder_content_for_display($pid, $css = false) { echo "partial"; throw new \RuntimeException("widget exploded"); } } }');
$saved = \Elementor\Plugin::$instance->frontend; \Elementor\Plugin::$instance->frontend = new \Elementor\ThrowingFrontend();
seed(); $links = $mgr->scan_links(822); $t = $tos($links);
check('a throwing render does not lose links (the non-rendering control is back)', in_array('https://bokatandlakartid.se/dental00/kampanj', $t, true), $t);
check('…and left no dangling output buffer', ob_get_level() === 0, ob_get_level());
\Elementor\Plugin::$instance->frontend = $saved;

echo "\n4. Element-scoped edit accepts the RELATIVE stored form (hub sends it back absolute)\n";
seed();
$r = $mgr->replace_link_in_element(822, 'btn1', 'https://bokatandlakartid.se/tandlakare', 'https://bokatandlakartid.se/tandlakare-2');
check('the manager alone cannot match the absolute needle against the relative value', (int) $r['replaced'] === 0, $r);
// The /replace-url route's fallback, executed the way the route does it.
$route = substr($tpl, strpos($tpl, "register_rest_route('pcm-conn/v1', '/replace-url'"), 3200);
check("the route retries element-scoped with the relative form when the absolute one matched nothing", preg_match('/if \(\(int\) \(\$r\[\'replaced\'\] \?\? 0\) === 0\) \{[\s\S]*?\$rel = substr\(\$old, strlen\(\$home\)\);[\s\S]*?replace_link_in_element\(\$pid, \$el_id, \$rel, \$new\)/', $route) === 1, 'no relative fallback');
$home = rtrim(home_url(), '/'); $old = 'https://bokatandlakartid.se/tandlakare'; $rel2 = substr($old, strlen($home));
$r2 = $mgr->replace_link_in_element(822, 'btn1', $rel2, 'https://bokatandlakartid.se/tandlakare-2');
$data = json_decode($GLOBALS['wpdb']->rows[3]->meta_value, true);
check('the fallback edits exactly the button, nothing else', (int) $r2['replaced'] === 1 && $data[0]['elements'][2]['settings']['link']['url'] === 'https://bokatandlakartid.se/tandlakare-2' && $data[0]['elements'][3]['settings']['link']['url'] === 'https://bokatandlakartid.se/boka', $data[0]['elements'][2]['settings']['link'] ?? null);
check('the fallback is element-scoped only — the global replace path gets no short relative needle', !preg_match('/replace_links\(\$pid, \$replacements\)[\s\S]{0,200}\$rel/', $route));

echo "\n5. The popup does not try to unwrap builder ELEMENTS (nothing to unwrap)\n";
$ui = file_get_contents($ROOT . '/app/src/modules/SEO/LinksPopup.tsx');
check('builder rows are recognised by their element id', strpos($ui, 'const isBuilderRow = (l: LinkRow) => !!l.elId;') !== false && strpos($ui, 'const canUnwrap = (l: LinkRow) => isEditable(l) && !isBuilderRow(l);') !== false);
check('"Remove all" targets only links WITH an <a>', preg_match('/const removeAllBroken = async \(\) => \{\s*const htmls = filtered\.filter\(canUnwrap\)/', $ui) === 1, 'still filters on isEditable');
check('…and when only builder elements are dead it explains instead of trying', preg_match('/if \(htmls\.length === 0\) \{\s*if \(builderNote\) toast\.info\(builderNote/', $ui) === 1);
check('the note tells the user what TO do (change the To cell / fix in the builder)', strpos($ui, 'change ${builderDead === 1 ? \'its\' : \'their\'} URL by clicking the To cell') !== false);
check('selection + Remove-selected follow the same rule', preg_match('/const selectable = filtered\.filter\(canUnwrap\);/', $ui) === 1 && preg_match('/removeSelected[\s\S]{0,200}canUnwrap\(l\)/', $ui) === 1);
check('the banner button is hidden when there is nothing it can unwrap', preg_match('/\{filtered\.length - builderDead > 0 && \(\s*<Button variant="destructive"/', $ui) === 1);
check('builder rows show a lock with the reason instead of unlink/rel/delete', preg_match('/editable && isBuilderRow\(l\) \? \([\s\S]{0,400}?title="Page-builder element — change its URL by clicking the To cell/', $ui) === 1);
check('post-body links keep unlink / rel / delete', strpos($ui, 'title="Unlink — remove the link but keep the text"') !== false && strpos($ui, 'title="Delete the entire element (restorable below)"') !== false);

echo "\n6. Self-update will carry it (the template changed → the build number moves)\n";
check('build number derives from the raw template', strpos($hub, 'md5(self::connector_php_simple_raw())') !== false);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
