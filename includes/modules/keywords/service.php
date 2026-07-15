<?php
/**
 * Keyword Explorer Service
 *
 * Handles external API calls for keyword research:
 * - Google Autocomplete (suggestqueries.google.com) — free, no key needed
 * - Ahrefs Keywords Explorer API v3 — requires Enterprise API key
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Keywords_Service
{

    /**
     * Fetch keyword suggestions from Google Autocomplete.
     *
     * Uses the undocumented but widely-used suggestqueries endpoint
     * with client=chrome for clean JSON output.
     *
     * @param string $query Search term.
     * @param string $lang  Language code (e.g. 'en', 'sv').
     * @param string $gl    Country code (e.g. 'us', 'se').
     * @return array|WP_Error Suggestion strings, or the TRANSPORT failure —
     *                        an unreachable Google must never read as "no
     *                        results" (proven live 2026-07-14: timeouts were
     *                        served as empty successes).
     */
    public static function google_suggest(string $query, string $lang = 'en', string $gl = ''): array|WP_Error
    {
        $url = add_query_arg(
            array_filter([
                'client' => 'chrome',
                'q'      => $query,
                'hl'     => $lang,
                'gl'     => $gl ?: null,
            ]),
            'https://suggestqueries.google.com/complete/search'
        );

        $response = wp_remote_get($url, [
            'timeout'    => 5,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        ]);

        if (is_wp_error($response)) {
            error_log('PCM Keywords: Google suggest wp_remote_get failed — ' . $response->get_error_message());
            return new WP_Error(
                'pcm_kw_suggest_unreachable',
                sprintf(
                    /* translators: %s: transport error message */
                    __('Google suggestions could not be reached from this server: %s', 'power-creatives'),
                    $response->get_error_message()
                ),
                ['status' => 502]
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // If json_decode fails, try parsing the JSONP-style response
        if (!is_array($data) && !empty($body)) {
            $json_str = preg_replace('/^[^[]*(\[.+\])[^]]*$/', '$1', $body);
            $data = json_decode($json_str, true);
        }

        // Google chrome-client format: [0]=query, [1]=suggestions[]
        return is_array($data) && isset($data[1]) ? $data[1] : [];
    }

    /**
     * Prefixes by language — exact match from original autopress-intelligence app.
     *
     * Each prefix is prepended to the seed keyword to generate
     * question-based and intent-based variations.
     */
    private const PREFIXES_BY_LANG = [
        'en' => ['what', 'how', 'why', 'which', 'who', 'whose', 'are', 'can', 'when', 'where', 'best', 'better', 'cheap', 'cheaper', 'cheapest', 'budget', 'price', 'cost', 'costs', 'how much'],
        'sv' => ['vad', 'hur', 'varför', 'vilken', 'vilka', 'vilket', 'är', 'kan', 'när', 'var', 'vart', 'bra', 'bästa', 'bättre', 'billig', 'billiga', 'billigaste', 'fördelar', 'budget', 'pris', 'kostnad', 'kostar', 'fungerar', 'vs', 'tips'],
        'de' => ['was', 'wie', 'warum', 'welche', 'welcher', 'wer', 'sind', 'kann', 'wann', 'wo', 'beste', 'besser', 'billig', 'billiger', 'billigste', 'budget', 'preis', 'kosten', 'vs', 'tipps'],
        'no' => ['hva', 'hvordan', 'hvorfor', 'hvilken', 'hvilke', 'er', 'kan', 'når', 'hvor', 'best', 'beste', 'bedre', 'billig', 'billigere', 'billigst', 'budsjett', 'pris', 'kost', 'vs', 'tips'],
    ];

    /**
     * Get prefixes for a given language.
     * Falls back to English if language is not configured.
     *
     * @param string $lang Language code (e.g. 'en', 'sv').
     * @return string[] List of prefix strings.
     */
    public static function get_prefixes(string $lang): array
    {
        return self::PREFIXES_BY_LANG[$lang] ?? self::PREFIXES_BY_LANG['en'];
    }

    /**
     * Language → ISO 3166-1 alpha-2 country code mapping.
     *
     * Ahrefs API requires a two-letter country code (e.g. 'se' for Sweden),
     * but the frontend sends language codes (e.g. 'sv' for Swedish).
     * This mapping resolves the difference centrally so all API calls
     * use the correct country code without frontend changes.
     */
    private const COUNTRY_BY_LANG = [
        'en' => 'us',
        'sv' => 'se',
        'no' => 'no',
        'de' => 'de',
        'fr' => 'fr',
        'es' => 'es',
    ];

    /**
     * Resolve a frontend language code to an Ahrefs-compatible ISO country code.
     *
     * Public: used by controller (prefixes endpoint) to provide the resolved
     * country code to the frontend, eliminating the need for frontend to
     * duplicate this mapping.
     *
     * Falls back to the input value if no mapping exists (covers cases
     * where lang code = country code, e.g. 'de' = 'de').
     *
     * @param string $lang_or_country Language code from frontend (e.g. 'sv').
     * @return string ISO 3166-1 alpha-2 country code (e.g. 'se').
     */
    public static function get_country(string $lang_or_country): string
    {
        $lower = strtolower($lang_or_country);
        return self::COUNTRY_BY_LANG[$lower] ?? $lower;
    }

    /**
     * Extract organic-only results from Ahrefs MCP SERP response.
     *
     * Ahrefs serp-overview returns ALL result types: organic, Local Pack,
     * PAA, featured snippets, sitelinks, knowledge panels, etc.
     * Organic results are identified by having BOTH:
     *   - A non-empty URL (SERP feature headers like PAA have null URL)
     *   - A non-null domain_rating (Local Pack entries, sitelinks,
     *     and other SERP features have null DR even when they have URLs)
     *
     * After filtering, positions are re-numbered sequentially (1,2,3,...)
     * so users see organic ranking without gaps.
     *
     * @param array $content_items Raw MCP content blocks from Ahrefs.
     * @param int   $limit         Maximum organic results to return.
     * @return array Top organic results with sequential positions.
     */
    private static function extract_organic_results(array $content_items, int $limit = 5): array
    {
        $serp_results = [];
        $seen_positions = [];

        foreach ($content_items as $content) {
            if (($content['type'] ?? '') !== 'text') continue;

            $json = json_decode($content['text'] ?? '', true);
            if (!$json) continue;

            $positions = $json['positions'] ?? $json;
            if (!is_array($positions)) continue;

            foreach ($positions as $pos) {
                $url = $pos['url'] ?? null;
                $dr  = $pos['domain_rating'] ?? null;

                // Not an organic result — skip
                if (empty($url) || $dr === null) continue;

                // Already have this position — skip (dedup sitelinks etc.)
                $position = (int) ($pos['position'] ?? 0);
                if (isset($seen_positions[$position])) continue;
                $seen_positions[$position] = true;

                $serp_results[] = [
                    'position'      => $position,
                    'title'         => $pos['title'] ?? '',
                    'url'           => $url,
                    'domain_rating' => (int) $dr,
                    'url_rating'    => (int) ($pos['url_rating'] ?? 0),
                    'traffic'       => (int) ($pos['traffic'] ?? 0),
                ];
            }
        }

        // Sort by original SERP position, take top N, then re-number sequentially
        usort($serp_results, fn($a, $b) => $a['position'] - $b['position']);
        $serp_results = array_slice($serp_results, 0, $limit);
        foreach ($serp_results as $i => &$entry) {
            $entry['position'] = $i + 1;
        }
        unset($entry);

        return $serp_results;
    }

    /**
     * Debug toggle — set to true to log each prefix request to debug.log.
     * Turn off in production.
     */
    private const DEBUG = true;

    private static function log(string $msg): void
    {
        if (self::DEBUG) {
            error_log('[PCM Keywords] ' . $msg);
        }
    }

    /**
     * Batch Google Suggest — runs multiple prefixed searches.
     *
     * @param string $seed  Seed keyword.
     * @param string $lang  Language code (e.g. 'en', 'sv').
     * @param string $gl    Country code (e.g. 'us', 'se').
     * @return array ['groups' => [...], 'total' => int]
     */
    public static function google_suggest_batch(string $seed, string $lang = 'en', string $gl = ''): array
    {
        // Extend PHP execution time for batch (20+ sequential HTTP calls)
        if (function_exists('set_time_limit')) {
            set_time_limit(120);
        }

        $prefixes = self::PREFIXES_BY_LANG[$lang] ?? self::PREFIXES_BY_LANG['en'];
        $groups   = [];
        $seen     = [];
        $total    = 0;

        self::log("Batch start: seed='{$seed}', lang='{$lang}', prefixes=" . count($prefixes));
        $startTime = microtime(true);

        foreach ($prefixes as $i => $prefix) {
            $query       = $prefix . ' ' . $seed;
            $suggestions = self::google_suggest($query, $lang, $gl);
            if (is_wp_error($suggestions)) {
                // The batch tolerates per-prefix failures BY DESIGN (logged
                // by google_suggest) — one dead prefix never kills the run.
                $suggestions = [];
            }

            $newCount = 0;
            if (!empty($suggestions)) {
                $unique = [];
                foreach ($suggestions as $s) {
                    $lower = strtolower($s);
                    if (!isset($seen[$lower])) {
                        $seen[$lower] = true;
                        $unique[]     = $s;
                        $total++;
                        $newCount++;
                    }
                }
                if (!empty($unique)) {
                    $groups[$prefix] = $unique;
                }
            }

            self::log(sprintf(
                '  [%d/%d] "%s" → %d raw, %d unique (total: %d)',
                $i + 1, count($prefixes), $prefix, count($suggestions), $newCount, $total
            ));

            // Polite delay — avoid rate limiting from Google
            usleep(200000); // 200ms
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        self::log("Batch done: {$total} keywords in {$elapsed}s across " . count($groups) . " groups");

        return [
            'groups' => $groups,
            'total'  => $total,
        ];
    }

    /**
     * Enrich keywords with Ahrefs search metrics via MCP JSON-RPC 2.0.
     *
     * Uses the Ahrefs MCP server (https://api.ahrefs.com/mcp/mcp) to call
     * the 'keywords-explorer-overview' tool. This approach works with all
     * paid Ahrefs plans (Lite+), unlike REST v3 which requires Enterprise.
     *
     * PHP port of autopress-intelligence ahrefsService.ts getAhrefsVolumeData().
     *
     * Flow: initialize → tools/list → tools/call (per batch of 50)
     *
     * @param array  $keywords List of keyword strings to enrich.
     * @param string $api_key  Ahrefs API bearer token.
     * @param string $country  Two-letter country code (default 'us').
     * @return array Enriched keyword data keyed by keyword string.
     */
    public static function ahrefs_enrich(array $keywords, string $api_key, string $country = 'us'): array
    {
        if (empty($keywords) || empty($api_key)) {
            return [];
        }

        // Extend PHP timeout for multi-step MCP calls
        if (function_exists('set_time_limit')) {
            set_time_limit(120);
        }

        self::log('Ahrefs MCP: Starting enrichment for ' . count($keywords) . ' keywords');

        try {
            // Step 1: MCP Initialize handshake
            $init_result = self::mcp_call($api_key, 'initialize', [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => [
                    'roots'    => ['listChanged' => true],
                    'sampling' => new \stdClass(),
                ],
                'clientInfo' => [
                    'name'    => 'PowerCreatives',
                    'version' => '1.0.0',
                ],
            ]);

            self::log('Ahrefs MCP: Initialized — server: ' . ($init_result['serverInfo']['name'] ?? 'unknown'));

            // Step 2: Discover available tools
            $tools_result = self::mcp_call($api_key, 'tools/list', new \stdClass());
            $tools = $tools_result['tools'] ?? [];

            // Find the Keywords Explorer Overview tool (matches original pattern)
            $volume_tool = null;
            foreach ($tools as $tool) {
                $name = $tool['name'] ?? '';
                if (
                    $name === 'keywords-explorer-overview' ||
                    $name === 'ahrefs_keywords_explorer_overview' ||
                    (str_contains($name, 'keywords-explorer') && str_contains($name, 'overview'))
                ) {
                    $volume_tool = $name;
                    break;
                }
            }

            if (!$volume_tool) {
                self::log('Ahrefs MCP: keywords-explorer-overview tool not found. Available: ' . implode(', ', array_column($tools, 'name')));
                return [];
            }

            self::log("Ahrefs MCP: Using tool '{$volume_tool}'");

            // Step 3: Call tool in batches of 50 (Ahrefs batch limit, matching original)
            $unique    = array_values(array_unique($keywords));
            $result    = [];
            $batch_size = 50;

            // Ahrefs echoes keywords back LOWERCASED — the result must be keyed
            // by the CALLER'S casing or "Privacy" never matches "privacy" and a
            // false "no data" gets cached (gap 71d3cde). Same transform on both
            // sides, so non-ASCII stays consistent by construction.
            $input_by_lower = [];
            foreach ($unique as $input_kw) {
                $input_by_lower[strtolower($input_kw)] = $input_kw;
            }

            for ($i = 0; $i < count($unique); $i += $batch_size) {
                $batch = array_slice($unique, $i, $batch_size);

                $tool_result = self::mcp_call($api_key, 'tools/call', [
                    'name'      => $volume_tool,
                    'arguments' => [
                        'keywords' => implode(',', $batch),
                        'country'  => strtoupper(self::get_country($country)),
                        'select'   => 'keyword,volume,difficulty',
                    ],
                ]);

                // Parse MCP content blocks (text type contains JSON)
                $content_items = $tool_result['content'] ?? [];
                foreach ($content_items as $content) {
                    if (($content['type'] ?? '') !== 'text') continue;

                    $json = json_decode($content['text'] ?? '', true);
                    if (!$json) continue;

                    // Expected structure: { keywords: [{ keyword, volume, difficulty, cpc }] }
                    $kw_items = $json['keywords'] ?? $json;
                    if (!is_array($kw_items)) continue;

                    foreach ($kw_items as $item) {
                        $kw = $item['keyword'] ?? null;
                        if (!$kw) continue;
                        // Re-key to the input casing (see map above).
                        $kw = $input_by_lower[strtolower((string) $kw)] ?? $kw;

                        $result[$kw] = [
                            'volume'     => $item['volume'] ?? 0,
                            'difficulty' => $item['difficulty'] ?? 0,
                            'cpc'        => $item['cpc'] ?? 0,
                        ];
                    }
                }

                self::log(sprintf(
                    'Ahrefs MCP: Batch %d/%d — %d keywords enriched',
                    floor($i / $batch_size) + 1,
                    ceil(count($unique) / $batch_size),
                    count($batch)
                ));
            }

            self::log('Ahrefs MCP: Done — enriched ' . count($result) . ' / ' . count($unique) . ' keywords');
            return $result;

        } catch (\Exception $e) {
            self::log('Ahrefs MCP: Error — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Send a JSON-RPC 2.0 request to the Ahrefs MCP server.
     *
     * Reusable helper for all MCP interactions (initialize, tools/list, tools/call).
     * Uses HTTP transport with Bearer auth and Accept: application/json.
     *
     * @param string $api_key Ahrefs API bearer token.
     * @param string $method  JSON-RPC method (e.g. 'initialize', 'tools/list', 'tools/call').
     * @param mixed  $params  Method parameters.
     * @return array Parsed JSON-RPC result.
     * @throws \RuntimeException On network or protocol errors.
     */
    private static function mcp_call(string $api_key, string $method, mixed $params): array
    {
        static $request_id = 0;
        $request_id++;

        $payload = [
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => $request_id,
        ];

        $response = wp_remote_post('https://api.ahrefs.com/mcp/mcp', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'Accept'        => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('MCP request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            throw new \RuntimeException("MCP HTTP {$code}: " . wp_remote_retrieve_body($response));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            throw new \RuntimeException('MCP Error ' . ($body['error']['code'] ?? '?') . ': ' . ($body['error']['message'] ?? 'Unknown'));
        }

        return $body['result'] ?? [];
    }

    /**
     * Fetch SERP Domain Rating data for keywords via Ahrefs MCP.
     *
     * Calls MCP `serp-overview` tool once per keyword to retrieve
     * the top 5 ranking pages with their Domain Rating, title, URL, and traffic.
     * Computes avgDR and lowDR from the results.
     *
     * NOTE: This method assumes MCP has already been initialized (via ahrefs_enrich).
     * If called standalone, it performs its own initialization.
     *
     * @param array  $keywords List of keyword strings.
     * @param string $api_key  Ahrefs API bearer token.
     * @param string $country  Two-letter country code.
     * @return array Keyed by keyword → { avgDR, lowDR, serpResults[] }
     */
    public static function ahrefs_serp_dr(array $keywords, string $api_key, string $country = 'us'): array
    {
        if (empty($keywords) || empty($api_key)) {
            return [];
        }

        // Extend PHP timeout — 1 MCP call per keyword
        if (function_exists('set_time_limit')) {
            set_time_limit(300);
        }

        self::log('Ahrefs SERP DR: Starting for ' . count($keywords) . ' keywords');

        try {
            // Step 1: MCP Initialize handshake
            self::mcp_call($api_key, 'initialize', [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => [
                    'roots'    => ['listChanged' => true],
                    'sampling' => new \stdClass(),
                ],
                'clientInfo' => [
                    'name'    => 'PowerCreatives',
                    'version' => '1.0.0',
                ],
            ]);

            // Step 2: Discover tools — find serp-overview
            $tools_result = self::mcp_call($api_key, 'tools/list', new \stdClass());
            $tools = $tools_result['tools'] ?? [];

            $serp_tool = null;
            foreach ($tools as $tool) {
                $name = $tool['name'] ?? '';
                if (
                    $name === 'serp-overview' ||
                    $name === 'ahrefs_serp_overview' ||
                    str_contains($name, 'serp')
                ) {
                    $serp_tool = $name;
                    break;
                }
            }

            if (!$serp_tool) {
                self::log('Ahrefs SERP DR: serp-overview tool not found. Available: ' . implode(', ', array_column($tools, 'name')));
                return [];
            }



            self::log("Ahrefs SERP DR: Using tool '{$serp_tool}'");

            // Step 3: Call per keyword (serp-overview does not support batch)
            $result = [];
            $unique = array_values(array_unique($keywords));

            for ($i = 0; $i < count($unique); $i++) {
                $keyword = $unique[$i];

                try {
                    $tool_result = self::mcp_call($api_key, 'tools/call', [
                        'name'      => $serp_tool,
                        'arguments' => [
                            'keyword'       => $keyword,
                            'country'       => strtoupper(self::get_country($country)),
                            'top_positions' => 15,
                            'select'        => 'position,title,url,domain_rating,url_rating,traffic',
                        ],
                    ]);

                    // Parse MCP content blocks and extract organic-only results
                    $content_items = $tool_result['content'] ?? [];
                    $serp_results = self::extract_organic_results($content_items, 15);

                    // Compute avg and lowest DR from the organic results (now handled by frontend, zeroing out to save cycles)
                    $avg_dr = 0;
                    $low_dr = 0;

                    // Compute avg and lowest UR from the organic results (now handled by frontend)
                    $avg_ur = 0;
                    $low_ur = 0;

                    $result[$keyword] = [
                        'avgDR'       => $avg_dr,
                        'lowDR'       => $low_dr,
                        'avgUR'       => $avg_ur,
                        'lowUR'       => $low_ur,
                        'serpResults' => $serp_results,
                    ];

                } catch (\Exception $e) {
                    self::log("Ahrefs SERP DR: Failed for '{$keyword}': " . $e->getMessage());
                    // Continue to next keyword
                }

                // Polite delay between calls (300ms)
                if ($i < count($unique) - 1) {
                    usleep(300000);
                }

                self::log(sprintf('Ahrefs SERP DR: %d/%d — "%s"', $i + 1, count($unique), $keyword));
            }

            self::log('Ahrefs SERP DR: Done — ' . count($result) . ' / ' . count($unique) . ' keywords');
            return $result;

        } catch (\Exception $e) {
            self::log('Ahrefs SERP DR: Error — ' . $e->getMessage());
            return [];
        }
    }
}

