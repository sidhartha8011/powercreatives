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
        return 'SERP reality';
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

        $top_n  = (int) (PCM_Optimizer_Service::research_tunables()['serp']['topN'] ?? 10);
        $locale = get_locale();
        $lang   = $locale ? substr($locale, 0, 2) : 'en';
        $serp   = PCM_Keywords_Service::ahrefs_serp_dr(array($primary), $key, $lang);
        $rows   = array_slice((array) ($serp[$primary]['serpResults'] ?? array()), 0, max(3, $top_n));
        if (empty($rows)) {
            throw new \RuntimeException(sprintf('Ahrefs returned no organic results for "%s" — check the keyword or try again.', $primary));
        }

        $winners = array();
        foreach ($rows as $r) {
            $winners[] = array(
                'position' => (int) ($r['position'] ?? 0),
                'title'    => (string) ($r['title'] ?? ''),
                'url'      => (string) ($r['url'] ?? ''),
            );
        }

        $messages = array(
            array(
                'role'    => 'system',
                // The exact output contract lives IN the prompt (the
                // Anthropic law — no response_format there).
                'content' => 'You are a search-results analyst. You get the REAL top organic results (position, '
                    . 'title, url) for a keyword, and the content of OUR page targeting it. From the winners\' '
                    . 'titles and urls, judge three things about OUR content, honestly and only from the given '
                    . 'data: (1) format — do the winners signal a different content format (guide, list, service '
                    . 'page, comparison) than ours? (2) coverage — which concrete themes the winners\' titles '
                    . 'promise that our content does not cover (max 4, only real gaps); (3) angle — is there a '
                    . 'clearly stronger angle in the winners\' titles (price, locality, speed, proof) that ours '
                    . 'misses? For each verdict: matches=true means we already align (evidence = why, short); '
                    . 'matches=false means a gap (evidence = the concrete gap; fix = ONE imperative sentence for '
                    . 'a rewriting AI). NEVER invent winners or content. Respond with ONLY this JSON, no markdown: '
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
                'label'       => sprintf('Winners for "%s": %s', $primary, (string) $v['id']),
                'evidence'    => (string) ($v['evidence'] ?? ''),
                'instruction' => $matches ? '' : $fix,
                'source'      => 'ahrefs',
            );
        }
        return $items;
    }
}

PCM_Optimizer_Service::register(new PCM_Teacher_Serp());
