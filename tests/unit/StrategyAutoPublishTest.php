<?php
/**
 * Unit Tests — Strategy generation model-selection, retry, auto-publish, and
 * manual item-status reset.
 *
 * Exercises the REAL PCM_Strategy_Service::generate_next_item() /
 * reset_item_to_pending() (and recompute_counters()/maybe_auto_publish(),
 * called via those public entry points — no reflection needed) against
 * lightweight in-process stand-ins for PCM_DB / PCM_LLM / PCM_Sites_Service /
 * PCM_Schema.
 * This suite deliberately avoids booting real WordPress (see bootstrap.php);
 * those three are heavy DB/HTTP-backed classes out of scope for a fast unit
 * test, and PCM_Sites_Service::publish_to_site() specifically needs wp_salt()
 * plus genuinely openssl-encrypted app-password data to even reach its HTTP
 * call — disproportionate to stand up here just to verify this service's own
 * orchestration logic (publish_to_site itself is unchanged and untested by
 * this file).
 *
 * The stand-ins below share class NAMES with the real, composer-classmapped
 * services, so the test class carries `@runTestsInSeparateProcesses` (see its
 * own docblock, PHPUnit only reads that metadata from the docblock directly
 * above the `class` keyword) — every test method runs in its own PHP process,
 * which is what makes this safe: without it, whichever test in the FULL suite
 * happens to autoload the real PCM_DB/PCM_Sites_Service first (in either
 * direction, in whatever order the run uses) would collide with these
 * stand-ins declared under the same names. Every `class_exists()` guard below
 * also passes `false` to disable autoloading — the default (`true`) would
 * itself trigger composer's classmap autoloader and load the REAL class the
 * moment the check runs. The class declarations are wrapped in a plain
 * top-level function (pcm_test_define_strategy_fakes) so they aren't parsed/
 * declared merely by PHPUnit collecting this file in the (non-isolated)
 * parent process — PHP only declares a class inside a function body when
 * that function actually runs, which happens in setUp() at test EXECUTION
 * time, inside the isolated child process.
 *
 * @package PowerCreatives\Tests\Unit
 */

/** Declares the fakes exactly once per (isolated) process, on first call. */
function pcm_test_define_strategy_fakes(): void
{
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/wordpress/');
    }
    // run_queue_tick() logs a swallowed background failure via error_log(). Left
    // at its default, that writes to STDERR under the CLI SAPI — which
    // @runTestsInSeparateProcesses (below) then misreports as a fatal, since
    // PHPUnit's process-isolation runner treats unexpected child-process STDERR
    // output as an error signal. Redirect to a throwaway file for this process
    // only — no production code changes, purely a test-run redirect.
    ini_set('error_log', sys_get_temp_dir() . '/pcm_test_error_log_' . getmypid() . '.log');
    if (!function_exists('sanitize_title')) {
        function sanitize_title($s)
        {
            return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim((string)$s)));
        }
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($s)
        {
            return is_string($s) ? trim($s) : $s;
        }
    }
    if (!function_exists('esc_url_raw')) {
        function esc_url_raw($s)
        {
            return $s;
        }
    }
    if (!function_exists('esc_url')) {
        function esc_url($s)
        {
            return $s;
        }
    }
    if (!function_exists('esc_html')) {
        function esc_html($s)
        {
            return htmlspecialchars((string)$s, ENT_QUOTES);
        }
    }
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id()
        {
            return 1;
        }
    }
    if (!function_exists('current_time')) {
        function current_time($type)
        {
            return PCM_Test_Cron::$now ?? date('Y-m-d H:i:s');
        }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($v)
        {
            return json_encode($v);
        }
    }
    // Options store — run_scheduled_scan() now also scans per-site schedule
    // rules (Task I2), which reads/writes the pcm_site_schedules option.
    if (!function_exists('get_option')) {
        function get_option($name, $default = false)
        {
            return $GLOBALS['pcm_test_options'][$name] ?? $default;
        }
    }
    if (!function_exists('update_option')) {
        function update_option($name, $value, $autoload = null)
        {
            $GLOBALS['pcm_test_options'][$name] = $value;
            return true;
        }
    }
    // Background-queue continuation fakes — record calls so tests can assert on
    // exactly what was (or wasn't) scheduled, and simulate wp_next_scheduled()'s
    // dedup-by-args behavior via a settable flag.
    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook, $args = array())
        {
            return PCM_Test_Cron::$alreadyScheduled;
        }
    }
    if (!function_exists('wp_schedule_single_event')) {
        function wp_schedule_single_event($timestamp, $hook, $args = array())
        {
            PCM_Test_Cron::$scheduleCalls[] = array('timestamp' => $timestamp, 'hook' => $hook, 'args' => $args);
            return true;
        }
    }
    if (!class_exists('PCM_Test_Cron', false)) {
        class PCM_Test_Cron
        {
            public static $scheduleCalls = array();
            public static $alreadyScheduled = false;
            public static $now = null; // override for current_time() fake; null = real date()
        }
    }

    // NOTE: every class_exists() below passes `false` as the second arg to disable
    // autoloading — the default (`true`) would itself trigger composer's classmap
    // autoloader and load the REAL PCM_DB/PCM_LLM/PCM_Sites_Service the moment the
    // check runs (class_exists() autoloads by default), defeating the entire guard.
    if (!class_exists('PCM_Schema', false)) {
        class PCM_Schema
        {
            public static function table($n)
            {
                return 'wp_pcm_' . $n;
            }
        }
    }

    // $wpdb only used by load_template() — returns a template with one prompt entry.
    if (!class_exists('PCM_Test_FakeWpdb', false)) {
        class PCM_Test_FakeWpdb
        {
            public $postmeta = 'wp_postmeta';
            /** @var string The template's single prompt entry — settable so a
             *  test can supply one carrying {{ post_* }} variables. */
            public static $promptEntry = 'SYS PROMPT';
            /** @var array|null Full entries list, overriding $promptEntry — lets a
             *  test include NON-prompt categories (only 'prompt' entries may be
             *  read for variables or rider suppression). */
            public static $entries = null;
            public function prepare($q, ...$a)
            {
                return $q;
            }
            public function get_row($q)
            {
                $entries = is_array(self::$entries)
                    ? self::$entries
                    : array(array('category' => 'prompt', 'value' => self::$promptEntry));
                return (object)array(
                    'name'     => 'Tmpl',
                    'formData' => json_encode(array('entries' => $entries)),
                );
            }
        }
    }
    $GLOBALS['wpdb'] = new PCM_Test_FakeWpdb();

    if (!class_exists('PCM_LLM', false)) {
        class PCM_LLM
        {
            public static $lastOptions = null;
            /** @var string|null Keyword substring to fail generation on. */
            public static $throwOn = null;
            /** @var int Total invoke_json() calls this test -- lets a test assert
             *  "generated exactly once" (Step 8's consolidated-batch behavior). */
            public static $callCount = 0;
            /** @var string|null The last call's user-role message content, for
             *  asserting on prompt content (Step 8: "all keywords in prompt"). */
            public static $lastUserMessage = null;
            /** @var array|null Overrides the generated article (emoji-strip wiring tests). */
            public static $nextResult = null;
            /** @var string|null The last call's system message — the template prompt,
             *  for asserting {{ post_* }} substitution reached the model. */
            public static $lastSystemMessage = null;
            public static function invoke_json($messages, $schema, $options)
            {
                self::$lastOptions = $options;
                self::$callCount++;
                $user = '';
                foreach ($messages as $m) {
                    if (($m['role'] ?? '') === 'user') {
                        $user = $m['content'];
                    }
                    if (($m['role'] ?? '') === 'system') {
                        self::$lastSystemMessage = $m['content'];
                    }
                }
                self::$lastUserMessage = $user;
                if (self::$throwOn && strpos($user, self::$throwOn) !== false) {
                    throw new \RuntimeException('LLM boom');
                }
                // Overridable so a test can hand back emoji-laden output and
                // assert on what actually gets PERSISTED; null = the default
                // clean article every other test in this suite relies on.
                if (is_array(self::$nextResult)) {
                    return self::$nextResult;
                }
                return array('title' => 'Generated Title', 'content' => '<p>body</p>', 'metaTitle' => 'MT', 'metaDescription' => 'MD');
            }
        }
    }

    if (!class_exists('PCM_DB', false)) {
        class PCM_DB
        {
            public static $items = array();      // id => object
            public static $strategy = array();   // last update() payload captured
            public static $strategyRow = null;    // (object) or null — what get_strategy() returns
            public static $articleSeq = 100;
            public static $articles = array();
            public static $site = null;           // (object) or null, per test
            public static $createStrategyId = 7;  // fake id returned by create_strategy()
            /** @var array|null Last create_strategy() payload (Task I2 tests assert on it). */
            public static $createStrategyData = null;

            public static function get_strategy($id, $uid)
            {
                return self::$strategyRow;
            }
            public static function create_strategy($data)
            {
                self::$createStrategyData = $data;
                return self::$createStrategyId;
            }
            /** Mirrors the real method's actual side effect: one pending
             *  strategy_items row per keyword, in position order. The optional
             *  4th $meta param (Task F3, keyed by keyword string) writes
             *  volume/difficulty onto the row exactly as the real method does. */
            public static function create_strategy_items($strategy_id, $user_id, $keywords, $meta = array())
            {
                $id = 1;
                foreach ($keywords as $position => $keyword) {
                    $row = (object)array(
                        'id' => $id, 'strategyId' => $strategy_id, 'keyword' => $keyword,
                        'status' => 'pending', 'position' => $position, 'articleId' => null, 'errorMessage' => '',
                        'volume' => null, 'difficulty' => null,
                    );
                    $km = is_array($meta) ? ($meta[$keyword] ?? null) : null;
                    if (is_array($km)) {
                        if (isset($km['volume']) && $km['volume'] !== null) {
                            $row->volume = (int)$km['volume'];
                        }
                        if (isset($km['difficulty']) && $km['difficulty'] !== null) {
                            $row->difficulty = (int)$km['difficulty'];
                        }
                    }
                    self::$items[$id] = $row;
                    $id++;
                }
                return count($keywords);
            }
            public static function get_next_pending_item($sid)
            {
                foreach (self::$items as $it) {
                    if ($it->status === 'pending') {
                        return $it;
                    }
                }
                return null;
            }
            /** @var object[] fake return value for get_due_scheduled_strategies() */
            public static $dueScheduledStrategies = array();
            public static function get_due_scheduled_strategies($now)
            {
                return self::$dueScheduledStrategies;
            }
            public static function get_strategy_items($sid)
            {
                return array_values(self::$items);
            }
            public static function update_strategy_item($id, $data)
            {
                foreach ($data as $k => $v) {
                    self::$items[$id]->$k = $v;
                }
                return true;
            }
            /** Faithful CAS: writes only while the item is still 'generating'. */
            public static function complete_strategy_item_if_generating($id, $data)
            {
                if (self::$forceCompleteFail
                    || !isset(self::$items[$id])
                    || (string) (self::$items[$id]->status ?? '') !== 'generating'
                ) {
                    return false;
                }
                foreach ($data as $k => $v) {
                    self::$items[$id]->$k = $v;
                }
                return true;
            }
            public static function delete_strategy_item($id)
            {
                if (!isset(self::$items[$id])) {
                    return false;
                }
                unset(self::$items[$id]);
                return true;
            }
            public static function update_strategy($id, $uid, $data)
            {
                self::$strategy = array_merge(self::$strategy, $data);
                return true;
            }
            public static function get_brand_by_id($id, $uid)
            {
                return null;
            }
            public static function create_article($data)
            {
                $id = self::$articleSeq++;
                $data['id'] = $id; // mirrors the real articles table's own auto-increment id column
                self::$articles[$id] = $data;
                return $id;
            }
            /** Forces get_article() to return null — covers maybe_auto_publish()'s
             *  defensive `!$article` guard (a lookup miss on the just-inserted id
             *  should be unreachable in practice, but the guard exists precisely
             *  so it degrades to a reported failure instead of a TypeError). */
            public static $forceNullArticle = false;
            public static function get_article($id, $uid)
            {
                if (self::$forceNullArticle) {
                    return null;
                }
                return (object)(self::$articles[$id] ?? array());
            }
            public static function update_article($id, $uid, $data)
            {
                if (!isset(self::$articles[$id])) {
                    return false;
                }
                self::$articles[$id] = array_merge(self::$articles[$id], $data);
                return true;
            }
            public static function get_site($id, $uid)
            {
                return self::$site;
            }
            public static function get_strategy_item_by_set_id($set_id, $uid)
            {
                foreach (self::$items as $it) {
                    if ((int)($it->setId ?? 0) === (int)$set_id) {
                        return $it;
                    }
                }
                return null;
            }
            /** Forces the atomic claim to lose -- simulates a concurrent caller
             *  already having won the race. */
            public static $forceClaimFail = false;
            /** Forces the completion CAS to lose -- simulates the stale-generating
             *  reclaim having handed this item to another generator mid-run. */
            public static $forceCompleteFail = false;
            public static function advance_strategy_item_from_in_review($id)
            {
                if (self::$forceClaimFail) {
                    return false;
                }
                if (!isset(self::$items[$id]) || self::$items[$id]->status !== 'in_review') {
                    return false;
                }
                self::$items[$id]->status = 'completed';
                return true;
            }
            /** When > 0, the next N claim_strategy_item() calls lose the race:
             *  a phantom CONCURRENT generator wins instead (the item flips to
             *  'generating' -- the winner's effect) and this call returns false,
             *  exactly what the real compare-and-set does for the loser. */
            public static $loseGenerationClaims = 0;
            public static function claim_strategy_item($id)
            {
                if (!isset(self::$items[$id]) || self::$items[$id]->status !== 'pending') {
                    return false;
                }
                self::$items[$id]->status = 'generating';
                if (self::$loseGenerationClaims > 0) {
                    self::$loseGenerationClaims--;
                    return false; // the phantom winner took it, not us
                }
                return true;
            }
            /** Mirrors the real staleness semantics: 'generating' items with an
             *  updatedAt older than $minutes (vs current_time()) go back to
             *  'pending'. Items without an updatedAt are treated as fresh. */
            public static function reclaim_stale_generating($sid, $minutes = 10)
            {
                $count = 0;
                $cutoff = strtotime(current_time('mysql')) - ($minutes * 60);
                foreach (self::$items as $it) {
                    if ($it->status === 'generating'
                        && !empty($it->updatedAt)
                        && strtotime($it->updatedAt) < $cutoff
                    ) {
                        $it->status = 'pending';
                        $count++;
                    }
                }
                return $count;
            }
        }
    }

    // Fake PCM_Approvals_Service — the strategy service's class_exists() guard
    // (service.php: `if (!class_exists('PCM_Approvals_Service')) { require_once
    // ...; }`) sees this already declared and skips loading the real, WP-dependent
    // one. Only the surface strategy/service.php actually calls is faked.
    if (!class_exists('PCM_Approvals_Service', false)) {
        class PCM_Approvals_Service
        {
            /** @var array<int,array{userId:int,data:array}> every create_set() call, for assertions */
            public static $createSetCalls = array();
            public static $nextSetId = 501;
            public static function create_set($user_id, $data)
            {
                self::$createSetCalls[] = array('userId' => $user_id, 'data' => $data);
                return self::$nextSetId;
            }
            /** @var array<int,array{id:int,userId:int,status:string}> lane moves (real create_set hardcodes 'draft'; the strategy side moves the set to its starting lane right after). */
            public static $updateStatusCalls = array();
            public static function update_status($id, $user_id, $next_status)
            {
                self::$updateStatusCalls[] = array('id' => (int)$id, 'userId' => (int)$user_id, 'status' => (string)$next_status);
                return true;
            }
        }
    }

    // Fake PCM_Sites_Service — the strategy service's class_exists() guard
    // (service.php: `if (!class_exists('PCM_Sites_Service')) { require_once ...; }`)
    // sees this already declared and skips loading the real, WP-dependent one.
    if (!class_exists('PCM_Sites_Service', false)) {
        class PCM_Sites_Service
        {
            /** @var array<int,array{site:object,article:object,user_id:int}> */
            public static $calls = array();
            public static $shouldThrow = false;
            public static function publish_to_site($site, $article, $user_id)
            {
                self::$calls[] = array('site' => $site, 'article' => $article, 'user_id' => $user_id);
                if (self::$shouldThrow) {
                    throw new \RuntimeException('remote publish boom');
                }
                return array('success' => true, 'postId' => 555, 'postUrl' => 'https://example.com/p/555', 'siteId' => (int)$site->id);
            }
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyAutoPublishTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_strategy_fakes();
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';

        PCM_DB::$items = array();
        PCM_DB::$strategy = array();
        PCM_DB::$strategyRow = null;
        PCM_DB::$site = null;
        PCM_DB::$articles = array();
        PCM_DB::$articleSeq = 100;
        PCM_DB::$forceNullArticle = false;
        PCM_LLM::$lastOptions = null;
        PCM_LLM::$throwOn = null;
        PCM_LLM::$callCount = 0;
        PCM_LLM::$lastUserMessage = null;
        PCM_Sites_Service::$calls = array();
        PCM_Sites_Service::$shouldThrow = false;
        PCM_Test_Cron::$scheduleCalls = array();
        PCM_Test_Cron::$alreadyScheduled = false;
        PCM_Test_Cron::$now = null;
        PCM_DB::$createStrategyId = 7;
        PCM_DB::$dueScheduledStrategies = array();
        PCM_Approvals_Service::$createSetCalls = array();
        PCM_Approvals_Service::$nextSetId = 501;
        PCM_DB::$forceClaimFail = false;
        PCM_DB::$forceCompleteFail = false;
        PCM_DB::$loseGenerationClaims = 0;
        PCM_LLM::$nextResult = null;
        PCM_LLM::$lastSystemMessage = null;
        PCM_Test_FakeWpdb::$promptEntry = 'SYS PROMPT';
        PCM_Test_FakeWpdb::$entries = null;
    }

    // ── {{ post_* }} template variables: the CALL SITE ───────────────────

    public function test_only_prompt_entries_are_read_for_variables_and_suppression(): void
    {
        // A variable sitting in a NON-prompt entry (a title/outline/reference
        // field) must neither be substituted nor suppress the rider — those
        // entries never become the system prompt.
        PCM_Test_FakeWpdb::$entries = array(
            array('category' => 'title', 'value' => 'Ignore me: {{ post_content }}'),
            array('category' => 'prompt', 'value' => 'SYS PROMPT'),
        );
        $this->seedItems(array(array(
            'id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0,
            'config' => json_encode(array('social' => true, 'sourceTitle' => 'T', 'sourceText' => 'C', 'sourceLink' => 'https://x/1')),
        )));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        // The rider must still fire — the prompt entry has no variables.
        $this->assertStringContainsString('Write an article about this social media post', (string)PCM_LLM::$lastUserMessage);
        $this->assertStringNotContainsString('Ignore me', (string)PCM_LLM::$lastSystemMessage);
    }

    public function test_template_source_variables_are_substituted_into_the_system_prompt(): void
    {
        PCM_Test_FakeWpdb::$promptEntry =
            "Rewrite this post.\nTITLE: {{ post_title }}\nBODY: {{post_content}}\nSOURCE: {{ post_link }}";
        $this->seedItems(array(array(
            'id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0,
            'config' => json_encode(array(
                'social'      => true,
                'sourceTitle' => 'Spain win the final',
                'sourceText'  => 'What a match tonight.',
                'sourceLink'  => 'https://insta/p/abc',
            )),
        )));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $sys = (string)PCM_LLM::$lastSystemMessage;
        $this->assertStringContainsString('TITLE: Spain win the final', $sys);
        $this->assertStringContainsString('BODY: What a match tonight.', $sys);
        $this->assertStringContainsString('SOURCE: https://insta/p/abc', $sys);
        $this->assertStringNotContainsString('{{', $sys, 'no known token may reach the model raw');
    }

    public function test_a_template_using_the_variables_suppresses_the_hardcoded_rider(): void
    {
        // The template now OWNS how the post is used; appending the built-in
        // sentence as well would duplicate and can contradict it.
        PCM_Test_FakeWpdb::$promptEntry = 'Rewrite: {{ post_content }}';
        $this->seedItems(array(array(
            'id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0,
            'config' => json_encode(array('social' => true, 'sourceTitle' => 'T', 'sourceText' => 'C', 'sourceLink' => 'https://x/1')),
        )));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertStringNotContainsString('Write an article about this social media post', (string)PCM_LLM::$lastUserMessage);
    }

    public function test_a_variable_free_template_still_gets_the_rider(): void
    {
        // Keyed off the template TEXT, not the source mode — otherwise every
        // existing social strategy would silently lose its post context.
        PCM_Test_FakeWpdb::$promptEntry = 'SYS PROMPT';
        $this->seedItems(array(array(
            'id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0,
            'config' => json_encode(array('social' => true, 'sourceTitle' => 'T', 'sourceText' => 'C', 'sourceLink' => 'https://x/1')),
        )));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertStringContainsString('Write an article about this social media post', (string)PCM_LLM::$lastUserMessage);
    }

    public function test_an_RSS_item_populates_post_content_and_still_suppresses_the_rider(): void
    {
        // The owner's headline case: "RSS reposting with variables". An RSS item
        // is NOT social — it must still resolve {{ post_content }} (from the feed
        // entry's description, captured by fetch_rss_feed_items()).
        PCM_Test_FakeWpdb::$promptEntry = 'Repost this feed item: {{ post_content }} ({{ post_link }})';
        $this->seedItems(array(array(
            'id' => 1, 'keyword' => 'Fed cuts rates', 'status' => 'pending', 'position' => 0,
            'config' => json_encode(array(
                'sourceTitle' => 'Fed cuts rates',
                'sourceText'  => 'The central bank lowered rates by 25bps.',
                'sourceLink'  => 'https://news.example/a',
            )), // note: no 'social' key — this is a plain RSS item
        )));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $sys = (string)PCM_LLM::$lastSystemMessage;
        $this->assertStringContainsString('The central bank lowered rates by 25bps.', $sys);
        $this->assertStringContainsString('https://news.example/a', $sys);
        $this->assertStringNotContainsString('This article responds to a new industry item', (string)PCM_LLM::$lastUserMessage);
    }

    public function test_an_empty_referenced_variable_keeps_the_rider_rather_than_losing_the_source(): void
    {
        // The trap: template references only {{ post_content }}, but this feed
        // entry shipped no description. Suppressing on the TOKEN alone would
        // leave the model with no source item AND no attribution link — strictly
        // worse than before the feature existed.
        PCM_Test_FakeWpdb::$promptEntry = 'Repost: {{ post_content }}';
        $this->seedItems(array(array(
            'id' => 1, 'keyword' => 'Fed cuts rates', 'status' => 'pending', 'position' => 0,
            'config' => json_encode(array(
                'sourceTitle' => 'Fed cuts rates',
                'sourceText'  => '',                       // feed had no description
                'sourceLink'  => 'https://news.example/a',
            )),
        )));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertStringContainsString('This article responds to a new industry item', (string)PCM_LLM::$lastUserMessage);
    }

    public function test_variables_resolve_to_empty_for_a_plain_keyword_item(): void
    {
        // A keyword strategy has no source post — the tokens must vanish, not
        // reach the model raw.
        PCM_Test_FakeWpdb::$promptEntry = 'Body:{{ post_content }}|Link:{{ post_link }}';
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertStringContainsString('Body:|Link:', (string)PCM_LLM::$lastSystemMessage);
    }

    // ── REQ 2/3 wiring: the CALL SITES, not just the pure helpers ────────
    //
    // A verifier proved these requirements could each be deleted outright with
    // the whole suite staying green — every test covered strip_emoji() and
    // social_source_image() in isolation, none covered generate_next_item()
    // actually calling them. These four close that.

    /** A social item whose config carries the post's captured image + text. */
    private function seedSocialItem(array $extraCfg = array()): void
    {
        $cfg = array_merge(array('social' => true, 'sourceLink' => 'https://insta/p/x', 'sourceText' => 'caption'), $extraCfg);
        $this->seedItems(array(array(
            'id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0,
            'config' => json_encode($cfg),
        )));
    }

    private function emojiArticle(): array
    {
        return array(
            'title'           => 'Spain win 🇪🇸 ⭐',
            'content'         => '<p>What a match 😱 tonight.</p>',
            'metaTitle'       => 'Spain win 🎉',
            'metaDescription' => 'A recap 🔥 of the final.',
        );
    }

    public function test_generate_strips_emoji_from_every_persisted_article_field_for_a_social_item(): void
    {
        $this->seedSocialItem();
        PCM_LLM::$nextResult = $this->emojiArticle();
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $article = end(PCM_DB::$articles);
        $this->assertSame('Spain win', $article['title']);
        $this->assertSame('<p>What a match tonight.</p>', $article['content']);
        // metaTitle/metaDescription reach _yoast_wpseo_* on the client's site —
        // an unstripped one puts the post's emojis in the SERP snippet.
        $this->assertSame('Spain win', $article['metaTitle']);
        $this->assertSame('A recap of the final.', $article['metaDescription']);
    }

    public function test_generate_leaves_emoji_alone_for_a_NON_social_item(): void
    {
        // The strip is scoped to social items — a keyword strategy's article
        // keeps whatever the model wrote.
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        PCM_LLM::$nextResult = $this->emojiArticle();
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $article = end(PCM_DB::$articles);
        $this->assertStringContainsString('🇪🇸', $article['title']);
        $this->assertStringContainsString('😱', $article['content']);
    }

    public function test_consolidated_batch_also_strips_emoji_when_the_batch_is_social(): void
    {
        // The consolidated path returns before any $item_cfg is read, so the
        // no-emoji rule silently did not apply to a shared article.
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0, 'config' => json_encode(array('social' => true))),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1, 'config' => json_encode(array('social' => true))),
        ));
        PCM_LLM::$nextResult = $this->emojiArticle();
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('structure' => 'consolidated')),
            'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $article = end(PCM_DB::$articles);
        $this->assertSame('Spain win', $article['title']);
        $this->assertSame('<p>What a match tonight.</p>', $article['content']);
        $this->assertSame('A recap of the final.', $article['metaDescription']);
    }

    public function test_generate_reuses_the_social_post_image_as_the_featured_image(): void
    {
        $this->seedSocialItem(array('sourceImage' => 'https://cdn.example/post.jpg'));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('featuredImages' => true)),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $article = end(PCM_DB::$articles);
        $this->assertSame('https://cdn.example/post.jpg', $article['featuredImage']);
    }

    public function test_the_featured_image_opt_out_also_blocks_the_social_post_image(): void
    {
        // The opt-in gate lives inside maybe_generate_featured_image(); putting
        // the social image on the left of `??` short-circuited past it, so a
        // strategy with featured images switched OFF still published one.
        $this->seedSocialItem(array('sourceImage' => 'https://cdn.example/post.jpg'));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('featuredImages' => false)),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $article = end(PCM_DB::$articles);
        $this->assertNull($article['featuredImage'], 'an opted-out strategy must not get a featured image from any source');
    }

    /** @param array<int,array<string,mixed>> $items */
    private function seedItems(array $items): void
    {
        foreach ($items as $it) {
            $o = (object)$it;
            PCM_DB::$items[$o->id] = $o;
        }
    }

    // ── Model selection ─────────────────────────────────────────────────

    public function test_model_defaults_to_gemini_when_strategy_has_no_config(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame('gemini-2.5-flash', PCM_LLM::$lastOptions['model'] ?? null);
        $this->assertArrayNotHasKey('provider', PCM_LLM::$lastOptions);
        $this->assertSame('completed', PCM_DB::$items[1]->status);
        $this->assertSame(1, PCM_DB::$strategy['completedItems'] ?? null);
        $this->assertSame('completed', PCM_DB::$strategy['status'] ?? null);
    }

    public function test_model_and_provider_are_read_from_strategy_config(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => json_encode(array('model' => 'gpt-4o', 'provider' => 'openai')), 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame('gpt-4o', PCM_LLM::$lastOptions['model'] ?? null);
        $this->assertSame('openai', PCM_LLM::$lastOptions['provider'] ?? null);
    }

    // ── Retry ────────────────────────────────────────────────────────────

    public function test_retry_by_item_id_regenerates_only_the_targeted_item(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'error', 'errorMessage' => 'boom', 'position' => 0),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1),
        ));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 1);

        PCM_Strategy_Service::generate_next_item($strategy, 1, 1);

        $this->assertSame('completed', PCM_DB::$items[1]->status);
        $this->assertSame('', PCM_DB::$items[1]->errorMessage);
        $this->assertSame('pending', PCM_DB::$items[2]->status, 'the next-pending item must stay untouched by a targeted retry');
        $this->assertSame(1, PCM_DB::$strategy['completedItems'] ?? null);
        $this->assertSame(0, PCM_DB::$strategy['failedItems'] ?? null, 'retrying clears the prior failure from the recomputed counters');
    }

    // ── Failure + counter recompute ─────────────────────────────────────

    public function test_generation_failure_marks_item_error_and_recomputes_failed_counter(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'good kw', 'status' => 'completed', 'position' => 0),
            array('id' => 2, 'keyword' => 'BADKW', 'status' => 'pending', 'position' => 1),
        ));
        PCM_LLM::$throwOn = 'BADKW';
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 2, 'completedItems' => 1, 'failedItems' => 0);

        $this->expectException(\Throwable::class);
        try {
            PCM_Strategy_Service::generate_next_item($strategy, 1);
        } finally {
            $this->assertSame('error', PCM_DB::$items[2]->status);
            $this->assertSame(1, PCM_DB::$strategy['completedItems'] ?? null);
            $this->assertSame(1, PCM_DB::$strategy['failedItems'] ?? null);
        }
    }

    public function test_no_pending_and_all_completed_returns_null_and_recomputes_status_completed(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw', 'status' => 'completed', 'position' => 0)));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 1, 'failedItems' => 0);

        $res = PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertNull($res);
        $this->assertSame('completed', PCM_DB::$strategy['status'] ?? null);
    }

    public function test_no_pending_but_a_failed_item_remains_does_not_falsely_report_completed(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'position' => 0),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'error', 'errorMessage' => 'boom', 'position' => 1),
        ));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 2, 'completedItems' => 1, 'failedItems' => 1);

        $res = PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertNull($res);
        $this->assertSame('in_progress', PCM_DB::$strategy['status'] ?? null, 'a remaining failure must not be reported as strategy status=completed');
        $this->assertSame(1, PCM_DB::$strategy['failedItems'] ?? null);
    }

    public function test_generation_that_lost_its_claim_to_a_reclaim_does_not_complete_or_publish(): void
    {
        // The stale-generating reclaim (per-strategy, and the global sweep in
        // reclaim_wedged_items()) hands a long-running item to a new generator
        // WITHOUT cancelling the original process. When that original finally
        // finishes it must not complete an item it no longer owns — otherwise
        // the item is double-counted and, in publish mode, a SECOND post is
        // published to the client's live site. Mirrors the same guarantee
        // test_advance_item_on_approval_does_not_publish_when_the_atomic_claim_loses_a_race
        // already pins for the approval path.
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        PCM_DB::$site = (object)array('id' => 9, 'name' => 'My Site', 'url' => 'https://mysite.example');
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'publishingMode' => 'publish', 'config' => json_encode(array('siteId' => 9)), 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);
        PCM_DB::$forceCompleteFail = true;

        $res = PCM_Strategy_Service::generate_next_item($strategy, 42);

        $this->assertCount(0, PCM_Sites_Service::$calls, 'a run that lost its claim must never publish');
        $this->assertNotSame('completed', PCM_DB::$items[1]->status ?? null, 'the losing run must not complete the item');
        $this->assertIsArray($res, 'the caller still gets a response rather than an exception');
    }

    public function test_in_review_write_is_also_claim_guarded_when_a_reclaim_wins(): void
    {
        // The approval branch needs the same guard as the completed branch —
        // otherwise a losing run parks the item in 'in_review' against the new
        // owner's set, and recompute_counters then counts it as neither
        // completed nor failed while the winner's own write is discarded.
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array(
            'id' => 7, 'name' => 'My Strategy', 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('approvalMode' => 'internal')),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );
        PCM_DB::$forceCompleteFail = true;

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertNotSame('in_review', PCM_DB::$items[1]->status ?? null, 'a run that lost its claim must not park the item in review');
    }

    public function test_consolidated_batch_that_lost_its_claim_does_not_publish(): void
    {
        // The consolidated path returns before the per-strategy reclaim, so it
        // was never reclaimable until the global sweep existed — which makes
        // its terminal writes newly concurrent, and so its publish newly
        // duplicable. Its shared article must not be published by a run that
        // lost the items.
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1),
        ));
        PCM_DB::$site = (object)array('id' => 9, 'name' => 'My Site', 'url' => 'https://mysite.example');
        $strategy = (object)array(
            'id' => 7, 'name' => 'My Strategy', 'templateId' => 3, 'brandId' => null,
            'publishingMode' => 'publish',
            'config' => json_encode(array('structure' => 'consolidated', 'siteId' => 9)),
            'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0,
        );
        PCM_DB::$forceCompleteFail = true;

        PCM_Strategy_Service::generate_next_item($strategy, 42);

        $this->assertCount(0, PCM_Sites_Service::$calls, 'a consolidated batch that lost its claim must never publish');
        $this->assertNotSame('completed', PCM_DB::$items[1]->status ?? null);
        $this->assertNotSame('completed', PCM_DB::$items[2]->status ?? null);
    }

    // ── Auto-publish ─────────────────────────────────────────────────────

    public function test_auto_publish_invokes_publish_to_site_with_the_resolved_site_article_and_owner(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        PCM_DB::$site = (object)array('id' => 9, 'name' => 'My Site', 'url' => 'https://mysite.example');
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'publishingMode' => 'publish', 'config' => json_encode(array('siteId' => 9)), 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        $res = PCM_Strategy_Service::generate_next_item($strategy, 42);

        $this->assertCount(1, PCM_Sites_Service::$calls);
        $this->assertSame(9, PCM_Sites_Service::$calls[0]['site']->id ?? null);
        $this->assertArrayHasKey('article', PCM_Sites_Service::$calls[0]);
        $this->assertSame(42, PCM_Sites_Service::$calls[0]['user_id'] ?? null, 'must publish as the STRATEGY OWNER, never a request-supplied id');
        $this->assertTrue($res['publish']['success'] ?? null);
        $this->assertSame('completed', PCM_DB::$items[1]->status, 'publish succeeding must not change item-status semantics');
    }

    public function test_schedule_mode_publishes_on_generation(): void
    {
        // Confirmed product gap fix: 'schedule' strategies generate articles via
        // cron on their due dates (run_scheduled_scan()) but, before this, never
        // published them -- maybe_auto_publish()'s gate only allowed 'publish'.
        // Schedule mode must now reach publish_to_site() exactly like 'publish'
        // mode does, the moment its due item generates.
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        PCM_DB::$site = (object)array('id' => 5, 'name' => 'My Site', 'url' => 'https://example.com');
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'publishingMode' => 'schedule', 'config' => json_encode(array('siteId' => 5)), 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(1, PCM_Sites_Service::$calls);
        $this->assertSame('completed', PCM_DB::$items[1]->status);
    }

    public function test_auto_publish_failure_is_isolated_from_generation_state(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        PCM_DB::$site = (object)array('id' => 9, 'name' => 'My Site', 'url' => 'https://mysite.example');
        PCM_Sites_Service::$shouldThrow = true;
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'publishingMode' => 'publish', 'config' => json_encode(array('siteId' => 9)), 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        $res = PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertNotNull($res, 'a publish failure must not propagate as a thrown exception — the draft already saved');
        $this->assertSame('completed', PCM_DB::$items[1]->status, 'item must NOT flip to error on a publish failure');
        $this->assertSame('', PCM_DB::$items[1]->errorMessage, 'errorMessage is reserved for GENERATION failures, not publish failures');
        $this->assertFalse($res['publish']['success'] ?? null);
        $this->assertStringContainsString('remote publish boom', $res['publish']['message'] ?? '');
        $this->assertSame(1, PCM_DB::$strategy['completedItems'] ?? null, 'generation succeeded regardless of the publish outcome');
    }

    public function test_draft_mode_never_calls_publish_to_site_even_with_a_site_configured(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        PCM_DB::$site = (object)array('id' => 9, 'name' => 'My Site', 'url' => 'https://mysite.example');
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'publishingMode' => 'draft', 'config' => json_encode(array('siteId' => 9)), 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        $res = PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(0, PCM_Sites_Service::$calls);
        $this->assertArrayNotHasKey('publish', $res);
    }

    public function test_publish_mode_with_no_site_configured_is_a_silent_noop(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'publishingMode' => 'publish', 'config' => json_encode(array('model' => 'x')), 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        $res = PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(0, PCM_Sites_Service::$calls);
        $this->assertArrayNotHasKey('publish', $res);
    }

    public function test_site_no_longer_connected_reports_failure_without_crashing_or_touching_item_state(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        PCM_DB::$site = null; // ownership-scoped lookup finds nothing (deleted/disconnected/not owned)
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'publishingMode' => 'publish', 'config' => json_encode(array('siteId' => 9)), 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        $res = PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(0, PCM_Sites_Service::$calls);
        $this->assertFalse($res['publish']['success'] ?? null);
        $this->assertSame('completed', PCM_DB::$items[1]->status);
    }

    public function test_null_article_reports_failure_instead_of_crashing_the_outer_catch(): void
    {
        // A lookup miss on the just-inserted article id should be unreachable in
        // practice, but maybe_auto_publish()'s `?object $article` + null-check
        // exists precisely so this degrades to a reported failure — NOT a
        // TypeError escaping into generate_next_item()'s outer catch, which would
        // wrongly flip a successfully-generated item to 'error'.
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        PCM_DB::$site = (object)array('id' => 9, 'name' => 'My Site', 'url' => 'https://mysite.example');
        PCM_DB::$forceNullArticle = true;
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'publishingMode' => 'publish', 'config' => json_encode(array('siteId' => 9)), 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        $res = PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(0, PCM_Sites_Service::$calls, 'publish_to_site must never be called with a null article');
        $this->assertFalse($res['publish']['success'] ?? null);
        $this->assertSame('Article not found for publish.', $res['publish']['message'] ?? null);
        $this->assertSame('completed', PCM_DB::$items[1]->status, 'the null-article guard must not flip the item to error');
        $this->assertSame('', PCM_DB::$items[1]->errorMessage);
    }

    // ── Manual reset-to-pending (Step 11) ────────────────────────────────

    public function test_reset_completed_item_clears_article_link_and_recomputes_counters(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'articleId' => 100, 'position' => 0),
        ));

        $items = PCM_Strategy_Service::reset_item_to_pending(7, 1, 1, 1);

        $this->assertSame('pending', $items[0]->status);
        $this->assertNull($items[0]->articleId, 'the article link must be detached, not left pointing at a stale article');
        $this->assertSame('', $items[0]->errorMessage);
        $this->assertSame(0, PCM_DB::$strategy['completedItems'] ?? null, 'recompute must reflect the item leaving completed');
        $this->assertSame('pending', PCM_DB::$strategy['status'] ?? null);
    }

    public function test_reset_error_item_clears_error_message(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'error', 'errorMessage' => 'boom', 'articleId' => null, 'position' => 0),
        ));

        $items = PCM_Strategy_Service::reset_item_to_pending(7, 1, 1, 1);

        $this->assertSame('pending', $items[0]->status);
        $this->assertSame('', $items[0]->errorMessage);
    }

    public function test_reset_rejects_a_pending_or_generating_item(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Only a 'completed' or 'error' item can be reset.");
        PCM_Strategy_Service::reset_item_to_pending(7, 1, 1, 1);
    }

    public function test_reset_rejects_an_item_id_not_in_this_strategy(): void
    {
        // Simulates a foreign itemId (another user's item, or a typo) — the
        // ownership boundary this method enforces since PCM_DB::update_strategy_item()
        // itself is not scoped by strategy or user.
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'position' => 0),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Item not found in this strategy.');
        PCM_Strategy_Service::reset_item_to_pending(7, 999, 1, 1);
    }

    // ── Background queue continuation (Step 1) ───────────────────────────

    public function test_next_pending_flow_schedules_continuation_when_items_remain(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1),
        ));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0);

        // item_id omitted (null) — the "next pending" flow (Generate / Generate All).
        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(1, PCM_Test_Cron::$scheduleCalls, 'item 2 is still pending — a continuation must be scheduled');
        $this->assertSame('pcm_strategy_process_queue', PCM_Test_Cron::$scheduleCalls[0]['hook']);
        $this->assertSame(array(7, 1), PCM_Test_Cron::$scheduleCalls[0]['args']);
    }

    public function test_no_continuation_scheduled_when_nothing_pending_remains(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
        ));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(0, PCM_Test_Cron::$scheduleCalls, 'no pending items remain — nothing to continue');
    }

    public function test_targeted_retry_does_not_schedule_continuation_even_if_other_items_are_pending(): void
    {
        // A targeted retry (explicit itemId) must stay scoped to that one item —
        // it must not surprise the user by silently kicking off unrelated pending
        // items in the background just because they clicked Retry on one row.
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'error', 'errorMessage' => 'boom', 'position' => 0),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1),
        ));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 1);

        PCM_Strategy_Service::generate_next_item($strategy, 1, 1); // explicit itemId=1

        $this->assertCount(0, PCM_Test_Cron::$scheduleCalls);
    }

    public function test_continuation_is_deduped_when_already_scheduled(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1),
        ));
        PCM_Test_Cron::$alreadyScheduled = true;
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(0, PCM_Test_Cron::$scheduleCalls, 'wp_next_scheduled() already true — must not double-schedule');
    }

    public function test_a_failed_generation_still_schedules_continuation_skip_and_continue(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'BADKW', 'status' => 'pending', 'position' => 0),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1),
        ));
        PCM_LLM::$throwOn = 'BADKW';
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0);

        try {
            PCM_Strategy_Service::generate_next_item($strategy, 1);
        } catch (\Throwable $e) {
            // expected — generate_next_item re-throws after recording the failure
        }

        $this->assertCount(1, PCM_Test_Cron::$scheduleCalls, 'a failed item must not halt the queue (Decision 4: skip-and-continue)');
    }

    public function test_run_queue_tick_generates_the_next_item_and_advances_the_queue(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1),
        ));
        PCM_DB::$strategyRow = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::run_queue_tick(7, 1);

        $this->assertSame('completed', PCM_DB::$items[1]->status, 'the tick must have generated item 1');
        $this->assertSame('pending', PCM_DB::$items[2]->status, 'item 2 untouched by this single tick');
        $this->assertCount(1, PCM_Test_Cron::$scheduleCalls, 'item 2 still pending — the tick re-arms itself');
    }

    public function test_run_queue_tick_swallows_a_generation_failure_without_throwing(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'BADKW', 'status' => 'pending', 'position' => 0),
        ));
        PCM_LLM::$throwOn = 'BADKW';
        PCM_DB::$strategyRow = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        // Must not throw — a background wp-cron callback that throws would show
        // up as a fatal in the site's error log on every future page load.
        PCM_Strategy_Service::run_queue_tick(7, 1);

        $this->assertSame('error', PCM_DB::$items[1]->status, 'the failure is still recorded on the item');
    }

    public function test_run_queue_tick_noops_when_the_strategy_no_longer_exists(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
        ));
        PCM_DB::$strategyRow = null; // deleted since the tick was scheduled

        PCM_Strategy_Service::run_queue_tick(7, 1);

        $this->assertSame('pending', PCM_DB::$items[1]->status, 'nothing should have been touched');
        $this->assertCount(0, PCM_Test_Cron::$scheduleCalls);
    }

    // ── Schedule computation (Step 3) ───────────────────────────────────

    public function test_calculate_schedule_dates_all_once_gives_every_item_the_same_date(): void
    {
        $dates = PCM_Strategy_Service::calculate_schedule_dates(3, 'all_once', '2026-07-08 00:00:00');

        $this->assertSame(
            array('2026-07-08 00:00:00', '2026-07-08 00:00:00', '2026-07-08 00:00:00'),
            $dates
        );
    }

    public function test_calculate_schedule_dates_daily_spaces_items_one_day_apart(): void
    {
        $dates = PCM_Strategy_Service::calculate_schedule_dates(3, 'daily', '2026-07-08 00:00:00');

        $this->assertSame(
            array('2026-07-08 00:00:00', '2026-07-09 00:00:00', '2026-07-10 00:00:00'),
            $dates
        );
    }

    public function test_calculate_schedule_dates_every_other_day_spaces_items_two_days_apart(): void
    {
        $dates = PCM_Strategy_Service::calculate_schedule_dates(3, 'every_other_day', '2026-07-08 00:00:00');

        $this->assertSame(
            array('2026-07-08 00:00:00', '2026-07-10 00:00:00', '2026-07-12 00:00:00'),
            $dates
        );
    }

    public function test_calculate_schedule_dates_weekly_spaces_items_seven_days_apart(): void
    {
        $dates = PCM_Strategy_Service::calculate_schedule_dates(3, 'weekly', '2026-07-08 00:00:00');

        $this->assertSame(
            array('2026-07-08 00:00:00', '2026-07-15 00:00:00', '2026-07-22 00:00:00'),
            $dates
        );
    }

    public function test_calculate_schedule_dates_biweekly_is_faithfully_ported_as_three_days_not_fourteen(): void
    {
        $dates = PCM_Strategy_Service::calculate_schedule_dates(3, 'biweekly', '2026-07-08 00:00:00');

        $this->assertSame(
            array('2026-07-08 00:00:00', '2026-07-11 00:00:00', '2026-07-14 00:00:00'),
            $dates,
            'AutoPress labels this "biweekly" but spaces +3 days -- ported verbatim, not a bug'
        );
    }

    public function test_calculate_schedule_dates_monthly_advances_by_a_calendar_month(): void
    {
        $dates = PCM_Strategy_Service::calculate_schedule_dates(2, 'monthly', '2026-03-01 00:00:00');

        $this->assertSame(
            array('2026-03-01 00:00:00', '2026-04-01 00:00:00'),
            $dates
        );
    }

    public function test_calculate_schedule_dates_unknown_frequency_falls_back_to_weekly_spacing(): void
    {
        $dates = PCM_Strategy_Service::calculate_schedule_dates(2, 'nonsense', '2026-07-08 00:00:00');

        $this->assertSame(
            array('2026-07-08 00:00:00', '2026-07-15 00:00:00'),
            $dates
        );
    }

    public function test_calculate_schedule_dates_zero_count_returns_empty_array(): void
    {
        $this->assertSame(array(), PCM_Strategy_Service::calculate_schedule_dates(0, 'daily', '2026-07-08 00:00:00'));
    }

    // ── create_from_keywords schedule integration ───────────────────────

    public function test_create_from_keywords_schedules_items_when_publishing_mode_is_schedule(): void
    {
        PCM_DB::$strategyRow = (object)array('id' => 7, 'userId' => 1);

        PCM_Strategy_Service::create_from_keywords(
            1,
            'My Strategy',
            3,
            null,
            array('kw one', 'kw two', 'kw three'),
            array(
                'publishingMode' => 'schedule',
                'config' => array('scheduleConfig' => array('frequency' => 'daily', 'startDate' => '2026-07-08 09:00:00')),
            )
        );

        $this->assertSame('2026-07-08 09:00:00', PCM_DB::$items[1]->scheduledDate);
        $this->assertSame('2026-07-09 09:00:00', PCM_DB::$items[2]->scheduledDate);
        $this->assertSame('2026-07-10 09:00:00', PCM_DB::$items[3]->scheduledDate);
    }

    public function test_create_from_keywords_defaults_to_weekly_and_now_when_schedule_config_is_missing(): void
    {
        PCM_DB::$strategyRow = (object)array('id' => 7, 'userId' => 1);
        PCM_Test_Cron::$now = '2026-07-08 12:00:00';

        PCM_Strategy_Service::create_from_keywords(
            1,
            'My Strategy',
            3,
            null,
            array('kw one', 'kw two'),
            array('publishingMode' => 'schedule')
        );

        $this->assertSame('2026-07-08 12:00:00', PCM_DB::$items[1]->scheduledDate, 'defaults startDate to current_time() when the dialog sends none');
        $this->assertSame('2026-07-15 12:00:00', PCM_DB::$items[2]->scheduledDate, 'defaults frequency to weekly when scheduleConfig is missing');
    }

    public function test_create_from_keywords_does_not_set_scheduled_date_when_not_schedule_mode(): void
    {
        PCM_DB::$strategyRow = (object)array('id' => 7, 'userId' => 1);

        PCM_Strategy_Service::create_from_keywords(
            1,
            'My Strategy',
            3,
            null,
            array('kw one'),
            array('publishingMode' => 'draft')
        );

        $this->assertFalse(property_exists(PCM_DB::$items[1], 'scheduledDate'), 'non-scheduled strategies must not touch scheduledDate at all');
    }

    // ── Cron scanner for due scheduled items (Step 4) ───────────────────

    public function test_run_scheduled_scan_schedules_the_queue_for_strategies_with_a_due_item(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
        ));
        PCM_DB::$dueScheduledStrategies = array((object)array('strategyId' => 7, 'userId' => 1));

        PCM_Strategy_Service::run_scheduled_scan();

        $this->assertCount(1, PCM_Test_Cron::$scheduleCalls);
        $this->assertSame('pcm_strategy_process_queue', PCM_Test_Cron::$scheduleCalls[0]['hook']);
        $this->assertSame(array(7, 1), PCM_Test_Cron::$scheduleCalls[0]['args']);
    }

    public function test_run_scheduled_scan_does_nothing_when_no_strategy_has_a_due_item(): void
    {
        PCM_DB::$dueScheduledStrategies = array(); // the default -- no due rows returned by the query

        PCM_Strategy_Service::run_scheduled_scan();

        $this->assertCount(0, PCM_Test_Cron::$scheduleCalls);
    }

    public function test_continuation_does_not_chain_through_a_not_yet_due_item(): void
    {
        // item 1 has no scheduledDate (it's the one this call generates); item 2
        // is scheduled far in the future -- the queue must NOT chain straight
        // into it just because item 1 finished.
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1, 'scheduledDate' => '2099-01-01 00:00:00'),
        ));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame('completed', PCM_DB::$items[1]->status);
        $this->assertCount(0, PCM_Test_Cron::$scheduleCalls, 'item 2 is not due yet -- run_scheduled_scan(), not this chain, picks it up later');
    }

    public function test_continuation_still_chains_when_the_next_item_is_due(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1, 'scheduledDate' => '2020-01-01 00:00:00'),
        ));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(1, PCM_Test_Cron::$scheduleCalls, 'item 2 is already due -- the chain must continue immediately');
    }

    // ── Approvals hand-off (Steps 5-7, Decision 2) ──────────────────────

    public function test_approval_mode_internal_parks_the_item_in_review_and_creates_a_set(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array(
            'id' => 7, 'name' => 'My Strategy', 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('approvalMode' => 'internal')),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame('in_review', PCM_DB::$items[1]->status);
        $this->assertSame(501, PCM_DB::$items[1]->setId);
        $this->assertCount(1, PCM_Approvals_Service::$createSetCalls);
        $snapshot = PCM_Approvals_Service::$createSetCalls[0]['data']['snapshot'];
        $this->assertCount(1, $snapshot['articles']);
        $this->assertSame('Generated Title', $snapshot['articles'][0]['title']);
        $this->assertStringContainsString('Internal Review', PCM_Approvals_Service::$createSetCalls[0]['data']['name']);
    }

    public function test_approval_mode_client_still_creates_an_unshared_set_distinguished_only_by_name(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array(
            'id' => 7, 'name' => 'My Strategy', 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('approvalMode' => 'client')),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame('in_review', PCM_DB::$items[1]->status);
        $this->assertStringContainsString('Client Review', PCM_Approvals_Service::$createSetCalls[0]['data']['name']);
    }

    public function test_approval_gate_defers_publish_even_when_publishing_mode_is_publish(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        PCM_DB::$site = (object)array('id' => 5, 'name' => 'My Site', 'url' => 'https://example.com');
        $strategy = (object)array(
            'id' => 7, 'name' => 'My Strategy', 'templateId' => 3, 'brandId' => null,
            'publishingMode' => 'publish',
            'config' => json_encode(array('approvalMode' => 'internal', 'siteId' => 5)),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame('in_review', PCM_DB::$items[1]->status);
        $this->assertCount(0, PCM_Sites_Service::$calls, 'publish must not happen until the approval set is fully approved (Step 7)');
    }

    public function test_approval_mode_none_is_unaffected_and_keeps_the_pre_step_6_behavior(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame('completed', PCM_DB::$items[1]->status);
        $this->assertCount(0, PCM_Approvals_Service::$createSetCalls, 'approvalMode defaults to none -- no Approvals involvement at all');
    }

    public function test_advance_item_on_approval_publishes_and_completes_the_item(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'in_review', 'position' => 0, 'articleId' => 100, 'setId' => 99, 'strategyId' => 7)));
        PCM_DB::$articles[100] = array('id' => 100, 'title' => 'T', 'content' => '<p>c</p>');
        PCM_DB::$site = (object)array('id' => 5, 'name' => 'My Site', 'url' => 'https://example.com');
        PCM_DB::$strategyRow = (object)array('id' => 7, 'publishingMode' => 'publish', 'config' => json_encode(array('approvalMode' => 'internal', 'siteId' => 5)), 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        $result = PCM_Strategy_Service::advance_item_on_approval(99, 1);

        $this->assertNotNull($result);
        $this->assertSame('completed', PCM_DB::$items[1]->status);
        $this->assertCount(1, PCM_Sites_Service::$calls, 'the deferred publish must run now that the set is fully approved');
    }

    public function test_advance_item_on_approval_returns_null_for_a_set_not_linked_to_any_strategy_item(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'in_review', 'position' => 0, 'setId' => 99)));

        $result = PCM_Strategy_Service::advance_item_on_approval(12345, 1);

        $this->assertNull($result, 'an approval set unrelated to any strategy item (e.g. a Writer/Copy/Image set) must be a clean no-op');
    }

    public function test_advance_item_on_approval_is_a_noop_on_re_entry_once_already_completed(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'position' => 0, 'articleId' => 100, 'setId' => 99)));

        $result = PCM_Strategy_Service::advance_item_on_approval(99, 1);

        $this->assertNull($result, 'the trigger firing again for an already-advanced item must not re-publish');
        $this->assertCount(0, PCM_Sites_Service::$calls);
    }

    public function test_advance_item_on_approval_does_not_publish_when_the_atomic_claim_loses_a_race(): void
    {
        // Security-review finding (P2): the Approvals module can deliver
        // set_fully_approved more than once concurrently (e.g. a client
        // double-clicking "approve all"). Simulate a losing race -- the atomic
        // claim fails even though the item still LOOKED like 'in_review' at the
        // initial read -- and confirm publish never runs.
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'in_review', 'position' => 0, 'articleId' => 100, 'setId' => 99, 'strategyId' => 7)));
        PCM_DB::$articles[100] = array('id' => 100, 'title' => 'T', 'content' => '<p>c</p>');
        PCM_DB::$site = (object)array('id' => 5, 'name' => 'My Site', 'url' => 'https://example.com');
        PCM_DB::$strategyRow = (object)array('id' => 7, 'publishingMode' => 'publish', 'config' => json_encode(array('approvalMode' => 'internal', 'siteId' => 5)), 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);
        PCM_DB::$forceClaimFail = true;

        $result = PCM_Strategy_Service::advance_item_on_approval(99, 1);

        $this->assertNull($result, 'losing the atomic claim must cleanly no-op, not double-publish');
        $this->assertCount(0, PCM_Sites_Service::$calls, 'a losing caller must never reach maybe_auto_publish');
    }

    public function test_publish_on_approval_action_handler_skips_cleanly_when_not_strategy_linked(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/modules/automations/handlers/interface-pcm-automation-action-handler.php';
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/class-pcm-publish-on-approval-action-handler.php';

        $handler = new PCM_Publish_On_Approval_Action_Handler();
        $result  = $handler->run(array(), array(), array('setId' => 999999), 1);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['skipped'], 'not every approval set is strategy-linked -- this must report as a clean skip, not an error');
    }

    // ── Consolidated structure (Step 8) ─────────────────────────────────

    public function test_consolidated_structure_generates_one_article_covering_all_keywords(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'best crm software', 'status' => 'pending', 'position' => 0),
            array('id' => 2, 'keyword' => 'cheap accounting tools', 'status' => 'pending', 'position' => 1),
            array('id' => 3, 'keyword' => 'invoice generators', 'status' => 'pending', 'position' => 2),
        ));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('structure' => 'consolidated')),
            'totalItems' => 3, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame(1, PCM_LLM::$callCount, 'consolidated mode must generate exactly ONE article for the whole batch');
        $this->assertStringContainsString('best crm software', PCM_LLM::$lastUserMessage);
        $this->assertStringContainsString('cheap accounting tools', PCM_LLM::$lastUserMessage);
        $this->assertStringContainsString('invoice generators', PCM_LLM::$lastUserMessage);

        $this->assertSame('completed', PCM_DB::$items[1]->status);
        $this->assertSame('completed', PCM_DB::$items[2]->status);
        $this->assertSame('completed', PCM_DB::$items[3]->status);
        $this->assertSame(PCM_DB::$items[1]->articleId, PCM_DB::$items[2]->articleId);
        $this->assertSame(PCM_DB::$items[1]->articleId, PCM_DB::$items[3]->articleId);
    }

    public function test_consolidated_structure_is_a_noop_once_already_generated(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'completed', 'position' => 1, 'articleId' => 100),
        ));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('structure' => 'consolidated')),
            'totalItems' => 2, 'completedItems' => 2, 'failedItems' => 0,
        );

        $result = PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertNull($result);
        $this->assertSame(0, PCM_LLM::$callCount, 'nothing left pending -- must not call the LLM again');
    }

    public function test_consolidated_structure_respects_approval_mode(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1),
        ));
        $strategy = (object)array(
            'id' => 7, 'name' => 'My Strategy', 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('structure' => 'consolidated', 'approvalMode' => 'internal')),
            'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame('in_review', PCM_DB::$items[1]->status);
        $this->assertSame('in_review', PCM_DB::$items[2]->status);
        $this->assertSame(PCM_DB::$items[1]->setId, PCM_DB::$items[2]->setId);
        $this->assertCount(1, PCM_Approvals_Service::$createSetCalls, 'one shared set for the whole batch, not one per item');
    }

    // ── Hierarchy-aware generation (Step 9) ─────────────────────────────

    public function test_children_only_injects_the_external_parent_link_into_every_item(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1),
        ));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null, 'hierarchyMode' => 'children_only',
            'config' => json_encode(array('parentTargetUrl' => 'https://example.com/parent')),
            'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);
        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $article1 = PCM_DB::$articles[PCM_DB::$items[1]->articleId];
        $article2 = PCM_DB::$articles[PCM_DB::$items[2]->articleId];
        $this->assertStringContainsString('https://example.com/parent', $article1['content']);
        $this->assertStringContainsString('https://example.com/parent', $article2['content']);
    }

    public function test_parent_and_children_generates_the_designated_parent_first_regardless_of_position(): void
    {
        // The parent keyword is at position 1, a child at position 0 -- plain
        // lowest-position selection would pick the child first; hierarchy-aware
        // selection must still pick the parent.
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'child kw', 'status' => 'pending', 'position' => 0),
            array('id' => 2, 'keyword' => 'parent kw', 'status' => 'pending', 'position' => 1),
        ));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null, 'hierarchyMode' => 'parent_and_children',
            'config' => json_encode(array('parentKeyword' => 'parent kw')),
            'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame('completed', PCM_DB::$items[2]->status, 'the designated parent must generate first');
        $this->assertSame('pending', PCM_DB::$items[1]->status, 'the child must still be waiting');
    }

    public function test_parent_and_children_children_wait_while_parent_still_generating(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'child kw', 'status' => 'pending', 'position' => 0),
            array('id' => 2, 'keyword' => 'parent kw', 'status' => 'generating', 'position' => 1),
        ));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null, 'hierarchyMode' => 'parent_and_children',
            'config' => json_encode(array('parentKeyword' => 'parent kw')),
            'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0,
        );

        $result = PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertNull($result, 'children must wait -- the parent has no article yet');
        $this->assertSame('pending', PCM_DB::$items[1]->status, 'the child must not have been touched');
        $this->assertSame(0, PCM_LLM::$callCount);
    }

    public function test_parent_and_children_child_links_to_the_generated_parent_url(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'parent kw', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'child kw', 'status' => 'pending', 'position' => 1),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'slug' => 'parent-slug', 'title' => 'Parent Article');
        PCM_DB::$site = (object)array('id' => 5, 'url' => 'https://example.com');
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null, 'hierarchyMode' => 'parent_and_children',
            'config' => json_encode(array('parentKeyword' => 'parent kw', 'siteId' => 5)),
            'totalItems' => 2, 'completedItems' => 1, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame('completed', PCM_DB::$items[2]->status);
        $child_article = PCM_DB::$articles[PCM_DB::$items[2]->articleId];
        $this->assertStringContainsString('https://example.com/parent-slug', $child_article['content']);
    }

    // ── Interlink injection (Step 10) ───────────────────────────────────

    public function test_interlinks_are_injected_once_the_strategy_transitions_into_completed(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'best crm software', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'cheap accounting tools', 'status' => 'completed', 'position' => 1, 'articleId' => 101),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'slug' => 'crm-guide', 'content' => '<p>We compare cheap accounting tools here too.</p>');
        PCM_DB::$articles[101] = array('id' => 101, 'slug' => 'accounting-guide', 'content' => '<p>Check out our best crm software list.</p>');
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'status' => 'in_progress',
            'config' => json_encode(array('interlinksConfig' => array('quantity' => 2))),
            'totalItems' => 2, 'completedItems' => 2, 'failedItems' => 0,
        );

        PCM_Strategy_Service::recompute_counters(7, 1, 2);

        $this->assertStringContainsString('accounting-guide', PCM_DB::$articles[100]['content']);
        $this->assertStringContainsString('crm-guide', PCM_DB::$articles[101]['content']);
    }

    public function test_interlinks_never_double_inject_the_same_target_link(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'best crm software', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'cheap accounting tools', 'status' => 'completed', 'position' => 1, 'articleId' => 101),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'slug' => 'crm-guide', 'content' => '<p>We compare cheap accounting tools here too.</p>');
        PCM_DB::$articles[101] = array('id' => 101, 'slug' => 'accounting-guide', 'content' => '<p>Check out our best crm software list.</p>');
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'status' => 'in_progress',
            'config' => json_encode(array('interlinksConfig' => array('quantity' => 2))),
            'totalItems' => 2, 'completedItems' => 2, 'failedItems' => 0,
        );

        // Called twice -- e.g. the fake's own limitation of not persisting the
        // status write back onto $strategyRow means this re-enters the same
        // "just completed" path a second time; the target-already-linked guard
        // must still prevent a duplicate injection either way.
        PCM_Strategy_Service::recompute_counters(7, 1, 2);
        PCM_Strategy_Service::recompute_counters(7, 1, 2);

        $this->assertSame(1, substr_count(PCM_DB::$articles[100]['content'], 'accounting-guide'));
        $this->assertSame(1, substr_count(PCM_DB::$articles[101]['content'], 'crm-guide'));
    }

    public function test_interlinks_respect_the_quantity_cap(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'alpha topic', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'beta topic', 'status' => 'completed', 'position' => 1, 'articleId' => 101),
            array('id' => 3, 'keyword' => 'gamma topic', 'status' => 'completed', 'position' => 2, 'articleId' => 102),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'slug' => 'alpha', 'content' => '<p>Mentions beta topic and gamma topic both.</p>');
        PCM_DB::$articles[101] = array('id' => 101, 'slug' => 'beta', 'content' => '<p>Just filler content.</p>');
        PCM_DB::$articles[102] = array('id' => 102, 'slug' => 'gamma', 'content' => '<p>Just filler content.</p>');
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'status' => 'in_progress',
            'config' => json_encode(array('interlinksConfig' => array('quantity' => 1))),
            'totalItems' => 3, 'completedItems' => 3, 'failedItems' => 0,
        );

        PCM_Strategy_Service::recompute_counters(7, 1, 3);

        $links_in_alpha = substr_count(PCM_DB::$articles[100]['content'], '<a href=');
        $this->assertSame(1, $links_in_alpha, 'quantity=1 must cap injection to a single link even though 2 candidates matched');
    }

    public function test_interlinks_are_a_noop_when_not_configured(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'best crm software', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'cheap accounting tools', 'status' => 'completed', 'position' => 1, 'articleId' => 101),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'slug' => 'crm-guide', 'content' => '<p>We compare cheap accounting tools here too.</p>');
        PCM_DB::$articles[101] = array('id' => 101, 'slug' => 'accounting-guide', 'content' => '<p>Check out our best crm software list.</p>');
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'status' => 'in_progress', 'config' => null,
            'totalItems' => 2, 'completedItems' => 2, 'failedItems' => 0,
        );

        PCM_Strategy_Service::recompute_counters(7, 1, 2);

        $this->assertStringNotContainsString('<a href', PCM_DB::$articles[100]['content']);
        $this->assertStringNotContainsString('<a href', PCM_DB::$articles[101]['content']);
    }

    public function test_interlinks_never_nest_an_anchor_inside_an_existing_anchors_text(): void
    {
        // Security-review finding: the only occurrence of "cheap tools" sits
        // inside an EXISTING anchor's rendered text (e.g. Step 9's own
        // parent-link paragraph). Wrapping it there would nest <a> inside <a> --
        // invalid HTML. Must skip this occurrence rather than blindly wrap it.
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'best crm software', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'cheap tools', 'status' => 'completed', 'position' => 1, 'articleId' => 101),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'slug' => 'crm-guide', 'content' => '<p>See <a href="https://other.example.com">cheap tools</a> for more.</p>');
        PCM_DB::$articles[101] = array('id' => 101, 'slug' => 'tools-guide', 'content' => '<p>Just filler content, no crm mention.</p>');
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'status' => 'in_progress',
            'config' => json_encode(array('interlinksConfig' => array('quantity' => 2))),
            'totalItems' => 2, 'completedItems' => 2, 'failedItems' => 0,
        );

        PCM_Strategy_Service::recompute_counters(7, 1, 2);

        $this->assertSame(
            1,
            substr_count(PCM_DB::$articles[100]['content'], '<a '),
            'must not add a second, nested anchor around the existing one\'s text'
        );
    }

    public function test_interlinks_idempotency_guard_handles_urls_with_special_characters(): void
    {
        // Security-review finding: the idempotency check must compare against
        // the SAME escaped form that's actually injected -- otherwise a target
        // URL containing characters esc_url() entity-encodes (e.g. "&" in a
        // query string) would defeat the guard and re-inject on every re-run.
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'best crm software', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'cheap accounting tools', 'status' => 'completed', 'position' => 1, 'articleId' => 101),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'slug' => 'crm-guide?utm=1&ref=2', 'content' => '<p>We compare cheap accounting tools here too.</p>');
        PCM_DB::$articles[101] = array('id' => 101, 'slug' => 'accounting-guide', 'content' => '<p>Check out our best crm software list.</p>');
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'status' => 'in_progress',
            'config' => json_encode(array('interlinksConfig' => array('quantity' => 2))),
            'totalItems' => 2, 'completedItems' => 2, 'failedItems' => 0,
        );

        PCM_Strategy_Service::recompute_counters(7, 1, 2);
        PCM_Strategy_Service::recompute_counters(7, 1, 2); // re-entry

        $this->assertSame(
            1,
            substr_count(PCM_DB::$articles[101]['content'], '<a '),
            'a target URL with special characters must not defeat the already-linked guard on re-run'
        );
    }

    // ── merge_strategy_config() (site-binding plan, Step 1) ─────────────

    public function test_merge_strategy_config_preserves_untouched_existing_keys(): void
    {
        $existing = json_encode(array(
            'model' => 'gpt-4o', 'approvalMode' => 'internal', 'siteId' => 3,
            'scheduleConfig' => array('frequency' => 'daily', 'startDate' => '2026-01-01'),
        ));

        $merged = PCM_Strategy_Service::merge_strategy_config($existing, array('siteId' => 9));

        $this->assertSame(9, $merged['siteId'], 'the incoming key must win');
        $this->assertSame('gpt-4o', $merged['model'], 'untouched keys must survive the merge');
        $this->assertSame('internal', $merged['approvalMode'], 'untouched keys must survive the merge');
        $this->assertSame(
            array('frequency' => 'daily', 'startDate' => '2026-01-01'),
            $merged['scheduleConfig'],
            'nested untouched config objects must survive the merge whole, not just top-level keys'
        );
    }

    public function test_merge_strategy_config_incoming_overwrites_matching_keys(): void
    {
        $existing = json_encode(array('siteId' => 3, 'approvalMode' => 'none'));

        $merged = PCM_Strategy_Service::merge_strategy_config($existing, array('siteId' => 9, 'approvalMode' => 'client'));

        $this->assertSame(9, $merged['siteId']);
        $this->assertSame('client', $merged['approvalMode']);
    }

    public function test_merge_strategy_config_handles_null_existing_json(): void
    {
        $merged = PCM_Strategy_Service::merge_strategy_config(null, array('siteId' => 5));

        $this->assertSame(array('siteId' => 5), $merged);
    }

    public function test_merge_strategy_config_handles_garbage_existing_json(): void
    {
        // Malformed JSON must degrade to "no existing config" rather than throw.
        $merged = PCM_Strategy_Service::merge_strategy_config('{not valid json!!', array('siteId' => 5));

        $this->assertSame(array('siteId' => 5), $merged);
    }

    public function test_merge_strategy_config_handles_empty_string_existing_json(): void
    {
        $merged = PCM_Strategy_Service::merge_strategy_config('', array('siteId' => 5));

        $this->assertSame(array('siteId' => 5), $merged);
    }

    public function test_merge_strategy_config_empty_incoming_is_a_noop(): void
    {
        $existing = json_encode(array('siteId' => 3, 'approvalMode' => 'internal'));

        $merged = PCM_Strategy_Service::merge_strategy_config($existing, array());

        $this->assertSame(array('siteId' => 3, 'approvalMode' => 'internal'), $merged);
    }

    // ── Atomic generation claim + stale reclaim (worker plan, Step 2) ───

    public function test_lost_claim_repicks_the_next_pending_item_instead_of_double_generating(): void
    {
        // A concurrent generator (phantom winner) takes item 1 between our pick
        // and our claim -- we must move on to item 2, never generate item 1 too.
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1),
        ));
        PCM_DB::$loseGenerationClaims = 1;
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame('generating', PCM_DB::$items[1]->status, 'item 1 belongs to the concurrent winner -- untouched by us');
        $this->assertSame('completed', PCM_DB::$items[2]->status, 'we re-picked and generated item 2');
        $this->assertSame(1, PCM_LLM::$callCount, 'exactly ONE generation -- no duplicate for the contested item');
    }

    public function test_all_pending_items_claimed_by_others_returns_null_without_generating(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
        ));
        PCM_DB::$loseGenerationClaims = 1; // the only item goes to the phantom winner

        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);
        $result = PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertNull($result, 'nothing left for us -- clean null, not an error');
        $this->assertSame(0, PCM_LLM::$callCount, 'we must not have generated anything');
        $this->assertSame('generating', PCM_DB::$items[1]->status, 'the winner keeps the item');
    }

    public function test_stale_generating_item_is_reclaimed_and_generated(): void
    {
        // A generator killed mid-run stranded item 1 in 'generating' 20 minutes
        // ago -- the next generate call must reclaim it and finish the job.
        PCM_Test_Cron::$now = '2026-07-09 12:00:00';
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'generating', 'position' => 0, 'updatedAt' => '2026-07-09 11:40:00'),
        ));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame('completed', PCM_DB::$items[1]->status, 'the wedged item was reclaimed and generated');
    }

    public function test_fresh_generating_item_is_not_reclaimed(): void
    {
        // An item another process claimed 2 minutes ago is LIVE work -- stealing
        // it would cause the exact duplicate generation the claim prevents.
        PCM_Test_Cron::$now = '2026-07-09 12:00:00';
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'generating', 'position' => 0, 'updatedAt' => '2026-07-09 11:58:00'),
        ));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        $result = PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertNull($result, 'nothing to pick -- the fresh generating item is left alone');
        $this->assertSame('generating', PCM_DB::$items[1]->status);
        $this->assertSame(0, PCM_LLM::$callCount);
    }

    // ── Manual per-item publish + interlink trigger (worker plan, Step 4) ──

    public function test_publish_item_publishes_a_completed_draft_article_to_the_site(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'title' => 'T', 'content' => '<p>c</p>');
        PCM_DB::$site = (object)array('id' => 5, 'name' => 'My Site', 'url' => 'https://example.com');
        $strategy = (object)array('id' => 7, 'publishingMode' => 'draft', 'config' => json_encode(array('siteId' => 5)), 'totalItems' => 1);

        $result = PCM_Strategy_Service::publish_item($strategy, 1, 1);

        $this->assertTrue($result['success']);
        $this->assertCount(1, PCM_Sites_Service::$calls, 'the existing publish pipeline must be reused');
        $this->assertSame(1, PCM_Sites_Service::$calls[0]['user_id']);
    }

    public function test_publish_item_is_idempotent_for_an_already_published_article(): void
    {
        // Done-gate finding F1: the endpoint (not just the UI) must refuse to
        // re-publish -- publish_to_site() always creates a FRESH post, so a
        // direct API call on a published item would duplicate it on the live site.
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'title' => 'T', 'content' => '<p>c</p>', 'publishedUrl' => 'https://example.com/already-live');
        PCM_DB::$site = (object)array('id' => 5, 'name' => 'My Site', 'url' => 'https://example.com');
        $strategy = (object)array('id' => 7, 'publishingMode' => 'draft', 'config' => json_encode(array('siteId' => 5)), 'totalItems' => 1);

        $result = PCM_Strategy_Service::publish_item($strategy, 1, 1);

        $this->assertTrue($result['success']);
        $this->assertSame('https://example.com/already-live', $result['postUrl']);
        $this->assertCount(0, PCM_Sites_Service::$calls, 'must NOT hit the site again -- that would duplicate the live post');
    }

    public function test_publish_item_requires_a_target_site(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'title' => 'T', 'content' => '<p>c</p>');
        $strategy = (object)array('id' => 7, 'publishingMode' => 'draft', 'config' => null, 'totalItems' => 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No Target Site/');
        PCM_Strategy_Service::publish_item($strategy, 1, 1);
    }

    public function test_publish_item_rejects_a_non_completed_item(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
        ));
        $strategy = (object)array('id' => 7, 'publishingMode' => 'draft', 'config' => json_encode(array('siteId' => 5)), 'totalItems' => 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/completed item/');
        PCM_Strategy_Service::publish_item($strategy, 1, 1);
    }

    public function test_publish_item_rejects_an_item_not_in_this_strategy(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
        ));
        $strategy = (object)array('id' => 7, 'publishingMode' => 'draft', 'config' => json_encode(array('siteId' => 5)), 'totalItems' => 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found in this strategy/');
        PCM_Strategy_Service::publish_item($strategy, 999, 1);
    }

    public function test_run_interlinks_returns_the_injected_count(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'best crm software', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'cheap accounting tools', 'status' => 'completed', 'position' => 1, 'articleId' => 101),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'slug' => 'crm-guide', 'content' => '<p>We compare cheap accounting tools here too.</p>');
        PCM_DB::$articles[101] = array('id' => 101, 'slug' => 'accounting-guide', 'content' => '<p>Check out our best crm software list.</p>');
        // NO interlinksConfig on the strategy -- the manual trigger must still
        // work (default quantity), unlike the auto on-completion run.
        PCM_DB::$strategyRow = (object)array('id' => 7, 'status' => 'completed', 'config' => null, 'totalItems' => 2, 'completedItems' => 2, 'failedItems' => 0);

        $result = PCM_Strategy_Service::run_interlinks(7, 1);

        $this->assertSame(2, $result['injected'], 'one link each way between the two articles');
        $this->assertStringContainsString('accounting-guide', PCM_DB::$articles[100]['content']);
        $this->assertStringContainsString('crm-guide', PCM_DB::$articles[101]['content']);
    }

    public function test_run_interlinks_is_idempotent_on_rerun(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'best crm software', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'cheap accounting tools', 'status' => 'completed', 'position' => 1, 'articleId' => 101),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'slug' => 'crm-guide', 'content' => '<p>We compare cheap accounting tools here too.</p>');
        PCM_DB::$articles[101] = array('id' => 101, 'slug' => 'accounting-guide', 'content' => '<p>Check out our best crm software list.</p>');
        PCM_DB::$strategyRow = (object)array('id' => 7, 'status' => 'completed', 'config' => null, 'totalItems' => 2, 'completedItems' => 2, 'failedItems' => 0);

        $first  = PCM_Strategy_Service::run_interlinks(7, 1);
        $second = PCM_Strategy_Service::run_interlinks(7, 1);

        $this->assertSame(2, $first['injected']);
        $this->assertSame(0, $second['injected'], 'clicking the button twice must not double-inject');
    }

    // ── Batch 2: reschedule / per-item date / per-item delete ───────────

    public function test_reschedule_pending_items_redistributes_only_pending_items(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'done kw', 'status' => 'completed', 'position' => 0, 'scheduledDate' => '2026-01-01 00:00:00'),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1, 'scheduledDate' => '2026-01-08 00:00:00'),
            array('id' => 3, 'keyword' => 'kw three', 'status' => 'pending', 'position' => 2, 'scheduledDate' => '2026-01-15 00:00:00'),
        ));

        $count = PCM_Strategy_Service::reschedule_pending_items(7, 'daily', '2026-08-01');

        $this->assertSame(2, $count);
        $this->assertSame('2026-01-01 00:00:00', PCM_DB::$items[1]->scheduledDate, 'completed item keeps its history');
        $this->assertSame('2026-08-01 00:00:00', PCM_DB::$items[2]->scheduledDate);
        $this->assertSame('2026-08-02 00:00:00', PCM_DB::$items[3]->scheduledDate);
    }

    public function test_set_item_scheduled_date_normalizes_and_writes(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
        ));

        $stored = PCM_Strategy_Service::set_item_scheduled_date(7, 1, 1, '2026-08-15');

        $this->assertSame('2026-08-15 00:00:00', $stored);
        $this->assertSame('2026-08-15 00:00:00', PCM_DB::$items[1]->scheduledDate);
    }

    public function test_set_item_scheduled_date_rejects_a_non_pending_item(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/pending/');
        PCM_Strategy_Service::set_item_scheduled_date(7, 1, 1, '2026-08-15');
    }

    public function test_set_item_scheduled_date_rejects_garbage_dates(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unrecognized date/');
        PCM_Strategy_Service::set_item_scheduled_date(7, 1, 1, 'not a date at all!!');
    }

    public function test_delete_item_removes_the_row_and_decrements_total(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1),
        ));

        $items = PCM_Strategy_Service::delete_item(7, 2, 1, 2);

        $this->assertCount(1, $items, 'one item left');
        $this->assertArrayNotHasKey(2, PCM_DB::$items);
        $this->assertSame(1, PCM_DB::$strategy['totalItems'], 'totalItems decremented');
        $this->assertSame('completed', PCM_DB::$strategy['status'], '1 completed of new total 1 -> strategy completes');
    }

    public function test_delete_item_refuses_a_generating_item(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'generating', 'position' => 0),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/generating right now/');
        PCM_Strategy_Service::delete_item(7, 1, 1, 1);
    }

    public function test_delete_item_rejects_a_foreign_item(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found in this strategy/');
        PCM_Strategy_Service::delete_item(7, 999, 1, 1);
    }
}
