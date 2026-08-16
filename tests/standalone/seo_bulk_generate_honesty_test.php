<?php
/**
 * Bugfix card 7 — bulk generation "skips cells" + "not using the template",
 * plus what the independent traces surfaced.
 *
 *  A. "It skips or leaves a field empty even if it should have generated in
 *     it": the table now DISPLAYS rendered-head fallbacks (since 08-16), so a
 *     Meta Title that is really EMPTY on the post looked filled to the bulk
 *     "where empty" check and was skipped. Rows now carry `metaFromHead`, and
 *     ONE hoisted predicate (rowCellIsEmpty) treats those as empty — in BOTH
 *     bulk paths (floating bar + column ✦), which used to disagree.
 *  B. Silent failures: bulk swallowed every error; an empty answer was STAGED
 *     as an invisible pending; the REMOTE hook never toasted generate errors
 *     at all (connected sites: refusal/envelope 422 = spinner stops, nothing).
 *     Now: empty answers count as failures, one honest summary, remote hook
 *     toasts, and an eligibility-rejected explicit pick is reported as
 *     `templateIgnored` so the user learns why the default ran.
 *  C. Connector registered SEO meta on post+page ONLY, so bulk-save on the
 *     custom-type rows (services/doctors) phantom-422'd "install the connector"
 *     on a site that HAD it. Now every public type.
 *
 * Run: php tests/standalone/seo_bulk_generate_honesty_test.php
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
$idx = file_get_contents($ROOT . '/app/src/modules/SEO/index.tsx');
$loc = file_get_contents($ROOT . '/app/src/modules/SEO/hooks/useSeoContent.ts');
$rem = file_get_contents($ROOT . '/app/src/modules/SEO/hooks/useRemoteSeoContent.ts');
$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');
$phl = file_get_contents($ROOT . '/includes/modules/seo/local.php');
$ai  = file_get_contents($ROOT . '/includes/modules/seo/ai.php');
$hub = file_get_contents($ROOT . '/includes/modules/seohub/service.php');
$types = file_get_contents($ROOT . '/app/src/modules/SEO/types.ts');

echo "\nA. One emptiness law, honoured by BOTH bulk paths — EXECUTED under node\n";
// Extract rowCellIsEmpty verbatim and run it.
$a = strpos($idx, 'function rowCellIsEmpty(');
// End on the function's OWN closing brace — the first line that is exactly "}" after
// the opening. A bare "\n}\n" search overshot into the TSX below (this file is CRLF).
$b = false;
if (preg_match('/\r?\n\}\r?\n/', $idx, $mm, PREG_OFFSET_CAPTURE, (int) $a)) { $b = $mm[0][1]; }
check('rowCellIsEmpty is hoisted to module scope (one definition)', $a !== false && substr_count($idx, 'function rowCellIsEmpty(') === 1);
$fn = str_replace("\r\n", "\n", substr($idx, (int) $a, (int) $b - (int) $a)) . "\n}";
$fn = preg_replace('/\(r: SeoRow \| undefined, field: string\): boolean/', '(r, field)', $fn);
$fn = str_replace('r[field as keyof SeoRow]', 'r[field]', $fn);
$js = $fn . "\n" . 'const cases = [
  ["stored title", {metaTitle:"Stored"}, "metaTitle", false],
  ["truly empty", {metaTitle:""}, "metaTitle", true],
  ["whitespace only", {metaTitle:"   "}, "metaTitle", true],
  ["HEAD fallback title (looks filled, is empty)", {metaTitle:"Rendered Title", metaFromHead:{title:true}}, "metaTitle", true],
  ["HEAD fallback description", {metaDescription:"Rendered desc", metaFromHead:{description:true}}, "metaDescription", true],
  ["head flag for OTHER field does not empty this one", {metaTitle:"Stored", metaFromHead:{description:true}}, "metaTitle", false],
  ["non-meta field ignores the head flag", {slug:"my-slug", metaFromHead:{title:true}}, "slug", false],
  ["missing row", undefined, "metaTitle", true],
];
process.stdout.write(JSON.stringify(cases.map(c => [c[0], rowCellIsEmpty(c[1], c[2]) === c[3]])));';
$tmp = tempnam(sys_get_temp_dir(), 'rce') . '.mjs';
file_put_contents($tmp, $js);
$out = shell_exec('node ' . escapeshellarg($tmp) . ' 2>&1');
@unlink($tmp);
$res = json_decode((string) $out, true);
check('predicate executed under node', is_array($res) && count($res) === 8, $out);
foreach ((array) $res as $c) { check('  ' . $c[0], $c[1] === true, $c[0]); }
check('the floating-bar run uses it', strpos($idx, 'const cellIsEmpty = (id: number, field: string) => rowCellIsEmpty(rowById.get(id), field);') !== false, 'bulk bar rewired');
check('the column ✦ run uses it (they used to disagree)', strpos($idx, ".filter((r) => mode === 'overwrite' || rowCellIsEmpty(r, field))") !== false, 'column run still ignores metaFromHead');
check('no path re-implements emptiness with a bare String(...).trim() on the row',
    preg_match("/mode === 'overwrite' \|\| !String\(r\[field as keyof SeoRow\]/", $idx) === 0, 'bespoke emptiness survives');

echo "\nA2. metaFromHead is set on EVERY fallback fill (both sides)\n";
check('types declare it', strpos($types, 'metaFromHead?: { title?: boolean; description?: boolean };') !== false);
check('remote_row marks head-sourced title/description', preg_match("/'metaFromHead'\s*=> array\(\s*\n\s*'title'\s*=> \\\$pick\(self::remote_meta_keys\('metaTitle'\)\) === '' && \\\$head\['title'\] !== '',/", $svc) === 1, 'remote_row unmarked');
check('local build_row marks them', strpos($phl, "'metaFromHead'       => array(") !== false);
check('connector /head-tags fill marks them', preg_match("/\\\$rows\[\\\$i\]\['metaTitle'\] = \(string\) \\\$t\['title'\];\s*\n\s*\\\$rows\[\\\$i\]\['metaFromHead'\]\['title'\] = true;/", $svc) === 1, 'connector fill unmarked');
check('fill_effective_meta marks them', preg_match("/\\\$rows\[\\\$i\]\['metaTitle'\] = \\\$tags\['title'\];\s*\n\s*\\\$rows\[\\\$i\]\['metaFromHead'\]\['title'\] = true;/", $phl) === 1, 'effective-meta fill unmarked');
check('a STORED value is never marked (marker requires the stored pick to be empty)', strpos($svc, "\$pick(self::remote_meta_keys('metaTitle')) === '' && \$head['title'] !== ''") !== false);

echo "\nB. No silent failures\n";
check('bulk bar: an empty answer is a FAILURE, never staged', strpos($idx, "if (value.trim() === '') { failed.push(key); }") !== false, 'empty staged as pending');
check('bulk bar: caught errors are counted', preg_match('/catch \{\s*\n\s*failed\.push\(key\);/', $idx) === 1, 'errors swallowed');
check('bulk bar: ONE honest summary names the count and the fields', strpos($idx, 'produced nothing (${byField})') !== false && strpos($idx, 'still empty, not skipped') !== false);
check('column run: empty answer counted as failure', preg_match("/if \(value\.trim\(\) === ''\) failed \+= 1;/", $idx) === 1);
check('column run: honest summary too', substr_count($idx, 'still empty, not skipped') === 2, substr_count($idx, 'still empty, not skipped'));
check('single-cell: an empty answer says so instead of staging nothing', strpos($idx, 'The model returned nothing for this cell') !== false);
// Locate generateField, then require a toasting .catch INSIDE that function.
$gf = strpos($rem, 'const generateField = useCallback(');
$gf_end = strpos($rem, '[rows, generateMutation, siteId]', (int) $gf);
$gf_body = substr($rem, (int) $gf, (int) $gf_end - (int) $gf);
check('REMOTE hook now toasts generate failures (it swallowed them)',
    strpos($gf_body, '.catch((err: unknown) => {') !== false && strpos($gf_body, "'AI generation failed'") !== false, 'remote still silent');
check('LOCAL hook still toasts', preg_match('/generateField[\s\S]{0,1400}?toast\.error\(err instanceof Error \? err\.message : \'AI generation failed\'\)/', $loc) === 1);

echo "\nB2. An eligibility-rejected PICK is reported, not silent\n";
check('resolver records an ignored explicit pick', preg_match('/if \(\$template_id && \(int\) \$r\[\'id\'\] === \$template_id\) \{\s*\n\s*self::\$last_pick_ignored = true;/', $ai) === 1, 'not recorded');
check('…reset per resolution (no bleed between requests)', strpos($ai, 'self::$last_pick_ignored = false;   // reset per resolution') !== false);
check('local generate reports templateIgnored', strpos($ai, "\$out['templateIgnored'] = 'envelope';") !== false);
check('remote generate reports templateIgnored', strpos($svc, "\$out['templateIgnored'] = 'envelope';") !== false);
check('both hooks toast it once', substr_count($loc . $rem, "id: 'seo-template-ignored'") === 2, substr_count($loc . $rem, "id: 'seo-template-ignored'"));

echo "\nC. Connector exposes SEO meta on EVERY public post type\n";
$a2 = strpos($hub, "<<<'PHP'"); $b2 = strpos($hub, "\nPHP;", (int) $a2);
$tpl = substr($hub, (int) $a2 + 9, (int) $b2 - (int) $a2 - 9);
check('registration iterates get_post_types(public)', strpos($tpl, "(array) get_post_types(array('public' => true), 'names')") !== false, 'still post+page only');
check('post + page always included (sites where a CPT list read fails still work)', strpos($tpl, "array('post', 'page'),") !== false);
check('attachment excluded (media is not content)', strpos($tpl, "if (in_array(\$type, array('attachment'), true)) { continue; }") !== false);
check('late init priority so theme/plugin CPTs exist first', preg_match('/register_post_meta\(\$type, \$k[\s\S]{0,300}?\}, 99\);/', $tpl) === 1, 'default priority — CPTs may not exist yet');
$baked = str_replace(array('__PCM_CONN_UPDATE_URI__', '__PCM_CONN_VERSION__', '__PCM_HUB_URL__', '__PCM_CLIENT_ID__'), array('https://x', '0.0.0', 'https://hub', 'cid'), $tpl);
$tmp = tempnam(sys_get_temp_dir(), 'pcmconn') . '.php';
file_put_contents($tmp, $baked);
exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $lo, $lc);
@unlink($tmp);
check('the extracted connector template parses', $lc === 0, implode("\n", array_slice($lo, 0, 3)));

echo "\nD. Cursor — a regular pointer on the view tabs, drag still wired\n";
check('no grab cursor on the pinned tabs', strpos($idx, 'cursor-grab') === false);
check('tabs still draggable (feature kept, only the cursor changed)', preg_match('/draggable\s*\n\s*onDragStart=/', $idx) === 1);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
