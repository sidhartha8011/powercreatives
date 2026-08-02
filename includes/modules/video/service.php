<?php
/**
 * Video Service — Business Logic
 *
 * Handles video generation by routing through the provider registry.
 * Mirrors the pattern used by PCM_Image_Service:
 *   controller → service → PCM_Provider_Registry → provider → API
 *
 * The provider's generate_video() method handles the full lifecycle:
 *   1. Creates the generation task (or submits long-running operation)
 *   2. Polls for completion (up to 600s for video)
 *   3. Returns the finished video URL
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Video_Service
{

    // ────────────────────────────────────────────────────────────
    // Video Generation
    // ────────────────────────────────────────────────────────────

    /**
     * Generate a single video via provider API.
     *
     * Routes through PCM_Provider_Registry to the correct provider
     * (e.g. PCM_Provider_KieAI::generate_video()), which handles
     * task creation AND waiting for completion.
     *
     * Removes PHP execution time limit since video generation
     * can take several minutes (providers poll up to 600 seconds).
     *
     * @param string $provider  Provider ID (e.g. 'kieai').
     * @param string $model_id  Model ID (e.g. 'kie-kling-2.6-t2v').
     * @param string $prompt    Generation prompt.
     * @param array  $params    Additional params (duration, format, inputUrls, etc.).
     * @param int    $user_id   User ID for API key lookup.
     *
     * @return array { url: string } The generated video URL.
     * @throws \RuntimeException On API failure or timeout.
     */
    public function generate_video(
        string $provider,
        string $model_id,
        string $prompt,
        array $params,
        int $user_id
        ): array
    {
        // Remove PHP time limit — video generation can take 5-10 minutes.
        // Original Express app had no such limit (Node.js is async).
        // WordPress/PHP defaults to 30s which is far too short.
        set_time_limit(0);

        $api_key = $this->get_provider_api_key($provider, $user_id);
        $instance = PCM_Provider_Registry::get($provider, $api_key);

        // Delegate to provider — each provider handles its own API format,
        // task creation, polling, and result normalization.
        // model_id is passed as explicit first argument (interface-enforced).
        return $instance->generate_video($model_id, array_merge($params, [
            'prompt' => $prompt,
            // model_id also in params for Kie.ai build_dedicated_body() variant selection
            'model_id' => $model_id,
        ]));
    }

    // ────────────────────────────────────────────────────────────
    // Video Persistence
    // ────────────────────────────────────────────────────────────

    /**
     * Persist a generated video from an external URL to WordPress Media Library.
     *
     * Downloads the video from the provider's temporary URL and saves it as a
     * WP attachment with full generation metadata for reproducibility.
     *
     * Metadata mapping:
     *   - post_title            → descriptive filename (model + unique ID)
     *   - post_content          → full generation prompt (searchable in WP Media Library)
     *   - _wp_attachment_image_alt → "provider / model_id" (visible in Media Library)
     *   - _pcm_context          → 'video-gen' (existing PCM_Storage pattern)
     *   - _pcm_generation_meta  → JSON snapshot of all generation parameters
     *
     * @param string $external_url  Temporary video URL from provider.
     * @param string $model_id      Model used (e.g. 'kie-kling-2.6-t2v').
     * @param string $provider      Provider ID (e.g. 'kieai').
     * @param string $prompt        The generation prompt.
     * @param array  $params        Generation params (duration, format, aspect_ratio, etc.).
     *
     * @return array { id: int, url: string } WP attachment ID and permanent URL.
     * @throws \RuntimeException On download or storage failure.
     */
    public function persist_video(
        string $external_url,
        string $model_id,
        string $provider,
        string $prompt,
        array $params
        ): array
    {
        // Build a descriptive, unique filename: {model}_{shortid}.mp4
        // Example: kling-2.6-t2v_67bc3a1f.mp4
        $short_id = substr(uniqid(), -8);
        $safe_model = sanitize_file_name($model_id);
        $filename = "{$safe_model}_{$short_id}.mp4";

        // Download external video and save to WP Media Library.
        // PCM_Storage::download_external() handles:
        //   - Temporary file download
        //   - wp_handle_sideload() into wp-content/uploads/
        //   - Attachment post creation
        //   - _pcm_context meta tagging
        $saved = PCM_Storage::download_external($external_url, 'video-gen', $filename, 120);
        $attachment_id = $saved['id'];

        // Store the full prompt in the WP Description field (post_content).
        // This makes it searchable via the WordPress Media Library search bar.
        wp_update_post(array(
            'ID' => $attachment_id,
            'post_content' => wp_kses_post($prompt),
        ));

        // Store provider/model in Alt Text field — visible in Media Library UI.
        update_post_meta($attachment_id, '_wp_attachment_image_alt', "{$provider} / {$model_id}");

        // Store full generation metadata as JSON for programmatic access.
        // This snapshot enables future features (Settings Snapshot Accordion,
        // re-generation, analytics) without schema changes.
        $generation_meta = array(
            'prompt' => $prompt,
            'model_id' => $model_id,
            'provider' => $provider,
            'duration' => $params['duration'] ?? null,
            'format' => $params['format'] ?? null,
            'aspect_ratio' => $params['aspect_ratio'] ?? null,
            'source_url' => $external_url,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        );
        update_post_meta($attachment_id, '_pcm_generation_meta', wp_json_encode($generation_meta));

        return $saved;
    }

    // ────────────────────────────────────────────────────────────
    // Asset Persistence (pcm_assets)
    // ────────────────────────────────────────────────────────────

    /**
     * Save a generated video as a pcm_assets row.
     *
     * Mirrors PCM_Image_Service::save_asset() — inserts into pcm_assets
     * with type='video' so the asset has a real DB ID usable for:
     *   - Save to Project (pcm_assets.projectId)
     *   - Asset listing in Projects module
     *   - Future: delete, move, bulk operations
     *
     * @param int    $user_id   PCM user ID.
     * @param string $prompt    Generation prompt.
     * @param string $model_id  Model used.
     * @param string $provider  Provider ID.
     * @param string $url       Permanent video URL (WP Media Library).
     * @param array  $params    Extra params (duration, format, aspect_ratio, etc.).
     *
     * @return array { id: int, url: string, type: 'video', ... }
     * @throws \RuntimeException If DB insert fails.
     */
    public function save_asset(
        int $user_id,
        string $prompt,
        string $model_id,
        string $provider,
        string $url,
        array $params = array()
    ): array {
        global $wpdb;

        $meta = array(
            'duration'     => $params['duration'] ?? null,
            'format'       => $params['format'] ?? null,
            'aspect_ratio' => $params['aspect_ratio'] ?? null,
        );

        $table = PCM_Schema::table('assets');

        $inserted = $wpdb->insert($table, array(
            'userId'    => $user_id,
            'projectId' => $params['projectId'] ?? null,
            'type'      => 'video',
            'url'       => $url,
            'prompt'    => $prompt,
            'provider'  => $provider,
            'modelId'   => $model_id,
            'metadata'  => wp_json_encode($meta),
            'createdAt' => current_time('mysql'),
        ));

        if (!$inserted) {
            throw new \RuntimeException('Failed to save video asset to database.');
        }

        return array(
            'id'       => $wpdb->insert_id,
            'url'      => $url,
            'prompt'   => $prompt,
            'provider' => $provider,
            'modelId'  => $model_id,
            'type'     => 'video',
        );
    }

    // ────────────────────────────────────────────────────────────
    // API Key Resolution
    // ────────────────────────────────────────────────────────────

    /**
     * Look up stored API key for a provider.
     *
     * @param string $provider Provider ID.
     * @param int    $user_id  PCM user ID.
     *
     * @return string API key.
     * @throws \RuntimeException If no integration found.
     */
    private function get_provider_api_key(string $provider, int $user_id): string
    {
        // Workspace-scoped (own key, else an admin's) — see PCM_Access::workspace_api_key.
        $api_key = class_exists('PCM_Access')
            ? PCM_Access::workspace_api_key($provider, $user_id)
            : null;

        if (empty($api_key)) {
            throw new \RuntimeException("No active API key found for provider: {$provider}");
        }

        return $api_key;
    }
}
