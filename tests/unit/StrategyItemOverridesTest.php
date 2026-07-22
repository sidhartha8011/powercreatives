<?php
/**
 * Unit Tests — Task F1/F2: per-ITEM overrides (templateId/publishingMode/
 * approvalMode) on top of a strategy's own config, resolved ONLY in the
 * per-item generate_next_item() path (a consolidated/Step-8 strategy shares
 * one article across every item and can't honor conflicting per-item
 * overrides — see item_config()'s docblock).
 *
 * Exercises the REAL PCM_Strategy_Service::generate_next_item() /
 * set_item_config() against lightweight in-process stand-ins for PCM_DB /
 * PCM_LLM / PCM_Sites_Service / PCM_Approvals_Service / PCM_Schema — same
 * shape as StrategyAutoPublishTest.php's fakes, trimmed to what this suite
 * actually exercises. @runTestsInSeparateProcesses for the same reason as
 * that sibling file: the stand-ins share class names with the real,
 * composer-classmapped services.
 *
 * @package PowerCreatives\Tests\Unit
 */

/** Declares the fakes exactly once per (isolated) process, on first call. */
function pcm_test_define_item_overrides_fakes(): void
{
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/wordpress/');
    }
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
            return date('Y-m-d H:i:s');
        }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($v)
        {
            return json_encode($v);
        }
    }
    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook, $args = array())
        {
            return false; // never "already scheduled" — this suite doesn't assert on continuation
        }
    }
    if (!function_exists('wp_schedule_single_event')) {
        function wp_schedule_single_event($timestamp, $hook, $args = array())
        {
            return true; // no-op recorder needed — this suite doesn't assert on continuation
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

    // $wpdb only used by load_template() — the "cheapest reliable assertion" seam
    // for the templateId-override test (a): prepare() records the template_id it
    // was actually queried with (the SQL's first %d), and get_row() returns a
    // DIFFERENT template per id so the test can assert which one generation used.
    if (!class_exists('PCM_Test_FakeWpdb', false)) {
        class PCM_Test_FakeWpdb
        {
            public $postmeta = 'wp_postmeta';
            /** @var int|null the template_id load_template() was last queried with */
            public static $lastTemplateId = null;
            public function prepare($q, ...$a)
            {
                self::$lastTemplateId = $a[0] ?? null;
                return $q;
            }
            public function get_row($q)
            {
                $prompt = self::$lastTemplateId === 99 ? 'OVERRIDE PROMPT' : 'DEFAULT PROMPT';
                return (object)array(
                    'name'     => 'Tmpl#' . self::$lastTemplateId,
                    'formData' => json_encode(array('entries' => array(array('category' => 'prompt', 'value' => $prompt)))),
                );
            }
        }
    }
    PCM_Test_FakeWpdb::$lastTemplateId = null;
    $GLOBALS['wpdb'] = new PCM_Test_FakeWpdb();

    if (!class_exists('PCM_LLM', false)) {
        class PCM_LLM
        {
            public static $callCount = 0;
            public static function invoke_json($messages, $schema, $options)
            {
                self::$callCount++;
                return array('title' => 'Generated Title', 'content' => '<p>body</p>', 'metaTitle' => 'MT', 'metaDescription' => 'MD');
            }
        }
    }

    if (!class_exists('PCM_DB', false)) {
        class PCM_DB
        {
            public static $items = array();      // id => object
            public static $strategy = array();   // last update() payload captured
            public static $strategyRow = null;
            public static $articleSeq = 100;
            public static $articles = array();
            public static $site = null;

            public static function get_strategy($id, $uid)
            {
                return self::$strategyRow;
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
                if (!isset(self::$items[$id])
                    || (string) (self::$items[$id]->status ?? '') !== 'generating'
                ) {
                    return false;
                }
                foreach ($data as $k => $v) {
                    self::$items[$id]->$k = $v;
                }
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
                $data['id'] = $id;
                self::$articles[$id] = $data;
                return $id;
            }
            public static function get_article($id, $uid)
            {
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
            public static function claim_strategy_item($id)
            {
                if (!isset(self::$items[$id]) || self::$items[$id]->status !== 'pending') {
                    return false;
                }
                self::$items[$id]->status = 'generating';
                return true;
            }
            public static function reclaim_stale_generating($sid, $minutes = 10)
            {
                return 0; // no stale items in this suite's scenarios
            }
        }
    }

    if (!class_exists('PCM_Approvals_Service', false)) {
        class PCM_Approvals_Service
        {
            public static $createSetCalls = array();
            public static $nextSetId = 501;
            public static function create_set($user_id, $data)
            {
                self::$createSetCalls[] = array('userId' => $user_id, 'data' => $data);
                return self::$nextSetId;
            }
            /** Lane moves — the real create_set() hardcodes 'draft'; the strategy
             *  side moves the fresh set to its mode's starting lane right after. */
            public static $updateStatusCalls = array();
            public static function update_status($id, $user_id, $next_status)
            {
                self::$updateStatusCalls[] = array('id' => (int)$id, 'userId' => (int)$user_id, 'status' => (string)$next_status);
                return true;
            }
        }
    }

    if (!class_exists('PCM_Sites_Service', false)) {
        class PCM_Sites_Service
        {
            public static $calls = array();
            public static function publish_to_site($site, $article, $user_id)
            {
                self::$calls[] = array('site' => $site, 'article' => $article, 'user_id' => $user_id);
                return array('success' => true, 'postId' => 555, 'postUrl' => 'https://example.com/p/555', 'siteId' => (int)$site->id);
            }
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyItemOverridesTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_item_overrides_fakes();
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';

        PCM_DB::$items = array();
        PCM_DB::$strategy = array();
        PCM_DB::$strategyRow = null;
        PCM_DB::$site = null;
        PCM_DB::$articles = array();
        PCM_DB::$articleSeq = 100;
        PCM_LLM::$callCount = 0;
        PCM_Sites_Service::$calls = array();
        PCM_Approvals_Service::$createSetCalls = array();
        PCM_Approvals_Service::$updateStatusCalls = array();
        PCM_Approvals_Service::$nextSetId = 501;
        PCM_Test_FakeWpdb::$lastTemplateId = null;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function seedItems(array $items): void
    {
        foreach ($items as $it) {
            $o = (object)$it;
            PCM_DB::$items[$o->id] = $o;
        }
    }

    // ── (a) item templateId override ────────────────────────────────────

    public function test_item_template_id_override_is_used_for_that_items_load_template(): void
    {
        // Item 1 overrides to template #99; the strategy's own templateId is 3.
        $this->seedItems(array(array(
            'id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0,
            'config' => json_encode(array('templateId' => 99)),
        )));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame(99, PCM_Test_FakeWpdb::$lastTemplateId, 'the ITEM override (99) must win over the strategy templateId (3)');
        $this->assertSame('completed', PCM_DB::$items[1]->status);
    }

    public function test_item_without_a_template_override_falls_back_to_the_strategy_template(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame(3, PCM_Test_FakeWpdb::$lastTemplateId, 'no item override -- must fall back to the strategy templateId');
    }

    // ── (b) item publishingMode override ────────────────────────────────

    public function test_item_publish_override_publishes_only_that_item_on_an_otherwise_draft_strategy(): void
    {
        // Strategy-wide mode is 'draft' (would never auto-publish), but item 1
        // overrides to 'publish'; item 2 has no override and must stay a draft.
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0, 'config' => json_encode(array('publishingMode' => 'publish'))),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1),
        ));
        PCM_DB::$site = (object)array('id' => 9, 'name' => 'My Site', 'url' => 'https://mysite.example');
        $strategy = (object)array('id' => 7, 'templateId' => 3, 'brandId' => null, 'publishingMode' => 'draft', 'config' => json_encode(array('siteId' => 9)), 'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1); // picks item 1 (position 0)
        PCM_Strategy_Service::generate_next_item($strategy, 1); // picks item 2 (position 1)

        $this->assertCount(1, PCM_Sites_Service::$calls, 'only the overridden item should have published');
        $this->assertSame('completed', PCM_DB::$items[1]->status);
        $this->assertSame('completed', PCM_DB::$items[2]->status);
        $this->assertSame((string)($strategy->publishingMode ?? ''), 'draft', 'the ORIGINAL strategy object must be untouched by the clone-and-override');
    }

    // ── (c) item approvalMode override ──────────────────────────────────

    public function test_item_approval_override_parks_only_that_item_in_review(): void
    {
        // Strategy-wide approvalMode is 'none' (would normally go straight to
        // 'completed'), but item 1 overrides to 'internal'; item 2 has no
        // override and must complete normally.
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0, 'config' => json_encode(array('approvalMode' => 'internal'))),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1),
        ));
        $strategy = (object)array('id' => 7, 'name' => 'My Strategy', 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1); // item 1
        PCM_Strategy_Service::generate_next_item($strategy, 1); // item 2

        $this->assertSame('in_review', PCM_DB::$items[1]->status, 'the overridden item must be parked in review');
        $this->assertCount(1, PCM_Approvals_Service::$createSetCalls, 'exactly one approval set — for the overridden item only');
        $this->assertSame('completed', PCM_DB::$items[2]->status, 'the non-overridden sibling must complete normally (strategy approvalMode is none)');
    }

    // ── (e) approvalMode 'both' — internal + client must BOTH approve ────

    public function test_strategy_level_both_approval_mode_creates_set_in_the_internal_starting_lane(): void
    {
        // Strategy-wide approvalMode is 'both': no per-item override, so
        // generate_next_item() must resolve 'both' from the strategy config
        // and create_approval_set_for_item() must start the set in the same
        // lane as plain 'internal' (see that method's docblock: 'both' starts
        // internal; the Client-lane move is the internal sign-off).
        $this->seedItems(array(array(
            'id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0,
        )));
        $strategy = (object)array(
            'id' => 7, 'name' => 'My Strategy', 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('approvalMode' => 'both')),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame('in_review', PCM_DB::$items[1]->status, 'a "both" item is parked in review, same as "internal"/"client"');
        $this->assertNotEmpty(PCM_DB::$items[1]->setId ?? null, 'the item must be linked to the created approval set');
        $this->assertCount(1, PCM_Approvals_Service::$createSetCalls);
        $this->assertSame(
            'internal',
            PCM_Approvals_Service::$createSetCalls[0]['data']['status'] ?? null,
            '"both" must start in the INTERNAL lane, not "client" or the bare "draft" default'
        );
        // REAL lane placement (create_set hardcodes draft): explicit move to internal.
        $this->assertSame('internal', PCM_Approvals_Service::$updateStatusCalls[0]['status'] ?? null);
    }

    public function test_item_approval_override_both_wins_over_a_strategy_level_none(): void
    {
        // Mirrors test_item_approval_override_parks_only_that_item_in_review()
        // above, but for the new 'both' value: an item-level 'both' override
        // must win over the strategy's own 'none', park that item in review,
        // and leave its non-overridden sibling to complete normally.
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0, 'config' => json_encode(array('approvalMode' => 'both'))),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'pending', 'position' => 1),
        ));
        $strategy = (object)array('id' => 7, 'name' => 'My Strategy', 'templateId' => 3, 'brandId' => null, 'config' => null, 'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0);

        PCM_Strategy_Service::generate_next_item($strategy, 1); // item 1
        PCM_Strategy_Service::generate_next_item($strategy, 1); // item 2

        $this->assertSame('in_review', PCM_DB::$items[1]->status, 'the "both"-overridden item must be parked in review');
        $this->assertCount(1, PCM_Approvals_Service::$createSetCalls, 'exactly one approval set — for the overridden item only');
        $this->assertSame(
            'internal',
            PCM_Approvals_Service::$createSetCalls[0]['data']['status'] ?? null,
            'the per-item "both" override must also start in the internal lane'
        );
        $this->assertSame('completed', PCM_DB::$items[2]->status, 'the non-overridden sibling must complete normally (strategy approvalMode is none)');
    }

    // ── (d) set_item_config: REPLACE semantics + ownership scoping ──────

    public function test_set_item_config_replaces_the_full_config_rather_than_merging(): void
    {
        $this->seedItems(array(array(
            'id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0,
            'config' => json_encode(array('templateId' => 5, 'approvalMode' => 'internal')),
        )));

        $items = PCM_Strategy_Service::set_item_config(7, 1, 1, array('publishingMode' => 'publish'));

        $stored = json_decode((string)$items[0]->config, true);
        $this->assertSame(array('publishingMode' => 'publish'), $stored, 'REPLACE (not merge) — the old templateId/approvalMode keys must be gone');
    }

    public function test_set_item_config_with_empty_config_clears_stored_overrides(): void
    {
        $this->seedItems(array(array(
            'id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0,
            'config' => json_encode(array('templateId' => 5)),
        )));

        $items = PCM_Strategy_Service::set_item_config(7, 1, 1, array()); // every key switched back to "Inherit"

        $this->assertNull($items[0]->config, 'an empty override set (everything back to Inherit) must clear the stored config entirely');
    }

    public function test_set_item_config_rejects_an_item_not_in_this_strategy(): void
    {
        // Simulates a foreign itemId (another user's item, or a typo) — the
        // ownership boundary this method enforces since PCM_DB::update_strategy_item()
        // itself is not scoped by strategy or user.
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Item not found in this strategy.');
        PCM_Strategy_Service::set_item_config(7, 999, 1, array('publishingMode' => 'publish'));
    }
}
