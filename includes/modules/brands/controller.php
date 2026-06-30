<?php
/**
 * Brands REST Controller
 *
 * Thin router for brand management with asset handling.
 * Handles HTTP concerns: route definitions, input validation, response formatting.
 *
 * All business logic delegated to PCM_Brands_Service.
 *
 * Endpoints:
 *   GET    /brands                           → list
 *   GET    /brands/<id>                      → getById
 *   GET    /brands/by-website                → findByWebsite
 *   POST   /brands                           → create
 *   PATCH  /brands/<id>                      → update
 *   DELETE /brands/<id>                      → delete
 *   POST   /brands/bulk/delete               → bulkDelete
 *   POST   /brands/bulk/duplicate            → bulkDuplicate
 *   POST   /brands/<id>/fetch-assets         → fetchAssets
 *   POST   /brands/<id>/assets               → addAsset (file upload)
 *   POST   /brands/<id>/assets/from-url      → addAssetFromUrl
 *   DELETE /brands/<id>/assets               → removeAsset
 *   POST   /brands/<id>/assets/reorder       → reorderAssets
 *   POST   /brands/<id>/assets/set-logo      → setAssetAsLogo
 *   POST   /brands/<id>/colors               → updateColors
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_Brands extends PCM_REST_Base
{
    // Work module — usable by non-admin team members (assigned access).
    protected string $default_capability = 'edit_posts';


    /**
     * Service instance — holds all business logic.
     *
     * @var PCM_Brands_Service
     */
    private PCM_Brands_Service $service;

    /**
     * Constructor — inject service dependency.
     */
    public function __construct()
    {
        $this->service = new PCM_Brands_Service();
    }

    /**
     * Define all brand routes.
     *
     * @return array
     */
    protected function routes(): array
    {
        return array(
            // Read
                array('GET', '/brands', 'list_items'),
                array('GET', '/brands/(?P<id>\\d+)', 'get_by_id'),
                array('GET', '/brands/by-website', 'find_by_website'),

            // CRUD — writes are admin-only; team members view + use granted
            // brands but never create or mutate them.
                array('POST', '/brands', 'create_item', array(), 'manage_options'),
                array('PATCH', '/brands/(?P<id>\\d+)', 'update_item', array(), 'manage_options'),
                array('DELETE', '/brands/(?P<id>\\d+)', 'delete_item', array(), 'manage_options'),

            // Bulk operations (admin-only writes)
                array('POST', '/brands/bulk/delete', 'bulk_delete', array(), 'manage_options'),
                array('POST', '/brands/bulk/duplicate', 'bulk_duplicate', array(), 'manage_options'),

            // Asset management (admin-only writes)
                array('POST', '/brands/(?P<id>\\d+)/fetch-assets', 'fetch_assets', array(), 'manage_options'),
                array('POST', '/brands/(?P<id>\\d+)/assets', 'add_asset', array(), 'manage_options'),
                array('POST', '/brands/(?P<id>\\d+)/assets/from-url', 'add_asset_from_url', array(), 'manage_options'),
                array('DELETE', '/brands/(?P<id>\\d+)/assets', 'remove_asset', array(), 'manage_options'),
                array('POST', '/brands/(?P<id>\\d+)/assets/reorder', 'reorder_assets', array(), 'manage_options'),
                array('POST', '/brands/(?P<id>\\d+)/assets/set-logo', 'set_asset_as_logo', array(), 'manage_options'),

            // Color management (admin-only writes)
                array('POST', '/brands/(?P<id>\\d+)/colors', 'update_colors', array(), 'manage_options'),

            // Unified URL scraping (text + colors + images with dimensions) —
            // feeds brand creation, so admin-only as well.
                array('POST', '/brands/scrape-url', 'scrape_url', array(), 'manage_options'),
        );
    }

    // =========================================================================
    // READ OPERATIONS
    // =========================================================================

    /** GET /brands — List all brands for the current user. */
    public function list_items(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->get_current_pcm_user();
        $brands = PCM_DB::get_user_brands($user->id);

        return $this->success(array_map(array($this->service, 'format_brand'), $brands));
    }

    /** GET /brands/<id> — Get a single brand by ID. */
    public function get_by_id(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $brand = PCM_DB::get_brand_by_id(absint($request->get_param('id')), $user->id);

        if (!$brand) {
            return $this->not_found('Brand');
        }

        return $this->success($this->service->format_brand($brand));
    }

    /** GET /brands/by-website — Find a brand by its website URL. */
    public function find_by_website(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->get_current_pcm_user();
        $website = esc_url_raw($request->get_param('url'));

        $brand = $this->service->find_by_website($user->id, $website);

        if (!$brand) {
            return $this->success(null);
        }

        return $this->success($this->service->format_brand($brand));
    }

    // =========================================================================
    // CRUD OPERATIONS
    // =========================================================================

    /** POST /brands — Create a new brand. */
    public function create_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();

        $name = sanitize_text_field($request->get_param('name'));
        if (empty($name)) {
            return $this->error('Brand name is required.');
        }

        $data = array(
            'userId' => $user->id,
            'name' => $name,
            'website' => esc_url_raw($request->get_param('website') ?? ''),
            'niche' => sanitize_text_field($request->get_param('niche') ?? ''),
            'location' => sanitize_text_field($request->get_param('location') ?? ''),
            'phone' => sanitize_text_field($request->get_param('phone') ?? ''),
            'clientEmail' => sanitize_email($request->get_param('clientEmail') ?? ''),
            'businessSummary' => sanitize_textarea_field($request->get_param('businessSummary') ?? ''),
            'language' => sanitize_text_field($request->get_param('language') ?? ''),
            'description' => sanitize_textarea_field($request->get_param('description') ?? ''),
            'tonOfVoice' => sanitize_textarea_field($request->get_param('tonOfVoice') ?? ''),
            'colors' => wp_json_encode($request->get_param('colors') ?? array()),
            'fonts' => wp_json_encode($request->get_param('fonts') ?? array()),
            'assets' => wp_json_encode(array()),
            'externalId' => sanitize_text_field($request->get_param('externalId') ?? ''),
        );

        // Add scrapedAt if provided (ISO 8601 from frontend)
        $scraped_at = $request->get_param('scrapedAt');
        if ($scraped_at) {
            $data['scrapedAt'] = gmdate('Y-m-d H:i:s', strtotime($scraped_at));
        }

        // Determine up-front whether this will be a genuine new insert vs a
        // domain-collision update (upsert_brand dedupes by domain). This lets us
        // fire the Automations trigger ONLY on a real creation. No website/domain
        // → always an insert.
        $is_new_brand = true;
        if (!empty($data['website'])) {
            $domain = PCM_DB::normalize_domain($data['website']);
            if ($domain) {
                global $wpdb;
                $brands_table = PCM_Schema::table('brands');
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$brands_table} WHERE domain = %s LIMIT 1",
                    $domain
                ));
                $is_new_brand = empty($existing);
            }
        }

        // Upsert: if a brand with the same domain already exists, update it
        // instead of creating a duplicate. See PCM_DB::upsert_brand().
        $brand_id = PCM_DB::upsert_brand($data);

        if (!$brand_id) {
            return $this->error('Failed to create brand.', 500);
        }

        // Return the full formatted brand so the frontend can populate
        // forms immediately without a second fetch.
        $brand = PCM_DB::get_brand_by_id($brand_id, $user->id);

        // Fire the Automations 'brands.brand_created' trigger only on a real new
        // brand (cross-module: e.g. Brand created → send webhook/email).
        if ($brand && $is_new_brand && class_exists('PCM_Automation_Engine')) {
            PCM_Automation_Engine::fire_trigger(
                'brands.brand_created',
                array(
                    'brandId' => (int) $brand->id,
                    'name'    => (string) $brand->name,
                    'website' => (string) ($brand->website ?? ''),
                    'niche'   => (string) ($brand->niche ?? ''),
                ),
                (int) $user->id
            );
        }

        if ($brand) {
            return $this->success($this->service->format_brand($brand), 201);
        }

        return $this->success(array('id' => $brand_id), 201);
    }

    /** PATCH /brands/<id> — Update a brand's information. */
    public function update_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));

        $existing = PCM_DB::get_brand_by_id($id, $user->id);
        if (!$existing) {
            return $this->not_found('Brand');
        }
        // get_brand_by_id also returns brands GRANTED to the caller via a
        // delivery assignment (view + use). Editing the brand record stays
        // owner-only — reject a non-owner rather than no-op + return success.
        if ((int) $existing->userId !== (int) $user->id) {
            return $this->error('You can view this brand but not edit it.', 403, 'pcm_forbidden');
        }

        $update = array();

        $text_fields = array('name', 'website', 'niche', 'location', 'phone', 'businessSummary', 'language', 'description', 'tonOfVoice');
        foreach ($text_fields as $field) {
            $val = $request->get_param($field);
            if (null !== $val) {
                $update[$field] = ('website' === $field)
                    ? esc_url_raw($val)
                    : sanitize_textarea_field($val);
            }
        }

        // Client email — validated as an email rather than free text.
        $client_email = $request->get_param('clientEmail');
        if (null !== $client_email) {
            $update['clientEmail'] = sanitize_email($client_email);
        }

        // External id — single-line value for mapping to an outside system (webhook brandExtID).
        $external_id = $request->get_param('externalId');
        if (null !== $external_id) {
            $update['externalId'] = sanitize_text_field($external_id);
        }

        $json_fields = array('colors', 'fonts');
        foreach ($json_fields as $field) {
            $val = $request->get_param($field);
            if (null !== $val) {
                $update[$field] = wp_json_encode($val);
            }
        }

        if (!empty($update)) {
            PCM_DB::update_brand($id, $user->id, $update);
        }

        return $this->success(array('success' => true));
    }

    /** DELETE /brands/<id> — Delete a single brand. */
    public function delete_item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $deleted = PCM_DB::delete_brand(absint($request->get_param('id')), $user->id);

        if (!$deleted) {
            return $this->not_found('Brand');
        }

        return $this->success(array('success' => true));
    }

    // =========================================================================
    // BULK OPERATIONS
    // =========================================================================

    /** POST /brands/bulk/delete — Delete multiple brands. */
    public function bulk_delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $ids = $request->get_param('ids');

        if (!is_array($ids) || empty($ids)) {
            return $this->error('IDs array is required.');
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if (PCM_DB::delete_brand(absint($id), $user->id)) {
                $deleted++;
            }
        }

        return $this->success(array('deleted' => $deleted));
    }

    /** POST /brands/bulk/duplicate — Duplicate multiple brands. */
    public function bulk_duplicate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $ids = $request->get_param('ids');

        if (!is_array($ids) || empty($ids)) {
            return $this->error('IDs array is required.');
        }

        $created = 0;
        foreach ($ids as $id) {
            $source = PCM_DB::get_brand_by_id(absint($id), $user->id);
            if (!$source) {
                continue;
            }
            if ($this->service->duplicate_brand($source, $user->id)) {
                $created++;
            }
        }

        return $this->success(array('created' => $created));
    }

    // =========================================================================
    // ASSET MANAGEMENT
    // =========================================================================

    /** POST /brands/<id>/assets — Upload an image asset. */
    public function add_asset(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));

        $brand = PCM_DB::get_brand_by_id($id, $user->id);
        if (!$brand) {
            return $this->not_found('Brand');
        }

        $files = $request->get_file_params();
        if (empty($files['file'])) {
            return $this->error('No file uploaded.');
        }

        $role = sanitize_text_field($request->get_param('role') ?? '');

        try {
            $asset = $this->service->upload_asset($files['file'], $id, $user->id, $brand, $role);
            return $this->success($asset, 201);
        }
        catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400, 'pcm_invalid_role');
        }
        catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /** POST /brands/<id>/assets/from-url — Download image from URL. */
    public function add_asset_from_url(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));
        $url = esc_url_raw($request->get_param('imageUrl'));

        $brand = PCM_DB::get_brand_by_id($id, $user->id);
        if (!$brand) {
            return $this->not_found('Brand');
        }

        if (empty($url)) {
            return $this->error('Image URL is required.');
        }

        $role = sanitize_text_field($request->get_param('role') ?? '');

        try {
            $asset = $this->service->add_asset_from_url($url, $id, $user->id, $brand, $role);
            return $this->success($asset, 201);
        }
        catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400, 'pcm_invalid_role');
        }
        catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /** POST /brands/<id>/fetch-assets — Fetch logo/images from website. */
    public function fetch_assets(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));
        $url = esc_url_raw($request->get_param('url'));

        $brand = PCM_DB::get_brand_by_id($id, $user->id);
        if (!$brand) {
            return $this->not_found('Brand');
        }

        if (empty($url)) {
            $url = $brand->website ?? '';
        }

        if (empty($url)) {
            return $this->error('No URL provided and brand has no website.');
        }

        try {
            $images = $this->service->fetch_website_assets($url);
            return $this->success(array('images' => $images));
        }
        catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /** DELETE /brands/<id>/assets — Remove an asset by fileKey. */
    public function remove_asset(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));
        $file_key = sanitize_text_field($request->get_param('fileKey'));

        $brand = PCM_DB::get_brand_by_id($id, $user->id);
        if (!$brand) {
            return $this->not_found('Brand');
        }

        $this->service->remove_asset($id, $user->id, $brand, $file_key);

        return $this->success(array('success' => true));
    }

    /** POST /brands/<id>/assets/reorder — Reorder assets. */
    public function reorder_assets(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));
        $file_keys = $request->get_param('fileKeys');

        if (!is_array($file_keys)) {
            return $this->error('fileKeys must be an array.');
        }

        $brand = PCM_DB::get_brand_by_id($id, $user->id);
        if (!$brand) {
            return $this->not_found('Brand');
        }

        $this->service->reorder_assets($id, $user->id, $brand, $file_keys);

        return $this->success(array('success' => true));
    }

    /** POST /brands/<id>/assets/set-logo — Promote a reference asset to logo role.
     *  The previous logo (if any) is automatically demoted to 'reference'. */
    public function set_asset_as_logo(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));
        $file_key = sanitize_text_field($request->get_param('fileKey'));

        if (empty($file_key)) {
            return $this->error('fileKey is required.');
        }

        $brand = PCM_DB::get_brand_by_id($id, $user->id);
        if (!$brand) {
            return $this->not_found('Brand');
        }

        $this->service->set_asset_as_logo($id, $user->id, $brand, $file_key);

        return $this->success(array('success' => true));
    }

    // =========================================================================
    // COLOR MANAGEMENT
    // =========================================================================

    /** POST /brands/<id>/colors — Update the brand's color palette. */
    public function update_colors(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $id = absint($request->get_param('id'));
        $colors = $request->get_param('colors');

        if (!is_array($colors)) {
            return $this->error('Colors must be an array.');
        }

        $brand = PCM_DB::get_brand_by_id($id, $user->id);
        if (!$brand) {
            return $this->not_found('Brand');
        }

        $validated = $this->service->validate_colors($colors);

        PCM_DB::update_brand($id, $user->id, array(
            'colors' => wp_json_encode($validated),
        ));

        return $this->success(array('success' => true));
    }

    // =========================================================================
    // UNIFIED URL SCRAPING
    // =========================================================================

    /**
     * POST /brands/scrape-url — Unified brand scraping.
     *
     * Single endpoint that extracts ALL brand identity data from a URL:
     *   - Business info (title, description, h1)
     *   - Images with dimensions (sorted by resolution)
     *   - Brand colors (CSS variables, inline styles, style blocks)
     *   - Optional: LLM-enriched business info (if model provided)
     *
     * Replaces the previous pattern of calling 2-4 separate endpoints.
     */
    public function scrape_url(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $url = esc_url_raw($request->get_param('url'));
        if (empty($url)) {
            return $this->error('URL is required.');
        }

        $model = sanitize_text_field($request->get_param('model') ?? '');

        try {
            $result = $this->service->scrape_and_prepare($url, $model ?: null);
            return $this->success($result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}
