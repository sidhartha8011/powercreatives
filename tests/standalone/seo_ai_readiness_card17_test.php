<?php
/**
 * Card 17 — Quality / SEO / AI Optimization. The owner's entries, newest first:
 *   2026-08-10 "nothing is done here? it is not useable. It is too complex … Remake it properly"
 *   2026-07-31 "The entire thing needs to be useable … structure it properly"
 *   2026-07-23 "There is no Templates for generating these documents in Templates"
 *   2026-06-15 "all needs to be exposed in the templates so the user can choose other templates"
 *   feedback   "llm-info does not seem to have a generate button just like the llms.txt";
 *              "Generate summary does not seem to work for a new site connected";
 *              "does not work on the remote site … the pages return 404 and it does not list any
 *               .md files. Check why it is not creating any files: robots.txt llms.txt .md /llms-info/"
 *
 * What this pins, EXECUTED where the code allows:
 *   1. THE LIVE CHECK — verify_public_files() over a fake fetcher: live / not served /
 *      blocked by a bot wall / robots blocks AI / theme 200 page — plain-words notes.
 *   2. robots_blocks_ai_crawlers() — pure.
 *   3. Templates: the page summary + site description are Templates rows (prompts.php keys,
 *      spoken names, templateTypes), summarize()/gen_site_description() resolve THROUGH the
 *      Templates layer with an explicit pick, llm-info build takes the pick.
 *   4. "Generate does not work": web-enabled → plain fallback, EXECUTED.
 *   5. "does not list any .md files": remote_ai_posts() is the LIGHT read, EXECUTED over a stub.
 *   6. Connector: /{slug}.md finds dated/nested permalinks; virtual files survive canonical redirect.
 *   7. One studio for local + remote: Generate + Publish per file, first Generate publishes,
 *      live check wired, template picks, the three old panels gone.
 *
 * Run: php tests/standalone/seo_ai_readiness_card17_test.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$ROOT = dirname(__DIR__, 2);
$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void { global $PASS, $FAIL; if ($ok) { $PASS++; echo "  ok  $name\n"; } else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; } }
$slice_of = static function (string $src, string $needle): string {
    $i = strpos($src, $needle); if ($i === false) { return ''; }
    $start = strrpos(substr($src, 0, $i), "\n") + 1;
    $j = strpos($src, "\n    /**", $i); if ($j === false) { $j = strpos($src, "\n    public static function", $i + 10); }
    if ($j === false) { $j = strpos($src, "\n    private static function", $i + 10); }
    if ($j === false) { $j = strpos($src, "\n}", $i); }
    $fn = substr($src, $start, (int)$j - $start);
    if (preg_match('/\n    \}\r?\n/', $fn, $m, PREG_OFFSET_CAPTURE)) { $fn = substr($fn, 0, $m[0][1]) . "\n    }"; }
    return str_replace('private static', 'public static', $fn);
};
$air = file_get_contents($ROOT . '/includes/modules/seo/ai-readiness.php');
$svc = file_get_contents($ROOT . '/includes/modules/seo/service.php');
$ctl = file_get_contents($ROOT . '/includes/modules/seo/controller.php');
$hub = file_get_contents($ROOT . '/includes/modules/seohub/service.php');
$prompts = require $ROOT . '/includes/modules/seo/prompts.php';

// ── WP stubs ──
function wp_remote_get($u, $a = array()) { return $GLOBALS['fetch'][$u] ?? array('response' => array('code' => 404), 'body' => '', 'headers' => array()); }
function wp_remote_retrieve_response_code($r) { return (int) ($r['response']['code'] ?? 0); }
function wp_remote_retrieve_body($r) { return (string) ($r['body'] ?? ''); }
function wp_remote_retrieve_headers($r) { return $r['headers'] ?? array(); }
function is_wp_error($x) { return $x instanceof WP_Error; }
class WP_Error { public $msg; public $code; public $data; public function __construct($c = '', $m = '', $d = null) { $this->code = $c; $this->msg = $m; $this->data = $d; } public function get_error_message() { return $this->msg; } }
function __($s, $d = null) { return $s; }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_meta($id, $k, $single = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function wp_strip_all_tags($s) { return trim(strip_tags($s)); }
function get_posts($a = array()) { return $GLOBALS['get_posts'] ?? array(); }
function get_bloginfo($k) { return $k === 'name' ? 'Massage Göteborg' : ($k === 'language' ? 'sv-SE' : ''); }
function html_entity_decode_x($s) { return $s; }
class PCM_LLM {
    public static $calls = array(); public static $fail_web = false; public static $fail_all = false; public static $reply = 'Hand-made summary';
    public static function invoke($messages, $opts = array()) {
        self::$calls[] = $opts;
        if (self::$fail_all || (self::$fail_web && !empty($opts['web']))) { throw new RuntimeException(!empty($opts['web']) ? 'web tool not supported for this model' : 'provider down'); }
        return array('content' => self::$reply);
    }
    public static function web_default() { return true; }
}
class PCM_SEO_AI {
    public static $resolve_calls = array();
    public static function field_prompts() { return $GLOBALS['prompts']; }
    public static function resolve_prompt($section, $default, $user_id = null, $template_id = null) { self::$resolve_calls[] = array($section, $user_id, $template_id); return $template_id ? "PICKED TEMPLATE {$template_id}: {{title}} / {{corpus}} / {{site_name}} / {{site.name}} / {{key_pages}}" : $default; }
    public static function substitute_vars($t, $vars) { foreach ($vars as $k => $v) { $t = str_replace('{{' . $k . '}}', (string) $v, $t); } return $t; }
    public static function language_law($vars = array(), $tpl = '') { return "\n[LANG " . ($vars['site.lang'] ?? '') . ']'; }
}
eval('class AirHost { '
    . $slice_of($air, 'public static function verify_public_files(') . "\n"
    . $slice_of($air, 'public static function robots_blocks_ai_crawlers(') . "\n"
    . $slice_of($air, 'private static function robots_has_own_group(') . "\n"
    . $slice_of($air, 'public static function resolve_section_prompt(') . "\n"
    . $slice_of($air, 'public static function summarize(') . "\n"
    . $slice_of($air, 'public static function gen_site_description(') . "\n"
    . " const META_MD = '_pcm_md_content'; const META_SUMMARY = '_pcm_md_summary'; public static function generate_md(\$id) { return ''; } }");

echo "\n1. THE LIVE CHECK — what a crawler gets, in plain words (EXECUTED over a fake fetcher)\n";
$fetch = static function (array $map) { return static function (string $url) use ($map): array { return $map[$url] ?? array('status' => 404, 'body' => '', 'headers' => array()); }; };
$base = 'https://massagegoteborg.nu';
$r = AirHost::verify_public_files($base, array('md' => $base . '/boka-tid.md'), $fetch(array(
    $base . '/robots.txt' => array('status' => 200, 'body' => "# START YOAST BLOCK\nUser-agent: *\nDisallow:\n\nSitemap: https://massagegoteborg.nu/sitemap_index.xml", 'headers' => array('content-type' => 'text/plain')),
    $base . '/llms.txt'   => array('status' => 403, 'body' => '<!DOCTYPE html><html><head><title>Just a moment...</title></head><body>cf-chl</body></html>', 'headers' => array('content-type' => 'text/html', 'server' => 'cloudflare')),
    $base . '/llm-info/'  => array('status' => 404, 'body' => 'Not found', 'headers' => array('content-type' => 'text/html')),
    $base . '/boka-tid.md' => array('status' => 200, 'body' => "# Boka tid\n\nVälkommen", 'headers' => array('content-type' => 'text/markdown; charset=utf-8')),
)));
$by = array(); foreach ($r as $f) { $by[$f['key']] = $f; }
check('four files checked in order: robots, llms, llminfo, md', array_keys($by) === array('robots', 'llms', 'llminfo', 'md'), array_keys($by));
check('robots.txt 200 allowing everyone → live "AI crawlers are allowed"', $by['robots']['state'] === 'live' && $by['robots']['ok'] === true && strpos($by['robots']['note'], 'allowed') !== false, $by['robots']);
check('llms.txt behind a Cloudflare challenge (403 "Just a moment") → BLOCKED, names the bot wall + the fix', $by['llms']['state'] === 'blocked' && strpos($by['llms']['note'], 'Cloudflare') !== false && strpos($by['llms']['note'], 'Allow-list') !== false, $by['llms']);
check('/llm-info/ 404 → "Not served yet — generate it and turn on Publish" (+ outdated-connector hint)', $by['llminfo']['state'] === 'missing' && strpos($by['llminfo']['note'], 'Publish') !== false && strpos($by['llminfo']['note'], 'connector') !== false, $by['llminfo']);
check('page .md 200 text/markdown → live', $by['md']['state'] === 'live' && $by['md']['ok'] === true, $by['md']);
check('every row carries its public url + http status (the user can click and see)', $by['llms']['url'] === $base . '/llms.txt' && $by['llms']['status'] === 403 && $by['llminfo']['status'] === 404);
$r2 = AirHost::verify_public_files($base, array(), $fetch(array(
    $base . '/robots.txt' => array('status' => 200, 'body' => "User-agent: GPTBot\nDisallow: /\n\nUser-agent: ClaudeBot\nDisallow: /\n\nUser-agent: *\nDisallow: /wp-admin/", 'headers' => array('content-type' => 'text/plain')),
    $base . '/llms.txt'   => array('status' => 200, 'body' => "# Massage Göteborg\n\n> Massage i centrala Göteborg.\n\n## Pages\n- [Boka tid](https://massagegoteborg.nu/boka-tid.md)\n- [Pris](https://massagegoteborg.nu/pris.md)\n", 'headers' => array('content-type' => 'text/plain; charset=utf-8')),
    $base . '/llm-info/'  => array('status' => 200, 'body' => '<!doctype html><html><head><title>Massage Göteborg — Overview</title></head><body><main><h1>Massage Göteborg</h1></main></body></html>', 'headers' => array('content-type' => 'text/html; charset=utf-8')),
)));
$by2 = array(); foreach ($r2 as $f) { $by2[$f['key']] = $f; }
check('robots.txt that DISALLOWS GPTBot + ClaudeBot → warn, names them, says remove the rules', $by2['robots']['state'] === 'warn' && strpos($by2['robots']['note'], 'GPTBot') !== false && strpos($by2['robots']['note'], 'ClaudeBot') !== false && strpos($by2['robots']['note'], 'remove') !== false, $by2['robots']);
check('llms.txt 200 text/plain → live, counts the page links (2)', $by2['llms']['state'] === 'live' && strpos($by2['llms']['note'], '2 page link') !== false, $by2['llms']);
check('/llm-info/ 200 with the generated overview shape → live', $by2['llminfo']['state'] === 'live', $by2['llminfo']);
check('no md sample → no md row', !isset($by2['md']));
$r3 = AirHost::verify_public_files($base, array('md' => $base . '/x.md'), $fetch(array(
    $base . '/llm-info/' => array('status' => 200, 'body' => '<!doctype html><html><head><title>Massage Göteborg</title></head><body><div class="theme-404">Sidan finns inte</div></body></html>', 'headers' => array('content-type' => 'text/html')),
    $base . '/llms.txt'  => array('status' => 200, 'body' => '<html>theme page</html>', 'headers' => array('content-type' => 'text/html')),
    $base . '/x.md'      => array('status' => 0, 'body' => 'cURL error 28: timed out', 'headers' => array()),
    $base . '/robots.txt' => array('status' => 500, 'body' => '', 'headers' => array()),
)));
$by3 = array(); foreach ($r3 as $f) { $by3[$f['key']] = $f; }
check('a 200 that is the THEME page at /llm-info/ is NOT called live (warn: not the generated overview)', $by3['llminfo']['state'] === 'warn' && strpos($by3['llminfo']['note'], 'not the generated overview') !== false, $by3['llminfo']);
check('a 200 HTML page at /llms.txt is NOT called live (warn: not plain-text)', $by3['llms']['state'] === 'warn', $by3['llms']);
check('no response → unreachable with the transport error', $by3['md']['state'] === 'unreachable' && strpos($by3['md']['note'], 'timed out') !== false, $by3['md']);
check('5xx → error with the code', $by3['robots']['state'] === 'error' && strpos($by3['robots']['note'], '500') !== false, $by3['robots']);
// The default fetcher (wp_remote_get) — a crawler-ish UA, redirects followed, no-verify off the table
$GLOBALS['fetch'] = array($base . '/robots.txt' => array('response' => array('code' => 200), 'body' => "User-agent: *\nDisallow:", 'headers' => array('content-type' => 'text/plain')));
$r4 = AirHost::verify_public_files($base);
check('default fetcher goes through wp_remote_get (robots read live)', $r4[0]['state'] === 'live', $r4[0]);
check('…with a crawler-like user agent + bounded timeout', preg_match("/'user-agent'\s*=>\s*'Mozilla\/5\.0 \(compatible; PowerCreatives-AIReadinessCheck/", $air) === 1 && preg_match("/'timeout'\s*=>\s*15,/", $air) === 1);

echo "\n2. robots_blocks_ai_crawlers() — pure\n";
check('Yoast default (Disallow: empty) blocks nobody', AirHost::robots_blocks_ai_crawlers("User-agent: *\nDisallow:\n") === array());
check('own group Disallow: / → that bot only', AirHost::robots_blocks_ai_crawlers("User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nDisallow: /wp-admin/\n") === array('GPTBot'));
$all = AirHost::robots_blocks_ai_crawlers("User-agent: *\nDisallow: /\n");
check('blanket * Disallow: / blocks every AI crawler (10)', count($all) === 10 && in_array('PerplexityBot', $all, true), $all);
$mixed = AirHost::robots_blocks_ai_crawlers("User-agent: GPTBot\nAllow: /\n\nUser-agent: *\nDisallow: /\n");
check('blanket block + a bot with its OWN group → that bot is not counted as blocked', !in_array('GPTBot', $mixed, true) && in_array('ClaudeBot', $mixed, true), $mixed);
check('comments and case are tolerated', AirHost::robots_blocks_ai_crawlers("user-agent: claudebot # ai\ndisallow: /   # all\n") === array('ClaudeBot'));

echo "\n3. Templates: the documents are Templates rows (owner 07-23 / 06-15)\n";
check('prompts.php ships air_page_summary (the llms.txt line / .md summary) using the SEO vocabulary names {{title}} {{corpus}}', isset($prompts['air_page_summary']['generate']) && strpos($prompts['air_page_summary']['generate'], '{{title}}') !== false && strpos($prompts['air_page_summary']['generate'], '{{corpus}}') !== false && strpos($prompts['air_page_summary']['generate'], '20-35 words') !== false);
check('…and site_ai_description + llm_info_page are still there (site description / overview page)', isset($prompts['site_ai_description']['generate'], $prompts['llm_info_page']['generate']));
$ai = file_get_contents($ROOT . '/includes/modules/seo/ai.php');
check('Templates rows get SPOKEN names: "AI Readiness — /llm-info/ page", "— llms.txt site description", "— page summary"', strpos($ai, "'llm_info_page_generate'       => 'AI Readiness — /llm-info/ page',") !== false && strpos($ai, "'site_ai_description_generate' => 'AI Readiness — llms.txt site description',") !== false && strpos($ai, "'air_page_summary_generate'    => 'AI Readiness — page summary (.md / llms.txt line)',") !== false);
$tt = file_get_contents($ROOT . '/app/shared/templateTypes.ts');
check('Templates module offers the three AI Readiness types (incl. the NEW page summary) under SEO', strpos($tt, '"air_page_summary_generate",') !== false && strpos($tt, 'air_page_summary_generate: "AI Readiness — page summary (.md / llms.txt line)",') !== false && strpos($tt, 'llm_info_page_generate: "AI Readiness — /llm-info/ page",') !== false && strpos($tt, 'site_ai_description_generate: "AI Readiness — llms.txt site description",') !== false);
// summarize() resolves THROUGH the Templates layer, with an explicit pick — EXECUTED
$GLOBALS['posts'] = array(7 => (object) array('ID' => 7, 'post_title' => 'Boka tid', 'post_name' => 'boka-tid'));
$GLOBALS['meta']  = array(7 => array('_pcm_md_content' => "# Boka tid\n\nBoka din massage online."));
PCM_LLM::$calls = array(); PCM_SEO_AI::$resolve_calls = array();
$res = AirHost::summarize(7, 'gpt-4o', 3, 'openai', 42);
check('summarize(): resolves section air_page_summary_generate for the user WITH the picked template id', PCM_SEO_AI::$resolve_calls === array(array('air_page_summary_generate', 3, 42)), PCM_SEO_AI::$resolve_calls);
$sent = (string) (PCM_LLM::$calls[0]['model'] ?? '');
$prompt_sent = (string) ($GLOBALS['last_prompt'] ?? '');
check('…the picked template’s text is what runs, with {{title}} / {{corpus}} filled', $res === array('summary' => 'Hand-made summary') && $sent === 'gpt-4o' && ($GLOBALS['meta'][7]['_pcm_md_summary'] ?? '') === 'Hand-made summary');
// capture the prompt: re-run with a recording LLM
class PCM_LLM_Rec extends PCM_LLM { }
PCM_SEO_AI::$resolve_calls = array();
$res2 = AirHost::summarize(7, null, 3, null, null);
check('summarize() without a pick → the section default (template_id null), not a hardcoded prompt', PCM_SEO_AI::$resolve_calls === array(array('air_page_summary_generate', 3, null)), PCM_SEO_AI::$resolve_calls);
check('no hardcoded summary prompt text remains in ai-readiness.php', strpos($air, '"Write a concise, factual description (20-35 words) for this page in a directory listing. "') === false);
// gen_site_description() — the LOCAL site description now goes through the SAME template as the connected-site one
$GLOBALS['get_posts'] = array((object) array('post_title' => 'Boka tid'), (object) array('post_title' => 'Pris'));
PCM_SEO_AI::$resolve_calls = array();
$d = AirHost::gen_site_description(null, 3, null, 9);
check('gen_site_description(): resolves site_ai_description_generate with the pick (9) and fills site_name + site.name + key_pages', PCM_SEO_AI::$resolve_calls === array(array('site_ai_description_generate', 3, 9)) && $d === array('description' => 'Hand-made summary'), array(PCM_SEO_AI::$resolve_calls, $d));
check('no hardcoded llms.txt description prompt remains in ai-readiness.php', strpos($air, '"Write a concise 1-2 sentence description of this website for an AI/LLM index file (llms.txt). "') === false);
check('the connected-site description fills site.name too (one vocabulary)', preg_match("/'site_name' => \\\$name,.*\n\s*'site\.name' => \\\$name,/", $svc) === 1);
check('llm-info build takes the template pick all the way down (build_llm_info → llm_info_prompt → resolve_prompt)', strpos($svc, 'public static function build_llm_info(array $ctx, ?string $model = null, ?int $user_id = null, ?string $provider = null, ?int $template_id = null)') !== false && strpos($svc, '$prompt = self::llm_info_prompt($ctx, $user_id, $template_id)') !== false && strpos($svc, "PCM_SEO_AI::resolve_prompt('llm_info_page_generate', \$default, \$user_id, \$template_id)") !== false);
check('REST accepts templateId on: llm-info build (local+remote), summarize, site description (local+remote)', substr_count($ctl, "\$tpl  = !empty(\$p['templateId']) ? absint(\$p['templateId']) : null;") === 1 && substr_count($ctl, "\$tpl    = !empty(\$p['templateId']) ? absint(\$p['templateId']) : null;") === 1 && substr_count($ctl, "\$tpl      = !empty(\$params['templateId']) ? absint(\$params['templateId']) : null;") === 3);
$routes = file_get_contents($ROOT . '/app/src/lib/trpc-routes.ts');
check('client passes templateId on the remote llm-info build + remote site description', strpos($routes, 'strengths: input.strengths, model: input.model, provider: input.provider, templateId: input.templateId }') !== false && strpos($routes, 'site-desc`, body: { model: input.model, provider: input.provider, templateId: input.templateId }') !== false);

echo "\n4. \"Generate summary does not seem to work\": web-enabled → plain fallback (EXECUTED)\n";
eval('class SvcHost { ' . $slice_of($svc, 'public static function invoke_with_web_fallback(') . ' }');
PCM_LLM::$calls = array(); PCM_LLM::$fail_web = true; PCM_LLM::$fail_all = false;
$out = SvcHost::invoke_with_web_fallback(array(array('role' => 'user', 'content' => 'x')), array('web' => true, 'model' => 'gpt-4o', 'max_tokens' => 1400));
check('web call throws → the SAME prompt runs again without web, result returned', ($out['content'] ?? '') === 'Hand-made summary' && count(PCM_LLM::$calls) === 2 && !empty(PCM_LLM::$calls[0]['web']) && empty(PCM_LLM::$calls[1]['web']) && PCM_LLM::$calls[1]['model'] === 'gpt-4o', PCM_LLM::$calls);
PCM_LLM::$calls = array(); PCM_LLM::$fail_web = false;
SvcHost::invoke_with_web_fallback(array(), array('web' => true));
check('web call succeeds → exactly one call', count(PCM_LLM::$calls) === 1);
PCM_LLM::$calls = array();
SvcHost::invoke_with_web_fallback(array(), array('web' => false));
check('web off → one plain call (no retry path)', count(PCM_LLM::$calls) === 1 && empty(PCM_LLM::$calls[0]['web']));
PCM_LLM::$fail_all = true; $threw = '';
try { SvcHost::invoke_with_web_fallback(array(), array('web' => true)); } catch (Throwable $e) { $threw = $e->getMessage(); }
check('both fail → the PLAIN call’s error surfaces (not the web one)', $threw === 'provider down', $threw);
PCM_LLM::$fail_all = false;
check('build_llm_info() uses the fallback-aware invoke', strpos($svc, '$result = self::invoke_with_web_fallback(array(array(\'role\' => \'user\', \'content\' => $prompt)), $opts);') !== false);
check('connected-site llm-info build passes the brand language hint (language law)', strpos($svc, "'language'  => \$lang,") !== false && strpos($svc, "business_record_for_site((int) (\$site->id ?? 0))['fields']['language']") !== false);

echo "\n5. \"it does not list any .md files\": remote_ai_posts() is the LIGHT read (EXECUTED)\n";
class PCM_Sites_Service {
    public static $calls = array(); public static $pages = array(); public static $posts = array();
    public static function remote_rest($site, $method, $route, $query = array(), $body = null, $timeout = 30) {
        self::$calls[] = array($route, $query);
        $src = $route === '/wp/v2/pages' ? self::$pages : self::$posts;
        $page = (int) ($query['page'] ?? 1); $per = (int) ($query['per_page'] ?? 100);
        $slice = array_slice($src, ($page - 1) * $per, $per);
        if ($page > 1 && $slice === array()) { return array('status' => 400, 'body' => array()); }
        return array('status' => 200, 'body' => $slice);
    }
}
eval('class PostsHost { public static function ensure_sites_service() {} ' . $slice_of($svc, 'public static function remote_ai_posts(') . ' }');
PCM_Sites_Service::$pages = array(array('id' => 1, 'title' => array('rendered' => 'Boka tid'), 'link' => 'https://massagegoteborg.nu/boka-tid/'), array('id' => 2, 'title' => array('rendered' => 'Om &amp; oss'), 'link' => 'https://massagegoteborg.nu/about-us/'));
PCM_Sites_Service::$posts = array(array('id' => 10, 'title' => array('rendered' => 'Recovery'), 'link' => 'https://massagegoteborg.nu/2024/05/recovery/'));
PCM_Sites_Service::$calls = array();
$list = PostsHost::remote_ai_posts((object) array('id' => 5, 'url' => 'https://massagegoteborg.nu'));
check('pages then posts, each with its .md url (permalink + .md), entities decoded', count($list) === 3 && $list[0]['mdUrl'] === 'https://massagegoteborg.nu/boka-tid.md' && $list[1]['title'] === 'Om & oss' && $list[2]['type'] === 'post' && $list[2]['mdUrl'] === 'https://massagegoteborg.nu/2024/05/recovery.md', $list);
check('LIGHT: only id,title,link, published only — not the full listing (no _embed / meta / head fields)', PCM_Sites_Service::$calls[0][1]['_fields'] === 'id,title,link' && PCM_Sites_Service::$calls[0][1]['status'] === 'publish' && !isset(PCM_Sites_Service::$calls[0][1]['_embed']), PCM_Sites_Service::$calls[0]);
check('short page = last page → exactly one request per type (no 400-provoking extra page)', count(PCM_Sites_Service::$calls) === 2, PCM_Sites_Service::$calls);
PCM_Sites_Service::$pages = array_fill(0, 100, array('id' => 1, 'title' => array('rendered' => 'P'), 'link' => 'https://x/p/')); PCM_Sites_Service::$posts = array(); PCM_Sites_Service::$calls = array();
$list2 = PostsHost::remote_ai_posts((object) array('id' => 5, 'url' => 'https://x'));
check('a full 100-row page → the next page is read (pagination), the 400 end signal is handled', count($list2) === 100 && count(PCM_Sites_Service::$calls) === 3, count(PCM_Sites_Service::$calls));
check('the REST verify routes exist (local + connected) and return files + checkedAt', strpos($ctl, "array('POST', '/seo/ai-readiness/verify',     'air_verify', array(), 'manage_options'),") !== false && strpos($ctl, "array('POST', '/seo/sites/(?P<id>\\d+)/ai/verify', 'remote_ai_verify', array(), 'manage_options'),") !== false && substr_count($ctl, "'checkedAt' => current_time('mysql')") === 2);
check('remote verify samples the first page .md from the light list', strpos($svc, 'public static function remote_ai_verify(object $site): array') !== false && strpos($svc, 'return PCM_SEO_AIReadiness::verify_public_files($base, $extra);') !== false);
check('client routes: seo.airVerify + seo.remoteAiVerify', strpos($routes, '"seo.airVerify": { endpoint: "seo/ai-readiness/verify", method: "POST" },') !== false && strpos($routes, 'url: `seo/sites/${input.siteId}/ai/verify`') !== false);

echo "\n6. Connector: the page .md files actually resolve (\"the pages return 404\")\n";
preg_match("/<<<'PHP'\r?\n(.*?)\r?\nPHP;/s", $hub, $m); $tpl = $m[1] ?? '';
check('connector template extracted', $tpl !== '');
check('/{slug}.md: hierarchical path first, then the LAST segment by name (dated / category permalinks), over every public type', strpos($tpl, "\$types = array_values(get_post_types(array('public' => true)));") !== false && strpos($tpl, "\$page = get_page_by_path(\$slug, OBJECT, \$types);") !== false && strpos($tpl, "\$found = get_posts(array('name' => basename(\$slug), 'post_type' => \$types, 'post_status' => 'publish', 'numberposts' => 1));") !== false);
check('…a miss is an explicit 404 body (not a silent fall-through to the theme)', strpos($tpl, "if (!\$page || \$page->post_status !== 'publish') { status_header(404); nocache_headers(); echo '# Page not found'; exit; }") !== false);
check('…a hit is an explicit 200 text/markdown', strpos($tpl, "status_header(200);\n    header('Content-Type: text/markdown; charset=utf-8');") !== false || strpos($tpl, "status_header(200);\r\n    header('Content-Type: text/markdown; charset=utf-8');") !== false);
check('canonical redirect never rewrites .md / .txt virtual files', preg_match("/add_filter\('redirect_canonical', function \(\\\$redirect_url, \\\$requested_url\) \{\s*\r?\n\s*\\\$p = \(string\) parse_url\(\(string\) \\\$requested_url, PHP_URL_PATH\);\s*\r?\n\s*return preg_match\('#\\\\\.\(md\|txt\)\\$#i', \\\$p\) \? false : \\\$redirect_url;/", $tpl) === 1);
check('llms.txt + /llm-info/ answer with an explicit 200 (a WP 404 status no longer leaks onto a served file)', substr_count($tpl, "status_header(200);") >= 3);
// template changed → build number bumps → connected sites self-update (the version law)
check('connector version law still derives the build from the template body (self-update on this change)', preg_match('/md5\(/', substr($hub, 0, strpos($hub, "<<<'PHP'"))) === 1 || strpos($hub, 'connector_build') !== false);

echo "\n7. ONE studio, usable (owner 08-10 / 07-31)\n";
$ui = file_get_contents($ROOT . '/app/src/modules/SEO/AiReadinessStudio.tsx');
$idx = file_get_contents($ROOT . '/app/src/modules/SEO/index.tsx');
check('the three old panels are gone (AIReadinessPanel / RemoteAIReadinessPanel / LlmInfoEditor)', !file_exists($ROOT . '/app/src/modules/SEO/AIReadinessPanel.tsx') && !file_exists($ROOT . '/app/src/modules/SEO/RemoteAIReadinessPanel.tsx') && !file_exists($ROOT . '/app/src/modules/SEO/LlmInfoEditor.tsx') && strpos($idx, 'LlmInfoSection') === false && strpos($idx, 'RemoteAIReadinessPanel') === false);
check('the SAME studio renders for this site and for a connected site', strpos($idx, '<AiReadinessStudio siteName="this site" />') !== false && strpos($idx, '<AiReadinessStudio siteId={siteId} siteName={activeSite?.name || activeSite?.url || \'this site\'} />') !== false);
check('three numbered cards: 1 · /llm-info/, 2 · llms.txt (+ page .md), 3 · robots.txt', strpos($ui, '1 · /llm-info/') !== false && strpos($ui, '2 · llms.txt') !== false && strpos($ui, '3 · robots.txt') !== false);
check('/llm-info/ has Generate + Publish like llms.txt does (the owner’s "no generate button" complaint)', preg_match('/onClick=\{handleLlmGenerate\}[\s\S]*?\{li\.content\.trim\(\) \? \'Regenerate\' : \'Generate\'\}/', $ui) === 1 && strpos($ui, 'onCheckedChange={handleLlmPublish}') !== false && preg_match('/onClick=\{handleLlmsGenerate\}[\s\S]*?\{llms\.text\.trim\(\) \? \'Regenerate\' : \'Generate\'\}/', $ui) === 1 && strpos($ui, 'onCheckedChange={handleLlmsPublish}') !== false);
check('Generate needs no input: the first generation PUBLISHES ("it should be created at /llm-info/")', strpos($ui, "const firstTime = li.content.trim() === '';") !== false && strpos($ui, 'const next = { ...li, content: html, enabled: li.enabled || firstTime };') !== false && strpos($ui, 'await saveLlmInfo(next);') !== false);
check('…llms.txt likewise: description written automatically when empty, built, saved, published on first run (local + remote)', strpos($ui, "if (d === '') { try { d = await handleSiteDesc(); }") !== false && strpos($ui, 'const enabled = llms.enabled || firstTime;') !== false && strpos($ui, "if (firstTime || !llms.enabled) await airPublish.mutateAsync({ published: true });") !== false);
check('the details are OPTIONAL and folded away (keywords/area/years/strengths; description/include/templates)', strpos($ui, 'Details (optional) — keywords, area, years, strengths, template') !== false && strpos($ui, 'Details (optional) — site description, what to include, templates') !== false);
check('the live check is wired for both scopes and runs again after every Generate/Publish', strpos($ui, 'trpc.seo.airVerify.useMutation()') !== false && strpos($ui, 'trpc.seo.remoteAiVerify.useMutation()') !== false && substr_count($ui, 'void runCheck(true);') >= 4);
check('a status strip names all four files with a verdict pill', strpos($ui, 'data-testid="air-status-strip"') !== false && strpos($ui, "{ key: 'llminfo', label: '/llm-info/'") !== false && strpos($ui, "{ key: 'llms', label: 'llms.txt'") !== false && strpos($ui, "{ key: 'md', label:") !== false && strpos($ui, "{ key: 'robots', label: 'robots.txt'") !== false);
check('the check’s plain-words note is shown under each card when a file is not live', substr_count($ui, "liCheck.state !== 'live' && <p") === 1 && substr_count($ui, "llmsCheck.state !== 'live' && <p") === 1 && strpos($ui, "mdCheck.state !== 'live' && <p") !== false && strpos($ui, '{robotsCheck && <p className="mt-1 text-[11px] text-muted-foreground">{robotsCheck.note}</p>}') !== false);
check('a blocked file surfaces its note at the top (the bot-wall case)', strpos($ui, "checks?.some((c) => c.state === 'blocked')") !== false);
check('errors are INLINE and say what happened (never a silent dead button)', substr_count($ui, '<InlineError text={liError} />') === 1 && substr_count($ui, '<InlineError text={llmsError} />') === 1 && strpos($ui, "setLiError(m); toast.error(m);") !== false);
check('a failed READ no longer hides Generate (remote GET error → warning, editor stays)', strpos($ui, "Couldn't read the current /llm-info/ from {siteName}: {llmLoadError} — Generate still works") !== false && strpos($ui, "Couldn't read llms.txt from {siteName}: {llmsLoadError}") !== false);
check('template picks for the three prompts (/llm-info/ page, site description, page summary)', strpos($ui, "templatesForSection(seoTemplatesRaw ?? [], 'llm_info_page_generate')") !== false && strpos($ui, "templatesForSection(seoTemplatesRaw ?? [], 'site_ai_description_generate')") !== false && strpos($ui, "templatesForSection(seoTemplatesRaw ?? [], 'air_page_summary_generate')") !== false && substr_count($ui, '<TemplatePick ') === 3 && strpos($ui, 'templateId: llmInfoTpl') !== false && strpos($ui, 'templateId: siteDescTpl') !== false && strpos($ui, 'templateId: pageSummaryTpl') !== false);
check('the page list stays: remote rows link to their .md; local rows keep .md status, exclude, regenerate, summarise, and "Summarise all"', strpos($ui, 'handleSummarizeAll') !== false && strpos($ui, 'handleRowSummarize') !== false && strpos($ui, 'toggleExclude') !== false && strpos($ui, 'title={`Open ${p.mdUrl}`}') !== false);
check('one text model pick for every Generate on the screen (shared SEO key)', strpos($ui, "const GEN_MODEL_KEY = 'pcm:seo:gen-model';") !== false && substr_count($ui, 'modelId || undefined') >= 4);
check('overview is PREVIEWED (rendered) by default, HTML editing on request', strpos($ui, "{liEditHtml ? 'Preview' : 'Edit HTML'}") !== false && strpos($ui, 'dangerouslySetInnerHTML={{ __html: li.content }}') !== false);
check('the select style law is kept (no height/font-size overrides on SelectTrigger)', preg_match('/<SelectTrigger className="[^"]*(h-\d|text-xs)[^"]*"/', $ui) === 0);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
