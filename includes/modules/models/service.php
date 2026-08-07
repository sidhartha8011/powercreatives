<?php
/**
 * Models Service — Business Logic Layer
 *
 * Contains model management business logic: capability detection,
 * sync orchestration, data formatting, module toggle logic.
 *
 * Extracted from PCM_REST_Models to enable:
 * - Unit testing without WordPress/REST context
 * - Reusability from CLI, cron, or other services
 * - DRY sync logic (was duplicated across sync + resync endpoints)
 *
 * @package PowerCreatives
 * @since   1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Models_Service
{

    // =========================================================================
    // CAPABILITY DETECTION
    // =========================================================================

    /**
     * Loaded capability registry from models.json.
     * Cached statically so the file is only read once per request.
     *
     * @var array|null
     */
    private static ?array $capability_registry = null;

    /**
     * Auto-detect capabilities based on model ID and primary type.
     *
     * Uses the data-driven registry at core/providers/models.json
     * instead of hardcoded arrays. Users can still override via the UI.
     *
     * @param string $model_id Model identifier (e.g. 'dall-e-3', 'gpt-4o').
     * @param string $type     Primary type (image, video, text).
     *
     * @return array Capability booleans { canGenerateImage, canEditImage, ... }
     */
    public function detect_capabilities(string $model_id, string $type): array
    {
        $registry = $this->load_registry();
        $id_lower = strtolower($model_id);

        // Start with type defaults from registry
        $defaults = $registry['typeDefaults'][$type] ?? array();
        $caps = array(
            'canGenerateImage' => $defaults['canGenerateImage'] ?? ('image' === $type),
            'canEditImage' => $defaults['canEditImage'] ?? false,
            'canGenerateVideo' => $defaults['canGenerateVideo'] ?? ('video' === $type),
            'canEditVideo' => $defaults['canEditVideo'] ?? false,
            'canGenerateText' => $defaults['canGenerateText'] ?? ('text' === $type),
            'canVision' => $defaults['canVision'] ?? false,
        );

        // Apply pattern matching from registry capabilities
        $capability_defs = $registry['capabilities'] ?? array();

        foreach ($capability_defs as $cap_name => $cap_config) {
            // Only apply relevant capabilities based on type
            if (!$this->is_capability_applicable($cap_name, $type)) {
                continue;
            }

            $patterns = $cap_config['patterns'] ?? array();
            foreach ($patterns as $pattern) {
                if (str_contains($id_lower, strtolower($pattern))) {
                    $caps[$cap_name] = true;
                    break;
                }
            }
        }

        return $caps;
    }

    /**
     * Determine if a capability should be checked for a given model type.
     *
     * Prevents vision capability from being applied to image models, etc.
     *
     * @param string $cap_name Capability name.
     * @param string $type     Model type (text, image, video).
     *
     * @return bool Whether this capability is applicable.
     */
    private function is_capability_applicable(string $cap_name, string $type): bool
    {
        return match ($cap_name) {
                'canEditImage' => 'image' === $type,
                'canEditVideo' => 'video' === $type,
                'canVision' => 'text' === $type,
                'canGenerateImage' => 'image' === $type,
                'canGenerateVideo' => 'video' === $type,
                default => true,
            };
    }

    /**
     * Load the model capability registry from JSON file.
     * Cached in static property so file is read only once per request.
     *
     * @return array Registry data.
     */
    private function load_registry(): array
    {
        if (null !== self::$capability_registry) {
            return self::$capability_registry;
        }

        $path = PCM_PLUGIN_DIR . 'includes/core/providers/models.json';

        if (!file_exists($path)) {
            error_log('PCM: models.json registry not found at ' . $path);
            self::$capability_registry = array();
            return self::$capability_registry;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            error_log('PCM: Failed to parse models.json registry');
            self::$capability_registry = array();
            return self::$capability_registry;
        }

        self::$capability_registry = $data;
        return self::$capability_registry;
    }

    // =========================================================================
    // MODEL SYNC (consolidated — was duplicated in controller)
    // =========================================================================

    /**
     * Sync models from a provider's API response.
     *
     * Validates API key, upserts discovered models with auto-detected capabilities,
     * and marks models no longer in the API as unavailable.
     *
     * This method consolidates the previously duplicated logic from
     * sync_from_integrations() and resync_provider().
     *
     * @param int    $user_id              PCM user ID.
     * @param string $provider             Provider ID (openai, google, kieai).
     * @param array  $validated_models     Array of models from PCM_Providers::validate_api_key().
     * @param bool   $mark_available       Whether to set isAvailable=1 on synced models.
     *
     * @return array { created: int, total: int }
     */
    public function sync_models(int $user_id, string $provider, array $validated_models, bool $mark_available = false): array
    {
        global $wpdb;

        $results = array('created' => 0, 'total' => count($validated_models));
        $available_model_ids = array();

        foreach ($validated_models as $model) {
            $available_model_ids[] = $model['id'];
            $model_type = $model['type'] ?? 'text';
            $capabilities = $this->detect_capabilities($model['id'], $model_type);

            $upsert_data = array(
                'userId' => $user_id,
                'modelId' => $model['id'],
                'provider' => $provider,
                'displayName' => $model['name'] ?? $model['id'],
                'canGenerateImage' => (int)$capabilities['canGenerateImage'],
                'canEditImage' => (int)$capabilities['canEditImage'],
                'canGenerateVideo' => (int)$capabilities['canGenerateVideo'],
                'canEditVideo' => (int)$capabilities['canEditVideo'],
                'canGenerateText' => (int)$capabilities['canGenerateText'],
                'canVision' => (int)$capabilities['canVision'],
                'costTier' => $model['costTier'] ?? 'standard',
                'status' => 'auto',
                'isEnabled' => 1,
            );

            // Set enabledModules based on primary type, OR override from
            // moduleOverrides rules in models.json (e.g., deep-research → all disabled).
            $enabled_modules = $this->get_module_override($model['id']);
            if (null === $enabled_modules) {
                $enabled_modules = array(
                    'copy' => ('text' === $model_type),
                    'image' => ('image' === $model_type),
                    'video' => ('video' === $model_type),
                );
            }
            $upsert_data['enabledModules'] = wp_json_encode($enabled_modules);

            // Resync explicitly marks models as available
            if ($mark_available) {
                $upsert_data['isAvailable'] = 1;
            }

            $model_id = PCM_DB::upsert_model($upsert_data);

            if ($model_id) {
                $results['created']++;
            }
        }

        // Mark models no longer available from API
        $this->mark_unavailable_models($user_id, $provider, $available_model_ids);

        return $results;
    }

    /**
     * SELF-REFRESHING REGISTRY (2026-07-13): reading the registry keeps it
     * fresh. For every ACTIVE integration whose provider has a live models
     * API, if that provider's registry rows are older than a day (or it has
     * none), a background resync is scheduled — non-blocking, once per
     * window (transient lock). The list mirrors what providers actually
     * offer BECAUSE it is used: no cron babysitting, no manual ritual.
     * The one-time key-add sync stopped being the registry's last word
     * (live-found: a May snapshot served as "the" model list for months).
     */
    public function maybe_schedule_refresh(int $user_id): void
    {
        global $wpdb;
        // Providers with a live /models API — mirrors the endpoint map in
        // PCM_Providers::validate_api_key (the single validation authority).
        $live = array('openai', 'anthropic', 'google', 'fal');
        $table = PCM_Schema::table('models');
        foreach ((array) PCM_DB::get_user_integrations($user_id) as $integration) {
            $provider = (string) ($integration->provider ?? '');
            if (empty($integration->isActive) || empty($integration->apiKey) || !in_array($provider, $live, true)) {
                continue;
            }
            $lock = 'pcm_models_refresh_' . $user_id . '_' . $provider;
            if (get_transient($lock)) {
                continue;
            }
            $newest = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(updatedAt) FROM {$table} WHERE userId = %d AND provider = %s",
                $user_id,
                $provider
            ));
            if ($newest !== null && (time() - (int) strtotime((string) $newest)) < DAY_IN_SECONDS) {
                continue;
            }
            set_transient($lock, 1, 6 * HOUR_IN_SECONDS);
            wp_schedule_single_event(time(), 'pcm_models_refresh', array($user_id, $provider));
        }
    }

    /**
     * The background refresh (wp-cron): validate the stored key against the
     * provider's LIVE models API, then the normal sync (adds new models,
     * marks vanished ones unavailable), then LIVE PRICING (below). Failures
     * are logged, never fatal — the registry simply stays as-is until the
     * next window.
     */
    public function run_refresh(int $user_id, string $provider): void
    {
        foreach ((array) PCM_DB::get_user_integrations($user_id) as $integration) {
            if ((string) ($integration->provider ?? '') !== $provider || empty($integration->apiKey)) {
                continue;
            }
            $validation = PCM_Providers::validate_api_key($provider, $integration->apiKey);
            if (empty($validation['valid'])) {
                error_log(sprintf('[PCM Models] Background refresh for "%s" failed: %s', $provider, (string) ($validation['error'] ?? 'unknown')));
                return;
            }
            $results = $this->sync_models($user_id, $provider, (array) ($validation['models'] ?? array()), true);
            $priced  = $this->apply_live_pricing($user_id, $provider);
            error_log(sprintf('[PCM Models] Background refresh for "%s": %d of %d models synced, %d priced.', $provider, (int) $results['created'], (int) $results['total'], $priced));
            return;
        }
    }

    // ── LIVE PRICING (2026-07-13): providers publish prices on WEBSITES, not
    //    in their APIs — the ONE machine-readable, continuously-updated feed
    //    is OpenRouter's public catalog (no key). Real per-million-token USD
    //    lands on each row; the tier becomes MATH against thresholds stored
    //    as DATA (option, editable). Hand-set tiers (status != 'auto') are
    //    never touched; models missing from the catalog keep the name-pattern
    //    tier — the patterns are the FALLBACK now, not the truth. ──

    /** Model-id identity across catalogs: lowercase, provider prefix and
     *  ':variant' stripped, separators folded to '-', trailing date stamps
     *  (-20YYMMDD) dropped. Pure — harness/probe testable. */
    public static function normalize_price_key(string $id): string
    {
        $key = strtolower(trim($id));
        $slash = strrpos($key, '/');
        if ($slash !== false) {
            $key = substr($key, $slash + 1);
        }
        $colon = strpos($key, ':');
        if ($colon !== false) {
            $key = substr($key, 0, $colon);
        }
        $key = str_replace(array('.', '_', ' '), '-', $key);
        $key = (string) preg_replace('/-20\d{6}$/', '', $key);
        return $key;
    }

    /** Tier from real prices: blended $(input+output)/2 per million tokens
     *  against thresholds stored as data (editable option). Pure. */
    public static function derive_tier(float $input_per_m, float $output_per_m): string
    {
        $t = get_option('pcm_model_tier_thresholds', array());
        $budget_max   = isset($t['budgetMax']) ? (float) $t['budgetMax'] : 2.0;
        $standard_max = isset($t['standardMax']) ? (float) $t['standardMax'] : 15.0;
        $blended = ($input_per_m + $output_per_m) / 2;
        if ($blended <= $budget_max) {
            return 'budget';
        }
        return $blended <= $standard_max ? 'standard' : 'premium';
    }

    /** OpenRouter's public catalog → map of normalized id → {in, out} USD per
     *  million tokens. Cached 12h (one fetch serves every provider refresh). */
    public static function fetch_live_pricing(): array
    {
        $cached = get_transient('pcm_live_pricing');
        if (is_array($cached)) {
            return $cached;
        }
        $res = wp_remote_get('https://openrouter.ai/api/v1/models', array('timeout' => 30));
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            error_log('[PCM Models] Live pricing fetch failed: ' . (is_wp_error($res) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($res)));
            return array();
        }
        $body = json_decode(wp_remote_retrieve_body($res), true);
        $map  = array();
        foreach ((array) ($body['data'] ?? array()) as $m) {
            if (!is_array($m) || empty($m['id']) || !isset($m['pricing']['prompt'], $m['pricing']['completion'])) {
                continue;
            }
            $in  = (float) $m['pricing']['prompt'] * 1000000;   // per-token → per-million
            $out = (float) $m['pricing']['completion'] * 1000000;
            if ($in < 0 || $out < 0) {
                continue; // dynamic/unknown pricing — no claim is better than a wrong one
            }
            $map[self::normalize_price_key((string) $m['id'])] = array('in' => $in, 'out' => $out);
        }
        if (!empty($map)) {
            set_transient('pcm_live_pricing', $map, 12 * HOUR_IN_SECONDS);
        }
        return $map;
    }

    /** Stamp real prices onto a provider's rows + re-derive AUTO tiers.
     *  @return int rows priced. */
    public function apply_live_pricing(int $user_id, string $provider): int
    {
        global $wpdb;
        $pricing = self::fetch_live_pricing();
        if (empty($pricing)) {
            return 0;
        }
        $table = PCM_Schema::table('models');
        $rows  = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, modelId, status FROM {$table} WHERE userId = %d AND provider = %s",
            $user_id,
            $provider
        ));
        $priced = 0;
        foreach ($rows as $row) {
            $hit = $pricing[self::normalize_price_key((string) $row->modelId)] ?? null;
            if ($hit === null) {
                continue; // not in the catalog — pattern tier stays (honest fallback)
            }
            $update = array('inputPrice' => $hit['in'], 'outputPrice' => $hit['out']);
            if ((string) $row->status === 'auto') {
                $update['costTier'] = self::derive_tier($hit['in'], $hit['out']);
            }
            $wpdb->update($table, $update, array('id' => (int) $row->id));
            $priced++;
        }
        return $priced;
    }

    /**
     * Mark models that are no longer available from the provider's API.
     * Sets isAvailable=0 for models that weren't in the latest sync.
     *
     * @param int    $user_id             PCM user ID.
     * @param string $provider            Provider ID.
     * @param array  $available_model_ids Model IDs that ARE still available.
     *
     * @return void
     */
    public function mark_unavailable_models(int $user_id, string $provider, array $available_model_ids): void
    {
        global $wpdb;

        $table = PCM_Schema::table('models');
        $all_user_models = $wpdb->get_results($wpdb->prepare(
            "SELECT id, modelId FROM {$table} WHERE userId = %d AND provider = %s",
            $user_id,
            $provider
        ));

        foreach ($all_user_models as $existing_model) {
            if (!in_array($existing_model->modelId, $available_model_ids, true)) {
                $wpdb->update(
                    $table,
                    array('isAvailable' => 0),
                    array('id' => $existing_model->id)
                );
            }
        }
    }

    // =========================================================================
    // DATA QUERIES
    // =========================================================================

    /**
     * Get models filtered by provider for a user.
     *
     * @param int    $user_id  PCM user ID.
     * @param string $provider Provider ID.
     *
     * @return array Raw model DB rows.
     */
    public function get_by_provider(int $user_id, string $provider): array
    {
        global $wpdb;

        $table = PCM_Schema::table('models');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE userId = %d AND provider = %s ORDER BY sortOrder ASC",
            $user_id,
            $provider
        ));
    }

    /**
     * Get models capable of generating/editing a specific content type.
     * Joins with integrations table to enforce isActive flag.
     * Filters by enabledModules to respect per-module toggle settings.
     *
     * @param int    $user_id PCM user ID.
     * @param string $type    Content type (image, video, text).
     * @param string $action  Action (generate, edit).
     *
     * @return array Raw model DB rows.
     */
    public function get_by_capability(int $user_id, string $type, string $action): array
    {
        global $wpdb;

        $capability_col = $this->type_to_capability_column($type, $action);
        if (!$capability_col) {
            return array();
        }

        $models_table = PCM_Schema::table('models');
        $integrations_table = PCM_Schema::table('integrations');

        // Owner scoping via the shared lever, so this query and the Models page
        // (PCM_DB::get_user_models) can never drift apart again. They HAD
        // drifted: only this one stayed hard-scoped to `m.userId = caller`, so
        // wp-admin (caller owns the keys) looked perfect while the shortcode
        // SPA — whose gate login is a DIFFERENT platform user row owning
        // nothing — rendered every model dropdown empty.
        // The i.userId join stays: a model is only offered when ITS OWN
        // owner's integration for that provider is still active.
        $scope = PCM_Access::model_scope('m.userId', $user_id);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $models = $wpdb->get_results($wpdb->prepare(
            "SELECT m.* FROM {$models_table} m
             INNER JOIN {$integrations_table} i ON m.provider = i.provider AND m.userId = i.userId
             WHERE {$scope['sql']} AND m.isEnabled = 1 AND m.{$capability_col} = 1 AND i.isActive = 1
             ORDER BY m.sortOrder ASC, m.displayName ASC",
            ...$scope['params']
        )) ?: array();

        // The team-wide and fallback tiers can surface the SAME modelId from
        // two owners (two people each added a fal key). The dropdowns key on
        // modelId, so collapse to the first — ordering already put the
        // lowest sortOrder first.
        $seen = array();
        $models = array_values(array_filter($models, function ($model) use (&$seen) {
            $key = (string) $model->modelId;
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            return true;
        }));

        // Filter by enabledModules — map content type to module name
        // (text → copy, image → image, video → video)
        $type_to_module = array(
            'text' => 'copy',
            'image' => 'image',
            'video' => 'video',
        );
        $module = $type_to_module[$type] ?? null;

        if ($module) {
            $models = array_values(array_filter($models, function ($model) use ($module) {
                $enabled = $this->parse_enabled_modules($model);
                // If module key exists, use its value; otherwise default is true
                // (backward compatible: models without explicit enabledModules
                // fall back to capability-based defaults in parse_enabled_modules)
                return $enabled[$module] ?? true;
            }));
        }

        return $models;
    }

    // =========================================================================
    // MODULE TOGGLE
    // =========================================================================

    /**
     * Parse the enabledModules value from a model row.
     * Handles JSON string, array, and null (derives from primary type).
     *
     * @param object $model Raw DB model row.
     *
     * @return array Associative array { copy: bool, image: bool, video: bool }
     */
    public function parse_enabled_modules(object $model): array
    {
        // Registry overrides take precedence over DB column — ensures models
        // like deep-research are excluded even if DB has stale enabledModules.
        $override = $this->get_module_override($model->modelId ?? '');
        if (null !== $override) {
            return $override;
        }

        $modules_raw = $model->enabledModules ?? null;

        if (is_string($modules_raw) && !empty($modules_raw)) {
            $parsed = json_decode($modules_raw, true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        if (is_array($modules_raw)) {
            return $modules_raw;
        }

        // Default: derive from primary type using priority detection.
        // A model with canGenerateVideo should NOT default to copy:true,
        // even if it also has canGenerateText (stale data or multimodal).
        // Priority: video > image > text (most specific first).
        $is_video = (bool)($model->canGenerateVideo || $model->canEditVideo);
        $is_image = (bool)($model->canGenerateImage || $model->canEditImage);
        $is_text = (bool)$model->canGenerateText;

        if ($is_video) {
            return array('copy' => false, 'image' => false, 'video' => true);
        }
        if ($is_image) {
            return array('copy' => false, 'image' => true, 'video' => false);
        }
        if ($is_text) {
            return array('copy' => true, 'image' => false, 'video' => false);
        }

        // No capabilities — disabled for all modules
        return array('copy' => false, 'image' => false, 'video' => false);
    }

    /**
     * Apply a module toggle and return the updated modules array.
     *
     * @param object $model   Raw DB model row.
     * @param string $module  Module name (copy, image, video).
     * @param bool   $enabled Whether to enable or disable.
     *
     * @return array Updated enabledModules array.
     */
    public function apply_module_toggle(object $model, string $module, bool $enabled): array
    {
        $modules = $this->parse_enabled_modules($model);
        $modules[$module] = $enabled;
        return $modules;
    }

    // =========================================================================
    // FORMATTING
    // =========================================================================

    /**
     * Format a model DB row for JSON API output.
     * Parses JSON fields and builds a typed response object.
     *
     * @param object $model Raw DB row.
     *
     * @return array Formatted model data for frontend consumption.
     */
    public function format_model(object $model): array
    {
        $metadata = json_decode($model->providerMetadata ?? '{}', true) ?: array();
        $enabled_modules = $this->parse_enabled_modules($model);

        return array(
            'id' => (int)$model->id,
            'modelId' => $model->modelId,
            'provider' => $model->provider,
            // Frontend expects originalName + customName (matches TS toModelData shape).
            'originalName' => $model->displayName ?? $model->modelId,
            'customName' => null,
            'canGenerateImage' => (bool)$model->canGenerateImage,
            'canEditImage' => (bool)$model->canEditImage,
            'canGenerateVideo' => (bool)$model->canGenerateVideo,
            'canEditVideo' => (bool)$model->canEditVideo,
            'canGenerateText' => (bool)$model->canGenerateText,
            'canVision' => (bool)$model->canVision,
            'costTier' => $model->costTier,
            'status' => $model->status,
            'isEnabled' => (bool)$model->isEnabled,
            'isAvailable' => (bool)($model->isAvailable ?? true),
            'description' => $model->description ?? '',
            'tags' => json_decode($model->tags ?? '[]', true) ?: array(),
            'sortOrder' => (int)$model->sortOrder,
            'enabledModules' => $enabled_modules,
            'providerMetadata' => $metadata,
            // Image input capability — looked up from the Kie marketplace registry.
            // null = model does NOT accept reference images (e.g. DALL-E, pure text-to-image).
            // Non-null = the field name the model uses for image input (e.g. "image_urls", "input_urls").
            'imageInputMode' => $this->get_image_input_mode($model->modelId),
            'createdAt' => $model->createdAt,
            'updatedAt' => $model->updatedAt,
        );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Map content type + action to the corresponding DB capability column.
     *
     * @param string $type   Content type (image, video, text).
     * @param string $action Action (generate, edit).
     *
     * @return string|null Column name or null if invalid.
     */
    public function type_to_capability_column(string $type, string $action): ?string
    {
        $map = array(
            'image_generate' => 'canGenerateImage',
            'image_edit' => 'canEditImage',
            'video_generate' => 'canGenerateVideo',
            'video_edit' => 'canEditVideo',
            'text_generate' => 'canGenerateText',
        );

        return $map["{$type}_{$action}"] ?? null;
    }

    /**
     * Check if a model has an enabledModules override in the registry.
     *
     * Reads `moduleOverrides.rules` from models.json. Each rule has a list
     * of string patterns — if the model ID contains any of them, the rule's
     * enabledModules value is returned.
     *
     * @param string $model_id Model identifier.
     *
     * @return array|null Override array { copy: bool, image: bool, video: bool } or null.
     */
    public function get_module_override(string $model_id): ?array
    {
        $registry = $this->load_registry();
        $rules = $registry['moduleOverrides']['rules'] ?? array();
        $id_lower = strtolower($model_id);

        foreach ($rules as $rule) {
            $patterns = $rule['patterns'] ?? array();
            foreach ($patterns as $pattern) {
                if (str_contains($id_lower, strtolower($pattern))) {
                    return $rule['enabledModules'] ?? null;
                }
            }
        }

        return null;
    }

    // =========================================================================
    // IMAGE INPUT MODE LOOKUP
    // =========================================================================

    /**
     * Cached marketplace registry for imageInputMode lookups.
     * Delegates to PCM_Kie_Api::load_marketplace_registry() (single source of truth).
     *
     * @var array|null Maps modelId → model definition array.
     */
    private static ?array $marketplace_models = null;

    /**
     * Dedicated Kie.ai models that accept image input but are NOT in
     * the marketplace registry. Kept here so format_model() can return
     * a truthful imageInputMode for them as well.
     */
    private const DEDICATED_IMAGE_INPUT = array(
        'kie-gpt-4o-image' => 'filesUrl',
        'kie-flux-kontext'  => 'inputImage',
    );

    /**
     * Get the imageInputMode for a model.
     *
     * Looks up the model in the Kie marketplace registry first,
     * then checks the dedicated models list. Returns null if the
     * model does not accept image input at all.
     *
     * @param string $model_id Model identifier.
     * @return string|null The image input field name, or null if unsupported.
     */
    private function get_image_input_mode(string $model_id): ?string
    {
        // Lazy-load from the shared registry (PCM_Kie_Api owns the JSON parsing)
        if (null === self::$marketplace_models) {
            self::$marketplace_models = PCM_Kie_Api::load_marketplace_registry();
        }

        // 1. Marketplace models
        if (isset(self::$marketplace_models[$model_id])) {
            return self::$marketplace_models[$model_id]['imageInputMode'] ?? null;
        }

        // 2. Dedicated Kie models (GPT-4o Image, Flux Kontext)
        return self::DEDICATED_IMAGE_INPUT[$model_id] ?? null;
    }
}
