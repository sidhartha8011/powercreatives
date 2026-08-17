<?php
/**
 * "Write in Chinese" works on EMPTY cells but not on OVERWRITE; and a named
 * language "doesn't work sometimes" (owner, 2026-08-17).
 *
 * TWO defects, both EXECUTED here:
 *
 *  1. THE TEMPLATE FOLLOWED THE MODE, NOT THE FIELD. Templates are typed per
 *     section (meta_title_generate OR meta_title_optimize) and the resolver
 *     matches on type. Empty cell → generate → the user's Chinese template →
 *     Chinese. Filled cell → optimize → no user row of THAT type → the shipped
 *     English default → English. Now: when a section resolves to only the
 *     shipped default, the user's OWN template for the sibling mode carries
 *     over, framed for this mode (optimize gets the current value appended).
 *     Only the user's rows carry over — never a shipped default.
 *
 *  2. THE LANGUAGE LAW COMPETED WITH THE TEMPLATE. It said "write in the SAME
 *     language as the existing content" with a soft trailing "override if the
 *     instructions name a language" — while {{current_value}} sat there in
 *     Swedish. Two instructions, one coin flip per call. Now the law READS the
 *     template: a named language flips it into a mandatory reinforcement of
 *     THAT language; the default only applies when none is named.
 *
 * Run: php tests/standalone/seo_template_language_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
$ROOT = dirname(__DIR__, 2);

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

function wp_json_encode($v) { return json_encode($v); }
class PCM_Schema { public static function table($n) { return 'wp_pcm_' . $n; } }
class WPDB_Stub {
    public $rows = array();
    public function prepare($q, ...$a) { return json_encode(array($q, $a)); }
    public function get_results($q, $t = null) {
        list($sql, $args) = json_decode($q, true);
        $uid = (int) ($args[0] ?? 0);
        $rows = $this->rows;
        if (strpos($sql, 'OR userId = 0') !== false) {
            $rows = array_filter($rows, static fn($r) => (int) $r['userId'] === $uid || (int) $r['userId'] === 0);
        } elseif (strpos($sql, 'WHERE userId = %d') !== false) {
            $rows = array_filter($rows, static fn($r) => (int) $r['userId'] === $uid);
        }
        return array_values($rows);
    }
    public function get_var($q) { return null; }   // no legacy overrides
    public function get_col($q) { $o = array(); foreach ($this->rows as $r) { if ((int) $r['userId'] === 0) { $o[] = $r['formData']; } } return $o; }
    public function insert($t, $d, $f = null) { return 1; }
}
$GLOBALS['wpdb'] = new WPDB_Stub();

$src = file_get_contents($ROOT . '/includes/modules/seo/ai.php');
$grab = static function (string $name) use ($src): string {
    $i = strpos($src, ' function ' . $name . '(');
    if ($i === false) { fwrite(STDERR, "method {$name} not found\n"); exit(1); }
    $start = strrpos(substr($src, 0, $i), "\n") + 1;
    $next = strlen($src);
    foreach (array("\n    public static function ", "\n    private static function ", "\n    public function ", "\n    private function ") as $mk) {
        $n = strpos($src, $mk, $i + 1);
        if ($n !== false && $n < $next) { $next = $n; }
    }
    $fn = substr($src, $start, $next - $start);
    $doc = strrpos($fn, "\n    /**");
    if ($doc !== false && strpos($fn, '}', $doc) === false) { $fn = substr($fn, 0, $doc); }
    return str_replace(array('private static', 'private function'), array('public static', 'public function'), $fn);
};
$body = '';
foreach (array('resolve_prompt', 'seed_seo_templates', 'seo_template_prompt', 'seo_user_template_prompt', 'frame_for_mode',
               'seo_entry_prompt', 'seo_section_label', 'prompt_demands_envelope', 'is_scalar_section',
               'template_language', 'language_law') as $m) {
    $body .= $grab($m) . "\n";
}
$body .= ' public static function get_default_prompts() { return array(
    "meta_title_generate" => "Generate an SEO-optimized meta title for this page. Page Title: {{title}}",
    "meta_title_optimize" => "Optimize the following meta title for SEO. Current Meta Title: {{current_value}} Page Title: {{title}}",
    "meta_description_generate" => "Generate a meta description. Page Title: {{title}}",
    "meta_description_optimize" => "Optimize the following meta description. Current: {{current_value}}",
); }';
eval('class LangHost { ' . $body . ' }');
check('resolver + law hosted', method_exists('LangHost', 'resolve_prompt') && method_exists('LangHost', 'language_law'));

$D = LangHost::get_default_prompts();
$row = static fn(int $id, int $user, string $type, string $value, int $default = 0) => array(
    'id' => $id, 'userId' => $user, 'isDefault' => $default, 'updatedAt' => '2026-08-17 10:00:00',
    'formData' => json_encode(array('type' => $type, 'entries' => array(array('category' => 'prompt', 'value' => $value)))),
);
$system = array(); $sid = 900;
foreach ($D as $section => $p) { $system[] = $row($sid++, 0, $section, $p, 1); }
$CHINESE = 'Write an SEO meta title for the page "{{title}}". Write in Chinese. Max 60 characters.';

echo "\n1. THE REPORTED BUG — the user's Chinese template, typed for GENERATE\n";
$GLOBALS['wpdb']->rows = array_merge($system, array($row(500, 7, 'meta_title_generate', $CHINESE, 1)));
$g = LangHost::resolve_prompt('meta_title_generate', $D['meta_title_generate'], 7, null);
check('empty cell (generate) → the Chinese template (this always worked)', $g === $CHINESE, $g);
$o = LangHost::resolve_prompt('meta_title_optimize', $D['meta_title_optimize'], 7, null);
check('FILLED cell (optimize) → the Chinese template CARRIES OVER (was: shipped English default)',
    strpos($o, 'Write in Chinese') !== false, $o);
check('…framed for optimize: the current value is handed to the model', strpos($o, '{{current_value}}') !== false, $o);
check('…and the frame keeps the author\'s instructions authoritative', strpos($o, 'keep the same language and instructions above') !== false, $o);
check('the shipped optimize default is NOT what ran', $o !== $D['meta_title_optimize']);

echo "\n2. …and the mirror: a template typed for OPTIMIZE covers empty cells too\n";
$GLOBALS['wpdb']->rows = array_merge($system, array($row(501, 7, 'meta_description_optimize', 'Rewrite this description in Chinese: {{current_value}}', 1)));
$g = LangHost::resolve_prompt('meta_description_generate', $D['meta_description_generate'], 7, null);
check('empty cell → the optimize-typed Chinese template carries over', strpos($g, 'in Chinese') !== false, $g);
check('…unframed (generate needs no current value)', $g === 'Rewrite this description in Chinese: {{current_value}}', $g);

echo "\n3. Boundaries — what must NOT carry over\n";
$GLOBALS['wpdb']->rows = $system;   // user has NO templates at all
$o = LangHost::resolve_prompt('meta_title_optimize', $D['meta_title_optimize'], 7, null);
check('no user template anywhere → the shipped default of THIS mode (never the sibling shipped default)',
    $o === $D['meta_title_optimize'], $o);
// This section HAS a user template → it wins; the sibling is irrelevant.
$GLOBALS['wpdb']->rows = array_merge($system, array(
    $row(502, 7, 'meta_title_generate', 'GEN in Chinese', 1),
    $row(503, 7, 'meta_title_optimize', 'OPT in Swedish', 1),
));
check('a user template for THIS mode beats the sibling', LangHost::resolve_prompt('meta_title_optimize', $D['meta_title_optimize'], 7, null) === 'OPT in Swedish');
// An explicit pick for this section wins outright.
$GLOBALS['wpdb']->rows = array_merge($system, array($row(504, 7, 'meta_title_optimize', 'PICKED', 0), $row(505, 7, 'meta_title_generate', 'GEN Chinese', 1)));
check('an explicit pick for this section wins over the sibling', LangHost::resolve_prompt('meta_title_optimize', $D['meta_title_optimize'], 7, 504) === 'PICKED');
// Another user's template never leaks.
$GLOBALS['wpdb']->rows = array_merge($system, array($row(506, 8, 'meta_title_generate', 'USER 8 Chinese', 1)));
check("another user's sibling template never carries over", LangHost::resolve_prompt('meta_title_optimize', $D['meta_title_optimize'], 7, null) === $D['meta_title_optimize']);
// The eligibility law still governs a carried-over envelope.
$ENV = 'OUTPUT FORMAT (mandatory): respond with ONLY this JSON: {"html":"…","changes":[]}';
$GLOBALS['wpdb']->rows = array_merge($system, array($row(507, 7, 'meta_title_generate', $ENV, 1)));
check('an envelope sibling is still ineligible for a scalar section', LangHost::resolve_prompt('meta_title_optimize', $D['meta_title_optimize'], 7, null) === $D['meta_title_optimize']);

echo "\n4. frame_for_mode — EXECUTED\n";
check('optimize + no {{current_value}} → appended', strpos(LangHost::frame_for_mode('Write X', 'optimize'), 'Current value: {{current_value}}') !== false);
check('optimize + already references it → untouched', LangHost::frame_for_mode('Improve {{current_value}}', 'optimize') === 'Improve {{current_value}}');
check('generate → untouched', LangHost::frame_for_mode('Write X', 'generate') === 'Write X');

echo "\n5. THE LAW reads the template — a named language is REINFORCED, not defaulted away\n";
$law = LangHost::language_law(array('site.lang' => 'sv'), $CHINESE);
check('with "Write in Chinese" in the template, the law names Chinese as MANDATORY', stripos($law, 'LANGUAGE (mandatory)') !== false && stripos($law, 'Chinese') !== false, $law);
check('…and forbids answering in any other language', stripos($law, 'Do not answer in any other language') !== false, $law);
check('…even though the SITE hint is sv (existing content is Swedish)', stripos($law, 'even if the existing content') !== false, $law);
check('…the competing "same language as the existing content" default is GONE', stripos($law, 'same language as the existing content') === false, $law);
$law2 = LangHost::language_law(array('site.lang' => 'sv'), 'Generate an SEO-optimized meta title for this page. Page Title: {{title}}');
check('with NO language named, the default (existing-content language) still applies', stripos($law2, 'LANGUAGE — default') !== false && stripos($law2, 'same language as the existing content') !== false, $law2);
check('the old override clause is preserved for that case', stripos($law2, 'EXPLICITLY name a language') !== false);
check('no template passed (site-level callers) → the default, unchanged', LangHost::language_law(array('site.lang' => 'sv')) === $law2);

echo "\n6. template_language — detection breadth + precision\n";
foreach (array(
    'Write in Chinese.' => 'Chinese', 'Please answer in Swedish' => 'Swedish', 'Output in German only' => 'German',
    'Skriv på svenska' => 'svenska', 'Schreibe auf Deutsch' => 'Deutsch', 'Escribe en español' => 'español',
    'Language: French' => 'French', 'Respond in Japanese please' => 'Japanese', 'Translate into Norwegian' => 'Norwegian',
    'Write the meta title in Chinese (simplified)' => 'Chinese',
) as $tpl => $want) {
    check("detects: $tpl → $want", strcasecmp(LangHost::template_language($tpl), $want) === 0, LangHost::template_language($tpl));
}
foreach (array(
    'Generate an SEO-optimized meta title for this page. Page Title: {{title}}',
    'Include the primary keyword naturally. Output ONLY the title.',
    'Language: {{site.lang}}',                       // a VARIABLE, not a named language
    // A variable whose NAME looks like a language: without the {{…}} strip this
    // would match "in english" and pin the answer to English on a Swedish site.
    'Write it in {{english}} tone.',
    'Answer {{in Swedish}} where relevant.',
    'The English Premier League fixtures page',      // a noun, not an instruction
    'Best French bakery in Göteborg — write a title', // adjective in the topic
) as $tpl) {
    check('NOT detected: ' . mb_substr($tpl, 0, 50), LangHost::template_language($tpl) === '', LangHost::template_language($tpl));
}

echo "\n7. The generate paths pass the TEMPLATE into the law\n";
check('local generate_field passes $tpl to language_law', strpos($src, 'self::substitute_vars($tpl . self::language_law($vars, $tpl), $vars)') !== false, 'local law blind to the template');
$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');
check('remote generate passes $tpl to language_law', strpos($svc, 'PCM_SEO_AI::substitute_vars($tpl . PCM_SEO_AI::language_law($vars, $tpl), $vars)') !== false, 'remote law blind to the template');
check('the heal retry passes the default it uses', strpos($src, 'self::language_law($vars, $default_tpl)') !== false);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
