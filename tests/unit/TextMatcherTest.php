<?php
/**
 * PCM_Text_Matcher unit tests — the dynamic-rule matcher's fixture corpus.
 *
 * Fixtures are REAL builder-shaped HTML (Gutenberg, classic/wpautop,
 * Elementor-style span-wrapped, Brizy-style class soup) so the normalization
 * spec v1 + boundary matching are proven against what client sites actually
 * serve. Pure class — no WP_Mock expectations needed.
 *
 * Runs where composer/vendor exists (CI / the other dev box) — this machine
 * intentionally has no vendor (documented; do not bootstrap it here).
 *
 * @package PowerCreatives\Tests
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/modules/seo/class-pcm-text-matcher.php';

final class TextMatcherTest extends TestCase
{
    // ── Normalization spec v1 ──

    public function test_normalize_collapses_whitespace_nbsp_entities_case(): void
    {
        $this->assertSame(
            'bäst i test — alltid',
            PCM_Text_Matcher::normalize("  B\xC3\xA4st   i\xC2\xA0test &mdash;\n\tALLTID  ")
        );
    }

    public function test_normalize_decodes_entities_like_builders_emit(): void
    {
        $this->assertSame(
            "we're #1 & proud",
            PCM_Text_Matcher::normalize('We&rsquo;re&nbsp;#1 &amp; proud')
        );
    }

    // ── Boundary matching on builder-shaped fixtures ──

    /** Gutenberg paragraph (clean <p>). */
    public function test_replace_block_gutenberg(): void
    {
        $html = '<p>Old intro text.</p><p>Keep me.</p>';
        $out  = PCM_Text_Matcher::replace_block(
            $html,
            'p',
            PCM_Text_Matcher::normalize('Old intro text.'),
            0,
            'New optimized intro.'
        );
        $this->assertSame('<p>New optimized intro.</p><p>Keep me.</p>', $out);
    }

    /** Elementor-style: text wrapped in the builder's own spans + attributes. */
    public function test_replace_block_elementor_span_wrapped(): void
    {
        $html = '<div class="elementor-widget-container">'
            . '<p class="elementor-text" data-id="a1b2">Boka <span class="x">din</span>&nbsp;tid idag</p>'
            . '</div>';
        $out = PCM_Text_Matcher::replace_block(
            $html,
            'p',
            PCM_Text_Matcher::normalize('Boka din tid idag'),
            0,
            'Boka din behandling redan idag'
        );
        $this->assertStringContainsString(
            '<p class="elementor-text" data-id="a1b2">Boka din behandling redan idag</p>',
            (string) $out
        );
    }

    /** Occurrence scoping: identical twins — only the addressed one changes. */
    public function test_replace_block_occurrence_targets_second_twin(): void
    {
        $html  = '<p>Läs mer</p><p>Läs mer</p><p>Läs mer</p>';
        $match = PCM_Text_Matcher::normalize('Läs mer');
        $out   = PCM_Text_Matcher::replace_block($html, 'p', $match, 1, 'Upptäck mer');
        $this->assertSame('<p>Läs mer</p><p>Upptäck mer</p><p>Läs mer</p>', $out);
    }

    /** No match → NULL (caller serves the ORIGINAL — the no-silent-fallback law). */
    public function test_replace_block_miss_returns_null(): void
    {
        $html = '<p>Client edited this text.</p>';
        $this->assertNull(
            PCM_Text_Matcher::replace_block($html, 'p', PCM_Text_Matcher::normalize('The old text'), 0, 'X')
        );
    }

    /** Script/style content never counts as visible text. */
    public function test_visible_text_drops_script_and_style(): void
    {
        $this->assertSame(
            'Visible only.',
            trim(PCM_Text_Matcher::visible_text('<p><script>var a=1;</script>Visible <style>.x{}</style>only.</p>'))
        );
    }

    /** Heading tags work through the same engine (target-agnostic by design). */
    public function test_replace_block_heading(): void
    {
        $html = '<h2 class="brz-heading">Våra tjänster</h2>';
        $out  = PCM_Text_Matcher::replace_block(
            $html,
            'h2',
            PCM_Text_Matcher::normalize('Våra tjänster'),
            0,
            'Tjänster som rankar'
        );
        $this->assertSame('<h2 class="brz-heading">Tjänster som rankar</h2>', $out);
    }

    /** Disallowed tag / empty match text → NULL, never a guess. */
    public function test_replace_block_rejects_bad_input(): void
    {
        $this->assertNull(PCM_Text_Matcher::replace_block('<div>x</div>', 'div', 'x', 0, 'y'));
        $this->assertNull(PCM_Text_Matcher::replace_block('<p>x</p>', 'p', '', 0, 'y'));
    }

    // ── occurrence_of (rule-creation side) ──

    public function test_occurrence_of_counts_normalized_twins(): void
    {
        $texts = array('Läs mer', 'Om oss', 'LÄS  MER', 'Läs&nbsp;mer');
        $this->assertSame(0, PCM_Text_Matcher::occurrence_of($texts, 0));
        $this->assertSame(1, PCM_Text_Matcher::occurrence_of($texts, 2)); // normalized twin of #0
        $this->assertSame(2, PCM_Text_Matcher::occurrence_of($texts, 3)); // entity twin
        $this->assertSame(0, PCM_Text_Matcher::occurrence_of($texts, 1));
    }
}
