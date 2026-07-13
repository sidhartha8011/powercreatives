<?php
/**
 * PCM_Text_Matcher unit tests — the PARSING/IDENTITY primitives the hub keeps
 * (cleanup C3: the serving/apply engine lives in the CONNECTOR only; its full
 * fixture corpus — section apply, inserts, paragraph rules, the v2.1 heading
 * pass and ordering — runs against the REAL extracted connector source in
 * `tests/standalone/run.php`, executable with bare PHP, no vendor).
 *
 * Fixtures are REAL builder-shaped HTML (Gutenberg, Elementor-style spans,
 * Brizy-style class soup) so normalization spec v1 + boundary parsing are
 * proven against what client sites actually serve. Pure class — no WP_Mock.
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

    /** Script/style content never counts as visible text. */
    public function test_visible_text_drops_script_and_style(): void
    {
        $this->assertSame(
            'Visible only.',
            trim(PCM_Text_Matcher::visible_text('<p><script>var a=1;</script>Visible <style>.x{}</style>only.</p>'))
        );
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
    // Section contracts v2 — identity/parsing primitives (the hub's side of
    // the harness-pinned pair; serving fixtures live in tests/standalone/).
    // =====================================================================

    /** A two-section Elementor-flavored page used across the parse tests. */
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

    /** Chrome exclusion (2.8.1 parity fix): scan and serving compare the same block set. */
    public function test_content_blocks_exclude_chrome(): void
    {
        $page = '<html><head><title>x</title></head><body>'
            . '<header><p>Vi använder cookies.</p><h1>Sajtnamn</h1></header>'
            . '<nav><p>Meny</p></nav>'
            . '<h2>Våra tjänster</h2><p>Första stycket.</p>'
            . '<aside><p>Sidokolumn.</p></aside>'
            . '<p>Andra stycket.</p>'
            . '<footer><p>© 2026</p></footer></body></html>';
        $this->assertSame(
            array('Våra tjänster', 'Första stycket.', 'Andra stycket.'),
            array_column(PCM_Text_Matcher::content_blocks($page), 'text')
        );
    }
}
