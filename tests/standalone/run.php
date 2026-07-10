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
$hr = static fn(string $old, int $ol, int $nl, string $new, int $occ = 0, bool $all = false): array => array(
    'id' => 9, 'target' => 'heading', 'active' => true,
    'match' => array('text' => pcm_conn_normalize_text($old), 'occurrence' => $occ),
    'replacement' => $new,
    'section' => array('level' => $ol, 'newLevel' => $nl, 'allOccurrences' => $all),
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

// ═════ summary ═════
echo "\n" . ($FAIL === 0 ? "ALL GREEN" : "FAILURES: $FAIL") . " — $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
