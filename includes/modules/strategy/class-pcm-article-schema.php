<?php
/**
 * Article JSON-LD @graph builder (strategy.article_schema)
 *
 * Pure, deterministic PHP port of AutoPress's schemaGeneratorService — builds a
 * schema.org `@graph` (WebSite + Organization + Person + BreadcrumbList + WebPage
 * + ImageObject + Article) with the reference's @id cross-referencing conventions.
 *
 * This is intentionally a plain builder: NO database access, NO WordPress runtime
 * dependency (the optional wp_json_encode call is function_exists-guarded),
 * so it can be unit-tested without any WP fakes. Callers feed it already-resolved
 * strings; it never fetches, sanitizes-at-source, or persists anything.
 *
 * @package PowerCreatives
 * @since   1.38.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Article_Schema
{
    /**
     * Maximum headline length. Mirrors the brief's 110-char truncation.
     */
    const HEADLINE_MAX = 110;

    /**
     * Build the full schema.org @graph structure.
     *
     * $args keys (all optional except title/url):
     *   title, description, url, siteUrl, siteName, orgName, logoUrl,
     *   authorName, authorUrl, authorSameAs (string[]), datePublished,
     *   dateModified, imageUrl, keywords (string[]), language,
     *   breadcrumbs (array of {name,url}).
     *
     * @param array $args Builder inputs.
     * @return array {'@context': string, '@graph': array<int,array>}
     */
    public static function build(array $args): array
    {
        $url      = self::normalize_url(self::str($args, 'url'));
        $site_url = self::resolve_site_url($args, $url);
        $title    = self::str($args, 'title');
        $desc     = self::str($args, 'description');
        $lang     = self::str($args, 'language');
        if ($lang === '') {
            $lang = 'en-US';
        }

        $graph = array();

        // --- WebSite -----------------------------------------------------
        $graph[] = array(
            '@type'      => 'WebSite',
            '@id'        => $site_url . '#website',
            'name'       => self::first_non_empty(array(self::str($args, 'siteName'), self::str($args, 'orgName'), 'Website')),
            'url'        => $site_url,
            'inLanguage' => $lang,
        );

        // --- Organization ------------------------------------------------
        $organization = array(
            '@type' => 'Organization',
            '@id'   => $site_url . '#organization',
            'name'  => self::first_non_empty(array(self::str($args, 'orgName'), self::str($args, 'siteName'), 'Organization')),
            'url'   => $site_url,
        );
        $logo_url = self::str($args, 'logoUrl');
        if ($logo_url !== '') {
            $organization['logo'] = array(
                '@type' => 'ImageObject',
                '@id'   => $site_url . '#logo',
                'url'   => $logo_url,
            );
        }
        $graph[] = $organization;

        // --- Person (author) --------------------------------------------
        $author_name = self::str($args, 'authorName');
        $has_author  = ($author_name !== '');
        if ($has_author) {
            $person = array(
                '@type' => 'Person',
                '@id'   => $url . '#author',
                'name'  => $author_name,
            );
            $author_url = self::str($args, 'authorUrl');
            if ($author_url !== '') {
                $person['url'] = $author_url;
            }
            $same_as = self::str_list($args, 'authorSameAs');
            if (!empty($same_as)) {
                $person['sameAs'] = $same_as;
            }
            $graph[] = $person;
        }

        // --- BreadcrumbList ---------------------------------------------
        $crumbs = self::crumbs($args);
        if (!empty($crumbs)) {
            $items    = array();
            $position = 1;
            foreach ($crumbs as $crumb) {
                $item_node = array();
                if ($crumb['url'] !== '') {
                    $item_node['@id'] = $crumb['url'];
                }
                $item_node['name'] = $crumb['name'];
                $items[] = array(
                    '@type'    => 'ListItem',
                    'position' => $position,
                    'item'     => $item_node,
                );
                $position++;
            }
            $graph[] = array(
                '@type'           => 'BreadcrumbList',
                '@id'             => $url . '#breadcrumb',
                'itemListElement' => $items,
            );
        }

        // --- WebPage -----------------------------------------------------
        $webpage = array(
            '@type' => 'WebPage',
            '@id'   => $url . '#webpage',
            'url'   => $url,
            'name'  => $title,
        );
        if ($desc !== '') {
            $webpage['description'] = $desc;
        }
        $webpage['isPartOf']   = array('@id' => $site_url . '#website');
        $webpage['about']      = array('@id' => $site_url . '#organization');
        $webpage['inLanguage'] = $lang;
        $date_published = self::str($args, 'datePublished');
        if ($date_published !== '') {
            $webpage['datePublished'] = $date_published;
        }
        $date_modified = self::str($args, 'dateModified');
        if ($date_modified !== '') {
            $webpage['dateModified'] = $date_modified;
        }
        $image_url = self::str($args, 'imageUrl');
        $has_image = ($image_url !== '');
        if ($has_image) {
            $webpage['primaryImageOfPage'] = array('@id' => $url . '#primaryimage');
        }
        $graph[] = $webpage;

        // --- ImageObject -------------------------------------------------
        if ($has_image) {
            $graph[] = array(
                '@type'      => 'ImageObject',
                '@id'        => $url . '#primaryimage',
                'url'        => $image_url,
                'contentUrl' => $image_url,
            );
        }

        // --- Article -----------------------------------------------------
        $article = array(
            '@type'    => 'Article',
            '@id'      => $url . '#article',
            'headline' => self::truncate($title, self::HEADLINE_MAX),
        );
        if ($desc !== '') {
            $article['description'] = $desc;
        }
        $article['isPartOf']         = array('@id' => $url . '#webpage');
        $article['mainEntityOfPage'] = array('@id' => $url . '#webpage');
        // Author: Person when present, else fall back to the publishing Organization.
        $article['author'] = $has_author
            ? array('@id' => $url . '#author')
            : array('@id' => $site_url . '#organization');
        $article['publisher'] = array('@id' => $site_url . '#organization');
        if ($has_image) {
            $article['image'] = array('@id' => $url . '#primaryimage');
        }
        $keywords = self::str_list($args, 'keywords');
        if (!empty($keywords)) {
            $article['keywords'] = implode(', ', $keywords);
        }
        $article['inLanguage'] = $lang;
        if ($date_published !== '') {
            $article['datePublished'] = $date_published;
        }
        if ($date_modified !== '') {
            $article['dateModified'] = $date_modified;
        }
        $graph[] = $article;

        return array(
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        );
    }

    /**
     * Convenience wrapper: build() encoded inside a <script> tag.
     *
     * Uses wp_json_encode when available (production), falling back to json_encode
     * so unit tests need no WordPress fakes.
     *
     * @param array $args Builder inputs (see build()).
     * @return string
     */
    public static function render(array $args): string
    {
        $schema = self::build($args);
        $json   = function_exists('wp_json_encode')
            ? wp_json_encode($schema, JSON_UNESCAPED_SLASHES)
            : json_encode($schema, JSON_UNESCAPED_SLASHES);

        // wp_json_encode()/json_encode() can return false on encode failure; harden
        // against that (and against an expired test mock of wp_json_encode) so the
        // wrapper always contains valid JSON.
        if (!is_string($json)) {
            $json = json_encode($schema, JSON_UNESCAPED_SLASHES);
        }

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    // --- Internal helpers ------------------------------------------------

    /**
     * Strip a single trailing slash for consistent @id construction.
     */
    private static function normalize_url(string $raw): string
    {
        return rtrim($raw, '/');
    }

    /**
     * Resolve the site URL: explicit siteUrl arg wins, else derive scheme://host
     * from the page URL (first three slash-delimited segments), mirroring the
     * reference's extractSiteUrl().
     */
    private static function resolve_site_url(array $args, string $normalized_url): string
    {
        $site_url = self::str($args, 'siteUrl');
        if ($site_url !== '') {
            return self::normalize_url($site_url);
        }
        $parts = explode('/', $normalized_url);
        return implode('/', array_slice($parts, 0, 3));
    }

    /**
     * Truncate to $max characters (no ellipsis), multibyte-safe when available.
     */
    private static function truncate(string $value, int $max): string
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
        }
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }

    /**
     * First non-empty string from a list (guaranteed non-empty by caller's tail default).
     *
     * @param array<int,string> $candidates
     */
    private static function first_non_empty(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Coerce an arg to a trimmed string ('' when missing/non-scalar).
     */
    private static function str(array $args, string $key): string
    {
        if (!isset($args[$key]) || !is_scalar($args[$key])) {
            return '';
        }
        return trim((string) $args[$key]);
    }

    /**
     * Coerce an arg to a list of non-empty strings ([] when missing/non-array).
     *
     * @return array<int,string>
     */
    private static function str_list(array $args, string $key): array
    {
        if (!isset($args[$key]) || !is_array($args[$key])) {
            return array();
        }
        $out = array();
        foreach ($args[$key] as $value) {
            if (is_scalar($value)) {
                $str = trim((string) $value);
                if ($str !== '') {
                    $out[] = $str;
                }
            }
        }
        return array_values($out);
    }

    /**
     * Normalize breadcrumbs into a list of {name,url} string pairs.
     *
     * @return array<int,array{name:string,url:string}>
     */
    private static function crumbs(array $args): array
    {
        if (!isset($args['breadcrumbs']) || !is_array($args['breadcrumbs'])) {
            return array();
        }
        $out = array();
        foreach ($args['breadcrumbs'] as $crumb) {
            if (!is_array($crumb)) {
                continue;
            }
            $name = (isset($crumb['name']) && is_scalar($crumb['name'])) ? trim((string) $crumb['name']) : '';
            $url  = (isset($crumb['url']) && is_scalar($crumb['url'])) ? trim((string) $crumb['url']) : '';
            $out[] = array('name' => $name, 'url' => $url);
        }
        return $out;
    }
}
