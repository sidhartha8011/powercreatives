<?php
/**
 * Unit Tests — re-applying the parent link across already-generated articles
 * ("change the parent after generation").
 *
 * Exercises the REAL PCM_Strategy_Service::reapply_parent_links() against the
 * shared strategy fakes. Focus: surgical strip of the deterministic
 * inject_parent_link() paragraph, role swaps when a different item is promoted
 * to parent, degradation to a pure strip when nothing resolves (standalone /
 * unconfigured), and idempotency.
 *
 * Same process-isolation contract as the sibling suites.
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyParentReapplyTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_strategy_fakes();
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';

        PCM_DB::$items = array();
        PCM_DB::$articles = array();
        PCM_DB::$strategyRow = null;
        PCM_DB::$site = null;
        PCM_DB::$forceNullArticle = false;
    }

    /** Build a strategy row + N items with stored articles. */
    private function seed(string $mode, array $config, array $items): void
    {
        PCM_DB::$strategyRow = (object) array(
            'id'            => 7,
            'status'        => 'completed',
            'hierarchyMode' => $mode,
            'config'        => json_encode($config),
        );
        $aid = 100;
        foreach ($items as $i => $spec) {
            $article_id = null;
            if (array_key_exists('content', $spec)) {
                $article_id = ++$aid;
                PCM_DB::$articles[$article_id] = array(
                    'id'           => $article_id,
                    'content'      => $spec['content'],
                    'slug'         => $spec['slug'] ?? ('slug-' . $i),
                    'publishedUrl' => $spec['publishedUrl'] ?? null,
                );
            }
            PCM_DB::$items[$i + 1] = (object) array(
                'id'        => $i + 1,
                'keyword'   => $spec['keyword'],
                'status'    => $article_id ? 'completed' : 'pending',
                'articleId' => $article_id,
                'position'  => $i,
            );
        }
    }

    private function articleContent(int $id): string
    {
        return (string) PCM_DB::$articles[$id]['content'];
    }

    public function test_children_only_url_change_replaces_old_link(): void
    {
        $old = '<p>Body A.</p><p>Learn more in our parent guide: <a href="https://old.example/pillar">our parent guide</a>.</p>';
        $this->seed('children_only', array('parentTargetUrl' => 'https://new.example/pillar'), array(
            array('keyword' => 'kw a', 'content' => $old),
            array('keyword' => 'kw b', 'content' => '<p>Body B.</p>'), // never had a link
        ));

        $out = PCM_Strategy_Service::reapply_parent_links(7, 1);

        $this->assertSame(2, $out['updated']);
        $a = $this->articleContent(101);
        $this->assertStringNotContainsString('old.example', $a);
        $this->assertStringContainsString('href="https://new.example/pillar"', $a);
        // Exactly one link paragraph — the old one was stripped, not stacked.
        $this->assertSame(1, substr_count($a, 'Learn more in'));
        $this->assertStringContainsString('href="https://new.example/pillar"', $this->articleContent(102));
    }

    public function test_parent_switch_swaps_roles(): void
    {
        // Old parent was "alpha" (article link-free); "beta"'s article linked to alpha.
        // Config now designates "beta" as the parent.
        $this->seed('parent_and_children', array('parentKeyword' => 'beta'), array(
            array('keyword' => 'alpha', 'content' => '<p>Alpha body.</p>', 'slug' => 'alpha-post'),
            array('keyword' => 'beta', 'content' => '<p>Beta body.</p><p>Learn more in alpha: <a href="/alpha-post">alpha</a>.</p>', 'slug' => 'beta-post', 'publishedUrl' => 'https://site.example/beta-post'),
        ));

        $out = PCM_Strategy_Service::reapply_parent_links(7, 1);

        // beta (new parent): old link stripped, nothing re-added.
        $beta = $this->articleContent(102);
        $this->assertStringNotContainsString('Learn more in', $beta);
        // alpha (now a child): gains a link to beta's published URL.
        $alpha = $this->articleContent(101);
        $this->assertStringContainsString('href="https://site.example/beta-post"', $alpha);
        $this->assertSame(1, $out['updated']);
        $this->assertSame(1, $out['cleared']);
    }

    public function test_switch_to_standalone_strips_all_links(): void
    {
        $linked = '<p>Body.</p><p>Learn more in guide: <a href="https://x.example/g">guide</a>.</p>';
        $this->seed('standalone', array(), array(
            array('keyword' => 'kw a', 'content' => $linked),
            array('keyword' => 'kw b', 'content' => $linked),
        ));

        $out = PCM_Strategy_Service::reapply_parent_links(7, 1);

        $this->assertSame(2, $out['cleared']);
        $this->assertStringNotContainsString('Learn more in', $this->articleContent(101));
        $this->assertStringNotContainsString('<a href', $this->articleContent(102));
    }

    public function test_idempotent_second_run_touches_nothing(): void
    {
        $this->seed('children_only', array('parentTargetUrl' => 'https://new.example/p'), array(
            array('keyword' => 'kw a', 'content' => '<p>Body.</p>'),
        ));

        PCM_Strategy_Service::reapply_parent_links(7, 1);
        $after_first = $this->articleContent(101);
        $out2 = PCM_Strategy_Service::reapply_parent_links(7, 1);

        $this->assertSame(0, $out2['updated'] + $out2['cleared']);
        $this->assertSame(1, $out2['skipped']);
        $this->assertSame($after_first, $this->articleContent(101));
    }

    public function test_items_without_articles_are_skipped(): void
    {
        $this->seed('children_only', array('parentTargetUrl' => 'https://new.example/p'), array(
            array('keyword' => 'kw a', 'content' => '<p>Body.</p>'),
            array('keyword' => 'kw pending'), // no article
        ));

        $out = PCM_Strategy_Service::reapply_parent_links(7, 1);

        $this->assertSame(1, $out['updated']);
        $this->assertSame(1, $out['skipped']);
    }

    public function test_anchor_override_is_used_on_reapply(): void
    {
        $this->seed('children_only', array(
            'parentTargetUrl'     => 'https://new.example/p',
            'parentAnchorKeyword' => 'the master guide',
        ), array(
            array('keyword' => 'kw a', 'content' => '<p>Body.</p>'),
        ));

        PCM_Strategy_Service::reapply_parent_links(7, 1);

        $this->assertStringContainsString('>the master guide</a>', $this->articleContent(101));
    }
}
