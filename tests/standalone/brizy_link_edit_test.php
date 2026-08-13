<?php
/**
 * Brizy link EDITS — the PDF's dated item: "brizy - Links / edits are not
 * working on Brizy sites."
 *
 * Scanning Brizy was fixed earlier (source-scoped keys, base64-aware walk).
 * EDITING still failed, for four precise reasons, each pinned here:
 *   1. collect_links only tracked Elementor-shaped ids (id + elType). Brizy
 *      elements carry value._id → every Brizy link scanned with elId='' and
 *      hub anchor edits fell to the legacy path that rewrites Brizy's STALE
 *      COMPILED post_content copy — false success, live page unchanged.
 *   2. The element-scoped ops pre-filtered metas with strpos(raw, el_id) —
 *      an id inside Brizy's base64 blob never matches raw.
 *   3. Their tree walkers neither matched _id nor decoded base64 strings.
 *   4. Brizy button targets live under linkExternal (not url) — invisible to
 *      the scan, and node_has_url couldn't tie a label to its link.
 *
 * The connector's Manager + helpers are EXTRACTED from the nowdoc template and
 * EXECUTED against a realistic Brizy fixture: serialized meta wrapping
 * base64-encoded editor JSON (escaped slashes, unicode), plus a compiled-HTML
 * blob that must never be scanned or touched.
 *
 * Run: php tests/standalone/brizy_link_edit_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('BRIZY_VERSION')) { define('BRIZY_VERSION', '2.4.0'); }

$ROOT = dirname(__DIR__, 2);
$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

// ── Extract the connector template (nowdoc — hub lint never checks it) ──────
$hub = file_get_contents($ROOT . '/includes/modules/seohub/service.php');
$a = strpos($hub, "<<<'PHP'");
$b = strpos($hub, "\nPHP;", (int)$a);
check('connector nowdoc found', $a !== false && $b !== false);
$tpl = substr($hub, (int)$a + 9, (int)$b - (int)$a - 9);
$tpl_baked = str_replace(
    array('__PCM_CONN_UPDATE_URI__', '__PCM_CONN_VERSION__', '__PCM_HUB_URL__', '__PCM_CLIENT_ID__'),
    array('https://x', '0.0.0', 'https://hub', 'cid'),
    $tpl
);
$tmp = tempnam(sys_get_temp_dir(), 'pcmconn') . '.php';
file_put_contents($tmp, $tpl_baked);
exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $lint_out, $lint_code);
@unlink($tmp);
echo "\n1. The edited template still parses\n";
check('php -l on the EXTRACTED template', $lint_code === 0, implode("\n", array_slice($lint_out, 0, 3)));

// ── Slice the replace helpers + Manager (+ builder classes) for execution ───
$s1 = strpos($tpl, 'function pcm_conn_json_variants');
$e1 = strpos($tpl, '// ── Universal builder-aware link replacement');
$helpers = substr($tpl, (int)$s1, (int)$e1 - (int)$s1);
$s2 = strpos($tpl, 'interface PCM_Conn_Builder_Handler');
$e2 = strpos($tpl, 'function pcm_conn_builder_manager');
$classes = substr($tpl, (int)$s2, (int)$e2 - (int)$s2);
check('helper + class slices found', $s1 !== false && $e1 !== false && $s2 !== false && $e2 !== false);

// ── WP stubs ────────────────────────────────────────────────────────────────
function maybe_unserialize($v) { if (is_string($v) && preg_match('/^[aOs]:\d/', $v)) { $u = @unserialize($v); if ($u !== false || $v === 'b:0;') { return $u; } } return $v; }
function maybe_serialize($v) { return (is_array($v) || is_object($v)) ? serialize($v) : $v; }
function wp_json_encode($v) { return json_encode($v); }
function wp_cache_delete($id, $group = '') { return true; }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function pcm_conn_purge_caches($id) { $GLOBALS['purged'][] = $id; }
function do_action(...$args) {}
function delete_post_meta($id, $k) {}
function get_post_field($f, $id) { return $GLOBALS['post_content'] ?? ''; }
function wp_update_post($arr) { $GLOBALS['post_content'] = $arr['post_content'] ?? ''; return $arr['ID'] ?? 0; }
function get_post_meta($id, $key = '', $single = false) { return $GLOBALS['meta_raw'][$key] ?? ''; }
class WPDB_Stub {
    public $postmeta = 'wp_postmeta';
    public $rows = array();
    public function prepare($q, ...$args) { return $q; }
    public function get_results($q) { return array_values(array_map(static fn($r) => clone $r, $this->rows)); }
    public function get_col($q) { $o = array(); foreach ($this->rows as $r) { $o[] = $r->meta_value; } return $o; }
    public function update($t, $data, $where) { $this->rows[$where['meta_id']]->meta_value = $data['meta_value']; return 1; }
}
$GLOBALS['wpdb'] = new WPDB_Stub();
eval($helpers . "\n" . $classes);

// ── The Brizy fixture: serialized meta → base64 → JSON (escaped slashes) ────
// Two buttons SHARING a URL (element scoping must touch only one), one rich-
// text element with an inline <a> (Swedish chars exercise \uXXXX re-encode),
// and a stale compiled-HTML blob that must never be scanned or edited.
function brizy_meta_raw(): string {
    $tree = array('items' => array(
        array('type' => 'Wrapper', 'value' => array('_id' => 'wrap1', 'items' => array(
            array('type' => 'Button', 'value' => array(
                '_id' => 'btn1', 'text' => 'Contact us',
                'linkType' => 'external', 'linkExternal' => 'https://old.se/contact',
            )),
            array('type' => 'Button', 'value' => array(
                '_id' => 'btn2', 'text' => 'Contact us',
                'linkType' => 'external', 'linkExternal' => 'https://old.se/contact',
            )),
            array('type' => 'RichText', 'value' => array(
                '_id' => 'rich1',
                'text' => '<p>Välkommen! <a href="https://old.se/tandvård">Läs mer</a> idag.</p>',
            )),
            array('type' => 'Button', 'value' => array(  // stale leftover from a previous link type
                '_id' => 'btn3', 'text' => 'Anchor jump',
                'linkType' => 'anchor', 'linkExternal' => 'https://old.se/stale-leftover',
            )),
        ))),
    ));
    return serialize(array('brizy-post' => array(
        'editor_data'   => base64_encode((string) json_encode($tree)),
        'compiled_html' => base64_encode('<html><a href="https://old.se/deleted-long-ago">ghost</a></html>'),
        // A SECOND base64-JSON blob in the same meta that no edit ever targets. When its sibling
        // editor_data changes, the whole meta is rewritten — this one must come back byte-identical
        // (an untouched blob must never be re-encoded "in passing"). JS-written JSON on purpose:
        // unescaped unicode + slashes, so a PHP decode→encode round trip would CHANGE its bytes.
        'editor_settings' => base64_encode('{"lang":"sv","note":"åäö","path":"a/b"}'),
        'editor_version' => '2.4.0',
    )));
}
function seed(): void {
    $GLOBALS['wpdb']->rows = array(9 => (object) array('meta_id' => 9, 'meta_key' => 'brizy', 'meta_value' => brizy_meta_raw()));
    $GLOBALS['meta_raw'] = array('brizy' => brizy_meta_raw(), 'brizy_post_uid' => 'uid123');
    $GLOBALS['post_content'] = '<p>stale compiled copy</p><a href="https://old.se/deleted-long-ago">ghost</a>';
    $GLOBALS['purged'] = array();
}
function editor_tree(): array {
    $meta = maybe_unserialize($GLOBALS['wpdb']->rows[9]->meta_value);
    return (array) json_decode((string) base64_decode($meta['brizy-post']['editor_data']), true);
}
$mgr = new PCM_Conn_Builder_Manager();
$mgr->register(new PCM_Conn_B_Brizy());

echo "\n2. Scan — Brizy links now carry their element ids (and buttons appear)\n";
seed();
$links = $mgr->scan_links(1);
$by_to = array();
foreach ($links as $l) { $by_to[$l['to']][] = $l; }
check('button target (linkExternal) is captured', isset($by_to['https://old.se/contact']), array_keys($by_to));
check('BOTH same-URL buttons captured as distinct elements',
    count($by_to['https://old.se/contact'] ?? array()) === 2, $by_to['https://old.se/contact'] ?? null);
$btn_ids = array_map(static fn($l) => $l['elId'], $by_to['https://old.se/contact'] ?? array());
sort($btn_ids);
check('…each with its own Brizy _id as elId', $btn_ids === array('btn1', 'btn2'), $btn_ids);
check('button label rides along', ($by_to['https://old.se/contact'][0]['anchor'] ?? '') === 'Contact us', $by_to['https://old.se/contact'][0] ?? null);
$rich = $by_to['https://old.se/tandvård'][0] ?? null;
check('inline rich-text link captured with the enclosing element id', is_array($rich) && $rich['elId'] === 'rich1', $rich);
check('stale linkType leftover is NOT reported as a live link', !isset($by_to['https://old.se/stale-leftover']), array_keys($by_to));
check('the compiled-HTML ghost link is NOT scanned', !isset($by_to['https://old.se/deleted-long-ago']), array_keys($by_to));

echo "\n3. Element-scoped URL edit — inside the base64 blob, only the target element\n";
seed();
check('pre-filter sees the element id inside the base64 blob',
    pcm_conn_meta_may_contain(brizy_meta_raw(), array('btn1')) === true);
$rep = $mgr->replace_link_in_element(1, 'btn1', 'https://old.se/contact', 'https://new.se/kontakt');
check('replace reports a hit', ($rep['replaced'] ?? 0) > 0, $rep);
$tree = editor_tree();
$flat = (string) json_encode($tree);
check('btn1 now points at the NEW url', strpos($flat, 'new.se\/kontakt') !== false, $flat);
check('btn2 (same URL, other element) is UNTOUCHED', substr_count($flat, 'old.se\/contact') === 1, $flat);
$meta = maybe_unserialize($GLOBALS['wpdb']->rows[9]->meta_value);
check('the compiled-HTML blob is byte-identical (never corrupts a cache)',
    $meta['brizy-post']['compiled_html'] === base64_encode('<html><a href="https://old.se/deleted-long-ago">ghost</a></html>'), $meta['brizy-post']['compiled_html']);
check('the untouched sibling JSON blob is byte-identical (no re-encode in passing)',
    $meta['brizy-post']['editor_settings'] === base64_encode('{"lang":"sv","note":"åäö","path":"a/b"}'), $meta['brizy-post']['editor_settings']);
check('caches purged after the edit', !empty($GLOBALS['purged']));

echo "\n4. Element-scoped ANCHOR edit — labels + inline anchors inside the blob\n";
seed();
$rep = $mgr->replace_anchor_in_element(1, 'btn1', 'https://old.se/contact', 'Contact us', 'Ring oss');
check('button label rename reports a hit', ($rep['replaced'] ?? 0) > 0, $rep);
$flat = (string) json_encode(editor_tree());
check('btn1 is renamed', strpos($flat, 'Ring oss') !== false, $flat);
check('btn2 keeps its identical label (same text, other element)', substr_count($flat, 'Contact us') === 1, $flat);
seed();
$rep = $mgr->replace_anchor_in_element(1, 'rich1', 'https://old.se/tandvård', 'Läs mer', 'Boka nu');
check('inline rich-text anchor rename reports a hit', ($rep['replaced'] ?? 0) > 0, $rep);
$tree = editor_tree();
$txt = (string) ($tree['items'][0]['value']['items'][2]['value']['text'] ?? '');
check('the <a> inner text changed, href intact',
    strpos($txt, '>Boka nu</a>') !== false && strpos($txt, 'https://old.se/tandvård') !== false, $txt);

echo "\n5. Whole-page replace still works on Brizy (regression)\n";
seed();
$rep = $mgr->replace_links(1, array('https://old.se/contact' => 'https://new.se/kontakt'));
check('global replace reaches the base64 blob', ($rep['replaced'] ?? 0) >= 2, $rep);
$flat = (string) json_encode(editor_tree());
check('BOTH buttons updated by the global pass', strpos($flat, 'old.se\/contact') === false, $flat);

echo "\n6. Elementor stays intact (id + elType shape, plain JSON meta)\n";
$GLOBALS['wpdb']->rows = array(3 => (object) array('meta_id' => 3, 'meta_key' => '_elementor_data', 'meta_value' => (string) json_encode(array(
    array('id' => 'el1', 'elType' => 'widget', 'settings' => array('text' => 'Go', 'url' => 'https://old.se/a')),
    array('id' => 'el2', 'elType' => 'widget', 'settings' => array('text' => 'Go', 'url' => 'https://old.se/a')),
))));
$GLOBALS['meta_raw'] = array();
$rep = $mgr->replace_link_in_element(1, 'el1', 'https://old.se/a', 'https://new.se/b');
check('Elementor element-scoped URL edit still works', ($rep['replaced'] ?? 0) > 0, $rep);
$dec = json_decode($GLOBALS['wpdb']->rows[3]->meta_value, true);
check('only el1 changed', $dec[0]['settings']['url'] === 'https://new.se/b' && $dec[1]['settings']['url'] === 'https://old.se/a', $dec);

echo "\n7. The hub routes Brizy edits down the builder-aware paths\n";
$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');
check('anchor edits go builder-aware whenever the link carries an element id',
    preg_match('/if \(\$el !== \'\' && \$anchor !== null && \$old_anchor !== null[\s\S]{0,200}?remote_replace_link_anchor/', $svc) === 1, 'anchor path gate changed');
$ui = file_get_contents($ROOT . '/app/src/modules/SEO/LinksPopup.tsx');
check('the popup sends elId + oldAnchor on every edit',
    strpos($ui, "elId: l.elId ?? ''") !== false && strpos($ui, 'oldAnchor: l.anchor') !== false, 'edit payload trimmed');

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
