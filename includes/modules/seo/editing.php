<?php
/**
 * SEO — SECTION RULES + THE SAVE TRANSACTION (the connected-site EDITING write path).
 *
 * Extracted VERBATIM from PCM_SEO_Service (2026-07-31, decomposition phase 7).
 * Section-rule contracts (FROZEN 2026-07-09), the atomic save transaction
 * (`save_page_edits`, `save_section_*`), page/section version history, and
 * `get_options()` (which simply lived at the tail of this block).
 *
 * INSTANCE methods, like the originals: PCM_SEO_Service is stateless (no
 * properties, no constructor), so `$this->` only ever calls siblings that moved
 * with them. controller.php holds its own `$this->editing` instance.
 * `VERSION_CAP` moved WITH this block and is therefore still `self::`.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_SEO_Editing
{
    /** Every rule row of one connected post — snapshot source for rollback + push. */
    private static function post_rule_rows(int $user_id, int $site_id, int $post_id): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_dynamic_rules');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, userId, siteId, postId, target, matchText, occurrence, replacement, anchorContext, active, staleCount FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d",
            $user_id,
            $site_id,
            $post_id
        ), ARRAY_A);
    }

    /**
     * Restore a post's rule set from a snapshot (ROLLBACK after a failed push —
     * hub DB must mirror what the connector actually serves; ids are restored
     * explicitly so insert-rule identities survive the rollback).
     */
    private static function restore_rule_rows(int $user_id, int $site_id, int $post_id, array $rows): void
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_dynamic_rules');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete($table, array('userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id), array('%d', '%d', '%d'));
        foreach ($rows as $r) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert($table, array(
                'id'            => (int) $r['id'],
                'userId'        => (int) $r['userId'],
                'siteId'        => (int) $r['siteId'],
                'postId'        => (int) $r['postId'],
                'target'        => (string) $r['target'],
                'matchText'     => (string) $r['matchText'],
                'occurrence'    => (int) $r['occurrence'],
                'replacement'   => (string) $r['replacement'],
                'anchorContext' => $r['anchorContext'] !== null ? (string) $r['anchorContext'] : null,
                'active'        => (int) $r['active'],
                'staleCount'    => (int) $r['staleCount'],
            ), array('%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%d'));
        }
    }

    // ── THE SAVE TRANSACTION (gap ATOMIC-SAVE 2026-07-17): one user intent =
    //    ONE push = ONE version. save_page_edits sets the flag (try/finally,
    //    leak-proof); the TWO chokepoints below — every routed save path flows
    //    through them — then defer instead of acting, and the transaction ends
    //    with a single real push + a flush of the queued version rows.
    //    Outside the flag nothing changes: single-section saves keep their
    //    existing 1-save-1-push behavior. ──
    /** @var bool Page-save transaction open: pushes stub, version rows queue. */
    private static $push_deferred = false;
    /** @var array<int,array{0:int,1:int,2:int,3:string,4:string,5:int,6:string}> record_version args queued until the commit push succeeds. */
    private static $deferred_versions = array();

    /** Push a post's CURRENT hub rule set; on failure restore $snapshot and return the error. */
    private static function push_current_rules_or_rollback(int $user_id, object $site, int $post_id, array $snapshot)
    {
        if (self::$push_deferred) {
            // Inside the save transaction the rows ARE the pending truth —
            // the ONE commit push at the end transmits them (every push sends
            // the full set, so intermediate pushes are redundant by
            // construction — the gap's core fact).
            return array('deferred' => true);
        }
        $rows   = self::post_rule_rows($user_id, (int) $site->id, $post_id);
        $schema = PCM_SEO_Page_Inventory::rules_to_schema($rows);
        // Page state rides EVERY push (frozen contract): the payload carries
        // the NEXT record, the hub records it only after the connector
        // accepted — hub record and connector echo can never disagree about
        // an accepted push. A rejected push rolls the rows back and leaves
        // the record untouched (the connector kept the previous set).
        $next = array(
            'version'     => PCM_SEO_Page_State::page_state((int) $site->id, $post_id)['version'] + 1,
            'fingerprint' => PCM_SEO_Page_State::page_fingerprint($schema),
        );
        $push = PCM_SEO_Page_Inventory::push_rules($site, $post_id, $schema, $next);
        if ($push instanceof WP_Error) {
            self::restore_rule_rows($user_id, (int) $site->id, $post_id, $snapshot);
            return $push;
        }
        PCM_SEO_Page_State::write_page_state((int) $site->id, $post_id, $next['version'], $next['fingerprint']);
        return $push;
    }

    /**
     * Save a SECTION replace rule (contracts v2) — same atomicity law as
     * every rule save: capability BEFORE any write, push-fail ROLLS BACK.
     *
     * UPSERT identity: siteId+postId+target='section'+matchText(heading)+occurrence.
     * CLEAN REVERT: a replacement that reproduces the original section exactly
     * (same heading text+level, same paragraph fingerprint, nothing extra)
     * deletes the rule. ABSORB: paragraph rules covered by this section are
     * deleted in the same push — a paragraph rule must never fight a section
     * rule over the same block (interaction law).
     *
     * @param array{headingText:string,headingLevel:int,headingOccurrence:int,
     *              paragraphs:array<int,array{text:string,occurrence:int}>,
     *              replacement:string} $input
     * @return array|\WP_Error
     */
    public function save_section_rule(int $user_id, object $site, int $post_id, array $input)
    {
        global $wpdb;
        $table       = PCM_Schema::table('seo_dynamic_rules');
        $site_id     = (int) $site->id;
        $match_text  = PCM_Text_Matcher::normalize((string) ($input['headingText'] ?? ''));
        $level       = max(1, min(6, (int) ($input['headingLevel'] ?? 2)));
        $occurrence  = max(0, (int) ($input['headingOccurrence'] ?? 0));
        $replacement = wp_kses_post((string) ($input['replacement'] ?? ''));
        $paragraphs  = array();
        $para_texts  = array();
        foreach ((array) ($input['paragraphs'] ?? array()) as $p) {
            if (!is_array($p) || !isset($p['text'])) {
                continue;
            }
            $paragraphs[] = array('text' => PCM_Text_Matcher::normalize((string) $p['text']), 'occurrence' => max(0, (int) ($p['occurrence'] ?? 0)));
            $para_texts[] = (string) $p['text'];
        }
        $fingerprint = PCM_Text_Matcher::fingerprint($para_texts);
        if ($match_text === '') {
            return new WP_Error('pcm_seo_rule_no_match', __('The section has no matchable heading text.', 'power-creatives'), array('status' => 400));
        }
        $units = PCM_Text_Matcher::parse_replacement_units($replacement);
        if (empty($units)) {
            return new WP_Error('pcm_seo_rule_empty', __('The replacement section is empty.', 'power-creatives'), array('status' => 400));
        }
        // Capability BEFORE any write — an old connector fails honestly, nothing half-done.
        if (PCM_SEO_Page_Inventory::connector_rules_schema_version($site) < 2) {
            return new WP_Error(
                'pcm_seo_connector_no_sections',
                __('This site’s connector doesn’t support section rules yet (needs v2.8.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }

        // CLEAN REVERT: the replacement reproduces the original section exactly —
        // heading unchanged (text + level) and paragraph fingerprint identical.
        $reverted = false;
        if (($units[0]['tag'] ?? '') === 'h' . $level
            && PCM_Text_Matcher::normalize(PCM_Text_Matcher::visible_text($units[0]['inner'])) === $match_text) {
            $rest_all_p = true;
            $rest_texts = array();
            foreach (array_slice($units, 1) as $u) {
                if ($u['tag'] !== 'p') {
                    $rest_all_p = false;
                    break;
                }
                $rest_texts[] = PCM_Text_Matcher::visible_text($u['inner']);
            }
            $reverted = $rest_all_p && PCM_Text_Matcher::fingerprint($rest_texts) === $fingerprint;
        }

        $snapshot = self::post_rule_rows($user_id, $site_id, $post_id);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $prev = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'section' AND matchText = %s AND occurrence = %d",
            $user_id,
            $site_id,
            $post_id,
            $match_text,
            $occurrence
        ), ARRAY_A);

        if ($reverted) {
            if (!$prev) {
                return array('reverted' => true, 'stored' => count($snapshot)); // nothing to do — no push needed
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($table, array('id' => (int) $prev['id']), array('%d'));
        } else {
            $ctx = wp_json_encode(array('level' => $level, 'fingerprint' => $fingerprint, 'paragraphs' => $paragraphs));
            if ($prev) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($table, array('replacement' => $replacement, 'anchorContext' => $ctx, 'active' => 1), array('id' => (int) $prev['id']), array('%s', '%s', '%d'), array('%d'));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert($table, array(
                    'userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id,
                    'target' => 'section', 'matchText' => $match_text, 'occurrence' => $occurrence,
                    'replacement' => $replacement, 'anchorContext' => $ctx, 'active' => 1,
                ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d'));
            }
            // ABSORB (one-owner, tightened 2026-07-11): covered paragraph rules
            // die with this push. A caller may know a paragraph by its ORIGINAL
            // text (unruled display) or by the rule's SERVED OUTPUT (the rule was
            // serving when the section was captured) — matching only the original
            // let a serving paragraph rule survive beside its new section owner
            // (observed live: rules #2 + #21 coexisting). Both forms match now.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $p_rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT id, matchText, occurrence, replacement FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'paragraph'",
                $user_id,
                $site_id,
                $post_id
            ), ARRAY_A);
            foreach ($p_rows as $p_row) {
                $served_out = PCM_Text_Matcher::normalize(PCM_Text_Matcher::visible_text((string) $p_row['replacement']));
                foreach ($paragraphs as $p) {
                    if (((string) $p_row['matchText'] === $p['text'] && (int) $p_row['occurrence'] === $p['occurrence'])
                        || ($served_out !== '' && $served_out === $p['text'])) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->delete($table, array('id' => (int) $p_row['id']), array('%d'));
                        break;
                    }
                }
            }
            // ABSORB (one-owner law, v2.2): a post-scope heading rule whose OUTPUT
            // is this section's heading dies here — its ORIGINAL identity (text,
            // occurrence, level) becomes the section's match key, so the section
            // owns the whole element and no rule chain survives the save.
            $section_rule_id = $prev ? (int) $prev['id'] : (int) $wpdb->insert_id;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $h_rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT id, matchText, occurrence, replacement, anchorContext FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'heading'",
                $user_id,
                $site_id,
                $post_id
            ), ARRAY_A);
            foreach ($h_rows as $hr) {
                $hctx = json_decode((string) ($hr['anchorContext'] ?? ''), true);
                $hctx = is_array($hctx) ? $hctx : array();
                if (PCM_Text_Matcher::normalize((string) $hr['replacement']) !== $match_text || (int) ($hctx['newLevel'] ?? 0) !== $level) {
                    continue;
                }
                $match_text = (string) $hr['matchText'];
                $occurrence = (int) $hr['occurrence'];
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($table, array(
                    'matchText'     => $match_text,
                    'occurrence'    => $occurrence,
                    'anchorContext' => wp_json_encode(array('level' => max(1, min(6, (int) ($hctx['level'] ?? $level))), 'fingerprint' => $fingerprint, 'paragraphs' => $paragraphs)),
                ), array('id' => $section_rule_id), array('%s', '%d', '%s'), array('%d'));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete($table, array('id' => (int) $hr['id']), array('%d'));
                break;
            }
        }

        $push = self::push_current_rules_or_rollback($user_id, $site, $post_id, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        // Version history: record ONLY after the push succeeded (a rolled-back
        // save must never leave a ghost version). Reverts record nothing — the
        // Original is always in the dropdown, read live from the scan.
        if (!$reverted) {
            self::record_version($user_id, $site_id, $post_id, 'section', $match_text, $occurrence, $replacement);
        }
        $out = array('stored' => (int) ($push['stored'] ?? 0));
        if ($reverted) {
            $out['reverted'] = true;
        } else {
            $out['rule'] = array('matchText' => $match_text, 'occurrence' => $occurrence, 'level' => $level, 'fingerprint' => $fingerprint, 'active' => true);
        }
        return $out;
    }

    /** Cap per identity — same runaway-guard pattern as the heading overrides list. */
    private const VERSION_CAP = 20;

    /**
     * Record one accepted state in the version history. Keyed by the row
     * IDENTITY (target + matchText + occurrence: a section's heading key, or
     * target='page' + '' for the whole-page document) — history survives the
     * rule row's deletion on clean revert. Consecutive duplicates are
     * skipped; history is capped (oldest rows dropped).
     */
    private static function record_version(int $user_id, int $site_id, int $post_id, string $target, string $match_text, int $occurrence, string $replacement): void
    {
        if (self::$push_deferred) {
            // Save transaction: history rows must only exist for states the
            // connector ACCEPTED — queued here, flushed after the commit push.
            self::$deferred_versions[] = array($user_id, $site_id, $post_id, $target, $match_text, $occurrence, $replacement);
            return;
        }
        global $wpdb;
        $table = PCM_Schema::table('seo_rule_versions');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $last = $wpdb->get_var($wpdb->prepare(
            "SELECT replacement FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = %s AND matchText = %s AND occurrence = %d ORDER BY id DESC LIMIT 1",
            $user_id,
            $site_id,
            $post_id,
            $target,
            $match_text,
            $occurrence
        ));
        if ($last !== null && (string) $last === $replacement) {
            return; // accepting the same content twice must not stack versions
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert($table, array(
            'userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id,
            'target' => $target, 'matchText' => $match_text, 'occurrence' => $occurrence,
            'replacement' => $replacement,
        ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = %s AND matchText = %s AND occurrence = %d ORDER BY id DESC",
            $user_id,
            $site_id,
            $post_id,
            $target,
            $match_text,
            $occurrence
        ));
        foreach (array_slice(array_map('intval', $ids), self::VERSION_CAP) as $old_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($table, array('id' => $old_id), array('%d'));
        }
    }

    /** Delete one saved version (ownership-checked; the Original is never a row,
     *  so it can never be deleted — it always comes live from the scan). */
    public function delete_section_version(int $user_id, int $version_id)
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_rule_versions');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted = $wpdb->delete($table, array('id' => $version_id, 'userId' => $user_id), array('%d', '%d'));
        if (!$deleted) {
            return new WP_Error('pcm_seo_version_not_found', __('That version no longer exists.', 'power-creatives'), array('status' => 404));
        }
        return array('deleted' => true);
    }

    /** Saved versions for one identity (target + matchText + occurrence), newest first. */
    private static function list_versions(int $user_id, int $site_id, int $post_id, string $target, string $match_text, int $occurrence): array
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_rule_versions');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, replacement, createdAt FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = %s AND matchText = %s AND occurrence = %d ORDER BY id DESC",
            $user_id,
            $site_id,
            $post_id,
            $target,
            $match_text,
            $occurrence
        ), ARRAY_A);
        return array_map(static fn($r) => array(
            'id'          => (int) $r['id'],
            'replacement' => (string) $r['replacement'],
            'createdAt'   => (string) $r['createdAt'],
        ), $rows);
    }

    /**
     * A section's saved versions, newest first — the editor's dropdown.
     *
     * @return array[] [{id, replacement, createdAt}, …]
     */
    public function list_section_versions(int $user_id, int $site_id, int $post_id, string $heading_text, int $occurrence): array
    {
        return self::list_versions($user_id, $site_id, $post_id, 'section', PCM_Text_Matcher::normalize($heading_text), $occurrence);
    }

    /**
     * The PAGE editor's version dropdown in one read: every saved page
     * document (newest first) + the true no-rules ORIGINAL — the rules-INPUT
     * snapshot assembled with the same law as the editor's document. An
     * empty originalHtml is honest (input view unavailable), never a guess.
     *
     * @return array{versions:array[],originalHtml:string}
     */
    public function list_page_versions(int $user_id, object $site, int $post_id, bool $rows_only = false): array
    {
        // ROWS-ONLY (gap 02d3cb7 D1): the editor's OPEN needs the saved rows
        // alone — a pure DB read, milliseconds. The Original (a REMOTE
        // snapshot round-trip) is fetched lazily when the versions dropdown
        // opens — its only consumer. Gating the open on it was the 30s white.
        $rows = array('versions' => self::list_versions($user_id, (int) $site->id, $post_id, 'page', '', 0));
        if ($rows_only) {
            return $rows;
        }
        $original = '';
        $in       = PCM_SEO_Page_Inventory::remote_fetch_snapshot($site, $post_id, 'input');
        if ($in !== null && $in['html'] !== '') {
            // No rules passed → no attribution → every section assembles as
            // original content, which is exactly what "Original" means.
            $parsed   = PCM_SEO_Page_Inventory::parse_page_snapshot($in['html']);
            $original = PCM_SEO_Page_Inventory::assemble_content_html($in['html'], $parsed['headings']);
        }
        return $rows + array('originalHtml' => $original);
    }

    /**
     * Save a SECTION INSERT rule (contracts v2): a complete NEW section anchored
     * before/after an existing heading. Identity = hub rule id (several inserts
     * may share an anchor). Saving an existing insert with an EMPTY replacement
     * deletes it (clean removal). Same capability/rollback laws as above.
     *
     * @param array{anchorText:string,anchorLevel:int,anchorOccurrence:int,
     *              position:string,replacement:string,ruleId?:int} $input
     * @return array|\WP_Error
     */
    public function save_section_insert(int $user_id, object $site, int $post_id, array $input)
    {
        global $wpdb;
        $table       = PCM_Schema::table('seo_dynamic_rules');
        $site_id     = (int) $site->id;
        $match_text  = PCM_Text_Matcher::normalize((string) ($input['anchorText'] ?? ''));
        $level       = max(1, min(6, (int) ($input['anchorLevel'] ?? 2)));
        $occurrence  = max(0, (int) ($input['anchorOccurrence'] ?? 0));
        $position    = ((string) ($input['position'] ?? 'after')) === 'before' ? 'before' : 'after';
        $replacement = wp_kses_post((string) ($input['replacement'] ?? ''));
        $rule_id     = isset($input['ruleId']) ? absint($input['ruleId']) : 0;
        if ($match_text === '') {
            return new WP_Error('pcm_seo_rule_no_match', __('New sections need an existing heading to anchor to.', 'power-creatives'), array('status' => 400));
        }
        if ($rule_id === 0 && trim(PCM_Text_Matcher::visible_text($replacement)) === '') {
            return new WP_Error('pcm_seo_rule_empty', __('The new section is empty.', 'power-creatives'), array('status' => 400));
        }
        if (PCM_SEO_Page_Inventory::connector_rules_schema_version($site) < 2) {
            return new WP_Error(
                'pcm_seo_connector_no_sections',
                __('This site’s connector doesn’t support section rules yet (needs v2.8.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }

        $prev = null;
        if ($rule_id > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $prev = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table} WHERE id = %d AND userId = %d AND siteId = %d AND postId = %d AND target = 'sectionInsert'",
                $rule_id,
                $user_id,
                $site_id,
                $post_id
            ), ARRAY_A);
            if (!$prev) {
                return new WP_Error('pcm_seo_rule_not_found', __('This added section no longer exists — re-open the outline.', 'power-creatives'), array('status' => 404));
            }
        }

        $snapshot = self::post_rule_rows($user_id, $site_id, $post_id);
        $removed  = false;
        $ctx      = wp_json_encode(array('level' => $level, 'position' => $position));
        if ($prev && trim(PCM_Text_Matcher::visible_text($replacement)) === '') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($table, array('id' => (int) $prev['id']), array('%d'));
            $removed = true;
        } elseif ($prev) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $table,
                array('matchText' => $match_text, 'occurrence' => $occurrence, 'replacement' => $replacement, 'anchorContext' => $ctx, 'active' => 1),
                array('id' => (int) $prev['id']),
                array('%s', '%d', '%s', '%s', '%d'),
                array('%d')
            );
            $rule_id = (int) $prev['id'];
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert($table, array(
                'userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id,
                'target' => 'sectionInsert', 'matchText' => $match_text, 'occurrence' => $occurrence,
                'replacement' => $replacement, 'anchorContext' => $ctx, 'active' => 1,
            ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d'));
            $rule_id = (int) $wpdb->insert_id;
        }

        $push = self::push_current_rules_or_rollback($user_id, $site, $post_id, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        $out = array('stored' => (int) ($push['stored'] ?? 0));
        if ($removed) {
            $out['removed'] = true;
        } else {
            $out['rule'] = array('id' => $rule_id, 'matchText' => $match_text, 'occurrence' => $occurrence, 'level' => $level, 'position' => $position, 'active' => true);
        }
        return $out;
    }

    /**
     * Save a heading instruction (instruction stream v2.2 — THE heading edit
     * mechanism on v3 connectors). Identity decides scope: postId 0 = site
     * chrome (allOccurrences, compiled-override semantics), postId N = one
     * page, one occurrence-th twin. UPSERT matches the CURRENT value first
     * (re-edit), then the original identity (stale outline raced the last
     * edit); editing back to the exact original deletes the rule (clean
     * revert); push-fail rolls back.
     *
     * @param array{postId:int,matchText:string,matchLevel:int,occurrence:int,
     *              currentText:string,currentLevel:int} $identity
     */
    public static function save_heading_rule(int $user_id, object $site, array $identity, ?string $new_text, int $new_level)
    {
        global $wpdb;
        $table     = PCM_Schema::table('seo_dynamic_rules');
        $site_id   = (int) $site->id;
        $post_id   = max(0, (int) ($identity['postId'] ?? 0));
        $match     = (string) ($identity['matchText'] ?? '');
        $m_level   = max(1, min(6, (int) ($identity['matchLevel'] ?? 0)));
        $occ       = max(0, (int) ($identity['occurrence'] ?? 0));
        $cur_text  = trim((string) ($identity['currentText'] ?? ''));
        $cur_level = max(1, min(6, (int) ($identity['currentLevel'] ?? $m_level)));
        $new_t     = trim((string) ($new_text !== null && $new_text !== '' ? $new_text : $cur_text));
        if ($match === '' || $new_level < 1 || $new_level > 6) {
            return new WP_Error('pcm_seo_rule_no_match', __('The heading has no matchable text.', 'power-creatives'), array('status' => 400));
        }
        if (PCM_SEO_Page_Inventory::connector_rules_schema_version($site) < 3) {
            return new WP_Error(
                'pcm_seo_connector_no_heading_rules',
                __('This site’s connector doesn’t support heading instructions yet (needs v3.0.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, matchText, occurrence, replacement, anchorContext FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'heading'",
            $user_id,
            $site_id,
            $post_id
        ), ARRAY_A);
        $prev = null;
        foreach ($rows as $r) {
            $ctx = json_decode((string) ($r['anchorContext'] ?? ''), true);
            $ctx = is_array($ctx) ? $ctx : array();
            // Re-edit of an already-instructed heading: the incoming "current"
            // is the rule's own output (what the user sees).
            if ((string) $r['replacement'] === $cur_text && (int) ($ctx['newLevel'] ?? 0) === $cur_level) {
                $prev = array('row' => $r, 'ctx' => $ctx);
                break;
            }
            // Same ORIGINAL identity edited again.
            if ((string) $r['matchText'] === $match && (int) ($ctx['level'] ?? 0) === $m_level && (int) $r['occurrence'] === $occ) {
                $prev = array('row' => $r, 'ctx' => $ctx);
                break;
            }
        }
        $snapshot = self::post_rule_rows($user_id, $site_id, $post_id);
        if ($prev) {
            $ctx      = $prev['ctx'];
            $original = (string) ($ctx['originalText'] ?? '');
            if ($original !== '' && $new_t === $original && $new_level === (int) ($ctx['level'] ?? 0)) {
                // Clean revert: back to the exact original → the rule dies.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete($table, array('id' => (int) $prev['row']['id']), array('%d'));
            } else {
                $ctx['newLevel'] = $new_level;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($table, array('replacement' => $new_t, 'anchorContext' => wp_json_encode($ctx), 'active' => 1), array('id' => (int) $prev['row']['id']), array('%s', '%s', '%d'), array('%d'));
            }
        } else {
            if ($new_t === $cur_text && $new_level === $cur_level) {
                return array('stored' => count($snapshot), 'noop' => true); // no-op edit, no rule
            }
            // originalText: exact when the row was un-instructed (currentText
            // IS the original); an instructed row always resolves to $prev.
            $ctx = array(
                'level'          => $m_level,
                'newLevel'       => $new_level,
                'scope'          => $post_id === 0 ? 'site' : 'post',
                'originalText'   => $cur_text,
                'allOccurrences' => $post_id === 0,
                // Frame edits apply ONLY inside the site frame (header/nav/
                // footer/aside) — identical text in some page's CONTENT is
                // never touched. Migrated legacy overrides keep their old
                // whole-page semantics (no flag).
                'frameOnly'      => $post_id === 0,
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert($table, array(
                'userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id,
                'target' => 'heading', 'matchText' => $match, 'occurrence' => $occ,
                'replacement' => $new_t, 'anchorContext' => wp_json_encode($ctx), 'active' => 1,
            ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d'));
        }
        $push = self::push_current_rules_or_rollback($user_id, $site, $post_id, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        return array('stored' => (int) ($push['stored'] ?? 0));
    }

    /**
     * ONE-OWNER LAW (v2.2, part A): when an active SECTION rule owns this
     * heading (keyed on its identity, replacement carries the heading unit),
     * a heading edit rewrites THAT rule's first unit — never stacks a second
     * rule on the same element. Returns NULL when the heading is not
     * section-owned (caller proceeds to the heading-rule path); array|WP_Error
     * otherwise. The rule's match identity is untouched (it keys on the
     * rules-input, which the replacement never changes); versions + rollback
     * ride the section mechanics.
     */
    public static function update_section_owned_heading(int $user_id, object $site, int $post_id, array $h, ?string $new_text, int $new_level)
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_dynamic_rules');
        $norm  = PCM_Text_Matcher::normalize((string) ($h['text'] ?? ''));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, replacement FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'section' AND matchText = %s AND occurrence = %d AND active = 1",
            $user_id,
            (int) $site->id,
            $post_id,
            $norm,
            (int) ($h['occurrence'] ?? 0)
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        $units = PCM_Text_Matcher::parse_replacement_units((string) $row['replacement']);
        if (empty($units) || !preg_match('/^h[1-6]$/', (string) $units[0]['tag'])
            || PCM_Text_Matcher::normalize(PCM_Text_Matcher::visible_text((string) $units[0]['inner'])) !== $norm) {
            return null; // the replacement doesn't carry this heading — not owned
        }
        return self::update_owned_heading_unit($user_id, $site, (int) $row['id'], 0, (string) $h['text'], $new_text, $new_level);
    }

    /**
     * Swap ONE heading unit inside an owning rule's replacement (one-owner +
     * served-truth laws): text/level change, the unit's own attributes kept,
     * everything else untouched. Push-fail rolls back; section versions ride.
     */
    public static function update_owned_heading_unit(int $user_id, object $site, int $rule_id, int $unit_index, string $current_text, ?string $new_text, int $new_level)
    {
        global $wpdb;
        $table = PCM_Schema::table('seo_dynamic_rules');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, postId, target, matchText, occurrence, replacement FROM {$table} WHERE id = %d AND userId = %d AND siteId = %d AND target IN ('section','sectionInsert') AND active = 1",
            $rule_id,
            $user_id,
            (int) $site->id
        ), ARRAY_A);
        if (!$row) {
            return new WP_Error('pcm_seo_rule_not_found', __('This section no longer exists — re-open the outline.', 'power-creatives'), array('status' => 404));
        }
        $units = PCM_Text_Matcher::parse_replacement_units((string) $row['replacement']);
        if (!isset($units[$unit_index]) || !preg_match('/^h[1-6]$/', (string) $units[$unit_index]['tag'])) {
            return new WP_Error('pcm_seo_heading_stale', __('This page changed since it was scanned — re-open the outline.', 'power-creatives'), array('status' => 409));
        }
        $new_t = trim((string) ($new_text !== null && $new_text !== '' ? $new_text : $current_text));
        $safe  = wp_kses_post($new_t);
        $unit  = preg_replace_callback(
            '/^<h[1-6]([^>]*)>.*<\/h[1-6]>$/is',
            static fn($m) => '<h' . $new_level . $m[1] . '>' . $safe . '</h' . $new_level . '>',
            (string) $units[$unit_index]['html']
        );
        $replacement = '';
        foreach ($units as $i => $u) {
            $replacement .= ($i === $unit_index) ? (string) $unit : $u['html'];
        }
        $rule_post = (int) $row['postId'];
        $snapshot  = self::post_rule_rows($user_id, (int) $site->id, $rule_post);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update($table, array('replacement' => $replacement), array('id' => (int) $row['id']), array('%s'), array('%d'));
        $push = self::push_current_rules_or_rollback($user_id, $site, $rule_post, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        if ((string) $row['target'] === 'section') {
            self::record_version($user_id, (int) $site->id, $rule_post, 'section', (string) $row['matchText'], (int) $row['occurrence'], $replacement);
        }
        return array('stored' => (int) ($push['stored'] ?? 0));
    }

    /**
     * Save a SLICE of an owning rule's replacement (served-truth law): the
     * edited unit range is spliced in, the rest of the rule untouched. An
     * empty slice deletes the units; a rule left with no visible content is
     * removed for inserts and rejected for section rules (an empty section
     * replacement is never valid — revert deletes the rule instead).
     */
    public function save_section_slice(int $user_id, object $site, int $post_id, array $input)
    {
        global $wpdb;
        $table       = PCM_Schema::table('seo_dynamic_rules');
        $rule_id     = absint($input['ruleId'] ?? 0);
        $from        = max(0, (int) ($input['unitFrom'] ?? 0));
        $to          = max($from, (int) ($input['unitTo'] ?? $from));
        $replacement = wp_kses_post((string) ($input['replacement'] ?? ''));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, postId, target, matchText, occurrence, replacement FROM {$table} WHERE id = %d AND userId = %d AND siteId = %d AND target IN ('section','sectionInsert') AND active = 1",
            $rule_id,
            $user_id,
            (int) $site->id
        ), ARRAY_A);
        if (!$row) {
            return new WP_Error('pcm_seo_rule_not_found', __('This section no longer exists — re-open the outline.', 'power-creatives'), array('status' => 404));
        }
        $units = PCM_Text_Matcher::parse_replacement_units((string) $row['replacement']);
        if ($from >= count($units)) {
            return new WP_Error('pcm_seo_heading_stale', __('This page changed since it was scanned — re-open the outline.', 'power-creatives'), array('status' => 409));
        }
        $to  = min($to, count($units) - 1);
        $new = '';
        foreach ($units as $i => $u) {
            if ($i < $from || $i > $to) {
                $new .= $u['html'];
            } elseif ($i === $from) {
                $new .= $replacement;
            }
        }
        $rule_post = (int) $row['postId'];
        $snapshot  = self::post_rule_rows($user_id, (int) $site->id, $rule_post);
        $removed   = false;
        if (trim(PCM_Text_Matcher::visible_text($new)) === '') {
            if ((string) $row['target'] !== 'sectionInsert') {
                return new WP_Error('pcm_seo_rule_empty', __('The replacement section is empty.', 'power-creatives'), array('status' => 400));
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($table, array('id' => (int) $row['id']), array('%d'));
            $removed = true;
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update($table, array('replacement' => $new), array('id' => (int) $row['id']), array('%s'), array('%d'));
        }
        $push = self::push_current_rules_or_rollback($user_id, $site, $rule_post, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        if (!$removed && (string) $row['target'] === 'section') {
            self::record_version($user_id, (int) $site->id, $rule_post, 'section', (string) $row['matchText'], (int) $row['occurrence'], $new);
        }
        $out = array('stored' => (int) ($push['stored'] ?? 0));
        if ($removed) {
            $out['removed'] = true;
        }
        return $out;
    }

    /**
     * Save the FULL-PAGE editor's document (G3): slice the edited HTML back
     * into unit-sections with the SAME parser that defines identity, align
     * them against the served baseline (1:1 by order when counts match; LCS
     * on level|norm(heading) keys as fallback), and route every changed pair
     * through the EXISTING save paths — page-level editing, section-level
     * storage, engine untouched:
     *
     * - unchanged (heading text+level, paragraph fingerprint AND raw-unit
     *   visible text identical) → skip;
     * - changed + attributed to a WHOLE section rule → save_section_rule
     *   keyed on the RULE's OWN stored identity (matchText/level/occurrence/
     *   paragraphs from its anchorContext) — the existing UPSERT and CLEAN
     *   REVERT laws apply, so editing a section back to its original deletes
     *   the rule exactly like the section editor;
     * - changed + attributed to a partial slice or an insert →
     *   save_section_slice on the owning rule;
     * - changed + original → save_section_rule with the row's identity
     *   (matchText/matchLevel/matchOccurrence — for a heading-ruled row the
     *   served identity IS the rule's output, so the absorb law rewires it to
     *   the original and the one-owner law holds);
     * - EXTRA edited sections → checked against ACTIVE sectionRemove rules
     *   first (a restored section DELETES its remove rule — clean revert),
     *   else save_section_insert anchored to the preceding served heading;
     * - FEWER sections (v2.4) → RENAME PAIRING first (leftovers pair
     *   positionally inside the same gap between matched anchors — a renamed
     *   heading is a replace, never remove+add), then per missing section:
     *   insert-born → its rule deletes; rule-owned → the rule deletes AND a
     *   sectionRemove lands on the ORIGINAL identity; original →
     *   sectionRemove on the row identity;
     * - IMAGES (v2.4) reconcile per normalized src: fewer copies in the doc
     *   than served → hide rules (from the tail of the visible original
     *   occurrences); more copies (a restored version) → hide rules delete.
     *   Images inside removed sections vanish from the doc and are hidden by
     *   the same reconciliation — the owner's "goes with the section" law.
     * - An EMPTY document is a legal FULL WIPE (owner order 2026-07-12):
     *   every section removes, every image hides — reversible via versions.
     *   (The former confirm gate is deleted by owner order: a broken load
     *   cannot reach a save since the load-once fix, so it only cost a click.)
     *
     * F9 LANDMINE (defused here + in assemble_content_html): the editor's
     * document NEVER contains the page's original between-content as
     * editable units — images enter only as locked context and are stripped
     * from every save (they persist as untouched between-content); original
     * lists never enter at all. Any remaining non-img raw unit is therefore
     * either the owning rule's own content (slices) or user-created — both
     * engine-legal (the expand/swap mapping the section editor already
     * allows) — so they are KEPT.
     *
     * Each routed save pushes and rolls itself back (existing atomicity law);
     * a mid-sequence failure stops honestly, reporting what already saved.
     *
     * @return array{saved:int,inserted:int,skipped:int,removed:int,restored:int,
     *               hidden:int,unhidden:int,flattened:int,notes:array<int,string>}|\WP_Error
     */
    public function save_page_edits(int $user_id, object $site, int $post_id, string $html)
    {
        // Editor-only heading metadata (origin lanes + review identity
        // anchors): content identity, rules and version snapshots must never
        // carry either — the review clears its anchors client-side, this is
        // the belt.
        $html = (string) preg_replace('#\s*data-pcm-(?:origin|review-id)="[^"]*"#i', '', $html);
        $inv = PCM_SEO_Page_Inventory::served_inventory($site, $post_id, $user_id);
        if ($inv === null || $inv['view'] !== 'served') {
            return new WP_Error(
                'pcm_seo_page_edit_unavailable',
                __('Page editing needs the served page view (connector 3.0.1+) — update the connector from the Sites module, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        // ── Baseline: the served content sections, exactly as the editor was assembled. ──
        $run_for  = PCM_SEO_Page_Inventory::section_runs($inv['headings'], $inv['nodes']);
        $baseline = array();
        foreach ($inv['headings'] as $i => $h) {
            if (((string) ($h['scope'] ?? 'post')) === 'site') {
                continue;
            }
            $paras = isset($run_for[$i]) ? $run_for[$i] : array();
            $att   = (isset($h['rule']) && is_array($h['rule'])
                && in_array((string) ($h['rule']['target'] ?? ''), array('section', 'sectionInsert'), true))
                ? $h['rule'] : null;
            $baseline[] = array(
                'key'             => (int) $h['level'] . '|' . PCM_Text_Matcher::normalize((string) $h['text']),
                'text'            => (string) $h['text'],
                'level'           => (int) $h['level'],
                'occurrence'      => (int) ($h['occurrence'] ?? 0),
                'matchText'       => (string) ($h['matchText'] ?? PCM_Text_Matcher::normalize((string) $h['text'])),
                'matchLevel'      => (int) ($h['matchLevel'] ?? $h['level']),
                'matchOccurrence' => (int) ($h['matchOccurrence'] ?? 0),
                'fp'              => PCM_Text_Matcher::fingerprint(array_map(static fn($p) => (string) $p['text'], $paras)),
                'paras'           => array_map(static fn($p) => array('text' => (string) $p['text'], 'occurrence' => (int) ($p['occurrence'] ?? 0)), $paras),
                'slice'           => $att ? array(
                    'ruleId'   => (int) $att['id'],
                    'unitFrom' => (int) ($att['unitFrom'] ?? 0),
                    'unitTo'   => (int) ($att['unitTo'] ?? 0),
                    'whole'    => !empty($att['whole']),
                    'target'   => (string) ($att['target'] ?? ''),
                    'html'     => (string) ($att['sliceHtml'] ?? ''),
                ) : null,
            );
        }
        // NOTE: an EMPTY baseline is legal — a fully wiped page serves no
        // sections, and restoring one arrives here with every edited section
        // as an "extra" that matches its sectionRemove rule (wipe must never
        // be a one-way door — live-caught 2026-07-12). The nothing-to-do case
        // is guarded after the edited document is parsed.

        // ── The edited document, sliced by the SAME parser that defines identity. ──
        $units = PCM_Text_Matcher::parse_replacement_units($html);
        $secs  = PCM_SEO_Page_Inventory::split_unit_sections($units);
        $notes = array();
        // Empty-p law (mirrors the parse): visible-text-empty paragraphs never
        // count toward a section's fingerprint — a trailing editor paragraph
        // must not make an untouched section look changed.
        $nonempty = static fn(array $texts): array => array_values(array_filter($texts, static fn($t) => PCM_Text_Matcher::normalize((string) $t) !== ''));
        $edited   = array();
        foreach ($secs as $s) {
            $edited[] = array(
                'key'   => $s['level'] . '|' . $s['norm'],
                'level' => (int) $s['level'],
                'norm'  => (string) $s['norm'],
                'label' => PCM_Text_Matcher::visible_text((string) $units[$s['from']]['inner']),
                'fp'    => PCM_Text_Matcher::fingerprint($nonempty($s['ptexts'])),
                'units' => array_slice($units, $s['from'], $s['to'] - $s['from'] + 1),
            );
        }
        // An EMPTY edited document is a legal full wipe (owner order 2026-07-12):
        // every baseline section removes, every image hides — all reversible
        // via the versions dropdown.
        if (empty($baseline) && empty($edited)) {
            return new WP_Error(
                'pcm_seo_page_edit_no_sections',
                __('This page has no editable sections (no content headings were found).', 'power-creatives'),
                array('status' => 409)
            );
        }

        // ── Leading no-heading zone: skipped by design, honestly noted when it changed. ──
        $first_from   = !empty($secs) ? $secs[0]['from'] : count($units);
        $orphan_texts = array();
        for ($ui = 0; $ui < $first_from; $ui++) {
            if ((string) $units[$ui]['tag'] === 'p') {
                $orphan_texts[] = PCM_Text_Matcher::visible_text((string) $units[$ui]['inner']);
            }
        }
        $base_orphans = array();
        foreach ($inv['nodes'] as $n) {
            if (!isset($n['anchor']) || !is_array($n['anchor'])) {
                $base_orphans[] = (string) $n['text'];
            }
        }
        if (PCM_Text_Matcher::fingerprint($nonempty($orphan_texts)) !== PCM_Text_Matcher::fingerprint($nonempty($base_orphans))) {
            $notes[] = __('Content before the first heading can’t be edited yet — those changes weren’t saved.', 'power-creatives');
        }

        // ── Replacement builder (the image KEEP LAW, 2026-07-12): an <img>
        //    survives a save ONLY when the platform placed it — the
        //    data-pcm-added marker AND a src on the client site's own host
        //    (server-verified: a marker alone could ride in on pasted or AI
        //    content, but only our delivery channel produces client-host
        //    files). Locked originals and everything foreign strip exactly
        //    as before — the live page's own images stay untouched
        //    between-content.
        $site_host = strtolower((string) (wp_parse_url((string) $site->url, PHP_URL_HOST) ?: ''));
        $keep_img  = static function (string $tag) use ($site_host): bool {
            if ($site_host === '' || stripos($tag, 'data-pcm-added') === false) {
                return false;
            }
            // A locked tag is assembly-produced CONTEXT even if it carries the
            // added marker (defense in depth with the gap-scan skip): keeping
            // it would clone the image into the rule on every save.
            if (stripos($tag, 'data-pcm-locked') !== false) {
                return false;
            }
            if (!preg_match('#(?<![\w-])src\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $tag, $m)) {
                return false;
            }
            $src = PCM_Text_Matcher::normalize_src($m[2] !== '' ? $m[2] : (isset($m[3]) ? $m[3] : ''));
            return strtolower((string) (wp_parse_url($src, PHP_URL_HOST) ?: '')) === $site_host;
        };
        $strip_img_tags = static function (array $unit_list) use ($keep_img): array {
            $out = array();
            foreach ($unit_list as $u) {
                if ((string) $u['tag'] !== '') {
                    // Block units keep platform-added inline images too (TipTap
                    // emits added images top-level, but kses round-trips can
                    // nest them) — same keep law, one discriminator.
                    $html = (string) preg_replace_callback('#<img\b[^>]*>#i', static fn($m) => $keep_img((string) $m[0]) ? (string) $m[0] : '', (string) $u['html']);
                    $out[] = array('tag' => $u['tag'], 'inner' => (string) preg_replace_callback('#<img\b[^>]*>#i', static fn($m) => $keep_img((string) $m[0]) ? (string) $m[0] : '', (string) $u['inner']), 'html' => $html);
                    continue;
                }
                $rest = (string) preg_replace_callback('#<img\b[^>]*>#i', static fn($m) => $keep_img((string) $m[0]) ? (string) $m[0] : '', (string) $u['html']);
                if (trim($rest) !== '') {
                    $out[] = array('tag' => '', 'inner' => '', 'html' => trim($rest));
                }
            }
            return $out;
        };
        $join = static fn(array $unit_list): string => implode('', array_map(static fn($u) => (string) $u['html'], $unit_list));
        // Raw-unit visible text (normalized, img-stripped) — the fingerprint is
        // paragraph-only, so list edits need their own unchanged-check input.
        $rawtext = static function (array $unit_list) use ($strip_img_tags): string {
            $texts = array();
            foreach ($strip_img_tags($unit_list) as $u) {
                if ((string) $u['tag'] === '') {
                    $texts[] = PCM_Text_Matcher::visible_text((string) $u['html']);
                }
            }
            return PCM_Text_Matcher::normalize(implode(' ', $texts));
        };
        // Kept-image identity (the skip law's fifth input, 2026-07-12): the
        // four text inputs above are image-blind by construction (visible
        // text, paragraph fingerprint) — so adding OR deleting a platform-
        // placed image is invisible to them and the section would skip.
        // Ordered normalized srcs of KEPT imgs per side close that hole.
        // Section IDENTITY (keys/rename pairing/restore match) stays
        // image-blind by design: images don't define WHAT a section is,
        // only WHETHER it changed.
        $kept_imgs = static function (array $unit_list) use ($keep_img): string {
            $srcs = array();
            foreach ($unit_list as $u) {
                if (!preg_match_all('#<img\b[^>]*>#i', (string) $u['html'], $mm)) {
                    continue;
                }
                foreach ($mm[0] as $tag) {
                    if ($keep_img((string) $tag)
                        && preg_match('#(?<![\w-])src\s*=\s*("([^"]*)"|\'([^\']*)\')#i', (string) $tag, $m)) {
                        $srcs[] = PCM_Text_Matcher::normalize_src($m[2] !== '' ? $m[2] : (isset($m[3]) ? $m[3] : ''));
                    }
                }
            }
            return implode('|', $srcs);
        };

        // ── Alignment: 1:1 by order when counts match; LCS on keys otherwise. ──
        $pairs   = array();
        $extras  = array();
        $removed = array();
        if (count($edited) === count($baseline)) {
            foreach ($baseline as $bi => $unused) {
                $pairs[] = array($bi, $bi);
            }
        } else {
            $n  = count($baseline);
            $m  = count($edited);
            $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
            for ($i = $n - 1; $i >= 0; $i--) {
                for ($j = $m - 1; $j >= 0; $j--) {
                    $dp[$i][$j] = ($baseline[$i]['key'] === $edited[$j]['key'])
                        ? $dp[$i + 1][$j + 1] + 1
                        : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
                }
            }
            $i = 0;
            $j = 0;
            while ($i < $n && $j < $m) {
                if ($baseline[$i]['key'] === $edited[$j]['key']) {
                    $pairs[] = array($i, $j);
                    $i++;
                    $j++;
                } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                    $removed[] = $i;
                    $i++;
                } else {
                    $extras[] = $j;
                    $j++;
                }
            }
            while ($i < $n) {
                $removed[] = $i++;
            }
            while ($j < $m) {
                $extras[] = $j++;
            }
            // RENAME PAIRING (v2.4): leftovers pair positionally IN ORDER when
            // they sit in the SAME gap between matched anchors — a renamed
            // heading is a replace, never remove+add. Only the true count
            // difference stays removed/extra.
            if (!empty($removed) && !empty($extras)) {
                $segment_of = static function (int $idx, array $prs, int $side): int {
                    $seg = 0;
                    foreach ($prs as $pr) {
                        if ($pr[$side] < $idx) {
                            $seg++;
                        }
                    }
                    return $seg;
                };
                $still_removed = array();
                foreach ($removed as $bi) {
                    $seg    = $segment_of($bi, $pairs, 0);
                    $paired = false;
                    foreach ($extras as $k => $ej) {
                        if ($segment_of($ej, $pairs, 1) === $seg) {
                            $pairs[] = array($bi, $ej);
                            unset($extras[$k]);
                            $paired = true;
                            break;
                        }
                    }
                    if (!$paired) {
                        $still_removed[] = $bi;
                    }
                }
                $removed = $still_removed;
                $extras  = array_values($extras);
                usort($pairs, static fn($a, $b) => $a[0] <=> $b[0]);
            }
        }

        global $wpdb;
        $rules_table = PCM_Schema::table('seo_dynamic_rules');

        // ── RESTORE detection (v2.4): an extra section matching an ACTIVE
        //    sectionRemove rule's identity is a restore — its rule deletes. ──
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $remove_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, matchText, occurrence, anchorContext FROM {$rules_table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'sectionRemove' AND active = 1",
            $user_id,
            (int) $site->id,
            $post_id
        ), ARRAY_A);
        $restores    = array();
        $true_extras = array();
        foreach ($extras as $ej) {
            $e   = $edited[$ej];
            $hit = null;
            foreach ($remove_rows as $rk => $rrow) {
                $rctx = json_decode((string) ($rrow['anchorContext'] ?? ''), true);
                $rctx = is_array($rctx) ? $rctx : array();
                if ((string) $rrow['matchText'] === $e['norm'] && (int) ($rctx['level'] ?? 0) === $e['level']
                    && (string) ($rctx['fingerprint'] ?? '') === $e['fp']) {
                    $hit = $rrow;
                    unset($remove_rows[$rk]); // one restore per rule
                    break;
                }
            }
            if ($hit !== null) {
                $restores[] = $hit;
            } else {
                $true_extras[] = $ej;
            }
        }
        $extras = $true_extras;

        // ── IMAGE reconciliation (v2.4): per normalized src, doc vs baseline.
        //    Fewer copies in the doc → hide rules (tail of the visible original
        //    occurrences); more copies (a restored version) → hide rules delete.
        //    Images inside removed sections are simply missing from the doc —
        //    the same arithmetic hides them (the "goes with the section" law). ──
        $img_list = static function (string $doc): array {
            $out = array();
            if (preg_match_all('#<img\b[^>]*>#i', $doc, $mm)) {
                foreach ($mm[0] as $tag) {
                    if (stripos($tag, 'data-pcm-added') !== false) {
                        continue; // platform-added images live and die with their RULE content — never hide-reconciled
                    }
                    if (!preg_match('#(?<![\w-])src\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $tag, $sm)) {
                        continue;
                    }
                    $src = PCM_Text_Matcher::normalize_src($sm[2] !== '' ? $sm[2] : (isset($sm[3]) ? $sm[3] : ''));
                    if ($src === '') {
                        continue;
                    }
                    $attr = static function (string $name) use ($tag): string {
                        if (!preg_match('#(?<![\w-])' . $name . '\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $tag, $am)) {
                            return '';
                        }
                        return html_entity_decode($am[2] !== '' ? $am[2] : (isset($am[3]) ? $am[3] : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    };
                    $out[] = array('src' => $src, 'alt' => $attr('alt'), 'title' => $attr('title'));
                }
            }
            return $out;
        };
        $per_src = static function (array $imgs): array {
            $by = array();
            foreach ($imgs as $im) {
                $by[$im['src']][] = $im;
            }
            return $by;
        };
        $base_by = $per_src($img_list((string) ($inv['contentHtml'] ?? '')));
        $doc_by  = $per_src($img_list($html));
        // Active hide rules per src (occurrences live in ORIGINAL space).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $img_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, matchText, occurrence, replacement FROM {$rules_table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'image' AND active = 1",
            $user_id,
            (int) $site->id,
            $post_id
        ), ARRAY_A);
        $hidden_by = array();
        foreach ($img_rows as $ir) {
            $set = json_decode((string) $ir['replacement'], true);
            if (is_array($set) && !empty($set['hidden'])) {
                $hidden_by[(string) $ir['matchText']][] = (int) $ir['occurrence'];
            }
        }
        $hides   = array();
        $unhides = array();
        foreach (array_unique(array_merge(array_keys($base_by), array_keys($doc_by))) as $src) {
            $v_list = isset($base_by[$src]) ? $base_by[$src] : array();
            $v      = count($v_list);
            $d      = isset($doc_by[$src]) ? count($doc_by[$src]) : 0;
            $h_occs = isset($hidden_by[$src]) ? $hidden_by[$src] : array();
            sort($h_occs);
            if ($d < $v) {
                // Visible original occurrences = [0 .. v+|h|-1] minus the hidden set.
                $vis = array();
                for ($o = 0, $total = $v + count($h_occs); $o < $total; $o++) {
                    if (!in_array($o, $h_occs, true)) {
                        $vis[] = $o;
                    }
                }
                for ($k = $v - 1; $k >= $d; $k--) {
                    $hides[] = array(
                        'src'        => $src,
                        'occurrence' => $vis[$k],
                        'alt'        => (string) ($v_list[$k]['alt'] ?? ''),
                        'title'      => (string) ($v_list[$k]['title'] ?? ''),
                    );
                }
            } elseif ($d > $v && !empty($h_occs)) {
                foreach (array_slice(array_reverse($h_occs), 0, min($d - $v, count($h_occs))) as $occ) {
                    $unhides[] = array('src' => $src, 'occurrence' => (int) $occ);
                }
            }
        }

        // ── W2 REPLACE-NOT-APPEND (page versioning, 2026-07-16): the save must
        //    leave the page's rows as the NET set. section/sectionInsert rows
        //    the served view attributes to NOTHING are superseded identities —
        //    they serve nothing and can only stack (the live-probed 63-rule
        //    pile). They die BEFORE routing so an identity upsert below can
        //    never resurrect a dead row; the routed saves and their version
        //    rows then run exactly as before. sectionRemove rows stay (net by
        //    law while the doc omits their baseline section; restores above
        //    already deleted theirs). Same atomicity law as every rule write:
        //    the deletion pushes or rolls back. ──
        $live_ids = array();
        foreach ($inv['headings'] as $h) {
            if (isset($h['rule']['id']) && in_array((string) ($h['rule']['target'] ?? ''), array('section', 'sectionInsert'), true)) {
                $live_ids[] = (int) $h['rule']['id'];
            }
        }
        $page_rows = self::post_rule_rows($user_id, (int) $site->id, $post_id);
        $dead_ids  = PCM_SEO_Page_State::superseded_rule_ids($page_rows, $live_ids);
        $flattened = 0;
        // ── THE SAVE TRANSACTION OPENS (gap ATOMIC-SAVE 2026-07-17):
        //    $page_rows (pre-mutation) is the WHOLE save's rollback point.
        //    From here every routed push is a deferred stub and every version
        //    row queues; EVERY exit path below either aborts ($fail: restore
        //    + clear) or commits (the ONE real push at the tail). The static
        //    is request-scoped — PHP request isolation is the leak backstop,
        //    and the commit/abort paths clear it explicitly. ──
        self::$push_deferred     = true;
        self::$deferred_versions = array();
        if (!empty($dead_ids)) {
            // Superseded-row deletion rides the transaction — the commit push
            // at the tail transmits the flattened set (W2 law unchanged).
            foreach ($dead_ids as $dead_id) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete($rules_table, array('id' => $dead_id, 'userId' => $user_id, 'siteId' => (int) $site->id), array('%d', '%d', '%d'));
            }
            $flattened = count($dead_ids);
        }

        // ── Route every pair through the existing save paths, document order. ──
        $saved    = 0;
        $inserted = 0;
        $skipped  = 0;
        $fail     = static function (string $label, WP_Error $err) use ($user_id, $site, $post_id, $page_rows): WP_Error {
            // TOTAL ABORT (gap ATOMIC-SAVE — supersedes the partial-progress
            // law): restore the pre-save rule set, drop the queued history.
            // Nothing was pushed (every push in the transaction is a stub),
            // so hub rows return to exactly what the site still serves.
            self::$push_deferred     = false;
            self::$deferred_versions = array();
            self::restore_rule_rows($user_id, (int) $site->id, $post_id, $page_rows);
            return new WP_Error(
                $err->get_error_code(),
                sprintf(
                    /* translators: 1: section heading, 2: reason */
                    __('Saving “%1$s” failed: %2$s Nothing was saved — the site still serves its previous state. Fix the reason and save again.', 'power-creatives'),
                    $label,
                    rtrim($err->get_error_message()) . (str_ends_with(rtrim($err->get_error_message()), '.') ? '' : '.')
                ),
                $err->get_error_data()
            );
        };
        foreach ($pairs as $pair) {
            list($bi, $ej) = $pair;
            $b = $baseline[$bi];
            $e = $edited[$ej];
            // Baseline units: a slice's own for owned sections; original
            // sections enter the editor with NO raw units and can never
            // contain kept imgs (added imgs exist only in rule content).
            $base_units = $b['slice'] !== null
                ? PCM_Text_Matcher::parse_replacement_units((string) $b['slice']['html'])
                : array();
            if ($e['level'] === $b['level'] && $e['norm'] === PCM_Text_Matcher::normalize($b['text'])
                && $e['fp'] === $b['fp'] && $rawtext($e['units']) === $rawtext($base_units)
                && $kept_imgs($e['units']) === $kept_imgs($base_units)) {
                $skipped++;
                continue;
            }
            $replacement = $join($strip_img_tags($e['units']));
            $res         = null;
            if ($b['slice'] !== null && $b['slice']['whole'] && $b['slice']['target'] === 'section') {
                // WHOLE section rule: route through save_section_rule keyed on
                // the rule's OWN stored identity — UPSERT updates it, and the
                // clean-revert law deletes it when the edit reproduces the
                // original (the slice path could never revert).
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
                $rrow = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, matchText, occurrence, anchorContext FROM {$rules_table} WHERE id = %d AND userId = %d AND siteId = %d AND target = 'section' AND active = 1",
                    $b['slice']['ruleId'],
                    $user_id,
                    (int) $site->id
                ), ARRAY_A);
                $rctx = $rrow ? json_decode((string) ($rrow['anchorContext'] ?? ''), true) : null;
                if ($rrow && is_array($rctx) && !empty($rctx['level'])) {
                    $res = $this->save_section_rule($user_id, $site, $post_id, array(
                        'headingText'       => (string) $rrow['matchText'],
                        'headingLevel'      => (int) $rctx['level'],
                        'headingOccurrence' => (int) $rrow['occurrence'],
                        'paragraphs'        => array_values(array_filter((array) ($rctx['paragraphs'] ?? array()), 'is_array')),
                        'replacement'       => $replacement,
                    ));
                }
            }
            if ($res === null && $b['slice'] !== null) {
                $res = $this->save_section_slice($user_id, $site, $post_id, array(
                    'ruleId'      => $b['slice']['ruleId'],
                    'unitFrom'    => $b['slice']['unitFrom'],
                    'unitTo'      => $b['slice']['unitTo'],
                    'replacement' => $replacement,
                ));
            } elseif ($res === null) {
                $res = $this->save_section_rule($user_id, $site, $post_id, array(
                    'headingText'       => $b['matchText'],
                    'headingLevel'      => $b['matchLevel'],
                    'headingOccurrence' => $b['matchOccurrence'],
                    'paragraphs'        => $b['paras'],
                    'replacement'       => $replacement,
                ));
            }
            if ($res instanceof WP_Error) {
                return $fail($b['text'], $res);
            }
            $saved++;
        }
        // ── Extra edited sections → inserts anchored to the preceding section's served heading. ──
        $anchor_for = static function (int $ej) use ($pairs, $baseline): array {
            $prev = null;
            foreach ($pairs as $pair) {
                if ($pair[1] < $ej) {
                    $prev = $baseline[$pair[0]];
                }
            }
            return $prev !== null
                ? array('section' => $prev, 'position' => 'after')
                : array('section' => $baseline[0], 'position' => 'before');
        };
        foreach ($extras as $ej) {
            $e = $edited[$ej];
            if (empty($baseline)) {
                // Nothing on the page to anchor a NEW section to (restores
                // above need no anchor — this is a genuinely new section on a
                // fully wiped page).
                return $fail($e['label'], new WP_Error(
                    'pcm_seo_page_edit_no_anchor',
                    __('Adding a new section needs at least one existing section to anchor to — restore a version first.', 'power-creatives'),
                    array('status' => 409)
                ));
            }
            $anchor = $anchor_for($ej);
            $res    = $this->save_section_insert($user_id, $site, $post_id, array(
                'anchorText'       => $anchor['section']['text'],
                'anchorLevel'      => $anchor['section']['level'],
                'anchorOccurrence' => $anchor['section']['occurrence'],
                'position'         => $anchor['position'],
                'replacement'      => $join($strip_img_tags($e['units'])),
            ));
            if ($res instanceof WP_Error) {
                return $fail($e['label'], $res);
            }
            $inserted++;
        }

        // ── CONFIRMED removals (v2.4), per kind. Whole-rule groups collapse to
        //    ONE removal of the rule's ORIGINAL section (the rule expanded that
        //    one original — deleting the rule + removing the original erases
        //    everything it produced). ──
        $removed_count = 0;
        $rule_total    = array(); // section-rule id → sections it owns in the baseline
        foreach ($baseline as $bb) {
            if ($bb['slice'] !== null && $bb['slice']['target'] === 'section') {
                $rid              = $bb['slice']['ruleId'];
                $rule_total[$rid] = ($rule_total[$rid] ?? 0) + 1;
            }
        }
        $rule_removing = array();
        foreach ($removed as $bi) {
            $b = $baseline[$bi];
            if ($b['slice'] !== null && $b['slice']['target'] === 'section') {
                $rule_removing[$b['slice']['ruleId']][] = $bi;
            }
        }
        $rules_done = array();
        foreach ($removed as $bi) {
            $b   = $baseline[$bi];
            $res = null;
            if ($b['slice'] !== null && $b['slice']['target'] === 'sectionInsert') {
                // Content WE added — its rule simply dies (existing removal law).
                $res = $this->save_section_insert($user_id, $site, $post_id, array(
                    'anchorText'       => $b['text'],
                    'anchorLevel'      => $b['level'],
                    'anchorOccurrence' => 0,
                    'position'         => 'after',
                    'replacement'      => '',
                    'ruleId'           => $b['slice']['ruleId'],
                ));
            } elseif ($b['slice'] !== null) {
                $rid = $b['slice']['ruleId'];
                if (count($rule_removing[$rid] ?? array()) >= ($rule_total[$rid] ?? PHP_INT_MAX)) {
                    // EVERY section of this rule is going: delete the rule and
                    // remove its ORIGINAL section in one push.
                    if (isset($rules_done[$rid])) {
                        continue; // handled with the group's first member
                    }
                    $rules_done[$rid] = true;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
                    $rrow = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, matchText, occurrence, anchorContext FROM {$rules_table} WHERE id = %d AND userId = %d AND siteId = %d AND target = 'section' AND active = 1",
                        $rid,
                        $user_id,
                        (int) $site->id
                    ), ARRAY_A);
                    $rctx = $rrow ? json_decode((string) ($rrow['anchorContext'] ?? ''), true) : null;
                    if ($rrow && is_array($rctx) && !empty($rctx['level'])) {
                        $res = $this->save_section_remove($user_id, $site, $post_id, array(
                            'headingText'       => (string) $rrow['matchText'],
                            'headingLevel'      => (int) $rctx['level'],
                            'headingOccurrence' => (int) $rrow['occurrence'],
                            'paragraphs'        => array_values(array_filter((array) ($rctx['paragraphs'] ?? array()), 'is_array')),
                            'absorbRuleId'      => (int) $rrow['id'],
                        ));
                    }
                } else {
                    // Part of a bigger rule: splice this section's units out.
                    $res = $this->save_section_slice($user_id, $site, $post_id, array(
                        'ruleId'      => $rid,
                        'unitFrom'    => $b['slice']['unitFrom'],
                        'unitTo'      => $b['slice']['unitTo'],
                        'replacement' => '',
                    ));
                }
            } else {
                // Original page section: the new sectionRemove rule.
                $res = $this->save_section_remove($user_id, $site, $post_id, array(
                    'headingText'       => $b['matchText'],
                    'headingLevel'      => $b['matchLevel'],
                    'headingOccurrence' => $b['matchOccurrence'],
                    'paragraphs'        => $b['paras'],
                ));
            }
            if ($res instanceof WP_Error) {
                return $fail($b['text'], $res);
            }
            if ($res !== null) {
                $removed_count++;
            }
        }

        // ── Restores: the section is back in the doc — its remove rule dies. ──
        $restored = 0;
        foreach ($restores as $rrow) {
            $rctx = json_decode((string) ($rrow['anchorContext'] ?? ''), true);
            $rctx = is_array($rctx) ? $rctx : array();
            $res  = $this->save_section_remove($user_id, $site, $post_id, array(
                'headingText'       => (string) $rrow['matchText'],
                'headingLevel'      => (int) ($rctx['level'] ?? 2),
                'headingOccurrence' => (int) $rrow['occurrence'],
                'restore'           => true,
            ));
            if ($res instanceof WP_Error) {
                return $fail((string) $rrow['matchText'], $res);
            }
            $restored++;
        }

        // ── Image visibility (v2.4): hides from the doc diff, un-hides from restores. ──
        $hidden_count = 0;
        foreach ($hides as $hh) {
            $res = $this->save_image_rule($user_id, $site, $post_id, array(
                'src'           => $hh['src'],
                'occurrence'    => $hh['occurrence'],
                'hidden'        => true,
                'originalAlt'   => $hh['alt'],
                'originalTitle' => $hh['title'],
            ));
            if ($res instanceof WP_Error) {
                return $fail($hh['src'], $res);
            }
            $hidden_count++;
        }
        $unhidden = 0;
        foreach ($unhides as $uh) {
            $res = $this->save_image_rule($user_id, $site, $post_id, array(
                'src'        => $uh['src'],
                'occurrence' => $uh['occurrence'],
                'revert'     => true,
            ));
            if ($res instanceof WP_Error) {
                return $fail($uh['src'], $res);
            }
            $unhidden++;
        }

        // ── THE SAVE TRANSACTION COMMITS (gap ATOMIC-SAVE): ONE real push
        //    carries the final net set with ONE version bump; a failed push
        //    restores the pre-save set entirely — the site serves ALL of this
        //    save or NONE of it. History (queued section rows + the page row)
        //    is written only after the connector ACCEPTED. A no-op save
        //    pushes nothing and leaves the recorded state untouched. ──
        $changed = $saved + $inserted + $removed_count + $restored + $hidden_count + $unhidden;
        self::$push_deferred = false;
        $pushed = false;
        if ($changed > 0 || $flattened > 0) {
            $push = self::push_current_rules_or_rollback($user_id, $site, $post_id, $page_rows);
            if ($push instanceof WP_Error) {
                self::$deferred_versions = array();
                return new WP_Error(
                    $push->get_error_code(),
                    sprintf(
                        /* translators: %s: reason */
                        __('Publishing to the site failed: %s Nothing was saved — the site still serves its previous state. Retry.', 'power-creatives'),
                        rtrim($push->get_error_message()) . (str_ends_with(rtrim($push->get_error_message()), '.') ? '' : '.')
                    ),
                    $push->get_error_data()
                );
            }
            $pushed = true;
        }
        foreach (self::$deferred_versions as $v) {
            self::record_version($v[0], $v[1], $v[2], $v[3], $v[4], $v[5], $v[6]);
        }
        self::$deferred_versions = array();
        // Page version: ONE row per changing save — the document as submitted
        // (duplicate-skip + cap ride record_version). No-op saves record nothing.
        if ($changed > 0) {
            self::record_version($user_id, (int) $site->id, $post_id, 'page', '', 0, $html);
        }
        return array(
            'saved'     => $saved,
            'inserted'  => $inserted,
            'skipped'   => $skipped,
            'removed'   => $removed_count,
            'restored'  => $restored,
            'hidden'    => $hidden_count,
            'unhidden'  => $unhidden,
            // Superseded rows the net-set law deleted (W2) — reported, never
            // silent; they change no served output, so they don't count as a
            // page-version change above.
            'flattened' => $flattened,
            'notes'     => array_values(array_unique($notes)),
            // THE CORNER'S TRUTH (gap ATOMIC-SAVE): pushed = the connector
            // accepted the commit push this request; pageState = the record
            // the connector echoed. A no-op reply (pushed=false) must never
            // overwrite the frontend's verdict.
            'pushed'    => $pushed,
            'pageState' => PCM_SEO_Page_State::page_state((int) $site->id, $post_id),
        );
    }

    /**
     * Save (or restore) a SECTION REMOVE rule (engine v2.4, connector 3.0.3):
     * identity = the section identity (normalized heading + level + occurrence
     * + paragraph fingerprint); serving verify-first locates like a replace
     * and removes the section's blocks — between-content stays, image
     * visibility is the image rule's job. `restore: true` DELETES the rule
     * (the section is present again — clean revert). Same atomicity laws as
     * every save: capability BEFORE any write, push-fail ROLLS BACK.
     * `absorbRuleId` deletes the section's owning rule in the same push (a
     * removed rule-born section = the rule dies AND its original is removed).
     *
     * @param array{headingText:string,headingLevel:int,headingOccurrence:int,
     *              paragraphs:array<int,array{text:string,occurrence:int}>,
     *              restore?:bool,absorbRuleId?:int} $input
     * @return array|\WP_Error
     */
    public function save_section_remove(int $user_id, object $site, int $post_id, array $input)
    {
        global $wpdb;
        $table      = PCM_Schema::table('seo_dynamic_rules');
        $site_id    = (int) $site->id;
        $match_text = PCM_Text_Matcher::normalize((string) ($input['headingText'] ?? ''));
        $level      = max(1, min(6, (int) ($input['headingLevel'] ?? 2)));
        $occurrence = max(0, (int) ($input['headingOccurrence'] ?? 0));
        $restore    = !empty($input['restore']);
        $absorb_id  = isset($input['absorbRuleId']) ? absint($input['absorbRuleId']) : 0;
        $para_texts = array();
        $paragraphs = array();
        foreach ((array) ($input['paragraphs'] ?? array()) as $p) {
            if (!is_array($p) || !isset($p['text'])) {
                continue;
            }
            $paragraphs[] = array('text' => PCM_Text_Matcher::normalize((string) $p['text']), 'occurrence' => max(0, (int) ($p['occurrence'] ?? 0)));
            $para_texts[] = (string) $p['text'];
        }
        $fingerprint = PCM_Text_Matcher::fingerprint($para_texts);
        if ($match_text === '') {
            return new WP_Error('pcm_seo_rule_no_match', __('The section has no matchable heading text.', 'power-creatives'), array('status' => 400));
        }
        if (PCM_SEO_Page_Inventory::connector_rules_schema_version($site) < 5) {
            return new WP_Error(
                'pcm_seo_connector_no_removal',
                __('This site’s connector doesn’t support section removal yet (needs v3.0.3+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        $snapshot = self::post_rule_rows($user_id, $site_id, $post_id);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $prev = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'sectionRemove' AND matchText = %s AND occurrence = %d",
            $user_id,
            $site_id,
            $post_id,
            $match_text,
            $occurrence
        ), ARRAY_A);
        if ($restore) {
            if (!$prev) {
                return array('restored' => true, 'stored' => count($snapshot)); // nothing to do — no push needed
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($table, array('id' => (int) $prev['id']), array('%d'));
        } else {
            $ctx = wp_json_encode(array('level' => $level, 'fingerprint' => $fingerprint, 'paragraphs' => $paragraphs));
            if ($prev) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($table, array('anchorContext' => $ctx, 'active' => 1), array('id' => (int) $prev['id']), array('%s', '%d'), array('%d'));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert($table, array(
                    'userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id,
                    'target' => 'sectionRemove', 'matchText' => $match_text, 'occurrence' => $occurrence,
                    'replacement' => '', 'anchorContext' => $ctx, 'active' => 1,
                ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d'));
            }
            if ($absorb_id > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->delete($table, array('id' => $absorb_id, 'userId' => $user_id, 'siteId' => $site_id), array('%d', '%d', '%d'));
            }
            // ABSORB (one-owner, 2026-07-12): paragraph rules covering the
            // removed section could never serve again (their blocks are gone)
            // and would stale-leak forever — they die here, matched by
            // ORIGINAL identity OR served output (the tightened law).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $p_rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT id, matchText, occurrence, replacement FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'paragraph'",
                $user_id,
                $site_id,
                $post_id
            ), ARRAY_A);
            foreach ($p_rows as $p_row) {
                $served_out = PCM_Text_Matcher::normalize(PCM_Text_Matcher::visible_text((string) $p_row['replacement']));
                foreach ($paragraphs as $p) {
                    if (((string) $p_row['matchText'] === $p['text'] && (int) $p_row['occurrence'] === $p['occurrence'])
                        || ($served_out !== '' && $served_out === $p['text'])) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                        $wpdb->delete($table, array('id' => (int) $p_row['id']), array('%d'));
                        break;
                    }
                }
            }
        }
        $push = self::push_current_rules_or_rollback($user_id, $site, $post_id, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        $out = array('stored' => (int) ($push['stored'] ?? 0));
        if ($restore) {
            $out['restored'] = true;
        } else {
            $out['rule'] = array('matchText' => $match_text, 'occurrence' => $occurrence, 'level' => $level, 'active' => true);
        }
        return $out;
    }

    /**
     * Save an IMAGE metadata rule (engine v2.3, connector 3.0.2): identity =
     * normalized src + occurrence among same-src CONTENT images; replacement
     * = attribute set {alt,title} served as an attr rewrite ONLY (never
     * src/position/existence). anchorContext stores the ORIGINAL alt/title —
     * captured ONCE at rule creation (for an unruled image the editor's
     * current attrs ARE the originals) and never overwritten by re-edits.
     * CLEAN REVERT: editing both attrs back to the originals (or the explicit
     * revert flag) deletes the rule. Same atomicity laws as every save:
     * capability BEFORE any write, push-fail ROLLS BACK.
     *
     * @param array{src:string,occurrence:int,alt:string,title:string,
     *              originalAlt?:string,originalTitle?:string,revert?:bool} $input
     * @return array|\WP_Error
     */
    public function save_image_rule(int $user_id, object $site, int $post_id, array $input)
    {
        global $wpdb;
        $table      = PCM_Schema::table('seo_dynamic_rules');
        $site_id    = (int) $site->id;
        $src        = PCM_Text_Matcher::normalize_src((string) ($input['src'] ?? ''));
        $occurrence = max(0, (int) ($input['occurrence'] ?? 0));
        $alt        = sanitize_text_field((string) ($input['alt'] ?? ''));
        $title      = sanitize_text_field((string) ($input['title'] ?? ''));
        $revert     = !empty($input['revert']);
        $hidden     = !empty($input['hidden']); // v2.4: hide at render (never storage/media)
        if ($src === '') {
            return new WP_Error('pcm_seo_rule_no_match', __('The image has no usable src to match on.', 'power-creatives'), array('status' => 400));
        }
        $min_schema = $hidden ? 5 : 4;
        if (PCM_SEO_Page_Inventory::connector_rules_schema_version($site) < $min_schema) {
            return new WP_Error(
                $hidden ? 'pcm_seo_connector_no_removal' : 'pcm_seo_connector_no_image_rules',
                $hidden
                    ? __('This site’s connector doesn’t support image hiding yet (needs v3.0.3+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives')
                    : __('This site’s connector doesn’t support image metadata rules yet (needs v3.0.2+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $prev = $wpdb->get_row($wpdb->prepare(
            "SELECT id, anchorContext FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target = 'image' AND matchText = %s AND occurrence = %d",
            $user_id,
            $site_id,
            $post_id,
            $src,
            $occurrence
        ), ARRAY_A);
        $prev_ctx = $prev ? json_decode((string) ($prev['anchorContext'] ?? ''), true) : null;
        $prev_ctx = is_array($prev_ctx) ? $prev_ctx : array();
        // Originals: the stored ones for an existing rule; for a NEW rule the
        // caller's current attrs (no rule has touched them = they ARE original).
        $orig_alt   = $prev ? (string) ($prev_ctx['originalAlt'] ?? '') : sanitize_text_field((string) ($input['originalAlt'] ?? ''));
        $orig_title = $prev ? (string) ($prev_ctx['originalTitle'] ?? '') : sanitize_text_field((string) ($input['originalTitle'] ?? ''));
        // A hide request is never an accidental revert — only the explicit
        // flag or an attrs-back-to-original METADATA save deletes the rule.
        $reverted = $revert || (!$hidden && $alt === $orig_alt && $title === $orig_title);

        $snapshot = self::post_rule_rows($user_id, $site_id, $post_id);
        if ($reverted) {
            if (!$prev) {
                return array('reverted' => true, 'stored' => count($snapshot)); // nothing to do — no push needed
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($table, array('id' => (int) $prev['id']), array('%d'));
        } else {
            $replacement = (string) wp_json_encode($hidden ? array('hidden' => true) : array('alt' => $alt, 'title' => $title));
            $ctx         = (string) wp_json_encode(array('originalAlt' => $orig_alt, 'originalTitle' => $orig_title));
            if ($prev) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($table, array('replacement' => $replacement, 'active' => 1), array('id' => (int) $prev['id']), array('%s', '%d'), array('%d'));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert($table, array(
                    'userId' => $user_id, 'siteId' => $site_id, 'postId' => $post_id,
                    'target' => 'image', 'matchText' => $src, 'occurrence' => $occurrence,
                    'replacement' => $replacement, 'anchorContext' => $ctx, 'active' => 1,
                ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d'));
            }
        }
        $push = self::push_current_rules_or_rollback($user_id, $site, $post_id, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        $out = array('stored' => (int) ($push['stored'] ?? 0));
        if ($reverted) {
            $out['reverted'] = true;
            // The editor applies these in place — it never reloads the document.
            $out['original'] = array('alt' => $orig_alt, 'title' => $orig_title);
        } else {
            $out['rule'] = array('src' => $src, 'occurrence' => $occurrence, 'alt' => $alt, 'title' => $title, 'hidden' => $hidden, 'active' => true);
        }
        return $out;
    }

    /**
     * MIGRATE one site's legacy heading overrides → site-scope heading
     * instructions (cleanup C4). Per site, verified before anything drops:
     * read the list via the 3.0.0 config channel → upsert heading rules →
     * ONE push (rollback on fail) → VERIFY the connector's stored siteRules
     * round-trip → clear the legacy option (the old buffer then no-ops via
     * its own empty-list early-return). Idempotent — re-running with an
     * empty list is a no-op.
     *
     * @return array{migrated:int,cleared:bool}|\WP_Error
     */
    public static function migrate_site_overrides(int $user_id, object $site)
    {
        global $wpdb;
        PCM_SEO_Service::ensure_sites_service();
        if (PCM_SEO_Page_Inventory::connector_rules_schema_version($site) < 3) {
            return new WP_Error(
                'pcm_seo_connector_no_heading_rules',
                __('This site’s connector doesn’t support heading instructions yet (needs v3.0.0+) — update it from the Sites module’s Connector column, then retry.', 'power-creatives'),
                array('status' => 409)
            );
        }
        $cfg = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/config');
        if (is_wp_error($cfg) || (int) ($cfg['status'] ?? 0) >= 300 || !is_array($cfg['body'] ?? null)) {
            return new WP_Error('pcm_seo_migrate_read', __('Could not read the site’s legacy override list.', 'power-creatives'), array('status' => 502));
        }
        $overrides = array_values(array_filter((array) ($cfg['body']['overrides'] ?? array()), 'is_array'));
        if (empty($overrides)) {
            return array('migrated' => 0, 'cleared' => false);
        }
        $table    = PCM_Schema::table('seo_dynamic_rules');
        $site_id  = (int) $site->id;
        $snapshot = self::post_rule_rows($user_id, $site_id, 0);
        $count    = 0;
        foreach ($overrides as $o) {
            $old_t = trim((string) ($o['oldText'] ?? ''));
            $new_t = trim((string) ($o['newText'] ?? ''));
            $old_l = max(1, min(6, (int) ($o['oldLevel'] ?? 0)));
            $new_l = max(1, min(6, (int) ($o['newLevel'] ?? 0)));
            if ($old_t === '' || $new_t === '') {
                continue;
            }
            $norm = PCM_Text_Matcher::normalize($old_t);
            // allOccurrences: migrated legacy overrides keep their apply-to-all semantics.
            $ctx  = wp_json_encode(array('level' => $old_l, 'newLevel' => $new_l, 'scope' => 'site', 'originalText' => $old_t, 'allOccurrences' => true));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $prev = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE userId = %d AND siteId = %d AND postId = 0 AND target = 'heading' AND matchText = %s",
                $user_id,
                $site_id,
                $norm
            ));
            if ($prev) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update($table, array('replacement' => $new_t, 'anchorContext' => $ctx, 'active' => 1), array('id' => (int) $prev), array('%s', '%s', '%d'), array('%d'));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert($table, array(
                    'userId' => $user_id, 'siteId' => $site_id, 'postId' => 0,
                    'target' => 'heading', 'matchText' => $norm, 'occurrence' => 0,
                    'replacement' => $new_t, 'anchorContext' => $ctx, 'active' => 1,
                ), array('%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d'));
            }
            $count++;
        }
        $push = self::push_current_rules_or_rollback($user_id, $site, 0, $snapshot);
        if ($push instanceof WP_Error) {
            return $push;
        }
        // VERIFY the connector actually stores every migrated instruction
        // before the legacy list is cleared — no big-bang, per the plan.
        $check = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/rules');
        $stored = (!is_wp_error($check) && is_array($check['body']['siteRules'] ?? null))
            ? array_column(array_filter((array) $check['body']['siteRules'], 'is_array'), 'match')
            : array();
        $stored_texts = array_map(static fn($m) => (string) ($m['text'] ?? ''), $stored);
        foreach ($overrides as $o) {
            $old_t = trim((string) ($o['oldText'] ?? ''));
            $new_t = trim((string) ($o['newText'] ?? ''));
            if ($old_t === '' || $new_t === '') {
                continue;
            }
            if (!in_array(PCM_Text_Matcher::normalize($old_t), $stored_texts, true)) {
                return new WP_Error(
                    'pcm_seo_migrate_verify',
                    __('Verification failed: the connector did not store every migrated instruction — the legacy overrides were NOT cleared, nothing changed on the live site.', 'power-creatives'),
                    array('status' => 502)
                );
            }
        }
        $clear = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/config', array(), array('clearOverrides' => true), 30);
        if (is_wp_error($clear) || (int) ($clear['status'] ?? 0) >= 300) {
            // Instructions serve already (after the overrides — same output);
            // the legacy list stayed. Safe but unfinished — say exactly that.
            return new WP_Error('pcm_seo_migrate_clear', __('Instructions are live, but clearing the legacy override list failed — retry the migration.', 'power-creatives'), array('status' => 502));
        }
        return array('migrated' => $count, 'cleared' => true);
    }

    /**
     * RE-KEY section/sectionInsert rules after a successful heading source-edit
     * (interaction law): their matchText/occurrence/level follow the heading so
     * the rules keep serving. Push-fail restores the snapshot — hub and connector
     * then AGREE on the old-keyed (now honestly stale) state; the stale flag is
     * the surface, never a divergence.
     */
    public static function rekey_section_rules(int $user_id, object $site, int $post_id, string $old_norm, int $old_occ, string $new_norm, int $new_occ, int $new_level): void
    {
        global $wpdb;
        // NOTE: even when text+occurrence are unchanged the LEVEL may have changed —
        // the update below always runs against whatever rules key on the old identity.
        $table = PCM_Schema::table('seo_dynamic_rules');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, anchorContext FROM {$table} WHERE userId = %d AND siteId = %d AND postId = %d AND target IN ('section','sectionInsert') AND matchText = %s AND occurrence = %d",
            $user_id,
            (int) $site->id,
            $post_id,
            $old_norm,
            $old_occ
        ), ARRAY_A);
        if (empty($rows)) {
            return;
        }
        $snapshot = self::post_rule_rows($user_id, (int) $site->id, $post_id);
        foreach ($rows as $r) {
            $ctx = json_decode((string) ($r['anchorContext'] ?? ''), true);
            $ctx = is_array($ctx) ? $ctx : array();
            $ctx['level'] = $new_level;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $table,
                array('matchText' => $new_norm, 'occurrence' => $new_occ, 'anchorContext' => wp_json_encode($ctx)),
                array('id' => (int) $r['id']),
                array('%s', '%d', '%s'),
                array('%d')
            );
        }
        self::push_current_rules_or_rollback($user_id, $site, $post_id, $snapshot);
    }

    /** AI-rewrite a whole section / draft a NEW one (NOT saved — staged). Returns { value } = block HTML.
     *  With $draft (a revise): THE HUMAN-EDITOR CONTRACT + retention check ride
     *  the run — a targeted note may never silently rewrite the whole draft
     *  (gap e8fcae5 D3). */
    public static function remote_optimize_section(object $site, int $post_id, string $type, string $html, string $topic = '', ?string $model = null, ?int $user_id = null, ?string $provider = null, ?int $template_id = null, string $draft = '', bool $report_changes = false, array $purposes = array())
    {
        PCM_SEO_Service::ensure_sites_service();
        $route = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
        $res   = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('_fields' => 'id,title,slug,link,author,meta'));
        $row   = (!is_wp_error($res) && is_array($res['body'] ?? null)) ? PCM_SEO_Service::remote_row($res['body'], $type, $site) : array();
        $vars  = PCM_SEO_Service::remote_field_vars($site, $row, $user_id, $post_id);
        $vars['current_value'] = $html;
        $vars['topic']         = $topic;
        // No hidden prompt: previously baked directly into $vars['topic'] as a
        // literal string, invisible to and unremovable by any template — now
        // its own Templates (module=seo) row ('revise_contract').
        $default_contract = 'You are a careful human editor revising an existing draft. Apply the user\'s request below EXACTLY '
            . 'and ONLY. Every sentence the request does not cover must be reproduced VERBATIM — word for word, '
            . 'unchanged, in full. Only if the request explicitly asks for a broad rewrite (tone, style, length, full '
            . 'rework) may you change text beyond it. Never invent facts.';
        $contract = PCM_SEO_AI::resolve_prompt('revise_contract_generate', $default_contract, $user_id);
        if ($draft !== '') {
            $vars['topic'] = $contract . "\n\nUSER REQUEST: " . $topic
                . "\n\nTHE CURRENT DRAFT (revise THIS text):\n" . $draft;
        }
        // THE CHANGE-CARD REVIEW (gap 0a0a3c3): the append-envelope contract —
        // the exact output contract lives IN the prompt (Anthropic JSON law),
        // appended server-side around the template like the revise contract
        // above, so user-customized templates need no migration. The reply is
        // PARSED AND VERIFIED by parse_section_reply(); an unparseable reply
        // falls back to raw — byte-identical legacy behavior, the floor.
        // Also no hidden prompt: 'revise_envelope' is its own Templates row.
        $envelope = '';
        if ($report_changes) {
            $why = !empty($purposes)
                ? 'one of these purpose ids: ' . implode(', ', array_map('sanitize_key', $purposes)) . ' (or an empty string when none fits)'
                : 'an empty string';
            $default_envelope = "\n\nOUTPUT FORMAT (mandatory): respond with ONLY this JSON, no markdown fences, no text around it: "
                . '{"html":"<the COMPLETE revised section HTML>","changes":[{"what":"one plain sentence describing ONE change you actually made","why":"<{{why}}>","quote":"5-12 words copied VERBATIM from your revised html"}]}'
                . ' List every real change you made; NEVER list a change you did not make.';
            $envelope_tpl = PCM_SEO_AI::resolve_prompt('revise_envelope_generate', $default_envelope, $user_id);
            $envelope     = PCM_SEO_AI::substitute_vars($envelope_tpl, array('why' => $why));
            $vars['topic'] .= $envelope;
        }
        $mode  = ($html !== '') ? 'optimize' : 'generate';
        $max   = (int) (PCM_SEO_AI::field_prompts()['section']['max'] ?? 1200);
        $val   = PCM_SEO_Local::run_prompt_section('section', $mode, $vars, $max, $model, $user_id, $provider, $template_id, false);
        if ($val instanceof WP_Error) {
            return $val;
        }
        // Parse BEFORE any retention math — with the envelope the raw reply
        // is JSON and retention must measure the extracted html, never the
        // JSON wrapper (gap 0a0a3c3).
        $parsed = self::parse_section_reply((string) $val['value'], $purposes, $report_changes);
        if ($draft !== '') {
            $min_retention = (float) ((PCM_Optimizer_Service::research_tunables()['revise']['minRetention'] ?? 0.6));
            $retention     = self::sentence_retention($draft, $parsed['value']);
            if ($retention < $min_retention && !self::note_wants_broad_rewrite($topic, $model, $user_id, $provider)) {
                // ONE retry with the contract restated — then honesty, never a
                // silent 80% text loss.
                $vars['topic'] = $contract . ' THIS IS A RETRY: the previous attempt rewrote text the request did not '
                    . 'cover. Copy the draft exactly and change ONLY what the request demands.'
                    . "\n\nUSER REQUEST: " . $topic . "\n\nTHE CURRENT DRAFT (revise THIS text):\n" . $draft
                    . $envelope;
                $retry = PCM_SEO_Local::run_prompt_section('section', $mode, $vars, $max, $model, $user_id, $provider, $template_id, false);
                if (!($retry instanceof WP_Error)) {
                    $retry_parsed = self::parse_section_reply((string) $retry['value'], $purposes, $report_changes);
                    if (self::sentence_retention($draft, $retry_parsed['value']) > $retention) {
                        $val       = $retry;
                        $parsed    = $retry_parsed;
                        $retention = self::sentence_retention($draft, $parsed['value']);
                    }
                }
                if ($retention < $min_retention) {
                    return new WP_Error('pcm_seo_revise_overwrote', __('The AI changed much more of the text than the note asked for — try again, or rephrase the note (say explicitly if you WANT a full rewrite).', 'power-creatives'), array('status' => 502));
                }
            }
        }
        // REWRITTEN verdict (gap 0a0a3c3): measured server-side against the
        // ORIGINAL section with the hub-data threshold — the review presents
        // such sections as calm Before/After blocks instead of word confetti.
        $review_cfg = (array) (PCM_Optimizer_Service::research_tunables()['review'] ?? array());
        $rewritten  = $html !== ''
            && self::sentence_retention($html, $parsed['value']) < (float) ($review_cfg['rewriteRetention'] ?? 0.35);
        // The UI shows what ACTUALLY generated (the API's own report).
        return array(
            'value'     => wp_kses_post($parsed['value']),
            'model'     => (string) $val['model'],
            'provider'  => (string) $val['provider'],
            // Verified per-section changes (what/why/quote) — empty when the
            // model answered without the envelope contract (the honest floor).
            'changes'   => $parsed['changes'],
            'rewritten' => $rewritten,
        );
    }

    /**
     * Parse + VERIFY the section reply under the change-card envelope
     * (gap 0a0a3c3). Reply contract: {"html": "...", "changes":
     * [{what, why, quote}]}. Every change survives ONLY if its quote is
     * found VERBATIM (whitespace/case-normalized) in the html's text and
     * its why is one of the run's purposes ('' otherwise) — the model's
     * confession is checked, never believed. Any parse failure returns the
     * RAW reply with no changes: byte-identical legacy behavior, the floor.
     *
     * @param string   $raw      The model's raw reply.
     * @param string[] $purposes Allowed why ids for this run.
     * @param bool     $expected Whether the envelope was requested at all.
     * @return array{value:string,changes:array<int,array{what:string,why:string,quote:string}>}
     */
    /**
     * Remove markdown code-fence markers from a value destined for the PAGE.
     *
     * The section contract forbids fences, but models emit them anyway — as a
     * wrapper, as a stray ```html on its own line mid-document, or inside the
     * envelope's html field. Any of those reaches the reader as literal
     * backticks once the HTML is served.
     *
     * Only the MARKERS are removed, never the content between them: if the model
     * genuinely fenced markup it wanted kept, the markup survives.
     *
     * @param string $html Model output.
     * @return string The same value with fence markers gone.
     */
    private static function strip_code_fences(string $html): string
    {
        $out = trim($html);
        if ($out === '') {
            return '';
        }
        // A fence wrapping the whole value.
        if (preg_match('/^```[a-zA-Z0-9]*\s*(.*?)\s*```$/s', $out, $m)) {
            $out = trim($m[1]);
        }
        // Markers left on their own line mid-document.
        $out = (string) preg_replace('/^[ 	]*```[a-zA-Z0-9]*[ 	]*\R?/m', '', $out);
        // Anything still inline, longest token first so ```html goes before ```.
        $out = str_ireplace(array('```html', '```'), '', $out);
        return trim($out);
    }

    public static function parse_section_reply(string $raw, array $purposes = array(), bool $expected = true): array
    {
        // Fences are stripped on EVERY exit, including this one. When the envelope
        // is absent or unparseable the raw reply becomes the page content verbatim,
        // which is how "```html" ended up rendered on live pages.
        $fallback = array('value' => self::strip_code_fences($raw), 'changes' => array());
        if (!$expected) {
            return $fallback;
        }
        $body = trim($raw);
        // Tolerate fenced replies (```json ... ```) — the contract forbids
        // them but a recoverable reply beats a discarded one.
        if (preg_match('/^```[a-z]*\s*(.*?)\s*```$/s', $body, $m)) {
            $body = trim($m[1]);
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['html']) || !is_string($decoded['html']) || trim($decoded['html']) === '') {
            return $fallback;
        }
        // The wrapping fence was handled above, but a model that emits
        // ```html INSIDE the html field slipped it straight onto the page.
        $value      = self::strip_code_fences((string) $decoded['html']);
        if ($value === '') {
            return $fallback;
        }
        $norm       = static fn(string $s): string => strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
        $value_text = $norm(wp_strip_all_tags($value));
        $allowed    = array_map('sanitize_key', $purposes);
        // class_exists = standalone-harness compatibility (bare PHP loads the
        // seo service alone); the default mirrors the seeded tunable.
        $max = (int) (class_exists('PCM_Optimizer_Service')
            ? (PCM_Optimizer_Service::research_tunables()['review']['maxChanges'] ?? 12)
            : 12);
        $changes    = array();
        foreach ((array) ($decoded['changes'] ?? array()) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $what  = sanitize_text_field((string) ($c['what'] ?? ''));
            $quote = sanitize_text_field((string) ($c['quote'] ?? ''));
            if ($what === '' || $quote === '' || strpos($value_text, $norm($quote)) === false) {
                continue; // unverifiable claim — never shown as a card
            }
            $why       = sanitize_key((string) ($c['why'] ?? ''));
            $changes[] = array(
                'what'  => $what,
                'why'   => in_array($why, $allowed, true) ? $why : '',
                'quote' => $quote,
            );
            if (count($changes) >= max(1, $max)) {
                break;
            }
        }
        return array('value' => $value, 'changes' => $changes);
    }


    /**
     * How much of the draft survived, sentence-wise: the share of the
     * draft's substantial sentences (≥40 chars) present verbatim
     * (whitespace/case-normalized) in the result. No measurable sentences
     * → 1.0 (never block a tiny draft).
     *
     * @param string $draft  The draft sent for revision.
     * @param string $result The model's output.
     * @return float 0..1
     */
    private static function sentence_retention(string $draft, string $result): float
    {
        $norm        = static fn(string $s): string => strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
        $result_norm = $norm(wp_strip_all_tags($result));
        $sentences   = preg_split('/(?<=[.!?])\s+/u', wp_strip_all_tags($draft)) ?: array();
        $len         = static fn(string $s): int => function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
        $substantial = array_values(array_filter(array_map($norm, $sentences), static fn(string $s): bool => $len($s) >= 40));
        if (empty($substantial)) {
            return 1.0;
        }
        $kept = 0;
        foreach ($substantial as $s) {
            if (strpos($result_norm, $s) !== false) {
                $kept++;
            }
        }
        return $kept / count($substantial);
    }

    /**
     * Dynamic scope judgment (never keyword-hardcoded): does the note ask
     * for a BROAD rewrite? Unjudgeable (LLM error) → false — the strict
     * path protects the user's text.
     *
     * @param string      $note     The revise note.
     * @param string|null $model    Model override.
     * @param int|null    $user_id  Key owner.
     * @param string|null $provider Provider override.
     * @return bool
     */
    private static function note_wants_broad_rewrite(string $note, ?string $model, ?int $user_id, ?string $provider): bool
    {
        try {
            // No hidden prompt: 'revise_scope_classifier' is its own Templates
            // (module=seo) row a user can view/edit.
            $default_system = 'Judge ONE thing about the user\'s revision request: does it ask for a BROAD rewrite of the whole text '
                . '(tone, style, length, full rework) or a TARGETED change (specific facts, words, numbers, links)? '
                . 'Respond with ONLY this JSON, no markdown: {"broad":true} or {"broad":false}.';
            $system = PCM_SEO_AI::resolve_prompt('revise_scope_classifier_generate', $default_system, $user_id);
            $parsed = PCM_LLM::invoke_json(
                array(
                    array('role' => 'system', 'content' => $system),
                    array('role' => 'user', 'content' => $note),
                ),
                array('name' => 'revise_scope', 'schema' => array('type' => 'object', 'properties' => array('broad' => array('type' => 'boolean')), 'required' => array('broad'))),
                array('model' => $model ?: null, 'provider' => $provider ?: null, 'user_id' => (int) $user_id)
            );
            return !empty($parsed['broad']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Dropdown option data for the content table (authors + statuses).
     *
     * @return array
     */
    public function get_options(): array
    {
        $authors = array();
        foreach (get_users(array('capability' => array('edit_posts'), 'fields' => array('ID', 'display_name'))) as $u) {
            $authors[] = array('id' => (int) $u->ID, 'name' => $u->display_name);
        }
        return array(
            'authors'    => $authors,
            'statuses'   => PCM_SEO_Service::VALID_STATUSES,
            // Every public content type, so the Type filter offers the custom ones too.
            'types'      => PCM_SEO_Local::content_types(),
            'seoPlugin'  => PCM_SEO_Local::detect_seo_plugin(),
        );
    }
}
