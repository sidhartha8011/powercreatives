<?php
/**
 * Social Media Source Helper — classification, feed conversion, Apify mapping.
 *
 * Pure/side-effect-isolated helpers behind the Source=Social strategies:
 *
 *   - classify()          PURE. Pasted link → {platform, kind: post|account}.
 *   - account_feed_url()  Free-platform account link → native feed URL the
 *                         existing RSS watcher can consume (YouTube channel
 *                         XML, Bluesky /rss, Reddit /.rss). The only impure
 *                         edge is the YouTube handle→channelId page resolve
 *                         (wp_remote_get, timeout 5, isolated try/catch).
 *   - post_context()      Best-effort {title, author, text} for a single post
 *                         link (oEmbed → og: meta → URL-derived label). NEVER
 *                         throws — a context failure must not break create.
 *   - apify_request()     PURE. Apify platform → {actor, input} request spec
 *                         per the default actor map (overridable via the
 *                         `pcm_apify_actor_map` filter — community actor
 *                         schemas drift, so drift is a config fix).
 *   - apify_map_items()   PURE. Raw Apify dataset items → the RSS watcher's
 *                         ingest shape {permalink, id, title, date, text}
 *                         (see PCM_Strategy_Service::fetch_rss_feed_items())
 *                         with defensive per-platform field fallback chains.
 *
 * Isolation contract: mirrors PCM_Topic_Suggester — no method here may throw
 * into a caller; network edges degrade to null/empty and the pure seams are
 * unit-tested without WordPress.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Social_Source
{
    /** Reserved first-path-segments that are never an Instagram username. */
    private const INSTAGRAM_RESERVED = array('explore', 'accounts', 'stories', 'direct', 'about', 'developer', 'legal');

    /** Reserved first-path-segments that are never an X/Twitter username. */
    private const X_RESERVED = array('home', 'explore', 'search', 'i', 'hashtag', 'notifications', 'messages', 'settings', 'login', 'signup', 'intent', 'share');

    /**
     * Classify a pasted social link. PURE — string in, array out.
     *
     * @param string $url Any pasted URL.
     * @return array{platform: string, kind: string} platform ∈ youtube|bluesky|reddit|instagram|tiktok|x|facebook|unknown, kind ∈ post|account.
     *                                               Unknown hosts are treated as a generic post link — never rejected.
     */
    public static function classify(string $url): array
    {
        $host = self::host($url);
        $seg  = self::segments($url);

        // ── YouTube ──
        if ($host === 'youtu.be') {
            return array('platform' => 'youtube', 'kind' => 'post');
        }
        if (self::host_is($host, 'youtube.com')) {
            $first = $seg[0] ?? '';
            if ($first !== '' && $first[0] === '@') {
                return array('platform' => 'youtube', 'kind' => 'account');
            }
            if (in_array($first, array('channel', 'c', 'user'), true)) {
                return array('platform' => 'youtube', 'kind' => 'account');
            }
            return array('platform' => 'youtube', 'kind' => 'post'); // watch/shorts/live/embed/…
        }

        // ── Bluesky ──
        if (self::host_is($host, 'bsky.app')) {
            if (($seg[0] ?? '') === 'profile' && isset($seg[1])) {
                $kind = (($seg[2] ?? '') === 'post' && isset($seg[3])) ? 'post' : 'account';
                return array('platform' => 'bluesky', 'kind' => $kind);
            }
            return array('platform' => 'bluesky', 'kind' => 'post');
        }

        // ── Reddit ──
        if (self::host_is($host, 'reddit.com') || $host === 'redd.it') {
            if (in_array('comments', $seg, true) || $host === 'redd.it') {
                return array('platform' => 'reddit', 'kind' => 'post');
            }
            if (in_array($seg[0] ?? '', array('r', 'user', 'u'), true) && isset($seg[1])) {
                return array('platform' => 'reddit', 'kind' => 'account');
            }
            return array('platform' => 'reddit', 'kind' => 'post');
        }

        // ── Instagram ──
        if (self::host_is($host, 'instagram.com')) {
            if (in_array($seg[0] ?? '', array('p', 'reel', 'reels', 'tv'), true)) {
                return array('platform' => 'instagram', 'kind' => 'post');
            }
            if (count($seg) === 1 && !in_array($seg[0], self::INSTAGRAM_RESERVED, true)) {
                return array('platform' => 'instagram', 'kind' => 'account');
            }
            return array('platform' => 'instagram', 'kind' => 'post');
        }

        // ── TikTok ──
        if ($host === 'vm.tiktok.com' || $host === 'vt.tiktok.com') {
            return array('platform' => 'tiktok', 'kind' => 'post');
        }
        if (self::host_is($host, 'tiktok.com')) {
            $first = $seg[0] ?? '';
            if ($first !== '' && $first[0] === '@') {
                return array('platform' => 'tiktok', 'kind' => count($seg) === 1 ? 'account' : 'post');
            }
            return array('platform' => 'tiktok', 'kind' => 'post'); // /t/{short}, embeds, …
        }

        // ── X / Twitter ──
        if (self::host_is($host, 'x.com') || self::host_is($host, 'twitter.com')) {
            if (($seg[1] ?? '') === 'status') {
                return array('platform' => 'x', 'kind' => 'post');
            }
            if (count($seg) === 1 && !in_array($seg[0], self::X_RESERVED, true)) {
                return array('platform' => 'x', 'kind' => 'account');
            }
            return array('platform' => 'x', 'kind' => 'post');
        }

        // ── Facebook ──
        if ($host === 'fb.watch') {
            return array('platform' => 'facebook', 'kind' => 'post');
        }
        if (self::host_is($host, 'facebook.com') || self::host_is($host, 'fb.com')) {
            foreach (array('posts', 'videos', 'reel', 'reels', 'watch', 'photo', 'photo.php', 'story.php', 'permalink.php') as $marker) {
                if (in_array($marker, $seg, true)) {
                    return array('platform' => 'facebook', 'kind' => 'post');
                }
            }
            if (($seg[0] ?? '') === 'profile.php' && strpos((string)(parse_url($url, PHP_URL_QUERY) ?? ''), 'id=') !== false) {
                return array('platform' => 'facebook', 'kind' => 'account');
            }
            if (count($seg) === 1) {
                return array('platform' => 'facebook', 'kind' => 'account');
            }
            return array('platform' => 'facebook', 'kind' => 'post');
        }

        // ── Anything else: generic post link — never reject. ──
        return array('platform' => 'unknown', 'kind' => 'post');
    }

    /**
     * Convert a FREE-platform account link into a native feed URL the existing
     * RSS watcher can consume. Returns null when the platform needs Apify
     * (instagram/tiktok/x/facebook) or is unknown — the caller routes those
     * to config.socialAccounts instead.
     *
     * YouTube /channel/UC… converts purely; /@handle, /c/…, /user/… need one
     * page fetch to resolve the channelId (wp_remote_get, timeout 5, isolated
     * try/catch — any failure → null, never a throw).
     *
     * @param string $url The account URL as pasted.
     * @param array  $c   The classify() result for $url ({platform, kind}).
     * @return string|null Feed URL, or null when not convertible.
     */
    public static function account_feed_url(string $url, array $c): ?string
    {
        $platform = (string)($c['platform'] ?? '');
        $seg      = self::segments($url);

        if ($platform === 'youtube') {
            if (($seg[0] ?? '') === 'channel' && preg_match('/^(UC[0-9A-Za-z_-]{22})$/', (string)($seg[1] ?? ''), $m)) {
                return 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $m[1];
            }
            $channel_id = self::resolve_youtube_channel_id($url);
            return $channel_id !== '' ? 'https://www.youtube.com/feeds/videos.xml?channel_id=' . $channel_id : null;
        }

        if ($platform === 'bluesky') {
            if (($seg[0] ?? '') === 'profile' && ($seg[1] ?? '') !== '') {
                return 'https://bsky.app/profile/' . $seg[1] . '/rss';
            }
            return null;
        }

        if ($platform === 'reddit') {
            // Rebuild from parsed parts: Reddit share links carry query
            // strings (?utm_source=share) that would otherwise end up INSIDE
            // the feed URL and break it (adversarial-review finding).
            $parts = parse_url($url);
            $host  = (string)($parts['host'] ?? 'www.reddit.com');
            $path  = rtrim((string)($parts['path'] ?? ''), '/');
            if ($path === '') {
                return null;
            }
            return 'https://' . $host . $path . '/.rss';
        }

        return null; // instagram/tiktok/x/facebook → Apify; unknown → no feed
    }

    /**
     * Best-effort context for a single post link: {title, author, text}.
     * Order: core oEmbed → og:/twitter: meta scrape → URL-derived label.
     * NEVER throws; every key is always present (empty string when unknown).
     *
     * @param string $url Post URL.
     * @return array{title: string, author: string, text: string}
     */
    public static function post_context(string $url, bool $network = true): array
    {
        $title  = '';
        $author = '';
        $text   = '';

        // ── 1) WordPress core oEmbed (YouTube/TikTok/Bluesky/Reddit/X have providers). ──
        // $network=false skips both network tiers (oEmbed + meta scrape) — the
        // create-path's time budget uses this so a many-link paste never
        // stalls; the URL-derived label below always yields a usable title.
        try {
            if ($network && function_exists('_wp_oembed_get_object')) {
                $oembed = _wp_oembed_get_object();
                if (is_object($oembed) && method_exists($oembed, 'get_data')) {
                    $data = $oembed->get_data($url);
                    if (is_object($data)) {
                        $title  = (string)($data->title ?? '');
                        $author = (string)($data->author_name ?? '');
                    }
                }
            }
        } catch (\Throwable $e) {
            // isolated — fall through to the meta scrape
        }

        // ── 2) og:/twitter: meta scrape of the page head. ──
        if ($network && ($title === '' || $text === '')) {
            try {
                $html = self::fetch_body($url);
                if ($html !== '') {
                    if ($title === '') {
                        $title = self::extract_meta($html, 'og:title');
                    }
                    if ($title === '') {
                        $title = self::extract_meta($html, 'twitter:title');
                    }
                    $text = self::extract_meta($html, 'og:description');
                    if ($text === '') {
                        $text = self::extract_meta($html, 'twitter:description');
                    }
                }
            } catch (\Throwable $e) {
                // isolated — fall through to the URL label
            }
        }

        // ── 3) URL-derived label — there is ALWAYS a usable title. ──
        if ($title === '') {
            $c      = self::classify($url);
            $handle = self::handle_from_url($url, $c['platform']);
            if ($c['platform'] !== 'unknown' && $handle !== '') {
                $label = $c['platform'] === 'x' ? 'X' : ucfirst($c['platform']);
                $title = $label . ' post by ' . $handle;
            } else {
                $parts = parse_url($url);
                $title = trim((string)($parts['host'] ?? '') . (string)($parts['path'] ?? ''));
                if ($title === '') {
                    $title = $url;
                }
            }
        }

        return array(
            'title'  => self::clean($title, 200),
            'author' => self::clean($author, 200),
            'text'   => self::clean($text, 1000),
        );
    }

    /**
     * Build the Apify run request spec {actor, input} for an Apify-watched
     * platform. PURE — the HTTP client (PCM_Apify) consumes this spec.
     *
     * The default actor map is overridable via the `pcm_apify_actor_map`
     * filter so community-actor schema drift is a config fix, not a code fix.
     *
     * @param string $platform classify() platform id.
     * @param string $url      The watched account URL.
     * @param int    $limit    Max items per scan (cost control).
     * @return array{actor: string, input: array}|null Null for non-Apify platforms.
     */
    public static function apify_request(string $platform, string $url, int $limit): ?array
    {
        $limit = max(1, $limit);
        $url   = self::strip_tracking_query($platform, $url);

        $map = array(
            'instagram' => array(
                'actor' => 'apify~instagram-scraper',
                'input' => array(
                    'directUrls'   => array($url),
                    'resultsType'  => 'posts',
                    'resultsLimit' => $limit,
                ),
            ),
            'tiktok' => array(
                'actor' => 'clockworks~tiktok-scraper',
                'input' => array(
                    'profiles'           => array(self::handle_from_url($url, 'tiktok')),
                    'resultsPerPage'     => $limit,
                    'excludePinnedPosts' => true,
                ),
            ),
            'x' => array(
                'actor' => 'apidojo~tweet-scraper',
                'input' => array(
                    'startUrls' => array($url),
                    'maxItems'  => $limit,
                ),
            ),
            'facebook' => array(
                'actor' => 'apify~facebook-posts-scraper',
                'input' => array(
                    'startUrls'    => array(array('url' => $url)),
                    'resultsLimit' => $limit,
                ),
            ),
        );

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('pcm_apify_actor_map', $map);
            if (is_array($filtered)) {
                $map = $filtered;
            }
        }

        $spec = $map[$platform] ?? null;
        return (is_array($spec) && isset($spec['actor'], $spec['input'])) ? $spec : null;
    }

    /**
     * Strip the query string + fragment from a social URL before it becomes
     * Apify actor input.
     *
     * WHY: links copied from the apps carry tracking suffixes — Instagram's
     * "Share → Copy link" appends `?igsh=…` — and the instagram-scraper's
     * input validator REJECTS any directUrls entry with a query string
     * (HTTP 400 invalid-input), which silently killed every scan of a
     * strategy whose stored link was a share link. The query never carries
     * identity on these platforms, so scheme://host/path is always the same
     * account/post.
     *
     * Facebook is the one exception and is returned untouched: its
     * `profile.php?id=123` account URLs carry the identity IN the query.
     *
     * @param string $platform Classified platform slug.
     * @param string $url      The URL as stored.
     * @return string The URL without query/fragment (Facebook: unchanged).
     */
    public static function strip_tracking_query(string $platform, string $url): string
    {
        if ($platform === 'facebook') {
            return $url;
        }
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host'])) {
            return $url;
        }
        $scheme = isset($p['scheme']) && $p['scheme'] !== '' ? $p['scheme'] : 'https';
        $path   = isset($p['path']) ? rtrim((string)$p['path'], '/') : '';
        return $scheme . '://' . $p['host'] . $path;
    }

    /**
     * Map raw Apify dataset items into the RSS watcher's ingest shape
     * {permalink, id, title, date, text} (fetch_rss_feed_items()'s shape plus
     * `text`). PURE + defensive: community actor fields drift, so every field
     * reads through a ??-fallback chain; entries with neither a resolvable
     * permalink nor an id are skipped (nothing dedupeable).
     *
     * @param string $platform instagram|tiktok|x|facebook.
     * @param array  $raw      Decoded Apify dataset items.
     * @return array<int, array{permalink: string, id: string, title: string, date: int, text: string}>
     */
    public static function apify_map_items(string $platform, array $raw): array
    {
        $out = array();
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $permalink = '';
            $id        = '';
            $title     = '';
            $date_raw  = null;
            $text      = '';

            switch ($platform) {
                case 'instagram':
                    $short     = (string)($item['shortCode'] ?? $item['shortcode'] ?? '');
                    $permalink = (string)($item['url'] ?? ($short !== '' ? 'https://www.instagram.com/p/' . $short . '/' : ''));
                    $id        = (string)($item['id'] ?? $short);
                    $text      = (string)($item['caption'] ?? '');
                    $title     = $text !== '' ? self::first_chars($text, 90) : 'Instagram post';
                    $date_raw  = $item['timestamp'] ?? null;
                    break;

                case 'tiktok':
                    $permalink = (string)($item['webVideoUrl'] ?? $item['url'] ?? '');
                    $id        = (string)($item['id'] ?? '');
                    $text      = (string)($item['text'] ?? $item['desc'] ?? '');
                    $title     = $text !== '' ? self::first_chars($text, 90) : 'TikTok post';
                    $date_raw  = $item['createTimeISO'] ?? $item['createTime'] ?? null;
                    break;

                case 'x':
                    $permalink = (string)($item['url'] ?? $item['twitterUrl'] ?? '');
                    $id        = (string)($item['id'] ?? '');
                    $text      = (string)($item['text'] ?? $item['fullText'] ?? '');
                    $title     = $text !== '' ? self::first_chars($text, 90) : 'X post';
                    $date_raw  = $item['createdAt'] ?? null;
                    break;

                case 'facebook':
                    $permalink = (string)($item['url'] ?? $item['postUrl'] ?? $item['topLevelUrl'] ?? '');
                    $id        = (string)($item['postId'] ?? $item['id'] ?? '');
                    $text      = (string)($item['text'] ?? $item['message'] ?? '');
                    $title     = $text !== '' ? self::first_chars($text, 90) : 'Facebook post';
                    $date_raw  = $item['time'] ?? $item['timestamp'] ?? null;
                    break;

                default:
                    continue 2; // not an Apify platform — nothing to map
            }

            if ($permalink === '' && $id === '') {
                continue; // nothing dedupeable — skip
            }

            $out[] = array(
                'permalink' => $permalink,
                'id'        => $id,
                'title'     => self::clean($title, 200),
                'date'      => self::to_timestamp($date_raw),
                'text'      => self::clean($text, 1000),
            );
        }
        return $out;
    }

    // ── Internals ────────────────────────────────────────────────────────

    /** Lowercased host with a leading www./m. stripped. */
    private static function host($url): string
    {
        $host = strtolower((string)(parse_url(trim((string)$url), PHP_URL_HOST) ?? ''));
        return (string)preg_replace('/^(www\.|m\.)/', '', $host);
    }

    /** True when $host is $base or any subdomain of it. */
    private static function host_is(string $host, string $base): bool
    {
        return $host === $base || substr($host, -strlen('.' . $base)) === '.' . $base;
    }

    /** Non-empty path segments of $url. */
    private static function segments($url): array
    {
        $path = (string)(parse_url(trim((string)$url), PHP_URL_PATH) ?? '');
        return array_values(array_filter(explode('/', $path), static function ($s) {
            return $s !== '';
        }));
    }

    /**
     * Best-effort handle/label from an account or post URL. TikTok handles
     * keep their @ (the Apify tiktok actor takes @-prefixed profiles).
     */
    private static function handle_from_url(string $url, string $platform): string
    {
        $seg = self::segments($url);
        switch ($platform) {
            case 'tiktok':
                foreach ($seg as $s) {
                    if ($s !== '' && $s[0] === '@') {
                        return $s;
                    }
                }
                return isset($seg[0]) ? '@' . $seg[0] : '';
            case 'bluesky':
                return (($seg[0] ?? '') === 'profile') ? (string)($seg[1] ?? '') : '';
            case 'reddit':
                return in_array($seg[0] ?? '', array('r', 'user', 'u'), true) ? implode('/', array_slice($seg, 0, 2)) : (string)($seg[0] ?? '');
            case 'youtube':
                if (($seg[0] ?? '') !== '' && $seg[0][0] === '@') {
                    return $seg[0];
                }
                return in_array($seg[0] ?? '', array('channel', 'c', 'user'), true) ? (string)($seg[1] ?? '') : (string)($seg[0] ?? '');
            default:
                $first = (string)($seg[0] ?? '');
                return in_array($first, array('p', 'reel', 'reels', 'tv', 'watch', 'posts', 'videos', 'status'), true) ? '' : $first;
        }
    }

    /** Resolve a YouTube /@handle|/c/|/user/ page to its UC… channelId ('' on any failure). */
    private static function resolve_youtube_channel_id(string $url): string
    {
        try {
            $html = self::fetch_body($url);
            if ($html !== '' && preg_match('/"channelId":"(UC[0-9A-Za-z_-]{22})"/', $html, $m)) {
                return $m[1];
            }
        } catch (\Throwable $e) {
            // isolated — resolution is best-effort
        }
        return '';
    }

    /** Fetch a URL body via wp_remote_get (timeout 5). '' on any failure; guarded for test contexts. */
    private static function fetch_body(string $url): string
    {
        if (!function_exists('wp_remote_get')) {
            return '';
        }
        $response = wp_remote_get($url, array(
            'timeout'     => 5,
            'redirection' => 3,
            'user-agent'  => 'Mozilla/5.0 (compatible; PowerCreatives)',
        ));
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return '';
        }
        if (function_exists('wp_remote_retrieve_body')) {
            return (string)wp_remote_retrieve_body($response);
        }
        return is_array($response) ? (string)($response['body'] ?? '') : '';
    }

    /** Extract a <meta property|name="…" content="…"> value (either attribute order). */
    private static function extract_meta(string $html, string $key): string
    {
        $quoted = preg_quote($key, '/');
        $patterns = array(
            '/<meta[^>]+(?:property|name)=["\']' . $quoted . '["\'][^>]*content=["\']([^"\']*)["\']/i',
            '/<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']' . $quoted . '["\']/i',
        );
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                return html_entity_decode((string)$m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return '';
    }

    /** sanitize_text_field (when available) + multibyte-safe cap. */
    private static function clean($value, int $max): string
    {
        $value = (string)$value;
        if (function_exists('sanitize_text_field')) {
            $value = (string)sanitize_text_field($value);
        } else {
            $value = trim((string)preg_replace('/[\r\n\t ]+/', ' ', strip_tags($value)));
        }
        return self::first_chars($value, $max);
    }

    /** Multibyte-safe prefix. */
    private static function first_chars(string $value, int $max): string
    {
        $value = trim($value);
        if (function_exists('mb_substr')) {
            return trim((string)mb_substr($value, 0, $max));
        }
        return trim(substr($value, 0, $max));
    }

    /** Loose date → unix timestamp int (numeric passthrough, strtotime for strings, 0 when unparseable). */
    private static function to_timestamp($value): int
    {
        if (is_int($value) || is_float($value)) {
            return (int)$value;
        }
        if (is_string($value) && $value !== '') {
            if (is_numeric($value)) {
                return (int)$value;
            }
            $ts = strtotime($value);
            return $ts !== false ? (int)$ts : 0;
        }
        return 0;
    }
}
