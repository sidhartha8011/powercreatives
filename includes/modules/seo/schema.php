<?php
/**
 * SEO — Schema.org JSON-LD renderer (faithful port of the source's
 * schema-renderer). Reads `pcm_seo_schema` post meta (array of type names)
 * and emits enriched JSON-LD in <head> on singular pages.
 *
 * Supported types: Article, WebPage, BreadcrumbList, FAQPage, HowTo, Product.
 * Description/keywords/primary-keyword come through the Phase 1 cross-plugin
 * abstraction (PCM_SEO_Service::seo_get), so they track the active SEO plugin.
 *
 * Required by service.php (loaded every request → wp_head hook registers).
 *
 * @package PowerCreatives
 * @since   1.23.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_SEO_Schema
{
    public const META_TYPES   = 'pcm_seo_schema';
    public const META_FAQ     = 'pcm_seo_schema_faq';
    public const META_HOWTO   = 'pcm_seo_schema_howto';
    public const META_SAMEAS  = 'pcm_seo_schema_sameas';

    public const TYPES = array('Article', 'WebPage', 'BreadcrumbList', 'FAQPage', 'HowTo', 'Product');

    /** Active schema types for a post (validated). */
    public static function types_for(int $post_id): array
    {
        $raw  = get_post_meta($post_id, self::META_TYPES, true);
        $list = json_decode((string) $raw, true);
        if (!is_array($list)) {
            return array();
        }
        return array_values(array_intersect($list, self::TYPES));
    }

    /** wp_head: emit one JSON-LD block per active type. */
    public static function render(): void
    {
        if (!is_singular()) {
            return;
        }
        $post = get_post();
        if (!$post) {
            return;
        }
        $types = self::types_for((int) $post->ID);
        if (empty($types)) {
            return;
        }
        $shared = self::shared_data($post);
        foreach ($types as $type) {
            $schema = self::build($type, $post, $shared);
            if ($schema) {
                echo '<script type="application/ld+json">'
                    . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    . '</script>' . "\n";
            }
        }
    }

    /** Shared data gathered once per render. */
    public static function shared_data(WP_Post $post): array
    {
        $id     = (int) $post->ID;
        $author = get_userdata($post->post_author);
        $desc   = class_exists('PCM_SEO_Service') ? PCM_SEO_Service::seo_get($id, 'description') : '';
        $kw_raw = class_exists('PCM_SEO_Service') ? PCM_SEO_Service::seo_get($id, 'meta_keywords') : '';
        $primary = class_exists('PCM_SEO_Service') ? PCM_SEO_Service::seo_get($id, 'keyword') : '';
        $site_lang = get_bloginfo('language');

        // sameAs: verified Wikidata entries → flat list + per-term map; else
        // a guessed Wikipedia link from the primary keyword.
        $same_as = array();
        $same_as_map = array();
        $stored = json_decode((string) get_post_meta($id, self::META_SAMEAS, true), true);
        if (is_array($stored) && !empty($stored)) {
            foreach ($stored as $entry) {
                if (!empty($entry['wikipedia_url'])) {
                    $same_as[] = $entry['wikipedia_url'];
                }
                if (!empty($entry['wikidata_url'])) {
                    $same_as[] = $entry['wikidata_url'];
                }
                $key = strtolower((string) ($entry['term'] ?? ''));
                if ($key !== '') {
                    $same_as_map[$key] = $entry;
                }
            }
            $same_as = array_values(array_filter($same_as));
        } elseif ($primary) {
            $slug = str_replace(' ', '_', ucwords($primary));
            $same_as[] = 'https://' . substr($site_lang, 0, 2) . '.wikipedia.org/wiki/' . $slug;
        }

        return array(
            'author'      => $author,
            'thumb'       => get_the_post_thumbnail_url($id, 'full'),
            'categories'  => get_the_category($id) ?: array(),
            'tags'        => get_the_tags($id) ?: array(),
            'description' => $desc,
            'keywords'    => array_filter(array_map('trim', explode(',', $kw_raw))),
            'primary_kw'  => $primary,
            'site_name'   => get_bloginfo('name'),
            'site_url'    => home_url('/'),
            'site_lang'   => $site_lang,
            'same_as'     => $same_as,
            'same_as_map' => $same_as_map,
            'permalink'   => get_permalink($id),
            'word_count'  => str_word_count(wp_strip_all_tags($post->post_content)),
        );
    }

    public static function build(string $type, WP_Post $post, array $s): ?array
    {
        switch ($type) {
            case 'Article':
                $schema = array(
                    '@context' => 'https://schema.org',
                    '@type' => 'Article',
                    'headline' => $post->post_title,
                    'url' => $s['permalink'],
                    'mainEntityOfPage' => array('@type' => 'WebPage', '@id' => $s['permalink']),
                    'datePublished' => get_the_date('c', $post),
                    'dateModified' => get_the_modified_date('c', $post),
                    'wordCount' => $s['word_count'],
                    'inLanguage' => $s['site_lang'],
                );
                if ($s['author']) {
                    $schema['author'] = array('@type' => 'Person', 'name' => $s['author']->display_name, 'url' => get_author_posts_url($s['author']->ID));
                }
                $schema['publisher'] = self::publisher($s['site_name'], $s['site_url']);
                if ($s['thumb']) {
                    $schema['image'] = $s['thumb'];
                }
                if ($s['description']) {
                    $schema['description'] = $s['description'];
                }
                if (!empty($s['keywords'])) {
                    $schema['keywords'] = implode(', ', $s['keywords']);
                }
                if ($s['primary_kw']) {
                    $schema['about'] = self::thing($s['primary_kw'], $s['same_as_map'], $s['same_as']);
                }
                if (!empty($s['tags'])) {
                    $schema['mentions'] = array_map(static fn($t) => self::thing($t->name, $s['same_as_map'], $s['same_as']), $s['tags']);
                }
                if (!empty($s['categories'])) {
                    $schema['articleSection'] = array_map(static fn($c) => $c->name, $s['categories']);
                }
                $schema['speakable'] = array('@type' => 'SpeakableSpecification', 'cssSelector' => array('h1', '.entry-content p:first-of-type'));
                $schema['potentialAction'] = array('@type' => 'ReadAction', 'target' => $s['permalink']);
                return $schema;

            case 'WebPage':
                $schema = array(
                    '@context' => 'https://schema.org',
                    '@type' => 'WebPage',
                    'name' => $post->post_title,
                    'url' => $s['permalink'],
                    'inLanguage' => $s['site_lang'],
                    'datePublished' => get_the_date('c', $post),
                    'dateModified' => get_the_modified_date('c', $post),
                    'isPartOf' => array('@type' => 'WebSite', 'name' => $s['site_name'], 'url' => $s['site_url']),
                );
                if ($s['description']) {
                    $schema['description'] = $s['description'];
                }
                if ($s['thumb']) {
                    $schema['primaryImageOfPage'] = $s['thumb'];
                }
                if (!empty($s['keywords'])) {
                    $schema['keywords'] = implode(', ', $s['keywords']);
                }
                if ($s['primary_kw']) {
                    $schema['about'] = self::thing($s['primary_kw'], $s['same_as_map'], $s['same_as']);
                }
                $schema['speakable'] = array('@type' => 'SpeakableSpecification', 'cssSelector' => array('h1', '.entry-content p:first-of-type'));
                $schema['potentialAction'] = array('@type' => 'ReadAction', 'target' => $s['permalink']);
                return $schema;

            case 'BreadcrumbList':
                $items = array();
                $pos = 1;
                $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => $s['site_name'], 'item' => $s['site_url']);
                if (!empty($s['categories'])) {
                    $cat = $s['categories'][0];
                    $items[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => $cat->name, 'item' => get_category_link($cat->term_id));
                }
                $items[] = array('@type' => 'ListItem', 'position' => $pos, 'name' => $post->post_title);
                return array('@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items);

            case 'FAQPage':
                $faqs = json_decode((string) get_post_meta($post->ID, self::META_FAQ, true), true);
                if (!is_array($faqs) || empty($faqs)) {
                    return null;
                }
                $entities = array();
                foreach ($faqs as $faq) {
                    if (empty($faq['q']) || empty($faq['a'])) {
                        continue;
                    }
                    $entities[] = array('@type' => 'Question', 'name' => $faq['q'], 'acceptedAnswer' => array('@type' => 'Answer', 'text' => $faq['a']));
                }
                return empty($entities) ? null : array('@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities);

            case 'HowTo':
                $steps = json_decode((string) get_post_meta($post->ID, self::META_HOWTO, true), true);
                if (!is_array($steps) || empty($steps)) {
                    return null;
                }
                $step_items = array();
                foreach ($steps as $i => $step) {
                    if (empty($step['text'])) {
                        continue;
                    }
                    $step_items[] = array('@type' => 'HowToStep', 'position' => $i + 1, 'name' => $step['name'] ?? '', 'text' => $step['text']);
                }
                if (empty($step_items)) {
                    return null;
                }
                $schema = array('@context' => 'https://schema.org', '@type' => 'HowTo', 'name' => $post->post_title, 'step' => $step_items, 'inLanguage' => $s['site_lang']);
                if ($s['description']) {
                    $schema['description'] = $s['description'];
                }
                if ($s['thumb']) {
                    $schema['image'] = $s['thumb'];
                }
                return $schema;

            case 'Product':
                $schema = array('@context' => 'https://schema.org', '@type' => 'Product', 'name' => $post->post_title, 'url' => $s['permalink']);
                if ($s['description']) {
                    $schema['description'] = $s['description'];
                }
                if ($s['thumb']) {
                    $schema['image'] = $s['thumb'];
                }
                if (!empty($s['keywords'])) {
                    $schema['keywords'] = implode(', ', $s['keywords']);
                }
                if ($s['primary_kw']) {
                    $schema['category'] = $s['primary_kw'];
                }
                $schema['brand'] = array('@type' => 'Brand', 'name' => $s['site_name']);
                return $schema;

            default:
                return null;
        }
    }

    private static function publisher(string $site_name, string $site_url): array
    {
        $publisher = array('@type' => 'Organization', 'name' => $site_name, 'url' => $site_url);
        $logo = self::site_logo();
        if ($logo) {
            $publisher['logo'] = array('@type' => 'ImageObject', 'url' => $logo);
        }
        return $publisher;
    }

    private static function thing(string $name, array $map, array $flat): array
    {
        $thing = array('@type' => 'Thing', 'name' => $name);
        $key = strtolower($name);
        if (isset($map[$key])) {
            $links = array_values(array_filter(array($map[$key]['wikipedia_url'] ?? '', $map[$key]['wikidata_url'] ?? '')));
            if (count($links) === 1) {
                $thing['sameAs'] = $links[0];
            } elseif (count($links) > 1) {
                $thing['sameAs'] = $links;
            }
        } elseif (!empty($flat)) {
            $thing['sameAs'] = $flat[0];
        }
        return $thing;
    }

    /** Publisher logo fallback chain (plugin → customizer → Yoast/RankMath/SEOPress → site icon). */
    public static function site_logo(): ?string
    {
        $plugin = get_option('pcm_seo_logo_url', '');
        if ($plugin) {
            return (string) $plugin;
        }
        $custom = get_theme_mod('custom_logo');
        if ($custom) {
            $url = wp_get_attachment_image_url($custom, 'full');
            if ($url) {
                return $url;
            }
        }
        $yoast = get_option('wpseo_titles');
        if (!empty($yoast['company_logo'])) {
            return (string) $yoast['company_logo'];
        }
        $rm = get_option('rank_math_knowledgegraph_logo');
        if ($rm) {
            $url = wp_get_attachment_image_url((int) $rm, 'full');
            if ($url) {
                return $url;
            }
        }
        $seopress = get_option('seopress_social_option_name');
        if (!empty($seopress['seopress_social_knowledge_img'])) {
            return (string) $seopress['seopress_social_knowledge_img'];
        }
        $icon = get_site_icon_url(512);
        return $icon ?: null;
    }

    /** Validate + persist a post's schema types (whitelist). */
    public static function set_types(int $post_id, array $types): array
    {
        $clean = array_values(array_intersect($types, self::TYPES));
        update_post_meta($post_id, self::META_TYPES, wp_json_encode($clean));
        return $clean;
    }
}

add_action('wp_head', array('PCM_SEO_Schema', 'render'), 1);
