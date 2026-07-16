<?php
/**
 * Unit Tests — Strategy research enrichment (B1/B2).
 *
 * Exercises the REAL PCM_Strategy_Service::generate_next_item() to verify the
 * optional live-research step (maybe_research_context() → build_prompt()):
 *   (a) config.research=true  → invoke_with_grounding() called once, and its
 *       summary is folded into the LLM's SYSTEM prompt;
 *   (b) config.research absent → grounding never called;
 *   (c) grounding throws       → article still generates, un-enriched (research
 *       failure must NEVER fail generation).
 *
 * Reuses StrategyAutoPublishTest.php's in-process fakes (PCM_DB, PCM_Schema,
 * PCM_Sites_Service, PCM_Approvals_Service, WP-function shims, the cron
 * helper) — see that file's own docblock for the process-isolation rationale.
 * The ONE fake it can't reuse is PCM_LLM: the sibling's has no
 * invoke_with_grounding(). So this file declares its OWN PCM_LLM — with BOTH
 * invoke_json() (behaviour copied verbatim from the sibling) AND
 * invoke_with_grounding() — and, crucially, declares it BEFORE calling
 * pcm_test_define_strategy_fakes(), whose `class_exists('PCM_LLM', false)`
 * guard then sees it already declared and skips the sibling's version. Every
 * method runs in its own process (@runTestsInSeparateProcesses below), so this
 * override is isolated to this suite and never leaks into the sibling's run.
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * Declares THIS suite's PCM_LLM (with grounding support) exactly once per
 * isolated process. Called from setUp() BEFORE pcm_test_define_strategy_fakes()
 * so it wins the class_exists() race for the PCM_LLM name.
 */
function pcm_test_define_research_llm(): void
{
    if (!class_exists('PCM_LLM', false)) {
        class PCM_LLM
        {
            public static $lastOptions = null;
            /** @var string|null Keyword substring to fail generation on. */
            public static $throwOn = null;
            /** @var int Total invoke_json() calls this test. */
            public static $callCount = 0;
            /** @var string|null The last invoke_json() user-role message content. */
            public static $lastUserMessage = null;
            /** @var array|null The last invoke_json() $messages, for asserting on
             *  the SYSTEM prompt (research block injection). */
            public static $lastMessages = null;

            /** @var array<int,array{messages:array,options:array}> every
             *  invoke_with_grounding() call — lets a test assert "called once". */
            public static $groundingCalls = array();
            /** @var bool When true, invoke_with_grounding() throws — simulates
             *  the missing-Google-key case (research must degrade silently). */
            public static $groundingThrow = false;
            /** @var array<int,array>|null When set, each sequential
             *  invoke_with_grounding() call returns groundingReturns[N] (0-based
             *  call index) instead of the default fixed content — lets a test
             *  give the 3 'deep'-mode calls distinct, assertable payloads. Falls
             *  back to the default return for any index beyond the array. */
            public static $groundingReturns = null;

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
                $callIndex = count(self::$groundingCalls);
                self::$groundingCalls[] = array('messages' => $messages, 'options' => $options);
                if (self::$groundingThrow) {
                    throw new \RuntimeException('missing google api key');
                }
                if (is_array(self::$groundingReturns) && array_key_exists($callIndex, self::$groundingReturns)) {
                    return self::$groundingReturns[$callIndex];
                }
                return array('content' => 'RESEARCH FINDINGS X');
            }
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyResearchTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_research_llm();   // MUST precede the fakes so our PCM_LLM wins
        pcm_test_define_strategy_fakes(); // everything else; its PCM_LLM guard now no-ops
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';

        PCM_DB::$items = array();
        PCM_DB::$strategy = array();
        PCM_DB::$strategyRow = null;
        PCM_DB::$site = null;
        PCM_DB::$articles = array();
        PCM_DB::$articleSeq = 100;
        PCM_DB::$forceNullArticle = false;
        PCM_DB::$forceClaimFail = false;
        PCM_DB::$loseGenerationClaims = 0;
        PCM_LLM::$lastOptions = null;
        PCM_LLM::$throwOn = null;
        PCM_LLM::$callCount = 0;
        PCM_LLM::$lastUserMessage = null;
        PCM_LLM::$lastMessages = null;
        PCM_LLM::$groundingCalls = array();
        PCM_LLM::$groundingThrow = false;
        PCM_LLM::$groundingReturns = null;
        PCM_Sites_Service::$calls = array();
        PCM_Sites_Service::$shouldThrow = false;
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

    /** Pull the SYSTEM-role content from the last invoke_json() call. */
    private function systemContent(): string
    {
        foreach ((PCM_LLM::$lastMessages ?? array()) as $m) {
            if (($m['role'] ?? '') === 'system') {
                return (string)$m['content'];
            }
        }
        return '';
    }

    public function test_research_enabled_calls_grounding_once_and_injects_into_system_prompt(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('research' => true)),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(1, PCM_LLM::$groundingCalls, 'research=true must call invoke_with_grounding() exactly once');
        $this->assertStringContainsString(
            'RESEARCH FINDINGS X',
            $this->systemContent(),
            'the grounding summary must be folded into the generation SYSTEM prompt'
        );
        $this->assertSame('completed', PCM_DB::$items[1]->status);
    }

    public function test_research_absent_never_calls_grounding(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => null,
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(0, PCM_LLM::$groundingCalls, 'no research opt-in — grounding must never be called');
        $this->assertStringNotContainsString('RESEARCH FINDINGS', $this->systemContent());
        $this->assertSame('completed', PCM_DB::$items[1]->status);
    }

    public function test_grounding_failure_still_generates_article_un_enriched(): void
    {
        // Research failure (here: the missing-Google-key throw) must NEVER fail
        // generation — the article is generated from an un-enriched prompt.
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        PCM_LLM::$groundingThrow = true;
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('research' => true)),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(1, PCM_LLM::$groundingCalls, 'grounding was attempted');
        $this->assertSame('completed', PCM_DB::$items[1]->status, 'a research failure must not fail generation');
        $this->assertSame('', PCM_DB::$items[1]->errorMessage ?? '', 'no error must be recorded on the item');
        $this->assertStringNotContainsString('RESEARCH FINDINGS', $this->systemContent());
        $this->assertStringNotContainsString('CURRENT SEARCH LANDSCAPE', $this->systemContent(), 'no research block when research degraded to empty');
    }

    public function test_research_mode_deep_makes_three_grounding_calls_with_all_sections(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        PCM_LLM::$groundingReturns = array(
            0 => array('content' => 'LANDSCAPE FINDINGS'),
            1 => array('content' => 'QUESTIONS AND STATS FINDINGS'),
            2 => array('content' => 'GAP FINDINGS'),
        );
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('researchMode' => 'deep')),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(3, PCM_LLM::$groundingCalls, "researchMode='deep' must make exactly 3 grounding calls");
        $system = $this->systemContent();
        $this->assertStringContainsString('SEARCH LANDSCAPE:', $system);
        $this->assertStringContainsString('QUESTIONS & DATA:', $system);
        $this->assertStringContainsString('CONTENT GAPS:', $system);
        $this->assertStringContainsString('LANDSCAPE FINDINGS', $system);
        $this->assertStringContainsString('QUESTIONS AND STATS FINDINGS', $system);
        $this->assertStringContainsString('GAP FINDINGS', $system);
        $this->assertSame('completed', PCM_DB::$items[1]->status);
    }

    public function test_research_mode_off_wins_over_legacy_research_true(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('research' => true, 'researchMode' => 'off')),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(0, PCM_LLM::$groundingCalls, "explicit researchMode='off' must win over legacy research=true");
        $this->assertStringNotContainsString('RESEARCH FINDINGS', $this->systemContent());
        $this->assertSame('completed', PCM_DB::$items[1]->status);
    }

    public function test_legacy_research_true_without_research_mode_is_grounded(): void
    {
        $this->seedItems(array(array('id' => 1, 'keyword' => 'kw one', 'status' => 'pending', 'position' => 0)));
        $strategy = (object)array(
            'id' => 7, 'templateId' => 3, 'brandId' => null,
            'config' => json_encode(array('research' => true)),
            'totalItems' => 1, 'completedItems' => 0, 'failedItems' => 0,
        );

        PCM_Strategy_Service::generate_next_item($strategy, 1);

        $this->assertCount(1, PCM_LLM::$groundingCalls, 'legacy research=true with no researchMode must resolve to a single grounded call, unchanged');
        $this->assertStringContainsString('RESEARCH FINDINGS X', $this->systemContent());
        $this->assertSame('completed', PCM_DB::$items[1]->status);
    }
}
