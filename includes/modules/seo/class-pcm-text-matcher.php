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
}
