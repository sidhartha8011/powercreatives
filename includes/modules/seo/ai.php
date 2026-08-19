<?php
/**
 * SEO AI field generation — prompts, template resolution, and the generate/
 * optimize entry points for the SEO table's AI actions.
 *
 * Extracted VERBATIM from PCM_SEO_Service (2026-07-29 decomposition, phase 2).
 *
 * AI field generation (Phase 3) — reuses PC's PCM_LLM provider routing.
 * Prompt DEFAULTS live in `prompts.php`; a user's override lives as a Templates
 * row (module=seo) resolved by resolve_prompt(). The only dependency back on
 * PCM_SEO_Service is seo_get() (cross-plugin meta read) — this class owns
 * everything else it touches.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_SEO_AI
{
    /** Cell field → prompt `use` key. Only these fields are AI-generatable. */
    public static function field_use_map(): array
    {
        return array(
            'title'           => 'page_title',
            'metaTitle'       => 'meta_title',
            'metaDescription' => 'meta_description',
            'metaKeywords'    => 'meta_keywords',
            'primaryKeyword'  => 'primary_keyword',
            'slug'            => 'slug',
        );
    }

    /**
     * Default field prompts (verbatim port), filterable for site overrides.
     *
     * @return array<string, array{max:int, generate:string, optimize?:string}>
     */
    public static function field_prompts(): array
    {
        static $prompts = null;
        if ($prompts === null) {
            $prompts = require __DIR__ . '/prompts.php';
        }
        $filtered = apply_filters('pcm_seo_field_prompts', $prompts);
        return is_array($filtered) ? $filtered : $prompts;
    }

    /**
     * Flatten the field prompts into the shared Prompt-Editor registry shape
     * ({module:'seo'} → section → content string). Sections are keyed
     * `{use}_{mode}` (e.g. `page_title_generate`, `content_optimize`). These
     * are the verbatim shipped defaults — surfaced as the "Built-in" variant
     * in Settings → Prompts → SEO and used whenever the user has no active
     * override. Consumed by PCM_REST_Prompts::get_default_prompt().
     *
     * @return array<string, string>
     */
    public static function get_default_prompts(): array
    {
        $out = array();
        foreach (self::field_prompts() as $use => $cfg) {
            if (!empty($cfg['generate'])) {
                $out[$use . '_generate'] = (string) $cfg['generate'];
            }
            if (!empty($cfg['optimize'])) {
                $out[$use . '_optimize'] = (string) $cfg['optimize'];
            }
        }
        return $out;
    }

    /**
     * Resolve the prompt template for a Prompt-Editor section: the user's
     * ACTIVE override (Settings → Prompts → SEO) when present, else the shipped
     * default. Mirrors PCM_Writer_Service::get_system_prompt.
     *
     * @param string   $section Section key, e.g. `meta_title_optimize`.
     * @param string   $default Shipped default template (fallback).
     * @param int|null $user_id PCM user id (wp_pcm_users.id), NOT the WP user id.
     * @return string
     */
    public static function resolve_prompt(string $section, string $default, ?int $user_id = null, ?int $template_id = null): string
    {
        if ($user_id && $user_id > 0) {
            // Prompts now live as Templates (module=seo). Seed defaults once, then
            // resolve the chosen/default template for this section.
            self::seed_seo_templates();
            $tpl = self::seo_template_prompt($user_id, $section, $template_id);
            // THE USER'S TEMPLATE FOLLOWS THE FIELD, NOT THE MODE (owner, 2026-08-17:
            // "in template it is written 'write in chinese' and it writes in chinese
            // when the field is empty but when I overwrite it starts writing in
            // english again"). Templates are typed per section — meta_title_generate
            // OR meta_title_optimize — and the resolver matches on type, so a template
            // written for the field was invisible to its OTHER mode and the shipped
            // English default ran there. Now: when THIS section resolves to nothing
            // more than the shipped default (no user template, no pick), the user's
            // own template for the SIBLING mode is used, framed for this mode. Only
            // the user's OWN rows carry over — never a shipped default of the other
            // mode, which would silently swap the built-in behaviour.
            if (($tpl === null || $tpl === '' || $tpl === $default) && preg_match('/^(.+)_(generate|optimize)$/', $section, $m)) {
                $sibling = self::seo_user_template_prompt($user_id, $m[1] . '_' . ($m[2] === 'generate' ? 'optimize' : 'generate'));
                // The eligibility law still applies to the carried-over prompt: an envelope
                // template can no more fill a scalar cell from the sibling mode than from its own.
                if ($sibling !== null && $sibling !== '' && !(self::is_scalar_section($section) && self::prompt_demands_envelope($sibling))) {
                    return self::frame_for_mode($sibling, $m[2]);
                }
            }
            if ($tpl !== null && $tpl !== '') {
                return $tpl;
            }
            // Legacy fallback: a pre-migration Settings→Prompts→SEO override.
            global $wpdb;
            $table    = PCM_Schema::table('prompt_overrides');
            $override = $wpdb->get_var($wpdb->prepare(
                "SELECT content FROM {$table} WHERE userId = %d AND module = 'seo' AND section = %s AND isActive = 1 ORDER BY updatedAt DESC LIMIT 1",
                $user_id,
                $section
            ));
            if (!empty($override)) {
                return (string) $override;
            }
        }
        return $default;
    }

    /**
     * The user's OWN template prompt for a section — starred first, else newest —
     * ignoring the shipped (userId=0) rows entirely. Null when the user has none.
     * Used for the sibling-mode carry-over: only a template the USER wrote should
     * follow the field across modes.
     */
    public static function seo_user_template_prompt(int $user_id, string $section): ?string
    {
        global $wpdb;
        $table = PCM_Schema::table('templates');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT formData, isDefault FROM {$table} WHERE userId = %d AND module = 'seo' ORDER BY isDefault DESC, updatedAt DESC, id DESC",
            $user_id
        ), ARRAY_A);
        foreach ((array) $rows as $r) {
            $fd = json_decode((string) ($r['formData'] ?? ''), true);
            if (!is_array($fd) || ($fd['type'] ?? '') !== $section) {
                continue;
            }
            $prompt = self::seo_entry_prompt($fd);
            if ($prompt !== null && trim($prompt) !== '') {
                return $prompt;
            }
        }
        return null;
    }

    /**
     * Frame a template written for ONE mode so it works in the OTHER.
     *
     * The shipped generate/optimize defaults differ in exactly one substantive way:
     * the optimize one hands the model the CURRENT value ("Current …: {{current_value}}")
     * and asks it to improve it. So a user's generate-worded template ("Write it in
     * Chinese…") is made optimize-capable by appending the current value when the
     * template doesn't already reference it — and an optimize-worded template used
     * on an EMPTY cell simply has nothing to reference (substitute_vars leaves
     * {{current_value}} empty), which the model handles as "write it fresh".
     */
    public static function frame_for_mode(string $prompt, string $mode): string
    {
        if ($mode === 'optimize' && strpos($prompt, '{{current_value}}') === false) {
            return rtrim($prompt) . "\n\nCurrent value: {{current_value}}\nImprove it — keep the same language and instructions above.";
        }
        return $prompt;
    }

    /**
     * Seed one default prompt Template per SEO section for a user (idempotent).
     * Stored in wp_pcm_templates (module=seo, formData={type,section,prompt,isDefault})
     * so prompts are managed as Templates rather than Prompt-Editor overrides.
     */
    public static function seed_seo_templates(): void
    {
        global $wpdb;
        $table = PCM_Schema::table('templates');
        // SYSTEM set (userId=0) — one shared default per section, visible to every
        // user (the Templates list + resolver both read `userId = me OR 0`). Idempotent.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows  = $wpdb->get_col("SELECT formData FROM {$table} WHERE userId = 0 AND module = 'seo'");
        $have  = array();
        foreach ($rows as $json) {
            $fd = json_decode((string) $json, true);
            if (!empty($fd['type'])) {
                $have[$fd['type']] = true;
            }
        }
        // Stored in the SAME shape the Templates module edits: module=seo,
        // formData.type=<section>, one entry {category:'prompt', value:<prompt>}.
        foreach (self::get_default_prompts() as $section => $prompt) {
            if (isset($have[$section])) {
                continue;
            }
            $name = self::seo_section_label($section);
            $form = array(
                'type'      => $section,
                'entries'   => array(array(
                    'key'      => 'prompt_' . $section,
                    'category' => 'prompt',
                    'label'    => $name,
                    'value'    => $prompt,
                )),
                'sortOrder' => 0,
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert($table, array(
                'userId'    => 0,
                'name'      => $name,
                'module'    => 'seo',
                'formData'  => wp_json_encode($form),
                'isDefault' => 1,
            ), array('%d', '%s', '%s', '%s', '%d'));
        }
    }

    /**
     * Does a prompt DEMAND the section-revise JSON envelope ({"html":…,"changes":…})?
     *
     * Such a prompt can only ever produce a structured blob — it is the contract of
     * revise_envelope, not of any single-value field. If it is routed to a scalar
     * section (a template whose TYPE says "Meta Title" while its VALUE still asks for
     * the envelope — the Templates UI lets the two be edited independently), every
     * generate for that cell dead-ends: "individual lines do not work. It complains
     * about HTML missing" (Filip). Detected on the OUTPUT-FORMAT instruction, not on the
     * mere word "html", so a legitimate prompt that says "no HTML" is unaffected.
     */
    public static function prompt_demands_envelope(string $prompt): bool
    {
        // The literal envelope key pair, or the mandatory-JSON instruction that only
        // revise_envelope carries.
        return (bool) preg_match('/"html"\s*:\s*"[^"]*"\s*,\s*"changes"\s*:|\{\s*"html"\s*:[\s\S]{0,200}"changes"\s*:/i', $prompt)
            || (bool) preg_match('/OUTPUT FORMAT \(mandatory\):[\s\S]{0,160}\{"html"/i', $prompt);
    }

    /** Sections whose output is ONE plain value — an envelope prompt is never eligible. */
    private static function is_scalar_section(string $section): bool
    {
        return (bool) preg_match('/^(page_title|meta_title|meta_description|meta_keywords|primary_keyword|slug)_(generate|optimize)$/', $section);
    }

    /** Set by seo_template_prompt() when the caller's EXPLICITLY picked template was
     *  skipped by the eligibility law. Read once by the generate paths and reported as
     *  `templateIgnored` so the UI can say the pick was not used. */
    public static bool $last_pick_ignored = false;

    /** Resolve a section's prompt from Templates (module=seo): the chosen template,
     *  else the user's own default, else the SYSTEM default, else any.
     *
     *  ELIGIBILITY LAW (2026-08-16): for a scalar section, a candidate whose value
     *  demands the JSON envelope is skipped — it cannot produce a value for that
     *  field, so it must not win the resolution. The shipped default (clean by
     *  construction) then resolves naturally: no wasted LLM call, no retry, no
     *  dead-end error, and the misrouted template can't hijack the field. */
    private static function seo_template_prompt(int $user_id, string $section, ?int $template_id): ?string
    {
        global $wpdb;
        $table = PCM_Schema::table('templates');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT id, userId, formData, isDefault, updatedAt FROM {$table} WHERE (userId = %d OR userId = 0) AND module = 'seo' ORDER BY updatedAt DESC, id DESC", $user_id), ARRAY_A);
        $scalar = self::is_scalar_section($section);
        self::$last_pick_ignored = false;   // reset per resolution
        $chosen = null;
        $chosen_shared = false; // requested id points at a SHARED (userId=0) row
        $user_fork = null;      // the user's most-recent OWN template for this section
        $user_default = null;
        $system_default = null;
        $any = null;
        foreach ($rows as $r) {
            $fd = json_decode((string) ($r['formData'] ?? ''), true);
            if (!is_array($fd) || ($fd['type'] ?? '') !== $section) {
                continue;
            }
            $prompt = self::seo_entry_prompt($fd);
            if ($prompt === null) {
                continue;
            }
            // Eligibility law: an envelope-demanding prompt can never fill a scalar cell.
            if ($scalar && self::prompt_demands_envelope($prompt)) {
                // If this was the EXPLICIT pick, remember it — the response tells the
                // UI so the user learns the pick was set aside instead of guessing.
                if ($template_id && (int) $r['id'] === $template_id) {
                    self::$last_pick_ignored = true;
                }
                continue;
            }
            if ($template_id && (int) $r['id'] === $template_id) {
                $chosen = $prompt;
                $chosen_shared = ((int) $r['userId'] === 0);
            }
            if ((int) $r['userId'] === $user_id && $user_fork === null) {
                $user_fork = $prompt;
            }
            if (!empty($r['isDefault'])) {
                if ((int) $r['userId'] === $user_id && $user_default === null) {
                    $user_default = $prompt;
                } elseif ((int) $r['userId'] === 0 && $system_default === null) {
                    $system_default = $prompt;
                }
            }
            if ($any === null) {
                $any = $prompt;
            }
        }
        // A picker can pass the ORIGINAL shared template's id from a cached list even after the
        // user's edit forked it into their own copy (the shared row stays in the DB, only hidden
        // from the list). Honoring the stale shared row would silently ignore the user's edit —
        // redirect to their fork of the same section instead.
        if ($chosen !== null && $chosen_shared && $user_fork !== null) {
            $chosen = $user_fork;
        }
        // A user's OWN template for this section beats the shipped system default
        // even when they never starred it — creating/duplicating a template for a
        // field is intent to use it. A starred one still wins (user_default first);
        // ties broken newest-first by the query's ORDER BY.
        return $chosen ?? $user_default ?? $user_fork ?? $system_default ?? $any;
    }

    /**
     * The mode ('generate'|'optimize') an EXPLICITLY PICKED template belongs to.
     *
     * Why this exists. The column menu offers both "<Field> — Generate" and
     * "<Field> — Optimize", but the mode was decided purely by whether the cell
     * was empty, and seo_template_prompt() only matches a template whose
     * formData.type equals "{$use}_{$mode}". So picking the Generate template on
     * a row that ALREADY had a value resolved section "…_optimize", never matched
     * the pick, and silently fell back to the optimize default — the reported
     * "sometimes it works, sometimes it doesn't". It worked on EMPTY cells (mode
     * happened to be generate) and failed on filled ones, which is exactly the
     * pattern that made it look random.
     *
     * An explicit pick is intent, so the pick now chooses the mode. Returns null
     * when nothing was picked or the template belongs to a DIFFERENT field, in
     * which case the emptiness heuristic stands.
     *
     * @param int      $user_id     PCM user id.
     * @param int|null $template_id The picked template, if any.
     * @param string   $use         Field key, e.g. 'meta_title'.
     * @return string|null 'generate' | 'optimize' | null
     */
    public static function template_mode(int $user_id, ?int $template_id, string $use): ?string
    {
        if (empty($template_id) || $template_id <= 0 || $use === '') {
            return null;
        }
        global $wpdb;
        $table = PCM_Schema::table('templates');
        // Shared rows (userId=0) are pickable too. A shared row and the user's fork
        // of it carry the SAME type, so reading either gives the right mode.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $form_data = $wpdb->get_var($wpdb->prepare(
            "SELECT formData FROM {$table} WHERE id = %d AND (userId = %d OR userId = 0) AND module = 'seo' LIMIT 1",
            $template_id,
            $user_id
        ));
        if (empty($form_data)) {
            return null;
        }
        $decoded = json_decode((string) $form_data, true);
        $type    = is_array($decoded) ? (string) ($decoded['type'] ?? '') : '';
        foreach (array('generate', 'optimize') as $mode) {
            if ($type === $use . '_' . $mode) {
                return $mode;
            }
        }
        return null; // a template for another field — ignore the pick
    }

    /**
     * Apply an explicit template pick to the mode the emptiness heuristic chose.
     *
     * 'optimize' is only viable when there IS something to optimize — the optimize
     * prompts are built around {{current_value}}, so honouring that pick on an
     * empty cell would ask the model to improve nothing. 'generate' is always
     * viable, and picking it on a filled cell is a deliberate "rewrite from
     * scratch", which is what the column menu's wording promises.
     *
     * @param string   $mode     Mode chosen by the emptiness heuristic.
     * @param int      $user_id  PCM user id.
     * @param int|null $template_id Picked template, if any.
     * @param string   $use      Field key.
     * @param string   $current  The cell's current value.
     * @param array    $prompts  field_prompts()[$use] — the available modes.
     * @return string The mode to actually run.
     */
    public static function apply_template_mode(string $mode, int $user_id, ?int $template_id, string $use, string $current, array $prompts): string
    {
        $picked = self::template_mode($user_id, $template_id, $use);
        if ($picked === null || empty($prompts[$picked])) {
            return $mode;
        }
        if ($picked === 'optimize' && trim($current) === '') {
            return $mode; // nothing to optimize — keep generate
        }
        return $picked;
    }

    /** Extract the prompt string from a SEO template's formData (entries[].value). */
    private static function seo_entry_prompt(array $fd): ?string
    {
        $entries = (isset($fd['entries']) && is_array($fd['entries'])) ? $fd['entries'] : array();
        foreach ($entries as $e) {
            if (($e['category'] ?? '') === 'prompt' && isset($e['value'])) {
                return (string) $e['value'];
            }
        }
        return isset($entries[0]['value']) ? (string) $entries[0]['value'] : null;
    }

    /** Human label for a section key, e.g. `meta_title_optimize` → "Meta Title — Optimize". */
    private static function seo_section_label(string $section): string
    {
        $mode = '';
        if (str_ends_with($section, '_generate')) {
            $mode = 'Generate';
            $section = substr($section, 0, -9);
        } elseif (str_ends_with($section, '_optimize')) {
            $mode = 'Optimize';
            $section = substr($section, 0, -9);
        }
        $field = ucwords(str_replace('_', ' ', $section));
        return $mode !== '' ? "{$field} — {$mode}" : $field;
    }

    /**
     * THE LANGUAGE LAW — appended to every prompt that produces text destined
     * for a page, so the answer comes back in the PAGE's language.
     *
     * Owner report 2026-08-08: "the changes in content should be in the
     * original language of the website whatever it is" — Swedish pages came
     * back rewritten in English. No default prompt named a language, and every
     * prompt AROUND the content is written in English, so the model followed
     * the instruction's language rather than the page's.
     *
     * The EXISTING CONTENT is the authority: it is what the page actually
     * speaks, whatever any setting claims. The configured site language is only
     * the fallback for generate-from-nothing, and is passed through verbatim
     * because it may be a code ('sv') or a name ('Swedish').
     *
     * Appended UNCONDITIONALLY, unlike the placeholder-gated append laws in
     * run_prompt_section(): there is no {{...}} for a template to opt into,
     * every seeded and user-edited template predates this, and a wrong-language
     * rewrite is never what anyone wanted.
     *
     * A STRONG DEFAULT, NOT AN ABSOLUTE (changed 2026-08-12). It first shipped
     * saying "overrides everything above", which did its job — no more silent
     * drift into English — but also meant an operator who deliberately wrote
     * "write the meta title in Chinese" into their template was ignored, with
     * no way to tell why. The law exists to prevent ACCIDENTAL language drift,
     * not to outrank an explicit instruction, so it now yields to one. Anything
     * that does not name a language behaves exactly as before.
     *
     * @param array<string, string> $vars Prompt vars; 'site.lang' is the hint.
     * @return string Text to append to the template (never empty).
     */
    /**
     * The language a template EXPLICITLY asks for, or '' when it names none.
     *
     * Owner (2026-08-17): "even if I write in the template to write in another
     * language it doesn't work sometimes." The law below said "write in the SAME
     * language as the existing content" and only added a soft trailing OVERRIDE
     * clause — while {{current_value}} sat in the prompt in Swedish/English. Two
     * competing instructions = a coin flip per call. Now the law itself flips: when
     * the template names a language, the law REINFORCES that language instead of
     * defaulting away from it.
     *
     * Matches "write in X", "in Chinese", "på svenska", "auf Deutsch", "en español",
     * "language: X", "output in X", "respond in X", "svara på X" etc. Returns the
     * language as written in the template (so the model sees the author's word).
     */
    public static function template_language(string $prompt): string
    {
        // Strip {{vars}} so a value like "{{site.lang}}" is not mistaken for a name.
        $t = preg_replace('/\{\{[^}]*\}\}/', ' ', $prompt);
        $langs = 'chinese|mandarin|cantonese|english|swedish|svenska|norwegian|norsk|danish|dansk|finnish|suomi|german|deutsch|french|français|francais|spanish|español|espanol|italian|italiano|portuguese|português|dutch|nederlands|polish|polski|russian|русский|japanese|日本語|korean|한국어|arabic|العربية|hindi|turkish|türkçe|greek|czech|hungarian|romanian|ukrainian|thai|vietnamese|indonesian|hebrew|简体中文|繁體中文|中文';
        // Verbs in the languages our authors write templates in (EN/SV/DE/ES/FR/NO/DA).
        $verbs = 'write|writing|written|answer|respond|reply|output|generate|create|produce|translate|compose'
            . '|skriv|skriva|svara|översätt|schreib|schreibe|schreiben|antworte|übersetze'
            . '|escribe|escribir|responde|traduce|écris|écrire|réponds|traduis|skriv|svar|oversett';
        $patterns = array(
            // "write … in Chinese", "skriv på svenska", "schreibe auf Deutsch", "escribe en español"
            '/\b(?:' . $verbs . ')\b[^.\n]{0,40}?\b(?:in|into|på|auf|en|em|in\s+the)\s+(' . $langs . ')\b/iu',
            // "Language: French" / "Språk: svenska"
            '/\b(?:language|språk|sprache|langue|idioma|lingua)\s*[:=\-–]\s*(' . $langs . ')\b/iu',
            // "in Chinese only" / "på svenska" standing alone as an instruction
            '/\b(?:in|på|auf|en)\s+(' . $langs . ')\s+(?:language|only|please)?\b/iu',
            '/\b(' . $langs . ')\s+(?:language|only)\b/iu',
        );
        foreach ($patterns as $p) {
            if (preg_match($p, $t, $m)) {
                return trim($m[1]);
            }
        }
        return '';
    }

    public static function language_law(array $vars = array(), string $template = ''): string
    {
        $hint = trim((string) ($vars['site.lang'] ?? ''));
        // The template names a language → the law REINFORCES it, no default that
        // could compete with it, and no "same as existing content" that {{current_value}}
        // would otherwise pull the answer back to.
        $named = $template !== '' ? self::template_language($template) : '';
        if ($named !== '') {
            return "\n\nLANGUAGE (mandatory): the instructions above name the target language — {$named}."
                . " Write your ENTIRE answer in {$named}, even if the existing content, page title,"
                . " keywords or business details are in another language. Translate the meaning; keep"
                . ' proper nouns, brand names and URLs exactly as they are. Do not answer in any other language.';
        }
        return "\n\nLANGUAGE — default: write your ENTIRE answer in the"
            . ' SAME language as the existing content you were given'
            . ($hint !== '' ? " (the site's language is {$hint})" : '')
            . '. These instructions are written in English — that is NOT the target language and'
            . ' must never change the language you answer in. Do NOT translate, localise, or switch'
            . ' language on your own initiative. Keep proper nouns, brand names and URLs exactly as'
            . ' they are.'
            . "\nOVERRIDE: if the instructions above EXPLICITLY name a language to write in, obey"
            . ' that instruction instead of this default.';
    }

    /**
     * Substitute {{key}} placeholders. Keys are matched literally (the map
     * carries the exact key strings, incl. dotted/piped ones), mirroring the
     * source's replacePromptVariables.
     *
     * @param string               $template Prompt template.
     * @param array<string, string> $vars    key => value.
     * @return string
     */
    public static function substitute_vars(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }
        return $template;
    }

    /**
     * Trim AI output and strip a single matching pair of surrounding quotes
     * (faithful to opt_simple_sanitize_ai_output).
     *
     * @param string $content Raw model output.
     * @return string
     */
    /**
     * Does this output look like a STRUCTURED envelope rather than a value?
     *
     * The section-revise flow asks for {"html":"…","changes":[…]} — a whole
     * different contract from these single-value fields. If such a prompt ever
     * ends up behind a scalar column (most easily by pointing a template's TYPE
     * at "Meta Title" while its VALUE still asks for the envelope), the model
     * obeys it and returns one long JSON line. sanitize_ai_output()'s
     * first-plausible-line pass sees exactly one line and hands the raw JSON
     * through, so `{"html":"<section>…` lands in the Meta Title cell and is one
     * "Accept all & save" away from the live site.
     *
     * Detected on SHAPE, not on the key names alone: any JSON object/array is
     * wrong for a field that must hold one short string.
     */
    public static function is_structured_envelope(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '' || ($raw[0] !== '{' && $raw[0] !== '[')) {
            return false;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded);
    }

    /**
     * Invoke the model for a SCALAR SEO field and sanitize the answer — with
     * SELF-HEALING when a customized prompt yields the section-revise envelope.
     *
     * Filip (2026-08-15): "It now works to generate for bulk, but individual
     * lines do not work. It complains about HTML missing." Bulk fills EMPTY
     * cells (generate mode → shipped prompt); a single filled cell resolves
     * OPTIMIZE mode, and on that install the customized optimize template asks
     * for the {"html":…,"changes":…} envelope. The old guard refused with a
     * dead-end error; now, when the prompt in play is CUSTOMIZED (an override
     * or a picked template), we retry ONCE with the shipped default for the
     * same section — generation succeeds instead of dead-ending. The envelope
     * error remains for the case the default itself envelopes (nothing left to
     * heal with), so garbage still never lands in a cell.
     *
     * @param string $prompt      Fully substituted prompt (customized or default).
     * @param string $default_tpl The SHIPPED default template for this section+mode.
     * @param array  $vars        Substitution vars (for the retry).
     * @param string $field       Cell field ('slug' gets slugified).
     * @param array  $opts        PCM_LLM options (max_tokens/model/provider).
     * @param bool   $customized  True when $prompt came from an override/template.
     * @return array{field:string,value:string}|\WP_Error
     */
    public static function invoke_scalar_field(string $prompt, string $default_tpl, array $vars, string $field, array $opts, bool $customized)
    {
        try {
            $result = PCM_LLM::invoke(array(array('role' => 'user', 'content' => $prompt)), $opts);
            $raw    = (string) ($result['content'] ?? '');
            $value  = self::sanitize_ai_output($raw);
            if ($field === 'slug') {
                $value = sanitize_title($value);
            }
            $healed = false;
            // A customized prompt that yields an ENVELOPE or a REFUSAL ("I need the existing
            // content…") is the same disease — the model was handed a prompt it cannot
            // answer for a scalar cell. Retry once with the shipped default.
            $unanswerable = self::is_structured_envelope($raw) || self::is_refusal($raw);
            if ($value === '' && $unanswerable && $customized) {
                // Retry with the shipped default — same section, same vars.
                $retry  = self::substitute_vars($default_tpl . self::language_law($vars, $default_tpl), $vars);
                $result = PCM_LLM::invoke(array(array('role' => 'user', 'content' => $retry)), $opts);
                $raw    = (string) ($result['content'] ?? '');
                $value  = self::sanitize_ai_output($raw);
                if ($field === 'slug') {
                    $value = sanitize_title($value);
                }
                $healed = $value !== '';
            }
            if ($value === '') {
                if (self::is_structured_envelope($raw)) {
                    return new WP_Error('pcm_seo_envelope', __('The prompt behind this field returns a JSON envelope ({"html":…,"changes":…}) instead of a single value — check the template selected for this column in Templates → SEO.', 'power-creatives'), array('status' => 422));
                }
                if (self::is_refusal($raw)) {
                    // Never stage an apology as a title. Say what happened and why.
                    return new WP_Error('pcm_seo_refused', sprintf(
                        /* translators: %s: the model's opening words */
                        __('The model declined instead of answering ("%s"). Usually the template for this column asks for content it never receives (e.g. a "revise this section" prompt behind a single-value field) — check it in Templates → SEO, or try another model.', 'power-creatives'),
                        mb_substr(trim(preg_replace('/\s+/u', ' ', $raw)), 0, 80)
                    ), array('status' => 422));
                }
                return new WP_Error('pcm_seo_empty', __('The model returned no text — try again.', 'power-creatives'), array('status' => 502));
            }
            // `healed` tells the UI the SELECTED template could not produce a value and the
            // shipped default was used instead — so the misconfigured template gets fixed
            // rather than silently costing a second model call on every generate.
            return array('field' => $field, 'value' => $value, 'healed' => $healed);
        } catch (\Throwable $e) {
            return new WP_Error('pcm_seo_generate_failed', $e->getMessage(), array('status' => 502));
        }
    }

    /**
     * Is this output the model DECLINING rather than answering?
     *
     * The card's screenshot: Meta Title cells staged with "I'm sorry, I can't
     * assist with that request." and "I need the existing content to provide a
     * revised output according to the specified format. Please provide the
     * section…" — the model refused (or asked for input it never got), and since a
     * refusal is a plain string, the first-plausible-line sanitizer handed it
     * through as a perfectly shaped title. One "Accept all" from the live site.
     *
     * Detected on the OPENING of the text: apology/refusal openers and
     * "I need … to provide/proceed" requests, in English and Swedish (the client
     * sites are Swedish and the language law can make the model answer in
     * Swedish). Anchored at the start so a legitimate title that merely contains
     * "sorry" or "need" (e.g. "Why We Need Sleep") is never rejected.
     */
    public static function is_refusal(string $raw): bool
    {
        $t = trim($raw);
        if ($t === '') {
            return false;
        }
        // Strip a leading markdown marker/bold, then look at the first ~160 chars.
        $t = trim((string) preg_replace('/^\s*(?:#{1,6}|>|[-*+]|\d+\.)\s+/', '', $t));
        $t = trim(str_replace(array('**', '__'), '', $t));
        $head = mb_substr($t, 0, 160);
        $patterns = array(
            // English refusals / apologies / can't-comply
            '/^(?:i\'?m\s+sorry|i\s+am\s+sorry|sorry,|unfortunately,?\s+i|i\s+can(?:no|\')t\s+(?:assist|help|comply|provide|do\s+that)|i\s+(?:am\s+)?(?:unable|not\s+able)\s+to|i\s+won\'?t\s+be\s+able|as\s+an\s+ai\b|i\s+apologi[sz]e)/iu',
            // English requests for missing input ("I need the existing content…")
            '/^(?:i\s+need\s+(?:the|more|additional|some|you\s+to)|(?:please|could\s+you)\s+(?:provide|share|paste|send)|to\s+(?:proceed|help|assist),?\s+i\s+need|it\s+(?:seems|looks)\s+(?:like\s+)?(?:the|no|there\s+is\s+no)\s+(?:content|text|section)|there\s+is\s+no\s+(?:content|text|section)\s+(?:provided|to))/iu',
            // Swedish equivalents
            '/^(?:tyvärr|jag\s+kan\s+(?:inte|tyvärr)|jag\s+beklagar|jag\s+behöver\s+(?:mer|det|den|texten|innehållet)|vänligen\s+(?:ange|skicka|dela)|det\s+(?:finns|verkar)\s+(?:inget|inte)\s+(?:innehåll|text))/iu',
        );
        foreach ($patterns as $p) {
            if (preg_match($p, $head)) {
                return true;
            }
        }
        return false;
    }

    public static function sanitize_ai_output(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }
        // A refusal / "please provide the content" reply is not a value. Return ''
        // so the caller raises a real error (invoke_scalar_field maps it to a
        // dedicated code) instead of staging the apology as a title.
        if (self::is_refusal($content)) {
            return '';
        }
        // Strip a wrapping code fence FIRST so a fenced ```json {...}``` envelope
        // is recognised too, then refuse the envelope outright. Returning '' makes
        // the caller raise its "no usable text" error instead of storing the blob —
        // failing loudly beats silently mangling JSON into a plausible-looking
        // title that hides the misconfigured template.
        $unfenced = str_starts_with($content, '```')
            ? trim((string) preg_replace('/^```[a-zA-Z0-9]*\s*|\s*```$/', '', $content))
            : $content;
        if (self::is_structured_envelope($unfenced)) {
            return '';
        }
        // Strip a wrapping code fence (```lang ... ```), if any.
        if (str_starts_with($content, '```')) {
            $content = trim((string) preg_replace('/^```[a-zA-Z0-9]*\s*|\s*```$/', '', $content));
        }
        // These are single-value fields. A chatty model (e.g. Claude Haiku) may wrap the
        // value in a markdown heading and follow it with commentary (character counts,
        // checklists) or lead with a "Here is…:" preamble. Pick the first line that looks
        // like the actual value: strip leading markdown markers + bold, skip empty/marker
        // lines and label/preamble lines (those ending in a colon).
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: array($content);
        $value = $content;
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $line = trim((string) preg_replace('/^\s*(#{1,6}|>|[-*+]|\d+\.)\s+/', '', $line));
            $line = trim(str_replace(array('**', '__'), '', $line));
            if ($line === '' || preg_match('/:\s*$/', $line)) {
                continue; // pure marker line, or a label/preamble line ("Meta title:")
            }
            $value = $line;
            break;
        }
        // Strip a leading "Label:" / "Label -" prefix on the value itself.
        $value = trim((string) preg_replace('/^(meta\s+title|title|meta\s+description|description|slug|keywords?|primary\s+keyword)\s*[:\-\x{2013}]\s+/iu', '', trim($value)));
        // Strip surrounding matched quotes.
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last  = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = trim(substr($value, 1, $len - 2));
            }
        }
        return trim($value);
    }

    /**
     * Build the substitution variable map for a post (+ optional brand for
     * business context; falls back to site info).
     *
     * @param int      $post_id  Post id.
     * @param int|null $brand_id Optional PC brand for {{business.*}}.
     * @return array<string, string>
     */
    public static function build_field_vars(int $post_id, ?int $brand_id = null): array
    {
        $post = get_post($post_id);

        // POST-specific tokens here; the site + business half comes from the shared
        // vocabulary (PCM_Content_Vars) that Writer templates now compose too — the
        // card's "centralized place… so that we always get the right variables".
        // '' pins {{site.lang}} to the hub locale, which is what this local path has
        // always used; the map is otherwise byte-identical to its previous inline form.
        return array(
            'title'                     => $post ? $post->post_title : '',
            'primary_keyword'           => PCM_SEO_Local::seo_get($post_id, 'keyword'),
            'supporting_keyword'        => (string) get_post_meta($post_id, 'pcm_seo_supporting_keyword', true),
            'meta_keywords'             => PCM_SEO_Local::seo_get($post_id, 'meta_keywords'),
            'meta_title'                => PCM_SEO_Local::seo_get($post_id, 'title'),
            'meta_description'          => PCM_SEO_Local::seo_get($post_id, 'description'),
            'post_type'                 => $post ? $post->post_type : '',
        ) + PCM_Content_Vars::site_business($brand_id, '');
    }

    /**
     * Generate (or optimize) an SEO field value with AI. Returns the suggested
     * value WITHOUT saving — the caller stages it for accept/reject. Reuses
     * PC's PCM_LLM provider routing.
     *
     * @param int      $post_id  Post id.
     * @param string   $field    Cell field (must be in field_use_map()).
     * @param int|null $brand_id Optional brand for business context.
     * @param string|null $model Optional model override.
     * @param int|null $user_id  PCM user (for prompt overrides + provider key).
     * @param string|null $provider Optional provider override (paired with $model).
     * @return array|WP_Error { field, value }.
     */
    public static function generate_field(int $post_id, string $field, ?int $brand_id = null, ?string $model = null, ?int $user_id = null, ?string $provider = null, ?int $template_id = null)
    {
        $use_map = self::field_use_map();
        if (!isset($use_map[$field])) {
            return new WP_Error('pcm_seo_not_generatable', __('This field cannot be AI-generated.', 'power-creatives'), array('status' => 400));
        }
        $use     = $use_map[$field];
        $prompts = self::field_prompts();
        if (!isset($prompts[$use])) {
            return new WP_Error('pcm_seo_no_prompt', __('No prompt configured for this field.', 'power-creatives'), array('status' => 500));
        }

        $vars    = self::build_field_vars($post_id, $brand_id);
        // Read the current value (for optimize mode) from the right storage key.
        $get_key_map = array(
            'metaTitle'       => 'title',
            'metaDescription' => 'description',
            'metaKeywords'    => 'meta_keywords',
            'primaryKeyword'  => 'keyword',
        );
        $current = in_array($field, array('title', 'slug'), true) ? '' : PCM_SEO_Local::seo_get($post_id, $get_key_map[$field] ?? 'meta_keywords');
        // 'title' and 'slug' are native post fields, not SEO meta — read them directly.
        if ($field === 'title' || $field === 'slug') {
            $p = get_post($post_id);
            $current = $p ? ($field === 'title' ? $p->post_title : $p->post_name) : '';
        }
        $vars['current_value'] = $current;

        // Optimize an existing value when present and an optimize prompt exists…
        $mode    = (!empty($current) && !empty($prompts[$use]['optimize'])) ? 'optimize' : 'generate';
        // …but an EXPLICITLY PICKED template decides the mode: picking
        // "<Field> — Generate" on a filled cell used to resolve the OPTIMIZE
        // section, never match the pick, and silently run the optimize default.
        $mode    = self::apply_template_mode($mode, (int) $user_id, $template_id, $use, (string) $current, $prompts[$use]);
        $default = $prompts[$use][$mode];
        // Honor the user's Settings → Prompts → SEO override (falls back to default).
        $tpl     = self::resolve_prompt($use . '_' . $mode, $default, $user_id, $template_id);
        // The law reads the TEMPLATE: a named language is reinforced, not defaulted away.
        $prompt  = self::substitute_vars($tpl . self::language_law($vars, $tpl), $vars);
        $max     = (int) ($prompts[$use]['max'] ?? 200);

        if (!class_exists('PCM_LLM')) {
            return new WP_Error('pcm_seo_no_llm', __('AI provider is unavailable.', 'power-creatives'), array('status' => 500));
        }

        $opts = array('max_tokens' => $max);
        if (!empty($model)) {
            $opts['model'] = $model;
        }
        // Data-driven provider routing — when the caller picks a model it also
        // sends its provider, so we never fall back to detect_provider().
        if (!empty($provider)) {
            $opts['provider'] = $provider;
        }
        // Shared invoke: sanitizes, slugifies, and SELF-HEALS an envelope answer by
        // retrying once with the shipped default when the prompt was customized.
        $out = self::invoke_scalar_field($prompt, $default, $vars, $field, $opts, $tpl !== $default);
        if (is_array($out) && self::$last_pick_ignored) {
            $out['templateIgnored'] = 'envelope';
        }
        return $out;
    }

    /**
     * Generate a SITE-wide field (robots.txt / LocalBusiness JSON-LD) from its
     * editable prompt (Settings → Templates, module=seo). Brand supplies the
     * business context for the schema; robots only needs the site URL. Returns
     * the generated text WITHOUT saving (the Site tab previews + saves).
     *
     * @param string      $field    'robots' | 'schema'.
     * @param int|null    $brand_id Optional brand for {{business.*}} context.
     * @param string|null $model    Optional model override.
     * @param int|null    $user_id  PCM user (for prompt-template override).
     * @param string|null $provider Optional provider override (paired with $model).
     * @return array|WP_Error { field, value }.
     */
    public static function generate_site_field(string $field, ?int $brand_id = null, ?string $model = null, ?int $user_id = null, ?string $provider = null, array $var_overrides = array())
    {
        $use_map = array(
            'robots'       => 'robots',
            'schema'       => 'site_schema',
            'site_title'   => 'site_title',
            'site_tagline' => 'site_tagline',
        );
        if (!isset($use_map[$field])) {
            return new WP_Error('pcm_seo_not_generatable', __('This site field cannot be generated.', 'power-creatives'), array('status' => 400));
        }
        $use     = $use_map[$field];
        $prompts = self::field_prompts();
        if (empty($prompts[$use]['generate'])) {
            return new WP_Error('pcm_seo_no_prompt', __('No prompt configured for this field.', 'power-creatives'), array('status' => 500));
        }

        // Site-level vars (no post): business.* (from brand), website.url, site.lang.
        // $var_overrides lets a CONNECTED site inject its own url/name so {{website.url}}
        // (robots Sitemap, schema url) resolves to the remote site, not the hub.
        $vars    = array_merge(self::build_field_vars(0, $brand_id), $var_overrides);
        $default = $prompts[$use]['generate'];
        $tpl     = self::resolve_prompt($use . '_generate', $default, $user_id);
        $tpl    .= self::language_law($vars);
        $prompt  = self::substitute_vars($tpl, $vars);
        $max     = (int) ($prompts[$use]['max'] ?? 600);

        if (!class_exists('PCM_LLM')) {
            return new WP_Error('pcm_seo_no_llm', __('AI provider is unavailable.', 'power-creatives'), array('status' => 500));
        }

        try {
            $opts = array('max_tokens' => $max);
            if (!empty($model)) {
                $opts['model'] = $model;
            }
            if (!empty($provider)) {
                $opts['provider'] = $provider;
            }
            $result = PCM_LLM::invoke(array(array('role' => 'user', 'content' => $prompt)), $opts);
            $value = trim((string) ($result['content'] ?? ''));
            if ($field === 'site_title' || $field === 'site_tagline') {
                // Single-line value fields — strip markdown/commentary a chatty model adds.
                $value = self::sanitize_ai_output($value);
            } elseif (strpos($value, '```') === 0) {
                // Multi-line output (robots/schema) — strip only a wrapping ```code fence```
                // (do NOT use sanitize_ai_output, which collapses to a single line).
                $value = trim((string) preg_replace('/^```[a-zA-Z0-9]*\s*|\s*```$/', '', $value));
            }
            if ($value === '') {
                return new WP_Error('pcm_seo_empty', __('The model returned no text — try again.', 'power-creatives'), array('status' => 502));
            }
            return array('field' => $field, 'value' => $value);
        } catch (\Throwable $e) {
            return new WP_Error('pcm_seo_generate_failed', $e->getMessage(), array('status' => 502));
        }
    }

    /**
     * AI-optimize a post's full body (SEO + AEO). Returns the suggested HTML
     * WITHOUT saving (caller previews + accepts). Reuses PCM_LLM.
     *
     * @return array|WP_Error { body }.
     */
    public static function optimize_body(int $post_id, ?int $brand_id = null, ?string $model = null, ?int $user_id = null)
    {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('pcm_seo_no_post', __('Content not found.', 'power-creatives'), array('status' => 404));
        }
        $prompts = self::field_prompts();
        if (empty($prompts['content']['optimize'])) {
            return new WP_Error('pcm_seo_no_prompt', __('No content prompt configured.', 'power-creatives'), array('status' => 500));
        }
        $vars = self::build_field_vars($post_id, $brand_id);
        $vars['current_value'] = $post->post_content;
        // Honor the user's Settings → Prompts → SEO override (falls back to default).
        $tpl    = self::resolve_prompt('content_optimize', $prompts['content']['optimize'], $user_id);
        $tpl   .= self::language_law($vars);
        $prompt = self::substitute_vars($tpl, $vars);

        if (!class_exists('PCM_LLM')) {
            return new WP_Error('pcm_seo_no_llm', __('AI provider is unavailable.', 'power-creatives'), array('status' => 500));
        }
        try {
            $opts = array('max_tokens' => (int) ($prompts['content']['max'] ?? 4096), 'web' => PCM_LLM::web_default());
            if (!empty($model)) {
                $opts['model'] = $model;
            }
            $result = PCM_LLM::invoke(array(array('role' => 'user', 'content' => $prompt)), $opts);
            $body   = trim((string) ($result['content'] ?? ''));
            // Strip accidental code fences.
            $body   = preg_replace('/^```[a-z]*\n?|\n?```$/i', '', $body);
            if ($body === '') {
                return new WP_Error('pcm_seo_empty', __('The model returned no content — try again.', 'power-creatives'), array('status' => 502));
            }
            return array('body' => $body);
        } catch (\Throwable $e) {
            return new WP_Error('pcm_seo_optimize_failed', $e->getMessage(), array('status' => 502));
        }
    }
}
