<?php
/**
 * Unit Tests — Strategy lifecycle: auto-start background generation on
 * creation (Task E2) and the completion-notification guard (Task E3).
 *
 * Reuses pcm_test_define_strategy_fakes() and the PCM_DB/PCM_LLM/etc. stand-ins
 * declared in StrategyAutoPublishTest.php (see that file's own docblock for why
 * @runTestsInSeparateProcesses is required — the same reasoning applies here:
 * these fakes share class names with the real, composer-classmapped services).
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyLifecycleTest extends \PHPUnit\Framework\TestCase
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
        PCM_DB::$loseGenerationClaims = 0;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function seedItems(array $items): void
    {
        foreach ($items as $it) {
            $o = (object)$it;
            PCM_DB::$items[$o->id] = $o;
        }
    }

    // ── E2: create_from_keywords auto-arms the background queue ────────────

    public function test_draft_mode_creation_arms_the_background_queue_continuation(): void
    {
        PCM_DB::$createStrategyId = 7;
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'name' => 'My Strategy', 'templateId' => 3, 'brandId' => null,
            'config' => null, 'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0,
            'status' => 'pending',
        );

        $strategy = PCM_Strategy_Service::create_from_keywords(1, 'My Strategy', 3, null, array('kw one', 'kw two'));

        $this->assertIsArray($strategy);
        $this->assertCount(1, PCM_Test_Cron::$scheduleCalls, 'a non-scheduled creation must arm exactly one continuation');
        $this->assertSame('pcm_strategy_process_queue', PCM_Test_Cron::$scheduleCalls[0]['hook']);
        $this->assertSame(array(7, 1), PCM_Test_Cron::$scheduleCalls[0]['args']);
    }

    public function test_schedule_mode_creation_does_not_arm_the_background_queue(): void
    {
        PCM_DB::$createStrategyId = 7;
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'name' => 'Scheduled Strategy', 'templateId' => 3, 'brandId' => null,
            'config' => null, 'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0,
            'status' => 'pending',
        );

        PCM_Strategy_Service::create_from_keywords(
            1,
            'Scheduled Strategy',
            3,
            null,
            array('kw one', 'kw two'),
            array('publishingMode' => 'schedule')
        );

        $this->assertSame(
            array(),
            PCM_Test_Cron::$scheduleCalls,
            'scheduled strategies start via run_scheduled_scan() on their own due dates, not immediately'
        );
    }

    // ── E3: completion notification guard ───────────────────────────────────

    public function test_completing_the_last_item_transitions_to_completed_without_fatal(): void
    {
        // No PCM_Automation_Engine class exists anywhere in this test process —
        // proves finalize_on_completion()'s class_exists() guard actually holds
        // (a naive unguarded fire_trigger() call here would fatal the process).
        $this->assertFalse(class_exists('PCM_Automation_Engine', false));

        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array(
            'id' => 7, 'name' => 'Finishing Strategy', 'templateId' => 3, 'brandId' => null,
            'config' => null, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );
        // recompute_counters() reads the PRE-update row via get_strategy() to
        // detect the one-time transition into 'completed' — seed it as
        // 'in_progress' so finalize_on_completion() actually fires.
        PCM_DB::$strategyRow = (object)array_merge((array)$strategy, array('status' => 'in_progress'));

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertSame('completed', PCM_DB::$items[1]->status);
        $this->assertSame('completed', PCM_DB::$strategy['status'] ?? null);
    }
}
