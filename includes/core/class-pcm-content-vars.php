<?php
/**
 * Content Vars — THE centralized site + business {{ variable }} vocabulary.
 *
 * Owner (Templates / Writer Templates card): "The writer templates should
 * essentially share the variables that it can have coming from the site and the
 * business… There should be some sort of centralized place where all these
 * variables are for their respective module, so that we always get the right
 * variables. Right now, we're lacking variables that we currently have in SEO,
 * but we don't have it in writer."
 *
 * So the post-INDEPENDENT half of the vocabulary — everything describing the
 * SITE and the BUSINESS — lives here, once, and every module composes its own
 * map from it:
 *
 *   PCM_SEO_AI::build_field_vars()        = post vars + these
 *   PCM_Strategy_Service::build_prompt()  = writer fragments + these
 *
 * Post-SPECIFIC tokens ({{title}}, {{primary_keyword}}, {{post_type}}…) stay
 * with their module: they describe a row, not the site, and Writer has no post
 * when it generates. Keeping them out is what makes this list share-able.
 *
 * KEYS() IS THE CONTRACT. The Templates typeahead (app/src/modules/Templates/
 * templateVars.ts) offers exactly these tokens for every module that composes
 * this map — a token advertised but never substituted would paste text that
 * silently survives into the prompt sent to the model, which is worse than no
 * suggestion at all. tests/standalone/template_vars_parity_test.php pins the
 * two sides together.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Content_Vars
{
    /**
     * Every token this shared map resolves, in map order.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return array_keys(self::site_business(null, ''));
    }

    /**
     * The site + business variables.
     *
     * @param int|null    $brand_id Brand for {{business.*}} (its Google Business
     *                              Profile, resolved = snapshot + overrides).
     *                              Null/0 falls back to this site's own info.
     * @param string|null $lang     Already-resolved {{site.lang}}. Pass '' to use
     *                              the hub locale (SEO's long-standing behaviour);
     *                              pass a brand language to describe the site the
     *                              content is FOR. Null = derive from the brand,
     *                              falling back to the hub locale.
     * @return array<string,string> Token (without braces) => value.
     */
    public static function site_business(?int $brand_id = null, ?string $lang = null): array
    {
        $home = home_url('/');
        $host = (string) wp_parse_url($home, PHP_URL_HOST);

        $business_name = (string) get_bloginfo('name');
        $business_tag  = (string) get_bloginfo('description');
        $brand_lang    = '';

        // GBP business context for the brand (resolved = snapshot + overrides).
        $gbp = array();
        if ($brand_id && $brand_id > 0) {
            global $wpdb;
            $brands = PCM_Schema::table('brands');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $brand = $wpdb->get_row($wpdb->prepare("SELECT name, language FROM {$brands} WHERE id = %d", $brand_id));
            if ($brand && !empty($brand->name)) {
                $business_name = (string) $brand->name;
            }
            if ($brand && !empty($brand->language)) {
                $brand_lang = trim((string) $brand->language);
            }
            if (class_exists('PCM_SEO_GBP')) {
                $gbp = PCM_SEO_GBP::get_for_brand($brand_id)['resolved'];
                if (!empty($gbp['name'])) {
                    $business_name = (string) $gbp['name'];
                }
            }
        }

        // '' = caller insists on the hub locale; null = prefer the brand's language
        // (it describes the site the content is written FOR), hub locale last.
        $locale = get_locale();
        $fallback_lang = $locale ? substr($locale, 0, 2) : 'en';
        if ($lang === null) {
            $site_lang = $brand_lang !== '' ? $brand_lang : $fallback_lang;
        } elseif ($lang === '') {
            $site_lang = $fallback_lang;
        } else {
            $site_lang = $lang;
        }

        return array(
            // The site.* namespace — "all site. variables" (owner card 13). site.name /
            // site.tagline are THIS site's own identity (WordPress General Settings),
            // deliberately separate from business.name / business.tagline, which the
            // brand's Google Business Profile may override. website.url stays for compat.
            'site.lang'                 => $site_lang,
            'site.name'                 => (string) get_bloginfo('name'),
            'site.tagline'              => (string) get_bloginfo('description'),
            'site.url'                  => $home,
            'site.host'                 => $host,
            'website.url'               => $home,
            'today'                     => gmdate('Y-m-d'),
            'business.name'             => $business_name,
            'business.tagline'          => $business_tag,
            'business.website'          => !empty($gbp['website']) ? (string) $gbp['website'] : $home,
            'business.website|hostname' => $host,
            'business.address'          => (string) ($gbp['address'] ?? ''),
            'business.phone'            => (string) ($gbp['phone'] ?? ''),
            'business.category'         => (string) ($gbp['category'] ?? ''),
            'business.hours'            => (string) ($gbp['hours'] ?? ''),
            'business.description'      => (string) ($gbp['description'] ?? ''),
            'business.rating'           => isset($gbp['rating']) ? (string) $gbp['rating'] : '',
            'business.lat'              => isset($gbp['lat']) ? (string) $gbp['lat'] : '',
            'business.lng'              => isset($gbp['lng']) ? (string) $gbp['lng'] : '',
            'business.types'            => !empty($gbp['types']) ? implode(', ', (array) $gbp['types']) : '',
        );
    }
}
