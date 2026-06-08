<?php
/**
 * Unit Tests — Automation Events catalog.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class AutomationEventsTest extends TestCase
{
    public function test_all_events_are_namespaced_strings(): void
    {
        $events = PCM_Automation_Events::all();
        $this->assertNotEmpty($events);
        foreach ($events as $event) {
            $this->assertIsString($event);
            $this->assertStringStartsWith('approval.', $event);
        }
    }

    public function test_is_valid_accepts_known_events(): void
    {
        $this->assertTrue(PCM_Automation_Events::is_valid(PCM_Automation_Events::APPROVAL_SET_SHARED));
        $this->assertTrue(PCM_Automation_Events::is_valid(PCM_Automation_Events::APPROVAL_ALL_APPROVED));
        $this->assertTrue(PCM_Automation_Events::is_valid(PCM_Automation_Events::APPROVAL_COMPLETED));
    }

    public function test_is_valid_rejects_unknown_events(): void
    {
        $this->assertFalse(PCM_Automation_Events::is_valid('approval.unknown'));
        $this->assertFalse(PCM_Automation_Events::is_valid(''));
        $this->assertFalse(PCM_Automation_Events::is_valid('random.event'));
    }
}
