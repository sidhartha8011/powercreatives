<?php
/**
 * SEO — REMOTE HEADINGS (connected sites, via the connector).
 *
 * Extracted VERBATIM from `PCM_SEO_Service` (2026-07-31, decomposition phase 5).
 * Every H1–H6 on a connected post: read the snapshot, apply an override, update
 * one heading (owned-unit / section-owned / body-rewrite paths), and AI-optimize
 * a heading's text.
 *
 * WHY THIS ONE STRADDLES: only `heading_target_post_id()` is edge-free. The rest
 * reach back into four concerns that remain on `PCM_SEO_Service` — PAGE INVENTORY
 * (`served_inventory`), SECTION RULES (`rekey_section_rules`, `save_heading_rule`),
 * THE SAVE TRANSACTION (`update_owned_heading_unit`, `update_section_owned_heading`)
 * and Remote-site SEO (`remote_row`, `remote_field_vars`). Extracting this group
 * therefore forced SIX `private static` → `public static` promotions on
 * `PCM_SEO_Service` (all but `save_heading_rule`, already public, and
 * `ensure_sites_service`, a deliberate seam). Documented here rather than hidden,
 * per the decomposition convention — see `.claude/CODEBASE_MAP.md` → SEO suite detail.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_SEO_Remote_Headings
{
    /** Every H1–H6 on a connected post. v3 connectors: ONE snapshot, parsed hub-side
     *  (cleanup C2/C3 — /scan-headings no longer exists there). Pre-3.0 fleet:
     *  builder-aware /scan-headings (v2.1.7+), then a post-body-only parse of
     *  content.raw on older connectors. */
    public static function remote_get_headings(object $site, int $post_id, string $type, ?int $user_id = null): array
    {
        PCM_SEO_Service::ensure_sites_service();
        $inv = PCM_SEO_Page_Inventory::served_inventory($site, $post_id, $user_id);
        if ($inv !== null) {
            return $inv['headings'];
        }
        $scan = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/scan-headings', array('post_id' => $post_id));
        if (!is_wp_error($scan) && (int) ($scan['status'] ?? 0) < 300 && is_array($scan['body']['headings'] ?? null)) {
            $out = array(); $i = 0;
            foreach ($scan['body']['headings'] as $h) {
                $text = trim((string) ($h['text'] ?? ''));
                if ($text === '') { continue; }
                $out[] = array(
                    'index'    => $i,
                    'id'       => $i,
                    'level'    => max(1, min(6, (int) ($h['level'] ?? 2))),
                    'text'     => $text,
                    'html'     => (string) ($h['html'] ?? ''),
                    'source'   => (string) ($h['source'] ?? 'content'),
                    'elId'     => (string) ($h['elId'] ?? ''),
                    'field'    => (string) ($h['field'] ?? ''),
                    'tagKey'   => (string) ($h['tagKey'] ?? ''),
                    'textKey'  => (string) ($h['textKey'] ?? ''),
                    // Shared-source headings (Elementor Theme Builder templates / reusable blocks):
                    // the OWNING post id the edit must target, + a label so the UI can warn it
                    // changes every page using that source. 0 / '' for a page's own headings.
                    'sourcePostId' => (int) ($h['sourcePostId'] ?? 0),
                    'sourceType'   => (string) ($h['sourceType'] ?? ''),
                    'sourceLabel'  => (string) ($h['sourceLabel'] ?? ''),
                    'editable' => true,
                );
                $i++;
            }
            return $out;
        }

        // Fallback: parse the post body only (older / missing connector).
        $route = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
        $res = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('context' => 'edit', '_fields' => 'content'));
        if (is_wp_error($res) || (int) ($res['status'] ?? 0) >= 300 || !is_array($res['body'] ?? null)) {
            return array();
        }
        $raw      = (string) ($res['body']['content']['raw'] ?? '');
        $editable = ($raw !== '');
        $content  = $editable ? $raw : (string) ($res['body']['content']['rendered'] ?? '');
        $list     = PCM_SEO_Local::parse_heading_details($content);
        return array_values(array_map(static function ($h, $i) use ($editable) {
            $h['index']    = (int) $i;
            $h['id']       = (int) $i;
            $h['editable'] = $editable;
            return $h;
        }, $list, array_keys($list)));
    }

    /** Edit a connected post's heading (text and/or level) via the connector: builder-FIELD headings
     *  (Elementor/Bricks widgets) through /replace-heading, content/inline-HTML headings through the
     *  builder-aware /replace-url (oldHtml → newHtml). Returns the refreshed heading list. */
    /** Which post an edit must write to: a shared-source heading (Elementor Theme Builder template /
     *  reusable block) targets its OWNING post (`sourcePostId`); a page's own heading targets the page.
     *  Pure — unit-tested. */
    public static function heading_target_post_id(array $h, int $page_post_id): int
    {
        $src = (int) ($h['sourcePostId'] ?? 0);
        return $src > 0 ? $src : $page_post_id;
    }

    /** Apply a heading edit through the connector's render-time OVERRIDE layer (connector 2.6.0+):
     *  rewrites the matching `<hN>text</hN>` in the page output regardless of how/where the builder
     *  stores it. This is the universal path for headings whose stored form the builder-field /
     *  content replace can't reach (Brizy & other builders store headings as STRUCTURED nodes, not
     *  inline HTML, and regenerate their compiled HTML — so a string replace finds nothing / is lost).
     *  Returns the refreshed heading list on success, WP_Error otherwise. */
    private static function remote_apply_heading_override(object $site, int $post_id, string $type, string $old_text, int $old_level, ?string $new_text, int $new_level)
    {
        $rep = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/override-heading', array(), array(
            'post_id'  => $post_id,
            'oldText'  => $old_text,
            'oldLevel' => $old_level,
            'newText'  => ($new_text !== null ? $new_text : $old_text),
            'newLevel' => $new_level,
        ), 60);
        if (is_wp_error($rep)) {
            return new WP_Error('pcm_seo_remote_heading', $rep->get_error_message(), array('status' => 502));
        }
        if ((int) ($rep['status'] ?? 0) === 404) {
            return new WP_Error('pcm_seo_connector_outdated', __('This site needs connector v2.6.0+ to edit this heading — push it via Sites → "Update connectors" (or reinstall once), then retry.', 'power-creatives'), array('status' => 409));
        }
        if ((int) ($rep['status'] ?? 0) >= 300 || (int) ($rep['body']['replaced'] ?? 0) === 0) {
            return new WP_Error('pcm_seo_remote_heading', sprintf(__('The connector rejected the heading override (HTTP %d).', 'power-creatives'), (int) ($rep['status'] ?? 0)), array('status' => 502));
        }
        return self::remote_get_headings($site, $post_id, $type);
    }

    public static function remote_update_heading(object $site, int $post_id, string $type, int $index, ?string $text, ?int $level, ?int $user_id = null)
    {
        PCM_SEO_Service::ensure_sites_service();
        $headings = self::remote_get_headings($site, $post_id, $type, $user_id);
        if (!isset($headings[$index])) {
            return new WP_Error('pcm_seo_heading_not_found', __('Heading not found — re-open and try again.', 'power-creatives'), array('status' => 404));
        }
        // Section rules key on the heading (identity = normalized text + occurrence) —
        // capture the OLD identity before the edit so a successful source-write can
        // RE-KEY those rules in the same operation. A heading fix must never strand
        // its section rule stale (interaction law, contracts v2).
        $texts    = array_map(static fn($hh) => (string) ($hh['text'] ?? ''), $headings);
        $old_norm = PCM_Text_Matcher::normalize((string) $headings[$index]['text']);
        $old_occ  = PCM_Text_Matcher::occurrence_of($texts, $index);
        $via      = '';
        $result   = self::remote_update_heading_apply($site, $post_id, $type, $index, $text, $level, $headings, $via, $user_id);
        // Re-key ONLY on SOURCE writes (2.x fleet): they change the rules-INPUT
        // text, so section identities must follow. A RULE-mediated edit
        // ($via 'override') changes DISPLAY only — the rules-input is untouched
        // and re-keying would corrupt the rule's own match identity (live-found
        // defect 2026-07-10: rule 9 re-keyed to its own new output and went
        // permanently stale).
        if ($user_id && $via === 'source' && !is_wp_error($result) && ($text !== null || $level !== null)) {
            $new_text  = ($text !== null && $text !== '') ? $text : (string) $headings[$index]['text'];
            $new_level = ($level !== null) ? max(1, min(6, $level)) : (int) $headings[$index]['level'];
            $new_texts = is_array($result) ? array_map(static fn($hh) => (string) ($hh['text'] ?? ''), $result) : array();
            $new_occ   = isset($new_texts[$index]) ? PCM_Text_Matcher::occurrence_of($new_texts, $index) : $old_occ;
            PCM_SEO_Editing::rekey_section_rules($user_id, $site, $post_id, $old_norm, $old_occ, PCM_Text_Matcher::normalize($new_text), $new_occ, $new_level);
        }
        return $result;
    }

    /** The heading edit's routing core (override / widget / content paths) — unchanged
     *  behavior, extracted so the public method can re-key section rules on success.
     *  `$via` reports which layer took the edit: 'source' (raw content changed) or
     *  'override' (render-time layer; source unchanged) — the re-key decision. */
    private static function remote_update_heading_apply(object $site, int $post_id, string $type, int $index, ?string $text, ?int $level, array $headings, string &$via = '', ?int $user_id = null)
    {
        $h = $headings[$index];
        // Shared-source headings (template / reusable block) edit their owning post, not the page.
        $target_pid = self::heading_target_post_id($h, $post_id);
        if (empty($h['editable'])) {
            return new WP_Error('pcm_seo_no_raw', __('This page’s content isn’t editable through the API (e.g. a page-builder layout on an older connector). Update the connector, or edit this heading in the page builder.', 'power-creatives'), array('status' => 422));
        }
        $old_level = (int) $h['level'];
        $new_level = ($level !== null) ? max(1, min(6, $level)) : $old_level;
        $new_text  = $text; // null = keep

        // v3 row (carries the v2.2 rule identity): EVERY heading edit is a
        // dynamic instruction — one mechanism, storage never rewritten. Scope
        // comes from the row (chrome ⇒ site-wide, content ⇒ this page + this
        // occurrence-th twin only).
        if (isset($h['scope']) && in_array((string) ($h['source'] ?? ''), array('snapshot', 'override'), true)) {
            if (!$user_id) {
                return new WP_Error('pcm_seo_no_user', __('Heading edits need a signed-in hub user.', 'power-creatives'), array('status' => 401));
            }
            $post_scope = ((string) $h['scope']) !== 'site';
            // One-owner law: a section/insert rule already owning this heading
            // takes the edit; never stack a heading rule on top of it. Served
            // rows carry the owner directly (attribution); input-view rows
            // resolve it by identity.
            $att = (isset($h['rule']) && is_array($h['rule'])) ? $h['rule'] : null;
            if ($att !== null && in_array((string) ($att['target'] ?? ''), array('section', 'sectionInsert'), true)) {
                $owned = PCM_SEO_Editing::update_owned_heading_unit((int) $user_id, $site, (int) ($att['id'] ?? 0), (int) ($att['unitFrom'] ?? 0), (string) $h['text'], $new_text, $new_level);
                if ($owned instanceof WP_Error) {
                    return $owned;
                }
                $via = 'override';
                return self::remote_get_headings($site, $post_id, $type, $user_id);
            }
            if ($post_scope && $att === null) {
                $owned = PCM_SEO_Editing::update_section_owned_heading((int) $user_id, $site, $post_id, $h, $new_text, $new_level);
                if ($owned !== null) {
                    if ($owned instanceof WP_Error) {
                        return $owned;
                    }
                    $via = 'override';
                    return self::remote_get_headings($site, $post_id, $type, $user_id);
                }
            }
            $saved      = PCM_SEO_Editing::save_heading_rule((int) $user_id, $site, array(
                'postId'       => $post_scope ? $post_id : 0,
                'matchText'    => (string) ($h['matchText'] ?? PCM_Text_Matcher::normalize((string) $h['text'])),
                'matchLevel'   => (int) ($h['matchLevel'] ?? $old_level),
                'occurrence'   => (int) ($h['matchOccurrence'] ?? 0),
                'currentText'  => (string) $h['text'],
                'currentLevel' => $old_level,
            ), $new_text, $new_level);
            if ($saved instanceof WP_Error) {
                return $saved;
            }
            $via = 'override';
            return self::remote_get_headings($site, $post_id, $type, $user_id);
        }

        // RENDERED-ONLY heading (theme PHP / nav menu / widget title — no DB source anywhere), or one
        // already edited via the override layer: goes straight to the render-time override.
        if (in_array((string) ($h['source'] ?? ''), array('rendered', 'override'), true)) {
            $via = 'override';
            return self::remote_apply_heading_override($site, $post_id, $type, (string) $h['text'], $old_level, $new_text, $new_level);
        }

        // Builder-FIELD heading (text + level in separate meta fields) → connector /replace-heading.
        if ((string) ($h['field'] ?? '') === 'widget') {
            $rep = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/replace-heading', array(), array(
                'post_id'  => $target_pid,
                'elId'     => (string) $h['elId'],
                'oldText'  => (string) $h['text'],
                'newText'  => ($new_text !== null ? $new_text : (string) $h['text']),
                'newLevel' => $new_level,
                'textKey'  => (string) ($h['textKey'] ?? ''),
                'tagKey'   => (string) ($h['tagKey'] ?? ''),
            ), 60);
            if (is_wp_error($rep)) {
                return new WP_Error('pcm_seo_remote_heading', $rep->get_error_message(), array('status' => 502));
            }
            if ((int) ($rep['status'] ?? 0) === 404) {
                return new WP_Error('pcm_seo_connector_outdated', __('This site needs Power Creatives connector v2.1.7+ to edit headings. Re-download it from Sites → Download connector and reinstall on the connected site.', 'power-creatives'), array('status' => 409));
            }
            if ((int) ($rep['status'] ?? 0) >= 300) {
                return new WP_Error('pcm_seo_remote_heading', sprintf(__('The connector rejected the heading edit (HTTP %d).', 'power-creatives'), (int) ($rep['status'] ?? 0)), array('status' => 502));
            }
            if ((int) ($rep['body']['replaced'] ?? 0) === 0) {
                // The widget field couldn't be matched (stale scan, or a builder that renders the
                // heading without the widget keys we edit) → fall back to the render-time override,
                // which rewrites the visible heading regardless of storage.
                $via = 'override';
                return self::remote_apply_heading_override($site, $post_id, $type, (string) $h['text'], $old_level, $new_text, $new_level);
            }
            $via = 'source';
            return self::remote_get_headings($site, $post_id, $type);
        }

        // Content / inline-HTML heading → builder-aware URL-style replace of the whole element HTML.
        $old_html = (string) $h['html'];
        if ($old_html === '') {
            // No stored markup to string-replace (e.g. a builder that keeps the heading as a
            // structured node) — go straight to the render-time override.
            $via = 'override';
            return self::remote_apply_heading_override($site, $post_id, $type, (string) $h['text'], $old_level, $new_text, $new_level);
        }
        $new_html = PCM_SEO_Local::rebuild_heading_html($old_html, $old_level, $new_level, $new_text);
        if ($new_html === $old_html) {
            return self::remote_get_headings($site, $post_id, $type);
        }
        $body = array('post_id' => $target_pid, 'old' => $old_html, 'new' => $new_html);
        if ((string) ($h['elId'] ?? '') !== '') { $body['elId'] = (string) $h['elId']; }
        $rep = PCM_Sites_Service::remote_rest($site, 'POST', '/pcm-conn/v1/replace-url', array(), $body, 60);
        if (is_wp_error($rep)) {
            $err = $rep->get_error_message();
            $msg = (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false || stripos($err, 'cURL error 28') !== false)
                ? __('The connector took too long (large page) — the change likely applied; re-open and clear the page/CDN cache.', 'power-creatives')
                : sprintf(__('Could not reach the connector to edit the heading (%s).', 'power-creatives'), $err);
            return new WP_Error('pcm_seo_remote_heading', $msg, array('status' => 502));
        }
        if ((int) ($rep['status'] ?? 0) >= 300) {
            return new WP_Error('pcm_seo_remote_heading', sprintf(__('The connector rejected the heading edit (HTTP %d) — check its app-password user can edit, and that the connector is v2.1.7+.', 'power-creatives'), (int) ($rep['status'] ?? 0)), array('status' => 502));
        }
        if ((int) ($rep['body']['replaced'] ?? 0) === 0) {
            // The string replace matched nothing — the builder (Brizy, Divi, …) stores the heading as
            // a structured node, or regenerated its compiled HTML, so there's no literal <hN> to swap.
            // Fall back to the render-time override so the edit still applies on the visible page.
            $via = 'override';
            return self::remote_apply_heading_override($site, $post_id, $type, (string) $h['text'], $old_level, $new_text, $new_level);
        }
        $via = 'source';
        return self::remote_get_headings($site, $post_id, $type);
    }

    /** AI-optimize a connected post's heading text (NOT saved). Returns { value }. */
    public static function remote_optimize_heading(object $site, int $post_id, string $type, string $text, ?string $model = null, ?int $user_id = null, ?string $provider = null, ?int $template_id = null)
    {
        PCM_SEO_Service::ensure_sites_service();
        $route = ($type === 'page' ? '/wp/v2/pages/' : '/wp/v2/posts/') . $post_id;
        $res   = PCM_Sites_Service::remote_rest($site, 'GET', $route, array('_fields' => 'id,title,slug,link,author,meta'));
        $row   = (!is_wp_error($res) && is_array($res['body'] ?? null)) ? PCM_SEO_Service::remote_row($res['body'], $type, $site) : array();
        $vars  = PCM_SEO_Service::remote_field_vars($site, $row, $user_id, $post_id);
        $vars['current_value'] = $text;
        $mode  = ($text !== '') ? 'optimize' : 'generate';
        $max   = (int) (PCM_SEO_AI::field_prompts()['heading']['max'] ?? 80);
        // run_prompt_section returns {value, model, provider} — pass it through.
        return PCM_SEO_Local::run_prompt_section('heading', $mode, $vars, $max, $model, $user_id, $provider, $template_id);
    }
}
