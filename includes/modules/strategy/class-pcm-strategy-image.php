<?php
/**
 * Strategy Featured-Image Generator
 *
 * AutoPress-parity featured image generation at article-creation time. A thin,
 * fully failure-isolated wrapper around the existing image-provider infra
 * (PCM_Provider_Registry): resolve provider/model, look up the provider's API
 * key from the integrations table, ask the provider for one image, and hand
 * back its URL.
 *
 * Isolation contract: an image failure must NEVER fail or delay article
 * generation. Every path here returns null on any problem (no key, provider
 * throw, empty URL) and error_log()s the reason — the caller stores null in the
 * article's featuredImage column and moves on.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Strategy_Image
{
    /**
     * Generate a featured image and return its URL, or null on ANY failure.
     *
     * @param string $prompt   Photo-style prompt describing the desired image.
     * @param int    $user_id  PCM/WP user ID whose integration API key is used.
     * @param string $provider Provider ID; empty → 'openai'.
     * @param string $model    Model ID; empty → 'gpt-image-1-mini' (dall-e-3
     *                         is retired on current OpenAI accounts).
     * @return string|null Image URL on success, null on any failure.
     */
    public static function generate(string $prompt, int $user_id, string $provider = '', string $model = ''): ?string
    {
        try {
            $provider = $provider !== '' ? $provider : 'openai';
            $model    = $model !== '' ? $model : 'gpt-image-1-mini';

            $api_key = self::get_api_key($provider, $user_id);
            if ($api_key === null || $api_key === '') {
                error_log(sprintf(
                    '[PCM_Strategy_Image] No active API key for provider "%s" (user #%d) — skipping featured image.',
                    $provider,
                    $user_id
                ));
                return null;
            }

            $instance = PCM_Provider_Registry::get($provider, $api_key);
            $result   = $instance->generate_image($model, array('prompt' => $prompt));
            $url      = is_array($result) ? (string)($result['url'] ?? '') : '';

            if ($url === '') {
                error_log(sprintf(
                    '[PCM_Strategy_Image] Provider "%s" returned no image URL for model "%s" — skipping featured image.',
                    $provider,
                    $model
                ));
                return null;
            }

            return $url;
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[PCM_Strategy_Image] Featured image generation failed: %s',
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Look up the active API key for a provider from the integrations table.
     * Same query shape as PCM_LLM::get_api_key() (which is private to PCM_LLM):
     * the newest active key for provider+user. Returns null (not a throw) when
     * none exists, so the caller can degrade cleanly.
     *
     * @param string $provider Provider ID.
     * @param int    $user_id  User ID.
     * @return string|null The API key, or null when none is active.
     */
    private static function get_api_key(string $provider, int $user_id): ?string
    {
        global $wpdb;

        $table  = PCM_Schema::table('integrations');
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT apiKey FROM $table WHERE provider = %s AND userId = %d AND isActive = 1 ORDER BY updatedAt DESC LIMIT 1",
            $provider,
            $user_id
        ));

        return empty($result) ? null : (string)$result;
    }
}
