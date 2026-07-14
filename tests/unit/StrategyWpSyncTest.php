<?php
/**
 * Unit Tests — Strategy WordPress status sync (Task D3) and native scheduled-post
 * passthrough on manual publish (Task D4).
 *
 * Exercises the REAL PCM_Strategy_Service::sync_items_from_wp() / publish_item()
 * against lightweight in-process stand-ins, reusing the same fakes pattern as
 * StrategyAutoPublishTest.php (see that file's own docblock for the full
 * rationale on @runTestsInSeparateProcesses + class_exists(..., false) guards —
 * it applies identically here).
 *
 * IMPORTANT: this file declares its OWN richer PCM_Sites_Service fake — with a
 * publish_to_site() recorder that also captures $options (needed for D4's
 * schedule_date assertion) plus fetch_remote_post_status() backed by a settable
 * $remoteStatuses map (needed for D3) — BEFORE requiring StrategyAutoPublishTest.php.
 * That file's own pcm_test_define_strategy_fakes() also declares a
 * PCM_Sites_Service fake, but every declaration there is guarded by
 * `class_exists('PCM_Sites_Service', false)`, so once OUR richer class already
 * exists it is left alone and the simpler stand-in is skipped.
 *
 * @package PowerCreatives\Tests\Unit
 */

/** Declares this file's own richer fakes exactly once per (isolated) process. */
function pcm_test_define_wpsync_fakes(): void
{
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/wordpress/');
    }

    // Minimal wp_remote_get()/is_wp_error()/retrieve helpers. Not exercised by the
    // tests below — they stub PCM_Sites_Service::fetch_remote_post_status()
    // directly rather than the HTTP layer — but declared so this file's fakes are
    // fully self-contained if a future test here needs the real HTTP surface.
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing)
        {
            return false;
        }
    }
    if (!function_exists('wp_remote_get')) {
        function wp_remote_get($url, $args = array())
        {
            return array('__pcm_test_stub' => true);
        }
    }
    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response)
        {
            return 200;
        }
    }
    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body($response)
        {
            return '{}';
        }
    }

    // Richer fake PCM_Sites_Service — MUST be declared before
    // StrategyAutoPublishTest.php's own pcm_test_define_strategy_fakes() runs
    // (see this file's docblock: its class_exists(...,false) guard then skips
    // redeclaring the simpler stand-in).
    if (!class_exists('PCM_Sites_Service', false)) {
        class PCM_Sites_Service
        {
            /** @var array<int,array{site:object,article:object,user_id:int,options:array}> every publish_to_site() call, for assertions */
            public static $calls = array();
            public static $shouldThrow = false;

            /** @var array<int,array{status:string,link?:string}|null> remote_post_id => fake fetch_remote_post_status() result ('null' entries still must be set explicitly; an absent key also yields null, matching a genuine fetch failure) */
            public static $remoteStatuses = array();

            public static function publish_to_site($site, $article, $user_id, $options = array())
            {
                self::$calls[] = array('site' => $site, 'article' => $article, 'user_id' => $user_id, 'options' => $options);
                if (self::$shouldThrow) {
                    throw new \RuntimeException('remote publish boom');
                }
                return array('success' => true, 'postId' => 555, 'postUrl' => 'https://example.com/p/555', 'siteId' => (int)$site->id);
            }

            public static function fetch_remote_post_status($site, $remote_post_id)
            {
                return array_key_exists($remote_post_id, self::$remoteStatuses)
                    ? self::$remoteStatuses[$remote_post_id]
                    : null;
            }
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyWpSyncTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Declare OUR richer PCM_Sites_Service fake first (see file docblock),
        // then pull in the rest of the shared strategy fakes (PCM_DB, PCM_LLM, ...).
        pcm_test_define_wpsync_fakes();
        require_once __DIR__ . '/StrategyAutoPublishTest.php';
        pcm_test_define_strategy_fakes();
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';

        PCM_DB::$items = array();
        PCM_DB::$strategy = array();
        PCM_DB::$strategyRow = null;
        PCM_DB::$site = null;
        PCM_DB::$articles = array();
        PCM_DB::$articleSeq = 100;
        PCM_DB::$forceNullArticle = false;

        PCM_Sites_Service::$calls = array();
        PCM_Sites_Service::$shouldThrow = false;
        PCM_Sites_Service::$remoteStatuses = array();
    }

    /** @param array<int,array<string,mixed>> $items */
    private function seedItems(array $items): void
    {
        foreach ($items as $it) {
            $o = (object)$it;
            PCM_DB::$items[$o->id] = $o;
        }
    }

    // ── D3: sync_items_from_wp() ─────────────────────────────────────────

    public function test_deleted_remotely_clears_the_articles_published_url_and_reports_summary(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'articleId' => 100, 'position' => 0),
        ));
        PCM_DB::$articles[100] = array(
            'id' => 100, 'publishedUrl' => 'https://example.com/p/1', 'publishedPostId' => 555, 'siteId' => 9,
        );
        PCM_DB::$site = (object)array('id' => 9, 'name' => 'My Site', 'url' => 'https://example.com');
        PCM_Sites_Service::$remoteStatuses[555] = array('status' => 'deleted');
        $strategy = (object)array('id' => 7, 'totalItems' => 1);

        $result = PCM_Strategy_Service::sync_items_from_wp($strategy, 1);

        $this->assertSame(array('checked' => 1, 'updated' => 1, 'deleted' => 1), $result);
        $this->assertSame('', PCM_DB::$articles[100]['publishedUrl']);
        $this->assertNull(PCM_DB::$articles[100]['publishedPostId']);
    }

    public function test_still_published_with_an_unchanged_link_reports_checked_with_no_update(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'articleId' => 100, 'position' => 0),
        ));
        PCM_DB::$articles[100] = array(
            'id' => 100, 'publishedUrl' => 'https://example.com/p/1', 'publishedPostId' => 555, 'siteId' => 9,
        );
        PCM_DB::$site = (object)array('id' => 9, 'name' => 'My Site', 'url' => 'https://example.com');
        PCM_Sites_Service::$remoteStatuses[555] = array('status' => 'publish', 'link' => 'https://example.com/p/1');
        $strategy = (object)array('id' => 7, 'totalItems' => 1);

        $result = PCM_Strategy_Service::sync_items_from_wp($strategy, 1);

        $this->assertSame(array('checked' => 1, 'updated' => 0, 'deleted' => 0), $result);
        $this->assertSame('https://example.com/p/1', PCM_DB::$articles[100]['publishedUrl']);
    }

    public function test_a_changed_remote_link_refreshes_published_url(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'articleId' => 100, 'position' => 0),
        ));
        PCM_DB::$articles[100] = array(
            'id' => 100, 'publishedUrl' => 'https://example.com/old-slug', 'publishedPostId' => 555, 'siteId' => 9,
        );
        PCM_DB::$site = (object)array('id' => 9, 'name' => 'My Site', 'url' => 'https://example.com');
        PCM_Sites_Service::$remoteStatuses[555] = array('status' => 'publish', 'link' => 'https://example.com/new-slug');
        $strategy = (object)array('id' => 7, 'totalItems' => 1);

        $result = PCM_Strategy_Service::sync_items_from_wp($strategy, 1);

        $this->assertSame(array('checked' => 1, 'updated' => 1, 'deleted' => 0), $result);
        $this->assertSame('https://example.com/new-slug', PCM_DB::$articles[100]['publishedUrl']);
    }

    public function test_a_future_remote_status_leaves_the_article_untouched(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'articleId' => 100, 'position' => 0),
        ));
        PCM_DB::$articles[100] = array(
            'id' => 100, 'publishedUrl' => 'https://example.com/p/1', 'publishedPostId' => 555, 'siteId' => 9,
        );
        PCM_DB::$site = (object)array('id' => 9, 'name' => 'My Site', 'url' => 'https://example.com');
        PCM_Sites_Service::$remoteStatuses[555] = array('status' => 'future', 'link' => 'https://example.com/p/1');
        $strategy = (object)array('id' => 7, 'totalItems' => 1);

        $result = PCM_Strategy_Service::sync_items_from_wp($strategy, 1);

        $this->assertSame(array('checked' => 1, 'updated' => 0, 'deleted' => 0), $result, 'a still-scheduled remote post must be left as-is, not touched or counted as updated');
        $this->assertSame('https://example.com/p/1', PCM_DB::$articles[100]['publishedUrl']);
    }

    public function test_a_fetch_failure_null_leaves_everything_unchanged(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'articleId' => 100, 'position' => 0),
        ));
        PCM_DB::$articles[100] = array(
            'id' => 100, 'publishedUrl' => 'https://example.com/p/1', 'publishedPostId' => 555, 'siteId' => 9,
        );
        PCM_DB::$site = (object)array('id' => 9, 'name' => 'My Site', 'url' => 'https://example.com');
        // 555 is intentionally absent from $remoteStatuses -> fetch_remote_post_status() returns null.
        $strategy = (object)array('id' => 7, 'totalItems' => 1);

        $result = PCM_Strategy_Service::sync_items_from_wp($strategy, 1);

        $this->assertSame(array('checked' => 1, 'updated' => 0, 'deleted' => 0), $result, 'an unknown/unreachable remote must be a pure no-op, not a false deletion or update');
        $this->assertSame('https://example.com/p/1', PCM_DB::$articles[100]['publishedUrl']);
        $this->assertSame(555, PCM_DB::$articles[100]['publishedPostId']);
    }

    public function test_items_with_no_published_article_are_skipped_and_not_counted_as_checked(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'articleId' => null, 'position' => 0),
            array('id' => 2, 'keyword' => 'kw two', 'status' => 'completed', 'articleId' => 100, 'position' => 1),
        ));
        // Article 100 exists but has never been published (no publishedPostId/siteId).
        PCM_DB::$articles[100] = array('id' => 100, 'publishedUrl' => '', 'publishedPostId' => null, 'siteId' => null);
        $strategy = (object)array('id' => 7, 'totalItems' => 2);

        $result = PCM_Strategy_Service::sync_items_from_wp($strategy, 1);

        $this->assertSame(array('checked' => 0, 'updated' => 0, 'deleted' => 0), $result);
    }

    // ── D4: publish_item() passes schedule_date for a not-yet-due scheduled item ──

    public function test_publish_item_passes_schedule_date_for_a_future_scheduled_item_in_schedule_mode(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'articleId' => 100, 'position' => 0, 'scheduledDate' => '2099-01-01 00:00:00'),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'publishedUrl' => '', 'publishedPostId' => null, 'siteId' => null);
        PCM_DB::$site = (object)array('id' => 9, 'name' => 'My Site', 'url' => 'https://example.com');
        $strategy = (object)array(
            'id'             => 7,
            'publishingMode' => 'schedule',
            'config'         => json_encode(array('siteId' => 9)),
        );

        PCM_Strategy_Service::publish_item($strategy, 1, 1);

        $this->assertCount(1, PCM_Sites_Service::$calls);
        $this->assertSame('2099-01-01 00:00:00', PCM_Sites_Service::$calls[0]['options']['schedule_date'] ?? null, 'a not-yet-due scheduled item must pass schedule_date through to publish_to_site()');
    }

    public function test_publish_item_does_not_pass_schedule_date_for_an_already_due_item(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'articleId' => 100, 'position' => 0, 'scheduledDate' => '2020-01-01 00:00:00'),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'publishedUrl' => '', 'publishedPostId' => null, 'siteId' => null);
        PCM_DB::$site = (object)array('id' => 9, 'name' => 'My Site', 'url' => 'https://example.com');
        $strategy = (object)array(
            'id'             => 7,
            'publishingMode' => 'schedule',
            'config'         => json_encode(array('siteId' => 9)),
        );

        PCM_Strategy_Service::publish_item($strategy, 1, 1);

        $this->assertCount(1, PCM_Sites_Service::$calls);
        $this->assertArrayNotHasKey('schedule_date', PCM_Sites_Service::$calls[0]['options'], 'a due/past date must publish normally, not as a native future post');
    }

    public function test_publish_item_does_not_pass_schedule_date_outside_schedule_publishing_mode(): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'kw one', 'status' => 'completed', 'articleId' => 100, 'position' => 0, 'scheduledDate' => '2099-01-01 00:00:00'),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'publishedUrl' => '', 'publishedPostId' => null, 'siteId' => null);
        PCM_DB::$site = (object)array('id' => 9, 'name' => 'My Site', 'url' => 'https://example.com');
        $strategy = (object)array(
            'id'             => 7,
            'publishingMode' => 'publish', // not 'schedule' -- a stray scheduledDate must be ignored
            'config'         => json_encode(array('siteId' => 9)),
        );

        PCM_Strategy_Service::publish_item($strategy, 1, 1);

        $this->assertCount(1, PCM_Sites_Service::$calls);
        $this->assertArrayNotHasKey('schedule_date', PCM_Sites_Service::$calls[0]['options']);
    }
}
