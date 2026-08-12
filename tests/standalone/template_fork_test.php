<?php
/**
 * Templates → SEO templates → editing a seeded prompt.
 *
 * Reported: "Template not found." on save, the value sometimes sticks and
 * sometimes reverts, and generation ignores the new prompt.
 *
 * Every seeded SEO template is a SHARED row (userId=0, isDefault=1 — see
 * PCM_SEO_AI::seed_seo_templates). Three write paths touch those rows and they
 * did not agree:
 *   update_item()  — forked the shared row  (worked)
 *   delete_item()  — allowed userId=0       (worked)
 *   set_default()  — owner-only WHERE       (404 "Template not found.")
 * The row toggle calls set_default, and every seeded row ships isDefault=1 so
 * its toggle renders ON — the first click on one produced exactly that error.
 *
 * The revert half was an IDENTITY mismatch. A template's section lives in
 * formData.type, and clear_other_defaults() + seo_template_prompt() both key on
 * it — but the fork deduped on NAME. Renaming while editing therefore slipped
 * past the dedupe and inserted a SECOND fork for the same section; the two both
 * carried isDefault=1 until one was demoted, and generation resolved whichever
 * won. Same cause for the list showing the section twice after a rename.
 *
 * Run: php tests/standalone/template_fork_test.php
 */

error_reporting(E_ALL); // undefined variables MUST fail this file — php -l cannot see them
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

$src = file_get_contents(__DIR__ . '/../../includes/modules/templates/controller.php');

/** Slice one method body out of the controller. */
function method(string $src, string $name): string {
    $i = strpos($src, 'function ' . $name . '(');
    if ($i === false) { return ''; }
    $j = strpos($src, "\n    /**", $i);          // next docblock
    return $j === false ? substr($src, $i) : substr($src, $i, $j - $i);
}

echo "\n1. set_default accepts a SHARED template instead of 404ing\n";
$set = method($src, 'set_default');
check('set_default() found', $set !== '');
check('it looks for a shared row when the owner query misses',
    (bool) preg_match('/WHERE id = %d AND userId = 0/', $set), 'no shared lookup');
check('and forks it rather than answering "not found"',
    strpos($set, 'fork_shared_template(') !== false, 'still 404s on seeded templates');
check('a genuinely unknown id still 404s',
    (bool) preg_match('/if \(\$shared\) \{[\s\S]{0,200}?\}\s*\n\s*return \$this->not_found\(\'Template\'\)/', $set),
    'unknown ids would be swallowed');
check('the toggle state is carried into the fork, not hardcoded',
    (bool) preg_match('/fork_shared_template\(\$shared, array\(\), \(int\) \$user->id, \$is_default\)/', $set),
    'un-starring a seeded template would still star it');

echo "\n2. fork_shared_template declares what it uses (php -l cannot see this)\n";
$fork = method($src, 'fork_shared_template');
check('fork_shared_template() found', $fork !== '');
check('$is_default is a real PARAMETER',
    (bool) preg_match('/function fork_shared_template\(object \$shared, array \$params, int \$user_id, int \$is_default = 1\)/', $fork),
    'undefined variable at runtime');
check('no branch hardcodes isDefault back to 1',
    !preg_match("/'isDefault'\s*=>\s*1,/", $fork), 'the parameter is ignored');
check('siblings are only demoted when this row IS the default',
    (bool) preg_match('/if \(\$is_default\) \{\s*\n\s*\$this->clear_other_defaults\(/', $fork),
    'un-starring would clear other defaults');

echo "\n3. The fork is identified by SECTION, not by name\n";
check('it matches an existing fork on formData.type',
    strpos($fork, "(string) (\$fd['type'] ?? '') === \$section") !== false, 'still name-keyed');
check('it still falls back to name for rows with no type',
    (bool) preg_match('/if \(!\$prior\)[\s\S]{0,260}?AND name = %s/', $fork), 'legacy rows would duplicate');
// strpos, not preg_match: in a DOUBLE-quoted PHP string "\$name" becomes "$name",
// and a bare $ in a regex is an end-of-line anchor — the pattern could never match.
// BOTH branches must write the name — the insert always did, so a single-match
// check passes while the UPDATE branch (the rename path) has lost it.
check('a rename updates the existing fork instead of inserting a second',
    substr_count($fork, "'name'        => \$name,") === 2,
    substr_count($fork, "'name'        => \$name,"));
// MySQL 5.7+ only — WordPress supports older, and the file already decodes in PHP
// elsewhere. Comments are stripped first: this fix is DESCRIBED in one, and a naive
// scan matches its own explanation and reports the dependency as still present.
$fork_code = preg_replace('!//[^\n]*|/\*.*?\*/!s', '', $fork);
check('no JSON_EXTRACT dependency', stripos($fork_code, 'JSON_EXTRACT') === false, 'breaks on older MySQL');

echo "\n4. The list agrees with the fork about identity\n";
$list = method($src, 'list_items');
check('list_items() found', $list !== '');
// Assert the section map is CONSULTED in the filter, not merely that the variable
// name appears somewhere — it is built in one place and used in another, so a
// name-only check passes while the filter no longer reads it.
// The map must be BUILT and CONSULTED. Checking only the consumer passes when the
// declaration has drifted (the `use` clause then imports an undefined variable and
// the filter quietly stops hiding anything); checking only the name passes too,
// since it is written in one place and read in another.
check('it builds a map of the sections the user owns',
    strpos($list, '$owned_sections = array();') !== false
    && strpos($list, "\$owned_sections[\$r->module . '|' . \$s] = true;") !== false,
    'section map not built');
check('it hides a shared row when the user owns that SECTION',
    (bool) preg_match('/if \(\$s !== \'\' && !empty\(\$owned_sections\[\$r->module \. \'\|\' \. \$s\]\)\) \{\s*\n\s*return false;/', $list),
    'renaming re-exposes the original');
check('it still hides by name for rows with no type',
    strpos($list, '$owned_names') !== false, 'legacy rows would double up');
check('the user\'s own rows are never hidden',
    (bool) preg_match('/if \(\(int\) \$r->userId !== 0\) \{\s*\n\s*return true;/', $list), 'own rows could vanish');

echo "\n5. All four paths key on the same thing\n";
// The whole bug was these disagreeing. Assert they all reference formData type.
foreach (array('clear_other_defaults', 'fork_shared_template', 'list_items') as $m) {
    $body = method($src, $m);
    check("$m() keys on the section", strpos($body, "'type'") !== false, $m);
}
$ai = file_get_contents(__DIR__ . '/../../includes/modules/seo/ai.php');
check('seo_template_prompt() keys on the section too',
    strpos($ai, "(\$fd['type'] ?? '') !== \$section") !== false, 'generation would disagree');

echo "\n6. The seeded rows really are shared+default (the premise)\n";
check('seeded SEO templates are userId=0',
    (bool) preg_match("/'userId'\s*=> 0,[\s\S]{0,200}?'module'\s*=> 'seo'/", $ai), 'premise wrong');
check('…and ship isDefault=1, so their toggle renders ON',
    (bool) preg_match("/'module'\s*=> 'seo',[\s\S]{0,160}?'isDefault' => 1/", $ai), 'premise wrong');

echo "\n" . str_repeat('-', 58) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
