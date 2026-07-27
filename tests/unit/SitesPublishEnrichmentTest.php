<?php
/**
 * Unit Tests — Sites publish-time enrichments (A2 featured image, A3 media
 * alt/title, A4-wire JSON-LD schema embed, A5 remote tags/category).
 *
 * Exercises the REAL PCM_Sites_Service::publish_to_site() against in-process
 * fakes. Every remote call the service makes routes through the single
 * wp_remote_post()/wp_remote_get() choke — this suite fakes those two (plus the
 * wp_remote_retrieve_* accessors) to return canned responses and records every
 * call in a global, so the tests can assert the exact HTTP call sequence without
 * a network or a live WordPress.
 *
 * Mirrors StrategyAutoPublishTest's isolation convention: the fakes share class
 * NAMES with the real, composer-classmapped services (PCM_DB), so this class
 * carries `@runTestsInSeparateProcesses` + `@preserveGlobalState disabled` and
 * the fake declarations live inside a plain function that only runs at test
 * EXECUTION time (setUp), inside the isolated child process. Every class_exists()
 * guard passes `false` to disable autoloading (the default would load the REAL
 * class the moment the check runs). Functions are function_exists-guarded and
 * defined BEFORE the real sites/service.php is required.
 *
 * @package PowerCreatives\Tests\Unit
 */

/** Declares the fakes exactly once per (isolated) process, on first call. */
function pcm_test_define_sites_fakes(): void
{
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/wordpress/');
    }
    // Our failure-isolated paths call error_log(); under the CLI SAPI that writes
    // to STDERR, which PHPUnit's process-isolation runner misreads as a fatal.
    // Redirect to a throwaway file for this process only (test-run redirect, no
    // production change).
    ini_set('error_log', sys_get_temp_dir() . '/pcm_sites_test_error_log_' . getmypid() . '.log');

    if (!function_exists('wp_salt')) {
        function wp_salt($scheme = 'auth')
        {
            return 'pcm-test-fixed-salt-value';
        }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data, $options = 0, $depth = 512)
        {
            return json_encode($data, $options, $depth);
        }
    }
    if (!function_exists('current_time')) {
        function current_time($type)
        {
            return '2026-07-10 00:00:00';
        }
    }
    if (!function_exists('__')) {
        function __($s, $d = 'default')
        {
            return $s;
        }
    }
    if (!function_exists('sanitize_file_name')) {
        function sanitize_file_name($s)
        {
            return preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $s);
        }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing)
        {
            return $thing instanceof WP_Error;
        }
    }
    if (!class_exists('WP_Error', false)) {
        class WP_Error
        {
            private $msg;
            public function __construct($code = '', $message = '', $data = null)
            {
                $this->msg = $message;
            }
            public function get_error_message()
            {
                return $this->msg;
            }
        }
    }

    // ── HTTP choke fakes — record every call, return canned responses. ──────
    if (!function_exists('wp_remote_post')) {
        function wp_remote_post($url, $args = array())
        {
            $GLOBALS['pcm_http_calls'][] = array('method' => 'POST', 'url' => $url, 'args' => $args);
            return PCM_Test_Http::respond('POST', $url, $args);
        }
    }
    if (!function_exists('wp_remote_get')) {
        function wp_remote_get($url, $args = array())
        {
            $GLOBALS['pcm_http_calls'][] = array('method' => 'GET', 'url' => $url, 'args' => $args);
            return PCM_Test_Http::respond('GET', $url, $args);
        }
    }
    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($r)
        {
            return is_array($r) ? (int) ($r['status'] ?? 0) : 0;
        }
    }
    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body($r)
        {
            return is_array($r) ? (string) ($r['body'] ?? '') : '';
        }
    }
    if (!function_exists('wp_remote_retrieve_header')) {
        function wp_remote_retrieve_header($r, $header)
        {
            return is_array($r) ? (string) ($r['headers'][strtolower($header)] ?? '') : '';
        }
    }

    if (!class_exists('PCM_Test_Http', false)) {
        class PCM_Test_Http
        {
            public static $imageStatus;
            public static $imageBytes;
            public static $imageContentType;
            public static $mediaStatus;
            public static $mediaId;
            public static $postStatus;
            public static $postId;
            public static $postLink;
            /** @var string|null When set, the /wp/v2/posts POST returns this body verbatim (error-path tests). */
            public static $postBody;
            /** @var array<int,array{id:int,name:string}> rows a term search GET returns */
            public static $termSearchRows;
            /** @var int id a term-create POST returns (0 => the create "fails") */
            public static $termCreateId;

            public static function reset(): void
            {
                self::$imageStatus      = 200;
                self::$imageBytes       = 'RAWIMAGEBYTES';
                self::$imageContentType = 'image/png';
                self::$mediaStatus      = 201;
                self::$mediaId          = 4242;
                self::$postStatus       = 201;
                self::$postId           = 777;
                self::$postLink         = 'https://site.example/hello-world';
                self::$postBody         = null;
                self::$termSearchRows   = array();
                self::$termCreateId     = 0;
            }

            public static function respond($method, $url, $args)
            {
                // An image fetch is the only GET with no `rest_route=` query var.
                if ($method === 'GET' && strpos($url, 'rest_route=') === false) {
                    return array(
                        'status'  => self::$imageStatus,
                        'body'    => self::$imageBytes,
                        'headers' => array('content-type' => self::$imageContentType),
                    );
                }
                $q = array();
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $route = (string) ($q['rest_route'] ?? '');

                if ($method === 'GET' && (strpos($route, '/wp/v2/tags') === 0 || strpos($route, '/wp/v2/categories') === 0)) {
                    return array('status' => 200, 'body' => json_encode(self::$termSearchRows), 'headers' => array());
                }
                if ($method === 'POST' && ($route === '/wp/v2/tags' || $route === '/wp/v2/categories')) {
                    if (self::$termCreateId > 0) {
                        return array('status' => 201, 'body' => json_encode(array('id' => self::$termCreateId)), 'headers' => array());
                    }
                    return array('status' => 500, 'body' => json_encode(array('code' => 'error')), 'headers' => array());
                }
                if ($method === 'POST' && $route === '/wp/v2/posts') {
                    return array(
                        'status'  => self::$postStatus,
                        'body'    => self::$postBody ?? json_encode(array('id' => self::$postId, 'link' => self::$postLink)),
                        'headers' => array(),
                    );
                }
                if ($method === 'POST' && strpos($route, '/wp/v2/media/') === 0) {
                    return array('status' => 200, 'body' => json_encode(array('id' => self::$mediaId)), 'headers' => array());
                }
                if ($method === 'POST' && $route === '/wp/v2/media') {
                    return array(
                        'status'  => self::$mediaStatus,
                        'body'    => json_encode(array('id' => self::$mediaId, 'source_url' => 'https://site.example/uploads/pic.png')),
                        'headers' => array(),
                    );
                }
                if ($method === 'POST' && strpos($route, '/wp/v2/posts/') === 0) {
                    return array('status' => 200, 'body' => json_encode(array('id' => self::$postId)), 'headers' => array());
                }
                return array('status' => 200, 'body' => '{}', 'headers' => array());
            }
        }
    }

    // Fake PCM_DB — publish_to_site() only calls update_article() at the tail.
    if (!class_exists('PCM_DB', false)) {
        class PCM_DB
        {
            /** @var array<int,array{id:int,user_id:int,data:array}> */
            public static $updateArticleCalls = array();
            public static function update_article($id, $user_id, $data)
            {
                self::$updateArticleCalls[] = array('id' => $id, 'user_id' => $user_id, 'data' => $data);
                return true;
            }
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SitesPublishEnrichmentTest extends \PHPUnit\Framework\TestCase
{
    /** @var object */
    private $site;

    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_sites_fakes();
        require_once dirname(__DIR__, 2) . '/includes/modules/sites/service.php';

        $GLOBALS['pcm_http_calls'] = array();
        PCM_Test_Http::reset();
        PCM_DB::$updateArticleCalls = array();

        $this->site = (object) array(
            'id'          => 9,
            'name'        => 'My Site',
            'url'         => 'https://site.example',
            'username'    => 'admin',
            'appPassword' => PCM_Sites_Service::encrypt_password('app-pass-123'),
        );
    }

    /** @param array<string,mixed> $over */
    private function article(array $over = array()): object
    {
        return (object) array_merge(array(
            'id'              => 100,
            'title'           => 'Hello World',
            'content'         => '<p>Body</p>',
            'slug'            => 'hello-world',
            'metaTitle'       => 'MT',
            'metaDescription' => 'MD',
            'featuredImage'   => '',
        ), $over);
    }

    /** @param array{method:string,url:string,args:array} $call */
    private function route(array $call): string
    {
        if ($call['method'] === 'GET' && strpos($call['url'], 'rest_route=') === false) {
            return 'GET image';
        }
        $q = array();
        parse_str((string) parse_url($call['url'], PHP_URL_QUERY), $q);
        return $call['method'] . ' ' . (string) ($q['rest_route'] ?? $call['url']);
    }

    /** @return string[] ordered route labels of every recorded HTTP call */
    private function sequence(): array
    {
        return array_map(array($this, 'route'), $GLOBALS['pcm_http_calls']);
    }

    /** @return array the decoded JSON body of the post-create call */
    private function postCreateBody(): array
    {
        foreach ($GLOBALS['pcm_http_calls'] as $call) {
            if ($this->route($call) === 'POST /wp/v2/posts') {
                return json_decode((string) $call['args']['body'], true);
            }
        }
        $this->fail('no POST /wp/v2/posts call was recorded');
    }

    // ── A2 guard: no featured image ⇒ no media calls at all ─────────────────

    public function test_article_without_featured_image_never_attempts_media_calls(): void
    {
        $res = PCM_Sites_Service::publish_to_site($this->site, $this->article(), 5);

        $this->assertTrue($res['success']);
        $this->assertSame(777, $res['postId']);
        foreach ($this->sequence() as $label) {
            $this->assertStringNotContainsString('/wp/v2/media', $label, 'media endpoints must not be touched without a featured image');
            $this->assertNotSame('GET image', $label, 'no source image should be fetched');
        }
    }

    // ── Error translation: role-permission codes → actionable message ───────

    /**
     * The EXACT reported bug: the connected site's Application-Password user
     * lacks the `create_posts` capability (e.g. a Subscriber). WP core pairs
     * code `rest_cannot_create` with the localized string "…create posts as
     * this user" (the reported Swedish text). Verified against wp-includes REST
     * posts controller. The thrown error must name the user + the fix, not echo
     * the opaque WordPress text.
     */
    public function test_rest_cannot_create_becomes_an_actionable_error(): void
    {
        PCM_Test_Http::$postStatus = 401;
        PCM_Test_Http::$postBody   = json_encode(array(
            'code'    => 'rest_cannot_create',
            'message' => 'Du har inte behörighet att skapa inlägg som om du vore denna användare.',
            'data'    => array('status' => 401),
        ));

        try {
            PCM_Sites_Service::publish_to_site($this->site, $this->article(), 5);
            $this->fail('publish_to_site must throw on a 401 rest_cannot_create');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('admin', $msg, 'the message names the remote username');
            $this->assertStringContainsString('Editor or Administrator', $msg, 'the message states the remedy');
            $this->assertStringNotContainsString('WordPress API error', $msg, 'the opaque raw-text branch must not be used');
            // The failed publish must NOT flip the article to "published".
            $this->assertCount(0, PCM_DB::$updateArticleCalls, 'a rejected publish records no published state');
        }
    }

    /** A Contributor (can draft, not publish): rest_cannot_publish, a DIFFERENT
     *  WP message — still a role failure, still translated to the remedy. */
    public function test_rest_cannot_publish_also_translated(): void
    {
        PCM_Test_Http::$postStatus = 403;
        PCM_Test_Http::$postBody   = json_encode(array(
            'code'    => 'rest_cannot_publish',
            'message' => 'Sorry, you are not allowed to publish posts in this post type.',
        ));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Editor or Administrator/');
        PCM_Sites_Service::publish_to_site($this->site, $this->article(), 5);
    }

    public function test_other_api_errors_keep_the_raw_message(): void
    {
        PCM_Test_Http::$postStatus = 500;
        PCM_Test_Http::$postBody   = json_encode(array('code' => 'internal_error', 'message' => 'Boom'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WordPress API error: Boom');
        PCM_Sites_Service::publish_to_site($this->site, $this->article(), 5);
    }

    // ── A4-wire: existing ld+json is not double-embedded ────────────────────

    public function test_content_with_existing_ld_json_is_sent_verbatim(): void
    {
        $content = '<p>Body</p><script type="application/ld+json">{"@type":"Article"}</script>';
        PCM_Sites_Service::publish_to_site($this->site, $this->article(array('content' => $content)), 5);

        $posted = $this->postCreateBody();
        $this->assertSame(1, substr_count($posted['content'], 'application/ld+json'), 'schema must not be embedded twice');
        $this->assertSame($content, $posted['content'], 'content already carrying schema must be sent unchanged');
    }

    // ── A4-wire: schema IS embedded when absent ─────────────────────────────

    public function test_content_without_ld_json_gets_schema_embedded_in_outgoing_only(): void
    {
        $article = $this->article(array('content' => '<p>Body</p>'));
        PCM_Sites_Service::publish_to_site($this->site, $article, 5);

        $posted = $this->postCreateBody();
        $this->assertStringContainsString('application/ld+json', $posted['content'], 'a JSON-LD block must be appended');
        $this->assertStringStartsWith('<p>Body</p>', $posted['content'], 'the original body must be preserved ahead of the schema');
        // The stored hub article object must be untouched — only the SENT copy changes.
        $this->assertSame('<p>Body</p>', $article->content);
    }

    // ── A5 + A2/A3 full happy path: term reuse + image sideload + featured ──

    public function test_full_happy_path_resolves_tag_uploads_image_and_sets_featured_media(): void
    {
        PCM_Test_Http::$termSearchRows = array(array('id' => 31, 'name' => 'kw one'));
        $article = $this->article(array('featuredImage' => 'https://cdn.example.com/pic.png', 'title' => 'Hello World'));

        $res = PCM_Sites_Service::publish_to_site($this->site, $article, 5, array('tags' => array('kw one')));

        $this->assertTrue($res['success']);
        $this->assertSame(777, $res['postId']);

        // Exact call sequence: resolve tag, create post, sideload image, set alt/title, attach featured.
        $this->assertSame(array(
            'GET /wp/v2/tags',
            'POST /wp/v2/posts',
            'GET image',
            'POST /wp/v2/media',
            'POST /wp/v2/media/4242',
            'POST /wp/v2/posts/777',
        ), $this->sequence());

        // Existing tag reused (no create) and attached to the post payload.
        $posted = $this->postCreateBody();
        $this->assertSame(array(31), $posted['tags']);

        // A3 — deterministic alt_text/title (the article title) on the media follow-up.
        $meta = json_decode((string) $GLOBALS['pcm_http_calls'][4]['args']['body'], true);
        $this->assertSame('Hello World', $meta['alt_text']);
        $this->assertSame('Hello World', $meta['title']);

        // A2 — featured_media set to the uploaded media id on the post.
        $featured = json_decode((string) $GLOBALS['pcm_http_calls'][5]['args']['body'], true);
        $this->assertSame(4242, $featured['featured_media']);

        // Uploaded filename derives from the slug + content-type extension.
        $this->assertStringContainsString('filename="hello-world.png"', $GLOBALS['pcm_http_calls'][3]['args']['headers']['Content-Disposition']);
    }

    // ── A5: missing term is created, then attached ──────────────────────────

    public function test_unresolved_tag_is_created_then_attached(): void
    {
        PCM_Test_Http::$termSearchRows = array();   // search misses
        PCM_Test_Http::$termCreateId   = 88;         // create returns id 88

        PCM_Sites_Service::publish_to_site($this->site, $this->article(), 5, array('tags' => array('newtag'), 'category' => 'News'));

        $posted = $this->postCreateBody();
        $this->assertSame(array(88), $posted['tags']);
        $this->assertSame(array(88), $posted['categories'], 'the category resolves through the same find-or-create path');

        // tag search+create, category search+create, then the post.
        $this->assertSame(array(
            'GET /wp/v2/tags',
            'POST /wp/v2/tags',
            'GET /wp/v2/categories',
            'POST /wp/v2/categories',
            'POST /wp/v2/posts',
        ), $this->sequence());
    }

    // ── Isolation: a broken image never fails the publish ───────────────────

    public function test_image_fetch_failure_is_isolated_and_post_still_publishes(): void
    {
        PCM_Test_Http::$imageStatus = 404;
        $article = $this->article(array('featuredImage' => 'https://cdn.example.com/missing.png'));

        $res = PCM_Sites_Service::publish_to_site($this->site, $article, 5);

        $this->assertTrue($res['success'], 'the post must publish even though the featured image could not be fetched');
        $seq = $this->sequence();
        $this->assertContains('GET image', $seq, 'the sideload was attempted');
        $this->assertNotContains('POST /wp/v2/media', $seq, 'a failed image fetch must not proceed to media upload');
        $this->assertCount(1, PCM_DB::$updateArticleCalls, 'the article record is still updated with the publish result');
    }
}
