<?php
/**
 * STANDALONE page-versioning tests — run with bare PHP, no vendor:
 *
 *   php tests/standalone/page_versioning_test.php
 *
 * WHAT IT PROVES (frozen contracts 2026-07-16, hub side):
 *
 * 1. page_fingerprint — sha1 of the NORMALIZED net rule set: stable across
 *    calls, insensitive to rule order and map-key order, blind to row ids
 *    (storage handles — rollback/replace must not shift the fingerprint),
 *    sensitive to every content change (order INSIDE a rule's lists is
 *    content and stays significant).
 * 2. Page state record — option 'pcm_page_state', key "siteId:postId":
 *    version-0/'' baseline before any save, each accepted-push write bumps
 *    and persists {version, fingerprint, savedAt}, keys are isolated.
 * 3. superseded_rule_ids (W2 net set) — a replaced section rule's dead row
 *    drops, live attributed rows stay, sectionRemove rows stay BY LAW
 *    (they suppress a baseline section while the doc omits it), every
 *    other target keeps its own lifecycle law and is never swept.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

// ── Minimal WP shims (the optimizer-test set + what the seo module loads) ────
define('ABSPATH', __DIR__ . '/');
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
$GLOBALS['__opts'] = array();
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['__opts']) ? $GLOBALS['__opts'][$k] : $d; }
function update_option($k, $v, $a = false) { $GLOBALS['__opts'][$k] = $v; return true; }
function add_option($k, $v, $x = '', $a = false) { if (!array_key_exists($k, $GLOBALS['__opts'])) { $GLOBALS['__opts'][$k] = $v; } return true; }
function delete_option($k) { unset($GLOBALS['__opts'][$k]); return true; }
function get_transient($k) { return get_option('_t_' . $k); }
function set_transient($k, $v, $e = 0) { return update_option('_t_' . $k, $v); }
function delete_transient($k) { return delete_option('_t_' . $k); }
function add_action(...$a) {}
function add_filter(...$a) {}
function register_activation_hook(...$a) {}
function plugin_basename($f) { return basename((string) $f); }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_attr($t) { return esc_html($t); }
function wp_strip_all_tags($t) { return trim(strip_tags((string) $t)); }
function wp_kses_post($t) { return (string) $t; }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function sanitize_key($t) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $t)); }
function sanitize_title($t) { $t = strtolower(trim(strip_tags((string) $t))); $t = preg_replace('/[^a-z0-9]+/', '-', $t); return trim((string) $t, '-'); }
function absint($n) { return abs((int) $n); }
function __($s, $d = null) { return $s; }
function wp_json_encode($v) { return json_encode($v); }
function wp_parse_url($u, $c = -1) { return $c === -1 ? parse_url((string) $u) : parse_url((string) $u, $c); }
function esc_url_raw($u) { $u = trim((string) $u); return preg_match('#^https?://#i', $u) ? $u : ''; }
function home_url($p = '') { return 'https://site.example' . $p; }
function get_bloginfo($k = '') { return 'Harness'; }

// ── Load the hub service (pulls its own module siblings) ─────────────────────
$root = dirname(__DIR__, 2);
require_once $root . '/includes/modules/seo/service.php';

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok  {$name}\n";
    } else {
        $fail++;
        echo "FAIL  {$name}\n";
    }
}

// Fixtures in the push shape (rules_to_schema output) — the fingerprint's
// contract input.
$rule_section = array(
    'id'          => 11,
    'target'      => 'section',
    'match'       => array('text' => 'about us', 'occurrence' => 0),
    'replacement' => '<h2>About us</h2><p>New body.</p>',
    'active'      => true,
    'section'     => array('level' => 2, 'fingerprint' => 'abc123'),
    'anchor'      => null,
);
$rule_insert = array(
    'id'          => 12,
    'target'      => 'sectionInsert',
    'match'       => array('text' => 'about us', 'occurrence' => 0),
    'replacement' => '<h2>FAQ</h2><p>Questions.</p>',
    'active'      => true,
    'section'     => array('level' => 2, 'position' => 'after'),
    'anchor'      => null,
);

// ═══ 1. page_fingerprint ═══
echo "page_fingerprint\n";
$fp = PCM_SEO_Service::page_fingerprint(array($rule_section, $rule_insert));
check('sha1 format', (bool) preg_match('/^[0-9a-f]{40}$/', $fp));
check('stable across calls', PCM_SEO_Service::page_fingerprint(array($rule_section, $rule_insert)) === $fp);
check('rule order is a storage accident', PCM_SEO_Service::page_fingerprint(array($rule_insert, $rule_section)) === $fp);

$reordered_keys = array(
    'anchor'      => null,
    'section'     => array('fingerprint' => 'abc123', 'level' => 2),
    'active'      => true,
    'replacement' => '<h2>About us</h2><p>New body.</p>',
    'match'       => array('occurrence' => 0, 'text' => 'about us'),
    'target'      => 'section',
    'id'          => 11,
);
check('map-key order is a storage accident (nested too)', PCM_SEO_Service::page_fingerprint(array($reordered_keys, $rule_insert)) === $fp);

$new_id       = $rule_section;
$new_id['id'] = 999;
check('row id is a storage handle, never content', PCM_SEO_Service::page_fingerprint(array($new_id, $rule_insert)) === $fp);

$edited                = $rule_section;
$edited['replacement'] = '<h2>About us</h2><p>Different body.</p>';
check('replacement change changes the fingerprint', PCM_SEO_Service::page_fingerprint(array($edited, $rule_insert)) !== $fp);

$inactive           = $rule_section;
$inactive['active'] = false;
check('active flag is content (it changes serving)', PCM_SEO_Service::page_fingerprint(array($inactive, $rule_insert)) !== $fp);

$with_paras            = $rule_section;
$with_paras['section'] = array('level' => 2, 'fingerprint' => 'abc123', 'paragraphs' => array(
    array('text' => 'first', 'occurrence' => 0),
    array('text' => 'second', 'occurrence' => 0),
));
$paras_swapped            = $rule_section;
$paras_swapped['section'] = array('level' => 2, 'fingerprint' => 'abc123', 'paragraphs' => array(
    array('text' => 'second', 'occurrence' => 0),
    array('text' => 'first', 'occurrence' => 0),
));
check('order INSIDE a rule list is content', PCM_SEO_Service::page_fingerprint(array($with_paras)) !== PCM_SEO_Service::page_fingerprint(array($paras_swapped)));

check('subset is not the set', PCM_SEO_Service::page_fingerprint(array($rule_section)) !== $fp);
check('empty set fingerprints deterministically', PCM_SEO_Service::page_fingerprint(array()) === PCM_SEO_Service::page_fingerprint(array()) && PCM_SEO_Service::page_fingerprint(array()) !== $fp);

// ═══ 2. page state record ═══
echo "page state record\n";
$state0 = PCM_SEO_Service::page_state(2, 3);
check('never-saved baseline is version 0 / empty fingerprint', $state0['version'] === 0 && $state0['fingerprint'] === '' && $state0['savedAt'] === 0);

// write_page_state runs only after an ACCEPTED push (private by design) —
// exercised directly here, exactly as push_current_rules_or_rollback calls it.
$write = new ReflectionMethod('PCM_SEO_Service', 'write_page_state');
$write->setAccessible(true);
$write->invoke(null, 2, 3, PCM_SEO_Service::page_state(2, 3)['version'] + 1, 'fp-one');
$state1 = PCM_SEO_Service::page_state(2, 3);
check('first accepted push records version 1', $state1['version'] === 1 && $state1['fingerprint'] === 'fp-one');
check('savedAt recorded', $state1['savedAt'] > 0);

$write->invoke(null, 2, 3, PCM_SEO_Service::page_state(2, 3)['version'] + 1, 'fp-two');
$state2 = PCM_SEO_Service::page_state(2, 3);
check('next accepted push bumps to version 2', $state2['version'] === 2 && $state2['fingerprint'] === 'fp-two');

check('option map keyed "siteId:postId"', isset($GLOBALS['__opts']['pcm_page_state']['2:3']));
check('other post untouched', PCM_SEO_Service::page_state(2, 4)['version'] === 0);
check('other site untouched', PCM_SEO_Service::page_state(3, 3)['version'] === 0);

// ═══ 3. superseded_rule_ids (W2 net set) ═══
echo "superseded_rule_ids\n";
$rows = array(
    array('id' => 1, 'target' => 'section'),       // attributed — its output serves
    array('id' => 2, 'target' => 'section'),       // replaced — attributed to nothing
    array('id' => 3, 'target' => 'sectionInsert'), // dead insert — anchor gone
    array('id' => 4, 'target' => 'sectionInsert'), // attributed insert — serves
    array('id' => 5, 'target' => 'sectionRemove'), // NET by law — stays while the doc omits its section
    array('id' => 6, 'target' => 'paragraph'),     // absorb law owns it
    array('id' => 7, 'target' => 'heading'),       // serves independently
    array('id' => 8, 'target' => 'image'),         // image reconciliation owns it
    array('id' => 9, 'target' => 'title'),         // never section-family
);
$dead = PCM_SEO_Service::superseded_rule_ids($rows, array(1, 4));
check('replace supersedes: the dead section row drops', in_array(2, $dead, true));
check('dead insert identity drops', in_array(3, $dead, true));
check('exactly the dead section-family rows', $dead === array(2, 3));
check('attributed rows survive', !in_array(1, $dead, true) && !in_array(4, $dead, true));
check('sectionRemove is kept by law', !in_array(5, $dead, true));
check('other targets are never swept', !array_intersect(array(6, 7, 8, 9), $dead));
check('all live = nothing to flatten', PCM_SEO_Service::superseded_rule_ids($rows, array(1, 2, 3, 4)) === array());
check('no rows = nothing to flatten', PCM_SEO_Service::superseded_rule_ids(array(), array(1)) === array());
check('string ids from the wire still match', PCM_SEO_Service::superseded_rule_ids($rows, array('1', '4')) === array(2, 3));

// ═══ 4. THE SAVE TRANSACTION chokepoints (gap ATOMIC-SAVE 2026-07-17) ═══
// With the deferral flag set, the two chokepoints every routed save flows
// through must act on NOTHING: push_current_rules_or_rollback returns the
// stub before touching rows/state (this harness has no $wpdb — reaching it
// would fatal, so a passing check IS the proof), and record_version queues.
echo "save transaction chokepoints\n";
$flag = new ReflectionProperty('PCM_SEO_Service', 'push_deferred');
$flag->setAccessible(true);
$queue = new ReflectionProperty('PCM_SEO_Service', 'deferred_versions');
$queue->setAccessible(true);
$flag->setValue(null, true);
$queue->setValue(null, array());

$push_m = new ReflectionMethod('PCM_SEO_Service', 'push_current_rules_or_rollback');
$push_m->setAccessible(true);
$state_before = PCM_SEO_Service::page_state(2, 3);
$stub = $push_m->invoke(null, 1, (object) array('id' => 2), 3, array());
check('deferred push returns the stub', is_array($stub) && ($stub['deferred'] ?? false) === true);
check('deferred push writes NO page state', PCM_SEO_Service::page_state(2, 3) === $state_before);

$rec_m = new ReflectionMethod('PCM_SEO_Service', 'record_version');
$rec_m->setAccessible(true);
$rec_m->invoke(null, 1, 2, 3, 'section', 'heading key', 0, '<p>queued</p>');
$queued = $queue->getValue(null);
check('deferred record_version queues instead of inserting', count($queued) === 1
    && $queued[0] === array(1, 2, 3, 'section', 'heading key', 0, '<p>queued</p>'));

$flag->setValue(null, false);
$queue->setValue(null, array());
check('flag and queue reset clean', $flag->getValue(null) === false && $queue->getValue(null) === array());

// ═══ 5. parse_section_reply — THE CHANGE-CARD contract (gap 0a0a3c3) ═══
// The model's confession is CHECKED, never believed: quotes must exist in
// the produced text, foreign why ids blank, parse failure = raw fallback.
echo "parse_section_reply\n";
$reply = json_encode(array(
    'html'    => '<h2>Privacy</h2><p>We protect your personal data with care.</p>',
    'changes' => array(
        array('what' => 'Added a direct answer', 'why' => 'answerability', 'quote' => 'protect your personal data'),
        array('what' => 'Claimed but absent',    'why' => 'answerability', 'quote' => 'this text is nowhere'),
        array('what' => 'Foreign purpose',       'why' => 'made-up-id',    'quote' => 'We protect your'),
        array('what' => '',                      'why' => 'answerability', 'quote' => 'personal data'),
    ),
));
$p = PCM_SEO_Service::parse_section_reply($reply, array('answerability'), true);
check('html extracted as the value', strpos($p['value'], '<h2>Privacy</h2>') === 0);
check('verified change kept', count($p['changes']) === 2 && $p['changes'][0]['what'] === 'Added a direct answer');
check('verified why kept', $p['changes'][0]['why'] === 'answerability');
check('unverifiable quote dropped', !in_array('Claimed but absent', array_column($p['changes'], 'what'), true));
check('foreign why blanked, change kept', $p['changes'][1]['why'] === '' && $p['changes'][1]['what'] === 'Foreign purpose');
check('empty what dropped', count($p['changes']) === 2);

$fenced = "```json\n" . $reply . "\n```";
$pf = PCM_SEO_Service::parse_section_reply($fenced, array('answerability'), true);
check('fenced reply still parses', strpos($pf['value'], '<h2>Privacy</h2>') === 0 && count($pf['changes']) === 2);

$raw = '<h2>Plain</h2><p>Just html, no JSON envelope.</p>';
$pr = PCM_SEO_Service::parse_section_reply($raw, array(), true);
check('non-JSON falls back to the raw reply (the floor)', $pr['value'] === $raw && $pr['changes'] === array());
$pn = PCM_SEO_Service::parse_section_reply($raw, array(), false);
check('envelope not requested = raw untouched', $pn['value'] === $raw && $pn['changes'] === array());

$many = array('html' => '<p>' . str_repeat('word ', 50) . 'quoted words here.</p>', 'changes' => array());
for ($ci = 0; $ci < 20; $ci++) {
    $many['changes'][] = array('what' => 'Change ' . $ci, 'why' => '', 'quote' => 'quoted words here');
}
$pm = PCM_SEO_Service::parse_section_reply(json_encode($many), array(), true);
check('change list capped', count($pm['changes']) === 12);

echo "\n{$pass}/" . ($pass + $fail) . " passed\n";
exit($fail === 0 ? 0 : 1);
