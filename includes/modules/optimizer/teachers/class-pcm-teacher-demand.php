<?php
/**
 * Teacher: Proven demand (research spine D5).
 *
 * The queries Google ALREADY shows this page for at striking distance
 * (default position 4-20) — the fastest real wins in SEO, because Google
 * has already told us it wants to rank this page for them. Reads ONLY the
 * stored per-page GSC rows the keyword drawer maintains (one data path,
 * one honesty label) — the item's source says stored-and-when, never
 * passing cached rows off as live.
 *
 * Deterministic — no LLM. Tunables are hub data (pcm_optimizer_research →
 * demand), never code.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Teacher_Demand implements PCM_Optimizer_Teacher
{
    public function id(): string
    {
        return 'demand';
    }

    public function label(): string
    {
        return 'Proven search demand';
    }

    public function order(): int
    {
        return 30;
    }

    public function group(): string
    {
        return 'search';
    }

    /**
     * Striking-distance filter over the stored GSC rows. No stored rows =
     * an honest error naming the fix (open the drawer's Ranking tab once).
     *
     * @param array $context See the interface.
     * @return array Catalog items.
     */
    public function analyze(array $context): array
    {
        $site_id = (int) ($context['siteId'] ?? 0);
        $post_id = (int) ($context['postId'] ?? 0);
        $stored  = PCM_Optimizer_Service::kw_stats_cache_get($site_id, $post_id);
        if ($stored === null) {
            throw new \RuntimeException('No Search Console data stored for this page yet — open the keyword drawer\'s Ranking tab once to load it.');
        }

        $tun       = PCM_Optimizer_Service::research_tunables()['demand'] ?? array();
        $min_pos   = (float) ($tun['minPos'] ?? 4);
        $max_pos   = (float) ($tun['maxPos'] ?? 20);
        $max_items = (int) ($tun['maxItems'] ?? 8);
        $source    = 'gsc:stored (' . gmdate('Y-m-d', (int) ($stored['fetchedAt'] ?? 0)) . ')';

        $text = function_exists('mb_strtolower')
            ? mb_strtolower(wp_strip_all_tags((string) ($context['html'] ?? '')))
            : strtolower(wp_strip_all_tags((string) ($context['html'] ?? '')));

        // Rows arrive clicks-desc from the store; striking distance sorts
        // by impressions — the demand signal, not the harvest signal.
        $candidates = array();
        foreach ((array) $stored['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $query    = trim((string) ($row['query'] ?? ''));
            $position = $row['position'];
            if ($query === '' || $position === null) {
                continue; // vanished-compare rows have no current position — honest skip
            }
            $position = (float) $position;
            if ($position < $min_pos || $position > $max_pos) {
                continue;
            }
            $needle = function_exists('mb_strtolower') ? mb_strtolower($query) : strtolower($query);
            if ($text !== '' && strpos($text, $needle) !== false) {
                continue; // already in the content — nothing to weave
            }
            $candidates[] = array(
                'query'       => $query,
                'position'    => $position,
                'impressions' => (int) ($row['impressions'] ?? 0),
                'clicks'      => (int) ($row['clicks'] ?? 0),
            );
        }
        usort($candidates, static fn(array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
        $candidates = array_slice($candidates, 0, max(1, $max_items));

        if (empty($candidates)) {
            // Honest "nothing found" — a quiet passing item, never a blank
            // section pretending the analysis didn't run.
            return array(array(
                'id'          => 'no-striking-distance',
                'teacherId'   => $this->id(),
                'found'       => false,
                'label'       => 'No untapped striking-distance queries',
                'evidence'    => sprintf('No stored query ranks between #%s and #%s without already appearing in the content.', $min_pos, $max_pos),
                'instruction' => '',
                'source'      => $source,
            ));
        }

        $items = array();
        foreach ($candidates as $c) {
            $items[] = array(
                'id'          => 'demand-' . sanitize_title($c['query']),
                'teacherId'   => $this->id(),
                'found'       => true,
                'label'       => sprintf('Proven demand: "%s"', $c['query']),
                'evidence'    => sprintf('Ranks #%s · %d impressions (%d clicks) — Google already associates this page with it.', round($c['position'], 1), $c['impressions'], $c['clicks']),
                'instruction' => sprintf('Weave the search phrase "%s" naturally into an existing relevant section — the page already ranks #%s for it.', $c['query'], round($c['position'], 1)),
                'source'      => $source,
            );
        }
        return $items;
    }
}

PCM_Optimizer_Service::register(new PCM_Teacher_Demand());
