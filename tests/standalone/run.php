<?php
/**
 * STANDALONE extraction harness (cleanup C3) — run with bare PHP, no vendor:
 *
 *   php tests/standalone/run.php
 *
 * WHAT IT PROVES (the machine-enforced successor of the old hand "sync
 * contract", contracts v3):
 *
 * 1. PARITY — the connector's parsing/identity primitives (extracted from the
 *    REAL generated template in seohub/service.php and eval'd here) behave
 *    identically to the hub's fixture-tested PCM_Text_Matcher reference.
 * 2. APPLY — the connector's serving engine (now the ONLY implementation;
 *    the hub's mirror is deleted) passes the full section/paragraph fixture
 *    corpus, PLUS the v2.1 heading pass and its ordering law.
 *
 * NOTE: this CLI may lack mbstring — normalize() falls back to ASCII folding
 * on BOTH sides (safe by construction, spec'd). Fixtures therefore compute
 * match texts through normalize() itself, never hardcode folded non-ASCII.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

// ── Minimal WP shims (only what the extracted template touches) ──────────────
define('ABSPATH', __DIR__ . '/');
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
$GLOBALS['__opts'] = array();
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['__opts']) ? $GLOBALS['__opts'][$k] : $d; }
function update_option($k, $v, $a = false) { $GLOBALS['__opts'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__opts'][$k]); return true; }
function get_transient($k) { return get_option('_t_' . $k); }
function set_transient($k, $v, $e = 0) { return update_option('_t_' . $k, $v); }
function delete_transient($k) { return delete_option('_t_' . $k); }
function add_action(...$a) {}
function add_filter(...$a) {}
function register_activation_hook(...$a) {}
function plugin_basename($f) { return basename((string) $f); }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return esc_html($t); }
function wp_strip_all_tags($t) { return trim(strip_tags((string) $t)); }
function wp_kses_post($t) { return (string) $t; }
function esc_url_raw($u) { $u = trim((string) $u); return preg_match('#^https?://#i', $u) ? $u : ''; }
function sanitize_key($t) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $t)); }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function absint($n) { return abs((int) $n); }
function __($s, $d = null) { return $s; }
function wp_json_encode($v) { return json_encode($v); }
function home_url($p = '') { return 'https://site.example' . $p; }
function get_bloginfo($k = '') { return 'Harness'; }
function wp_parse_url($u, $c = -1) { return $c === -1 ? parse_url((string) $u) : parse_url((string) $u, $c); }

// ── Load the hub reference + the REAL extracted connector ────────────────────
$root = dirname(__DIR__, 2);
require_once $root . '/includes/modules/seo/class-pcm-text-matcher.php';

$svc = file_get_contents($root . '/includes/modules/seohub/service.php');
if ($svc === false || !preg_match("/return <<<'PHP'\r?\n(.*?)\r?\nPHP;/s", $svc, $m)) {
    fwrite(STDERR, "FATAL: connector template nowdoc not found\n");
    exit(1);
}
$template = strtr($m[1], array(
    '__PCM_CONN_MANIFEST_URL__'  => 'https://hub.example/manifest',
    '__PCM_CONN_UPDATE_URI__'    => 'https://hub.example/pcm-connector',
    '__PCM_CONN_UPDATE_HOST__'   => 'hub.example',
    '__PCM_CONN_VERSION__'       => '0.0.0-harness',
    '__PCM_CONN_HUB_URL__'       => '',
    '__PCM_CONN_CLIENT_ID__'     => '',
    '__PCM_CONN_CLIENT_SECRET__' => '',
));
$template = preg_replace('/^<\?php/', '', $template);
eval($template); // defines every pcm_conn_* function — the REAL shipped code

// ── Tiny assertion runner ─────────────────────────────────────────────────────
$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

$N = static fn(string $t): string => PCM_Text_Matcher::normalize($t);

// ═════ 1. PARITY — hub primitives vs extracted connector primitives ═════
echo "-- parity (hub PCM_Text_Matcher vs extracted pcm_conn_*) --\n";
$parity_inputs = array(
    "  B\xC3\xA4st   i\xC2\xA0test &mdash;\n\tALLTID  ",
    'We&rsquo;re&nbsp;#1 &amp; proud',
    'Läs mer', 'LÄS  MER', 'Läs&nbsp;mer', '',
);
foreach ($parity_inputs as $i => $in) {
    check("normalize parity #$i", PCM_Text_Matcher::normalize($in) === pcm_conn_normalize_text($in), pcm_conn_normalize_text($in));
}
$vt = '<p><script>var a=1;</script>Visible <style>.x{}</style>only.</p>';
check('visible_text parity', PCM_Text_Matcher::visible_text($vt) === pcm_conn_visible_text($vt), pcm_conn_visible_text($vt));
check('visible_text drops script/style', trim(pcm_conn_visible_text($vt)) === 'Visible only.', trim(pcm_conn_visible_text($vt)));
$fp_in = array('Första  stycket.', 'Andra&nbsp;stycket.');
check('fingerprint parity', PCM_Text_Matcher::fingerprint($fp_in) === pcm_conn_section_fingerprint($fp_in), pcm_conn_section_fingerprint($fp_in));
check('fingerprint empty = empty section', pcm_conn_section_fingerprint(array()) === '', pcm_conn_section_fingerprint(array()));

$fixture = '<div class="elementor-section"><h2 class="elementor-heading" data-id="h1">Våra tjänster</h2></div>'
    . '<div class="col"><p class="elementor-text" data-id="p1">Första stycket.</p></div>'
    . '<div class="col"><p class="elementor-text" data-id="p2">Andra&nbsp;stycket.</p></div>'
    . '<img src="between.jpg" alt="">'
    . '<div class="col"><p class="elementor-text" data-id="p3">Tredje stycket.</p></div>'
    . '<h2>Kontakt</h2><p>Ring oss.</p>';
check('parse_blocks parity', PCM_Text_Matcher::parse_blocks($fixture) === pcm_conn_parse_blocks($fixture));
$blocks = pcm_conn_parse_blocks($fixture);
check('parse_blocks order', array_column($blocks, 'tag') === array('h2', 'p', 'p', 'p', 'h2', 'p'), array_column($blocks, 'tag'));
check('parse_blocks offsets exact', $blocks[2]['html'] === substr($fixture, $blocks[2]['start'], $blocks[2]['len']));

$page = '<html><head><title>x</title></head><body>'
    . '<header><p>Vi använder cookies.</p><h1>Sajtnamn</h1></header>'
    . '<nav><p>Meny</p></nav>'
    . '<h2>Våra tjänster</h2><p>Första stycket.</p>'
    . '<aside><p>Sidokolumn.</p></aside>'
    . '<p>Andra stycket.</p>'
    . '<footer><p>© 2026</p></footer></body></html>';
check('chrome_spans parity', PCM_Text_Matcher::chrome_spans($page) === pcm_conn_chrome_spans($page));
check('content_blocks parity', PCM_Text_Matcher::content_blocks($page) === pcm_conn_content_blocks($page));
check('content_blocks excludes chrome', array_column(pcm_conn_content_blocks($page), 'text') === array('Våra tjänster', 'Första stycket.', 'Andra stycket.'), array_column(pcm_conn_content_blocks($page), 'text'));

$ru = '<h2>FAQ</h2><p>Intro.</p><ul><li>Ett</li></ul><p>Utro.</p>';
check('parse_replacement_units parity', PCM_Text_Matcher::parse_replacement_units($ru) === pcm_conn_parse_replacement_units($ru));
check('replacement units keep lists', pcm_conn_parse_replacement_units($ru)[2]['html'] === '<ul><li>Ett</li></ul>');

// normalize_src (v2.3): entity-decode + trim, NO case fold, query kept.
$src_in = '  /Media/Bild.JPG?v=2&amp;x=1 ';
check('normalize_src parity', PCM_Text_Matcher::normalize_src($src_in) === pcm_conn_normalize_src($src_in), pcm_conn_normalize_src($src_in));
check('normalize_src keeps case + query', pcm_conn_normalize_src($src_in) === '/Media/Bild.JPG?v=2&x=1', pcm_conn_normalize_src($src_in));

// ═════ 2. APPLY — the connector engine (the ONLY implementation) ═════
echo "-- apply engine (extracted connector) --\n";

// Section replace: equal count preserves builder attrs; between-content untouched.
$fp  = pcm_conn_section_fingerprint(array('Första stycket.', 'Andra stycket.', 'Tredje stycket.'));
$out = pcm_conn_apply_section_rule($fixture, $N('Våra tjänster'), 2, $fp, 0,
    '<h2>Tjänster som rankar</h2><p>Nytt ett.</p><p>Nytt två.</p><p>Nytt tre.</p>');
check('section equal-count keeps attrs', is_string($out)
    && strpos($out, '<h2 class="elementor-heading" data-id="h1">Tjänster som rankar</h2>') !== false
    && strpos($out, '<p class="elementor-text" data-id="p2">Nytt två.</p>') !== false
    && strpos($out, '<img src="between.jpg" alt="">') !== false
    && strpos($out, '<h2>Kontakt</h2><p>Ring oss.</p>') !== false, $out);

// Merge 4→2: surplus originals removed whole, wrappers survive.
$out = pcm_conn_apply_section_rule($fixture, $N('Våra tjänster'), 2, $fp, 0,
    '<h2>Tjänster</h2><p>Ett sammanslaget stycke.</p>');
check('section merge removes surplus', is_string($out)
    && strpos($out, '<p class="elementor-text" data-id="p1">Ett sammanslaget stycke.</p>') !== false
    && strpos($out, 'Tredje stycket') === false
    && strpos($out, '<div class="col"></div>') !== false, $out);

// Expand with new block kinds (H3 + list ride as siblings).
$out = pcm_conn_apply_section_rule('<h2>FAQ</h2><p>Gammal fråga.</p>', $N('FAQ'), 2,
    pcm_conn_section_fingerprint(array('Gammal fråga.')), 0,
    '<h2>FAQ</h2><h3>Vad kostar det?</h3><p>Det beror på.</p><ul><li>Ett</li><li>Två</li></ul>');
check('section expand new kinds', $out === '<h2>FAQ</h2><h3>Vad kostar det?</h3><p>Det beror på.</p><ul><li>Ett</li><li>Två</li></ul>', $out);

// Stale fingerprint → null (original serves).
check('section stale fingerprint null', pcm_conn_apply_section_rule($fixture, $N('Våra tjänster'), 2,
    pcm_conn_section_fingerprint(array('Första stycket.', 'KLIENTEN ÄNDRADE.', 'Tredje stycket.')), 0,
    '<h2>X</h2><p>Y.</p>') === null);

// Fingerprint beats a chrome-duplicate heading even when occurrence points wrong.
$dup = '<header><h2>Om oss</h2></header><h2>Om oss</h2><p>Rätt sektion.</p>';
$out = pcm_conn_apply_section_rule($dup, $N('Om oss'), 2,
    pcm_conn_section_fingerprint(array('Rätt sektion.')), 0, '<h2>Om oss</h2><p>Ny text.</p>');
check('section fingerprint beats twin', is_string($out)
    && strpos($out, '<header><h2>Om oss</h2></header>') !== false
    && strpos($out, '<p>Ny text.</p>') !== false, $out);

// Empty-bodied section is legal.
$out = pcm_conn_apply_section_rule('<h2>Rubrik</h2><h2>Nästa</h2><p>x</p>', $N('Rubrik'), 2, '', 0,
    '<h2>Rubrik</h2><p>Nytt stycke under.</p>');
check('section empty body', $out === '<h2>Rubrik</h2><p>Nytt stycke under.</p><h2>Nästa</h2><p>x</p>', $out);

// Chrome paragraphs never break a match (2.8.1 parity fix), chrome untouched.
$out = pcm_conn_apply_section_rule($page, $N('Våra tjänster'), 2,
    pcm_conn_section_fingerprint(array('Första stycket.', 'Andra stycket.')), 0,
    '<h2>Tjänster</h2><p>Ny text ett.</p><p>Ny text två.</p>');
check('section ignores chrome paragraphs', is_string($out)
    && strpos($out, '<p>Ny text ett.</p>') !== false
    && strpos($out, '<header><p>Vi använder cookies.</p><h1>Sajtnamn</h1></header>') !== false
    && strpos($out, '<footer><p>© 2026</p></footer>') !== false, $out);

// Inserts.
$out = pcm_conn_apply_section_insert($fixture, $N('Våra tjänster'), 2, 'after', 0, '<h2>FAQ</h2><p>Fråga och svar.</p>');
check('insert after section end', is_string($out)
    && preg_match('#Tredje stycket\.</p></div><h2>FAQ</h2><p>Fråga och svar\.</p><h2>Kontakt</h2>#', $out) === 1, $out);
$out = pcm_conn_apply_section_insert('<h2>Kontakt</h2><p>Ring oss.</p>', $N('Kontakt'), 2, 'before', 0, '<h2>FAQ</h2><p>Svar.</p>');
check('insert before anchor', $out === '<h2>FAQ</h2><p>Svar.</p><h2>Kontakt</h2><p>Ring oss.</p>', $out);
check('insert missing anchor null', pcm_conn_apply_section_insert('<h2>Annat</h2><p>x</p>', $N('Kontakt'), 2, 'after', 0, '<h2>FAQ</h2><p>Svar.</p>') === null);

// Paragraph rules through the FULL serving pass (the old replace_block corpus).
$pr = static fn(string $text, int $occ, string $rep): array => array(
    'id' => 1, 'target' => 'paragraph', 'active' => true,
    'match' => array('text' => pcm_conn_normalize_text($text), 'occurrence' => $occ),
    'replacement' => $rep,
);
$out = pcm_conn_apply_rules('<p>Old intro text.</p><p>Keep me.</p>', array($pr('Old intro text.', 0, 'New optimized intro.')), 1);
check('paragraph gutenberg swap', $out === '<p>New optimized intro.</p><p>Keep me.</p>', $out);
$out = pcm_conn_apply_rules(
    '<div class="elementor-widget-container"><p class="elementor-text" data-id="a1b2">Boka <span class="x">din</span>&nbsp;tid idag</p></div>',
    array($pr('Boka din tid idag', 0, 'Boka din behandling redan idag')), 1);
check('paragraph span-wrapped swap keeps attrs', strpos((string) $out, '<p class="elementor-text" data-id="a1b2">Boka din behandling redan idag</p>') !== false, $out);
$out = pcm_conn_apply_rules('<p>Läs mer</p><p>Läs mer</p><p>Läs mer</p>', array($pr('Läs mer', 1, 'Upptäck mer')), 1);
check('paragraph occurrence second twin', $out === '<p>Läs mer</p><p>Upptäck mer</p><p>Läs mer</p>', $out);
$out = pcm_conn_apply_rules('<p>Client edited this text.</p>', array($pr('The old text', 0, 'X')), 1);
check('paragraph miss serves original', $out === '<p>Client edited this text.</p>', $out);

// ═════ 3. v2.2 HEADING pass (occurrence-aware; allOccurrences = override semantics) ═════
$hr = static fn(string $old, int $ol, int $nl, string $new, int $occ = 0, bool $all = false, bool $frame = false): array => array(
    'id' => 9, 'target' => 'heading', 'active' => true,
    'match' => array('text' => pcm_conn_normalize_text($old), 'occurrence' => $occ),
    'replacement' => $new,
    'section' => array('level' => $ol, 'newLevel' => $nl, 'allOccurrences' => $all, 'frameOnly' => $frame),
);
$out = pcm_conn_apply_rules('<h1 class="site-title" data-x="1">Hello world!</h1><p>x</p>', array($hr('Hello world!', 1, 1, 'Hello!')), 1);
check('heading swap keeps attrs', $out === '<h1 class="site-title" data-x="1">Hello!</h1><p>x</p>', $out);
$out = pcm_conn_apply_rules('<h2>Om oss</h2><p>a</p><h2>Om oss</h2>', array($hr('Om oss', 2, 3, 'Om företaget', 0, true)), 1);
check('allOccurrences hits ALL + level change', $out === '<h3>Om företaget</h3><p>a</p><h3>Om företaget</h3>', $out);
$out = pcm_conn_apply_rules('<h2>Om oss</h2><p>a</p><h2>Om oss</h2>', array($hr('Om oss', 2, 2, 'Om företaget', 1)), 1);
check('occurrence targets ONLY the second twin', $out === '<h2>Om oss</h2><p>a</p><h2>Om företaget</h2>', $out);
$out = pcm_conn_apply_rules('<header><h2>Om oss</h2></header><h2>Om oss</h2><p>a</p>', array($hr('Om oss', 2, 2, 'Ny rubrik', 0)), 1);
check('chrome twin neither counted nor touched', $out === '<header><h2>Om oss</h2></header><h2>Ny rubrik</h2><p>a</p>', $out);
$out = pcm_conn_apply_rules('<h2>x</h2>', array($hr('x', 2, 2, 'a <b>bold</b> & raw')), 1);
check('heading text is esc_html-ed', $out === '<h2>a &lt;b&gt;bold&lt;/b&gt; &amp; raw</h2>', $out);
// frameOnly (v2.2 tightening): a frame edit rewrites ONLY chrome copies —
// identical text in page CONTENT is never touched.
$page2 = '<header><h2>Om oss</h2></header><h2>Om oss</h2><p>a</p><footer><h2>Om oss</h2></footer>';
$out = pcm_conn_apply_rules($page2, array($hr('Om oss', 2, 2, 'Om företaget', 0, true, true)), 1);
check('frameOnly touches ONLY frame copies', $out === '<header><h2>Om företaget</h2></header><h2>Om oss</h2><p>a</p><footer><h2>Om företaget</h2></footer>', $out);
$out = pcm_conn_apply_rules($page2, array($hr('Om oss', 2, 2, 'Om företaget', 0, true, false)), 1);
check('legacy allOccurrences keeps whole-page reach', $out === '<header><h2>Om företaget</h2></header><h2>Om företaget</h2><p>a</p><footer><h2>Om företaget</h2></footer>', $out);

// ORDERING LAW: the heading pass runs FIRST, so a section rule keyed on the
// heading's DISPLAY text (what the hub's snapshot parse computed) matches.
$html  = '<h2>Old title</h2><p>Body one.</p>';
$rules = array(
    $hr('Old title', 2, 2, 'New title'),
    array(
        'id' => 2, 'target' => 'section', 'active' => true,
        'match' => array('text' => pcm_conn_normalize_text('New title'), 'occurrence' => 0),
        'replacement' => '<h2>New title</h2><p>Rewritten body.</p>',
        'section' => array('level' => 2, 'fingerprint' => pcm_conn_section_fingerprint(array('Body one.'))),
    ),
);
$out = pcm_conn_apply_rules($html, $rules, 1);
check('ORDERING: heading pass before sections', $out === '<h2>New title</h2><p>Rewritten body.</p>', $out);

// Site + post rule merge order is caller-side (site first) — assert the pass
// handles a merged array with both scopes without interference.
$out = pcm_conn_apply_rules('<h2>T</h2><p>Old para.</p>', array($hr('T', 2, 2, 'T2'), $pr('Old para.', 0, 'New para.')), 1);
check('merged site+post rules coexist', $out === '<h2>T2</h2><p>New para.</p>', $out);

// ═════ 4. v2.3 IMAGE pass (attr rewrite ONLY; content-region identity) ═════
$ir = static fn(string $src, int $occ, array $attrs): array => array(
    'id' => 20, 'target' => 'image', 'active' => true,
    'match' => array('text' => pcm_conn_normalize_src($src), 'occurrence' => $occ),
    'replacement' => json_encode($attrs),
);
// Attr swap: alt+title replaced, everything else (class/srcset/order) kept,
// sibling images untouched.
$imgpage = '<p>x</p><img class="hero" srcset="a.jpg 2x" src="/img/a.jpg" alt="old alt" title="old"><img src="/img/b.jpg" alt="b">';
$out = pcm_conn_apply_rules($imgpage, array($ir('/img/a.jpg', 0, array('alt' => 'New alt', 'title' => 'New title'))), 1);
check('image attr swap keeps everything else', $out === '<p>x</p><img class="hero" srcset="a.jpg 2x" src="/img/a.jpg" alt="New alt" title="New title"><img src="/img/b.jpg" alt="b">', $out);
// Missing attrs are ADDED (never duplicated), position/src untouched.
$out = pcm_conn_apply_rules('<img src="/img/a.jpg">', array($ir('/img/a.jpg', 0, array('alt' => 'Added', 'title' => 'T'))), 1);
check('image adds absent alt/title', $out === '<img title="T" alt="Added" src="/img/a.jpg">', $out);
// Occurrence targets the occurrence-th same-src twin only.
$out = pcm_conn_apply_rules('<img src="/img/a.jpg" alt="1"><img src="/img/a.jpg" alt="2">', array($ir('/img/a.jpg', 1, array('alt' => 'second'))), 1);
check('image occurrence second twin', $out === '<img src="/img/a.jpg" alt="1"><img src="/img/a.jpg" alt="second">', $out);
// Chrome images: neither counted nor touched (the frame law) — occurrence 0
// is the first CONTENT copy.
$out = pcm_conn_apply_rules('<header><img src="/logo.png" alt="chrome"></header><img src="/logo.png" alt="content">', array($ir('/logo.png', 0, array('alt' => 'edited'))), 1);
check('image chrome twin neither counted nor touched', $out === '<header><img src="/logo.png" alt="chrome"></header><img src="/logo.png" alt="edited">', $out);
// src miss = inert, original serves.
$out = pcm_conn_apply_rules('<img src="/img/other.jpg" alt="keep">', array($ir('/img/gone.jpg', 0, array('alt' => 'X'))), 1);
check('image src miss serves original', $out === '<img src="/img/other.jpg" alt="keep">', $out);
// Values are esc_attr-ed — a quote can never break out of the attribute.
$out = pcm_conn_apply_rules('<img src="/img/a.jpg" alt="old">', array($ir('/img/a.jpg', 0, array('alt' => 'He said "hi" & left'))), 1);
check('image attr value is esc_attr-ed', $out === '<img src="/img/a.jpg" alt="He said &quot;hi&quot; &amp; left">', $out);
// data-alt is NOT the alt attribute (word-boundary guard).
$out = pcm_conn_apply_rules('<img data-alt="keep" src="/img/a.jpg" alt="old">', array($ir('/img/a.jpg', 0, array('alt' => 'new'))), 1);
check('image data-alt attr never confused with alt', $out === '<img data-alt="keep" src="/img/a.jpg" alt="new">', $out);
// ORDERING LAW: the image pass runs LAST — it reaches images that only exist
// in a SECTION rule's output.
$rules = array(
    array(
        'id' => 21, 'target' => 'section', 'active' => true,
        'match' => array('text' => pcm_conn_normalize_text('Bild'), 'occurrence' => 0),
        'replacement' => '<h2>Bild</h2><p>Text.</p><img src="/img/new.jpg" alt="raw">',
        'section' => array('level' => 2, 'fingerprint' => pcm_conn_section_fingerprint(array('Gammal text.'))),
    ),
    $ir('/img/new.jpg', 0, array('alt' => 'polished')),
);
$out = pcm_conn_apply_rules('<h2>Bild</h2><p>Gammal text.</p>', $rules, 1);
check('ORDERING: image pass after sections', is_string($out) && strpos($out, '<img src="/img/new.jpg" alt="polished">') !== false, $out);

// ═════ 5. v2.4 sectionRemove + image hidden (engine v2.4, schema 5) ═════
$rr = static fn(string $text, int $lvl, string $fp, int $occ = 0): array => array(
    'id' => 30, 'target' => 'sectionRemove', 'active' => true,
    'match' => array('text' => pcm_conn_normalize_text($text), 'occurrence' => $occ),
    'replacement' => '',
    'section' => array('level' => $lvl, 'fingerprint' => $fp),
);
// Remove hit: heading + body gone; between-content + neighbors survive.
$fp3 = pcm_conn_section_fingerprint(array('Första stycket.', 'Andra stycket.', 'Tredje stycket.'));
$out = pcm_conn_apply_rules($fixture, array($rr('Våra tjänster', 2, $fp3)), 1);
check('remove deletes heading + body', is_string($out)
    && strpos($out, 'Våra tjänster') === false && strpos($out, 'Första stycket') === false && strpos($out, 'Tredje stycket') === false, $out);
check('remove keeps between-content + neighbors', is_string($out)
    && strpos($out, '<img src="between.jpg" alt="">') !== false && strpos($out, '<h2>Kontakt</h2><p>Ring oss.</p>') !== false, $out);
// Fingerprint miss (client changed the section) = inert, original serves.
$out = pcm_conn_apply_rules($fixture, array($rr('Våra tjänster', 2, 'stale-fingerprint')), 1);
check('remove fingerprint miss serves original', $out === $fixture, $out);
// Occurrence among VERIFIED twins: only the second identical section dies.
$twins = '<h2>Twin</h2><p>a</p><h2>Twin</h2><p>a</p><h2>End</h2>';
$fpA   = pcm_conn_section_fingerprint(array('a'));
$out   = pcm_conn_apply_rules($twins, array($rr('Twin', 2, $fpA, 1)), 1);
check('remove occurrence second twin', $out === '<h2>Twin</h2><p>a</p><h2>End</h2>', $out);
// Empty-bodied section (heading only) removes cleanly.
$out = pcm_conn_apply_rules('<h2>Lonely</h2><h2>Next</h2><p>x</p>', array($rr('Lonely', 2, pcm_conn_section_fingerprint(array()))), 1);
check('remove empty-body section', $out === '<h2>Next</h2><p>x</p>', $out);
// ORDERING: removes run BEFORE inserts — an insert anchored on a surviving
// neighbor lands after the removal reshaped the page.
$rules = array(
    $rr('Twin', 2, $fpA, 0),
    array(
        'id' => 31, 'target' => 'sectionInsert', 'active' => true,
        'match' => array('text' => pcm_conn_normalize_text('End'), 'occurrence' => 0),
        'replacement' => '<h2>New</h2><p>n</p>',
        'section' => array('level' => 2, 'position' => 'before'),
    ),
);
$out = pcm_conn_apply_rules('<h2>Twin</h2><p>a</p><h2>End</h2>', $rules, 1);
check('ORDERING: remove before insert', $out === '<h2>New</h2><p>n</p><h2>End</h2>', $out);
// Image hidden: the tag is removed at render — siblings untouched.
$ih = static fn(string $src, int $occ): array => array(
    'id' => 32, 'target' => 'image', 'active' => true,
    'match' => array('text' => pcm_conn_normalize_src($src), 'occurrence' => $occ),
    'replacement' => json_encode(array('hidden' => true)),
);
$out = pcm_conn_apply_rules('<p>x</p><img src="/a.jpg" alt="1"><img src="/b.jpg" alt="keep">', array($ih('/a.jpg', 0)), 1);
check('image hidden removes the tag', $out === '<p>x</p><img src="/b.jpg" alt="keep">', $out);
$out = pcm_conn_apply_rules('<img src="/a.jpg" alt="1"><img src="/a.jpg" alt="2">', array($ih('/a.jpg', 1)), 1);
check('image hidden occurrence second twin', $out === '<img src="/a.jpg" alt="1">', $out);
$out = pcm_conn_apply_rules('<header><img src="/l.png" alt="logo"></header><img src="/l.png" alt="content">', array($ih('/l.png', 0)), 1);
check('image hidden chrome isolation', $out === '<header><img src="/l.png" alt="logo"></header>', $out);
// SINGLE-SCAN stability: hide occ 0 AND rewrite occ 1 in the same pass —
// occurrence indexes never shift mid-scan (the v2.4 one-scan law).
$out = pcm_conn_apply_rules('<img src="/a.jpg" alt="1"><img src="/a.jpg" alt="2">', array($ih('/a.jpg', 0), $ir('/a.jpg', 1, array('alt' => 'kept+renamed'))), 1);
check('image single-scan hide+rewrite stability', $out === '<img src="/a.jpg" alt="kept+renamed">', $out);

// ═════ 6. v2.4.1 content-region primitive + insert placement/ordering ═════
pcm_conn_content_remember(5, '<h2>X</h2><p>base</p>');
$page5 = '<html><body><div class="entry"><h2>X</h2><p>base</p></div><p>furniture</p></body></html>';
$span  = pcm_conn_content_span($page5, 5);
check('content span exact', is_array($span) && substr($page5, $span[0], $span[1] - $span[0]) === '<h2>X</h2><p>base</p>', $span);
check('content span null when unrecorded', pcm_conn_content_span($page5, 6) === null);
check('content span null when not found', pcm_conn_content_span('<p>other page</p>', 5) === null);

$ins = static fn(int $id, string $body, string $pos = 'after', string $anchor = 'X'): array => array(
    'id' => $id, 'target' => 'sectionInsert', 'active' => true,
    'match' => array('text' => pcm_conn_normalize_text($anchor), 'occurrence' => 0),
    'replacement' => "<h2>$body</h2><p>p$body</p>",
    'section' => array('level' => 2, 'position' => $pos),
);
// The I1 case turned green: with the sentinel, an 'after' insert on the LAST
// section lands at the content end — trailing theme furniture stays after it.
$buf = '<h2>X</h2><p>base</p>' . PCM_CONN_CE . '<p>furniture-1</p><p>furniture-2</p>';
$out = pcm_conn_apply_rules($buf, array($ins(1, 'NEW')), 1);
check('insert clamps to content end (furniture stays after)', is_string($out)
    && strpos($out, 'base</p><h2>NEW</h2>') !== false && strpos($out, '<h2>NEW</h2>') < strpos($out, 'furniture-1'), $out);
// A heading BEYOND the region (comments title) must not pull the insert out either.
$buf = '<h2>X</h2><p>base</p>' . PCM_CONN_CE . '<p>f</p><h2>Comments</h2>';
$out = pcm_conn_apply_rules($buf, array($ins(1, 'NEW')), 1);
check('insert never crosses the region toward a comments heading', is_string($out) && strpos($out, 'base</p><h2>NEW</h2>') !== false, $out);
// Anchors OUTSIDE the region keep legacy semantics.
$buf = '<p>content</p>' . PCM_CONN_CE . '<h2>Outside</h2><p>a</p><p>b</p>';
$out = pcm_conn_apply_rules($buf, array($ins(1, 'NEW', 'after', 'Outside')), 1);
check('anchor outside region keeps legacy fallback', is_string($out) && strpos($out, 'b</p><h2>NEW</h2>') !== false, $out);
// Sentinel integrity: apply_rules must never eat or duplicate the marker
// (the serving callback strips it after the passes).
check('sentinel survives passes exactly once', substr_count((string) $out, PCM_CONN_CE) === 1, $out);
// ORDERING LAW: after-inserts serve in creation order now…
$out = pcm_conn_apply_rules('<h2>X</h2><p>base</p><h2>End</h2>', array($ins(1, 'ONE'), $ins(2, 'TWO'), $ins(3, 'THREE')), 1);
preg_match_all('/<h2>(\w+)<\/h2>/', (string) $out, $mm);
check('after-inserts serve in creation order', implode(',', $mm[1]) === 'X,ONE,TWO,THREE,End', implode(',', $mm[1]));
// …and before-inserts keep their (already correct) forward order.
$out = pcm_conn_apply_rules('<h2>X</h2><p>base</p>', array($ins(1, 'A', 'before'), $ins(2, 'B', 'before')), 1);
preg_match_all('/<h2>(\w+)<\/h2>/', (string) $out, $mm);
check('before-inserts keep forward order', implode(',', $mm[1]) === 'A,B,X', implode(',', $mm[1]));

// ═════ 7. v3.0.5 slug-change redirects (pure decision functions) ═════
echo "-- redirects (3.0.5) --\n";
$np = 'pcm_conn_redirect_norm_path';
foreach (array('https://s.example/Old-Slug/?q=1#f', '/rel%20path/', '', '/', 'bare', '//host.example/p/') as $i => $in) {
    check("normalize_path parity #$i", PCM_Text_Matcher::normalize_path($in) === $np($in), array(PCM_Text_Matcher::normalize_path($in), $np($in)));
}
check('norm_path absolute URL to path', $np('https://site.example/old-slug/?a=1#frag') === '/old-slug', $np('https://site.example/old-slug/?a=1#frag'));
check('norm_path relative keeps case, strips query+hash', $np('/Old-Slug?x=1#y') === '/Old-Slug', $np('/Old-Slug?x=1#y'));
check('norm_path decodes + leading slash', $np('v%C3%A5ra-tj%C3%A4nster/') === '/våra-tjänster', $np('v%C3%A5ra-tj%C3%A4nster/'));
check('norm_path empty and root are root', $np('') === '/' && $np('https://site.example/') === '/', array($np(''), $np('https://site.example/')));
$store = array(
    array('from' => '/old-slug', 'to' => 'https://site.example/new-slug/', 'code' => 301),
    array('from' => '/moved', 'to' => 'https://elsewhere.example/target', 'code' => 302),
);
$hit = pcm_conn_redirect_match('/old-slug/', $store);
check('match exact (trailing slash tolerated)', is_array($hit) && $hit['to'] === 'https://site.example/new-slug/' && $hit['code'] === 301, $hit);
$hit = pcm_conn_redirect_match('/old-slug?utm=x&b=2', $store);
check('match passes the query through', is_array($hit) && $hit['to'] === 'https://site.example/new-slug/?utm=x&b=2', $hit);
$hit = pcm_conn_redirect_match('/moved?q=1', array(array('from' => '/moved', 'to' => 'https://t.example/?keep=1', 'code' => 302)));
check('query passthrough appends with & when target has one', is_array($hit) && $hit['to'] === 'https://t.example/?keep=1&q=1' && $hit['code'] === 302, $hit);
check('match is case-sensitive exact', pcm_conn_redirect_match('/OLD-SLUG', $store) === null);
check('no match returns null', pcm_conn_redirect_match('/unrelated', $store) === null);
check('empty store returns null', pcm_conn_redirect_match('/old-slug', array()) === null);
$hit = pcm_conn_redirect_match('/old-slug', array(array('from' => '/old-slug', 'to' => 'https://x.example/a', 'code' => 999)));
check('illegal stored code answers 301', is_array($hit) && $hit['code'] === 301, $hit);
$clean = pcm_conn_redirect_sanitize(array(
    array('from' => 'https://site.example/keep/', 'to' => 'https://site.example/kept', 'code' => '302'),
    array('from' => '/keep', 'to' => 'https://site.example/dupe', 'code' => 301),      // dupe path — first wins
    array('from' => '/', 'to' => 'https://site.example/never', 'code' => 301),          // front page — refused
    array('from' => '/bad-target', 'to' => 'javascript:alert(1)', 'code' => 301),       // unsafe target — dropped
    array('from' => '/bad-code', 'to' => 'https://site.example/x', 'code' => 500),      // whitelisted down to 301
));
check('sanitize: dedupe + front-page refusal + unsafe target dropped', count($clean) === 2
    && $clean[0] === array('from' => '/keep', 'to' => 'https://site.example/kept', 'code' => 302)
    && $clean[1] === array('from' => '/bad-code', 'to' => 'https://site.example/x', 'code' => 301), $clean);

// ═════ APPROVALS — a save may never erase a document ═════════════════════════
//
// On 2026-08-06 approval set 26 lost 489,939 bytes of content: an empty document
// was stored over a real one. The guard that makes that impossible is extracted
// from the REAL service file and exercised here — same technique as the
// connector template above, so this tests shipping code and not a copy of it.
//
// SCOPE, stated honestly: only `would_erase_document` is provable standalone —
// it is pure string logic over `wp_strip_all_tags`, which this harness shims
// faithfully. Its sibling `sanitize_document_html` depends on WordPress's own
// kses protocol list and CANNOT be proven without WordPress; a shim would only
// test the shim. That one is proven in web context instead.
echo "\n── approvals: a save may never erase a document ──\n";

$approvals_src = file_get_contents($root . '/includes/modules/approvals/service.php');
if ($approvals_src === false
    || !preg_match('/private static function would_erase_document\((.*?)\n    \}/s', $approvals_src, $wm)) {
    check('would_erase_document extracted from the real service', false, 'method not found');
} else {
    eval('function pcm_would_erase_document(' . $wm[1] . "\n}");

    check('empty over content is REFUSED — the set 26 regression',
        pcm_would_erase_document('<p data-id="x"></p>', '<p>Real work</p>') === true);
    check('empty over an image-only card is REFUSED',
        pcm_would_erase_document('<p></p>', '<img src="data:image/png;base64,AAAA">') === true);
    check('empty over empty is allowed (nothing to lose)',
        pcm_would_erase_document('<p></p>', '') === false);
    check('empty over a blank paragraph is allowed',
        pcm_would_erase_document('<p></p>', '<p></p>') === false);
    check('real text is always allowed through',
        pcm_would_erase_document('<p>New words</p>', '<p>Old words</p>') === false);
    check('an image-only document is NOT treated as empty',
        pcm_would_erase_document('<img src="https://x.test/a.png">', '<p>Old words</p>') === false);
    check('whitespace-only does not sneak past as content',
        pcm_would_erase_document('<p>   </p>', '<p>Real work</p>') === true);
}

// ═════ summary ═════
echo "\n" . ($FAIL === 0 ? "ALL GREEN" : "FAILURES: $FAIL") . " — $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
