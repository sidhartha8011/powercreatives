<?php
/**
 * Teacher: Interlinking (research spine D5 — owner spec 2026-07-13).
 *
 * IN-CONTEXT links FROM this page to the site's other pages: natural
 * phrases already in the text that match another page's topic. Relevance
 * comes from what pages ACTUALLY rank for when a GSC key exists (ONE
 * domain-wide page_stats call — Google already told us the association,
 * source='gsc'); otherwise from the pages' titles, honestly labeled
 * (source='page titles'). The linking laws ride the prompt: anchor must
 * exist VERBATIM in our text · one link per target · descriptive anchors
 * · never self-link.
 *
 * The page list arrives from the editor (the live-state law — same as the
 * html). No pages = an honest error, never a guess.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Teacher_Interlink implements PCM_Optimizer_Teacher
{
    public function id(): string
    {
        return 'interlinks';
    }

    public function label(): string
    {
        return 'Internal links';
    }

    public function order(): int
    {
        return 35;
    }

    public function group(): string
    {
        return 'search';
    }

    /**
     * Enrich the page list with proven GSC queries when available, then
     * ONE structured call proposing in-context from-links.
     *
     * @param array $context See the interface.
     * @return array Catalog items.
     */
    public function analyze(array $context): array
    {
        $pages = (array) ($context['pages'] ?? array());
        // The current page never links to itself — drop it by id up front.
        $post_id = (int) ($context['postId'] ?? 0);
        $pages   = array_values(array_filter($pages, static fn($p): bool => is_array($p) && (int) ($p['id'] ?? 0) !== $post_id));
        if (empty($pages)) {
            throw new \RuntimeException('Interlink research needs the site\'s page list — open the editor from the SEO table so the pages ride along.');
        }

        $user_id = (int) ($context['userId'] ?? 0);
        $source  = 'page titles';
        $pages   = array_slice($pages, 0, 60);

        // Proven relevance when Google can tell us: ONE domain-wide call
        // maps url → its top queries. A GSC failure DEGRADES the label,
        // never the run — stated on every item via source.
        $gsc_key = PCM_Optimizer_Service::provider_key('gsc', $user_id);
        if ($gsc_key !== null && class_exists('PCM_GSC') && !empty($pages[0]['permalink'])) {
            $stats = $this->page_query_map($gsc_key, (string) $pages[0]['permalink']);
            if (!empty($stats)) {
                $source = 'gsc';
                foreach ($pages as &$pg) {
                    $queries = $stats[self::normalize_url((string) $pg['permalink'])] ?? array();
                    if (!empty($queries)) {
                        $pg['ranksFor'] = array_slice($queries, 0, 5);
                    }
                }
                unset($pg);
            }
        }

        $messages = array(
            array(
                'role'    => 'system',
                // The exact output contract lives IN the prompt (the
                // Anthropic law — no response_format there).
                'content' => 'You are an internal-linking strategist. You get OUR page content and the site\'s other '
                    . 'pages (title, url' . ($source === 'gsc' ? ', ranksFor = the queries Google actually ranks that page for' : '') . '). '
                    . 'Propose links FROM our content TO the most relevant pages. HARD LAWS: the anchor phrase must '
                    . 'already exist VERBATIM in our content (never invent or reword text); descriptive anchors only '
                    . '(never "click here"); at most ONE link per target page; only genuinely relevant targets '
                    . ($source === 'gsc' ? '(prefer targets whose ranksFor queries match the anchor\'s meaning); ' : '')
                    . 'maximum 6 proposals; fewer is better than forced. Respond with ONLY this JSON, no markdown: '
                    . '{"links":[{"anchor":"<verbatim phrase from our content>","url":"<target url>","target":"<target title>","why":"<one short sentence>"}]}',
            ),
            array(
                'role'    => 'user',
                'content' => 'SITE PAGES:' . "\n" . wp_json_encode($pages)
                    . PCM_Optimizer_Service::context_suffix($context)
                    . "\n\nOUR PAGE CONTENT (HTML):\n" . (string) $context['html'],
            ),
        );

        $schema = array(
            'name'   => 'optimizer_interlink_proposals',
            'schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'links' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'       => 'object',
                            'properties' => array(
                                'anchor' => array('type' => 'string'),
                                'url'    => array('type' => 'string'),
                                'target' => array('type' => 'string'),
                                'why'    => array('type' => 'string'),
                            ),
                            'required'   => array('anchor', 'url', 'target'),
                        ),
                    ),
                ),
                'required'   => array('links'),
            ),
        );

        $parsed = PCM_LLM::invoke_json($messages, $schema, array(
            'model'    => (string) ($context['model'] ?? '') ?: null,
            'provider' => (string) ($context['provider'] ?? '') ?: null,
            'user_id'  => $user_id,
        ));

        // SERVER-side law enforcement — the model's claims are verified,
        // never trusted: anchor verbatim-present, target in the given list,
        // one link per target.
        $text = wp_strip_all_tags((string) ($context['html'] ?? ''));
        $valid_urls = array();
        foreach ($pages as $pg) {
            $valid_urls[self::normalize_url((string) $pg['permalink'])] = (string) $pg['permalink'];
        }
        $items = array();
        $used_targets = array();
        foreach ((array) ($parsed['links'] ?? array()) as $link) {
            if (!is_array($link)) {
                continue;
            }
            $anchor = trim((string) ($link['anchor'] ?? ''));
            $url    = self::normalize_url((string) ($link['url'] ?? ''));
            if ($anchor === '' || !isset($valid_urls[$url]) || isset($used_targets[$url])) {
                continue;
            }
            $present = function_exists('mb_stripos') ? mb_stripos($text, $anchor) !== false : stripos($text, $anchor) !== false;
            if (!$present) {
                continue; // the law: anchors exist verbatim or the proposal dies here
            }
            $used_targets[$url] = true;
            $items[] = array(
                'id'          => 'link-' . sanitize_title((string) ($link['target'] ?? $url)),
                'teacherId'   => $this->id(),
                'found'       => true,
                'label'       => sprintf('Link "%s" → %s', $anchor, (string) ($link['target'] ?? $url)),
                'evidence'    => trim((string) ($link['why'] ?? '')),
                'instruction' => sprintf('Turn the existing phrase "%s" into an in-context link to %s — keep the wording exactly as it stands.', $anchor, $valid_urls[$url]),
                'source'      => $source,
            );
        }

        if (empty($items)) {
            // Honest quiet result — the analysis ran, nothing qualified.
            return array(array(
                'id'          => 'no-link-opportunities',
                'teacherId'   => $this->id(),
                'found'       => false,
                'label'       => 'No natural link opportunities',
                'evidence'    => 'No phrase in the content matches another page\'s topic under the linking laws (verbatim anchor, one per target, genuine relevance).',
                'instruction' => '',
                'source'      => $source,
            ));
        }
        return $items;
    }

    /**
     * ONE domain-wide GSC pull: url → its top queries. Failures return []
     * — the caller degrades the SOURCE LABEL, never fakes rankings.
     *
     * @param string $key       GSC service-account JSON.
     * @param string $sample_url Any site url — property matching.
     * @return array<string, string[]>
     */
    private function page_query_map(string $key, string $sample_url): array
    {
        try {
            $props = PCM_GSC::list_properties($key);
            if (is_wp_error($props)) {
                return array();
            }
            $candidates = PCM_GSC::match_properties($props, $sample_url);
            if (empty($candidates)) {
                return array();
            }
            $stats = PCM_GSC::page_stats($key, $candidates[0]);
            if (is_wp_error($stats) || !is_array($stats['pages'] ?? null)) {
                return array();
            }
            // page_stats shape: pages = {url => {clicks, …, keywords[] (top 5)}}.
            $map = array();
            foreach ($stats['pages'] as $url => $row) {
                $page     = self::normalize_url((string) $url);
                $keywords = array_values(array_filter(array_map('strval', (array) ($row['keywords'] ?? array()))));
                if ($page !== '' && !empty($keywords)) {
                    $map[$page] = $keywords;
                }
            }
            return $map;
        } catch (\Throwable $e) {
            error_log('[PCM_Optimizer] interlink teacher: GSC page map failed — ' . $e->getMessage());
            return array();
        }
    }

    /** Scheme/www/trailing-slash-insensitive url key. */
    private static function normalize_url(string $url): string
    {
        $url = strtolower(trim($url));
        $url = (string) preg_replace('#^https?://(www\.)?#', '', $url);
        return rtrim($url, '/');
    }
}

PCM_Optimizer_Service::register(new PCM_Teacher_Interlink());
