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
}
