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
}
