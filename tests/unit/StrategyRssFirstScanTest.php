<?php
/**
 * Unit Tests — RSS instant first pull (create-time first scan).
 *
 * Covers the create-time kick that makes a Source=RSS strategy generate from
 * the feed's newest EXISTING item immediately instead of waiting for the
 * hourly watcher:
 *
 *   - create_from_keywords() arms a one-off 'pcm_strategy_rss_first_scan'
 *     event (with the strategy/owner args) when — and only when — the STORED
 *     config is sourceMode 'rss' with non-empty rssFeeds, dedup'd by
 *     wp_next_scheduled() exactly like maybe_schedule_queue_continuation();
 *   - run_rss_first_scan() (the event's callback) re-verifies ownership and
 *     the rss config, then runs the same per-strategy watcher pass as the
 *     hourly run_rss_scan() — asserted end-to-end here by seeding the config's
 *     rssQueue and observing the popped entry become a pending strategy item;
 *   - non-rss / missing / foreign strategies are silent no-ops.
 *
 * House-style fakes: a suite-local PCM_DB rich enough for BOTH the
 * create_from_keywords() path and scan_rss_strategy()'s DB edges
 * (count_strategy_items / create_rss_strategy_item / update_strategy),
 * declared BEFORE the shared require so the class_exists() guard lets it win
 * (same pattern as StrategyCronTickTest); then the shared WP shims / cron
 * recorder from StrategyAutoPublishTest via pcm_test_define_strategy_fakes().
 * The feed-fetch edge needs no fake at all: fetch_rss_feed_items() returns
 * array() when fetch_feed()/WPINC are absent, so the scan runs pure over the
 * config's own rssQueue — no SimplePie, no network.
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

function pcm_test_define_rss_first_scan_fakes(): void
{
    // Suite-local PCM_DB — must be declared before the shared fakes so this
    // richer stand-in (RSS watcher DB edges included) wins the guard race.
    if (!class_exists('PCM_DB', false)) {
        class PCM_DB
        {
            public static $createStrategyId = 7;
            public static $createStrategyData = null;
            /** @var object|null What get_strategy() returns (ownership-checked getter). */
            public static $strategyRow = null;
            /** @var object|null What get_next_pending_item() returns. */
            public static $nextPendingItem = null;
            /** @var int What count_strategy_items() returns. */
            public static $itemCount = 0;
            /** @var array<int,array> Every create_rss_strategy_item() call. */
            public static $rssItemCalls = array();
            /** @var array<int,array> Every update_strategy() payload. */
            public static $updateStrategyCalls = array();

            public static function create_strategy($data)
            {
                self::$createStrategyData = $data;
                return self::$createStrategyId;
            }
            public static function create_strategy_items($strategy_id, $user_id, $keywords, $meta = array())
            {
                return count($keywords);
            }
            public static function get_strategy($id, $uid)
            {
                return self::$strategyRow;
            }
            public static function get_strategy_items($sid)
            {
                return array();
            }
            public static function get_next_pending_item($sid)
            {
                return self::$nextPendingItem;
            }
            public static function count_strategy_items($sid, $since = null)
            {
                return self::$itemCount;
            }
            public static function create_rss_strategy_item($strategy_id, $user_id, $keyword, $item_config = array())
            {
                self::$rssItemCalls[] = array(
                    'strategyId' => $strategy_id,
                    'userId'     => $user_id,
                    'keyword'    => $keyword,
                    'config'     => $item_config,
                );
                return 100 + count(self::$rssItemCalls);
            }
            public static function update_strategy($id, $uid, $data)
            {
                self::$updateStrategyCalls[] = array('id' => $id, 'userId' => $uid, 'data' => $data);
                return true;
            }
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyRssFirstScanTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_rss_first_scan_fakes(); // richer PCM_DB must win the race
        pcm_test_define_strategy_fakes();       // shared WP shims / cron recorder
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';

        PCM_DB::$createStrategyId = 7;
        PCM_DB::$createStrategyData = null;
        PCM_DB::$strategyRow = null;
        PCM_DB::$nextPendingItem = null;
        PCM_DB::$itemCount = 0;
        PCM_DB::$rssItemCalls = array();
        PCM_DB::$updateStrategyCalls = array();
        PCM_Test_Cron::$scheduleCalls = array();
        PCM_Test_Cron::$alreadyScheduled = false;
        PCM_Test_Cron::$now = null;
    }

    /** Hooks of every wp_schedule_single_event() call this test recorded. */
    private function scheduledHooks(): array
    {
        return array_column(PCM_Test_Cron::$scheduleCalls, 'hook');
    }

    // ── (1) Create-time kick: rss config arms the first-scan event ───────

    public function test_create_from_keywords_with_rss_config_schedules_the_first_scan_event(): void
    {
        PCM_DB::$strategyRow = (object)array('id' => 7, 'userId' => 42);

        PCM_Strategy_Service::create_from_keywords(
            42,
            'RSS Strategy',
            3,
            null,
            array(), // rss strategies are created feed-only — no keyword items
            array(
                'publishingMode' => 'draft',
                'config' => array(
                    'sourceMode' => 'rss',
                    'rssFeeds'   => array('https://feed.example/rss'),
                ),
            )
        );

        $this->assertContains('pcm_strategy_rss_first_scan', $this->scheduledHooks(), 'an rss create must arm the one-off first scan');
        foreach (PCM_Test_Cron::$scheduleCalls as $call) {
            if ($call['hook'] === 'pcm_strategy_rss_first_scan') {
                $this->assertSame(array(7, 42), $call['args'], 'event args must be (strategyId, ownerId)');
            }
        }
    }

    public function test_rss_first_scan_event_is_deduped_when_already_scheduled(): void
    {
        PCM_DB::$strategyRow = (object)array('id' => 7, 'userId' => 42);
        PCM_Test_Cron::$alreadyScheduled = true;

        PCM_Strategy_Service::create_from_keywords(
            42,
            'RSS Strategy',
            3,
            null,
            array(),
            array(
                'publishingMode' => 'draft',
                'config' => array('sourceMode' => 'rss', 'rssFeeds' => array('https://feed.example/rss')),
            )
        );

        $this->assertNotContains('pcm_strategy_rss_first_scan', $this->scheduledHooks(), 'wp_next_scheduled() already true — must not double-schedule');
    }

    // ── (2) Keywords-mode create: no first-scan event ────────────────────

    public function test_create_from_keywords_keywords_mode_does_not_schedule_a_first_scan(): void
    {
        PCM_DB::$strategyRow = (object)array('id' => 7, 'userId' => 42);
        // A pending item exists, so the normal queue continuation IS armed —
        // proving the assertion below isolates the first-scan hook, not just
        // "nothing was scheduled at all".
        PCM_DB::$nextPendingItem = (object)array('id' => 1, 'status' => 'pending');

        PCM_Strategy_Service::create_from_keywords(
            42,
            'Keyword Strategy',
            3,
            null,
            array('kw one'),
            array('publishingMode' => 'draft')
        );

        $hooks = $this->scheduledHooks();
        $this->assertContains('pcm_strategy_process_queue', $hooks, 'the existing auto-start continuation is untouched');
        $this->assertNotContains('pcm_strategy_rss_first_scan', $hooks, 'no rss config — no first scan');
    }

    public function test_rss_source_mode_with_empty_feeds_does_not_schedule_a_first_scan(): void
    {
        PCM_DB::$strategyRow = (object)array('id' => 7, 'userId' => 42);

        PCM_Strategy_Service::create_from_keywords(
            42,
            'RSS Strategy (no feeds)',
            3,
            null,
            array(),
            array(
                'publishingMode' => 'draft',
                'config' => array('sourceMode' => 'rss', 'rssFeeds' => array()),
            )
        );

        $this->assertNotContains('pcm_strategy_rss_first_scan', $this->scheduledHooks(), 'nothing to scan — no event');
    }

    // ── (3) run_rss_first_scan: guards ───────────────────────────────────

    public function test_run_rss_first_scan_skips_a_non_rss_strategy(): void
    {
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'userId' => 42, 'status' => 'pending',
            'config' => json_encode(array('siteId' => 9)), // keywords-mode config
        );

        PCM_Strategy_Service::run_rss_first_scan(7, 42);

        $this->assertCount(0, PCM_DB::$rssItemCalls, 'a non-rss strategy must not gain feed items');
        $this->assertCount(0, PCM_DB::$updateStrategyCalls, 'no watcher state must be written');
        $this->assertCount(0, PCM_Test_Cron::$scheduleCalls, 'no queue continuation must be armed');
    }

    public function test_run_rss_first_scan_is_a_silent_noop_for_a_missing_or_foreign_strategy(): void
    {
        PCM_DB::$strategyRow = null; // ownership-checked getter finds nothing

        PCM_Strategy_Service::run_rss_first_scan(999, 42);

        $this->assertCount(0, PCM_DB::$rssItemCalls);
        $this->assertCount(0, PCM_DB::$updateStrategyCalls);
        $this->assertCount(0, PCM_Test_Cron::$scheduleCalls);
    }

    // ── (4) run_rss_first_scan: the positive full pass ───────────────────

    public function test_run_rss_first_scan_pops_the_queued_feed_item_into_a_pending_strategy_item(): void
    {
        // The feed-fetch edge is absent in unit tests (fetch_feed()/WPINC
        // undefined → fetch_rss_feed_items() returns array()), so the pass
        // runs pure over the config's own rssQueue — seed it with the feed's
        // latest existing post and watch the scan turn it into an item.
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'userId' => 42, 'status' => 'pending',
            'config' => json_encode(array(
                'sourceMode' => 'rss',
                'rssFeeds'   => array('https://feed.example/rss'),
                'rssSeen'    => array(md5('https://feed.example/fresh')),
                'rssQueue'   => array(array(
                    'guid'  => md5('https://feed.example/fresh'),
                    'title' => 'Fresh Post',
                    'link'  => 'https://feed.example/fresh',
                    'ts'    => 1000,
                )),
            )),
        );

        PCM_Strategy_Service::run_rss_first_scan(7, 42);

        $this->assertCount(1, PCM_DB::$rssItemCalls, 'the queued feed item must become a strategy item immediately');
        $this->assertSame(7, PCM_DB::$rssItemCalls[0]['strategyId']);
        $this->assertSame(42, PCM_DB::$rssItemCalls[0]['userId']);
        $this->assertSame('Fresh Post', PCM_DB::$rssItemCalls[0]['keyword'], 'keyword = the feed item title');
        $this->assertSame('https://feed.example/fresh', PCM_DB::$rssItemCalls[0]['config']['sourceLink'] ?? null);
        // The watcher state write-back: the popped entry left the queue.
        $this->assertNotCount(0, PCM_DB::$updateStrategyCalls, 'the shrunken rssQueue must persist back onto the config');
        $written = json_decode((string)PCM_DB::$updateStrategyCalls[0]['data']['config'], true);
        $this->assertSame(array(), $written['rssQueue'], 'the popped entry must leave the queue');
    }
}
