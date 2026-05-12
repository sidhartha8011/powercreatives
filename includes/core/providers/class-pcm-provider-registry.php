<?php
/**
 * Provider Registry
 *
 * Factory for creating provider instances. Controllers call this
 * instead of hard-coding provider switches.
 *
 * Usage:
 *   $provider = PCM_Provider_Registry::get('openai', $api_key);
 *   $result   = $provider->generate_image($params);
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Provider_Registry
{

    /** Map of provider ID → class name. */
    private const PROVIDERS = [
        'openai' => 'PCM_Provider_OpenAI',
        'google' => 'PCM_Provider_Google',
        'kieai' => 'PCM_Provider_KieAI',
        'fal' => 'PCM_Provider_Fal',
    ];

    /**
     * Get a provider instance by ID.
     *
     * @param string $provider_id Provider ID (e.g. 'openai', 'google', 'kieai').
     * @param string $api_key     API key for the provider.
     * @return PCM_Provider_Interface
     * @throws \RuntimeException If provider is not registered.
     */
    public static function get(string $provider_id, string $api_key): PCM_Provider_Interface
    {
        $class = self::PROVIDERS[$provider_id] ?? null;

        if (!$class || !class_exists($class)) {
            throw new \RuntimeException("Unknown provider: {$provider_id}");
        }

        return new $class($api_key);
    }

    /**
     * Check if a provider is registered.
     *
     * @param string $provider_id Provider ID.
     * @return bool
     */
    public static function has(string $provider_id): bool
    {
        return isset(self::PROVIDERS[$provider_id]);
    }

    /**
     * List all registered provider IDs.
     *
     * @return string[]
     */
    public static function list_ids(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /**
     * Find the right provider for a given model ID.
     *
     * @deprecated Provider should come from the frontend (model.provider from DB).
     *             This method exists only as a temporary fallback for legacy callers.
     *             New code must NEVER use this — see docs/architecture-vision.md
     *             → "Provider Routing — Data-Driven".
     *
     * @param string $model_id Model ID.
     * @return string Provider ID.
     */
    public static function detect_provider(string $model_id): string
    {
        // Log deprecation warning so we can identify remaining callers
        error_log('[PCM DEPRECATED] detect_provider() called for model: ' . $model_id
            . '. Provider should be sent explicitly by the frontend. '
            . 'See docs/architecture-vision.md → "Provider Routing — Data-Driven".');

        if (PCM_Kie_Api::is_kie_model($model_id)) {
            return 'kieai';
        }

        // Delegate to existing LLM provider detection for OpenAI/Google
        if (class_exists('PCM_LLM') && method_exists('PCM_LLM', 'detect_provider')) {
            return PCM_LLM::detect_provider($model_id);
        }

        // Fallback heuristics (kept for backwards compatibility)
        if (str_starts_with($model_id, 'dall-e') || str_starts_with($model_id, 'gpt')) {
            return 'openai';
        }
        if (str_starts_with($model_id, 'imagen') || str_starts_with($model_id, 'gemini') || str_starts_with($model_id, 'veo')) {
            return 'google';
        }

        return 'openai'; // Safe default
    }
}
