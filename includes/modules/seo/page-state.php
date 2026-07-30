<?php
/**
 * SEO Page State — page versioning for connected sites.
 *
 * Extracted VERBATIM from PCM_SEO_Service (2026-07-29 decomposition, phase 4).
 *
 * PAGE STATE (page versioning, frozen contracts 2026-07-16) — ONE
 * version+fingerprint per "siteId:postId": the hub records it beside
 * every accepted push, the connector stores the VALUE and echoes it
 * (never recomputes), the inventory reply compares echo vs record.
 *
 * NOTE on visibility: `write_page_state()` and `page_state_reply()` were
 * `private` inside the old monolith. The save transaction and the inventory
 * reply — both still on PCM_SEO_Service — call them, so extraction forced them
 * to `public`. The CONTRACT is unchanged and still narrow: write_page_state()
 * may run only after an ACCEPTED push. tests/standalone/page_versioning_test.php
 * reaches it via ReflectionMethod, which keeps working either way.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_SEO_Page_State
{
    /**
     * Content fingerprint of a NET rule set (schema shape, rules_to_schema
     * output) — sha1 of its normalized JSON. THE one fingerprint function
     * (frozen contract): the connector receives the value and echoes it,
     * nothing anywhere recomputes it from served HTML.
     *
     * Normalization = CONTENT identity, not storage identity: row ids are
     * dropped (rollback/replace re-creates rows without changing what
     * serves), map keys sort recursively, and the rule LIST sorts by its
     * encoded form (row order is a storage accident). Lists inside a rule
     * (paragraphs, units) keep their order — order there IS content.
     */
    public static function page_fingerprint(array $rules): string
    {
        $normalize = static function ($value) use (&$normalize) {
            if (!is_array($value)) {
                return $value;
            }
            $out = array();
            foreach ($value as $k => $v) {
                $out[$k] = $normalize($v);
            }
            if ($out !== array_values($out)) {
                ksort($out);
            }
            return $out;
        };
        $encoded = array();
        foreach ($rules as $rule) {
            $rule = (array) $rule;
            unset($rule['id']);
            $encoded[] = (string) wp_json_encode($normalize($rule));
        }
        sort($encoded, SORT_STRING);
        return sha1('[' . implode(',', $encoded) . ']');
    }

    /**
     * The recorded page state for one "siteId:postId" (option 'pcm_page_state',
     * the proven option-map pattern — no schema change). version 0 +
     * fingerprint '' = never saved under versioning: the documented baseline,
     * which the inventory reply reports as drifted:false (nothing recorded =
     * nothing to drift from).
     *
     * @return array{version:int,fingerprint:string,savedAt:int}
     */
    public static function page_state(int $site_id, int $post_id): array
    {
        $map = get_option('pcm_page_state', array());
        $rec = is_array($map) ? ($map[$site_id . ':' . $post_id] ?? null) : null;
        return array(
            'version'     => is_array($rec) ? (int) ($rec['version'] ?? 0) : 0,
            'fingerprint' => is_array($rec) ? (string) ($rec['fingerprint'] ?? '') : '',
            'savedAt'     => is_array($rec) ? (int) ($rec['savedAt'] ?? 0) : 0,
        );
    }

    /**
     * THE FEATHERWEIGHT CHECK (gap e48b1ff): local record vs the connector's
     * /page-state answer — no page render anywhere. remote=null + error set
     * when the site could not answer (an unanswered question is reported as
     * unanswered, never as a verdict). brandId/pageType ride along so the
     * editor's instant-open path needs NO inventory fetch for them.
     *
     * @return array{local:array,remote:?array,drifted:?bool,error:?string,brandId:int,pageType:string}
     */
    public static function page_state_compare(object $site, int $post_id, ?int $user_id = null): array
    {
        PCM_SEO_Service::ensure_sites_service();
        $local = self::page_state((int) $site->id, $post_id);
        $out   = array(
            'local'    => $local,
            'remote'   => null,
            'drifted'  => null,
            'error'    => null,
            'brandId'  => (int) ($site->brandId ?? 0),
            'pageType' => ($user_id && $post_id) ? PCM_SEO_Service::get_page_type($user_id, (int) $site->id, $post_id) : '',
        );
        // Timeout is hub DATA (read-through seed — the tunables law).
        $cfg = get_option('pcm_seo_state_check');
        if (!is_array($cfg) || !isset($cfg['timeoutS'])) {
            $cfg = array('timeoutS' => 5);
            add_option('pcm_seo_state_check', $cfg, '', false);
        }
        // $body MUST be null on GET: any non-null body is wp_json_encode()d to a
        // STRING, and WP's cURL transport http_build_query()s GET data — a string
        // there is a TypeError 500 before the request ever leaves the hub (the
        // 2026-07-17 red-cloud root cause; this was the codebase's only array()-body GET).
        $rep = PCM_Sites_Service::remote_rest($site, 'GET', '/pcm-conn/v1/page-state', array('post_id' => $post_id), null, max(1, (int) $cfg['timeoutS']));
        if (is_wp_error($rep)) {
            $out['error'] = $rep->get_error_message();
            return $out;
        }
        // remote_rest answers {status, body} for EVERY HTTP status — only a
        // 200 is an ANSWER. HONESTY (gap 89ef71a, proven probe): 404 means
        // the site IS reachable but its connector predates the state check —
        // name the fix, never call a reachable site unreachable.
        $status = (int) ($rep['status'] ?? 0);
        if ($status === 404) {
            $out['error'] = __('The site\'s connector is outdated (needs 3.0.7+) — update it from the Sites module.', 'power-creatives');
            return $out;
        }
        if ($status !== 200 || !is_array($rep['body'] ?? null)) {
            $out['error'] = sprintf(__('The site answered the state check with HTTP %d.', 'power-creatives'), $status);
            return $out;
        }
        $remote = array(
            'version'     => (int) ($rep['body']['version'] ?? 0),
            'fingerprint' => (string) ($rep['body']['fingerprint'] ?? ''),
        );
        $out['remote'] = $remote;
        // Nothing recorded on either side = nothing to drift from (the
        // documented pre-versioning baseline).
        $out['drifted'] = ($local['version'] !== 0 || $remote['version'] !== 0)
            ? ($local['fingerprint'] !== $remote['fingerprint'])
            : false;
        return $out;
    }

    /**
     * The inventory reply's pageState block (frozen contract keys): the HUB
     * RECORD is the reported state; drifted = the connector's ECHO disagreeing
     * with it. An absent echo (pre-versioning connector, or a reply without
     * the field) is honest ignorance — reported drifted:false, never a guess
     * presented as truth.
     *
     * @param array{version:int,fingerprint:string}|null $echo Connector echo.
     * @return array{version:int,fingerprint:string,drifted:bool}
     */
    public static function page_state_reply(int $site_id, int $post_id, ?array $echo): array
    {
        $record = self::page_state($site_id, $post_id);
        return array(
            'version'     => $record['version'],
            'fingerprint' => $record['fingerprint'],
            'drifted'     => is_array($echo) && (
                (int) ($echo['version'] ?? 0) !== $record['version']
                || (string) ($echo['fingerprint'] ?? '') !== $record['fingerprint']
            ),
        );
    }

    /** Record an ACCEPTED push's page state — called only after the connector stored the same pair. */
    public static function write_page_state(int $site_id, int $post_id, int $version, string $fingerprint): void
    {
        $map = get_option('pcm_page_state', array());
        $map = is_array($map) ? $map : array();
        $map[$site_id . ':' . $post_id] = array('version' => $version, 'fingerprint' => $fingerprint, 'savedAt' => time());
        update_option('pcm_page_state', $map, false);
    }

    /**
     * W2 (replace-not-append): ids of page rule rows SUPERSEDED by the served
     * truth — section/sectionInsert rows the served view attributes to no
     * section serve nothing and can only stack (the observed 63-rule pile).
     * sectionRemove rows are part of the NET set BY LAW (they stay while the
     * doc omits their baseline section); every other target keeps its own
     * lifecycle law (absorb/clean-revert) and is never swept here.
     *
     * @param array[] $rows          The page's rule rows (post_rule_rows shape).
     * @param int[]   $live_rule_ids Rule ids the served inventory attributed.
     * @return int[] Row ids that must die with the save.
     */
    public static function superseded_rule_ids(array $rows, array $live_rule_ids): array
    {
        $live = array_map('intval', $live_rule_ids);
        $dead = array();
        foreach ($rows as $r) {
            $target = (string) ($r['target'] ?? '');
            if (($target === 'section' || $target === 'sectionInsert') && !in_array((int) ($r['id'] ?? 0), $live, true)) {
                $dead[] = (int) $r['id'];
            }
        }
        return $dead;
    }
}
