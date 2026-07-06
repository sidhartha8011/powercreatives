<?php
/**
 * AI Provider Registry
 *
 * PHP port of server/providers/index.ts.
 * Central registry for all AI providers (Google, OpenAI, Anthropic, Kie.ai, Manus).
 * Handles provider lookup, capability detection, and API key validation.
 *
 * Each provider entry defines:
 * - id, name, apiKeyUrl — metadata for UI
 * - knownModels — pre-defined models with capabilities
 * - isBuiltIn — whether the provider is platform-managed
 * - validateEndpoint — URL to validate API keys
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Providers
{

    /**
     * Known provider configurations.
     * Mirrors the Node.js provider registry (server/providers/).
     *
     * @var array<string, array>
     */
    private static array $registry = array(
        'manus' => array(
            'id' => 'manus',
            'name' => 'Manus (Built-in)',
            'apiKeyUrl' => null,
            'isBuiltIn' => true,
            'knownModels' => array(),
        ),
        'google' => array(
            'id' => 'google',
            'name' => 'Google AI',
            'apiKeyUrl' => 'https://aistudio.google.com/app/apikey',
            'isBuiltIn' => false,
            'knownModels' => array(
                // Matches TS google.ts knownModels — fallback if API parse fails
                    array('id' => 'gemini-2.0-flash-exp-image-generation', 'name' => 'Gemini 2.0 Flash Image', 'type' => 'image'),
                    array('id' => 'imagen-4.0-generate-001', 'name' => 'Imagen 4', 'type' => 'image'),
                    array('id' => 'veo-2.0-generate-001', 'name' => 'Veo 2', 'type' => 'video'),
                    array('id' => 'gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash', 'type' => 'text'),
            ),
        ),
        'openai' => array(
            'id' => 'openai',
            'name' => 'OpenAI',
            'apiKeyUrl' => 'https://platform.openai.com/api-keys',
            'isBuiltIn' => false,
            'knownModels' => array(
                // Image models — matches TS openai.ts knownModels
                    array('id' => 'dall-e-3', 'name' => 'DALL-E 3', 'type' => 'image'),
                    array('id' => 'dall-e-2', 'name' => 'DALL-E 2', 'type' => 'image'),
                    array('id' => 'gpt-4o-image', 'name' => 'GPT-4o Image', 'type' => 'image'),
                // Text models
                    array('id' => 'gpt-4o', 'name' => 'GPT-4o', 'type' => 'text', 'costTier' => 'standard'),
                    array('id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini', 'type' => 'text', 'costTier' => 'budget'),
                    array('id' => 'gpt-4-turbo', 'name' => 'GPT-4 Turbo', 'type' => 'text', 'costTier' => 'standard'),
                    array('id' => 'o3-mini', 'name' => 'o3 Mini', 'type' => 'text', 'costTier' => 'budget'),
            ),
        ),
        'anthropic' => array(
            'id' => 'anthropic',
            'name' => 'Anthropic (Claude)',
            'apiKeyUrl' => 'https://console.anthropic.com/settings/keys',
            'isBuiltIn' => false,
            'knownModels' => array(
                    array('id' => 'claude-3-5-sonnet-20241022', 'name' => 'Claude 3.5 Sonnet', 'type' => 'text'),
                    array('id' => 'claude-3-5-haiku-20241022', 'name' => 'Claude 3.5 Haiku', 'type' => 'text'),
                    array('id' => 'claude-3-opus-20240229', 'name' => 'Claude 3 Opus', 'type' => 'text'),
            ),
        ),
        'fal' => array(
            'id' => 'fal',
            'name' => 'Fal.ai',
            'apiKeyUrl' => 'https://fal.ai/dashboard/keys',
            'isBuiltIn' => false,
            'knownModels' => array(
                    array('id' => 'fal-nano-banana-pro', 'name' => 'Nano Banana Pro', 'type' => 'image'),
                    array('id' => 'fal-nano-banana-2', 'name' => 'Nano Banana 2', 'type' => 'image'),
                    array('id' => 'fal-flux-kontext', 'name' => 'Flux Kontext', 'type' => 'image'),
                    array('id' => 'fal-flux-2-flex', 'name' => 'Flux 2 Flex', 'type' => 'image'),
                    array('id' => 'fal-recraft-v4', 'name' => 'Recraft V4 Pro', 'type' => 'image'),
                    array('id' => 'fal-ideogram-v3', 'name' => 'Ideogram V3', 'type' => 'image'),
            ),
        ),
        'kieai' => array(
            'id' => 'kieai',
            'name' => 'Kie.ai',
            'apiKeyUrl' => 'https://kie.ai/dashboard',
            'isBuiltIn' => false,
            'knownModels' => array(
                // --- Dedicated API models (not in marketplace) ---
                    array('id' => 'kie-gpt-4o-image', 'name' => 'GPT-4o Image', 'type' => 'image'),
                    array('id' => 'kie-flux-kontext', 'name' => 'Flux Kontext', 'type' => 'image'),
                    array('id' => 'kie-runway-gen3', 'name' => 'Runway Gen-3', 'type' => 'video'),
                    array('id' => 'kie-veo-3.1', 'name' => 'Veo 3.1 Fast', 'type' => 'video'),
                    array('id' => 'kie-veo-3.1-quality', 'name' => 'Veo 3.1 Quality', 'type' => 'video'),

                // --- Marketplace Image Models ---
                // Seedream
                    array('id' => 'kie-seedream-3', 'name' => 'Seedream 3.0', 'type' => 'image'),
                    array('id' => 'kie-seedream-4-t2i', 'name' => 'Seedream 4.0', 'type' => 'image'),
                    array('id' => 'kie-seedream-4-edit', 'name' => 'Seedream 4.0 Edit', 'type' => 'image'),
                    array('id' => 'kie-seedream-4.5-t2i', 'name' => 'Seedream 4.5', 'type' => 'image'),
                    array('id' => 'kie-seedream-4.5-edit', 'name' => 'Seedream 4.5 Edit', 'type' => 'image'),
                // Z-Image
                    array('id' => 'kie-z-image', 'name' => 'Z-Image', 'type' => 'image'),
                // Google
                    array('id' => 'kie-imagen4', 'name' => 'Imagen 4', 'type' => 'image'),
                    array('id' => 'kie-imagen4-fast', 'name' => 'Imagen 4 Fast', 'type' => 'image'),
                    array('id' => 'kie-imagen4-ultra', 'name' => 'Imagen 4 Ultra', 'type' => 'image'),
                    array('id' => 'kie-nano-banana', 'name' => 'Nano Banana', 'type' => 'image'),
                    array('id' => 'kie-nano-banana-edit', 'name' => 'Nano Banana Edit', 'type' => 'image'),
                    array('id' => 'kie-nano-banana-pro-i2i', 'name' => 'Nano Banana Pro I2I', 'type' => 'image'),
                // Flux 2
                    array('id' => 'kie-flux2-pro-t2i', 'name' => 'Flux 2 Pro', 'type' => 'image'),
                    array('id' => 'kie-flux2-pro-i2i', 'name' => 'Flux 2 Pro I2I', 'type' => 'image'),
                    array('id' => 'kie-flux2-flex-t2i', 'name' => 'Flux 2 Flex', 'type' => 'image'),
                    array('id' => 'kie-flux2-flex-i2i', 'name' => 'Flux 2 Flex I2I', 'type' => 'image'),
                // Grok Imagine
                    array('id' => 'kie-grok-imagine-t2i', 'name' => 'Grok Imagine', 'type' => 'image'),
                    array('id' => 'kie-grok-imagine-i2i', 'name' => 'Grok Imagine I2I', 'type' => 'image'),
                    array('id' => 'kie-grok-imagine-upscale', 'name' => 'Grok Imagine Upscale', 'type' => 'image'),
                // GPT Image 1.5
                    array('id' => 'kie-gpt-image-1.5-t2i', 'name' => 'GPT Image 1.5', 'type' => 'image'),
                    array('id' => 'kie-gpt-image-1.5-i2i', 'name' => 'GPT Image 1.5 I2I', 'type' => 'image'),
                // GPT Image 2
                    array('id' => 'kie-gpt-image-2-t2i', 'name' => 'GPT Image 2', 'type' => 'image'),
                    array('id' => 'kie-gpt-image-2-i2i', 'name' => 'GPT Image 2 I2I', 'type' => 'image'),
                // Ideogram
                    array('id' => 'kie-ideogram-character', 'name' => 'Ideogram Character', 'type' => 'image'),
                    array('id' => 'kie-ideogram-character-edit', 'name' => 'Ideogram Character Edit', 'type' => 'image'),
                    array('id' => 'kie-ideogram-v3-reframe', 'name' => 'Ideogram V3 Reframe', 'type' => 'image'),
                // Qwen
                    array('id' => 'kie-qwen-t2i', 'name' => 'Qwen T2I', 'type' => 'image'),
                    array('id' => 'kie-qwen-i2i', 'name' => 'Qwen I2I', 'type' => 'image'),
                    array('id' => 'kie-qwen-edit', 'name' => 'Qwen Image Edit', 'type' => 'image'),
                // Recraft
                    array('id' => 'kie-recraft-crisp-upscale', 'name' => 'Recraft Crisp Upscale', 'type' => 'image'),
                    array('id' => 'kie-recraft-remove-bg', 'name' => 'Recraft Remove BG', 'type' => 'image'),
                // Topaz
                    array('id' => 'kie-topaz-upscale', 'name' => 'Topaz Image Upscale', 'type' => 'image'),

                // --- Marketplace Video Models ---
                // Kling
                    array('id' => 'kie-kling-2.6-t2v', 'name' => 'Kling 2.6 T2V', 'type' => 'video'),
                    array('id' => 'kie-kling-2.6-i2v', 'name' => 'Kling 2.6 I2V', 'type' => 'video'),
                    array('id' => 'kie-kling-3.0', 'name' => 'Kling 3.0', 'type' => 'video'),
                // Sora 2
                    array('id' => 'kie-sora2-t2v', 'name' => 'Sora 2 T2V', 'type' => 'video'),
                    array('id' => 'kie-sora2-i2v', 'name' => 'Sora 2 I2V', 'type' => 'video'),
                    array('id' => 'kie-sora2-pro-t2v', 'name' => 'Sora 2 Pro T2V', 'type' => 'video'),
                    array('id' => 'kie-sora2-pro-i2v', 'name' => 'Sora 2 Pro I2V', 'type' => 'video'),
                // Bytedance
                    array('id' => 'kie-bytedance-seedance-1.5-pro', 'name' => 'Seedance 1.5 Pro', 'type' => 'video'),
                    array('id' => 'kie-bytedance-v1-pro-t2v', 'name' => 'Bytedance V1 Pro T2V', 'type' => 'video'),
                    array('id' => 'kie-bytedance-v1-pro-i2v', 'name' => 'Bytedance V1 Pro I2V', 'type' => 'video'),
                // Hailuo
                    array('id' => 'kie-hailuo-02-t2v-pro', 'name' => 'Hailuo 02 T2V Pro', 'type' => 'video'),
                    array('id' => 'kie-hailuo-02-i2v-pro', 'name' => 'Hailuo 02 I2V Pro', 'type' => 'video'),
                    array('id' => 'kie-hailuo-2.3-i2v-pro', 'name' => 'Hailuo 2.3 I2V Pro', 'type' => 'video'),
                // Wan
                    array('id' => 'kie-wan-2.6-t2v', 'name' => 'Wan 2.6 T2V', 'type' => 'video'),
                    array('id' => 'kie-wan-2.6-i2v', 'name' => 'Wan 2.6 I2V', 'type' => 'video'),
                // Grok Imagine Video
                    array('id' => 'kie-grok-imagine-t2v', 'name' => 'Grok Imagine T2V', 'type' => 'video'),
                    array('id' => 'kie-grok-imagine-i2v', 'name' => 'Grok Imagine I2V', 'type' => 'video'),

                // --- Marketplace Audio Models (mapped to video type for ProviderModel compat) ---
                    array('id' => 'kie-elevenlabs-tts-turbo', 'name' => 'ElevenLabs TTS Turbo 2.5', 'type' => 'video'),
                    array('id' => 'kie-elevenlabs-tts-multilingual', 'name' => 'ElevenLabs Multilingual V2', 'type' => 'video'),
                    array('id' => 'kie-elevenlabs-dialogue', 'name' => 'ElevenLabs Dialogue V3', 'type' => 'video'),
                    array('id' => 'kie-elevenlabs-sfx', 'name' => 'ElevenLabs Sound Effects V2', 'type' => 'video'),
            ),
        ),
        'ahrefs' => array(
            'id'          => 'ahrefs',
            'name'        => 'Ahrefs',
            'apiKeyUrl'   => 'https://app.ahrefs.com/user/api',
            'isBuiltIn'   => false,
            // Ahrefs provides SEO tools (volume, difficulty, SERP) — not AI generation models.
            // Tools are discovered dynamically via MCP handshake, so knownModels is empty.
            'knownModels' => array(),
            // Custom capabilities flag — not an AI provider, but an SEO data provider
            'supportsSeo' => true,
        ),
        'proranktracker' => array(
            'id'          => 'proranktracker',
            'name'        => 'ProRankTracker',
            'apiKeyUrl'   => 'https://app.proranktracker.com/api-doc',
            'isBuiltIn'   => false,
            // ProRankTracker provides rank-tracking data (URL/term rankings,
            // history, SERP dashboards) — not AI generation models.
            'knownModels' => array(),
            // Custom capabilities flag — an SEO data provider like Ahrefs
            'supportsSeo' => true,
        ),
        'gsc' => array(
            'id'          => 'gsc',
            'name'        => 'Google Search Console',
            'apiKeyUrl'   => 'https://console.cloud.google.com/iam-admin/serviceaccounts',
            'isBuiltIn'   => false,
            // GSC supplies search-performance data (clicks/impressions/CTR/position/queries)
            // for the SEO table — not AI models. The "API key" is a service-account JSON file.
            'knownModels' => array(),
            // Custom capabilities flag — an SEO data provider like Ahrefs/ProRankTracker
            'supportsSeo' => true,
        ),
        'brevo' => array(
            'id'          => 'brevo',
            'name'        => 'Brevo (Email)',
            'apiKeyUrl'   => 'https://app.brevo.com/settings/keys/api',
            'isBuiltIn'   => false,
            // Brevo sends transactional email for the Automations module — it is
            // not an AI generation provider, so knownModels is empty.
            'knownModels' => array(),
            'supportsEmail' => true,
        ),
    );

    /**
     * Get all registered providers.
     *
     * @return array<string, array>
     */
    public static function get_all(): array
    {
        return self::$registry;
    }

    /**
     * Get a single provider by ID.
     *
     * @param string $provider_id Provider key (e.g. 'google').
     * @return array|null Provider config or null.
     */
    public static function get(string $provider_id): ?array
    {
        return self::$registry[$provider_id] ?? null;
    }

    /**
     * Check if a provider ID is registered.
     *
     * @param string $provider_id Provider key.
     * @return bool
     */
    public static function is_known(string $provider_id): bool
    {
        return isset(self::$registry[$provider_id]);
    }

    /**
     * Check if a provider is built-in (platform-managed, cannot be deleted).
     *
     * @param string $provider_id Provider key.
     * @return bool
     */
    public static function is_built_in(string $provider_id): bool
    {
        $provider = self::get($provider_id);
        return $provider && ($provider['isBuiltIn'] ?? false);
    }

    /**
     * Get provider info formatted for the React frontend UI.
     *
     * @return array Array of provider display objects.
     */
    public static function get_for_ui(): array
    {
        $providers = array();

        foreach (self::$registry as $provider) {
            $models = $provider['knownModels'] ?? array();
            $providers[] = array(
                'id' => $provider['id'],
                'name' => $provider['name'],
                'apiKeyUrl' => $provider['apiKeyUrl'] ?? null,
                'supportsImage' => self::has_model_type($models, 'image'),
                'supportsVideo' => self::has_model_type($models, 'video'),
                'supportsText' => self::has_model_type($models, 'text'),
                'supportsVision' => self::has_model_type($models, 'text'), // Vision via multimodal text
                'isBuiltIn' => $provider['isBuiltIn'] ?? false,
            );
        }

        return $providers;
    }

    /**
     * Get all provider details including known models list.
     *
     * @return array Array of provider detail objects.
     */
    public static function get_all_details(): array
    {
        return array_values(array_map(function ($p) {
            return array(
                'id' => $p['id'],
                'name' => $p['name'],
                'apiKeyUrl' => $p['apiKeyUrl'] ?? null,
                'knownModels' => $p['knownModels'] ?? array(),
            );
        }, self::$registry));
    }

    /**
     * Validate an API key by calling the provider's API.
     *
     * Uses wp_remote_get/wp_remote_post to test common endpoints.
     * Returns validation result with capabilities and discovered models.
     *
     * @param string $provider_id Provider key.
     * @param string $api_key     API key to validate.
     * @return array{ valid: bool, error?: string, capabilities: array, models: array }
     */
    public static function validate_api_key(string $provider_id, string $api_key): array
    {
        $provider = self::get($provider_id);

        if (!$provider) {
            return array(
                'valid' => false,
                'error' => "Unknown provider: {$provider_id}",
                'capabilities' => array(),
                'models' => array(),
            );
        }

        // Provider-specific validation endpoints
        $validation_map = array(
            'google'    => 'https://generativelanguage.googleapis.com/v1beta/models?key=',
            'openai'    => 'https://api.openai.com/v1/models',
            'anthropic' => 'https://api.anthropic.com/v1/models',
            'kieai'     => 'https://api.kie.ai/api/v1/jobs/createTask',
            'fal'       => 'https://api.fal.ai/v1/models',
            'ahrefs'    => 'https://api.ahrefs.com/mcp/mcp',
            'brevo'     => 'https://api.brevo.com/v3/account',
            'proranktracker' => 'https://api.proranktracker.com/v3/user/quota',
        );

        // Built-in providers don't need validation
        if (self::is_built_in($provider_id)) {
            return array(
                'valid' => true,
                'capabilities' => array('image' => true, 'video' => true, 'text' => true, 'vision' => true),
                'models' => $provider['knownModels'] ?? array(),
            );
        }

        $url = $validation_map[$provider_id] ?? null;

        if (!$url) {
            // Unknown provider — return known models without validation
            return array(
                'valid' => true,
                'capabilities' => array('image' => false, 'video' => false, 'text' => false, 'vision' => false),
                'models' => $provider['knownModels'] ?? array(),
            );
        }

        return self::call_validation_endpoint($provider_id, $api_key, $url);
    }

    /**
     * Call a provider's validation endpoint to verify the API key.
     *
     * @param string $provider_id Provider key.
     * @param string $api_key     API key.
     * @param string $url         Validation endpoint URL.
     * @return array Validation result.
     */
    private static function call_validation_endpoint(string $provider_id, string $api_key, string $url): array
    {
        $headers = array(
            'Content-Type' => 'application/json',
        );

        // --- Kie.ai: POST-based validation (matches TypeScript kieai.ts) ---
        if ('kieai' === $provider_id) {
            return self::validate_kieai_key($api_key);
        }

        // --- Fal.ai: GET /v1/models — free auth check, no image generation cost ---
        if ('fal' === $provider_id) {
            return self::validate_fal_key($api_key);
        }

        // --- Ahrefs: MCP JSON-RPC initialize handshake ---
        if ('ahrefs' === $provider_id) {
            return self::validate_ahrefs_key($api_key);
        }

        // --- Brevo: GET /v3/account with the api-key header ---
        if ('brevo' === $provider_id) {
            return self::validate_brevo_key($api_key);
        }

        // --- ProRankTracker: GET /v3/user/quota with the X-TOKEN header ---
        if ('proranktracker' === $provider_id) {
            return self::validate_proranktracker_key($api_key);
        }

        // --- Google Search Console: the "key" is a service-account JSON file ---
        if ('gsc' === $provider_id) {
            return self::validate_gsc_key($api_key);
        }

        // Provider-specific auth headers
        if ('google' === $provider_id) {
            $url .= $api_key; // Google uses query param
        }
        elseif ('anthropic' === $provider_id) {
            $headers['x-api-key'] = $api_key;
            $headers['anthropic-version'] = '2023-06-01';
        }
        else {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array(
                'valid' => false,
                'error' => $response->get_error_message(),
                'capabilities' => array(),
                'models' => array(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // API key is valid if we get a 200 OK
        $valid = ($code >= 200 && $code < 300);

        if (!$valid) {
            return array(
                'valid' => false,
                'error' => "API key validation failed (HTTP {$code})",
                'capabilities' => array(),
                'models' => array(),
            );
        }

        // Try to extract discovered models from the response
        $discovered_models = self::extract_models_from_response($provider_id, $body);

        // Use known models as fallback if no models discovered
        $provider = self::get($provider_id);
        $models = !empty($discovered_models) ? $discovered_models : ($provider['knownModels'] ?? array());

        return array(
            'valid' => true,
            'capabilities' => array(
                'image' => self::has_model_type($models, 'image'),
                'video' => self::has_model_type($models, 'video'),
                'text' => self::has_model_type($models, 'text'),
                'vision' => self::has_model_type($models, 'text'),
            ),
            'models' => $models,
        );
    }

    /**
     * Validate a Fal.ai API key using GET /v1/models.
     *
     * Uses the free models listing endpoint — no image generation cost.
     * Returns 200 with valid key, 401/403 with invalid.
     *
     * @param string $api_key Fal.ai API key (format: key_id:key_secret).
     * @return array Validation result.
     */
    private static function validate_fal_key(string $api_key): array
    {
        $url = 'https://api.fal.ai/v1/models?limit=1';

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Key ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array(
                'valid' => false,
                'error' => 'Connection failed: ' . $response->get_error_message(),
                'capabilities' => array('image' => false, 'video' => false, 'text' => false, 'vision' => false),
                'models' => array(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);

        // 401 = invalid key, 403 = wrong scope
        if ($code === 401 || $code === 403) {
            return array(
                'valid' => false,
                'error' => 'Invalid API key — please check your Fal.ai API key',
                'capabilities' => array('image' => false, 'video' => false, 'text' => false, 'vision' => false),
                'models' => array(),
            );
        }

        // Key is valid — return known models (Fal.ai has no dynamic model listing we need)
        $provider = self::get('fal');
        $models = $provider['knownModels'] ?? array();

        return array(
            'valid' => true,
            'capabilities' => array(
                'image' => true,
                'video' => false,
                'text' => false,
                'vision' => false,
            ),
            'models' => $models,
        );
    }

    /**
     * Validate a Kie.ai API key using POST to /jobs/createTask.
     *
     * Kie.ai does not expose a simple GET /models endpoint.
     * Instead, we send a minimal createTask request and check the response code.
     * - code 401 → invalid key
     * - any other response → key is valid (auth passed)
     *
     * PHP port of kieai.ts validateApiKey().
     *
     * @param string $api_key Kie.ai API key.
     * @return array Validation result.
     */
    private static function validate_kieai_key(string $api_key): array
    {
        $url = 'https://api.kie.ai/api/v1/jobs/createTask';

        // Send a minimal probe task — the model and prompt don't matter,
        // we only care whether auth passes or returns 401.
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body' => wp_json_encode(array(
                'model' => 'flux-2/pro-text-to-image',
                'input' => array('prompt' => '__validation_probe__'),
            )),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array(
                'valid' => false,
                'error' => $response->get_error_message(),
                'capabilities' => array('image' => false, 'video' => false, 'text' => false, 'vision' => false),
                'models' => array(),
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Kie.ai returns code 401 in the JSON body for invalid keys
        if (isset($body['code']) && 401 === (int)$body['code']) {
            return array(
                'valid' => false,
                'error' => 'Invalid API key — please check your Kie.ai API key',
                'capabilities' => array('image' => false, 'video' => false, 'text' => false, 'vision' => false),
                'models' => array(),
                'rawResponse' => $body,
            );
        }

        // Key is valid — auth passed regardless of other response codes.
        // Kie.ai uses known models (no dynamic model listing endpoint).
        $provider = self::get('kieai');
        $models = $provider['knownModels'] ?? array();

        return array(
            'valid' => true,
            'capabilities' => array(
                'image' => true,
                'video' => true,
                'text' => false,
                'vision' => false,
            ),
            'models' => $models,
            'rawResponse' => $body,
        );
    }

    /**
     * Validate an Ahrefs API key using MCP JSON-RPC 'initialize' handshake.
     *
     * Sends a JSON-RPC 2.0 'initialize' request to https://api.ahrefs.com/mcp/mcp.
     * Bearer token auth + Accept: application/json forces HTTP transport (not SSE).
     * If auth passes, the server returns capabilities and server info.
     *
     * PHP port of autopress-intelligence ahrefsService.ts McpClient.initialize().
     *
     * @param string $api_key Ahrefs API bearer token.
     * @return array Validation result.
     */
    private static function validate_ahrefs_key(string $api_key): array
    {
        $url = 'https://api.ahrefs.com/mcp/mcp';

        $payload = array(
            'jsonrpc' => '2.0',
            'method'  => 'initialize',
            'params'  => array(
                'protocolVersion' => '2024-11-05',
                'capabilities'    => array(
                    'roots'    => array('listChanged' => true),
                    'sampling' => new \stdClass(),
                ),
                'clientInfo' => array(
                    'name'    => 'PowerCreatives',
                    'version' => '1.0.0',
                ),
            ),
            'id' => 1,
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'Accept'        => 'application/json',
            ),
            'body'    => wp_json_encode($payload),
            'timeout' => 20,
        ));

        if (is_wp_error($response)) {
            return array(
                'valid'        => false,
                'error'        => 'Connection failed: ' . $response->get_error_message(),
                'capabilities' => array('image' => false, 'video' => false, 'text' => false, 'vision' => false),
                'models'       => array(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Unauthorized — invalid API key
        if ($code === 401 || $code === 403) {
            return array(
                'valid'        => false,
                'error'        => 'Invalid Ahrefs API key — check your key at https://app.ahrefs.com/user/api',
                'capabilities' => array('image' => false, 'video' => false, 'text' => false, 'vision' => false),
                'models'       => array(),
            );
        }

        // JSON-RPC error in response body
        if (isset($body['error'])) {
            return array(
                'valid'        => false,
                'error'        => 'Ahrefs MCP error: ' . ($body['error']['message'] ?? 'Unknown'),
                'capabilities' => array('image' => false, 'video' => false, 'text' => false, 'vision' => false),
                'models'       => array(),
            );
        }

        // Success — MCP handshake passed, key is valid
        return array(
            'valid'        => true,
            'capabilities' => array(
                'image'  => false,
                'video'  => false,
                'text'   => false,
                'vision' => false,
                'seo'    => true, // Ahrefs provides SEO data, not AI generation
            ),
            'models' => array(),
        );
    }

    /**
     * Validate a Brevo API key using GET /v3/account.
     *
     * Brevo authenticates transactional-email requests with an `api-key`
     * header (not Bearer). A 200 from /v3/account confirms the key works;
     * 401 indicates an invalid key. Brevo has no AI models, so a valid key
     * reports the `email` capability only.
     *
     * @param string $api_key Brevo API v3 key.
     * @return array Validation result.
     */
    private static function validate_brevo_key(string $api_key): array
    {
        $response = wp_remote_get('https://api.brevo.com/v3/account', array(
            'headers' => array(
                'api-key' => $api_key,
                'Accept'  => 'application/json',
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array(
                'valid'        => false,
                'error'        => 'Connection failed: ' . $response->get_error_message(),
                'capabilities' => array('image' => false, 'video' => false, 'text' => false, 'vision' => false),
                'models'       => array(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code === 401 || $code === 403) {
            return array(
                'valid'        => false,
                'error'        => 'Invalid Brevo API key — check your key at https://app.brevo.com/settings/keys/api',
                'capabilities' => array('image' => false, 'video' => false, 'text' => false, 'vision' => false),
                'models'       => array(),
            );
        }

        if ($code < 200 || $code >= 300) {
            return array(
                'valid'        => false,
                'error'        => "Brevo API key validation failed (HTTP {$code})",
                'capabilities' => array('image' => false, 'video' => false, 'text' => false, 'vision' => false),
                'models'       => array(),
            );
        }

        return array(
            'valid'        => true,
            'capabilities' => array(
                'image'  => false,
                'video'  => false,
                'text'   => false,
                'vision' => false,
                'email'  => true,
            ),
            'models' => array(),
        );
    }

    /**
     * Validate a ProRankTracker API token using GET /v3/user/quota.
     *
     * ProRankTracker authenticates with a Personal Access Token in the
     * `X-TOKEN` header (not Bearer). The quota endpoint is free, has no
     * side effects, and returns {"result":"success"} with a valid token;
     * 401/403 (or "result":"error") indicates an invalid token. PRT has
     * no AI models, so a valid token reports the `seo` capability only.
     *
     * @param string $api_key ProRankTracker Personal Access Token.
     * @return array Validation result.
     */
    private static function validate_proranktracker_key(string $api_key): array
    {
        $response = wp_remote_get('https://api.proranktracker.com/v3/user/quota', array(
            'headers' => array(
                'X-TOKEN' => $api_key,
                'Accept'  => 'application/json',
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array(
                'valid'        => false,
                'error'        => 'Connection failed: ' . $response->get_error_message(),
                'capabilities' => array('image' => false, 'video' => false, 'text' => false, 'vision' => false),
                'models'       => array(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 401 || $code === 403) {
            return array(
                'valid'        => false,
                'error'        => 'Invalid ProRankTracker token — check your token in the API section at https://app.proranktracker.com',
                'capabilities' => array('image' => false, 'video' => false, 'text' => false, 'vision' => false),
                'models'       => array(),
            );
        }

        // PRT signals failures in the JSON body too: {"result":"error", ...}
        if ($code < 200 || $code >= 300 || ($body['result'] ?? '') !== 'success') {
            return array(
                'valid'        => false,
                'error'        => 'ProRankTracker token validation failed (HTTP ' . $code . '): ' . ($body['error_message'] ?? 'Unknown error'),
                'capabilities' => array('image' => false, 'video' => false, 'text' => false, 'vision' => false),
                'models'       => array(),
            );
        }

        return array(
            'valid'        => true,
            'capabilities' => array(
                'image'  => false,
                'video'  => false,
                'text'   => false,
                'vision' => false,
                'seo'    => true, // PRT provides rank-tracking data, not AI generation
            ),
            'models' => array(),
        );
    }

    /**
     * Validate a Google Search Console service-account JSON: parse it, then mint a REAL
     * access token (proves the private key signs and Google accepts the account). Property
     * access is checked later per pull — a fresh service account with zero properties is
     * still a valid credential.
     *
     * @param string $api_key The full service-account JSON file contents.
     * @return array Validation result.
     */
    private static function validate_gsc_key(string $api_key): array
    {
        $no_caps = array('image' => false, 'video' => false, 'text' => false, 'vision' => false);
        $creds   = PCM_GSC::parse_credentials($api_key);
        if (is_wp_error($creds)) {
            return array('valid' => false, 'error' => $creds->get_error_message(), 'capabilities' => $no_caps, 'models' => array());
        }
        $token = PCM_GSC::access_token($api_key);
        if (is_wp_error($token)) {
            return array('valid' => false, 'error' => $token->get_error_message(), 'capabilities' => $no_caps, 'models' => array());
        }
        return array(
            'valid'        => true,
            'capabilities' => array_merge($no_caps, array('seo' => true)), // search data, not AI generation
            'models'       => array(),
        );
    }

    /**
     * Extract model list from a provider's API response.
     *
     * PHP port of google.ts parseModels() and openai.ts parseModels().
     * Includes full 3-way classification (image/video/text), exclusion
     * patterns, de-duplication, and friendly display name generation.
     *
     * @param string     $provider_id Provider key.
     * @param array|null $body        Response body.
     * @return array Array of model objects.
     */
    /** Humanize an unrecognized OpenAI chat model id into a display name (snapshots collapsed). */
    private static function humanize_openai_id(string $id): string
    {
        // Drop dated/numeric snapshot + preview/latest suffixes so variants collapse to one.
        $base = (string) preg_replace('/-(\d{4}-\d{2}-\d{2}|\d{3,8}|preview|latest)$/', '', $id);
        $base = rtrim($base, '-');
        if ($base === '') {
            $base = $id;
        }
        if (preg_match('/^o\d/', $base)) {
            // o-series: "o5-mini" → "o5 Mini"
            return (string) preg_replace_callback('/-(\w)/', static fn ($m) => ' ' . strtoupper($m[1]), $base);
        }
        $words = array();
        foreach (explode('-', $base) as $w) {
            if ($w === '') {
                continue;
            }
            if ($w === 'gpt') {
                $words[] = 'GPT';
            } elseif ($w === 'chatgpt') {
                $words[] = 'ChatGPT';
            } else {
                $words[] = ucfirst($w);
            }
        }
        return implode(' ', $words);
    }

    private static function extract_models_from_response(string $provider_id, ?array $body): array
    {
        if (!$body) {
            return array();
        }

        $models = array();

        if ('google' === $provider_id && isset($body['models'])) {
            // --- Port of google.ts classifyModelType() + createDisplayName() ---
            // Exclusion patterns — models we don't want to expose
            $exclude_patterns = array(
                'embedding', 'embed-', 'aqa', 'retrieval',
                'attribution', 'bisect', 'code-', 'palm', 'text-bison',
            );

            foreach ($body['models'] as $m) {
                $id = str_replace('models/', '', $m['name'] ?? '');
                $id_lower = strtolower($id);
                $original_name = $m['displayName'] ?? $id;
                $name_lower = strtolower($original_name);
                $methods = $m['supportedGenerationMethods'] ?? array();

                // --- Classify model type (port of classifyModelType) ---
                $type = null;

                // Explicit IMAGE models
                if (
                str_contains($id_lower, 'image') ||
                str_contains($id_lower, 'imagen') ||
                str_contains($id_lower, 'nano-banana') ||
                str_contains($name_lower, 'image') ||
                str_contains($name_lower, 'imagen') ||
                str_contains($name_lower, 'nano banana')
                ) {
                    $type = 'image';
                }
                // Explicit VIDEO models
                elseif (
                str_contains($id_lower, 'veo') ||
                str_contains($id_lower, 'video') ||
                str_contains($name_lower, 'veo') ||
                str_contains($name_lower, 'video')
                ) {
                    $type = 'video';
                }
                // TEXT / LLM models — must have generateContent and not be excluded
                else {
                    $is_excluded = false;
                    foreach ($exclude_patterns as $p) {
                        if (str_contains($id_lower, $p)) {
                            $is_excluded = true;
                            break;
                        }
                    }
                    if (!$is_excluded && in_array('generateContent', $methods, true)) {
                        $type = 'text';
                    }
                }

                // Skip unclassified models (embedding, aqa, etc.)
                if (null === $type) {
                    continue;
                }

                // Generate friendly display name (port of createDisplayName)
                $display_name = self::create_google_display_name($original_name, $id);

                $models[] = array(
                    'id' => $id,
                    'name' => $display_name,
                    'type' => $type,
                    'costTier' => self::get_google_cost_tier($id, $type),
                );
            }
        }
        elseif ('openai' === $provider_id && isset($body['data'])) {
            // --- Port of openai.ts classifyOpenAIModel() ---
            $image_ids = array('dall-e-3', 'dall-e-2', 'gpt-4o-image');
            $image_names = array(
                'dall-e-3' => 'DALL-E 3',
                'dall-e-2' => 'DALL-E 2',
                'gpt-4o-image' => 'GPT-4o Image',
            );

            // Text model patterns (order matters — first match wins)
            $text_patterns = array(
                    array('pattern' => '/^gpt-5-nano/', 'name' => 'GPT-5 Nano', 'costTier' => 'budget'),
                    array('pattern' => '/^gpt-5-mini/', 'name' => 'GPT-5 Mini', 'costTier' => 'budget'),
                    array('pattern' => '/^gpt-5/', 'name' => 'GPT-5', 'costTier' => 'premium'),
                    array('pattern' => '/^gpt-4\.5/', 'name' => 'GPT-4.5', 'costTier' => 'premium'),
                    array('pattern' => '/^gpt-4o-mini/', 'name' => 'GPT-4o Mini', 'costTier' => 'budget'),
                    array('pattern' => '/^gpt-4o(?!-image)/', 'name' => 'GPT-4o', 'costTier' => 'standard'),
                    array('pattern' => '/^gpt-4-turbo/', 'name' => 'GPT-4 Turbo', 'costTier' => 'standard'),
                    array('pattern' => '/^gpt-4\.1-nano/', 'name' => 'GPT-4.1 Nano', 'costTier' => 'budget'),
                    array('pattern' => '/^gpt-4\.1-mini/', 'name' => 'GPT-4.1 Mini', 'costTier' => 'budget'),
                    array('pattern' => '/^gpt-4\.1/', 'name' => 'GPT-4.1', 'costTier' => 'premium'),
                    array('pattern' => '/^o4-mini/', 'name' => 'o4-mini', 'costTier' => 'standard'),
                    array('pattern' => '/^o4(?!-mini)/', 'name' => 'o4', 'costTier' => 'premium'),
                    array('pattern' => '/^o3-pro/', 'name' => 'o3 Pro', 'costTier' => 'premium'),
                    array('pattern' => '/^o3-mini/', 'name' => 'o3 Mini', 'costTier' => 'budget'),
                    array('pattern' => '/^o3(?!-)/', 'name' => 'o3', 'costTier' => 'premium'),
                    array('pattern' => '/^o1-mini/', 'name' => 'o1 Mini', 'costTier' => 'budget'),
                    array('pattern' => '/^o1(?!-)/', 'name' => 'o1', 'costTier' => 'premium'),
            );

            $seen_names = array(); // De-duplicate by display name

            foreach ($body['data'] as $m) {
                $id = $m['id'] ?? '';

                // Check image models first
                if (in_array($id, $image_ids, true)) {
                    $name = $image_names[$id] ?? $id;
                    if (!isset($seen_names[$name])) {
                        $seen_names[$name] = true;
                        $models[] = array('id' => $id, 'name' => $name, 'type' => 'image');
                    }
                    continue;
                }

                // Check text model patterns (nice names + cost tiers for known families)
                $matched = false;
                foreach ($text_patterns as $entry) {
                    if (preg_match($entry['pattern'], $id)) {
                        $matched = true;
                        if (!isset($seen_names[$entry['name']])) {
                            $seen_names[$entry['name']] = true;
                            $models[] = array(
                                'id' => $id,
                                'name' => $entry['name'],
                                'type' => 'text',
                                'costTier' => $entry['costTier'],
                            );
                        }
                        break; // First pattern match wins
                    }
                }

                // Future-proof: include any other chat-capable GPT / o-series model that
                // isn't explicitly listed (new families, snapshots) so the latest models
                // always surface. Skip non-chat endpoints (embeddings, audio, image, etc.).
                if (!$matched) {
                    $id_lower = strtolower($id);
                    $is_chat  = (bool) preg_match('/^(gpt-|o[0-9]|chatgpt)/', $id_lower);
                    $excluded = false;
                    foreach (array('embedding', 'embed', 'whisper', 'tts', 'audio', 'realtime', 'transcribe', 'moderation', 'image', 'dall-e', 'davinci', 'babbage', 'instruct', 'search', 'codex', 'computer-use') as $skip) {
                        if (str_contains($id_lower, $skip)) {
                            $excluded = true;
                            break;
                        }
                    }
                    if ($is_chat && !$excluded) {
                        $name = self::humanize_openai_id($id);
                        if ($name !== '' && !isset($seen_names[$name])) {
                            $seen_names[$name] = true;
                            $models[] = array('id' => $id, 'name' => $name, 'type' => 'text', 'costTier' => 'standard');
                        }
                    }
                }
            }

            // Always include DALL-E models even if not in /models response
            $provider = self::get('openai');
            foreach (($provider['knownModels'] ?? array()) as $known) {
                if (str_starts_with($known['id'], 'dall-e')) {
                    $already = false;
                    foreach ($models as $existing) {
                        if ($existing['id'] === $known['id']) {
                            $already = true;
                            break;
                        }
                    }
                    if (!$already) {
                        $models[] = $known;
                    }
                }
            }
        }
        elseif ('anthropic' === $provider_id && isset($body['data'])) {
            // --- Anthropic /v1/models response parsing ---
            // Response shape: { "data": [{ "id": "claude-...", "display_name": "...", "type": "model" }] }
            // All Anthropic models are text-only (no image/video generation).

            // Cost tier mapping based on model family
            $cost_tiers = array(
                'opus'   => 'premium',
                'sonnet' => 'standard',
                'haiku'  => 'budget',
            );

            $seen_names = array(); // De-duplicate by display name

            foreach ($body['data'] as $m) {
                $id = $m['id'] ?? '';
                $display_name = $m['display_name'] ?? $id;

                // Skip non-model entries and empty IDs
                if (empty($id)) {
                    continue;
                }

                // De-duplicate by display name (multiple versions of same model)
                if (isset($seen_names[$display_name])) {
                    continue;
                }
                $seen_names[$display_name] = true;

                // Determine cost tier from model ID
                $cost_tier = 'standard';
                $id_lower = strtolower($id);
                foreach ($cost_tiers as $keyword => $tier) {
                    if (str_contains($id_lower, $keyword)) {
                        $cost_tier = $tier;
                        break;
                    }
                }

                $models[] = array(
                    'id'       => $id,
                    'name'     => $display_name,
                    'type'     => 'text',
                    'costTier' => $cost_tier,
                );
            }
        }

        return $models;
    }

    /**
     * Generate a friendly display name for Google AI models.
     *
     * PHP port of google.ts createDisplayName().
     *
     * @param string $original_name displayName from API.
     * @param string $model_id      Model identifier.
     * @return string Friendly display name.
     */
    private static function create_google_display_name(string $original_name, string $model_id): string
    {
        $name_lower = strtolower($original_name);
        $is_preview = str_contains($model_id, 'preview');
        $is_fast = str_contains($model_id, 'fast');

        // Nano Banana variants
        if (str_contains($name_lower, 'nano banana')) {
            if (str_contains($model_id, 'nano-banana-pro'))
                return 'Nano Banana Pro (Preview)';
            if (str_contains($model_id, 'gemini-3-pro'))
                return 'Nano Banana Pro (Gemini 3)';
            if (str_contains($model_id, 'gemini-2.5-flash'))
                return 'Nano Banana (2.5 Flash)';
            return $original_name;
        }

        // Imagen variants
        if (str_contains($name_lower, 'imagen')) {
            if (str_contains($model_id, 'ultra'))
                return 'Imagen 4 Ultra' . ($is_preview ? ' (Preview)' : '');
            if ($is_fast)
                return 'Imagen 4 Fast';
            return 'Imagen 4' . ($is_preview ? ' (Preview)' : '');
        }

        // Veo variants
        if (str_contains($name_lower, 'veo')) {
            if (str_contains($model_id, 'veo-3.1'))
                return 'Veo 3.1' . ($is_fast ? ' Fast' : '') . ($is_preview ? ' (Preview)' : '');
            if (str_contains($model_id, 'veo-3.0') || str_contains($model_id, 'veo-3-'))
                return 'Veo 3' . ($is_fast ? ' Fast' : '');
            if (str_contains($model_id, 'veo-2'))
                return 'Veo 2';
            return $original_name;
        }

        return $original_name;
    }

    /**
     * Determine cost tier based on Google model ID and type.
     *
     * PHP port of google.ts getCostTier().
     *
     * @param string $model_id Model identifier.
     * @param string $type     Model type.
     * @return string Cost tier (budget, standard, premium).
     */
    private static function get_google_cost_tier(string $model_id, string $type): string
    {
        $id_lower = strtolower($model_id);

        if ('text' === $type) {
            if (str_contains($id_lower, 'flash') || str_contains($id_lower, 'lite'))
                return 'budget';
            if (str_contains($id_lower, 'ultra') || str_contains($id_lower, 'pro'))
                return 'premium';
            return 'standard';
        }
        if ('image' === $type) {
            if (str_contains($id_lower, 'fast'))
                return 'budget';
            if (str_contains($id_lower, 'ultra'))
                return 'premium';
            if (str_contains($id_lower, 'gemini-3') || str_contains($id_lower, 'nano-banana-pro'))
                return 'premium';
            return 'standard';
        }
        if ('video' === $type) {
            if (str_contains($id_lower, 'fast'))
                return 'budget';
            if (str_contains($id_lower, 'veo-2'))
                return 'budget';
            if (str_contains($id_lower, 'veo-3.1'))
                return 'premium';
            return 'standard';
        }

        return 'standard';
    }

    /**
     * Check if the models array contains any model of a given type.
     *
     * @param array  $models Array of model objects.
     * @param string $type   Model type to look for (image, video, text).
     * @return bool
     */
    private static function has_model_type(array $models, string $type): bool
    {
        foreach ($models as $model) {
            if (($model['type'] ?? '') === $type) {
                return true;
            }
        }
        return false;
    }
}
