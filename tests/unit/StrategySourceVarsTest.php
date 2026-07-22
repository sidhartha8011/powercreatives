<?php
/**
 * Unit Tests — the {{ post_* }} template variables.
 *
 * Before these, the source post reached the model ONLY through the hardcoded
 * English sentence in rss_source_instruction(), so a template author could not
 * change how or where the post was used. `{{ post_content }}`,
 * `{{ post_title }}` and `{{ post_link }}` hand that control to the template;
 * both RSS feed items and social posts populate the same item-config keys, so
 * one set covers both sources.
 *
 * Covers the three pure statics: source_vars() (the map), uses_source_vars()
 * (the rider-suppression predicate) and render_source_vars() (substitution).
 * The CALL SITES are covered in StrategyAutoPublishTest — helper-only tests
 * previously let a whole requirement be deleted with the suite staying green.
 *
 * House-style fakes: the shared WP shims from StrategyAutoPublishTest via
 * pcm_test_define_strategy_fakes().
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategySourceVarsTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_strategy_fakes();
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';
    }

    /** A social/RSS item config carrying all three source fields. */
    private function cfg(): array
    {
        return array(
            'social'      => true,
            'sourceTitle' => 'Spain win the final',
            'sourceText'  => 'What a match tonight.',
            'sourceLink'  => 'https://insta/p/abc',
        );
    }

    // ── source_vars ──────────────────────────────────────────────────────

    public function test_source_vars_maps_the_three_item_config_fields(): void
    {
        $this->assertSame(
            array(
                'post_content' => 'What a match tonight.',
                'post_title'   => 'Spain win the final',
                'post_link'    => 'https://insta/p/abc',
            ),
            PCM_Strategy_Service::source_vars($this->cfg())
        );
    }

    public function test_source_vars_are_empty_strings_for_a_keyword_item(): void
    {
        // Never null — a keyword item must render the tokens away, not emit
        // "{{ post_content }}" or a PHP notice.
        $this->assertSame(
            array('post_content' => '', 'post_title' => '', 'post_link' => ''),
            PCM_Strategy_Service::source_vars(array())
        );
    }

    // ── uses_source_vars ─────────────────────────────────────────────────

    public function test_uses_source_vars_detects_each_variable_and_ignores_others(): void
    {
        $this->assertTrue(PCM_Strategy_Service::uses_source_vars('a {{ post_content }} b'));
        $this->assertTrue(PCM_Strategy_Service::uses_source_vars('a {{post_title}} b'));
        $this->assertTrue(PCM_Strategy_Service::uses_source_vars('a {{  post_link  }} b'));
        $this->assertFalse(PCM_Strategy_Service::uses_source_vars('plain template with no variables'));
        // A near-miss must NOT suppress the rider — otherwise a typo silently
        // strips the post context from the prompt entirely.
        $this->assertFalse(PCM_Strategy_Service::uses_source_vars('a {{ post_body }} b'));
    }

    // ── render_source_vars ───────────────────────────────────────────────

    public function test_render_substitutes_all_three_with_whitespace_tolerance(): void
    {
        $out = PCM_Strategy_Service::render_source_vars(
            "T:{{ post_title }}|C:{{post_content}}|L:{{  post_link  }}",
            $this->cfg()
        );
        $this->assertSame('T:Spain win the final|C:What a match tonight.|L:https://insta/p/abc', $out);
    }

    public function test_render_replaces_every_occurrence_not_just_the_first(): void
    {
        $this->assertSame(
            'X and X',
            PCM_Strategy_Service::render_source_vars('{{ post_title }} and {{ post_title }}', array('sourceTitle' => 'X'))
        );
    }

    public function test_render_resolves_absent_values_to_empty(): void
    {
        // Only the token is removed — the author's surrounding whitespace is
        // left exactly as written (no magic collapsing), so the result is
        // predictable from the template text alone.
        $this->assertSame(
            'Body:  | Link: ',
            PCM_Strategy_Service::render_source_vars('Body: {{ post_content }} | Link: {{ post_link }}', array())
        );
    }

    public function test_render_leaves_an_unknown_token_untouched(): void
    {
        // Deliberate: a typo stays visible to the author instead of being
        // silently swallowed, and a legitimate brace pair is never eaten.
        $this->assertSame(
            'keep {{ post_body }} and {{ anything }}',
            PCM_Strategy_Service::render_source_vars('keep {{ post_body }} and {{ anything }}', $this->cfg())
        );
    }

    public function test_render_treats_dollar_and_backslash_in_the_post_as_literal_text(): void
    {
        // A caption is arbitrary user text. preg_replace reads "$1"/"\0" in the
        // REPLACEMENT as backreferences, so an unescaped value would corrupt the
        // prompt (or blank the token). Both must survive verbatim.
        $cfg = array('sourceText' => 'Save $1 now — 50$ off \\ path\\to');
        $this->assertSame(
            'Deal: Save $1 now — 50$ off \\ path\\to',
            PCM_Strategy_Service::render_source_vars('Deal: {{ post_content }}', $cfg)
        );
    }

    public function test_source_vars_trims_surrounding_whitespace(): void
    {
        // Feed descriptions and captions routinely arrive padded with newlines;
        // pasting that raw into a prompt is noise.
        $vars = PCM_Strategy_Service::source_vars(array(
            'sourceText'  => "  padded caption \n",
            'sourceTitle' => "\n  A title  ",
            'sourceLink'  => '  https://x/1  ',
        ));
        $this->assertSame('padded caption', $vars['post_content']);
        $this->assertSame('A title', $vars['post_title']);
        $this->assertSame('https://x/1', $vars['post_link']);
    }

    public function test_a_variable_inside_the_post_content_is_not_re_expanded(): void
    {
        // ONE pass: substituting each variable in its own pass re-scanned the
        // text already substituted, so a caption that itself contained
        // "{{ post_title }}" had that expanded too — user content being
        // interpreted as template syntax.
        $out = PCM_Strategy_Service::render_source_vars(
            'BODY: {{ post_content }} | T: {{ post_title }}',
            array('sourceText' => 'see {{ post_title }} for more', 'sourceTitle' => 'REAL')
        );
        $this->assertSame('BODY: see {{ post_title }} for more | T: REAL', $out);
    }

    public function test_referenced_source_vars_lists_only_what_the_template_uses(): void
    {
        $this->assertSame(
            array('post_content', 'post_link'),
            PCM_Strategy_Service::referenced_source_vars('{{ post_content }} then {{post_link}}')
        );
        $this->assertSame(array(), PCM_Strategy_Service::referenced_source_vars('nothing here'));
    }

    public function test_template_carries_source_requires_a_REFERENCED_var_to_be_non_empty(): void
    {
        $cfg = array('sourceTitle' => 'A title', 'sourceLink' => 'https://x/1'); // no caption
        // References only post_content, which is empty → the template does NOT
        // carry the post, so the caller must keep the built-in rider. Without
        // this, an RSS feed with no description (or a social post whose caption
        // capture failed) would lose BOTH its context and its attribution link.
        $this->assertFalse(PCM_Strategy_Service::template_carries_source('Rewrite: {{ post_content }}', $cfg));
        // References a var that DOES resolve → the template carries it.
        $this->assertTrue(PCM_Strategy_Service::template_carries_source('Rewrite: {{ post_title }}', $cfg));
        // Mixed: one empty, one populated → still carries.
        $this->assertTrue(PCM_Strategy_Service::template_carries_source('{{ post_content }} {{ post_link }}', $cfg));
        // No variables at all → never carries.
        $this->assertFalse(PCM_Strategy_Service::template_carries_source('plain', $cfg));
    }

    public function test_render_keeps_the_prompt_when_the_pcre_engine_fails(): void
    {
        // preg_replace_callback returns null on an engine failure (exhausted
        // backtrack limit). Blanking the prompt would send the model an empty
        // brief; a visible token is the better failure.
        $old = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');
        try {
            $text = 'A ' . str_repeat('x', 200) . ' {{ post_title }} B';
            $out  = PCM_Strategy_Service::render_source_vars($text, array('sourceTitle' => 'T'));
            $this->assertNotSame('', $out, 'a PCRE failure must never blank the prompt');
        } finally {
            ini_set('pcre.backtrack_limit', (string)$old);
        }
    }

    public function test_the_rss_reader_captures_the_feed_description_so_post_content_is_real(): void
    {
        // THE headline case: "RSS reposting with variables {{ post_content }}".
        // The feed reader previously captured only permalink/id/title/date, so
        // post_content could never be anything but '' on an RSS strategy. It now
        // reads the entry's description, which rides the SAME `text` key the
        // social branch uses → ingest → pop → sourceText.
        // fetch_rss_feed_items() also touches these WP edges.
        if (!function_exists('is_wp_error')) {
            function is_wp_error($t)
            {
                return false;
            }
        }
        // Guarded INDIVIDUALLY — the shared fakes define add_filter but not
        // remove_filter, so a combined guard would skip both.
        if (!function_exists('add_filter')) {
            function add_filter($h, $c, $p = 10, $a = 1)
            {
                return true;
            }
        }
        if (!function_exists('remove_filter')) {
            function remove_filter($h, $c, $p = 10)
            {
                return true;
            }
        }
        if (!function_exists('fetch_feed')) {
            /** Minimal SimplePie stand-in — fetch_rss_feed_items() only calls
             *  get_item_quantity/get_items and the item getters below. */
            function fetch_feed($url)
            {
                return new class {
                    public function get_item_quantity($max)
                    {
                        return 1;
                    }
                    public function get_items($start, $qty)
                    {
                        return array(new class {
                            public function get_permalink()
                            {
                                return 'https://news.example/a';
                            }
                            public function get_id()
                            {
                                return 'guid-1';
                            }
                            public function get_title()
                            {
                                return 'Fed cuts rates';
                            }
                            public function get_date($fmt)
                            {
                                return 1750000000;
                            }
                            public function get_description()
                            {
                                return 'The central bank lowered rates by 25bps.';
                            }
                        });
                    }
                };
            }
        }

        $m = new \ReflectionMethod(PCM_Strategy_Service::class, 'fetch_rss_feed_items');
        $raw = $m->invoke(null, array('https://news.example/feed'));

        $this->assertCount(1, $raw);
        $this->assertSame('The central bank lowered rates by 25bps.', $raw[0]['text'] ?? null);
        // And it survives the ingest seam into a queue entry's `text`.
        $cfg = PCM_Strategy_Service::ingest_feed_items($raw, array());
        $this->assertSame('The central bank lowered rates by 25bps.', $cfg['rssQueue'][0]['text'] ?? null);
    }

    public function test_render_is_a_no_op_on_an_empty_template(): void
    {
        $this->assertSame('', PCM_Strategy_Service::render_source_vars('', $this->cfg()));
    }

    public function test_render_leaves_a_variable_free_template_byte_identical(): void
    {
        // The back-compat contract: every existing template must produce the
        // exact prompt it produced before this feature existed.
        $plain = "You are an expert SEO writer.\n\nUse {braces} and 100% of the brief.";
        $this->assertSame($plain, PCM_Strategy_Service::render_source_vars($plain, $this->cfg()));
    }
}
