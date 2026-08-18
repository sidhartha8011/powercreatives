<?php
/**
 * Templates — Writer templates share the SITE + BUSINESS vocabulary.
 *
 * Owner: "The writer templates should essentially share the variables that it
 * can have coming from the site and the business… There should be some sort of
 * centralized place where all these variables are for their respective module,
 * so that we always get the right variables. Right now, we're lacking variables
 * that we currently have in SEO, but we don't have it in writer."
 *
 * The centralized place is PCM_Content_Vars::site_business(). SEO's
 * build_field_vars() and Writer's build_prompt() both compose it, and the
 * Templates "/" typeahead offers exactly what those resolvers substitute — an
 * advertised-but-unresolved token would paste text that silently survives into
 * the prompt sent to the model.
 *
 * The shared builder AND the Writer renderer are EXECUTED here; the PHP↔TS
 * parity is machine-checked in both directions.
 *
 * Run: php tests/standalone/template_vars_parity_test.php
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

// ── WP stubs ────────────────────────────────────────────────────────────────
class PCM_Schema { public static function table($n) { return 'wp_pcm_' . $n; } }
function home_url($p = '/') { return 'https://acme.test/'; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function get_bloginfo($k) { return $k === 'name' ? 'Acme Site' : 'Acme tagline'; }
function get_locale() { return $GLOBALS['locale'] ?? 'en_US'; }
class WPDB_Stub {
    public $brand = null;
    public function prepare($q, ...$a) { return $q; }
    public function get_row($q) { return $this->brand; }
}
$GLOBALS['wpdb'] = new WPDB_Stub();
$GLOBALS['gbp'] = array();
class PCM_SEO_GBP {
    public static function get_for_brand($id) { return array('resolved' => $GLOBALS['gbp']); }
}
require $ROOT . '/includes/core/class-pcm-content-vars.php';

echo "\n1. The shared vocabulary — EXECUTED\n";
$GLOBALS['wpdb']->brand = null;
$vars = PCM_Content_Vars::site_business(null, '');
check('no brand → falls back to this site', $vars['business.name'] === 'Acme Site' && $vars['website.url'] === 'https://acme.test/', $vars);
check('hostname variant strips the scheme', $vars['business.website|hostname'] === 'acme.test', $vars);
check('a missing business detail is EMPTY, never a leaked token', $vars['business.phone'] === '' && $vars['business.lat'] === '', $vars);

$GLOBALS['wpdb']->brand = (object) array('name' => 'Acme Brand', 'language' => 'Swedish');
$GLOBALS['gbp'] = array(
    'name' => 'Acme Massage AB', 'phone' => '+46 31 123', 'address' => 'Storgatan 1',
    'category' => 'Massage therapist', 'hours' => 'Mon–Fri 9–17', 'description' => 'Massage i Göteborg',
    'rating' => 4.8, 'lat' => 57.7, 'lng' => 11.97, 'types' => array('spa', 'massage'),
    'website' => 'https://acme.se',
);
$vars = PCM_Content_Vars::site_business(5, null);
check('GBP name wins over brand and site', $vars['business.name'] === 'Acme Massage AB', $vars);
check('phone/address/category/hours resolve', $vars['business.phone'] === '+46 31 123' && $vars['business.address'] === 'Storgatan 1'
    && $vars['business.category'] === 'Massage therapist' && $vars['business.hours'] === 'Mon–Fri 9–17', $vars);
check('types list is joined', $vars['business.types'] === 'spa, massage', $vars);
check('numeric fields are stringified', $vars['business.rating'] === '4.8' && $vars['business.lat'] === '57.7', $vars);
check('lang = null → the BRAND language (the site the content is FOR)', $vars['site.lang'] === 'Swedish', $vars);
check("lang = '' → the hub locale (SEO's long-standing behaviour)",
    PCM_Content_Vars::site_business(5, '')['site.lang'] === 'en', PCM_Content_Vars::site_business(5, '')['site.lang']);
check('an explicit language is passed through verbatim',
    PCM_Content_Vars::site_business(5, 'Norwegian')['site.lang'] === 'Norwegian');
check('today is an ISO date', preg_match('/^\d{4}-\d{2}-\d{2}$/', $vars['today']) === 1, $vars['today']);

echo "\n2. SEO composes it — the map keeps its shape\n";
$ai = file_get_contents($ROOT . '/includes/modules/seo/ai.php');
check('build_field_vars composes the shared map', strpos($ai, "+ PCM_Content_Vars::site_business(\$brand_id, '')") !== false, 'still inline');
check('the inline duplicate is gone', strpos($ai, "'business.website|hostname' => \$host,") === false, 'duplicate survives');
check('post-specific tokens stay with SEO',
    strpos($ai, "'primary_keyword'           => PCM_SEO_Local::seo_get(\$post_id, 'keyword')") !== false
    && strpos($ai, "'post_type'                 => \$post ? \$post->post_type : ''") !== false);
// The union order must keep POST keys first (they are the ones a caller overrides).
check("SEO pins {{site.lang}} to the hub locale (unchanged behaviour)",
    strpos($ai, "site_business(\$brand_id, '')") !== false, 'behaviour changed');

echo "\n3. Writer resolves them — the REAL renderer, EXECUTED\n";
$svc = file_get_contents($ROOT . '/includes/modules/strategy/service.php');
check('build_prompt merges the shared map', strpos($svc, 'PCM_Content_Vars::site_business($brand ? (int) ($brand->id ?? 0) : null, null)') !== false, 'writer still lacks them');
check('…guarded, so a missing class degrades instead of fatals', strpos($svc, "class_exists('PCM_Content_Vars')") !== false);
check('they are NOT fragment vars (never auto-appended)',
    preg_match("/\\\$fragment_vars\s*=\s*array\('keyword', 'brand_context', 'research', 'output_format', 'media_instructions'\)/", $svc) === 1, 'shared vars became fragments');

// Execute the REAL render_template_vars against the REAL shared map.
$i = strpos($svc, 'private static function render_template_vars');
$start = strrpos(substr($svc, 0, (int)$i), "\n") + 1;
$j = strpos($svc, "\n    private static function", (int)$i + 10);
$fn = substr($svc, $start, (int)$j - $start);
if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
eval('class RenderHost { ' . str_replace('private static', 'public static', $fn) . ' }');

$tpl = "Write for {{business.name}} in {{site.lang}}.\nCall {{business.phone}} — {{business.address}}.\n"
     . "Site: {{business.website|hostname}}. Date: {{today}}. Missing: [{{business.tagline2}}]";
$out = RenderHost::render_template_vars($tpl, $vars, array());
check('{{business.name}} resolves in a WRITER prompt', strpos($out, 'Acme Massage AB') !== false, $out);
check('{{site.lang}} resolves', strpos($out, 'in Swedish.') !== false, $out);
check('{{business.phone}} + {{business.address}} resolve', strpos($out, '+46 31 123 — Storgatan 1') !== false, $out);
check('the PIPED token resolves (regex alternation backtracks correctly)',
    strpos($out, 'Site: acme.test.') !== false, $out);
check('an unknown token is left ALONE (never blanked silently)', strpos($out, '{{business.tagline2}}') !== false, $out);
check('no raw shared token survives', preg_match('/\{\{\s*(business\.|site\.|today)/', str_replace('{{business.tagline2}}', '', $out)) === 0, $out);
// Whitespace tolerance is a Writer promise — the same token spaced must resolve.
$out2 = RenderHost::render_template_vars('X {{ business.name }} Y', $vars, array());
check('Writer stays whitespace-tolerant for the shared tokens', trim($out2) === 'X Acme Massage AB Y', $out2);

echo "\n4. PHP ↔ typeahead parity (both directions)\n";
$ts = file_get_contents($ROOT . '/app/src/modules/Templates/templateVars.ts');
preg_match('/const SITE_BUSINESS_VARS: Record<string, string> = \{(.*?)\n\};/s', $ts, $bm);
preg_match_all("/'\{\{([^}]+)\}\}'\s*:/", (string) ($bm[1] ?? ''), $tm);
$ts_shared = array_map('trim', $tm[1]);
$php_shared = PCM_Content_Vars::keys();
sort($ts_shared); $php_sorted = $php_shared; sort($php_sorted);
check('the typeahead advertises EXACTLY what PHP substitutes', $ts_shared === $php_sorted,
    array('ts' => array_diff($ts_shared, $php_sorted), 'php' => array_diff($php_sorted, $ts_shared)));
check('keys() is derived from the map itself (cannot drift)',
    strpos(file_get_contents($ROOT . '/includes/core/class-pcm-content-vars.php'), 'return array_keys(self::site_business(null, \'\'));') !== false);
// Slice each vocabulary BLOCK and assert against it. An unanchored whole-file
// search would be satisfied by the OTHER block's spread — it would pass while the
// block under test had lost it (the "somewhere in region" trap).
$block = static function (string $ts, string $name): string {
    $at = strpos($ts, 'const ' . $name);
    if ($at === false) { return ''; }
    $end = strpos($ts, "\n};", $at);
    return $end === false ? '' : substr($ts, $at, $end - $at + 3);
};
$wblk = $block($ts, 'WRITER_VARS');
$sblk = $block($ts, 'SEO_VARS');
check('both vocabulary blocks located', $wblk !== '' && $sblk !== '');
check('WRITER vocabulary composes the shared map',
    strpos($wblk, '...SITE_BUSINESS_VARS,') !== false, 'writer still short');
check('SEO vocabulary composes the SAME map (one definition)',
    strpos($sblk, '...SITE_BUSINESS_VARS,') !== false, 'seo duplicated');
// One shared definition, and NEITHER composing block may re-declare a business.*
// literal. (Optimizer keeps its own vocabulary on purpose — a different resolver.)
check('the shared block is defined exactly once', substr_count($ts, 'const SITE_BUSINESS_VARS') === 1);
check('WRITER_VARS declares no business.* literal (it composes instead)',
    strpos($wblk, "'{{business.") === false, $wblk);
check('SEO_VARS declares no business.* literal (it composes instead)',
    strpos($sblk, "'{{business.") === false, $sblk);
// The phantom: advertised, never substituted anywhere in PHP.
check('the phantom {{site_name}} is gone from the vocabulary', strpos($ts, "'{{site_name}}'") === false, 'phantom survives');
check('…and it really is unresolved in PHP (so removing it was right)',
    strpos($ai, "'site_name'") === false && strpos($svc, "'site_name'") === false);

echo "\n5. Authors can discover them\n";
$hint = file_get_contents($ROOT . '/app/src/modules/Templates/SourceVarsHint.tsx');
$hint_flat = preg_replace('/\s+/u', ' ', $hint);   // JSX prose wraps; match on flattened text
check('the Writer hint names the shared set',
    strpos($hint_flat, 'The site and business variables SEO templates use resolve here too') !== false);
check('…and points at the "/" typeahead for the full list',
    strpos($hint_flat, 'in the value editor for the full list') !== false, 'no pointer to the typeahead');
// 2026-08-18 (cards 5/13): writer is prompt-only — the hint renders for it whatever
// category an old row was saved under; other modules keep the strict prompt gate.
check('the hint renders for writer (any category) and for prompt entries only elsewhere',
    strpos($hint, 'if (module !== "writer" && category !== "prompt") return null;') !== false
    && strpos($hint, 'if (module === "video" || module === "seo" || module === "optimizer") return null;') !== false);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
