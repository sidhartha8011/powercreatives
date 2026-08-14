<?php
/**
 * A JSON envelope must never become a scalar SEO field value.
 *
 * Reported with a screenshot: the Meta Title column staged
 *   {"html":"<section> <h1>Welcome to Our Website</h1> …","changes":[{"what":…}]}
 * one "Accept all & save" away from the live site.
 *
 * WHAT THAT IS: the section-REVISE contract (prompts.php `revise_envelope`),
 * a different output shape from these single-value fields. Neither generate
 * path can route Meta Title to it — $use comes straight from field_use_map()
 * on both the local and remote paths — so the prompt behind that column was a
 * template whose TYPE says Meta Title while its VALUE still asks for the
 * envelope (the Templates UI lets Type and Value be edited independently).
 *
 * WHY IT REACHED THE CELL: sanitize_ai_output() picks the first plausible
 * LINE, and an envelope is one long line, so the raw JSON passed straight
 * through. That hole is cause-agnostic — a mis-typed template, a chatty model,
 * or any future prompt mix-up would all land a blob in a meta title. This
 * guards the hole, not the one template.
 *
 * Run: php tests/standalone/seo_envelope_guard_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

// ── Host the REAL methods (same technique as seo_language_law_test.php) ──
$SEO = dirname(__DIR__, 2) . '/includes/modules/seo/';
$src = file_get_contents($SEO . 'ai.php');
// Slice from the declaration to just before the NEXT method. Brace-counting
// cannot be used here: these methods contain braces inside STRING literals
// (is_structured_envelope tests $raw[0] !== '{', sanitize_ai_output uses the
// regex quantifier {1,6}), so a naive counter never balances and swallows the
// following method — which is exactly how this harness first failed to parse.
$grab = function (string $name) use ($src): string {
    $i = strpos($src, ' function ' . $name . '(');
    if ($i === false) { fwrite(STDERR, "method {$name} not found\n"); exit(1); }
    $start = strrpos(substr($src, 0, $i), "\n") + 1;
    $next  = strlen($src);
    foreach (array("\n    public static function ", "\n    private static function ",
                   "\n    public function ", "\n    private function ") as $marker) {
        $n = strpos($src, $marker, $i + 1);
        if ($n !== false && $n < $next) { $next = $n; }
    }
    $chunk = substr($src, $start, $next - $start);
    // End at the closing brace AT METHOD INDENTATION. Searching for the last '}'
    // instead lands inside the FOLLOWING method's docblock whenever that docblock
    // quotes JSON — this one documents {"html":…,"changes":…} and did exactly
    // that, truncating mid-comment.
    if (preg_match('/\n    \}\r?\n/', $chunk, $m, PREG_OFFSET_CAPTURE)) {
        $chunk = substr($chunk, 0, $m[0][1]) . "\n    }";
    }
    return str_replace('private static', 'public static', $chunk);
};
eval('class Host { ' . $grab('is_structured_envelope') . $grab('sanitize_ai_output') . ' }');

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

// The exact payloads from the report.
$REPORTED_1 = '{"html":"<section> <h1>Welcome to Our Website</h1> <p>Your success is our priority! Discover our services below.</p> <ul> <li><a href=\"service1.html\">Service 1</a> </li> </ul> <footer> <p>Contact us at info@ourwebsite.com</p> </footer> </section>","changes":[{"what":"x","why":"y","quote":"z"}]}';
$REPORTED_2 = '{"html":"<section> <h1>Welcome to Our Website</h1> <p>Discover our amazing services and products tailored just for you!</p> <button>Contact Us</button> </section>","changes":[{"what":"Replaced \'amazing services\' with \'exciting offerings\'","why":"to enhance the appeal of the text","quote":"Discover our exciting offerings and products tailored"}]}';

echo "\n1. The reported payloads are refused outright\n";
foreach (array($REPORTED_1, $REPORTED_2) as $i => $raw) {
    $n = $i + 1;
    check("payload #{$n} is recognised as an envelope", Host::is_structured_envelope($raw) === true);
    check("payload #{$n} sanitises to EMPTY, so it cannot be saved", Host::sanitize_ai_output($raw) === '', Host::sanitize_ai_output($raw));
}
// A fenced envelope is the same failure wearing a coat.
check('a ```json fenced envelope is refused too',
    Host::sanitize_ai_output("```json\n" . $REPORTED_2 . "\n```") === '', 'fenced envelope leaked');
check('a bare ``` fenced envelope is refused',
    Host::sanitize_ai_output("```\n{\"html\":\"<p>x</p>\",\"changes\":[]}\n```") === '', 'fenced envelope leaked');

echo "\n2. Any JSON object/array is wrong for a single-value field\n";
foreach (array(
    '{"title":"Some Title"}'                => 'object with a plausible key',
    '{"value":"x","note":"y"}'              => 'multi-key object',
    '["one","two"]'                         => 'array',
    '{}'                                    => 'empty object',
    '[]'                                    => 'empty array',
    '{"html":"<p>only html</p>"}'           => 'envelope without changes[]',
    '{"changes":[]}'                        => 'envelope without html',
) as $raw => $why) {
    check("refused: {$why}", Host::sanitize_ai_output($raw) === '', Host::sanitize_ai_output($raw));
}

echo "\n3. REAL titles still pass through untouched (no over-blocking)\n";
$ok_cases = array(
    'Welcome to Our Website'                                  => 'Welcome to Our Website',
    '  Spaced Title  '                                        => 'Spaced Title',
    '"Quoted Title"'                                          => 'Quoted Title',
    'Meta title: Massage i Göteborg'                          => 'Massage i Göteborg',
    "**Bold Title**"                                          => 'Bold Title',
    '按摩临床医生的研究途径 | Research Perch 86'                  => '按摩临床医生的研究途径 | Research Perch 86',
    'Prices from {$99} — Book Today'                          => 'Prices from {$99} — Book Today',
    'JSON is great: a guide'                                  => 'JSON is great: a guide',
);
foreach ($ok_cases as $raw => $want) {
    $got = Host::sanitize_ai_output((string) $raw);
    check('kept: ' . (mb_strlen((string) $raw) > 34 ? mb_substr((string) $raw, 0, 34) . '…' : $raw), $got === $want, $got);
}
// A title that merely STARTS with a brace but is not JSON must survive.
check('a brace-leading non-JSON title survives',
    Host::sanitize_ai_output('{Not JSON} Best Massage in Town') === '{Not JSON} Best Massage in Town',
    Host::sanitize_ai_output('{Not JSON} Best Massage in Town'));
check('a fenced PLAIN title still unwraps normally',
    Host::sanitize_ai_output("```\nBest Massage in Town\n```") === 'Best Massage in Town',
    Host::sanitize_ai_output("```\nBest Massage in Town\n```"));

echo "\n4. ONE shared invoke reports the real cause — and SELF-HEALS first\n";
// 2026-08-15 (Filip: "individual lines do not work. It complains about HTML
// missing"): the guard moved from two duplicated copies into
// PCM_SEO_AI::invoke_scalar_field, which both paths call. It now retries ONCE
// with the SHIPPED default when a CUSTOMIZED prompt yields the envelope, so a
// mispointed template heals instead of dead-ending; the 422 remains only when
// the default itself envelopes.
$svc = file_get_contents($SEO . 'service.php');
$inv_at = strpos($src, 'function invoke_scalar_field');
check('the shared invoke exists', $inv_at !== false);
$inv = substr($src, (int) $inv_at, 2600);
check('envelope is checked on the RAW output', strpos($inv, 'self::is_structured_envelope($raw)') !== false, 'raw not checked');
check('a customized prompt retries with the shipped default',
    preg_match('/is_structured_envelope\(\$raw\) && \$customized[\s\S]{0,400}?substitute_vars\(\$default_tpl/', $inv) === 1, 'no self-heal');
check('the retry keeps the language law', strpos($inv, '$default_tpl . self::language_law($vars)') !== false, 'law dropped on retry');
check('names Templates → SEO in the message',
    strpos($inv, 'check the template selected for this column in Templates') !== false);
check('422 (bad configuration), not 502 (provider fault)',
    preg_match("/pcm_seo_envelope[\s\S]{0,400}?'status' => 422/", $inv) === 1);
// Order matters: the specific check must precede the generic one.
$env = strpos($inv, 'pcm_seo_envelope');
$emp = strpos($inv, 'pcm_seo_empty', (int) $env);
check('the specific error comes first', $env !== false && $emp !== false && $env < $emp,
    array('envelope' => $env, 'empty' => $emp));
check('the slug field is slugified on BOTH attempts', substr_count($inv, 'sanitize_title($value)') === 2, $inv);
// Both per-field paths route through it, with customization = resolved ≠ default.
check('local ai.php routes through the shared invoke',
    preg_match('/return self::invoke_scalar_field\(\$prompt, \$default, \$vars, \$field, \$opts, \$tpl !== \$default\);/', $src) === 1, 'local bespoke');
check('remote service.php routes through the shared invoke',
    preg_match('/return PCM_SEO_AI::invoke_scalar_field\(\$prompt, \$default, \$vars, \$field, \$opts, \$tpl !== \$default\);/', $svc) === 1, 'remote bespoke');
check('no duplicated envelope guard remains outside the shared invoke',
    substr_count($src . $svc, 'pcm_seo_envelope') === 1, substr_count($src . $svc, 'pcm_seo_envelope'));

echo "\n5. The shared invoke — EXECUTED with a scripted model\n";
// Filip: "Test every generation, both bulk and singular, before you return the
// card." The full invoke runs here: good answers, the envelope-then-heal retry,
// and the two cases that must still refuse.
if (!class_exists('WP_Error')) {
    class WP_Error { public $code; public $msg; public $data;
        public function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->msg = $m; $this->data = $d; } }
}
if (!function_exists('__')) { function __($s, $d = null) { return $s; } }
if (!function_exists('sanitize_title')) { function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $s)), '-'); } }
class PCM_LLM {
    public static $script = array();   // successive responses
    public static $prompts = array();  // what each invoke was asked
    public static function invoke($messages, $opts = array()) {
        self::$prompts[] = (string) ($messages[0]['content'] ?? '');
        return array('content' => (string) array_shift(self::$script));
    }
}
$inv_fn = $grab('invoke_scalar_field');
eval('class InvokeHost { '
    . $inv_fn . "\n" . $grab('sanitize_ai_output') . "\n" . $grab('is_structured_envelope') . "\n"
    . ' public static function substitute_vars($tpl, $vars) { foreach ($vars as $k => $v) { $tpl = str_replace(\'{{\' . $k . \'}}\', (string) $v, $tpl); } return $tpl; }'
    . ' public static function language_law($vars) { return " LAW"; } }');
$ENVELOPE = '{"html":"<section><h1>Welcome</h1></section>","changes":[{"what":"x"}]}';
$vars = array('title' => 'Massage');

PCM_LLM::$script = array('Massage i Göteborg — boka idag'); PCM_LLM::$prompts = array();
$r = InvokeHost::invoke_scalar_field('CUSTOM {{title}}', 'DEFAULT {{title}}', $vars, 'metaTitle', array(), true);
check('a good answer passes through, ONE invoke', ($r['value'] ?? '') === 'Massage i Göteborg — boka idag' && count(PCM_LLM::$prompts) === 1, $r);

PCM_LLM::$script = array($ENVELOPE, 'Massage i Göteborg | Klinik'); PCM_LLM::$prompts = array();
$r = InvokeHost::invoke_scalar_field('CUSTOM {{title}}', 'DEFAULT {{title}}', $vars, 'metaTitle', array(), true);
check('SELF-HEAL: customized prompt envelopes → default retried → real title',
    ($r['value'] ?? '') === 'Massage i Göteborg | Klinik', $r);
check('…the retry used the SHIPPED default (with the law, substituted)',
    (PCM_LLM::$prompts[1] ?? '') === 'DEFAULT Massage LAW', PCM_LLM::$prompts);
check('…and the envelope never became the value', strpos((string) ($r['value'] ?? ''), '{') === false, $r);

PCM_LLM::$script = array($ENVELOPE, $ENVELOPE); PCM_LLM::$prompts = array();
$r = InvokeHost::invoke_scalar_field('CUSTOM', 'DEFAULT', $vars, 'metaTitle', array(), true);
check('default ALSO envelopes → the honest 422 remains (nothing left to heal with)',
    $r instanceof WP_Error && $r->code === 'pcm_seo_envelope', $r);

PCM_LLM::$script = array($ENVELOPE); PCM_LLM::$prompts = array();
$r = InvokeHost::invoke_scalar_field('DEFAULT', 'DEFAULT', $vars, 'metaTitle', array(), false);
check('an UNCUSTOMIZED prompt never retries (nothing to heal with) → 422, one invoke',
    $r instanceof WP_Error && $r->code === 'pcm_seo_envelope' && count(PCM_LLM::$prompts) === 1, $r);

PCM_LLM::$script = array(''); PCM_LLM::$prompts = array();
$r = InvokeHost::invoke_scalar_field('CUSTOM', 'DEFAULT', $vars, 'metaTitle', array(), true);
check('an empty answer is the generic error, NOT the envelope one — and no wasted retry',
    $r instanceof WP_Error && $r->code === 'pcm_seo_empty' && count(PCM_LLM::$prompts) === 1, $r);

PCM_LLM::$script = array($ENVELOPE, 'My Great Page — Best One!'); PCM_LLM::$prompts = array();
$r = InvokeHost::invoke_scalar_field('CUSTOM', 'DEFAULT', $vars, 'slug', array(), true);
check('the slug is slugified on the HEALED attempt too', ($r['value'] ?? '') === 'my-great-page-best-one', $r);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
