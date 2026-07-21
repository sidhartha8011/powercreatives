<?php
/**
 * Unit Tests — Source=Social strategies (create-path split, watcher Apify
 * branch, social prompt rider; step 3 of the social-source plan).
 *
 * Exercises the REAL PCM_REST_Strategy / PCM_Strategy_Service /
 * PCM_Social_Source against in-process stand-ins:
 *
 *   (1) sanitize_config_fields(): sourceMode 'social' whitelisted; socialLinks
 *       mirrors rssFeeds (esc_url_raw + http(s) check) with a cap of 10;
 *   (2) create_strategy() validation BEFORE anything persists: social + zero
 *       links rejected; an Apify-platform ACCOUNT link without an Apify token
 *       rejected with the honest per-link error; with a token → accepted and
 *       the account persisted onto config.socialAccounts;
 *   (3) create-time split: a post link becomes ONE pending item immediately
 *       (keyword = post_context()'s URL-label fallback — no network in this
 *       harness — config carries sourceLink + social:true); a free YouTube
 *       /channel/UC… account converts to the native videos.xml feed merged
 *       into rssFeeds + the existing rss first-scan event armed;
 *   (4) social_scan_due() staleness math (absent → due, 3h → not due, 4h/5h → due);
 *   (5) the watcher's social branch end-to-end via run_rss_first_scan(): fake
 *       PCM_Apify items → real apify_map_items() → SAME ingest/pop path →
 *       item created with sourceText + social in its config; lastSocialScan
 *       stamped site-local; queue entries carry text + social through
 *       ingest_feed_items() while plain rss entries keep their exact shape;
 *   (6) rss_source_instruction(): the social wording (post text quoted +
 *       mandatory visible-link sentence) and the BYTE-IDENTICAL rss wording
 *       for non-social items.
 *
 * House-style fakes: this suite's own richer stand-ins — a PCM_DB with the
 * rss-watcher DB edges (same shape as StrategyRssFirstScanTest's), a minimal
 * REST stack (same shape as StrategySectionsConfigTest's), an absint() shim,
 * and a fake PCM_Apify (class_exists-guarded, configurable items + has_key) —
 * are declared BEFORE the shared require so they win the guard race; then the
 * shared WP shims / cron recorder from StrategyAutoPublishTest via
 * pcm_test_define_strategy_fakes(). The REAL PCM_Social_Source is hook-free
 * and required directly — its network edges (oEmbed / wp_remote_get) are
 * function_exists-guarded and degrade to the pure URL-label fallback here.
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/** Declares THIS suite's richer fakes exactly once per (isolated) process. */
function pcm_test_define_social_source_fakes(): void
{
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/wordpress/');
    }
    if (!function_exists('absint')) {
        function absint($n)
        {
            return abs((int)$n);
        }
    }

    // Fake PCM_Apify — MUST be declared before controller.php/service.php run
    // their guarded requires, so the guard sees it and never loads the real
    // client (whose token lookup needs a full $wpdb).
    if (!class_exists('PCM_Apify', false)) {
        class PCM_Apify
        {
            /** @var bool What has_key() reports for every user. */
            public static $hasKey = false;
            /** @var string|null Mirrors the real client's last-failure reason. */
            public static ?string $last_error = null;
            /** @var array<int,array> Raw dataset items fetch_account_items() returns. */
            public static $items = array();
            /** @var array<int,array{request:array,userId:int}> Every fetch call. */
            public static $fetchCalls = array();

            public static function has_key(int $user_id): bool
            {
                return self::$hasKey;
            }
            /** @var string|null When set, fetch fails with this as $last_error. */
            public static $failWith = null;

            public static function fetch_account_items(array $request, int $user_id): array
            {
                self::$fetchCalls[] = array('request' => $request, 'userId' => $user_id);
                self::$last_error = self::$failWith;
                return self::$failWith !== null ? array() : self::$items;
            }
        }
    }

    // Suite-local PCM_DB — declared before the shared fakes so this richer
    // stand-in (rss/social watcher DB edges included) wins the guard race.
    if (!class_exists('PCM_DB', false)) {
        class PCM_DB
        {
            public static $createStrategyId = 7;
            public static $createStrategyData = null;
            /** @var object|null What get_strategy() returns (ownership-checked getter). */
            public static $strategyRow = null;
            /** @var object|null What get_next_pending_item() returns. */
            public static $nextPendingItem = null;
            /** @var int What count_strategy_items() returns. */
            public static $itemCount = 0;
            /** @var array<int,array> Every create_rss_strategy_item() call. */
            public static $rssItemCalls = array();
            /** @var array<int,array> Every update_strategy() payload. */
            public static $updateStrategyCalls = array();
            /** @var array<int,array{id:int,data:array}> Every update_strategy_item() payload. */
            public static $updateItemCalls = array();

            public static function create_strategy($data)
            {
                self::$createStrategyData = $data;
                return self::$createStrategyId;
            }
            public static function create_strategy_items($strategy_id, $user_id, $keywords, $meta = array())
            {
                return count($keywords);
            }
            public static function get_strategy($id, $uid)
            {
                // Reflect the latest persisted config write (update_strategy) so
                // reload-after-write flows (e.g. scan_strategy_now's self-heal)
                // see what they just saved — like the real DB would.
                $row = self::$strategyRow;
                if ($row && self::$updateStrategyCalls !== array()) {
                    foreach (array_reverse(self::$updateStrategyCalls) as $c) {
                        if (isset($c['data']['config'])) {
                            $row = clone $row;
                            $row->config = $c['data']['config'];
                            break;
                        }
                    }
                }
                return $row;
            }
            public static function get_strategy_items($sid)
            {
                return array();
            }
            public static function get_next_pending_item($sid)
            {
                return self::$nextPendingItem;
            }
            public static function count_strategy_items($sid, $since = null)
            {
                return self::$itemCount;
            }
            public static function create_rss_strategy_item($strategy_id, $user_id, $keyword, $item_config = array())
            {
                self::$rssItemCalls[] = array(
                    'strategyId' => $strategy_id,
                    'userId'     => $user_id,
                    'keyword'    => $keyword,
                    'config'     => $item_config,
                );
                return 100 + count(self::$rssItemCalls);
            }
            public static function update_strategy($id, $uid, $data)
            {
                self::$updateStrategyCalls[] = array('id' => $id, 'userId' => $uid, 'data' => $data);
                return true;
            }
            public static function update_strategy_item($id, $data)
            {
                self::$updateItemCalls[] = array('id' => $id, 'data' => $data);
                return true;
            }
        }
    }

    // ── Minimal REST stack so the REAL controller handler is drivable
    //    (same shape as StrategySectionsConfigTest's). ──
    if (!class_exists('WP_REST_Response', false)) {
        class WP_REST_Response
        {
            public $data;
            public $status;
            public function __construct($data = null, $status = 200)
            {
                $this->data = $data;
                $this->status = $status;
            }
        }
    }
    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            public $code;
            public $message;
            public $data;
            public function __construct($code = '', $message = '', $data = null)
            {
                $this->code = $code;
                $this->message = $message;
                $this->data = $data;
            }
        }
    }
    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            private $json;
            public function __construct(array $json = array())
            {
                $this->json = $json;
            }
            public function get_json_params()
            {
                return $this->json;
            }
            public function get_param($key)
            {
                return $this->json[$key] ?? null;
            }
        }
    }
    if (!class_exists('PCM_REST_Base', false)) {
        class PCM_REST_Base
        {
            public static $currentUserId = 42;
            protected function get_current_pcm_user()
            {
                return (object)array('id' => self::$currentUserId);
            }
            protected function success($data, $status = 200)
            {
                return new WP_REST_Response($data, $status);
            }
            protected function error($message, $status = 400)
            {
                return new WP_Error('error', $message, array('status' => $status));
            }
            protected function not_found($what)
            {
                return new WP_Error('not_found', $what . ' not found', array('status' => 404));
            }
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategySocialSourceTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_social_source_fakes(); // richer PCM_DB / REST stack / fake PCM_Apify must win the races
        pcm_test_define_strategy_fakes();      // shared WP shims / cron recorder
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/class-pcm-social-source.php';
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/controller.php';

        PCM_DB::$createStrategyId = 7;
        PCM_DB::$createStrategyData = null;
        PCM_DB::$strategyRow = null;
        PCM_DB::$nextPendingItem = null;
        PCM_DB::$itemCount = 0;
        PCM_DB::$rssItemCalls = array();
        PCM_DB::$updateStrategyCalls = array();
        PCM_Apify::$hasKey = false;
        PCM_Apify::$items = array();
        PCM_Apify::$fetchCalls = array();
        PCM_Apify::$last_error = null;
        PCM_Apify::$failWith = null;
        PCM_REST_Base::$currentUserId = 42;
        PCM_Test_Cron::$scheduleCalls = array();
        PCM_Test_Cron::$alreadyScheduled = false;
        PCM_Test_Cron::$now = null;
    }

    /** Invoke the controller's private sanitize_config_fields() on raw $fields. */
    private function sanitizeConfig(array $fields): array
    {
        $controller = new PCM_REST_Strategy();
        $method = new \ReflectionMethod(PCM_REST_Strategy::class, 'sanitize_config_fields');
        return $method->invoke($controller, $fields);
    }

    /** Invoke the private rss_source_instruction() rider (PHP 8.1+: no setAccessible()). */
    private function sourceInstruction(array $item_cfg, string $angle): string
    {
        $method = new \ReflectionMethod(PCM_Strategy_Service::class, 'rss_source_instruction');
        return $method->invoke(null, $item_cfg, $angle);
    }

    /** Invoke the private maybe_enrich_social_post() with a fake item row. */
    private function enrich(array $item_cfg, int $user_id = 5, int $item_id = 42): array
    {
        $item = (object) array('id' => $item_id, 'config' => wp_json_encode($item_cfg));
        $method = new \ReflectionMethod(PCM_Strategy_Service::class, 'maybe_enrich_social_post');
        return $method->invoke(null, $item, $item_cfg, $user_id);
    }

    /** Hooks of every wp_schedule_single_event() call this test recorded. */
    private function scheduledHooks(): array
    {
        return array_column(PCM_Test_Cron::$scheduleCalls, 'hook');
    }

    /** The last update_strategy() call's decoded config payload (or null). */
    private function lastWrittenConfig(): ?array
    {
        foreach (array_reverse(PCM_DB::$updateStrategyCalls) as $call) {
            if (isset($call['data']['config'])) {
                return json_decode((string)$call['data']['config'], true);
            }
        }
        return null;
    }

    // ── (1) Sanitization: sourceMode 'social' + socialLinks ──────────────

    public function test_sanitize_accepts_social_source_mode_and_clamps_links_to_ten(): void
    {
        // 'social' joins the sourceMode whitelist; junk still drops the key.
        $this->assertSame('social', $this->sanitizeConfig(array('sourceMode' => 'social'))['sourceMode']);
        $this->assertArrayNotHasKey('sourceMode', $this->sanitizeConfig(array('sourceMode' => 'telepathy')));

        // 11 valid links clamp to the first 10 (rssFeeds handling, cap 10).
        $eleven = array();
        for ($i = 1; $i <= 11; $i++) {
            $eleven[] = 'https://x.com/user' . $i . '/status/' . $i;
        }
        $clamped = $this->sanitizeConfig(array('socialLinks' => $eleven))['socialLinks'];
        $this->assertCount(10, $clamped, '11 links must clamp to 10');
        $this->assertSame('https://x.com/user1/status/1', $clamped[0]);
        $this->assertSame('https://x.com/user10/status/10', $clamped[9]);

        // Non-http(s)/malformed entries drop; valid ones survive in order.
        $mixed = $this->sanitizeConfig(array('socialLinks' => array(
            'https://www.instagram.com/p/Cabc123/',
            'ftp://nope.example.com/post',
            'not a url at all',
            'http://bsky.app/profile/user.bsky.social/post/3kabc',
        )))['socialLinks'];
        $this->assertSame(
            array('https://www.instagram.com/p/Cabc123/', 'http://bsky.app/profile/user.bsky.social/post/3kabc'),
            $mixed
        );

        // Nothing valid → the key is omitted entirely (never an empty array).
        $this->assertArrayNotHasKey('socialLinks', $this->sanitizeConfig(array('socialLinks' => array('javascript:alert(1)'))));
        $this->assertArrayNotHasKey('socialLinks', $this->sanitizeConfig(array('socialLinks' => 'not-an-array')));
    }

    // ── (2) Create-time validation (before anything persists) ────────────

    public function test_social_create_with_zero_links_is_rejected_before_create(): void
    {
        $request = new WP_REST_Request(array(
            'name' => 'Social Strategy', 'templateId' => 3, 'keywords' => array(),
            'sourceMode' => 'social', 'socialLinks' => array(),
        ));
        $res = (new PCM_REST_Strategy())->create_strategy($request);

        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame('At least one social post or account link is required.', $res->message);
        $this->assertNull(PCM_DB::$createStrategyData, 'nothing may persist on rejection');
    }

    public function test_social_create_rejects_apify_account_link_without_apify_key(): void
    {
        PCM_Apify::$hasKey = false;

        $request = new WP_REST_Request(array(
            'name' => 'Social Strategy', 'templateId' => 3, 'keywords' => array(),
            'sourceMode' => 'social',
            'socialLinks' => array(
                'https://x.com/naval/status/1002103360646823936', // post — never needs Apify
                'https://www.instagram.com/nasa',                 // ACCOUNT on an Apify platform
            ),
        ));
        $res = (new PCM_REST_Strategy())->create_strategy($request);

        $this->assertInstanceOf(WP_Error::class, $res, 'an Apify-platform account without a token must be rejected');
        $this->assertSame(
            'Watching instagram accounts needs your Apify API token — add it under Integrations first. (https://www.instagram.com/nasa)',
            $res->message
        );
        $this->assertNull(PCM_DB::$createStrategyData, 'nothing may persist on rejection — validation runs BEFORE create');
        $this->assertCount(0, PCM_DB::$rssItemCalls, 'no items may be created either');
    }

    public function test_social_create_with_apify_key_is_accepted_and_persists_social_accounts(): void
    {
        PCM_Apify::$hasKey = true;
        PCM_DB::$strategyRow = (object)array('id' => 7, 'userId' => 42, 'status' => 'pending');

        $request = new WP_REST_Request(array(
            'name' => 'Social Strategy', 'templateId' => 3, 'keywords' => array(),
            'sourceMode' => 'social',
            'socialLinks' => array('https://www.instagram.com/nasa'),
        ));
        $res = (new PCM_REST_Strategy())->create_strategy($request);

        $this->assertInstanceOf(WP_REST_Response::class, $res, 'with a token the same account link must be accepted');
        $this->assertSame(201, $res->status);
        // sourceMode + socialLinks persisted on the created row's config.
        $stored = json_decode((string)(PCM_DB::$createStrategyData['config'] ?? ''), true);
        $this->assertSame('social', $stored['sourceMode'] ?? null);
        $this->assertSame(array('https://www.instagram.com/nasa'), $stored['socialLinks'] ?? null);
        // The create split routed the account onto config.socialAccounts.
        $written = $this->lastWrittenConfig();
        $this->assertSame(
            array(array('url' => 'https://www.instagram.com/nasa', 'platform' => 'instagram')),
            $written['socialAccounts'] ?? null,
            'the Apify-platform account must persist onto config.socialAccounts'
        );
        $this->assertCount(0, PCM_DB::$rssItemCalls, 'an account link never becomes a one-off post item');
    }

    // ── (3) Create-time split: posts and free-platform accounts ──────────

    public function test_create_split_turns_a_post_link_into_a_pending_item_with_social_flag(): void
    {
        PCM_DB::$strategyRow = (object)array('id' => 7, 'userId' => 42, 'status' => 'pending');
        PCM_DB::$itemCount = 1; // what count_strategy_items() reports after the insert
        PCM_DB::$nextPendingItem = (object)array('id' => 101, 'status' => 'pending');

        PCM_Strategy_Service::create_from_keywords(
            42,
            'Social Strategy',
            3,
            null,
            array(), // social strategies are created link-only — no keyword items
            array(
                'publishingMode' => 'draft',
                'config' => array(
                    'sourceMode'  => 'social',
                    'socialLinks' => array('https://x.com/naval/status/1002103360646823936'),
                ),
            )
        );

        // No network in this harness → post_context() lands on the URL-label
        // fallback title; the item still gets created (never-throws contract).
        $this->assertCount(1, PCM_DB::$rssItemCalls, 'a post link must become exactly one pending item at create');
        $call = PCM_DB::$rssItemCalls[0];
        $this->assertSame(7, $call['strategyId']);
        $this->assertSame(42, $call['userId']);
        $this->assertSame('X post by naval', $call['keyword'], 'keyword = post_context() URL-label fallback title');
        $this->assertSame('https://x.com/naval/status/1002103360646823936', $call['config']['sourceLink'] ?? null);
        $this->assertSame('X post by naval', $call['config']['sourceTitle'] ?? null);
        $this->assertTrue($call['config']['social'] ?? null, 'the item config must carry the social flag for the rider');

        // totalItems bumps the way the rss watcher does after inserts.
        $totals = array_filter(PCM_DB::$updateStrategyCalls, static fn($c) => isset($c['data']['totalItems']));
        $this->assertNotCount(0, $totals, 'totalItems must be recounted after the insert');
        $this->assertSame(1, array_values($totals)[0]['data']['totalItems']);

        // The existing background queue is kicked once for the new item.
        $this->assertContains('pcm_strategy_process_queue', $this->scheduledHooks());
        // A pure post link converts no feeds — no rss first scan.
        $this->assertNotContains('pcm_strategy_rss_first_scan', $this->scheduledHooks());
    }

    public function test_create_split_converts_free_youtube_account_to_feed_and_arms_first_scan(): void
    {
        PCM_DB::$strategyRow = (object)array('id' => 7, 'userId' => 42, 'status' => 'pending');

        PCM_Strategy_Service::create_from_keywords(
            42,
            'Social Strategy',
            3,
            null,
            array(),
            array(
                'publishingMode' => 'draft',
                'config' => array(
                    'sourceMode'  => 'social',
                    'socialLinks' => array('https://www.youtube.com/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw'),
                ),
            )
        );

        // The /channel/UC… form converts purely (no page resolve needed) and
        // merges into rssFeeds — the existing RSS watcher takes over.
        $written = $this->lastWrittenConfig();
        $this->assertSame(
            array('https://www.youtube.com/feeds/videos.xml?channel_id=UC_x5XG1OV2P6uZZ5FSM9Ttw'),
            $written['rssFeeds'] ?? null,
            'the free-platform account must convert into the native feed URL'
        );
        $this->assertCount(0, PCM_DB::$rssItemCalls, 'an account link never becomes a one-off post item');

        // The EXISTING rss instant first pull is armed with the same args shape.
        $this->assertContains('pcm_strategy_rss_first_scan', $this->scheduledHooks());
        foreach (PCM_Test_Cron::$scheduleCalls as $call) {
            if ($call['hook'] === 'pcm_strategy_rss_first_scan') {
                $this->assertSame(array(7, 42), $call['args'], 'event args must be (strategyId, ownerId)');
            }
        }
    }

    // ── (4) social_scan_due: the ≥4h staleness gate ──────────────────────

    public function test_social_scan_due_math(): void
    {
        $now = (int)strtotime('2026-07-16 12:00:00');

        $this->assertTrue(PCM_Strategy_Service::social_scan_due('', $now), 'never scanned → due');
        $this->assertTrue(PCM_Strategy_Service::social_scan_due('not-a-date', $now), 'unparseable stamp → due (fail open)');
        $this->assertFalse(PCM_Strategy_Service::social_scan_due('2026-07-16 09:00:00', $now), '3h old → not yet due');
        $this->assertTrue(PCM_Strategy_Service::social_scan_due('2026-07-16 08:00:00', $now), 'exactly 4h old → due (>=)');
        $this->assertTrue(PCM_Strategy_Service::social_scan_due('2026-07-16 07:00:00', $now), '5h old → due');
    }

    // ── (5) Watcher social branch: Apify items → ingest → pop → item ─────

    public function test_watcher_social_branch_ingests_apify_items_and_creates_item_with_source_text(): void
    {
        PCM_Test_Cron::$now = '2026-07-16 12:00:00';
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'userId' => 42, 'status' => 'pending',
            'config' => json_encode(array(
                'sourceMode'     => 'social',
                'socialLinks'    => array('https://www.instagram.com/nasa'),
                'socialAccounts' => array(array('url' => 'https://www.instagram.com/nasa', 'platform' => 'instagram')),
                // no lastSocialScan → the Apify branch is due on the first pass
            )),
        );
        PCM_DB::$nextPendingItem = (object)array('id' => 101, 'status' => 'pending');
        PCM_Apify::$items = array(
            array(
                'shortCode' => 'Cabc123',
                'url'       => 'https://www.instagram.com/p/Cabc123/',
                'id'        => '321',
                'caption'   => 'Big news from the space station today',
                'timestamp' => '2026-07-15T10:00:00.000Z',
            ),
        );

        PCM_Strategy_Service::run_rss_first_scan(7, 42);

        // The fetch went through the real apify_request() spec (limit 10) with
        // the strategy OWNER's id — never get_current_user_id() in cron.
        $this->assertCount(1, PCM_Apify::$fetchCalls);
        $this->assertSame('apify~instagram-scraper', PCM_Apify::$fetchCalls[0]['request']['actor'] ?? null);
        $this->assertSame(10, PCM_Apify::$fetchCalls[0]['request']['input']['resultsLimit'] ?? null, 'cost control: 10 items per account per scan');
        $this->assertSame(42, PCM_Apify::$fetchCalls[0]['userId'], 'the token lookup must use the strategy owner id');

        // The mapped item rode the SAME ingest/pop path into a pending item —
        // with the post text + social flag in its config for the rider.
        $this->assertCount(1, PCM_DB::$rssItemCalls, 'the Apify item must become a strategy item');
        $call = PCM_DB::$rssItemCalls[0];
        $this->assertSame('Big news from the space station today', $call['keyword'], 'keyword = caption-derived title');
        $this->assertSame('https://www.instagram.com/p/Cabc123/', $call['config']['sourceLink'] ?? null);
        $this->assertSame('Big news from the space station today', $call['config']['sourceText'] ?? null, 'the post text must reach the item config');
        $this->assertTrue($call['config']['social'] ?? null, 'watcher-created Apify items must carry the social flag');

        // lastSocialScan stamped site-local and persisted with the config write.
        $written = $this->lastWrittenConfig();
        $this->assertSame('2026-07-16 12:00:00', $written['lastSocialScan'] ?? null);
        $this->assertSame(array(), $written['rssQueue'] ?? null, 'the popped entry must leave the queue');
        $this->assertContains(md5('https://www.instagram.com/p/Cabc123/'), $written['rssSeen'] ?? array(), 'the guid is marked seen — never re-ingested');
        // And the background queue got its kick for the new pending item.
        $this->assertContains('pcm_strategy_process_queue', $this->scheduledHooks());
    }

    public function test_watcher_social_branch_respects_the_four_hour_gate(): void
    {
        PCM_Test_Cron::$now = '2026-07-16 12:00:00';
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'userId' => 42, 'status' => 'pending',
            'config' => json_encode(array(
                'sourceMode'     => 'social',
                'socialAccounts' => array(array('url' => 'https://www.instagram.com/nasa', 'platform' => 'instagram')),
                'lastSocialScan' => '2026-07-16 10:00:00', // 2h ago — NOT due
            )),
        );
        PCM_Apify::$items = array(array('shortCode' => 'Cabc123', 'caption' => 'x'));

        PCM_Strategy_Service::run_rss_first_scan(7, 42);

        $this->assertCount(0, PCM_Apify::$fetchCalls, 'a fresh lastSocialScan must skip the Apify fetch entirely (cost control)');
        $this->assertCount(0, PCM_DB::$rssItemCalls);
    }

    /**
     * "Scan now" (scan_strategy_now → force=true) bypasses the 4h cadence gate:
     * the SAME fresh-lastSocialScan strategy that the auto-scan skips above must
     * fetch and create an item on a manual scan — and report the created count.
     */
    public function test_scan_strategy_now_bypasses_the_four_hour_gate(): void
    {
        PCM_Test_Cron::$now = '2026-07-16 12:00:00';
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'userId' => 42, 'status' => 'pending',
            'config' => json_encode(array(
                'sourceMode'     => 'social',
                'socialAccounts' => array(array('url' => 'https://www.instagram.com/nasa', 'platform' => 'instagram')),
                'lastSocialScan' => '2026-07-16 10:00:00', // 2h ago — auto-scan would SKIP
            )),
        );
        PCM_DB::$nextPendingItem = (object)array('id' => 101, 'status' => 'pending');
        PCM_Apify::$items = array(array(
            'shortCode' => 'Cxyz789',
            'url'       => 'https://www.instagram.com/p/Cxyz789/',
            'id'        => '999',
            'caption'   => 'Manual scan pulled this post',
            'timestamp' => '2026-07-16T11:00:00.000Z',
        ));

        $result = PCM_Strategy_Service::scan_strategy_now(7, 42);

        $this->assertSame(array('created' => 1), $result, 'force scan creates the item and reports the count');
        $this->assertCount(1, PCM_Apify::$fetchCalls, 'force must bypass the 4h gate and fetch');
        $this->assertCount(1, PCM_DB::$rssItemCalls);
        $this->assertSame('Manual scan pulled this post', PCM_DB::$rssItemCalls[0]['keyword']);
    }

    public function test_scan_strategy_now_on_missing_strategy_is_safe(): void
    {
        PCM_DB::$strategyRow = null; // get_strategy() returns nothing (deleted / not owned)
        $this->assertSame(array('created' => 0), PCM_Strategy_Service::scan_strategy_now(7, 42));
        $this->assertCount(0, PCM_Apify::$fetchCalls);
    }

    // ── (8) Scan-now zero-item diagnostics + zombie self-heal ─────────────

    public function test_scan_now_reports_missing_apify_key_as_the_reason(): void
    {
        PCM_Test_Cron::$now = '2026-07-16 12:00:00';
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'userId' => 42, 'status' => 'pending',
            'config' => json_encode(array(
                'sourceMode'     => 'social',
                'socialAccounts' => array(array('url' => 'https://www.instagram.com/nasa', 'platform' => 'instagram')),
            )),
        );
        PCM_Apify::$hasKey = false;
        PCM_Apify::$failWith = 'No active Apify API key for this user — add it under Integrations.';

        $result = PCM_Strategy_Service::scan_strategy_now(7, 42);

        $this->assertSame(0, $result['created']);
        $this->assertSame('no_apify_key', $result['reason'], 'the toast must say the key is missing, not "no new posts"');
    }

    public function test_scan_now_reports_fetch_failure_with_detail(): void
    {
        PCM_Test_Cron::$now = '2026-07-16 12:00:00';
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'userId' => 42, 'status' => 'pending',
            'config' => json_encode(array(
                'sourceMode'     => 'social',
                'socialAccounts' => array(array('url' => 'https://www.instagram.com/nasa', 'platform' => 'instagram')),
            )),
        );
        PCM_Apify::$hasKey = true;
        PCM_Apify::$failWith = 'Apify returned HTTP 401.';

        $result = PCM_Strategy_Service::scan_strategy_now(7, 42);

        $this->assertSame(0, $result['created']);
        $this->assertSame('fetch_failed', $result['reason']);
        $this->assertSame('Apify returned HTTP 401.', $result['detail'], 'the Apify failure detail must reach the toast');
    }

    public function test_scan_now_reports_volume_cap_when_posts_wait_in_the_queue(): void
    {
        PCM_Test_Cron::$now = '2026-07-16 12:00:00';
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'userId' => 42, 'status' => 'pending',
            'config' => json_encode(array(
                'sourceMode'     => 'social',
                'socialAccounts' => array(array('url' => 'https://www.instagram.com/nasa', 'platform' => 'instagram')),
                'rssCadence'     => array('perWeek' => 1),
            )),
        );
        PCM_DB::$itemCount = 5; // 5 items this week ≥ perWeek 1 → zero free slots
        PCM_Apify::$hasKey = true;
        PCM_Apify::$items = array(array(
            'shortCode' => 'Cnew111', 'url' => 'https://www.instagram.com/p/Cnew111/',
            'id' => '111', 'caption' => 'Fresh post', 'timestamp' => '2026-07-16T11:00:00.000Z',
        ));

        $result = PCM_Strategy_Service::scan_strategy_now(7, 42);

        $this->assertSame(0, $result['created'], 'backpressure must hold even on a forced scan');
        $this->assertSame('volume_capped', $result['reason'], 'the fetched post waits in the queue — say so');
    }

    /**
     * The prod zombie shape: sourceMode=social with pasted socialLinks but NO
     * persisted socialAccounts/rssFeeds (old build's create died mid-split).
     * Scan now must repair it — re-split the links, persist the accounts, and
     * pull posts in the same click.
     */
    public function test_scan_now_self_heals_a_zombie_social_strategy(): void
    {
        PCM_Test_Cron::$now = '2026-07-16 12:00:00';
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'userId' => 42, 'status' => 'pending',
            'config' => json_encode(array(
                'sourceMode'  => 'social',
                'socialLinks' => array('https://www.instagram.com/nasa'),
                // NO socialAccounts, NO rssFeeds — the zombie.
            )),
        );
        PCM_Apify::$hasKey = true;
        PCM_Apify::$items = array(array(
            'shortCode' => 'Cheal42', 'url' => 'https://www.instagram.com/p/Cheal42/',
            'id' => '842', 'caption' => 'Healed and pulled', 'timestamp' => '2026-07-16T11:00:00.000Z',
        ));

        $result = PCM_Strategy_Service::scan_strategy_now(7, 42);

        // The split persisted the derived watched account…
        $written = $this->lastWrittenConfig();
        $this->assertSame(
            array(array('url' => 'https://www.instagram.com/nasa', 'platform' => 'instagram')),
            $written['socialAccounts'] ?? null,
            'the heal must persist the re-derived watched account'
        );
        // …and the same click fetched and created the item.
        $this->assertCount(1, PCM_Apify::$fetchCalls, 'the healed account must be fetched in the same scan');
        $this->assertSame(1, $result['created']);
        $this->assertSame('Healed and pulled', PCM_DB::$rssItemCalls[0]['keyword'] ?? null);
    }

    public function test_ingest_carries_text_and_social_on_apify_entries_and_keeps_rss_entries_byte_identical(): void
    {
        $raw = array(
            // A social (Apify-mapped) item — carries text + social.
            array('permalink' => 'https://www.instagram.com/p/Cabc123/', 'id' => '321', 'title' => 'IG title', 'date' => 2000, 'text' => 'Post caption here', 'social' => true),
            // A plain rss feed item — no text/social keys at all.
            array('permalink' => 'https://feed.example/post-1', 'id' => '', 'title' => 'Feed title', 'date' => 1000),
        );

        $out = PCM_Strategy_Service::ingest_feed_items($raw, array('rssSeen' => array(), 'rssQueue' => array()));

        $social_entry = $out['rssQueue'][0]; // ts 2000 — freshest first
        $this->assertSame('Post caption here', $social_entry['text'] ?? null, 'the Apify entry must carry the post text');
        $this->assertTrue($social_entry['social'] ?? null, 'the Apify entry must carry the social flag');

        $rss_entry = $out['rssQueue'][1];
        $this->assertSame(
            array('guid' => md5('https://feed.example/post-1'), 'title' => 'Feed title', 'link' => 'https://feed.example/post-1', 'ts' => 1000),
            $rss_entry,
            'a plain rss queue entry must keep its EXACT historical shape — no text/social keys, not even empty ones'
        );

        // Re-normalization on a later pass preserves the social carry-through.
        $again = PCM_Strategy_Service::ingest_feed_items(array(), $out);
        $this->assertSame($out['rssQueue'], $again['rssQueue'], 'text/social survive queue re-normalization; rss entries stay identical');
    }

    // ── (6) The prompt rider: social wording; rss byte-identical ─────────

    public function test_rider_social_wording_with_text_angle_and_link_include_sentence(): void
    {
        $cfg = array(
            'sourceLink'  => 'https://www.instagram.com/p/Cabc123/',
            'sourceTitle' => 'Cool Post',
            'sourceText'  => 'Space is big',
            'social'      => true,
        );

        $with = $this->sourceInstruction($cfg, 'ai seo');
        $this->assertSame(
            "Write an article about this social media post: 'Cool Post' (https://www.instagram.com/p/Cabc123/)."
            . ' The post says: "Space is big".'
            . " Tailor it to the angle/primary keyword: 'ai seo'."
            . ' INCLUDE a visible link to the original post in the article HTML.',
            $with
        );

        // No captured text and no angle → both optional clauses drop; the
        // visible-link requirement always stands.
        $no_text = $this->sourceInstruction(array('sourceLink' => 'https://x.com/naval/status/1', 'sourceTitle' => 'X post by naval', 'social' => true), '');
        $this->assertSame(
            "Write an article about this social media post: 'X post by naval' (https://x.com/naval/status/1)."
            . ' INCLUDE a visible link to the original post in the article HTML.',
            $no_text
        );
    }

    public function test_rider_rss_items_keep_the_byte_identical_wording(): void
    {
        $cfg = array('sourceLink' => 'https://x.example/original', 'sourceTitle' => 'Cool Post');

        // Captured BEFORE this round from the shipped implementation — byte-identical.
        $this->assertSame(
            "This article responds to a new industry item: 'Cool Post' (https://x.example/original). "
            . "Write a better, more complete take on that topic, tailored to the angle/primary keyword: 'ai seo'. "
            . 'Do not copy the source; outdo it.',
            $this->sourceInstruction($cfg, 'ai seo')
        );
        $this->assertSame(
            "This article responds to a new industry item: 'Cool Post' (https://x.example/original). "
            . 'Write a better, more complete take on that topic. Do not copy the source; outdo it.',
            $this->sourceInstruction($cfg, '')
        );
        // Non-source items still contribute nothing.
        $this->assertSame('', $this->sourceInstruction(array(), 'ai seo'));
    }

    // ── (7) Generation-time Apify enrichment of bare post links ───────────

    /**
     * The bug this fixes: a pasted IG/X/FB post link has NO caption at create
     * time (public oEmbed is dead), so the item carries the bare URL as title
     * and empty sourceText. At generation time, with an Apify key present, the
     * post is fetched, the caption fills sourceText, the URL-placeholder title
     * is upgraded, and the enrichment is persisted back onto the item.
     */
    public function test_enrich_fetches_apify_caption_for_bare_instagram_post(): void
    {
        PCM_Apify::$hasKey = true;
        PCM_Apify::$items  = array(array(
            'shortCode' => 'Da5ynsiuAZ_',
            'url'       => 'https://www.instagram.com/p/Da5ynsiuAZ_/',
            'id'        => '123',
            'caption'   => 'Mars in motion! Our Psyche spacecraft is on its way.',
            'timestamp' => '2026-07-17T17:56:51.000Z',
        ));
        PCM_DB::$updateItemCalls = array();

        $link = 'https://www.instagram.com/p/Da5ynsiuAZ_/';
        $out = $this->enrich(array(
            'sourceLink'  => $link,
            'sourceTitle' => $link, // URL placeholder from create-time fallback
            'sourceText'  => '',
            'social'      => true,
        ), 5, 42);

        // Fetch happened once, through the real apify_request() IG spec at limit 1.
        $this->assertCount(1, PCM_Apify::$fetchCalls);
        $this->assertSame('apify~instagram-scraper', PCM_Apify::$fetchCalls[0]['request']['actor']);
        // The request builder normalizes the URL (query/fragment/trailing slash
        // stripped) before it becomes actor input.
        $this->assertSame(array('https://www.instagram.com/p/Da5ynsiuAZ_'), PCM_Apify::$fetchCalls[0]['request']['input']['directUrls']);
        $this->assertSame(1, PCM_Apify::$fetchCalls[0]['request']['input']['resultsLimit']);

        // Caption filled; URL-placeholder title upgraded to the caption lead.
        $this->assertStringContainsString('Mars in motion', $out['sourceText']);
        $this->assertStringContainsString('Mars in motion', $out['sourceTitle']);
        $this->assertNotSame($link, $out['sourceTitle']);

        // Persisted back onto the item so a re-run never re-bills Apify.
        $this->assertCount(1, PCM_DB::$updateItemCalls);
        $this->assertSame(42, PCM_DB::$updateItemCalls[0]['id']);
        $persisted = json_decode((string) PCM_DB::$updateItemCalls[0]['data']['config'], true);
        $this->assertStringContainsString('Mars in motion', $persisted['sourceText']);
    }

    public function test_enrich_no_ops_when_source_text_already_present(): void
    {
        PCM_Apify::$hasKey = true;
        PCM_Apify::$fetchCalls = array();
        PCM_DB::$updateItemCalls = array();

        $cfg = array(
            'sourceLink'  => 'https://www.instagram.com/p/Cabc/',
            'sourceTitle' => 'Already known',
            'sourceText'  => 'The caption is already here.',
            'social'      => true,
        );
        $out = $this->enrich($cfg);

        $this->assertSame($cfg, $out);            // unchanged
        $this->assertCount(0, PCM_Apify::$fetchCalls);   // no Apify spend
        $this->assertCount(0, PCM_DB::$updateItemCalls);
    }

    public function test_enrich_no_ops_without_apify_key(): void
    {
        PCM_Apify::$hasKey = false;
        PCM_Apify::$fetchCalls = array();

        $out = $this->enrich(array(
            'sourceLink'  => 'https://www.instagram.com/p/Cabc/',
            'sourceTitle' => 'https://www.instagram.com/p/Cabc/',
            'sourceText'  => '',
            'social'      => true,
        ));

        $this->assertSame('', $out['sourceText']);        // still bare
        $this->assertCount(0, PCM_Apify::$fetchCalls);    // never called without a key
    }

    public function test_enrich_skips_free_platforms_and_tiktok(): void
    {
        PCM_Apify::$hasKey = true;
        PCM_Apify::$fetchCalls = array();

        // YouTube (free platform, oEmbed works) — must not touch Apify.
        $this->enrich(array(
            'sourceLink' => 'https://www.youtube.com/watch?v=abc',
            'sourceText' => '', 'social' => true,
        ));
        // TikTok post — actor is profile-based, so a single fetch would return
        // the WRONG (latest) video; enrichment deliberately skips it.
        $this->enrich(array(
            'sourceLink' => 'https://www.tiktok.com/@nasa/video/123',
            'sourceText' => '', 'social' => true,
        ));

        $this->assertCount(0, PCM_Apify::$fetchCalls);
    }

    public function test_enrich_ignores_non_social_items(): void
    {
        PCM_Apify::$hasKey = true;
        PCM_Apify::$fetchCalls = array();

        // An RSS item (no social flag) on an instagram-looking link never enriches.
        $this->enrich(array(
            'sourceLink' => 'https://www.instagram.com/p/Cabc/',
            'sourceText' => '',
            // no 'social' => true
        ));

        $this->assertCount(0, PCM_Apify::$fetchCalls);
    }
}
