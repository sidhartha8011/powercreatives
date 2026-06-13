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
            array('POST', '/seo/content/(?P<id>\d+)/cell',   'save_cell'),
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
        $field  = sanitize_key($params['field'] ?? '');
        if ($field === '') {
            return $this->error('Field is required.', 400, 'pcm_seo_missing_field');
        }

        $result = $this->service->save_cell($id, $field, $params['value'] ?? '');
        if ($result instanceof WP_Error) {
            return $result;
        }
        return $this->success(array_merge(array('id' => $id), $result));
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
