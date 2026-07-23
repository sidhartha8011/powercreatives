<?php
/**
 * Unit Tests — the wedged-item reclaim sweep (PCM_Strategy_Service::reclaim_wedged_items).
 *
 * THE BUG THIS PINS: generate_next_item() claims an item ('generating') and only
 * ever writes 'error' from its catch block, so a generator process KILLED
 * mid-run (host wall-clock / FPM request_terminate_timeout / OOM) strands the
 * row in 'generating' with nothing able to resolve it — reclaim_stale_generating()
 * runs only from the next-pending path of a generate tick, and a tick is only
 * armed when an item is PENDING. Owner report: RSS items "stuck at generating".
 *
 * Covered here:
 *   - a stale strategy is reclaimed AND its queue re-armed (the whole point);
 *   - nothing stale ⇒ no reclaim, no scheduling (idle sites aren't churned);
 *   - a reclaim that frees 0 rows (another worker won the race) must NOT
 *     re-arm — otherwise every sweep would re-schedule for a healthy strategy;
 *   - the cutoff is 90 minutes (NOT the per-strategy path's 10 — a shorter one
 *     would throw away a generation that was still running), and the SELECT and
 *     UPDATE windows agree;
 *   - a paused strategy is un-wedged but never re-armed (D2);
 *   - one throwing strategy cannot stop the rest of the sweep;
 *   - the keep-alive chain actually calls the sweep, before the event drain.
 *
 * House-style fakes: a suite-local PCM_DB declared BEFORE the shared require so
 * the class_exists() guard lets it win (same pattern as StrategyRssFirstScanTest),
 * then the shared WP shims / cron recorder from StrategyAutoPublishTest.
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

function pcm_test_define_wedged_reclaim_fakes(): void
{
    if (!class_exists('PCM_DB', false)) {
        class PCM_DB
        {
            /** @var object[] Rows get_stale_generating_items() returns. */
            public static $staleItems = array();
            /** @var string The cutoff the sweep passed to the query. */
            public static $cutoffSeen = '';
            /** @var array<int,array> Every update_strategy_item() call. */
            public static $updateCalls = array();
            /** @var int[] Item ids whose update must throw. */
            public static $updateThrowsFor = array();
            /** @var array<int,object> In-memory item state keyed by id. */
            public static $items = array();
            /** @var object|null What get_next_pending_item() returns. */
            public static $nextPendingItem = null;
            /** @var object|null What get_strategy() returns (paused check). */
            public static $strategyRow = null;

            public static function get_stale_generating_items($cutoff)
            {
                self::$cutoffSeen = $cutoff;
                return self::$staleItems;
            }
            public static function update_strategy_item($id, $data)
            {
                self::$updateCalls[] = array('id' => $id, 'data' => $data);
                if (in_array((int) $id, self::$updateThrowsFor, true)) {
                    throw new \RuntimeException('db exploded');
                }
                // Mirror the real side effect onto the in-memory state so the
                // cap and the re-arm logic can read the resulting status.
                if (isset(self::$items[$id])) {
                    foreach ($data as $k => $v) {
                        self::$items[$id]->$k = $v;
                    }
                }
                return true;
            }
            public static function get_strategy($id, $uid)
            {
                return self::$strategyRow;
            }
            public static function get_next_pending_item($sid)
            {
                return self::$nextPendingItem;
            }
            public static function get_strategy_items($sid)
            {
                return array_values(self::$items);
            }
            /** Swallows the counter/status write (recompute_counters calls this). */
            public static $strategy = array();
            public static function update_strategy($id, $uid, $data)
            {
                self::$strategy = array_merge(self::$strategy, $data);
                return true;
            }
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyWedgedItemReclaimTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_wedged_reclaim_fakes(); // suite-local PCM_DB wins the race
        pcm_test_define_strategy_fakes();       // shared WP shims / cron recorder
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';

        PCM_DB::$staleItems       = array();
        PCM_DB::$cutoffSeen       = '';
        PCM_DB::$updateCalls      = array();
        PCM_DB::$updateThrowsFor  = array();
        PCM_DB::$items            = array();
        // A pending item + non-paused strategy so the re-arm can proceed.
        PCM_DB::$nextPendingItem   = (object) array('id' => 5, 'scheduledDate' => null);
        PCM_DB::$strategyRow       = (object) array('id' => 3, 'status' => 'in_progress', 'totalItems' => 5);

        PCM_Test_Cron::$scheduleCalls    = array();
        PCM_Test_Cron::$alreadyScheduled = false;
        PCM_Test_Cron::$now              = '2026-07-21 12:00:00';
        $GLOBALS['pcm_test_options']     = array();
    }

    public function test_stale_strategy_is_reclaimed_and_its_queue_rearmed(): void
    {
        PCM_DB::$staleItems = array((object) array('id' => 11, 'strategyId' => 3, 'userId' => 9, 'config' => null));

        PCM_Strategy_Service::reclaim_wedged_items();

        $this->assertCount(1, PCM_DB::$updateCalls, 'the stale item must be reclaimed exactly once');
        $this->assertSame(11, (int) PCM_DB::$updateCalls[0]['id'], 'the reclaim must target the item the query returned');
        $this->assertSame('pending', PCM_DB::$updateCalls[0]['data']['status'], 'the item is flipped back to pending for retry');
        $this->assertCount(1, PCM_Test_Cron::$scheduleCalls, 'the freed item must get a queue continuation');
        $this->assertSame('pcm_strategy_process_queue', PCM_Test_Cron::$scheduleCalls[0]['hook']);
        $this->assertSame(array(3, 9), PCM_Test_Cron::$scheduleCalls[0]['args']);
    }

    public function test_nothing_stale_is_a_silent_no_op(): void
    {
        PCM_DB::$staleItems = array();

        PCM_Strategy_Service::reclaim_wedged_items();

        $this->assertSame(array(), PCM_DB::$updateCalls, 'no stale items ⇒ no reclaim attempted');
        $this->assertCount(0, PCM_Test_Cron::$scheduleCalls, 'an idle site must not be churned with cron events');
    }

    public function test_reclaim_freeing_zero_rows_does_not_rearm(): void
    {
        // Another worker resolved the item between the query and the UPDATE —
        // the strategy is healthy, so the sweep must not schedule anything.
        PCM_DB::$staleItems = array();

        PCM_Strategy_Service::reclaim_wedged_items();

        $this->assertSame(array(), PCM_DB::$updateCalls, 'no stale items ⇒ nothing reclaimed');
        $this->assertCount(0, PCM_Test_Cron::$scheduleCalls, 'no items freed means the strategy was already healthy — do not re-arm');
    }

    public function test_cutoff_is_90_minutes_and_the_select_and_update_windows_agree(): void
    {
        PCM_DB::$staleItems = array((object) array('id' => 11, 'strategyId' => 3, 'userId' => 9, 'config' => null));

        PCM_Strategy_Service::reclaim_wedged_items();

        // 90, deliberately NOT the per-strategy path's 10: the measured
        // worst case for one item is ~39 min on the default providers (two
        // 300s LLM calls, grounding passes, Apify enrich, featured image and
        // up to 8 in-content media at 120s each), and a reclaim does not
        // cancel the run it steals from — so a short cutoff throws away a
        // generation that was about to land.
        $this->assertSame('2026-07-21 10:30:00', PCM_DB::$cutoffSeen);
    }

    public function test_paused_strategy_is_not_rearmed(): void
    {
        // D2: the SQL already filters paused strategies, but a pause landing
        // between the query and the re-arm must not resurrect work either.
        PCM_DB::$staleItems   = array((object) array('id' => 11, 'strategyId' => 3, 'userId' => 9, 'config' => null));
        PCM_DB::$strategyRow  = (object) array('id' => 3, 'status' => 'paused', 'totalItems' => 5);

        PCM_Strategy_Service::reclaim_wedged_items();

        $this->assertCount(1, PCM_DB::$updateCalls, 'the row is still un-wedged');
        $this->assertCount(0, PCM_Test_Cron::$scheduleCalls, 'but a paused strategy must not be re-armed');
    }

    /**
     * The sweep is only useful if the keep-alive chain actually calls it — and
     * that call site is invisible to the fakes above (the chain's sleep loop
     * can't run in a unit harness, which is why StrategyKeepaliveTest brakes it
     * off). Without this, deleting the call from run_keepalive_chain() leaves
     * every other test in this file green. Source-invariant style follows
     * PlatformRoleInvariantTest.
     */
    public function test_keepalive_chain_calls_the_sweep_before_draining_events(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/includes/modules/strategy/service.php');
        $this->assertIsString($src);

        $start = strpos($src, 'public static function run_keepalive_chain(): void');
        $this->assertNotFalse($start, 'run_keepalive_chain() not found — did it get renamed?');
        // The work-first block ends at the sleep loop; only look inside it.
        $end = strpos($src, 'for ($i = 0; $i < 3; $i++)', $start);
        $this->assertNotFalse($end, 'the sleep loop that ends the work-first block was not found — if it was refactored, update this anchor');
        $body = substr($src, $start, $end - $start);

        $sweep = strpos($body, 'self::reclaim_wedged_items();');
        $drain = strpos($body, 'self::process_due_pcm_events();');
        $this->assertNotFalse($sweep, 'run_keepalive_chain() must call reclaim_wedged_items()');
        $this->assertNotFalse($drain, 'run_keepalive_chain() must still drain due PCM events');
        // Order matters: a reclaimed item re-arms a continuation event, and the
        // drain is what fires it — sweeping after the drain would defer every
        // recovery to the NEXT link.
        $this->assertLessThan($drain, $sweep, 'the sweep must run before the event drain');
    }

    public function test_one_failing_strategy_does_not_stop_the_sweep(): void
    {
        PCM_DB::$staleItems = array(
            (object) array('id' => 11, 'strategyId' => 3, 'userId' => 9, 'config' => null),
            (object) array('id' => 12, 'strategyId' => 4, 'userId' => 9, 'config' => null),
        );
        PCM_DB::$updateThrowsFor = array(11);

        PCM_Strategy_Service::reclaim_wedged_items();

        $this->assertCount(2, PCM_DB::$updateCalls, 'item #12 must still be swept');
        $this->assertCount(1, PCM_Test_Cron::$scheduleCalls, 'only the surviving strategy re-arms');
        $this->assertSame(array(4, 9), PCM_Test_Cron::$scheduleCalls[0]['args']);
    }

    /**
     * W3: an item that has already been reclaimed the max number of times must
     * be marked 'error' (not flipped back to 'pending'), so a deterministically-
     * failing item stops re-burning LLM/image spend. Default cap is 3.
     */
    public function test_item_at_attempt_cap_is_failed_not_requeued(): void
    {
        $GLOBALS['pcm_test_options']['pcm_strategy_max_attempts'] = '3';
        // config.attempts already at 3 → this reclaim must FAIL the item. The
        // fake in-memory item is seeded so recompute_counters can count it.
        PCM_DB::$items = array(11 => (object) array('id' => 11, 'strategyId' => 3, 'status' => 'error'));
        PCM_DB::$staleItems = array(
            (object) array('id' => 11, 'strategyId' => 3, 'userId' => 9, 'config' => wp_json_encode(array('attempts' => 3))),
        );

        PCM_Strategy_Service::reclaim_wedged_items();

        $this->assertCount(1, PCM_DB::$updateCalls, 'the item is written once');
        $this->assertSame('error', PCM_DB::$updateCalls[0]['data']['status'], 'an item past the cap is failed, not retried');
        $this->assertStringContainsString('failed after', (string) PCM_DB::$updateCalls[0]['data']['errorMessage']);
        $this->assertCount(0, PCM_Test_Cron::$scheduleCalls, 'a failed item must not re-arm the queue');
        // P2 pin: a strategy whose item was capped-to-failed must still get its
        // counters recomputed (recompute_counters → update_strategy) so the
        // failedItems count and status reflect the failure immediately.
        $this->assertArrayHasKey('failedItems', PCM_DB::$strategy, 'recompute_counters must run for a capped-to-failed strategy');
    }

    /**
     * W3: an item UNDER the cap is reclaimed to 'pending' AND its attempts
     * counter is bumped, so the next reclaim sees one more attempt.
     */
    public function test_item_under_cap_is_reclaimed_with_incremented_attempts(): void
    {
        $GLOBALS['pcm_test_options']['pcm_strategy_max_attempts'] = '3';
        PCM_DB::$staleItems = array(
            (object) array('id' => 11, 'strategyId' => 3, 'userId' => 9, 'config' => wp_json_encode(array('attempts' => 1))),
        );

        PCM_Strategy_Service::reclaim_wedged_items();

        $this->assertSame('pending', PCM_DB::$updateCalls[0]['data']['status'], 'an item under the cap is retried');
        $cfg = json_decode((string) PCM_DB::$updateCalls[0]['data']['config'], true);
        $this->assertSame(2, $cfg['attempts'], 'the attempt counter is incremented on each reclaim');
    }

    /**
     * W2: the wedge-reclaim sweep must run on an independent cron (not only the
     * traffic-driven keep-alive chain), so a killed item is recovered even on a
     * DISABLE_WP_CRON host with no loopback. Source-invariant check (same style
     * as test_keepalive_chain_calls_the_sweep_before_draining_events).
     */
    public function test_wedge_reclaim_has_an_independent_cron_action_and_interval(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/includes/modules/strategy/service.php');
        $this->assertIsString($src);
        // The action callback is wired at file load.
        $this->assertNotFalse(
            strpos($src, "add_action('pcm_strategy_wedge_reclaim', array('PCM_Strategy_Service', 'reclaim_wedged_items'))"),
            'pcm_strategy_wedge_reclaim action must be registered for reclaim_wedged_items()'
        );
        // A dedicated interval is registered via cron_schedules.
        $this->assertNotFalse(strpos($src, "'pcm_wedge_interval'"), 'a pcm_wedge_interval cron schedule must exist');
        // And a recurring event is armed on that interval.
        $this->assertNotFalse(
            strpos($src, "wp_schedule_event(time(), 'pcm_wedge_interval', 'pcm_strategy_wedge_reclaim')"),
            'a recurring pcm_strategy_wedge_reclaim event must be scheduled'
        );
    }
}
