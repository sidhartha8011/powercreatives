<?php
/**
 * Unit Tests — notification visibility clause (role-scoped feed).
 *
 * Pure SQL-shape tests over PCM_Notifications_Service::visibility_clause().
 * The DB-backed feed + seen anchor are covered by the live verify step.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class NotificationVisibilityTest extends TestCase
{
    public function test_admin_sees_everything(): void
    {
        $scope = PCM_Notifications_Service::visibility_clause(true, 7, array(1, 2));
        $this->assertSame('1=1', $scope['sql']);
        $this->assertSame(array(), $scope['params']);
    }

    public function test_user_without_grants_sees_only_owned(): void
    {
        $scope = PCM_Notifications_Service::visibility_clause(false, 7, array());
        $this->assertSame('ownerId = %d', $scope['sql']);
        $this->assertSame(array(7), $scope['params']);
    }

    public function test_user_with_grants_sees_owned_or_granted_brands(): void
    {
        $scope = PCM_Notifications_Service::visibility_clause(false, 7, array(3, 9));
        $this->assertSame('(ownerId = %d OR brandId IN (%d,%d))', $scope['sql']);
        $this->assertSame(array(7, 3, 9), $scope['params']);
    }
}
