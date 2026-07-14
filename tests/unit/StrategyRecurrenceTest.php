<?php
/**
 * Unit Tests — custom-recurrence date engine
 * (PCM_Strategy_Service::calculate_recurrence_dates()).
 *
 * The engine methods (calculate_recurrence_dates / calculate_schedule_dates)
 * are PURE and deterministic — no DB/WP dependency — so most tests call them
 * directly with fixed dates and assert the exact returned slots.
 *
 * Reuses the shared in-process fakes established by StrategyAutoPublishTest.php
 * (PCM_DB / PCM_LLM / PCM_Schema stand-ins + the WP function shims) via
 * pcm_test_define_strategy_fakes() in setUp(), so this class carries the same
 * @runTestsInSeparateProcesses / @preserveGlobalState disabled contract: the
 * fakes share class NAMES with the real composer-classmapped services, and
 * process isolation is what prevents a collision with whichever test in the
 * FULL suite autoloads the real classes first.
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyRecurrenceTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_strategy_fakes();
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';
    }

    // ── Fixed-interval units ────────────────────────────────────────────

    /** (a) interval=1 unit=day over 4 items — one calendar day apart, time kept. */
    public function test_interval_one_unit_day_spaces_items_one_day_apart(): void
    {
        $dates = PCM_Strategy_Service::calculate_recurrence_dates(
            4,
            array('interval' => 1, 'unit' => 'day'),
            '2026-07-14 09:00:00'
        );

        $this->assertSame(
            array('2026-07-14 09:00:00', '2026-07-15 09:00:00', '2026-07-16 09:00:00', '2026-07-17 09:00:00'),
            $dates
        );
    }

    /** (b) interval=3 unit=day — three calendar days apart. */
    public function test_interval_three_unit_day_spaces_items_three_days_apart(): void
    {
        $dates = PCM_Strategy_Service::calculate_recurrence_dates(
            4,
            array('interval' => 3, 'unit' => 'day'),
            '2026-07-14 09:00:00'
        );

        $this->assertSame(
            array('2026-07-14 09:00:00', '2026-07-17 09:00:00', '2026-07-20 09:00:00', '2026-07-23 09:00:00'),
            $dates
        );
    }

    /** (c) interval=1 unit=month — preserves day-of-month and time-of-day. */
    public function test_interval_one_unit_month_preserves_day_of_month_and_time(): void
    {
        $dates = PCM_Strategy_Service::calculate_recurrence_dates(
            3,
            array('interval' => 1, 'unit' => 'month'),
            '2026-01-14 09:30:00'
        );

        $this->assertSame(
            array('2026-01-14 09:30:00', '2026-02-14 09:30:00', '2026-03-14 09:30:00'),
            $dates
        );
    }

    // ── byDays (weekly weekday-set) scheduling ──────────────────────────

    /**
     * (d) byDays [Mon,Wed] starting on a Tuesday → the first slot is the next
     * selected weekday (Wed), then Mon/Wed of the following week, in order.
     * Fixed anchor '2026-07-14 09:00:00' is a Tuesday.
     */
    public function test_by_days_mon_wed_starting_tuesday_lands_wed_then_next_mon_wed(): void
    {
        $dates = PCM_Strategy_Service::calculate_recurrence_dates(
            3,
            array('unit' => 'week', 'interval' => 1, 'byDays' => array(1, 3)),
            '2026-07-14 09:00:00' // Tuesday
        );

        $this->assertSame(
            array('2026-07-15 09:00:00', '2026-07-20 09:00:00', '2026-07-22 09:00:00'), // Wed, Mon, Wed
            $dates
        );
    }

    /**
     * (e) byDays with interval=2 — after the last selected day in a block, the
     * next block is TWO weeks on. Start '2026-07-13 09:00:00' is a Monday.
     */
    public function test_by_days_interval_two_advances_two_week_blocks(): void
    {
        $dates = PCM_Strategy_Service::calculate_recurrence_dates(
            4,
            array('unit' => 'week', 'interval' => 2, 'byDays' => array(1, 3)),
            '2026-07-13 09:00:00' // Monday
        );

        $this->assertSame(
            array('2026-07-13 09:00:00', '2026-07-15 09:00:00', '2026-07-27 09:00:00', '2026-07-29 09:00:00'),
            $dates
        );
    }

    /**
     * (f) Starting ON a selected weekday includes that very day as the first
     * slot. Start '2026-07-15 09:00:00' is a Wednesday; byDays=[Wed,Fri].
     */
    public function test_by_days_starting_on_a_selected_day_includes_that_day(): void
    {
        $dates = PCM_Strategy_Service::calculate_recurrence_dates(
            2,
            array('unit' => 'week', 'interval' => 1, 'byDays' => array(3, 5)),
            '2026-07-15 09:00:00' // Wednesday
        );

        $this->assertSame(
            array('2026-07-15 09:00:00', '2026-07-17 09:00:00'), // Wed (the start day), Fri
            $dates
        );
    }

    // ── `ends` caps ─────────────────────────────────────────────────────

    /** (g) ends after=2 over 5 items → 2 dates followed by 3 nulls. */
    public function test_ends_after_caps_to_first_n_slots_then_nulls(): void
    {
        $dates = PCM_Strategy_Service::calculate_recurrence_dates(
            5,
            array('interval' => 1, 'unit' => 'day', 'ends' => array('type' => 'after', 'count' => 2)),
            '2026-07-14 09:00:00'
        );

        $this->assertSame(
            array('2026-07-14 09:00:00', '2026-07-15 09:00:00', null, null, null),
            $dates
        );
    }

    /** (h) ends on=<date> → no slot strictly after that day's 23:59:59. */
    public function test_ends_on_date_nulls_slots_past_the_cap_day(): void
    {
        $dates = PCM_Strategy_Service::calculate_recurrence_dates(
            5,
            array('interval' => 1, 'unit' => 'day', 'ends' => array('type' => 'on', 'date' => '2026-07-16')),
            '2026-07-14 09:00:00'
        );

        $this->assertSame(
            array('2026-07-14 09:00:00', '2026-07-15 09:00:00', '2026-07-16 09:00:00', null, null),
            $dates
        );
    }

    // ── Legacy parity ───────────────────────────────────────────────────

    /**
     * (i) EVERY legacy frequency stays byte-identical to the current
     * implementation. Expected values are computed with plain strtotime math
     * mirroring the original switch — the same numbers, derived independently
     * of the engine under test.
     */
    public function test_every_legacy_frequency_is_byte_identical(): void
    {
        $start = '2026-07-08 09:00:00';

        // Independent reference mirroring the ported switch (all_once/daily/
        // every_other_day/weekly/biweekly=+3d/monthly).
        $expected = static function (int $count, string $frequency, string $start): array {
            $dates = array();
            $ts = strtotime($start);
            for ($i = 0; $i < $count; $i++) {
                $dates[] = date('Y-m-d H:i:s', $ts);
                if ($frequency === 'all_once') {
                    continue;
                }
                switch ($frequency) {
                    case 'daily':           $ts = strtotime('+1 day', $ts); break;
                    case 'every_other_day': $ts = strtotime('+2 days', $ts); break;
                    case 'weekly':          $ts = strtotime('+7 days', $ts); break;
                    case 'biweekly':        $ts = strtotime('+3 days', $ts); break; // ported quirk
                    case 'monthly':         $ts = strtotime('+1 month', $ts); break;
                    default:                $ts = strtotime('+7 days', $ts); break;
                }
            }
            return $dates;
        };

        foreach (array('all_once', 'daily', 'every_other_day', 'weekly', 'biweekly', 'monthly') as $frequency) {
            $want = $expected(4, $frequency, $start);

            // Via the custom-recurrence engine's legacy branch...
            $this->assertSame(
                $want,
                PCM_Strategy_Service::calculate_recurrence_dates(4, array('frequency' => $frequency), $start),
                "calculate_recurrence_dates legacy branch drifted for '{$frequency}'"
            );
            // ...and via the thin public adapter that still fronts it.
            $this->assertSame(
                $want,
                PCM_Strategy_Service::calculate_schedule_dates(4, $frequency, $start),
                "calculate_schedule_dates adapter drifted for '{$frequency}'"
            );
        }
    }

    /** (j) An empty config defaults to weekly spacing (interval=1, unit=week). */
    public function test_empty_config_defaults_to_weekly(): void
    {
        $start = '2026-07-14 09:00:00';

        $dates = PCM_Strategy_Service::calculate_recurrence_dates(3, array(), $start);

        $this->assertSame(
            array('2026-07-14 09:00:00', '2026-07-21 09:00:00', '2026-07-28 09:00:00'),
            $dates
        );
        // And equal to the legacy 'weekly' key, confirming the default matches.
        $this->assertSame(
            PCM_Strategy_Service::calculate_schedule_dates(3, 'weekly', $start),
            $dates
        );
    }
}
