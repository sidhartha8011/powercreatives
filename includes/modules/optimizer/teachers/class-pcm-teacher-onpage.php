<?php
/**
 * Teacher: On-page & keyword placement (research spine D5).
 *
 * DETERMINISTIC by design — zero LLM: word count, per-keyword usage and
 * density against the hub-controlled tunables, and role-correct placement
 * (primary in H1/first paragraph/headings · supporting in some headings).
 * Instant, exact, free — and testable in the standalone harness.
 *
 * Verdicts follow THE KEYWORD HIERARCHY LAW (owner): primary threads the
 * page; supporting is structural but not everywhere; additional stays a
 * light touch — the stuffing check guards ALL of them.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Teacher_Onpage implements PCM_Optimizer_Teacher
{
    public function id(): string
    {
        return 'onpage';
    }

    public function label(): string
    {
        return 'On-page & keyword placement';
    }

    public function order(): int
    {
        return 15;
    }

    public function group(): string
    {
        return 'search';
    }

    /**
     * Pure measurement — no model call. Without any keywords the teacher
     * reports the honest single fact it can still measure (word count) and
     * says why the rest is quiet.
     *
     * @param array $context See the interface.
     * @return array Catalog items.
     */
    public function analyze(array $context): array
    {
        $tun   = PCM_Optimizer_Service::research_tunables()['onpage'] ?? array();
        $min_words   = (int) ($tun['minWords'] ?? 300);
        $max_density = (float) ($tun['maxDensityPct'] ?? 2.5);

        $html       = (string) ($context['html'] ?? '');
        $text       = self::to_text($html);
        $words      = self::word_count($text);
        $headings   = self::headings($html);
        $first_para = self::first_paragraph($html);

        $items = array();

        // ── Length — measured, judged against DATA. ──
        $items[] = array(
            'id'          => 'word-count',
            'teacherId'   => $this->id(),
            'found'       => $words < $min_words,
            'label'       => sprintf('Content length (%d words)', $words),
            'evidence'    => $words < $min_words
                ? sprintf('%d words — below the %d-word working minimum for a page that competes.', $words, $min_words)
                : sprintf('%d words of body content.', $words),
            'instruction' => $words < $min_words
                ? sprintf('Expand the content with genuinely useful substance to at least %d words — concrete details, steps, facts; never filler.', $min_words)
                : '',
        );

        $kw = (array) ($context['keywords'] ?? array());
        $primary    = trim((string) ($kw['primary'] ?? ''));
        $supporting = array_values(array_filter(array_map('trim', array_map('strval', (array) ($kw['supporting'] ?? array())))));

        if ($primary === '') {
            // HONEST empty: placement can't be judged without a target.
            $items[] = array(
                'id'          => 'primary-missing',
                'teacherId'   => $this->id(),
                'found'       => true,
                'label'       => 'No primary keyword set',
                'evidence'    => 'Placement, density and coverage need a target — set the primary keyword in the keyword drawer.',
                'instruction' => '',
            );
            return $items;
        }

        // ── Primary placement: H1/first heading · first paragraph · any heading. ──
        $primary_in_first_heading = isset($headings[0]) && self::contains($headings[0]['text'], $primary);
        $primary_in_first_para    = self::contains($first_para, $primary);
        $items[] = array(
            'id'          => 'primary-first-heading',
            'teacherId'   => $this->id(),
            'found'       => !$primary_in_first_heading,
            'label'       => sprintf('Primary "%s" in the top heading', $primary),
            'evidence'    => $primary_in_first_heading
                ? sprintf('"%s"', $headings[0]['text'])
                : (isset($headings[0]) ? sprintf('The top heading is "%s" — the primary keyword is not in it.', $headings[0]['text']) : 'The content has no headings at all.'),
            'instruction' => $primary_in_first_heading ? '' : sprintf('Work the primary keyword "%s" naturally into the page\'s top heading.', $primary),
        );
        $items[] = array(
            'id'          => 'primary-first-paragraph',
            'teacherId'   => $this->id(),
            'found'       => !$primary_in_first_para,
            'label'       => sprintf('Primary "%s" in the first paragraph', $primary),
            'evidence'    => $primary_in_first_para
                ? 'The opening paragraph names the target.'
                : 'The first paragraph never names the primary keyword — search and AI engines read the opening first.',
            'instruction' => $primary_in_first_para ? '' : sprintf('Name "%s" naturally within the first paragraph — the opening must say what the page is about.', $primary),
        );

        // ── Density per keyword — stuffing guard for EVERY role. ──
        $all_roles = array_merge(
            array(array('kw' => $primary, 'role' => 'primary')),
            array_map(static fn(string $s): array => array('kw' => $s, 'role' => 'supporting'), $supporting),
            array_map(static fn(string $s): array => array('kw' => trim((string) $s), 'role' => 'additional'), (array) ($kw['additional'] ?? array()))
        );
        foreach ($all_roles as $entry) {
            if ($entry['kw'] === '') {
                continue;
            }
            $uses    = self::uses($text, $entry['kw']);
            $density = $words > 0 ? round(($uses * self::word_count($entry['kw']) / $words) * 100, 2) : 0.0;
            if ($density > $max_density) {
                $items[] = array(
                    'id'          => 'stuffing-' . sanitize_title($entry['kw']),
                    'teacherId'   => $this->id(),
                    'found'       => true,
                    'label'       => sprintf('"%s" is over-used (%s%%)', $entry['kw'], $density),
                    'evidence'    => sprintf('%d uses = %s%% density — above the %s%% stuffing threshold; reads unnatural to people and engines.', $uses, $density, $max_density),
                    'instruction' => sprintf('Reduce repetitions of "%s" — keep the clearest uses, rewrite the rest with natural synonyms and plain language.', $entry['kw']),
                );
            } elseif ($entry['role'] === 'primary' && $uses < (int) ($tun['minPrimaryUses'] ?? 1)) {
                $items[] = array(
                    'id'          => 'primary-unused',
                    'teacherId'   => $this->id(),
                    'found'       => true,
                    'label'       => sprintf('Primary "%s" barely appears', $entry['kw']),
                    'evidence'    => sprintf('%d use(s) in %d words.', $uses, $words),
                    'instruction' => sprintf('Thread "%s" naturally through the body — it is the page\'s target topic.', $entry['kw']),
                );
            } else {
                $items[] = array(
                    'id'          => 'density-' . sanitize_title($entry['kw']),
                    'teacherId'   => $this->id(),
                    'found'       => false,
                    'label'       => sprintf('"%s" density healthy (%s%%)', $entry['kw'], $density),
                    'evidence'    => sprintf('%d natural use(s).', $uses),
                    'instruction' => '',
                );
            }
        }

        // ── Supporting keywords in headings — SOME, not all (hierarchy law). ──
        if (!empty($supporting) && count($headings) > 1) {
            $in_headings = array();
            foreach ($supporting as $s) {
                foreach ($headings as $h) {
                    if (self::contains($h['text'], $s)) {
                        $in_headings[] = $s;
                        break;
                    }
                }
            }
            $missing = array_values(array_diff($supporting, $in_headings));
            $items[] = array(
                'id'          => 'supporting-headings',
                'teacherId'   => $this->id(),
                'found'       => empty($in_headings),
                'label'       => 'Supporting keywords in subheadings',
                'evidence'    => empty($in_headings)
                    ? sprintf('None of the supporting keywords (%s) appear in any subheading.', implode(', ', $supporting))
                    : sprintf('In headings: %s.%s', implode(', ', $in_headings), empty($missing) ? '' : ' Not yet: ' . implode(', ', $missing) . ' — some is enough, all is stuffing.'),
                'instruction' => empty($in_headings)
                    ? sprintf('Work one or two supporting keywords (%s) naturally into existing subheadings — never all of them.', implode(', ', $supporting))
                    : '',
            );
        }

        return $items;
    }

    /** Strip to plain text, whitespace-normalized. */
    private static function to_text(string $html): string
    {
        $text = wp_strip_all_tags(preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Unicode-safe word count. */
    private static function word_count(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        return count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: array());
    }

    /** All headings in document order: [{level, text}]. */
    private static function headings(string $html): array
    {
        $out = array();
        if (preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $text = trim(wp_strip_all_tags($hit[2]));
                if ($text !== '') {
                    $out[] = array('level' => (int) $hit[1], 'text' => $text);
                }
            }
        }
        return $out;
    }

    /** The first real paragraph's text ('' when none). */
    private static function first_paragraph(string $html): string
    {
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $m)) {
            foreach ($m[1] as $p) {
                $text = trim(wp_strip_all_tags($p));
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return '';
    }

    /** Case-insensitive containment (unicode-aware when mbstring exists). */
    private static function contains(string $haystack, string $needle): bool
    {
        if ($haystack === '' || $needle === '') {
            return false;
        }
        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle) !== false;
        }
        return stripos($haystack, $needle) !== false;
    }

    /** Occurrences of a phrase in the text, case-insensitive. */
    private static function uses(string $text, string $phrase): int
    {
        if ($text === '' || $phrase === '') {
            return 0;
        }
        $lower_text   = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        $lower_phrase = function_exists('mb_strtolower') ? mb_strtolower($phrase) : strtolower($phrase);
        return substr_count($lower_text, $lower_phrase);
    }
}

PCM_Optimizer_Service::register(new PCM_Teacher_Onpage());
