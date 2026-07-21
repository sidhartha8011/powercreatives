<?php
/**
 * Unit Tests — RSS watcher (Filip's Source=RSS strategies).
 *
 * Exercises the watcher's PURE static decision seams on the REAL
 * PCM_Strategy_Service — no fetch_feed()/SimplePie anywhere near these tests
 * (the impure fetch edge is deliberately isolated in
 * fetch_rss_feed_items(), which returns plain scalar arrays):
 *
 *   - ingest_feed_items()      seen/queued dedupe, queue-entry shape,
 *                              freshest-first ordering, queue cap 10 with
 *                              overflow guids marked seen, rssSeen cap 200;
 *   - rss_free_slots()         perWeek backpressure math (default 3, clamp);
 *   - rss_pop_due_items()      freshest-first pop + queue shrink;
 *   - rss_duration_blocked()   'until' end-date and 'limit' article-cap gates;
 *   - rss_source_instruction() the generation-prompt rider, with/without an
 *                              rssAngle (private — invoked via reflection,
 *                              same house pattern as StrategySectionsConfigTest's
 *                              sanitize_config_fields() harness).
 *
 * House-style fakes: everything these seams touch (ABSPATH, sanitize_text_field,
 * esc_url_raw, wp_json_encode, current_time via PCM_Test_Cron::$now) comes from
 * the shared stand-ins in StrategyAutoPublishTest.php via
 * pcm_test_define_strategy_fakes() — this suite needs no richer fakes of its own.
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyRssWatcherTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_strategy_fakes();
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';

        PCM_Test_Cron::$scheduleCalls = array();
        PCM_Test_Cron::$alreadyScheduled = false;
        PCM_Test_Cron::$now = null;
    }

    /** One raw feed item as fetch_rss_feed_items() shapes them. */
    private function rawItem(string $permalink, string $title, int $ts, string $id = ''): array
    {
        return array('permalink' => $permalink, 'id' => $id, 'title' => $title, 'date' => $ts);
    }

    /** Invoke the private rss_source_instruction() rider (PHP 8.1+: no setAccessible()). */
    private function sourceInstruction(array $item_cfg, string $angle): string
    {
        $method = new \ReflectionMethod(PCM_Strategy_Service::class, 'rss_source_instruction');
        return $method->invoke(null, $item_cfg, $angle);
    }

    // ── (1) Seen/queued dedupe ───────────────────────────────────────────

    public function test_ingest_skips_guids_already_seen_or_already_queued(): void
    {
        $queued = array('guid' => md5('https://a.example/post-3'), 'title' => 'Queued', 'link' => 'https://a.example/post-3', 'ts' => 500);
        $config = array(
            'sourceMode' => 'rss',
            'rssSeen'    => array(md5('https://a.example/post-1')),
            'rssQueue'   => array($queued),
        );
        $raw = array(
            $this->rawItem('https://a.example/post-1', 'Already seen', 1000),
            $this->rawItem('https://a.example/post-3', 'Queued again', 1500),
            $this->rawItem('https://a.example/post-2', str_repeat('T', 300), 2000),
        );

        $out = PCM_Strategy_Service::ingest_feed_items($raw, $config);

        $guids = array_column($out['rssQueue'], 'guid');
        $this->assertNotContains(md5('https://a.example/post-1'), $guids, 'an rssSeen guid must never re-enter the queue');
        $this->assertSame(1, count(array_keys($guids, md5('https://a.example/post-3'), true)), 'an already-queued guid must not be duplicated');
        $this->assertContains(md5('https://a.example/post-2'), $guids, 'the genuinely new item is queued');
        // Queue-entry shape: title sanitized + capped at 200, link esc_url_raw'd, ts carried.
        $fresh = $out['rssQueue'][0]; // ts 2000 is the freshest → first
        $this->assertSame(md5('https://a.example/post-2'), $fresh['guid']);
        $this->assertSame(200, strlen($fresh['title']), 'title is capped at 200 chars');
        $this->assertSame('https://a.example/post-2', $fresh['link']);
        $this->assertSame(2000, $fresh['ts']);
        // Untouched config keys survive byte-for-byte.
        $this->assertSame('rss', $out['sourceMode']);
    }

    // ── (2) Freshest-first + queue cap 10 + overflow marked seen ────────

    public function test_ingest_orders_freshest_first_caps_queue_at_ten_and_marks_overflow_seen(): void
    {
        $raw = array();
        for ($i = 1; $i <= 12; $i++) {
            // Deliberately ingested oldest-first — ordering must come from ts, not input order.
            $raw[] = $this->rawItem('https://b.example/post-' . $i, 'Post ' . $i, $i * 100);
        }

        $out = PCM_Strategy_Service::ingest_feed_items($raw, array('rssSeen' => array(), 'rssQueue' => array()));

        $this->assertCount(10, $out['rssQueue'], 'queue is capped at 10');
        $this->assertSame(md5('https://b.example/post-12'), $out['rssQueue'][0]['guid'], 'freshest item first');
        $this->assertSame(md5('https://b.example/post-3'), $out['rssQueue'][9]['guid'], 'the two OLDEST items are the overflow');
        // Overflow guids are marked seen anyway — they must never be re-ingested.
        $this->assertContains(md5('https://b.example/post-1'), $out['rssSeen']);
        $this->assertContains(md5('https://b.example/post-2'), $out['rssSeen']);

        // Second scan over the SAME feed content: nothing new appears.
        $again = PCM_Strategy_Service::ingest_feed_items($raw, $out);
        $this->assertSame($out['rssQueue'], $again['rssQueue'], 'a rescan of identical feed content is a no-op');
        $this->assertSame($out['rssSeen'], $again['rssSeen']);
    }

    // ── (3) Backpressure: perWeek slots ──────────────────────────────────

    public function test_free_slots_is_per_week_minus_items_created_in_the_last_seven_days(): void
    {
        $config = array('rssCadence' => array('perWeek' => 3));

        $this->assertSame(1, PCM_Strategy_Service::rss_free_slots(2, $config), 'perWeek 3 with 2 recent → 1 slot');
        $this->assertSame(0, PCM_Strategy_Service::rss_free_slots(3, $config), 'perWeek 3 with 3 recent → 0 slots');
        $this->assertSame(0, PCM_Strategy_Service::rss_free_slots(5, $config), 'over-quota never goes negative');
        $this->assertSame(3, PCM_Strategy_Service::rss_free_slots(0, array()), 'absent cadence defaults to perWeek 3');
        $this->assertSame(21, PCM_Strategy_Service::rss_free_slots(0, array('rssCadence' => array('perWeek' => 999))), 'perWeek clamps to 21 (mirrors the controller clamp)');
    }

    // ── (4) Duration gates ────────────────────────────────────────────────

    public function test_duration_limit_and_until_block_the_watcher(): void
    {
        PCM_Test_Cron::$now = '2026-07-16 12:00:00';

        // 'until': a passed end date blocks; the end date ITSELF still runs.
        $this->assertTrue(PCM_Strategy_Service::rss_duration_blocked(array('duration' => array('mode' => 'until', 'endDate' => '2026-07-15')), 0));
        $this->assertFalse(PCM_Strategy_Service::rss_duration_blocked(array('duration' => array('mode' => 'until', 'endDate' => '2026-07-16')), 0), 'the endDate day itself is still inside the window');
        $this->assertFalse(PCM_Strategy_Service::rss_duration_blocked(array('duration' => array('mode' => 'until', 'endDate' => '2026-08-01')), 0));

        // 'limit': blocked exactly when the item count reaches maxArticles.
        $this->assertTrue(PCM_Strategy_Service::rss_duration_blocked(array('duration' => array('mode' => 'limit', 'maxArticles' => 5)), 5));
        $this->assertTrue(PCM_Strategy_Service::rss_duration_blocked(array('duration' => array('mode' => 'limit', 'maxArticles' => 5)), 6));
        $this->assertFalse(PCM_Strategy_Service::rss_duration_blocked(array('duration' => array('mode' => 'limit', 'maxArticles' => 5)), 4));

        // 'ongoing' / absent duration never blocks.
        $this->assertFalse(PCM_Strategy_Service::rss_duration_blocked(array('duration' => array('mode' => 'ongoing')), 9999));
        $this->assertFalse(PCM_Strategy_Service::rss_duration_blocked(array(), 9999));
    }

    // ── (5) The generation-prompt rider ──────────────────────────────────

    public function test_rss_source_instruction_with_and_without_angle(): void
    {
        $item_cfg = array('sourceLink' => 'https://x.example/original', 'sourceTitle' => 'Cool Post');

        $with = $this->sourceInstruction($item_cfg, 'ai seo');
        $this->assertStringContainsString("This article responds to a new industry item: 'Cool Post' (https://x.example/original).", $with);
        $this->assertStringContainsString("tailored to the angle/primary keyword: 'ai seo'", $with);
        $this->assertStringContainsString('Do not copy the source; outdo it.', $with);

        $without = $this->sourceInstruction($item_cfg, '');
        $this->assertStringContainsString("This article responds to a new industry item: 'Cool Post' (https://x.example/original).", $without);
        $this->assertStringNotContainsString('tailored to the angle/primary keyword', $without, 'no angle → no tailoring clause');
        $this->assertStringContainsString('Do not copy the source; outdo it.', $without);

        // A non-RSS item (no source context in its config) contributes NOTHING
        // to the prompt — the '' contract every existing generation path relies on.
        $this->assertSame('', $this->sourceInstruction(array(), 'ai seo'));
        $this->assertSame('', $this->sourceInstruction(array('templateId' => 4), ''));
    }

    // ── (6) rssSeen cap 200 newest ────────────────────────────────────────

    public function test_rss_seen_caps_at_two_hundred_newest_guids(): void
    {
        $seen = array();
        for ($i = 0; $i < 200; $i++) {
            $seen[] = md5('old-' . $i);
        }
        $raw = array();
        for ($i = 1; $i <= 5; $i++) {
            $raw[] = $this->rawItem('https://c.example/new-' . $i, 'New ' . $i, $i);
        }

        $out = PCM_Strategy_Service::ingest_feed_items($raw, array('rssSeen' => $seen, 'rssQueue' => array()));

        $this->assertCount(200, $out['rssSeen'], 'rssSeen never exceeds 200');
        $this->assertContains(md5('https://c.example/new-5'), $out['rssSeen'], 'every newly ingested guid is retained');
        $this->assertContains(md5('https://c.example/new-1'), $out['rssSeen']);
        $this->assertNotContains(md5('old-0'), $out['rssSeen'], 'the OLDEST guids are the ones evicted');
        $this->assertNotContains(md5('old-4'), $out['rssSeen']);
        $this->assertContains(md5('old-5'), $out['rssSeen'], 'guid #6-oldest survives (exactly 5 evictions for 5 new)');
    }

    // ── (7) Pop: freshest-first, queue shrinks, popped carry their context ─

    public function test_pop_due_items_pops_freshest_first_and_shrinks_the_queue(): void
    {
        $config = array(
            'rssAngle' => 'kept-key',
            'rssQueue' => array(
                // Stored out of order on purpose — pop must re-sort ts DESC.
                array('guid' => 'g-old', 'title' => 'Oldest', 'link' => 'https://q.example/1', 'ts' => 10),
                array('guid' => 'g-new', 'title' => 'Newest', 'link' => 'https://q.example/3', 'ts' => 30),
                array('guid' => 'g-mid', 'title' => 'Middle', 'link' => 'https://q.example/2', 'ts' => 20),
            ),
        );

        $result = PCM_Strategy_Service::rss_pop_due_items($config, 2);

        $this->assertSame(array('g-new', 'g-mid'), array_column($result['popped'], 'guid'), 'the TWO freshest entries pop, newest first');
        $this->assertSame('Newest', $result['popped'][0]['title'], 'popped entries keep the title/link the item insert needs');
        $this->assertSame('https://q.example/3', $result['popped'][0]['link']);
        $this->assertSame(array('g-old'), array_column($result['config']['rssQueue'], 'guid'), 'only the unpopped remainder stays queued');
        $this->assertSame('kept-key', $result['config']['rssAngle'], 'other config keys pass through untouched');

        // Zero (or negative) slots pop nothing and leave the queue intact.
        $none = PCM_Strategy_Service::rss_pop_due_items($config, 0);
        $this->assertSame(array(), $none['popped']);
        $this->assertCount(3, $none['config']['rssQueue']);
    }
}
