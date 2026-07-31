<?php
/**
 * SEO — extracted VERBATIM from PCM_SEO_Service (2026-07-31 decomposition).
 *
 * Section: PAGE INVENTORY (cleanup C2)
 * Calls that remain on PCM_SEO_Service are qualified explicitly; anything still
 * written `self::` is intra-class. Callees promoted private→public for this move
 * are listed in service.php's class docblock.
 *
 * PROMOTED private→public (2026-07-31): split_unit_sections, section_runs,
 * assemble_content_html, rules_to_schema. They were private helpers of the SAVE
 * TRANSACTION back when both lived on PCM_SEO_Service; that code is now
 * PCM_SEO_Editing, so the calls are cross-class and private would fatal at
 * runtime ("Call to private method … from scope PCM_SEO_Editing"). Not public API.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_SEO_Page_Inventory
{
    // docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md → "Cleanup contracts v3".
    // =====================================================================

    /**
     * Fetch a v3 connector's page snapshot (`{html, tier[, error]}`), or NULL
     * when the connector predates 3.0.0 (callers compose from the legacy
     * scanners instead). Two views (contracts v2.2): 'input' = the page as
     * RULES-INPUT (identity space), 'served' = what a visitor sees.
     */
    public static function remote_fetch_snapshot(object $site, int $post_id, string $mode = 'input'): ?array
    {
        PCM_SEO_Service::ensure_sites_service();
        if (self::connector_rules_schema_version($site) < 3) {
            return null;
        }
        $args = array('post_id' => $post_id);
        if ($mode === 'served') {
            $args['mode'] = 'served';
        }
        $res = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/snapshot', $args, null, 30);
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return null;
        }
        return array(
            'html'  => (string) ($res['body']['html'] ?? ''),
            'tier'  => (string) ($res['body']['tier'] ?? ''),
            // 3.0.1 marks its view; a 3.0.0 connector ignores ?mode entirely —
            // the missing marker tells callers the served view is unavailable.
            'view'  => (string) ($res['body']['view'] ?? 'input'),
            'error' => isset($res['body']['error']) ? (string) $res['body']['error'] : '',
            // The connector's page-state ECHO (frozen contract: the value the
            // last accepted push carried, stored — never recomputed). NULL on
            // pre-versioning connectors: honest ignorance, not a state.
            'pageState' => (isset($res['body']['pageState']) && is_array($res['body']['pageState'])) ? array(
                'version'     => (int) ($res['body']['pageState']['version'] ?? 0),
                'fingerprint' => (string) ($res['body']['pageState']['fingerprint'] ?? ''),
            ) : null,
        );
    }

    /** Every ACTIVE rule shaping this post's served view (site scope included). */
    private static function rule_rows_for_display(int $user_id, int $site_id, int $post_id): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_dynamic_rules');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, postId, target, matchText, occurrence, replacement, anchorContext, active FROM {$table} WHERE userId = %d AND siteId = %d AND postId IN (0, %d) AND active = 1 ORDER BY id ASC",
            $user_id,
            $site_id,
            $post_id
        ), ARRAY_A);
    }

    /**
     * Split an ordered unit list (parse_replacement_units) into UNIT-SECTIONS:
     * each heading unit starts one; every following non-heading unit (p or
     * raw) extends it. Units before the first heading belong to NO section
     * (the leading no-heading zone — callers decide what it means).
     *
     * @return array<int,array{from:int,to:int,level:int,norm:string,
     *                         ptexts:array<int,string>}>
     *         from/to = unit indexes; norm = normalized heading visible text;
     *         ptexts = the section's paragraph visible texts (fingerprint input).
     */
    public static function split_unit_sections(array $units): array
    {
        $secs = array();
        $cur  = null;
        foreach ($units as $ui => $u) {
            if (preg_match('/^h([1-6])$/', (string) $u['tag'], $m)) {
                if ($cur !== null) {
                    $secs[] = $cur;
                }
                $cur = array(
                    'from'   => $ui,
                    'to'     => $ui,
                    'level'  => (int) $m[1],
                    'norm'   => PCM_Text_Matcher::normalize(PCM_Text_Matcher::visible_text((string) $u['inner'])),
                    'ptexts' => array(),
                );
            } elseif ($cur !== null) {
                $cur['to'] = $ui;
                if ((string) $u['tag'] === 'p') {
                    $cur['ptexts'][] = PCM_Text_Matcher::visible_text((string) $u['inner']);
                }
            }
        }
        if ($cur !== null) {
            $secs[] = $cur;
        }
        return $secs;
    }

    /**
     * Display-section pairing (the owner-locked grouping law): contiguous
     * same-anchor RUNS of paragraph nodes; the k-th run of a key belongs to
     * the k-th CONTENT heading with that key.
     *
     * @return array<int,array> heading index → its paragraph nodes.
     */
    public static function section_runs(array $headings, array $nodes): array
    {
        $runs = array();
        foreach ($nodes as $n) {
            $key  = (isset($n['anchor']) && is_array($n['anchor'])) ? ($n['anchor']['level'] . '|' . $n['anchor']['text']) : '';
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last]['key'] === $key) {
                $runs[$last]['paras'][] = $n;
            } else {
                $runs[] = array('key' => $key, 'paras' => array($n));
            }
        }
        $heads_by_key = array();
        foreach ($headings as $i => $h) {
            if (((string) ($h['scope'] ?? 'post')) === 'site') {
                continue;
            }
            $heads_by_key[$h['level'] . '|' . PCM_Text_Matcher::normalize((string) $h['text'])][] = $i;
        }
        $run_for = array();
        $run_occ = array();
        foreach ($runs as $r) {
            if ($r['key'] === '') {
                continue;
            }
            $occ               = isset($run_occ[$r['key']]) ? $run_occ[$r['key']] : 0;
            $run_occ[$r['key']] = $occ + 1;
            if (isset($heads_by_key[$r['key']][$occ])) {
                $run_for[$heads_by_key[$r['key']][$occ]] = $r['paras'];
            }
        }
        return $run_for;
    }

    /**
     * ATTRIBUTION (served-truth law, contracts v2.2): map every served row to
     * the rule that produced it. Section/sectionInsert replacements are split
     * into UNIT-SECTIONS (each heading unit starts one); a served section
     * matches a unit-section on (normalized heading, level, paragraph
     * fingerprint) — the row then carries `rule: {id, target, unitFrom,
     * unitTo, whole, sliceHtml}` and the editor edits that slice. Heading
     * rules attribute by their output (replacement text + newLevel);
     * paragraph rules mark their node `optimized`. Unmatched rows are
     * ORIGINAL content — their identity fields (computed from the served
     * parse) equal the rules-input identity by definition.
     */
    private static function attribute_inventory(array $headings, array $nodes, array $rules): array
    {
        $run_for = self::section_runs($headings, $nodes);
        // Rule outputs.
        $unitmaps  = array();
        $headrules = array();
        $pararules = array();
        foreach ($rules as $r) {
            $t = (string) ($r['target'] ?? '');
            if ($t === 'section' || $t === 'sectionInsert') {
                $units      = PCM_Text_Matcher::parse_replacement_units((string) $r['replacement']);
                $unitmaps[] = array('rule' => $r, 'sections' => self::split_unit_sections($units), 'unitCount' => count($units), 'units' => $units);
            } elseif ($t === 'heading') {
                $ctx         = json_decode((string) ($r['anchorContext'] ?? ''), true);
                $ctx         = is_array($ctx) ? $ctx : array();
                $headrules[] = array(
                    'id'    => (int) $r['id'],
                    'norm'  => PCM_Text_Matcher::normalize((string) $r['replacement']),
                    'level' => (int) ($ctx['newLevel'] ?? 0),
                    'scope' => ((string) ($ctx['scope'] ?? 'post')) === 'site' ? 'site' : 'post',
                );
            } elseif ($t === 'paragraph') {
                $pararules[] = array('id' => (int) $r['id'], 'norm' => PCM_Text_Matcher::normalize(PCM_Text_Matcher::visible_text((string) $r['replacement'])));
            }
        }
        foreach ($headings as $i => $h) {
            $norm    = PCM_Text_Matcher::normalize((string) $h['text']);
            $is_site = ((string) ($h['scope'] ?? 'post')) === 'site';
            if (!$is_site) {
                $paras = isset($run_for[$i]) ? $run_for[$i] : array();
                $fp    = PCM_Text_Matcher::fingerprint(array_map(static fn($p) => (string) $p['text'], $paras));
                $hit   = null;
                foreach ($unitmaps as $um) {
                    foreach ($um['sections'] as $sec) {
                        if ($sec['level'] !== (int) $h['level'] || $sec['norm'] !== $norm || PCM_Text_Matcher::fingerprint($sec['ptexts']) !== $fp) {
                            continue;
                        }
                        $slice = '';
                        for ($ui = $sec['from']; $ui <= $sec['to']; $ui++) {
                            $slice .= $um['units'][$ui]['html'];
                        }
                        $hit = array(
                            'id'        => (int) $um['rule']['id'],
                            'target'    => (string) $um['rule']['target'],
                            'unitFrom'  => (int) $sec['from'],
                            'unitTo'    => (int) $sec['to'],
                            'whole'     => ($sec['from'] === 0 && $sec['to'] === $um['unitCount'] - 1),
                            'sliceHtml' => $slice,
                        );
                        break 2;
                    }
                }
                if ($hit !== null) {
                    $headings[$i]['rule']        = $hit;
                    $headings[$i]['source']      = 'override';
                    $headings[$i]['sourceType']  = 'override';
                    $headings[$i]['sourceLabel'] = __('Optimized (render-time, this page)', 'power-creatives');
                    continue;
                }
            }
            foreach ($headrules as $hr) {
                if (($hr['scope'] === 'site') !== $is_site || $hr['level'] !== (int) $h['level'] || $hr['norm'] !== $norm) {
                    continue;
                }
                $headings[$i]['rule']        = array('id' => $hr['id'], 'target' => 'heading');
                $headings[$i]['source']      = 'override';
                $headings[$i]['sourceType']  = 'override';
                $headings[$i]['sourceLabel'] = $is_site
                    ? __('Site-wide override (render-time)', 'power-creatives')
                    : __('Optimized (render-time, this page)', 'power-creatives');
                break;
            }
        }
        foreach ($nodes as $j => $n) {
            $pn = PCM_Text_Matcher::normalize((string) $n['text']);
            foreach ($pararules as $pr) {
                if ($pr['norm'] === $pn) {
                    $nodes[$j]['optimized'] = true;
                    $nodes[$j]['ruleId']    = $pr['id'];
                    break;
                }
            }
        }
        return array('headings' => $headings, 'nodes' => $nodes);
    }

    /**
     * The SERVED-truth inventory (v3 connectors): parse the served view, map
     * every row to its producing rule. Falls back to the rules-input view +
     * display-state law when the served fetch fails (a display view may be
     * stale or missing, never wrong-serving). NULL = pre-v3 connector.
     */
    public static function served_inventory(object $site, int $post_id, ?int $user_id): ?array
    {
        $served = self::remote_fetch_snapshot($site, $post_id, 'served');
        if ($served === null) {
            return null;
        }
        if ($served['view'] !== 'served') {
            // 3.0.0 connector (no served view yet): honest input-view fallback.
            $served['html'] = '';
        }
        if ($served['html'] !== '') {
            $parsed = self::parse_page_snapshot($served['html']);
            if ($user_id) {
                $parsed = self::attribute_inventory($parsed['headings'], $parsed['nodes'], self::rule_rows_for_display((int) $user_id, (int) $site->id, $post_id));
            }
            return array(
                'view'        => 'served',
                'tier'        => $served['tier'],
                'headings'    => $parsed['headings'],
                'nodes'       => $parsed['nodes'],
                // The full-page editor's document (G2) — assembled from the
                // SAME parse the rows come from, never raw builder soup.
                'contentHtml' => self::assemble_content_html($served['html'], $parsed['headings']),
                'error'       => '',
                'pageState'   => $served['pageState'],
            );
        }
        $in = self::remote_fetch_snapshot($site, $post_id, 'input');
        if ($in !== null && $in['html'] !== '') {
            $rules  = $user_id ? self::heading_instructions((int) $user_id, (int) $site->id, $post_id) : array();
            $parsed = self::parse_page_snapshot($in['html'], $rules);
            return array('view' => 'input', 'tier' => $in['tier'], 'headings' => $parsed['headings'], 'nodes' => $parsed['nodes'], 'error' => '', 'pageState' => $in['pageState']);
        }
        return array('view' => 'served', 'tier' => (string) $served['tier'], 'headings' => array(), 'nodes' => array(), 'error' => (string) ($served['error'] !== '' ? $served['error'] : 'loopback_blocked'), 'pageState' => $served['pageState']);
    }

    /**
     * Extract <img> tags from an HTML fragment. With $skip_added, images the
     * platform placed (`data-pcm-added`) stay IN PLACE — they are content,
     * not context, and must never drift to a section's end on round-trips.
     *
     * @return array{0:string,1:array<int,string>} [fragment without extracted imgs, extracted tags in order].
     */
    private static function extract_imgs(string $fragment, bool $skip_added = false): array
    {
        $imgs = array();
        $rest = (string) preg_replace_callback('#<img\b[^>]*>#i', static function ($m) use (&$imgs, $skip_added) {
            if ($skip_added && stripos((string) $m[0], 'data-pcm-added') !== false) {
                return (string) $m[0];
            }
            $imgs[] = (string) $m[0];
            return '';
        }, $fragment);
        return array($rest, $imgs);
    }

    /**
     * The full-page editor's document (G2): a CLEAN block-level assembly of
     * the served CONTENT region — never raw builder soup.
     *
     * - ORIGINAL sections emit bare <hN>/<p> blocks (inner HTML kept, wrapper
     *   attrs dropped — serving keeps the original block's attrs on the
     *   equal-count mapping, and the editor drops unknown attrs anyway).
     * - RULE-OWNED sections emit their owning rule's unit range (sliceHtml)
     *   so the rule's own lists/raw units survive the editor round-trip; the
     *   section's page blocks are skipped (they ARE that slice, served).
     * - EVERY <img> — between blocks, inside a block's inner HTML, or inside
     *   a slice — is extracted as a standalone locked block (data-pcm-locked):
     *   visible context in the editor, stripped again on save (F9 law —
     *   images persist as untouched between-content). Chrome regions are
     *   excluded from gap extraction (a header logo is not page content).
     * - Empty-text paragraphs are dropped (the parse's own empty-p law), so
     *   an untouched round-trip stays fingerprint-identical.
     */
    public static function assemble_content_html(string $html, array $headings): string
    {
        $blocks = PCM_Text_Matcher::content_blocks($html);
        $spans  = PCM_Text_Matcher::chrome_spans($html);
        // Content heading rows in document order — same skip laws as the parse,
        // so the cursor below stays aligned with the block walk.
        $rows = array_values(array_filter($headings, static fn($h) => ((string) ($h['scope'] ?? 'post')) !== 'site'));
        $lock = static fn(string $img): string => (string) preg_replace('#^<img\b#i', '<img data-pcm-locked="1"', $img);
        $gap_imgs = static function (int $from, int $to) use ($html, $spans): array {
            if ($to <= $from) {
                return array();
            }
            $imgs = array();
            if (preg_match_all('#<img\b[^>]*>#i', substr($html, $from, $to - $from), $mm, PREG_OFFSET_CAPTURE)) {
                foreach ($mm[0] as $m) {
                    // A SERVED platform-added img is rule content already
                    // emitted in place by its slice — lifting it as locked
                    // context would duplicate it (F9 genus, live-caught
                    // 2026-07-12: the duplicate even defeats unchanged-skip).
                    if (stripos((string) $m[0], 'data-pcm-added') !== false) {
                        continue;
                    }
                    $abs = $from + (int) $m[1];
                    foreach ($spans as $s) {
                        if ($abs >= $s[0] && $abs < $s[1]) {
                            continue 2;
                        }
                    }
                    $imgs[] = (string) $m[0];
                }
            }
            return $imgs;
        };
        $out      = array();
        $ci       = 0;     // content-heading row cursor
        $in_slice = false; // inside a rule-owned section: its blocks live in the emitted slice
        // Gap scanning covers the WHOLE non-chrome document — before the first
        // block, between blocks, and after the last (identity-completeness law:
        // the editor's image set must equal the connector's counting set, or
        // occurrence indexes diverge and edge images stay invisible/uneditable).
        $prev_end = 0;
        foreach ($blocks as $b) {
            foreach ($gap_imgs($prev_end, (int) $b['start']) as $img) {
                $out[] = $lock($img);
            }
            $prev_end = (int) $b['start'] + (int) $b['len'];
            if ($b['tag'] !== 'p') {
                if (trim((string) $b['text']) === '') {
                    continue; // parse law: empty headings are not rows — keep the cursor aligned
                }
                $row = $rows[$ci] ?? null;
                $ci++;
                $att = ($row && isset($row['rule']) && is_array($row['rule'])
                    && in_array((string) ($row['rule']['target'] ?? ''), array('section', 'sectionInsert'), true))
                    ? $row['rule'] : null;
                if ($att !== null) {
                    // Platform-added images stay in place (content); everything
                    // else lifts out as locked context.
                    list($slice, $imgs) = self::extract_imgs((string) ($att['sliceHtml'] ?? ''), true);
                    // Section ORIGIN (frames, 2026-07-13): the attribution this
                    // walk already computed, carried on the section's heading so
                    // the editor can tell owned/inserted/original apart. Emit-
                    // only metadata: save_page_edits strips it on entry.
                    $origin = ((string) ($att['target'] ?? '')) === 'sectionInsert' ? 'insert' : 'owned';
                    $out[]  = (string) preg_replace('#<h([1-6])(\b[^>]*)>#i', '<h$1$2 data-pcm-origin="' . $origin . '">', trim($slice), 1);
                    foreach ($imgs as $img) {
                        $out[] = $lock($img);
                    }
                    $in_slice = true;
                    continue;
                }
                $in_slice = false;
                list($inner, $imgs) = self::extract_imgs((string) $b['inner']);
                $out[] = '<h' . (int) $b['level'] . ' data-pcm-origin="original">' . $inner . '</h' . (int) $b['level'] . '>';
                foreach ($imgs as $img) {
                    $out[] = $lock($img);
                }
                continue;
            }
            if ($in_slice) {
                continue;
            }
            list($inner, $imgs) = self::extract_imgs((string) $b['inner']);
            $plain = trim(html_entity_decode(trim(PCM_Text_Matcher::visible_text($inner)), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\xC2\xA0");
            if ($plain !== '') {
                $out[] = '<p>' . $inner . '</p>';
            }
            foreach ($imgs as $img) {
                $out[] = $lock($img);
            }
        }
        // Tail gap: images after the last content block (footer stays chrome-excluded).
        foreach ($gap_imgs($prev_end, strlen($html)) as $img) {
            $out[] = $lock($img);
        }
        return implode("\n", array_filter($out, static fn($u) => trim((string) $u) !== ''));
    }

    /**
     * Active heading instructions (compiled overrides) that shape a post's
     * DISPLAY state: site-scope rows (postId 0) + the post's own — the hub
     * applies them to the parsed snapshot (display-state law, contracts v3).
     *
     * @return array[] [{matchText, replacement, level, newLevel}, …]
     */
    private static function heading_instructions(int $user_id, int $site_id, int $post_id): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_dynamic_rules');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT matchText, occurrence, replacement, anchorContext FROM {$table} WHERE userId = %d AND siteId = %d AND postId IN (0, %d) AND target = 'heading' AND active = 1",
            $user_id,
            $site_id,
            $post_id
        ), ARRAY_A);
        $out = array();
        foreach ($rows as $r) {
            $ctx   = json_decode((string) ($r['anchorContext'] ?? ''), true);
            $ctx   = is_array($ctx) ? $ctx : array();
            $out[] = array(
                'matchText'      => (string) $r['matchText'],
                'occurrence'     => (int) $r['occurrence'],
                'allOccurrences' => !empty($ctx['allOccurrences']),
                'replacement'    => (string) $r['replacement'],
                'level'          => (int) ($ctx['level'] ?? 0),
                'newLevel'       => (int) ($ctx['newLevel'] ?? ($ctx['level'] ?? 0)),
            );
        }
        return $out;
    }

    /**
     * Parse ONE snapshot into the outline's full inventory — heading rows +
     * paragraph nodes, exactly the shapes the legacy scanners produced:
     *
     * - Headings: EVERY body heading in document order (chrome INCLUDED —
     *   theme/nav headings are editable), deduped by level|text like the old
     *   rendered pass. Snapshot rows carry no storage handles by design
     *   (handle-resolution law): identity is {text, level, occurrence}.
     * - Paragraphs: chrome-stripped (scan-content parity), nearest preceding
     *   CONTENT heading as the anchor (normalized display text).
     * - Display-state law: active heading instructions transform text/level
     *   BEFORE dedupe/anchoring, so section keys match what the site serves.
     */
    public static function parse_page_snapshot(string $html, array $heading_rules = array()): array
    {
        if (($bpos = stripos($html, '<body')) !== false) {
            $html = substr($html, $bpos);
        }
        // Scope-decision law (v2.2): a heading inside a chrome span is SITE
        // chrome; everything else is PAGE content.
        $spans     = PCM_Text_Matcher::chrome_spans($html);
        $in_chrome = static function (int $start) use ($spans): bool {
            foreach ($spans as $s) {
                if ($start >= $s[0] && $start < $s[1]) {
                    return true;
                }
            }
            return false;
        };
        // Display-state law: heading instructions match in the ORIGINAL-text
        // space, occurrence-aware — exactly what serving does. $match_seen
        // counts CONTENT headings per (level, normalized original); chrome
        // headings never consume content occurrences.
        $match_seen = array();
        $instruct   = static function (int $level, string $text, bool $chrome) use ($heading_rules, &$match_seen): array {
            $norm = PCM_Text_Matcher::normalize($text);
            $occ  = -1;
            if (!$chrome) {
                $mkey              = $level . '|' . $norm;
                $occ               = isset($match_seen[$mkey]) ? $match_seen[$mkey] : 0;
                $match_seen[$mkey] = $occ + 1;
            }
            $out = array('level' => $level, 'text' => $text, 'instructed' => false, 'matchText' => $norm, 'matchLevel' => $level, 'matchOccurrence' => max(0, $occ));
            foreach ($heading_rules as $r) {
                if ((int) ($r['level'] ?? 0) !== $level || (string) ($r['matchText'] ?? '') !== $norm) {
                    continue;
                }
                if (empty($r['allOccurrences']) && (int) ($r['occurrence'] ?? 0) !== $occ) {
                    continue;
                }
                $out['level']      = max(1, min(6, (int) ($r['newLevel'] ?? $level)));
                $out['text']       = (string) ($r['replacement'] ?? $text);
                $out['instructed'] = true;
                break;
            }
            return $out;
        };
        $headings    = array();
        $seen_chrome = array();
        $disp_seen   = array();
        foreach (PCM_Text_Matcher::parse_blocks($html) as $b) {
            if ($b['tag'] === 'p') {
                continue;
            }
            $text = trim($b['text']);
            if ($text === '') {
                continue;
            }
            $chrome = $in_chrome((int) $b['start']);
            $d      = $instruct($b['level'], $text, $chrome);
            if ($chrome) {
                // One row per site-wide item (a menu title repeats in header + footer).
                $key = $d['level'] . '|' . $d['text'];
                if (isset($seen_chrome[$key])) {
                    continue;
                }
                $seen_chrome[$key] = 1;
                $occurrence        = 0;
            } else {
                // Content twins are NEVER deduped (v2.2): each is its own row,
                // individually editable. Occurrence here is DISPLAY-space —
                // the identity section keys use.
                $dkey             = $d['level'] . '|' . PCM_Text_Matcher::normalize($d['text']);
                $occurrence       = isset($disp_seen[$dkey]) ? $disp_seen[$dkey] : 0;
                $disp_seen[$dkey] = $occurrence + 1;
            }
            $headings[] = array(
                'level'           => $d['level'],
                'text'            => $d['text'],
                'html'            => $d['instructed'] ? '' : $b['html'],
                'scope'           => $chrome ? 'site' : 'post',
                'occurrence'      => $occurrence,
                // Rule identity (ORIGINAL-text space) — what an edit targets.
                'matchText'       => $d['matchText'],
                'matchLevel'      => $d['matchLevel'],
                'matchOccurrence' => $d['matchOccurrence'],
                'source'          => $d['instructed'] ? 'override' : 'snapshot',
                'elId'            => '',
                'field'           => '',
                'tagKey'          => '',
                'textKey'         => '',
                'sourcePostId'    => 0,
                'sourceType'      => $d['instructed'] ? 'override' : ($chrome ? 'chrome' : ''),
                'sourceLabel'     => $d['instructed']
                    ? ($chrome ? __('Site-wide override (render-time)', 'power-creatives') : __('Optimized (render-time, this page)', 'power-creatives'))
                    : ($chrome ? __('Site-wide (theme/menu) — an edit changes every page', 'power-creatives') : ''),
                'editable'        => true,
            );
        }
        foreach ($headings as $i => $unused) {
            $headings[$i]['index'] = $i;
            $headings[$i]['id']    = $i;
        }
        $nodes    = array();
        $anchor   = null;
        $occ_seen = array();
        $i        = 0;
        // Second walk = content blocks only; instruction matching restarts its
        // occurrence count (same document order, content headings only — the
        // exact space the first walk counted them in).
        $match_seen = array();
        foreach (PCM_Text_Matcher::content_blocks($html) as $b) {
            if ($b['tag'] !== 'p') {
                $text = trim($b['text']);
                if ($text === '') {
                    continue;
                }
                $d      = $instruct($b['level'], $text, false);
                $anchor = array('level' => $d['level'], 'text' => PCM_Text_Matcher::normalize($d['text']));
                continue;
            }
            $text  = trim($b['text']);
            $plain = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\xC2\xA0");
            if ($plain === '') {
                continue;
            }
            $norm            = PCM_Text_Matcher::normalize($text);
            $occ             = isset($occ_seen[$norm]) ? $occ_seen[$norm] : 0;
            $occ_seen[$norm] = $occ + 1;
            $nodes[]         = array(
                'kind'       => 'paragraph',
                'index'      => $i++,
                'text'       => $text,
                'html'       => $b['html'],
                'occurrence' => $occ,
                'source'     => 'rendered',
                'anchor'     => $anchor,
            );
        }
        return array('headings' => $headings, 'nodes' => $nodes);
    }

    /**
     * The connected post's FULL inventory in one call — the outline's single
     * read. v3 connector → one snapshot, one hub-side parse; pre-3.0 fleet →
     * honest composition from the legacy scanners (the same two calls the UI
     * used to make itself; this fallback dies with the C5 follow-up).
     */
    public static function remote_get_inventory(object $site, int $post_id, string $type, int $user_id): array
    {
        $inv = self::served_inventory($site, $post_id, $user_id);
        if ($inv !== null) {
            $out = array(
                'supported' => true,
                'source'    => 'snapshot',
                'view'      => $inv['view'],
                'tier'      => $inv['tier'],
                'headings'  => $inv['headings'],
                'nodes'     => $inv['nodes'],
                // The editor header's context controls (owner order 2026-07-13).
                'pageType'  => $user_id ? PCM_SEO_Service::get_page_type($user_id, (int) $site->id, $post_id) : '',
                'brandId'   => (int) ($site->brandId ?? 0),
                // Page versioning (frozen contract, 2026-07-16): hub record +
                // drift verdict from the connector's echo in this snapshot.
                'pageState' => PCM_SEO_Page_State::page_state_reply((int) $site->id, $post_id, $inv['pageState']),
            );
            if (isset($inv['contentHtml'])) {
                $out['contentHtml'] = (string) $inv['contentHtml'];
            }
            if ($inv['error'] !== '') {
                $out['error'] = $inv['error'];
            }
            return $out;
        }
        $headings = PCM_SEO_Remote_Headings::remote_get_headings($site, $post_id, $type, $user_id);
        $meta     = PCM_SEO_Service::remote_get_content_nodes($site, $post_id);
        $out      = array(
            'supported' => (bool) $meta['supported'],
            'source'    => 'scan',
            'view'      => 'input',
            'headings'  => $headings,
            'nodes'     => (array) $meta['nodes'],
            // Pre-3.0 fleet: no snapshot, no echo — the record with an honest
            // no-echo verdict (drifted:false).
            'pageState' => PCM_SEO_Page_State::page_state_reply((int) $site->id, $post_id, null),
        );
        if (!empty($meta['error'])) {
            $out['error'] = (string) $meta['error'];
        }
        return $out;
    }

    /** Upload an image (by URL) into a connected site's media library — the
     *  SINGLE existing channel in PCM_Sites_Service (delegation, never duplicate). */
    public static function remote_add_media(object $site, string $image_url)
    {
        PCM_SEO_Service::ensure_sites_service();
        return PCM_Sites_Service::remote_upload_media($site, $image_url);
    }

    /** Whether a connected site's connector accepts rule schema v1 (capability check). */
    public static function connector_supports_rules(object $site): bool
    {
        return self::connector_rules_schema_version($site) >= 1;
    }

    /**
     * The highest rule-schema version a connected site's connector accepts:
     * 0 = none (pre-2.7.0), 1 = paragraph rules (2.7.x), 2 = + section rules
     * (2.8.0+). Read from GET /pcm-conn/v1/rules `schemaVersion` — the single
     * capability handle for every push decision.
     */
    public static function connector_rules_schema_version(object $site): int
    {
        // Per-request memo: several code paths check capability for the same
        // site in one request (inventory, heading edit, rule save) — one GET.
        static $memo = array();
        $key = (int) $site->id;
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        PCM_SEO_Service::ensure_sites_service();
        $res = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/rules');
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || empty($res['body']['supported'])) {
            return $memo[$key] = 0;
        }
        return $memo[$key] = max(1, (int) ($res['body']['schemaVersion'] ?? 1));
    }

    /**
     * Push a post's COMPLETE rule set to its connector (schema v1 — replaces
     * the set; the connector re-normalizes + sanitizes defensively and purges
     * caches). Capability-checked first so an old connector fails honestly.
     *
     * @param array[] $rules Rule rows shaped per rule schema v1.
     * @param array{version:int,fingerprint:string} $page_state The state this
     *        push establishes (frozen contract keys) — the connector stores it
     *        beside the set and echoes it in the snapshot reply; pre-versioning
     *        connectors ignore the key.
     * @return array{stored:int}|\WP_Error
     */
    public static function push_rules(object $site, int $post_id, array $rules, array $page_state)
    {
        PCM_SEO_Service::ensure_sites_service();
        // v2 ONLY when the set contains section targets — posts with plain
        // paragraph rules keep pushing v1, byte-identical to before (zero
        // regression on un-updated connectors).
        $needs_v2 = false;
        $needs_v3 = ($post_id === 0); // site scope exists only in v3
        $needs_v4 = false;            // image target exists only in v4 (3.0.2+)
        $needs_v5 = false;            // sectionRemove + image hidden exist only in v5 (3.0.3+)
        foreach ($rules as $r) {
            $t = (string) ($r['target'] ?? '');
            if ($t === 'section' || $t === 'sectionInsert') {
                $needs_v2 = true;
            }
            if ($t === 'heading') {
                $needs_v3 = true; // SERVED heading rules exist only in v3
            }
            if ($t === 'sectionRemove') {
                $needs_v5 = true;
            }
            if ($t === 'image') {
                $needs_v4 = true;
                $set = json_decode((string) ($r['replacement'] ?? ''), true);
                if (is_array($set) && !empty($set['hidden'])) {
                    $needs_v5 = true;
                }
            }
        }
        $accepts = self::connector_rules_schema_version($site);
        if ($accepts < 1) {
            return new WP_Error(
                'pcm_seo_connector_no_rules',
                __('This site’s connector doesn’t support dynamic rules yet (needs v2.7.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        if ($needs_v5 && $accepts < 5) {
            return new WP_Error(
                'pcm_seo_connector_no_removal',
                __('This site’s connector doesn’t support section removal / image hiding yet (needs v3.0.3+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        if ($needs_v4 && $accepts < 4) {
            return new WP_Error(
                'pcm_seo_connector_no_image_rules',
                __('This site’s connector doesn’t support image metadata rules yet (needs v3.0.2+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        if ($needs_v3 && $accepts < 3) {
            return new WP_Error(
                'pcm_seo_connector_no_heading_rules',
                __('This site’s connector doesn’t support heading instructions yet (needs v3.0.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        if ($needs_v2 && $accepts < 2) {
            return new WP_Error(
                'pcm_seo_connector_no_sections',
                __('This site’s connector doesn’t support section rules yet (needs v2.8.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        $res = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/rules', array(), array(
            'schemaVersion' => $needs_v5 ? 5 : ($needs_v4 ? 4 : ($needs_v3 ? 3 : ($needs_v2 ? 2 : 1))),
            'postId'        => $post_id,
            'rules'         => array_values($rules),
            'pageState'     => array(
                'version'     => (int) ($page_state['version'] ?? 0),
                'fingerprint' => (string) ($page_state['fingerprint'] ?? ''),
            ),
        ), 60);
        if (is_wp_error($res)) {
            return new WP_Error('pcm_seo_rules_push', $res->get_error_message(), array('status' => 502));
        }
        if ((int) ($res['status'] ?? 0) >= 300) {
            $msg = (is_array($res['body'] ?? null) && !empty($res['body']['error']))
                ? (string) $res['body']['error']
                : ('HTTP ' . (int) ($res['status'] ?? 0));
            return new WP_Error('pcm_seo_rules_push', sprintf(__('The connector rejected the rule push (%s).', 'power-creatives'), $msg), array('status' => 502));
        }
        return array('stored' => (int) ($res['body']['stored'] ?? 0));
    }

    /** The hub's stored dynamic rules for one connected post (the UI overlay's source of truth). */
    public function list_dynamic_rules(int $user_id, int $site_id, int $post_id): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_dynamic_rules');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, target, matchText, occurrence, replacement, anchorContext, active, staleCount FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d ORDER BY id ASC",
            $user_id,
            $site_id,
            $post_id
        ), ARRAY_A);
        return array_map(static function ($r) {
            $ctx = null;
            if (!empty($r['anchorContext'])) {
                $decoded = json_decode((string) $r['anchorContext'], true);
                if (is_array($decoded)) {
                    $ctx = $decoded;
                }
            }
            return array(
                'id'          => (int) $r['id'],
                'target'      => (string) $r['target'],
                'matchText'   => (string) $r['matchText'],
                'occurrence'  => (int) $r['occurrence'],
                'replacement' => (string) $r['replacement'],
                'active'      => (bool) (int) $r['active'],
                'staleCount'  => (int) $r['staleCount'],
                // Section rules: {level, fingerprint, paragraphs} / {level, position}
                // (the phase-2 overlay + absorb read these; null on paragraph rules
                // whose anchorContext is display-anchor data, not section identity).
                'section'     => in_array((string) $r['target'], array('section', 'sectionInsert', 'sectionRemove'), true) ? $ctx : null,
            );
        }, (array) $rows);
    }

    /** Map hub rule rows to rule schema v1/v2 payload entries (the push shape). */
    public static function rules_to_schema(array $rows): array
    {
        return array_map(static function ($r) {
            $target = (string) $r['target'];
            $ctx    = null;
            if (!empty($r['anchorContext'])) {
                $decoded = json_decode((string) $r['anchorContext'], true);
                if (is_array($decoded)) {
                    $ctx = $decoded;
                }
            }
            $out = array(
                'id'          => (int) $r['id'],
                'target'      => $target,
                'match'       => array('text' => (string) $r['matchText'], 'occurrence' => (int) $r['occurrence']),
                'replacement' => (string) $r['replacement'],
                'active'      => (bool) (int) $r['active'],
            );
            if (in_array($target, array('section', 'sectionInsert', 'sectionRemove'), true)) {
                // v2/v2.4: anchorContext IS the section identity {level, fingerprint|position}.
                $out['section'] = array(
                    'level' => (int) ($ctx['level'] ?? 0),
                );
                if ($target === 'section' || $target === 'sectionRemove') {
                    $out['section']['fingerprint'] = (string) ($ctx['fingerprint'] ?? '');
                } else {
                    $out['section']['position'] = (string) ($ctx['position'] ?? 'after');
                }
                $out['anchor'] = null;
            } elseif ($target === 'heading') {
                // v3: heading instruction — anchorContext = {level, newLevel,
                // scope, originalText, allOccurrences}. allOccurrences keeps
                // compiled-override semantics (chrome/site + migrated rules);
                // without it serving targets the occurrence-th content twin.
                $out['scope']   = ((string) ($ctx['scope'] ?? 'post')) === 'site' ? 'site' : 'post';
                $out['section'] = array(
                    'level'          => (int) ($ctx['level'] ?? 0),
                    'newLevel'       => (int) ($ctx['newLevel'] ?? ($ctx['level'] ?? 0)),
                    // Site scope ⇒ override semantics BY LAW — rules created
                    // before the flag existed keep serving.
                    'allOccurrences' => !empty($ctx['allOccurrences']) || $out['scope'] === 'site',
                    'frameOnly'      => !empty($ctx['frameOnly']),
                );
                $out['anchor'] = null;
            } else {
                $out['anchor'] = $ctx;
            }
            return $out;
        }, $rows);
    }
    // NOTE (consolidation, 2026-07-11): save_paragraph_rule was DELETED with
    // its endpoints — paragraph-rule CREATION had zero UI callers (the page +
    // section editors superseded it). Existing paragraph rules keep serving
    // and displaying; each dies via the absorb law on the next save touching
    // its section. The connector's paragraph pass follows in the owner-gated
    // fleet-convergence cleanup (deletions ledger).
}
