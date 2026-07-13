<?php
/**
 * PCM_Text_Matcher — the dynamic-rule matcher (pure PHP, NO WordPress deps).
 *
 * Implements normalization spec v1 and the PARSING/IDENTITY primitives the hub
 * uses to parse page snapshots and compute rule identities
 * (docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md → "Cleanup contracts v3").
 *
 * SERVING lives in the CONNECTOR ONLY (3.0.0 — the hub's apply mirror is
 * gone). The connector's copies of the primitives below are enforced
 * behavior-identical by the committed extraction harness
 * (tests/standalone/run.php), which runs the connector's REAL extracted
 * source against the same fixtures — no hand-maintained sync contract.
 *
 * Block parsing uses boundary matching on NON-NESTABLE tags (<p>, <h1..6>):
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
     * Image-src identity (engine v2.3): entity-decode + trim ONLY. NO case
     * fold (URL paths are case-sensitive), query string KEPT (it is
     * identity). The connector's pcm_conn_normalize_src is harness-pinned
     * behavior-identical.
     */
    public static function normalize_src(string $src): string
    {
        return trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Redirect-path identity (connector 3.0.5): URL or path → decoded path,
     * query/fragment stripped, case PRESERVED, no trailing slash, '/' floor.
     * The connector's pcm_conn_redirect_norm_path is harness-pinned
     * behavior-identical — the hub's UPSERT identity and the connector's
     * request matching MUST agree byte-for-byte.
     */
    public static function normalize_path(string $path): string
    {
        $p = $path;
        if ($p === '') {
            return '/';
        }
        if (preg_match('#^([a-z][a-z0-9+.-]*:)?//#i', $p)) {
            $p = (string) (parse_url($p, PHP_URL_PATH) ?: '/');
        } else {
            $p = substr($p, 0, strcspn($p, '?#'));
        }
        $p = rawurldecode($p);
        if ($p === '' || $p[0] !== '/') {
            $p = '/' . $p;
        }
        $p = rtrim($p, '/');
        return $p === '' ? '/' : $p;
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

}
