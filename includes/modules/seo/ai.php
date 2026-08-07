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

    /** Resolve a section's prompt from Templates (module=seo): the chosen template,
     *  else the user's own default, else the SYSTEM default, else any. */
    private static function seo_template_prompt(int $user_id, string $section, ?int $template_id): ?string
    {
        global $wpdb;
        $table = PCM_Schema::table('templates');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT id, userId, formData, isDefault, updatedAt FROM {$table} WHERE (userId = %d OR userId = 0) AND module = 'seo' ORDER BY updatedAt DESC, id DESC", $user_id), ARRAY_A);
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
     * @param array<string, string> $vars Prompt vars; 'site.lang' is the hint.
     * @return string Text to append to the template (never empty).
     */
    public static function language_law(array $vars = array()): string
    {
        $hint = trim((string) ($vars['site.lang'] ?? ''));
        return "\n\nLANGUAGE (absolute, overrides everything above): write your ENTIRE answer in the"
            . ' SAME language as the existing content you were given'
            . ($hint !== '' ? " (the site's language is {$hint})" : '')
            . '. These instructions are written in English — that is NOT the target language and'
            . ' must never change the language you answer in. Do NOT translate, localise, or switch'
            . ' language for any reason. Keep proper nouns, brand names and URLs exactly as they are.';
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
    public static function sanitize_ai_output(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
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
        $home = home_url('/');
        $host = (string) wp_parse_url($home, PHP_URL_HOST);

        $business_name = (string) get_bloginfo('name');
        $business_tag  = (string) get_bloginfo('description');
        // GBP business context for the brand (resolved = snapshot + overrides).
        $gbp = array();
        if ($brand_id && $brand_id > 0) {
            global $wpdb;
            $brands = PCM_Schema::table('brands');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $brand = $wpdb->get_row($wpdb->prepare("SELECT name FROM {$brands} WHERE id = %d", $brand_id));
            if ($brand && !empty($brand->name)) {
                $business_name = (string) $brand->name;
            }
            if (class_exists('PCM_SEO_GBP')) {
                $gbp = PCM_SEO_GBP::get_for_brand($brand_id)['resolved'];
                if (!empty($gbp['name'])) {
                    $business_name = (string) $gbp['name'];
                }
            }
        }

        $locale = get_locale();
        return array(
            'title'                     => $post ? $post->post_title : '',
            'primary_keyword'           => PCM_SEO_Local::seo_get($post_id, 'keyword'),
            'supporting_keyword'        => (string) get_post_meta($post_id, 'pcm_seo_supporting_keyword', true),
            'meta_keywords'             => PCM_SEO_Local::seo_get($post_id, 'meta_keywords'),
            'meta_title'                => PCM_SEO_Local::seo_get($post_id, 'title'),
            'meta_description'          => PCM_SEO_Local::seo_get($post_id, 'description'),
            'post_type'                 => $post ? $post->post_type : '',
            'site.lang'                 => $locale ? substr($locale, 0, 2) : 'en',
            'website.url'               => $home,
            'today'                     => gmdate('Y-m-d'),
            'business.name'             => $business_name,
            'business.tagline'          => $business_tag,
            'business.website'          => !empty($gbp['website']) ? (string) $gbp['website'] : $home,
            'business.website|hostname' => $host,
            'business.address'          => (string) ($gbp['address'] ?? ''),
            'business.phone'            => (string) ($gbp['phone'] ?? ''),
            'business.category'         => (string) ($gbp['category'] ?? ''),
            'business.hours'            => (string) ($gbp['hours'] ?? ''),
            'business.description'      => (string) ($gbp['description'] ?? ''),
            'business.rating'           => isset($gbp['rating']) ? (string) $gbp['rating'] : '',
            'business.lat'              => isset($gbp['lat']) ? (string) $gbp['lat'] : '',
            'business.lng'              => isset($gbp['lng']) ? (string) $gbp['lng'] : '',
            'business.types'            => !empty($gbp['types']) ? implode(', ', (array) $gbp['types']) : '',
        );
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

        // Optimize an existing value when present and an optimize prompt exists.
        $mode    = (!empty($current) && !empty($prompts[$use]['optimize'])) ? 'optimize' : 'generate';
        $default = $prompts[$use][$mode];
        // Honor the user's Settings → Prompts → SEO override (falls back to default).
        $tpl     = self::resolve_prompt($use . '_' . $mode, $default, $user_id, $template_id);
        $tpl    .= self::language_law($vars);
        $prompt  = self::substitute_vars($tpl, $vars);
        $max     = (int) ($prompts[$use]['max'] ?? 200);

        if (!class_exists('PCM_LLM')) {
            return new WP_Error('pcm_seo_no_llm', __('AI provider is unavailable.', 'power-creatives'), array('status' => 500));
        }

        try {
            $opts = array('max_tokens' => $max);
            if (!empty($model)) {
                $opts['model'] = $model;
            }
            // Data-driven provider routing — when the caller picks a model it also
            // sends its provider, so we never fall back to detect_provider().
            if (!empty($provider)) {
                $opts['provider'] = $provider;
            }
            $result = PCM_LLM::invoke(array(array('role' => 'user', 'content' => $prompt)), $opts);
            $value  = self::sanitize_ai_output((string) ($result['content'] ?? ''));
            // The slug field must be a valid URL slug — slugify whatever the model
            // returned (handles chatty output / spaces / casing reliably).
            if ($field === 'slug') {
                $value = sanitize_title($value);
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
            $opts = array('max_tokens' => (int) ($prompts['content']['max'] ?? 4096));
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
