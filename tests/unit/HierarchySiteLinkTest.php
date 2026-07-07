<?php
/**
 * Unit Tests — PCM_Hierarchy Site ↔ Project link (v1.34.0).
 *
 * Covers site_for_project() (forward read of projects.siteId) and
 * projects_for_site() (reverse lookup, N:1 — several projects may connect to
 * the same site, so it always returns a list). $wpdb is mocked; the REST
 * endpoint (set_project_site ownership/404 paths) is covered by the live
 * verify step, matching how the other scope/DB-backed paths are tested.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class HierarchySiteLinkTest extends TestCase
{
    /**
     * Mock $wpdb: prepare() inlines %d args so the query-string map below can
     * route get_var/get_col results per table + id.
     *
     * @param array<string,mixed> $var_map get_var: substring => return value.
     * @param array<string,array> $col_map get_col: substring => return value.
     */
    private function mock_wpdb(array $var_map = array(), array $col_map = array()): void
    {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($q, ...$args) {
            foreach ($args as $a) {
                $q = preg_replace('/%d/', (string) (int) $a, $q, 1);
            }
            return $q;
        });
        $wpdb->shouldReceive('get_var')->andReturnUsing(function ($q) use ($var_map) {
            foreach ($var_map as $needle => $value) {
                if (strpos($q, $needle) !== false) {
                    return $value;
                }
            }
            return null;
        });
        $wpdb->shouldReceive('get_col')->andReturnUsing(function ($q) use ($col_map) {
            foreach ($col_map as $needle => $value) {
                if (strpos($q, $needle) !== false) {
                    return $value;
                }
            }
            return array();
        });
        $GLOBALS['wpdb'] = $wpdb;
    }

    public function test_site_for_project_returns_int_when_connected(): void
    {
        // wpdb returns strings — the resolver must cast to int.
        $this->mock_wpdb(array('SELECT siteId FROM wp_pcm_projects WHERE id = 11' => '5'));
        $this->assertSame(5, PCM_Hierarchy::site_for_project(11));
    }

    public function test_site_for_project_returns_null_when_not_connected(): void
    {
        $this->mock_wpdb(array('SELECT siteId FROM wp_pcm_projects WHERE id = 11' => null));
        $this->assertNull(PCM_Hierarchy::site_for_project(11));
    }

    public function test_site_for_project_guards_empty_project_id(): void
    {
        // Short-circuits before any DB access — no wpdb mock needed.
        $this->assertNull(PCM_Hierarchy::site_for_project(null));
        $this->assertNull(PCM_Hierarchy::site_for_project(0));
    }

    public function test_projects_for_site_returns_empty_list_when_unused(): void
    {
        $this->mock_wpdb(array(), array('WHERE siteId = 5' => array()));
        $this->assertSame(array(), PCM_Hierarchy::projects_for_site(5));
    }

    public function test_projects_for_site_guards_zero_site_id(): void
    {
        $this->assertSame(array(), PCM_Hierarchy::projects_for_site(0));
    }

    public function test_projects_for_site_returns_multiple_chains_for_shared_site(): void
    {
        // N:1 contract: projects 11 AND 12 both connect to site 5. Project 11 has a full
        // Brand → Delivery chain; project 12 has no delivery — missing links resolve null.
        $this->mock_wpdb(
            array(
                'SELECT deliveryId FROM wp_pcm_projects WHERE id = 11'  => '3',
                'SELECT brandId FROM wp_pcm_deliveries WHERE id = 3'    => '2',
                'SELECT deliveryId FROM wp_pcm_projects WHERE id = 12'  => null,
            ),
            array('WHERE siteId = 5' => array('11', '12'))
        );

        $chains = PCM_Hierarchy::projects_for_site(5);

        $this->assertCount(2, $chains);
        $this->assertSame(
            array('projectId' => 11, 'deliveryId' => 3, 'brandId' => 2, 'siteId' => 5),
            $chains[0]
        );
        $this->assertSame(
            array('projectId' => 12, 'deliveryId' => null, 'brandId' => null, 'siteId' => 5),
            $chains[1]
        );
    }
}
