<?php
/**
 * Unit Tests — no-emoji article text for Source=Social strategies.
 *
 *   - PCM_Strategy_Service::strip_emoji() — the pure static that strips emoji
 *     from the ARTICLE (title + body) the LLM writes from a social post, while
 *     leaving CJK / Latin / digits / punctuation and HTML tags untouched;
 *   - PCM_Strategy_Service::social_source_image() — resolves a social item's
 *     captured post image to reuse as the article's featured image (null for
 *     non-social / image-less items → the AI featured-image fallback).
 *
 * House-style fakes: ABSPATH, sanitize_text_field, esc_url_raw, wp_json_encode
 * come from the shared stand-ins in StrategyAutoPublishTest.php via
 * pcm_test_define_strategy_fakes(). strip_emoji() is public; social_source_image()
 * is private and invoked via reflection (same house pattern as
 * StrategyRssWatcherTest's rss_source_instruction() harness).
 *
 * @package PowerCreatives\Tests\Unit
 */

require_once __DIR__ . '/StrategyAutoPublishTest.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategyEmojiStripTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_strategy_fakes();
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/service.php';
    }

    /** Invoke the private social_source_image() (PHP 8.1+: no setAccessible()). */
    private function socialSourceImage(array $item_cfg): ?string
    {
        $method = new \ReflectionMethod(PCM_Strategy_Service::class, 'social_source_image');
        return $method->invoke(null, $item_cfg);
    }

    // ── strip_emoji ──────────────────────────────────────────────────────

    public function test_strips_pictographs_and_dingbats(): void
    {
        $this->assertSame('Hello world', PCM_Strategy_Service::strip_emoji('Hello 🚀 world ✅'));
    }

    public function test_strips_zwj_sequences_and_flags(): void
    {
        $this->assertSame('Family trip done', PCM_Strategy_Service::strip_emoji('Family 👨‍👩‍👧 trip 🇺🇸 done'));
    }

    public function test_strips_misc_symbols_and_keycap_selectors(): void
    {
        // ☀ (U+2600) misc symbol; ❤️ (U+2764 + U+FE0F); the '#' of '#️⃣' survives,
        // its keycap selectors (FE0F + 20E3) are stripped.
        $this->assertSame('Sun Heart #1', PCM_Strategy_Service::strip_emoji('Sun ☀ Heart ❤️ #️⃣1'));
    }

    public function test_malformed_utf8_returns_the_text_untouched_instead_of_wiping_it(): void
    {
        // REGRESSION (verifier F14, proven): preg_replace with /u returns NULL on
        // malformed UTF-8, and (string)null is '' — so a single bad byte anywhere
        // in the article saved an EMPTY title, body and slug, silently, with no
        // exception and no log. Emoji removal is cosmetic; losing the article is
        // not, so a failed match must hand the text back unchanged.
        $bad = "<h2>Intro</h2><p>bad byte \xC3\x28 here</p><p>More</p>";

        $out = PCM_Strategy_Service::strip_emoji($bad);

        $this->assertNotSame('', $out, 'malformed UTF-8 must never wipe the article');
        $this->assertSame($bad, $out);
    }

    public function test_strips_the_stars_and_arrows_block(): void
    {
        // U+2B00-U+2BFF sits ABOVE the dingbats range and was surviving — ⭐ is
        // one of the most common emoji in social captions, so an article written
        // from such a post kept it.
        $this->assertSame('Rated five and up', PCM_Strategy_Service::strip_emoji('Rated ⭐ five ⬆ and up'));
    }

    public function test_strips_enclosed_alphanumeric_and_ideographic_supplements(): void
    {
        // U+1F000-U+1F2FF (below the pictographs block) carries 🆕 🆗 🆚 🈁 🈚 —
        // emoji that were surviving because the class started at U+1F300.
        $this->assertSame('Status now then', PCM_Strategy_Service::strip_emoji('Status 🆕 now 🆚 then'));
    }

    public function test_preserves_typographic_punctuation_and_currency(): void
    {
        // Guards the widened ranges against over-reach: an em dash, a euro sign
        // and smart quotes must survive an emoji strip untouched.
        $this->assertSame(
            'Cost — €5 “final”',
            PCM_Strategy_Service::strip_emoji('Cost — €5 “final”')
        );
    }

    public function test_preserves_cjk_latin_digits_punctuation(): void
    {
        $plain = '你好，世界 — 100% great: a "quoted" word.';
        $this->assertSame($plain, PCM_Strategy_Service::strip_emoji($plain));
    }

    public function test_preserves_clean_text_unchanged(): void
    {
        $this->assertSame('No emoji here at all', PCM_Strategy_Service::strip_emoji('No emoji here at all'));
    }

    public function test_collapses_double_space_left_behind(): void
    {
        // Removing the emoji between two words would otherwise leave a double space.
        $this->assertSame('a b', PCM_Strategy_Service::strip_emoji('a 🎉 b'));
    }

    public function test_preserves_html_tags_strips_only_emoji(): void
    {
        $in = '<h2>Title🎉</h2><p>Body ✅ text ❤️ here.</p>';
        $this->assertSame('<h2>Title</h2><p>Body text here.</p>', PCM_Strategy_Service::strip_emoji($in));
    }

    /**
     * F15: these "early emoji" in the low symbol blocks survived round 1 (only
     * their VS-16 was removed, leaving the base glyph). All 13 must vanish now.
     */
    public function test_strips_the_early_emoji_that_survived_round_one(): void
    {
        $thirteen = "⌚⌛⏰⏳▶️◀️ℹ️‼️↔️Ⓜ️㊙▪️〰️";
        $this->assertSame('Keep Drop', PCM_Strategy_Service::strip_emoji('Keep ' . $thirteen . ' Drop'));
        // Individually too (a class typo would drop one but pass the joined run).
        foreach (array('⌚', '⌛', '⏰', '⏳', '▶', '◀', 'ℹ', '‼', '↔', 'Ⓜ', '㊙', '▪', '〰') as $ch) {
            $this->assertSame('', PCM_Strategy_Service::strip_emoji($ch), $ch . ' must be stripped');
        }
    }

    /**
     * F16 (the tension): the broad 26xx/27xx ranges must NOT eat the text
     * symbols that are ordinary article typography. ✓/✗ in a comparison table,
     * card suits, music notes, and ✂✈✉✏ all survive — with OR without a
     * trailing emoji VS-16 (✓️ → ✓, the marker is not erased).
     */
    public function test_preserves_text_symbols_used_as_typography(): void
    {
        $this->assertSame('Supported ✓ / Not ✗', PCM_Strategy_Service::strip_emoji('Supported ✓ / Not ✗'));
        $this->assertSame('Suits ♠♥♦♣ Notes ♪♫ Tools ✂✈✉✏', PCM_Strategy_Service::strip_emoji('Suits ♠♥♦♣ Notes ♪♫ Tools ✂✈✉✏'));
        // VS-16 on a text symbol is stripped, the base glyph is KEPT.
        $this->assertSame('✓ included / ✗ not', PCM_Strategy_Service::strip_emoji('✓️ included / ✗️ not'));
        $this->assertSame('♠ and ♫', PCM_Strategy_Service::strip_emoji('♠️ and ♫️'));
    }

    /**
     * F18: the [ \t]{2,} collapse used to run on every article, destroying
     * <pre><code> indentation even when no emoji was present. Now a no-op when
     * nothing was removed.
     */
    public function test_preserves_pre_code_indentation_when_no_emoji_removed(): void
    {
        $code = "<pre><code>function f() {\n    return 1;\n}</code></pre>";
        $this->assertSame($code, PCM_Strategy_Service::strip_emoji($code));
        // But indentation next to an ACTUAL removal still collapses cleanly.
        $this->assertSame('a b', PCM_Strategy_Service::strip_emoji("a    🎉    b"));
    }

    // ── social_source_image ──────────────────────────────────────────────

    public function test_social_source_image_null_for_non_social(): void
    {
        $this->assertNull($this->socialSourceImage(array('sourceImage' => 'https://x.example/a.jpg')));
    }

    public function test_social_source_image_null_when_no_image(): void
    {
        $this->assertNull($this->socialSourceImage(array('social' => true)));
    }

    public function test_social_source_image_returns_sanitized_url(): void
    {
        $url = 'https://cdn.example.com/post.jpg';
        $this->assertSame($url, $this->socialSourceImage(array('social' => true, 'sourceImage' => $url)));
    }

    public function test_social_source_image_rejects_non_http(): void
    {
        $this->assertNull($this->socialSourceImage(array('social' => true, 'sourceImage' => 'data:image/png;base64,xx')));
        $this->assertNull($this->socialSourceImage(array('social' => true, 'sourceImage' => 'javascript:alert(1)')));
    }
}
