<?php
/**
 * Unit Tests — pending-client reminder scanner math.
 *
 * Pure tests over PCM_Automation_Engine::pending_should_fire() and
 * pending_dedupe_key() — no DB, no network. The DB-level loop in
 * run_pending_client_scan() is covered by the live verify step in the plan.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class PendingClientScannerTest extends TestCase
{
    public function test_fires_once_min_days_reached(): void
    {
        // minDays = 3 → eligible from day 3 onward; the cycle-anchored dedupe
        // key (not this check) limits firing to once per cycle.
        $this->assertTrue(PCM_Automation_Engine::pending_should_fire(3, 3));
        $this->assertTrue(PCM_Automation_Engine::pending_should_fire(4, 3)); // catch-up after a missed cron day
        $this->assertTrue(PCM_Automation_Engine::pending_should_fire(6, 3));
        $this->assertTrue(PCM_Automation_Engine::pending_should_fire(30, 3));
    }

    public function test_does_not_fire_before_min_days(): void
    {
        $this->assertFalse(PCM_Automation_Engine::pending_should_fire(0, 3));
        $this->assertFalse(PCM_Automation_Engine::pending_should_fire(1, 3));
        $this->assertFalse(PCM_Automation_Engine::pending_should_fire(2, 3));
    }

    public function test_cycle_day_anchors_each_min_days_window(): void
    {
        // Days 3–5 (min 3) all belong to the cycle that started on day 3, so a
        // cron run on day 4 catches up the missed day-3 reminder exactly once.
        $this->assertSame(3, PCM_Automation_Engine::pending_cycle_day(3, 3));
        $this->assertSame(3, PCM_Automation_Engine::pending_cycle_day(4, 3));
        $this->assertSame(3, PCM_Automation_Engine::pending_cycle_day(5, 3));
        $this->assertSame(6, PCM_Automation_Engine::pending_cycle_day(6, 3));
        $this->assertSame(6, PCM_Automation_Engine::pending_cycle_day(8, 3));
        $this->assertSame(9, PCM_Automation_Engine::pending_cycle_day(9, 3));
    }

    public function test_zero_or_negative_min_days_never_fires(): void
    {
        $this->assertFalse(PCM_Automation_Engine::pending_should_fire(10, 0));
        $this->assertFalse(PCM_Automation_Engine::pending_should_fire(10, -1));
    }

    public function test_dedupe_key_shape(): void
    {
        $this->assertSame(
            'pending:set:42:rule:7:day:6',
            PCM_Automation_Engine::pending_dedupe_key(7, 42, 6)
        );
    }
}
