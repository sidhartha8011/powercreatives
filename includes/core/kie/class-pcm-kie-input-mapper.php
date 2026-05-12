<?php
/**
 * Kie.ai Input Mapper
 *
 * Bridges our normalized generation parameters to the exact field names
 * each Kie.ai marketplace model expects, based on the model's registry definition.
 *
 * This file contains ZERO model-specific code.
 * All behavior is derived from the model definition fields:
 *   - imageInputMode: how image URLs are sent (input_urls, image_url, image, etc.)
 *   - aspectRatioMode: field name for aspect ratio (aspect_ratio, image_size)
 *   - aspectRatioMap: model-specific value mapping (portrait→9:16, landscape→16:9)
 *   - supportsDuration: whether model accepts duration parameter
 *   - supportsNegativePrompt: whether model accepts negative_prompt
 *   - extraRequiredFields: model-specific defaults for required fields
 *
 * Ported from: SOURCE/server/kieInputMapper.ts (232 lines)
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Kie_Input_Mapper
{

    // ─── Default Aspect Ratios ──────────────────────────────────────────

    /**
     * Default aspect ratio values per mode for IMAGE models.
     * Used when model requires aspect ratio but caller didn't provide one.
     *
     * @var array<string, string>
     */
    private const DEFAULT_AR_IMAGE = [
        'aspect_ratio' => '1:1',
        'image_size' => 'square_hd',
    ];

    /**
     * Default aspect ratio values per mode for VIDEO models.
     *
     * @var array<string, string>
     */
    private const DEFAULT_AR_VIDEO = [
        'aspect_ratio' => '16:9',
        'image_size' => 'landscape_16_9',
    ];

    /**
     * Standard format-to-aspect-ratio mapping.
     * Used when a model does NOT have a custom aspectRatioMap.
     *
     * @var array<string, string>
     */
    private const FORMAT_MAP = [
        'portrait' => '9:16',
        'landscape' => '16:9',
    ];

    // ─── Main Mapper ────────────────────────────────────────────────────

    /**
     * Build the `input` object for a marketplace createTask request.
     *
     * Reads the model definition to determine which fields to include
     * and what field names to use. Zero model-specific logic.
     *
     * @param array $model_def  Model definition from the registry. Expected keys:
     *                          - assetType: 'image'|'video'
     *                          - aspectRatioMode: 'aspect_ratio'|'image_size'|null
     *                          - aspectRatioMap: array|null
     *                          - imageInputMode: string|null
     *                          - maxImageInputs: int
     *                          - supportsDuration: bool
     *                          - supportsNegativePrompt: bool
     *                          - extraRequiredFields: array|null
     *                          - validDurations: string[]|null
     *                          - defaultDuration: string|null
     * @param array $params     Normalized generation parameters:
     *                          - prompt: string (required)
     *                          - aspectRatio: string|null
     *                          - format: 'portrait'|'landscape'|null
     *                          - duration: string|null
     *                          - inputUrls: string[]|null
     *                          - negativePrompt: string|null
     *
     * @return array The `input` object ready for the API request body.
     */
    public static function build_marketplace_input(array $model_def, array $params): array
    {
        $input = [];

        // --- Prompt (always required) ---
        $input['prompt'] = $params['prompt'] ?? '';

        // --- Negative prompt (only if model supports it) ---
        if (!empty($model_def['supportsNegativePrompt']) && !empty($params['negativePrompt'])) {
            $input['negative_prompt'] = $params['negativePrompt'];
        }

        // --- Aspect ratio (field name depends on model definition) ---
        $ar_mode = $model_def['aspectRatioMode'] ?? null;
        if ($ar_mode) {
            $value = self::resolve_aspect_ratio(
                $model_def,
                $params['format'] ?? null,
                $params['aspectRatio'] ?? null
            );
            if ($value) {
                // Use the correct field name: 'aspect_ratio' or 'image_size'
                $input[$ar_mode] = $value;
            }
        }

        // --- Duration (video models only, validated against model constraints) ---
        $duration = self::resolve_duration(
            $model_def,
            $params['duration'] ?? null
        );
        if ($duration !== null) {
            $input['duration'] = $duration;
        }

        // --- Image input (field name and structure depend on model definition) ---
        $image_mode = $model_def['imageInputMode'] ?? null;
        if ($image_mode && !empty($params['inputUrls'])) {
            $max_images = (int)($model_def['maxImageInputs'] ?? 1);
            $urls = array_slice($params['inputUrls'], 0, max(1, $max_images));

            switch ($image_mode) {
                case 'input_urls':
                    // Array field: input.input_urls = ["url1", "url2"]
                    $input['input_urls'] = $urls;
                    break;
                case 'image_url':
                    // Single field: input.image_url = "url"
                    $input['image_url'] = $urls[0];
                    break;
                case 'image_urls':
                    // Array field: input.image_urls = ["url1"] (Kling style)
                    $input['image_urls'] = $urls;
                    break;
                case 'image':
                    // Single field: input.image = "url" (Recraft style)
                    $input['image'] = $urls[0];
                    break;
                case 'video_url':
                    // Single field: input.video_url = "url" (video-to-video)
                    $input['video_url'] = $urls[0];
                    break;
                case 'reference_image_urls':
                    // Array field: input.reference_image_urls = ["url1"]
                    $input['reference_image_urls'] = $urls;
                    break;
                case 'image_input':
                    // Array field: input.image_input = ["url1", ...] (Nano Banana Pro style)
                    $input['image_input'] = $urls;
                    break;
            }
        }

        // --- Extra required fields (model-specific defaults) ---
        // Won't override fields already set above.
        if (!empty($model_def['extraRequiredFields']) && is_array($model_def['extraRequiredFields'])) {
            foreach ($model_def['extraRequiredFields'] as $key => $default_value) {
                if (!array_key_exists($key, $input)) {
                    $input[$key] = $default_value;
                }
            }
        }

        return $input;
    }

    // ─── Duration Helpers ───────────────────────────────────────────────

    /**
     * Resolve the duration value for a model.
     *
     * - If the model has validDurations, ensures the value is in the list.
     * - Falls back to model's defaultDuration, then to the raw value.
     * - Returns null if the model doesn't support duration.
     *
     * @param array       $model_def Model definition.
     * @param string|null $requested Requested duration from caller.
     *
     * @return string|null Resolved duration, or null if unsupported.
     */
    private static function resolve_duration(array $model_def, ?string $requested): ?string
    {
        if (empty($model_def['supportsDuration'])) {
            return null;
        }

        $requested = $requested ? trim($requested) : null;

        // If model defines valid durations, validate against the list
        $valid = $model_def['validDurations'] ?? [];
        if (!empty($valid)) {
            // Exact match — user picked a supported value
            if ($requested && in_array($requested, $valid, true)) {
                return $requested;
            }
            // Explicit but non-matching — clamp to nearest valid ≤ requested.
            // Example: user picks 10s, model supports [4,8,12] → returns 8.
            if ($requested) {
                return self::nearest_valid_duration($valid, (int)$requested);
            }
            // Smart mode (null) — pick highest valid duration, capped at 15s.
            // This gives the video AI maximum creative length without
            // exceeding a reasonable limit.
            return self::max_duration_capped($valid, 15);
        }

        // Model supports duration but has no explicit valid list — pass through
        if ($requested) {
            return $requested;
        }
        return $model_def['defaultDuration'] ?? null;
    }

    /**
     * Pick the nearest valid duration ≤ the requested value.
     *
     * Used when a user explicitly selects a duration that isn't in the
     * model's valid list. Picks the highest value that doesn't exceed
     * the user's intent. Falls back to smallest valid if all exceed.
     *
     * @param string[] $valid_durations Valid duration strings (e.g. ["4","8","12"]).
     * @param int      $requested       Requested duration in seconds.
     *
     * @return string Nearest valid duration ≤ requested, or smallest valid.
     */
    private static function nearest_valid_duration(array $valid_durations, int $requested): string
    {
        // Filter to values ≤ what the user asked for
        $at_or_below = array_filter($valid_durations, fn(string $d) => (int)$d <= $requested);

        if (!empty($at_or_below)) {
            // Pick the highest valid value that doesn't exceed user's choice
            $best = max(array_map('intval', $at_or_below));
            foreach ($valid_durations as $d) {
                if ((int)$d === $best) {
                    return $d;
                }
            }
        }

        // Nothing ≤ requested — use smallest available (fail-safe)
        return $valid_durations[0];
    }

    /**
     * Pick the highest duration from valid list, capped at a maximum.
     *
     * @param string[] $valid_durations List of valid duration strings (e.g. ["5","10"]).
     * @param int      $cap            Maximum allowed duration in seconds.
     *
     * @return string|null Highest valid duration ≤ cap, or first valid if all exceed cap.
     */
    private static function max_duration_capped(array $valid_durations, int $cap): ?string
    {
        // Filter to durations within the cap
        $within_cap = array_filter($valid_durations, fn(string $d) => (int)$d <= $cap);

        if (!empty($within_cap)) {
            // Return the highest value within the cap
            $max_val = max(array_map('intval', $within_cap));
            // Return the original string form from the valid list
            foreach ($valid_durations as $d) {
                if ((int)$d === $max_val) {
                    return $d;
                }
            }
        }

        // All durations exceed cap — use the smallest available
        return $valid_durations[0] ?? null;
    }

    // ─── Aspect Ratio Helpers ───────────────────────────────────────────

    /**
     * Resolve the aspect ratio value for a model.
     *
     * - If a format ('portrait'|'landscape') is provided, translate using the
     *   model's aspectRatioMap or the standard mapping.
     * - Otherwise use the raw aspectRatio value from the caller.
     * - Falls back to the default for the model's aspectRatioMode.
     *
     * @param array       $model_def Model definition.
     * @param string|null $format    Normalized format from frontend.
     * @param string|null $raw_ar    Raw aspect ratio value (e.g. '16:9').
     *
     * @return string|null Resolved aspect ratio value.
     */
    private static function resolve_aspect_ratio(
        array $model_def,
        ?string $format,
        ?string $raw_ar
        ): ?string
    {
        $ar_mode = $model_def['aspectRatioMode'] ?? null;
        if (!$ar_mode) {
            return null;
        }

        // Format takes priority — translate to model-specific value
        if ($format) {
            $normalized = strtolower($format);
            $ar_map = $model_def['aspectRatioMap'] ?? null;

            if ($ar_map && isset($ar_map[$normalized])) {
                return $ar_map[$normalized];
            }
            if (isset(self::FORMAT_MAP[$normalized])) {
                return self::FORMAT_MAP[$normalized];
            }
        }

        // Use raw aspect ratio if provided
        if ($raw_ar) {
            return $raw_ar;
        }

        // Fall back to safe default
        return self::get_default_aspect_ratio($model_def);
    }

    /**
     * Get the default aspect ratio for a model based on its type.
     *
     * @param array $model_def Model definition.
     *
     * @return string Default aspect ratio value.
     */
    private static function get_default_aspect_ratio(array $model_def): string
    {
        $ar_mode = $model_def['aspectRatioMode'] ?? 'aspect_ratio';

        // If model has a custom aspectRatioMap, use the 'landscape' value as default
        $ar_map = $model_def['aspectRatioMap'] ?? null;
        if ($ar_map) {
            return $ar_map['landscape']
                ?? $ar_map['portrait']
                ?? reset($ar_map)
                ?: '1:1';
        }

        $is_video = ($model_def['assetType'] ?? 'image') === 'video';
        $defaults = $is_video ?self::DEFAULT_AR_VIDEO : self::DEFAULT_AR_IMAGE;
        return $defaults[$ar_mode] ?? '1:1';
    }
}
