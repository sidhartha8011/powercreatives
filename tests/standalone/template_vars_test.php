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

/**
 * Pull the TOKENS out of a `const NAME: Record<string, string> = { '…': '…' };`.
 *
 * Matches only quoted strings followed by a colon, i.e. the map KEYS. A blanket
 * "every quoted string" would also sweep up the descriptions and report them as
 * variables.
 */
function ts_list(string $s, string $name): array {
    if (!preg_match('/const\s+' . preg_quote($name, '/') . '\s*(?::[^=]*)?=\s*\{(.*?)\n\};/s', $s, $m)) return array();
    preg_match_all("/'([^']+)'\s*:/", $m[1], $i);
    $out = $i[1];
    // Follow the shared-map spread, exactly like ts_map — otherwise the substitution
    // check below silently skips every composed token (16 per composing module).
    if ($name !== 'SITE_BUSINESS_VARS' && strpos($m[1], '...SITE_BUSINESS_VARS,') !== false) {
        $out = array_values(array_unique(array_merge($out, ts_list($s, 'SITE_BUSINESS_VARS'))));
    }
    return $out;
}

/** Token => description for a module's map, so the prose can be checked too. */
function ts_map(string $s, string $name): array {
    if (!preg_match('/const\s+' . preg_quote($name, '/') . '\s*(?::[^=]*)?=\s*\{(.*?)\n\};/s', $s, $m)) return array();
    preg_match_all("/'([^']+)'\s*:\s*'((?:[^'\\\\]|\\\\.)*)'/", $m[1], $i, PREG_SET_ORDER);
    $out = array();
    foreach ($i as $pair) { $out[$pair[1]] = $pair[2]; }
    // Blocks may COMPOSE the shared site+business map instead of re-declaring it
    // (PCM_Content_Vars). Resolve the spread, or those tokens would look unoffered
    // and every check below would skip them.
    if ($name !== 'SITE_BUSINESS_VARS' && strpos($m[1], '...SITE_BUSINESS_VARS,') !== false) {
        $out += ts_map($s, 'SITE_BUSINESS_VARS');
    }
    return $out;
}
/** `{{ x }}` → `x`. */
function key_of(string $token): string { return trim(str_replace(array('{{', '}}'), '', $token)); }

/** Concatenated PHP of a module — EVERY file, not just its service.php. */
function module_php(string $dir): string {
    if (!is_dir($dir)) return '';
    $out = '';
    // The shared site+business map is defined in core and composed by modules —
    // include it so its keys count as substituted for whoever composes it.
    $shared = dirname(__DIR__, 2) . '/includes/core/class-pcm-content-vars.php';
    if (is_file($shared)) { $out .= file_get_contents($shared) . "\n"; }
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
// Assert the RULE, not one spelling of it: `=== 'prompt'` and `!== 'prompt'`
// are the same gate, and the empty default is `[]` or `{}` depending on whether
// the vocabulary is stored as a list or a token=>description map.
check('only prompt-category entries get variables',
    (bool) preg_match("/category\s*[!=]==\s*'prompt'/", $src));
check('varsFor is an allow-list with an empty default',
    (bool) preg_match('/default:\s*return\s*(\[\]|\{\});/', $src));

echo "\n4b. Every variable carries a usable description\n";
// The menu shows the description beside the token; a blank or stub one is worse
// than none because it takes up a row and teaches nothing.
$desc_problems = array();
$total_desc = 0;
foreach ($MODULES as $mod => $cfg) {
    foreach (ts_map($src, $cfg[0]) as $token => $desc) {
        $total_desc++;
        $d = trim($desc);
        if ($d === '')                       { $desc_problems[] = "$mod $token: empty"; }
        elseif (strlen($d) < 12)             { $desc_problems[] = "$mod $token: too short (\"$d\")"; }
        elseif (rtrim($d, '.') === trim(key_of($token))) { $desc_problems[] = "$mod $token: just restates the token"; }
    }
}
check('every token has a real description', $desc_problems === array(), $desc_problems);
// 97 → 112: the phantom {{site_name}} was removed (−1, nothing ever substituted it)
// and Writer gained the 16 shared site+business tokens it was missing (+16) — the
// Templates/Writer-Templates card. Counted through the ...SITE_BUSINESS_VARS spread.
// 112 → 113 (2026-08-18, cards 5/13): Writer gained {{ primary_keyword }} — SEO's name for the
// item keyword, substituted by build_prompt() as a plain value (alias of {{ keyword }}).
check('description count matches token count', $total_desc === 113, $total_desc);

echo "\n4c. The menu renders the description and can scroll sideways\n";
$menu = file_get_contents($ROOT . '/app/src/modules/Templates/SlashVariableMenu.tsx');
check('row renders the description', str_contains($menu, '{description}'), 'not rendered');
check('description also offered as a tooltip', str_contains($menu, 'title={description}'), 'no title attr');
// One line + a horizontally scrollable track is what lets a long sentence be read
// by scrolling instead of wrapping every row and burying the list.
check('rows stay on one line', str_contains($menu, 'whitespace-nowrap'), 'rows would wrap');
check('list scrolls both ways', str_contains($menu, 'overflow-auto'), 'no horizontal scroll');
check('rows share the widest row’s width', str_contains($menu, 'w-max min-w-full'), 'ragged highlight');
// li must be a DIRECT child of the ul, or the arrow-key scrollIntoView lookup
// (children[active]) points at a wrapper instead of the highlighted row.
check('no wrapper between ul and li', !preg_match('/<ul[^>]*>\s*\{?\s*<div/', $menu), 'wrapper breaks children[active]');

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
