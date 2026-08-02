<?php
/**
 * Apify Client — social account watching for Social strategies.
 *
 * Thin, hook-free client around Apify's synchronous actor-run endpoint
 * (`POST /v2/acts/{actor}/run-sync-get-dataset-items`). The RSS/social
 * watcher uses it to pull the latest posts of a watched social account
 * (Instagram, TikTok, X, Facebook) as dataset items; the {actor, input}
 * request shape is built elsewhere (PCM_Social_Source::apify_request) —
 * this class only resolves the user's Apify token and executes the call.
 *
 * IMPORTANT — cron/background context ONLY: fetch_account_items() holds the
 * HTTP connection for up to 120 seconds while Apify runs the actor. It runs
 * exclusively inside the strategy watcher (WP-cron / keepalive background
 * tick) and must NEVER be called from a user-facing request path.
 *
 * Isolation contract: mirrors PCM_Topic_Suggester — a fetch failure must
 * NEVER break the watcher. Every path returns an empty array on any problem
 * (missing token, WP_Error, non-2xx, malformed JSON) and error_log()s the
 * reason — the caller treats an empty array as "no items".
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Apify
{
    /**
     * Short human-readable reason for the LAST fetch_account_items() failure
     * in this request, or null when the last fetch succeeded. Written on every
     * degradation path (missing token, WP_Error, non-2xx, bad JSON) so the
     * manual "Scan now" diagnostics can tell the admin WHY zero items came
     * back instead of a silent empty array. Same-request read only.
     *
     * @var string|null
     */
    public static ?string $last_error = null;

    /**
     * Look up the user's active Apify API token from the integrations table.
     *
     * Mirrors PCM_LLM::get_api_key()'s prepared query (provider + userId +
     * isActive, newest first) for the 'apify' provider — but returns '' when
     * no active integration exists instead of throwing. The $user_id is the
     * owner-scoped PCM user id passed in by the watcher (cron context has no
     * meaningful get_current_user_id()).
     *
     * @param int $user_id Owner PCM/WP user ID.
     * @return string The API token, or '' when none is configured.
     */
    public static function get_token(int $user_id): string
    {
        // Workspace-scoped (own key, else an admin's) — see
        // PCM_Access::workspace_api_key. A strategy owned by a platform id/pass
        // user found no token and every social scan silently pulled 0 items.
        $result = class_exists('PCM_Access')
            ? PCM_Access::workspace_api_key('apify', $user_id)
            : null;

        return $result ?? '';
    }

    /**
     * Whether the user has an active Apify token configured.
     *
     * @param int $user_id Owner PCM/WP user ID.
     * @return bool
     */
    public static function has_key(int $user_id): bool
    {
        return self::get_token($user_id) !== '';
    }

    /**
     * Run an Apify actor synchronously and return its dataset items.
     *
     * CRON/BACKGROUND CONTEXT ONLY (the watcher) — never a user-facing
     * request: the run-sync call blocks up to 120s while the actor scrapes.
     *
     * @param array $request {
     *     Request built by PCM_Social_Source::apify_request().
     *
     *     @type string $actor Actor ID (e.g. 'apify~instagram-scraper').
     *     @type array  $input Actor input payload (sent as the JSON body).
     * }
     * @param int   $user_id Owner PCM/WP user ID whose Apify token is used.
     * @return array<int, array> Decoded dataset items. Empty array on ANY failure.
     */
    public static function fetch_account_items(array $request, int $user_id): array
    {
        self::$last_error = null;
        $token = self::get_token($user_id);
        if ($token === '') {
            self::$last_error = 'No active Apify API key for this user — add it under Integrations.';
            error_log(sprintf(
                '[PCM_Apify] No active Apify token for user %d — skipping social account fetch.',
                $user_id
            ));
            return array();
        }

        $actor = trim((string) ($request['actor'] ?? ''));
        $input = is_array($request['input'] ?? null) ? $request['input'] : array();
        if ($actor === '') {
            error_log('[PCM_Apify] fetch_account_items called without an actor — skipping.');
            return array();
        }

        // run-sync holds the connection while the actor runs (Apify cap ~5 min);
        // timeout=110 makes Apify abort the wait before our own 120s HTTP timeout.
        $url = sprintf(
            'https://api.apify.com/v2/acts/%s/run-sync-get-dataset-items?timeout=110&format=json',
            rawurlencode($actor)
        );

        $response = wp_remote_post($url, array(
            'headers'   => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'      => wp_json_encode($input),
            // MUST exceed the URL's own timeout=110 server-side cap, or the HTTP
            // client aborts before Apify returns and the pass ingests ZERO items.
            // A 10-result Instagram account scan routinely runs ~40-60s, so a 45s
            // client cap was silently timing out real scans. Safe to wait this
            // long: BACKGROUND-only (cron/keepalive), lastSocialScan is stamped
            // BEFORE the fetch (no billing loop on a mid-pass kill), and each
            // account carries its own @set_time_limit(120).
            'timeout'   => 120,
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            self::$last_error = 'Apify request failed: ' . $response->get_error_message();
            error_log(sprintf(
                '[PCM_Apify] Actor %s request failed: %s',
                $actor,
                $response->get_error_message()
            ));
            return array();
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            // Apify error bodies carry {"error":{"message":"…"}} — the message
            // names the actual problem (invalid input, run failed, plan limit),
            // so surface it in the Scan-now toast instead of a bare code.
            $decoded  = json_decode($body, true);
            $api_msg  = is_array($decoded) ? trim((string)($decoded['error']['message'] ?? '')) : '';
            self::$last_error = $api_msg !== ''
                ? sprintf('Apify returned HTTP %d: %s', $code, substr($api_msg, 0, 180))
                : sprintf('Apify returned HTTP %d.', $code);
            error_log(sprintf(
                '[PCM_Apify] Actor %s returned HTTP %d: %s',
                $actor,
                $code,
                substr($body, 0, 200)
            ));
            return array();
        }

        $items = json_decode($body, true);
        if (!is_array($items)) {
            self::$last_error = 'Apify returned an unreadable response.';
            error_log(sprintf(
                '[PCM_Apify] Actor %s returned non-array JSON: %s',
                $actor,
                substr($body, 0, 200)
            ));
            return array();
        }

        return $items;
    }
}
