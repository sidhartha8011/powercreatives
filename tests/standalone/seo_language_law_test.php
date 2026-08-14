<?php
/**
 * SEO rewrites must come back in the PAGE's language.
 *
 * Reported: "there is a problem in seo module, the changes in content should be
 * in the original language of the website what ever it is" — Swedish client
 * pages (massagegoteborg.nu, seobyra.eu) came back rewritten in English.
 *
 * Two independent causes:
 *  1. NO default prompt named a language, and every prompt AROUND the content
 *     is written in English, so the model answered in the instruction's
 *     language rather than the page's.
 *  2. `site.lang` for a REMOTE site was derived from get_locale() — the HUB's
 *     WordPress locale (the agency dashboard), not the client site being
 *     edited. A Swedish site managed from an English hub reported 'en'.
 *
 * Fix under test: PCM_SEO_AI::language_law() appended to every content path,
 * and remote_field_vars() resolving site.lang from the linked brand (whose
 * language is scraped from that site's own <html lang>).
 *
 * Run: php tests/standalone/seo_language_law_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

$ROOT = dirname(__DIR__, 2);
$SEO  = $ROOT . '/includes/modules/seo/';

// ── 1. The law itself, executed for real ───────────────────────────────────
// Load ONLY the method under test: ai.php pulls in the whole plugin's world,
// but language_law() is pure string work with no dependencies.
$ai_src = file_get_contents($SEO . 'ai.php');
$i = strpos($ai_src, 'public static function language_law');
$j = strpos($ai_src, "\n    }", $i);
$body = substr($ai_src, $i, $j - $i + 6);
eval('class LawHost { ' . $body . ' }');

echo "\n1. The law names the right authority\n";
$law = LawHost::language_law(array('site.lang' => 'sv'));
check('mentions the site language hint when known', str_contains($law, 'sv'), $law);
check('makes the EXISTING CONTENT the authority',
    stripos($law, 'same language as the existing content') !== false, $law);
check('forbids translating', stripos($law, 'do not translate') !== false, $law);
// The instructions are English; that is exactly why the model switched.
check('neutralises the English instructions themselves',
    stripos($law, 'written in English') !== false && stripos($law, 'NOT the target language') !== false, $law);
check('protects proper nouns / URLs from being localised',
    stripos($law, 'proper nouns') !== false, $law);
// SUPERSEDED 2026-08-12. This used to require "overrides everything above", i.e.
// "a custom template cannot outrank it". That stopped the drift into English but
// also ignored an operator who deliberately asked their template for another
// language (reported against SEO → Meta Title → Generate). The law is now a
// strong DEFAULT that yields to an explicit instruction; it must still forbid
// drifting of the model's own accord, which is what section 1 above covers.
check('presents itself as the default, not an absolute',
    stripos($law, 'LANGUAGE — default') !== false, $law);
check('yields to an EXPLICIT language instruction in the template',
    stripos($law, 'EXPLICITLY name a language') !== false
    && stripos($law, 'obey that instruction instead') !== false, $law);
check('still forbids the model switching language unprompted',
    stripos($law, 'on your own initiative') !== false, $law);
// The carve-out must be scoped to an explicit request — a blanket "ignore this"
// would reopen the original bug.
check('the override is conditional, not unconditional',
    stripos($law, 'if the instructions above') !== false, $law);

echo "\n2. It degrades safely when no language is configured\n";
$bare = LawHost::language_law(array());
check('still emitted with no hint', trim($bare) !== '', $bare);
check('no empty parenthetical left behind', !str_contains($bare, '()'), $bare);
check('no stray "the site\'s language is ." fragment',
    !preg_match("/language is\s*[).]/", $bare), $bare);
check('content is still the authority without a hint',
    stripos($bare, 'same language as the existing content') !== false, $bare);
$blank = LawHost::language_law(array('site.lang' => '   '));
check('whitespace-only hint treated as absent', $blank === $bare, $blank);

echo "\n3. EVERY content-producing path appends it\n";
// Rule, not spelling: each of these functions ends in an LLM call that puts
// text on a page, so each must carry the law.
$paths = array(
    'local.php'   => array('run_prompt_section'),          // sections + revise + headings
    'ai.php'      => array('generate_field', 'generate_site_field', 'optimize_body'),
    'service.php' => array('remote_generate_field', 'remote_ai_site_desc', 'build_llm_info'),
);
foreach ($paths as $file => $fns) {
    $src = file_get_contents($SEO . $file);
    foreach ($fns as $fn) {
        $s = strpos($src, "function {$fn}(");
        check("{$file}::{$fn}() found", $s !== false, $fn);
        if ($s === false) { continue; }
        // Window to the function's own LLM call — the law must land before it.
        $call = strpos($src, 'PCM_LLM::invoke', $s);
        $chunk = $call !== false ? substr($src, $s, $call - $s) : substr($src, $s, 4000);
        // Strip comments FIRST: each call site is introduced by a comment that
        // says "see language_law()", and a naive scan is satisfied by that
        // comment alone — deleting the actual call still passed.
        $code = preg_replace('!//[^\n]*|/\*.*?\*/!s', '', $chunk);
        check("{$file}::{$fn}() appends the language law before invoking",
            preg_match('/language_law\s*\(/', $code) === 1, $fn);
    }
}

echo "\n4. The law is defined ONCE (no drifting copies)\n";
$defs = 0; $literals = 0;
foreach (glob($SEO . '*.php') as $f) {
    $src = file_get_contents($f);
    $defs += substr_count($src, 'function language_law');
    // An inlined copy of the wording would drift out of sync with the helper.
    if (basename($f) !== 'ai.php') {
        $literals += substr_count($src, 'LANGUAGE (absolute');
    }
}
check('exactly one definition', $defs === 1, $defs);
check('no inlined copies of the wording elsewhere', $literals === 0, $literals);

echo "\n5. site.lang for a REMOTE site comes from the site, not the hub\n";
$svc = file_get_contents($SEO . 'service.php');
$k = strpos($svc, 'function remote_field_vars');
$rfv = substr($svc, $k, 2500);
check('remote_field_vars no longer derives site.lang from get_locale()',
    !preg_match("/'site\.lang'\s*=>\s*\\\$locale\s*\?/", $rfv), 'still hub-derived');
check('it reads the linked brand language', str_contains($rfv, "\$gbp['language']"), 'brand language unused');
check('hub locale kept only as the fallback', str_contains($rfv, '$locale ? substr($locale, 0, 2)'), 'no fallback');
check('the brand value is NOT substr-mangled ("Swedish" must not become "Sw")',
    preg_match('/\$brand_lang !== \'\'\s*\?\s*\$brand_lang/', $rfv) === 1, 'brand language truncated');
// The LOCAL builder is correct as-is: there the hub IS the site.
$aisrc = file_get_contents($SEO . 'ai.php');
$b = strpos($aisrc, 'function build_field_vars');
// The token now comes from the SHARED builder, which takes an explicit language:
// '' means "use the hub locale" — exactly what this local path has always done.
check('build_field_vars (local posts) still pins site.lang to the hub locale',
    str_contains(substr($aisrc, $b, 2500), "PCM_Content_Vars::site_business(\$brand_id, '')"),
    'local path no longer pins the hub locale');
$cvsrc = file_get_contents(dirname(__DIR__, 2) . '/includes/core/class-pcm-content-vars.php');
check("…and '' really does resolve to the hub locale in the shared builder",
    preg_match("/elseif \\(\\\$lang === ''\\) \\{\\s*\\n\\s*\\\$site_lang = \\\$fallback_lang;/", $cvsrc) === 1,
    'the empty-string contract changed');
check('the shared fallback is still the hub locale, 2-letter',
    str_contains($cvsrc, "\$fallback_lang = \$locale ? substr(\$locale, 0, 2) : 'en';"), 'fallback changed');

echo "\n6. The user's TEMPLATE really does reach the prompt\n";
// Reported as "the prompt in templates are not used" (SEO → Meta Title → Generate).
// It IS used — the law appended after it was overriding its language. Guard the
// whole chain so that stays true, and so the diagnosis is not repeated.
// strpos returning false becomes offset 0 when used as a substr() start, which
// silently slices the TOP of the file and fails every check below for the wrong
// reason. Locate first, assert, then slice.
$gen_at = strpos($aisrc, 'function generate_field(');
check('the generate path is locatable', $gen_at !== false, 'generate_field not found');
$gen = $gen_at === false ? '' : substr($aisrc, $gen_at, 3000);
check('the generate path resolves a prompt for the section',
    preg_match('/\$tpl\s*=\s*self::resolve_prompt\(/', $gen) === 1, 'template never consulted');
$rp = substr($aisrc, strpos($aisrc, 'function resolve_prompt'), 1400);
check('resolve_prompt asks the Templates module first',
    str_contains($rp, 'self::seo_template_prompt($user_id, $section, $template_id)'), 'templates bypassed');
check('a resolved template short-circuits the shipped default',
    preg_match('/if \(\$tpl !== null && \$tpl !== \'\'\) \{\s*\n\s*return \$tpl;/', $rp) === 1, 'default would win');
check('the shipped default is only the last resort',
    strrpos($rp, 'return $default;') > strpos($rp, 'seo_template_prompt'), 'default returned too early');
// ORDERING: the law says "the instructions ABOVE". If it were ever prepended,
// that sentence would point at nothing and the override would silently not work.
// (Spelling changed 2026-08-15: the append is now inline — `$tpl . language_law()`
// inside substitute_vars — because the retry path needs the same composition. The
// ORDER is what matters: template first, law after.)
check('the law is APPENDED after the template, so "above" is true',
    preg_match('/substitute_vars\(\$tpl \. self::language_law\(\$vars\), \$vars\)/', $gen) === 1,
    'law prepended or reordered — "instructions above" would be meaningless');

echo "\n" . str_repeat('-', 56) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
