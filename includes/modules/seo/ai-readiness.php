<?php
/**
 * SEO — AI Readiness (faithful port of the source's ai-readiness module).
 *
 * Exposes the site's content to LLMs via virtual routes:
 *   /llms.txt       — llmstxt.org index
 *   /llms-full.txt  — full corpus in one Markdown file
 *   /{slug}.md      — per-page Markdown (Stripe-style)
 *
 * Plus an HTML→Markdown converter (page-builder aware) and the llms.txt
 * builders. Routes only register when published (option pcm_seo_air_published).
 *
 * This file is required by the seo service.php (loaded every request by the
 * module-loader), so its hook registrations run on normal page loads.
 *
 * @package PowerCreatives
 * @since   1.23.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_SEO_AIReadiness
{
    public const OPT_PUBLISHED = 'pcm_seo_air_published';
    public const OPT_LLMS      = 'pcm_seo_air_llms_txt';
    public const OPT_LLMS_FULL = 'pcm_seo_air_llms_full_txt';
    public const OPT_SETTINGS  = 'pcm_seo_air_settings';
    public const OPT_LLMINFO   = 'pcm_seo_llminfo';
    public const META_MD       = '_pcm_md_content';
    public const META_HASH     = '_pcm_md_hash';
    public const META_SUMMARY  = '_pcm_md_summary';

    /** /llm-info/ settings (inputs + generated HTML). */
    public static function llm_info(): array
    {
        $defaults = array('enabled' => false, 'keywords' => '', 'years' => '', 'area' => '', 'strengths' => '', 'content' => '');
        $saved    = get_option(self::OPT_LLMINFO, array());
        return array_merge($defaults, is_array($saved) ? $saved : array());
    }

    /** Wrap /llm-info/ HTML body content in a minimal, crawlable HTML document. */
    public static function wrap_llm_info(string $body): string
    {
        $name = get_bloginfo('name');
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc_html($name) . ' — Overview</title>'
            . '<meta name="robots" content="index,follow"></head>'
            . '<body><main style="max-width:760px;margin:2rem auto;padding:0 1rem;font-family:system-ui,-apple-system,sans-serif;line-height:1.6">'
            . $body . '</main></body></html>';
    }

    /** Settings with defaults. */
    public static function settings(): array
    {
        $defaults = array('post_types' => array('page', 'post'), 'excluded_ids' => array(), 'max_posts' => 50, 'site_description' => '');
        $saved = get_option(self::OPT_SETTINGS, array());
        return array_merge($defaults, is_array($saved) ? $saved : array());
    }

    public static function is_published(): bool
    {
        return (bool) get_option(self::OPT_PUBLISHED, false);
    }

    // ── Rewrite rules ──

    public static function register_rules(): void
    {
        add_rewrite_rule('^llms\.txt$', 'index.php?pcm_air_file=llms', 'top');
        add_rewrite_rule('^llms-full\.txt$', 'index.php?pcm_air_file=llms-full', 'top');
        add_rewrite_rule('^(.+)\.md$', 'index.php?pcm_air_page_md=$matches[1]', 'top');
    }

    /** Re-register + flush (publish). */
    public static function flush(): void
    {
        self::register_rules();
        flush_rewrite_rules(false);
    }

    /** Flush without re-registering (unpublish drops the rules). */
    public static function flush_remove(): void
    {
        flush_rewrite_rules(false);
    }

    /** template_redirect handler: serve the virtual files. */
    public static function maybe_serve(): void
    {
        // /llm-info/ — AI-optimization summary (served verbatim HTML; no rewrite rule, no flush).
        $req = trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        if ($req === 'llm-info') {
            $s = self::llm_info();
            if (!empty($s['enabled']) && (string) $s['content'] !== '') {
                status_header(200);
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: no-cache, must-revalidate');
                echo self::wrap_llm_info((string) $s['content']); // sanitized on save (wp_kses_post)
                exit;
            }
            return; // not enabled / empty — let WordPress 404 normally
        }

        $file = get_query_var('pcm_air_file', '');
        $md   = get_query_var('pcm_air_page_md', '');
        if ($file === '' && $md === '') {
            return;
        }
        if (!self::is_published()) {
            status_header(404);
            exit;
        }

        if ($file === 'llms' || $file === 'llms-full') {
            $content = (string) get_option($file === 'llms' ? self::OPT_LLMS : self::OPT_LLMS_FULL, '');
            if ($content === '') {
                status_header(404);
                echo '# not generated yet';
                exit;
            }
            status_header(200);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');
            echo $content; // plain text, served verbatim
            exit;
        }

        // /{slug}.md
        $slug = sanitize_text_field($md);
        $post = get_page_by_path($slug, OBJECT, array('page', 'post'));
        if (!$post) {
            $posts = get_posts(array('name' => basename($slug), 'post_type' => array('page', 'post'), 'post_status' => 'publish', 'numberposts' => 1));
            $post  = !empty($posts) ? $posts[0] : null;
        }
        if (!$post || $post->post_status !== 'publish') {
            status_header(404);
            echo '# Page not found';
            exit;
        }
        $stored = get_post_meta($post->ID, self::META_MD, true);
        $body   = $stored !== '' ? $stored : self::post_to_markdown($post);
        status_header(200);
        header('Content-Type: text/markdown; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        echo $body;
        exit;
    }

    // ── HTML → Markdown ──

    public static function html_to_markdown(string $html, bool $strip_shortcodes = true): string
    {
        if (trim($html) === '') {
            return '';
        }
        $c = $strip_shortcodes ? strip_shortcodes($html) : do_shortcode($html);
        $c = preg_replace('/<!--\s*\/?wp:[^>]*-->/s', '', $c);

        for ($i = 6; $i >= 1; $i--) {
            $prefix = str_repeat('#', $i);
            $c = preg_replace_callback('/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/si', static function ($m) use ($prefix) {
                return "\n\n" . $prefix . ' ' . trim(strip_tags($m[1])) . "\n\n";
            }, $c);
        }
        $c = preg_replace('/<(strong|b)[^>]*>(.*?)<\/\1>/si', '**$2**', $c);
        $c = preg_replace('/<(em|i)[^>]*>(.*?)<\/\1>/si', '*$2*', $c);
        $c = preg_replace_callback('/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/si', static function ($m) {
            $url = trim($m[1]);
            $text = trim(strip_tags($m[2]));
            return $text === '' ? $url : '[' . $text . '](' . $url . ')';
        }, $c);
        $c = preg_replace_callback('/<img\s[^>]*src=["\']([^"\']*)["\'][^>]*/si', static function ($m) {
            $alt = '';
            if (preg_match('/alt=["\']([^"\']*)["\']/', $m[0], $am)) {
                $alt = $am[1];
            }
            return '![' . $alt . '](' . trim($m[1]) . ')';
        }, $c);
        $c = preg_replace_callback('/<ul[^>]*>(.*?)<\/ul>/si', static function ($m) {
            preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $m[1], $lis);
            $out = '';
            foreach ($lis[1] as $li) {
                $out .= '- ' . trim(strip_tags($li)) . "\n";
            }
            return "\n" . $out . "\n";
        }, $c);
        $c = preg_replace_callback('/<ol[^>]*>(.*?)<\/ol>/si', static function ($m) {
            preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $m[1], $lis);
            $out = '';
            $n = 1;
            foreach ($lis[1] as $li) {
                $out .= $n++ . '. ' . trim(strip_tags($li)) . "\n";
            }
            return "\n" . $out . "\n";
        }, $c);
        $c = preg_replace_callback('/<blockquote[^>]*>(.*?)<\/blockquote>/si', static function ($m) {
            $lines = explode("\n", trim(strip_tags($m[1])));
            return "\n" . implode("\n", array_map(static fn($l) => '> ' . trim($l), $lines)) . "\n";
        }, $c);
        $c = preg_replace_callback('/<pre[^>]*>\s*<code[^>]*>(.*?)<\/code>\s*<\/pre>/si', static function ($m) {
            return "\n```\n" . trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')) . "\n```\n";
        }, $c);
        $c = preg_replace('/<code[^>]*>(.*?)<\/code>/si', '`$1`', $c);
        $c = preg_replace('/<hr[^>]*\/?>/si', "\n---\n", $c);
        $c = preg_replace_callback('/<table[^>]*>(.*?)<\/table>/si', static function ($m) {
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $m[1], $trs);
            $rows = array();
            foreach ($trs[1] as $idx => $tr) {
                preg_match_all('/<(th|td)[^>]*>(.*?)<\/\1>/si', $tr, $tds);
                $cells = array_map(static fn($cell) => trim(strip_tags($cell)), $tds[2]);
                if (!empty($cells)) {
                    $rows[] = '| ' . implode(' | ', $cells) . ' |';
                    if ($idx === 0) {
                        $sep = array_map(static fn($cell) => str_repeat('-', max(3, mb_strlen($cell))), $cells);
                        $rows[] = '| ' . implode(' | ', $sep) . ' |';
                    }
                }
            }
            return "\n" . implode("\n", $rows) . "\n";
        }, $c);
        $c = preg_replace('/<br[^>]*\/?>/si', "\n", $c);
        $c = preg_replace('/<p[^>]*>(.*?)<\/p>/si', "$1\n\n", $c);
        $c = preg_replace('/<(div|section|article|aside|figure|figcaption|main|nav|header|footer|span)[^>]*>/si', '', $c);
        $c = preg_replace('/<\/(div|section|article|aside|figure|figcaption|main|nav|header|footer|span)>/si', '', $c);
        $c = strip_tags($c);
        $c = html_entity_decode($c, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $c = str_replace(array("\r\n", "\r"), "\n", $c);
        $c = preg_replace('/(\n\s*){3,}/', "\n\n", $c);
        $c = implode("\n", array_map('rtrim', explode("\n", $c)));
        return trim($c);
    }

    /** Page-builder-aware rendered content (Elementor / Divi / Brizy / default). */
    public static function rendered_content(WP_Post $post): string
    {
        if (class_exists('\Elementor\Plugin') && get_post_meta($post->ID, '_elementor_edit_mode', true) === 'builder') {
            $html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($post->ID);
            if (trim(strip_tags($html)) !== '') {
                return $html;
            }
        }
        if (get_post_meta($post->ID, '_et_pb_use_builder', true) === 'on') {
            global $post;
            $orig = $post;
            setup_postdata($post);
            $html = apply_filters('the_content', $post->post_content);
            $post = $orig;
            wp_reset_postdata();
            if (trim(strip_tags($html)) !== '') {
                return $html;
            }
        }
        if (class_exists('Brizy_Editor_Post') && get_post_meta($post->ID, 'brizy_post_uid', true)) {
            try {
                $bp = \Brizy_Editor_Post::get($post->ID);
                if ($bp) {
                    $compiled = $bp->getCompiledHtml();
                    if ($compiled && method_exists($compiled, 'getBody')) {
                        $body = $compiled->getBody();
                        if (trim(strip_tags($body)) !== '') {
                            return $body;
                        }
                    }
                }
            } catch (\Exception $e) {
                // fall through
            }
        }
        return $post->post_content;
    }

    public static function post_to_markdown(WP_Post $post): string
    {
        $title = html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body  = self::html_to_markdown(self::rendered_content($post));
        return '# ' . $title . "\n\n" . ($body !== '' ? $body . "\n" : '');
    }

    public static function md_url(int $post_id): string
    {
        return rtrim(get_permalink($post_id), '/') . '.md';
    }

    /** Store a post's markdown + hash + word count. */
    public static function generate_md(int $post_id): string
    {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }
        $md = self::post_to_markdown($post);
        update_post_meta($post_id, self::META_MD, $md);
        update_post_meta($post_id, self::META_HASH, md5($post->post_content));
        update_post_meta($post_id, '_pcm_md_generated', current_time('mysql'));
        return $md;
    }

    /** Per-post readiness: none | stale | ready. */
    public static function status_for(int $post_id): string
    {
        $post = get_post($post_id);
        if (!$post || trim($post->post_content) === '') {
            return 'none';
        }
        $stored = get_post_meta($post_id, self::META_MD, true);
        if ($stored === '') {
            return 'none';
        }
        return get_post_meta($post_id, self::META_HASH, true) !== md5($post->post_content) ? 'stale' : 'ready';
    }

    /** Per-post AI-readiness detail for the status table (word count, summary, generated-at). */
    public static function post_meta_row(int $post_id): array
    {
        $md      = (string) get_post_meta($post_id, self::META_MD, true);
        $summary = (string) get_post_meta($post_id, self::META_SUMMARY, true);
        return array(
            'wordCount'  => $md !== '' ? (int) preg_match_all('/\S+/u', wp_strip_all_tags($md)) : 0,
            'hasSummary' => $summary !== '',
            'summary'    => $summary,
            'generated'  => (string) get_post_meta($post_id, '_pcm_md_generated', true),
        );
    }

    /**
     * AI-generate a concise directory-listing description for a post (stored as META_SUMMARY).
     * Faithful to Optimizer Simple's summarize prompt. Generates the .md first if needed.
     *
     * @return array{summary:string}|\WP_Error
     */
    public static function summarize(int $post_id, ?string $model = null, ?int $user_id = null, ?string $provider = null, ?int $template_id = null)
    {
        if (!class_exists('PCM_LLM')) {
            return new WP_Error('pcm_seo_no_llm', __('AI provider is unavailable.', 'power-creatives'), array('status' => 500));
        }
        $md = (string) get_post_meta($post_id, self::META_MD, true);
        if ($md === '') {
            $md = self::generate_md($post_id);
        }
        if (trim($md) === '') {
            return new WP_Error('pcm_seo_no_content', __('Generate the Markdown first.', 'power-creatives'), array('status' => 400));
        }
        $post   = get_post($post_id);
        $title  = $post ? $post->post_title : '';
        $body   = mb_substr(wp_strip_all_tags($md), 0, 2000);
        // The prompt is a Templates (module=seo) row — type air_page_summary_generate —
        // the user's pick/edit wins, the shipped default is the fallback (card 17).
        // {{title}} / {{corpus}} — the SEO vocabulary's own names (the page's title, its text).
        $prompt = self::resolve_section_prompt('air_page_summary', $user_id, $template_id, array(
            'title'  => $title,
            'corpus' => $body,
        ));
        try {
            $opts = array('max_tokens' => 120);
            if (!empty($model)) {
                $opts['model'] = $model;
            }
            if (!empty($provider)) {
                $opts['provider'] = $provider;
            }
            $res     = PCM_LLM::invoke(array(array('role' => 'user', 'content' => $prompt)), $opts);
            $summary = trim((string) ($res['content'] ?? ''), " \t\n\r\"'");
            if ($summary === '') {
                return new WP_Error('pcm_seo_empty', __('The model returned no text — try again.', 'power-creatives'), array('status' => 502));
            }
            update_post_meta($post_id, self::META_SUMMARY, $summary);
            return array('summary' => $summary);
        } catch (\Throwable $e) {
            return new WP_Error('pcm_seo_generate_failed', $e->getMessage(), array('status' => 502));
        }
    }

    // ── llms.txt builders ──

    private static function llms_string(string $key): string
    {
        $en = array(
            'resources'    => 'Resources',
            'sitemap_desc' => 'Complete sitemap index with all public URLs for this website.',
            'body_text'    => 'Below are Markdown versions of key pages on this site. For the complete content of all pages in a single file, see [%1$s full content](%2$s).',
        );
        $strings = apply_filters('pcm_seo_air_llms_strings', $en, get_locale());
        return $strings[$key] ?? ($en[$key] ?? '');
    }

    public static function site_description(): string
    {
        $s = self::settings();
        if (!empty($s['site_description'])) {
            return trim($s['site_description']);
        }
        $tagline = get_bloginfo('description');
        return $tagline ? trim($tagline) : '';
    }

    private static function format_entry(WP_Post $post): string
    {
        $summary = get_post_meta($post->ID, self::META_SUMMARY, true);
        $desc    = $summary !== '' ? ': ' . $summary : '';
        $title   = html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '- [' . $title . '](' . self::md_url($post->ID) . ')' . $desc . "\n";
    }

    public static function build_llms_index(array $posts, string $site_name, string $site_desc, string $site_url, int $max_posts): string
    {
        $out = "# {$site_name}\n\n";
        if ($site_desc !== '') {
            $out .= "> {$site_desc}\n\n";
        }
        $out .= sprintf(self::llms_string('body_text'), $site_name, $site_url . '/llms-full.txt') . "\n\n";

        $grouped = array();
        foreach ($posts as $p) {
            $grouped[$p->post_type][] = $p;
        }
        if (!empty($grouped['page'])) {
            $obj = get_post_type_object('page');
            $out .= '## ' . ($obj ? $obj->labels->name : 'Pages') . "\n\n";
            foreach ($grouped['page'] as $p) {
                $out .= self::format_entry($p);
            }
            $out .= "\n";
        }
        foreach ($grouped as $type => $list) {
            if (in_array($type, array('page', 'post'), true)) {
                continue;
            }
            $obj = get_post_type_object($type);
            $out .= '## ' . ($obj ? $obj->labels->name : ucfirst($type)) . "\n\n";
            foreach ($list as $p) {
                $out .= self::format_entry($p);
            }
            $out .= "\n";
        }
        if (!empty($grouped['post'])) {
            $out .= "## Optional\n\n";
            $n = 0;
            foreach ($grouped['post'] as $p) {
                if ($n >= $max_posts) {
                    break;
                }
                $out .= self::format_entry($p);
                $n++;
            }
            $out .= "\n";
        }
        $out .= '## ' . self::llms_string('resources') . "\n\n";
        $out .= '- [XML Sitemap](' . home_url('/sitemap.xml') . '): ' . self::llms_string('sitemap_desc') . "\n\n";
        return $out;
    }

    public static function build_llms_full(array $posts, string $site_name, string $site_desc): string
    {
        $out = "# {$site_name}\n\n";
        if ($site_desc !== '') {
            $out .= "> {$site_desc}\n\n";
        }
        foreach ($posts as $p) {
            $md = get_post_meta($p->ID, self::META_MD, true);
            if ($md === '') {
                $md = self::post_to_markdown($p);
            }
            $out .= "---\n\nSource: " . get_permalink($p->ID) . "\n\n";
            $out .= trim($md) . "\n\n";
        }
        return $out;
    }

    /** Fetch the published posts for the configured types. */
    public static function query_posts(bool $respect_exclusions = true): array
    {
        $s    = self::settings();
        $args = array(
            'post_type'      => $s['post_types'],
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        );
        if ($respect_exclusions) {
            $args['post__not_in'] = array_map('intval', $s['excluded_ids']);
        }
        $q = new WP_Query($args);
        return $q->posts;
    }

    /** Build + persist both llms files. Reflush if published. */
    public static function build_index(): array
    {
        $posts    = self::query_posts();
        $name     = get_bloginfo('name');
        $desc     = self::site_description();
        $url      = home_url('');
        $max      = (int) self::settings()['max_posts'];
        $index    = self::build_llms_index($posts, $name, $desc, $url, $max);
        $full     = self::build_llms_full($posts, $name, $desc);
        update_option(self::OPT_LLMS, $index, false);
        update_option(self::OPT_LLMS_FULL, $full, false);
        if (self::is_published()) {
            self::flush();
        }
        return array('llmsBytes' => strlen($index), 'fullBytes' => strlen($full), 'posts' => count($posts));
    }

    /** Persist a hand-edited llms.txt (reflushed if published). */
    public static function save_llms(string $content): void
    {
        update_option(self::OPT_LLMS, $content, false);
        if (self::is_published()) {
            self::flush();
        }
    }

    /** Reset everything: clear both llms files, unpublish, drop per-post meta + site description. */
    public static function delete_all(): void
    {
        delete_option(self::OPT_LLMS);
        delete_option(self::OPT_LLMS_FULL);
        update_option(self::OPT_PUBLISHED, false);
        self::flush_remove();
        foreach (self::query_posts() as $p) {
            delete_post_meta((int) $p->ID, self::META_MD);
            delete_post_meta((int) $p->ID, self::META_HASH);
            delete_post_meta((int) $p->ID, self::META_SUMMARY);
            delete_post_meta((int) $p->ID, '_pcm_md_generated');
        }
        $s = self::settings();
        $s['site_description'] = '';
        update_option(self::OPT_SETTINGS, $s, false);
    }

    /**
     * AI-generate a 1–2 sentence site description from the top published pages.
     *
     * @return array{description:string}|\WP_Error
     */
    public static function gen_site_description(?string $model = null, ?int $user_id = null, ?string $provider = null, ?int $template_id = null)
    {
        if (!class_exists('PCM_LLM')) {
            return new WP_Error('pcm_seo_no_llm', __('AI provider is unavailable.', 'power-creatives'), array('status' => 500));
        }
        $pages  = get_posts(array('post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 10, 'orderby' => 'menu_order', 'order' => 'ASC'));
        $titles = array();
        foreach ($pages as $pg) {
            $titles[] = '- ' . html_entity_decode($pg->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $name   = get_bloginfo('name');
        // Same template the connected-site generator uses (site_ai_description_generate)
        // — one prompt, both scopes, user-editable under Templates → SEO (card 17).
        $prompt = self::resolve_section_prompt('site_ai_description', $user_id, $template_id, array(
            'site_name' => $name,      // the shipped prompt's name (seeded rows carry it)
            'site.name' => $name,      // the shared vocabulary's name
            'key_pages' => implode("\n", $titles),
        ), (string) get_bloginfo('language'));
        try {
            $opts = array('max_tokens' => 120);
            if (!empty($model)) {
                $opts['model'] = $model;
            }
            if (!empty($provider)) {
                $opts['provider'] = $provider;
            }
            $res  = PCM_LLM::invoke(array(array('role' => 'user', 'content' => $prompt)), $opts);
            $desc = trim((string) ($res['content'] ?? ''), " \t\n\r\"'");
            if ($desc === '') {
                return new WP_Error('pcm_seo_empty', __('The model returned no text — try again.', 'power-creatives'), array('status' => 502));
            }
            return array('description' => $desc);
        } catch (\Throwable $e) {
            return new WP_Error('pcm_seo_generate_failed', $e->getMessage(), array('status' => 502));
        }
    }

    /**
     * Resolve one of the AI-Readiness prompts through the SEO Templates layer —
     * the user's picked/edited template (Templates → SEO → "AI Readiness — …")
     * or the shipped default — then fill its {{vars}} and append the language law.
     *
     * @param string      $use         prompts.php key (air_page_summary | site_ai_description).
     * @param int|null    $user_id     PCM user id (null → shipped default).
     * @param int|null    $template_id Explicit template pick (null → the section default).
     * @param array       $vars        {{key}} → value.
     * @param string      $site_lang   Language hint for the language law ('' = none).
     */
    public static function resolve_section_prompt(string $use, ?int $user_id, ?int $template_id, array $vars, string $site_lang = ''): string
    {
        $default = class_exists('PCM_SEO_AI') ? (string) (PCM_SEO_AI::field_prompts()[$use]['generate'] ?? '') : '';
        $tpl     = class_exists('PCM_SEO_AI') ? PCM_SEO_AI::resolve_prompt($use . '_generate', $default, $user_id, $template_id) : $default;
        $prompt  = class_exists('PCM_SEO_AI') ? PCM_SEO_AI::substitute_vars($tpl, $vars) : $tpl;
        if (class_exists('PCM_SEO_AI')) {
            $prompt .= PCM_SEO_AI::language_law(array('site.lang' => $site_lang), $tpl);
        }
        return $prompt;
    }

    /**
     * THE LIVE CHECK (owner card 17: "it does not work on the remote site … the pages
     * return 404 … Check why it is not creating any files"). Fetches each AI-Readiness
     * file from its PUBLIC URL exactly as a crawler would and reports what came back,
     * with a plain-language reason. Pure over the fetcher so it is testable; used for
     * the local site (home_url) and connected sites (site url) alike.
     *
     * @param string        $base   Site origin, e.g. https://example.com
     * @param array         $extra  Optional: ['md' => one page's .md URL to sample]
     * @param callable|null $fetch  fn(string $url): array{status:int, body:string, headers:array}
     * @return array<int, array{key:string,label:string,url:string,status:int,ok:bool,state:string,note:string}>
     */
    public static function verify_public_files(string $base, array $extra = array(), ?callable $fetch = null): array
    {
        $base  = rtrim($base, '/');
        $fetch = $fetch ?: static function (string $url): array {
            $res = wp_remote_get($url, array(
                'timeout'     => 15,
                'redirection' => 3,
                'sslverify'   => false,
                // A crawler-ish UA, NOT the WP default — some hosts special-case WordPress/x.y.
                'user-agent'  => 'Mozilla/5.0 (compatible; PowerCreatives-AIReadinessCheck/1.0; +https://powercreatives.com)',
            ));
            if (is_wp_error($res)) {
                return array('status' => 0, 'body' => $res->get_error_message(), 'headers' => array());
            }
            $h = array();
            foreach ((array) wp_remote_retrieve_headers($res) as $k => $v) {
                $h[strtolower((string) $k)] = is_array($v) ? implode(', ', $v) : (string) $v;
            }
            return array('status' => (int) wp_remote_retrieve_response_code($res), 'body' => (string) wp_remote_retrieve_body($res), 'headers' => $h);
        };
        $files = array(
            array('key' => 'robots',  'label' => 'robots.txt',          'url' => $base . '/robots.txt'),
            array('key' => 'llms',    'label' => 'llms.txt',            'url' => $base . '/llms.txt'),
            array('key' => 'llminfo', 'label' => '/llm-info/',          'url' => $base . '/llm-info/'),
        );
        if (!empty($extra['md'])) {
            $files[] = array('key' => 'md', 'label' => 'page .md', 'url' => (string) $extra['md']);
        }
        $out = array();
        foreach ($files as $f) {
            $r      = $fetch($f['url']);
            $status = (int) ($r['status'] ?? 0);
            $body   = (string) ($r['body'] ?? '');
            $ctype  = strtolower((string) (($r['headers'] ?? array())['content-type'] ?? ''));
            $server = strtolower((string) (($r['headers'] ?? array())['server'] ?? ''));
            $state  = 'missing';
            $note   = '';
            $ok     = false;
            $challenge = $status === 403 && (stripos($body, 'Just a moment') !== false || stripos($body, 'cf-chl') !== false || stripos($body, 'challenge') !== false || $server === 'cloudflare');
            if ($status === 0) {
                $state = 'unreachable';
                $note  = 'Could not reach the site: ' . ($body !== '' ? $body : 'no response') . '.';
            } elseif ($challenge) {
                $state = 'blocked';
                $note  = 'The site’s bot protection (Cloudflare challenge) blocks this URL for non-browser visitors — AI crawlers get the same wall. Allow-list /llms.txt, /llm-info/ and *.md in the firewall.';
            } elseif ($status === 403) {
                $state = 'blocked';
                $note  = 'The server refuses this URL (403) — a firewall or security plugin is blocking it.';
            } elseif ($status === 404) {
                $state = 'missing';
                $note  = $f['key'] === 'robots'
                    ? 'No robots.txt is served.'
                    : ($f['key'] === 'md'
                        ? 'Not served yet — turn on “Serve llms.txt + page .md” and Save; if it stays 404 the connector on this site is outdated.'
                        : 'Not served yet — generate it and turn on “Publish”; if it stays 404 after publishing, the connector on this site is outdated.');
            } elseif ($status >= 500) {
                $state = 'error';
                $note  = "The site returned a server error ({$status}) for this URL.";
            } elseif ($status >= 200 && $status < 300) {
                if ($f['key'] === 'robots') {
                    $blocked = self::robots_blocks_ai_crawlers($body);
                    $ok      = $blocked === array();
                    $state   = $ok ? 'live' : 'warn';
                    $note    = $ok ? 'AI crawlers are allowed.' : ('robots.txt blocks AI crawlers: ' . implode(', ', $blocked) . ' — remove those Disallow rules so AI search can read the site.');
                } elseif ($f['key'] === 'llminfo') {
                    $is_html = strpos($ctype, 'text/html') !== false;
                    $looks   = $is_html && stripos($body, '<main') !== false && stripos($body, 'Overview</title>') !== false;
                    $ok      = $looks;
                    $state   = $looks ? 'live' : 'warn';
                    $note    = $looks ? 'Live — served as the AI overview page.' : 'A page answers here, but it is not the generated overview (the theme’s own page or a 200-OK “not found” page). Generate + Publish again.';
                } elseif ($f['key'] === 'llms') {
                    $ok    = strpos($ctype, 'text/plain') !== false || str_starts_with(ltrim($body), '#');
                    $state = $ok ? 'live' : 'warn';
                    $note  = $ok ? 'Live — ' . max(0, substr_count($body, "\n- [")) . ' page link(s) in the index.' : 'A page answers here but it is not a plain-text llms.txt (the theme’s 200 “not found” page?).';
                } else {
                    $ok    = strpos($ctype, 'markdown') !== false || str_starts_with(ltrim($body), '#');
                    $state = $ok ? 'live' : 'warn';
                    $note  = $ok ? 'Live — the page is served as Markdown.' : 'A page answers here but it is not Markdown.';
                }
            } else {
                $state = 'warn';
                $note  = "Unexpected response ({$status}).";
            }
            $out[] = array('key' => $f['key'], 'label' => $f['label'], 'url' => $f['url'], 'status' => $status, 'ok' => $ok, 'state' => $state, 'note' => $note);
        }
        return $out;
    }

    /** Which well-known AI crawlers a robots.txt DISALLOWS at root. Pure. */
    public static function robots_blocks_ai_crawlers(string $robots): array
    {
        $bots    = array('GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'anthropic-ai', 'PerplexityBot', 'Google-Extended', 'CCBot', 'Applebot-Extended', 'Bytespider');
        $blocked = array();
        $agents  = array();
        $in_rules = false; // a directive has followed the current User-agent line(s)
        foreach (preg_split('/\r?\n/', $robots) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '') {
                continue;
            }
            if (preg_match('/^user-agent\s*:\s*(.+)$/i', $line, $m)) {
                // Consecutive User-agent lines share one group; a User-agent AFTER rules
                // starts a NEW group (robots.txt grouping), so the agents reset here.
                if ($in_rules) {
                    $agents   = array();
                    $in_rules = false;
                }
                $agents[] = trim($m[1]);
                continue;
            }
            $in_rules = true;
            if (preg_match('/^disallow\s*:\s*(.*)$/i', $line, $m)) {
                $path = trim($m[1]);
                if ($path === '/' ) {
                    foreach ($agents as $a) {
                        foreach ($bots as $b) {
                            if (strcasecmp($a, $b) === 0 && !in_array($b, $blocked, true)) {
                                $blocked[] = $b;
                            }
                        }
                        if ($a === '*') {
                            // A blanket "Disallow: /" for everyone blocks the AI crawlers too —
                            // unless a bot has its own group (handled by its own group above).
                            foreach ($bots as $b) {
                                if (!in_array($b, $blocked, true) && !self::robots_has_own_group($robots, $b)) {
                                    $blocked[] = $b;
                                }
                            }
                        }
                    }
                }
                continue;
            }
            // Any other directive (Allow/Sitemap/Crawl-delay) belongs to the current group.
        }
        return $blocked;
    }

    private static function robots_has_own_group(string $robots, string $bot): bool
    {
        return (bool) preg_match('/^user-agent\s*:\s*' . preg_quote($bot, '/') . '\s*$/im', $robots);
    }
}

// ── Frontend hook registration (runs every request via service.php) ──

add_action('init', static function () {
    if (PCM_SEO_AIReadiness::is_published()) {
        PCM_SEO_AIReadiness::register_rules();
    }
});

add_filter('query_vars', static function ($vars) {
    $vars[] = 'pcm_air_file';
    $vars[] = 'pcm_air_page_md';
    return $vars;
});

add_filter('redirect_canonical', static function ($redirect_url, $requested_url) {
    if (preg_match('/\.(md|txt)$/i', (string) wp_parse_url($requested_url, PHP_URL_PATH))) {
        return false;
    }
    return $redirect_url;
}, 10, 2);

add_action('template_redirect', array('PCM_SEO_AIReadiness', 'maybe_serve'));
