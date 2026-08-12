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

    private PCM_SEO_Editing $editing;

    public function __construct()
    {
        require_once __DIR__ . '/service.php';
        $this->service = new PCM_SEO_Service();
        $this->editing = new PCM_SEO_Editing();
    }

    protected function routes(): array
    {
        return array(
            // Static segments before the numeric matcher.
            array('GET',  '/seo/content/options',            'get_options'),
            // Creating a WordPress USER — gated at manage_options, NOT the module's
            // edit_posts default. A second, explicit create_users check lives in the
            // handler (see create_author).
            array('POST', '/seo/authors',                    'create_author', array(), 'manage_options'),
            array('POST', '/seo/content/bulk-delete',        'bulk_delete'),
            array('GET',  '/seo/content',                    'list_content'),
            array('POST', '/seo/content',                    'quick_create'),
            array('POST', '/seo/content/(?P<id>\d+)/duplicate', 'duplicate'),
            array('POST', '/seo/content/(?P<id>\d+)/cell',   'save_cell'),
            array('POST', '/seo/content/(?P<id>\d+)/generate', 'generate_field'),
            array('POST', '/seo/content/(?P<id>\d+)/scan-links', 'scan_links'),
            array('GET',  '/seo/content/(?P<id>\d+)/links', 'get_links'),
            array('POST', '/seo/content/(?P<id>\d+)/links/(?P<idx>\d+)', 'update_link'),
            array('POST', '/seo/content/(?P<id>\d+)/links/(?P<idx>\d+)/remove', 'remove_link'),
            array('GET',  '/seo/content/(?P<id>\d+)/headings', 'get_headings'),
            array('GET',  '/seo/content/(?P<id>\d+)/content-nodes', 'get_content_nodes'),
            array('POST', '/seo/content/(?P<id>\d+)/headings/(?P<idx>\d+)', 'update_heading'),
            array('POST', '/seo/content/(?P<id>\d+)/headings/(?P<idx>\d+)/optimize', 'optimize_heading'),
            array('GET',  '/seo/content/(?P<id>\d+)/body',     'get_body'),
            array('POST', '/seo/content/(?P<id>\d+)/body',     'save_body'),
            array('POST', '/seo/content/(?P<id>\d+)/optimize', 'optimize_body'),
            // Remote-site SEO — read + inline-edit a connected site's posts/pages
            // via the connector proxy (admin only; site is owner-scoped in the handler).
            // Authors ON a connected site — the remote twin of /seo/authors. Both are
            // manage_options: creating a user on a client's install is the same
            // privilege-escalation surface as creating one here.
            array('GET',  '/seo/sites/(?P<id>\d+)/authors', 'remote_authors', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/authors', 'remote_create_author', array(), 'manage_options'),
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
            array('GET',  '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/links', 'remote_get_links', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/links/(?P<idx>\d+)', 'remote_update_link', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/links/(?P<idx>\d+)/remove', 'remote_remove_link', array(), 'manage_options'),
            array('GET',  '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/headings', 'remote_get_headings', array(), 'manage_options'),
            array('GET',  '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/content-nodes', 'remote_get_content_nodes', array(), 'manage_options'),
            array('GET',  '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/inventory', 'remote_get_inventory', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/migrate-overrides', 'remote_migrate_overrides', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/push-config', 'remote_push_config', array(), 'manage_options'),
            array('GET',  '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/rules', 'remote_list_rules', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/section-rule', 'remote_save_section_rule', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/page-edits', 'remote_save_page_edits', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/image-rule', 'remote_save_image_rule', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/media', 'remote_add_media', array(), 'manage_options'),
            array('GET',  '/seo/sites/(?P<id>\d+)/redirects', 'remote_list_redirects', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/redirects', 'remote_save_redirect', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/redirects/(?P<rid>\d+)/delete', 'remote_delete_redirect', array(), 'manage_options'),
            array('GET',  '/seo/sites/(?P<id>\d+)/url-usage', 'remote_url_usage', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/page-type', 'remote_save_page_type', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/section-optimize', 'remote_optimize_section', array(), 'manage_options'),
            // THE FEATHERWEIGHT CHECK (gap e48b1ff): version+fingerprint compare, no render.
            array('GET', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/page-state', 'remote_page_state', array(), 'manage_options'),
            array('GET',  '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/section-versions', 'remote_section_versions', array(), 'manage_options'),
            array('GET',  '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/page-versions', 'remote_page_versions', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/section-versions/(?P<vid>\d+)/delete', 'remote_delete_section_version', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/headings/(?P<idx>\d+)', 'remote_update_heading', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/headings/(?P<idx>\d+)/optimize', 'remote_optimize_heading', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/content/(?P<post>\d+)/featured', 'remote_set_featured', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/preview', 'remote_preview', array(), 'manage_options'),
            array('GET',  '/seo/sites/(?P<id>\d+)/llm-info',       'remote_llminfo_get',   array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/llm-info',       'remote_llminfo_save',  array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/llm-info/build', 'remote_llminfo_build', array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/llm-info/keywords', 'remote_llminfo_keywords', array(), 'manage_options'),
            // Saved views (per-user column/filter configs).
            array('GET',    '/seo/views',                'views_list'),
            array('POST',   '/seo/views',                'views_create'),
            array('PATCH',  '/seo/views/(?P<id>\d+)/default', 'views_set_default'),
            array('PATCH',  '/seo/views/(?P<id>\d+)/name',    'views_rename'),
            array('PATCH',  '/seo/views/(?P<id>\d+)/pin',     'views_set_pinned'),
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
            array('POST', '/seo/llm-info/keywords',       'llminfo_keywords', array(), 'manage_options'),
            // Schema (per-post → edit_post checked in handler).
            array('GET',  '/seo/content/(?P<id>\d+)/schema', 'get_schema'),
            array('POST', '/seo/content/(?P<id>\d+)/schema', 'set_schema'),
            // Site-wide settings (admin only).
            array('GET',  '/seo/site',          'site_get',      array(), 'manage_options'),
            array('POST', '/seo/site',          'site_save',     array(), 'manage_options'),
            array('POST', '/seo/site/generate', 'site_generate', array(), 'manage_options'),
            array('POST', '/seo/site/restore',  'site_restore',  array(), 'manage_options'),
            // GBP (business identity → admin only).
            // THE BUSINESS CARD (gap 616870f): SEO consumes — reads the
            // resolved per-site record, writes ONLY its own site-override
            // layer + orchestrates brand-unit merges; the site↔brand
            // CONNECTION itself is written through the SITES module.
            array('GET',  '/seo/sites/(?P<id>\d+)/business',            'business_card',       array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/business/overrides',  'business_overrides',  array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/business/refresh',    'business_refresh',    array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/business/maps',       'business_maps',       array(), 'manage_options'),
            array('POST', '/seo/sites/(?P<id>\d+)/business/place',      'business_place',      array(), 'manage_options'),
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

        return $this->success(PCM_SEO_Local::list_content($types));
    }

    /** GET /seo/content/options — dropdown data + detected SEO plugin. */
    public function get_options(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success($this->editing->get_options());
    }

    /**
     * POST /seo/authors — create a WordPress user to assign as a post author.
     *
     * Input: { name: string, email: string }.
     *
     * SECURITY. This creates a real WP user, so it is deliberately stricter than the rest of
     * this controller:
     *  - the route is gated at `manage_options` (the module default is only `edit_posts`);
     *  - `create_users` is re-checked here, because on Multisite an admin does NOT hold it and
     *    the route capability alone would let the call through;
     *  - the role is FIXED to `author` and never taken from input — accepting a role would turn
     *    this into a privilege-escalation endpoint;
     *  - the password is generated, never accepted, so this can't be used to set a known
     *    credential on an account.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function create_author(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!current_user_can('create_users')) {
            return $this->error('You do not have permission to create users.', 403);
        }
        $p     = $request->get_json_params();
        $name  = is_array($p) ? sanitize_text_field((string) ($p['name'] ?? '')) : '';
        $email = is_array($p) ? sanitize_email((string) ($p['email'] ?? '')) : '';
        if ($name === '') {
            return $this->error('A name is required.', 400);
        }
        if ($email === '' || !is_email($email)) {
            return $this->error('A valid email address is required.', 400);
        }
        if (email_exists($email)) {
            return $this->error('A user with that email already exists.', 409);
        }

        // Derive a login from the name, then de-duplicate. sanitize_user() can empty a
        // non-latin name entirely, so fall back to the email's local part before giving up.
        $base = sanitize_user(sanitize_title($name), true);
        if ($base === '') {
            $base = sanitize_user((string) strstr($email, '@', true), true);
        }
        if ($base === '') {
            return $this->error('Could not derive a username from that name — try a different one.', 400);
        }
        $login = $base;
        for ($i = 2; username_exists($login) && $i < 100; $i++) {
            $login = $base . $i;
        }
        if (username_exists($login)) {
            return $this->error('Could not find a free username for that name.', 409);
        }

        $user_id = wp_insert_user(array(
            'user_login'   => $login,
            'user_email'   => $email,
            'display_name' => $name,
            'nickname'     => $name,
            'user_pass'    => wp_generate_password(24, true, true), // generated, never from input
            'role'         => 'author',                            // FIXED — never from input
        ));
        if (is_wp_error($user_id)) {
            return $this->error($user_id->get_error_message(), 400);
        }
        return $this->success(array('id' => (int) $user_id, 'name' => $name));
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
        return $this->success(PCM_SEO_Local::build_row($post), 201);
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

        $new_id = PCM_SEO_Local::duplicate($id);
        if ($new_id instanceof WP_Error) {
            return $new_id;
        }
        return $this->success(PCM_SEO_Local::build_row(get_post((int) $new_id)), 201);
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

        $result = PCM_SEO_Local::save_cell($id, $field, $params['value'] ?? '');
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
        // SWR (gap fad81ea P4): ?cached=1 answers from the hub's stored copy
        // in milliseconds — the table renders INSTANTLY while the live fetch
        // runs as a separate background request. A cache miss returns
        // {miss:true} (not an empty list — an absent copy must never render
        // as "no content"). The reply shape for rows stays the bare array
        // (frozen frontend contract).
        if ($request->get_param('cached')) {
            $cache = get_option('pcm_remote_content_cache');
            $rec   = is_array($cache) ? ($cache[(int) $site->id] ?? null) : null;
            return $this->success(is_array($rec) && is_array($rec['rows'] ?? null)
                ? $rec['rows']
                : array('miss' => true));
        }
        $rows = PCM_SEO_Service::remote_list_content($site);
        if (is_array($rows)) {
            // The live truth feeds the store — next mount opens instantly.
            $cache = get_option('pcm_remote_content_cache');
            if (!is_array($cache)) {
                $cache = array();
            }
            $cache[(int) $site->id] = array('rows' => array_slice($rows, 0, 500), 'savedAt' => time());
            update_option('pcm_remote_content_cache', $cache, false);
        }
        return $this->success($rows);
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

    /** GET /seo/sites/{id}/content/{post}/links — per-link details for a connected post. */
    public function remote_get_links(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $type = sanitize_key($request->get_param('type') ?? 'post') === 'page' ? 'page' : 'post';
        return $this->success(array('links' => PCM_SEO_Service::remote_get_links($site, absint($request->get_param('post')), $type)));
    }

    /** POST /seo/sites/{id}/content/{post}/links/{idx} — edit a connected post's link. */
    public function remote_update_link(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $type   = sanitize_key($params['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $anchor   = array_key_exists('anchor', $params) ? (string) $params['anchor'] : null;
        $href     = array_key_exists('href', $params) ? (string) $params['href'] : null;
        $old_href   = array_key_exists('oldHref', $params) ? (string) $params['oldHref'] : null;
        $el_id      = array_key_exists('elId', $params) ? (string) $params['elId'] : null;
        $old_anchor = array_key_exists('oldAnchor', $params) ? (string) $params['oldAnchor'] : null;
        $result = PCM_SEO_Service::remote_update_link($site, absint($request->get_param('post')), $type, absint($request->get_param('idx')), $anchor, $href, $old_href, $el_id, $old_anchor);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success(array('links' => $result));
    }

    /** POST /seo/sites/{id}/content/{post}/links/{idx}/remove — unwrap a connected post's link. */
    public function remote_remove_link(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $type   = sanitize_key($params['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $result = PCM_SEO_Service::remote_remove_link($site, absint($request->get_param('post')), $type, absint($request->get_param('idx')));
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success(array('links' => $result));
    }

    /** GET /seo/sites/{id}/content/{post}/headings — a connected post's H1–H6 outline. */
    public function remote_get_headings(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $type = sanitize_key($request->get_param('type') ?? 'post') === 'page' ? 'page' : 'post';
        return $this->success(array('headings' => PCM_SEO_Remote_Headings::remote_get_headings($site, absint($request->get_param('post')), $type, (int) $user->id)));
    }

    /** GET /seo/sites/{id}/content/{post}/content-nodes — the connected post's paragraph
     *  inventory (scan-content v1) in rendered document order, with display anchors. */
    public function remote_get_content_nodes(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        return $this->success(PCM_SEO_Service::remote_get_content_nodes($site, absint($request->get_param('post'))));
    }

    /** GET /seo/sites/{id}/content/{post}/inventory — the outline's ONE read (cleanup C2):
     *  heading rows + paragraph nodes from a single hub-side snapshot parse on v3
     *  connectors; honest legacy-scan composition on the pre-3.0 fleet. */
    public function remote_get_inventory(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $type = sanitize_key($request->get_param('type') ?? 'post') === 'page' ? 'page' : 'post';
        return $this->success(PCM_SEO_Page_Inventory::remote_get_inventory($site, absint($request->get_param('post')), $type, (int) $user->id));
    }

    /** POST /seo/sites/{id}/migrate-overrides — cleanup C4: convert the site's legacy
     *  heading overrides into site-scope heading instructions (verified, then cleared). */
    public function remote_migrate_overrides(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $result = PCM_SEO_Editing::migrate_site_overrides((int) $user->id, $site);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/push-config — cleanup C5: push the hub-controlled
     *  connector tunables to the site (v3+; pushed:false honestly on older). */
    public function remote_push_config(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        return $this->success(PCM_SEO_Service::push_connector_config($site));
    }

    /** GET /seo/sites/{id}/content/{post}/rules — the hub's dynamic rules for the post (UI overlay). */
    public function remote_list_rules(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        return $this->success(array('rules' => $this->service->list_dynamic_rules((int) $user->id, (int) $site->id, absint($request->get_param('post')))));
    }

    /**
     * POST /seo/sites/{id}/content/{post}/section-rule — save a section's dynamic
     * rule (contracts v2/v2.2). `kind:'replace'` (default) swaps a whole existing
     * section; `kind:'insert'` adds a NEW section anchored to an existing heading;
     * `kind:'slice'` edits ONE unit range of an owning rule's replacement (the
     * served-truth editor's path for rule-born sections).
     */
    public function remote_save_section_rule(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $kind   = in_array((string) ($params['kind'] ?? 'replace'), array('insert', 'slice'), true) ? (string) $params['kind'] : 'replace';
        if ($kind === 'slice') {
            $result = $this->editing->save_section_slice((int) $user->id, $site, absint($request->get_param('post')), array(
                'ruleId'      => (int) ($params['ruleId'] ?? 0),
                'unitFrom'    => (int) ($params['unitFrom'] ?? 0),
                'unitTo'      => (int) ($params['unitTo'] ?? 0),
                'replacement' => (string) ($params['replacement'] ?? ''),
            ));
        } elseif ($kind === 'insert') {
            $result = $this->editing->save_section_insert((int) $user->id, $site, absint($request->get_param('post')), array(
                'anchorText'       => (string) ($params['anchorText'] ?? ''),
                'anchorLevel'      => (int) ($params['anchorLevel'] ?? 2),
                'anchorOccurrence' => (int) ($params['anchorOccurrence'] ?? 0),
                'position'         => (string) ($params['position'] ?? 'after'),
                'replacement'      => (string) ($params['replacement'] ?? ''),
                'ruleId'           => (int) ($params['ruleId'] ?? 0),
            ));
        } else {
            $result = $this->editing->save_section_rule((int) $user->id, $site, absint($request->get_param('post')), array(
                'headingText'       => (string) ($params['headingText'] ?? ''),
                'headingLevel'      => (int) ($params['headingLevel'] ?? 2),
                'headingOccurrence' => (int) ($params['headingOccurrence'] ?? 0),
                'paragraphs'        => (isset($params['paragraphs']) && is_array($params['paragraphs'])) ? $params['paragraphs'] : array(),
                'replacement'       => (string) ($params['replacement'] ?? ''),
            ));
        }
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/content/{post}/page-edits — save the full-page
     *  editor's document: sliced back into sections and routed through the
     *  EXISTING section save paths (replace / slice / insert); raw units are
     *  stripped from replacements (the F9 law — images stay untouched). */
    public function remote_save_page_edits(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $result = $this->editing->save_page_edits(
            (int) $user->id,
            $site,
            absint($request->get_param('post')),
            wp_kses_post((string) ($params['html'] ?? ''))
        );
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** GET /seo/sites/{id}/content/{post}/section-versions — a section's saved
     *  version history (newest first) for the editor's version dropdown. */
    public function remote_section_versions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        return $this->success(array('versions' => $this->editing->list_section_versions(
            (int) $user->id,
            (int) $site->id,
            absint($request->get_param('post')),
            (string) $request->get_param('text'),
            absint($request->get_param('occurrence'))
        )));
    }

    /** POST /seo/sites/{id}/media — deliver an image (by hub URL) into the
     *  connected site's own media library; returns the client-native {id, url}.
     *  The page editor inserts THAT url — client sites stay autonomous. */
    public function remote_add_media(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $url    = esc_url_raw((string) ($params['url'] ?? ''));
        if ($url === '') {
            return new WP_Error('pcm_seo_media_no_url', __('No image URL to deliver.', 'power-creatives'), array('status' => 400));
        }
        $result = PCM_SEO_Page_Inventory::remote_add_media($site, $url);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** GET /seo/sites/{id}/redirects — the site's redirect list + capability flag. */
    public function remote_list_redirects(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        return $this->success(PCM_SEO_Redirects::list_redirects((int) $user->id, $site));
    }

    /** POST /seo/sites/{id}/redirects — save (UPSERT on from-path) + push. */
    public function remote_save_redirect(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $result = PCM_SEO_Redirects::save_redirect((int) $user->id, $site, array(
            'from'        => (string) ($params['from'] ?? ''),
            'to'          => (string) ($params['to'] ?? ''),
            'code'        => (int) ($params['code'] ?? 301),
            'updateLinks' => !empty($params['updateLinks']),
        ));
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/redirects/{rid}/delete — delete + push. */
    public function remote_delete_redirect(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $result = PCM_SEO_Redirects::delete_redirect((int) $user->id, $site, absint($request->get_param('rid')));
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/content/{post}/page-type — what this page IS
     *  (local/blog/product/…): steers every AI run on it. '' clears. */
    public function remote_save_page_type(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $result = PCM_SEO_Service::save_page_type(
            (int) $user->id,
            (int) $site->id,
            absint($request->get_param('post')),
            sanitize_key((string) ($params['type'] ?? ''))
        );
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** GET /seo/sites/{id}/url-usage?url= — posts linking to a URL (the popup's N). */
    public function remote_url_usage(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $url = esc_url_raw((string) $request->get_param('url'));
        if ($url === '') {
            return new WP_Error('pcm_seo_usage_no_url', __('No URL to search for.', 'power-creatives'), array('status' => 400));
        }
        return $this->success(PCM_SEO_Redirects::remote_url_usage($site, $url));
    }

    /** POST /seo/sites/{id}/content/{post}/image-rule — save an image METADATA
     *  rule (engine v2.3): alt/title rewritten at render time, never the image
     *  itself. Editing back to the originals (or revert:true) deletes it. */
    public function remote_save_image_rule(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $result = $this->editing->save_image_rule((int) $user->id, $site, absint($request->get_param('post')), array(
            'src'           => (string) ($params['src'] ?? ''),
            'occurrence'    => (int) ($params['occurrence'] ?? 0),
            'alt'           => (string) ($params['alt'] ?? ''),
            'title'         => (string) ($params['title'] ?? ''),
            'originalAlt'   => (string) ($params['originalAlt'] ?? ''),
            'originalTitle' => (string) ($params['originalTitle'] ?? ''),
            'revert'        => !empty($params['revert']),
        ));
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** GET /seo/sites/{id}/content/{post}/page-versions — the page editor's
     *  version dropdown: saved page documents (newest first) + the true
     *  no-rules Original (rules-input snapshot, hub-assembled). */
    public function remote_page_versions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        // ?rowsOnly=1 = the editor's OPEN path (pure DB read); without it the
        // reply includes the remote-assembled Original (gap 02d3cb7 D1).
        return $this->success($this->editing->list_page_versions((int) $user->id, $site, absint($request->get_param('post')), (bool) $request->get_param('rowsOnly')));
    }

    /** POST /seo/sites/{id}/content/{post}/section-versions/{vid}/delete — delete one saved version. */
    public function remote_delete_section_version(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $result = $this->editing->delete_section_version((int) $user->id, absint($request->get_param('vid')));
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/content/{post}/section-optimize — AI-rewrite a whole
     *  section / draft a new one (block HTML, NOT saved — staged). */
    /**
     * GET /seo/sites/{id}/content/{post}/page-state — the featherweight
     * sync check the editor's glyph runs in the background (gap e48b1ff).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function remote_page_state(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        return $this->success(PCM_SEO_Page_State::page_state_compare($site, absint($request->get_param('post')), (int) $user->id));
    }

    public function remote_optimize_section(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params      = $request->get_json_params() ?: array();
        $type        = sanitize_key($params['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $html        = wp_kses_post((string) ($params['html'] ?? '')); // section HTML — kses, NOT textarea-stripped
        $topic       = sanitize_textarea_field((string) ($params['topic'] ?? ''));
        $model       = isset($params['model']) ? sanitize_text_field((string) $params['model']) : null;
        $provider    = isset($params['provider']) ? sanitize_text_field((string) $params['provider']) : null;
        $template_id = isset($params['templateId']) && $params['templateId'] ? absint($params['templateId']) : null;
        // REVISE FIDELITY (gap e8fcae5 D3): the draft travels SEPARATELY from
        // the note so the server can enforce the human-editor contract and
        // measure retention against it.
        $draft = wp_kses_post((string) ($params['draft'] ?? ''));
        // THE CHANGE-CARD REVIEW (gap 0a0a3c3): the envelope contract is
        // requested per call; purposes = the run's teacher ids, the only
        // legal `why` values (the verifier blanks anything else).
        $report_changes = !empty($params['reportChanges']);
        $purposes       = array_values(array_filter(array_map('sanitize_key', (array) ($params['purposes'] ?? array()))));
        $result = PCM_SEO_Editing::remote_optimize_section($site, absint($request->get_param('post')), $type, $html, $topic, $model, (int) $user->id, $provider, $template_id, $draft, $report_changes, $purposes);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /** POST /seo/sites/{id}/content/{post}/headings/{idx} — edit a connected post's heading. */
    public function remote_update_heading(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $type   = sanitize_key($params['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $text   = array_key_exists('text', $params) ? wp_kses_post((string) $params['text']) : null;
        $level  = array_key_exists('level', $params) ? absint($params['level']) : null;
        // user id rides along so a successful edit RE-KEYS any section rules
        // anchored to this heading (interaction law, contracts v2).
        $result = PCM_SEO_Remote_Headings::remote_update_heading($site, absint($request->get_param('post')), $type, absint($request->get_param('idx')), $text, $level, (int) $user->id);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success(array('headings' => $result));
    }

    /** POST /seo/sites/{id}/content/{post}/headings/{idx}/optimize — AI-optimize a remote heading. */
    public function remote_optimize_heading(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params   = $request->get_json_params() ?: array();
        $type     = sanitize_key($params['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $text     = sanitize_text_field((string) ($params['text'] ?? ''));
        $model    = isset($params['model']) ? sanitize_text_field((string) $params['model']) : null;
        $provider = isset($params['provider']) ? sanitize_text_field((string) $params['provider']) : null;
        $template_id = isset($params['templateId']) && $params['templateId'] ? absint($params['templateId']) : null;
        $result = PCM_SEO_Remote_Headings::remote_optimize_heading($site, absint($request->get_param('post')), $type, $text, $model, (int) $user->id, $provider, $template_id ?: null);
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
        $result = PCM_SEO_AI::generate_site_field($field, $brand_id, $model, $user ? (int) $user->id : null, $provider, $overrides);
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

    /** GET /seo/sites/{id}/authors — the authors on a connected site. */
    public function remote_authors(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $result = PCM_SEO_Service::remote_authors($site);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
    }

    /**
     * POST /seo/sites/{id}/authors — create an author ON the connected site.
     *
     * ⚠ Same shape as create_author() above, aimed at someone else's install: the role
     * is hardcoded to 'author' in the service and the password is generated there, so
     * neither can be influenced from input. Whether the connection may create users at
     * all is the remote's decision — its 403 is surfaced verbatim.
     */
    public function remote_create_author(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $p     = $request->get_json_params();
        $name  = is_array($p) ? sanitize_text_field((string) ($p['name'] ?? '')) : '';
        $email = is_array($p) ? sanitize_email((string) ($p['email'] ?? '')) : '';
        $result = PCM_SEO_Service::remote_create_author($site, $name, $email);
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
        $result = PCM_SEO_AI::generate_field($id, $field, $brand_id, $model, $user ? (int) $user->id : null, $provider, $template_id);
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
        $result = PCM_SEO_Local::scan_links($id);
        return $this->success(array_merge(array('id' => $id), $result));
    }

    /** GET /seo/content/{id}/links — stored per-link details for the popup table. */
    public function get_links(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        return $this->success(array('links' => PCM_SEO_Local::get_post_links($id)));
    }

    /** POST /seo/content/{id}/links/{idx} — edit a link's anchor/href in the post content. */
    public function update_link(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        if (!current_user_can('edit_post', $id)) {
            return $this->error('You cannot edit this content.', 403, 'pcm_forbidden');
        }
        $params = $request->get_json_params() ?: array();
        $anchor = array_key_exists('anchor', $params) ? (string) $params['anchor'] : null;
        $href   = array_key_exists('href', $params) ? (string) $params['href'] : null;
        $result = PCM_SEO_Local::update_post_link($id, absint($request->get_param('idx')), $anchor, $href);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success(array('links' => $result));
    }

    /** POST /seo/content/{id}/links/{idx}/remove — unwrap a link (keep its text). */
    public function remove_link(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        if (!current_user_can('edit_post', $id)) {
            return $this->error('You cannot edit this content.', 403, 'pcm_forbidden');
        }
        $result = PCM_SEO_Local::remove_post_link($id, absint($request->get_param('idx')));
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success(array('links' => $result));
    }

    /** GET /seo/content/{id}/headings — the post's H1–H6 outline for the expandable editor. */
    public function get_headings(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        return $this->success(array('headings' => PCM_SEO_Local::get_post_headings($id)));
    }

    /** GET /seo/content/{id}/content-nodes — ordered headings + paragraphs for the outline. */
    public function get_content_nodes(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        return $this->success(array('nodes' => PCM_SEO_Local::get_post_content_nodes($id)));
    }

    /** POST /seo/content/{id}/headings/{idx} — change a heading's text and/or tag level. */
    public function update_heading(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        if (!current_user_can('edit_post', $id)) {
            return $this->error('You cannot edit this content.', 403, 'pcm_forbidden');
        }
        $params = $request->get_json_params() ?: array();
        $text   = array_key_exists('text', $params) ? wp_kses_post((string) $params['text']) : null;
        $level  = array_key_exists('level', $params) ? absint($params['level']) : null;
        $result = PCM_SEO_Local::update_post_heading($id, absint($request->get_param('idx')), $text, $level);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success(array('headings' => $result));
    }

    /** POST /seo/content/{id}/headings/{idx}/optimize — AI-optimize a heading's text (not saved). */
    public function optimize_heading(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        if (!$id || !get_post($id)) {
            return $this->not_found('Content');
        }
        if (!current_user_can('edit_post', $id)) {
            return $this->error('You cannot edit this content.', 403, 'pcm_forbidden');
        }
        $params   = $request->get_json_params() ?: array();
        $text     = sanitize_text_field((string) ($params['text'] ?? ''));
        $brand_id = isset($params['brandId']) && $params['brandId'] ? absint($params['brandId']) : null;
        $model    = isset($params['model']) ? sanitize_text_field((string) $params['model']) : null;
        $provider = isset($params['provider']) ? sanitize_text_field((string) $params['provider']) : null;
        $template_id = isset($params['templateId']) && $params['templateId'] ? absint($params['templateId']) : null;
        $user     = $this->get_current_pcm_user();
        $result   = PCM_SEO_Local::optimize_heading($id, $text, $brand_id, $model, $user ? (int) $user->id : null, $provider, $template_id);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success($result);
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
        $result   = PCM_SEO_AI::optimize_body($id, $brand_id, $model, $user ? (int) $user->id : null);
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
        return $this->success(PCM_SEO_Views::list_views((int) $user->id));
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
        return $this->success(PCM_SEO_Views::create_view((int) $user->id, $name, $config), 201);
    }

    /**
     * PATCH /seo/views/{id}/default — set (or clear) this view as the user's
     * default. Enforces at most one default per user. Body: { isDefault: bool }
     * (omitted/true makes it the default).
     */
    /**
 * PATCH /seo/views/{id}/name — rename a saved view. Input: { name }.
 * Ownership is enforced in PCM_SEO_Views::rename_view(); a view that isn't yours 404s.
 */
    /** PATCH /seo/views/{id}/pin — pin/unpin a view to the tab strip. Input: { isPinned }. */
    public function views_set_pinned(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id       = absint($request->get_param('id'));
        $params   = $request->get_json_params() ?: array();
        $isPinned = array_key_exists('isPinned', $params) ? (bool) $params['isPinned'] : true;
        $user = $this->get_current_pcm_user();
        if (!PCM_SEO_Views::set_pinned($id, (int) $user->id, $isPinned)) {
            return $this->not_found('View');
        }
        return $this->success(array('id' => $id, 'isPinned' => $isPinned));
    }

    public function views_rename(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id     = absint($request->get_param('id'));
        $params = $request->get_json_params() ?: array();
        $name   = sanitize_text_field((string) ($params['name'] ?? ''));
        if ($name === '') {
            return $this->error('A view name is required.', 400);
        }
        $user = $this->get_current_pcm_user();
        if (!PCM_SEO_Views::rename_view($id, (int) $user->id, $name)) {
            return $this->not_found('View');
        }
        return $this->success(array('id' => $id, 'name' => $name));
    }

    public function views_set_default(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id     = absint($request->get_param('id'));
        $params = $request->get_json_params() ?: array();
        $isDefault = array_key_exists('isDefault', $params) ? (bool) $params['isDefault'] : true;

        $user = $this->get_current_pcm_user();
        if (!PCM_SEO_Views::set_default_view($id, (int) $user->id, $isDefault)) {
            return $this->not_found('View');
        }
        return $this->success(array('id' => $id, 'isDefault' => $isDefault));
    }

    /** DELETE /seo/views/{id} — delete a view owned by the current user. */
    public function views_delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = absint($request->get_param('id'));
        $user = $this->get_current_pcm_user();
        if (!PCM_SEO_Views::delete_view($id, (int) $user->id)) {
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
        $result = PCM_SEO_AI::generate_site_field($field, $brand_id, $model, $user ? (int) $user->id : null, $provider);
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

    // ── THE BUSINESS CARD (gap 616870f) ──

    /** GET /seo/sites/{id}/business — the resolved per-site record + mapping
     *  context (units of the mapped brand; exact-domain suggestion when
     *  unmapped — a deterministic fact, never a guess). */
    public function business_card(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $record = PCM_SEO_Business::business_record_for_site((int) $site->id);
        $record['units'] = $record['brandId'] > 0 ? PCM_Brands_Service::list_business_units($record['brandId']) : array();
        // The DYNAMIC provider name (owner correction, gap 23955b9): the
        // working line names the ACTUAL configured fetcher — registry
        // display name of whatever seo_gbp_provider points at. Never a
        // hardcoded vendor string anywhere in the UI.
        $provider_id = (string) PCM_Settings::get('seo_gbp_provider', 'apify');
        $reg = PCM_Providers::get($provider_id);
        $record['providerName'] = is_array($reg) ? (string) ($reg['name'] ?? $provider_id) : $provider_id;
        $record['suggestion'] = null;
        if ($record['brandId'] === 0) {
            $match = (new PCM_Brands_Service())->find_by_website((int) $user->id, (string) $site->url);
            $host  = strtolower((string) (wp_parse_url((string) $site->url, PHP_URL_HOST) ?: ''));
            $b_host = $match ? strtolower((string) (wp_parse_url((string) ($match->website ?? ''), PHP_URL_HOST) ?: ($match->domain ?? ''))) : '';
            if ($match && $host !== '' && ($b_host === $host || $b_host === 'www.' . $host || 'www.' . $b_host === $host)) {
                $record['suggestion'] = array('brandId' => (int) $match->id, 'name' => (string) $match->name);
            }
        }
        return $this->success($record);
    }

    /** POST /seo/sites/{id}/business/overrides — the SITE layer (SEO's own). */
    public function business_overrides(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $params = $request->get_json_params() ?: array();
        $result = PCM_SEO_Business::save_site_business_overrides((int) $site->id, (array) ($params['fields'] ?? array()));
        return $result instanceof WP_Error ? $result : $this->success($result);
    }

    /** POST /seo/sites/{id}/business/refresh — re-scrape the site's OWN
     *  visible HTML into the mapped unit's fetched layer (tag 'scrape');
     *  manual corrections survive by construction. */
    public function business_refresh(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $record = PCM_SEO_Business::business_record_for_site((int) $site->id);
        if ($record['brandId'] === 0) {
            return $this->error(__('Link this site to a brand first — the refresh writes into the brand\'s business unit.', 'power-creatives'), 409, 'pcm_seo_biz_unmapped');
        }
        // ASK-FIRST refresh (gap 670d0e0): the popover's CONFIRMED url is the
        // one scraped — never a guessed system URL — and it is remembered as
        // this site's Indexed URL (site layer).
        $params      = $request->get_json_params() ?: array();
        $custom_url  = esc_url_raw((string) ($params['url'] ?? ''));
        $scrape_url  = ($custom_url !== '' && preg_match('#^https?://#i', $custom_url)) ? $custom_url : (string) $site->url;
        if ($custom_url !== '' && $scrape_url === $custom_url) {
            PCM_SEO_Business::save_site_business_overrides((int) $site->id, array('indexedurl' => $custom_url));
        }
        $scraped = (new PCM_Brands_Service())->scrape_and_prepare($scrape_url);
        $info    = (array) ($scraped['businessInfo'] ?? array());
        $fields  = array_filter(array(
            'name'        => (string) ($info['name'] ?? ''),
            'description' => (string) ($info['business_summary'] ?? ''),
            'niche'       => (string) ($info['niche'] ?? ''),
            'website'     => (string) ($info['website'] ?? ''),
        ), static fn($v) => $v !== '');
        if (empty($fields)) {
            return $this->error(__('The site scrape returned no usable business fields — nothing was changed.', 'power-creatives'), 502, 'pcm_seo_biz_scrape_empty');
        }
        $saved = PCM_Brands_Service::save_business_unit($record['brandId'], array('mergeFetched' => $fields, 'sourceTag' => 'scrape'), $record['unitId']);
        if ($saved instanceof WP_Error) {
            return $saved;
        }
        return $this->success(PCM_SEO_Business::business_record_for_site((int) $site->id));
    }

    /** POST /seo/sites/{id}/business/maps — ONE pasted Maps Share URL →
     *  CID + coordinates + embed URL into the mapped unit (tag 'maps-paste').
     *  The zero-API Google surface. */
    public function business_maps(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $record = PCM_SEO_Business::business_record_for_site((int) $site->id);
        if ($record['brandId'] === 0) {
            return $this->error(__('Link this site to a brand first — the Maps details belong to the brand\'s business unit.', 'power-creatives'), 409, 'pcm_seo_biz_unmapped');
        }
        $params = $request->get_json_params() ?: array();
        $url    = (string) ($params['url'] ?? '');
        $parsed = PCM_SEO_Business::parse_maps_url($url);
        // FLOW-ORDER FIX (gap 972481e, live-proven): short links (share.google,
        // maps.app.goo.gl) legitimately parse to NOTHING locally — that is
        // NOT fatal; the resolver + provider below do the real work. Only a
        // foreign host stays fatal.
        if ($parsed instanceof WP_Error) {
            if ($parsed->get_error_code() !== 'pcm_seo_maps_unparsed') {
                return $parsed;
            }
            $parsed = array('fields' => array('mapsShareUrl' => esc_url_raw($url)));
        }
        $saved = PCM_Brands_Service::save_business_unit($record['brandId'], array('mergeFetched' => $parsed['fields'], 'sourceTag' => 'maps-paste'), $record['unitId']);
        if ($saved instanceof WP_Error) {
            return $saved;
        }
        // GOOGLE NATIVE (gap 670d0e0): with a google_places key the SAME paste
        // fills the WHOLE record — resolve the place, pull the wide-mask
        // details, merge tagged 'gbp'. No key / resolve failure = the local
        // cid/geo fields above stand alone, the reason NAMED in the reply —
        // never a silent half-result.
        $google = array('filled' => false, 'error' => null);
        $pid    = PCM_SEO_GBP::resolve_share_url($url, (int) $user->id);
        if ($pid instanceof WP_Error) {
            $google['error'] = $pid->get_error_message();
            // The resolver may still have reached a LONG url whose local
            // fields (cid/geo) are extractable — salvage them (gap 972481e).
            $err_data = $pid->get_error_data();
            if (is_array($err_data) && !empty($err_data['resolvedUrl'])) {
                $late = PCM_SEO_Business::parse_maps_url((string) $err_data['resolvedUrl']);
                if (!($late instanceof WP_Error)) {
                    PCM_Brands_Service::save_business_unit($record['brandId'], array('mergeFetched' => $late['fields'], 'sourceTag' => 'maps-paste'), $record['unitId']);
                }
            }
        } else {
            $raw = PCM_SEO_GBP::provider((int) $user->id)->details($pid, PCM_SEO_GBP::default_lang());
            if (isset($raw['error'])) {
                $google['error'] = (string) $raw['error'];
            } elseif (!empty($raw)) {
                $normalized = PCM_SEO_GBP::normalize($raw);
                $merge      = PCM_Brands_Service::save_business_unit($record['brandId'], array('mergeFetched' => $normalized, 'sourceTag' => 'gbp'), $record['unitId']);
                $google['filled'] = !($merge instanceof WP_Error);
                if ($merge instanceof WP_Error) {
                    $google['error'] = $merge->get_error_message();
                }
            }
        }
        $out = PCM_SEO_Business::business_record_for_site((int) $site->id);
        $out['google'] = $google;
        return $this->success($out);
    }

    /** POST /seo/sites/{id}/business/place — fill the mapped unit from ONE
     *  picked place id (the share-link-independent path, gap 972481e):
     *  Find on Google → pick → the whole record incl. the schema ids. */
    public function business_place(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $record = PCM_SEO_Business::business_record_for_site((int) $site->id);
        if ($record['brandId'] === 0) {
            return $this->error(__('Link this site to a brand first — the place details belong to the brand\'s business unit.', 'power-creatives'), 409, 'pcm_seo_biz_unmapped');
        }
        $params   = $request->get_json_params() ?: array();
        $place_id = sanitize_text_field((string) ($params['placeId'] ?? ''));
        if (!preg_match('/^ChIJ[0-9A-Za-z_-]{10,}$/', $place_id)) {
            return $this->error(__('A valid Google place id is required.', 'power-creatives'), 400, 'pcm_seo_biz_bad_place');
        }
        $raw = PCM_SEO_GBP::provider((int) $user->id)->details($place_id, PCM_SEO_GBP::default_lang());
        if (isset($raw['error'])) {
            return $this->error((string) $raw['error'], 502, 'pcm_seo_gbp_error');
        }
        if (empty($raw)) {
            return $this->error(__('No details returned for that place.', 'power-creatives'), 502, 'pcm_seo_gbp_empty');
        }
        $saved = PCM_Brands_Service::save_business_unit($record['brandId'], array('mergeFetched' => PCM_SEO_GBP::normalize($raw), 'sourceTag' => 'gbp'), $record['unitId']);
        if ($saved instanceof WP_Error) {
            return $saved;
        }
        return $this->success(PCM_SEO_Business::business_record_for_site((int) $site->id));
    }

    /** POST /seo/gbp/search — search places (via the configured provider). */
    public function gbp_search(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?: array();
        $query  = sanitize_text_field((string) ($params['query'] ?? ''));
        if ($query === '') {
            return $this->error('Search query is required.', 400, 'pcm_seo_gbp_no_query');
        }
        $lang   = sanitize_text_field((string) ($params['language'] ?? PCM_SEO_GBP::default_lang()));
        $raw    = PCM_SEO_GBP::provider((int) $this->get_current_pcm_user()->id)->search($query, $lang);
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
        $raw  = PCM_SEO_GBP::provider((int) $this->get_current_pcm_user()->id)->details($place_id, $lang);
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

    /** POST /seo/llm-info/keywords — most-used keywords across this site's published content. */
    public function llminfo_keywords(WP_REST_Request $request): WP_REST_Response
    {
        $list = PCM_SEO_Service::top_keywords(PCM_SEO_Service::local_content_corpus());
        return $this->success(array(
            'keywords' => PCM_SEO_Service::keywords_to_string($list),
            'list'     => $list,
        ));
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

    /** POST /seo/sites/{id}/llm-info/keywords — most-used keywords across a connected site's content. */
    public function remote_llminfo_keywords(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $user = $this->get_current_pcm_user();
        $site = PCM_DB::get_site(absint($request->get_param('id')), (int) $user->id);
        if (!$site) {
            return $this->not_found('Site');
        }
        $list = PCM_SEO_Service::top_keywords(PCM_SEO_Service::remote_content_corpus($site));
        return $this->success(array(
            'keywords' => PCM_SEO_Service::keywords_to_string($list),
            'list'     => $list,
        ));
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
