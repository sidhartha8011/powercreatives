<?php
/**
 * Unit Tests — Move-Lane Action Handler (approvals.move_to_lane).
 *
 * Covers the handler's own decision logic: id/mode and the skip guards (invalid
 * lane, missing set, missing user). The DB-level "ok" path (update_status moving
 * the set) is covered by the live verification step in the plan — same split as
 * PendingClientScannerTest.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class MoveLaneActionHandlerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::userFunction('__')->andReturnUsing(fn($s) => $s);
    }

    public function test_id_and_mode(): void
    {
        $h = new PCM_Move_Lane_Action_Handler();
        $this->assertSame('approvals.move_to_lane', $h->id());
        $this->assertSame('sync', $h->mode());
    }

    public function test_skips_on_invalid_lane(): void
    {
        $h = new PCM_Move_Lane_Action_Handler();
        $result = $h->run(array('lane' => 'nope'), array(), array('setId' => 5), 1);
        $this->assertTrue($result['skipped']);
        $this->assertFalse($result['ok']);
        $this->assertSame('nope', $result['target']);
    }

    public function test_skips_when_no_set_in_context(): void
    {
        $h = new PCM_Move_Lane_Action_Handler();
        $result = $h->run(array('lane' => 'client'), array(), array(), 1);
        $this->assertTrue($result['skipped']);
        $this->assertFalse($result['ok']);
    }

    public function test_skips_when_no_user(): void
    {
        $h = new PCM_Move_Lane_Action_Handler();
        $result = $h->run(array('lane' => 'launch'), array(), array('setId' => 5), 0);
        $this->assertTrue($result['skipped']);
        $this->assertFalse($result['ok']);
    }
}
