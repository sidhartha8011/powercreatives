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
        $open  = strripos($before, '<a ');
        $open2 = strripos($before, '<a>');
        $open  = max($open === false ? -1 : $open, $open2 === false ? -1 : $open2);
        $close = strripos($before, '</a>');
        $close = $close === false ? -1 : $close;

        return $open > $close;
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

        $from = 0;
        $len  = strlen($content);

        while ($from < $len) {
            $pos = $case_sensitive
                ? strpos($content, $needle, $from)
                : stripos($content, $needle, $from);

            if ($pos === false) {
                return null;
            }
            if (!self::is_inside_markup($content, $pos)) {
                // Return the text AS IT APPEARS, so wrapping preserves the
                // page's own casing rather than imposing the needle's.
                return array(substr($content, $pos, strlen($needle)), $pos);
            }
            $from = $pos + 1;
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
        foreach ($m[2] as $href) {
            if (self::normalize_url(html_entity_decode($href)) === $target) {
                return true;
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

    /** Scheme/host/slash-insensitive URL key. */
    public static function normalize_url(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $url = preg_replace('#^https?://#i', '', $url);
        $url = preg_replace('#^www\.#i', '', (string) $url);
        $url = preg_replace('#\#.*$#', '', (string) $url);
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
            // Both ends must still exist in the table.
            if (!isset($by_id[$child_id]) || !isset($by_id[$parent_id])) {
                continue;
            }
            if (in_array('up', $directions, true)) {
                $pairs[] = array($child_id, $parent_id, 'up');
            }
            if (in_array('down', $directions, true)) {
                $pairs[] = array($parent_id, $child_id, 'down');
            }
        }

        $out = array();
        foreach ($pairs as $pair) {
            if (count($out) >= self::MAX_PROPOSALS) {
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
                $out[]              = $proposal;
                continue;
            }
            if ($body === '') {
                $proposal['reason'] = 'the source page has no readable content';
                $out[]              = $proposal;
                continue;
            }
            if (self::already_links_to($body, $url)) {
                $proposal['reason'] = 'already linked';
                $out[]              = $proposal;
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

            $out[] = $proposal;
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
    public static function bodies_remote(object $site, array $ids, array $types): array
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
            if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300) {
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

        $updated = wp_update_post(array('ID' => $post_id, 'post_content' => $res['content']), true);
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
