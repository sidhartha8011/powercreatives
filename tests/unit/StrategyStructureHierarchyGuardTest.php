<?php
/**
 * Unit Tests — server-side guard: a 'consolidated' structure (one article for
 * every keyword) can never carry a hierarchy between the strategy's own
 * articles.
 *
 * Exercises PCM_Strategy_Service::create_from_keywords() (the single funnel
 * for every strategy-creation path — REST create, duplicate_strategy(), and
 * the per-site schedule scanner) against the shared strategy fakes, plus the
 * pure PCM_Strategy_Service::apply_structure_hierarchy_guard() helper that
 * PCM_REST_Strategy::update_strategy() also calls on its PATCH door. The
 * REST controller itself isn't unit-testable here (no WP_REST_Request stand-
 * in in this suite) — its guard call is covered by code review, same as
 * sibling suites that can't reach a REST handler directly.
 *
 * Same process-isolation contract as the sibling suites (shared class NAMES
 * with real classmapped services → @runTestsInSeparateProcesses, fakes behind
 * class_exists(..., false) guards, declared via top-level functions invoked
 * from setUp()).
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyStructureHierarchyGuardTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_strategy_fakes();

        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';

        PCM_DB::$items = array();
        PCM_DB::$strategy = array();
        PCM_DB::$strategyRow = (object) array('id' => 7, 'status' => 'pending', 'config' => null, 'hierarchyMode' => 'standalone');
        PCM_DB::$createStrategyData = null;
        PCM_Test_Cron::$scheduleCalls = array();
        PCM_Test_Cron::$alreadyScheduled = true; // no continuation needed for these assertions
        PCM_Test_Cron::$now = '2026-07-14 09:00:00';
    }

    /**
     * (a) consolidated + parent_and_children → downgraded to parent_only on
     * create (the single consolidated article IS the pillar, with no outbound
     * parent link); only parentKeyword (the item-designation key) is dropped,
     * while the external parentTargetUrl and the parentAnchorKeyword anchor
     * override survive untouched.
     */
    public function test_create_consolidated_downgrades_parent_and_children_to_parent_only(): void
    {
        PCM_Strategy_Service::create_from_keywords(
            1,
            'Guard Test',
            5,
            null,
            array('kw one', 'kw two'),
            array(
                'hierarchyMode'  => 'parent_and_children',
                'publishingMode' => 'draft',
                'config'         => array(
                    'structure'           => 'consolidated',
                    'parentTargetUrl'     => 'https://example.com/parent',
                    'parentKeyword'       => 'parent keyword',
                    'parentAnchorKeyword' => 'anchor text',
                    'model'               => 'gpt-x',
                ),
            )
        );

        $this->assertNotNull(PCM_DB::$createStrategyData);
        $this->assertSame('parent_only', PCM_DB::$createStrategyData['hierarchyMode']);

        $stored_config = json_decode((string) PCM_DB::$createStrategyData['config'], true);
        $this->assertSame('consolidated', $stored_config['structure']);
        // The item-designation key is meaningless with one article → dropped.
        $this->assertArrayNotHasKey('parentKeyword', $stored_config);
        // The external target URL + anchor override are kept untouched.
        $this->assertSame('https://example.com/parent', $stored_config['parentTargetUrl']);
        $this->assertSame('anchor text', $stored_config['parentAnchorKeyword']);
        // Unrelated config keys survive the guard untouched.
        $this->assertSame('gpt-x', $stored_config['model']);
    }

    /**
     * (a cont'd) consolidated + parent_only passes through untouched — the
     * single consolidated article legitimately IS the pillar (no link above
     * it), so nothing is downgraded and no keys are dropped.
     */
    public function test_create_consolidated_parent_only_passes_through(): void
    {
        PCM_Strategy_Service::create_from_keywords(
            1,
            'Guard Test Parent Only',
            5,
            null,
            array('kw one', 'kw two'),
            array(
                'hierarchyMode'  => 'parent_only',
                'publishingMode' => 'draft',
                'config'         => array(
                    'structure'           => 'consolidated',
                    'parentTargetUrl'     => 'https://example.com/parent',
                    'parentAnchorKeyword' => 'anchor text',
                ),
            )
        );

        $this->assertNotNull(PCM_DB::$createStrategyData);
        $this->assertSame('parent_only', PCM_DB::$createStrategyData['hierarchyMode']);

        $stored_config = json_decode((string) PCM_DB::$createStrategyData['config'], true);
        $this->assertSame('consolidated', $stored_config['structure']);
        $this->assertSame('https://example.com/parent', $stored_config['parentTargetUrl']);
        $this->assertSame('anchor text', $stored_config['parentAnchorKeyword']);
    }

    /**
     * (a cont'd) consolidated + children_only passes through untouched — the
     * single article legitimately links to an external parent, so nothing is
     * downgraded and no keys are dropped.
     */
    public function test_create_consolidated_children_only_passes_through(): void
    {
        PCM_Strategy_Service::create_from_keywords(
            1,
            'Guard Test Children Only',
            5,
            null,
            array('kw one', 'kw two'),
            array(
                'hierarchyMode'  => 'children_only',
                'publishingMode' => 'draft',
                'config'         => array(
                    'structure'           => 'consolidated',
                    'parentTargetUrl'     => 'https://example.com/parent',
                    'parentAnchorKeyword' => 'anchor text',
                ),
            )
        );

        $this->assertNotNull(PCM_DB::$createStrategyData);
        $this->assertSame('children_only', PCM_DB::$createStrategyData['hierarchyMode']);

        $stored_config = json_decode((string) PCM_DB::$createStrategyData['config'], true);
        $this->assertSame('consolidated', $stored_config['structure']);
        $this->assertSame('https://example.com/parent', $stored_config['parentTargetUrl']);
        $this->assertSame('anchor text', $stored_config['parentAnchorKeyword']);
    }

    /** (b) individual structure → hierarchy + parent keys pass through untouched. */
    public function test_create_individual_structure_passes_hierarchy_through_untouched(): void
    {
        PCM_Strategy_Service::create_from_keywords(
            1,
            'Guard Test Individual',
            5,
            null,
            array('kw one'),
            array(
                'hierarchyMode'  => 'parent_and_children',
                'publishingMode' => 'draft',
                'config'         => array(
                    'structure'           => 'individual',
                    'parentTargetUrl'     => 'https://example.com/parent',
                    'parentKeyword'       => 'parent keyword',
                    'parentAnchorKeyword' => 'anchor text',
                ),
            )
        );

        $this->assertNotNull(PCM_DB::$createStrategyData);
        $this->assertSame('parent_and_children', PCM_DB::$createStrategyData['hierarchyMode']);

        $stored_config = json_decode((string) PCM_DB::$createStrategyData['config'], true);
        $this->assertSame('individual', $stored_config['structure']);
        $this->assertSame('https://example.com/parent', $stored_config['parentTargetUrl']);
        $this->assertSame('parent keyword', $stored_config['parentKeyword']);
        $this->assertSame('anchor text', $stored_config['parentAnchorKeyword']);
    }

    /**
     * (c) The PATCH door: PCM_REST_Strategy::update_strategy() funnels through
     * the same pure guard before persisting. The REST handler itself isn't
     * reachable from this unit suite (no WP_REST_Request stand-in here, same
     * limitation as the sibling suites) — so this exercises the guard
     * directly with the EFFECTIVE (already-merged) values the controller
     * would compute, which is the exact contract the controller relies on.
     */
    public function test_patch_door_guard_downgrades_and_drops_only_parent_keyword(): void
    {
        $result = PCM_Strategy_Service::apply_structure_hierarchy_guard(
            'parent_and_children',
            array(
                'structure'           => 'consolidated',
                'parentTargetUrl'     => 'https://example.com/parent',
                'parentKeyword'       => 'parent keyword',
                'parentAnchorKeyword' => 'anchor text',
                'siteId'              => 42,
            )
        );

        $this->assertSame('parent_only', $result['hierarchyMode']);
        // Only the item-designation key is dropped.
        $this->assertArrayNotHasKey('parentKeyword', $result['config']);
        // External URL + anchor override + unrelated keys survive.
        $this->assertSame('https://example.com/parent', $result['config']['parentTargetUrl']);
        $this->assertSame('anchor text', $result['config']['parentAnchorKeyword']);
        $this->assertSame(42, $result['config']['siteId']);
    }

    /** (c cont'd) consolidated + children_only through the guard is a no-op. */
    public function test_patch_door_guard_is_noop_for_consolidated_children_only(): void
    {
        $config = array(
            'structure'           => 'consolidated',
            'parentTargetUrl'     => 'https://example.com/parent',
            'parentAnchorKeyword' => 'anchor text',
        );
        $result = PCM_Strategy_Service::apply_structure_hierarchy_guard('children_only', $config);

        $this->assertSame('children_only', $result['hierarchyMode']);
        $this->assertSame($config, $result['config']);
    }

    /** (c cont'd) consolidated + parent_only through the guard is a no-op. */
    public function test_patch_door_guard_is_noop_for_consolidated_parent_only(): void
    {
        $config = array(
            'structure'           => 'consolidated',
            'parentTargetUrl'     => 'https://example.com/parent',
            'parentAnchorKeyword' => 'anchor text',
        );
        $result = PCM_Strategy_Service::apply_structure_hierarchy_guard('parent_only', $config);

        $this->assertSame('parent_only', $result['hierarchyMode']);
        $this->assertSame($config, $result['config']);
    }

    /** (c cont'd) Non-consolidated structure through the PATCH-door guard is a no-op. */
    public function test_patch_door_guard_is_noop_for_individual_structure(): void
    {
        $config = array(
            'structure'       => 'individual',
            'parentTargetUrl' => 'https://example.com/parent',
        );
        $result = PCM_Strategy_Service::apply_structure_hierarchy_guard('children_only', $config);

        $this->assertSame('children_only', $result['hierarchyMode']);
        $this->assertSame($config, $result['config']);
    }
}
