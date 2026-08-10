<?php
/**
 * Unit Tests — PCM_Access scope helper.
 *
 * Tests over scope_clause() (SQL shape + params) and the zero-uid guards.
 * The DB-backed granted_* lookups are covered by the live verify step.
 *
 * NOTE: scope_clause() was pure until 2026-08-07, when an admin short-circuit
 * (team-wide oversight) made it consult is_admin() — a role lookup. setUp()
 * therefore stubs $wpdb; the zero-uid guards below still short-circuit before
 * any DB access, which is what makes them worth pinning separately.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class AccessHelperTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // scope_clause() stopped being pure (2026-08-07): it now short-circuits to a
        // match-everything clause for admins, which asks is_admin() — a DB read. These
        // tests pin the NON-admin shapes, so stub the role lookup to a plain user and
        // clear the per-request memo so one test's answer can't leak into the next.
        PCM_Access::reset_memo();
        global $wpdb;
        $wpdb = \Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('user');
    }

    public function tearDown(): void
    {
        PCM_Access::reset_memo();
        parent::tearDown();
    }

    public function test_scope_clause_without_grants_is_owner_only(): void
    {
        $scope = PCM_Access::scope_clause('userId', 'id', 7, array());
        $this->assertSame('userId = %d', $scope['sql']);
        $this->assertSame(array(7), $scope['params']);
    }

    public function test_scope_clause_with_grants_adds_in_list(): void
    {
        $scope = PCM_Access::scope_clause('userId', 'id', 7, array(3, 9));
        $this->assertSame('(userId = %d OR id IN (%d,%d))', $scope['sql']);
        $this->assertSame(array(7, 3, 9), $scope['params']);
    }

    public function test_scope_clause_matches_everything_for_an_admin(): void
    {
        // The new admin short-circuit — team-wide oversight. `%d = %d` (not a bare
        // 1=1) so callers that interpolate this into a prepare() whose ONLY
        // placeholders come from here still receive the args they expect.
        PCM_Access::reset_memo();
        global $wpdb;
        $wpdb = \Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn('admin');

        $scope = PCM_Access::scope_clause('userId', 'id', 7, array(3, 9));
        $this->assertSame('%d = %d', $scope['sql']);
        $this->assertSame(array(1, 1), $scope['params']);
    }

    public function test_granted_ids_guard_zero_user(): void
    {
        $this->assertSame(array(), PCM_Access::granted_delivery_ids(0));
        $this->assertSame(array(), PCM_Access::granted_brand_ids(-1));
        $this->assertSame(array(), PCM_Access::granted_project_ids(0));
    }

    public function test_brands_for_modules_guards(): void
    {
        // Zero uid and empty module list short-circuit before any DB access.
        $this->assertSame(array(), PCM_Access::granted_brand_ids_for_modules(0, array('copy')));
        $this->assertSame(array(), PCM_Access::granted_brand_ids_for_modules(7, array()));
    }
}
