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

    // =====================================================================
    // Section contracts v2 — fingerprint / parse_blocks / section apply.
    // Fixtures mirror the v1 corpus: clean Gutenberg + builder-attribute HTML.
    // =====================================================================

    /** A two-section Elementor-flavored page used across the section tests. */
    private function sectionFixture(): string
    {
        return '<div class="elementor-section"><h2 class="elementor-heading" data-id="h1">Våra tjänster</h2></div>'
            . '<div class="col"><p class="elementor-text" data-id="p1">Första stycket.</p></div>'
            . '<div class="col"><p class="elementor-text" data-id="p2">Andra&nbsp;stycket.</p></div>'
            . '<img src="between.jpg" alt="">'
            . '<div class="col"><p class="elementor-text" data-id="p3">Tredje stycket.</p></div>'
            . '<h2>Kontakt</h2><p>Ring oss.</p>';
    }

    public function test_fingerprint_normalizes_and_joins(): void
    {
        $this->assertSame(
            "första stycket.\nandra stycket.",
            PCM_Text_Matcher::fingerprint(array('Första  stycket.', 'Andra&nbsp;STYCKET.'))
        );
        $this->assertSame('', PCM_Text_Matcher::fingerprint(array())); // empty-bodied section is legal
    }

    /** Replacement units keep NON-h/p content (lists etc.) — never dropped. */
    public function test_parse_replacement_units_keeps_lists(): void
    {
        $units = PCM_Text_Matcher::parse_replacement_units(
            '<h2>FAQ</h2><p>Intro.</p><ul><li>Ett</li></ul><p>Utro.</p>'
        );
        $this->assertSame(array('h2', 'p', '', 'p'), array_column($units, 'tag'));
        $this->assertSame('<ul><li>Ett</li></ul>', $units[2]['html']);
    }

    public function test_parse_blocks_orders_and_offsets(): void
    {
        $blocks = PCM_Text_Matcher::parse_blocks($this->sectionFixture());
        $this->assertCount(6, $blocks);
        $this->assertSame(array('h2', 'p', 'p', 'p', 'h2', 'p'), array_column($blocks, 'tag'));
        $this->assertSame('Våra tjänster', $blocks[0]['text']);
        // Offsets are exact: cutting a block's span out of the source yields its html.
        $b = $blocks[2];
        $this->assertSame($b['html'], substr($this->sectionFixture(), $b['start'], $b['len']));
    }

    /** Equal block count: same-tag mapping keeps each ORIGINAL block's attributes. */
    public function test_apply_section_rule_equal_count_preserves_builder_attrs(): void
    {
        $fp  = PCM_Text_Matcher::fingerprint(array('Första stycket.', 'Andra stycket.', 'Tredje stycket.'));
        $out = PCM_Text_Matcher::apply_section_rule(
            $this->sectionFixture(),
            PCM_Text_Matcher::normalize('Våra tjänster'),
            2,
            $fp,
            0,
            '<h2>Tjänster som rankar</h2><p>Nytt ett.</p><p>Nytt två.</p><p>Nytt tre.</p>'
        );
        $this->assertStringContainsString('<h2 class="elementor-heading" data-id="h1">Tjänster som rankar</h2>', (string) $out);
        $this->assertStringContainsString('<p class="elementor-text" data-id="p2">Nytt två.</p>', (string) $out);
        $this->assertStringContainsString('<img src="between.jpg" alt="">', (string) $out); // between-content untouched
        $this->assertStringContainsString('<h2>Kontakt</h2><p>Ring oss.</p>', (string) $out); // next section untouched
    }

    /** Merge 4→2 blocks: surplus originals removed whole, wrappers kept. */
    public function test_apply_section_rule_merge_removes_surplus_blocks(): void
    {
        $fp  = PCM_Text_Matcher::fingerprint(array('Första stycket.', 'Andra stycket.', 'Tredje stycket.'));
        $out = PCM_Text_Matcher::apply_section_rule(
            $this->sectionFixture(),
            PCM_Text_Matcher::normalize('Våra tjänster'),
            2,
            $fp,
            0,
            '<h2>Tjänster</h2><p>Ett sammanslaget stycke.</p>'
        );
        $this->assertStringContainsString('<p class="elementor-text" data-id="p1">Ett sammanslaget stycke.</p>', (string) $out);
        $this->assertStringNotContainsString('Andra', (string) $out);
        $this->assertStringNotContainsString('Tredje stycket', (string) $out);
        $this->assertStringContainsString('<div class="col"></div>', (string) $out); // emptied wrapper survives
    }

    /** Expand + restructure: extra blocks (incl. a list and an H3) ride as siblings; tag change swaps whole block. */
    public function test_apply_section_rule_expand_with_new_block_kinds(): void
    {
        $html = '<h2>FAQ</h2><p>Gammal fråga.</p>';
        $out  = PCM_Text_Matcher::apply_section_rule(
            $html,
            PCM_Text_Matcher::normalize('FAQ'),
            2,
            PCM_Text_Matcher::fingerprint(array('Gammal fråga.')),
            0,
            '<h2>FAQ</h2><h3>Vad kostar det?</h3><p>Det beror på.</p><ul><li>Ett</li><li>Två</li></ul>'
        );
        $this->assertSame('<h2>FAQ</h2><h3>Vad kostar det?</h3><p>Det beror på.</p><ul><li>Ett</li><li>Två</li></ul>', $out);
    }

    /** Client edited a paragraph → fingerprint mismatch → NULL (original serves, never a guess). */
    public function test_apply_section_rule_stale_fingerprint_returns_null(): void
    {
        $this->assertNull(PCM_Text_Matcher::apply_section_rule(
            $this->sectionFixture(),
            PCM_Text_Matcher::normalize('Våra tjänster'),
            2,
            PCM_Text_Matcher::fingerprint(array('Första stycket.', 'KLIENTEN ÄNDRADE.', 'Tredje stycket.')),
            0,
            '<h2>X</h2><p>Y.</p>'
        ));
    }

    /** Chrome-duplicate heading text: the fingerprint disqualifies the wrong candidate. */
    public function test_apply_section_rule_fingerprint_beats_duplicate_heading(): void
    {
        $html = '<header><h2>Om oss</h2></header>'
            . '<h2>Om oss</h2><p>Rätt sektion.</p>';
        $out  = PCM_Text_Matcher::apply_section_rule(
            $html,
            PCM_Text_Matcher::normalize('Om oss'),
            2,
            PCM_Text_Matcher::fingerprint(array('Rätt sektion.')),
            0, // occurrence hint even points at the chrome twin — the guard corrects it
            '<h2>Om oss</h2><p>Ny text.</p>'
        );
        $this->assertStringContainsString('<header><h2>Om oss</h2></header>', (string) $out);
        $this->assertStringContainsString('<p>Ny text.</p>', (string) $out);
    }

    /** Empty-bodied section (heading directly followed by another heading) is legal. */
    public function test_apply_section_rule_empty_body_section(): void
    {
        $html = '<h2>Rubrik</h2><h2>Nästa</h2><p>x</p>';
        $out  = PCM_Text_Matcher::apply_section_rule(
            $html,
            PCM_Text_Matcher::normalize('Rubrik'),
            2,
            '',
            0,
            '<h2>Rubrik</h2><p>Nytt stycke under.</p>'
        );
        $this->assertSame('<h2>Rubrik</h2><p>Nytt stycke under.</p><h2>Nästa</h2><p>x</p>', $out);
    }

    // ── sectionInsert v2 ──

    public function test_apply_section_insert_after_section_end(): void
    {
        $out = PCM_Text_Matcher::apply_section_insert(
            $this->sectionFixture(),
            PCM_Text_Matcher::normalize('Våra tjänster'),
            2,
            'after',
            0,
            '<h2>FAQ</h2><p>Fråga och svar.</p>'
        );
        // Lands after the section's LAST block (p3), before the Kontakt heading.
        $this->assertMatchesRegularExpression(
            '#Tredje stycket\.</p></div><h2>FAQ</h2><p>Fråga och svar\.</p><h2>Kontakt</h2>#',
            (string) $out
        );
    }

    public function test_apply_section_insert_before_anchor_heading(): void
    {
        $out = PCM_Text_Matcher::apply_section_insert(
            '<h2>Kontakt</h2><p>Ring oss.</p>',
            PCM_Text_Matcher::normalize('Kontakt'),
            2,
            'before',
            0,
            '<h2>FAQ</h2><p>Svar.</p>'
        );
        $this->assertSame('<h2>FAQ</h2><p>Svar.</p><h2>Kontakt</h2><p>Ring oss.</p>', $out);
    }

    /** Anchor gone (client removed the heading) → NULL, nothing inserted. */
    public function test_apply_section_insert_missing_anchor_returns_null(): void
    {
        $this->assertNull(PCM_Text_Matcher::apply_section_insert(
            '<h2>Annat</h2><p>x</p>',
            PCM_Text_Matcher::normalize('Kontakt'),
            2,
            'after',
            0,
            '<h2>FAQ</h2><p>Svar.</p>'
        ));
    }
}
