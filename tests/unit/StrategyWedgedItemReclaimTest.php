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
            /** @var object[] Rows get_stale_generating_strategies() returns. */
            public static $staleStrategies = array();
            /** @var string The cutoff the sweep passed to the query. */
            public static $cutoffSeen = '';
            /** @var array<int,array> Every reclaim_stale_generating() call. */
            public static $reclaimCalls = array();
            /** @var array<int,int> Rows freed per strategy id (default 1). */
            public static $reclaimReturns = array();
            /** @var int[] Strategy ids whose reclaim must throw. */
            public static $reclaimThrowsFor = array();
            /** @var object|null What get_next_pending_item() returns. */
            public static $nextPendingItem = null;
            /** @var object|null What get_strategy() returns (paused check). */
            public static $strategyRow = null;

            public static function get_stale_generating_strategies($cutoff)
            {
                self::$cutoffSeen = $cutoff;
                return self::$staleStrategies;
            }
            public static function reclaim_stale_generating($sid, $minutes = 10)
            {
                self::$reclaimCalls[] = array('strategyId' => $sid, 'minutes' => $minutes);
                if (in_array((int) $sid, self::$reclaimThrowsFor, true)) {
                    throw new \RuntimeException('db exploded');
                }
                return self::$reclaimReturns[(int) $sid] ?? 1;
            }
            public static function get_strategy($id, $uid)
            {
                return self::$strategyRow;
            }
            public static function get_next_pending_item($sid)
            {
                return self::$nextPendingItem;
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

        PCM_DB::$staleStrategies   = array();
        PCM_DB::$cutoffSeen        = '';
        PCM_DB::$reclaimCalls      = array();
        PCM_DB::$reclaimReturns    = array();
        PCM_DB::$reclaimThrowsFor  = array();
        // A pending item + non-paused strategy so the re-arm can proceed.
        PCM_DB::$nextPendingItem   = (object) array('id' => 5, 'scheduledDate' => null);
        PCM_DB::$strategyRow       = (object) array('id' => 3, 'status' => 'in_progress');

        PCM_Test_Cron::$scheduleCalls    = array();
        PCM_Test_Cron::$alreadyScheduled = false;
        PCM_Test_Cron::$now              = '2026-07-21 12:00:00';
        $GLOBALS['pcm_test_options']     = array();
    }

    public function test_stale_strategy_is_reclaimed_and_its_queue_rearmed(): void
    {
        PCM_DB::$staleStrategies = array((object) array('strategyId' => 3, 'userId' => 9));

        PCM_Strategy_Service::reclaim_wedged_items();

        $this->assertCount(1, PCM_DB::$reclaimCalls, 'the stale strategy must be reclaimed exactly once');
        $this->assertSame(3, (int) PCM_DB::$reclaimCalls[0]['strategyId'], 'the reclaim must target the strategy the query returned');
        $this->assertCount(1, PCM_Test_Cron::$scheduleCalls, 'the freed item must get a queue continuation');
        $this->assertSame('pcm_strategy_process_queue', PCM_Test_Cron::$scheduleCalls[0]['hook']);
        $this->assertSame(array(3, 9), PCM_Test_Cron::$scheduleCalls[0]['args']);
    }

    public function test_nothing_stale_is_a_silent_no_op(): void
    {
        PCM_DB::$staleStrategies = array();

        PCM_Strategy_Service::reclaim_wedged_items();

        $this->assertSame(array(), PCM_DB::$reclaimCalls, 'no stale strategies ⇒ no reclaim attempted');
        $this->assertCount(0, PCM_Test_Cron::$scheduleCalls, 'an idle site must not be churned with cron events');
    }

    public function test_reclaim_freeing_zero_rows_does_not_rearm(): void
    {
        // Another worker resolved the item between the query and the UPDATE —
        // the strategy is healthy, so the sweep must not schedule anything.
        PCM_DB::$staleStrategies = array((object) array('strategyId' => 3, 'userId' => 9));
        PCM_DB::$reclaimReturns  = array(3 => 0);

        PCM_Strategy_Service::reclaim_wedged_items();

        $this->assertCount(1, PCM_DB::$reclaimCalls, 'the reclaim is still attempted');
        $this->assertCount(0, PCM_Test_Cron::$scheduleCalls, 'freeing 0 rows means the strategy was already healthy — do not re-arm');
    }

    public function test_cutoff_is_90_minutes_and_the_select_and_update_windows_agree(): void
    {
        PCM_DB::$staleStrategies = array((object) array('strategyId' => 3, 'userId' => 9));

        PCM_Strategy_Service::reclaim_wedged_items();

        // 90, deliberately NOT the per-strategy path's 10: the measured
        // worst case for one item is ~39 min on the default providers (two
        // 300s LLM calls, grounding passes, Apify enrich, featured image and
        // up to 8 in-content media at 120s each), and a reclaim does not
        // cancel the run it steals from — so a short cutoff throws away a
        // generation that was about to land.
        $this->assertSame('2026-07-21 10:30:00', PCM_DB::$cutoffSeen);
        // The SELECT window and the UPDATE window must be the same number of
        // minutes, or the query would hand back rows the reclaim then refuses
        // to free (a silent no-op sweep that never un-wedges anything).
        $this->assertSame(90, (int) PCM_DB::$reclaimCalls[0]['minutes']);
    }

    public function test_paused_strategy_is_not_rearmed(): void
    {
        // D2: the SQL already filters paused strategies, but a pause landing
        // between the query and the re-arm must not resurrect work either.
        PCM_DB::$staleStrategies = array((object) array('strategyId' => 3, 'userId' => 9));
        PCM_DB::$strategyRow     = (object) array('id' => 3, 'status' => 'paused');

        PCM_Strategy_Service::reclaim_wedged_items();

        $this->assertCount(1, PCM_DB::$reclaimCalls, 'the row is still un-wedged');
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
        PCM_DB::$staleStrategies  = array(
            (object) array('strategyId' => 3, 'userId' => 9),
            (object) array('strategyId' => 4, 'userId' => 9),
        );
        PCM_DB::$reclaimThrowsFor = array(3);

        PCM_Strategy_Service::reclaim_wedged_items();

        $this->assertCount(2, PCM_DB::$reclaimCalls, 'strategy #4 must still be swept');
        $this->assertCount(1, PCM_Test_Cron::$scheduleCalls, 'only the surviving strategy re-arms');
        $this->assertSame(array(4, 9), PCM_Test_Cron::$scheduleCalls[0]['args']);
    }
}
