<?php
/**
 * Asset Scraper REST Controller
 *
 * Thin router for URL scraping, image analysis, and smart selection.
 * Handles HTTP concerns: route definitions, input validation, response formatting.
 *
 * All business logic delegated to PCM_Scraper_Service.
 *
 * Endpoints:
 *   GET    /scraper/collections             → list scraped collections
 *   GET    /scraper/collections/<id>         → get single collection
 *   POST   /scraper/scrape                  → scrape a URL for images
 *   POST   /scraper/analyze                 → AI vision analysis of images
 *   POST   /scraper/smart-select            → AI smart selection for ads
 *   DELETE /scraper/collections/<id>         → delete a collection
 *   POST   /scraper/extract-text            → extract text content from URL
 *
 * @package PowerCreatives
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Scraper extends PCM_REST_Base
{
    // Admin-only: these routes fetch arbitrary caller-supplied URLs server-side
    // (SSRF surface). Not in the user-facing work-module set (brand/copy
    // "Fetch Info" use their own module routes), so it stays manage_options.


    /**
     * Service instance — holds all business logic.
     *
     * @var PCM_Scraper_Service
     */
    private PCM_Scraper_Service $service;

    /**
     * Constructor — inject service dependency.
     */
    public function __construct()
    {
        $this->service = new PCM_Scraper_Service();
    }

    /**
     * Define all scraper routes.
     *
     * @return array
     */
    protected function routes(): array
    {
        return array(
            // Collection CRUD
                array('GET', '/scraper/collections', 'list_collections'),
                array('GET', '/scraper/collections/(?P<id>\\d+)', 'get_collection'),
                array('DELETE', '/scraper/collections/(?P<id>\\d+)', 'delete_collection'),

            // Image operations
                array('PATCH', '/scraper/images/(?P<id>\\d+)', 'toggle_image_exclusion'),

            // Scraping operations
                array('POST', '/scraper/scrape', 'scrape_url'),
                array('POST', '/scraper/analyze', 'analyze_images'),
                array('POST', '/scraper/smart-select', 'smart_select'),
                array('POST', '/scraper/extract-text', 'extract_text'),
        );
    }

    /* ─────────────────────────── Collections CRUD ─────────────────────────── */

    /** GET /scraper/collections — List all scraped collections for the user. */
    public function list_collections(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $table = PCM_Schema::prefix() . 'scraped_collections';

        $collections = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE userId = %d ORDER BY createdAt DESC",
            $user->id
        ));

        // Cast numeric types — wpdb returns everything as strings
        foreach ($collections as &$col) {
            $col->id            = (int) $col->id;
            $col->imageCount    = (int) $col->imageCount;
            $col->selectedCount = (int) $col->selectedCount;
        }

        return $this->success($collections);
    }

    /** GET /scraper/collections/<id> — Get a single collection with its images. */
    public function get_collection(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));

        $collection = $this->service->get_collection($id, $user->id);
        if (!$collection) {
            return $this->not_found('Collection');
        }

        // Get associated images
        $img_table = PCM_Schema::prefix() . 'scraped_images';
        $images = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$img_table} WHERE collectionId = %d ORDER BY id ASC",
            $id
        ));

        // Cast types — wpdb returns all values as strings, which causes
        // JavaScript truthy coercion bugs (string "0" is truthy in JS).
        foreach ($images as &$img) {
            $img->aiTags     = json_decode($img->aiTags ?? '[]', true) ?: array();
            $img->isExcluded = (bool) (int) $img->isExcluded;
            $img->isUsable   = (bool) (int) $img->isUsable;
            $img->aiScore    = $img->aiScore !== null ? (float) $img->aiScore : null;
            $img->id         = (int) $img->id;
            $img->collectionId = (int) $img->collectionId;
        }

        $collection->images = $images;

        return $this->success($collection);
    }

    /** DELETE /scraper/collections/<id> — Delete a collection and its images. */
    public function delete_collection(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));

        $collection = $this->service->get_collection($id, $user->id);
        if (!$collection) {
            return $this->not_found('Collection');
        }

        $img_table = PCM_Schema::prefix() . 'scraped_images';
        $col_table = PCM_Schema::prefix() . 'scraped_collections';

        // Delete images first (foreign key child)
        $wpdb->delete($img_table, array('collectionId' => $id), array('%d'));
        $wpdb->delete($col_table, array('id' => $id), array('%d'));

        return $this->success(array('success' => true));
    }

    /**
     * PATCH /scraper/images/<id> — Toggle image exclusion.
     *
     * Accepts explicit state (true/false) instead of "toggle" to ensure
     * idempotency — industry best practice for boolean PATCH endpoints.
     */
    public function toggle_image_exclusion(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $user = $this->get_current_pcm_user();
        $id   = absint($request->get_param('id'));
        $excluded = (bool) $request->get_param('excluded');

        $img_table = PCM_Schema::prefix() . 'scraped_images';

        // Verify ownership
        $image = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$img_table} WHERE id = %d AND userId = %d",
            $id,
            $user->id
        ));

        if (!$image) {
            return $this->not_found('Image');
        }

        $wpdb->update(
            $img_table,
            array('isExcluded' => $excluded ? 1 : 0),
            array('id' => $id),
            array('%d'),
            array('%d')
        );

        return $this->success(array('success' => true, 'isExcluded' => $excluded));
    }

    /* ─────────────────────────── Scraping Operations ─────────────────────────── */

    /** POST /scraper/scrape — Scrape a URL for images. */
    public function scrape_url(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $url = esc_url_raw($request->get_param('url'));

        if (empty($url)) {
            return $this->error('URL is required.');
        }

        try {
            $fetch = $this->service->fetch_html($url, 'Image Scraper');
            $result = $this->service->create_collection_with_images(
                $user->id,
                $url,
                $fetch['html'],
                $fetch['status_code']
            );
            return $this->success($result, 201);
        }
        catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() >= 400 ? 400 : 500);
        }
    }

    /** POST /scraper/analyze — Analyze images using AI Vision. */
    public function analyze_images(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $collection_id = absint($request->get_param('collectionId'));
        $image_ids = $request->get_param('imageIds') ?? array();

        // Verify collection ownership
        $collection = $this->service->get_collection($collection_id, $user->id);
        if (!$collection) {
            return $this->not_found('Collection');
        }

        // Get images to analyze
        $images = $this->service->get_images($collection_id, $image_ids);

        if (empty($images)) {
            return $this->success(array('analyzed' => 0, 'results' => array()));
        }

        try {
            $analyses = $this->service->analyze_images(
                $images,
                $user->id,
                $request->get_param('model') ?? 'gpt-4o-mini'
            );

            return $this->success(array(
                'analyzed' => count($analyses),
                'results' => $analyses,
            ));
        }
        catch (\RuntimeException $e) {
            return $this->error('Vision analysis failed: ' . $e->getMessage(), 500);
        }
    }

    /** POST /scraper/smart-select — AI-powered smart image selection. */
    public function smart_select(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $collection_id = absint($request->get_param('collectionId'));
        $context = $request->get_param('context') ?? array();
        $limit = absint($request->get_param('limit') ?? 5);

        // Verify collection ownership
        $collection = $this->service->get_collection($collection_id, $user->id);
        if (!$collection) {
            return $this->not_found('Collection');
        }

        // Get analyzed images
        $images = $this->service->get_images($collection_id);

        if (empty($images)) {
            return $this->success(array('selected' => array()));
        }

        try {
            $result = $this->service->smart_select(
                $images,
                $context,
                $limit,
                $user->id,
                $request->get_param('model') ?? 'gpt-4o-mini'
            );
            return $this->success($result);
        }
        catch (\RuntimeException $e) {
            return $this->error('Smart selection failed: ' . $e->getMessage(), 500);
        }
    }

    /** POST /scraper/extract-text — Extract text content from a URL. */
    public function extract_text(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $url = esc_url_raw($request->get_param('url'));

        if (empty($url)) {
            return $this->error('URL is required.');
        }

        try {
            $fetch = $this->service->fetch_html($url, 'Content Extractor');
            $result = $this->service->extract_text_content($fetch['html'], $url);
            return $this->success($result);
        }
        catch (\RuntimeException $e) {
            return $this->error('Failed to fetch URL: ' . $e->getMessage());
        }
    }
}
