<?php
/**
 * PCM_LLM::repair_json() — the mechanical rescue for almost-valid LLM JSON.
 *
 * Loads the REAL class file (it is hook-free; only ABSPATH must be defined)
 * and exercises the pure static repair path that sits in front of the
 * "LLM returned invalid JSON" terminal error: Gemini's two known decode-fatal
 * quirks (raw control characters inside string values of long HTML fields,
 * trailing commas) must recover; genuinely unrecoverable output must not.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */

use PHPUnit\Framework\TestCase;

class LlmJsonRepairTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/');
        }
        require_once dirname(__DIR__, 2) . '/includes/core/llm/class-pcm-llm.php';
    }

    public function test_raw_control_chars_inside_strings_are_repaired(): void
    {
        // A literal newline + tab inside a string value — json_decode fails
        // with JSON_ERROR_CTRL_CHAR; the repair must escape them in place.
        $bad = "{\"title\":\"Hello\",\"content\":\"<p>line one\nline two\tend</p>\"}";
        $this->assertNull(json_decode($bad, true));

        $repaired = PCM_LLM::repair_json($bad);
        $this->assertIsArray($repaired);
        $this->assertSame("<p>line one\nline two\tend</p>", $repaired['content']);
        $this->assertSame('Hello', $repaired['title']);
    }

    public function test_trailing_commas_are_repaired(): void
    {
        $bad = '{"a": 1, "b": [1, 2, 3,], }';
        $this->assertNull(json_decode($bad, true));

        $repaired = PCM_LLM::repair_json($bad);
        $this->assertIsArray($repaired);
        $this->assertSame(1, $repaired['a']);
        $this->assertSame(array(1, 2, 3), $repaired['b']);
    }

    public function test_both_quirks_in_one_payload_recover(): void
    {
        $bad = "{\"content\":\"a\nb\",\"keys\":[\"x\",\"y\",],}";
        $repaired = PCM_LLM::repair_json($bad);
        $this->assertIsArray($repaired);
        $this->assertSame("a\nb", $repaired['content']);
        $this->assertSame(array('x', 'y'), $repaired['keys']);
    }

    public function test_escaped_quotes_do_not_confuse_the_string_walker(): void
    {
        // The \" inside the value must not end the string state — the raw
        // newline AFTER it is still in-string and must be escaped.
        $bad = "{\"content\":\"she said \\\"hi\\\"\nnext line\"}";
        $repaired = PCM_LLM::repair_json($bad);
        $this->assertIsArray($repaired);
        $this->assertSame("she said \"hi\"\nnext line", $repaired['content']);
    }

    public function test_multibyte_content_survives_the_byte_walk(): void
    {
        $bad = "{\"content\":\"åäö emoji 🚀 twå\nrader\"}";
        $repaired = PCM_LLM::repair_json($bad);
        $this->assertIsArray($repaired);
        $this->assertSame("åäö emoji 🚀 twå\nrader", $repaired['content']);
    }

    public function test_structural_whitespace_outside_strings_is_untouched(): void
    {
        // Newlines BETWEEN tokens are legal JSON; a payload that only has
        // those plus a trailing comma repairs via the comma rule alone.
        $bad = "{\n  \"a\": 1,\n  \"b\": 2,\n}";
        $repaired = PCM_LLM::repair_json($bad);
        $this->assertIsArray($repaired);
        $this->assertSame(array('a' => 1, 'b' => 2), $repaired);
    }

    public function test_truncated_mid_string_output_is_not_rescued(): void
    {
        // Output cut at the token cap mid-string: repair must refuse (the
        // doubled-budget retry owns this case) rather than invent half a doc.
        $bad = '{"title":"Hello","content":"<p>this article was cut off righ';
        $this->assertNull(PCM_LLM::repair_json($bad));
    }

    public function test_empty_and_non_json_return_null(): void
    {
        $this->assertNull(PCM_LLM::repair_json(''));
        $this->assertNull(PCM_LLM::repair_json('Sorry, I cannot help with that.'));
    }

    public function test_valid_json_passes_through_unchanged(): void
    {
        $good = '{"a":"clean"}';
        $this->assertSame(array('a' => 'clean'), PCM_LLM::repair_json($good));
    }

    // ── looks_truncated() — the structural truncation detector that fires the
    //    doubled-budget retry even when the API omits finish_reason='length'. ──

    public function test_looks_truncated_flags_output_cut_mid_string(): void
    {
        // The real production failure: a long listicle article cut off inside
        // its content string (no closing quote/brace) → "Syntax error".
        $this->assertTrue(PCM_LLM::looks_truncated(
            '{"title":"Top 12 SEO Companies","content":"<p>The best agencies righ'
        ));
    }

    public function test_looks_truncated_flags_unclosed_containers(): void
    {
        $this->assertTrue(PCM_LLM::looks_truncated('{"a":1,"media":[{"id":"IMAGE_1"'));
        $this->assertTrue(PCM_LLM::looks_truncated('{"a":1'));
    }

    public function test_looks_truncated_flags_empty(): void
    {
        $this->assertTrue(PCM_LLM::looks_truncated(''));
        $this->assertTrue(PCM_LLM::looks_truncated('   '));
    }

    public function test_looks_truncated_passes_complete_json(): void
    {
        $this->assertFalse(PCM_LLM::looks_truncated('{"title":"X","content":"<p>done</p>"}'));
        $this->assertFalse(PCM_LLM::looks_truncated('{"a":[1,2,3],"b":{"c":"d"}}'));
    }

    public function test_looks_truncated_ignores_braces_and_quotes_inside_strings(): void
    {
        // Escaped quotes and structural chars inside string VALUES must not be
        // miscounted — this complete doc is NOT truncated.
        $this->assertFalse(PCM_LLM::looks_truncated(
            '{"content":"<p>Use {curly} and \"quoted\" braces [here]</p>"}'
        ));
    }

    // ── Schema-aware salvage (unescaped inner quotes — the prod failure) ──

    public function test_salvage_recovers_unescaped_inner_quotes_in_content(): void
    {
        // The REAL prod shape: strict schema, so the trailing non-string
        // `media_assets` key IS present in the body. The model embedded the
        // caption's quotes without escaping them → json_decode AND repair_json
        // both fail. The last string field (metaDescription) must NOT swallow
        // the trailing `,"media_assets":null}` — it is bounded by that key's
        // marker.
        $bad = '{"title":"Messi Bows Out","content":"<p>Argentina to Messi: "Thank You" — a fitting end.</p>","metaTitle":"Messi","metaDescription":"The end of an era for "La Albiceleste" fans.","media_assets":null}';
        $this->assertNull(json_decode($bad, true), 'precondition: raw is unparseable');
        $this->assertNull(PCM_LLM::repair_json($bad), 'precondition: repair_json cannot fix inner quotes');

        $out = PCM_LLM::salvage_json_by_keys(
            $bad,
            array('title', 'content', 'metaTitle', 'metaDescription'),
            array('media_assets')
        );
        $this->assertIsArray($out);
        $this->assertSame('Messi Bows Out', $out['title']);
        $this->assertSame('<p>Argentina to Messi: "Thank You" — a fitting end.</p>', $out['content']);
        $this->assertSame('Messi', $out['metaTitle']);
        $this->assertSame('The end of an era for "La Albiceleste" fans.', $out['metaDescription'], 'the trailing media_assets key must not bleed into metaDescription');
        $this->assertNull($out['media_assets'], 'non-string required keys are nulled');
    }

    public function test_salvage_does_not_swallow_a_populated_trailing_media_array(): void
    {
        // media_assets carries a real array (with its own quotes/braces) after
        // the last string field — metaDescription must still end cleanly at the
        // media_assets marker, not run into the array.
        $bad = '{"title":"a","content":"<p>He said "go" now</p>","metaTitle":"t","metaDescription":"clean end here","media_assets":[{"placeholder":"IMAGE_1","prompt":"a "cool" shot"}]}';
        $out = PCM_LLM::salvage_json_by_keys(
            $bad,
            array('title', 'content', 'metaTitle', 'metaDescription'),
            array('media_assets')
        );
        $this->assertIsArray($out);
        $this->assertSame('clean end here', $out['metaDescription']);
        $this->assertNull($out['media_assets']);
    }

    public function test_salvage_combines_surrogate_pair_emoji(): void
    {
        // An emoji emitted as an ESCAPED UTF-16 surrogate pair (😀)
        // must decode to the single astral codepoint, not two U+FFFD chars.
        // NOTE: the \u sequences are written as real backslashes in the JSON, so
        // the string literal below escapes the backslash (\\u...).
        $bad = "{\"title\":\"hi \\uD83D\\uDE00 there\",\"content\":\"<p>x \"y\" z</p>\",\"metaTitle\":\"t\",\"metaDescription\":\"d\"}";
        $this->assertStringContainsString('\\uD83D\\uDE00', $bad, 'precondition: the raw carries an ESCAPED surrogate pair');
        $this->assertStringNotContainsString('😀', $bad, 'precondition: NOT a literal emoji — the surrogate branch must do the work');

        $out = PCM_LLM::salvage_json_by_keys(
            $bad,
            array('title', 'content', 'metaTitle', 'metaDescription')
        );
        $this->assertIsArray($out);
        $this->assertSame('hi 😀 there', $out['title']);
    }

    public function test_salvage_ignores_a_key_lookalike_inside_content(): void
    {
        // Article HTML that literally shows `"metaTitle":` as prose (not
        // preceded by a structural { or ,) must not be taken as the field
        // boundary — the real metaTitle marker (preceded by a comma) wins.
        $bad = '{"title":"a","content":"<p>Set the tag like \"metaTitle\": \"Foo\" in your head.</p>","metaTitle":"Real MT","metaDescription":"d"}';
        $out = PCM_LLM::salvage_json_by_keys(
            $bad,
            array('title', 'content', 'metaTitle', 'metaDescription')
        );
        $this->assertIsArray($out);
        $this->assertStringContainsString('Set the tag like', $out['content']);
        $this->assertSame('Real MT', $out['metaTitle']);
    }

    public function test_salvage_refuses_when_a_structural_key_marker_survives_a_misslice(): void
    {
        // A STRUCTURAL comma-preceded key lookalike inside content (article that
        // literally prints JSON with `,"metaTitle":"…"`) can mis-slice. Rather
        // than publish garbage, the post-salvage sanity gate rejects it: a
        // correctly-bounded value never still contains a `[{,]"schemaKey":`
        // marker. → null, so the caller fails loudly instead.
        $bad = '{"title":"a","content":"<p>example config: ,"metaTitle":"Hijacked" shown</p>","metaTitle":"Real MT","metaDescription":"d"}';
        $this->assertNull(PCM_LLM::salvage_json_by_keys(
            $bad,
            array('title', 'content', 'metaTitle', 'metaDescription'),
            array('media_assets')
        ), 'a surviving structural key marker means a mis-slice — refuse rather than publish garbage');
    }

    public function test_salvage_honors_standard_escapes_and_emoji(): void
    {
        // Mixed: a properly-escaped \" and \n survive decoding, an emoji passes
        // through, and an UNescaped quote later in the same value is tolerated.
        $bad = '{"title":"🤯 THE GREATEST TEENAGER","content":"<p>Line one\nShe said \"hi\" then said "bye" loudly 🇪🇸</p>","metaTitle":"t","metaDescription":"d"}';
        $out = PCM_LLM::salvage_json_by_keys(
            $bad,
            array('title', 'content', 'metaTitle', 'metaDescription')
        );
        $this->assertIsArray($out);
        $this->assertSame('🤯 THE GREATEST TEENAGER', $out['title']);
        $this->assertSame('<p>Line one' . "\n" . 'She said "hi" then said "bye" loudly 🇪🇸</p>', $out['content']);
    }

    public function test_salvage_refuses_when_a_required_string_key_is_missing(): void
    {
        // metaDescription absent → salvage returns null rather than a half article.
        $bad = '{"title":"x","content":"y","metaTitle":"z"}';
        $this->assertNull(PCM_LLM::salvage_json_by_keys(
            $bad,
            array('title', 'content', 'metaTitle', 'metaDescription')
        ));
    }

    public function test_salvage_recovers_odd_count_of_unescaped_quotes(): void
    {
        // An ODD number of unescaped inner quotes makes looks_truncated() read
        // the tail as "still in a string" (false-positive truncated) — which is
        // exactly why the salvage guard keys on the closing brace, NOT on
        // looks_truncated(). Salvage itself is bounded by the key markers, so it
        // recovers the value regardless of the stray quote count.
        $bad = '{"title":"a","content":"<p>she said "hi and "bye" and left</p>","metaTitle":"t","metaDescription":"d"}';
        $this->assertTrue(PCM_LLM::looks_truncated($bad), 'odd inner quotes fool looks_truncated');
        $this->assertSame('}', substr(rtrim($bad), -1), 'but the object IS structurally closed — the real gate');

        $out = PCM_LLM::salvage_json_by_keys(
            $bad,
            array('title', 'content', 'metaTitle', 'metaDescription'),
            array('media_assets')
        );
        $this->assertIsArray($out);
        $this->assertSame('<p>she said "hi and "bye" and left</p>', $out['content']);
        $this->assertSame('d', $out['metaDescription']);
    }

    public function test_genuinely_truncated_output_has_no_closing_brace_so_salvage_is_gated_out(): void
    {
        // A value cut off at the token cap does NOT end in `}` — the guard in
        // invoke_json_fallback() skips salvage on it (no complete final field to
        // bound), leaving the truncation retry / terminal error to handle it.
        $truncated = '{"title":"ok","content":"<p>this article was cut off mid sen';
        $this->assertNotSame('}', substr(rtrim($truncated), -1));
    }

    public function test_schema_partition_splits_the_article_schema_string_fields(): void
    {
        // Mirrors service.php::article_schema() — the wiring must feed salvage
        // the four string fields and null the media array.
        $schema = array(
            'name'   => 'article_output',
            'schema' => array(
                'type'     => 'object',
                'required' => array('title', 'content', 'metaTitle', 'metaDescription', 'media_assets'),
                'properties' => array(
                    'title'           => array('type' => 'string'),
                    'content'         => array('type' => 'string'),
                    'metaTitle'       => array('type' => 'string'),
                    'metaDescription' => array('type' => 'string'),
                    'media_assets'    => array('type' => array('array', 'null')),
                ),
            ),
        );
        $m = new \ReflectionMethod(PCM_LLM::class, 'schema_key_partition');
        list($stringKeys, $nullKeys) = $m->invoke(null, $schema);
        $this->assertSame(array('title', 'content', 'metaTitle', 'metaDescription'), $stringKeys);
        $this->assertSame(array('media_assets'), $nullKeys);
    }

    // ── extract_json: narrowing the reply to the JSON value ──────────────
    //
    // These sit in FRONT of every tier above — a bad extraction hands repair
    // and salvage a fragment they cannot possibly recover, so a defect here
    // presents as the same opaque "LLM returned invalid JSON" the salvage was
    // built to prevent.

    public function test_extract_json_survives_a_code_fence_inside_the_article_content(): void
    {
        // The reply is fenced AND the generated HTML itself contains a ```
        // fence. A non-greedy fence match ends at the INNER fence and returns
        // a fragment cut mid-string; the extraction must run to the LAST fence.
        $raw = "```json\n"
            . '{"title":"T","content":"<p>Use ```code``` here</p>","metaTitle":"m","metaDescription":"d","media_assets":null}'
            . "\n```";

        $decoded = json_decode(PCM_LLM::extract_json($raw), true);

        $this->assertIsArray($decoded, 'an inner fence must not truncate the extraction');
        $this->assertSame('<p>Use ```code``` here</p>', $decoded['content']);
    }

    public function test_extract_json_documents_the_leading_prose_bracket_limit(): void
    {
        // KNOWN LIMIT, pinned so it is not mistaken for working: leading prose
        // containing a bracket hijacks the span. Two attempts to fix it by
        // ranking `{`/`[` candidates each introduced a worse failure — an inner
        // well-formed array winning and yielding a silent EMPTY article — so
        // the original behaviour stands until someone does a real
        // brace-matching scan. This test asserts the CURRENT behaviour; flip it
        // when that scan lands.
        $raw = 'Here is the article [as requested]: '
            . '{"title":"T","content":"<p>x</p>","metaTitle":"m","metaDescription":"d","media_assets":null}';

        $extracted = PCM_LLM::extract_json($raw);

        $this->assertNull(
            json_decode($extracted, true),
            'documents the known limit — extraction is hijacked by the prose bracket'
        );
        // Pin the exact span, not just "it does not decode": the span runs from
        // the prose `[` to the LAST `]`, which here is the prose's own closing
        // bracket — so the entire article object is discarded and the repair
        // and salvage tiers downstream receive this instead. Asserting only
        // non-decodability would let that detail drift unnoticed.
        $this->assertSame('[as requested]', $extracted);
    }

    public function test_extract_json_still_returns_a_genuine_array_reply(): void
    {
        // Candidate ranking must not break a reply that really is a JSON array.
        $decoded = json_decode(PCM_LLM::extract_json('[{"a":1},{"b":2}]'), true);

        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
    }

    public function test_extract_json_does_not_reduce_a_single_element_array_to_its_object(): void
    {
        // The dangerous shape: the OBJECT span of `[{"a":1}]` decodes cleanly on
        // its own, so an object-first preference silently returns `{"a":1}` and
        // the caller sees a map where the model sent a list. Ranking candidates
        // by opening offset is what keeps this an array.
        $decoded = json_decode(PCM_LLM::extract_json('[{"a":1}]'), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey(0, $decoded, 'a one-element array must stay a list, not become its element');
        $this->assertSame(array('a' => 1), $decoded[0]);

        $tail = json_decode(PCM_LLM::extract_json('["x",{"a":1}]'), true);
        $this->assertSame(array('x', array('a' => 1)), $tail);
    }

    /**
     * THE dangerous regression, end-to-end: a malformed object that contains a
     * well-formed array. `media_assets` is REQUIRED by article_schema(), so
     * every real article payload has one. If extraction returns that inner
     * array, invoke_json_fallback() sees a successful parse (is_array() true,
     * no json error), never reaches salvage, never throws — and the item
     * completes with `content` defaulting to '' (strategy/service.php reads
     * `$result['content'] ?? ''`). Silent empty article instead of a loud error.
     *
     * @dataProvider mediaAssetsShapes
     */
    public function test_extract_json_never_returns_the_inner_media_array_of_a_broken_object(string $media): void
    {
        $raw = '{"title":"T","content":"<p>A 26" screen</p>","metaTitle":"m","metaDescription":"d","media_assets":' . $media . '}';

        $extracted = PCM_LLM::extract_json($raw);

        $this->assertSame('{', substr($extracted, 0, 1), 'extraction must keep the object root, not the inner array');
        $this->assertSame('}', substr(rtrim($extracted), -1));

        // And the object span must still be recoverable end-to-end.
        $salvaged = PCM_LLM::salvage_json_by_keys(
            rtrim($extracted),
            array('title', 'content', 'metaTitle', 'metaDescription'),
            array('media_assets')
        );
        $this->assertIsArray($salvaged, 'the four string keys must still salvage');
        $this->assertSame('T', $salvaged['title']);
        $this->assertStringContainsString('26" screen', $salvaged['content']);
    }

    public function mediaAssetsShapes(): array
    {
        return array(
            'empty array'     => array('[]'),
            'populated array' => array('[{"placeholder":"IMAGE_1","type":"image","prompt":"a stadium","chart_config":null}]'),
            'null'            => array('null'),
        );
    }

    /**
     * @dataProvider nonJsonFenceBodyProvider
     */
    public function test_extract_json_never_mines_a_decodable_span_out_of_a_non_json_fence(string $body): void
    {
        // The fence branch must RETURN its body, never fall through to the
        // brace scan. Falling through lets the scan carve a decodable fragment
        // out of a fence that is not JSON at all — e.g. a ```js block
        // containing `{}` yields "{}", which decodes to an empty array, passes
        // every `!is_array($parsed)` gate in invoke_json_fallback(), and
        // completes the item with an EMPTY article instead of throwing.
        // A decodable-but-wrong value returned silently is strictly worse than
        // a loud failure.
        $extracted = PCM_LLM::extract_json($body);
        $decoded   = json_decode($extracted, true);

        $this->assertFalse(
            is_array($decoded) && $decoded === array(),
            'a non-JSON fence must not yield an empty-array decode — that becomes a silent empty article'
        );
    }

    public function nonJsonFenceBodyProvider(): array
    {
        return array(
            'js sample with empty braces'  => array("```js\nconst cfg = {};\n```"),
            'css sample with empty braces' => array("```css\n.a { }\n```"),
            'prose with a bracket list'    => array("```json\nSteps [1,2,3] then nothing\n```"),
        );
    }

    public function test_extract_json_handles_two_separate_fenced_blocks(): void
    {
        // A JSON block followed by a usage sample. Widening the fence match
        // unconditionally would weld the two together into unparseable text —
        // the narrow match has to win whenever it already yields valid JSON.
        $raw = "```json\n{\"a\":1}\n```\nThen use it:\n```js\nif (x) { y(); }\n```";

        $decoded = json_decode(PCM_LLM::extract_json($raw), true);

        $this->assertSame(array('a' => 1), $decoded, 'a trailing second fence must not corrupt the first block');
    }

    public function test_extract_json_handles_a_plain_fenced_object_and_a_bare_object(): void
    {
        $fenced = json_decode(PCM_LLM::extract_json("```json\n{\"a\":1}\n```"), true);
        $this->assertSame(array('a' => 1), $fenced);

        $bare = json_decode(PCM_LLM::extract_json('{"a":1}'), true);
        $this->assertSame(array('a' => 1), $bare);

        $prosed = json_decode(PCM_LLM::extract_json('Sure! {"a":1} Hope that helps.'), true);
        $this->assertSame(array('a' => 1), $prosed);
    }

    public function test_extract_json_keeps_a_malformed_object_intact_for_the_repair_tiers(): void
    {
        // When nothing decodes, extraction must still hand the OBJECT span to
        // repair/salvage rather than an array fragment — otherwise the salvage
        // gate ("must end with }") can never pass.
        $raw = '{"title":"T","content":"<p>He said "hi" [IMAGE_1]</p>","metaTitle":"m","metaDescription":"d","media_assets":null}';

        $extracted = PCM_LLM::extract_json($raw);

        $this->assertSame('{', substr($extracted, 0, 1));
        $this->assertSame('}', substr(rtrim($extracted), -1), 'the salvage gate depends on the closing brace surviving');
        $salvaged = PCM_LLM::salvage_json_by_keys(
            $extracted,
            array('title', 'content', 'metaTitle', 'metaDescription'),
            array('media_assets')
        );
        $this->assertIsArray($salvaged, 'the object span must remain salvageable end-to-end');
        $this->assertSame('T', $salvaged['title']);
    }

    // ── describe_json_failure: the terminal error must not misdiagnose ───

    public function test_failure_detail_reports_truncation_from_finish_reason(): void
    {
        $detail = PCM_LLM::describe_json_failure(true, '{"title":"T","content":"<p>cut');

        $this->assertStringContainsString('TRUNCATED', $detail);
        $this->assertStringContainsString('finish_reason=length', $detail);
    }

    public function test_failure_detail_reports_truncation_from_shape_when_finish_reason_is_absent(): void
    {
        // Gemini's OpenAI-compat endpoint truncates without setting
        // finish_reason — the structure is the only signal left.
        $detail = PCM_LLM::describe_json_failure(false, '{"title":"T","content":"<p>cut off mid sen');

        $this->assertStringContainsString('TRUNCATED', $detail);
        $this->assertStringContainsString('unterminated JSON structure', $detail);
    }

    public function test_failure_detail_does_not_cry_truncation_on_an_unescaped_quote(): void
    {
        // THE misdiagnosis guard. looks_truncated() counts quote parity, so a
        // COMPLETE reply carrying an unescaped inner quote reads as "still in a
        // string". That is exactly the payload class that reaches the terminal
        // error (salvage refused it), so trusting the shape signal alone would
        // blame the token budget for a quote-escaping bug.
        $complete_but_broken = '{"title":"T","content":"<p>A 26" screen</p>","metaTitle":"m","metaDescription":"d","media_assets":null}';
        $this->assertTrue(PCM_LLM::looks_truncated($complete_but_broken), 'precondition: the shape scan false-positives here');

        $detail = PCM_LLM::describe_json_failure(false, $complete_but_broken);

        $this->assertStringNotContainsString('TRUNCATED', $detail);
        $this->assertStringContainsString('appears complete but unparseable', $detail);
    }

    public function test_failure_detail_names_an_empty_reply_rather_than_blaming_the_budget(): void
    {
        // extract_json() hands back its input unchanged when it finds no
        // braces, so an empty span means the reply itself was empty — say that,
        // rather than pointing the reader at a token budget.
        $detail = PCM_LLM::describe_json_failure(false, '');

        $this->assertStringContainsString('EMPTY reply', $detail);
        $this->assertStringNotContainsString('too long for the budget', $detail);
    }

    public function test_failure_detail_needs_BOTH_incompleteness_and_the_shape_scan(): void
    {
        // Pins the negative half of the `$incomplete && looks_truncated()`
        // conjunct. This span does not end in `}`/`]` (so it IS "incomplete"),
        // but the shape scan reports complete — dropping the second operand
        // would make this claim TRUNCATED and blame the token budget.
        $bare_string = '"a complete bare string"';
        $this->assertFalse(PCM_LLM::looks_truncated($bare_string), 'precondition: the shape scan sees it as complete');
        $this->assertNotSame('}', substr($bare_string, -1), 'precondition: it does not close with a brace');

        $detail = PCM_LLM::describe_json_failure(false, $bare_string);

        $this->assertStringNotContainsString('TRUNCATED', $detail);
    }

    public function test_failure_detail_prefers_finish_reason_over_an_empty_span(): void
    {
        // finish_reason is the only NON-heuristic truncation signal. An empty
        // extracted span must not short-circuit ahead of it: the API explicitly
        // said the reply was cut at the token limit, and reporting "empty
        // reply" instead would discard the one authoritative diagnosis.
        $detail = PCM_LLM::describe_json_failure(true, '');

        $this->assertStringContainsString('TRUNCATED', $detail);
        $this->assertStringContainsString('finish_reason=length', $detail);
        $this->assertStringNotContainsString('EMPTY reply', $detail);
    }

    public function test_failure_detail_does_not_cry_truncation_on_a_complete_array_root(): void
    {
        // extract_json() can return an array-rooted span; a complete-but-broken
        // array ends in `]`, and treating that as incomplete would re-open the
        // quote-parity false positive for the array root.
        $detail = PCM_LLM::describe_json_failure(false, '["a 26" screen"]');

        $this->assertStringNotContainsString('TRUNCATED', $detail);
    }
}
