<?php
/**
 * Template variable chips — vocabulary contract.
 *
 * The chips in TemplateVarChips.tsx are click-to-insert, so every token offered
 * MUST be one the backend actually substitutes. A stale list is worse than no
 * list: the author pastes `{{business.email}}`, nothing errors, and the literal
 * text ends up in the prompt sent to the model.
 *
 * This pins the frontend list against the two server-side resolvers:
 *
 *   SEO    → PCM_SEO_AI::build_field_vars() builds the map, and the caller adds
 *            `current_value` before PCM_SEO_AI::substitute_vars() runs. That
 *            substitution is a LITERAL str_replace('{{' . $key . '}}'), so SEO
 *            tokens must carry NO inner spaces or they silently never resolve.
 *
 *   Writer → PCM_Strategy_Service::build_prompt(), documented lowercase and
 *            whitespace-tolerant, so `{{ post_title }}` is fine there.
 *
 * Run: php tests/standalone/template_var_chips_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$ROOT = dirname(__DIR__, 2);
$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

// ── Read the frontend lists ────────────────────────────────────────────────
$chips = file_get_contents($ROOT . '/app/src/modules/Templates/TemplateVarChips.tsx');

/** Pull a `const NAME = [ '...', ... ];` string array out of the TSX. */
function tsx_list(string $src, string $name): array {
    if (!preg_match('/const\s+' . preg_quote($name, '/') . '\s*=\s*\[(.*?)\];/s', $src, $m)) {
        return array();
    }
    preg_match_all("/'([^']+)'/", $m[1], $items);
    return $items[1];
}

$seo_chips    = tsx_list($chips, 'SEO_VARS');
$writer_chips = tsx_list($chips, 'WRITER_VARS');

check('SEO_VARS parsed from the component', count($seo_chips) > 0, count($seo_chips));
check('WRITER_VARS parsed from the component', count($writer_chips) > 0, count($writer_chips));

// ── The SEO source of truth: keys built by build_field_vars() ──────────────
$ai = file_get_contents($ROOT . '/includes/modules/seo/ai.php');
$start = strpos($ai, 'function build_field_vars');
check('build_field_vars() found in seo/ai.php', $start !== false);

// Take a generous window from the function start and harvest quoted array keys.
$body = substr($ai, $start, 6000);
preg_match_all("/'([a-z_][a-z0-9_.|]*)'\s*=>/i", $body, $km);
$seo_keys = array_values(array_unique($km[1]));
// The caller assigns this after building the map, so it is equally available.
$seo_keys[] = 'current_value';

check('harvested a plausible SEO key set', count($seo_keys) >= 15, count($seo_keys));

echo "\n1. Every SEO chip is a key the backend substitutes\n";
$unknown = array();
foreach ($seo_chips as $tok) {
    $key = trim($tok, '{}');
    if (!in_array($key, $seo_keys, true)) { $unknown[] = $tok; }
}
check('no SEO chip advertises an unknown variable', $unknown === array(), $unknown);

echo "\n2. SEO tokens carry no inner spaces (substitute_vars is literal)\n";
$spaced = array_values(array_filter($seo_chips, fn($t) => trim($t, '{}') !== trim(trim($t, '{}'))));
check('no spaced SEO token', $spaced === array(), $spaced);
// And prove the resolver really is space-intolerant, so this rule is not folklore.
check('substitute_vars uses a literal str_replace',
    str_contains($ai, "str_replace('{{' . \$key . '}}'"), 'pattern not found');

echo "\n3. Every Writer chip is referenced by the strategy prompt builder\n";
$svc = file_get_contents($ROOT . '/includes/modules/strategy/service.php');
$missing = array();
foreach ($writer_chips as $tok) {
    $key = trim(trim($tok, '{}'));
    if (!str_contains($svc, $key)) { $missing[] = $tok; }
}
check('no Writer chip is unknown to build_prompt()', $missing === array(), $missing);

echo "\n4. Writer spacing is safe because that side is whitespace-tolerant\n";
check('service.php documents the tolerance',
    str_contains($svc, 'whitespace-tolerant'), 'claim not documented in source');

echo "\n5. The two vocabularies stay separate\n";
// Advertising Writer tokens on an SEO template (or vice versa) is the exact
// mistake SourceVarsHint's docblock warns about.
$overlap = array_intersect(
    array_map(fn($t) => trim(trim($t, '{}')), $seo_chips),
    array_map(fn($t) => trim(trim($t, '{}')), $writer_chips)
);
// output_format legitimately exists in both vocabularies; anything else is suspect.
$overlap = array_values(array_diff($overlap, array('output_format')));
check('no unexpected cross-vocabulary token', $overlap === array(), $overlap);
check('varsFor() is an allow-list, not a default',
    str_contains($chips, "if (module === 'writer')") && str_contains($chips, "return [];"), 'allow-list shape not found');

echo "\n6. Both value editors render the chips\n";
foreach (array('TemplateRow.tsx', 'TemplateDialog.tsx') as $f) {
    $src = file_get_contents($ROOT . '/app/src/modules/Templates/' . $f);
    // Match the MODULE, not one exact named-import spelling — TemplateRow also
    // pulls templateVarsFor from here for the "/" typeahead, and an over-specific
    // substring turned that into a false failure.
    check("$f imports from ./TemplateVarChips and renders it",
        str_contains($src, 'from "./TemplateVarChips"') && str_contains($src, '<TemplateVarChips'));
}

echo "\n7. Only prompt-category entries get chips\n";
check('gated on category === prompt', str_contains($chips, "category === 'prompt'"), 'gate not found');

echo "\n" . str_repeat('─', 52) . "\n";
echo "  passed: $PASS   failed: $FAIL\n";
echo "  SEO chips: " . count($seo_chips) . " / backend keys: " . count($seo_keys) . "\n";
exit($FAIL > 0 ? 1 : 0);
