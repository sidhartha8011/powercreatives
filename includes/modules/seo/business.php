<?php
/**
 * SEO Business Card — the per-site business record.
 *
 * Extracted VERBATIM from PCM_SEO_Service (2026-07-29 decomposition).
 *
 * THE BUSINESS CARD (Business Spine P2+P3, gap 616870f). Ownership law:
 * brands own brand truth · SITES own the connection (brandId +
 * businessUnitId FKs) · SEO consumes and owns exactly ONE layer — the
 * per-site SEO overrides. The ladder is a PURE function (harness-
 * tested); the resolver is data reads + that call. Malleable by
 * construction: fields are open keys (the frontend registry decides
 * what renders), sources ride every field.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_SEO_Business
{
    /**
     * THE LADDER — pure. Precedence upward: site basics < brand basics <
     * unit fetched (its own per-key sources) < unit manual < site override.
     * Empty values never overwrite (empty stays empty, never invents);
     * every landed field carries its source tag.
     *
     * @param array $site_overrides Sparse per-site SEO fields.
     * @param array $unit           Brands unit record {fetched,manual,sources} (may be empty).
     * @param array $brand_basics   Flat brand-row fields (may be empty).
     * @param array $site_basics    Flat site fields (name/siteUrl).
     * @return array{fields:array<string,mixed>,sources:array<string,string>}
     */
    public static function merge_business_ladder(array $site_overrides, array $unit, array $brand_basics, array $site_basics): array
    {
        $fields  = array();
        $sources = array();
        $lay = static function (array $layer, $tag) use (&$fields, &$sources): void {
            foreach ($layer as $k => $v) {
                if ($v === '' || $v === null || $v === array()) {
                    continue;
                }
                $fields[$k]  = $v;
                $sources[$k] = is_array($tag) ? (string) ($tag[$k] ?? 'gbp') : (string) $tag;
            }
        };
        $lay($site_basics, 'site-basics');
        $lay($brand_basics, 'brand');
        $lay((array) ($unit['fetched'] ?? array()), (array) ($unit['sources'] ?? array()));
        $lay((array) ($unit['manual'] ?? array()), 'manual');
        $lay($site_overrides, 'site');
        return array('fields' => $fields, 'sources' => $sources);
    }

    /** The per-site SEO override layer's option key. */
    private static function biz_site_option(int $site_id): string
    {
        return 'pcm_seo_biz_site_' . $site_id;
    }

    /**
     * The resolved business record for a SITE — what every generation and
     * the card consume. Site row (FKs) → brands unit API → SEO site layer
     * → the pure ladder. No brand linked = brand layers empty, honest.
     *
     * @return array{fields:array,sources:array,brandId:int,brandName:string,unitId:int,unitLabel:string}
     */
    public static function business_record_for_site(int $site_id): array
    {
        $empty = array('fields' => array(), 'sources' => array(), 'brandId' => 0, 'brandName' => '', 'unitId' => 0, 'unitLabel' => '');
        if ($site_id <= 0) {
            return $empty;
        }
        global $wpdb;
        $sites = PCM_Schema::table('sites');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $site = $wpdb->get_row($wpdb->prepare("SELECT name, url, brandId, businessUnitId FROM {$sites} WHERE id = %d", $site_id));
        if (!$site) {
            return $empty;
        }
        $site_basics = array(
            'name'    => (string) ($site->name ?? ''),
            'website' => (string) ($site->url ?? ''),
            'siteUrl' => (string) ($site->url ?? ''),
        );
        $brand_id     = (int) ($site->brandId ?? 0);
        $unit_id      = (int) ($site->businessUnitId ?? 0);
        $brand_basics = array();
        $unit         = array();
        $brand_name   = '';
        $unit_label   = '';
        if ($brand_id > 0) {
            $brands = PCM_Schema::table('brands');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $brand = $wpdb->get_row($wpdb->prepare(
                "SELECT name, website, phone, location, language, businessSummary FROM {$brands} WHERE id = %d",
                $brand_id
            ));
            if ($brand) {
                $brand_name   = (string) ($brand->name ?? '');
                $brand_basics = array(
                    'name'        => (string) ($brand->name ?? ''),
                    'website'     => (string) ($brand->website ?? ''),
                    'phone'       => (string) ($brand->phone ?? ''),
                    'address'     => (string) ($brand->location ?? ''),
                    'language'    => (string) ($brand->language ?? ''),
                    'description' => (string) ($brand->businessSummary ?? ''),
                );
            }
            if (class_exists('PCM_Brands_Service')) {
                $unit       = PCM_Brands_Service::get_business_record($brand_id, $unit_id);
                $unit_label = (string) ($unit['label'] ?? '');
                $unit_id    = (int) ($unit['unitId'] ?? 0);
            }
        }
        $overrides = get_option(self::biz_site_option($site_id), array());
        $ladder    = self::merge_business_ladder(is_array($overrides) ? $overrides : array(), $unit, $brand_basics, $site_basics);
        return array(
            'fields'    => $ladder['fields'],
            'sources'   => $ladder['sources'],
            'brandId'   => $brand_id,
            'brandName' => $brand_name,
            'unitId'    => $unit_id,
            'unitLabel' => $unit_label,
        );
    }

    /**
     * Save the per-site SEO override layer (sparse). Open keys by design —
     * the frontend registry decides what exists; the server guards shape:
     * sanitized keys, textarea-sanitized scalar values, caps on count and
     * length. An EMPTY value removes the override (back to the brand
     * truth). Returns the fresh resolved record.
     *
     * @param int   $site_id Site id.
     * @param array $fields  key => value (scalar).
     * @return array|\WP_Error business_record_for_site() shape.
     */
    public static function save_site_business_overrides(int $site_id, array $fields)
    {
        if ($site_id <= 0) {
            return new WP_Error('pcm_seo_biz_no_site', __('A site is required.', 'power-creatives'), array('status' => 400));
        }
        $stored = get_option(self::biz_site_option($site_id), array());
        $stored = is_array($stored) ? $stored : array();
        $n      = 0;
        foreach ($fields as $k => $v) {
            $key = sanitize_key((string) $k);
            if ($key === '' || !is_scalar($v)) {
                continue;
            }
            if (++$n > 40) {
                return new WP_Error('pcm_seo_biz_too_many', __('Too many fields in one save (max 40).', 'power-creatives'), array('status' => 400));
            }
            $val = sanitize_textarea_field((string) $v);
            if (function_exists('mb_substr')) {
                $val = mb_substr($val, 0, 2000);
            } else {
                $val = substr($val, 0, 2000);
            }
            if ($val === '') {
                unset($stored[$key]); // clearing = back to the brand truth
            } else {
                $stored[$key] = $val;
            }
        }
        update_option(self::biz_site_option($site_id), $stored, false);
        return self::business_record_for_site($site_id);
    }

    /**
     * Parse a pasted Google Maps share URL — pure, harness-tested. ONE
     * human paste yields CID + coordinates + a buildable embed URL: the
     * zero-API Google surface (Business Spine, Google-free ruling).
     *
     * @param string $url The pasted URL.
     * @return array{fields:array<string,string>}|\WP_Error Named error when nothing parses.
     */
    public static function parse_maps_url(string $url)
    {
        $url  = trim($url);
        $host = strtolower((string) (wp_parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '' || (strpos($host, 'google') === false && strpos($host, 'goo.gl') === false)) {
            return new WP_Error('pcm_seo_maps_not_maps', __('That is not a Google Maps link — paste the Share URL from Google Maps.', 'power-creatives'), array('status' => 400));
        }
        $fields = array('mapsShareUrl' => esc_url_raw($url));
        if (preg_match('/[?&]cid=(\d+)/', $url, $m)) {
            $fields['cid'] = $m[1];
        } elseif (preg_match('/!1s0x[0-9a-f]+:0x([0-9a-f]+)/i', $url, $m)) {
            // The hex place ref's second half IS the CID in decimal. CIDs are
            // 64-bit — hexdec() would overflow to float; exact string math.
            $fields['cid'] = self::hex_to_dec($m[1]);
        }
        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)
            || preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $url, $m)) {
            $fields['lat'] = $m[1];
            $fields['lng'] = $m[2];
        }
        if (!empty($fields['cid'])) {
            $fields['mapsEmbedUrl'] = 'https://maps.google.com/maps?cid=' . $fields['cid'] . '&output=embed';
        } elseif (!empty($fields['lat'])) {
            $fields['mapsEmbedUrl'] = 'https://maps.google.com/maps?q=' . $fields['lat'] . ',' . $fields['lng'] . '&output=embed';
        }
        if (count($fields) === 1) {
            return new WP_Error('pcm_seo_maps_unparsed', __('Nothing recognizable in that Maps link — use the Share button in Google Maps and paste that URL.', 'power-creatives'), array('status' => 400));
        }
        return array('fields' => $fields);
    }

    /** Exact hex → decimal string (64-bit CIDs overflow hexdec) — pure string math, no extension dependency. */
    private static function hex_to_dec(string $hex): string
    {
        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $digit = (int) hexdec($hex[$i]);
            $carry = $digit;
            $out   = '';
            for ($j = strlen($dec) - 1; $j >= 0; $j--) {
                $v     = ((int) $dec[$j]) * 16 + $carry;
                $out   = (string) ($v % 10) . $out;
                $carry = intdiv($v, 10);
            }
            while ($carry > 0) {
                $out   = (string) ($carry % 10) . $out;
                $carry = intdiv($carry, 10);
            }
            $dec = ltrim($out, '0');
            if ($dec === '') {
                $dec = '0';
            }
        }
        return $dec;
    }
}
