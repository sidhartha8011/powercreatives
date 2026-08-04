<?php
/**
 * Template variable vocabulary — contract with the server-side resolvers.
 *
 * app/src/modules/Templates/templateVars.ts feeds the "/" typeahead in the Value
 * editors, so every token it offers is one keypress away from being pasted into a
 * prompt. A stale or invented token is worse than no suggestion at all: nothing
 * errors, the literal `{{whatever}}` just survives into the text sent to the model.
 *
 * Each module's list is therefore pinned against the PHP that substitutes it:
 *
 *   writer    → PCM_Strategy_Service (render_template_vars / build_prompt)
 *   seo       → PCM_SEO_AI::build_field_vars() + current_value
 *   copy      → PCM_Copy_Service. NOTE it has TWO var maps — build_generation_context()
 *               for research prompts, and the ads/organic map that the module=copy
 *               TEMPLATE rows actually flow through. The union is accepted here;
 *               picking the wrong one by hand is exactly the mistake this catches.
 *   image     → PCM_Image_Service::build_image_context() + call-site brief/style
 *   optimizer → PCM_Optimizer_Service render_prompt_vars at the 'compile' site
 *   video     → MUST be empty; that module has no {{ }} engine at all
 *
 * Run: php tests/standalone/template_vars_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$ROOT = dirname(__DIR__, 2);
$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

$vars_ts = $ROOT . '/app/src/modules/Templates/templateVars.ts';
check('templateVars.ts exists', is_file($vars_ts), $vars_ts);
check('the chips component is gone',
    !is_file($ROOT . '/app/src/modules/Templates/TemplateVarChips.tsx'));

$src = file_get_contents($vars_ts);

/** Pull a `const NAME = [ '…' ];` array out of the TS. */
function ts_list(string $s, string $name): array {
    if (!preg_match('/const\s+' . preg_quote($name, '/') . '\s*=\s*\[(.*?)\];/s', $s, $m)) return array();
    preg_match_all("/'([^']+)'/", $m[1], $i);
    return $i[1];
}
/** `{{ x }}` → `x`. */
function key_of(string $token): string { return trim(str_replace(array('{{', '}}'), '', $token)); }

/** Concatenated PHP of a module — EVERY file, not just its service.php. */
function module_php(string $dir): string {
    if (!is_dir($dir)) return '';
    $out = '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $out .= file_get_contents($f->getPathname()) . "\n";
        }
    }
    return $out;
}

/**
 * Every quoted array key, $vars['key'], and literal {{token}} in a module.
 *
 * A module substitutes at SEVERAL sites — seo alone spans ai.php (the field-var
 * map), prompts.php (per-section prompts) and local.php (GBP pages) — so scanning
 * one file per module is what let the vocabulary be short in the first place.
 * Literal {{token}}s count because a DEFAULT prompt shipping a token is proof
 * that the token resolves.
 */
function php_keys(string $php): array {
    if ($php === '') return array();
    preg_match_all("/'([a-zA-Z_][a-zA-Z0-9_.|]*)'\s*=>/", $php, $a);
    preg_match_all("/\\\$(?:vars|prompt_vars)\['([a-zA-Z_][a-zA-Z0-9_]*)'\]/", $php, $b);
    preg_match_all("/\{\{([a-zA-Z_][a-zA-Z0-9_.|]*)\}\}/", $php, $c);
    return array_values(array_unique(array_merge($a[1], $b[1], $c[1])));
}

/**
 * Tokens that only ever appear in DOCBLOCKS explaining the mechanism, plus one
 * real typo. None may be advertised: matching a literal {{token}} would otherwise
 * let a comment vouch for a variable that does not exist.
 * `primary_kw` is recorded in seo/prompts.php as corrected to `primary_keyword`.
 */
const DOC_ONLY = array('key', 'placeholder', 'placeholders', 'var', 'variable', 'primary_kw');

$MODULES = array(
    'writer'    => array('WRITER_VARS',    'includes/modules/strategy'),
    'seo'       => array('SEO_VARS',       'includes/modules/seo'),
    'copy'      => array('COPY_VARS',      'includes/modules/copy'),
    'image'     => array('IMAGE_VARS',     'includes/modules/image'),
    'optimizer' => array('OPTIMIZER_VARS', 'includes/modules/optimizer'),
);

echo "\n1. Every advertised token is a key its module's PHP substitutes\n";
foreach ($MODULES as $mod => $cfg) {
    list($const, $php) = $cfg;
    $tokens = ts_list($src, $const);
    check("$mod: list is non-empty", count($tokens) > 0, count($tokens));

    $module_src = module_php($ROOT . '/' . $php);
    $keys = php_keys($module_src);

    $unknown = array();
    foreach ($tokens as $t) {
        $k = key_of($t);
        // The writer resolver is whitespace-tolerant and its names appear as bare
        // map keys/words rather than literal {{tokens}}, so fall back to a raw
        // source match for that module only.
        $known = in_array($k, $keys, true)
            || ($mod === 'writer' && str_contains($module_src, "'" . $k . "'"));
        if (!$known) { $unknown[] = $t; }
    }
    check("$mod: no invented token", $unknown === array(), $unknown);

    // A docblock must never be able to vouch for a variable.
    $doc = array();
    foreach ($tokens as $t) {
        if (in_array(key_of($t), DOC_ONLY, true)) { $doc[] = $t; }
    }
    check("$mod: no doc-only placeholder advertised", $doc === array(), $doc);
}

echo "\n2. Video offers nothing, because nothing substitutes there\n";
check('no VIDEO_VARS list', ts_list($src, 'VIDEO_VARS') === array());
check("varsFor() has no 'video' case", !preg_match("/case\s+'video'/", $src));
$video = $ROOT . '/includes/modules/video/service.php';
$video_src = is_file($video) ? file_get_contents($video) : '';
check('video service has no substitution engine',
    !str_contains($video_src, 'resolve_prompt_placeholders') && !str_contains($video_src, 'substitute_vars'));

echo "\n3. Token FORM matches each resolver\n";
// Only the writer resolver tolerates spaces; the others are literal
// str_replace('{{' . key . '}}'), so a spaced token there would never resolve.
foreach (array('SEO_VARS', 'COPY_VARS', 'IMAGE_VARS', 'OPTIMIZER_VARS') as $const) {
    $spaced = array_values(array_filter(ts_list($src, $const), fn($t) => key_of($t) !== trim($t, '{}')));
    check("$const: no spaced token", $spaced === array(), $spaced);
}
check('SEO resolver really is a literal str_replace',
    str_contains(file_get_contents($ROOT . '/includes/modules/seo/ai.php'), "str_replace('{{' . \$key . '}}'"));
check('writer side documents its whitespace tolerance',
    str_contains(file_get_contents($ROOT . '/includes/modules/strategy/service.php'), 'whitespace-tolerant'));

echo "\n4. Gating\n";
check('only prompt-category entries get variables', str_contains($src, "category === 'prompt'"));
check('varsFor is an allow-list with an empty default', str_contains($src, 'default: return [];'));

echo "\n5. The chip strip is gone from the Templates module\n";
foreach (array('TemplateRow.tsx', 'TemplateDialog.tsx') as $f) {
    $c = file_get_contents($ROOT . '/app/src/modules/Templates/' . $f);
    check("$f: no 'Available variables' label", !str_contains($c, 'Available variables'), $f);
    check("$f: no TemplateVarChips reference", !str_contains($c, 'TemplateVarChips'), $f);
}
// Automations keeps its own strip — that is the thing being mirrored, not deleted.
check('Automations still has its chip strip',
    str_contains(file_get_contents($ROOT . '/app/src/modules/Automations/index.tsx'),
        'Available variables — click one to copy:'));

echo "\n6. The \"/\" typeahead is wired into BOTH value editors\n";
foreach (array('TemplateRow.tsx', 'TemplateDialog.tsx') as $f) {
    $c = file_get_contents($ROOT . '/app/src/modules/Templates/' . $f);
    check("$f: uses the shared vocabulary", str_contains($c, 'templateVarsFor('), $f);
    check("$f: mounts the menu", str_contains($c, '<SlashVariableMenu'), $f);
    check("$f: routes onChange through the hook",
        str_contains($c, 'SlashVars.onChange') || str_contains($c, 'slashVars.onChange'), $f);
}

echo "\n" . str_repeat('─', 52) . "\n";
echo "  passed: $PASS   failed: $FAIL\n";
foreach ($MODULES as $mod => $cfg) {
    echo "  " . str_pad($mod, 10) . count(ts_list($src, $cfg[0])) . " tokens\n";
}
echo "  video     0 tokens (no resolver)\n";
exit($FAIL > 0 ? 1 : 0);
