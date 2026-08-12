<?php
/**
 * Unit Tests — who may DESTROY an approval set.
 *
 * Owner report 2026-08-10: an admin got "Set not found or not owned by this
 * user." deleting a card from the Approvals board.
 *
 * Cause: list_sets_by_user() drops the userId filter for admins, so the board
 * shows them EVERY set — but delete_set / bulk_delete_sets / update_status all
 * scoped their WHERE to `userId = <caller>`, matched 0 rows, and the service
 * reported that as "not found". The admin could see a card and was refused the
 * moment they touched it.
 *
 * can_write_set() is the gate the write paths now share: owner OR admin, and
 * deliberately NOT brand/project grantees — those may still view and append via
 * get_set_scoped(), but not destroy.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class ApprovalsWritePermissionTest extends TestCase
{
    /** Stub the owner lookup + the role lookup is_admin() performs. */
    private function stub(?int $owner_id, string $role = 'user'): void
    {
        PCM_Access::reset_memo();
        global $wpdb;
        $wpdb = \Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        // First get_var() = the set's owner, second = the caller's role.
        $wpdb->shouldReceive('get_var')->andReturnValues(
            array($owner_id === null ? null : (string) $owner_id, $role)
        );
    }

    public function tearDown(): void
    {
        PCM_Access::reset_memo();
        parent::tearDown();
    }

    public function test_owner_may_destroy_their_own_set(): void
    {
        // Owner short-circuits before the role lookup.
        $this->stub(7);
        $this->assertTrue(PCM_Approvals_Service::can_write_set(10, 7));
    }

    public function test_admin_may_destroy_a_set_they_do_not_own(): void
    {
        // THE BUG: this was false, so the delete matched 0 rows and reported
        // "Set not found or not owned by this user".
        $this->stub(7, 'admin');
        $this->assertTrue(PCM_Approvals_Service::can_write_set(10, 8001));
    }

    public function test_plain_user_may_not_destroy_someone_elses_set(): void
    {
        $this->stub(7, 'user');
        $this->assertFalse(PCM_Approvals_Service::can_write_set(10, 8002));
    }

    public function test_missing_set_is_not_writable(): void
    {
        // Genuinely absent — the caller's 404 is correct here, and an admin
        // must NOT be waved through onto a row that does not exist.
        $this->stub(null, 'admin');
        $this->assertFalse(PCM_Approvals_Service::can_write_set(999, 8003));
    }

    public function test_zero_ids_are_rejected_without_a_query(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock();
        $wpdb->shouldNotReceive('get_var');

        $this->assertFalse(PCM_Approvals_Service::can_write_set(0, 7));
        $this->assertFalse(PCM_Approvals_Service::can_write_set(10, 0));
    }

    // ── the write paths must actually USE the gate ──

    /** @return string approvals/service.php source */
    private function src(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/includes/modules/approvals/service.php'
        );
    }

    public function test_every_destructive_path_gates_on_can_write_set(): void
    {
        $src = $this->src();
        foreach (array('delete_set', 'bulk_delete_sets', 'update_status') as $fn) {
            $start = strpos($src, "function {$fn}(");
            $this->assertNotFalse($start, "{$fn}() must exist");
            $body = substr($src, $start, 2200);
            $this->assertStringContainsString(
                'can_write_set',
                $body,
                "{$fn}() must authorise through can_write_set()"
            );
        }
    }

    public function test_writes_no_longer_re_impose_owner_only_in_their_where(): void
    {
        // The gate is the authorisation. Leaving `userId` in the WHERE as well
        // would match 0 rows for the very admin the gate just allowed — the
        // original bug, reintroduced.
        $src = $this->src();
        foreach (array('delete_set', 'update_status') as $fn) {
            $start = strpos($src, "function {$fn}(");
            $body  = substr($src, $start, 2200);
            $this->assertDoesNotMatchRegularExpression(
                "/'userId'\s*=>\s*\\\$user_id/",
                $body,
                "{$fn}()'s WHERE must not re-impose owner-only"
            );
        }
    }
}
