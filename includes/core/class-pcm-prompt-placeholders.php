<?php
/**
 * Prompt Placeholders — Declarative Registry + Idempotent Migration
 *
 * Self-healing migration that brings legacy DB-stored prompt overrides into
 * sync with the current placeholder contract defined by `get_default_prompts()`.
 *
 * Background
 * ----------
 * When a new placeholder (e.g. `{{creativeBrief}}`) is added to a default prompt
 * template in code, existing rows in `pcm_prompt_overrides` keep the OLD template
 * content (seeds are idempotent and never overwrite existing rows). The result:
 * the variable is mapped in `$vars` at render time but the template string has
 * no slot to receive it, so the value is silently dropped from the LLM prompt.
 *
 * The Registry below declares, for each placeholder we ship in defaults, WHERE
 * it belongs (which sections), AT WHICH ANCHOR text it must be injected, and
 * WHICH BLOCK to inject. `sync_all()` walks every row in `prompt_overrides`,
 * skips those that already contain the placeholder (idempotent + customization-
 * safe), and injects the block at the anchor for those that don't.
 *
 * No prompt content is ever overwritten — only missing sections are added.
 * Missing anchors are reported (never silently appended) so the admin can
 * resolve manually without context damage.
 *
 * Adding future placeholders ({{templateTonality}}, {{copyFramework}}, …) is a
 * matter of adding new entries to INJECTIONS — no migration logic to rewrite.
 *
 * @package PowerCreatives
 * @since   1.12.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Prompt_Placeholders
{
    /**
     * Mutex transient key — prevents concurrent sync runs (admin button click
     * racing with plugins_loaded upgrade trigger, etc.).
     */
    const SYNC_LOCK_KEY = 'pcm_prompt_sync_lock';

    /**
     * Mutex TTL in seconds. Longer than a worst-case sync run, short enough
     * that a crashed run won't block real work for long.
     */
    const SYNC_LOCK_TTL = 60;

    /**
     * Declarative injection map.
     *
     * Each entry declares one placeholder × section-group × anchor combination.
     * The migration injects {block} BEFORE the first occurrence of {anchor} in
     * any matching row whose content does not already contain the placeholder.
     *
     * `modules` covers BOTH 'copy' and 'ads' because prompts are dual-seeded
     * under both namespaces (see class-pcm-prompt-seeds.php).
     *
     * Anchor choice rationale (verified against PCM_Copy_Service::get_default_prompts()):
     *   - system_prompt_ads / system_prompt_organic → "OUTPUT FORMAT:" sits after
     *     the BUSINESS CONTEXT block and before the rules; CREATIVE BRIEF belongs
     *     right before it as its own primary-directive section.
     *   - angle_generation / audience_generation / angle_generation_with_audiences
     *     → "REFERENCE ADS" anchor (substring match catches all three variants:
     *     "REFERENCE ADS (study these for style and angle inspiration):",
     *     "REFERENCE ADS (study these to understand existing targeting):",
     *     and bare "REFERENCE ADS:").
     *   - audience_research → "RESEARCH OBJECTIVES:" — this prompt has no
     *     REFERENCE ADS section, so we anchor on the next logical block.
     */
    const INJECTIONS = array(
        'creativeBrief_system' => array(
            'placeholder' => 'creativeBrief',
            'modules'     => array('copy', 'ads'),
            'sections'    => array('system_prompt_ads', 'system_prompt_organic'),
            'anchor'      => 'OUTPUT FORMAT:',
            'block'       => "CREATIVE BRIEF (follow these instructions closely — this is the user's primary directive):\n{{creativeBrief}}\n\n",
        ),
        'creativeBrief_generation' => array(
            'placeholder' => 'creativeBrief',
            'modules'     => array('copy', 'ads'),
            'sections'    => array('angle_generation', 'audience_generation', 'angle_generation_with_audiences'),
            'anchor'      => 'REFERENCE ADS',
            'block'       => "CREATIVE BRIEF:\n{{creativeBrief}}\n\n",
        ),
        'creativeBrief_research' => array(
            'placeholder' => 'creativeBrief',
            'modules'     => array('copy', 'ads'),
            'sections'    => array('audience_research'),
            'anchor'      => 'RESEARCH OBJECTIVES:',
            'block'       => "CREATIVE BRIEF:\n{{creativeBrief}}\n\n",
        ),
        // {{copyFramework}} — the selected copywriting framework. Sits right after
        // the CREATIVE BRIEF section and before OUTPUT FORMAT in the copy-generation
        // prompts. Runs after creativeBrief_system (same anchor), so on already-
        // patched rows it lands between the brief block and OUTPUT FORMAT.
        // Only the final-copy prompts — angle/audience generation don't use frameworks.
        'copyFramework_system' => array(
            'placeholder' => 'copyFramework',
            'modules'     => array('copy', 'ads'),
            'sections'    => array('system_prompt_ads', 'system_prompt_organic'),
            'anchor'      => 'OUTPUT FORMAT:',
            'block'       => "COPY FRAMEWORK (structure the copy using this framework when provided):\n{{copyFramework}}\n\n",
        ),
    );

    /**
     * Run the placeholder sync across all prompt_overrides rows.
     *
     * Idempotent: rows that already contain the placeholder are skipped.
     * Customization-safe: existing content is never overwritten — block is
     * inserted at the anchor, leaving everything else untouched.
     * Resilient: per-row try/catch ensures one bad row doesn't abort the run.
     *
     * @return array {
     *   @type bool   $success  Always true (per-row failures are reported, not raised).
     *   @type int    $patched  Rows that received an injection.
     *   @type int    $skipped  Rows that already had the placeholder (or no matching injection).
     *   @type int    $failed   Rows where the patch threw an exception.
     *   @type array  $errors   Human-readable messages for warnings/failures.
     *   @type bool   $locked   True if another sync was already running.
     * }
     */
    public static function sync_all(): array
    {
        global $wpdb;

        $result = array(
            'success' => true,
            'patched' => 0,
            'skipped' => 0,
            'failed'  => 0,
            'errors'  => array(),
            'locked'  => false,
        );

        // ── Mutex: prevent overlap between plugins_loaded trigger and admin button ──
        if (get_transient(self::SYNC_LOCK_KEY)) {
            $result['locked'] = true;
            $result['errors'][] = 'Sync already in progress — try again in a moment.';
            error_log('[PCM_PROMPT_SYNC] sync_all() short-circuited: mutex already held');
            return $result;
        }
        set_transient(self::SYNC_LOCK_KEY, 1, self::SYNC_LOCK_TTL);

        try {
            $table = PCM_Schema::table('prompt_overrides');

            // Build the set of (module, section) tuples we care about, derived
            // from the registry so adding a new injection automatically widens
            // the query without code changes here.
            $module_section_pairs = array();
            foreach (self::INJECTIONS as $injection) {
                foreach ($injection['modules'] as $module) {
                    foreach ($injection['sections'] as $section) {
                        $module_section_pairs[$module . '|' . $section] = array($module, $section);
                    }
                }
            }

            // Single query covering all relevant rows across all users + variants.
            // We patch ALL variants (active + inactive) so users can swap variants
            // later without re-breaking the placeholder.
            if (empty($module_section_pairs)) {
                error_log('[PCM_PROMPT_SYNC] sync_all() nothing to do: registry is empty');
                return $result;
            }

            $where_clauses = array();
            $where_args    = array();
            foreach ($module_section_pairs as $pair) {
                $where_clauses[] = '(module = %s AND section = %s)';
                $where_args[]    = $pair[0];
                $where_args[]    = $pair[1];
            }
            $sql  = "SELECT id, module, section, content FROM {$table} WHERE " . implode(' OR ', $where_clauses);
            $rows = $wpdb->get_results($wpdb->prepare($sql, $where_args));

            if (!is_array($rows)) {
                $result['errors'][] = 'Database query failed: ' . ($wpdb->last_error ?: 'unknown error');
                $result['success']  = false;
                error_log('[PCM_PROMPT_SYNC] sync_all() DB error: ' . $wpdb->last_error);
                return $result;
            }

            error_log('[PCM_PROMPT_SYNC] sync_all() processing ' . count($rows) . ' candidate row(s)');

            foreach ($rows as $row) {
                try {
                    $patched_content = self::patch_row_content(
                        (string) $row->content,
                        (string) $row->module,
                        (string) $row->section,
                        $result['errors']
                    );

                    if ($patched_content === null) {
                        // Either already had every relevant placeholder OR no injection applied
                        // (e.g. anchor missing — already logged into $errors).
                        $result['skipped']++;
                        continue;
                    }

                    $updated = $wpdb->update(
                        $table,
                        array(
                            'content'   => $patched_content,
                            'updatedAt' => current_time('mysql'),
                        ),
                        array('id' => (int) $row->id),
                        array('%s', '%s'),
                        array('%d')
                    );

                    if ($updated === false) {
                        throw new \RuntimeException('wpdb->update failed: ' . ($wpdb->last_error ?: 'unknown error'));
                    }

                    $result['patched']++;
                    error_log(sprintf(
                        '[PCM_PROMPT_SYNC] patched row id=%d module=%s section=%s',
                        (int) $row->id,
                        $row->module,
                        $row->section
                    ));
                } catch (\Throwable $e) {
                    $result['failed']++;
                    $msg = sprintf(
                        'Row id=%d module=%s section=%s — %s',
                        (int) $row->id,
                        $row->module,
                        $row->section,
                        $e->getMessage()
                    );
                    $result['errors'][] = $msg;
                    error_log('[PCM_PROMPT_SYNC] FAILED ' . $msg);
                    // continue with next row
                }
            }
        } finally {
            delete_transient(self::SYNC_LOCK_KEY);
        }

        error_log(sprintf(
            '[PCM_PROMPT_SYNC] sync_all() complete — patched=%d skipped=%d failed=%d',
            $result['patched'],
            $result['skipped'],
            $result['failed']
        ));

        return $result;
    }

    /**
     * Apply every registry injection that targets this row's (module, section).
     *
     * Returns the patched content string when at least one injection was
     * actually applied. Returns null if no change was needed (either every
     * relevant placeholder is already present, or the only candidate injection
     * could not be applied because its anchor is missing — in which case a
     * warning has been appended to $errors).
     *
     * Customization-safe: each injection runs an independent presence-check on
     * `{{placeholder}}`, so user-edited prompts that already moved the
     * placeholder somewhere else are left untouched.
     *
     * @param string $content  Original prompt content from DB.
     * @param string $module   Row's module ('copy' or 'ads').
     * @param string $section  Row's section ('system_prompt_ads', etc.).
     * @param array  $errors   Output parameter — warnings appended here.
     *
     * @return string|null  Patched content, or null if nothing changed.
     */
    private static function patch_row_content(string $content, string $module, string $section, array &$errors): ?string
    {
        $original = $content;

        foreach (self::INJECTIONS as $key => $injection) {
            if (!in_array($module, $injection['modules'], true)) {
                continue;
            }
            if (!in_array($section, $injection['sections'], true)) {
                continue;
            }

            $placeholder_token = '{{' . $injection['placeholder'] . '}}';
            if (strpos($content, $placeholder_token) !== false) {
                // Already there — respects customizations that moved the
                // placeholder, and makes re-runs no-ops (idempotency).
                continue;
            }

            $anchor_pos = strpos($content, $injection['anchor']);
            if ($anchor_pos === false) {
                // Customized prompt removed/renamed the anchor — we refuse to
                // append blindly because that would land the block AFTER the
                // task section, corrupting prompt context. Log + report so the
                // admin can resolve it manually in the Prompt Editor.
                $msg = sprintf(
                    'Anchor "%s" not found in module=%s section=%s (injection=%s) — row skipped, please add placeholder manually',
                    $injection['anchor'],
                    $module,
                    $section,
                    $key
                );
                $errors[] = $msg;
                error_log('[PCM_PROMPT_SYNC_WARNING] ' . $msg);
                continue;
            }

            $content = substr($content, 0, $anchor_pos)
                . $injection['block']
                . substr($content, $anchor_pos);
        }

        return $content === $original ? null : $content;
    }
}
