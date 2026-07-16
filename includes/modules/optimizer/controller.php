<?php
/**
 * Optimizer REST Controller
 *
 * Endpoints:
 *   GET  /optimizer/teachers → the teacher registry (id, label, order)
 *   POST /optimizer/analyze  → run ONE teacher against the page content
 *
 * Analyze takes a single teacherId by design: the rail's per-purpose
 * re-analyze button IS this endpoint — never a separate code path.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Optimizer extends PCM_REST_Base
{
    // Work module — same bar as the SEO editor it lives inside.
    protected string $default_capability = 'edit_posts';

    /**
     * Define optimizer routes.
     *
     * @return array
     */
    protected function routes(): array
    {
        return [
            ['GET',  '/optimizer/teachers', 'list_teachers'],
            ['POST', '/optimizer/analyze', 'analyze'],
            ['POST', '/optimizer/compile', 'compile'],
            // The keyword drawer: GSC per-query stats + the page's bucket.
            ['POST', '/optimizer/keywords/stats', 'keyword_stats'],
            ['GET',  '/optimizer/keywords', 'get_keywords'],
            ['POST', '/optimizer/keywords', 'save_keywords'],
            ['POST', '/optimizer/keywords/volumes', 'keyword_volumes'],
            // THE RESULTS LOOP (gap e8fcae5 D5): stamp + read back.
            ['POST', '/optimizer/history', 'history_stamp'],
            ['GET',  '/optimizer/history', 'history_get'],
        ];
    }

    /**
     * POST /optimizer/history — stamp an optimization event (review ended
     * with accepted sections). Input: { siteId, postId, purposes: string[] }.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function history_stamp(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $p       = $request->get_json_params();
        $site_id = is_array($p) ? absint($p['siteId'] ?? 0) : 0;
        $post_id = is_array($p) ? absint($p['postId'] ?? 0) : 0;
        if ($site_id === 0 || $post_id === 0) {
            return $this->error('siteId and postId are required.');
        }
        $purposes = array();
        foreach ((is_array($p) && is_array($p['purposes'] ?? null)) ? $p['purposes'] : array() as $purpose) {
            $clean = sanitize_key((string) $purpose);
            if ($clean !== '') {
                $purposes[] = $clean;
            }
        }
        PCM_Optimizer_Service::history_stamp($site_id, $post_id, $purposes);
        return $this->success(array('stamped' => true));
    }

    /**
     * GET /optimizer/history?siteId&postId — the last optimization event +
     * the then-vs-now stored-GSC summary (null when never optimized).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function history_get(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(array(
            'event' => PCM_Optimizer_Service::history_get(absint($request->get_param('siteId')), absint($request->get_param('postId'))),
        ));
    }

    /**
     * POST /optimizer/keywords/volumes — search volumes for a keyword set.
     *
     * Input:  { keywords: string[] (capped 20), siteId, contentSample? }
     * Output: { volumes: {kw: int|null}, hasKey: bool, country: string }
     *
     * The market comes from THE SMART COUNTRY CHAIN (site's brand → AI
     * language check on contentSample → hub default; gap 2cf0a44).
     * Cache-first (30-day option map, country-keyed); misses batch through
     * the keyword engine's Ahrefs enrichment. No Ahrefs key → volumes come
     * back null with hasKey=false — the drawer shows dashes, keywords stay
     * usable.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function keyword_volumes(WP_REST_Request $request): WP_REST_Response
    {
        $pcm_user = $this->get_current_pcm_user();
        $p        = $request->get_json_params();
        $keywords = array();
        foreach ((is_array($p) && is_array($p['keywords'] ?? null)) ? $p['keywords'] : array() as $kw) {
            $clean = sanitize_text_field((string) $kw);
            if ($clean !== '' && !in_array($clean, $keywords, true)) {
                $keywords[] = $clean;
            }
        }
        $keywords    = array_slice($keywords, 0, 20);
        $refresh     = is_array($p) && !empty($p['refresh']);
        $cached_only = is_array($p) && !empty($p['cachedOnly']);
        if (empty($keywords)) {
            return $this->success(array('volumes' => (object) array(), 'hasKey' => true));
        }

        $site_id = is_array($p) ? absint($p['siteId'] ?? 0) : 0;
        $site    = $site_id > 0 ? PCM_DB::get_site($site_id, (int) $pcm_user->id) : null;
        $sample  = is_array($p) ? sanitize_textarea_field((string) ($p['contentSample'] ?? '')) : '';
        $country = $site
            ? PCM_Keywords_Service::resolve_country($site, $sample)
            : PCM_Keywords_Service::default_country();

        $result = PCM_Optimizer_Service::keyword_volumes($keywords, $country, function (array $missing) use ($pcm_user, $country): ?array {
            try {
                $key = $this->get_provider_api_key('ahrefs', (int) $pcm_user->id);
            } catch (\RuntimeException $e) {
                return null; // no key — honest dashes, nothing cached
            }
            try {
                $enriched = PCM_Keywords_Service::ahrefs_enrich($missing, $key, $country);
            } catch (\Throwable $e) {
                error_log('[PCM_Optimizer] volume enrichment failed: ' . $e->getMessage());
                return null; // transient failure — never poison the cache
            }
            $out = array();
            foreach ($missing as $kw) {
                $out[$kw] = isset($enriched[$kw]['volume']) ? (int) $enriched[$kw]['volume'] : null;
            }
            return $out;
        }, $refresh, $cached_only);

        $result['country'] = $country;
        return $this->success($result);
    }

    /**
     * POST /optimizer/keywords/stats — the queries ONE page is seen for.
     *
     * Input:  { pageUrl, days? }
     * Output: { property, rows: [{query, clicks, impressions, position}] }
     *
     * Mirrors the proven integrations GSC flow: match the properties the
     * service account can read, try up to 3 candidates, keep the first
     * WITH data (www/non-www/sc-domain twins — the documented trap).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function keyword_stats(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $p        = $request->get_json_params();
        $site_id  = is_array($p) ? absint($p['siteId'] ?? 0) : 0;
        $post_id  = is_array($p) ? absint($p['postId'] ?? 0) : 0;
        $page_url = is_array($p) ? esc_url_raw(trim((string) ($p['pageUrl'] ?? ''))) : '';
        $days     = is_array($p) ? max(1, min(180, (int) ($p['days'] ?? 30))) : 30;
        $compare  = is_array($p) && !empty($p['compare']);
        if ($page_url === '') {
            return $this->error('pageUrl is required.');
        }

        $live = $this->fetch_live_keyword_stats($page_url, $days, $compare);
        if (!is_wp_error($live)) {
            // Success feeds the store — the drawer opens instantly next time
            // and stays useful when Google is unreachable.
            PCM_Optimizer_Service::kw_stats_cache_save($site_id, $post_id, $live['property'], $live['rows'], array('days' => $days, 'compare' => $compare));
            return $this->success(array(
                'source'    => 'live',
                'property'  => $live['property'],
                'rows'      => $live['rows'],
                'fetchedAt' => time(),
            ));
        }

        // Live failed (no integration / Google error): serve the STORED rows
        // when they exist — always labeled, never passed off as live. No
        // store either → the live error stands, honestly.
        $stored = PCM_Optimizer_Service::kw_stats_cache_get($site_id, $post_id);
        if ($stored !== null) {
            return $this->success(array(
                'source'    => 'stored',
                'property'  => (string) ($stored['property'] ?? ''),
                'rows'      => $stored['rows'],
                'fetchedAt' => (int) ($stored['fetchedAt'] ?? 0),
            ));
        }
        return $this->error($live->get_error_message(), (int) ($live->get_error_data()['status'] ?? 502));
    }

    /**
     * One live GSC per-query fetch for one page — the proven candidate-try
     * flow (www/non-www/sc-domain twins), every failure as WP_Error. With
     * $compare the PREVIOUS period (same length, same property) is fetched
     * too and the rows come back MERGED with per-keyword deltas; a failing
     * compare fetch is an honest error, never a silently plain result.
     *
     * @param string $page_url The page to filter on.
     * @param int    $days     Look-back window.
     * @param bool   $compare  Also fetch the previous period and merge.
     * @return array{property: string, rows: array}|WP_Error
     */
    private function fetch_live_keyword_stats(string $page_url, int $days, bool $compare = false): array|WP_Error
    {
        $pcm_user = $this->get_current_pcm_user();
        try {
            $key = $this->get_provider_api_key('gsc', (int) $pcm_user->id);
        } catch (\RuntimeException $e) {
            return new WP_Error('pcm_no_gsc', 'No active Google Search Console integration found — add one on the Integrations page.', array('status' => 400));
        }
        $props = PCM_GSC::list_properties($key);
        if (is_wp_error($props)) {
            return $props;
        }
        $candidates = PCM_GSC::match_properties($props, $page_url);
        if (empty($candidates)) {
            return new WP_Error('pcm_no_gsc_property', 'The GSC service account has no access to a property for this page\'s site.', array('status' => 404));
        }

        $rows     = null;
        $property = $candidates[0];
        foreach (array_slice($candidates, 0, 3) as $prop) {
            $r = PCM_GSC::query_stats($key, $prop, $days, $page_url);
            if (is_wp_error($r)) {
                $rows = $rows ?? $r;
                continue;
            }
            if (!empty($r)) {
                $property = $prop;
                $rows     = $r;
                break;
            }
            if ($rows === null || is_wp_error($rows)) {
                $property = $prop;
                $rows     = $r;
            }
        }
        if ($rows === null || is_wp_error($rows)) {
            return is_wp_error($rows) ? $rows : new WP_Error('pcm_gsc_empty', 'Search Console returned no result.', array('status' => 502));
        }
        if ($compare) {
            $previous = PCM_GSC::query_stats($key, $property, $days, $page_url, $days);
            if (is_wp_error($previous)) {
                return new WP_Error('pcm_gsc_compare', sprintf('The compare period could not be read: %s', $previous->get_error_message()), array('status' => 502));
            }
            $rows = PCM_Optimizer_Service::merge_compare($rows, $previous);
        }
        return array('property' => $property, 'rows' => $rows);
    }

    /**
     * GET /optimizer/keywords?siteId&postId — the page's keyword bucket.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_keywords(WP_REST_Request $request): WP_REST_Response
    {
        $site_id = absint($request->get_param('siteId'));
        $post_id = absint($request->get_param('postId'));
        return $this->success(array('keywords' => PCM_Optimizer_Service::bucket_get($site_id, $post_id)));
    }

    /**
     * POST /optimizer/keywords — save the page's keyword bucket.
     *
     * Input: { siteId, postId, keywords: string[] }
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function save_keywords(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $p       = $request->get_json_params();
        $site_id = is_array($p) ? absint($p['siteId'] ?? 0) : 0;
        $post_id = is_array($p) ? absint($p['postId'] ?? 0) : 0;
        if ($site_id === 0 || $post_id === 0) {
            return $this->error('siteId and postId are required.');
        }
        $keywords = array();
        foreach ((is_array($p) && is_array($p['keywords'] ?? null)) ? $p['keywords'] : array() as $kw) {
            $clean = sanitize_text_field((string) $kw);
            if ($clean !== '' && !in_array($clean, $keywords, true)) {
                $keywords[] = $clean;
            }
        }
        PCM_Optimizer_Service::bucket_save($site_id, $post_id, array_slice($keywords, 0, 50));
        return $this->success(array('keywords' => PCM_Optimizer_Service::bucket_get($site_id, $post_id)));
    }

    /**
     * POST /optimizer/compile — THE BASKET COMPILER (one spine stage).
     *
     * Input:  { items: [{instruction, teacherId, label}], model?, provider? }
     * Output: { directives: [{text, purposes: string[], sources: int[]}] }
     *
     * Merges the ticked suggestions into one concise, ordered to-do list.
     * The service enforces the certainty contract: every input item must be
     * covered by the output — a dropped intent is an honest error.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function compile(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $p     = $request->get_json_params();
        $items = array();
        foreach ((is_array($p) && is_array($p['items'] ?? null)) ? $p['items'] : array() as $it) {
            if (!is_array($it)) {
                continue;
            }
            $instruction = sanitize_text_field((string) ($it['instruction'] ?? ''));
            $teacher_id  = sanitize_key((string) ($it['teacherId'] ?? ''));
            if ($instruction !== '' && $teacher_id !== '') {
                $items[] = array(
                    'instruction' => $instruction,
                    'teacherId'   => $teacher_id,
                    'label'       => sanitize_text_field((string) ($it['label'] ?? '')),
                );
            }
        }
        if (empty($items)) {
            return $this->error('There are no selected suggestions to compile.');
        }

        try {
            // The same context package the teachers analyzed with rides the
            // reconciliation (research spine D6) — merge order respects the
            // keyword hierarchy and real business facts.
            $directives = PCM_Optimizer_Service::compile($items, array(
                'model'    => is_array($p) ? sanitize_text_field((string) ($p['model'] ?? '')) : '',
                'provider' => is_array($p) ? sanitize_key((string) ($p['provider'] ?? '')) : '',
                'userId'   => get_current_user_id(),
                'keywords' => $this->sanitize_keywords(is_array($p) ? ($p['keywords'] ?? null) : null),
                'business' => PCM_Optimizer_Service::business_context(is_array($p) ? (int) ($p['siteId'] ?? 0) : 0),
            ));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 502);
        }

        return $this->success(array('directives' => $directives));
    }

    /**
     * GET /optimizer/teachers — the registry, in rail order.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function list_teachers(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(array('teachers' => PCM_Optimizer_Service::teacher_meta()));
    }

    /**
     * POST /optimizer/analyze — run ONE teacher.
     *
     * Input:  { teacherId, siteId, postId, html, pageType, model?, provider? }
     * Output: { teacherId, items: [{id, teacherId, found, label, evidence, instruction}] }
     *
     * The content ARRIVES from the editor (the live document is the source
     * of truth there); the server contributes checklist data, identity and
     * the LLM plumbing. Failures are honest errors — the rail shows the
     * failed purpose with its own retry, never a fake empty result.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function analyze(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $p          = $request->get_json_params();
        $teacher_id = is_array($p) ? sanitize_key((string) ($p['teacherId'] ?? '')) : '';
        $html       = is_array($p) ? (string) ($p['html'] ?? '') : '';
        if ($teacher_id === '') {
            return $this->error('teacherId is required.');
        }
        if (trim($html) === '') {
            return $this->error('There is no content to analyze.');
        }

        $site_id = is_array($p) ? (int) ($p['siteId'] ?? 0) : 0;
        $post_id = is_array($p) ? (int) ($p['postId'] ?? 0) : 0;

        // THE CONTEXT PACKAGE (research spine D1): keywords arrive from the
        // editor's LIVE state (the same source-of-truth law as the html);
        // the additional list falls back to the stored bucket when the
        // payload sends none (documented — the drawer fills the bucket).
        $keywords = $this->sanitize_keywords(is_array($p) ? ($p['keywords'] ?? null) : null);
        if (empty($keywords['additional'])) {
            $keywords['additional'] = PCM_Optimizer_Service::bucket_get($site_id, $post_id);
        }
        // The business record resolves SERVER-side (site → brand → GBP) —
        // cheap DB reads, never trusted from the client.
        $business = PCM_Optimizer_Service::business_context($site_id);
        $pages    = array();
        foreach ((is_array($p) && is_array($p['pages'] ?? null)) ? array_slice($p['pages'], 0, 200) : array() as $pg) {
            if (!is_array($pg)) {
                continue;
            }
            $permalink = esc_url_raw((string) ($pg['permalink'] ?? ''));
            $title     = sanitize_text_field((string) ($pg['title'] ?? ''));
            if ($permalink !== '' && $title !== '') {
                $pages[] = array('id' => absint($pg['id'] ?? 0), 'title' => $title, 'permalink' => $permalink);
            }
        }

        $context = array(
            'siteId'   => $site_id,
            'postId'   => $post_id,
            'html'     => $html,
            'pageType' => is_array($p) ? sanitize_key((string) ($p['pageType'] ?? 'general')) : 'general',
            'model'    => is_array($p) ? sanitize_text_field((string) ($p['model'] ?? '')) : '',
            'provider' => is_array($p) ? sanitize_key((string) ($p['provider'] ?? '')) : '',
            // The CALLER's identity rides every LLM call (key lookup must
            // never lean on an absent session — the model-truth lesson).
            'userId'   => get_current_user_id(),
            'keywords' => $keywords,
            'business' => $business,
            'pages'    => $pages,
        );

        try {
            $items = PCM_Optimizer_Service::analyze($teacher_id, $context);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 502);
        }

        return $this->success(array(
            'teacherId'   => $teacher_id,
            'items'       => $items,
            // THE PEEK (owner confirmation tool): exactly what this run was
            // given — the rail shows it read-only, nothing hidden.
            'contextUsed' => array(
                'keywords'       => $keywords,
                'businessFields' => array_values(array_filter(array_keys($business), static fn(string $k): bool => is_scalar($business[$k]) && trim((string) $business[$k]) !== '')),
                'businessName'   => (string) ($business['name'] ?? ''),
                'pageType'       => $context['pageType'],
                'model'          => $context['model'],
                'provider'       => $context['provider'],
                'pageCount'      => count($pages),
            ),
        ));
    }

    /**
     * Sanitize the analyze/compile payload's keyword package into the
     * context shape {primary, supporting[], additional[]}.
     *
     * @param mixed $raw The payload's `keywords` value.
     * @return array{primary: string, supporting: string[], additional: string[]}
     */
    private function sanitize_keywords(mixed $raw): array
    {
        $clean = array('primary' => '', 'supporting' => array(), 'additional' => array());
        if (!is_array($raw)) {
            return $clean;
        }
        $clean['primary'] = sanitize_text_field((string) ($raw['primary'] ?? ''));
        foreach (array('supporting', 'additional') as $role) {
            foreach (is_array($raw[$role] ?? null) ? $raw[$role] : array() as $kw) {
                $k = sanitize_text_field((string) $kw);
                if ($k !== '' && !in_array($k, $clean[$role], true)) {
                    $clean[$role][] = $k;
                }
            }
            $clean[$role] = array_slice($clean[$role], 0, 50);
        }
        return $clean;
    }
}
