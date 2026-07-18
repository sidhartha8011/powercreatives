<?php
/**
 * SEO — Google Business Profile (GBP) integration.
 *
 * Fetches public business data (name, address, phone, geo, hours, rating)
 * for a place and attaches it to a PC brand (brand ≈ client), so the SEO
 * prompt variables ({{business.*}}) resolve to real data.
 *
 * Provider abstraction: the fetch transport is behind PCM_SEO_GBP_Provider so
 * the current n8n-webhook implementation can be swapped for a direct Google
 * Places (New) client later WITHOUT touching the normalize/storage/UI code —
 * just register a different provider id. The response normalizer is shared
 * (both n8n and a direct client return the Places API New v1 shape).
 *
 * Required by service.php.
 *
 * @package PowerCreatives
 * @since   1.23.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Fetch transport contract — implement once per backend (n8n today, Google later). */
interface PCM_SEO_GBP_Provider
{
    /** @return array[] Raw place candidates (Places New v1 shape). */
    public function search(string $query, string $language_code): array;

    /** @return array Raw place details (Places New v1 shape), or []. */
    public function details(string $place_id, string $language_code): array;
}

/**
 * NATIVE Google Places (New) v1 provider (Google Native Phase A, gap
 * 670d0e0 — the n8n middleman is DELETED, this replaces it in the same
 * pair). Key = the `google_places` Integrations provider entry, per user —
 * the regular flow: add a key, gain the voice. THE WIDE FIELD MASK carries
 * the agency cheat sheet's public half (address components, intl phone,
 * maps URI, public reviews, hours, rating, category). Reviews are CONTENT
 * material only — never schema markup (policy law).
 */
class PCM_SEO_GBP_Google_Provider implements PCM_SEO_GBP_Provider
{
    private const MASK = 'id,displayName,formattedAddress,shortFormattedAddress,addressComponents,nationalPhoneNumber,internationalPhoneNumber,location,websiteUri,types,primaryTypeDisplayName,rating,userRatingCount,regularOpeningHours,editorialSummary,googleMapsUri,reviews';

    /** The calling user's key — resolved per request (set by PCM_SEO_GBP::provider()). */
    private $user_id;

    public function __construct(int $user_id = 0)
    {
        $this->user_id = $user_id;
    }

    private function api_key(): string
    {
        global $wpdb;
        $table = PCM_Schema::table('integrations');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $key = $wpdb->get_var($wpdb->prepare(
            "SELECT apiKey FROM {$table} WHERE provider = %s AND userId = %d AND isActive = 1 LIMIT 1",
            'google_places',
            $this->user_id
        ));
        return (string) ($key ?: '');
    }

    private function request(string $url, ?array $body, string $language_code): array
    {
        $key = $this->api_key();
        if ($key === '') {
            return array('error' => __('No Google Places key — add the "Google Places" integration (Integrations module), then retry.', 'power-creatives'));
        }
        $args = array(
            'timeout' => 20,
            'headers' => array(
                'Content-Type'             => 'application/json',
                'X-Goog-Api-Key'           => $key,
                'X-Goog-FieldMask'         => $body !== null ? preg_replace('/(^|,)/', '$1places.', self::MASK) : self::MASK,
                'languageCode'             => $language_code,
                'reviews_no_translations'  => 'true',
            ),
        );
        if ($body !== null) {
            $args['method'] = 'POST';
            $args['body']   = wp_json_encode($body);
        }
        $res = ($body !== null) ? wp_remote_post($url, $args) : wp_remote_get($url, $args);
        if (is_wp_error($res)) {
            return array('error' => $res->get_error_message());
        }
        $code    = (int) wp_remote_retrieve_response_code($res);
        $decoded = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code !== 200) {
            $msg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
            return array('error' => sprintf(
                /* translators: 1: HTTP status, 2: Google's message */
                __('Google Places answered HTTP %1$d%2$s — check the key and the enabled API in its Google project.', 'power-creatives'),
                $code,
                $msg !== '' ? ' (' . $msg . ')' : ''
            ));
        }
        return is_array($decoded) ? $decoded : array();
    }

    public function search(string $query, string $language_code): array
    {
        $body = $this->request('https://places.googleapis.com/v1/places:searchText', array('textQuery' => $query), $language_code);
        if (isset($body['error'])) {
            return $body;
        }
        return is_array($body['places'] ?? null) ? array_values($body['places']) : array();
    }

    public function details(string $place_id, string $language_code): array
    {
        return $this->request('https://places.googleapis.com/v1/places/' . rawurlencode($place_id), null, $language_code);
    }
}

/**
 * APIFY provider (gap 1f38238): ONE actor run supplies the place record +
 * ALL FOUR schema ids (placeId / cid / fid / KGMID) + reviews — no Google
 * Cloud project. Key = the `apify` Integrations provider (regular flow);
 * WHICH actors run, caps, timeout, language = hub DATA (pcm_seo_apify,
 * merge-seeded) — never code.
 */
class PCM_SEO_GBP_Apify_Provider implements PCM_SEO_GBP_Provider
{
    private $user_id;

    public function __construct(int $user_id = 0)
    {
        $this->user_id = $user_id;
    }

    /** Seeded read-through tunables (merge law — new keys reach old installs). */
    public static function tunables(): array
    {
        $seed = array(
            'actorId'          => 'compass~crawler-google-places',
            // The cheap reviews-only top-up tap (future weekly refresh) —
            // stored as DATA now so wiring it later changes no code.
            'reviewsActorId'   => 'compass~google-maps-reviews-scraper',
            'timeoutS'         => 90,
            'searchLimit'      => 5,
            'maxReviews'       => 20,
            'storedReviewsCap' => 20,
            'language'         => '', // '' = the WP-locale default_lang()
        );
        $stored = get_option('pcm_seo_apify');
        if (is_array($stored) && !empty($stored['actorId'])) {
            return array_replace_recursive($seed, $stored);
        }
        add_option('pcm_seo_apify', $seed, '', false);
        return $seed;
    }

    private function api_key(): string
    {
        global $wpdb;
        $table = PCM_Schema::table('integrations');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $key = $wpdb->get_var($wpdb->prepare(
            "SELECT apiKey FROM {$table} WHERE provider = %s AND userId = %d AND isActive = 1 LIMIT 1",
            'apify',
            $this->user_id
        ));
        return (string) ($key ?: '');
    }

    /** One synchronous actor run → dataset items. Named errors, fix included. */
    private function run(array $input): array
    {
        $key = $this->api_key();
        if ($key === '') {
            return array('error' => __('No Apify key — add the "Apify" integration (Integrations module), then retry.', 'power-creatives'));
        }
        $cfg = self::tunables();
        $url = 'https://api.apify.com/v2/acts/' . rawurlencode((string) $cfg['actorId']) . '/run-sync-get-dataset-items?token=' . rawurlencode($key);
        $res = wp_remote_post($url, array(
            'timeout' => max(10, (int) $cfg['timeoutS']),
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($input),
        ));
        if (is_wp_error($res)) {
            return array('error' => sprintf(
                /* translators: %s: transport error */
                __('Apify did not answer: %s', 'power-creatives'),
                $res->get_error_message()
            ));
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($body) ? (string) ($body['error']['message'] ?? '') : '';
            return array('error' => sprintf(
                /* translators: 1: HTTP status, 2: Apify's message */
                __('Apify answered HTTP %1$d%2$s — check the key and the actor id in the pcm_seo_apify settings.', 'power-creatives'),
                $code,
                $msg !== '' ? ' (' . $msg . ')' : ''
            ));
        }
        return array('items' => is_array($body) ? array_values($body) : array());
    }

    private function lang(string $language_code): string
    {
        $cfg = self::tunables();
        return (string) ($cfg['language'] !== '' ? $cfg['language'] : $language_code);
    }

    public function search(string $query, string $language_code): array
    {
        $cfg = self::tunables();
        $out = $this->run(array(
            'searchStringsArray'         => array($query),
            'maxCrawledPlacesPerSearch'  => max(1, (int) $cfg['searchLimit']),
            'maxReviews'                 => 0,
            'language'                   => $this->lang($language_code),
        ));
        return isset($out['error']) ? $out : (array) $out['items'];
    }

    public function details(string $place_id, string $language_code): array
    {
        $cfg = self::tunables();
        $out = $this->run(array(
            'placeIds'    => array($place_id),
            'maxReviews'  => max(0, (int) $cfg['maxReviews']),
            'reviewsSort' => 'newest',
            'language'    => $this->lang($language_code),
        ));
        if (isset($out['error'])) {
            return $out;
        }
        return is_array($out['items'][0] ?? null) ? $out['items'][0] : array();
    }
}

class PCM_SEO_GBP
{
    /** Registered providers by id (n8n RETIRED 2026-07-17, gap 670d0e0;
     *  apify ADDED 2026-07-18, gap 1f38238 — the hub setting selects). */
    public static function providers(): array
    {
        $map = array(
            'apify'  => PCM_SEO_GBP_Apify_Provider::class,
            'google' => PCM_SEO_GBP_Google_Provider::class,
        );
        return apply_filters('pcm_seo_gbp_providers', $map);
    }

    /** The configured provider instance for one user (key resolution is
     *  per user — the Integrations pattern). Default = apify (owner ruling
     *  2026-07-18); the SETTING is the switch, never code. */
    public static function provider(int $user_id = 0): PCM_SEO_GBP_Provider
    {
        $id  = (string) PCM_Settings::get('seo_gbp_provider', 'apify');
        $map = self::providers();
        $class = $map[$id] ?? PCM_SEO_GBP_Apify_Provider::class;
        return new $class($user_id);
    }

    /** Default UI language code from the WP locale (e.g. 'sv'). */
    public static function default_lang(): string
    {
        $locale = get_locale();
        return $locale ? substr($locale, 0, 2) : 'en';
    }

    /**
     * Normalize a raw Places (New) v1 place into a flat record. Defensive
     * across v1/legacy key variants so a future direct client or a different
     * n8n shape both work.
     *
     * @param array $raw Raw place.
     * @return array Flat normalized record.
     */
    public static function normalize(array $raw): array
    {
        // SHAPE DETECTION (gap 1f38238): the Apify actor speaks its own
        // dialect (title/totalScore/kgmid) — one entry point, two mappers,
        // every caller untouched.
        if (isset($raw['title']) || isset($raw['kgmid']) || isset($raw['totalScore'])) {
            return self::normalize_apify($raw);
        }
        $name = '';
        if (isset($raw['displayName'])) {
            $name = is_array($raw['displayName']) ? (string) ($raw['displayName']['text'] ?? '') : (string) $raw['displayName'];
        } elseif (isset($raw['name'])) {
            $name = (string) $raw['name'];
        }
        $lat = $raw['location']['latitude'] ?? ($raw['geometry']['location']['lat'] ?? null);
        $lng = $raw['location']['longitude'] ?? ($raw['geometry']['location']['lng'] ?? null);
        $category = '';
        if (isset($raw['primaryTypeDisplayName'])) {
            $category = is_array($raw['primaryTypeDisplayName']) ? (string) ($raw['primaryTypeDisplayName']['text'] ?? '') : (string) $raw['primaryTypeDisplayName'];
        }
        $hours = '';
        if (!empty($raw['regularOpeningHours']['weekdayDescriptions']) && is_array($raw['regularOpeningHours']['weekdayDescriptions'])) {
            $hours = implode("\n", array_map('strval', $raw['regularOpeningHours']['weekdayDescriptions']));
        }
        $desc = '';
        if (isset($raw['editorialSummary'])) {
            $desc = is_array($raw['editorialSummary']) ? (string) ($raw['editorialSummary']['text'] ?? '') : (string) $raw['editorialSummary'];
        }
        // THE WIDE MASK additions (gap 670d0e0) — all additive, nothing renamed.
        $components = array();
        foreach ((array) ($raw['addressComponents'] ?? array()) as $c) {
            if (!is_array($c) || empty($c['types']) || !is_array($c['types'])) {
                continue;
            }
            $text = is_array($c['longText'] ?? null) ? '' : (string) ($c['longText'] ?? ($c['long_name'] ?? ''));
            foreach ($c['types'] as $t) {
                $components[(string) $t] = $text;
            }
        }
        $maps_uri = (string) ($raw['googleMapsUri'] ?? '');
        $cid      = '';
        if ($maps_uri !== '' && preg_match('/[?&]cid=(\d+)/', $maps_uri, $cm)) {
            $cid = $cm[1];
        }
        $public_reviews = array();
        foreach (array_slice((array) ($raw['reviews'] ?? array()), 0, 5) as $rv) {
            if (!is_array($rv)) {
                continue;
            }
            $text = is_array($rv['text'] ?? null) ? (string) ($rv['text']['text'] ?? '') : (string) ($rv['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $public_reviews[] = array(
                'author' => is_array($rv['authorAttribution'] ?? null) ? (string) ($rv['authorAttribution']['displayName'] ?? '') : '',
                'rating' => isset($rv['rating']) ? (float) $rv['rating'] : null,
                'text'   => $text,
                'time'   => (string) ($rv['publishTime'] ?? ''),
            );
        }
        return array_filter(array(
            'place_id'      => (string) ($raw['id'] ?? $raw['place_id'] ?? $raw['placeId'] ?? ''),
            'name'          => $name,
            'address'       => (string) ($raw['formattedAddress'] ?? $raw['formatted_address'] ?? ''),
            'shortAddress'  => (string) ($raw['shortFormattedAddress'] ?? ''),
            'postal'        => (string) ($components['postal_code'] ?? ''),
            'city'          => (string) ($components['postal_town'] ?? $components['locality'] ?? ''),
            'region'        => (string) ($components['administrative_area_level_1'] ?? ''),
            'country'       => (string) ($components['country'] ?? ''),
            'phone'         => (string) ($raw['nationalPhoneNumber'] ?? $raw['formatted_phone_number'] ?? ''),
            'phoneIntl'     => (string) ($raw['internationalPhoneNumber'] ?? ''),
            'lat'           => $lat !== null ? (float) $lat : null,
            'lng'           => $lng !== null ? (float) $lng : null,
            'website'       => (string) ($raw['websiteUri'] ?? $raw['website'] ?? ''),
            'category'      => $category,
            'rating'        => isset($raw['rating']) ? (float) $raw['rating'] : null,
            'reviews'       => isset($raw['userRatingCount']) ? (int) $raw['userRatingCount'] : null,
            'hours'         => $hours,
            'types'         => !empty($raw['types']) && is_array($raw['types']) ? array_values(array_map('strval', $raw['types'])) : array(),
            'description'   => $desc,
            'mapsShareUrl'  => $maps_uri,
            'cid'           => $cid,
            'mapsEmbedUrl'  => $cid !== '' ? 'https://maps.google.com/maps?cid=' . $cid . '&output=embed' : '',
            'publicReviews' => $public_reviews,
        ), static fn($v) => $v !== '' && $v !== null && $v !== array());
    }

    /**
     * Apify crawler-google-places item → THE SAME normalized record shape
     * (gap 1f38238) + the schema ids the direct API never gave: kgid (from
     * kgmid), fid. Defensive mapping; empty fields never land (filter law).
     * Pure — harness-pinned.
     *
     * @param array $raw One dataset item.
     * @return array Normalized record.
     */
    public static function normalize_apify(array $raw): array
    {
        $hours = '';
        if (!empty($raw['openingHours']) && is_array($raw['openingHours'])) {
            $lines = array();
            foreach ($raw['openingHours'] as $h) {
                if (is_array($h) && isset($h['day'])) {
                    $lines[] = (string) $h['day'] . ': ' . (string) ($h['hours'] ?? '');
                }
            }
            $hours = implode("\n", $lines);
        }
        $cap = 20;
        if (class_exists('PCM_SEO_GBP_Apify_Provider')) {
            $cap = max(0, (int) (PCM_SEO_GBP_Apify_Provider::tunables()['storedReviewsCap'] ?? 20));
        }
        $public_reviews = array();
        foreach (array_slice((array) ($raw['reviews'] ?? array()), 0, $cap) as $rv) {
            if (!is_array($rv)) {
                continue;
            }
            $text = (string) ($rv['text'] ?? ($rv['textTranslated'] ?? ''));
            if ($text === '') {
                continue;
            }
            $public_reviews[] = array(
                'author' => (string) ($rv['name'] ?? ''),
                'rating' => isset($rv['stars']) ? (float) $rv['stars'] : null,
                'text'   => $text,
                'time'   => (string) ($rv['publishedAtDate'] ?? ''),
            );
        }
        $cid = (string) ($raw['cid'] ?? '');
        return array_filter(array(
            'place_id'      => (string) ($raw['placeId'] ?? ''),
            'name'          => (string) ($raw['title'] ?? ''),
            'address'       => (string) ($raw['address'] ?? ''),
            'street'        => (string) ($raw['street'] ?? ''),
            'postal'        => (string) ($raw['postalCode'] ?? ''),
            'city'          => (string) ($raw['city'] ?? ''),
            'region'        => (string) ($raw['state'] ?? ''),
            'country'       => (string) ($raw['countryCode'] ?? ''),
            'phone'         => (string) ($raw['phone'] ?? ($raw['phoneUnformatted'] ?? '')),
            'lat'           => isset($raw['location']['lat']) ? (float) $raw['location']['lat'] : null,
            'lng'           => isset($raw['location']['lng']) ? (float) $raw['location']['lng'] : null,
            'website'       => (string) ($raw['website'] ?? ''),
            'category'      => (string) ($raw['categoryName'] ?? ''),
            'rating'        => isset($raw['totalScore']) ? (float) $raw['totalScore'] : null,
            'reviews'       => isset($raw['reviewsCount']) ? (int) $raw['reviewsCount'] : null,
            'hours'         => $hours,
            'types'         => !empty($raw['categories']) && is_array($raw['categories']) ? array_values(array_map('strval', $raw['categories'])) : array(),
            'description'   => (string) ($raw['description'] ?? ''),
            'mapsShareUrl'  => (string) ($raw['url'] ?? ''),
            'cid'           => $cid,
            'fid'           => (string) ($raw['fid'] ?? ''),
            'kgid'          => (string) ($raw['kgmid'] ?? ''),
            'mapsEmbedUrl'  => $cid !== '' ? 'https://maps.google.com/maps?cid=' . $cid . '&output=embed' : '',
            'publicReviews' => $public_reviews,
        ), static fn($v) => $v !== '' && $v !== null && $v !== array());
    }

    /**
     * Share-URL → place id (Google Native Phase A, gap 670d0e0). Follows the
     * short link's redirects manually (max 5), then extracts the ChIJ place
     * id from the long URL; falls back to the /maps/place/{name}/ segment →
     * ONE searchText call → the best match. Named errors — never a guess
     * presented as a fact.
     *
     * @param string $url     The pasted share URL (already host-validated by parse_maps_url callers).
     * @param int    $user_id Key owner for the fallback search.
     * @return string|\WP_Error The place id.
     */
    public static function resolve_share_url(string $url, int $user_id)
    {
        $current = $url;
        for ($hop = 0; $hop < 5; $hop++) {
            if (preg_match('/(ChIJ[0-9A-Za-z_-]{10,})/', $current, $m)) {
                return $m[1];
            }
            $res = wp_remote_get($current, array('timeout' => 15, 'redirection' => 0, 'headers' => array('User-Agent' => 'Mozilla/5.0 (compatible; PowerCreatives)')));
            if (is_wp_error($res)) {
                return new WP_Error('pcm_seo_maps_resolve', sprintf(
                    /* translators: %s: transport error */
                    __('Could not follow the Maps link: %s', 'power-creatives'),
                    $res->get_error_message()
                ), array('status' => 502));
            }
            $location = (string) wp_remote_retrieve_header($res, 'location');
            if ($location === '') {
                break; // final URL reached
            }
            $current = $location;
        }
        if (preg_match('/(ChIJ[0-9A-Za-z_-]{10,})/', $current, $m)) {
            return $m[1];
        }
        // Fallback: the place NAME from the long URL → one search.
        if (preg_match('#/maps/place/([^/@]+)#', $current, $m)) {
            $name    = trim(rawurldecode(str_replace('+', ' ', $m[1])));
            $results = self::provider($user_id)->search($name, self::default_lang());
            if (isset($results['error'])) {
                return new WP_Error('pcm_seo_gbp_error', (string) $results['error'], array('status' => 502));
            }
            $pid = (string) ($results[0]['id'] ?? '');
            if ($pid !== '') {
                return $pid;
            }
        }
        return new WP_Error('pcm_seo_maps_no_place', __('No Google place could be identified from that link — search by name in the Business panel instead.', 'power-creatives'), array('status' => 404));
    }

    // ── Storage FACADE (Business Spine P1, gap 1aedf65): the record lives in
    //    the brand's PRIMARY business unit (wp_pcm_brand_business_units, owned
    //    by PCM_Brands_Service — the old pcm_seo_gbp_{brandId} options were
    //    migrated there in v1.43.0). These three methods keep their EXACT
    //    former signatures and reply shapes so every consumer — keywords lane
    //    included — reads/writes unchanged. gbp ↔ the unit's `fetched` layer,
    //    overrides ↔ its `manual` layer; manual always wins, survives refresh. ──

    /** Stored business record for a brand: GBP snapshot + manual overrides merged. */
    public static function get_for_brand(int $brand_id): array
    {
        $unit = PCM_Brands_Service::get_business_record($brand_id);
        return array(
            'gbp'       => (array) $unit['fetched'],
            'overrides' => (array) $unit['manual'],
            'resolved'  => (array) $unit['resolved'],
        );
    }

    /** Save the GBP snapshot for a brand (keeps existing manual overrides). */
    public static function save_snapshot(int $brand_id, array $normalized): array
    {
        $unit = PCM_Brands_Service::get_business_record($brand_id);
        PCM_Brands_Service::save_business_unit($brand_id, array('fetched' => $normalized, 'sourceTag' => 'gbp'), (int) $unit['unitId']);
        return self::get_for_brand($brand_id);
    }

    /** Save manual field overrides for a brand (survive GBP refresh). */
    public static function save_overrides(int $brand_id, array $overrides): array
    {
        $allowed = array('name', 'address', 'phone', 'website', 'category', 'description', 'lat', 'lng', 'hours');
        $clean = array();
        foreach ($overrides as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $clean[$k] = is_string($v) ? sanitize_text_field($v) : $v;
            }
        }
        $unit = PCM_Brands_Service::get_business_record($brand_id);
        PCM_Brands_Service::save_business_unit($brand_id, array('manual' => $clean), (int) $unit['unitId']);
        return self::get_for_brand($brand_id);
    }
}
