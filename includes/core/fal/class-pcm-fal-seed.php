<?php
/**
 * Fal.ai Model Seeder
 *
 * Seeds Fal.ai models into the wp_pcm_models table from fal-models.json.
 * Called during plugin activation or when validating the Fal.ai API key.
 *
 * Uses PCM_DB::upsert_model() which matches on userId + modelId + provider.
 * This ensures model data stays current without duplicating rows.
 *
 * Column names must match the pcm_models schema exactly:
 * - `displayName` (not `name`)
 * - `userId` (required, NOT NULL)
 * - `enabledModules` (JSON for module visibility)
 * - No `type` or `isDefault` columns exist in schema
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Fal_Seed
{

    /**
     * Seed all Fal.ai models from the JSON definition file.
     *
     * Reads fal-models.json and upserts each model into the database.
     * Existing models (matched by userId + modelId + provider) are updated.
     *
     * @param int|null $user_id  PCM user ID. If null, resolves from current WP user.
     * @return int Number of models seeded.
     */
    public static function seed(?int $user_id = null): int
    {
        // Resolve userId — during activation, get current admin user
        if (null === $user_id) {
            $user_id = self::resolve_user_id();
            if (0 === $user_id) {
                error_log('[Fal.ai Seed] Cannot seed: no user context available');
                return 0;
            }
        }

        $models = self::load_model_definitions();

        if (empty($models)) {
            error_log('[Fal.ai Seed] No models found in fal-models.json');
            return 0;
        }

        $count = 0;
        foreach ($models as $model) {
            $success = self::upsert_model($model, $user_id);
            if ($success) {
                $count++;
            }
        }

        error_log(sprintf('[Fal.ai Seed] Seeded %d models for userId=%d', $count, $user_id));
        return $count;
    }

    /**
     * Load model definitions from the JSON file.
     *
     * @return array List of model definitions.
     */
    private static function load_model_definitions(): array
    {
        $path = PCM_PLUGIN_DIR . 'includes/core/fal/fal-models.json';

        if (!file_exists($path)) {
            error_log('[Fal.ai Seed] fal-models.json not found at: ' . $path);
            return [];
        }

        $json = file_get_contents($path);
        $models = json_decode($json, true);

        if (!is_array($models)) {
            error_log('[Fal.ai Seed] Failed to parse fal-models.json');
            return [];
        }

        return $models;
    }

    /**
     * Upsert a single model into the database via PCM_DB::upsert_model().
     *
     * Column names match pcm_models schema exactly (displayName, not name).
     * Follows the same pattern as PCM_Models_Service::sync_models().
     *
     * @param array $model   Model definition from JSON.
     * @param int   $user_id PCM user ID.
     * @return bool True on success.
     */
    private static function upsert_model(array $model, int $user_id): bool
    {
        $model_id = $model['modelId'] ?? '';

        if (empty($model_id)) {
            return false;
        }

        // Determine enabledModules based on capabilities (same logic as sync_models)
        $is_image = (bool)($model['canGenerateImage'] ?? false);
        $enabled_modules = [
            'copy' => false,
            'image' => $is_image,
            'video' => false,
        ];

        // Build data matching exact DB column names from pcm_models schema
        $data = [
            'userId' => $user_id,
            'modelId' => $model_id,
            'provider' => $model['provider'] ?? 'fal',
            'displayName' => $model['name'] ?? $model_id,
            'canGenerateImage' => (int)($model['canGenerateImage'] ?? false),
            'canEditImage' => (int)($model['canEditImage'] ?? false),
            'canGenerateVideo' => (int)($model['canGenerateVideo'] ?? false),
            'canEditVideo' => (int)($model['canEditVideo'] ?? false),
            'canGenerateText' => (int)($model['canGenerateText'] ?? false),
            'canVision' => (int)($model['canVision'] ?? false),
            'costTier' => $model['costTier'] ?? 'standard',
            'status' => 'auto',
            'isEnabled' => (int)($model['isEnabled'] ?? true),
            'isAvailable' => 1,
            'enabledModules' => wp_json_encode($enabled_modules),
        ];

        $result = PCM_DB::upsert_model($data);
        return (bool)$result;
    }

    /**
     * Resolve the PCM user ID from the current WordPress user.
     *
     * During plugin activation, the current WP admin user is available.
     * Maps WP user ID to PCM user via the same pattern as PCM_REST_Base.
     *
     * @return int PCM user ID, or 0 if unavailable.
     */
    private static function resolve_user_id(): int
    {
        $wp_user_id = get_current_user_id();

        if (0 === $wp_user_id) {
            return 0;
        }

        $open_id = 'wp_' . $wp_user_id;
        $pcm_user = PCM_DB::get_user_by_open_id($open_id);

        if ($pcm_user) {
            return (int)$pcm_user->id;
        }

        // During first activation, user might not exist yet — create it
        $wp_user = get_userdata($wp_user_id);
        if (!$wp_user) {
            return 0;
        }

        $role = user_can($wp_user_id, 'manage_options') ? 'admin' : 'user';
        $user_id = PCM_DB::upsert_user([
            'openId' => $open_id,
            'name' => $wp_user->display_name,
            'email' => $wp_user->user_email,
            'role' => $role,
            'avatarUrl' => get_avatar_url($wp_user_id),
        ]);

        return (int)($user_id ?: 0);
    }
}
