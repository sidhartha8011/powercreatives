<?php
/**
 * Unit Tests — PCM_Social_Source (Source=Social strategies, step 1).
 *
 * Exercises the PURE seams of the social link helper — no WordPress, no
 * network (the impure edges — YouTube channelId page-resolve, oEmbed, og:
 * scrape — are function_exists-guarded inside the class and degrade cleanly
 * when the WP HTTP layer is absent, which is exactly the state here):
 *
 *   - classify()          full platform × post/account matrix + edge cases
 *                         (reserved segments, trailing slashes, http vs
 *                         https, m./www. hosts, short-link hosts);
 *   - account_feed_url()  pure conversions (YouTube /channel/UC…, Bluesky
 *                         /rss, Reddit /.rss) + null for Apify platforms;
 *   - post_context()      the always-works URL-label fallback path;
 *   - apify_request()     default actor-map shapes for all 4 Apify platforms,
 *                         null for free platforms, `pcm_apify_actor_map`
 *                         filter override;
 *   - apify_map_items()   realistic per-platform fixtures, missing-field
 *                         degradation, skip-when-undedupeable, date coercion,
 *                         title/text caps.
 *
 * House-style fakes: this class file is hook-free, so it is required directly
 * after defining tiny shims (sanitize_text_field; ABSPATH + apply_filters
 * already come from the WP_Mock bootstrap) inside
 * pcm_test_define_social_fakes() — called from setUp() so nothing leaks into
 * the discovery process. The filter-override test drives WP_Mock's own
 * apply_filters via WP_Mock::onFilter().
 *
 * @package PowerCreatives\Tests\Unit
 */

function pcm_test_define_social_fakes(): void
{
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/');
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($str)
        {
            return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string)$str)));
        }
    }
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class StrategySocialClassifyTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        pcm_test_define_social_fakes();
        require_once dirname(__DIR__, 2) . '/includes/modules/strategy/class-pcm-social-source.php';
    }

    private function assertClassifies(string $url, string $platform, string $kind): void
    {
        $this->assertSame(
            array('platform' => $platform, 'kind' => $kind),
            PCM_Social_Source::classify($url),
            "classify({$url})"
        );
    }

    // ── (1) classify: platform × post/account matrix ─────────────────────

    public function test_classify_youtube(): void
    {
        $this->assertClassifies('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'post');
        $this->assertClassifies('https://youtu.be/dQw4w9WgXcQ', 'youtube', 'post');
        $this->assertClassifies('https://www.youtube.com/shorts/AbCdEf12345', 'youtube', 'post');
        $this->assertClassifies('https://www.youtube.com/live/AbCdEf12345', 'youtube', 'post');
        $this->assertClassifies('http://m.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'post'); // m. + http
        $this->assertClassifies('https://www.youtube.com/@mkbhd', 'youtube', 'account');
        $this->assertClassifies('https://youtube.com/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw', 'youtube', 'account');
        $this->assertClassifies('https://www.youtube.com/c/mkbhd/', 'youtube', 'account'); // trailing slash
        $this->assertClassifies('https://www.youtube.com/user/marquesbrownlee', 'youtube', 'account');
    }

    public function test_classify_bluesky(): void
    {
        $this->assertClassifies('https://bsky.app/profile/pfrazee.com/post/3kabc123xyz', 'bluesky', 'post');
        $this->assertClassifies('https://bsky.app/profile/pfrazee.com', 'bluesky', 'account');
        $this->assertClassifies('https://bsky.app/profile/did:plc:ragtjsm2j2vknwkz3zp4oxrd/', 'bluesky', 'account');
    }

    public function test_classify_reddit(): void
    {
        $this->assertClassifies('https://www.reddit.com/r/php/comments/1abcde/some_title/', 'reddit', 'post');
        $this->assertClassifies('https://www.reddit.com/r/php/', 'reddit', 'account');
        $this->assertClassifies('https://reddit.com/user/spez', 'reddit', 'account');
        $this->assertClassifies('https://www.reddit.com/u/spez', 'reddit', 'account');
    }

    public function test_classify_instagram(): void
    {
        $this->assertClassifies('https://www.instagram.com/p/Cxyz123AbCd/', 'instagram', 'post');
        $this->assertClassifies('https://www.instagram.com/reel/Cxyz123AbCd/', 'instagram', 'post');
        $this->assertClassifies('https://instagram.com/reels/Cxyz123AbCd', 'instagram', 'post');
        $this->assertClassifies('https://www.instagram.com/tv/Cxyz123AbCd/', 'instagram', 'post');
        $this->assertClassifies('https://www.instagram.com/natgeo', 'instagram', 'account');
        $this->assertClassifies('https://www.instagram.com/natgeo/', 'instagram', 'account');
        // Reserved segments must never classify as an account.
        $this->assertSame('post', PCM_Social_Source::classify('https://www.instagram.com/explore')['kind']);
        $this->assertSame('post', PCM_Social_Source::classify('https://www.instagram.com/accounts/login/')['kind']);
    }

    public function test_classify_tiktok(): void
    {
        $this->assertClassifies('https://www.tiktok.com/@khaby.lame/video/7137423965982686469', 'tiktok', 'post');
        $this->assertClassifies('https://www.tiktok.com/t/ZTRQsJx1c/', 'tiktok', 'post');
        $this->assertClassifies('https://vm.tiktok.com/ZMhkq1234/', 'tiktok', 'post');
        $this->assertClassifies('https://www.tiktok.com/@khaby.lame', 'tiktok', 'account');
        $this->assertClassifies('http://tiktok.com/@khaby.lame/', 'tiktok', 'account'); // http + no www + trailing slash
    }

    public function test_classify_x_and_twitter(): void
    {
        $this->assertClassifies('https://x.com/jack/status/20', 'x', 'post');
        $this->assertClassifies('https://twitter.com/jack/status/20', 'x', 'post');
        $this->assertClassifies('https://x.com/jack', 'x', 'account');
        $this->assertClassifies('https://www.twitter.com/jack/', 'x', 'account');
        // Reserved segments must never classify as an account.
        $this->assertSame('post', PCM_Social_Source::classify('https://x.com/home')['kind']);
        $this->assertSame('post', PCM_Social_Source::classify('https://x.com/i/flow/login')['kind']);
    }

    public function test_classify_facebook(): void
    {
        $this->assertClassifies('https://www.facebook.com/zuck/posts/10102577175875681', 'facebook', 'post');
        $this->assertClassifies('https://www.facebook.com/meta/videos/123456789/', 'facebook', 'post');
        $this->assertClassifies('https://www.facebook.com/reel/123456789', 'facebook', 'post');
        $this->assertClassifies('https://fb.watch/abc123xyz/', 'facebook', 'post');
        $this->assertClassifies('https://www.facebook.com/meta', 'facebook', 'account');
        $this->assertClassifies('https://www.facebook.com/profile.php?id=4', 'facebook', 'account');
    }

    public function test_classify_unknown_host_is_generic_post_never_rejected(): void
    {
        $this->assertClassifies('https://example.com/some/article', 'unknown', 'post');
        $this->assertClassifies('not even a url', 'unknown', 'post');
        $this->assertClassifies('', 'unknown', 'post');
    }

    // ── (2) account_feed_url: pure conversions + Apify/unknown → null ────

    public function test_account_feed_url_youtube_channel_id_is_pure(): void
    {
        $url = 'https://www.youtube.com/channel/UC_x5XG1OV2P6uZZ5FSM9Ttw';
        $this->assertSame(
            'https://www.youtube.com/feeds/videos.xml?channel_id=UC_x5XG1OV2P6uZZ5FSM9Ttw',
            PCM_Social_Source::account_feed_url($url, PCM_Social_Source::classify($url))
        );
    }

    public function test_account_feed_url_youtube_handle_degrades_to_null_without_http_layer(): void
    {
        // No wp_remote_get here → the handle resolve must degrade to null, not throw.
        $url = 'https://www.youtube.com/@mkbhd';
        $this->assertNull(PCM_Social_Source::account_feed_url($url, PCM_Social_Source::classify($url)));
    }

    public function test_account_feed_url_bluesky_and_reddit(): void
    {
        $bsky = 'https://bsky.app/profile/pfrazee.com';
        $this->assertSame(
            'https://bsky.app/profile/pfrazee.com/rss',
            PCM_Social_Source::account_feed_url($bsky, PCM_Social_Source::classify($bsky))
        );
        $sub = 'https://www.reddit.com/r/php/'; // trailing slash stripped before /.rss
        $this->assertSame(
            'https://www.reddit.com/r/php/.rss',
            PCM_Social_Source::account_feed_url($sub, PCM_Social_Source::classify($sub))
        );
        $user = 'https://www.reddit.com/user/spez';
        $this->assertSame(
            'https://www.reddit.com/user/spez/.rss',
            PCM_Social_Source::account_feed_url($user, PCM_Social_Source::classify($user))
        );
    }

    public function test_account_feed_url_null_for_apify_platforms_and_unknown(): void
    {
        foreach (array(
            'https://www.instagram.com/natgeo'  => 'instagram',
            'https://www.tiktok.com/@khaby.lame' => 'tiktok',
            'https://x.com/jack'                 => 'x',
            'https://www.facebook.com/meta'      => 'facebook',
            'https://example.com/blog'           => 'unknown',
        ) as $url => $platform) {
            $c = PCM_Social_Source::classify($url);
            $this->assertSame($platform, $c['platform']);
            $this->assertNull(PCM_Social_Source::account_feed_url($url, $c), "no native feed for {$platform}");
        }
    }

    // ── (3) post_context: the always-works URL-label fallback ────────────

    public function test_post_context_always_returns_all_three_keys_with_url_label(): void
    {
        // No oEmbed/HTTP layer here → straight to the URL-derived label.
        $ctx = PCM_Social_Source::post_context('https://www.instagram.com/p/Cxyz123AbCd/');
        $this->assertSame(array('title', 'author', 'text'), array_keys($ctx));
        $this->assertNotSame('', $ctx['title'], 'there is ALWAYS a usable title');
        $this->assertSame('', $ctx['author']);
        $this->assertSame('', $ctx['text']);

        $ctx = PCM_Social_Source::post_context('https://x.com/jack/status/20');
        $this->assertSame('X post by jack', $ctx['title']);

        $ctx = PCM_Social_Source::post_context('https://example.com/some/article');
        $this->assertSame('example.com/some/article', $ctx['title']);
    }

    // ── (4) apify_request: default map shapes + filter override ──────────

    public function test_apify_request_instagram_shape(): void
    {
        $spec = PCM_Social_Source::apify_request('instagram', 'https://www.instagram.com/natgeo', 10);
        $this->assertSame('apify~instagram-scraper', $spec['actor']);
        $this->assertSame(array('https://www.instagram.com/natgeo'), $spec['input']['directUrls']);
        $this->assertSame('posts', $spec['input']['resultsType']);
        $this->assertSame(10, $spec['input']['resultsLimit']);
    }

    public function test_apify_request_tiktok_shape_uses_at_handle(): void
    {
        $spec = PCM_Social_Source::apify_request('tiktok', 'https://www.tiktok.com/@khaby.lame', 5);
        $this->assertSame('clockworks~tiktok-scraper', $spec['actor']);
        $this->assertSame(array('@khaby.lame'), $spec['input']['profiles']);
        $this->assertSame(5, $spec['input']['resultsPerPage']);
        $this->assertTrue($spec['input']['excludePinnedPosts']);
    }

    public function test_apify_request_x_shape(): void
    {
        $spec = PCM_Social_Source::apify_request('x', 'https://x.com/jack', 10);
        $this->assertSame('apidojo~tweet-scraper', $spec['actor']);
        $this->assertSame(array('https://x.com/jack'), $spec['input']['startUrls']);
        $this->assertSame(10, $spec['input']['maxItems']);
    }

    public function test_apify_request_facebook_shape(): void
    {
        $spec = PCM_Social_Source::apify_request('facebook', 'https://www.facebook.com/meta', 10);
        $this->assertSame('apify~facebook-posts-scraper', $spec['actor']);
        $this->assertSame(array(array('url' => 'https://www.facebook.com/meta')), $spec['input']['startUrls']);
        $this->assertSame(10, $spec['input']['resultsLimit']);
    }

    /**
     * App share links carry tracking suffixes (IG "Share → Copy link" appends
     * ?igsh=…) and the instagram-scraper input validator REJECTS query strings
     * with HTTP 400 invalid-input — the exact prod "Scan now returned HTTP 400"
     * failure. The request builder must strip query + fragment for every
     * URL-targeted platform EXCEPT facebook, whose profile.php?id=123 account
     * URLs carry their identity in the query.
     */
    public function test_apify_request_strips_tracking_query_from_share_links(): void
    {
        // Instagram account share link → clean directUrls entry.
        $ig = PCM_Social_Source::apify_request('instagram', 'https://www.instagram.com/hailthegame?igsh=MXRxc2t4bA==', 10);
        $this->assertSame(array('https://www.instagram.com/hailthegame'), $ig['input']['directUrls']);

        // Instagram post link (the enrichment path) — same stripping, trailing slash trimmed.
        $post = PCM_Social_Source::apify_request('instagram', 'https://www.instagram.com/p/Da5ynsiuAZ_/?igsh=abc&utm_source=share', 1);
        $this->assertSame(array('https://www.instagram.com/p/Da5ynsiuAZ_'), $post['input']['directUrls']);

        // X share links (?s=20) → clean startUrls entry.
        $x = PCM_Social_Source::apify_request('x', 'https://x.com/naval/status/1002103360646823936?s=20&t=xyz', 5);
        $this->assertSame(array('https://x.com/naval/status/1002103360646823936'), $x['input']['startUrls']);

        // Facebook keeps its query — profile.php?id=123 IS the account identity.
        $fb = PCM_Social_Source::apify_request('facebook', 'https://www.facebook.com/profile.php?id=100044279661280', 10);
        $this->assertSame(array(array('url' => 'https://www.facebook.com/profile.php?id=100044279661280')), $fb['input']['startUrls']);
    }

    public function test_apify_request_null_for_free_platforms(): void
    {
        $this->assertNull(PCM_Social_Source::apify_request('youtube', 'https://www.youtube.com/@mkbhd', 10));
        $this->assertNull(PCM_Social_Source::apify_request('bluesky', 'https://bsky.app/profile/x.com', 10));
        $this->assertNull(PCM_Social_Source::apify_request('reddit', 'https://www.reddit.com/r/php', 10));
        $this->assertNull(PCM_Social_Source::apify_request('unknown', 'https://example.com', 10));
    }

    public function test_apify_request_actor_map_is_filter_overridable(): void
    {
        \WP_Mock::setUp();
        try {
            // Schema drift on a community actor = a config fix via the filter, not a code fix.
            \WP_Mock::onFilter('pcm_apify_actor_map')->withAnyArgs()->reply(array(
                'instagram' => array(
                    'actor' => 'someone~drift-proof-ig-scraper',
                    'input' => array('directUrls' => array('https://www.instagram.com/natgeo')),
                ),
            ));
            $spec = PCM_Social_Source::apify_request('instagram', 'https://www.instagram.com/natgeo', 10);
            $this->assertSame('someone~drift-proof-ig-scraper', $spec['actor']);
            $this->assertNull(PCM_Social_Source::apify_request('x', 'https://x.com/jack', 10), 'platforms dropped by the filtered map yield null');
        } finally {
            \WP_Mock::tearDown();
        }
    }

    // ── (5) apify_map_items: realistic fixtures + degradation ────────────

    public function test_map_items_instagram_realistic_and_shortcode_fallback(): void
    {
        $caption = str_repeat('A very long Instagram caption. ', 60); // > 1000 chars
        $out = PCM_Social_Source::apify_map_items('instagram', array(
            array('id' => '318', 'url' => 'https://www.instagram.com/p/Cxyz/', 'caption' => $caption, 'timestamp' => '2026-07-01T12:00:00.000Z'),
            array('shortCode' => 'AbCd123', 'caption' => ''), // no url, no caption → shortCode permalink + generic title
            array('caption' => 'orphan with nothing dedupeable'),  // no permalink AND no id → skipped
        ));

        $this->assertCount(2, $out);
        $this->assertSame(array('permalink', 'id', 'title', 'date', 'text'), array_keys($out[0]));
        $this->assertSame('https://www.instagram.com/p/Cxyz/', $out[0]['permalink']);
        $this->assertSame('318', $out[0]['id']);
        $this->assertLessThanOrEqual(90, strlen($out[0]['title']), 'title derives from the first ~90 caption chars');
        $this->assertSame(strtotime('2026-07-01T12:00:00.000Z'), $out[0]['date'], 'ISO date → unix ts');
        $this->assertLessThanOrEqual(1000, strlen($out[0]['text']), 'text capped at 1000');

        $this->assertSame('https://www.instagram.com/p/AbCd123/', $out[1]['permalink'], 'shortCode → canonical permalink');
        $this->assertSame('AbCd123', $out[1]['id'], 'shortCode doubles as the id');
        $this->assertSame('Instagram post', $out[1]['title'], 'captionless items get the generic title');
    }

    public function test_map_items_tiktok_with_numeric_createtime_fallback(): void
    {
        $out = PCM_Social_Source::apify_map_items('tiktok', array(
            array('id' => '7137', 'webVideoUrl' => 'https://www.tiktok.com/@k/video/7137', 'text' => 'Learning to fly', 'createTimeISO' => '2026-07-02T09:30:00Z'),
            array('id' => '7138', 'webVideoUrl' => 'https://www.tiktok.com/@k/video/7138', 'desc' => 'desc-field drift', 'createTime' => 1780000000),
        ));

        $this->assertCount(2, $out);
        $this->assertSame('Learning to fly', $out[0]['title']);
        $this->assertSame(strtotime('2026-07-02T09:30:00Z'), $out[0]['date']);
        $this->assertSame('desc-field drift', $out[1]['text'], 'text falls back to desc');
        $this->assertSame(1780000000, $out[1]['date'], 'numeric createTime passes through');
    }

    public function test_map_items_x_with_fulltext_fallback(): void
    {
        $out = PCM_Social_Source::apify_map_items('x', array(
            array('id' => '20', 'url' => 'https://x.com/jack/status/20', 'text' => 'just setting up my twttr', 'createdAt' => 'Tue Mar 21 20:50:14 +0000 2006'),
            array('id' => '21', 'twitterUrl' => 'https://twitter.com/jack/status/21', 'fullText' => 'fullText drift variant'),
        ));

        $this->assertCount(2, $out);
        $this->assertSame('just setting up my twttr', $out[0]['text']);
        $this->assertSame(strtotime('Tue Mar 21 20:50:14 +0000 2006'), $out[0]['date']);
        $this->assertSame('https://twitter.com/jack/status/21', $out[1]['permalink'], 'permalink falls back to twitterUrl');
        $this->assertSame('fullText drift variant', $out[1]['text'], 'text falls back to fullText');
        $this->assertSame(0, $out[1]['date'], 'missing date degrades to 0, not a throw');
    }

    public function test_map_items_facebook_field_chains_and_skip(): void
    {
        $out = PCM_Social_Source::apify_map_items('facebook', array(
            array('postId' => '101', 'url' => 'https://www.facebook.com/meta/posts/101', 'message' => 'message-field drift', 'time' => '2026-07-03 10:00:00'),
            array('id' => '102', 'topLevelUrl' => 'https://www.facebook.com/meta/posts/102', 'text' => 'text field wins', 'timestamp' => 1780100000),
            array('message' => 'no permalink, no id'), // skipped
        ));

        $this->assertCount(2, $out);
        $this->assertSame('message-field drift', $out[0]['text']);
        $this->assertSame('101', $out[0]['id']);
        $this->assertSame('https://www.facebook.com/meta/posts/102', $out[1]['permalink'], 'permalink falls back through postUrl → topLevelUrl');
        $this->assertSame(1780100000, $out[1]['date']);
    }

    public function test_map_items_unknown_platform_and_garbage_rows_yield_empty(): void
    {
        $this->assertSame(array(), PCM_Social_Source::apify_map_items('youtube', array(array('id' => '1'))));
        $this->assertSame(array(), PCM_Social_Source::apify_map_items('instagram', array('not-an-array', 42)));
    }
}
