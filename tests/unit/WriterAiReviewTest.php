<?php
/**
 * Unit Tests — Writer AI Review (structured suggestions + surgical apply).
 *
 * Exercises the REAL PCM_Article_Review against a fake PCM_LLM declared here
 * (the class's only collaborator). Focus is the deterministic safety rail:
 * suggestions whose `find` can't be located verbatim OUTSIDE HTML markup are
 * dropped, and apply() only ever touches the first safe occurrence — the same
 * tag-safe scan contract as the Strategy interlinker.
 *
 * Same process-isolation contract as the sibling suites: the fake shares the
 * PCM_LLM class NAME with the real classmapped service, so every test method
 * runs in its own PHP process and the class_exists() guard passes `false` to
 * disable autoloading. The fake is declared via a top-level function called
 * from setUp() (execution time, inside the isolated child) — never at file
 * top level.
 *
 * @package PowerCreatives\Tests\Unit
 */

/**
 * Declares the fake PCM_LLM exactly once per (isolated) process. Settable
 * $returnValue lets each test script the review LLM's suggestion payload;
 * $throw simulates a provider failure (review() must propagate it so the
 * controller can surface an error instead of a silent "no issues").
 */
function pcm_test_define_writer_review_llm_fake(): void
{
    if (!class_exists('PCM_LLM', false)) {
        class PCM_LLM
        {
            /** @var array What invoke_json() returns. */
            public static $returnValue = array('suggestions' => array());
            /** @var bool Throw instead of returning. */
            public static $throw = false;
            /** @var array|null Last messages arg, for prompt assertions. */
            public static $lastMessages = null;

            public static function invoke_json($messages, $schema, $options = array())
            {
                self::$lastMessages = $messages;
                if (self::$throw) {
                    throw new \RuntimeException('LLM boom');
                }
                return self::$returnValue;
            }
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class WriterAiReviewTest extends \PHPUnit\Framework\TestCase
{
    private const HTML = '<h2>Solar Panels</h2>'
        . '<p>Solar energy is very good for homes. It is very good for the planet too.</p>'
        . '<p>Read our <a href="/guide" title="very good guide">very good guide</a> on panels.</p>';

    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_writer_review_llm_fake();
        require_once dirname(__DIR__, 2) . '/includes/modules/writer/class-pcm-article-review.php';

        PCM_LLM::$returnValue = array('suggestions' => array());
        PCM_LLM::$throw = false;
        PCM_LLM::$lastMessages = null;
    }

    // ── review(): validation rail ──────────────────────────────────────

    public function test_review_keeps_locatable_suggestion_and_returns_matched_surface_form(): void
    {
        PCM_LLM::$returnValue = array('suggestions' => array(
            array('find' => 'very good for homes', 'issue' => 'Weak phrasing', 'replacement' => 'highly cost-effective for homes'),
        ));

        $out = PCM_Article_Review::review(self::HTML, '', 1);

        $this->assertCount(1, $out);
        $this->assertSame('very good for homes', $out[0]['find']);
        $this->assertSame('Weak phrasing', $out[0]['issue']);
        $this->assertSame('highly cost-effective for homes', $out[0]['replacement']);
    }

    public function test_review_drops_hallucinated_and_identity_suggestions(): void
    {
        PCM_LLM::$returnValue = array('suggestions' => array(
            array('find' => 'text that is not in the article', 'issue' => 'x', 'replacement' => 'y'),
            array('find' => 'Solar energy', 'issue' => 'no-op', 'replacement' => 'Solar energy'),
            array('find' => '', 'issue' => 'empty', 'replacement' => 'z'),
        ));

        $out = PCM_Article_Review::review(self::HTML, '', 1);

        $this->assertSame(array(), $out);
    }

    public function test_review_drops_find_only_present_inside_markup(): void
    {
        // 'href="/guide"' exists only inside the anchor tag's own markup; the
        // phrase 'title=' likewise. Both must be rejected by the tag-safe scan.
        PCM_LLM::$returnValue = array('suggestions' => array(
            array('find' => 'href="/guide"', 'issue' => 'x', 'replacement' => 'href="/new"'),
        ));

        $out = PCM_Article_Review::review(self::HTML, '', 1);

        $this->assertSame(array(), $out);
    }

    public function test_review_case_insensitive_fallback_returns_actual_casing(): void
    {
        // Model returns lowercase; the article has 'Solar energy'. The relaxed
        // pass must locate it and hand back the article's exact surface form so
        // apply()'s case-sensitive scan hits the same spot.
        PCM_LLM::$returnValue = array('suggestions' => array(
            array('find' => 'solar energy is very good', 'issue' => 'x', 'replacement' => 'Solar power is excellent'),
        ));

        $out = PCM_Article_Review::review(self::HTML, '', 1);

        $this->assertCount(1, $out);
        $this->assertSame('Solar energy is very good', $out[0]['find']);
    }

    public function test_review_empty_content_returns_empty_without_llm_call(): void
    {
        $out = PCM_Article_Review::review('   ', 'feedback', 1);

        $this->assertSame(array(), $out);
        $this->assertNull(PCM_LLM::$lastMessages);
    }

    public function test_review_propagates_llm_failure(): void
    {
        PCM_LLM::$throw = true;

        $this->expectException(\RuntimeException::class);
        PCM_Article_Review::review(self::HTML, '', 1);
    }

    public function test_review_forwards_feedback_into_prompt(): void
    {
        PCM_LLM::$returnValue = array('suggestions' => array());
        PCM_Article_Review::review(self::HTML, 'make it more formal', 1);

        $user = '';
        foreach (PCM_LLM::$lastMessages as $m) {
            if (($m['role'] ?? '') === 'user') {
                $user = $m['content'];
            }
        }
        $this->assertStringContainsString('make it more formal', $user);
    }

    // ── apply(): surgical replace ──────────────────────────────────────

    public function test_apply_replaces_only_first_safe_occurrence(): void
    {
        $out = PCM_Article_Review::apply(self::HTML, 'very good', 'excellent');

        $this->assertNotNull($out);
        // First body occurrence replaced…
        $this->assertStringContainsString('Solar energy is excellent for homes', $out);
        // …second body occurrence untouched…
        $this->assertStringContainsString('It is very good for the planet too', $out);
        // …and the anchor's attribute + link text untouched.
        $this->assertStringContainsString('<a href="/guide" title="very good guide">very good guide</a>', $out);
    }

    public function test_apply_skips_occurrences_inside_markup_and_anchor_text(): void
    {
        // 'very good guide' appears in the title attribute (inside tag markup)
        // and as the anchor's rendered text (inside <a>…</a>) — both unsafe.
        // With no safe occurrence anywhere, apply must refuse.
        $out = PCM_Article_Review::apply(self::HTML, 'very good guide', 'excellent guide');

        $this->assertNull($out);
    }

    public function test_apply_returns_null_when_text_absent(): void
    {
        $this->assertNull(PCM_Article_Review::apply(self::HTML, 'not present at all', 'x'));
    }

    public function test_apply_empty_replacement_deletes_excerpt(): void
    {
        $out = PCM_Article_Review::apply('<p>This is really quite short.</p>', 'really quite ', '');

        $this->assertSame('<p>This is short.</p>', $out);
    }
}
