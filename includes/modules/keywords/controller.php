<?php
/**
 * Keyword Explorer REST Controller
 *
 * Endpoints:
 *   POST /keywords/search   → Google Autocomplete suggestions
 *   POST /keywords/enrich   → Ahrefs keyword metrics enrichment
 *   GET  /keywords/lists    → List saved keyword lists
 *   POST /keywords/lists    → Save a keyword list
 *   DELETE /keywords/lists/<id> → Delete a saved keyword list
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Keywords extends PCM_REST_Base
{
    // Work module — usable by non-admin team members (assigned access).
    protected string $default_capability = 'edit_posts';
    // Per-delivery module grant ids (see PCM_REST_Base::$module_grant_keys).
    protected array $module_grant_keys = array('keywords');


    /**
     * Define all keyword explorer routes.
     *
     * @return array
     */
    protected function routes(): array
    {
        return [
            // Prefix configuration for the selected language
            ['GET',  '/keywords/prefixes', 'get_prefixes'],

            // Google Autocomplete proxy — single query (frontend batches)
            ['POST', '/keywords/search', 'search_keywords'],

            // Ahrefs enrichment (search volume, KD, CPC)
            ['POST', '/keywords/enrich', 'enrich_keywords'],

            // Saved keyword lists — CRUD
            ['GET',    '/keywords/lists', 'list_saved'],
            ['POST',   '/keywords/lists', 'save_list'],
            ['GET',    '/keywords/lists/(?P<id>[\\w]+)', 'load_list'],
            ['DELETE', '/keywords/lists/(?P<id>[\\w]+)', 'delete_list'],
        ];
    }

    /**
     * POST /keywords/search — Proxy Google Autocomplete.
     *
     * Input: { query: string, lang?: string, gl?: string }
     * Returns: string[] of suggestions.
     *
     * @param WP_REST_Request $request Request with query, lang, gl.
     * @return WP_REST_Response|WP_Error
     */
    /**
     * GET /keywords/prefixes — Return prefix list for a language.
     *
     * Frontend calls this once to know which prefixes to iterate.
     * Single source of truth — no hardcoded prefixes on frontend.
     *
     * @param WP_REST_Request $request Request with ?lang= param.
     * @return WP_REST_Response
     */
    public function get_prefixes(WP_REST_Request $request): WP_REST_Response
    {
        $lang = sanitize_text_field($request->get_param('lang') ?? 'en');

        return $this->success([
            'lang'     => $lang,
            'country'  => PCM_Keywords_Service::get_country($lang),
            'prefixes' => PCM_Keywords_Service::get_prefixes($lang),
        ]);
    }

    /**
     * POST /keywords/search — Proxy single Google Autocomplete query.
     *
     * Frontend calls this once per prefix for progressive loading.
     * Input: { query: string, lang?: string, gl?: string }
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function search_keywords(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $query = sanitize_text_field($request->get_param('query'));

        if (empty($query)) {
            return $this->error('Query parameter is required.');
        }

        $lang = sanitize_text_field($request->get_param('lang') ?? 'en');
        $gl   = sanitize_text_field($request->get_param('gl') ?? '');

        // Single query proxy — frontend handles batching for progressive UX
        $suggestions = PCM_Keywords_Service::google_suggest($query, $lang, $gl);
        if (is_wp_error($suggestions)) {
            // An unreachable Google is an ERROR, never an empty result
            // (proven live 2026-07-14: timeouts read as "zero results").
            return $this->error($suggestions->get_error_message(), 502);
        }

        return $this->success([
            'query'       => $query,
            'suggestions' => $suggestions,
            'count'       => count($suggestions),
        ]);
    }

    /**
     * POST /keywords/enrich — Enrich keywords with Ahrefs metrics.
     *
     * Input: { keywords: string[], country?: string }
     * Requires an active 'ahrefs' integration with API key.
     *
     * @param WP_REST_Request $request Request with keywords array.
     * @return WP_REST_Response|WP_Error
     */
    public function enrich_keywords(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user             = $this->get_current_pcm_user();
        $keywords         = $request->get_param('keywords');
        $country          = sanitize_text_field($request->get_param('country') ?? 'us');
        $include_serp_dr  = (bool) $request->get_param('includeSerpDR');

        if (empty($keywords) || !is_array($keywords)) {
            return $this->error('Keywords array is required.');
        }

        // Retrieve Ahrefs API key from Integrations module
        try {
            $api_key = $this->get_provider_api_key('ahrefs', $user->id);
        } catch (\RuntimeException $e) {
            return $this->error(
                'No Ahrefs API key found. Add it in Integrations → Ahrefs.',
                400,
                'pcm_missing_api_key'
            );
        }

        $sanitized = array_map('sanitize_text_field', $keywords);

        // Phase 1: Volume/KD/CPC enrichment (batch — fast)
        $enriched = PCM_Keywords_Service::ahrefs_enrich($sanitized, $api_key, $country);

        // Phase 2: SERP DR enrichment (per-keyword — slower, opt-in)
        $serp_data = [];
        if ($include_serp_dr) {
            $serp_data = PCM_Keywords_Service::ahrefs_serp_dr($sanitized, $api_key, $country);
        }

        return $this->success([
            'enriched' => $enriched,
            'serpDR'   => $serp_data,
            'count'    => count($enriched),
        ]);
    }

    /**
     * GET /keywords/lists — List saved keyword lists for current user.
     *
     * Returns lightweight metadata only (no full data blob).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function list_saved(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->get_current_pcm_user();

        $lists = get_user_meta($user->id, 'pcm_keyword_lists', true);
        if (!is_array($lists)) {
            return $this->success([]);
        }

        // Return metadata only — strip heavy 'data' field for listing
        $lightweight = array_map(function ($list) {
            return [
                'id'         => $list['id'] ?? '',
                'name'       => $list['name'] ?? '',
                'seed'       => $list['seed'] ?? '',
                'lang'       => $list['lang'] ?? '',
                'totalCount' => $list['totalCount'] ?? 0,
                'createdAt'  => $list['createdAt'] ?? '',
            ];
        }, $lists);

        return $this->success(array_values($lightweight));
    }

    /**
     * POST /keywords/lists — Save a keyword list (full snapshot).
     *
     * Input: { name: string, seed: string, lang: string, data: object, totalCount: number }
     * Data contains the full groupedResults with enrichment + SERP data.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function save_list(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user       = $this->get_current_pcm_user();
        $name       = sanitize_text_field($request->get_param('name'));
        $seed       = sanitize_text_field($request->get_param('seed') ?? '');
        $lang       = sanitize_text_field($request->get_param('lang') ?? '');
        $projectId  = (int) ($request->get_param('projectId') ?? 0);
        $data       = $request->get_param('data');
        $totalCount = (int) ($request->get_param('totalCount') ?? 0);

        if (empty($name) || empty($data)) {
            return $this->error('Name and data are required.');
        }

        // Retrieve existing lists
        $lists = get_user_meta($user->id, 'pcm_keyword_lists', true);
        if (!is_array($lists)) {
            $lists = [];
        }

        // Create new list entry with full snapshot
        $new_list = [
            'id'         => time() . '_' . wp_rand(1000, 9999),
            'name'       => $name,
            'seed'       => $seed,
            'lang'       => $lang,
            'projectId'  => $projectId,
            'totalCount' => $totalCount,
            'data'       => $data,
            'createdAt'  => gmdate('c'),
        ];

        $lists[] = $new_list;
        update_user_meta($user->id, 'pcm_keyword_lists', $lists);

        // Return without full data blob
        unset($new_list['data']);
        return $this->success($new_list, 201);
    }

    /**
     * GET /keywords/lists/<id> — Load a single saved keyword list's full data.
     *
     * @param WP_REST_Request $request Request with list id.
     * @return WP_REST_Response|WP_Error
     */
    public function load_list(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id   = sanitize_text_field($request->get_param('id'));

        $lists = get_user_meta($user->id, 'pcm_keyword_lists', true);
        if (!is_array($lists)) {
            return $this->not_found('Keyword list');
        }

        $found = null;
        foreach ($lists as $list) {
            if (($list['id'] ?? '') === $id) {
                $found = $list;
                break;
            }
        }

        if (!$found) {
            return $this->not_found('Keyword list');
        }

        return $this->success($found);
    }

    /**
     * DELETE /keywords/lists/<id> — Delete a saved keyword list.
     *
     * @param WP_REST_Request $request Request with list id.
     * @return WP_REST_Response|WP_Error
     */
    public function delete_list(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id   = sanitize_text_field($request->get_param('id'));

        $lists = get_user_meta($user->id, 'pcm_keyword_lists', true);
        if (!is_array($lists)) {
            return $this->not_found('Keyword list');
        }

        $lists = array_values(array_filter($lists, fn($l) => ($l['id'] ?? '') !== $id));
        update_user_meta($user->id, 'pcm_keyword_lists', $lists);

        return $this->success(['success' => true]);
    }
}
