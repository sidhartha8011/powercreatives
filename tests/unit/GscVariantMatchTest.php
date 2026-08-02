<?php
/**
 * Unit Tests — GSC www/non-www variant matching.
 *
 * Owner report 2026-08-03: the "Verify in GSC" dialog auto-detected the indexed
 * domain as https://knallenstandvard.se, the user removed the www and clicked
 * Verify — and the system connected to https://www.knallenstandvard.se/ anyway,
 * which had no data ("it's insisting on using the www").
 *
 * Cause: match_properties() is deliberately variant-INSENSITIVE (for auto-matching
 * the www property is a fine stand-in), so the www property still "matched" a
 * non-www request and gsc_provision reused it — silently discarding the pick.
 * covers_exact_variant() is the strict test gsc_provision now applies when the
 * variant came from a human.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class GscVariantMatchTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // covers_exact_variant/match_properties are pure apart from wp_parse_url.
        WP_Mock::userFunction('wp_parse_url')->andReturnUsing(
            static fn($url, $component = -1) => parse_url($url, $component)
        );
    }

    // ── the exact-variant test (what makes an explicit pick stick) ──

    public function test_non_www_pick_does_not_match_the_www_property(): void
    {
        // THE BUG: this returned true via match_properties, so the www property
        // was reused for a non-www pick.
        $this->assertFalse(PCM_GSC::covers_exact_variant(
            'https://www.knallenstandvard.se/',
            'https://knallenstandvard.se'
        ));
    }

    public function test_www_pick_does_not_match_the_non_www_property(): void
    {
        $this->assertFalse(PCM_GSC::covers_exact_variant(
            'https://knallenstandvard.se/',
            'https://www.knallenstandvard.se'
        ));
    }

    public function test_exact_variant_matches(): void
    {
        $this->assertTrue(PCM_GSC::covers_exact_variant(
            'https://knallenstandvard.se/',
            'https://knallenstandvard.se'
        ));
        $this->assertTrue(PCM_GSC::covers_exact_variant(
            'https://www.knallenstandvard.se/',
            'https://www.knallenstandvard.se'
        ));
    }

    public function test_sc_domain_property_covers_both_variants(): void
    {
        // A DOMAIN property genuinely serves www AND non-www, so reusing it honours
        // either pick rather than overriding it — it must stay reusable.
        $this->assertTrue(PCM_GSC::covers_exact_variant(
            'sc-domain:knallenstandvard.se',
            'https://knallenstandvard.se'
        ));
        $this->assertTrue(PCM_GSC::covers_exact_variant(
            'sc-domain:knallenstandvard.se',
            'https://www.knallenstandvard.se'
        ));
    }

    public function test_unrelated_domains_never_match(): void
    {
        $this->assertFalse(PCM_GSC::covers_exact_variant(
            'https://otherdomain.se/',
            'https://knallenstandvard.se'
        ));
        $this->assertFalse(PCM_GSC::covers_exact_variant(
            'sc-domain:otherdomain.se',
            'https://knallenstandvard.se'
        ));
        // A parent-domain property does NOT serve a subdomain variant exactly —
        // match_properties ranks it last as a fallback; it must not count as exact.
        $this->assertFalse(PCM_GSC::covers_exact_variant(
            'sc-domain:knallenstandvard.se',
            'https://blog.knallenstandvard.se'
        ));
    }

    public function test_empty_host_is_not_a_match(): void
    {
        $this->assertFalse(PCM_GSC::covers_exact_variant('https://example.com/', ''));
    }

    // ── the pre-existing auto-match behaviour must be UNCHANGED ──

    public function test_auto_matching_stays_variant_insensitive(): void
    {
        // Nothing above may narrow match_properties: with no explicit pick, the www
        // property is still a valid auto-match for a non-www site (that is what stops
        // the app creating an empty duplicate property).
        $matches = PCM_GSC::match_properties(
            array('https://www.knallenstandvard.se/'),
            'https://knallenstandvard.se'
        );
        $this->assertSame(array('https://www.knallenstandvard.se/'), $matches);
    }

    public function test_match_properties_ranks_exact_above_the_other_variant(): void
    {
        $matches = PCM_GSC::match_properties(
            array('https://www.knallenstandvard.se/', 'https://knallenstandvard.se/'),
            'https://knallenstandvard.se'
        );
        $this->assertSame('https://knallenstandvard.se/', $matches[0]);
    }
}
