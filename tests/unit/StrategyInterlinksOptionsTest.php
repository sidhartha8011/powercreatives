<?php
/**
 * Unit Tests — Step C1: interlinks options (maxLinks / maxLinksPerArticle /
 * manualRules / aiAnchors plumbing) on PCM_Strategy_Service::run_interlinks()
 * / maybe_inject_interlinks().
 *
 * Reuses StrategyAutoPublishTest.php's fakes (PCM_DB / PCM_LLM /
 * PCM_Sites_Service / PCM_Schema stand-ins) rather than redeclaring them --
 * see that file's own docblock for why the fakes share class names with the
 * real, composer-classmapped services and why this class also needs
 * `@runTestsInSeparateProcesses`.
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyInterlinksOptionsTest extends \PHPUnit\Framework\TestCase
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

    public function test_manual_rule_injects_the_given_url_into_the_other_article(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'best crm software', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'cheap accounting tools', 'status' => 'completed', 'position' => 1, 'articleId' => 101),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'slug' => 'crm-guide', 'content' => '<p>We recommend a popular widget for your workflow.</p>');
        PCM_DB::$articles[101] = array('id' => 101, 'slug' => 'accounting-guide', 'content' => '<p>Some unrelated content here.</p>');
        PCM_DB::$strategyRow = (object)array('id' => 7, 'status' => 'completed', 'config' => null, 'totalItems' => 2, 'completedItems' => 2, 'failedItems' => 0);

        $result = PCM_Strategy_Service::run_interlinks(7, 1, array(
            'manualRules' => array(
                array('keyword' => 'popular widget', 'url' => 'https://example.com/widgets', 'matchType' => 'phrase'),
            ),
        ));

        $this->assertStringContainsString('https://example.com/widgets', PCM_DB::$articles[100]['content']);
        $injectedRows = array_filter($result['results'], static fn($r) => $r['status'] === 'injected');
        $this->assertNotEmpty($injectedRows, 'must record an injected row for the manual rule match');
        $found = false;
        foreach ($injectedRows as $row) {
            if ($row['target'] === 'popular widget') {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function test_max_links_per_article_caps_inbound_links_to_a_shared_target(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'completed', 'position' => 1, 'articleId' => 101),
            array('id' => 3, 'keyword' => 'kw three', 'status' => 'completed', 'position' => 2, 'articleId' => 102),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'slug' => 'a', 'content' => '<p>Read our shared target guide today.</p>');
        PCM_DB::$articles[101] = array('id' => 101, 'slug' => 'b', 'content' => '<p>Also see our shared target guide today.</p>');
        PCM_DB::$articles[102] = array('id' => 102, 'slug' => 'c', 'content' => '<p>Check the shared target guide today too.</p>');
        PCM_DB::$strategyRow = (object)array('id' => 7, 'status' => 'completed', 'config' => null, 'totalItems' => 3, 'completedItems' => 3, 'failedItems' => 0);

        $result = PCM_Strategy_Service::run_interlinks(7, 1, array(
            'maxLinksPerArticle' => 1,
            'manualRules' => array(
                array('keyword' => 'shared target guide', 'url' => 'https://example.com/shared', 'matchType' => 'phrase'),
            ),
        ));

        $this->assertSame(1, $result['injected']);
        $capRows = array_filter($result['results'], static fn($r) => $r['status'] === 'skipped' && $r['reason'] === 'target at cap');
        $this->assertCount(2, $capRows, '3 sources point at the same target with a cap of 1 -> 1 injected + 2 at-cap skips');
    }

    public function test_exact_match_type_is_case_sensitive_while_phrase_is_not(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'completed', 'position' => 1, 'articleId' => 101),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'slug' => 'a', 'content' => '<p>Our Widget Pro line is popular.</p>');
        PCM_DB::$articles[101] = array('id' => 101, 'slug' => 'b', 'content' => '<p>Unrelated content.</p>');
        PCM_DB::$strategyRow = (object)array('id' => 7, 'status' => 'completed', 'config' => null, 'totalItems' => 2, 'completedItems' => 2, 'failedItems' => 0);

        // 'widget pro' (lowercase) against '<p>Our Widget Pro line...' -- exact
        // (case-sensitive) must NOT match; phrase (case-insensitive) must.
        $exactResult = PCM_Strategy_Service::run_interlinks(7, 1, array(
            'manualRules' => array(
                array('keyword' => 'widget pro', 'url' => 'https://example.com/wp', 'matchType' => 'exact'),
            ),
        ));
        $this->assertSame(0, $exactResult['injected'], 'exact matchType is case-sensitive and must not match differently-cased text');

        $phraseResult = PCM_Strategy_Service::run_interlinks(7, 1, array(
            'manualRules' => array(
                array('keyword' => 'widget pro', 'url' => 'https://example.com/wp', 'matchType' => 'phrase'),
            ),
        ));
        $this->assertSame(1, $phraseResult['injected'], 'phrase matchType is case-insensitive');
    }

    public function test_response_shape_has_injected_int_and_results_rows_with_source_target_status_reason(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'best crm software', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'cheap accounting tools', 'status' => 'completed', 'position' => 1, 'articleId' => 101),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'slug' => 'crm-guide', 'content' => '<p>We compare cheap accounting tools here too.</p>');
        PCM_DB::$articles[101] = array('id' => 101, 'slug' => 'accounting-guide', 'content' => '<p>Check out our best crm software list.</p>');
        PCM_DB::$strategyRow = (object)array('id' => 7, 'status' => 'completed', 'config' => null, 'totalItems' => 2, 'completedItems' => 2, 'failedItems' => 0);

        $result = PCM_Strategy_Service::run_interlinks(7, 1);

        $this->assertIsInt($result['injected']);
        $this->assertIsArray($result['results']);
        $this->assertNotEmpty($result['results']);
        foreach ($result['results'] as $row) {
            $this->assertArrayHasKey('source', $row);
            $this->assertArrayHasKey('target', $row);
            $this->assertArrayHasKey('status', $row);
            $this->assertArrayHasKey('reason', $row);
            $this->assertContains($row['status'], array('injected', 'skipped', 'failed'));
        }
    }

    public function test_run_interlinks_with_no_options_still_auto_injects_and_returns_new_shape(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'best crm software', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'cheap accounting tools', 'status' => 'completed', 'position' => 1, 'articleId' => 101),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'slug' => 'crm-guide', 'content' => '<p>We compare cheap accounting tools here too.</p>');
        PCM_DB::$articles[101] = array('id' => 101, 'slug' => 'accounting-guide', 'content' => '<p>Check out our best crm software list.</p>');
        PCM_DB::$strategyRow = (object)array('id' => 7, 'status' => 'completed', 'config' => null, 'totalItems' => 2, 'completedItems' => 2, 'failedItems' => 0);

        $result = PCM_Strategy_Service::run_interlinks(7, 1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('injected', $result);
        $this->assertArrayHasKey('results', $result);
        $this->assertSame(2, $result['injected'], 'backward-compat: auto mode still injects with no options passed');
        $this->assertStringContainsString('accounting-guide', PCM_DB::$articles[100]['content']);
        $this->assertStringContainsString('crm-guide', PCM_DB::$articles[101]['content']);
    }
}
