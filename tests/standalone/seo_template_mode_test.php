<?php
/**
 * SEO templates: an explicitly PICKED template must actually be used.
 *
 * Reported: "sometimes it is working sometimes not"; a new Meta Title template
 * appeared unused; Re-generate seemed to use a different prompt than the ✦ star.
 *
 * THE BUG. The column menu offers BOTH "<Field> — Generate" and
 * "<Field> — Optimize", but the server picked the mode purely from whether the
 * cell was empty:
 *     $mode = (!empty($current) && optimize exists) ? 'optimize' : 'generate';
 * and seo_template_prompt() only matches a template whose formData.type equals
 * "{$use}_{$mode}". So picking the GENERATE template on a row that already had
 * a value resolved section "…_optimize", never matched the pick, and silently
 * ran the optimize default. It worked on EMPTY cells and failed on filled ones
 * — which is precisely what made it look random.
 *
 * Exercises the REAL methods (extracted into a host class, the same technique
 * seo_language_law_test.php uses) against a fake $wpdb, across EVERY
 * generatable column and both modes.
 *
 * Run: php tests/standalone/seo_template_mode_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); } // wpdb output-format constant

class PCM_Schema { public static function table($n) { return "wp_pcm_{$n}"; } }

/** Serves template rows; understands the two queries the real code issues. */
class FakeWpdb {
    /** @var array<int, array{id:int,userId:int,type:string,prompt:string,isDefault:int}> */
    public array $templates = array();
    public function prepare($sql, ...$args) {
        foreach ($args as $a) {
            $sql = preg_replace('/%d/', (string) (int) $a, $sql, 1);
            $sql = preg_replace('/%s/', "'" . (string) $a . "'", $sql, 1);
        }
        return $sql;
    }
    private function encode(array $t): string {
        return json_encode(array(
            'type'    => $t['type'],
            'entries' => array(array('category' => 'prompt', 'value' => $t['prompt'])),
        ));
    }
    /** template_mode(): SELECT formData ... WHERE id = N AND (userId = U OR userId = 0) */
    public function get_var($sql) {
        if (preg_match('/WHERE id = (\d+) AND \(userId = (\d+) OR userId = 0\)/', $sql, $m)) {
            foreach ($this->templates as $t) {
                if ((int) $t['id'] === (int) $m[1]
                    && ((int) $t['userId'] === (int) $m[2] || (int) $t['userId'] === 0)) {
                    return $this->encode($t);
                }
            }
        }
        return null;
    }
    /** seo_template_prompt(): SELECT id, userId, formData, isDefault, updatedAt ... */
    public function get_results($sql, $mode = null) {
        preg_match('/userId = (\d+)/', $sql, $m);
        $uid = isset($m[1]) ? (int) $m[1] : 0;
        $out = array();
        foreach ($this->templates as $t) {
            if ((int) $t['userId'] !== $uid && (int) $t['userId'] !== 0) { continue; }
            $out[] = array(
                'id'        => (int) $t['id'],
                'userId'    => (int) $t['userId'],
                'formData'  => $this->encode($t),
                'isDefault' => (int) ($t['isDefault'] ?? 0),
                'updatedAt' => $t['updatedAt'] ?? '2026-08-01',
            );
        }
        return $out;
    }
}

global $wpdb;
$wpdb = new FakeWpdb();

// ── Host the REAL methods, so this tests shipped code, not a transcription ──
$src = file_get_contents(dirname(__DIR__, 2) . '/includes/modules/seo/ai.php');
$grab = function (string $name) use ($src): string {
    $i = strpos($src, ' function ' . $name . '(');
    if ($i === false) { fwrite(STDERR, "method {$name} not found\n"); exit(1); }
    // Back up to the start of the declaration, then brace-match the body.
    $start = strrpos(substr($src, 0, $i), "\n") + 1;
    $open  = strpos($src, '{', $i);
    $depth = 0;
    for ($p = $open; $p < strlen($src); $p++) {
        if ($src[$p] === '{') { $depth++; }
        elseif ($src[$p] === '}') { $depth--; if ($depth === 0) { break; } }
    }
    // `private` would make these unreachable from the test host.
    return str_replace('private static', 'public static', substr($src, $start, $p - $start + 1));
};
eval('class Host { '
    . $grab('template_mode')
    . $grab('apply_template_mode')
    . $grab('seo_template_prompt')
    . $grab('seo_entry_prompt')
    . ' }');

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

const USER = 7;
/** The six generatable table columns → their prompt `use` key. */
$COLUMNS = array(
    'title'           => 'page_title',
    'metaTitle'       => 'meta_title',
    'metaDescription' => 'meta_description',
    'metaKeywords'    => 'meta_keywords',   // generate-only — immune by construction
    'primaryKeyword'  => 'primary_keyword',
    'slug'            => 'slug',
);
/** Which uses actually ship an optimize prompt (only those can flip mode). */
$HAS_OPTIMIZE = array(
    'page_title' => true, 'meta_title' => true, 'meta_description' => true,
    'meta_keywords' => false, 'primary_keyword' => true, 'slug' => true,
);

/** Rebuild the template table: one Generate + one Optimize per use, ids 100+. */
$id = 100;
foreach ($COLUMNS as $use) {
    foreach (array('generate', 'optimize') as $m) {
        $wpdb->templates[] = array(
            'id' => $id, 'userId' => USER, 'type' => $use . '_' . $m,
            'prompt' => strtoupper($use . ' ' . $m . ' PROMPT'), 'isDefault' => 0,
        );
        $id++;
    }
}
$tid = function (string $use, string $mode) use ($wpdb): int {
    foreach ($wpdb->templates as $t) {
        if ($t['type'] === $use . '_' . $mode) { return (int) $t['id']; }
    }
    return 0;
};
/** The prompt map shape apply_template_mode() expects. */
$modesFor = function (string $use) use ($HAS_OPTIMIZE): array {
    return $HAS_OPTIMIZE[$use]
        ? array('generate' => 'G', 'optimize' => 'O')
        : array('generate' => 'G');
};
/** The old, buggy behaviour: mode from emptiness alone. */
$heuristic = function (string $use, string $current) use ($HAS_OPTIMIZE): string {
    return ($current !== '' && $HAS_OPTIMIZE[$use]) ? 'optimize' : 'generate';
};

echo "\n1. THE BUG — picking \"Generate\" on a FILLED cell (every column)\n";
foreach ($COLUMNS as $col => $use) {
    $current = 'an existing value';
    $before  = $heuristic($use, $current);
    $after   = Host::apply_template_mode($before, USER, $tid($use, 'generate'), $use, $current, $modesFor($use));
    check("{$col}: picking Generate runs GENERATE (was '{$before}')", $after === 'generate', $after);
    // …and the picked template's own prompt must come back for that section.
    $prompt = Host::seo_template_prompt(USER, $use . '_' . $after, $tid($use, 'generate'));
    check("{$col}: …and resolves the PICKED prompt",
        $prompt === strtoupper($use . ' generate PROMPT'), $prompt);
}

echo "\n2. Picking \"Optimize\" on a filled cell keeps optimizing\n";
foreach ($COLUMNS as $col => $use) {
    if (!$HAS_OPTIMIZE[$use]) { continue; }
    $after = Host::apply_template_mode($heuristic($use, 'v'), USER, $tid($use, 'optimize'), $use, 'v', $modesFor($use));
    check("{$col}: stays optimize", $after === 'optimize', $after);
    $prompt = Host::seo_template_prompt(USER, $use . '_optimize', $tid($use, 'optimize'));
    check("{$col}: resolves the picked OPTIMIZE prompt",
        $prompt === strtoupper($use . ' optimize PROMPT'), $prompt);
}

echo "\n3. Picking \"Optimize\" on an EMPTY cell must NOT optimize nothing\n";
// The optimize prompts are built around {{current_value}} — honouring the pick
// with nothing to improve would ask the model to rewrite an empty string.
foreach ($COLUMNS as $col => $use) {
    if (!$HAS_OPTIMIZE[$use]) { continue; }
    $after = Host::apply_template_mode($heuristic($use, ''), USER, $tid($use, 'optimize'), $use, '', $modesFor($use));
    check("{$col}: falls back to generate", $after === 'generate', $after);
}
check('whitespace-only counts as empty',
    Host::apply_template_mode('generate', USER, $tid('meta_title', 'optimize'), 'meta_title', "   \n\t", $modesFor('meta_title')) === 'generate');

echo "\n4. No pick → the old emptiness heuristic is untouched\n";
foreach ($COLUMNS as $col => $use) {
    foreach (array('' => 'generate', 'v' => null) as $cur => $_) {
        $expect = $heuristic($use, (string) $cur);
        $after  = Host::apply_template_mode($expect, USER, null, $use, (string) $cur, $modesFor($use));
        check("{$col}: current='" . ($cur === '' ? '' : 'v') . "' stays {$expect}", $after === $expect, $after);
    }
}
check('template id 0 is treated as no pick',
    Host::apply_template_mode('optimize', USER, 0, 'meta_title', 'v', $modesFor('meta_title')) === 'optimize');

echo "\n5. A template for ANOTHER field never hijacks the mode\n";
foreach (array('meta_title', 'slug') as $use) {
    $foreign = $tid($use === 'slug' ? 'meta_title' : 'slug', 'generate');
    $after   = Host::apply_template_mode('optimize', USER, $foreign, $use, 'v', $modesFor($use));
    check("{$use}: a {$use}-foreign pick is ignored", $after === 'optimize', $after);
}
check('an unknown template id is ignored',
    Host::apply_template_mode('optimize', USER, 999999, 'meta_title', 'v', $modesFor('meta_title')) === 'optimize');

echo "\n6. A generate-only column can never be flipped to optimize\n";
// meta_keywords ships no optimize prompt, so it was immune to the bug — and must
// stay immune even if a stray optimize template exists for it.
$wpdb->templates[] = array('id' => 900, 'userId' => USER, 'type' => 'meta_keywords_optimize',
                           'prompt' => 'STRAY', 'isDefault' => 0);
check('meta_keywords stays generate with a stray optimize pick',
    Host::apply_template_mode('generate', USER, 900, 'meta_keywords', 'v', $modesFor('meta_keywords')) === 'generate');

echo "\n7. Both call sites apply the rule (local AND remote)\n";
$ai  = $src;
$svc = file_get_contents(dirname(__DIR__, 2) . '/includes/modules/seo/service.php');
foreach (array('local generate_field' => $ai, 'remote generate' => $svc) as $label => $code) {
    check("{$label}: mode is passed through apply_template_mode",
        preg_match('/\$mode\s*=\s*(self|PCM_SEO_AI)::apply_template_mode\(/', $code) === 1, $label);
    // Ordering: the override must land BEFORE $default/$tpl are read from $mode.
    $at  = strpos($code, '::apply_template_mode(');
    $def = strpos($code, '$default = $prompts[$use][$mode];', $at ?: 0);
    check("{$label}: it runs BEFORE the prompt is chosen", $at !== false && $def !== false && $at < $def,
        array('apply' => $at, 'default' => $def));
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
