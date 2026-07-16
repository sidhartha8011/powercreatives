<?php
/**
 * Unit Tests — Step C2: AI-assisted anchor fallback for interlinks.
 *
 * Exercises the REAL PCM_Strategy_Service::run_interlinks() /
 * maybe_inject_interlinks() aiAnchors path: when a target keyword has no safe
 * verbatim occurrence in the source article and aiAnchors is enabled, the
 * service asks the LLM (exactly one call per source/target pair) for an
 * existing short phrase to wrap instead, then re-validates that phrase through
 * the SAME deterministic rails (case-sensitive verbatim scan +
 * is_inside_html_tag). Any LLM failure or unsafe answer degrades to the plain
 * 'skipped'/'no safe occurrence' result and never fails the run.
 *
 * Reuses StrategyAutoPublishTest.php's fakes (PCM_DB / PCM_Sites_Service /
 * PCM_Schema / PCM_Approvals_Service stand-ins) rather than redeclaring them --
 * see that file's docblock for why the fakes share class names with the real,
 * composer-classmapped services and why this class also needs
 * `@runTestsInSeparateProcesses`.
 *
 * The one fake this file OWNS is PCM_LLM: it is declared at top level BEFORE the
 * require below, so pcm_test_define_strategy_fakes()'s `class_exists('PCM_LLM',
 * false)` guard sees it already present and skips the sibling's generation-only
 * version. This one adds a configurable anchor return + a branch on the request
 * schema: an anchor-schema call (the C2 fallback) returns `{anchor: <static>}`;
 * anything else falls back to the sibling's generation payload shape.
 *
 * @package PowerCreatives\Tests\Unit
 */

// Declare our PCM_LLM fake FIRST so the sibling's guarded (class_exists(...,
// false)) declaration is skipped and this richer one wins.
if (!class_exists('PCM_LLM', false)) {
    class PCM_LLM
    {
        // ── C2 anchor-fallback controls ──────────────────────────────────
        /** @var string Phrase invoke_json() returns for an anchor-schema call. */
        public static $anchor = '';
        /** @var bool When true, an anchor-schema call throws (simulates LLM failure). */
        public static $throwOnAnchor = false;
        /** @var int Number of anchor-schema calls this test (assert "consulted once"). */
        public static $anchorCallCount = 0;
        /** @var array|null Options passed on the last anchor-schema call. */
        public static $lastAnchorOptions = null;

        // ── synonym-fallback controls (anchorMode='synonym') ─────────────
        /** @var array<int,string> Synonyms invoke_json() returns for a synonym-schema call. */
        public static $synonyms = array();
        /** @var bool When true, a synonym-schema call throws (simulates LLM failure). */
        public static $throwOnSynonym = false;
        /** @var int Number of synonym-schema calls this test. */
        public static $synonymCallCount = 0;
        /** @var array|null Options passed on the last synonym-schema call. */
        public static $lastSynonymOptions = null;

        // ── Generation-path statics (mirror the sibling fake) ────────────
        public static $lastOptions = null;
        public static $throwOn = null;
        public static $callCount = 0;
        public static $lastUserMessage = null;

        public static function invoke_json($messages, $schema, $options)
        {
            self::$callCount++;
            $user = '';
            foreach ($messages as $m) {
                if (($m['role'] ?? '') === 'user') {
                    $user = $m['content'];
                }
            }
            self::$lastUserMessage = $user;

            // Branch on the request schema: the C2 fallback requires 'anchor'.
            // The service passes the full json_schema WRAPPER ({name, schema})
            // since the OpenAI response_format fix — look inside it first.
            $required = (array)($schema['schema']['required'] ?? $schema['required'] ?? array());
            if (in_array('anchor', $required, true)) {
                self::$anchorCallCount++;
                self::$lastAnchorOptions = $options;
                if (self::$throwOnAnchor) {
                    throw new \RuntimeException('anchor LLM boom');
                }
                return array('anchor' => self::$anchor);
            }
            // The 'synonym' anchor-mode fallback requires 'synonyms' (an array).
            if (in_array('synonyms', $required, true)) {
                self::$synonymCallCount++;
                self::$lastSynonymOptions = $options;
                if (self::$throwOnSynonym) {
                    throw new \RuntimeException('synonym LLM boom');
                }
                return array('synonyms' => self::$synonyms);
            }

            // Generation path (unused by these tests, kept for parity).
            self::$lastOptions = $options;
            if (self::$throwOn && strpos($user, self::$throwOn) !== false) {
                throw new \RuntimeException('LLM boom');
            }
            return array('title' => 'Generated Title', 'content' => '<p>body</p>', 'metaTitle' => 'MT', 'metaDescription' => 'MD');
        }
    }
}

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyAiAnchorsTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_strategy_fakes();
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';

        PCM_DB::$items = array();
        PCM_DB::$strategy = array();
        PCM_DB::$strategyRow = null;
        PCM_DB::$site = null;
        PCM_DB::$articles = array();
        PCM_DB::$articleSeq = 100;
        PCM_DB::$forceNullArticle = false;
        PCM_LLM::$anchor = '';
        PCM_LLM::$throwOnAnchor = false;
        PCM_LLM::$anchorCallCount = 0;
        PCM_LLM::$lastAnchorOptions = null;
        PCM_LLM::$synonyms = array();
        PCM_LLM::$throwOnSynonym = false;
        PCM_LLM::$synonymCallCount = 0;
        PCM_LLM::$lastSynonymOptions = null;
        PCM_LLM::$lastOptions = null;
        PCM_LLM::$throwOn = null;
        PCM_LLM::$callCount = 0;
        PCM_LLM::$lastUserMessage = null;
        PCM_Sites_Service::$calls = array();
        PCM_Sites_Service::$shouldThrow = false;
        PCM_Test_Cron::$scheduleCalls = array();
        PCM_Test_Cron::$alreadyScheduled = false;
        PCM_Test_Cron::$now = null;
        PCM_DB::$createStrategyId = 7;
        PCM_DB::$dueScheduledStrategies = array();
        PCM_Approvals_Service::$createSetCalls = array();
        PCM_Approvals_Service::$nextSetId = 501;
        PCM_DB::$forceClaimFail = false;
        PCM_DB::$loseGenerationClaims = 0;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function seedItems(array $items): void
    {
        foreach ($items as $it) {
            $o = (object)$it;
            PCM_DB::$items[$o->id] = $o;
        }
    }

    /**
     * Seeds two completed, mutually-linkable items. Source article 100 (keyword
     * 'best crm software') is where the AI fallback fires; article 101 (keyword
     * 'cheap accounting tools') already contains 'best crm software' verbatim so
     * its OWN link injects deterministically via the exact-match path — that
     * keeps the AI branch scoped to exactly ONE (source,target) pair, so
     * anchorCallCount reflects only article 100's attempt.
     */
    private function seedTwoArticles(string $sourceContent): void
    {
        $this->seedItems(array(
            array('id' => 1, 'keyword' => 'best crm software', 'status' => 'completed', 'position' => 0, 'articleId' => 100),
            array('id' => 2, 'keyword' => 'cheap accounting tools', 'status' => 'completed', 'position' => 1, 'articleId' => 101),
        ));
        PCM_DB::$articles[100] = array('id' => 100, 'slug' => 'crm-guide', 'content' => $sourceContent);
        PCM_DB::$articles[101] = array('id' => 101, 'slug' => 'accounting-guide', 'content' => '<p>Read our best crm software guide today.</p>');
        PCM_DB::$strategyRow = (object)array('id' => 7, 'status' => 'completed', 'config' => null, 'totalItems' => 2, 'completedItems' => 2, 'failedItems' => 0);
    }

    /** @return array<int,array<string,string>> injected rows only */
    private static function injectedRows(array $result): array
    {
        return array_values(array_filter($result['results'], static fn($r) => $r['status'] === 'injected'));
    }

    // (a) keyword absent + aiAnchors=true -> LLM consulted once, its phrase wraps.
    public function test_ai_anchor_wraps_llm_supplied_phrase_when_keyword_absent(): void
    {
        // Article 100 lacks the target keyword 'cheap accounting tools' but DOES
        // contain the AI-supplied phrase 'accounting made simple' verbatim.
        $this->seedTwoArticles('<p>Great accounting made simple for busy teams.</p>');
        PCM_LLM::$anchor = 'accounting made simple';

        $result = PCM_Strategy_Service::run_interlinks(7, 1, array('aiAnchors' => true));

        $this->assertSame(1, PCM_LLM::$anchorCallCount, 'exactly one AI anchor attempt for the one keyword-absent pair');
        $this->assertStringContainsString('<a href="/accounting-guide">accounting made simple</a>', PCM_DB::$articles[100]['content']);

        $aiRow = null;
        foreach ($result['results'] as $row) {
            if ($row['source'] === 'best crm software' && $row['target'] === 'cheap accounting tools') {
                $aiRow = $row;
            }
        }
        $this->assertNotNull($aiRow);
        $this->assertSame('injected', $aiRow['status']);
        $this->assertSame('ai anchor', $aiRow['reason']);

        // The fallback must forward max_tokens + user_id on its single call.
        $this->assertSame(256, PCM_LLM::$lastAnchorOptions['max_tokens'] ?? null);
        $this->assertSame(1, PCM_LLM::$lastAnchorOptions['user_id'] ?? null);
    }

    // (b) aiAnchors absent -> no LLM call, the keyword-absent pair is skipped.
    public function test_no_ai_call_and_pair_skipped_when_aiAnchors_absent(): void
    {
        $this->seedTwoArticles('<p>Great accounting made simple for busy teams.</p>');
        PCM_LLM::$anchor = 'accounting made simple';

        $result = PCM_Strategy_Service::run_interlinks(7, 1); // no aiAnchors

        $this->assertSame(0, PCM_LLM::$anchorCallCount, 'aiAnchors off -> the LLM must never be consulted');
        $this->assertStringNotContainsString('accounting made simple</a>', PCM_DB::$articles[100]['content']);

        $skipped = null;
        foreach ($result['results'] as $row) {
            if ($row['source'] === 'best crm software' && $row['target'] === 'cheap accounting tools') {
                $skipped = $row;
            }
        }
        $this->assertNotNull($skipped);
        $this->assertSame('skipped', $skipped['status']);
        $this->assertSame('no safe occurrence', $skipped['reason']);
    }

    // (c) AI returns a phrase NOT present in content -> skipped, no corruption.
    public function test_ai_anchor_not_present_in_content_is_skipped_without_corruption(): void
    {
        $original = '<p>Great accounting made simple for busy teams.</p>';
        $this->seedTwoArticles($original);
        PCM_LLM::$anchor = 'totally absent phrase xyz';

        $result = PCM_Strategy_Service::run_interlinks(7, 1, array('aiAnchors' => true));

        $this->assertSame(1, PCM_LLM::$anchorCallCount, 'the LLM is consulted once and NOT retried');
        $this->assertSame($original, PCM_DB::$articles[100]['content'], 'an unlocatable AI anchor must leave the article byte-for-byte unchanged');

        $skipped = null;
        foreach ($result['results'] as $row) {
            if ($row['source'] === 'best crm software' && $row['target'] === 'cheap accounting tools') {
                $skipped = $row;
            }
        }
        $this->assertNotNull($skipped);
        $this->assertSame('skipped', $skipped['status']);
        $this->assertSame('no safe occurrence', $skipped['reason']);
    }

    // (d) AI anchor sits inside an existing <a> tag -> skipped (rails hold).
    public function test_ai_anchor_inside_existing_anchor_is_rejected_by_rails(): void
    {
        // The only occurrence of the AI phrase is the rendered text of an
        // existing anchor -- wrapping it would nest anchors, so is_inside_html_tag
        // must reject it and the pair stays skipped.
        $original = '<p>See <a href="https://ref.example/x">accounting made simple</a> for more.</p>';
        $this->seedTwoArticles($original);
        PCM_LLM::$anchor = 'accounting made simple';

        $result = PCM_Strategy_Service::run_interlinks(7, 1, array('aiAnchors' => true));

        $this->assertSame(1, PCM_LLM::$anchorCallCount);
        $this->assertSame($original, PCM_DB::$articles[100]['content'], 'must never nest a new anchor inside the existing one');

        $skipped = null;
        foreach ($result['results'] as $row) {
            if ($row['source'] === 'best crm software' && $row['target'] === 'cheap accounting tools') {
                $skipped = $row;
            }
        }
        $this->assertNotNull($skipped);
        $this->assertSame('skipped', $skipped['status']);
        $this->assertSame('no safe occurrence', $skipped['reason']);
    }

    // (e) LLM throws -> the pair is skipped and the whole run still completes.
    public function test_llm_throwing_degrades_to_skip_and_run_completes(): void
    {
        $this->seedTwoArticles('<p>Great accounting made simple for busy teams.</p>');
        PCM_LLM::$anchor = 'accounting made simple';
        PCM_LLM::$throwOnAnchor = true;

        $result = PCM_Strategy_Service::run_interlinks(7, 1, array('aiAnchors' => true));

        // The exact-match pair (article 101 -> 'best crm software') still injected,
        // proving the run completed past the throwing AI attempt.
        $this->assertIsArray($result);
        $this->assertSame(1, $result['injected'], 'the deterministic exact-match injection still happens; only the AI pair is skipped');
        $this->assertSame(1, PCM_LLM::$anchorCallCount);

        $skipped = null;
        foreach ($result['results'] as $row) {
            if ($row['source'] === 'best crm software' && $row['target'] === 'cheap accounting tools') {
                $skipped = $row;
            }
        }
        $this->assertNotNull($skipped);
        $this->assertSame('skipped', $skipped['status']);
        $this->assertSame('no safe occurrence', $skipped['reason']);
    }

    // (f) anchorMode='synonym': keyword absent, the LLM returns several synonyms
    // but only the SECOND exists safely -> that one is wrapped (first locatable
    // candidate wins), and the AI single-phrase path is never consulted.
    public function test_synonym_mode_wraps_first_locatable_synonym(): void
    {
        // Article 100 lacks the target keyword 'cheap accounting tools'; of the
        // returned synonyms only 'top sneakers' appears verbatim in its content.
        $this->seedTwoArticles('<p>We reviewed the top sneakers for busy teams.</p>');
        PCM_LLM::$synonyms = array('best running shoes', 'top sneakers');

        $result = PCM_Strategy_Service::run_interlinks(7, 1, array('anchorMode' => 'synonym'));

        $this->assertSame(1, PCM_LLM::$synonymCallCount, 'exactly one synonym attempt for the one keyword-absent pair');
        $this->assertSame(0, PCM_LLM::$anchorCallCount, 'synonym mode must never consult the AI single-phrase path');
        $this->assertStringContainsString('<a href="/accounting-guide">top sneakers</a>', PCM_DB::$articles[100]['content']);
        $this->assertStringNotContainsString('best running shoes</a>', PCM_DB::$articles[100]['content']);

        $synRow = null;
        foreach ($result['results'] as $row) {
            if ($row['source'] === 'best crm software' && $row['target'] === 'cheap accounting tools') {
                $synRow = $row;
            }
        }
        $this->assertNotNull($synRow);
        $this->assertSame('injected', $synRow['status']);
        $this->assertSame('synonym anchor', $synRow['reason']);

        // The synonym fallback forwards max_tokens + user_id on its single call.
        $this->assertSame(256, PCM_LLM::$lastSynonymOptions['max_tokens'] ?? null);
        $this->assertSame(1, PCM_LLM::$lastSynonymOptions['user_id'] ?? null);
    }

    // (g) anchorMode='keyword' with the exact keyword absent -> the pair is
    // skipped and NEITHER LLM path is ever consulted (zero LLM calls).
    public function test_keyword_mode_skips_without_any_llm_call(): void
    {
        $this->seedTwoArticles('<p>Great accounting made simple for busy teams.</p>');
        PCM_LLM::$anchor   = 'accounting made simple';
        PCM_LLM::$synonyms = array('accounting made simple');

        $result = PCM_Strategy_Service::run_interlinks(7, 1, array('anchorMode' => 'keyword'));

        $this->assertSame(0, PCM_LLM::$callCount, 'keyword mode must never invoke the LLM at all');
        $this->assertSame(0, PCM_LLM::$anchorCallCount);
        $this->assertSame(0, PCM_LLM::$synonymCallCount);
        $this->assertStringNotContainsString('accounting made simple</a>', PCM_DB::$articles[100]['content']);

        $skipped = null;
        foreach ($result['results'] as $row) {
            if ($row['source'] === 'best crm software' && $row['target'] === 'cheap accounting tools') {
                $skipped = $row;
            }
        }
        $this->assertNotNull($skipped);
        $this->assertSame('skipped', $skipped['status']);
        $this->assertSame('no safe occurrence', $skipped['reason']);
    }

    // (h) back-compat equivalence: explicit anchorMode='ai' behaves exactly like
    // legacy aiAnchors=true -> the AI single-phrase path is consulted once and
    // its phrase is wrapped with the 'ai anchor' reason.
    public function test_anchor_mode_ai_matches_legacy_aiAnchors(): void
    {
        $this->seedTwoArticles('<p>Great accounting made simple for busy teams.</p>');
        PCM_LLM::$anchor = 'accounting made simple';

        $result = PCM_Strategy_Service::run_interlinks(7, 1, array('anchorMode' => 'ai'));

        $this->assertSame(1, PCM_LLM::$anchorCallCount, 'anchorMode=ai consults the AI path exactly once, like legacy aiAnchors=true');
        $this->assertSame(0, PCM_LLM::$synonymCallCount);
        $this->assertStringContainsString('<a href="/accounting-guide">accounting made simple</a>', PCM_DB::$articles[100]['content']);

        $aiRow = null;
        foreach ($result['results'] as $row) {
            if ($row['source'] === 'best crm software' && $row['target'] === 'cheap accounting tools') {
                $aiRow = $row;
            }
        }
        $this->assertNotNull($aiRow);
        $this->assertSame('injected', $aiRow['status']);
        $this->assertSame('ai anchor', $aiRow['reason']);
    }
}
