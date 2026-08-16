<?php
/**
 * "The table is not reading the meta tags, even if there are meta tags" —
 * THIRD report, closed at the source this time.
 *
 * Diagnosed LIVE on massagegoteborg.nu (2026-08-16), the exact page from the
 * card:
 *   GET the permalink (any user-agent) → HTTP 403 Cloudflare "Just a moment..."
 *       — the public-page reader is WALLED; the challenge guard correctly
 *       refuses to cache it, but nothing else could read the page.
 *   GET /wp-json/wp/v2/posts?_fields=…yoast_head_json → 200, and Yoast's
 *       yoast_head_json carries the exact title + description in the card's
 *       screenshot. Rank Math and SEOPress expose equivalents.
 *   context=embed drops `meta` entirely; yoast_head_json survives every context.
 *
 * So: the SEO plugins' RENDERED-HEAD REST fields are now read (remote), the
 * plugin's own PHP API is asked (local + connector), and the walled loopback
 * fetch becomes the last resort instead of the only path.
 *
 * head_fields_from_item() and remote_row() are EXECUTED against the REAL
 * payload captured from that site (tests/standalone/fixtures/…).
 *
 * Run: php tests/standalone/seo_rendered_head_fields_test.php
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

// WP stubs the real code calls.
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function wp_specialchars_decode($s, $q = null) { return html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
function wp_trim_words($t, $n = 55, $m = '…') { $w = preg_split('/\s+/', trim((string) $t)); return count($w) > $n ? implode(' ', array_slice($w, 0, $n)) . $m : implode(' ', $w); }

$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');
$slice = static function (string $src, string $name): string {
    $i = strpos($src, "function {$name}(");
    $start = strrpos(substr($src, 0, (int)$i), 'public static');
    $j = strpos($src, "\n    /**", (int)$i);
    if ($j === false) { $j = strpos($src, "\n    public ", (int)$i + 10); }
    $fn = substr($src, (int)$start, (int)$j - (int)$start);
    if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
    return $fn;
};
preg_match("/public const REMOTE_HEAD_FIELDS = '[^']+';/", $svc, $cm);
$row_fn = str_replace('self::remote_meta_keys', 'self::meta_keys', $slice($svc, 'remote_row'));
eval('class HeadHost { ' . ($cm[0] ?? '') . "\n"
    . $slice($svc, 'head_fields_from_item') . "\n" . $row_fn . "\n"
    . ' public static function meta_keys($f) { $m = array(
        "metaTitle" => array("pcm_seo_meta_title", "_yoast_wpseo_title", "rank_math_title", "_seopress_titles_title"),
        "metaDescription" => array("pcm_seo_meta_description", "_yoast_wpseo_metadesc", "rank_math_description", "_seopress_titles_desc"),
      ); return $m[$f] ?? array(); } }');
check('head_fields_from_item + remote_row hosted', method_exists('HeadHost', 'head_fields_from_item') && method_exists('HeadHost', 'remote_row'));

$site = (object) array('id' => 3, 'url' => 'https://massagegoteborg.nu');
$FX = json_decode(file_get_contents($ROOT . '/tests/standalone/fixtures/massagegoteborg_post_rest.json'), true);
check('the LIVE fixture loaded (captured 2026-08-16 from the card\'s site)', is_array($FX) && !empty($FX['yoast_head_json']), $FX);

echo "\n1. The card's exact page — REAL payload, EXECUTED\n";
$h = HeadHost::head_fields_from_item($FX);
check('Yoast rendered title read', $h['title'] === 'Why the Highest Compliment in Business Is Hearing I Feel Better', $h);
check('Yoast rendered description read', str_starts_with($h['description'], 'Discover why a customer feeling better'), $h);
$row = HeadHost::remote_row($FX, 'post', $site);
check('the ROW carries the meta title (the blank cell in the screenshot)', $row['metaTitle'] === 'Why the Highest Compliment in Business Is Hearing I Feel Better', $row['metaTitle']);
check('…and the meta description', str_starts_with($row['metaDescription'], 'Discover why a customer feeling better'), $row['metaDescription']);

echo "\n2. The scenario that produced the blank table: `meta` MISSING, head present\n";
// embed-context / security-plugin stripping / meta not registered → no `meta` at all.
$no_meta = $FX; unset($no_meta['meta']);
$row = HeadHost::remote_row($no_meta, 'post', $site);
check('with NO meta at all, the rendered head still fills the cells',
    $row['metaTitle'] !== '' && $row['metaDescription'] !== '', array($row['metaTitle'], $row['metaDescription']));
// Template-generated: plugin stores NOTHING per post, but renders a title.
$tpl_only = $FX; $tpl_only['meta'] = array('_yoast_wpseo_title' => '', '_yoast_wpseo_metadesc' => '');
$row = HeadHost::remote_row($tpl_only, 'post', $site);
check('empty stored overrides + rendered head → the rendered value shows (template-generated tags)',
    $row['metaTitle'] === $h['title'], $row['metaTitle']);

echo "\n3. Precedence + the other plugins\n";
$stored = $FX; $stored['meta']['pcm_seo_meta_title'] = 'Hub-set title';
$row = HeadHost::remote_row($stored, 'post', $site);
check('a STORED override still wins over the rendered head', $row['metaTitle'] === 'Hub-set title', $row['metaTitle']);
$rm = array('id' => 9, 'rank_math_title' => 'RM &amp; Title', 'rank_math_description' => '<b>RM</b> desc');
$h = HeadHost::head_fields_from_item($rm);
check('Rank Math fields read, entity-decoded + tag-stripped', $h['title'] === 'RM & Title' && $h['description'] === 'RM desc', $h);
$rm2 = array('id' => 9, 'head' => array('title' => 'RM head title', 'description' => 'RM head desc'));
check('Rank Math `head` object read', HeadHost::head_fields_from_item($rm2)['title'] === 'RM head title');
$sp = array('id' => 9, 'seopress_titles_title' => 'SP title', 'seopress_titles_desc' => 'SP desc');
check('SEOPress fields read', HeadHost::head_fields_from_item($sp)['description'] === 'SP desc');
$og = array('id' => 9, 'yoast_head_json' => array('og_title' => 'OG only', 'og_description' => 'OG desc'));
check('Yoast og_* fallbacks when title/description absent', HeadHost::head_fields_from_item($og) === array('title' => 'OG only', 'description' => 'OG desc'));
check('no head data at all → empty strings (never a leaked structure)', HeadHost::head_fields_from_item(array('id' => 1)) === array('title' => '', 'description' => ''));

echo "\n4. The list REQUESTS the fields (core silently ignores unknown _fields)\n";
check('REMOTE_HEAD_FIELDS names every plugin\'s field',
    strpos((string) ($cm[0] ?? ''), 'yoast_head_json') !== false && strpos((string) ($cm[0] ?? ''), 'rank_math_title') !== false
    && strpos((string) ($cm[0] ?? ''), 'seopress_titles_title') !== false, $cm[0] ?? '');
check('remote_list_content appends them to _fields',
    strpos($svc, "_embedded.wp:featuredmedia,' . self::REMOTE_HEAD_FIELDS;") !== false, 'fields not requested');
check('the row uses the head as the FALLBACK for both cells',
    strpos($svc, "\$pick(self::remote_meta_keys('metaTitle'), \$head['title'])") !== false
    && strpos($svc, "\$pick(self::remote_meta_keys('metaDescription'), \$head['description'])") !== false, 'row not wired');

echo "\n5. Local + connector ask the PLUGIN, not the walled page\n";
$loc = file_get_contents($ROOT . '/includes/modules/seo/local.php');
check('local: plugin_rendered_head exists', strpos($loc, 'public static function plugin_rendered_head') !== false);
check('local: Yoast API asked (YoastSEO()->meta->for_post)', strpos($loc, '->for_post($post_id)') !== false);
check('local: Rank Math template resolved via replace_vars', strpos($loc, 'RankMath\\Helper::replace_vars') !== false);
check('local: any plugin API failure degrades to empty, never fatal', preg_match('/plugin_rendered_head[\s\S]{0,3000}?catch \(\\\\Throwable/', $loc) === 1);
check('local rows fall back to it for title AND description',
    strpos($loc, "self::plugin_rendered_head(\$id)['title']") !== false && strpos($loc, "self::plugin_rendered_head(\$id)['description']") !== false, 'local row not wired');
$hub = file_get_contents($ROOT . '/includes/modules/seohub/service.php');
$a = strpos($hub, "<<<'PHP'"); $b = strpos($hub, "\nPHP;", (int)$a);
$tpl = substr($hub, (int)$a + 9, (int)$b - (int)$a - 9);
$ht = substr($tpl, strpos($tpl, "'/head-tags'"), 4000);
// ORDER is the point: the plugin API call must come BEFORE the loopback wp_remote_get.
$api_at = strpos($ht, 'YoastSEO()->meta');
$fetch_at = strpos($ht, 'wp_remote_get(get_permalink($pid)');
check('connector /head-tags asks the plugin API FIRST (before the loopback fetch)',
    $api_at !== false && $fetch_at !== false && $api_at < $fetch_at, array('api' => $api_at, 'fetch' => $fetch_at));
check('connector: a plugin answer skips the loopback fetch entirely',
    preg_match('/if \(\$api_title !== \'\' \|\| \$api_desc !== \'\'\) \{[\s\S]{0,300}?continue;/', $ht) === 1, 'still fetches after API answer');
check('connector: the challenge guard on the fetch remains (last resort still honest)',
    strpos($ht, 'Just a moment') !== false);
// The template changed → the connector must still parse.
$baked = str_replace(array('__PCM_CONN_UPDATE_URI__', '__PCM_CONN_VERSION__', '__PCM_HUB_URL__', '__PCM_CLIENT_ID__'), array('https://x', '0.0.0', 'https://hub', 'cid'), $tpl);
$tmp = tempnam(sys_get_temp_dir(), 'pcmconn') . '.php';
file_put_contents($tmp, $baked);
exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $lo, $lc);
@unlink($tmp);
check('the extracted connector template parses', $lc === 0, implode("\n", array_slice($lo, 0, 3)));

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
