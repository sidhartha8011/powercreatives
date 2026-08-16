<?php
/**
 * SEO generation — a REFUSAL is never a value (card 7, the screenshot).
 *
 * The bugfix card's own screenshot: Meta Title cells staged with
 *   "I'm sorry, I can't assist with that request."
 *   "I need the existing content to provide a revised output according to the
 *    specified format. Please provide the section you need revised…"
 *   "I'm sorry, I need the text you want revised in order to proceed…"
 * The model declined / asked for input it never got — and because a refusal
 * is a plain string, sanitize_ai_output's first-plausible-line pass handed it
 * through as a perfectly shaped title, one "Accept all" from the live site.
 *
 * Now: is_refusal() catches apology / can't-comply / "I need the …" openers
 * (English + Swedish), sanitize returns '' for them, invoke_scalar_field maps
 * that to a dedicated pcm_seo_refused error, and a refusal on a CUSTOMIZED
 * prompt self-heals with the shipped default exactly like an envelope does.
 * All EXECUTED here.
 *
 * Run: php tests/standalone/seo_refusal_guard_test.php
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

if (!class_exists('WP_Error')) {
    class WP_Error { public $code; public $message; public $data;
        public function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->message = $m; $this->data = $d; } }
}
function __($s, $d = null) { return $s; }
function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $s)), '-'); }
class PCM_LLM {
    public static $script = array(); public static $prompts = array();
    public static function invoke($m, $o = array()) { self::$prompts[] = (string) ($m[0]['content'] ?? ''); return array('content' => (string) array_shift(self::$script)); }
}

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
    return str_replace('private static', 'public static', $fn);
};
eval('class RefHost { ' . $grab('is_refusal') . "\n" . $grab('sanitize_ai_output') . "\n" . $grab('is_structured_envelope') . "\n" . $grab('invoke_scalar_field')
    . ' public static function substitute_vars($t, $v) { foreach ($v as $k => $x) { $t = str_replace("{{" . $k . "}}", (string) $x, $t); } return $t; }'
    . ' public static function language_law($v) { return " LAW"; } }');
check('is_refusal + sanitizer + invoke hosted', method_exists('RefHost', 'is_refusal'));

echo "\n1. The card's EXACT strings are refusals\n";
$card = array(
    "I'm sorry, I can't assist with that request.",
    "I need the existing content to provide a revised output according to the specified format. Please provide the section you need revised, and I'll assist you accordingly.",
    "I'm sorry, I need the text you want revised in order to proceed with the task. Please provide the section in need of revision.",
);
foreach ($card as $s) {
    check('refusal: ' . mb_substr($s, 0, 48) . '…', RefHost::is_refusal($s) === true, $s);
    check('  → sanitize yields EMPTY (never a title)', RefHost::sanitize_ai_output($s) === '', RefHost::sanitize_ai_output($s));
}

echo "\n2. More shapes — English + Swedish, markdown-wrapped\n";
foreach (array(
    "Sorry, but I cannot help with that.",
    "Unfortunately, I am unable to provide a meta title without the page content.",
    "As an AI language model, I cannot browse the page.",
    "**I apologize**, but I need more information.",
    "Please provide the content you would like me to optimize.",
    "It seems the content is missing. Could you share it?",
    "Tyvärr kan jag inte hjälpa till med det.",
    "Jag behöver texten du vill ha reviderad för att fortsätta.",
    "Vänligen ange innehållet som ska optimeras.",
) as $s) {
    check('refusal: ' . mb_substr($s, 0, 44), RefHost::is_refusal($s) === true, $s);
}

echo "\n3. Real titles are NOT refusals (precision)\n";
foreach (array(
    "Why We Need Sleep: 7 Science-Backed Reasons",
    "Sorry Seems to Be the Hardest Word — A Guide to Apologies",
    "I Need a Dentist in Göteborg — Here's How to Choose",
    "Unable to Sleep? Massage Can Help",
    "Massage i Göteborg — boka idag",
    "Please Touch: The Museum That Lets You",
    "Tandvård för hela familjen i Umeå",
) as $s) {
    check('NOT a refusal: ' . mb_substr($s, 0, 44), RefHost::is_refusal($s) === false, $s);
    check('  → sanitize keeps it', RefHost::sanitize_ai_output($s) !== '', RefHost::sanitize_ai_output($s));
}
check('empty input is not a refusal', RefHost::is_refusal('') === false);

echo "\n4. invoke_scalar_field — EXECUTED\n";
$vars = array('title' => 'T');
PCM_LLM::$script = array($card[0]); PCM_LLM::$prompts = array();
$r = RefHost::invoke_scalar_field('DEFAULT {{title}}', 'DEFAULT {{title}}', $vars, 'metaTitle', array(), false);
check('uncustomized prompt + refusal → dedicated pcm_seo_refused error (not fake success, not generic empty)',
    $r instanceof WP_Error && $r->code === 'pcm_seo_refused', $r);
check('…the message quotes the model\'s opening words', $r instanceof WP_Error && strpos($r->message, "I'm sorry, I can't assist") !== false, $r->message ?? '');
check('…and points at Templates → SEO', $r instanceof WP_Error && strpos($r->message, 'Templates → SEO') !== false);
check('one invoke (nothing to heal with)', count(PCM_LLM::$prompts) === 1, PCM_LLM::$prompts);

PCM_LLM::$script = array($card[1], 'Boka tandläkare i Göteborg | Klinik'); PCM_LLM::$prompts = array();
$r = RefHost::invoke_scalar_field('CUSTOM {{title}}', 'DEFAULT {{title}}', $vars, 'metaTitle', array(), true);
check('customized prompt + refusal → SELF-HEALS with the shipped default → real title',
    is_array($r) && $r['value'] === 'Boka tandläkare i Göteborg | Klinik', $r);
check('…flagged healed=true (UI tells the user to fix the template)', is_array($r) && $r['healed'] === true, $r);
check('…the retry used the shipped default with the law', (PCM_LLM::$prompts[1] ?? '') === 'DEFAULT T LAW', PCM_LLM::$prompts);

PCM_LLM::$script = array($card[2], $card[0]); PCM_LLM::$prompts = array();
$r = RefHost::invoke_scalar_field('CUSTOM', 'DEFAULT', $vars, 'metaTitle', array(), true);
check('refusal on BOTH attempts → pcm_seo_refused (still never a value)', $r instanceof WP_Error && $r->code === 'pcm_seo_refused', $r);

PCM_LLM::$script = array('A fine title'); PCM_LLM::$prompts = array();
$r = RefHost::invoke_scalar_field('CUSTOM', 'DEFAULT', $vars, 'metaTitle', array(), true);
check('a normal answer is untouched — no retry, healed=false', is_array($r) && $r['value'] === 'A fine title' && $r['healed'] === false && count(PCM_LLM::$prompts) === 1, $r);

echo "\n5. The remote path shares it (one invoke, one sanitizer)\n";
$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');
check('remote generate routes through the shared invoke', strpos($svc, '$out = PCM_SEO_AI::invoke_scalar_field(') !== false);
check('is_refusal is consulted inside the shared invoke (both paths inherit it)',
    preg_match('/function invoke_scalar_field[\s\S]{0,1200}?self::is_refusal\(\$raw\)/', $src) === 1);
check('sanitize_ai_output refuses refusals up front',
    preg_match('/function sanitize_ai_output[\s\S]{0,700}?if \(self::is_refusal\(\$content\)\) \{\s*\n\s*return \'\';/', $src) === 1);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
