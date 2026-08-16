<?php
/**
 * SEO generation — EVERY field × both modes × single AND bulk, EXECUTED.
 *
 * Filip: "It now works to generate for bulk, but individual lines do not
 * work. It complains about HTML missing… Test every generation, both bulk and
 * singular, before you return the card."
 *
 * The asymmetry: bulk fills EMPTY cells (mode 'generate'); a single FILLED
 * cell resolves 'optimize'. A user template whose TYPE is a meta field but
 * whose VALUE is the section-revise JSON envelope hijacked that mode — the
 * envelope guard refused with a dead end.
 *
 * Two layers now, both run here against a scripted templates table + model:
 *   1. ELIGIBILITY (resolver): for a scalar section, an envelope-demanding
 *      template is never eligible — the shipped default resolves; no LLM call
 *      is wasted, no retry, no error.
 *   2. SELF-HEAL (invoke): if an envelope still reaches the model (legacy
 *      override), retry once with the default and flag `healed` so the UI
 *      tells the user to fix the template.
 *
 * Run: php tests/standalone/seo_generation_matrix_test.php
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

// ── Stubs ───────────────────────────────────────────────────────────────────
if (!class_exists('WP_Error')) {
    class WP_Error { public $code; public $message; public $data;
        public function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->message = $m; $this->data = $d; } }
}
function __($s, $d = null) { return $s; }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $s)), '-'); }
function wp_json_encode($v) { return json_encode($v); }
class PCM_Schema { public static function table($n) { return 'wp_pcm_' . $n; } }
class WPDB_Stub {
    public $rows = array();     // templates rows
    public function prepare($q, ...$a) { return json_encode(array($q, $a)); }
    public function get_results($q, $t = null) { return $this->rows; }
    public function get_var($q) {
        // template_mode() lookup by id → formData; legacy override lookup → null.
        list($sql, $args) = json_decode($q, true);
        if (strpos($sql, 'SELECT formData FROM') !== false) {
            foreach ($this->rows as $r) { if ((int) $r['id'] === (int) $args[0]) { return $r['formData']; } }
        }
        return null;
    }
    public function get_col($q) { $o = array(); foreach ($this->rows as $r) { if ((int) $r['userId'] === 0) { $o[] = $r['formData']; } } return $o; }
    public function insert($t, $d, $f = null) { return 1; }
}
$GLOBALS['wpdb'] = new WPDB_Stub();
class PCM_LLM {
    public static $reply = 'A fine title';   // scalar answer by default
    public static $log = array();
    public static function invoke($messages, $opts = array()) {
        $prompt = (string) ($messages[0]['content'] ?? '');
        self::$log[] = $prompt;
        // The model OBEYS the prompt: an envelope-demanding prompt yields an envelope.
        if (preg_match('/"html"\s*:/', $prompt) || stripos($prompt, 'OUTPUT FORMAT (mandatory)') !== false) {
            return array('content' => '{"html":"<section><h1>x</h1></section>","changes":[{"what":"y"}]}');
        }
        return array('content' => self::$reply);
    }
}

// ── Host the REAL resolver + mode + invoke + sanitizer chain ────────────────
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
    // Trim a trailing docblock that belongs to the NEXT method.
    $doc = strrpos($fn, "\n    /**");
    if ($doc !== false && strpos($fn, '}', $doc) === false) { $fn = substr($fn, 0, $doc); }
    return str_replace(array('private static', 'private function'), array('public static', 'public function'), $fn);
};
$body = '';
foreach (array('resolve_prompt', 'seed_seo_templates', 'seo_template_prompt', 'seo_entry_prompt', 'seo_section_label',
               'prompt_demands_envelope', 'is_scalar_section', 'template_mode', 'apply_template_mode',
               'invoke_scalar_field', 'sanitize_ai_output', 'is_structured_envelope', 'is_refusal') as $m) {
    $body .= $grab($m) . "\n";
}
// The shipped defaults: keep them tiny + clean, keyed exactly like get_default_prompts.
$body .= ' public static function get_default_prompts() { return array(
    "meta_title_generate" => "Write a meta title for {{title}}.", "meta_title_optimize" => "Optimize this meta title: {{current_value}}",
    "meta_description_generate" => "Write a meta description for {{title}}.", "meta_description_optimize" => "Optimize this description: {{current_value}}",
    "meta_keywords_generate" => "List keywords for {{title}}.", "meta_keywords_optimize" => "Improve keywords: {{current_value}}",
    "primary_keyword_generate" => "Pick a primary keyword for {{title}}.", "primary_keyword_optimize" => "Improve the keyword: {{current_value}}",
    "page_title_generate" => "Write a page title for {{title}}.", "page_title_optimize" => "Optimize the page title: {{current_value}}",
    "slug_generate" => "Write a slug for {{title}}.", "slug_optimize" => "Improve the slug: {{current_value}}",
); }
 public static function substitute_vars($tpl, $vars) { foreach ($vars as $k => $v) { $tpl = str_replace("{{" . $k . "}}", (string) $v, $tpl); } return $tpl; }
 public static function language_law($vars) { return " LAW"; }';
eval('class GenHost { ' . $body . ' }');
check('resolver + invoke chain hosted', method_exists('GenHost', 'seo_template_prompt') && method_exists('GenHost', 'invoke_scalar_field'));

$ENVELOPE_PROMPT = "Revise the section.\n\nOUTPUT FORMAT (mandatory): respond with ONLY this JSON, no markdown fences, no text around it: "
    . '{"html":"<the COMPLETE revised section HTML>","changes":[{"what":"one change","why":"<{{why}}>","quote":"5-12 words"}]}';
$tplRow = static fn(int $id, int $user, string $type, string $value, int $default = 0) => array(
    'id' => $id, 'userId' => $user, 'isDefault' => $default, 'updatedAt' => '2026-08-15 12:00:00',
    'formData' => json_encode(array('type' => $type, 'entries' => array(array('category' => 'prompt', 'value' => $value)))),
);
// The user's misrouted template: TYPE meta_title_optimize, VALUE the envelope — plus a
// system default row per section (as seed_seo_templates would create).
$rows = array($tplRow(500, 7, 'meta_title_optimize', $ENVELOPE_PROMPT, 1));
$id = 600;
foreach (GenHost::get_default_prompts() as $section => $prompt) { $rows[] = $tplRow($id++, 0, $section, $prompt, 1); }
$GLOBALS['wpdb']->rows = $rows;

// The generate_field pipeline for one field, as both the local + remote paths run it.
$prompts = array();
foreach (GenHost::get_default_prompts() as $section => $p) {
    if (preg_match('/^(.*)_(generate|optimize)$/', $section, $m)) { $prompts[$m[1]][$m[2]] = $p; }
}
$run = static function (string $use, string $current, ?int $template_id, string $field = 'metaTitle') use ($prompts) {
    $vars = array('title' => 'Massage i Göteborg', 'current_value' => $current);
    $mode = (!empty($current) && !empty($prompts[$use]['optimize'])) ? 'optimize' : 'generate';
    $mode = GenHost::apply_template_mode($mode, 7, $template_id, $use, $current, $prompts[$use]);
    $default = $prompts[$use][$mode];
    $tpl = GenHost::resolve_prompt($use . '_' . $mode, $default, 7, $template_id);
    $prompt = GenHost::substitute_vars($tpl . GenHost::language_law($vars), $vars);
    PCM_LLM::$log = array();
    return array(GenHost::invoke_scalar_field($prompt, $default, $vars, $field, array(), $tpl !== $default), $mode, $tpl === $default);
};

echo "\n1. Filip's exact repro — ONE filled meta-title cell, misrouted user template present\n";
list($r, $mode, $used_default) = $run('meta_title', 'Existing title', null);
check('mode is OPTIMIZE (the cell is filled — the singular case)', $mode === 'optimize', $mode);
check('the envelope template is NOT eligible → the shipped default resolved', $used_default === true, 'envelope template hijacked the field');
check('generation SUCCEEDS with a real value', is_array($r) && $r['value'] === 'A fine title', $r);
check('exactly ONE model call (no wasted envelope round trip)', count(PCM_LLM::$log) === 1, PCM_LLM::$log);
check('no heal was needed (resolver caught it upstream)', is_array($r) && $r['healed'] === false, $r);

echo "\n2. …and the bulk case (empty cell → generate) still works\n";
list($r, $mode) = $run('meta_title', '', null);
check('empty cell → GENERATE', $mode === 'generate');
check('bulk-style generate succeeds', is_array($r) && $r['value'] === 'A fine title', $r);

echo "\n3. THE MATRIX — every field × both modes\n";
foreach (array('metaTitle' => 'meta_title', 'metaDescription' => 'meta_description', 'metaKeywords' => 'meta_keywords',
               'primaryKeyword' => 'primary_keyword', 'title' => 'page_title', 'slug' => 'slug') as $field => $use) {
    foreach (array('' => 'generate', 'has a value' => 'optimize') as $current => $expect) {
        list($r, $mode) = $run($use, $current, null, $field);
        $ok = is_array($r) && $r['value'] !== '' && $mode === $expect && count(PCM_LLM::$log) === 1;
        check(sprintf('%-16s %-8s → value, one call', $field, $expect), $ok, array('mode' => $mode, 'r' => $r, 'calls' => count(PCM_LLM::$log)));
    }
}

echo "\n4. Explicit picks — the misrouted template PICKED by id still cannot hijack\n";
list($r, $mode, $used_default) = $run('meta_title', 'Existing title', 500);
check('picking the envelope template by id → default still resolves (ineligible)', $used_default === true && is_array($r) && $r['value'] === 'A fine title', array($used_default, $r));
// A LEGIT customized template (non-envelope) picked by id must still win.
$GLOBALS['wpdb']->rows[] = $tplRow(700, 7, 'meta_title_optimize', 'My own optimize prompt: {{current_value}}', 0);
list($r, $mode, $used_default) = $run('meta_title', 'Existing title', 700);
check('a legit picked template still wins (eligibility only removes envelopes)', $used_default === false && is_array($r) && $r['value'] === 'A fine title', array($used_default, $r));
check('…and the model was asked the CUSTOM prompt', strpos(PCM_LLM::$log[0], 'My own optimize prompt') !== false, PCM_LLM::$log);

echo "\n4b. The law is SCOPED — a non-scalar section keeps its envelope prompt\n";
// revise_envelope legitimately DEMANDS the envelope: if the law were applied to every
// section, the section-revise flow would lose its own prompt. Register a user row for
// it and confirm the resolver still returns the envelope there.
$GLOBALS['wpdb']->rows[] = $tplRow(800, 7, 'revise_envelope_generate', $ENVELOPE_PROMPT, 1);
$got = GenHost::resolve_prompt('revise_envelope_generate', 'DEFAULT', 7, null);
check('a NON-scalar section still resolves its envelope template (law is scoped to scalar fields)',
    $got === $ENVELOPE_PROMPT, substr((string) $got, 0, 60));

echo "\n5. Layer 2 — an envelope that still reaches the model heals + flags\n";
// Bypass the resolver: hand invoke_scalar_field a customized envelope prompt directly
// (what a legacy Settings→Prompts override would produce).
$vars = array('title' => 'T');
$r = GenHost::invoke_scalar_field($ENVELOPE_PROMPT, 'Write a meta title for {{title}}.', $vars, 'metaTitle', array(), true);
check('heal: retried with the default → value', is_array($r) && $r['value'] === 'A fine title', $r);
check('…and flagged healed=true so the UI can say the template needs fixing', is_array($r) && $r['healed'] === true, $r);
$r = GenHost::invoke_scalar_field($ENVELOPE_PROMPT, $ENVELOPE_PROMPT, $vars, 'metaTitle', array(), false);
check('uncustomized envelope default → honest 422 (nothing to heal with)', $r instanceof WP_Error && $r->code === 'pcm_seo_envelope', $r);
// A heal that is ATTEMPTED but yields nothing must not be reported as a heal — and a
// customized prompt that answers cleanly must not be flagged either (a false "fix
// your template" warning on every generate is its own bug).
PCM_LLM::$reply = 'A fine title';
$r = GenHost::invoke_scalar_field('Custom clean prompt {{title}}', 'DEFAULT {{title}}', $vars, 'metaTitle', array(), true);
check('a customized prompt that answers cleanly is NOT flagged healed', is_array($r) && $r['healed'] === false, $r);
PCM_LLM::$reply = '';   // the default retry answers EMPTY → no value → not a heal
$r = GenHost::invoke_scalar_field($ENVELOPE_PROMPT, 'DEFAULT {{title}}', $vars, 'metaTitle', array(), true);
check('a retry that yields nothing is an error, never a "heal"', $r instanceof WP_Error, $r);
PCM_LLM::$reply = 'A fine title';

echo "\n6. prompt_demands_envelope — precise, not trigger-happy\n";
check('the revise envelope prompt is detected', GenHost::prompt_demands_envelope($ENVELOPE_PROMPT) === true);
check('a prompt that merely SAYS "no HTML" is NOT flagged', GenHost::prompt_demands_envelope('Write a meta title. Plain text only, no HTML, no JSON.') === false);
check('a JSON-LD schema prompt (site field, not scalar) is NOT flagged', GenHost::prompt_demands_envelope('Output ONLY the raw JSON object with @context and @type') === false);
check('a prompt returning {"broad":true} is NOT flagged', GenHost::prompt_demands_envelope('Respond with ONLY this JSON: {"broad":true} or {"broad":false}.') === false);
check('scalar sections identified', GenHost::is_scalar_section('meta_title_optimize') && GenHost::is_scalar_section('slug_generate') && !GenHost::is_scalar_section('revise_envelope_generate') && !GenHost::is_scalar_section('paragraph_optimize'));

echo "\n7. The UI surfaces the heal\n";
foreach (array('useSeoContent.ts', 'useRemoteSeoContent.ts') as $f) {
    $h = file_get_contents($ROOT . '/app/src/modules/SEO/hooks/' . $f);
    check("$f: warns once when healed", strpos($h, 'if (res?.healed)') !== false && strpos($h, "id: 'seo-template-healed'") !== false, $f);
    check("$f: names where to fix it", strpos($h, 'Fix it in Templates → SEO') !== false, $f);
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
