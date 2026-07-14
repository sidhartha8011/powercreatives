<?php
/**
 * Unit Tests — PCM_Article_Schema JSON-LD @graph builder.
 *
 * Pure builder tests: no DB, no WordPress functions, no WP_Mock fakes required.
 * Verifies graph composition, @id cross-referencing, conditional-node omission,
 * headline truncation, keyword joining, breadcrumb ordering, and render() output.
 *
 * @package PowerCreatives\Tests\Unit
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}

require_once dirname(__DIR__, 1) . '/../includes/modules/strategy/class-pcm-article-schema.php';

use PHPUnit\Framework\TestCase;

class ArticleSchemaTest extends TestCase
{
    /**
     * Index the @graph by @type => node (first occurrence).
     *
     * @return array<string,array>
     */
    private function byType(array $schema): array
    {
        $out = array();
        foreach ($schema['@graph'] as $node) {
            if (!isset($out[$node['@type']])) {
                $out[$node['@type']] = $node;
            }
        }
        return $out;
    }

    private function fullArgs(): array
    {
        return array(
            'title'         => 'How to Build a WordPress Plugin',
            'description'   => 'A practical guide.',
            'url'           => 'https://example.com/blog/wp-plugin/',
            'siteUrl'       => 'https://example.com',
            'siteName'      => 'Example Blog',
            'orgName'       => 'Example Inc',
            'logoUrl'       => 'https://example.com/logo.png',
            'authorName'    => 'Jane Doe',
            'authorUrl'     => 'https://example.com/author/jane',
            'authorSameAs'  => array('https://twitter.com/jane', 'https://linkedin.com/in/jane'),
            'datePublished' => '2026-01-01T00:00:00Z',
            'dateModified'  => '2026-02-01T00:00:00Z',
            'imageUrl'      => 'https://example.com/hero.jpg',
            'keywords'      => array('wordpress', 'plugin', 'php'),
            'language'      => 'en-US',
            'breadcrumbs'   => array(
                array('name' => 'Home', 'url' => 'https://example.com'),
                array('name' => 'Blog', 'url' => 'https://example.com/blog'),
                array('name' => 'WP Plugin', 'url' => 'https://example.com/blog/wp-plugin'),
            ),
        );
    }

    public function test_full_args_graph_contains_all_seven_node_types_with_id_cross_refs(): void
    {
        $schema = PCM_Article_Schema::build($this->fullArgs());

        $this->assertSame('https://schema.org', $schema['@context']);

        $nodes = $this->byType($schema);
        foreach (array('WebSite', 'Organization', 'Person', 'BreadcrumbList', 'WebPage', 'ImageObject', 'Article') as $type) {
            $this->assertArrayHasKey($type, $nodes, "Missing $type node");
        }
        $this->assertCount(7, $schema['@graph']);

        // @id conventions (site-scoped vs page-scoped).
        $this->assertSame('https://example.com#website', $nodes['WebSite']['@id']);
        $this->assertSame('https://example.com#organization', $nodes['Organization']['@id']);
        $this->assertSame('https://example.com/blog/wp-plugin#author', $nodes['Person']['@id']);
        $this->assertSame('https://example.com/blog/wp-plugin#webpage', $nodes['WebPage']['@id']);
        $this->assertSame('https://example.com/blog/wp-plugin#primaryimage', $nodes['ImageObject']['@id']);
        $this->assertSame('https://example.com/blog/wp-plugin#article', $nodes['Article']['@id']);

        // Cross-references.
        $this->assertSame('https://example.com#website', $nodes['WebPage']['isPartOf']['@id']);
        $this->assertSame('https://example.com#organization', $nodes['WebPage']['about']['@id']);
        $this->assertSame('https://example.com/blog/wp-plugin#primaryimage', $nodes['WebPage']['primaryImageOfPage']['@id']);
        $this->assertSame('https://example.com/blog/wp-plugin#webpage', $nodes['Article']['isPartOf']['@id']);
        $this->assertSame('https://example.com/blog/wp-plugin#webpage', $nodes['Article']['mainEntityOfPage']['@id']);
        $this->assertSame('https://example.com/blog/wp-plugin#author', $nodes['Article']['author']['@id']);
        $this->assertSame('https://example.com#organization', $nodes['Article']['publisher']['@id']);
        $this->assertSame('https://example.com/blog/wp-plugin#primaryimage', $nodes['Article']['image']['@id']);

        // Organization logo becomes an ImageObject.
        $this->assertSame('ImageObject', $nodes['Organization']['logo']['@type']);
        $this->assertSame('https://example.com/logo.png', $nodes['Organization']['logo']['url']);

        // Person sameAs preserved.
        $this->assertSame(array('https://twitter.com/jane', 'https://linkedin.com/in/jane'), $nodes['Person']['sameAs']);
    }

    public function test_minimal_args_omit_person_breadcrumb_image_and_fall_back_to_org_author(): void
    {
        $schema = PCM_Article_Schema::build(array(
            'title' => 'Bare Post',
            'url'   => 'https://example.com/bare/',
        ));

        $nodes = $this->byType($schema);

        // Present.
        $this->assertArrayHasKey('WebSite', $nodes);
        $this->assertArrayHasKey('Organization', $nodes);
        $this->assertArrayHasKey('WebPage', $nodes);
        $this->assertArrayHasKey('Article', $nodes);

        // Omitted.
        $this->assertArrayNotHasKey('Person', $nodes);
        $this->assertArrayNotHasKey('BreadcrumbList', $nodes);
        $this->assertArrayNotHasKey('ImageObject', $nodes);

        // siteUrl derived from the page URL (scheme://host).
        $this->assertSame('https://example.com#website', $nodes['WebSite']['@id']);

        // No author Person => Article author points to the Organization.
        $this->assertSame('https://example.com#organization', $nodes['Article']['author']['@id']);
        $this->assertSame('https://example.com#organization', $nodes['Article']['publisher']['@id']);

        // No image => no primaryImageOfPage on the WebPage, no image on the Article.
        $this->assertArrayNotHasKey('primaryImageOfPage', $nodes['WebPage']);
        $this->assertArrayNotHasKey('image', $nodes['Article']);

        // No keywords key when none supplied.
        $this->assertArrayNotHasKey('keywords', $nodes['Article']);

        // No dates when not supplied (deterministic — never "now").
        $this->assertArrayNotHasKey('datePublished', $nodes['Article']);
        $this->assertArrayNotHasKey('dateModified', $nodes['Article']);
    }

    public function test_headline_truncated_to_110_chars(): void
    {
        $longTitle = str_repeat('A', 200);
        $schema    = PCM_Article_Schema::build(array(
            'title' => $longTitle,
            'url'   => 'https://example.com/x',
        ));
        $nodes = $this->byType($schema);

        $this->assertSame(110, strlen($nodes['Article']['headline']));
        $this->assertSame(substr($longTitle, 0, 110), $nodes['Article']['headline']);

        // Short titles are left intact.
        $short = PCM_Article_Schema::build(array('title' => 'Short', 'url' => 'https://example.com/x'));
        $this->assertSame('Short', $this->byType($short)['Article']['headline']);
    }

    public function test_keywords_joined_by_comma(): void
    {
        $schema = PCM_Article_Schema::build(array(
            'title'    => 'K',
            'url'      => 'https://example.com/k',
            'keywords' => array('seo', 'wordpress', 'php'),
        ));
        $nodes = $this->byType($schema);

        $this->assertSame('seo, wordpress, php', $nodes['Article']['keywords']);
    }

    public function test_render_wraps_in_script_tag_and_is_valid_json(): void
    {
        $html = PCM_Article_Schema::render($this->fullArgs());

        $this->assertStringStartsWith('<script type="application/ld+json">', $html);
        $this->assertStringEndsWith('</script>', $html);

        $json = substr($html, strlen('<script type="application/ld+json">'), -strlen('</script>'));
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('https://schema.org', $decoded['@context']);
        $this->assertArrayHasKey('@graph', $decoded);
    }

    public function test_breadcrumb_positions_are_sequential(): void
    {
        $schema = PCM_Article_Schema::build($this->fullArgs());
        $nodes  = $this->byType($schema);

        $items = $nodes['BreadcrumbList']['itemListElement'];
        $this->assertCount(3, $items);

        $expectedNames = array('Home', 'Blog', 'WP Plugin');
        foreach ($items as $i => $item) {
            $this->assertSame('ListItem', $item['@type']);
            $this->assertSame($i + 1, $item['position']);
            $this->assertSame($expectedNames[$i], $item['item']['name']);
        }
    }
}
