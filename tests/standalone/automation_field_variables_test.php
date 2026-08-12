<?php
/**
 * Automations field variables — the webhook mapping vocabulary.
 *
 * Owner (card, 2026-08-12): these names must be available in automation
 * payloads "just exactly using these names":
 *   brandName brandExtID deliveryName deliveryExtID projectName projectExtID
 *   setID setName setStatus setLink setInternalLink setComment
 * Purpose: match the right delivery with the right client to map to the right
 * chat in n8n & co.  Their test `{{ deliveryname }}` (lowercase) resolved to
 * NULL in the webhook body — the resolver's lookup was case-sensitive.
 *
 * Most of the vocabulary shipped with the Approvals redesign (enrich_context).
 * What this run added: case-insensitive token lookup (exact spelling first),
 * enrichment on the LAST bare emit (the "still awaiting client approval"
 * reminder scanner), and that trigger's declaration.
 *
 * Run: php tests/standalone/automation_field_variables_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d) { return json_encode($d); } }

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/includes/modules/automations/class-pcm-automation-mapping.php';

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

/** The exact vocabulary from the card. */
$REQUIRED = array(
    'brandName', 'brandExtID', 'deliveryName', 'deliveryExtID',
    'projectName', 'projectExtID',
    'setID', 'setName', 'setStatus', 'setLink', 'setInternalLink', 'setComment',
);

echo "\n1. Token resolution — the REAL resolver, incl. the reported case slip\n";
$ctx = array(
    'setID' => 42, 'setId' => 42, 'setName' => 'Spring Launch', 'setStatus' => 'client',
    'setLink' => 'https://x/review?t=abc', 'setInternalLink' => 'https://x/wp-admin/?pcm_approval_set=42',
    'setComment' => 'Please fix the logo',
    'brandName' => 'Brizy Profit Media', 'brandExtID' => 'HUB-77',
    'deliveryName' => 'August content', 'deliveryExtID' => 'DLV-9',
    'projectName' => 'Q3 SEO', 'projectExtID' => 'PRJ-3',
);
check('exact spelling resolves: {{deliveryName}}',
    PCM_Automation_Mapping::interpolate('{{deliveryName}}', $ctx) === 'August content',
    PCM_Automation_Mapping::interpolate('{{deliveryName}}', $ctx));
check('THE REPORTED BUG: {{ deliveryname }} (lowercase) resolves too',
    PCM_Automation_Mapping::interpolate('{{ deliveryname }}', $ctx) === 'August content',
    PCM_Automation_Mapping::interpolate('{{ deliveryname }}', $ctx));
check('any casing resolves: {{DELIVERYNAME}}',
    PCM_Automation_Mapping::interpolate('{{DELIVERYNAME}}', $ctx) === 'August content');
check('every required token resolves via its exact name',
    array_values(array_filter($REQUIRED, fn($k) => PCM_Automation_Mapping::interpolate('{{' . $k . '}}', $ctx) === null)) === array(),
    array_filter($REQUIRED, fn($k) => PCM_Automation_Mapping::interpolate('{{' . $k . '}}', $ctx) === null));
check('a lone token keeps its TYPE (setID stays an int for the consumer)',
    PCM_Automation_Mapping::interpolate('{{setID}}', $ctx) === 42,
    PCM_Automation_Mapping::interpolate('{{setID}}', $ctx));
check('inline interpolation still works',
    PCM_Automation_Mapping::interpolate('New comment on {{setName}} for {{brandName}}', $ctx)
        === 'New comment on Spring Launch for Brizy Profit Media');
// Exact spelling must WIN over a case-folded neighbour.
$clash = array('setID' => 'upper', 'setid' => 'lower');
check('exact match beats a case-folded neighbour ({{setID}})',
    PCM_Automation_Mapping::interpolate('{{setID}}', $clash) === 'upper');
check('…and the other exact spelling gets ITS value ({{setid}})',
    PCM_Automation_Mapping::interpolate('{{setid}}', $clash) === 'lower');
check('a genuinely unknown token still resolves to null (visible, not fabricated)',
    PCM_Automation_Mapping::interpolate('{{nonsense}}', $ctx) === null);

echo "\n2. enrich_context returns every required field\n";
$svc = file_get_contents($ROOT . '/includes/modules/approvals/service.php');
$e = strpos($svc, 'function enrich_context(');
check('enrich_context found', $e !== false);
$body = substr($svc, $e, 8000);
foreach ($REQUIRED as $k) {
    check("enrich_context emits '{$k}'", preg_match("/'" . preg_quote($k, '/') . "'\s*=>/", $body) === 1, $k);
}
check('enrich_context is PUBLIC (the reminder scanner lives in another module)',
    strpos($svc, 'public static function enrich_context(') !== false, 'still private');
check('setInternalLink deep-links into the Approvals module',
    preg_match("/'setInternalLink'\s*=>\s*admin_url\('admin\.php\?page=power-creatives&pcm_approval_set='/", $body) === 1,
    'not an admin deep link');
check('setLink is the PUBLIC client review link (distinct from internal)',
    preg_match("/'setLink'\s*=>\s*\\\$share_url/", $body) === 1, 'setLink not the share url');
// commentUrl must use the param the review page CONSUMES. It emitted `#asset-`
// for weeks — a hash nothing read, so "link to the review comment" opened the
// page at the top and never surfaced the comment. ClientReviewPage's deep-link
// effect reads `pcm_asset`; the emit and the consumer are asserted against each
// other so the two conventions cannot drift apart again.
check('commentUrl uses ?pcm_asset= (the param the page handles), not a dead hash',
    preg_match("/'commentUrl'[\s\S]{0,220}?add_query_arg\('pcm_asset'/", $body) === 1
    && strpos($body, "'#asset-'") === false, 'dead #asset- hash still emitted');
// FILE-WIDE, comments stripped: the first pass checked only enrich_context and
// missed a SECOND emitter (build_event_context's assetUrl) — the archive
// verification caught it. Any '#asset-' left in CODE is a link that silently
// opens the page at the top.
$svc_code = preg_replace('!/\*.*?\*/|//[^\n]*!s', '', $svc);
check('NO live #asset- emitter anywhere in the file (comments excluded)',
    strpos($svc_code, "'#asset-'") === false, 'a second dead-hash emitter survives');
check('assetUrl uses the consumed param too',
    preg_match("/'assetUrl'\]\s*=\s*add_query_arg\('pcm_asset'/", $svc_code) === 1, 'assetUrl still dead');
$crp = file_get_contents($ROOT . '/app/src/modules/Approvals/components/ClientReviewPage.tsx');
check('…and the review page really consumes pcm_asset (both ends of the contract)',
    strpos($crp, "get('pcm_asset')") !== false && strpos($crp, 'scrollIntoView') !== false,
    'consumer missing — the link would silently do nothing');

echo "\n3. Every approvals trigger declares the vocabulary AND its emit carries it\n";
$auto = file_get_contents($ROOT . '/includes/modules/approvals/automations.php');
$asvc = file_get_contents($ROOT . '/includes/modules/automations/service.php');
// Declarations: slice each register() block by trigger id.
$ids = array('approvals.set_status_changed', 'approvals.set_shared', 'approvals.set_fully_approved',
             'approvals.comment_added', 'approvals.asset_approved', 'approvals.set_pending_in_client');
foreach ($ids as $id) {
    $i = strpos($auto, "'" . $id . "'");
    check("declaration found: {$id}", $i !== false, $id);
    if ($i === false) { continue; }
    $block = substr($auto, $i, strpos($auto, 'conditionFields', $i) - $i);
    $missing = array_values(array_filter($REQUIRED, fn($k) => strpos($block, "'" . $k . "'") === false));
    // setComment is declared on EVERY trigger (owner, 2026-08-12: "setComment is
    // missing"). enrich_context always emits it — empty for non-comment events —
    // so advertising it everywhere is truthful, and the earlier comment-only
    // carve-out just meant the chip was absent from five of the six pickers.
    check("{$id} declares the vocabulary (incl. setComment)", $missing === array(), $missing);
}
// Emits: the five in approvals/service.php + the reminder in automations/service.php.
check('all five set-level emits merge enrich_context',
    substr_count($svc, 'self::enrich_context($set') >= 5, substr_count($svc, 'self::enrich_context($set'));
check('THE GAP THIS TASK CLOSED: the reminder scanner merges enrich_context too',
    strpos($asvc, 'PCM_Approvals_Service::enrich_context($set)') !== false, 'reminder still bare');
check('the reminder still carries its own keys (daysSinceSent, clientEmail)',
    preg_match("/'daysSinceSent'\s*=>\s*\\\$days/", $asvc) === 1
    && preg_match("/'clientEmail'/", $asvc) === 1, 'reminder-specific keys lost');

echo "\n4. The UI advertises what the trigger declares (no hardcoded stale list)\n";
$ui = file_get_contents($ROOT . '/app/src/modules/Automations/index.tsx');
check('the payload hint renders contextKeys dynamically',
    strpos($ui, 'selectedTrigger?.contextKeys ?? []') !== false, 'hint not dynamic');
check('tokens are click-to-copy', strpos($ui, 'copyToken(token)') !== false, 'no copy affordance');
check('no stale hardcoded token list remains',
    preg_match('/Available: \{\{setId\}\}/', $ui) === 0, 'old static hint still present');

echo "\n5. The rules LIST shows what fires for you — not four copies of everything\n";
// Owner: "why is everything four of everything?" — the 08-07 workspace-law sweep
// made an admin's list return EVERY user's rows, and since every user carries an
// identical SEEDED rule set, N users rendered N copies of each rule. The list is
// now the same set run_rules() executes: own rules + foreign ADMINS' CUSTOM rules.
$asvc2 = file_get_contents($ROOT . '/includes/modules/automations/service.php');
$lr = substr($asvc2, strpos($asvc2, 'function list_rules('), 2400);
check('no team-wide "every row" admin branch in list_rules',
    strpos($lr, 'FROM {$table} ORDER BY createdAt DESC"') === false, 'admin sees every copy again');
check('foreign rules are admin-only AND custom-only (seeds excluded)',
    strpos($lr, "role = 'admin'") !== false && strpos($lr, 'NOT LIKE') !== false, 'seed copies visible');
check('the seed marker is esc_like()d (`_` is a LIKE wildcard)',
    strpos($lr, 'esc_like(\'"__seedKey"\')') !== false, 'raw underscores in LIKE');
check('the engine gate it mirrors is untouched (foreign admin custom = global)',
    strpos($asvc2, "isset(\$config['__seedKey'])") !== false, 'engine gate changed');

echo "\n7. PARITY: every advertised chip is actually emitted, on every trigger\n";
// Owner: "Why do webhook have more variables? is there hardcoded somewhere, it
// should have the same." The lists are per-TRIGGER (email and webhook actions on
// the same trigger always show identical chips — both resolve from the same
// context via the same resolver). What CAN rot is declared-vs-emitted drift:
// a chip advertising a variable the emit never sends (the user-visible lie), or
// an emit sending variables no chip advertises. Both directions are parsed from
// SOURCE here, so the next lagging trigger fails this test instead of shipping.
$enrich_keys = array();
$eb = substr($svc, strpos($svc, 'function enrich_context('), 9000);
$rb = substr($eb, strpos($eb, 'return array('));
preg_match_all("/'([A-Za-z][A-Za-z0-9_]*)'\s*=>/", $rb, $mm);
$enrich_keys = array_unique($mm[1]);
check('enrich_context parsed (sanity: 12 core keys present)',
    count(array_diff($REQUIRED, $enrich_keys)) === 0, array_diff($REQUIRED, $enrich_keys));

$fires = array(
    // trigger id => the file whose fire site emits it
    'approvals.set_status_changed'    => $svc,
    'approvals.set_shared'            => $svc,
    'approvals.set_fully_approved'    => $svc,
    'approvals.comment_added'         => $svc,
    'approvals.asset_approved'        => $svc,
    'approvals.set_pending_in_client' => $asvc2,
);
foreach ($fires as $id => $file_src) {
    // Declared chips for this trigger.
    $di = strpos($auto, "'" . $id . "'");
    $dblock = substr($auto, $di, strpos($auto, 'conditionFields', $di) - $di);
    preg_match_all("/'([A-Za-z][A-Za-z0-9_]*)'/", substr($dblock, strpos($dblock, 'contextKeys')), $dm);
    $declared = array_values(array_diff(array_unique($dm[1]), array('contextKeys')));

    // Emitted keys at the fire site: the array literal(s) in the fire_trigger call,
    // unioned with enrich_context's keys when the site merges it.
    // Find the fire_trigger CALL whose first argument is this id: scan every
    // `fire_trigger(` occurrence and take the one with the id in the next few
    // lines. (The id string also appears in a rules-query filter, and the first
    // `fire_trigger` in the file is the engine's own DEFINITION — anchoring on
    // either produced a keyless window and reported every chip as a ghost.)
    $call_at = false;
    for ($p = strpos($file_src, 'fire_trigger('); $p !== false; $p = strpos($file_src, 'fire_trigger(', $p + 1)) {
        if (strpos(substr($file_src, $p, 160), "'" . $id . "'") !== false) { $call_at = $p; break; }
    }
    check("{$id}: fire site located", $call_at !== false, 'no call found');
    if ($call_at === false) { continue; }
    $window = substr($file_src, (int) $call_at, 2600);
    // Two emit shapes exist: the five approvals sites inline their array in the
    // call; the reminder builds `$context = array_merge(...)` on the lines ABOVE
    // and passes the variable — a forward-only window sees none of its keys and
    // reported EVERY chip as a ghost. When the call passes $context, pull in the
    // nearest preceding `$context =` assignment (and only that — a blind
    // backward window would swallow an unrelated earlier array and count keys
    // as emitted that are not).
    if (preg_match('/fire_trigger\(\s*\n?\s*\'' . preg_quote($id, '/') . '\',\s*\n?\s*\$context/', $window)) {
        $assign_at = strrpos(substr($file_src, 0, (int) $call_at), '$context = ');
        if ($assign_at !== false) {
            $window = substr($file_src, $assign_at, ((int) $call_at - $assign_at) + 400);
        }
    }
    preg_match_all("/'([A-Za-z][A-Za-z0-9_]*)'\s*=>/", $window, $wm);
    $emitted = array_unique($wm[1]);
    if (strpos($window, 'enrich_context') !== false) {
        $emitted = array_unique(array_merge($emitted, $enrich_keys));
    }
    $ghost_chips = array_values(array_diff($declared, $emitted));
    check("{$id}: every chip is emitted (no advertised-but-absent variables)",
        $ghost_chips === array(), $ghost_chips);
    $hidden = array_values(array_diff(array_intersect($enrich_keys, $REQUIRED), $declared));
    check("{$id}: every core emitted variable has a chip (nothing hidden)",
        $hidden === array(), $hidden);
}

echo "\n6. Click-to-copy uses the fallback-capable helper\n";
check('chips copy via copyToClipboard (execCommand fallback for HTTP installs)',
    preg_match('/copyToken = useCallback[\s\S]{0,200}?copyToClipboard\(token\)/', $ui) === 1, 'raw navigator.clipboard');
check('failure is surfaced, not silent',
    strpos($ui, "toast.error('Could not copy to clipboard')") !== false, 'silent failure');

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
