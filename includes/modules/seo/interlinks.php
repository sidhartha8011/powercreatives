<?php
/**
 * PCM_SEO_Interlinks — the SEO table's pillar/cluster overlay and its
 * interlink generator.
 *
 * TWO HALVES, deliberately separated:
 *
 *  1. HIERARCHY (needs the DB): which page is the parent of which. Stored in
 *     {prefix}seo_page_parents, NOT in WordPress' post_parent — setting a real
 *     post_parent on a hierarchical type rewrites the permalink and 404s the
 *     live URL. This is a planning layer; the client's site keeps its structure.
 *     It also works on connected sites with NO connector, unlike the sibling
 *     `clusterLabel` (post meta, silently dropped without one).
 *
 *  2. PROPOSAL + INSERTION (pure PHP, NO WordPress deps): given rows, their
 *     bodies and the parent map, decide which links to propose; given content,
 *     splice one in. Kept pure so tests EXECUTE the real logic against
 *     fixtures instead of asserting what the source says — the lesson this
 *     project keeps re-learning (a scanner asserts a spelling, not a rule).
 *
 * THE ANCHOR LAW: we only ever wrap text that ALREADY EXISTS on the page. No
 * invented copy, no rewriting sentences — an interlink pass must never change
 * what a page says, only what it links. That also means a proposal can be
 * refused honestly ("no safe occurrence") rather than fabricating a sentence.
 *
 * @package PowerCreatives
 * @since   1.7.0 (DB 1.48.0)
 */

if (!defined('PCM_SEO_INTERLINKS_LOADED')) {
    define('PCM_SEO_INTERLINKS_LOADED', true);
}

class PCM_SEO_Interlinks
{
    /** Hard ceiling on proposals per run — a 500-page site must not melt the UI. */
    public const MAX_PROPOSALS = 200;

    /** Most anchor phrases one page may define — a picker, not a thesaurus. */
    public const MAX_ANCHORS = 10;

    // ─────────────────────────────────────────────────────────────────
    // PURE: markup-safety primitives
    // ─────────────────────────────────────────────────────────────────

    /**
     * Is $offset an unsafe place to wrap text — inside a tag, or already
     * inside an <a>? Wrapping either produces broken markup or a nested link.
     *
     * Mirrors the strategy interlinker's guard (PCM_Strategy_Service::
     * is_inside_html_tag) because the hazard is identical; it lives here too
     * rather than being reached across modules, and both are covered by tests.
     *
     * @param string $content Full HTML.
     * @param int    $offset  Byte offset being considered.
     */
    public static function is_inside_markup(string $content, int $offset): bool
    {
        $before = substr($content, 0, $offset);

        // Inside a tag: an unclosed '<' behind us.
        // NB both halves must be compared as !== false. `strrpos(...) > strrpos(...)`
        // reads naturally but is WRONG when the content OPENS with a tag: the
        // missing '>' returns false, false coerces to 0, and '<' at index 0
        // then compares 0 > 0 — reporting the inside of the very first tag as
        // safe to wrap. Caught by seo_interlinks_test before it shipped.
        $lt = strrpos($before, '<');
        $gt = strrpos($before, '>');
        if ($lt !== false && ($gt === false || $lt > $gt)) {
            return true;
        }

        // Inside an anchor: the last <a is more recent than the last </a>.
        // `<a` may be followed by ANY whitespace, not just a space — real markup
        // carries `<a\nhref=…`, and the space-only check nested an <a> inside it
        // (executed and confirmed by the 08-21 audit).
        if (self::inside_element($before, 'a')) {
            return true;
        }

        // Inside a container whose TEXT must never become a link: script/style
        // (wrapping corrupts the code — executed: a JS string variable got an
        // <a> spliced into it), textarea/title (text, not markup), and the
        // h1–h6 headings (linking a page's own heading is not an interlink).
        foreach (array('script', 'style', 'textarea', 'title', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6') as $el) {
            if (self::inside_element($before, $el)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The search pattern for one phrase. Three laws, each audit-executed:
     *
     *  - WORD BOUNDARIES: 'tak' must never match inside 'intakta' — Swedish
     *    compounds made mid-word links constant ("in<a>tak</a>ta" was the
     *    generator's actual output). Unicode-letter lookarounds guard any edge
     *    of the phrase that IS a letter; punctuation edges stay unguarded.
     *  - WHITESPACE: a space in the phrase matches any whitespace run on the
     *    page, in every spelling — \s, a literal NBSP, or the 6-byte "&nbsp;"
     *    entity editors store between words.
     *  - SOFT HYPHENS: hyphenation plugins thread U+00AD / "&shy;" through long
     *    words invisibly ("tak\u{00AD}läggning" reads as "takläggning") —
     *    ignored between characters, and the matched text keeps them.
     */
    private static function build_pattern(string $needle, bool $case_sensitive): string
    {
        $flags = '/u' . ($case_sensitive ? '' : 'i');
        $chars = preg_split('//u', preg_replace('/\s+/u', ' ', $needle), -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false || $chars === array()) {
            return '/' . preg_quote($needle, '/') . $flags;
        }
        $parts = array();
        foreach ($chars as $ch) {
            $parts[] = $ch === ' ' ? '(?:\s|\x{00A0}|&nbsp;)+' : preg_quote($ch, '/');
        }
        $inner  = implode('(?:\x{00AD}|&shy;)*', $parts);
        $before = preg_match('/^\p{L}/u', $needle) ? '(?<!\p{L})' : '';
        $after  = preg_match('/\p{L}$/u', $needle) ? '(?!\p{L})' : '';
        return '/' . $before . $inner . $after . $flags;
    }

    /** Is the end of $before inside an open <$el …> element? */
    private static function inside_element(string $before, string $el): bool
    {
        $open = -1;
        if (preg_match_all('/<' . $el . '(?=[\s>\/])/i', $before, $m, PREG_OFFSET_CAPTURE)) {
            $last = end($m[0]);
            $open = (int) $last[1];
        }
        $close = strripos($before, '</' . $el);
        return $open > ($close === false ? -1 : $close);
    }

    /**
     * First occurrence of $needle in $content that is safe to wrap.
     *
     * @param string $content        Full HTML.
     * @param string $needle         Phrase to find.
     * @param bool   $case_sensitive Exact-case match when true.
     * @return array{0:string,1:int}|null [matched text as it appears, byte offset]
     */
    public static function find_safe_occurrence(string $content, string $needle, bool $case_sensitive = false): ?array
    {
        $needle = trim($needle);
        if ($needle === '' || $content === '') {
            return null;
        }

        // The page's own spellings of the same phrase. Executed against real
        // Swedish bodies, the byte-wise search MISSED all of these (the
        // "generation is not working" report):
        //   - stripos only case-folds ASCII, so "änglamark" never matched
        //     "Änglamark" — every non-ASCII phrase at a sentence start refused;
        //   - WordPress stores "&" as "&amp;" (and typographic quotes as ’ “ ”),
        //     so a plain-typed anchor/title never matched the stored bytes.
        // Each variant is hunted in order; the FIRST safe hit wins.
        // The needle itself can ARRIVE entity-encoded too: remote rows carry
        // title.rendered, where WordPress spells "&" as "&#038;" — decode first
        // so one plain base feeds every stored spelling (audit-executed miss).
        $base = html_entity_decode($needle, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $variants = array($needle);
        if ($base !== $needle) {
            $variants[] = $base;
        }
        $entified = htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        if ($entified !== $base && !in_array($entified, $variants, true)) {
            $variants[] = $entified;
        }
        $curly = str_replace(array("'", '"'), array("\u{2019}", "\u{201D}"), $base);
        if ($curly !== $base) {
            $variants[] = $curly;
            $curly_ent = htmlspecialchars($curly, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
            if ($curly_ent !== $curly) {
                $variants[] = $curly_ent;
            }
        }

        foreach ($variants as $variant) {
            $hit = self::scan_for($content, $variant, $case_sensitive);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
    }

    /**
     * One variant's scan: first SAFE occurrence, case-folded across the whole
     * of Unicode (preg /iu), not just ASCII. Falls back to the byte-wise
     * search when the content is not valid UTF-8 (preg /u refuses it).
     *
     * @return array{0:string,1:int}|null [matched text AS IT APPEARS, byte offset]
     */
    private static function scan_for(string $content, string $needle, bool $case_sensitive): ?array
    {
        $pattern = self::build_pattern($needle, $case_sensitive);
        // Valid pattern probe: preg_match returns 0 (no match) for a healthy
        // pattern and FALSE only on error — so the test must be !== false, not
        // truthiness (0 is falsy and would silently disable the preg path).
        $use_preg = @preg_match($pattern, '') !== false;
        $from = 0;
        $len  = strlen($content);

        while ($from < $len) {
            if ($use_preg) {
                $r = @preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE, $from);
                if ($r === false) {
                    // The SUBJECT refused /u (invalid UTF-8 content) — the
                    // pattern probe can't see that. Byte-wise from here on.
                    $use_preg = false;
                    continue;
                }
                if ($r === 0) {
                    return null;
                }
                $pos  = (int) $m[0][1];
                $text = (string) $m[0][0];
            } else {
                $pos = $case_sensitive ? strpos($content, $needle, $from) : stripos($content, $needle, $from);
                if ($pos === false) {
                    return null;
                }
                $text = substr($content, $pos, strlen($needle));
                // The word-boundary law holds on the fallback path too (ASCII
                // approximation — this path only runs on invalid-UTF-8 content).
                $prev = $pos > 0 ? $content[$pos - 1] : '';
                $next = $content[$pos + strlen($text)] ?? '';
                if ((ctype_alpha($prev) && preg_match('/^[A-Za-z]/', $needle))
                    || (ctype_alpha($next) && preg_match('/[A-Za-z]$/', $needle))) {
                    $from = $pos + 1;
                    continue;
                }
            }
            if (!self::is_inside_markup($content, $pos)) {
                // The text AS IT APPEARS, so wrapping preserves the page's own
                // casing (and entity form) rather than imposing the needle's.
                return array($text, $pos);
            }
            // Advance by one CHARACTER, not one byte: an unsafe hit starting
            // with a multibyte char (Änglamark in an alt attribute) left the
            // /u pattern anchored mid-character, which errors and silently
            // degraded the rest of the scan to the ASCII-only byte search —
            // the later real occurrence was then missed (audit-executed).
            $b = ord($content[$pos]);
            $from = $pos + ($b >= 0xF0 ? 4 : ($b >= 0xE0 ? 3 : ($b >= 0xC0 ? 2 : 1)));
        }
        return null;
    }

    /**
     * Does $content already link to $url? The idempotency guard — running the
     * generator twice must not produce a second identical link.
     *
     * Compares on a normalised form so http/https, trailing slash and www
     * variants of the same target all count as "already linked".
     */
    public static function already_links_to(string $content, string $url): bool
    {
        $target = self::normalize_url($url);
        if ($target === '') {
            return false;
        }

        if (!preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/i', $content, $m)) {
            return false;
        }
        // A RELATIVE href to the same page counts too. Real content links with
        // href="/tandvard/" while the proposal carries the absolute permalink;
        // compared only on the full normalised form those never matched, so the
        // guard waved duplicates through on every run (executed and confirmed).
        $target_path = rtrim((string) parse_url('https://' . $target, PHP_URL_PATH), '/');
        foreach ($m[2] as $href) {
            $href = html_entity_decode($href);
            if (self::normalize_url($href) === $target) {
                return true;
            }
            // Single leading slash only: "//other-site.se/…" is a SCHEME-RELATIVE
            // link to ANOTHER host, whose path merely coincides — counting it
            // wrongly refused real proposals as "already linked" (audit-executed).
            if ($target_path !== '' && ($href[0] ?? '') === '/' && ($href[1] ?? '') !== '/') {
                $href_path = rtrim((string) parse_url($href, PHP_URL_PATH), '/');
                if ($href_path === $target_path) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * The human-readable path of a permalink, for telling two pages apart.
     *
     * Titles alone are NOT an identity: a site can carry the same title on a
     * post and a page, and the proposal list then reads "X → X", which looks
     * like the generator proposing a self-link. Owner hit exactly that.
     */
    public static function path_of(string $url): string
    {
        $path = (string) parse_url(trim($url), PHP_URL_PATH);
        // '/' counts as no path, not just ''. A DRAFT's permalink is
        // `/?page_id=9`, whose path parses to '/' — so without this every draft
        // would display an identical '/' and stay exactly as indistinguishable
        // as the titles were. (Same permalink shape behind the 08-13 GSC
        // draft/homepage collision.)
        if ($path === '' || $path === '/') {
            $q = (string) parse_url(trim($url), PHP_URL_QUERY);
            return $q !== '' ? '?' . $q : $path;
        }
        return $path;
    }

    /**
     * Scheme/host-case/www/slash/encoding-insensitive URL key.
     *
     * The duplicate guard compares on this, so every spelling of the same page
     * must collapse to one key. Executed audit misses now covered: an
     * UPPERCASE host ("https://Kliniken.SE/…"), a percent-encoded path
     * ("/tandv%C3%A5rd/" vs "/tandvård/"), and tracking query params
     * (?utm_…/fbclid/gclid) — all of them let a second identical link in.
     */
    public static function normalize_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $url = preg_replace('#^https?://#i', '', $url);
        $url = preg_replace('#^www\.#i', '', (string) $url);
        $url = preg_replace('#\#.*$#', '', (string) $url);
        // Host is case-insensitive by spec; paths on WordPress are lowercase
        // slugs, so lowercasing the host segment only (up to the first '/').
        $slash = strpos((string) $url, '/');
        if ($slash !== false) {
            $url = strtolower(substr((string) $url, 0, $slash)) . substr((string) $url, $slash);
        } else {
            $url = strtolower((string) $url);
        }
        // Percent-decoding: /tandv%C3%A5rd/ and /tandvård/ are the same page.
        $url = rawurldecode((string) $url);
        // Tracking params never change the page.
        $url = preg_replace('/[?&](?:utm_[a-z]+|fbclid|gclid|msclkid)=[^&#]*/i', '', (string) $url);
        $url = rtrim((string) $url, '?&');
        return rtrim((string) $url, '/');
    }

    /**
     * Splice a link into $content by wrapping an existing occurrence of $phrase.
     *
     * @return array{content:string,anchor:string,offset:int}|null
     *         null = no safe occurrence, or already linked (both are honest
     *         refusals, not errors).
     */
    public static function insert_link(string $content, string $url, string $phrase, bool $case_sensitive = false): ?array
    {
        if (trim($url) === '' || self::already_links_to($content, $url)) {
            return null;
        }

        $safe = self::find_safe_occurrence($content, $phrase, $case_sensitive);
        if ($safe === null) {
            return null;
        }

        list($text, $offset) = $safe;

        // esc_url/esc_attr when WordPress is present; the pure fallback keeps
        // the primitive testable standalone (tests require this file alone).
        $href   = function_exists('esc_url') ? esc_url($url) : htmlspecialchars($url, ENT_QUOTES);
        $anchor = '<a href="' . $href . '">' . $text . '</a>';

        return array(
            'content' => substr_replace($content, $anchor, $offset, strlen($text)),
            'anchor'  => $text,
            'offset'  => $offset,
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // PURE: hierarchy shape
    // ─────────────────────────────────────────────────────────────────

    /**
     * Would making $parent_id the parent of $post_id create a cycle?
     *
     * Without this a user can build A→B→A, and every tree walk after that
     * loops forever. Checked BEFORE the write, on the map as it stands.
     *
     * @param array<int,int> $map       childId => parentId (current state).
     * @param int            $post_id   The child being reparented.
     * @param int            $parent_id The proposed parent.
     */
    public static function would_cycle(array $map, int $post_id, int $parent_id): bool
    {
        if ($post_id <= 0 || $parent_id <= 0) {
            return false;
        }
        if ($post_id === $parent_id) {
            return true; // a page cannot be its own parent
        }

        // Walk UP from the proposed parent; if we reach the child, it's a loop.
        $seen   = array();
        $cursor = $parent_id;
        while ($cursor > 0 && !isset($seen[$cursor])) {
            if ($cursor === $post_id) {
                return true;
            }
            $seen[$cursor] = true;
            $cursor        = isset($map[$cursor]) ? (int) $map[$cursor] : 0;
        }
        return false;
    }

    /**
     * Depth of each page in the tree, for the indented view.
     * Orphaned parents (parent id no longer in the row set) are treated as
     * roots so a deleted page never hides its children.
     *
     * @param int[]          $ids Every page id present in the table.
     * @param array<int,int> $map childId => parentId.
     * @return array<int,int> pageId => depth (0 = root)
     */
    public static function depths(array $ids, array $map): array
    {
        $present = array_flip($ids);
        $out     = array();

        foreach ($ids as $id) {
            $id    = (int) $id;
            $depth = 0;
            $seen  = array();
            $c     = isset($map[$id]) ? (int) $map[$id] : 0;
            while ($c > 0 && isset($present[$c]) && !isset($seen[$c]) && $depth < 32) {
                $seen[$c] = true;
                $depth++;
                $c = isset($map[$c]) ? (int) $map[$c] : 0;
            }
            $out[$id] = $depth;
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────
    // PURE: proposal engine
    // ─────────────────────────────────────────────────────────────────

    /**
     * Build the interlink proposals implied by the hierarchy.
     *
     * Pillar/cluster linking, both directions:
     *   - UP:   every child links to its parent (the pillar).
     *   - DOWN: the parent links to each of its children.
     * Siblings are deliberately NOT linked — that is O(n²) per cluster and
     * buries the pillar in noise. Say the word and it becomes an option.
     *
     * Nothing here touches the database or WordPress: rows in, proposals out.
     *
     * @param array $rows   [{id,title,permalink,primaryKeyword}] every page in scope.
     * @param array $bodies pageId => that page's HTML.
     * @param array $map    childId => parentId.
     * @param array $opts   ['directions' => ['up','down']]
     * @return array<int,array> proposals, each
     *         {sourceId,targetId,sourceTitle,targetTitle,url,anchor,direction,reason}
     *         `anchor` empty + `reason` set = an honest refusal, shown greyed.
     */
    public static function propose(array $rows, array $bodies, array $map, array $opts = array()): array
    {
        $directions = isset($opts['directions']) && is_array($opts['directions'])
            ? $opts['directions']
            : array('up', 'down');

        $by_id = array();
        foreach ($rows as $r) {
            $by_id[(int) ($r['id'] ?? 0)] = $r;
        }

        $pairs = array();
        $missing_refusals = 0;
        // SEPARATE caps: refusal rows must never starve real proposals. With one
        // shared cap, 200 honest refusals filled it and the single linkable pair
        // after them was dropped unevaluated (audit-executed) — the run then read
        // as "the generator finds nothing". Refusals keep their own cap so a huge
        // site still cannot melt the UI.
        $out   = array();
        $n_act = 0;
        $n_ref = 0;
        $push  = static function (array $p) use (&$out, &$n_act, &$n_ref): void {
            if ($p['anchor'] !== '') {
                $n_act++;
                $out[] = $p;
            } elseif ($n_ref < self::MAX_PROPOSALS) {
                $n_ref++;
                $out[] = $p;
            }
        };
        foreach ($map as $child_id => $parent_id) {
            $child_id  = (int) $child_id;
            $parent_id = (int) $parent_id;
            if ($child_id <= 0 || $parent_id <= 0) {
                continue;
            }
            // A page is never its own pillar. set_parent()'s cycle guard already
            // refuses to STORE this, so reaching here means the row arrived some
            // other way (a hand-edited table, an import). Defence in depth: a
            // self-link is never a useful proposal, and shown as "X → X" it also
            // reads to the user as a bug in the generator.
            if ($child_id === $parent_id) {
                continue;
            }
            // Both ends must still exist in the table — but a missing end is
            // SAID, not skipped. Silently dropping the pair made a run "look
            // smaller" with no trace when a page was deleted or fell past the
            // per-type row cap (audit-executed) — the same hidden-refusal class
            // card 7 already taught this module about.
            if (!isset($by_id[$child_id]) || !isset($by_id[$parent_id])) {
                if ($missing_refusals < self::MAX_PROPOSALS) {
                    $missing_refusals++;
                    $missing = !isset($by_id[$child_id]) ? $child_id : $parent_id;
                    $out[] = array(
                        'sourceId'    => $child_id,
                        'targetId'    => $parent_id,
                        'sourceTitle' => (string) ($by_id[$child_id]['title'] ?? ('#' . $child_id)),
                        'targetTitle' => (string) ($by_id[$parent_id]['title'] ?? ('#' . $parent_id)),
                        'url'         => (string) ($by_id[$parent_id]['permalink'] ?? ''),
                        'direction'   => 'up',
                        'anchor'      => '',
                        'reason'      => sprintf('page #%d is in the hierarchy but not in the table (deleted, or past the row limit)', $missing),
                        'sourcePath'  => self::path_of((string) ($by_id[$child_id]['permalink'] ?? '')),
                        'targetPath'  => self::path_of((string) ($by_id[$parent_id]['permalink'] ?? '')),
                    );
                }
                continue;
            }
            if (in_array('up', $directions, true)) {
                $pairs[] = array($child_id, $parent_id, 'up');
            }
            if (in_array('down', $directions, true)) {
                $pairs[] = array($parent_id, $child_id, 'down');
            }
        }

        foreach ($pairs as $pair) {
            if ($n_act >= self::MAX_PROPOSALS) {
                break;
            }
            list($source_id, $target_id, $direction) = $pair;

            $source = $by_id[$source_id];
            $target = $by_id[$target_id];
            $url    = (string) ($target['permalink'] ?? '');
            $body   = (string) ($bodies[$source_id] ?? '');

            $proposal = array(
                'sourceId'    => $source_id,
                'targetId'    => $target_id,
                'sourceTitle' => (string) ($source['title'] ?? ''),
                'targetTitle' => (string) ($target['title'] ?? ''),
                'url'         => $url,
                'direction'   => $direction,
                'anchor'      => '',
                'reason'      => '',
                // Shown next to the titles so same-titled pages are tellable apart.
                'sourcePath'  => self::path_of((string) ($source['permalink'] ?? '')),
                'targetPath'  => self::path_of($url),
            );

            if ($url === '') {
                $proposal['reason'] = 'the target page has no permalink yet';
                $push($proposal);
                continue;
            }
            if ($body === '') {
                $proposal['reason'] = 'the source page has no readable content';
                $push($proposal);
                continue;
            }
            if (self::already_links_to($body, $url)) {
                $proposal['reason'] = 'already linked';
                $push($proposal);
                continue;
            }

            // Anchor candidates, best first. The target's USER-DEFINED anchors
            // outrank everything — they exist precisely because the automatic
            // candidates can be in the wrong language (the dental site: an
            // ENGLISH primaryKeyword hunted inside SWEDISH copy, so nothing
            // ever matched). Then the keyword, then the title as the honest
            // fallback. Order within the defined list is the user's own.
            $candidates = array();
            foreach ((array) ($opts['anchors'][$target_id] ?? array()) as $defined) {
                if (trim((string) $defined) !== '') {
                    $candidates[] = (string) $defined;
                }
            }
            if (trim((string) ($target['primaryKeyword'] ?? '')) !== '') {
                $candidates[] = (string) $target['primaryKeyword'];
            }
            if (trim((string) ($target['title'] ?? '')) !== '') {
                $candidates[] = (string) $target['title'];
            }

            foreach ($candidates as $phrase) {
                $safe = self::find_safe_occurrence($body, $phrase);
                if ($safe !== null) {
                    $proposal['anchor'] = $safe[0];
                    break;
                }
            }
            if ($proposal['anchor'] === '') {
                $n = count($candidates);
                $proposal['reason'] = 'no safe occurrence of "'
                    . ($candidates ? $candidates[0] : '?')
                    . '"' . ($n > 1 ? sprintf(' (or %d other phrases)', $n - 1) : '')
                    . ' in the source page';
            }

            $push($proposal);
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────
    // DB: the hierarchy overlay
    // ─────────────────────────────────────────────────────────────────

    /**
     * childId => parentId for one scope.
     *
     * @param int $user_id PCM/WP user id owning the overlay.
     * @param int $site_id 0 = this site's own posts, else a connected site.
     * @return array<int,int>
     */
    public static function parent_map(int $user_id, int $site_id): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_page_parents');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT postId, parentPostId FROM {$table} WHERE userId = %d AND siteId = %d",
            $user_id,
            $site_id
        ));

        $map = array();
        foreach ((array) $rows as $r) {
            $map[(int) $r->postId] = (int) $r->parentPostId;
        }
        return $map;
    }

    /**
     * postId => anchor phrases for one scope. Fed to propose() as
     * $opts['anchors'] — kept out of propose() itself so the engine stays pure.
     *
     * @return array<int,string[]>
     */
    public static function anchors_map(int $user_id, int $site_id): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_page_anchors');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT postId, anchors FROM {$table} WHERE userId = %d AND siteId = %d",
            $user_id,
            $site_id
        ));

        $map = array();
        foreach ((array) $rows as $r) {
            $list = json_decode((string) $r->anchors, true);
            if (is_array($list) && $list !== array()) {
                $map[(int) $r->postId] = array_values(array_map('strval', $list));
            }
        }
        return $map;
    }

    /**
     * Set (or clear) a page's anchor phrases.
     *
     * Empty list = clear (delete the row) — that is how anchors are removed,
     * so it is not an error. Phrases are capped at MAX_ANCHORS and 120 chars
     * each; blanks and duplicates are dropped, ORDER PRESERVED (the order is
     * the user's preference ranking, which propose() honours).
     *
     * @param string[] $anchors
     * @return true|WP_Error
     */
    public static function set_anchors(int $user_id, int $site_id, int $post_id, array $anchors)
    {
        if ($post_id <= 0) {
            return new WP_Error('pcm_seo_bad_post', __('Unknown page.', 'power-creatives'), array('status' => 400));
        }

        $clean = array();
        foreach ($anchors as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase === '' || mb_strlen($phrase) > 120 || in_array($phrase, $clean, true)) {
                continue;
            }
            $clean[] = $phrase;
            if (count($clean) >= self::MAX_ANCHORS) {
                break;
            }
        }

        global $wpdb;
        $table = PCM_Schema::table('seo_page_anchors');

        if ($clean === array()) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete(
                $table,
                array('userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id),
                array('%d', '%d', '%d')
            );
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $ok = $wpdb->query($wpdb->prepare(
            "REPLACE INTO {$table} (userId, siteId, postId, anchors) VALUES (%d, %d, %d, %s)",
            $user_id,
            $site_id,
            $post_id,
            wp_json_encode($clean)
        ));

        return $ok === false
            ? new WP_Error('pcm_seo_write', __('Could not save the anchors.', 'power-creatives'), array('status' => 500))
            : true;
    }

    /**
     * Set (or clear) a page's parent.
     *
     * @param int $parent_post_id 0 clears the parent — that is how a page is
     *                            promoted back to a root, so it is not an error.
     * @return true|WP_Error
     */
    public static function set_parent(int $user_id, int $site_id, int $post_id, int $parent_post_id)
    {
        if ($post_id <= 0) {
            return new WP_Error('pcm_seo_bad_post', __('Unknown page.', 'power-creatives'), array('status' => 400));
        }

        global $wpdb;
        $table = PCM_Schema::table('seo_page_parents');

        if ($parent_post_id <= 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete(
                $table,
                array('userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id),
                array('%d', '%d', '%d')
            );
            return true;
        }

        if (self::would_cycle(self::parent_map($user_id, $site_id), $post_id, $parent_post_id)) {
            return new WP_Error(
                'pcm_seo_cycle',
                __('That would make a page its own ancestor.', 'power-creatives'),
                array('status' => 409)
            );
        }

        // REPLACE on the UNIQUE(userId,siteId,postId) key — one parent per page,
        // enforced by the schema rather than by a read-then-write race.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $ok = $wpdb->query($wpdb->prepare(
            "REPLACE INTO {$table} (userId, siteId, postId, parentPostId) VALUES (%d, %d, %d, %d)",
            $user_id,
            $site_id,
            $post_id,
            $parent_post_id
        ));

        return $ok === false
            ? new WP_Error('pcm_seo_write', __('Could not save the parent.', 'power-creatives'), array('status' => 500))
            : true;
    }

    // ─────────────────────────────────────────────────────────────────
    // WORDPRESS: reading bodies, and applying an accepted proposal
    // ─────────────────────────────────────────────────────────────────

    /**
     * Stored content for local posts.
     *
     * @param int[] $ids
     * @return array<int,string> postId => HTML
     */
    public static function bodies_local(array $ids): array
    {
        $out = array();
        foreach ($ids as $id) {
            $id   = (int) $id;
            $post = $id > 0 ? get_post($id) : null;
            if ($post) {
                $out[$id] = (string) $post->post_content;
            }
        }
        return $out;
    }

    /**
     * Stored content for pages on a connected site.
     *
     * One request per page (context=edit for content.raw), so the CALLER must
     * bound the id list — we only ever ask for pages that are actually in the
     * hierarchy, never the whole table. A page whose raw content is empty (a
     * builder layout) is simply absent from the result, and propose() then
     * refuses it honestly rather than pretending it has no anchor.
     *
     * @param int[] $ids
     * @param array<int,string> $types postId => post type
     * @return array<int,string>
     */
    public static function bodies_remote(object $site, array $ids, array $types, ?array &$errors = null): array
    {
        PCM_SEO_Service::ensure_sites_service();
        $out = array();
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $route = PCM_SEO_Service::remote_route($site, (string) ($types[$id] ?? 'page'), $id);
            $res   = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('context' => 'edit', '_fields' => 'content'));
            if (is_wp_error($res)) {
                // The real cause travels with the refusal instead of being
                // swallowed into a generic "no readable content" (audit find:
                // a 401 on every read looked identical to six builder pages).
                if ($errors !== null) {
                    $errors[$id] = $res->get_error_message();
                }
                continue;
            }
            if ((int) ($res['status'] ?? 0) >= 300) {
                if ($errors !== null) {
                    $errors[$id] = sprintf('HTTP %d reading the page', (int) ($res['status'] ?? 0));
                }
                continue;
            }
            $raw = (string) ($res['body']['content']['raw'] ?? '');
            if ($raw !== '') {
                $out[$id] = $raw;
            }
        }
        return $out;
    }

    /**
     * Apply one accepted proposal to a LOCAL post.
     *
     * @return array{anchor:string}|WP_Error
     */
    public static function apply_local(int $post_id, string $url, string $phrase)
    {
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post) {
            return new WP_Error('pcm_seo_not_found', __('Page not found.', 'power-creatives'), array('status' => 404));
        }

        $res = self::insert_link((string) $post->post_content, $url, $phrase);
        if ($res === null) {
            return self::refusal($url, $phrase);
        }

        // wp_slash: wp_update_post() expects SLASHED data and unslashes it — an
        // unslashed write strips the backslashes Gutenberg stores in block
        // attributes (e.g. the -- escapes CSS custom properties use),
        // corrupting the block on the very Accept that added the link. Same
        // lesson local.php:533 already records for update_post_meta.
        $updated = wp_update_post(wp_slash(array('ID' => $post_id, 'post_content' => $res['content'])), true);
        if (is_wp_error($updated)) {
            return $updated;
        }

        PCM_SEO_Local::purge_post_caches($post_id);
        return array('anchor' => $res['anchor']);
    }

    /**
     * Apply one accepted proposal to a page on a connected site.
     *
     * Mirrors remote_rewrite_link_content's contract deliberately — same raw
     * read, same 422 when a builder owns the layout, same verify-after-write —
     * but INSERTS rather than rewriting an nth link, which that function cannot
     * do (its $build callback is anchored to an existing <a>).
     *
     * @return array{anchor:string}|WP_Error
     */
    public static function apply_remote(object $site, int $post_id, string $type, string $url, string $phrase)
    {
        PCM_SEO_Service::ensure_sites_service();
        $route = PCM_SEO_Service::remote_route($site, $type, $post_id);

        $res = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('context' => 'edit', '_fields' => 'content'));
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return new WP_Error(
                'pcm_seo_remote_link',
                __('Could not read the page — the connector’s app password may not have edit access.', 'power-creatives'),
                array('status' => 502)
            );
        }

        $raw = (string) ($res['body']['content']['raw'] ?? '');
        if ($raw === '') {
            // Same wording and status as the link editors use for this case, so
            // a builder page fails identically everywhere in the module.
            return new WP_Error(
                'pcm_seo_no_raw',
                __('This page’s content isn’t editable through the API (e.g. a page-builder layout), so a link can’t be added here.', 'power-creatives'),
                array('status' => 422)
            );
        }

        $ins = self::insert_link($raw, $url, $phrase);
        if ($ins === null) {
            return self::refusal($url, $phrase);
        }

        $put = PCM_Sites_Service::remote_rest($site, 'POST', $route, array(), array('content' => $ins['content']));
        if (is_wp_error($put)) {
            return new WP_Error('pcm_seo_remote_link', $put->get_error_message(), array('status' => 502));
        }
        if ((int) ($put['status'] ?? 0) >= 300) {
            return new WP_Error(
                'pcm_seo_remote_link',
                sprintf(__('Could not save the page (HTTP %d) — the connector’s user may lack edit permission.', 'power-creatives'), (int) $put['status']),
                array('status' => 502)
            );
        }

        // VERIFY the write landed. WordPress can accept a POST and store
        // nothing (the phantom-save class this module has been bitten by twice:
        // SEO meta without a connector, and the silently-ignored author write).
        // Reporting success on an unverified write is the bug, not the write.
        $check = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('context' => 'edit', '_fields' => 'content'));
        if (!is_wp_error($check) && (int) ($check['status'] ?? 0) < 300) {
            $now = (string) ($check['body']['content']['raw'] ?? '');
            if ($now !== '' && !self::already_links_to($now, $url)) {
                return new WP_Error(
                    'pcm_seo_write_ignored',
                    __('The site accepted the save but the link is not there — the page is probably owned by a builder or a plugin is filtering the content.', 'power-creatives'),
                    array('status' => 422)
                );
            }
        }

        return array('anchor' => $ins['anchor']);
    }

    /**
     * Why insert_link() declined. Both reasons are legitimate outcomes, not
     * faults, so they are 422s that name the cause rather than 500s.
     */
    private static function refusal(string $url, string $phrase): WP_Error
    {
        return new WP_Error(
            'pcm_seo_no_anchor',
            sprintf(
                /* translators: %s: the phrase that was looked for. */
                __('Nothing to link: “%s” doesn’t appear as plain text on this page, or the page already links there. An interlink only wraps wording that is already on the page — it never adds new sentences.', 'power-creatives'),
                $phrase
            ),
            array('status' => 422)
        );
    }

    /**
     * Forget a scope's overlay entirely (a site disconnected, say). Not routed —
     * called by cleanup paths.
     */
    public static function clear_scope(int $user_id, int $site_id): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete(
            PCM_Schema::table('seo_page_parents'),
            array('userId' => $user_id, 'siteId' => $site_id),
            array('%d', '%d')
        );
    }
}
