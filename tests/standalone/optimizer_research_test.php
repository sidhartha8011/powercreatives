<?php
/**
 * STANDALONE research-spine tests — run with bare PHP, no vendor:
 *
 *   php tests/standalone/optimizer_research_test.php
 *
 * WHAT IT PROVES (deterministic parts of the research spine only — LLM
 * teachers are exercised live through the rail):
 *
 * 1. context_block — the ONE formatter: full package renders every section,
 *    empty package renders NOTHING (no hallucination bait).
 * 2. mentions_brand — the mention panel's deterministic verdict: name hit,
 *    host hit (www/scheme-insensitive), honest miss.
 * 3. onpage teacher — word count, primary placement, stuffing, supporting-
 *    in-headings, and the honest no-primary item.
 * 4. demand teacher — striking-distance filter over stored GSC rows:
 *    in-range kept, in-content skipped, out-of-range skipped, impressions
 *    order, honest source label, honest no-data error.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

// ── Minimal WP shims (the run.php set + what the spine touches) ──────────────
define('ABSPATH', __DIR__ . '/');
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
$GLOBALS['__opts'] = array();
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['__opts']) ? $GLOBALS['__opts'][$k] : $d; }
function update_option($k, $v, $a = false) { $GLOBALS['__opts'][$k] = $v; return true; }
function add_option($k, $v, $x = '', $a = false) { if (!array_key_exists($k, $GLOBALS['__opts'])) { $GLOBALS['__opts'][$k] = $v; } return true; }
function delete_option($k) { unset($GLOBALS['__opts'][$k]); return true; }
function wp_strip_all_tags($t) { return trim(strip_tags((string) $t)); }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function sanitize_key($t) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $t)); }
function sanitize_title($t) { $t = strtolower(trim(strip_tags((string) $t))); $t = preg_replace('/[^a-z0-9]+/', '-', $t); return trim((string) $t, '-'); }
function absint($n) { return abs((int) $n); }
function __($s, $d = null) { return $s; }
function wp_json_encode($v) { return json_encode($v); }
function wp_parse_url($u, $c = -1) { return $c === -1 ? parse_url((string) $u) : parse_url((string) $u, $c); }
function esc_url_raw($u) { $u = trim((string) $u); return preg_match('#^https?://#i', $u) ? $u : ''; }

// ── Load the spine (service + interface + the deterministic teachers) ────────
$root = dirname(__DIR__, 2);
require_once $root . '/includes/modules/optimizer/service.php';
require_once $root . '/includes/modules/optimizer/teachers/interface-pcm-optimizer-teacher.php';
require_once $root . '/includes/modules/optimizer/teachers/class-pcm-teacher-onpage.php';
require_once $root . '/includes/modules/optimizer/teachers/class-pcm-teacher-demand.php';

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

// ═══ 1. context_block ═══
echo "context_block\n";
$full = PCM_Optimizer_Service::context_block(array(
    'keywords' => array('primary' => 'tandläkare stockholm', 'supporting' => array('akut tandvård'), 'additional' => array('tandblekning', 'implantat')),
    'business' => array('name' => 'Smile AB', 'phone' => '08-123 456', 'address' => 'Storgatan 1, Stockholm', 'category' => 'Dentist'),
    'pageType' => 'local',
));
check('primary line present', strpos($full, 'PRIMARY KEYWORD') !== false && strpos($full, 'tandläkare stockholm') !== false);
check('supporting line present', strpos($full, 'SUPPORTING KEYWORDS') !== false && strpos($full, 'akut tandvård') !== false);
check('additional line present', strpos($full, 'ADDITIONAL KEYWORDS') !== false && strpos($full, 'tandblekning, implantat') !== false);
check('business facts present', strpos($full, 'BUSINESS FACTS') !== false && strpos($full, '08-123 456') !== false);
check('page type present', strpos($full, 'PAGE TYPE: local') !== false);
check('empty package renders empty', PCM_Optimizer_Service::context_block(array()) === '');
check('general page type omitted', strpos(PCM_Optimizer_Service::context_block(array('pageType' => 'general')), 'PAGE TYPE') === false);
check('suffix separates non-empty', strpos(PCM_Optimizer_Service::context_suffix(array('pageType' => 'blog')), "\n\nPAGE CONTEXT:") === 0);
check('suffix empty stays empty', PCM_Optimizer_Service::context_suffix(array()) === '');

// ═══ 2. mentions_brand ═══
echo "mentions_brand\n";
$biz = array('name' => 'Smile AB', 'website' => 'https://www.smile.se');
check('name match', PCM_Optimizer_Service::mentions_brand('I would recommend Smile AB and two others.', $biz));
check('case-insensitive name', PCM_Optimizer_Service::mentions_brand('SMILE ab is solid.', $biz));
check('host match without www', PCM_Optimizer_Service::mentions_brand('See smile.se for details.', $biz));
check('honest miss', !PCM_Optimizer_Service::mentions_brand('I recommend Dental Kings and Tooth Co.', $biz));
check('empty record never matches', !PCM_Optimizer_Service::mentions_brand('Anything at all.', array()));

// ═══ 3. onpage teacher ═══
echo "onpage teacher\n";
$onpage = new PCM_Teacher_Onpage();
$html = '<h1>Emergency dental care in Stockholm</h1>'
    . '<p>We provide emergency dental care for the whole family.</p>'
    . '<h2>Prices for emergency dental care</h2><p>' . str_repeat('Plain words about teeth and calm handling of pain. ', 40) . '</p>'
    . '<h2>Opening hours</h2><p>Open every day.</p>';
$ctx = array(
    'html'     => $html,
    'keywords' => array('primary' => 'emergency dental care', 'supporting' => array('opening hours'), 'additional' => array()),
    'pageType' => 'local',
);
$items = $onpage->analyze($ctx);
$by_id = array();
foreach ($items as $it) { $by_id[$it['id']] = $it; }
check('primary in first heading passes', isset($by_id['primary-first-heading']) && $by_id['primary-first-heading']['found'] === false);
check('primary in first paragraph passes', isset($by_id['primary-first-paragraph']) && $by_id['primary-first-paragraph']['found'] === false);
check('supporting found in headings', isset($by_id['supporting-headings']) && $by_id['supporting-headings']['found'] === false);
check('every item carries the teacher id', array_reduce($items, static fn($c, $it) => $c && $it['teacherId'] === 'onpage', true));

$thin = $onpage->analyze(array('html' => '<h1>Tiny</h1><p>Ten words only in this thin little page here.</p>', 'keywords' => array('primary' => 'emergency dental care')));
$thin_by = array();
foreach ($thin as $it) { $thin_by[$it['id']] = $it; }
check('thin content flagged', isset($thin_by['word-count']) && $thin_by['word-count']['found'] === true);
check('absent primary in heading flagged', isset($thin_by['primary-first-heading']) && $thin_by['primary-first-heading']['found'] === true);

$stuffed_body = '<h1>emergency dental care</h1><p>' . str_repeat('emergency dental care ', 30) . 'word</p>';
$stuffed = $onpage->analyze(array('html' => $stuffed_body, 'keywords' => array('primary' => 'emergency dental care')));
$has_stuffing = false;
foreach ($stuffed as $it) { if (strpos($it['id'], 'stuffing-') === 0 && $it['found']) { $has_stuffing = true; } }
check('stuffing flagged', $has_stuffing);

$no_kw = $onpage->analyze(array('html' => $html, 'keywords' => array()));
$no_kw_by = array();
foreach ($no_kw as $it) { $no_kw_by[$it['id']] = $it; }
check('no primary = honest informational item', isset($no_kw_by['primary-missing']) && $no_kw_by['primary-missing']['found'] === true && $no_kw_by['primary-missing']['instruction'] === '');

// ═══ 4. demand teacher ═══
echo "demand teacher\n";
$demand = new PCM_Teacher_Demand();
$GLOBALS['__opts']['pcm_optimizer_kw_stats_cache'] = array(
    '7:42' => array(
        'property'  => 'sc-domain:smile.se',
        'fetchedAt' => 1780000000,
        'rows'      => array(
            array('query' => 'akut tandläkare pris', 'clicks' => 2, 'impressions' => 900, 'position' => 8.4),
            array('query' => 'tandläkare öppettider', 'clicks' => 1, 'impressions' => 500, 'position' => 12.0),
            array('query' => 'already in content', 'clicks' => 5, 'impressions' => 2000, 'position' => 6.0),
            array('query' => 'rank one query', 'clicks' => 50, 'impressions' => 5000, 'position' => 1.2),
            array('query' => 'page two query', 'clicks' => 0, 'impressions' => 100, 'position' => 35.0),
            array('query' => 'vanished query', 'clicks' => 0, 'impressions' => 0, 'position' => null),
        ),
    ),
);
$demand_ctx = array('siteId' => 7, 'postId' => 42, 'html' => '<p>This text says already in content once.</p>');
$out = $demand->analyze($demand_ctx);
$queries = array_map(static fn(array $it): string => $it['label'], $out);
check('striking-distance kept', (bool) preg_grep('/akut tandläkare pris/', $queries));
check('in-content skipped', !(bool) preg_grep('/already in content/', $queries));
check('rank-one skipped', !(bool) preg_grep('/rank one query/', $queries));
check('deep page-two skipped', !(bool) preg_grep('/page two query/', $queries));
check('vanished (null position) skipped', !(bool) preg_grep('/vanished query/', $queries));
check('impressions order', isset($out[0]) && strpos($out[0]['label'], 'akut tandläkare pris') !== false);
check('honest stored source label', isset($out[0]['source']) && strpos($out[0]['source'], 'gsc:stored (') === 0);

$empty_ctx = array('siteId' => 9, 'postId' => 9, 'html' => '<p>x</p>');
$threw = false;
try { $demand->analyze($empty_ctx); } catch (\RuntimeException $e) { $threw = strpos($e->getMessage(), 'Ranking tab') !== false; }
check('no stored rows = honest named error', $threw);

$GLOBALS['__opts']['pcm_optimizer_kw_stats_cache']['3:3'] = array('property' => 'p', 'fetchedAt' => 1780000000, 'rows' => array(
    array('query' => 'covered topic', 'clicks' => 1, 'impressions' => 10, 'position' => 5.0),
));
$quiet = $demand->analyze(array('siteId' => 3, 'postId' => 3, 'html' => '<p>covered topic present.</p>'));
check('all-covered = quiet passing item, never blank', count($quiet) === 1 && $quiet[0]['found'] === false && $quiet[0]['id'] === 'no-striking-distance');

// ═══ tunables seed ═══
echo "research tunables\n";
unset($GLOBALS['__opts']['pcm_optimizer_research']);
$tun = PCM_Optimizer_Service::research_tunables();
check('seeded once with all four groups', isset($tun['onpage'], $tun['demand'], $tun['serp'], $tun['mention']));
check('seed persisted as option data', isset($GLOBALS['__opts']['pcm_optimizer_research']));
$GLOBALS['__opts']['pcm_optimizer_research']['demand']['maxPos'] = 15;
check('edited option wins (hub-controlled)', PCM_Optimizer_Service::research_tunables()['demand']['maxPos'] === 15);

echo "\n{$pass}/" . ($pass + $fail) . " passed\n";
exit($fail === 0 ? 0 : 1);
