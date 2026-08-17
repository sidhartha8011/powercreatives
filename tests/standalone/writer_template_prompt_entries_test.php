<?php
/**
 * Cards 5 → 13 (Templates / Writer Templates, resubmitted): "the writer templates
 * should share the variables … just like the SEO … we're lacking variables that we
 * currently have in SEO but not in writer" + "all site. variables needed".
 *
 * The shared site+business map landed on 08-15. What was STILL wrong: the Templates
 * dialog auto-set the 'prompt' category only for video/seo/optimizer — a WRITER
 * template created there landed under the dropdown's default, 'reference_ad'. Two
 * consequences, both invisible to the author:
 *   1. the "/" variable menu is offered only for 'prompt' entries → NO variables in
 *      the writer editor (the card, literally), while SEO's editor shows them;
 *   2. every strategy reader keeps only 'prompt' entries → the writer template's text
 *      was IGNORED at generation (the article got the hardcoded fallback prompt) —
 *      "the template doesn't work at all" (08-17).
 *
 * Under test:
 *  A. PCM_Strategy_Service::load_template() EXECUTED against a DB-row stub: a writer
 *     row saved as 'reference_ad' comes back with every entry as 'prompt'; other
 *     modules untouched.
 *  B. Writer's map gains SEO's name for the keyword — {{ primary_keyword }} — as a
 *     plain value; placing it counts as placing {{ keyword }} (no doubled keyword line).
 *  C. The editor: writer is a PROMPT-ONLY module — auto 'prompt', no category picker,
 *     variables offered whatever category an old row carries; CSV import lands as
 *     'prompt'; the strategy dialog's fit classifier reads every writer entry.
 *
 * Run: php tests/standalone/writer_template_prompt_entries_test.php
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
$svc = file_get_contents($ROOT . '/includes/modules/strategy/service.php');
$slice = static function (string $needle) use ($svc): string {
    $i = strpos($svc, $needle); $start = strrpos(substr($svc, 0, (int)$i), "\n") + 1;
    $j = strpos($svc, "\n    /**", (int)$i); if ($j === false) { $j = strpos($svc, "\n    private static function", (int)$i + 10); }
    $fn = substr($svc, $start, (int)$j - $start);
    if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
    return str_replace('private static', 'public static', $fn);
};

// ── A. load_template normalises writer entries ──────────────────────────────
echo "\n1. load_template(): a writer row saved under 'reference_ad' is read as prompts\n";
class PCM_Schema { public static function table($n) { return 'wp_' . $n; } }
class WPDB_Stub { public $rows = array(); public function prepare($q, ...$a) { return $a[0]; } public function get_row($id) { return $this->rows[$id] ?? null; } }
$GLOBALS['wpdb'] = new WPDB_Stub();
$GLOBALS['wpdb']->rows = array(
    11 => (object) array('id' => 11, 'module' => 'writer', 'name' => 'Filip blog', 'userId' => 1, 'formData' => json_encode(array('type' => 'article', 'entries' => array(
        array('key' => 'reference_ad_1', 'category' => 'reference_ad', 'label' => 'Filip blog', 'value' => 'Write about {{ keyword }} for {{business.name}} in {{site.lang}}.'),
        array('key' => 'tonality_2', 'category' => 'tonality', 'label' => 'Tone', 'value' => 'Warm and direct.'),
    )))),
    12 => (object) array('id' => 12, 'module' => 'copy', 'name' => 'Ad', 'userId' => 1, 'formData' => json_encode(array('type' => 'ad', 'entries' => array(
        array('key' => 'reference_ad_1', 'category' => 'reference_ad', 'label' => 'Ref', 'value' => 'Some reference ad'),
    )))),
    13 => (object) array('id' => 13, 'module' => 'writer', 'name' => 'Seeded', 'userId' => 0, 'formData' => json_encode(array('type' => 'article', 'entries' => array(
        array('key' => 'p', 'category' => 'prompt', 'label' => 'SEO Pillar Article', 'value' => 'Prompt text.'),
    )))),
);
eval('class LoadHost { ' . $slice('private static function load_template(') . ' }');
$t = LoadHost::load_template(11, 1);
check('every entry of the writer row is now a prompt (both the reference_ad and the tonality one)', array_column($t['entries'], 'category') === array('prompt', 'prompt'), $t['entries']);
check('values/keys/labels untouched', $t['entries'][0]['value'] === 'Write about {{ keyword }} for {{business.name}} in {{site.lang}}.' && $t['entries'][0]['key'] === 'reference_ad_1' && $t['entries'][1]['label'] === 'Tone');
$c = LoadHost::load_template(12, 1);
check('a COPY row keeps its real categories (only writer is prompt-only)', array_column($c['entries'], 'category') === array('reference_ad'), $c['entries']);
$s = LoadHost::load_template(13, 1);
check('an already-prompt seed row is byte-identical', $s['entries'][0]['category'] === 'prompt' && $s['name'] === 'Seeded');
check('…and every strategy reader still filters on prompt (build_prompt, image prompt, source-carry) — so the normalisation is what makes the text count',
    preg_match_all("/\(\\\$entry\['category'\] \?\? ''\) (?:===|!==) 'prompt'/", $svc) === 3, preg_match_all("/\(\\\$entry\['category'\] \?\? ''\) (?:===|!==) 'prompt'/", $svc));

// ── B. {{ primary_keyword }} ────────────────────────────────────────────────
echo "\n2. Writer accepts SEO's name for the keyword\n";
check('build_prompt() maps primary_keyword to the item keyword as a PLAIN value', preg_match("/'primary_keyword'\s*=>\s*\\\$keyword_text,/", $svc) === 1, 'no alias');
check('…and it is NOT a fragment (no blank-line collapse, never auto-appended)', preg_match("/\\\$fragment_vars\s*=\s*array\('keyword', 'brand_context', 'research', 'output_format', 'media_instructions'\)/", $svc) === 1);
check('placing {{ primary_keyword }} counts as placing {{ keyword }} (keyword line not doubled)',
    preg_match("/if \(in_array\('primary_keyword', \\\$referenced, true\) && !in_array\('keyword', \\\$referenced, true\)\) \{\s*\r?\n\s*\\\$referenced\[\] = 'keyword';/", $svc) === 1, 'no suppression bridge');
// Execute the real renderer + referenced_vars on it.
eval('class RenderHost { ' . $slice('private static function render_template_vars(') . "\n" . $slice('private static function referenced_vars(') . ' }');
$vars = array('keyword' => 'tandimplantat', 'primary_keyword' => 'tandimplantat', 'brand_context' => '', 'business.name' => 'Klinik AB');
$out = RenderHost::render_template_vars('Skriv om {{ primary_keyword }} ({{keyword}}) för {{business.name}}.', $vars, array('keyword'));
check('renders through the same whitespace-tolerant engine as {{ keyword }}', $out === 'Skriv om tandimplantat (tandimplantat) för Klinik AB.', $out);
$ref = RenderHost::referenced_vars('Only {{ primary_keyword }} here.', array_keys($vars));
check('referenced_vars sees primary_keyword (the bridge then adds keyword)', in_array('primary_keyword', $ref, true) && !in_array('keyword', $ref, true), $ref);

// ── C. The editor ───────────────────────────────────────────────────────────
echo "\n3. Writer is a PROMPT-ONLY module in the editor\n";
$tv  = file_get_contents($ROOT . '/app/src/modules/Templates/templateVars.ts');
$dlg = file_get_contents($ROOT . '/app/src/modules/Templates/TemplateDialog.tsx');
$row = file_get_contents($ROOT . '/app/src/modules/Templates/TemplateRow.tsx');
$idx = file_get_contents($ROOT . '/app/src/modules/Templates/index.tsx');
$csd = file_get_contents($ROOT . '/app/src/modules/Keywords/CreateStrategyDialog.tsx');
check('ONE definition of the prompt-only set, writer included', preg_match("/export const PROMPT_ONLY_MODULES = new Set\(\['writer', 'video', 'seo', 'optimizer'\]\);/", $tv) === 1, 'no shared set');
check('the variable menu is offered for writer whatever the stored category', preg_match("/if \(category !== 'prompt' && !isPromptOnlyModule\(module\)\) return \[\];/", $tv) === 1, 'still prompt-only by category');
check('Writer’s vocabulary carries {{ primary_keyword }} beside {{ keyword }}', strpos($tv, "'{{ primary_keyword }}': 'The same target keyword under its SEO-template name") !== false);
check('dialog: writer auto-sets category prompt', preg_match("/if \(isPromptOnlyModule\(module\)\) \{\s*\r?\n\s*setSelectedCategory\(\"prompt\"\);/", $dlg) === 1, 'writer still lands on reference_ad');
check('dialog: the category picker is hidden for prompt-only modules', preg_match('/\{!isPromptOnlyModule\(module\) && \(/', $dlg) === 1 && strpos($dlg, 'module !== "video" && module !== "seo" && module !== "optimizer" && (') === false);
check('dialog: the grid drops the category column for them', strpos($dlg, 'isPromptOnlyModule(module) ? "grid-cols-2" : "grid-cols-3"') !== false);
check('row: subtype select hidden for prompt-only modules, reads "Prompt"', preg_match('/isPromptOnlyModule\(template\.module\) \? \([\s\S]{0,300}?\{template\.module === "video" \? "—" : "Prompt"\}/', $row) === 1);
check('CSV import lands prompt-only modules as prompt', strpos($idx, 'entries: [{ category: isPromptOnlyModule(module) ? "prompt" : (subtype ?? "default"), value }]') !== false);
check('strategy dialog fit classifier reads EVERY writer entry (an old reference_ad row is classified by its text)', preg_match("/for \(const e of entries\) \{\s*\r?\n\s*\/\/[^\n]*\n\s*\/\/[^\n]*\n\s*if \(POST_VAR_RE\.test/", $csd) === 1 && strpos($csd, "if ((e?.category ?? '') !== 'prompt') continue;") === false, 'classifier still skips non-prompt writer entries');
// The shared site+business half is still composed on both sides (08-15 must not regress).
check('Writer still composes the shared site+business vocabulary', preg_match('/const WRITER_VARS[\s\S]*?\.\.\.SITE_BUSINESS_VARS,\s*\};/', $tv) === 1);
check('…and build_prompt() still merges PCM_Content_Vars::site_business', strpos($svc, 'PCM_Content_Vars::site_business($brand ? (int) ($brand->id ?? 0) : null, null)') !== false);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
