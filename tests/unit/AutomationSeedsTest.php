<?php
/**
 * Unit Tests — Automation default-rule seeder.
 *
 * Exercises idempotency: a second call inserts nothing. wpdb + create_rule are
 * captured via Mockery so the test is pure.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class AutomationSeedsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        WP_Mock::userFunction('__')->andReturnUsing(fn($s) => $s);
        WP_Mock::userFunction('wp_json_encode')->andReturnUsing(fn($v) => json_encode($v));
    }

    public function test_zero_user_id_is_noop(): void
    {
        $n = PCM_Automation_Seeds::seed_for_user(0);
        $this->assertSame(0, $n);
    }

    public function test_seed_then_reseed_is_idempotent(): void
    {
        // Stub wpdb so is_seeded() returns "not seeded" on first pass and
        // "already seeded" on the second.
        $callsByKey = array();
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn($q, ...$a) => $q . '|' . implode(',', array_map('strval', $a)));
        $wpdb->shouldReceive('get_var')->andReturnUsing(function ($q) use (&$callsByKey) {
            // Extract the seed key from the LIKE pattern in the prepared query.
            if (preg_match('/__seedKey":"([^"]+)"/', $q, $m)) {
                $key = $m[1];
                $seen = $callsByKey[$key] ?? 0;
                $callsByKey[$key] = $seen + 1;
                return $seen > 0 ? '7' : null; // first call → not seeded; later → seeded
            }
            return null;
        });
        $insertedRows = 0;
        $wpdb->shouldReceive('insert')->andReturnUsing(function () use (&$insertedRows) {
            $insertedRows++;
            return 1;
        });
        $wpdb->insert_id = 100;
        $GLOBALS['wpdb'] = $wpdb;

        $first = PCM_Automation_Seeds::seed_for_user(1);
        $second = PCM_Automation_Seeds::seed_for_user(1);

        // All default rules seeded the first time, nothing the second time.
        $this->assertSame(9, $first);
        $this->assertSame(0, $second);
    }

    public function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }
}
