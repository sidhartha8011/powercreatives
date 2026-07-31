<?php
/**
 * Unit Tests — who may EDIT a delivery.
 *
 * Owner report 2026-07-31: a platform Administrator logged in through the plugin
 * (not wp-admin) could SEE deliveries but got "You can view this delivery but not
 * edit it." on every change. PCM_DB::get_delivery_by_id already returns any
 * delivery to an admin for team-wide oversight, but the write paths checked raw
 * ownership only — so the read grant was a dead end.
 *
 * These pin the rule: owner OR admin may edit; a non-owner non-admin (an ASSIGNED
 * user, who can legitimately view) may not. The admin half covers BOTH kinds —
 * PCM_Access::is_admin() reads the `role` column, which PlatformRoleInvariantTest
 * separately guards against unsanctioned writers.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class DeliveryEditPermissionTest extends TestCase
{
    /** Invoke the private owner-or-admin helper. */
    private function canEdit(int $owner_id, int $caller_id): bool
    {
        // No setAccessible() — a no-op since PHP 8.1 and deprecated in 8.5.
        $m = new ReflectionMethod('PCM_REST_Deliveries', 'can_edit_delivery');
        return (bool) $m->invoke(null, (object) array('userId' => $owner_id), $caller_id);
    }

    /** Make PCM_Access::is_admin() answer $role for the next uncached user id. */
    private function stubRole(?string $role): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn($role);
    }

    public function test_owner_can_edit(): void
    {
        // Owner short-circuits before any role lookup — no $wpdb needed.
        $this->assertTrue($this->canEdit(7, 7));
    }

    public function test_admin_can_edit_a_delivery_they_do_not_own(): void
    {
        // THE BUG: this returned false, so an admin saw the delivery and was
        // then refused the edit.
        $this->stubRole('admin');
        $this->assertTrue($this->canEdit(7, 8001));
    }

    public function test_non_owner_non_admin_cannot_edit(): void
    {
        // An ASSIGNED user: allowed to view (get_delivery_by_id returns it),
        // never to edit.
        $this->stubRole('user');
        $this->assertFalse($this->canEdit(7, 8002));
    }

    public function test_non_owner_with_no_role_row_cannot_edit(): void
    {
        // Unknown/deleted user — absence of a role must not read as admin.
        $this->stubRole(null);
        $this->assertFalse($this->canEdit(7, 8003));
    }
}
