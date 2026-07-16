<?php
/**
 * Teacher: SERP reality (research spine D5).
 *
 * The live top organic results for the PRIMARY keyword — via the user's
 * OWN Ahrefs integration (serp-overview, the tap that already powers the
 * keyword drawer's volumes; keys live in the Integrations module). ONE
 * structured call then compares what the winners signal (titles, format,
 * angle) against THIS content. Every item carries source='ahrefs' — the
 * owner sees which tap answered, always.
 *
 * No Ahrefs key or no primary keyword = an HONEST named error with the
 * fix in the message — never a fake-empty rail section.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Teacher_Serp implements PCM_Optimizer_Teacher
{
    public function id(): string
    {
        return 'serp';
    }

    public function label(): string
    {
        return 'Competitor gaps (SERP)';
    }

    public function order(): int
    {
        return 25;
    }

    public function group(): string
    {
        return 'search';
    }

    /**
     * Ahrefs top-N organic for the primary keyword → one invoke_json
     * comparing winners vs this page. Items in DATA order; the model's
     * ordering is never trusted for identity.
     *
     * @param array $context See the interface.
     * @return array Catalog items.
     */
    public function analyze(array $context): array
    {
        $primary = trim((string) ($context['keywords']['primary'] ?? ''));
        if ($primary === '') {
            throw new \RuntimeException('SERP research needs a primary keyword — set one in the keyword drawer first.');
        }
        $user_id = (int) ($context['userId'] ?? 0);
        $key     = PCM_Optimizer_Service::provider_key('ahrefs', $user_id);
        if ($key === null) {
            throw new \RuntimeException('SERP research needs an Ahrefs key — add one on the Integrations page.');
        }
        if (!class_exists('PCM_Keywords_Service')) {
            throw new \RuntimeException('The keyword engine is unavailable.');
        }

        $tun    = (array) (PCM_Optimizer_Service::research_tunables()['serp'] ?? array());
        $top_n  = (int) ($tun['topN'] ?? 10);
        // The market comes from THE SMART COUNTRY CHAIN (site → brand → AI
        // check → hub default) — never a silent locale guess (gap e8fcae5).
        $country = $this->resolve_market((int) ($context['siteId'] ?? 0), (string) ($context['html'] ?? ''));
        $serp    = PCM_Keywords_Service::ahrefs_serp_dr(array($primary), $key, $country);
        $rows    = array_slice((array) ($serp[$primary]['serpResults'] ?? array()), 0, max(3, $top_n));
        if (empty($rows)) {
            throw new \RuntimeException(sprintf('Ahrefs returned no organic results for "%s" — check the keyword or try again.', $primary));
        }

        // THE CONTENT UPGRADE (gap e8fcae5 D4): fetch the top winners' REAL
        // pages — gaps become facts, not title-inference. A failed fetch
        // degrades THAT winner to title-only, marked — never kills the run.
        $fetch_top = (int) ($tun['fetchTop'] ?? 3);
        $max_chars = (int) ($tun['maxCharsPerPage'] ?? 4000);
        $timeout   = (int) ($tun['fetchTimeout'] ?? 8);
        $scraper   = class_exists('PCM_Scraper_Service') ? new PCM_Scraper_Service() : null;

        $winners = array();
        foreach (array_values($rows) as $i => $r) {
            $winner = array(
                'position' => (int) ($r['position'] ?? 0),
                'title'    => (string) ($r['title'] ?? ''),
                'url'      => (string) ($r['url'] ?? ''),
            );
            if ($scraper !== null && $i < $fetch_top && $winner['url'] !== '') {
                try {
                    $fetched   = $scraper->fetch_html($winner['url'], 'SERP Researcher', $timeout);
                    $extracted = $scraper->extract_text_content((string) ($fetched['html'] ?? ''), $winner['url']);
                    $text      = trim(implode("\n", array_merge(
                        array_map(static fn($h): string => '## ' . $h, (array) ($extracted['headings'] ?? array())),
                        (array) ($extracted['paragraphs'] ?? array())
                    )));
                    if ($text !== '') {
                        $winner['content'] = function_exists('mb_substr') ? mb_substr($text, 0, $max_chars) : substr($text, 0, $max_chars);
                    } else {
                        $winner['contentNote'] = 'page fetched but no readable text extracted';
                    }
                } catch (\Throwable $e) {
                    $winner['contentNote'] = 'content unavailable (' . $e->getMessage() . ')';
                }
            }
            $winners[] = $winner;
        }

        $messages = array(
            array(
                'role'    => 'system',
                // The exact output contract lives IN the prompt (the
                // Anthropic law — no response_format there).
                'content' => 'You are a search-results analyst. You get the REAL top organic results for a keyword — '
                    . 'the leading ones INCLUDING their actual page content (headings + paragraphs; a winner with '
                    . 'contentNote could not be fetched, judge it by title only) — and the content of OUR page '
                    . 'targeting it. Judge three things about OUR content, honestly and only from the given data: '
                    . '(1) format — do the winners use a different content format (guide, list, service page, '
                    . 'comparison) than ours? (2) coverage — which concrete topics/sections/facts the winners\' '
                    . 'CONTENT covers that ours does not (max 4, only real gaps a reader would miss); (3) angle — '
                    . 'is there a clearly stronger angle in the winners (price, locality, speed, proof) that ours '
                    . 'misses? For each verdict: matches=true means we already align (evidence = why, short); '
                    . 'matches=false means a gap (evidence = the concrete gap NAMING which winner shows it; fix = '
                    . 'ONE imperative sentence for a rewriting AI). NEVER invent winners or content. Respond with '
                    . 'ONLY this JSON, no markdown: '
                    . '{"verdicts":[{"id":"format|coverage-<slug>|angle","matches":true,"evidence":"...","fix":"..."}]}',
            ),
            array(
                'role'    => 'user',
                'content' => 'KEYWORD: ' . $primary . "\n\nTOP ORGANIC RESULTS (Ahrefs, live):\n" . wp_json_encode($winners)
                    . PCM_Optimizer_Service::context_suffix($context)
                    . "\n\nOUR PAGE CONTENT (HTML):\n" . (string) $context['html'],
            ),
        );

        $schema = array(
            'name'   => 'optimizer_serp_verdicts',
            'schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'verdicts' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'id'       => array('type' => 'string'),
                                'matches'  => array('type' => 'boolean'),
                                'evidence' => array('type' => 'string'),
                                'fix'      => array('type' => 'string'),
                            ),
                            'required'   => array('id', 'matches', 'evidence'),
                        ),
                    ),
                ),
                'required'   => array('verdicts'),
            ),
        );

        $parsed = PCM_LLM::invoke_json($messages, $schema, array(
            'model'    => (string) ($context['model'] ?? '') ?: null,
            'provider' => (string) ($context['provider'] ?? '') ?: null,
            'user_id'  => $user_id,
        ));

        $verdicts = array_values(array_filter(
            (array) ($parsed['verdicts'] ?? array()),
            static fn($v): bool => is_array($v) && trim((string) ($v['id'] ?? '')) !== ''
        ));
        if (empty($verdicts)) {
            throw new \RuntimeException('The model did not analyze the search results — re-analyze, or pick another model.');
        }

        $items = array();
        foreach ($verdicts as $v) {
            $matches = (bool) $v['matches'];
            $fix     = trim((string) ($v['fix'] ?? ''));
            if (!$matches && $fix === '') {
                continue; // a gap without a directive can't ride the basket — skip the malformed row
            }
            $items[] = array(
                'id'          => sanitize_title((string) $v['id']),
                'teacherId'   => $this->id(),
                'found'       => !$matches,
                // The differentiator leads; the shared keyword lives in the
                // card/context, not repeated per row (owner ruling).
                'label'       => ucfirst(str_replace('-', ': ', (string) $v['id'])),
                'evidence'    => (string) ($v['evidence'] ?? ''),
                'instruction' => $matches ? '' : $fix,
                'source'      => 'ahrefs',
            );
        }
        return $items;
    }

    /**
     * The market for the SERP pull — the live country chain (site → brand
     * GBP → AI language check → hub default); any failure lands on the hub
     * default honestly (it is DATA, editable).
     *
     * @param int    $site_id Site id from the context.
     * @param string $html    Content sample for the language check.
     * @return string Two-letter country code.
     */
    private function resolve_market(int $site_id, string $html): string
    {
        try {
            if ($site_id > 0 && class_exists('PCM_Schema')) {
                global $wpdb;
                $sites = PCM_Schema::table('sites');
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $site = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$sites} WHERE id = %d", $site_id));
                if ($site) {
                    return PCM_Keywords_Service::resolve_country($site, wp_strip_all_tags($html));
                }
            }
        } catch (\Throwable $e) {
            error_log('[PCM_Optimizer] serp teacher: country resolution failed — ' . $e->getMessage());
        }
        return PCM_Keywords_Service::default_country();
    }
}

PCM_Optimizer_Service::register(new PCM_Teacher_Serp());
