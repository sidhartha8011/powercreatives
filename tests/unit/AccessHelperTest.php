<?php
/**
 * Unit Tests — PCM_Access scope helper.
 *
 * Pure tests over scope_clause() (SQL shape + params) and the zero-uid guards.
 * The DB-backed granted_* lookups are covered by the live verify step.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class AccessHelperTest extends TestCase
{
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
