<?php
/**
 * Unit Tests — cross-plugin SEO meta integration.
 *
 * Guards the faithful-critical pieces of the Optimizer Simple port: the exact
 * meta-key map per SEO plugin, detection precedence, the read fallback chain,
 * the dual-write routing, and the cell-save whitelist. The real Yoast/RankMath
 * dual-write is additionally verified live against WordPress.
 *
 * @package PowerCreatives\Tests\Unit
 */

use WP_Mock\Tools\TestCase;

class SeoIntegrationTest extends TestCase
{
    public function test_key_map_is_faithful(): void
    {
        $map = PCM_SEO_Service::seo_key_map();

        $this->assertSame('_yoast_wpseo_title', $map['yoast']['title']);
        $this->assertSame('_yoast_wpseo_metadesc', $map['yoast']['description']);
        $this->assertSame('_yoast_wpseo_focuskw', $map['yoast']['keyword']);

        $this->assertSame('rank_math_title', $map['rankmath']['title']);
        $this->assertSame('rank_math_description', $map['rankmath']['description']);
        $this->assertSame('rank_math_focus_keyword', $map['rankmath']['keyword']);

        $this->assertSame('_seopress_titles_title', $map['seopress']['title']);
        $this->assertSame('_seopress_titles_desc', $map['seopress']['description']);
        $this->assertSame('_seopress_analysis_target_kw', $map['seopress']['keyword']);

        $this->assertSame('pcm_seo_meta_title', $map['simple']['title']);
        $this->assertSame('pcm_seo_meta_description', $map['simple']['description']);
        $this->assertSame('pcm_seo_primary_keyword', $map['simple']['keyword']);

        // meta_keywords always falls back to the internal backup key.
        foreach (array('yoast', 'rankmath', 'seopress', 'simple') as $p) {
            $this->assertSame('pcm_seo_meta_keywords', $map[$p]['meta_keywords']);
        }
    }

    public function test_detect_defaults_to_simple_without_seo_plugin(): void
    {
        // No Yoast/RankMath/SEOPress symbols defined in the unit env.
        $this->assertSame('simple', PCM_SEO_Service::detect_seo_plugin());
    }

    public function test_seo_get_reads_active_key(): void
    {
        WP_Mock::userFunction('get_post_meta')
            ->once()
            ->with(5, 'pcm_seo_meta_title', true)
            ->andReturn('Hello World');

        $this->assertSame('Hello World', PCM_SEO_Service::seo_get(5, 'title'));
    }

    public function test_seo_get_empty_returns_blank(): void
    {
        WP_Mock::userFunction('get_post_meta')->andReturn('');
        $this->assertSame('', PCM_SEO_Service::seo_get(5, 'description'));
    }

    public function test_seo_update_simple_writes_internal_key_once(): void
    {
        // plugin === simple → single write to the internal key (no mirror).
        WP_Mock::userFunction('update_post_meta')
            ->once()
            ->with(9, 'pcm_seo_primary_keyword', 'roofing');

        PCM_SEO_Service::seo_update(9, 'keyword', 'roofing');
        $this->assertConditionsMet();
    }

    public function test_save_cell_routes_seo_field_through_dual_write(): void
    {
        WP_Mock::passthruFunction('sanitize_text_field');
        WP_Mock::userFunction('update_post_meta')
            ->once()
            ->with(7, 'pcm_seo_meta_description', 'A great page');

        $svc = new PCM_SEO_Service();
        $res = $svc->save_cell(7, 'metaDescription', 'A great page');

        $this->assertSame('metaDescription', $res['field']);
        $this->assertSame('A great page', $res['value']);
    }

    public function test_save_cell_routes_internal_meta_field(): void
    {
        WP_Mock::passthruFunction('sanitize_text_field');
        WP_Mock::userFunction('update_post_meta')
            ->once()
            ->with(7, 'pcm_seo_cluster_label', 'Services');

        $svc = new PCM_SEO_Service();
        $res = $svc->save_cell(7, 'clusterLabel', 'Services');

        $this->assertSame('Services', $res['value']);
    }

    public function test_save_cell_whitelist_covers_seo_and_native_fields(): void
    {
        $fields = PCM_SEO_Service::save_cell_fields();

        $this->assertSame('seo:title', $fields['metaTitle']);
        $this->assertSame('seo:description', $fields['metaDescription']);
        $this->assertSame('seo:keyword', $fields['primaryKeyword']);
        $this->assertSame('seo:meta_keywords', $fields['metaKeywords']);
        $this->assertSame('post_title', $fields['title']);
        $this->assertSame('post_status', $fields['status']);
        $this->assertArrayNotHasKey('arbitrary', $fields);
    }

    // ── AI field generation (Phase 3) ──

    public function test_substitute_vars_replaces_all_placeholders(): void
    {
        $tpl  = 'Title: {{title}} / KW: {{primary_keyword}} / Biz: {{business.name}} / Host: {{business.website|hostname}}';
        $out  = PCM_SEO_Service::substitute_vars($tpl, array(
            'title'                     => 'Roof Repair',
            'primary_keyword'           => 'roofing',
            'business.name'             => 'ACME Roofing',
            'business.website|hostname' => 'acme.test',
        ));
        $this->assertSame('Title: Roof Repair / KW: roofing / Biz: ACME Roofing / Host: acme.test', $out);
    }

    public function test_substitute_vars_unknown_placeholder_is_left_intact(): void
    {
        // Only declared keys are replaced; others pass through unchanged.
        $this->assertSame('{{unknown}}', PCM_SEO_Service::substitute_vars('{{unknown}}', array('title' => 'x')));
    }

    public function test_sanitize_ai_output_strips_one_quote_pair(): void
    {
        $this->assertSame('Best Roofers in Town', PCM_SEO_Service::sanitize_ai_output('  "Best Roofers in Town"  '));
        $this->assertSame('Plain text', PCM_SEO_Service::sanitize_ai_output("'Plain text'"));
        // Mismatched / inner quotes untouched.
        $this->assertSame('He said "hi"', PCM_SEO_Service::sanitize_ai_output('He said "hi"'));
    }

    public function test_field_use_map_only_lists_generatable_fields(): void
    {
        $map = PCM_SEO_Service::field_use_map();
        $this->assertSame('page_title', $map['title']);
        $this->assertSame('meta_title', $map['metaTitle']);
        $this->assertSame('meta_description', $map['metaDescription']);
        $this->assertSame('meta_keywords', $map['metaKeywords']);
        // primaryKeyword is an input, not AI-generated.
        $this->assertArrayNotHasKey('primaryKeyword', $map);
    }

    public function test_field_prompts_provide_generate_for_every_use(): void
    {
        WP_Mock::userFunction('apply_filters')->andReturnUsing(fn($hook, $value) => $value);
        $prompts = PCM_SEO_Service::field_prompts();
        foreach (PCM_SEO_Service::field_use_map() as $use) {
            $this->assertArrayHasKey($use, $prompts, "missing prompt for {$use}");
            $this->assertNotEmpty($prompts[$use]['generate']);
            $this->assertIsInt($prompts[$use]['max']);
        }
    }

    // ── Prompt-Editor integration: SEO prompts editable in Settings → Prompts ──

    public function test_get_default_prompts_covers_every_editor_section(): void
    {
        WP_Mock::userFunction('apply_filters')->andReturnUsing(fn($hook, $value) => $value);
        $defaults = PCM_SEO_Service::get_default_prompts();

        // These keys MUST mirror PCM_REST_Prompts::get_default_sections('seo').
        // If they drift, the Prompt Editor renders blank/orphaned tabs.
        $expected = array(
            'page_title_generate', 'page_title_optimize',
            'meta_title_generate', 'meta_title_optimize',
            'meta_description_generate', 'meta_description_optimize',
            'meta_keywords_generate',
            'content_optimize',
        );
        $actual = array_keys($defaults);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);

        foreach ($defaults as $section => $content) {
            $this->assertNotEmpty($content, "empty built-in default for {$section}");
        }
    }

    public function test_resolve_prompt_returns_default_without_user(): void
    {
        // No PCM user id → no DB lookup → shipped default returned verbatim.
        $this->assertSame('DEFAULT', PCM_SEO_Service::resolve_prompt('meta_title_generate', 'DEFAULT', null));
        $this->assertSame('DEFAULT', PCM_SEO_Service::resolve_prompt('meta_title_generate', 'DEFAULT', 0));
    }

    public function test_resolve_prompt_prefers_active_db_override(): void
    {
        global $wpdb;
        $wpdb = \Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->once()->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->once()->with('SQL')->andReturn('MY CUSTOM PROMPT');

        $this->assertSame(
            'MY CUSTOM PROMPT',
            PCM_SEO_Service::resolve_prompt('content_optimize', 'DEFAULT', 42)
        );
    }

    public function test_resolve_prompt_ignores_blank_override(): void
    {
        // An empty/absent override row must fall back to the default — never
        // send a blank prompt to the model.
        global $wpdb;
        $wpdb = \Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_var')->andReturn(null);

        $this->assertSame(
            'DEFAULT',
            PCM_SEO_Service::resolve_prompt('content_optimize', 'DEFAULT', 42)
        );
    }

    // ── AI Readiness: HTML → Markdown (faithful port) ──

    public function test_html_to_markdown_headings_bold_links(): void
    {
        WP_Mock::passthruFunction('strip_shortcodes');
        $md = PCM_SEO_AIReadiness::html_to_markdown(
            '<h2>Hello</h2><p>World <strong>bold</strong> and <a href="https://x.test">link</a></p>'
        );
        $this->assertSame("## Hello\n\nWorld **bold** and [link](https://x.test)", $md);
    }

    public function test_html_to_markdown_unordered_list(): void
    {
        WP_Mock::passthruFunction('strip_shortcodes');
        $md = PCM_SEO_AIReadiness::html_to_markdown('<ul><li>One</li><li>Two</li></ul>');
        $this->assertStringContainsString('- One', $md);
        $this->assertStringContainsString('- Two', $md);
    }

    public function test_html_to_markdown_strips_gutenberg_comments(): void
    {
        WP_Mock::passthruFunction('strip_shortcodes');
        $md = PCM_SEO_AIReadiness::html_to_markdown('<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->');
        $this->assertSame('Body', $md);
    }

    // ── Schema (Phase 4) ──

    public function test_schema_set_types_filters_to_whitelist(): void
    {
        WP_Mock::userFunction('wp_json_encode')->andReturnUsing(static fn($v) => json_encode($v));
        WP_Mock::userFunction('update_post_meta');
        $clean = PCM_SEO_Schema::set_types(5, array('Article', 'Bogus', 'Product', 'FAQPage'));
        $this->assertSame(array('Article', 'Product', 'FAQPage'), $clean);
    }

    public function test_schema_types_for_reads_and_validates(): void
    {
        WP_Mock::userFunction('get_post_meta')->andReturn('["Article","NotAType","HowTo"]');
        $this->assertSame(array('Article', 'HowTo'), PCM_SEO_Schema::types_for(9));
    }

    // ── Site (Phase 6) ──

    public function test_site_default_robots_has_admin_and_sitemap(): void
    {
        WP_Mock::userFunction('home_url')->andReturnUsing(static fn($p = '') => 'https://acme.test' . $p);
        $robots = PCM_SEO_Site::default_robots();
        $this->assertStringContainsString('Disallow: /wp-admin/', $robots);
        $this->assertStringContainsString('Allow: /wp-admin/admin-ajax.php', $robots);
        $this->assertStringContainsString('Sitemap: https://acme.test/sitemap.xml', $robots);
    }

    public function test_site_robots_filter_passthrough_when_disabled(): void
    {
        WP_Mock::userFunction('get_option')->with('pcm_seo_robots_enabled', false)->andReturn(false);
        $this->assertSame('original', PCM_SEO_Site::filter_robots('original', true));
    }

    // ── GBP normalize (Phase 7) — exact live Places New v1 shape ──

    public function test_gbp_normalize_places_v1_shape(): void
    {
        $raw = array(
            'id' => 'ChIJ_bWaT8tuWkYREXkfNqQotZ4',
            'types' => array('roofing_contractor', 'general_contractor'),
            'nationalPhoneNumber' => '036-37 80 16',
            'formattedAddress' => 'Tallvägen 9, 564 35 Bankeryd, Sweden',
            'location' => array('latitude' => 57.8692739, 'longitude' => 14.1112864),
            'rating' => 4.7,
            'websiteUri' => 'https://www.vikantak.se/',
            'userRatingCount' => 23,
            'displayName' => array('text' => 'Smålands Tak & Plåt AB', 'languageCode' => 'en'),
            'primaryTypeDisplayName' => array('text' => 'Roofing contractor'),
            'regularOpeningHours' => array('weekdayDescriptions' => array('Monday: 7-16', 'Tuesday: 7-16')),
        );
        $n = PCM_SEO_GBP::normalize($raw);

        $this->assertSame('ChIJ_bWaT8tuWkYREXkfNqQotZ4', $n['place_id']);
        $this->assertSame('Smålands Tak & Plåt AB', $n['name']);
        $this->assertSame('036-37 80 16', $n['phone']);
        $this->assertSame('Tallvägen 9, 564 35 Bankeryd, Sweden', $n['address']);
        $this->assertSame(57.8692739, $n['lat']);
        $this->assertSame(14.1112864, $n['lng']);
        $this->assertSame('https://www.vikantak.se/', $n['website']);
        $this->assertSame('Roofing contractor', $n['category']);
        $this->assertSame(4.7, $n['rating']);
        $this->assertSame(23, $n['reviews']);
        $this->assertStringContainsString('Monday: 7-16', $n['hours']);
        $this->assertSame(array('roofing_contractor', 'general_contractor'), $n['types']);
    }

    public function test_gbp_normalize_handles_legacy_keys(): void
    {
        $n = PCM_SEO_GBP::normalize(array('place_id' => 'X', 'name' => 'Legacy', 'formatted_address' => 'A', 'website' => 'w', 'geometry' => array('location' => array('lat' => 1.0, 'lng' => 2.0))));
        $this->assertSame('X', $n['place_id']);
        $this->assertSame('Legacy', $n['name']);
        $this->assertSame(1.0, $n['lat']);
        $this->assertSame(2.0, $n['lng']);
    }

    public function test_gbp_provider_factory_defaults_to_n8n(): void
    {
        WP_Mock::userFunction('apply_filters')->andReturnUsing(static fn($hook, $value) => $value);
        WP_Mock::userFunction('get_option')->andReturn('n8n');
        // PCM_Settings::get reads the option; provider() returns the n8n impl.
        $this->assertInstanceOf(PCM_SEO_GBP_N8N_Provider::class, PCM_SEO_GBP::provider());
    }
}
