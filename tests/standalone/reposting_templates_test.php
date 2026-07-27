<?php
/**
 * Reposting templates — standalone harness.
 *
 * Guards the "move the reposting prompt into editable templates, no hidden
 * hardcoded prompt" work. The seeded writer templates "Social Media Reposting"
 * and "RSS Reposting" must carry the source variables {{ post_title }} /
 * {{ post_link }} / {{ post_content }} (so generate_next_item() suppresses the
 * legacy code rider — the whole prompt is IN the template) AND every standard
 * fragment ({{ keyword }}/{{ brand_context }}/{{ research }}/
 * {{ media_instructions }}/{{ output_format }}) so build_prompt() auto-appends
 * NOTHING — nothing about the prompt stays hidden in code.
 *
 * Clean CLI process with plain stubs (same isolation as run.php). Run:
 *   php tests/standalone/reposting_templates_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

// Minimal WP shims for loading the two class files (no DB / no runtime calls hit).
foreach (array('add_action', 'add_filter') as $fn) {
    if (!function_exists($fn)) { eval("function {$fn}() { return true; }"); }
}
if (!function_exists('esc_html')) { function esc_html($s) { return $s; } }
if (!function_exists('__')) { function __($s, $d = null) { return $s; } }

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/includes/core/class-pcm-template-seeds.php';
require_once $ROOT . '/includes/modules/strategy/service.php';

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

// Pull the seed array WITHOUT touching the DB (get_seed_templates is pure).
$m = new ReflectionMethod('PCM_Template_Seeds', 'get_seed_templates');
$templates = $m->invoke(null);

/** First 'prompt' entry value of a named template, or ''. */
function prompt_of(array $templates, string $name): string
{
    foreach ($templates as $t) {
        if (($t['name'] ?? '') === $name) {
            foreach (($t['formData']['entries'] ?? array()) as $e) {
                if (($e['category'] ?? '') === 'prompt') { return (string) ($e['value'] ?? ''); }
            }
        }
    }
    return '';
}

// Item shape a watcher/create-path produces for a source post.
$item = array(
    'sourceTitle' => 'Argentina lose the final',
    'sourceLink'  => 'https://www.instagram.com/p/Abc123/',
    'sourceText'  => 'Spain are champions after a dominant performance.',
    'social'      => true,
);

$source_vars   = array('{{ post_title }}', '{{ post_link }}', '{{ post_content }}');
$fragment_vars = array('{{ keyword }}', '{{ brand_context }}', '{{ research }}', '{{ media_instructions }}', '{{ output_format }}');

foreach (array('Social Media Reposting', 'RSS Reposting') as $name) {
    $prompt = prompt_of($templates, $name);
    check("[$name] template exists with a prompt entry", $prompt !== '', $prompt);

    foreach ($source_vars as $v) {
        check("[$name] prompt references $v", strpos($prompt, $v) !== false);
    }
    foreach ($fragment_vars as $v) {
        check("[$name] prompt references $v (nothing auto-appends)", strpos($prompt, $v) !== false);
    }

    // The decisive behavior: a template carrying the source vars makes
    // generate_next_item() suppress the hidden code rider.
    check(
        "[$name] carries source → hidden rider suppressed",
        PCM_Strategy_Service::template_carries_source($prompt, $item) === true
    );

    // No leftover ```html fence hint issues / the visible link instruction is present.
    check("[$name] instructs a visible link back to the post", strpos($prompt, '{{ post_link }}') !== false);
}

echo "\n" . ($FAIL === 0 ? "ALL GREEN" : "FAILURES: $FAIL") . " — $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
