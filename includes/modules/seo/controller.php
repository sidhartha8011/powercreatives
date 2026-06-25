<?php
/**
 * SEO REST Controller — content-SEO workbench.
 *
 * Thin router: routing + validation + response formatting. Logic lives in
 * PCM_SEO_Service. Operates on the live WordPress posts & pages, so writes
 * are additionally gated per-post against the caller's WP capabilities
 * (edit_post / delete_post) on top of the module's edit_posts requirement.
 *
 * Endpoints:
 *   GET  /seo/content                 → list (types= post,page)
 *   GET  /seo/content/options         → dropdown data + detected SEO plugin
 *   POST /seo/content                 → quick-create a draft
 *   POST /seo/content/{id}/cell       → inline cell save
 *   POST /seo/content/bulk-delete     → trash selected
 *
 * @package PowerCreatives
 * @since   1.23.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_REST_SEO extends PCM_REST_Base
{
    // Work module — usable by team members who can edit posts.
    protected string $default_capability = 'edit_posts';

    private PCM_SEO_Service $service;

    public function __construct()
    {
        require_once __DIR__ . '/service.php';
        $this->service = new PCM_SEO_Service();
    }

    protected function routes(): array
    {
        return array(
            // Static segments before the numeric matcher.
            array('GET',  '/seo/content/options',            'get_options'),
            array('POST', '/seo/content/bulk-delete',        'bulk_delete'),
            array('GET',  '/seo/content',                    'list_content'),
            array('POST', '/seo/content',                    'quick_create'),
            array('POST', '/seo/content/(?P<id>\d+)/duplicate', 'duplicate'),
            array('POST', '/seo/content/(?P<id>\d+)/cell',   'save_cell'),
            array('POST', '/seo/content/(?P<id>\d+)/generate', 'generate_field'),
            array('POST', '/seo/content/(?P<id>\d+)/scan-links', 'scan_links'),
            array('GET',  '/seo/content/(?P<id>\d+)/body',     'get_body'),
            array('POST', '/seo/content/(?P<id>\d+)/body',     'save_body'),
            array('POST', '/seo/content/(?P<id>\d+)/optimize', 'optimize_body'),
            // Remote-site SEO — read + inline-edit a connected site's posts/pages
            // via the connector proxy (admin only; site is owner-scoped in the handler).
            array('GET',  '/seo/sites/(?P<id>\d+)/content', 'remote_content', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content', 'remote_create', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/cell', 'remote_save_cell', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/generate', 'remote_generate_field', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/scan-links', 'remote_scan_links', array(), 'manage_options'),
            array('GET', '/seo/sites/(?P<id>\d+)/site', 'remote_site_get', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/site', 'remote_site_save', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/site/generate', 'remote_site_generate', array(), 'manage_options'),
            array('GET', '/seo/sites/(?P<id>\d+)/ai', 'remote_ai_get', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/ai', 'remote_ai_save', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/ai/build', 'remote_ai_build', array(), 'manage_options'),
            array('GET',  '/seo/sites/(?P<id>\d+)/ai/posts', 'remote_ai_posts', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/ai/site-desc', 'remote_ai_site_desc', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/schema', 'remote_set_schema', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/delete', 'remote_delete', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/duplicate', 'remote_duplicate', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/featured', 'remote_set_featured', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/preview', 'remote_preview', array(), 'manage_options'),
            array('GET',  '/seo/sites/(?P<id>\d+)/llm-info',       'remote_llminfo_get',   array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/llm-info',       'remote_llminfo_save',  array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/llm-info/build', 'remote_llminfo_build', array(), 'manage_options'),
            // Saved views (per-user column/filter configs).
            array('GET',    '/seo/views',                'views_list'),
            array('POST',   '/seo/views',                'views_create'),
            array('PATCH',  '/seo/views/(?P<id>\d+)/default', 'views_set_default'),
            array('DELETE', '/seo/views/(?P<id>\d+)',     'views_delete'),
            // AI Readiness (site-wide → admin only).
            array('GET',  '/seo/ai-readiness',           'air_status',   array(), 'manage_options'),
            array('POST', '/seo/ai-readiness/build',      'air_build',    array(), 'manage_options'),
            array('POST', '/seo/ai-readiness/publish',    'air_publish',  array(), 'manage_options'),
            array('POST', '/seo/ai-readiness/settings',   'air_settings', array(), 'manage_options'),
            array('POST', '/seo/ai-readiness/generate',   'air_generate', array(), 'manage_options'),
            array('POST', '/seo/ai-readiness/summarize',  'air_summarize', array(), 'manage_options'),
            array('POST', '/seo/ai-readiness/save-llms',  'air_save_llms', array(), 'manage_options'),
            array('POST', '/seo/ai-readiness/site-desc',  'air_gen_site_desc', array(), 'manage_options'),
            array('POST', '/seo/ai-readiness/delete-all', 'air_delete_all', array(), 'manage_options'),
            array('GET',  '/seo/llm-info',                'llminfo_get',   array(), 'manage_options'),
            array('POST', '/seo/llm-info',                'llminfo_save',  array(), 'manage_options'),
            array('POST', '/seo/llm-info/build',          'llminfo_build', array(), 'manage_options'),
            // Schema (per-post → edit_post checked in handler).
            array('GET',  '/seo/content/(?P<id>\d+)/schema', 'get_schema'),
            array('POST', '/seo/content/(?P<id>\d+)/schema', 'set_schema'),
            // Site-wide settings (admin only).
            array('GET',  '/seo/site',          'site_get',      array(), 'manage_options'),
            array('POST', '/seo/site',          'site_save',     array(), 'manage_options'),
            array('POST', '/seo/site/generate', 'site_generate', array(), 'manage_options'),
            array('POST', '/seo/site/restore',  'site_restore',  array(), 'manage_options'),
            // GBP (business identity → admin only).
            array('POST', '/seo/gbp/search',                   'gbp_search',    array(), 'manage_options'),
            array('POST', '/seo/gbp/brand/(?P<brand>\d+)/save', 'gbp_save',     array(), 'manage_options'),
            array('GET',  '/seo/gbp/brand/(?P<brand>\d+)',      'gbp_get',      array(), 'manage_options'),
            array('POST', '/seo/gbp/brand/(?P<brand>\d+)/overrides', 'gbp_overrides', array(), 'manage_options'),
            // Export / Import (admin only).
            array('GET',  '/seo/export', 'config_export', array(), 'manage_options'),
            array('POST', '/seo/import', 'config_import', array(), 'manage_options'),
        );
    }

    /** GET /seo/content — list posts/pages with SEO fields. */
    public function list_content(WP_REST_Request $request): WP_REST_Response
    {
        $types_param = $request->get_param('types');
        $types = is_string($types_param) && $types_param !== ''
            ? array_map('sanitize_key', explode(',', $types_param))
            : PCM_SEO_Service::VALID_TYPES;

        return $this->success($this->service->list_content($types));
    }

    /** GET /seo/content/options — dropdown data + detected SEO plugin. */
    public function get_options(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success($this->service->get_options());
    }

    /** POST /seo/content — quick-create a draft post/page. */
    public function quick_create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_json_params() ?: array();
        $type   = sanitize_key($params['type'] ?? 'post');
        if (!in_array($type, PCM_SEO_Service::VALID_TYPES, true)) {
            return $this->error('Invalid content type.', 400, 'pcm_seo_bad_type');
        }
        // Capability for creating this post type.
        $pto = get_post_type_object($type);
        if (!$pto || !current_user_can($pto->cap->create_posts)) {
            return $this->error('You cannot create this content type.', 403, 'pcm_forbidden');
        }

        $id = wp_insert_post(array(
            'post_type'   => $type,
            'post_status' => 'draft',
            'post_title'  => sanitize_text_field($params['title'] ?? __('Untitled', 'power-creatives')),
        ), true);
        if (is_wp_error($id)) {
            return $this->error('Failed to create content: ' . $id->get_error_message(), 500);
        }

        $post = get_post((int) $id);
        return $this->success($this->service->build_row($post), 201);
    }

    /** POST /seo/content/{id}/duplicate — clone a post/page (as draft) with its meta. */
    public function duplicate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id  = absint($request->get_param('id'));
        $src = $id ? get_post($id) : null;
        if (!$src) {
            return $this->not_found('Content');
        }
        if (!current_user_can('edit_post', $id)) {
            return $this->error('You cannot duplicate this content.', 403, 'pcm_forbidden');
        }
        $pto = get_post_type_object($src->post_type);
        if (!$pto || !current_user_can($pto->cap->create_posts)) {
            return $this->error('You cannot create this content type.', 403, 'pcm_forbidden');
        }

        $new_id = $this->service->duplicate($id);
        if ($new_id instanceof WP_Error) {
            return $new_id;
        }
        return $this->success($this->service->build_row(get_post((int) $new_id)), 201);
    }

    /** POST /seo/content/{id}/cell — inline save one cell. */
    public function save_cell(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        if (!current_user_can('edit_post', $id)) {
            return $this->error('You cannot edit this content.', 403, 'pcm_forbidden');
        }

        $params = $request->get_json_params() ?: array();
        // sanitize_text_field (NOT sanitize_key) — field keys are camelCase
        // (metaTitle, primaryKeyword…) and are whitelist-validated downstream.
        $field  = sanitize_text_field($params['field'] ?? '');
        if ($field === '') {
            return $this->error('Field is required.', 400, 'pcm_seo_missing_field');
        }

        $result = $this->service->save_cell($id, $field, $params['value'] ?? '');
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success(array_merge(array('id' => $id), $result));
    }

    /** GET /seo/sites/{id}/content — list a connected site's posts/pages SEO via the proxy. */
    public function remote_content(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        return $this->success(PCM_SEO_Service::remote_list_content($site));
    }

    /** POST /seo/sites/{id}/preview — fetch a connected site's page HTML authenticated
     *  (via the connector's app password) so the frontend can preview it same-origin
     *  with the WP admin bar. */
    public function remote_preview(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $url    = esc_url_raw((string) ($params['url'] ?? ''));
        $result = PCM_SEO_Service::remote_preview_html($site, $url);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/content — create a draft post/page on a connected site. */
    public function remote_create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $type   = sanitize_key($params['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $result = PCM_SEO_Service::remote_create_content($site, $type);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/content/{post}/scan-links — scan a connected site's post links. */
    public function remote_scan_links(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $type   = sanitize_key($params['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $result = PCM_SEO_Service::remote_scan_links($site, absint($request->get_param('post')), $type);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/content/{post}/duplicate — clone a connected site's
     *  post/page (as a draft "(Copy)") with its content + SEO meta, via the connector. */
    public function remote_duplicate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $type   = sanitize_key($params['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $result = PCM_SEO_Service::remote_duplicate_content($site, absint($request->get_param('post')), $type);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/content/{post}/delete — trash a post/page on a connected site. */
    public function remote_delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $type   = sanitize_key($params['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $result = PCM_SEO_Service::remote_delete_content($site, absint($request->get_param('post')), $type);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/content/{post}/featured — set a connected post's featured image (by URL). */
    public function remote_set_featured(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $type   = sanitize_key($params['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $url    = esc_url_raw((string) ($params['imageUrl'] ?? ''));
        if ($url === '') {
            return $this->error('Image URL is required.', 400, 'pcm_seo_missing_image');
        }
        $result = PCM_SEO_Service::remote_set_featured_image($site, absint($request->get_param('post')), $type, $url);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** GET /seo/sites/{id}/site — read a connected site's hub-managed Site settings. */
    public function remote_site_get(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $result = PCM_SEO_Service::remote_site_get($site);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/site — save a connected site's hub-managed Site settings. */
    public function remote_site_save(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $fields = array();
        if (array_key_exists('robots', $params)) {
            $fields['robots'] = sanitize_textarea_field((string) $params['robots']);
        }
        if (array_key_exists('jsonld', $params)) {
            $fields['jsonld'] = (string) $params['jsonld'];
        }
        if (array_key_exists('siteTitle', $params)) {
            $fields['siteTitle'] = sanitize_text_field((string) $params['siteTitle']);
        }
        if (array_key_exists('tagline', $params)) {
            $fields['tagline'] = sanitize_text_field((string) $params['tagline']);
        }
        $result = PCM_SEO_Service::remote_site_save($site, $fields);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/site/generate — generate a site field for a CONNECTED site
     *  (robots / schema / site_title / site_tagline), with the remote site's url/name as
     *  the {{website.url}} / {{business.*}} context. Returns the text (not saved). */
    public function remote_site_generate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params   = $request->get_json_params() ?: array();
        $field    = sanitize_text_field((string) ($params['field'] ?? ''));
        $brand_id = isset($params['brandId']) && $params['brandId'] ? absint($params['brandId']) : null;
        $model    = isset($params['model']) ? sanitize_text_field((string) $params['model']) : null;
        $provider = isset($params['provider']) ? sanitize_text_field((string) $params['provider']) : null;

        $url       = rtrim((string) $site->url, '/');
        $overrides = array(
            'website.url'               => $url,
            'business.website'          => $url,
            'business.website|hostname' => (string) wp_parse_url($url, PHP_URL_HOST),
        );
        // No brand picked → fall back to the connected site's name for {{business.name}}.
        if (!$brand_id && ($site->name ?? '') !== '') {
            $overrides['business.name'] = (string) $site->name;
        }
        $result = $this->service->generate_site_field($field, $brand_id, $model, $user ? (int) $user->id : null, $provider, $overrides);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** GET /seo/sites/{id}/ai — read a connected site's hub-managed AI Readiness. */
    public function remote_ai_get(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $result = PCM_SEO_Service::remote_ai_get($site);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/ai — save a connected site's hub-managed AI Readiness. */
    public function remote_ai_save(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $fields = array();
        if (array_key_exists('llms', $params)) {
            $fields['llms'] = (string) $params['llms'];
        }
        if (array_key_exists('enabled', $params)) {
            $fields['enabled'] = (bool) $params['enabled'];
        }
        $result = PCM_SEO_Service::remote_ai_save($site, $fields);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/ai/build — generate an llms.txt index from the connected site. */
    public function remote_ai_build(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $desc   = isset($params['desc']) ? (string) $params['desc'] : '';
        return $this->success(array('llms' => PCM_SEO_Service::remote_ai_build($site, $desc)));
    }

    /** GET /seo/sites/{id}/ai/posts — list the connected site's published pages (live .md URLs). */
    public function remote_ai_posts(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        return $this->success(array('posts' => PCM_SEO_Service::remote_ai_posts($site)));
    }

    /** POST /seo/sites/{id}/ai/site-desc — AI-generate a site description for the connected site. */
    public function remote_ai_site_desc(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params   = $request->get_json_params() ?: array();
        $model    = isset($params['model']) ? sanitize_text_field((string) $params['model']) : null;
        $provider = isset($params['provider']) ? sanitize_text_field((string) $params['provider']) : null;
        $result   = PCM_SEO_Service::remote_ai_site_desc($site, $model, $user ? (int) $user->id : null, $provider);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/content/{post}/schema — set a connected post's schema types. */
    public function remote_set_schema(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $type   = sanitize_key($params['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $types  = array_map('sanitize_text_field', (array) ($params['types'] ?? array()));
        $result = PCM_SEO_Service::remote_set_schema($site, absint($request->get_param('post')), $type, $types);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/content/{post}/cell — inline-save one SEO field to the remote. */
    public function remote_save_cell(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $field  = sanitize_text_field($params['field'] ?? '');
        $type   = sanitize_key($params['type'] ?? 'post');
        $value  = sanitize_text_field((string) ($params['value'] ?? ''));
        if ($field === '') {
            return $this->error('Field is required.', 400, 'pcm_seo_missing_field');
        }
        $result = PCM_SEO_Service::remote_save_cell($site, absint($request->get_param('post')), $type, $field, $value);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/content/{post}/generate — AI-suggest a remote field value (not saved). */
    public function remote_generate_field(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $field  = sanitize_text_field($params['field'] ?? '');
        $type   = sanitize_key($params['type'] ?? 'post');
        if ($field === '') {
            return $this->error('Field is required.', 400, 'pcm_seo_missing_field');
        }
        $model       = isset($params['model']) ? sanitize_text_field((string) $params['model']) : null;
        $provider    = isset($params['provider']) ? sanitize_text_field((string) $params['provider']) : null;
        $template_id = isset($params['templateId']) ? absint($params['templateId']) : null;
        $result = PCM_SEO_Service::remote_generate_field($site, absint($request->get_param('post')), $type, $field, $model, (int) $user->id, $provider, $template_id ?: null);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/content/{id}/generate — AI-suggest a field value (not saved). */
    public function generate_field(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        if (!current_user_can('edit_post', $id)) {
            return $this->error('You cannot edit this content.', 403, 'pcm_forbidden');
        }

        $params = $request->get_json_params() ?: array();
        // sanitize_text_field (NOT sanitize_key) — field keys are camelCase
        // (metaTitle, primaryKeyword…) and are whitelist-validated downstream.
        $field  = sanitize_text_field($params['field'] ?? '');
        if ($field === '') {
            return $this->error('Field is required.', 400, 'pcm_seo_missing_field');
        }
        $brand_id = isset($params['brandId']) && $params['brandId'] ? absint($params['brandId']) : null;
        $model    = isset($params['model']) ? sanitize_text_field((string) $params['model']) : null;
        $provider = isset($params['provider']) ? sanitize_text_field((string) $params['provider']) : null;
        $template_id = isset($params['templateId']) && $params['templateId'] ? absint($params['templateId']) : null;

        $user   = $this->get_current_pcm_user();
        $result = $this->service->generate_field($id, $field, $brand_id, $model, $user ? (int) $user->id : null, $provider, $template_id);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success(array_merge(array('id' => $id), $result));
    }

    /** POST /seo/content/{id}/scan-links — count internal/external + broken links. */
    public function scan_links(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        if (!current_user_can('edit_post', $id)) {
            return $this->error('You cannot edit this content.', 403, 'pcm_forbidden');
        }
        $result = $this->service->scan_links($id);
        return $this->success(array_merge(array('id' => $id), $result));
    }

    // =====================================================================
    // Body (Optimize Content modal)
    // =====================================================================

    /** GET /seo/content/{id}/body — the post's raw HTML body. */
    public function get_body(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        $post = $id ? get_post($id) : null;
        if (!$post) {
            return $this->not_found('Content');
        }
        return $this->success(array('id' => $id, 'body' => $post->post_content));
    }

    /** POST /seo/content/{id}/body — save an edited/accepted body. */
    public function save_body(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        if (!current_user_can('edit_post', $id)) {
            return $this->error('You cannot edit this content.', 403, 'pcm_forbidden');
        }
        $params = $request->get_json_params() ?: array();
        $body   = wp_kses_post((string) ($params['body'] ?? ''));
        wp_update_post(array('ID' => $id, 'post_content' => $body));
        return $this->success(array('id' => $id, 'body' => $body));
    }

    /** POST /seo/content/{id}/optimize — AI-optimize the body (NOT saved). */
    public function optimize_body(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        if (!current_user_can('edit_post', $id)) {
            return $this->error('You cannot edit this content.', 403, 'pcm_forbidden');
        }
        $params   = $request->get_json_params() ?: array();
        $brand_id = isset($params['brandId']) && $params['brandId'] ? absint($params['brandId']) : null;
        $model    = isset($params['model']) ? sanitize_text_field((string) $params['model']) : null;
        $user     = $this->get_current_pcm_user();
        $result   = $this->service->optimize_body($id, $brand_id, $model, $user ? (int) $user->id : null);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success(array_merge(array('id' => $id), $result));
    }

    // =====================================================================
    // Saved Views (per-user column/filter configurations)
    // =====================================================================

    /** GET /seo/views — the current user's saved views, newest first. */
    public function views_list(WP_REST_Request $request): WP_REST_Response
    {
        $user = $this->get_current_pcm_user();
        return $this->success($this->service->list_views((int) $user->id));
    }

    /** POST /seo/views — create a saved view for the current user. */
    public function views_create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_json_params() ?: array();
        $name   = sanitize_text_field((string) ($params['name'] ?? ''));
        if ($name === '') {
            return $this->error('Name is required.', 400, 'pcm_seo_view_no_name');
        }
        $name = mb_substr($name, 0, 191);

        $config = $params['config'] ?? null;
        if (!is_array($config)) {
            return $this->error('Config must be an object.', 400, 'pcm_seo_view_bad_config');
        }

        $user = $this->get_current_pcm_user();
        return $this->success($this->service->create_view((int) $user->id, $name, $config), 201);
    }

    /**
     * PATCH /seo/views/{id}/default — set (or clear) this view as the user's
     * default. Enforces at most one default per user. Body: { isDefault: bool }
     * (omitted/true makes it the default).
     */
    public function views_set_default(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id     = absint($request->get_param('id'));
        $params = $request->get_json_params() ?: array();
        $isDefault = array_key_exists('isDefault', $params) ? (bool) $params['isDefault'] : true;

        $user = $this->get_current_pcm_user();
        if (!$this->service->set_default_view($id, (int) $user->id, $isDefault)) {
            return $this->not_found('View');
        }
        return $this->success(array('id' => $id, 'isDefault' => $isDefault));
    }

    /** DELETE /seo/views/{id} — delete a view owned by the current user. */
    public function views_delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        $user = $this->get_current_pcm_user();
        if (!$this->service->delete_view($id, (int) $user->id)) {
            return $this->not_found('View');
        }
        return $this->success(array('deleted' => true));
    }

    // =====================================================================
    // Schema (per-post)
    // =====================================================================

    /** GET /seo/content/{id}/schema — active types + available type list. */
    public function get_schema(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        return $this->success(array(
            'id'        => $id,
            'types'     => PCM_SEO_Schema::types_for($id),
            'available' => PCM_SEO_Schema::TYPES,
        ));
    }

    /** POST /seo/content/{id}/schema — set the active schema types (whitelist). */
    public function set_schema(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        if (!current_user_can('edit_post', $id)) {
            return $this->error('You cannot edit this content.', 403, 'pcm_forbidden');
        }
        $params = $request->get_json_params() ?: array();
        $types  = is_array($params['types'] ?? null) ? array_map('sanitize_text_field', $params['types']) : array();
        return $this->success(array('id' => $id, 'types' => PCM_SEO_Schema::set_types($id, $types)));
    }

    // =====================================================================
    // Site-wide settings
    // =====================================================================

    /** GET /seo/site — current site SEO settings. */
    public function site_get(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(PCM_SEO_Site::get_settings());
    }

    /** POST /seo/site — save site SEO settings (robots / schema / lang / tz). */
    public function site_save(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?: array();
        return $this->success(PCM_SEO_Site::save($params));
    }

    /** POST /seo/site/generate — AI-generate a site field (robots / schema) from its
     *  editable prompt. Returns the text; the Site tab previews + saves it. */
    public function site_generate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params   = $request->get_json_params() ?: array();
        $field    = sanitize_text_field((string) ($params['field'] ?? ''));
        $brand_id = isset($params['brandId']) && $params['brandId'] ? absint($params['brandId']) : null;
        $model    = isset($params['model']) ? sanitize_text_field((string) $params['model']) : null;
        $provider = isset($params['provider']) ? sanitize_text_field((string) $params['provider']) : null;

        $user   = $this->get_current_pcm_user();
        $result = $this->service->generate_site_field($field, $brand_id, $model, $user ? (int) $user->id : null, $provider);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/site/restore — restore language/timezone from backup. */
    public function site_restore(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?: array();
        $what   = sanitize_key($params['what'] ?? 'all');
        return $this->success(PCM_SEO_Site::restore(in_array($what, array('language', 'timezone', 'all'), true) ? $what : 'all'));
    }

    // =====================================================================
    // Export / Import
    // =====================================================================

    /** GET /seo/export — the SEO config bundle (JSON). */
    public function config_export(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(PCM_SEO_Export::export());
    }

    /** POST /seo/import — apply a config bundle. */
    public function config_import(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_json_params() ?: array();
        $config = is_array($params['config'] ?? null) ? $params['config'] : array();
        if (empty($config)) {
            return $this->error('No config provided.', 400, 'pcm_seo_no_config');
        }
        $result = PCM_SEO_Export::import($config);
        if (isset($result['error'])) {
            return $this->error((string) $result['error'], 400, 'pcm_seo_bad_config');
        }
        return $this->success($result);
    }

    // =====================================================================
    // GBP (Google Business Profile)
    // =====================================================================

    /** POST /seo/gbp/search — search places (via the configured provider). */
    public function gbp_search(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?: array();
        $query  = sanitize_text_field((string) ($params['query'] ?? ''));
        if ($query === '') {
            return $this->error('Search query is required.', 400, 'pcm_seo_gbp_no_query');
        }
        $lang   = sanitize_text_field((string) ($params['language'] ?? PCM_SEO_GBP::default_lang()));
        $raw    = PCM_SEO_GBP::provider()->search($query, $lang);
        if (isset($raw['error'])) {
            return $this->error((string) $raw['error'], 502, 'pcm_seo_gbp_error');
        }
        $results = array_map(array('PCM_SEO_GBP', 'normalize'), $raw);
        return $this->success(array('results' => $results));
    }

    /** POST /seo/gbp/brand/{brand}/save — fetch details + store snapshot on the brand. */
    public function gbp_save(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $brand_id = absint($request->get_param('brand'));
        $params   = $request->get_json_params() ?: array();
        $place_id = sanitize_text_field((string) ($params['placeId'] ?? ''));
        if (!$brand_id || $place_id === '') {
            return $this->error('brand and placeId are required.', 400, 'pcm_seo_gbp_bad_input');
        }
        $lang = sanitize_text_field((string) ($params['language'] ?? PCM_SEO_GBP::default_lang()));
        $raw  = PCM_SEO_GBP::provider()->details($place_id, $lang);
        if (isset($raw['error'])) {
            return $this->error((string) $raw['error'], 502, 'pcm_seo_gbp_error');
        }
        if (empty($raw)) {
            return $this->error('No details returned for that place.', 502, 'pcm_seo_gbp_empty');
        }
        $normalized = PCM_SEO_GBP::normalize($raw);
        return $this->success(PCM_SEO_GBP::save_snapshot($brand_id, $normalized));
    }

    /** GET /seo/gbp/brand/{brand} — stored business record for a brand. */
    public function gbp_get(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(PCM_SEO_GBP::get_for_brand(absint($request->get_param('brand'))));
    }

    /** POST /seo/gbp/brand/{brand}/overrides — manual field overrides (survive refresh). */
    public function gbp_overrides(WP_REST_Request $request): WP_REST_Response
    {
        $brand_id  = absint($request->get_param('brand'));
        $params    = $request->get_json_params() ?: array();
        $overrides = is_array($params['overrides'] ?? null) ? $params['overrides'] : array();
        return $this->success(PCM_SEO_GBP::save_overrides($brand_id, $overrides));
    }

    // =====================================================================
    // AI Readiness
    // =====================================================================

    /** GET /seo/ai-readiness — published state, settings, urls, per-post status. */
    public function air_status(WP_REST_Request $request): WP_REST_Response
    {
        $posts    = array();
        $excluded = array_map('intval', PCM_SEO_AIReadiness::settings()['excluded_ids']);
        // List ALL eligible posts (excluded ones marked) so the table can toggle inclusion.
        foreach (PCM_SEO_AIReadiness::query_posts(false) as $p) {
            $posts[] = array_merge(array(
                'id'       => (int) $p->ID,
                'title'    => $p->post_title,
                'type'     => $p->post_type,
                'status'   => PCM_SEO_AIReadiness::status_for((int) $p->ID),
                'mdUrl'    => PCM_SEO_AIReadiness::md_url((int) $p->ID),
                'excluded' => in_array((int) $p->ID, $excluded, true),
            ), PCM_SEO_AIReadiness::post_meta_row((int) $p->ID));
        }
        return $this->success(array(
            'published'  => PCM_SEO_AIReadiness::is_published(),
            'settings'   => PCM_SEO_AIReadiness::settings(),
            'llmsUrl'    => home_url('/llms.txt'),
            'llmsFullUrl' => home_url('/llms-full.txt'),
            'llmsTxt'    => get_option(PCM_SEO_AIReadiness::OPT_LLMS, ''),
            'posts'      => $posts,
        ));
    }

    /** POST /seo/ai-readiness/build — (re)generate per-post .md + both llms files. */
    public function air_build(WP_REST_Request $request): WP_REST_Response
    {
        foreach (PCM_SEO_AIReadiness::query_posts() as $p) {
            PCM_SEO_AIReadiness::generate_md((int) $p->ID);
        }
        return $this->success(PCM_SEO_AIReadiness::build_index());
    }

    /** POST /seo/ai-readiness/publish — toggle virtual routes on/off. */
    public function air_publish(WP_REST_Request $request): WP_REST_Response
    {
        $params    = $request->get_json_params() ?: array();
        $published = !empty($params['published']);
        update_option(PCM_SEO_AIReadiness::OPT_PUBLISHED, $published);
        if ($published) {
            PCM_SEO_AIReadiness::flush();
        } else {
            PCM_SEO_AIReadiness::flush_remove();
        }
        return $this->success(array('published' => $published));
    }

    /** POST /seo/ai-readiness/settings — save settings (max_posts clamped 1–200). */
    public function air_settings(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?: array();
        $clean  = array(
            'post_types'       => isset($params['post_types']) && is_array($params['post_types'])
                ? array_values(array_intersect(array_map('sanitize_key', $params['post_types']), get_post_types(array('public' => true))))
                : array('page', 'post'),
            'excluded_ids'     => isset($params['excluded_ids']) && is_array($params['excluded_ids'])
                ? array_map('absint', $params['excluded_ids']) : array(),
            'max_posts'        => max(1, min(200, (int) ($params['max_posts'] ?? 50))),
            'site_description'  => sanitize_text_field((string) ($params['site_description'] ?? '')),
        );
        if (empty($clean['post_types'])) {
            $clean['post_types'] = array('page', 'post');
        }
        update_option(PCM_SEO_AIReadiness::OPT_SETTINGS, $clean, false);
        return $this->success(array('settings' => $clean));
    }

    /** POST /seo/ai-readiness/generate — (re)generate one post's markdown. */
    public function air_generate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_json_params() ?: array();
        $id     = absint($params['id'] ?? 0);
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        $md = PCM_SEO_AIReadiness::generate_md($id);
        return $this->success(array('id' => $id, 'bytes' => strlen($md), 'status' => PCM_SEO_AIReadiness::status_for($id)));
    }

    /** POST /seo/ai-readiness/summarize — AI-generate one post's directory summary. */
    public function air_summarize(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_json_params() ?: array();
        $id     = absint($params['id'] ?? 0);
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        $model    = isset($params['model']) ? sanitize_text_field((string) $params['model']) : null;
        $provider = isset($params['provider']) ? sanitize_text_field((string) $params['provider']) : null;
        $user     = $this->get_current_pcm_user();
        $result   = PCM_SEO_AIReadiness::summarize($id, $model, $user ? (int) $user->id : null, $provider);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success(array_merge(array('id' => $id), $result));
    }

    /** POST /seo/ai-readiness/save-llms — persist a hand-edited llms.txt. */
    public function air_save_llms(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?: array();
        PCM_SEO_AIReadiness::save_llms((string) ($params['llms'] ?? ''));
        return $this->success(array('saved' => true));
    }

    /** POST /seo/ai-readiness/site-desc — AI-generate a site description from the top pages. */
    public function air_gen_site_desc(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params   = $request->get_json_params() ?: array();
        $model    = isset($params['model']) ? sanitize_text_field((string) $params['model']) : null;
        $provider = isset($params['provider']) ? sanitize_text_field((string) $params['provider']) : null;
        $user     = $this->get_current_pcm_user();
        $result   = PCM_SEO_AIReadiness::gen_site_description($model, $user ? (int) $user->id : null, $provider);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/ai-readiness/delete-all — reset all AI Readiness data. */
    public function air_delete_all(WP_REST_Request $request): WP_REST_Response
    {
        PCM_SEO_AIReadiness::delete_all();
        return $this->success(array('deleted' => true));
    }

    /** GET /seo/llm-info — current /llm-info/ settings + public URL. */
    public function llminfo_get(WP_REST_Request $request): WP_REST_Response
    {
        $s        = PCM_SEO_AIReadiness::llm_info();
        $s['url'] = home_url('/llm-info/');
        return $this->success($s);
    }

    /** POST /seo/llm-info — save /llm-info/ settings (inputs + generated HTML). */
    public function llminfo_save(WP_REST_Request $request): WP_REST_Response
    {
        $p    = $request->get_json_params() ?: array();
        $cur  = PCM_SEO_AIReadiness::llm_info();
        $next = array(
            'enabled'   => array_key_exists('enabled', $p) ? (bool) $p['enabled'] : (bool) $cur['enabled'],
            'keywords'  => array_key_exists('keywords', $p) ? sanitize_text_field((string) $p['keywords']) : $cur['keywords'],
            'years'     => array_key_exists('years', $p) ? sanitize_text_field((string) $p['years']) : $cur['years'],
            'area'      => array_key_exists('area', $p) ? sanitize_text_field((string) $p['area']) : $cur['area'],
            'strengths' => array_key_exists('strengths', $p) ? sanitize_textarea_field((string) $p['strengths']) : $cur['strengths'],
            'content'   => array_key_exists('content', $p) ? wp_kses_post((string) $p['content']) : $cur['content'],
        );
        update_option(PCM_SEO_AIReadiness::OPT_LLMINFO, $next, false);
        $next['url'] = home_url('/llm-info/');
        return $this->success($next);
    }

    /** POST /seo/llm-info/build — generate the /llm-info/ HTML (not saved; caller saves on accept). */
    public function llminfo_build(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $p        = $request->get_json_params() ?: array();
        $model    = isset($p['model']) ? sanitize_text_field((string) $p['model']) : null;
        $provider = isset($p['provider']) ? sanitize_text_field((string) $p['provider']) : null;
        $user     = $this->get_current_pcm_user();
        $cur      = PCM_SEO_AIReadiness::llm_info();
        $ctx      = array(
            'name'      => get_bloginfo('name'),
            'url'       => home_url('/'),
            'keywords'  => isset($p['keywords']) ? sanitize_text_field((string) $p['keywords']) : $cur['keywords'],
            'years'     => isset($p['years']) ? sanitize_text_field((string) $p['years']) : $cur['years'],
            'area'      => isset($p['area']) ? sanitize_text_field((string) $p['area']) : $cur['area'],
            'strengths' => isset($p['strengths']) ? sanitize_textarea_field((string) $p['strengths']) : $cur['strengths'],
            'pages'     => PCM_SEO_Service::local_content_corpus(),
        );
        $html = PCM_SEO_Service::build_llm_info($ctx, $model, $user ? (int) $user->id : null, $provider);
        if ($html instanceof WP_Error) {
            return $html;
        }
        return $this->success(array('content' => $html));
    }

    /** GET /seo/sites/{id}/llm-info — read a connected site's /llm-info/. */
    public function remote_llminfo_get(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $result = PCM_SEO_Service::remote_llminfo_get($site);
        return $result instanceof WP_Error ? $result : $this->success($result);
    }

    /** POST /seo/sites/{id}/llm-info — save a connected site's /llm-info/ content + enabled. */
    public function remote_llminfo_save(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $p      = $request->get_json_params() ?: array();
        $fields = array();
        if (array_key_exists('content', $p)) {
            $fields['content'] = wp_kses_post((string) $p['content']);
        }
        if (array_key_exists('enabled', $p)) {
            $fields['enabled'] = (bool) $p['enabled'];
        }
        $result = PCM_SEO_Service::remote_llminfo_save($site, $fields);
        return $result instanceof WP_Error ? $result : $this->success($result);
    }

    /** POST /seo/sites/{id}/llm-info/build — generate a connected site's /llm-info/ HTML. */
    public function remote_llminfo_build(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $p        = $request->get_json_params() ?: array();
        $model    = isset($p['model']) ? sanitize_text_field((string) $p['model']) : null;
        $provider = isset($p['provider']) ? sanitize_text_field((string) $p['provider']) : null;
        $inputs   = array(
            'keywords'  => isset($p['keywords']) ? sanitize_text_field((string) $p['keywords']) : '',
            'years'     => isset($p['years']) ? sanitize_text_field((string) $p['years']) : '',
            'area'      => isset($p['area']) ? sanitize_text_field((string) $p['area']) : '',
            'strengths' => isset($p['strengths']) ? sanitize_textarea_field((string) $p['strengths']) : '',
        );
        $result = PCM_SEO_Service::remote_llminfo_build($site, $inputs, $model, (int) $user->id, $provider);
        return $result instanceof WP_Error ? $result : $this->success($result);
    }

    /** POST /seo/content/bulk-delete — trash selected posts. */
    public function bulk_delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $params = $request->get_json_params() ?: array();
        $ids    = is_array($params['ids'] ?? null) ? array_map('absint', $params['ids']) : array();
        if (empty($ids)) {
            return $this->error('No ids provided.', 400, 'pcm_seo_no_ids');
        }

        $deleted = array();
        $failed  = array();
        foreach ($ids as $id) {
            if ($id && get_post($id) && current_user_can('delete_post', $id) && wp_trash_post($id)) {
                $deleted[] = $id;
            } else {
                $failed[] = $id;
            }
        }
        return $this->success(array('deleted' => $deleted, 'failed' => $failed));
    }
}
