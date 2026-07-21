<?php
/**
 * Unit Tests — Filip's simplified strategy-sections model (Source / Trigger /
 * Volume & cadence / Publishing / Duration / Research).
 *
 * Covers the four backend seams this round adds:
 *   (A) config-key sanitization/whitelisting in
 *       PCM_REST_Strategy::sanitize_config_fields() (the create/PATCH funnel) —
 *       exercised directly via reflection (a pure method whose only deps are the
 *       WP-function shims below);
 *   (B) maybe_auto_publish()'s draft-gate — driven end-to-end through the REAL
 *       PCM_Strategy_Service::generate_next_item();
 *   (C) research-pass selection + legacy back-compat — same generate path,
 *       asserting exactly which grounding prompts run;
 *   (D) zero-keyword create when sourceMode==='rss' (and the unchanged rejection
 *       for every other source) — driven through the REAL controller
 *       create_strategy() handler.
 *
 * House-style fakes: the shared in-process stand-ins (PCM_DB, PCM_Schema,
 * PCM_Sites_Service, PCM_Approvals_Service, WP shims, cron) come from
 * StrategyAutoPublishTest.php via pcm_test_define_strategy_fakes(). This suite's
 * OWN richer fakes — a PCM_LLM WITH invoke_with_grounding() (the shared one has
 * none), an absint() shim, and a minimal REST stack (PCM_REST_Base /
 * WP_REST_Request / WP_REST_Response / WP_Error) so the controller handler is
 * exercisable — are declared BEFORE that shared require, each behind a
 * class_exists(...,false) / function_exists() guard so they win the name race
 * and are isolated to this suite's own (per-method) processes.
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * Declares THIS suite's richer fakes exactly once per isolated process. Called
 * from setUp() BEFORE pcm_test_define_strategy_fakes() so its PCM_LLM /
 * PCM_REST_Base guards win the class_exists() race over the shared/real ones.
 */
function pcm_test_define_sections_fakes(): void
{
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/wordpress/');
    }
    // Not shimmed by the shared fakes — sanitize_config_fields() int-coerces
    // several nested values with it (rssCadence.perWeek, duration.maxArticles).
    if (!function_exists('absint')) {
        function absint($n)
        {
            return abs((int)$n);
        }
    }

    // PCM_LLM with grounding — mirrors StrategyResearchTest's fake so invoke_json
    // behaves identically, plus captures every invoke_with_grounding() call so a
    // test can assert both HOW MANY passes ran and WHICH prompt each one used.
    if (!class_exists('PCM_LLM', false)) {
        class PCM_LLM
        {
            public static $lastOptions = null;
            public static $throwOn = null;
            public static $callCount = 0;
            public static $lastUserMessage = null;
            public static $lastMessages = null;
            /** @var array<int,array{messages:array,options:array}> */
            public static $groundingCalls = array();
            public static $groundingThrow = false;

            public static function invoke_json($messages, $schema, $options)
            {
                self::$lastOptions = $options;
                self::$callCount++;
                self::$lastMessages = $messages;
                $user = '';
                foreach ($messages as $m) {
                    if (($m['role'] ?? '') === 'user') {
                        $user = $m['content'];
                    }
                }
                self::$lastUserMessage = $user;
                if (self::$throwOn && strpos($user, self::$throwOn) !== false) {
                    throw new \RuntimeException('LLM boom');
                }
                return array('title' => 'Generated Title', 'content' => '<p>body</p>', 'metaTitle' => 'MT', 'metaDescription' => 'MD');
            }

            public static function invoke_with_grounding($messages, $options = array())
            {
                self::$groundingCalls[] = array('messages' => $messages, 'options' => $options);
                if (self::$groundingThrow) {
                    throw new \RuntimeException('missing google api key');
                }
                return array('content' => 'RESEARCH FINDINGS X');
            }
        }
    }

    // ── Minimal REST stack so the REAL controller handler is drivable ──
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
    // Fake PCM_REST_Base — the controller's `extends PCM_REST_Base` resolves to
    // this (declared before controller.php is required), so no real WP-dependent
    // base is pulled in. Only the surface create_strategy() actually touches is
    // provided; success()/error() return the same WP_REST_Response|WP_Error the
    // real base does, so the handler's declared return type is satisfied.
    if (!class_exists('PCM_REST_Base', false)) {
        class PCM_REST_Base
        {
            public static $currentUserId = 1;
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
class StrategySectionsConfigTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_sections_fakes();  // MUST precede the shared fakes (PCM_LLM / PCM_REST_Base races)
        pcm_test_define_strategy_fakes();  // shared PCM_DB / PCM_Schema / PCM_Sites_Service / WP shims / cron
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/controller.php';

        PCM_DB::$items = array();
        PCM_DB::$strategy = array();
        PCM_DB::$strategyRow = null;
        PCM_DB::$site = null;
        PCM_DB::$articles = array();
        PCM_DB::$articleSeq = 100;
        PCM_DB::$forceNullArticle = false;
        PCM_DB::$forceClaimFail = false;
        PCM_DB::$loseGenerationClaims = 0;
        PCM_DB::$createStrategyId = 7;
        PCM_DB::$createStrategyData = null;
        PCM_DB::$dueScheduledStrategies = array();
        PCM_LLM::$lastOptions = null;
        PCM_LLM::$throwOn = null;
        PCM_LLM::$callCount = 0;
        PCM_LLM::$lastUserMessage = null;
        PCM_LLM::$lastMessages = null;
        PCM_LLM::$groundingCalls = array();
        PCM_LLM::$groundingThrow = false;
        PCM_Sites_Service::$calls = array();
        PCM_Sites_Service::$shouldThrow = false;
        PCM_Approvals_Service::$createSetCalls = array();
        PCM_Approvals_Service::$nextSetId = 501;
        PCM_Test_Cron::$scheduleCalls = array();
        PCM_Test_Cron::$alreadyScheduled = false;
        PCM_Test_Cron::$now = null;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function seedItems(array $items): void
    {
        foreach ($items as $it) {
            $o = (object)$it;
            PCM_DB::$items[$o->id] = $o;
        }
    }

    /** Invoke the controller's private sanitize_config_fields() on raw $fields. */
    private function sanitizeConfig(array $fields): array
    {
        // No setAccessible() — private methods are reflectively invokable since
        // PHP 8.1, and the call is a hard-deprecation under this suite's runner.
        $controller = new PCM_REST_Strategy();
        $method = new \ReflectionMethod(PCM_REST_Strategy::class, 'sanitize_config_fields');
        return $method->invoke($controller, $fields);
    }

    /** The user-role prompt content of the Nth (0-based) grounding call. */
    private function groundingPrompt(int $index): string
    {
        $call = PCM_LLM::$groundingCalls[$index] ?? null;
        if (!$call) {
            return '';
        }
        foreach ($call['messages'] as $m) {
            if (($m['role'] ?? '') === 'user') {
                return (string)$m['content'];
            }
        }
        return '';
    }

    // ── (1) Research-pass SELECTION ──────────────────────────────────────

    public function test_research_passes_only_questions_runs_exactly_that_prompt(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('researchPasses' => array('questions'))),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(1, PCM_LLM::$groundingCalls, "only 'questions' selected must run exactly one pass");
        // The single pass must be the QUESTIONS prompt, verbatim — not landscape.
        $this->assertStringContainsString('citable statistics or data points', $this->groundingPrompt(0));
        $this->assertStringNotContainsString('Research the current top-ranking content', $this->groundingPrompt(0));
        $this->assertSame('completed', PCM_DB::$items[1]->status);
    }

    public function test_research_passes_selection_order_is_canonical_not_input_order(): void
    {
        // Input order is deliberately scrambled + carries an unknown value; the
        // resolver must intersect against the canonical whitelist order.
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('researchPasses' => array('gaps', 'bogus', 'landscape'))),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(2, PCM_LLM::$groundingCalls, "unknown 'bogus' dropped; two valid passes remain");
        $this->assertStringContainsString('Research the current top-ranking content', $this->groundingPrompt(0), 'landscape runs first (canonical order)');
        $this->assertStringContainsString("competitors' top-ranking pages commonly miss", $this->groundingPrompt(1), 'gaps runs second');
    }

    // ── (2) Back-compat: legacy researchMode + explicit-empty override ───

    public function test_research_mode_deep_without_passes_makes_three_calls(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('researchMode' => 'deep')),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(3, PCM_LLM::$groundingCalls, "legacy researchMode='deep' (no researchPasses) must still run all 3 passes");
        $this->assertStringContainsString('Research the current top-ranking content', $this->groundingPrompt(0));
        $this->assertStringContainsString('citable statistics or data points', $this->groundingPrompt(1));
        $this->assertStringContainsString("competitors' top-ranking pages commonly miss", $this->groundingPrompt(2));
    }

    public function test_explicit_empty_research_passes_runs_nothing(): void
    {
        // An explicit empty array is the contract's "research off" signal — it
        // must WIN over any legacy researchMode/research flag also present.
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('researchPasses' => array(), 'researchMode' => 'deep', 'research' => true)),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(0, PCM_LLM::$groundingCalls, 'explicit empty researchPasses = research off, even alongside researchMode=deep');
        $this->assertSame('completed', PCM_DB::$items[1]->status);
    }

    // ── (3) Publishing draft-gate ────────────────────────────────────────

    public function test_draft_gate_blocks_publish_even_in_schedule_mode(): void
    {
        // Same site + schedule mode that WOULD publish (cf. StrategyAutoPublishTest's
        // test_schedule_mode_publishes_on_generation) — config.publishing='draft'
        // is the only thing blocking it.
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        PCM_DB::$site = (object)array('id' => 5, 'name' => 'My Site', 'url' => 'https://example.com');
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null, 'publishingMode' => 'schedule',
            'config' => json_encode(array('siteId' => 5, 'publishing' => 'draft')),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        $res = PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(0, PCM_Sites_Service::$calls, "config.publishing='draft' must block auto-publish");
        $this->assertArrayNotHasKey('publish', $res, 'no publish result is surfaced when the draft-gate returns null');
        $this->assertSame('completed', PCM_DB::$items[1]->status, 'the article is still generated, just not published');
    }

    public function test_absent_publishing_key_publishes_as_before(): void
    {
        // No config.publishing key → legacy behavior: publish still fires.
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        PCM_DB::$site = (object)array('id' => 5, 'name' => 'My Site', 'url' => 'https://example.com');
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null, 'publishingMode' => 'publish',
            'config' => json_encode(array('siteId' => 5)),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(1, PCM_Sites_Service::$calls, 'a strategy with no publishing key must auto-publish exactly as before');
    }

    // ── (4) Config-key sanitization ──────────────────────────────────────

    public function test_sanitize_drops_invalid_source_mode_and_keeps_valid(): void
    {
        $this->assertArrayNotHasKey('sourceMode', $this->sanitizeConfig(array('sourceMode' => 'telepathy')));
        $this->assertSame('rss', $this->sanitizeConfig(array('sourceMode' => 'rss'))['sourceMode']);
        $this->assertSame('keywords', $this->sanitizeConfig(array('sourceMode' => 'keywords'))['sourceMode']);
    }

    public function test_sanitize_clamps_rss_feeds_to_five_and_drops_invalid_urls(): void
    {
        // 7 valid http(s) feeds → clamped to the first 5.
        $seven = array();
        for ($i = 1; $i <= 7; $i++) {
            $seven[] = 'https://feed' . $i . '.example.com/rss';
        }
        $clamped = $this->sanitizeConfig(array('rssFeeds' => $seven))['rssFeeds'];
        $this->assertCount(5, $clamped, '7 feeds must clamp to 5');
        $this->assertSame('https://feed1.example.com/rss', $clamped[0]);
        $this->assertSame('https://feed5.example.com/rss', $clamped[4]);

        // Mixed list: non-http(s) / malformed entries are dropped, valid ones kept.
        $mixed = $this->sanitizeConfig(array('rssFeeds' => array(
            'https://good.example.com/feed',
            'ftp://nope.example.com/feed',   // wrong scheme → dropped
            'not a url at all',              // malformed → dropped
            'http://also-good.example.com/feed',
        )))['rssFeeds'];
        $this->assertSame(
            array('https://good.example.com/feed', 'http://also-good.example.com/feed'),
            $mixed,
            'only http(s) URLs survive; invalid entries are dropped'
        );

        // Nothing valid → the key is omitted entirely (never an empty array).
        $this->assertArrayNotHasKey('rssFeeds', $this->sanitizeConfig(array('rssFeeds' => array('mailto:x@y.z', 'javascript:alert(1)'))));
    }

    public function test_sanitize_clamps_and_shapes_the_remaining_section_keys(): void
    {
        // rssCadence.perWeek clamps 1–21; rssAngle caps at 200 chars.
        $this->assertSame(21, $this->sanitizeConfig(array('rssCadence' => array('perWeek' => 999)))['rssCadence']['perWeek']);
        $this->assertSame(1, $this->sanitizeConfig(array('rssCadence' => array('perWeek' => 0)))['rssCadence']['perWeek']);
        $this->assertSame(200, strlen($this->sanitizeConfig(array('rssAngle' => str_repeat('a', 500)))['rssAngle']));

        // trigger + publishing are strict whitelists (unknown → dropped).
        $this->assertSame('new_source_item', $this->sanitizeConfig(array('trigger' => 'new_source_item'))['trigger']);
        $this->assertArrayNotHasKey('trigger', $this->sanitizeConfig(array('trigger' => 'telekinesis')));
        $this->assertSame('draft', $this->sanitizeConfig(array('publishing' => 'draft'))['publishing']);
        $this->assertArrayNotHasKey('publishing', $this->sanitizeConfig(array('publishing' => 'someday')));

        // duration: mode whitelisted, endDate Y-m-d-validated, maxArticles clamped 1–500.
        $dur = $this->sanitizeConfig(array('duration' => array(
            'mode' => 'until', 'endDate' => '2026-08-01', 'maxArticles' => 9000,
        )))['duration'];
        $this->assertSame('until', $dur['mode']);
        $this->assertSame('2026-08-01', $dur['endDate']);
        $this->assertSame(500, $dur['maxArticles']);
        // Bad mode → whole key dropped; bad endDate → that sub-key dropped.
        $this->assertArrayNotHasKey('duration', $this->sanitizeConfig(array('duration' => array('mode' => 'forever'))));
        $this->assertArrayNotHasKey('endDate', $this->sanitizeConfig(array('duration' => array('mode' => 'until', 'endDate' => 'not-a-date')))['duration']);

        // researchPasses intersect + dedupe + canonical order; explicit [] kept.
        $this->assertSame(
            array('landscape', 'gaps'),
            $this->sanitizeConfig(array('researchPasses' => array('gaps', 'landscape', 'gaps', 'nope')))['researchPasses']
        );
        $this->assertSame(array(), $this->sanitizeConfig(array('researchPasses' => array()))['researchPasses']);
    }

    // ── (5) Zero-keyword RSS create ──────────────────────────────────────

    public function test_rss_create_allows_zero_keywords(): void
    {
        PCM_DB::$strategyRow = (object)array(
            'id' => 7, 'userId' => 1, 'name' => 'RSS Strategy', 'status' => 'pending',
            'publishingMode' => 'draft', 'config' => json_encode(array('sourceMode' => 'rss')), 'totalItems' => 0,
        );

        $request = new WP_REST_Request(array(
            'name' => 'RSS Strategy', 'templateId' => 3, 'keywords' => array(), 'sourceMode' => 'rss',
        ));
        $res = (new PCM_REST_Strategy())->create_strategy($request);

        $this->assertInstanceOf(WP_REST_Response::class, $res, 'RSS create with zero keywords must succeed');
        $this->assertSame(201, $res->status);
        $this->assertSame(0, (int)($res->data['totalItems'] ?? -1), 'the strategy is created with zero items');
        $this->assertSame(array(), $res->data['items'], 'no items until the RSS watcher adds them');
        // sourceMode='rss' must be persisted on the created row's config.
        $stored = json_decode((string)(PCM_DB::$createStrategyData['config'] ?? ''), true);
        $this->assertSame('rss', $stored['sourceMode'] ?? null);
    }

    public function test_non_rss_create_still_rejects_empty_keywords(): void
    {
        $request = new WP_REST_Request(array(
            'name' => 'KW Strategy', 'templateId' => 3, 'keywords' => array(),
        ));
        $res = (new PCM_REST_Strategy())->create_strategy($request);

        $this->assertInstanceOf(WP_Error::class, $res, 'keywords-mode create with no keywords must be rejected');
        $this->assertSame('Keywords array is required.', $res->message);
        $this->assertNull(PCM_DB::$createStrategyData, 'no strategy row is created on rejection');
    }
}
