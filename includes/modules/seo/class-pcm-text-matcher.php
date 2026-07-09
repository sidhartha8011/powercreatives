<?php
/**
 * PCM_Text_Matcher — the dynamic-rule matcher (pure PHP, NO WordPress deps).
 *
 * Implements normalization spec v1 and the block-boundary match/replace used
 * by render-time dynamic rules (docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md →
 * "Normalization spec v1" / "Serving mechanism v1").
 *
 * SYNC CONTRACT: the connector's single-file copy of this logic
 * (pcm_conn_normalize_text / pcm_conn_apply_rules in the seohub connector
 * template) MUST stay byte-behavior-identical to this class — this class is
 * the fixture-tested reference. Change here first, mirror there, same pair.
 *
 * Block targets use boundary matching on NON-NESTABLE tags (<p>, <h1..6>):
 * verified decision — WP core's HTML API cannot atomically replace a block's
 * inner HTML, and these tags cannot legally nest, so the boundary regex is
 * exact (the same proven pattern as the shipped heading overrides).
 *
 * @package PowerCreatives
 * @since   1.7.0 (DB 1.37.0, pair 2)
 */

if (!defined('PCM_TEXT_MATCHER_LOADED')) {
    define('PCM_TEXT_MATCHER_LOADED', true);
}

class PCM_Text_Matcher
{
    /** Tags a block rule may target — non-nestable, so boundary matching is exact. */
    public const BLOCK_TAGS = array('p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6');

    /**
     * Normalization spec v1: entity-decode → NBSP→space → collapse whitespace
     * → trim → case-fold. The ONLY comparison form for rule match texts.
     *
     * Case-folding needs mbstring for non-ASCII (WP hosts ship it; WP lists it
     * as required). Without it the fold degrades to ASCII-only — which is SAFE
     * by construction: a fold mismatch is just a non-match, so the original
     * serves and the rule flags stale. Never wrong content, never a guess.
     */
    public static function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    /** Visible text of an HTML fragment (script/style dropped, tags stripped), NOT normalized. */
    public static function visible_text(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html);
        return strip_tags($html);
    }

    /**
     * Replace the inner content of the occurrence-th block whose visible text
     * normalizes to $match_text. Returns the new HTML, or NULL when no block
     * matched (the caller serves the ORIGINAL and flags stale — never guesses).
     *
     * @param string $html        Full document (or fragment) HTML.
     * @param string $tag         One of BLOCK_TAGS.
     * @param string $match_text  ALREADY-normalized target text (spec v1).
     * @param int    $occurrence  0-based among same-normalized-text blocks.
     * @param string $replacement Pre-sanitized inner HTML to swap in.
     * @return string|null
     */
    public static function replace_block(string $html, string $tag, string $match_text, int $occurrence, string $replacement): ?string
    {
        $tag = strtolower($tag);
        if (!in_array($tag, self::BLOCK_TAGS, true) || $match_text === '') {
            return null;
        }
        $seen = 0;
        $done = false;
        $out  = preg_replace_callback(
            '#<' . $tag . '(\s[^>]*)?>(.*?)</' . $tag . '>#is',
            static function ($m) use (&$seen, &$done, $match_text, $occurrence, $replacement, $tag) {
                if ($done) {
                    return $m[0];
                }
                if (self::normalize(self::visible_text($m[2])) !== $match_text) {
                    return $m[0];
                }
                if ($seen++ !== $occurrence) {
                    return $m[0];
                }
                $done = true;
                return '<' . $tag . (isset($m[1]) ? $m[1] : '') . '>' . $replacement . '</' . $tag . '>';
            },
            $html
        );
        // PCRE failure → treat as no-match: the caller serves the original.
        return (is_string($out) && $done) ? $out : null;
    }

    /**
     * Occurrence index (0-based) of $needle_html's block among blocks with the
     * same normalized visible text — computed at RULE-CREATION time so the
     * rule targets the exact block the user edited, not just the first twin.
     *
     * @param array<int,string> $block_texts Visible texts of ALL blocks of the
     *                                       tag, in document order.
     * @param int               $index       The edited block's position.
     * @return int
     */
    public static function occurrence_of(array $block_texts, int $index): int
    {
        if (!isset($block_texts[$index])) {
            return 0;
        }
        $target = self::normalize((string) $block_texts[$index]);
        $occ    = 0;
        for ($i = 0; $i < $index; $i++) {
            if (self::normalize((string) $block_texts[$i]) === $target) {
                $occ++;
            }
        }
        return $occ;
    }

    // =====================================================================
    // Section contracts v2 (FROZEN 2026-07-09) — reference implementation.
    // The connector's single-file mirror MUST stay behavior-identical to the
    // four methods below (same sync contract as normalize/visible_text).
    // =====================================================================

    /**
     * Section identity fingerprint v2: each paragraph's visible text run
     * through normalization spec v1, joined with "\n". Empty array → '' (a
     * heading with no paragraphs is a legal, empty-bodied section).
     *
     * @param array<int,string> $texts Paragraph visible texts, document order.
     */
    public static function fingerprint(array $texts): string
    {
        return implode("\n", array_map(static fn($t) => self::normalize((string) $t), $texts));
    }

    /**
     * Top-level <h1-6>/<p> blocks of an HTML string, document order, with
     * offsets — the section engine's working set. Same boundary-regex family
     * as replace_block/scan-content (non-nestable tags, so boundaries are
     * exact even in malformed builder HTML).
     *
     * @return array<int,array{tag:string,level:int,attrs:string,inner:string,
     *                         text:string,html:string,start:int,len:int}>
     *         level = 0 for <p>; text = visible text, NOT normalized.
     */
    public static function parse_blocks(string $html): array
    {
        $out = array();
        if (!preg_match_all('#<(h[1-6]|p)(\s[^>]*)?>(.*?)</\1>#is', $html, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        foreach ($mm as $m) {
            $tag   = strtolower($m[1][0]);
            $out[] = array(
                'tag'   => $tag,
                'level' => ($tag === 'p') ? 0 : (int) substr($tag, 1),
                'attrs' => isset($m[2][0]) ? (string) $m[2][0] : '',
                'inner' => (string) $m[3][0],
                'text'  => self::visible_text((string) $m[3][0]),
                'html'  => (string) $m[0][0],
                'start' => (int) $m[0][1],
                'len'   => strlen((string) $m[0][0]),
            );
        }
        return $out;
    }

    /**
     * Character spans of page CHROME — everything before <body> plus every
     * <header>/<nav>/<footer>/<aside> region. MUST mirror the scan-content
     * stripping (scan REMOVES these regions before inventorying paragraphs),
     * so the serving side sees the SAME block set the fingerprint was built
     * from — a cookie-banner <p> in the site chrome must never break a
     * section match (the 2.8.1 parity fix).
     *
     * @return array<int,array{0:int,1:int}> [start, end) spans.
     */
    public static function chrome_spans(string $html): array
    {
        $spans = array();
        $body  = stripos($html, '<body');
        if ($body !== false && $body > 0) {
            $spans[] = array(0, $body);
        }
        foreach (array('header', 'nav', 'footer', 'aside') as $tag) {
            if (preg_match_all('#<' . $tag . '(\s[^>]*)?>.*?</' . $tag . '>#is', $html, $mm, PREG_OFFSET_CAPTURE)) {
                foreach ($mm[0] as $m) {
                    $spans[] = array((int) $m[1], (int) $m[1] + strlen((string) $m[0]));
                }
            }
        }
        return $spans;
    }

    /** parse_blocks() filtered to CONTENT blocks (outside every chrome span). */
    public static function content_blocks(string $html): array
    {
        $spans = self::chrome_spans($html);
        if (empty($spans)) {
            return self::parse_blocks($html);
        }
        return array_values(array_filter(self::parse_blocks($html), static function ($b) use ($spans) {
            foreach ($spans as $s) {
                if ($b['start'] >= $s[0] && $b['start'] < $s[1]) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * A REPLACEMENT's ordered top-level units: every <h1-6>/<p> block (as in
     * parse_blocks) PLUS any non-empty content between/around them (lists,
     * tables, figures) as raw units — a section rewrite may legally contain
     * `<ul>`/`<ol>` etc., which must never be dropped.
     *
     * @return array<int,array{tag:string,inner:string,html:string}>
     *         tag = '' for raw (non-h/p) units.
     */
    public static function parse_replacement_units(string $html): array
    {
        $units = array();
        $pos   = 0;
        if (preg_match_all('#<(h[1-6]|p)(\s[^>]*)?>(.*?)</\1>#is', $html, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($mm as $m) {
                $start = (int) $m[0][1];
                $gap   = substr($html, $pos, $start - $pos);
                if (trim($gap) !== '') {
                    $units[] = array('tag' => '', 'inner' => '', 'html' => trim($gap));
                }
                $units[] = array(
                    'tag'   => strtolower($m[1][0]),
                    'inner' => (string) $m[3][0],
                    'html'  => (string) $m[0][0],
                );
                $pos = $start + strlen((string) $m[0][0]);
            }
        }
        $tail = substr($html, $pos);
        if (trim($tail) !== '') {
            $units[] = array('tag' => '', 'inner' => '', 'html' => trim($tail));
        }
        return $units;
    }

    /**
     * The section owned by the heading at $blocks[$i]: every following <p>
     * block up to (not including) the next heading block of ANY level —
     * the owner-locked section boundary. Returns the p-blocks' indices.
     *
     * @param array $blocks parse_blocks() output.
     * @param int   $i      Index of the heading block.
     * @return int[]
     */
    private static function section_body_indices(array $blocks, int $i): array
    {
        $body = array();
        for ($j = $i + 1, $n = count($blocks); $j < $n; $j++) {
            if ($blocks[$j]['tag'] !== 'p') {
                break; // next heading = next section
            }
            $body[] = $j;
        }
        return $body;
    }

    /**
     * Locate the section a rule targets: candidates = heading blocks whose
     * normalized visible text AND level match; each candidate VERIFIES its
     * body fingerprint. Exactly one verified → that one; several (identical
     * twin sections) → the occurrence-th; none → null (the caller serves the
     * ORIGINAL and flags stale — the fingerprint is the guard, occurrence is
     * only a hint, so chrome-duplicate headings can never cause a wrong swap).
     *
     * @return array{heading:int,body:int[]}|null Block indices.
     */
    private static function locate_section(array $blocks, string $match_text, int $level, string $fingerprint, int $occurrence): ?array
    {
        $verified = array();
        foreach ($blocks as $i => $b) {
            if ($b['tag'] === 'p' || ($level >= 1 && $b['level'] !== $level)) {
                continue;
            }
            if (self::normalize($b['text']) !== $match_text) {
                continue;
            }
            $body = self::section_body_indices($blocks, $i);
            $fp   = self::fingerprint(array_map(static fn($j) => $blocks[$j]['text'], $body));
            if ($fp === $fingerprint) {
                $verified[] = array('heading' => $i, 'body' => $body);
            }
        }
        if (empty($verified)) {
            return null;
        }
        return $verified[min(max(0, $occurrence), count($verified) - 1)];
    }

    /**
     * Apply one `section` (replace) rule v2 — all-or-nothing, wrapper-safe:
     * replacement blocks map 1:1 onto the original blocks [heading, p1..pn];
     * same tag → keep the ORIGINAL block's attributes, swap inner (builder
     * styling survives); different tag → whole-block swap; surplus NEW blocks
     * ride as siblings after the last mapped block; surplus ORIGINAL blocks
     * are removed whole. Content BETWEEN blocks (images, divs) is untouched
     * by construction. Returns new HTML, or NULL on no verified section
     * (caller serves the original — never a guess).
     *
     * @param string $html        Full document HTML.
     * @param string $match_text  Normalized heading text (spec v1).
     * @param int    $level       Heading level (1–6).
     * @param string $fingerprint Section fingerprint v2.
     * @param int    $occurrence  Hint among verified twins.
     * @param string $replacement Pre-sanitized section block HTML.
     */
    public static function apply_section_rule(string $html, string $match_text, int $level, string $fingerprint, int $occurrence, string $replacement): ?string
    {
        if ($match_text === '') {
            return null;
        }
        // CONTENT blocks only (chrome excluded) — parity with the scan the
        // fingerprint was computed from; offsets stay true to the full buffer.
        $blocks = self::content_blocks($html);
        $hit    = self::locate_section($blocks, $match_text, $level, $fingerprint, $occurrence);
        if ($hit === null) {
            return null;
        }
        $orig_idx = array_merge(array($hit['heading']), $hit['body']);
        $units    = self::parse_replacement_units($replacement);
        if (empty($units)) {
            return null; // an empty section replacement is never valid — revert deletes the rule instead
        }
        // Build per-block edits, then apply in REVERSE offset order (offsets stay valid).
        $edits  = array();
        $shared = min(count($orig_idx), count($units));
        for ($k = 0; $k < $shared; $k++) {
            $o = $blocks[$orig_idx[$k]];
            $n = $units[$k];
            $new_html = ($n['tag'] !== '' && $o['tag'] === $n['tag'])
                ? '<' . $o['tag'] . $o['attrs'] . '>' . $n['inner'] . '</' . $o['tag'] . '>'
                : $n['html'];
            $edits[] = array('start' => $o['start'], 'len' => $o['len'], 'html' => $new_html);
        }
        // Surplus NEW units: siblings appended right after the last mapped original block.
        if (count($units) > $shared) {
            $last  = $blocks[$orig_idx[$shared - 1]];
            $extra = '';
            for ($k = $shared; $k < count($units); $k++) {
                $extra .= $units[$k]['html'];
            }
            $edits[] = array('start' => $last['start'] + $last['len'], 'len' => 0, 'html' => $extra);
        }
        // Surplus ORIGINAL blocks: removed whole (their wrappers stay).
        for ($k = $shared; $k < count($orig_idx); $k++) {
            $o       = $blocks[$orig_idx[$k]];
            $edits[] = array('start' => $o['start'], 'len' => $o['len'], 'html' => '');
        }
        usort($edits, static fn($a, $b) => $b['start'] <=> $a['start']);
        foreach ($edits as $e) {
            $html = substr_replace($html, $e['html'], $e['start'], $e['len']);
        }
        return $html;
    }

    /**
     * Apply one `sectionInsert` rule v2: insert a complete new section before
     * the anchor heading block, or after the anchor section's last block.
     * Inserts key on the heading ONLY (no fingerprint gate — the anchor
     * section's body may legitimately change). Anchor missing → NULL (nothing
     * inserted, caller flags stale).
     *
     * @param string $html        Full document HTML.
     * @param string $match_text  Normalized ANCHOR heading text.
     * @param int    $level       Anchor heading level (1–6).
     * @param string $position    'before' | 'after'.
     * @param int    $occurrence  0-based among matching anchor headings.
     * @param string $replacement Pre-sanitized new-section block HTML.
     */
    public static function apply_section_insert(string $html, string $match_text, int $level, string $position, int $occurrence, string $replacement): ?string
    {
        if ($match_text === '' || trim($replacement) === '') {
            return null;
        }
        $blocks     = self::content_blocks($html); // chrome-excluded (scan parity)
        $candidates = array();
        foreach ($blocks as $i => $b) {
            if ($b['tag'] === 'p' || ($level >= 1 && $b['level'] !== $level)) {
                continue;
            }
            if (self::normalize($b['text']) === $match_text) {
                $candidates[] = $i;
            }
        }
        if (empty($candidates)) {
            return null;
        }
        $i = $candidates[min(max(0, $occurrence), count($candidates) - 1)];
        if ($position === 'before') {
            $at = $blocks[$i]['start'];
        } else {
            // 'after' = before the NEXT section's heading block when one exists —
            // top-level placement, outside the anchor's builder wrappers. Only the
            // page's LAST section falls back to after-its-last-block (which may sit
            // inside a wrapper — the documented container-inheritance limitation).
            $body = self::section_body_indices($blocks, $i);
            $next = empty($body) ? $i + 1 : end($body) + 1;
            if (isset($blocks[$next]) && $blocks[$next]['tag'] !== 'p') {
                $at = $blocks[$next]['start'];
            } else {
                $last = empty($body) ? $blocks[$i] : $blocks[end($body)];
                $at   = $last['start'] + $last['len'];
            }
        }
        return substr_replace($html, $replacement, $at, 0);
    }
}
