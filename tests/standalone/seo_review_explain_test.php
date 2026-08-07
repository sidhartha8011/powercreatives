<?php
/**
 * SEO review — code fences never reach the page, and each change is explainable.
 *
 * Two reported defects in the optimizer's review step:
 *
 *   1. "sometimes when it rewrites it leaves ```html fragments in the page".
 *      parse_section_reply() stripped only a fence wrapping the WHOLE reply. A
 *      fence INSIDE the envelope's html field, or a stray ```html left
 *      mid-document, passed through verbatim — and worse, the fallback returned
 *      the raw reply untouched whenever the envelope was missing or unparseable,
 *      so a chatty non-JSON answer became page content backticks and all.
 *
 *   2. The review cards showed WHAT changed and a bare purpose tag, never WHY.
 *      The rail already has everything needed — each teacher is grouped
 *      `search` or `ai` (the Category) and the run's compiled directives carry
 *      the instruction text (the Why) — so this asserts the data contract the
 *      UI now depends on, rather than re-testing React.
 *
 * Run: php tests/standalone/seo_review_explain_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

foreach (array('add_action', 'add_filter') as $fn) {
    if (!function_exists($fn)) { eval("function {$fn}() { return true; }"); }
}
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('sanitize_key')) { function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }
if (!function_exists('esc_html')) { function esc_html($s) { return $s; } }
if (!function_exists('__')) { function __($s, $d = null) { return $s; } }

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/includes/modules/seo/editing.php';

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

$strip = new ReflectionMethod('PCM_SEO_Editing', 'strip_code_fences');
$strip->setAccessible(true);
$f = fn(string $s): string => $strip->invoke(null, $s);

echo "\n1. Fence markers are removed, content is not\n";
check('a fence wrapping the value', $f("```html\n<p>Hello</p>\n```") === '<p>Hello</p>', $f("```html\n<p>Hello</p>\n```"));
check('a language-less wrapper', $f("```\n<p>Hi</p>\n```") === '<p>Hi</p>', $f("```\n<p>Hi</p>\n```"));
check('uppercase language tag', $f("```HTML\n<p>X</p>\n```") === '<p>X</p>', $f("```HTML\n<p>X</p>\n```"));
// The reported shape: a marker left in the middle of an otherwise fine document.
check('a stray marker mid-document',
    $f("<p>A</p>\n```html\n<p>B</p>") === "<p>A</p>\n<p>B</p>", $f("<p>A</p>\n```html\n<p>B</p>"));
check('an orphaned closing marker', $f("<p>A</p>\n```") === '<p>A</p>', $f("<p>A</p>\n```"));
check('an inline leftover', $f('<p>A</p>```html<p>B</p>') === '<p>A</p><p>B</p>', $f('<p>A</p>```html<p>B</p>'));
check('clean html is untouched', $f('<p>Nothing to strip</p>') === '<p>Nothing to strip</p>');
check('empty input stays empty', $f('') === '');
// Non-destructive: the markup between markers survives.
check('content between markers survives', str_contains($f("```html\n<h2>Kept</h2><p>Also kept</p>\n```"), 'Also kept'));

echo "\n2. parse_section_reply() strips on EVERY exit\n";
$parse = fn(string $raw, array $purposes = array(), bool $expected = true): array
    => PCM_SEO_Editing::parse_section_reply($raw, $purposes, $expected);

// (a) the envelope's html field carries a fence
$r = $parse(json_encode(array('html' => "```html\n<p>Fenced inside</p>\n```", 'changes' => array())));
check('fence inside the envelope html', $r['value'] === '<p>Fenced inside</p>', $r['value']);

// (b) no envelope requested — raw reply is the value
$r = $parse("```html\n<p>Raw</p>\n```", array(), false);
check('unenveloped raw reply', $r['value'] === '<p>Raw</p>', $r['value']);

// (c) THE WORST PATH: envelope expected but the reply is not JSON at all.
$r = $parse("```html\n<p>Chatty</p>\n```", array('interlink'), true);
check('unparseable reply falls back CLEAN', $r['value'] === '<p>Chatty</p>', $r['value']);
check('no backticks survive any path', !str_contains($r['value'], '```'), $r['value']);

// A valid envelope still behaves exactly as before.
$ok = json_encode(array(
    'html' => '<p>The clinic offers same-day crowns.</p>',
    'changes' => array(array('what' => 'Added a service line', 'why' => 'interlink', 'quote' => 'same-day crowns')),
));
$r = $parse($ok, array('interlink'));
check('valid envelope keeps its html', $r['value'] === '<p>The clinic offers same-day crowns.</p>', $r['value']);
check('verified change is kept', count($r['changes']) === 1, $r['changes']);
check('what survives', ($r['changes'][0]['what'] ?? '') === 'Added a service line');
check('why survives as the purpose id', ($r['changes'][0]['why'] ?? '') === 'interlink');

// The verification law must not have been weakened by the fence work.
$bad = json_encode(array(
    'html' => '<p>Something else entirely.</p>',
    'changes' => array(array('what' => 'Claimed', 'why' => 'interlink', 'quote' => 'text that is absent')),
));
check('unverifiable claim still dropped', count($parse($bad, array('interlink'))['changes']) === 0);
$off = json_encode(array(
    'html' => '<p>Quoted text here.</p>',
    'changes' => array(array('what' => 'Did a thing', 'why' => 'not_in_this_run', 'quote' => 'Quoted text')),
));
check('a purpose outside the run is blanked', ($parse($off, array('interlink'))['changes'][0]['why'] ?? 'x') === '');

echo "\n3. The rail can build Category / Why / What without a new model call\n";
$rail = file_get_contents($ROOT . '/app/src/modules/SEO/editor/ReviewRail.tsx');
check('category derived from the teacher group', str_contains($rail, 'const categoryOf ='), 'no categoryOf');
check('SEO / AI / BOTH are the three outcomes',
    str_contains($rail, "'BOTH'") && str_contains($rail, "'AI' : 'SEO'"), 'category set incomplete');
check('BOTH means purposes spanning both groups',
    str_contains($rail, 'groups.size > 1'), 'BOTH not derived from a span');
check('why comes from the run\'s compiled directive',
    str_contains($rail, 'const directiveFor =') && str_contains($rail, 'const purposeOf ='), 'no purpose source');
check('a section-routed directive is preferred',
    str_contains($rail, '(d.targets ?? []).includes(sectionIdx)'), 'routing ignored');
check('falls back to the purpose label when unrouted',
    str_contains($rail, 'teacherById[why]?.label'), 'no label fallback');
check('the card renders all three', str_contains($rail, '{categoryOf(c.why, i)}')
    && str_contains($rail, 'Why: {purposeOf(c.why, i)}')
    && str_contains($rail, '{c.what}'), 'card incomplete');
// The teacher group is the ONLY category source — it must still exist upstream.
check('TeacherMeta still carries the group',
    str_contains(file_get_contents($ROOT . '/app/src/modules/SEO/optimizer/types.ts'), "group: 'search' | 'ai'"),
    'group field gone');
check('CompiledDirective still carries purposes + text',
    (bool) preg_match('/interface CompiledDirective \{.*?text: string;.*?purposes: string\[\];/s',
        file_get_contents($ROOT . '/app/src/modules/SEO/optimizer/types.ts')), 'directive shape changed');

echo "\n" . str_repeat('─', 52) . "\n";
echo "  passed: $PASS   failed: $FAIL\n";
exit($FAIL > 0 ? 1 : 0);
