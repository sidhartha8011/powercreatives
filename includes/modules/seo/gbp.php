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
 * n8n-webhook provider. Posts {action, ...} to the configured webhook (the
 * same contract the source plugin used) and returns the raw Places payload.
 */
class PCM_SEO_GBP_N8N_Provider implements PCM_SEO_GBP_Provider
{
    private const FIELDS = 'places.id,places.displayName,places.formattedAddress,places.location,places.nationalPhoneNumber,places.websiteUri,places.types,places.rating,places.userRatingCount,places.editorialSummary,places.regularOpeningHours,places.primaryTypeDisplayName';

    private function call(string $action, array $data): array
    {
        $webhook = (string) PCM_Settings::get('seo_gbp_webhook', '');
        if ($webhook === '') {
            return array('error' => __('GBP webhook not configured (Settings → SEO).', 'power-creatives'));
        }
        $payload = array_merge(array('action' => $action, 'source' => 'power_creatives'), $data);
        $secret  = (string) PCM_Settings::get('seo_gbp_secret', '');
        $headers = array('Content-Type' => 'application/json');
        if ($secret !== '') {
            // Optional shared-secret header — set a matching check on the n8n
            // side to stop anyone with the URL from spending your Google quota.
            $headers['X-PCM-Secret'] = $secret;
        }
        $res = wp_remote_post($webhook, array('timeout' => 20, 'headers' => $headers, 'body' => wp_json_encode($payload)));
        if (is_wp_error($res)) {
            return array('error' => $res->get_error_message());
        }
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        return is_array($body) ? $body : array();
    }

    public function search(string $query, string $language_code): array
    {
        $body = $this->call('search_places', array('business_name' => $query, 'input_type' => 'textquery', 'language_code' => $language_code, 'fields' => self::FIELDS));
        if (isset($body['error'])) {
            return $body;
        }
        // n8n may wrap as {success,data} or return {places:[...]} directly.
        $places = $body['places'] ?? ($body['data']['places'] ?? ($body['data'] ?? array()));
        return is_array($places) ? array_values($places) : array();
    }

    public function details(string $place_id, string $language_code): array
    {
        $body = $this->call('get_business_details', array('place_id' => $place_id, 'language_code' => $language_code, 'fields' => 'id,displayName,formattedAddress,location,nationalPhoneNumber,websiteUri,regularOpeningHours,rating,userRatingCount,types,primaryTypeDisplayName,editorialSummary'));
        if (isset($body['error'])) {
            return $body;
        }
        $place = $body['place'] ?? ($body['data'] ?? $body);
        if (isset($place['places'][0])) {
            $place = $place['places'][0];
        }
        return is_array($place) ? $place : array();
    }
}

class PCM_SEO_GBP
{
    /** Registered providers by id. Add 'google' here later. */
    public static function providers(): array
    {
        $map = array('n8n' => PCM_SEO_GBP_N8N_Provider::class);
        return apply_filters('pcm_seo_gbp_providers', $map);
    }

    /** The configured provider instance (defaults to n8n). */
    public static function provider(): PCM_SEO_GBP_Provider
    {
        $id  = (string) PCM_Settings::get('seo_gbp_provider', 'n8n');
        $map = self::providers();
        $class = $map[$id] ?? PCM_SEO_GBP_N8N_Provider::class;
        return new $class();
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
        return array(
            'place_id'      => (string) ($raw['id'] ?? $raw['place_id'] ?? $raw['placeId'] ?? ''),
            'name'          => $name,
            'address'       => (string) ($raw['formattedAddress'] ?? $raw['formatted_address'] ?? ''),
            'phone'         => (string) ($raw['nationalPhoneNumber'] ?? $raw['formatted_phone_number'] ?? ''),
            'lat'           => $lat !== null ? (float) $lat : null,
            'lng'           => $lng !== null ? (float) $lng : null,
            'website'       => (string) ($raw['websiteUri'] ?? $raw['website'] ?? ''),
            'category'      => $category,
            'rating'        => isset($raw['rating']) ? (float) $raw['rating'] : null,
            'reviews'       => isset($raw['userRatingCount']) ? (int) $raw['userRatingCount'] : null,
            'hours'         => $hours,
            'types'         => !empty($raw['types']) && is_array($raw['types']) ? array_values(array_map('strval', $raw['types'])) : array(),
            'description'   => $desc,
        );
    }

    // ── Per-brand storage (option, no schema change) + manual overrides ──

    private static function option_key(int $brand_id): string
    {
        return 'pcm_seo_gbp_' . $brand_id;
    }

    /** Stored business record for a brand: GBP snapshot + manual overrides merged. */
    public static function get_for_brand(int $brand_id): array
    {
        $stored = get_option(self::option_key($brand_id), array());
        if (!is_array($stored)) {
            $stored = array();
        }
        $gbp       = is_array($stored['gbp'] ?? null) ? $stored['gbp'] : array();
        $overrides = is_array($stored['overrides'] ?? null) ? $stored['overrides'] : array();
        // Overrides win, then GBP snapshot.
        return array(
            'gbp'      => $gbp,
            'overrides' => $overrides,
            'resolved' => array_merge($gbp, array_filter($overrides, static fn($v) => $v !== '' && $v !== null)),
        );
    }

    /** Save the GBP snapshot for a brand (keeps existing manual overrides). */
    public static function save_snapshot(int $brand_id, array $normalized): array
    {
        $stored = get_option(self::option_key($brand_id), array());
        $stored = is_array($stored) ? $stored : array();
        $stored['gbp'] = $normalized;
        update_option(self::option_key($brand_id), $stored, false);
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
        $stored = get_option(self::option_key($brand_id), array());
        $stored = is_array($stored) ? $stored : array();
        $stored['overrides'] = $clean;
        update_option(self::option_key($brand_id), $stored, false);
        return self::get_for_brand($brand_id);
    }
}
