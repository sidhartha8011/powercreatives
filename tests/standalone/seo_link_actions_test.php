<?php
/**
 * Link optimization — the card's NEW trio: unlink / DELETE (restorable) /
 * follow-nofollow, across local and connected posts.
 *
 * Card (Add/SEO/Link optimization, "NEW (not bugs)"):
 *  - unlink  → remove the link but keep the anchor/element   (existed: Remove)
 *  - delete  → remove the ENTIRE element, mark it deleted in the table,
 *              still visible so it can be added back           (built now)
 *  - set to follow / set to nofollow → change the link type in the code
 *                                                              (built now)
 *
 * The rel surgery is EXECUTED here (real method, extracted); the ledger
 * contract, routes, and popup wiring are asserted against source.
 *
 * Run: php tests/standalone/seo_link_actions_test.php
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

// ── Extract toggle_nofollow_html (slice to next method at indentation — this
//    file's methods contain braces in strings; counting braces fails here). ──
$loc = file_get_contents($ROOT . '/includes/modules/seo/local.php');
$i = strpos($loc, 'public static function toggle_nofollow_html');
$next = strlen($loc);
foreach (array("\n    public static function ", "\n    private static function ") as $marker) {
    $n = strpos($loc, $marker, $i + 1);
    if ($n !== false && $n < $next) { $next = $n; }
}
$fn = substr($loc, $i, $next - $i);
if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
eval('class RelHost { ' . $fn . ' }');

echo "\n1. rel surgery — EXECUTED (\"it just changes the link type in the code\")\n";
$a = '<a href="https://x.se/p">Pris</a>';
check('follow → nofollow adds the attribute',
    RelHost::toggle_nofollow_html($a, true) === '<a rel="nofollow" href="https://x.se/p">Pris</a>',
    RelHost::toggle_nofollow_html($a, true));
$b = '<a href="https://x.se/p" rel="noopener noreferrer">Pris</a>';
check('existing rel tokens are PRESERVED when adding nofollow',
    RelHost::toggle_nofollow_html($b, true) === '<a href="https://x.se/p" rel="noopener noreferrer nofollow">Pris</a>',
    RelHost::toggle_nofollow_html($b, true));
$c = '<a href="https://x.se/p" rel="noopener nofollow">Pris</a>';
check('removing nofollow keeps the other tokens',
    RelHost::toggle_nofollow_html($c, false) === '<a href="https://x.se/p" rel="noopener">Pris</a>',
    RelHost::toggle_nofollow_html($c, false));
$d = '<a href="https://x.se/p" rel="nofollow">Pris</a>';
check('an emptied rel attribute is dropped entirely',
    RelHost::toggle_nofollow_html($d, false) === '<a href="https://x.se/p">Pris</a>',
    RelHost::toggle_nofollow_html($d, false));
check('setting nofollow twice does not stack tokens',
    substr_count(RelHost::toggle_nofollow_html(RelHost::toggle_nofollow_html($a, true), true), 'nofollow') === 1);
check('follow requested on a follow link is a no-op', RelHost::toggle_nofollow_html($a, false) === $a);
check('single-quoted rel handled',
    RelHost::toggle_nofollow_html("<a href='https://x' rel='nofollow'>t</a>", false) === "<a href='https://x'>t</a>",
    RelHost::toggle_nofollow_html("<a href='https://x' rel='nofollow'>t</a>", false));
check('the anchor TEXT is never touched',
    str_contains(RelHost::toggle_nofollow_html('<a href="https://x">nofollow tips</a>', true), '>nofollow tips</a>'));
check('non-anchor html passes through unchanged', RelHost::toggle_nofollow_html('<button>x</button>', true) === '<button>x</button>');

echo "\n2. DELETE is reversible BY CONTRACT (ledger before/around the cut)\n";
check('local: ledger row inserted BEFORE the content cut',
    ($p1 = strpos($loc, "return new WP_Error('pcm_seo_ledger'")) !== false
    && ($p2 = strpos($loc, "substr_replace(\$content, '', \$pos", $p1)) !== false, 'cut happens without a ledger');
check('local delete cuts the WHOLE element (empty replacement, not unwrap)',
    strpos($loc, "substr_replace(\$content, '', \$pos, strlen(\$old_html))") !== false, 'still unwrapping');
check('local restore prefers the ORIGINAL SPOT via stored context',
    strpos($loc, '$at = $ctx !== ') !== false || preg_match('/\$at\s+=\s+\$ctx !== \'\' \? strpos\(\$content, \$ctx\)/', $loc) === 1, 'no positional restore');
check('…and falls back to appending, never loses the link',
    preg_match('/\} else \{\s*\n\s*\$content \.= "\\\\n" \. \(string\) \$row->html;/', $loc) === 1, 'no fallback');
check('restore removes the ledger row (list and page agree)',
    preg_match('/wpdb->delete\(\$table, array\(\'id\' => \$ledger_id\)/', $loc) === 1, 'row lingers after restore');
$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');
// Slice the function rather than a fixed window (a comment-length change broke a
// windowed regex before — the session's recurring lesson).
$rdl = substr($svc, strpos($svc, 'function remote_delete_link'), 4000);
check('remote: delete records to the SAME ledger with the site id',
    strpos($rdl, "'siteId'   => (int) \$site->id") !== false, 'remote deletes unrecorded');
check('remote: ledger written only AFTER the remote write succeeded',
    ($q1 = strpos($svc, 'function remote_delete_link')) !== false
    && ($q2 = strpos($svc, 'if ($result instanceof WP_Error)', $q1)) !== false
    && ($q3 = strpos($svc, "wpdb->insert(PCM_Schema::table('seo_deleted_links')", $q1)) !== false
    && $q2 < $q3, 'bogus restore offered for an uncut link');
check('remote restore appends and drops the ledger row',
    preg_match("/remote_restore_deleted_link[\s\S]{0,2400}?'content' => \\\$raw \. \"\\\\n\" \. \(string\) \\\$row->html/", $svc) === 1, 'no remote restore');

echo "\n3. Storage — the ledger table exists and follows the schema laws\n";
$sch = file_get_contents($ROOT . '/includes/core/db/class-pcm-schema.php');
// Exact name incl. the opening paren — a bare-substring check matched a RENAMED
// table (`seo_deleted_links_x`) because the real name is its prefix.
check('seo_deleted_links table created', strpos($sch, 'CREATE TABLE {$prefix}seo_deleted_links (') !== false);
check('keyed by site+post for the popup list', strpos($sch, 'KEY idx_post (siteId, postId)') !== false);
check('DB version bumped to 1.47.0',
    strpos(file_get_contents($ROOT . '/power-creatives.php'), "PCM_DB_VERSION', '1.47.0'") !== false);
check('drop_tables knows the new table (schema law)', strpos($sch, "'seo_deleted_links',") !== false);

echo "\n4. Routes — local + remote, all six operations\n";
$ctl = file_get_contents($ROOT . '/includes/modules/seo/controller.php');
foreach (array(
    "links/(?P<idx>\\d+)/rel', 'set_link_rel'",
    "links/(?P<idx>\\d+)/delete', 'delete_link'",
    "links/deleted', 'deleted_links'",
    "deleted/(?P<ledger>\\d+)/restore', 'restore_deleted_link'",
    "links/(?P<idx>\\d+)/rel', 'remote_set_link_rel'",
    "links/(?P<idx>\\d+)/delete', 'remote_delete_link'",
    "links/deleted', 'remote_deleted_links'",
    "deleted/(?P<ledger>\\d+)/restore', 'remote_restore_deleted_link'",
) as $needle) {
    check('route: ' . substr($needle, 0, 46), strpos($ctl, $needle) !== false, $needle);
}
check('remote routes stay manage_options',
    preg_match_all("/'remote_(set_link_rel|delete_link|deleted_links|restore_deleted_link)', array\(\), 'manage_options'/", $ctl) === 4, 'under-gated');
check('remote handlers are ownership-scoped (site fetched with the caller id)',
    preg_match_all('/function remote_(set_link_rel|delete_link|deleted_links|restore_deleted_link)\([\s\S]{0,300}?PCM_DB::get_site\(absint\(\$request->get_param\(\'id\'\)\), \(int\) \$user->id\)/', $ctl) === 4, 'any site id would do');

echo "\n5. The popup offers all three, gated exactly like Remove\n";
$ui = file_get_contents($ROOT . '/app/src/modules/SEO/LinksPopup.tsx');
check('unlink stays, described in the card\'s words', strpos($ui, 'Unlink — remove the link but keep the text') !== false);
check('follow/nofollow toggle present, state read from the row html', strpos($ui, '/nofollow/i.test(l.html)') !== false);
check('both toggle directions wired', strpos($ui, 'setRel(l, false)') !== false && strpos($ui, 'setRel(l, true)') !== false);
check('delete confirms before cutting', preg_match('/deleteWhole[\s\S]{0,400}?window\.confirm/', $ui) === 1);
check('delete refreshes the Deleted list', preg_match('/deleteWhole[\s\S]{0,900}?refetchDeleted\(\)/', $ui) === 1);
check('the Deleted list renders with Restore', strpos($ui, 'Deleted links on this page') !== false && strpos($ui, 'restoreDeleted(d.ledgerId)') !== false);
check('deleted rows stay visible (struck through, not hidden)', strpos($ui, 'line-through') !== false);
// The gate grew a builder branch (card 10: builder ELEMENTS have no <a> to unlink/rel/delete —
// they get a lock + reason). Read-only rows still get nothing; the three actions sit only in
// the `editable ?` branch that follows it.
check('all three actions live inside the editable gate',
    preg_match('/\{editable && isBuilderRow\(l\) \? \([\s\S]{0,600}?\) : editable \? \(\s*\r?\n\s*<>/', $ui) === 1, 'actions offered on read-only rows');
$routes = file_get_contents($ROOT . '/app/src/lib/trpc-routes.ts');
foreach (array('seo.setLinkRel', 'seo.deleteLink', 'seo.deletedLinks', 'seo.restoreDeletedLink',
               'seo.remoteSetLinkRel', 'seo.remoteDeleteLink', 'seo.remoteDeletedLinks', 'seo.remoteRestoreDeletedLink') as $r) {
    check("tRPC route {$r}", strpos($routes, '"' . $r . '"') !== false, $r);
}

echo "\n6. Earlier card items still hold (popup width, clickable cells)\n";
check('dialog is wide (no left-right scrolling)', strpos($ui, 'sm:max-w-[min(1400px,95vw)]') !== false);
check('from cell opens in a new tab', preg_match('/href=\{l\.from\}\s*\n\s*target="_blank"/', $ui) === 1);
check('to cell opens in a new tab', preg_match('/href=\{l\.to\}\s*\n(?:[^\n]*\n){0,2}\s*target="_blank"/', $ui) === 1);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
