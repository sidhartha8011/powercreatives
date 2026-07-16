<?php
/**
 * Unit Tests — the parent link on a CONSOLIDATED strategy (ONE article for
 * every keyword). Two paths, both new in this step:
 *   (a) generate_consolidated_batch() injects the deterministic parent-link
 *       paragraph into the single shared article (children_only → external URL).
 *   (b) reapply_parent_links() dedupes by articleId, so a consolidated
 *       strategy whose N items all share ONE articleId touches that article
 *       exactly once (no inflated counts, no wasteful repeat writes).
 *
 * Exercises the REAL PCM_Strategy_Service against the shared strategy fakes.
 * Same process-isolation contract as the sibling suites (shared class NAMES
 * with real classmapped services → @runTestsInSeparateProcesses, fakes behind
 * class_exists(..., false) guards, declared via pcm_test_define_strategy_fakes).
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyConsolidatedParentLinkTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_strategy_fakes();
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';

        PCM_DB::$items = array();
        PCM_DB::$articles = array();
        PCM_DB::$articleSeq = 100;
        PCM_DB::$strategyRow = null;
        PCM_DB::$site = null;
        PCM_DB::$forceNullArticle = false;
        PCM_Test_Cron::$scheduleCalls = array();
        PCM_Test_Cron::$alreadyScheduled = true; // no continuation needed here
        PCM_Test_Cron::$now = '2026-07-14 09:00:00';
    }

    /** A consolidated strategy row (structure=consolidated) with the given hierarchy config. */
    private function consolidatedStrategy(string $mode, array $config): object
    {
        return (object) array(
            'id'             => 7,
            'status'         => 'pending',
            'templateId'     => 5,
            'brandId'        => null,
            'hierarchyMode'  => $mode,
            'publishingMode' => 'draft',
            'totalItems'     => 0,
            'config'         => json_encode(array_merge(array('structure' => 'consolidated'), $config)),
        );
    }

    private function seedItems(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            PCM_DB::$items[$i] = (object) array(
                'id' => $i, 'strategyId' => 7, 'keyword' => 'kw ' . $i,
                'status' => 'pending', 'position' => $i - 1, 'articleId' => null, 'errorMessage' => '',
            );
        }
    }

    /**
     * (a) Consolidated + children_only generation bakes the deterministic
     * parent-link paragraph (with the configured external URL) into the single
     * shared article.
     */
    public function test_consolidated_children_only_generation_injects_parent_link(): void
    {
        $this->seedItems(3);
        $strategy = $this->consolidatedStrategy('children_only', array(
            'parentTargetUrl' => 'https://example.com/pillar',
        ));

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        // One article was created for the whole batch (fake ids start at 100).
        $this->assertCount(1, PCM_DB::$articles);
        $content = (string) PCM_DB::$articles[100]['content'];
        $this->assertStringContainsString('<p>Learn more in', $content);
        $this->assertStringContainsString('href="https://example.com/pillar"', $content);
        // Exactly one injected paragraph on the single shared article.
        $this->assertSame(1, substr_count($content, 'Learn more in'));
    }

    /**
     * (a cont'd) A consolidated strategy with no parent URL (children_only but
     * unconfigured) generates the article WITHOUT any parent-link paragraph —
     * resolve_parent_link() returns null and injection is skipped.
     */
    public function test_consolidated_without_parent_url_injects_nothing(): void
    {
        $this->seedItems(2);
        $strategy = $this->consolidatedStrategy('children_only', array());

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $content = (string) PCM_DB::$articles[100]['content'];
        $this->assertStringNotContainsString('Learn more in', $content);
    }

    /**
     * (a cont'd) A consolidated parent_only strategy — the single consolidated
     * article IS the pillar/parent, so nothing links above it. Generation
     * produces the article WITHOUT any deterministic parent-link paragraph:
     * resolve_parent_link() falls through its default-null path for parent_only
     * and injection is skipped. parentTargetUrl is present but irrelevant here.
     */
    public function test_consolidated_parent_only_generation_injects_nothing(): void
    {
        $this->seedItems(3);
        $strategy = $this->consolidatedStrategy('parent_only', array(
            'parentTargetUrl' => 'https://example.com/pillar',
        ));

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        // One shared article, and no injected parent-link paragraph.
        $this->assertCount(1, PCM_DB::$articles);
        $content = (string) PCM_DB::$articles[100]['content'];
        $this->assertStringNotContainsString('Learn more in', $content);
    }

    /**
     * (b) Reapply on a consolidated strategy: 3 items all share ONE articleId,
     * so the article is touched exactly once — counts reflect 1 and the content
     * carries exactly one link paragraph (not three stacked/rewritten passes).
     */
    public function test_reapply_consolidated_touches_shared_article_once(): void
    {
        PCM_DB::$strategyRow = (object) array(
            'id'            => 7,
            'status'        => 'completed',
            'hierarchyMode' => 'children_only',
            'config'        => json_encode(array(
                'structure'       => 'consolidated',
                'parentTargetUrl' => 'https://new.example/pillar',
            )),
        );
        // ONE article, shared by every item.
        PCM_DB::$articles[101] = array('id' => 101, 'content' => '<p>Shared body.</p>', 'slug' => 'shared');
        for ($i = 1; $i <= 3; $i++) {
            PCM_DB::$items[$i] = (object) array(
                'id' => $i, 'keyword' => 'kw ' . $i, 'status' => 'completed',
                'articleId' => 101, 'position' => $i - 1,
            );
        }

        $out = PCM_Strategy_Service::reapply_parent_links(7, 1);

        // The single distinct article was updated once; the two duplicate items
        // bumped no counter (dedup, not skip).
        $this->assertSame(1, $out['updated']);
        $this->assertSame(0, $out['cleared']);
        $this->assertSame(0, $out['skipped']);

        $content = (string) PCM_DB::$articles[101]['content'];
        $this->assertSame(1, substr_count($content, 'Learn more in'));
        $this->assertStringContainsString('href="https://new.example/pillar"', $content);
    }
}
