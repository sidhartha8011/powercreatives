<?php
/**
 * Unit Tests — Strategy ops bundle:
 *   - E1 duplicate_strategy() (fresh pending items, no auto-start)
 *   - D2 pause/resume (queue + scan + tick exclusions, recompute preservation)
 *   - G1 parent-anchor override in the injected parent link + config merge
 *
 * Reuses pcm_test_define_strategy_fakes() and the PCM_DB/PCM_LLM/etc. stand-ins
 * declared in StrategyAutoPublishTest.php (see that file for why
 * @runTestsInSeparateProcesses is required — the fakes share class names with
 * the real, composer-classmapped services).
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyOpsTest extends \PHPUnit\Framework\TestCase
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

    // ── E1: duplicate_strategy ───────────────────────────────────────────────

    public function test_duplicate_recreates_items_all_pending(): void
    {
        // Source has a completed + an errored item — the copy's items must be
        // fresh and pending regardless of the source's per-item states.
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'alpha', 'status' => 'completed', 'position' => 0),
            array('id' => 2, 'keyword' => 'beta', 'status' => 'error', 'position' => 1),
        ));
        $source = (object)array(
            'id' => 5, 'name' => 'Launch Plan', 'templateId' => 3, 'brandId' => null,
            'hierarchyMode' => 'standalone', 'publishingMode' => 'draft',
            'config' => null, 'totalItems' => 2,
        );
        PCM_DB::$createStrategyId = 9;
        PCM_DB::$strategyRow = (object)array(
            'id' => 9, 'name' => 'Launch Plan (copy)', 'templateId' => 3,
            'publishingMode' => 'draft', 'status' => 'pending', 'config' => null,
            'totalItems' => 2, 'completedItems' => 0, 'failedItems' => 0,
        );

        $copy = PCM_Strategy_Service::duplicate_strategy($source, 1);

        $this->assertIsArray($copy);
        $this->assertArrayHasKey('items', $copy);
        $keywords = array_map(static fn($it) => $it->keyword, $copy['items']);
        $this->assertSame(array('alpha', 'beta'), $keywords, 'items recreated from source keywords in order');
        foreach ($copy['items'] as $it) {
            $this->assertSame('pending', $it->status, 'every copied item starts pending');
        }
    }

    public function test_duplicate_does_not_autostart_generation(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'alpha', 'status' => 'pending', 'position' => 0),
        ));
        $source = (object)array(
            'id' => 5, 'name' => 'Plan', 'templateId' => 3, 'brandId' => null,
            'hierarchyMode' => 'standalone', 'publishingMode' => 'draft',
            'config' => null, 'totalItems' => 1,
        );
        PCM_DB::$createStrategyId = 9;
        PCM_DB::$strategyRow = (object)array(
            'id' => 9, 'name' => 'Plan (copy)', 'templateId' => 3,
            'publishingMode' => 'draft', 'status' => 'pending', 'config' => null,
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::duplicate_strategy($source, 1);

        // Deliberate deviation from create_from_keywords() (Task E2's auto-arm):
        // the copy waits for a manual start.
        $this->assertSame(array(), PCM_Test_Cron::$scheduleCalls, 'the copy must not arm the background queue');
        $this->assertSame(0, PCM_LLM::$callCount, 'no generation runs on duplication');
    }

    // ── D2: pause / resume ───────────────────────────────────────────────────

    public function test_paused_strategy_is_not_armed_by_scheduled_scan(): void
    {
        // A due pending item exists, but the parent strategy is paused — the
        // service-level guard in maybe_schedule_queue_continuation() must skip it
        // (the DB-layer SQL exclusion is not exercised by the fake, by design).
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'x', 'status' => 'pending', 'position' => 0,
                  'scheduledDate' => '2020-01-01 00:00:00'),
        ));
        PCM_DB::$dueScheduledStrategies = array((object)array('strategyId' => 7, 'userId' => 1));
        PCM_DB::$strategyRow = (object)array('id' => 7, 'status' => 'paused');

        PCM_Strategy_Service::run_scheduled_scan();

        $this->assertSame(array(), PCM_Test_Cron::$scheduleCalls, 'a paused strategy is never armed for continuation');
    }

    public function test_run_queue_tick_no_ops_when_paused(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'x', 'status' => 'pending', 'position' => 0),
        ));
        PCM_DB::$strategyRow = (object)array('id' => 7, 'status' => 'paused', 'templateId' => 3);

        PCM_Strategy_Service::run_queue_tick(7, 1);

        $this->assertSame(0, PCM_LLM::$callCount, 'paused → the tick generates nothing');
        $this->assertSame('pending', PCM_DB::$items[1]->status, 'the pending item is left untouched');
        $this->assertSame(array(), PCM_Test_Cron::$scheduleCalls);
    }

    public function test_recompute_counters_keeps_paused_while_items_remain(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'a', 'status' => 'completed', 'position' => 0),
            array('id' => 2, 'keyword' => 'b', 'status' => 'pending', 'position' => 1),
        ));
        // PRE-update status is 'paused'; recompute() reads it via get_strategy().
        PCM_DB::$strategyRow = (object)array('id' => 7, 'status' => 'paused');

        PCM_Strategy_Service::recompute_counters(7, 1, 2);

        // Computed status would be 'in_progress' (1 of 2 done) — pause is preserved.
        $this->assertSame('paused', PCM_DB::$strategy['status'] ?? null);
        $this->assertSame(1, PCM_DB::$strategy['completedItems'] ?? null, 'counters still recompute under a pause');
    }

    public function test_recompute_counters_completes_a_paused_strategy_when_all_done(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'a', 'status' => 'completed', 'position' => 0),
            array('id' => 2, 'keyword' => 'b', 'status' => 'completed', 'position' => 1),
        ));
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'status' => 'paused', 'name' => 'S', 'completedItems' => 2, 'totalItems' => 2,
        );

        PCM_Strategy_Service::recompute_counters(7, 1, 2);

        // A fully-completed batch escapes the pause (→ 'completed').
        $this->assertSame('completed', PCM_DB::$strategy['status'] ?? null);
    }

    // ── G1: parent-anchor override + config merge ────────────────────────────

    public function test_parent_anchor_override_is_used_in_injected_link(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'child topic', 'status' => 'pending', 'position' => 0),
        ));
        $config = json_encode(array(
            'parentTargetUrl'       => 'https://ext.example.com/hub',
            'parentAnchorKeyword'   => 'Ultimate Widget Guide',
            'allowAnchorVariations' => false,
        ));
        $strategy = (object)array(
            'id' => 7, 'name' => 'Children', 'templateId' => 3, 'brandId' => null,
            'hierarchyMode' => 'children_only', 'publishingMode' => 'draft',
            'config' => $config, 'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );
        PCM_DB::$strategyRow = $strategy;

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $article = PCM_DB::$articles[100] ?? null;
        $this->assertNotNull($article, 'the article was created');
        $content = (string)$article['content'];
        $this->assertStringContainsString('Ultimate Widget Guide', $content, 'override anchor text is used');
        $this->assertStringContainsString('href="https://ext.example.com/hub"', $content, 'link points at the parent URL');
        $this->assertStringNotContainsString('our parent guide', $content, 'the default topic text is replaced');
    }

    public function test_merge_config_passes_the_new_anchor_keys(): void
    {
        $existing = json_encode(array('siteId' => 5, 'approvalMode' => 'internal'));
        $merged = PCM_Strategy_Service::merge_strategy_config($existing, array(
            'parentAnchorKeyword'   => 'Widget Hub',
            'allowAnchorVariations' => false,
        ));

        $this->assertSame('Widget Hub', $merged['parentAnchorKeyword']);
        $this->assertFalse($merged['allowAnchorVariations']);
        // Existing unrelated keys survive the partial merge.
        $this->assertSame(5, $merged['siteId']);
        $this->assertSame('internal', $merged['approvalMode']);
    }
}
