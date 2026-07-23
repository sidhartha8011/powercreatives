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

    // ── build_prompt() fragment variables: the hidden-prompt surfaces ─────
    //
    // build_prompt() used to inject fragments the template author never saw —
    // the default system line, BRAND CONTEXT, CURRENT SEARCH LANDSCAPE, the
    // keyword + output-format user message, and the in-content media block. Each
    // is now a {{ var }} ({{ keyword }}, {{ brand_context }}, {{ research }},
    // {{ output_format }}, {{ media_instructions }}): placing one hands the
    // author control of where it lands, and the built-in injection is suppressed
    // ONLY then — the same contract {{ post_* }} already has for the RSS rider.
    //
    // The first four tests pin the LOAD-BEARING back-compat contract: a template
    // referencing NONE of the new variables must produce the exact bytes
    // build_prompt() produced before they existed. Baselines were captured from
    // the unmodified method by reflection; any drift in a fragment's wording,
    // spacing, or join turns these red.

    private function build_messages(array $keywords, array $template, ?object $brand, string $research = '', bool $media = false, int $mc = 3, string $mt = 'both', string $mg = '', string $rss = '', array $cfg = array()): array
    {
        $m = new \ReflectionMethod(PCM_Strategy_Service::class, 'build_prompt');
        return $m->invoke(null, $keywords, $template, $brand, $research, $media, $mc, $mt, $mg, $rss, $cfg);
    }

    private function prompt_template(string $prompt): array
    {
        return array('entries' => array(array('category' => 'prompt', 'value' => $prompt)));
    }

    private function full_brand(): object
    {
        return (object)array(
            'name' => 'Acme', 'niche' => 'Widgets', 'tonOfVoice' => 'Bold',
            'targetAudience' => 'Makers', 'uniqueSellingPoints' => 'Fastest', 'language' => 'en',
        );
    }

    public function test_placing_every_user_side_variable_never_empties_the_user_turn(): void
    {
        // The end state this feature invites — "no hidden prompt at all" — is a
        // template that places keyword + output_format + media_instructions.
        // That leaves nothing to auto-append, and an EMPTY user turn is not a
        // cosmetic problem: the Anthropic adapter lifts `system` to a top-level
        // field (class-pcm-llm.php ~:1854) and the API then rejects the empty
        // content block left behind in messages[], failing the whole request.
        $msgs = $this->build_messages(
            array('seo tools'),
            $this->prompt_template('{{ keyword }} {{ output_format }} {{ media_instructions }}'),
            $this->full_brand(),
            'RESEARCH.',
            true
        );

        $this->assertNotSame('', trim($msgs[1]['content']), 'the user turn must never be empty');
        // The fallback is the bare generation target — NOT a re-smuggled
        // instruction the author just took control of.
        $this->assertSame('seo tools', $msgs[1]['content']);
        $this->assertStringNotContainsString('targeting the keyword', $msgs[1]['content']);
        $this->assertStringNotContainsString('Return a JSON object', $msgs[1]['content']);
    }

    public function test_byte_identity_minimal_single_keyword(): void
    {
        $msgs = $this->build_messages(array('best running shoes'), $this->prompt_template('SYS PROMPT'), null);

        $this->assertSame('SYS PROMPT', $msgs[0]['content']);
        $this->assertSame(
            "Write a comprehensive article targeting the keyword: \"best running shoes\"\n\n"
            . "Return a JSON object with the following fields: title, content (HTML), metaTitle, metaDescription.",
            $msgs[1]['content']
        );
    }

    public function test_byte_identity_consolidated_joins_keywords_quote_separated(): void
    {
        $msgs = $this->build_messages(
            array('kw one', 'kw two', 'kw three'),
            $this->prompt_template('SYS PROMPT'),
            null
        );

        $this->assertSame('SYS PROMPT', $msgs[0]['content']);
        $this->assertSame(
            'Write a SINGLE comprehensive article that covers ALL of the following keywords together, '
            . 'as distinct sections or subtopics within one cohesive piece: "kw one", "kw two", "kw three"'
            . "\n\nReturn a JSON object with the following fields: title, content (HTML), metaTitle, metaDescription.",
            $msgs[1]['content']
        );
    }

    public function test_byte_identity_brand_and_research_blocks_join_with_the_double_blank_line(): void
    {
        // The brand block ends in "\n" and system_parts join with "\n\n", so the
        // brand→research boundary is THREE newlines (two visible blank lines).
        // Pinning the exact bytes guards both the wording and that accident-prone
        // join — the part of the refactor most likely to drift silently.
        $msgs = $this->build_messages(
            array('lawn care tips'),
            $this->prompt_template('SYS PROMPT'),
            $this->full_brand(),
            'Research findings'
        );

        $expected_system = <<<'EOT'
SYS PROMPT

BRAND CONTEXT:
- Company: Acme
- Industry: Widgets
- Tone of Voice: Bold
- Target Audience: Makers
- Unique Selling Points: Fastest
- Content Language: en


CURRENT SEARCH LANDSCAPE (from live research — use to inform coverage, do not cite):
Research findings
EOT;
        $this->assertSame($expected_system, $msgs[0]['content']);
        $this->assertSame(
            "Write a comprehensive article targeting the keyword: \"lawn care tips\"\n\n"
            . "Return a JSON object with the following fields: title, content (HTML), metaTitle, metaDescription.",
            $msgs[1]['content']
        );
    }

    public function test_byte_identity_full_user_message_with_media_and_rss_rider(): void
    {
        $rss = "Write an article about this social media post: 'T' (https://x/1). INCLUDE a visible link to the original post in the article HTML.";
        $msgs = $this->build_messages(
            array('best running shoes'),
            $this->prompt_template('SYS PROMPT'),
            $this->full_brand(),
            'SERP data here',
            true, 3, 'both', 'show product photos', $rss
        );

        // Media lives on the USER message, so the system side is the brand +
        // research shape pinned above; only assert its markers here.
        $this->assertStringContainsString('BRAND CONTEXT:', $msgs[0]['content']);
        $this->assertStringContainsString('CURRENT SEARCH LANDSCAPE', $msgs[0]['content']);

        $expected_user = <<<'EOT'
Write a comprehensive article targeting the keyword: "best running shoes"

Return a JSON object with the following fields: title, content (HTML), metaTitle, metaDescription.

IN-CONTENT MEDIA: You may add up to 3 supporting visuals. Insert placeholder tokens [IMAGE_1], [IMAGE_2], [IMAGE_3] — each on its OWN line, wrapped in its own <p></p>, at natural points in the HTML body. For EVERY placeholder you insert, add one matching entry to a "media_assets" array, where each entry is {placeholder: "IMAGE_1", type: "image"|"chart", prompt: string, chart_config: string|null}. Each media_assets entry's type must be either "image" or "chart". For type "image", `prompt` is a detailed image-generation prompt and chart_config is null. For type "chart", put a VALID Chart.js config — serialized as a JSON string — in `chart_config`, presenting REAL, meaningful data: at least 3 data points, a named dataset/series (a non-empty `label`), and a descriptive chart title; put a short caption naming the data source in `prompt`. Never use placeholder, filler, or all-identical values. Base chart data on the RESEARCH FINDINGS above (real statistics, real comparisons); cite the source in the chart caption (`prompt` field). CREATIVE DIRECTION for the visuals: "show product photos" — follow it for image subjects/style and for what data the charts present. Only insert a placeholder if you also return its media_assets entry, and never exceed 3.

Write an article about this social media post: 'T' (https://x/1). INCLUDE a visible link to the original post in the article HTML.
EOT;
        $this->assertSame($expected_user, $msgs[1]['content']);
    }

    // ── the feature: each variable resolves, and suppresses its auto-append ─

    public function test_each_fragment_variable_resolves_into_the_system_prompt(): void
    {
        $tpl = $this->prompt_template(
            "Target: {{ keyword }}\n{{ brand_context }}\n{{ research }}\n{{ media_instructions }}\n{{ output_format }}"
        );
        $msgs = $this->build_messages(
            array('best shoes'), $tpl, $this->full_brand(), 'SERP', true, 3, 'both', 'guidance', '', array()
        );

        $sys = $msgs[0]['content'];
        $this->assertStringContainsString('Target: best shoes', $sys);
        $this->assertStringContainsString('BRAND CONTEXT:', $sys);
        $this->assertStringContainsString('CURRENT SEARCH LANDSCAPE', $sys);
        $this->assertStringContainsString('IN-CONTENT MEDIA:', $sys);
        $this->assertStringContainsString('Return a JSON object with the following fields:', $sys);
        $this->assertStringNotContainsString('{{', $sys, 'no known token may reach the model raw');
    }

    public function test_referencing_brand_context_suppresses_its_auto_append_no_duplication(): void
    {
        $tpl = $this->prompt_template("Intro.\n\n{{ brand_context }}");
        $msgs = $this->build_messages(array('kw'), $tpl, $this->full_brand(), '');

        // Exactly one occurrence — the author's placement — never also appended.
        $this->assertSame(1, substr_count($msgs[0]['content'], 'BRAND CONTEXT:'));
        $this->assertStringContainsString('Intro.', $msgs[0]['content']);
    }

    public function test_referencing_research_suppresses_its_auto_append(): void
    {
        $tpl = $this->prompt_template("Intro.\n\n{{ research }}");
        $msgs = $this->build_messages(array('kw'), $tpl, null, 'FINDINGS');

        $this->assertSame(1, substr_count($msgs[0]['content'], 'CURRENT SEARCH LANDSCAPE'));
        $this->assertStringContainsString('FINDINGS', $msgs[0]['content']);
    }

    public function test_keyword_variable_carries_the_consolidated_framing(): void
    {
        // Referencing {{ keyword }} SUPPRESSES the auto-appended user sentence.
        // For a consolidated batch that sentence is the only thing telling the
        // model to write ONE article covering ALL the keywords — a bare comma
        // join silently turned a consolidated strategy into "write a pillar
        // about a list", which is exactly what the seeded template does.
        // So the variable must carry the framing, not just the names.
        $tpl = $this->prompt_template('Write about {{ keyword }}.');
        $msgs = $this->build_messages(array('kw one', 'kw two', 'kw three'), $tpl, null);

        $sys = $msgs[0]['content'];
        $this->assertStringContainsString('"kw one", "kw two", "kw three"', $sys);
        $this->assertStringContainsString('cover ALL of them together', $sys);
        $this->assertStringContainsString('ONE cohesive article', $sys);
        // Still suppressed — the instruction moved into the template, not duplicated.
        $this->assertStringNotContainsString('Write a SINGLE comprehensive article', $msgs[1]['content']);
    }

    public function test_the_empty_turn_nudge_never_repeats_a_fragment_the_template_placed(): void
    {
        // With every user-side variable placed AND no keyword to fall back on,
        // the nudge must not be one of the five fragments: emitting
        // $output_format there put the JSON contract in the prompt twice, which
        // is precisely the hidden-duplicate this whole feature removes.
        $tpl = $this->prompt_template('{{ keyword }} {{ output_format }} {{ media_instructions }}');
        $msgs = $this->build_messages(array(), $tpl, null, '', true);

        $user = $msgs[1]['content'];
        $this->assertNotSame('', trim($user), 'the user turn must never be empty');
        $this->assertStringNotContainsString('Return a JSON object', $user, 'the JSON contract is already in the template');
        $this->assertSame(
            1,
            substr_count($msgs[0]['content'] . $user, 'Return a JSON object'),
            'the output_format fragment must appear exactly once across both turns'
        );
    }

    public function test_the_consolidated_nudge_is_a_BARE_list_not_the_framing(): void
    {
        // {{ keyword }} carries the whole "cover ALL of them in ONE article"
        // framing for a consolidated batch. Reusing it as the empty-turn nudge
        // printed that instruction in BOTH turns — the duplicate-fragment bug
        // this feature removes, relocated onto `keyword`.
        $tpl = $this->prompt_template('{{ keyword }} {{ output_format }} {{ media_instructions }}');
        $msgs = $this->build_messages(array('kw one', 'kw two'), $tpl, null, '', true);

        $this->assertSame('kw one, kw two', $msgs[1]['content'], 'the nudge is a bare list');
        $this->assertSame(1, substr_count($msgs[0]['content'] . $msgs[1]['content'], 'cover ALL of them'),
            'the consolidated framing must appear exactly once across both turns');
    }

    public function test_the_blank_line_collapse_never_touches_post_variables(): void
    {
        // The {{ post_* }} tokens shipped BEFORE the collapse existed, so
        // applying it to them would silently reformat prompts that are supposed
        // to be byte-identical to what they produced then.
        $this->assertSame(
            "A\n\n\n\nB",
            PCM_Strategy_Service::render_source_vars("A\n\n{{ post_title }}\n\nB", array('sourceTitle' => '')),
            'an empty post_* token must leave the author spacing untouched'
        );
    }

    public function test_the_seeded_seo_pillar_template_places_every_variable(): void
    {
        // The owner's requirement is that NOTHING is injected invisibly. The
        // seed is the deliverable, so pin it: all five tokens present, or a
        // future edit silently reintroduces a hidden fragment.
        $seed = file_get_contents(dirname(__DIR__, 2) . '/includes/core/class-pcm-template-seeds.php');
        $this->assertIsString($seed);
        $start = strpos($seed, "'key' => 'seo_pillar_prompt'");
        $this->assertNotFalse($start, 'seo_pillar_prompt seed not found — renamed?');
        // Bound to THIS seed's own value string. A fixed window overran the
        // 1,144-char value into the next template, so a token added to a
        // NEIGHBOURING seed would have satisfied this test.
        $this->assertSame(1, preg_match("/'value' => '(.*?)',\n/s", substr($seed, $start), $mm));
        $block = $mm[1];

        foreach (array('keyword', 'brand_context', 'research', 'output_format', 'media_instructions') as $var) {
            $this->assertMatchesRegularExpression(
                '/\{\{\s*' . $var . '\s*\}\}/',
                $block,
                "the seeded SEO Pillar prompt must place {{ $var }}"
            );
        }
    }

    public function test_an_empty_variable_does_not_leave_a_stack_of_blank_lines(): void
    {
        // The seeded template puts each fragment on its own line with blank
        // lines around it. With no brand and research off, those tokens resolve
        // to '' and the surrounding blank lines stack — six newlines before the
        // next heading. Collapse only when something actually emptied.
        $tpl = $this->prompt_template("INTRO\n\n{{ brand_context }}\n\n{{ research }}\n\nHEADING:");
        $msgs = $this->build_messages(array('kw'), $tpl, null);

        $this->assertSame(0, preg_match_all('/\n{3,}/', $msgs[0]['content']), 'no run of 3+ newlines');
        $this->assertStringContainsString("INTRO\n\nHEADING:", $msgs[0]['content']);
    }

    public function test_spacing_is_untouched_when_every_variable_resolves(): void
    {
        // The collapse must not reformat a prompt whose variables all filled —
        // only the empty-token case earns it.
        $tpl = $this->prompt_template("A\n\n\n\nB {{ keyword }}");
        $msgs = $this->build_messages(array('kw'), $tpl, null);

        $this->assertStringContainsString("A\n\n\n\nB kw", $msgs[0]['content']);
    }

    public function test_a_variable_in_ANY_prompt_entry_suppresses_the_append(): void
    {
        // The documented contract is "the template is the unit, not one entry".
        // Nothing pinned it: capturing only the FIRST entry left the suite green.
        $tpl = array('entries' => array(
            array('category' => 'prompt', 'value' => 'FIRST ENTRY'),
            array('category' => 'prompt', 'value' => 'SECOND {{ brand_context }}'),
        ));
        $msgs = $this->build_messages(array('kw'), $tpl, $this->full_brand());

        $this->assertSame(1, substr_count($msgs[0]['content'], 'BRAND CONTEXT:'), 'placed once, never also appended');
    }

    public function test_a_variable_in_a_NON_prompt_entry_does_not_suppress(): void
    {
        // Only 'prompt' entries become the system message, so a token in a
        // guidance/title entry must NOT cancel the auto-append — otherwise the
        // brand block would vanish from the prompt entirely.
        $tpl = array('entries' => array(
            array('category' => 'guidance', 'value' => 'notes: {{ brand_context }}'),
            array('category' => 'prompt',   'value' => 'REAL PROMPT'),
        ));
        $msgs = $this->build_messages(array('kw'), $tpl, $this->full_brand());

        $this->assertStringContainsString('BRAND CONTEXT:', $msgs[0]['content'], 'still auto-appended');
        $this->assertStringNotContainsString('notes:', $msgs[0]['content'], 'non-prompt entries never reach the prompt');
    }

    public function test_render_and_suppress_agree_on_whitespace_inside_the_token(): void
    {
        // The two regexes must stay in lockstep. If suppression were stricter
        // than substitution, "{{keyword}}" would render AND get the sentence
        // auto-appended — the fragment placed and appended at once.
        foreach (array('{{keyword}}', '{{  keyword  }}', "{{\tkeyword\t}}") as $token) {
            $msgs = $this->build_messages(array('seo tools'), $this->prompt_template("Target: $token."), null);
            $this->assertStringContainsString('Target: seo tools.', $msgs[0]['content'], "rendered: $token");
            $this->assertStringNotContainsString(
                'targeting the keyword',
                $msgs[1]['content'],
                "suppressed: $token"
            );
        }
    }

    public function test_a_template_with_no_prompt_entries_still_gets_the_default_system_line(): void
    {
        $msgs = $this->build_messages(array('kw'), array('entries' => array()), null);

        $this->assertStringContainsString('You are an expert SEO content writer.', $msgs[0]['content']);
    }

    public function test_a_token_split_across_two_entries_is_neither_placed_nor_lost(): void
    {
        // Concatenating entries before matching meant a token split across two
        // of them matched the JOIN, suppressed the append, and rendered in
        // neither — the brand block disappeared silently. Per-entry matching
        // means the halves stay literal AND the block is still appended.
        $tpl = array('entries' => array(
            array('category' => 'prompt', 'value' => 'Part one {{'),
            array('category' => 'prompt', 'value' => 'brand_context }} part two'),
        ));
        $msgs = $this->build_messages(array('kw'), $tpl, $this->full_brand());

        $this->assertStringContainsString('BRAND CONTEXT:', $msgs[0]['content'], 'the fragment must not vanish');
    }

    public function test_a_single_keyword_stays_a_bare_keyword(): void
    {
        // The framing above must NOT leak into the ordinary per-item case.
        $tpl = $this->prompt_template('Write about {{ keyword }}.');
        $msgs = $this->build_messages(array('seo tools'), $tpl, null);

        $this->assertStringContainsString('Write about seo tools.', $msgs[0]['content']);
        $this->assertStringNotContainsString('cover ALL of them', $msgs[0]['content']);
    }

    public function test_brand_context_resolves_to_empty_when_there_is_no_brand(): void
    {
        $tpl = $this->prompt_template("Intro.{{ brand_context }}End");
        $msgs = $this->build_messages(array('kw'), $tpl, null, '');

        // No brand → the variable is '' (token removed) and nothing is appended.
        $this->assertSame('Intro.End', $msgs[0]['content']);
    }

    public function test_media_instructions_suppresses_the_user_message_media_block(): void
    {
        $tpl = $this->prompt_template("Intro.\n\n{{ media_instructions }}");
        $msgs = $this->build_messages(array('kw'), $tpl, null, '', true, 3, 'both', '', '');

        // Resolved into the system prompt where the author placed it…
        $this->assertStringContainsString('IN-CONTENT MEDIA:', $msgs[0]['content']);
        // …and NOT duplicated onto the user message.
        $this->assertStringNotContainsString('IN-CONTENT MEDIA:', $msgs[1]['content']);
    }

    public function test_output_format_variable_is_the_json_return_contract_and_suppresses_the_user_turn_copy(): void
    {
        $tpl = $this->prompt_template("Do the work.\n\n{{ output_format }}");
        $msgs = $this->build_messages(array('kw'), $tpl, null, '');

        // The variable carries the exact return-instruction sentence…
        $this->assertStringContainsString(
            'Return a JSON object with the following fields: title, content (HTML), metaTitle, metaDescription.',
            $msgs[0]['content']
        );
        // …and, being referenced, is NOT also injected onto the user turn.
        $this->assertStringNotContainsString('Return a JSON object', $msgs[1]['content']);
    }

    public function test_the_rss_rider_is_still_appended_when_other_variables_are_used(): void
    {
        // The RSS/social rider is NOT one of the new variables — it keeps its
        // own template_carries_source() suppression decided at the call site, so
        // it rides the user message here regardless of the other variables used.
        $tpl = $this->prompt_template('Intro with {{ keyword }}.');
        $rss = "Write an article about this social media post: 'T' (https://x/1).";
        $msgs = $this->build_messages(array('kw'), $tpl, null, '', false, 3, 'both', '', $rss);

        $this->assertStringContainsString('Write an article about this social media post', $msgs[1]['content']);
    }
}
