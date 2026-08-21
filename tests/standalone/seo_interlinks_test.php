<?php
/**
 * SEO interlinks — the hierarchy overlay and the proposal engine, EXECUTED.
 *
 * Everything asserted here runs the REAL PCM_SEO_Interlinks methods. The pure
 * half was designed pure precisely so this file can do that: no $wpdb, no
 * WordPress, no scanning the source for a spelling.
 *
 * The laws under test:
 *   - An interlink NEVER invents copy. It wraps text that already exists, or
 *     it refuses and says why.
 *   - It never produces broken or nested markup.
 *   - Running it twice does not double-link.
 *   - The hierarchy is a TREE: no page may become its own ancestor.
 *
 * Run: php tests/standalone/seo_interlinks_test.php
 */

error_reporting(E_ALL);
$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/includes/modules/seo/interlinks.php';

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

// ── 1. markup safety ────────────────────────────────────────────────
$html = '<p>Book a <a href="/x">massage</a> today</p><p>massage helps</p>';

check('offset inside a tag is unsafe',
    PCM_SEO_Interlinks::is_inside_markup('<p class="a">hi</p>', 5) === true);
check('offset inside an existing anchor is unsafe',
    PCM_SEO_Interlinks::is_inside_markup($html, strpos($html, 'massage</a>')) === true);
check('offset in plain text is safe',
    PCM_SEO_Interlinks::is_inside_markup($html, strpos($html, 'massage helps')) === false);
check('offset after the anchor closes is safe',
    PCM_SEO_Interlinks::is_inside_markup('<a href="/x">a</a>massage', 24) === false);

// Multi-anchor content: the naive "last <a vs last </a>" shape must still be
// right when several anchors precede the offset (the strategy injector counts
// opens vs closes instead — both must agree).
$multi = '<a href="/1">one</a> mid <a href="/2">two</a> tail';
check('text BETWEEN two closed anchors is safe',
    PCM_SEO_Interlinks::is_inside_markup($multi, strpos($multi, 'mid')) === false);
check('text AFTER the last closed anchor is safe',
    PCM_SEO_Interlinks::is_inside_markup($multi, strpos($multi, 'tail')) === false);
$unclosed = '<a href="/1">one</a> mid <a href="/2">inside';
check('text inside a LATER unclosed anchor is unsafe',
    PCM_SEO_Interlinks::is_inside_markup($unclosed, strpos($unclosed, 'inside')) === true);
check('attribute-less <a> is still detected',
    PCM_SEO_Interlinks::is_inside_markup('<a>bare', 4) === true);

// ── 2. find_safe_occurrence ─────────────────────────────────────────
$r = PCM_SEO_Interlinks::find_safe_occurrence($html, 'massage');
check('skips the occurrence inside the anchor and finds the free one',
    $r !== null && $r[1] === strpos($html, 'massage helps'), $r);

$cased = '<p>Klassisk Massage is lovely</p>';
$r2 = PCM_SEO_Interlinks::find_safe_occurrence($cased, 'klassisk massage');
check('match is case-insensitive by default',
    $r2 !== null, $r2);
check('returns the text AS THE PAGE WRITES IT, not as the needle was typed',
    $r2 !== null && $r2[0] === 'Klassisk Massage', $r2);
check('case_sensitive=true refuses a differing case',
    PCM_SEO_Interlinks::find_safe_occurrence($cased, 'klassisk massage', true) === null);
check('no occurrence returns null',
    PCM_SEO_Interlinks::find_safe_occurrence($html, 'chiropractic') === null);
check('empty needle returns null (never wraps nothing)',
    PCM_SEO_Interlinks::find_safe_occurrence($html, '   ') === null);
check('an occurrence ONLY inside markup returns null',
    PCM_SEO_Interlinks::find_safe_occurrence('<a href="/x">only here</a>', 'only here') === null);

// ── 3. already_links_to (idempotency) ───────────────────────────────
$linked = '<p>See <a href="https://site.test/massage/">this</a></p>';
check('exact url counts as linked',
    PCM_SEO_Interlinks::already_links_to($linked, 'https://site.test/massage/') === true);
check('http vs https counts as linked',
    PCM_SEO_Interlinks::already_links_to($linked, 'http://site.test/massage/') === true);
check('trailing slash difference counts as linked',
    PCM_SEO_Interlinks::already_links_to($linked, 'https://site.test/massage') === true);
check('www difference counts as linked',
    PCM_SEO_Interlinks::already_links_to($linked, 'https://www.site.test/massage') === true);
check('a DIFFERENT page is not counted as linked',
    PCM_SEO_Interlinks::already_links_to($linked, 'https://site.test/other') === false);
check('single-quoted href is still seen',
    PCM_SEO_Interlinks::already_links_to("<a href='/massage'>x</a>", '/massage') === true);

// ── 4. insert_link ──────────────────────────────────────────────────
$body = '<p>We offer massage in Gothenburg.</p>';
$ins  = PCM_SEO_Interlinks::insert_link($body, 'https://site.test/massage', 'massage');
check('insert wraps the existing phrase',
    $ins !== null && $ins['content'] === '<p>We offer <a href="https://site.test/massage">massage</a> in Gothenburg.</p>',
    $ins['content'] ?? null);
check('insert reports the anchor it used',
    $ins !== null && $ins['anchor'] === 'massage', $ins);
check('THE COPY IS UNCHANGED apart from the tags',
    $ins !== null && strip_tags($ins['content']) === strip_tags($body), $ins['content'] ?? null);

check('running it again is a no-op (no double link)',
    PCM_SEO_Interlinks::insert_link($ins['content'], 'https://site.test/massage', 'massage') === null);

// The re-run above is ALSO blocked by the markup guard (the only occurrence is
// now inside the anchor), so on its own it does not prove the idempotency check
// exists. This one does: the page already links to the target AND the phrase
// appears again as free text, so only already_links_to() can refuse it.
// (Found by the negative control — the first assertion missed that mutant.)
$twice = '<p>See <a href="https://site.test/massage">our page</a>. We do massage daily.</p>';
check('a page already linking to the target is not linked a SECOND time',
    PCM_SEO_Interlinks::insert_link($twice, 'https://site.test/massage', 'massage') === null);
check('...and a DIFFERENT target on that same page is still allowed',
    PCM_SEO_Interlinks::insert_link($twice, 'https://site.test/other', 'massage') !== null);
check('refuses when the phrase is absent',
    PCM_SEO_Interlinks::insert_link($body, 'https://site.test/x', 'chiropractic') === null);
check('refuses an empty url',
    PCM_SEO_Interlinks::insert_link($body, '   ', 'massage') === null);
check('never nests inside an existing anchor',
    PCM_SEO_Interlinks::insert_link('<a href="/a">massage</a>', 'https://site.test/m', 'massage') === null);

// ── 5. would_cycle ──────────────────────────────────────────────────
$map = array(2 => 1, 3 => 2); // 3 -> 2 -> 1
check('a page cannot be its own parent',
    PCM_SEO_Interlinks::would_cycle($map, 5, 5) === true);
check('direct loop is refused (1 under 2, when 2 is already under 1)',
    PCM_SEO_Interlinks::would_cycle($map, 1, 2) === true);
check('deep loop is refused (1 under 3, three levels down)',
    PCM_SEO_Interlinks::would_cycle($map, 1, 3) === true);
check('a legitimate reparent is allowed',
    PCM_SEO_Interlinks::would_cycle($map, 4, 3) === false);
check('clearing a parent (0) is never a cycle',
    PCM_SEO_Interlinks::would_cycle($map, 2, 0) === false);

// ── 6. depths ───────────────────────────────────────────────────────
$d = PCM_SEO_Interlinks::depths(array(1, 2, 3), array(2 => 1, 3 => 2));
check('root is depth 0', ($d[1] ?? null) === 0, $d);
check('child is depth 1',  ($d[2] ?? null) === 1, $d);
check('grandchild is depth 2', ($d[3] ?? null) === 2, $d);
$d2 = PCM_SEO_Interlinks::depths(array(3), array(3 => 99));
check('a child whose parent is NOT in the table shows as a root (never hidden)',
    ($d2[3] ?? null) === 0, $d2);
$d3 = PCM_SEO_Interlinks::depths(array(1, 2), array(1 => 2, 2 => 1));
check('a corrupt cyclic map still terminates',
    is_int($d3[1]) && is_int($d3[2]), $d3);

// ── 7. propose ──────────────────────────────────────────────────────
$rows = array(
    array('id' => 1, 'title' => 'Massage Göteborg', 'permalink' => 'https://s.test/massage-goteborg', 'primaryKeyword' => 'massage i Göteborg'),
    array('id' => 2, 'title' => 'Klassisk massage',  'permalink' => 'https://s.test/klassisk',        'primaryKeyword' => 'klassisk massage'),
);
$bodies = array(
    1 => '<p>Vi erbjuder klassisk massage och mer.</p>',
    2 => '<p>Boka massage i Göteborg hos oss.</p>',
);
$hier = array(2 => 1); // 2 is a child of 1

$props = PCM_SEO_Interlinks::propose($rows, $bodies, $hier);
check('one pair yields two proposals (up and down)', count($props) === 2, count($props));

$up = null; $down = null;
foreach ($props as $p) { if ($p['direction'] === 'up') { $up = $p; } else { $down = $p; } }

check('UP links the child to its parent',
    $up && $up['sourceId'] === 2 && $up['targetId'] === 1, $up);
check('UP anchors on the parent keyword found in the child body',
    $up && $up['anchor'] === 'massage i Göteborg', $up);
check('DOWN links the parent to the child',
    $down && $down['sourceId'] === 1 && $down['targetId'] === 2, $down);
check('DOWN anchors on the child keyword found in the parent body',
    $down && $down['anchor'] === 'klassisk massage', $down);

// direction filter
$only_up = PCM_SEO_Interlinks::propose($rows, $bodies, $hier, array('directions' => array('up')));
check('directions: up only yields exactly the up link',
    count($only_up) === 1 && $only_up[0]['direction'] === 'up', $only_up);
// The MIRROR matters: asserting only the 'up' filter lets a bug that ignores
// the 'up' gate pass, because 'down' is still gated and the count stays 1.
// (The negative control caught precisely that.)
$only_down = PCM_SEO_Interlinks::propose($rows, $bodies, $hier, array('directions' => array('down')));
check('directions: down only yields exactly the down link',
    count($only_down) === 1 && $only_down[0]['direction'] === 'down', $only_down);

// honest refusals
$already = PCM_SEO_Interlinks::propose(
    $rows,
    array(1 => $bodies[1], 2 => '<p>Boka <a href="https://s.test/massage-goteborg">massage i Göteborg</a>.</p>'),
    $hier,
    array('directions' => array('up'))
);
check('an already-linked page is refused, not re-linked',
    $already[0]['anchor'] === '' && $already[0]['reason'] === 'already linked', $already[0]);

$nohit = PCM_SEO_Interlinks::propose(
    $rows,
    array(1 => $bodies[1], 2 => '<p>Helt orelaterad text.</p>'),
    $hier,
    array('directions' => array('up'))
);
check('no safe occurrence is refused with a reason naming the phrase',
    $nohit[0]['anchor'] === '' && strpos($nohit[0]['reason'], 'massage i Göteborg') !== false, $nohit[0]);

$noperma = PCM_SEO_Interlinks::propose(
    array($rows[1], array('id' => 1, 'title' => 'P', 'permalink' => '', 'primaryKeyword' => 'x')),
    $bodies, $hier, array('directions' => array('up'))
);
check('a target with no permalink is refused honestly',
    $noperma[0]['anchor'] === '' && strpos($noperma[0]['reason'], 'permalink') !== false, $noperma[0]);

// title fallback when the keyword is absent
$rows_nokw = array(
    array('id' => 1, 'title' => 'Klassisk massage', 'permalink' => 'https://s.test/a', 'primaryKeyword' => ''),
    array('id' => 2, 'title' => 'Child',            'permalink' => 'https://s.test/b', 'primaryKeyword' => ''),
);
$fb = PCM_SEO_Interlinks::propose($rows_nokw, array(2 => '<p>Om Klassisk massage.</p>'), array(2 => 1), array('directions' => array('up')));
check('falls back to the target TITLE when it has no keyword',
    $fb[0]['anchor'] === 'Klassisk massage', $fb[0]);

// ── self-pair + path disambiguation (owner screenshot 2026-08-20) ──
// The list showed two rows reading "X → X". Titles are not an identity: a post
// and a page can share one. Two separate defences.
$same_title = array(
    array('id' => 1, 'title' => 'Compassionate Care', 'permalink' => 'https://s.test/care-page', 'primaryKeyword' => 'care'),
    array('id' => 2, 'title' => 'Compassionate Care', 'permalink' => 'https://s.test/blog/care',  'primaryKeyword' => 'care'),
);
$st = PCM_SEO_Interlinks::propose(
    $same_title,
    array(1 => '<p>About care here.</p>', 2 => '<p>More care writing.</p>'),
    array(2 => 1),
    array('directions' => array('up'))
);
check('two DIFFERENT pages sharing a title still produce a proposal',
    count($st) === 1, $st);
check('the paths distinguish them even though the titles match',
    $st[0]['sourcePath'] === '/blog/care' && $st[0]['targetPath'] === '/care-page', $st[0]);

// A map claiming a page is its own parent can only arrive out-of-band
// (set_parent refuses it), but it must never surface as "X -> X".
$selfmap = PCM_SEO_Interlinks::propose($rows, $bodies, array(1 => 1));
check('a page listed as its OWN parent produces no proposal at all',
    $selfmap === array(), $selfmap);

check('path_of strips scheme and host',
    PCM_SEO_Interlinks::path_of('https://site.test/a/b/') === '/a/b/');
check('path_of falls back to the query for ?page_id= permalinks',
    PCM_SEO_Interlinks::path_of('https://site.test/?page_id=9') === '?page_id=9');
check('path_of on an empty url is empty, never a crash',
    PCM_SEO_Interlinks::path_of('') === '');

// ── defined anchors (owner card 2026-08-20: "define the anchors for each of
// the parent so it can be … used to generate the interlinks") ──
// The dental-site failure: an ENGLISH primaryKeyword hunted in SWEDISH copy.
// Defined anchors are the phrases in the site's own language, and they outrank
// keyword and title.
$sv_rows = array(
    array('id' => 1, 'title' => 'Heart To Heart', 'permalink' => 'https://s.test/tandlakare', 'primaryKeyword' => 'dental clinic gothenburg'),
    array('id' => 2, 'title' => 'Om oss',         'permalink' => 'https://s.test/om',         'primaryKeyword' => ''),
);
$sv_body = array(2 => '<p>Vi är en tandläkare Göteborg litar på. Läs om tandvård här.</p>');

// Without anchors: the English keyword misses Swedish copy — honest refusal.
$no_anchor = PCM_SEO_Interlinks::propose($sv_rows, $sv_body, array(2 => 1), array('directions' => array('up')));
check('without defined anchors the English keyword still refuses on Swedish copy',
    $no_anchor[0]['anchor'] === '', $no_anchor[0]);

// With anchors: the defined Swedish phrase wins.
$with = PCM_SEO_Interlinks::propose($sv_rows, $sv_body, array(2 => 1),
    array('directions' => array('up'), 'anchors' => array(1 => array('tandläkare Göteborg', 'tandvård'))));
check('a defined anchor in the site language resolves where the keyword could not',
    $with[0]['anchor'] === 'tandläkare Göteborg', $with[0]);

// Order within the defined list is the user's ranking.
$ranked = PCM_SEO_Interlinks::propose($sv_rows, $sv_body, array(2 => 1),
    array('directions' => array('up'), 'anchors' => array(1 => array('tandvård', 'tandläkare Göteborg'))));
check('defined anchors are tried in the user\'s own order',
    $ranked[0]['anchor'] === 'tandvård', $ranked[0]);

// Defined anchors OUTRANK a keyword that would also match.
$both_rows = array(
    array('id' => 1, 'title' => 'P', 'permalink' => 'https://s.test/p', 'primaryKeyword' => 'tandvård'),
    array('id' => 2, 'title' => 'C', 'permalink' => 'https://s.test/c', 'primaryKeyword' => ''),
);
$outrank = PCM_SEO_Interlinks::propose($both_rows, $sv_body, array(2 => 1),
    array('directions' => array('up'), 'anchors' => array(1 => array('tandläkare Göteborg'))));
check('a defined anchor beats a keyword that would ALSO have matched',
    $outrank[0]['anchor'] === 'tandläkare Göteborg', $outrank[0]);

// When no defined anchor appears in the body, keyword/title still rescue.
$rescue = PCM_SEO_Interlinks::propose($both_rows, $sv_body, array(2 => 1),
    array('directions' => array('up'), 'anchors' => array(1 => array('implantat'))));
check('an absent defined anchor falls back to the keyword, not to a refusal',
    $rescue[0]['anchor'] === 'tandvård', $rescue[0]);

// The refusal names how many phrases were tried.
$multi_refuse = PCM_SEO_Interlinks::propose($sv_rows, array(2 => '<p>Helt annat.</p>'), array(2 => 1),
    array('directions' => array('up'), 'anchors' => array(1 => array('implantat', 'akut'))));
check('a multi-phrase refusal says how many candidates were tried',
    strpos($multi_refuse[0]['reason'], 'other phrases') !== false, $multi_refuse[0]);

// Anchors on a CHILD serve the down-link too — one mechanism, both directions.
$down = PCM_SEO_Interlinks::propose($sv_rows, array(1 => '<p>Läs mer om vår mottagning.</p>'), array(2 => 1),
    array('directions' => array('down'), 'anchors' => array(2 => array('vår mottagning'))));
check('anchors defined on a child are used when the pillar links DOWN to it',
    $down[0]['anchor'] === 'vår mottagning', $down[0]);

// ── set_anchors sanitation, EXECUTED against a scripted wpdb ──
if (!class_exists('WP_Error')) {
    class WP_Error { public $code; public $message; public $data;
        public function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->message = $m; $this->data = $d; } }
}
if (!function_exists('__')) { function __($s, $d = null) { return $s; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); } }
if (!class_exists('PCM_Schema')) {
    class PCM_Schema { public static function table($n) { return 'wp_pcm_' . $n; } }
}
class FakeWpdbAnchors {
    public $last_replace_json = null; public $deleted = false; public $queries = 0;
    public function prepare($sql, ...$args) {
        // capture the JSON payload handed to REPLACE
        foreach ($args as $a) { if (is_string($a) && $a !== '' && $a[0] === '[') { $this->last_replace_json = $a; } }
        return $sql;
    }
    public function query($sql) { $this->queries++; return 1; }
    public function delete($table, $where, $fmt) { $this->deleted = true; return 1; }
}
global $wpdb;
$wpdb = new FakeWpdbAnchors();

$many = array();
for ($i = 1; $i <= 15; $i++) { $many[] = "phrase {$i}"; }
PCM_SEO_Interlinks::set_anchors(7, 0, 10, $many);
$stored = json_decode((string) $wpdb->last_replace_json, true);
check('set_anchors caps at MAX_ANCHORS', count($stored) === PCM_SEO_Interlinks::MAX_ANCHORS, $stored);
check('…keeping the FIRST N (the user\'s ranking), not an arbitrary N',
    $stored[0] === 'phrase 1' && $stored[9] === 'phrase 10', $stored);

$wpdb = new FakeWpdbAnchors();
PCM_SEO_Interlinks::set_anchors(7, 0, 10, array('  tandvård  ', '', 'tandvård', str_repeat('x', 121), 'akut'));
$stored = json_decode((string) $wpdb->last_replace_json, true);
check('blanks, duplicates and over-length phrases are dropped; order kept',
    $stored === array('tandvård', 'akut'), $stored);

$wpdb = new FakeWpdbAnchors();
PCM_SEO_Interlinks::set_anchors(7, 0, 10, array('', '   '));
check('an all-blank list DELETES the row (clear), never stores []',
    $wpdb->deleted === true && $wpdb->last_replace_json === null, array($wpdb->deleted, $wpdb->last_replace_json));

$bad = PCM_SEO_Interlinks::set_anchors(7, 0, 0, array('x'));
check('post id 0 is refused', $bad instanceof WP_Error);

// dangling ends — SAID since 2026-08-21, not silently dropped (audit find: a
// deleted page or the row cap shrank the run with no trace). The old contract
// here asserted `=== array()`; the new one is an explicit refusal row.
$dangling = PCM_SEO_Interlinks::propose(array($rows[1]), $bodies, $hier);
check('a pair whose parent left the table becomes a REFUSAL, never an actionable proposal',
    count($dangling) >= 1
    && array_filter($dangling, static fn($p) => $p['anchor'] !== '') === array()
    && strpos($dangling[0]['reason'], 'is in the hierarchy but not in the table') !== false,
    $dangling);

// cap
$many_rows = array(); $many_bodies = array(); $many_hier = array();
$many_rows[] = array('id' => 1, 'title' => 'Pillar', 'permalink' => 'https://s.test/p', 'primaryKeyword' => 'pillar');
$many_bodies[1] = str_repeat('<p>child</p>', 400);
for ($i = 2; $i <= 400; $i++) {
    $many_rows[]      = array('id' => $i, 'title' => 'child', 'permalink' => "https://s.test/c{$i}", 'primaryKeyword' => 'child');
    $many_bodies[$i]  = '<p>pillar</p>';
    $many_hier[$i]    = 1;
}
$capped = PCM_SEO_Interlinks::propose($many_rows, $many_bodies, $many_hier);
check('proposals are capped at MAX_PROPOSALS',
    count($capped) === PCM_SEO_Interlinks::MAX_PROPOSALS, count($capped));

// ── The "not working properly" bugs (owner, 2026-08-21) — all EXECUTED,
//    all reproduced against the pre-fix engine before being fixed ───────
echo "\nMatching that mirrors how WordPress actually stores text\n";
check('multibyte case folding: a lowercase Swedish anchor matches its capitalised form (Ä/ä)',
    PCM_SEO_Interlinks::find_safe_occurrence('<p>Änglamarkens produkter är bra.</p>', 'änglamarkens produkter')
        === array('Änglamarkens produkter', 3));
check('entity form: a plain "&" anchor matches the stored "&amp;" text — and the ENTITY form is what gets wrapped',
    PCM_SEO_Interlinks::find_safe_occurrence('<p>Vi på Tak &amp; Bygg hjälper dig.</p>', 'Tak & Bygg')
        === array('Tak &amp; Bygg', 10));
check('typographic quotes: a straight-apostrophe anchor matches the page’s curly one',
    PCM_SEO_Interlinks::find_safe_occurrence("<p>Sweden\u{2019}s best massage in town.</p>", "Sweden's best massage")
        === array("Sweden\u{2019}s best massage", 3));
check('whitespace flexibility: the phrase matches across the page’s own line break (and the apply round-trip’s collapsed spaces)',
    PCM_SEO_Interlinks::find_safe_occurrence("<p>the best\nmassage in town</p>", 'best massage')
        === array("best\nmassage", 7));
check('plain ASCII behaviour unchanged',
    PCM_SEO_Interlinks::find_safe_occurrence('<p>the best massage in town</p>', 'best massage')
        === array('best massage', 7));
check('no match is still an honest refusal',
    PCM_SEO_Interlinks::find_safe_occurrence('<p>something else entirely</p>', 'tandläkare göteborg') === null);
check('the entity variant only fires when it differs (no double-scan of plain phrases)',
    PCM_SEO_Interlinks::find_safe_occurrence('<p>plain words here</p>', 'plain words') === array('plain words', 3));
check('invalid UTF-8 content falls back to the byte-wise scan instead of matching nothing',
    PCM_SEO_Interlinks::find_safe_occurrence("<p>best massage \xC3</p>", 'best massage') === array('best massage', 3));

echo "\nIdempotency sees relative links\n";
check('a RELATIVE href to the target path counts as already linked (no duplicate on re-run)',
    PCM_SEO_Interlinks::already_links_to('<p>Läs om <a href="/tandvard/">tandvård</a> här.</p>', 'https://site.se/tandvard/') === true);
check('…trailing-slash variants of the relative form too',
    PCM_SEO_Interlinks::already_links_to('<p><a href="/tandvard">x</a></p>', 'https://site.se/tandvard/') === true);
check('a DIFFERENT relative path does not count',
    PCM_SEO_Interlinks::already_links_to('<p><a href="/kontakt/">x</a></p>', 'https://site.se/tandvard/') === false);
check('absolute-href matching unchanged',
    PCM_SEO_Interlinks::already_links_to('<p><a href="http://www.site.se/tandvard/">x</a></p>', 'https://site.se/tandvard') === true);

echo "\ninsert_link carries the new matching end to end\n";
$ins = PCM_SEO_Interlinks::insert_link('<p>Vi på Tak &amp; Bygg hjälper dig.</p>', 'https://site.se/tak/', 'Tak & Bygg');
check('inserting via an entity-form match wraps the entity text and keeps the page bytes intact',
    $ins !== null && $ins['content'] === '<p>Vi på <a href="https://site.se/tak/">Tak &amp; Bygg</a> hjälper dig.</p>', $ins);
$ins2 = PCM_SEO_Interlinks::insert_link('<p>Läs om <a href="/tandvard/">tandvård</a> och tandvård igen.</p>', 'https://site.se/tandvard/', 'tandvård');
check('insert refuses when a RELATIVE link to the target already exists', $ins2 === null);

// ── Audit round 2 (2026-08-21, all executed against the pre-fix engine first) ──
echo "\nWord boundaries — Swedish compounds must never get mid-word links\n";
check('\'tak\' does NOT match inside \'intakta\' (the audit\'s "in<a>tak</a>ta" output)',
    PCM_SEO_Interlinks::find_safe_occurrence('<p>Priserna har varit intakta i flera år.</p>', 'tak') === null);
check('…but the LATER standalone \'tak\' on the same page is found',
    PCM_SEO_Interlinks::find_safe_occurrence('<p>intakta priser. Vi bygger nytt tak i sommar.</p>', 'tak') === array('tak', 34));
check('\'bygg\' does not match inside \'utbyggnad\'',
    PCM_SEO_Interlinks::find_safe_occurrence('<p>Vi planerar en utbyggnad av kontoret.</p>', 'bygg') === null);
check('a phrase ending in punctuation keeps its unguarded edge',
    PCM_SEO_Interlinks::find_safe_occurrence('<p>Läs mer om tak.</p>', 'tak.') === array('tak.', 15));
check('multibyte boundaries: \'är\' does not match inside \'lär\' or \'ärlig\'',
    PCM_SEO_Interlinks::find_safe_occurrence('<p>vi lär oss ärligt</p>', 'är') === null);

echo "\nInvisible spellings of visible text\n";
check('the 6-byte &nbsp; ENTITY between words matches a plain-space phrase',
    PCM_SEO_Interlinks::find_safe_occurrence('<p>Vi jobbar med tak&nbsp;och bygg i Uppsala.</p>', 'tak och bygg') === array('tak&nbsp;och bygg', 17));
check('a soft hyphen (U+00AD) threaded through a compound is ignored — and KEPT in the matched text',
    PCM_SEO_Interlinks::find_safe_occurrence("<p>Vi kan tak\u{00AD}läggning här.</p>", 'takläggning') === array("tak\u{00AD}läggning", 10));
check('an &shy; entity inside the word too',
    PCM_SEO_Interlinks::find_safe_occurrence('<p>Vi kan tak&shy;läggning här.</p>', 'takläggning') === array('tak&shy;läggning', 10));

echo "\nForbidden containers + anchor detection\n";
check('a phrase inside <script> text is never wrapped (JS would break); the <p> occurrence wins',
    PCM_SEO_Interlinks::find_safe_occurrence('<script>var x = "bygg";</script><p>vi kan bygg</p>', 'bygg') === array('bygg', 42));
check('a phrase inside an <h2> is skipped — headings are not interlink anchors',
    PCM_SEO_Interlinks::find_safe_occurrence('<h2>bygg mera</h2><p>vi kan bygg</p>', 'bygg') === array('bygg', 28));
$nested = PCM_SEO_Interlinks::insert_link("<p>Se <a\nhref=\"/akut/\">akut tandvård</a> för mer. God tandvård här.</p>", 'https://x.se/tandvard/', 'tandvård');
check('<a followed by a NEWLINE is still an open anchor — no nested link; the free-text occurrence is used',
    $nested !== null && strpos($nested['content'], "akut <a href=") === false && strpos($nested['content'], 'God <a href="https://x.se/tandvard/">tandvård</a> här') !== false, $nested);
check('an unsafe multibyte FIRST hit (alt attribute) no longer kills the scan — the later text hit is found',
    PCM_SEO_Interlinks::find_safe_occurrence('<img alt="Änglamark bild"><p>Här finns änglamark i butiken.</p>', 'Änglamark') === array('änglamark', 41));

echo "\nIdempotency spellings + entity-encoded needles\n";
check('UPPERCASE host counts as the same page',
    PCM_SEO_Interlinks::already_links_to('<a href="https://Kliniken.SE/tandvard/">x</a>', 'https://kliniken.se/tandvard/') === true);
check('tracking params (utm/fbclid) never make a page "different"',
    PCM_SEO_Interlinks::already_links_to('<a href="https://kliniken.se/tandvard/?utm_source=x">x</a>', 'https://kliniken.se/tandvard/') === true);
check('percent-encoded path equals its decoded form',
    PCM_SEO_Interlinks::already_links_to('<a href="https://kliniken.se/tandv%C3%A5rd/">x</a>', "https://kliniken.se/tandv\u{00E5}rd/") === true);
check('a SCHEME-RELATIVE link to another host is NOT "already linked"',
    PCM_SEO_Interlinks::already_links_to('<a href="//helt-annan-sajt.se/tandvard/">x</a>', 'https://kliniken.se/tandvard/') === false);
check('an entity-encoded needle (remote title.rendered: &#038;) matches the stored &amp; body',
    PCM_SEO_Interlinks::find_safe_occurrence('<p>Se Priser &amp; Omdömen här.</p>', 'Priser &#038; Omdömen') === array('Priser &amp; Omdömen', 6));

echo "\nCaps + visibility of the invisible\n";
$rows_cap = array(array('id' => 500, 'title' => 'Pillar', 'permalink' => 'https://s.se/p/', 'primaryKeyword' => 'zebrafisk'));
$bodies_cap = array(); $map_cap = array();
for ($i = 1; $i <= 200; $i++) {
    $rows_cap[] = array('id' => $i, 'title' => "c{$i}", 'permalink' => "https://s.se/c{$i}/", 'primaryKeyword' => '');
    $bodies_cap[$i] = '<p>inget relevant här</p>'; $map_cap[$i] = 500;
}
$rows_cap[] = array('id' => 999, 'title' => 'c999', 'permalink' => 'https://s.se/c999/', 'primaryKeyword' => '');
$bodies_cap[999] = '<p>Vi skriver om zebrafisk idag.</p>'; $map_cap[999] = 500;
$out_cap = PCM_SEO_Interlinks::propose($rows_cap, $bodies_cap, $map_cap, array('directions' => array('up')));
$act_cap = array_values(array_filter($out_cap, static fn($p) => $p['anchor'] !== ''));
check('200 refusals can no longer starve the one REAL proposal (separate caps)',
    count($act_cap) === 1 && (int) $act_cap[0]['sourceId'] === 999, array(count($out_cap), count($act_cap)));
$gone = PCM_SEO_Interlinks::propose(
    array(array('id' => 1, 'title' => 'Tandvård', 'permalink' => 'https://ex.se/tandvard/', 'primaryKeyword' => '')),
    array(1 => '<p>x</p>'),
    array(2 => 1)
);
check('a hierarchy pair whose member is MISSING from the table is SAID, not silently dropped',
    count($gone) === 1 && $gone[0]['anchor'] === '' && strpos($gone[0]['reason'], 'page #2 is in the hierarchy but not in the table') === 0, $gone);

echo "\nWiring pins (WP-dependent halves — source contracts)\n";
$eng = file_get_contents($ROOT . '/includes/modules/seo/interlinks.php');
$ctl = file_get_contents($ROOT . '/includes/modules/seo/controller.php');
check('bodies_remote surfaces read errors through the by-ref map (401 ≠ builder page)',
    strpos($eng, 'public static function bodies_remote(object $site, array $ids, array $types, ?array &$errors = null): array') !== false
    && strpos($eng, "\$errors[\$id] = sprintf('HTTP %d reading the page'") !== false);
check('the remote propose controller rewrites the generic refusal with the REAL read error',
    strpos($ctl, 'PCM_SEO_Interlinks::bodies_remote($site, $ids, $types, $read_errors)') !== false
    && strpos($ctl, "\$p['reason'] = 'could not read the source page: ' . \$read_errors[\$sid];") !== false);
check('apply_local writes SLASHED content (wp_update_post unslashes — Gutenberg attrs survive)',
    strpos($eng, "wp_update_post(wp_slash(array('ID' => \$post_id, 'post_content' => \$res['content'])), true)") !== false);

echo "\n" . ($FAIL === 0 ? "ALL GREEN" : "FAILURES") . " — {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
